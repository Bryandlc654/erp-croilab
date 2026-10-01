<?php
/* Guarda los cambios del editor visual (modo edición del portal).
   Recibe JSON por POST y actualiza el cliente. Métricas NO se tocan. */
require_once __DIR__ . '/../auth.php';
require_can_edit();
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in) || empty($in['id'])) { echo json_encode(['ok'=>false,'msg'=>'Datos incompletos.']); exit; }

$id = (int)$in['id'];
/* Alcance: un miembro con rol limitado solo guarda cambios de SUS clientes. */
if (function_exists('alcance_ve_cliente') && !alcance_ve_cliente($id)) { echo json_encode(['ok'=>false,'msg'=>'No tienes acceso a este cliente.']); exit; }
$name = trim($in['name'] ?? '');
$username = trim($in['username'] ?? '');
$password = $in['password'] ?? '';
if ($name==='' || $username==='') { echo json_encode(['ok'=>false,'msg'=>'Nombre y usuario son obligatorios.']); exit; }

$chk = db()->prepare('SELECT id FROM clients WHERE username = ? AND id <> ?');
$chk->execute([$username, $id]);
if ($chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Ese usuario ya existe, elige otro.']); exit; }

$fields = [
  'username'=>$username,
  'name'=>$name,
  'iniciales'=>trim($in['iniciales'] ?? 'CL'),
  'saludo'=>trim($in['saludo'] ?? ''),
  'conversiones'=> !empty($in['conversiones']) ? 1 : 0,
  'tipo_id'=> (isset($in['tipo_id']) && $in['tipo_id']!=='' && $in['tipo_id']!==null) ? (int)$in['tipo_id'] : null,
  'actual'=>trim($in['actual'] ?? ''),
  'estado_json'=>json_encode($in['estado'] ?? ['nombre'=>'','etiqueta'=>'','siguiente'=>'','fases'=>[]], JSON_UNESCAPED_UNICODE),
  'plan_json'=>json_encode($in['plan'] ?? ['resumen'=>'','items'=>[],'detalle'=>[]], JSON_UNESCAPED_UNICODE),
  'accesos_json'=>json_encode($in['accesos'] ?? [], JSON_UNESCAPED_UNICODE),
  'informes_json'=>json_encode($in['informes'] ?? [], JSON_UNESCAPED_UNICODE),
  'looker_url'=>trim($in['looker'] ?? ''),
  'tareas_json'=>json_encode($in['tareas'] ?? [], JSON_UNESCAPED_UNICODE),
  // met_json NO se toca: las métricas son objetivas
];
// servicios: solo se actualiza si vienen definidos (no pisa clientes antiguos)
if (isset($in['servicios']) && is_array($in['servicios'])) {
    $fields['servicios_json'] = json_encode($in['servicios'], JSON_UNESCAPED_UNICODE);
}
$set = implode(', ', array_map(fn($k)=>"$k = :$k", array_keys($fields)));
$params = $fields; $params['id'] = $id;

try {
    /* La contraseña se escribe aparte, por credenciales_cambiar(), que sube la
       versión de credenciales. Escrita aquí no cerraría las sesiones abiertas de
       ese cliente. */
    db()->prepare("UPDATE clients SET $set WHERE id = :id")->execute($params);
    if ($password !== '') {
        $r = credenciales_cambiar('clients', $id, $password);
        if (!$r['ok']) { echo json_encode(['ok'=>false,'msg'=>$r['msg']]); exit; }
    }
    echo json_encode(['ok'=>true]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'msg'=>'Error al guardar.']);
}
