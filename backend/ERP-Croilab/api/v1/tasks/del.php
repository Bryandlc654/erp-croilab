<?php
require_once __DIR__ . '/../_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); api_fail('Método no permitido', 405); }
if (!can_edit()) api_fail('Sin permisos', 403);
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$id = isset($data['id']) ? (int)$data['id'] : 0;
$cli = isset($data['cli']) ? (int)$data['cli'] : (isset($data['client_id']) ? (int)$data['client_id'] : 0);
if (!$id) api_fail('Falta id', 400);
$t = db()->prepare('SELECT titulo,client_id FROM tasks WHERE id=?');
$t->execute([$id]);
$row = $t->fetch();
if (!$row) api_fail('No encontrada', 404);
$cli = (int)($row['client_id'] ?? $cli);
$tn = (string)$row['titulo'];
if ($tn !== '' && function_exists('pap_borrar_flash')) {
    try { db()->exec("DELETE r FROM task_comment_reactions r JOIN task_comments c ON c.id=r.comment_id WHERE c.task_id=".$id); } catch (Exception $e) {}
    @pap_borrar_flash('tasks', $id, 'tarea', $tn, [['tabla'=>'task_comments','fk'=>'task_id'],['tabla'=>'task_checklist','fk'=>'task_id'],['tabla'=>'task_attachments','fk'=>'task_id']], 'Tarea «'.$tn.'» eliminada');
}
db()->prepare('DELETE FROM tasks WHERE id=?')->execute([$id]);
if ($cli && function_exists('publicar_progreso')) @publicar_progreso($cli);
api_ok(['deleted' => true]);
