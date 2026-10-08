<?php
/* Avisos del equipo (tabla `notifications`). Portado de admin/erp_nav.php del
   ERP antiguo, que se borró al pasar a API: sin esto la API no avisaba de
   nada (los servicios comprobaban function_exists y se callaban).

   Las URLs que se guardan son rutas del front nuevo (docs/migracion/RUTAS.md).
   `ref` + UNIQUE (admin_id, ref) evita repetir un mismo hecho: INSERT IGNORE.
   Bandejas: 'principal' (lo tuyo) y 'otras' (actividad del equipo). */

/* Categoría silenciable. Las asignaciones y menciones de tareas no se pueden
   silenciar (te perderías trabajo asignado); el chat y los avisos sí. */
function notif_cat($tipo) {
    if ($tipo === 'chat') return 'chat';
    if (in_array($tipo, ['bell', 'file', 'inbox', 'info'], true)) return 'avisos';
    return '';
}

function notif_add($adminId, $tipo, $titulo, $cuerpo, $url = '', $ref = null, $tarea = '', $actor = '', $bandeja = 'principal') {
    $adminId = (int)$adminId;
    if (!$adminId) return;
    $cat = notif_cat($tipo);
    if ($cat !== '' && function_exists('get_setting')) {
        $mute = (string)get_setting('notifmute_' . $adminId, '');
        if ($mute !== '' && in_array($cat, array_map('trim', explode(',', $mute)), true)) return;
    }
    $bandeja = $bandeja === 'otras' ? 'otras' : 'principal';
    try {
        db()->prepare('INSERT IGNORE INTO notifications (admin_id, tipo, titulo, cuerpo, url, ref, tarea, actor, bandeja) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$adminId, (string)$tipo, mb_substr((string)$titulo, 0, 200), mb_substr((string)$cuerpo, 0, 400),
                       mb_substr((string)$url, 0, 200), $ref, mb_substr((string)$tarea, 0, 200), mb_substr((string)$actor, 0, 120), $bandeja]);
    } catch (Throwable $e) {
        error_log('notif_add: ' . $e->getMessage());
    }
}

function notif_yo_id() {
    $me = function_exists('current_admin') ? current_admin() : null;
    return (int)($me['id'] ?? 0);
}

/* username en minúsculas => id, para las @menciones. Sin caché estática: se
   llama una vez por comentario y una caché por proceso se quedaba vieja
   (personas nuevas o renombradas en procesos largos: cron, tests). */
function notif_admin_map() {
    $m = [];
    try { foreach (db()->query('SELECT id, username FROM admins WHERE activo = 1') as $r) $m[mb_strtolower($r['username'])] = (int)$r['id']; }
    catch (Throwable $e) {}
    return $m;
}

function task_ctx($taskId) {
    try {
        $q = db()->prepare('SELECT t.titulo, t.responsable_id, c.name AS cname FROM tasks t JOIN clients c ON c.id = t.client_id WHERE t.id = ?');
        $q->execute([(int)$taskId]);
        return $q->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/* Personas activas cuyo rol tiene ese permiso (o acceso total), menos $excluir.
   Lee los roles de la base cada vez: la caché estática de roles_todos() se
   quedaba vieja en procesos largos (cron, tests) y avisaba a quien no tocaba. */
function notif_con_permiso($permiso, $excluir = 0) {
    $ids = [];
    try {
        $roles = [];
        try {
            foreach (db()->query('SELECT clave, permisos FROM roles') as $r) {
                $p = json_decode((string)$r['permisos'], true);
                $roles[(string)$r['clave']] = is_array($p) ? $p : [];
            }
        } catch (Throwable $e) {
            foreach ((function_exists('roles_todos') ? roles_todos() : []) as $k => $r) $roles[$k] = $r['permisos'] ?? [];
        }
        $con = [];
        foreach ($roles as $k => $p) {
            if (in_array('admin.total', $p, true) || in_array($permiso, $p, true)) $con[] = $k;
        }
        if (!$con) return [];
        $st = db()->prepare('SELECT id FROM admins WHERE activo = 1 AND role IN (' . implode(',', array_fill(0, count($con), '?')) . ')');
        $st->execute($con);
        foreach ($st as $r) if ((int)$r['id'] !== (int)$excluir) $ids[] = (int)$r['id'];
    } catch (Throwable $e) {}
    return $ids;
}

/* Personas con acceso total (les importan los hechos de negocio), menos $excluir. */
function notif_duenos($excluir = 0) {
    return notif_con_permiso('admin.total', $excluir);
}

function notif_unread($adminId) {
    try {
        $q = db()->prepare("SELECT COUNT(*) FROM notifications WHERE admin_id = ? AND leido = 0 AND borrado = 0 AND tipo <> 'chat'
                            AND (snooze_until IS NULL OR snooze_until <= NOW())");
        $q->execute([(int)$adminId]);
        return (int)$q->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/* ---------- Tareas ---------- */

function notif_task_assigned($taskId, $targetId, $byName = '') {
    $targetId = (int)$targetId;
    if (!$targetId || $targetId === notif_yo_id()) return;   // no avisarte a ti mismo
    $c = task_ctx($taskId);
    if (!$c) return;
    notif_add($targetId, 'tarea', 'te ha asignado esta tarea', (string)$c['cname'], '/tareas/' . (int)$taskId, null, (string)$c['titulo'], (string)$byName);
}

/* A los dueños: alguien ha puesto en marcha una tarea o le ha puesto fecha. A
   «Principal» si ese dueño está asignado; si no, a «Otras». Una vez al día. */
function notif_task_activity($taskId, $kind, $detalle = '', $byName = '') {
    $c = task_ctx($taskId);
    if (!$c) return;
    $asignados = array_fill_keys(function_exists('task_asignados') ? task_asignados($taskId) : [], true);
    $verbo = ['start' => 'ha puesto en marcha una tarea', 'date' => 'le ha puesto fecha a una tarea'][$kind] ?? 'ha actualizado una tarea';
    if (trim((string)$detalle) !== '') $verbo .= ' · ' . trim((string)$detalle);
    $hoy = date('Y-m-d');
    foreach (notif_duenos(notif_yo_id()) as $uid) {
        notif_add($uid, 'tarea', $verbo, (string)$c['cname'], '/tareas/' . (int)$taskId,
                  "taskact:$kind:" . (int)$taskId . ":$hoy:$uid", (string)$c['titulo'], (string)$byName, isset($asignados[$uid]) ? 'principal' : 'otras');
    }
}

function notif_check_assigned($taskId, $targetId, $texto = '', $byName = '') {
    $targetId = (int)$targetId;
    if (!$targetId || $targetId === notif_yo_id()) return;
    $c = task_ctx($taskId);
    notif_add($targetId, 'tarea', 'te asignó un punto de la lista de control: ' . mb_substr(trim((string)$texto), 0, 90),
              (string)($c['cname'] ?? ''), '/tareas/' . (int)$taskId . '#chk', null, (string)($c['titulo'] ?? ''), (string)$byName);
}

/* Punto marcado o desmarcado: avisa a los asignados de la tarea y al del punto. */
function notif_check_done($taskId, $chkId, $done, $texto = '', $byName = '') {
    $c = task_ctx($taskId);
    if (!$c) return;
    $dest = [];
    foreach ((function_exists('task_asignados') ? task_asignados($taskId) : []) as $aid) $dest[(int)$aid] = true;
    if (!empty($c['responsable_id'])) $dest[(int)$c['responsable_id']] = true;
    try {
        $q = db()->prepare('SELECT responsable_id FROM task_checklist WHERE id = ? AND task_id = ?');
        $q->execute([(int)$chkId, (int)$taskId]);
        if ($r = (int)$q->fetchColumn()) $dest[$r] = true;
    } catch (Throwable $e) {}
    unset($dest[notif_yo_id()]);
    $verbo = $done ? 'completó un punto de la lista de control' : 'desmarcó un punto de la lista de control';
    if (trim((string)$texto) !== '') $verbo .= ': ' . mb_substr(trim((string)$texto), 0, 90);
    foreach (array_keys($dest) as $uid) {
        notif_add($uid, 'tarea', $verbo, (string)$c['cname'], '/tareas/' . (int)$taskId . '#chk',
                  'chkdone:' . (int)$chkId . ':' . ($done ? 1 : 0) . ':' . $uid, (string)$c['titulo'], (string)$byName);
    }
}

/* @menciones de un texto => ids (sin repetir ni contarte a ti). */
function notif_menciones($texto) {
    $map = notif_admin_map();
    $yo = notif_yo_id();
    $ids = [];
    if ($map && preg_match_all('/@([\p{L}0-9_.\-]+)/u', (string)$texto, $mm)) {
        foreach ($mm[1] as $nombre) {
            $id = $map[mb_strtolower($nombre)] ?? 0;
            if ($id && $id !== $yo) $ids[$id] = true;
        }
    }
    return array_keys($ids);
}

/* Comentario: avisa a los mencionados y al responsable de la tarea. */
function notif_comment_scan($taskId, $commentId, $body, $byName = '') {
    $c = task_ctx($taskId);
    $tarea = (string)($c['titulo'] ?? '');
    $plano = trim(preg_replace('/\s+/', ' ', str_replace('[[img]]', '[img]', strip_tags((string)$body))));
    $resumen = mb_substr($plano, 0, 160);
    $url = '/tareas/' . (int)$taskId . '#c' . (int)$commentId;
    $avisados = [];
    foreach (notif_menciones($body) as $id) {
        $avisados[$id] = true;
        notif_add($id, 'tarea', 'te ha mencionado: ' . $resumen, '', $url, 'cmt:' . (int)$commentId . ":$id", $tarea, (string)$byName);
    }
    $rid = (int)($c['responsable_id'] ?? 0);
    if ($rid && $rid !== notif_yo_id() && !isset($avisados[$rid])) {
        notif_add($rid, 'tarea', 'ha comentado en tu tarea: ' . $resumen, '', $url, 'cmt:' . (int)$commentId . ":$rid", $tarea, (string)$byName);
    }
}

/* Menciones en la descripción. Ref estable por (tarea, persona): el
   autoguardado no repite el aviso. */
function notif_desc_scan($taskId, $body, $byName = '') {
    $c = task_ctx($taskId);
    foreach (notif_menciones($body) as $id) {
        notif_add($id, 'tarea', 'te ha mencionado en una tarea', '', '/tareas/' . (int)$taskId,
                  'descmention:' . (int)$taskId . ":$id", (string)($c['titulo'] ?? ''), (string)$byName);
    }
}

/* ---------- Otros hechos ---------- */

function notif_ticket_assigned($ticketId, $targetId, $byName = '') {
    $targetId = (int)$targetId;
    if (!$targetId || $targetId === notif_yo_id()) return;
    try {
        $q = db()->prepare('SELECT t.asunto, c.name cname FROM support_tickets t LEFT JOIN clients c ON c.id = t.client_id WHERE t.id = ?');
        $q->execute([(int)$ticketId]);
        if (!$r = $q->fetch()) return;
        notif_add($targetId, 'ticket', 'te ha asignado este ticket', $r['cname'] ? (string)$r['cname'] : 'Sin cliente',
                  '/soporte/' . (int)$ticketId, 'tkassign:' . (int)$ticketId . ":$targetId", (string)$r['asunto'], (string)$byName);
    } catch (Throwable $e) {}
}

function notif_invoice_paid($invoiceId, $byName = '') {
    try {
        $q = db()->prepare('SELECT numero, cliente_nombre, fecha_pago FROM invoices WHERE id = ?');
        $q->execute([(int)$invoiceId]);
        if (!$v = $q->fetch()) return;
        $ref = 'invpaid:' . (int)$invoiceId . ':' . ($v['fecha_pago'] ?: date('Y-m-d'));
        foreach (notif_duenos(notif_yo_id()) as $t) {
            notif_add($t, 'info', 'factura cobrada ' . (string)$v['numero'], trim((string)($v['cliente_nombre'] ?: 'Cliente')),
                      '/finanzas/facturas/' . (int)$invoiceId, $ref, '', (string)$byName);
        }
    } catch (Throwable $e) {}
}

function notif_client_new($clientId, $byName = '') {
    try {
        $q = db()->prepare('SELECT name FROM clients WHERE id = ?');
        $q->execute([(int)$clientId]);
        $nombre = (string)$q->fetchColumn();
        if ($nombre === '') return;
        foreach (notif_duenos(notif_yo_id()) as $t) {
            notif_add($t, 'info', 'nuevo cliente de alta', $nombre, '/clientes/' . (int)$clientId, 'clinew:' . (int)$clientId, '', (string)$byName);
        }
    } catch (Throwable $e) {}
}

/* ---------- Sincronizaciones (sin acción que las dispare) ----------
   El ERP antiguo las lanzaba al pintar el menú; aquí las llama el endpoint de
   avisos o el cron. Son idempotentes gracias a `ref`. */

/* Facturas vencidas: solo las emitidas (con número; ni borradores ni anuladas)
   y solo a quien ve Finanzas. */
function notif_sync_invoices() {
    try {
        db()->exec("UPDATE invoices SET estado = 'vencida' WHERE estado = 'enviada' AND numero IS NOT NULL AND numero <> ''
                    AND fecha_venc IS NOT NULL AND fecha_venc < CURDATE()");
        $admins = notif_con_permiso('ver.finanzas');
        if (!$admins) return;
        foreach (db()->query("SELECT id, numero, cliente_nombre, fecha_venc FROM invoices WHERE estado = 'vencida' AND numero IS NOT NULL AND numero <> ''") as $v) {
            $cuerpo = ($v['cliente_nombre'] ?: 'Cliente') . ' · vencía ' . ($v['fecha_venc'] ? date('d/m/Y', strtotime($v['fecha_venc'])) : '');
            foreach ($admins as $t) notif_add($t, 'factura', 'Factura vencida ' . $v['numero'], $cuerpo, '/finanzas/facturas/' . (int)$v['id'], 'inv:' . $v['id']);
        }
    } catch (Throwable $e) {}
}

function notif_sync_meeting_requests() {
    try {
        $rows = db()->query("SELECT r.id, c.name AS cliente FROM portal_meeting_requests r JOIN clients c ON c.id = r.client_id WHERE r.estado = 'pendiente'")->fetchAll();
        if (!$rows) return;
        $duenos = notif_duenos();
        foreach ($rows as $r) foreach ($duenos as $t) {
            notif_add($t, 'info', 'Nueva solicitud de reunión', ($r['cliente'] ?: 'Un cliente') . ' ha pedido una reunión desde su portal', '/reuniones', 'meetreq:' . $r['id']);
        }
    } catch (Throwable $e) {}
}

/* «Toca contactar»: contactos del CRM en fase abierta con la próxima acción vencida. */
function notif_sync_leads() {
    try {
        $admins = db()->query('SELECT id FROM admins WHERE activo = 1')->fetchAll(PDO::FETCH_COLUMN);
        if (!$admins) return;
        $abiertas = [];
        try { $abiertas = db()->query("SELECT slug FROM pipeline_stages WHERE tipo NOT IN ('ganada','perdida','pausa')")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
        $sql = "SELECT id, nombre, empresa, fecha_prox, propietario_id, proxima_accion FROM contacts
                WHERE fecha_prox IS NOT NULL AND fecha_prox <> '0000-00-00' AND fecha_prox <= CURDATE()";
        if ($abiertas) $sql .= ' AND fase IN (' . implode(',', array_fill(0, count($abiertas), '?')) . ')';
        $st = db()->prepare($sql);
        $st->execute($abiertas);
        foreach ($st as $l) {
            $quien = trim((string)($l['empresa'] ?: $l['nombre'])) ?: 'un contacto';
            $cuerpo = (trim((string)$l['proxima_accion']) !== '' ? $l['proxima_accion'] : 'Seguimiento pendiente') . ' · ' . date('d/m/Y', strtotime($l['fecha_prox']));
            foreach ($l['propietario_id'] ? [(int)$l['propietario_id']] : $admins as $t) {
                notif_add($t, 'lead', 'Toca contactar a ' . $quien, $cuerpo, '/crm/contactos/' . (int)$l['id'], 'cto:' . $l['id'] . ':' . $l['fecha_prox']);
            }
        }
    } catch (Throwable $e) {}
}

/* Cumpleaños: a todo el equipo menos al cumpleañero, uno por persona y día. */
function notif_sync_birthdays() {
    try {
        $st = db()->prepare("SELECT p.admin_id, a.username FROM admin_profiles p JOIN admins a ON a.id = p.admin_id
                             WHERE a.activo = 1 AND p.cumple IS NOT NULL AND DATE_FORMAT(p.cumple, '%m-%d') = ?");
        $st->execute([date('m-d')]);
        $cumplen = $st->fetchAll();
        if (!$cumplen) return;
        $todos = db()->query('SELECT id FROM admins WHERE activo = 1')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cumplen as $c) foreach ($todos as $t) {
            if ((int)$t === (int)$c['admin_id']) continue;
            notif_add($t, 'info', '🎂 hoy cumple años ' . $c['username'], 'Felicítale cuando puedas', '/perfil/' . (int)$c['admin_id'],
                      'bday:' . (int)$c['admin_id'] . ':' . date('Y-m-d'), '', (string)$c['username']);
        }
    } catch (Throwable $e) {}
}

/* Todas las sincronizaciones, como mucho una vez cada 10 minutos (las llama el
   endpoint de avisos, que el front consulta cada pocos segundos). */
function notif_sync_todo() {
    $marca = sys_get_temp_dir() . '/croilab-notif-sync-' . md5((string)(defined('DB_NAME') ? DB_NAME : '')) . '.txt';
    if (is_file($marca) && time() - (int)@filemtime($marca) < 600) return;
    @touch($marca);
    notif_sync_invoices();
    notif_sync_meeting_requests();
    notif_sync_leads();
    notif_sync_birthdays();
}
