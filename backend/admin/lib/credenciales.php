<?php
/* Versión de credenciales: lo que hace que cambiar una contraseña cierre de
   verdad las sesiones que ya estaban abiertas.

   El problema que resuelve: en el ERP, entrar al panel abría una sesión que se
   quedaba viva. Si una credencial se filtraba, la respuesta era cambiar la
   contraseña, pero eso no expulsaba a nadie: todas las sesiones que ya estaban
   abiertas seguían funcionando, porque la contraseña solo se comprobaba al
   entrar. Quien tuviera la sesión robada conservaba el acceso hasta que
   expirara por tiempo, sin importar cuántas veces se cambiara la clave.

   La solución es una columna `cred_ver` en `admins` y en `clients`, un entero
   que sube cada vez que cambia la credencial. La sesión guarda el valor que
   tenía al entrar; en cada petición se compara con el de la base de datos y, si
   no coinciden, la sesión se destruye. Como el valor está en la sesión, cambiar
   la contraseña es la operación que hace caer a todos los demás, sin lista de
   sesiones que mantener ni nada que purgar.

   Lo mismo sirve para lo demás: desactivar a alguien o cambiarle el rol también
   sube la versión, para que el cambio se aplique en la siguiente petición en vez
   de cuando le toca. Y una función de «cerrar todas las sesiones» es la misma
   operación, sin ninguna columna más.

   Todo pasa por credenciales_cambiar() y credenciales_subir(). Antes había tres
   sitios que cambiaban una contraseña escribiendo el hash por su cuenta -la ficha
   del cliente, el guardado del portal y la del miembro del equipo-, y los tres
   se olvidaban de invalidar algo: seguirían dejando abiertas las sesiones ya
   abiertas. Ahora hay un solo sitio donde se pueda olvidar. Las altas de cuenta
   nueva sí siguen haciendo su propio INSERT, que no tiene sesión que tumbar. */

require_once __DIR__ . '/audit.php';

/* Las dos tablas con credenciales. Una lista blanca, no un nombre de la
   petición: el nombre va a la consulta, así que nunca puede venir de fuera. */
function credenciales_tablas() { return ['admins', 'clients']; }

/* Crea lo que falte. Idempotente, y envuelto en un try porque no debe impedir
   que el ERP funcione aunque el usuario de MySQL no pueda hacer ALTER TABLE. */
function credenciales_asegurar() {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS password_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tabla VARCHAR(20) NOT NULL,
            usuario_id INT NOT NULL,
            hash VARCHAR(255) NOT NULL,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (tabla, usuario_id, creado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    $cols = [
        'cred_ver'           => 'INT NOT NULL DEFAULT 1',
        'password_changed_at' => 'DATETIME NULL DEFAULT NULL',
    ];
    foreach (credenciales_tablas() as $t) {
        foreach ($cols as $c => $def) {
            try {
                $hay = db()->query("SELECT COUNT(*) FROM information_schema.columns
                                     WHERE table_schema=DATABASE() AND table_name=" . db()->quote($t) .
                                     " AND column_name=" . db()->quote($c))->fetchColumn();
                if (!$hay) db()->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
            } catch (Exception $e) {}
        }
    }
}

/* La versión tal y como está en la base de datos. Una cuenta creada antes de
   que existiera la columna vale 1, que es lo mismo que lleva por defecto. */
function cred_ver_de($fila) {
    if (!is_array($fila)) return 1;
    return isset($fila['cred_ver']) ? (int)$fila['cred_ver'] : 1;
}

/* Sube la versión sin tocar la contraseña. Es lo que se llama al desactivar a
   alguien, al cambiarle el rol, o al pedirle que cierre todas las sesiones. */
function credenciales_subir($tabla, $id) {
    credenciales_asegurar();
    if (!in_array($tabla, credenciales_tablas(), true) || !$id) return false;
    try {
        db()->prepare("UPDATE `$tabla` SET cred_ver = cred_ver + 1 WHERE id = ?")
            ->execute([(int)$id]);
        return true;
    } catch (Exception $e) { return false; }
}

/* La versión que lleva la sesión, comparada con la de la fila recién leída.

   Una sesión abierta antes de que existiera esta columna no lleva versión. Aquí se
   rechaza en vez de aceptarse: aceptarla dejaría un agujero permanente, porque
   esa sesión seguiría entrando sin comprobar nunca la versión y ningún cambio
   posterior de contraseña podría echarla. El coste es que el día del despliegue
   hay que volver a entrar una vez; el olvido de quien la robó no lo es.

   Quien no ha iniciado sesión (páginas públicas) no lleva id y no se compara. */
function cred_ver_coincide($tabla, $fila) {
    $id = $tabla === 'admins' ? ($_SESSION['admin_id'] ?? null) : ($_SESSION['client_id'] ?? null);
    if (empty($id)) return true;                        /* no hay sesión que comprobar */
    if (!isset($_SESSION['cred_ver'])) return false;     /* sesión anterior a esta columna */
    return (int)$_SESSION['cred_ver'] === cred_ver_de($fila);
}

/* Sella la versión en la sesión. Se llama al entrar, para que a partir de ahí
   cualquier cambio de credencial la invalide. */
function cred_ver_sellar($tabla, $fila) {
    $_SESSION['cred_ver'] = cred_ver_de($fila);
}

/* Comprobación de la política de contraseñas. El mínimo estaba puesto a mano en
   cada pantalla (6 caracteres en unas, nada en otras). Aquí vive uno solo, y se
   sube cambiando este número en vez de persiguiendo los formularios.
   Se deja en 6, que es lo que ya aceptaba el ERP: subirlo cambiaría el
   comportamiento sin que lo hubiera pedido nadie. */
function password_valida($p, $minimo = 6) {
    $p = (string)$p;
    if (strlen($p) < $minimo) return 'La contraseña debe tener al menos ' . $minimo . ' caracteres.';
    if (mb_strlen($p) > 200) return 'La contraseña es demasiado larga.';
    return '';
}

/* Genera una contraseña legible con random_int, que es el generador criptográfico
   de PHP. El suministro de respaldo que se usaba en varios sitios era
   bin2hex(random_bytes(4)): 32 bits, y se pueden probar miles por segundo. */
function password_generar($largo = 14) {
    /* Se evita el 0 y la O, el 1 y la l, para que se pueda dictar por teléfono. */
    $letras = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    $cifras = '23456789';
    $alf    = $letras . $cifras;
    $out = '';
    for ($i = 0; $i < $largo; $i++) $out .= $alf[random_int(0, strlen($alf) - 1)];
    /* Se garantiza al menos una cifra: si no, el comprobador de contraseñas
      ([-a-z] y [A-Z] pero sin cifras) la marcaría como débil. */
    if (!preg_match('/\d/', $out)) {
        $pos = random_int(0, $largo - 1);
        $out[$pos] = $cifras[random_int(0, strlen($cifras) - 1)];
    }
    return $out;
}

/* Historial de contraseñas, para no dejar que se repita una de las últimas.
   Se compara también con la que tiene la cuenta ahora mismo: el historial solo
   guarda contraseñas ya sustituidas, así que sin esta comprobación la primera
   vez que se cambia una se aceptaría 그대로 como "nueva". */
function password_uso_reciente($tabla, $id, $nueva, $cuantos = 5) {
    credenciales_asegurar();
    if (!in_array($tabla, credenciales_tablas(), true) || !$id) return '';
    try {
        $st = db()->prepare("SELECT password_hash FROM `$tabla` WHERE id = ?");
        $st->execute([(int)$id]);
        $actual = $st->fetchColumn();
        if ($actual !== false && $actual !== null && password_verify((string)$nueva, (string)$actual))
            return 'Es la misma contraseña que tienes ahora. Elige una distinta.';

        $q = db()->prepare('SELECT hash FROM password_history WHERE tabla=? AND usuario_id=? ORDER BY id DESC LIMIT ' . (int)$cuantos);
        $q->execute([$tabla, (int)$id]);
        while ($h = $q->fetch()) {
            if (password_verify((string)$nueva, (string)$h['hash']))
                return 'Esa contraseña ya la habías usado. Elige una distinta.';
        }
    } catch (Exception $e) {}
    return '';
}

/* El único sitio del ERP donde se escribe una contraseña.
   $op acepta: 'propia' => true si la persona la está cambiando para sí misma,
   'actor' => quién la está cambiando (por defecto, la sesión), y
   'sin_historial' => true para no repetir comprobación (altas nuevas). */
function credenciales_cambiar($tabla, $id, $nueva, $op = []) {
    credenciales_asegurar();
    if (!in_array($tabla, credenciales_tablas(), true)) throw new Exception('Tabla no permitida');
    $id = (int)$id;
    if (!$id) throw new Exception('Cuenta no válida');

    $nueva = (string)$nueva;
    $err   = password_valida($nueva);
    if ($err !== '') return ['ok' => 0, 'msg' => $err];
    if (empty($op['sin_historial'])) {
        $err = password_uso_reciente($tabla, $id, $nueva);
        if ($err !== '') return ['ok' => 0, 'msg' => $err];
    }
    /* Un solo hash, el mismo que se guarda y el que se anota en el historial.
       Con dos llamadas a password_hash() se obtenían dos cadenas distintas
       (bcrypt mete sal al azar) y la cuenta guardaba una que el historial no
       tenía. */
    $hash = password_hash($nueva, PASSWORD_DEFAULT);

    /* Se lee la que hay ahora antes de sobrescribirla. El historial guarda la
       contraseña que SALE, no la que entra: si solo se anotara la nueva, la
       original de siempre -la que se puso a mano al crear la cuenta, y que por
       tanto nunca pasó por aquí- seguiría pudiendo volver a usarse. */
    $viejo = null;
    try {
        $st = db()->prepare("SELECT password_hash FROM `$tabla` WHERE id = ?");
        $st->execute([$id]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) $viejo = (string)$v;
    } catch (Exception $e) {}

    /* La versión sube en la misma sentencia que escribe el hash: si se hiciera
       en dos, un fallo en medio dejaría el hash nuevo con la versión vieja, y
       las sesiones abiertas no caerían. */
    $q = db()->prepare("UPDATE `$tabla`
                           SET password_hash = ?,
                               cred_ver = cred_ver + 1,
                               password_changed_at = NOW()
                         WHERE id = ?");
    $q->execute([$hash, $id]);

    if (empty($op['sin_historial']) && $viejo !== null) {
        try {
            db()->prepare('INSERT INTO password_history (tabla, usuario_id, hash) VALUES (?,?,?)')
                ->execute([$tabla, $id, $viejo]);
        } catch (Exception $e) {}
    }

    if (function_exists('audit_log')) {
        $actor = current_admin();
        audit_log('password.' . $tabla,
            'cuenta #' . $id . (empty($op['propia']) ? ' por ' . ($actor['username'] ?? '?') : ' por la propia persona'));
    }
    return ['ok' => 1, 'msg' => ''];
}

/* Tras cambiar la contraseña, la sesión de quien la ha cambiado se queda viva y
   pasa a llevar la versión nueva: si no, se echaría a sí mismo al siguiente
   clic. Los demás, que llevan la versión antigua en su sesión, sí caen.

   El valor se lee de la base de datos y no de current_admin() a propósito: esa
   función tiene la fila en caché desde el principio de la petición, con la
   versión de antes del cambio. Si se usara, la sesión propia quedaría sellada
   con la versión vieja y el usuario se expulsaría a sí mismo en el siguiente
   clic, que es justo el fallo que se quería evitar. */
function credenciales_renovar_sesion($tabla, $id) {
    if (!in_array($tabla, credenciales_tablas(), true) || !$id) return;

    /* Solo se renueva la sesión de quien realmente la está cambiando. Si quien
       cambia la contraseña es un administrador sobre otra cuenta, esta petición
       no debe tocar su propia sesión. */
    $quien = $tabla === 'admins' ? ($_SESSION['admin_id'] ?? null) : ($_SESSION['client_id'] ?? null);
    if (!$quien || (int)$quien !== (int)$id) return;

    if (session_status() === PHP_SESSION_ACTIVE) {
        /* Identificador de sesión nuevo: si alguien tenía el anterior, no le
           sirve. Es la respuesta a un robo de cookie. */
        session_regenerate_id(true);
    }

    try {
        $st = db()->prepare("SELECT cred_ver FROM `$tabla` WHERE id = ?");
        $st->execute([(int)$id]);
        $v = $st->fetchColumn();
        $_SESSION['cred_ver'] = ($v === false || $v === null) ? 1 : (int)$v;
    } catch (Exception $e) {
        /* Si no se puede leer, no se sella nada: la comparación siguiente
           aceptará la sesión como estaba. Es preferible a dejar al usuario
           encerrado fuera por un fallo puntual de la base de datos. */
    }
}

/* sesion_cerrar() vive en sesion.php, no aquí: también la necesitan logout.php y
   admin/logout.php, que no cargan este fichero, y tiene que poder usarse sin
   base de datos. Ver sesion.php. */

