<?php
require_once __DIR__ . '/../_bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    header('Allow: POST');
    api_fail('Método no permitido', 405);
}
if (!can_edit()) api_fail('Sin permisos', 403);
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) api_fail('JSON inválido', 400);

$id = isset($data['id']) ? (int)$data['id'] : 0;
$cli = isset($data['client_id']) ? (int)$data['client_id'] : (isset($data['cli']) ? (int)$data['cli'] : 0);
$listId = isset($data['list_id']) ? (int)$data['list_id'] : 0;

$me = current_admin();
$meId = (int)$me['id'];

if (!$id) {
    if ($listId <= 0 || $cli <= 0 || trim((string)($data['titulo'] ?? '')) === '') {
        api_fail('Falta título o lista/cliente', 422);
    }
    $fields = [
        'client_id' => $cli,
        'list_id' => $listId,
        'titulo' => trim((string)$data['titulo']),
        'descripcion' => trim((string)($data['descripcion'] ?? '')),
        'estado' => in_array($data['estado'] ?? '', ['pendiente','en proceso','atemporal','completada']) ? $data['estado'] : 'pendiente',
        'responsable_id' => ($data['responsable_id'] ?? null) !== null && $data['responsable_id'] !== '' ? (int)$data['responsable_id'] : null,
        'prioridad' => (int)($data['prioridad'] ?? 0),
        'fecha_inicio' => ($data['fecha_inicio'] ?? '') !== '' ? $data['fecha_inicio'] : null,
        'due_date' => ($data['due_date'] ?? '') !== '' ? $data['due_date'] : null,
        'etiquetas' => trim((string)($data['etiquetas'] ?? '')),
        'visible_cliente' => !empty($data['visible_cliente']) ? 1 : 0,
        'titulo_cliente' => trim((string)($data['titulo_cliente'] ?? '')),
        'explicacion_cliente' => trim((string)($data['explicacion_cliente'] ?? '')),
        'mes' => trim((string)($data['mes'] ?? '')),
    ];
    $cols = implode(',', array_keys($fields));
    $ph = implode(',', array_map(fn($k)=>":$k", array_keys($fields)));
    db()->prepare("INSERT INTO tasks ($cols) VALUES ($ph)")->execute($fields);
    $id = (int)db()->lastInsertId();
    $newResp = $fields['responsable_id'] ? (int)$fields['responsable_id'] : 0;
    if ($newResp && function_exists('task_set_asignados')) task_set_asignados($id, [$newResp]);
    if ($newResp && function_exists('notif_task_assigned')) @notif_task_assigned($id, $newResp, $me['username']);
    if (function_exists('publicar_progreso')) @publicar_progreso($cli);
    api_ok(['id' => $id, 'created' => true]);
} else {
    $old = db()->prepare('SELECT * FROM tasks WHERE id=?');
    $old->execute([$id]);
    $tOld = $old->fetch();
    if (!$tOld) api_fail('Tarea no encontrada', 404);
    $cli = (int)$tOld['client_id'];
    $fields = [
        'titulo' => trim((string)($data['titulo'] ?? $tOld['titulo'])),
        'descripcion' => trim((string)($data['descripcion'] ?? $tOld['descripcion'])),
        'estado' => in_array($data['estado'] ?? $tOld['estado'], ['pendiente','en proceso','atemporal','completada']) ? ($data['estado'] ?? $tOld['estado']) : 'pendiente',
        'responsable_id' => array_key_exists('responsable_id', $data) ? (($data['responsable_id'] === null || $data['responsable_id'] === '') ? null : (int)$data['responsable_id']) : $tOld['responsable_id'],
        'prioridad' => (int)($data['prioridad'] ?? $tOld['prioridad']),
        'fecha_inicio' => array_key_exists('fecha_inicio', $data) ? (($data['fecha_inicio'] === '') ? null : $data['fecha_inicio']) : $tOld['fecha_inicio'],
        'due_date' => array_key_exists('due_date', $data) ? (($data['due_date'] === '') ? null : $data['due_date']) : $tOld['due_date'],
        'etiquetas' => trim((string)($data['etiquetas'] ?? $tOld['etiquetas'])),
        'visible_cliente' => !empty($data['visible_cliente']) ? 1 : 0,
        'titulo_cliente' => trim((string)($data['titulo_cliente'] ?? $tOld['titulo_cliente'])),
        'explicacion_cliente' => trim((string)($data['explicacion_cliente'] ?? $tOld['explicacion_cliente'])),
        'mes' => trim((string)($data['mes'] ?? $tOld['mes'])),
    ];
    $set = implode(', ', array_map(fn($k)=>"$k=:$k", array_keys($fields)));
    $params = $fields; $params['id'] = $id;
    db()->prepare("UPDATE tasks SET $set WHERE id=:id")->execute($params);
    $newResp = $fields['responsable_id'] ? (int)$fields['responsable_id'] : 0;
    $oldResp = (int)($tOld['responsable_id'] ?? 0);
    if ($newResp && $newResp !== $oldResp && function_exists('task_set_asignados')) task_set_asignados($id, [$newResp]);
    if ($newResp && $newResp !== $oldResp && function_exists('notif_task_assigned')) @notif_task_assigned($id, $newResp, $me['username']);
    if (function_exists('publicar_progreso')) @publicar_progreso($cli);
    api_ok(['id' => $id, 'updated' => true]);
}
