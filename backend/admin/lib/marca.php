<?php
/* Marca blanca, en un solo sitio.

   Antes la marca estaba escrita a mano en cada pantalla: «Croilab» y la «C» del
   logo aparecían tal cual en el login del cliente, en el del equipo y en media
   docena de textos. Y el WhatsApp y el enlace de reuniones eran globales, así
   que un cliente de una agencia colaboradora veía el teléfono de Croilab en su
   propio portal: justo lo contrario de lo que quiere decir «marca blanca».

   Aquí se resuelve una vez: quién firma, con qué logo y a qué contacto escribe
   el cliente. Todo lo demás solo pregunta.

   Ojo: esto NO toca la estética del portal ni de los informes. Cambia los datos
   que se pintan, no cómo se pintan. */

require_once __DIR__ . '/../../db.php';

/* La tabla de agencias nació sin datos de contacto: se le añaden aquí para que
   una instalación antigua se ponga al día sola al abrir cualquier página. */
function marca_ensure() { if (croilab_esquema_gestionado()) return;   /* el esquema lo crean las migraciones */
    static $done = false; if ($done) return; $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS partner_agencies (
          id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(160) NOT NULL, logo_url VARCHAR(400) DEFAULT '',
          color VARCHAR(20) DEFAULT '', web VARCHAR(200) DEFAULT '', email VARCHAR(160) DEFAULT '',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cols = ['whatsapp'=>"VARCHAR(40) NOT NULL DEFAULT ''",
                 'meeting_url'=>"VARCHAR(300) NOT NULL DEFAULT ''",
                 'telefono'=>"VARCHAR(40) NOT NULL DEFAULT ''"];
        foreach ($cols as $c => $def) {
            $hay = db()->query("SELECT COUNT(*) FROM information_schema.columns
                                 WHERE table_schema=DATABASE() AND table_name='partner_agencies'
                                   AND column_name=" . db()->quote($c))->fetchColumn();
            if (!$hay) db()->exec("ALTER TABLE partner_agencies ADD COLUMN $c $def");
        }
    } catch (Exception $e) {}
}

/* La marca de la casa: la que ve el equipo y la que ve un cliente que no está
   asignado a ninguna agencia colaboradora.
 *
 * Los cuatro ajustes se leen de una vez porque get_setting() es una consulta por
   clave, y /api/v1/nav —que se pide en cada página— usaba cuatro viajes sueltos
   solo para pintar el nombre del logo. Una consulta, 1,6 s menos. */
function marca_agencia() {
    static $m = null; if ($m !== null) return $m;
    $s = get_settings(['agency_name', 'agency_logo', 'agency_color', 'agency_web']);
    $nom = trim($s['agency_name']) ?: 'Croilab';
    $m = [
        'name'    => $nom,
        'initial' => mb_strtoupper(mb_substr($nom, 0, 1)),
        'logo'    => trim($s['agency_logo']),
        'color'   => trim($s['agency_color']),
        'web'     => trim($s['agency_web']),
        'propia'  => true,
    ];
    return $m;
}

/* La marca que le corresponde a un cliente. Si pertenece a una agencia
   colaboradora, la suya; si no, la de la casa. Un campo vacío en la agencia
   colaboradora nunca deja un hueco: cae al de la casa. */
function marca_partner($partnerId) {
    $base = marca_agencia();
    $pid  = (int)$partnerId; if (!$pid) return $base;
    marca_ensure();
    try {
        $q = db()->prepare('SELECT * FROM partner_agencies WHERE id=?'); $q->execute([$pid]);
        $p = $q->fetch(); if (!$p) return $base;
    } catch (Exception $e) { return $base; }
    $nom = trim((string)($p['nombre'] ?? '')) ?: $base['name'];
    return [
        'name'    => $nom,
        'initial' => mb_strtoupper(mb_substr($nom, 0, 1)),
        'logo'    => trim((string)($p['logo_url'] ?? '')) ?: $base['logo'],
        'color'   => trim((string)($p['color'] ?? '')) ?: $base['color'],
        'web'     => trim((string)($p['web'] ?? '')) ?: $base['web'],
        'propia'  => false,
    ];
}

/* A quién escribe o llama el cliente. Mismo criterio: lo de su agencia si lo
   tiene puesto, lo de la casa si no. Un WhatsApp equivocado aquí es un cliente
   escribiendo a la empresa que no debe. */
function marca_contacto($partnerId = 0) {
    /* Las cinco claves de una vez. Aquí el ahorro era de tres o cuatro consultas
       según el caso, porque 'email' caía a 'agency_email' con ?: y eso solo se
       leía cuando el primero venía vacío. */
    $s = get_settings(['meeting_url', 'whatsapp', 'email', 'agency_email', 'agency_phone']);
    $casa = [
        'meeting_url' => $s['meeting_url'],
        'whatsapp'    => $s['whatsapp'],
        'email'       => $s['email'] ?: $s['agency_email'],
        'telefono'    => $s['agency_phone'],
    ];
    $pid = (int)$partnerId; if (!$pid) return $casa;
    marca_ensure();
    try {
        $q = db()->prepare('SELECT * FROM partner_agencies WHERE id=?'); $q->execute([$pid]);
        $p = $q->fetch(); if (!$p) return $casa;
    } catch (Exception $e) { return $casa; }
    return [
        'meeting_url' => trim((string)($p['meeting_url'] ?? '')) ?: $casa['meeting_url'],
        'whatsapp'    => trim((string)($p['whatsapp'] ?? ''))    ?: $casa['whatsapp'],
        'email'       => trim((string)($p['email'] ?? ''))       ?: $casa['email'],
        'telefono'    => trim((string)($p['telefono'] ?? ''))    ?: $casa['telefono'],
    ];
}

/* Devuelve el <div> del logo ya resuelto: imagen si hay URL, y si no la inicial
   sobre el color de la marca. Se usa igual en los dos logins, para que no haya
   dos maneras distintas de pintar lo mismo. */
function marca_logo_html($m, $clase = 'mark', $fallbackColor = '') {
    $col = $m['color'] !== '' ? $m['color'] : $fallbackColor;
    $st  = $col !== '' ? ' style="background:' . htmlspecialchars($col, ENT_QUOTES) . '"' : '';
    if (!empty($m['logo'])) {
        return '<div class="' . htmlspecialchars($clase, ENT_QUOTES) . '"' . $st . '>'
             . '<img src="' . htmlspecialchars($m['logo'], ENT_QUOTES) . '" alt="' . htmlspecialchars($m['name'], ENT_QUOTES) . '"></div>';
    }
    return '<div class="' . htmlspecialchars($clase, ENT_QUOTES) . '"' . $st . '>'
         . htmlspecialchars($m['initial'], ENT_QUOTES) . '</div>';
}
