<?php
require_once __DIR__ . '/_bootstrap.php';

$view = $_GET['view'] ?? 'all';
$cli  = isset($_GET['cli']) ? (int)$_GET['cli'] : 0;
$emp  = isset($_GET['emp']) ? (int)$_GET['emp'] : 0;
$fEstado = $_GET['fe'] ?? '';
$fResp   = $_GET['fr'] ?? '';

$meId = (int)current_admin()['id'];
$lists = [];
$curList = 0;
$curListTipo = 'tareas';
$porEstado = ['pendiente'=>[], 'en proceso'=>[], 'atemporal'=>[], 'completada'=>[]];
$mesGroups = [];

if ($view === 'cliente' && $cli) {
    $st = db()->prepare('SELECT id,name,username FROM clients WHERE id=?');
    $st->execute([$cli]);
    $curClient = $st->fetch();
    if (!$curClient) api_fail('Cliente no encontrado', 404);
    $ls = db()->prepare('SELECT * FROM task_lists WHERE client_id=? ORDER BY orden,id');
    $ls->execute([$cli]);
    $lists = $ls->fetchAll();
    $curList = isset($_GET['list']) ? (int)$_GET['list'] : (count($lists) ? (int)$lists[0]['id'] : 0);
    if (isset($_GET['informe'])) {
        $infId = 0;
        foreach ($lists as $ll) {
            if (($ll['tipo'] ?? '') === 'informe') { $infId = (int)$ll['id']; break; }
        }
        if (!$infId && can_edit()) {
            $mo = (int)db()->query('SELECT COALESCE(MAX(orden),0)+1 FROM task_lists WHERE client_id=' . $cli)->fetchColumn();
            db()->prepare("INSERT INTO task_lists (client_id,nombre,es_cliente,tipo,orden) VALUES (?, 'INFORMES CLIENTE', 0, 'informe', ?)")->execute([$cli, $mo]);
            $infId = (int)db()->lastInsertId();
            $ls->execute([$cli]); $lists = $ls->fetchAll();
        }
        if ($infId) $curList = $infId;
    }
    foreach ($lists as $ll) {
        if ($ll['id'] == $curList) $curListTipo = $ll['tipo'] ?? 'tareas';
    }
    $porEstado = ['pendiente'=>[], 'en proceso'=>[], 'atemporal'=>[], 'completada'=>[]];
    $mesGroups = [];
    if ($curList) {
        $t = db()->prepare('SELECT t.* FROM tasks t WHERE t.list_id=? AND t.client_id=? ORDER BY t.orden,t.id');
        $t->execute([$curList, $cli]);
        foreach ($t as $r) {
            $e = $r['estado'];
            if (!isset($porEstado[$e])) $e = 'pendiente';
            $porEstado[$e][] = $r;
            $mm = trim((string)($r['mes'] ?? '')) !== '' ? trim((string)$r['mes']) : 'Sin mes';
            $mesGroups[$mm][] = $r;
        }
    }
} else {
    $w = []; $p = [];
    if ($view === 'mine') { $w[] = 't.responsable_id=?'; $p[] = $meId; }
    if ($view === 'emp' && $emp) { $w[] = 't.responsable_id=?'; $p[] = $emp; }
    if ($fEstado !== '') { $w[] = 't.estado=?'; $p[] = $fEstado; }
    if ($fResp !== '') { $w[] = 't.responsable_id=?'; $p[] = (int)$fResp; }
    if ($fEstado === '') { $w[] = "t.estado<>'completada'"; }
    $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
    $sql = "SELECT t.*, c.name AS client_name, c.username AS client_username, l.nombre AS list_name
            FROM tasks t
            LEFT JOIN clients c ON c.id = t.client_id
            LEFT JOIN task_lists l ON l.id = t.list_id
            $where
            ORDER BY t.due_date IS NULL, t.due_date, t.prioridad DESC, t.id DESC
            LIMIT 500";
    $st = db()->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    api_ok(['view' => $view, 'tasks' => $rows, 'filters' => ['fe' => $fEstado, 'fr' => $fResp]]);
    return;
}

api_ok([
    'view' => $view,
    'cli' => $cli,
    'curList' => $curList,
    'curListTipo' => $curListTipo,
    'lists' => $lists,
    'porEstado' => $porEstado,
    'mesGroups' => $mesGroups,
]);
