<?php
require_once __DIR__ . '/../_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); api_fail('Método no permitido', 405); }
if (!can_edit()) api_fail('Sin permisos', 403);
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$id = (int)($data['id'] ?? 0);
$field = $data['field'] ?? '';
$val = (string)($data['val'] ?? '');
if (!$id || $field==='') api_fail('Datos inválidos', 400);
$allowed = ['titulo','descripcion','estado','prioridad','fecha_inicio','due_date','etiquetas','mes','responsable_id'];
if (!in_array($field, $allowed, true)) api_fail('Campo no permitido', 422);
$t = db()->prepare('SELECT * FROM tasks WHERE id=?'); $t->execute([$id]); $tOld=$t->fetch(); if(!$tOld) api_fail('No encontrada',404);
$cli=(int)$tOld['client_id'];
$store = null;
if ($field==='estado') { $store = in_array($val,['pendiente','en proceso','atemporal','completada']) ? $val : 'pendiente'; }
elseif ($field==='prioridad') { $store = (int)$val; }
elseif (in_array($field,['fecha_inicio','due_date'],true)) { $store = ($val!=='' ? $val : null); }
elseif ($field==='responsable_id') { $store = ($val!=='' ? (int)$val : null); }
else { $store = trim($val); }
if ($field==='titulo' && $store==='') api_fail('Título obligatorio', 422);
db()->prepare("UPDATE tasks SET `$field`=? WHERE id=?")->execute([$store, $id]);
$me = current_admin()['username'];
if ($field==='responsable_id' && $store && (int)$store !== (int)($tOld['responsable_id']??0)) {
    if (function_exists('task_set_asignados')) @task_set_asignados($id, [(int)$store]);
    if (function_exists('notif_task_assigned')) @notif_task_assigned($id,(int)$store,$me);
}
if (function_exists('publicar_progreso')) @publicar_progreso($cli);
if (function_exists('notif_task_activity')) {
    if ($field==='estado' && $store==='en proceso' && (string)($tOld['estado']??'')!=='en proceso') @notif_task_activity($id,'start','',$me);
    if (in_array($field,['fecha_inicio','due_date'],true) && $store && (string)($tOld[$field]??'')!==(string)$store) {
        $lbl = $field==='due_date' ? 'fecha límite' : 'inicio';
        @notif_task_activity($id,'date',$lbl.' '.date('d/m/Y',strtotime($store)),$me);
    }
}
api_ok(['updated'=>true]);
