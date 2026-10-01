<?php
/* Buscador global del ERP.
   Una sola caja para encontrar cualquier cosa: clientes, tareas, contactos,
   negocios, facturas, tickets, proyectos y las propias páginas del panel.

   Dos modos, mismo motor:
     - buscar.php?q=texto&json=1  -> JSON, lo consume la paleta (Ctrl+K) del shell.
     - buscar.php?q=texto         -> página de resultados completa, para cuando
                                     hay más de los que caben en la paleta.

   Cada tabla se consulta dentro de su propio try: este ERP crea buena parte de
   sus tablas sobre la marcha, así que una que todavía no exista no puede tumbar
   la búsqueda entera. */
require_once __DIR__ . '/../auth.php';
require_admin();

/* ---------- motor ---------- */

/* Ejecuta una consulta y devuelve filas; si la tabla no existe, lista vacía. */
function bs_q($sql, $args = []) {
    try { $st = db()->prepare($sql); $st->execute($args); return $st->fetchAll(); }
    catch (Exception $e) { return []; }
}

/* Un grupo de resultados, en dos pasos.
 *
 * El problema de fondo: «LIKE '%x%'» no lo acelera NINGÚN índice, porque el
 * comodín inicial deja indeterminada la posición de la clave. No hay índice
 * compuesto, ni por fecha, ni FULLTEXT de MIMO que lo arregle: es un escaneo
 * de la tabla, siempre. Un CREATE INDEX al lado de esa consulta no cambia el
 * plan de ejecución y da la impresión de haberlo arreglado cuando no.
 *
 * Lo que sí funciona es el orden inverso. 'x%' sí usa el índice (db.php crea
 * ix_cli_name, ix_task_titulo, ix_inv_cliente_nombre… para esto), y además es lo
 * que se busca el 95 % de las veces: quien teclea «cro» quiere a Croilab, no una
 * empresa que lleve «cro» en medio del nombre. Así que primero la pasada
 * indexada por prefijo, y el escaneo completo solo si con ella no se ha llenado
 * el grupo. Es decir: el caso raro y el caro es el que no se ha resuelto, y el
 * frecuente es el que ya no toca la tabla entera.
 *
 * Si la primera pasada ya trae $lim filas, ni se lanza la segunda. */
function bs_dos_pasadas($sqlPrefijo, $argsPrefijo, $sqlLike, $argsLike, $lim, $clave = 'id') {
    $primera = bs_q($sqlPrefijo, $argsPrefijo);
    if (count($primera) >= $lim) return $primera;

    $vistos = [];
    foreach ($primera as $x) { $vistos[(string)($x[$clave] ?? '')] = true; }

    /* La segunda pasada se queda con $lim aunque la consulta traiga más, que es
       lo que hace cada LIMIT de arriba: el relleno no puede empujar al grupo
       fuera de su tope. */
    foreach (bs_q($sqlLike, $argsLike) as $x) {
        if (count($primera) >= $lim) break;
        $k = (string)($x[$clave] ?? '');
        if (isset($vistos[$k])) continue;   /* ya estaba por prefijo: no se repite */
        $vistos[$k] = true;
        $primera[] = $x;
    }
    return $primera;
}

/* Recorta un texto largo para que quepa en una línea del resultado. */
function bs_corta($t, $n = 90) {
    $t = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$t)));
    return mb_strlen($t) > $n ? mb_substr($t, 0, $n - 1) . '…' : $t;
}

/* Las páginas del panel también se buscan: escribir «factur» y que salte
   Facturas ahorra tener que acordarse de en qué menú vivía. */
function bs_paginas() {
    return [
        ['Dashboard',            'Resumen del día',                 'dashboard.php',            'home'],
        ['Todas las tareas',     'Tablero de trabajo',              'workspace.php?view=all',   'inbox'],
        ['Mis tareas',           'Lo que tienes asignado',          'workspace.php?view=mine',  'usercheck'],
        ['Calendario',           'Vencimientos y reuniones',        'calendar.php',             'cal'],
        ['CRM · Contactos',      'Leads y contactos',               'crm.php',                  'crm'],
        ['CRM · Negocio',        'Embudo de oportunidades',         'negocio.php',              'trend'],
        ['Clientes en alta',     'Fichas y accesos al portal',      'index.php',                'clients'],
        ['Facturas',             'Emitidas, borradores y cobros',   'facturas.php',             'file'],
        ['Contabilidad',         'Ingresos y gastos',               'contabilidad.php',         'euro'],
        ['Resumen financiero',   'Cierre por meses',                'fin-resumen.php',          'chart'],
        ['Horas de equipo',      'Horas y tarifas por persona',     'fin-horas.php',            'clock'],
        ['Tarifas y precios',    'Calculadora de propuestas',       'pricing.php',              'calc'],
        ['Proyectos',            'Agrupación de trabajo',           'proyectos.php',            'layers'],
        ['Soporte',              'Tickets de clientes',             'support.php',              'ticket'],
        ['Chat de equipo',       'Conversaciones internas',         'chat.php',                 'chat'],
        ['Credenciales',         'Bóveda de accesos',               'credenciales.php',         'vault'],
        ['Mi equipo',            'Personas y permisos',             'team.php',                 'user'],
        ['Agencias',             'Marca blanca',                    'agencias.php',             'building'],
        ['Servicios',            'Catálogo de servicios',           'servicios.php',            'list'],
        ['Tipos de cliente',     'Plantillas de alta',              'types.php',                'flag'],
        ['Ajustes',              'Configuración del ERP',           'settings.php',             'settings'],
        ['Reglas automáticas',   'Avisos automáticos (Ajustes)',   'automations.php',          'bolt'],
        ['Notificaciones',       'Tu bandeja',                      'notifications.php',        'bell'],
    ];
}

/* El buscador de verdad. Devuelve grupos en el orden en que se enseñan. */
function bs_buscar($q, $porGrupo = 6) {
    $q = trim((string)$q);
    if (mb_strlen($q) < 2) return [];
    $like = '%' . str_replace(['%','_'], ['\%','\_'], $q) . '%';
    $pre  = str_replace(['%','_'], ['\%','\_'], $q) . '%';
    $lim  = max(1, (int)$porGrupo);
    $g    = [];

    /* --- Clientes --- */
    $r = bs_dos_pasadas(
        "SELECT id,name,username,activo FROM clients
         WHERE name LIKE ? OR username LIKE ? OR fact_nombre LIKE ?
         ORDER BY name LIMIT $lim", [$pre,$pre,$pre],
        "SELECT id,name,username,activo FROM clients
         WHERE name LIKE ? OR username LIKE ? OR fact_nombre LIKE ? OR fact_nif LIKE ?
         ORDER BY (name LIKE ?) DESC, name LIMIT $lim", [$like,$like,$like,$like,$pre],
        $lim);
    if (!$r) $r = bs_q("SELECT id,name,username,1 AS activo FROM clients WHERE name LIKE ? OR username LIKE ? ORDER BY name LIMIT $lim", [$like,$like]);
    foreach ($r as $x) $g['Clientes'][] = [
        't'=>$x['name'], 's'=>'@'.$x['username'] . (((int)($x['activo'] ?? 1))===0 ? ' · dado de baja' : ''),
        'u'=>'client.php?id='.(int)$x['id'], 'i'=>'clients'];

    /* --- Equipo (empleados del panel) --- */
    $r = bs_dos_pasadas(
        "SELECT id,username,email,role FROM admins WHERE username LIKE ? OR email LIKE ? ORDER BY username LIMIT $lim", [$pre,$pre],
        "SELECT id,username,email,role FROM admins WHERE username LIKE ? OR email LIKE ? ORDER BY username LIMIT $lim", [$like,$like],
        $lim);
    $RLAB = ['owner'=>'Dueño','editor'=>'Editor','viewer'=>'Solo lectura'];
    foreach ($r as $x) $g['Equipo'][] = [
        't'=>$x['username'], 's'=>trim(($RLAB[$x['role']??'']??'Miembro') . ($x['email'] ? ' · '.$x['email'] : '')),
        'u'=>'perfil.php?id='.(int)$x['id'], 'i'=>'user'];

    /* --- Tareas --- */
    $r = bs_dos_pasadas(
        "SELECT t.id,t.titulo,t.estado,c.name AS cname FROM tasks t
         LEFT JOIN clients c ON c.id=t.client_id
         WHERE t.titulo LIKE ?
         ORDER BY (t.estado='completada'), t.updated_at DESC LIMIT $lim", [$pre],
        "SELECT t.id,t.titulo,t.estado,c.name AS cname FROM tasks t
         LEFT JOIN clients c ON c.id=t.client_id
         WHERE t.titulo LIKE ? OR t.descripcion LIKE ?
         ORDER BY (t.estado='completada'), t.updated_at DESC LIMIT $lim", [$like,$like],
        $lim, 'id');
    foreach ($r as $x) $g['Tareas'][] = [
        't'=>$x['titulo'], 's'=>trim(($x['cname'] ? $x['cname'].' · ' : '') . ($x['estado']==='pendiente'?'En espera':ucfirst((string)$x['estado']))),
        'u'=>'task.php?id='.(int)$x['id'], 'i'=>'check'];

    /* --- Contactos del CRM --- */
    $r = bs_dos_pasadas(
        "SELECT id,nombre,empresa,email,fase FROM contacts
         WHERE nombre LIKE ? OR empresa LIKE ?
         ORDER BY updated_at DESC LIMIT $lim", [$pre,$pre],
        "SELECT id,nombre,empresa,email,fase FROM contacts
         WHERE nombre LIKE ? OR empresa LIKE ? OR email LIKE ? OR telefono LIKE ?
         ORDER BY updated_at DESC LIMIT $lim", [$like,$like,$like,$like],
        $lim);
    foreach ($r as $x) $g['Contactos'][] = [
        't'=>$x['nombre'], 's'=>trim(($x['empresa'] ? $x['empresa'].' · ' : '') . ($x['email'] ?: str_replace('_',' ',(string)$x['fase']))),
        'u'=>'crm.php?open='.(int)$x['id'], 'i'=>'crm'];

    /* --- Negocios --- */
    $r = bs_dos_pasadas(
        "SELECT d.id,d.nombre,d.valor,d.fase,c.nombre AS cn FROM deals d
         LEFT JOIN contacts c ON c.id=d.contact_id
         WHERE d.nombre LIKE ?
         ORDER BY d.archivado, d.id DESC LIMIT $lim", [$pre],
        "SELECT d.id,d.nombre,d.valor,d.fase,c.nombre AS cn FROM deals d
         LEFT JOIN contacts c ON c.id=d.contact_id
         WHERE d.nombre LIKE ? OR d.servicio LIKE ? OR c.nombre LIKE ? OR c.empresa LIKE ?
         ORDER BY d.archivado, d.id DESC LIMIT $lim", [$like,$like,$like,$like],
        $lim, 'id');
    foreach ($r as $x) $g['Negocio'][] = [
        't'=>$x['nombre'] ?: 'Negocio #'.(int)$x['id'],
        's'=>trim(($x['cn'] ? $x['cn'].' · ' : '') . ((float)$x['valor']>0 ? number_format((float)$x['valor'],0,',','.').' €' : str_replace('_',' ',(string)$x['fase']))),
        'u'=>'negocio.php?open='.(int)$x['id'], 'i'=>'trend'];

    /* --- Facturas --- */
    $r = bs_dos_pasadas(
        "SELECT id,numero,cliente_nombre,estado,fecha FROM invoices
         WHERE numero LIKE ? OR cliente_nombre LIKE ?
         ORDER BY fecha DESC, id DESC LIMIT $lim", [$pre,$pre],
        "SELECT id,numero,cliente_nombre,estado,fecha FROM invoices
         WHERE numero LIKE ? OR cliente_nombre LIKE ? OR cliente_nif LIKE ? OR notas LIKE ?
         ORDER BY fecha DESC, id DESC LIMIT $lim", [$like,$like,$like,$like],
        $lim);
    foreach ($r as $x) $g['Facturas'][] = [
        't'=>($x['numero'] ?: 'Borrador #'.(int)$x['id']) . ' · ' . ($x['cliente_nombre'] ?: 'Sin cliente'),
        's'=>ucfirst((string)$x['estado']) . ($x['fecha'] ? ' · '.date('d/m/Y', strtotime($x['fecha'])) : ''),
        'u'=>'facturas.php?edit='.(int)$x['id'], 'i'=>'file'];

    /* --- Tickets de soporte --- */
    $r = bs_dos_pasadas(
        "SELECT s.id,s.asunto,s.estado,c.name AS cname FROM support_tickets s
         LEFT JOIN clients c ON c.id=s.client_id
         WHERE s.asunto LIKE ?
         ORDER BY (s.estado='cerrado'), s.updated_at DESC LIMIT $lim", [$pre],
        "SELECT s.id,s.asunto,s.estado,c.name AS cname FROM support_tickets s
         LEFT JOIN clients c ON c.id=s.client_id
         WHERE s.asunto LIKE ? OR s.cuerpo LIKE ?
         ORDER BY (s.estado='cerrado'), s.updated_at DESC LIMIT $lim", [$like,$like],
        $lim, 'id');
    foreach ($r as $x) $g['Soporte'][] = [
        't'=>$x['asunto'], 's'=>trim(($x['cname'] ? $x['cname'].' · ' : '') . ucfirst((string)$x['estado'])),
        'u'=>'support.php?t='.(int)$x['id'], 'i'=>'ticket'];

    /* --- Proyectos --- */
    $r = bs_dos_pasadas(
        "SELECT p.id,p.nombre,c.name AS cname FROM projects p
         LEFT JOIN clients c ON c.id=p.client_id
         WHERE p.nombre LIKE ? ORDER BY p.activo DESC, p.nombre LIMIT $lim", [$pre],
        "SELECT p.id,p.nombre,c.name AS cname FROM projects p
         LEFT JOIN clients c ON c.id=p.client_id
         WHERE p.nombre LIKE ? ORDER BY p.activo DESC, p.nombre LIMIT $lim", [$like],
        $lim, 'id');
    foreach ($r as $x) $g['Proyectos'][] = [
        't'=>$x['nombre'], 's'=>$x['cname'] ?: 'Interno',
        'u'=>'proyectos.php#p'.(int)$x['id'], 'i'=>'layers'];

    /* --- Páginas del panel --- */
    $qn = mb_strtolower($q); $pg = [];
    foreach (bs_paginas() as $p) {
        if (mb_strpos(mb_strtolower($p[0].' '.$p[1]), $qn) !== false) {
            $pg[] = ['t'=>$p[0], 's'=>$p[1], 'u'=>$p[2], 'i'=>$p[3]];
            if (count($pg) >= $lim) break;
        }
    }
    if ($pg) $g['Ir a'] = $pg;

    /* «Ir a» primero cuando el texto es corto: si escribes «fact» casi siempre
       quieres la sección, no la factura número 4 de un cliente. */
    if (isset($g['Ir a']) && mb_strlen($q) <= 4) $g = ['Ir a'=>$g['Ir a']] + $g;
    return $g;
}

/* ---------- modo JSON ---------- */
$q = (string)($_GET['q'] ?? '');
if (isset($_GET['json'])) {
    $grupos = bs_buscar($q, 5);
    $out = []; $n = 0;
    foreach ($grupos as $nombre => $items) { $out[] = ['g'=>$nombre, 'r'=>$items]; $n += count($items); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true, 'q'=>$q, 'n'=>$n, 'grupos'=>$out], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- página completa ---------- */
require_once __DIR__ . '/erp_nav.php';
$grupos = bs_buscar($q, 40);
$total = 0; foreach ($grupos as $it) $total += count($it);
erp_head('', 'Buscar');
?>
<style>
.bs-box{display:flex;align-items:center;gap:10px;border:1px solid var(--line);background:#fff;border-radius:14px;padding:14px 18px;margin-bottom:20px}
.bs-box svg{color:var(--muted);flex:none}
.bs-box input{flex:1;border:none;outline:none;font-family:inherit;font-size:16px;color:var(--ink-strong);background:none}
.bs-g{margin-bottom:24px}
.bs-g h2{font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin:0 0 10px}
.bs-r{display:flex;align-items:center;gap:14px;padding:13px 15px;border:1px solid var(--line);border-radius:12px;background:#fff;text-decoration:none;margin-bottom:8px}
.bs-r:hover{border-color:#dcdcde;background:var(--soft)}
.bs-r .ic{width:32px;height:32px;border-radius:9px;background:var(--soft);color:var(--ink-strong);display:flex;align-items:center;justify-content:center;flex:none}
.bs-r .t{font-size:14px;font-weight:600;color:var(--ink-strong)}
.bs-r .s{font-size:12.5px;color:var(--muted);margin-top:3px}
.bs-empty{color:var(--muted);font-size:14px;padding:26px 0}
/* Modo oscuro: caja de búsqueda y filas de resultado. */
[data-theme=dark] .bs-box{background-color:var(--card)}
[data-theme=dark] .bs-r{background-color:var(--card)}
[data-theme=dark] .bs-r:hover{border-color:var(--line-strong)}
</style>
<h1 style="font-size:22px;margin-bottom:14px">Buscar</h1>
<form method="get" class="bs-box">
  <?= ic('search',18) ?>
  <input name="q" value="<?= e($q) ?>" placeholder="Cliente, tarea, contacto, negocio, factura, ticket…" autofocus autocomplete="off">
</form>
<?php if (mb_strlen(trim($q)) < 2): ?>
  <div class="bs-empty">Escribe al menos dos letras. También puedes abrir esta búsqueda desde cualquier página con <b>Ctrl + K</b>.</div>
<?php elseif (!$total): ?>
  <div class="bs-empty">Nada coincide con «<?= e($q) ?>».</div>
<?php else: ?>
  <div class="muted" style="margin-bottom:16px"><?= $total ?> resultado<?= $total===1?'':'s' ?> para «<?= e($q) ?>»</div>
  <?php foreach ($grupos as $nombre => $items): ?>
    <div class="bs-g">
      <h2><?= e($nombre) ?></h2>
      <?php foreach ($items as $it): ?>
        <a class="bs-r" href="<?= e($it['u']) ?>">
          <span class="ic"><?= ic($it['i'],16) ?></span>
          <span><span class="t" style="display:block"><?= e(bs_corta($it['t'],120)) ?></span><span class="s" style="display:block"><?= e(bs_corta($it['s'],120)) ?></span></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php erp_foot(); ?>
