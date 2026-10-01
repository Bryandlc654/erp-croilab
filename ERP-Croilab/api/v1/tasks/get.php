<?php
require_once __DIR__ . '/../_bootstrap.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) api_fail('Falta id', 400);

$t = db()->prepare('SELECT t.*, c.name AS client_name, l.nombre AS list_name FROM tasks t LEFT JOIN clients c ON c.id=t.client_id LEFT JOIN task_lists l ON l.id=t.list_id WHERE t.id=?');
$t->execute([$id]);
$task = $t->fetch();
if (!$task) api_fail('Tarea no encontrada', 404);

$responsables = db()->query('SELECT id,username FROM admins ORDER BY username')->fetchAll();
$asignados = function_exists('task_asignados') ? task_asignados($id) : [];

$comments = db()->prepare('SELECT c.*, a.username FROM task_comments c LEFT JOIN admins a ON a.id=c.admin_id WHERE c.task_id=? ORDER BY c.created_at ASC,c.id ASC');
$comments->execute([$id]);
$comments = $comments->fetchAll();
$cmById = [];
foreach ($comments as $cc) $cmById[(int)$cc['id']] = $cc;

$checklist = db()->prepare('SELECT * FROM task_checklist WHERE task_id=? ORDER BY done DESC,orden,id');
$checklist->execute([$id]);
$checklist = $checklist->fetchAll();
$chkAsgMap = function_exists('chk_asignados_map') ? chk_asignados_map($id) : [];

$atts = db()->prepare('SELECT * FROM task_attachments WHERE task_id=? ORDER BY id');
$atts->execute([$id]);
$atts = $atts->fetchAll();

$cmReacts = [];
$cids = array_map(fn($x)=> (int)$x['id'], $comments);
if ($cids) {
    $in = implode(',', $cids);
    $rs = db()->query("SELECT comment_id, emoji, COUNT(*) c, MAX(CASE WHEN admin_id=".(int)current_admin()['id']." THEN 1 ELSE 0 END) mine FROM task_comment_reactions WHERE comment_id IN ($in) GROUP BY comment_id, emoji");
    foreach ($rs as $r) {
        $cmReacts[(int)$r['comment_id']][] = $r;
    }
}

api_ok([
    'task' => $task,
    'responsables' => $responsables,
    'asignados' => $asignados,
    'comments' => $comments,
    'cmById' => $cmById,
    'checklist' => $checklist,
    'chkAsgMap' => $chkAsgMap,
    'atts' => $atts,
    'cmReacts' => $cmReacts,
]);
