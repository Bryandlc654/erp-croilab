<?php
/* ===========================================================
   API para automatizaciones (n8n).
   Actualiza los datos de un cliente por POST JSON.
   Autenticación: cabecera  X-API-Token: <token>   (o campo "token" en el JSON)
   El token está en el panel → Ajustes.

   Ejemplo de cuerpo:
   {
     "client": "cabana",
     "set_met": { "Julio": {"ll":70,"wa":12,"fo":6,"vi":1600,"ap":26000,"ctr":6.1} },
     "add_informe": { "mes":"Julio", "titulo":"Informe de julio", "texto":"...", "url":"" },
     "actual": "Julio"
   }
   También admite reemplazo total: met, tareas, informes, estado, plan, accesos, servicios, looker.
   =========================================================== */
require_once __DIR__ . '/db.php';
ensure_schema();
header('Content-Type: application/json; charset=utf-8');
function out($a, $code = 200){ http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) out(['ok'=>false,'msg'=>'JSON inválido'], 400);

/* --- token --- */
$token = get_setting('api_token', '');
$given = $_SERVER['HTTP_X_API_TOKEN'] ?? ($in['token'] ?? '');
if ($token === '' || !hash_equals($token, (string)$given)) out(['ok'=>false,'msg'=>'Token inválido'], 401);

/* --- localizar cliente --- */
$cli = null;
if (!empty($in['client_id'])) {
    $st = db()->prepare('SELECT * FROM clients WHERE id = ?'); $st->execute([(int)$in['client_id']]); $cli = $st->fetch();
} elseif (!empty($in['client'])) {
    $st = db()->prepare('SELECT * FROM clients WHERE username = ?'); $st->execute([trim($in['client'])]); $cli = $st->fetch();
}
if (!$cli) out(['ok'=>false,'msg'=>'Cliente no encontrado (usa "client" con el usuario o "client_id")'], 404);

$fields = [];

/* --- reemplazo total de secciones --- */
$map = [
  'met'=>'met_json', 'tareas'=>'tareas_json', 'informes'=>'informes_json',
  'estado'=>'estado_json', 'plan'=>'plan_json', 'accesos'=>'accesos_json', 'servicios'=>'servicios_json',
];
foreach ($map as $key=>$col) {
    if (array_key_exists($key, $in)) $fields[$col] = json_encode($in[$key], JSON_UNESCAPED_UNICODE);
}
if (array_key_exists('looker', $in)) $fields['looker_url'] = trim((string)$in['looker']);
if (array_key_exists('actual', $in)) $fields['actual']     = trim((string)$in['actual']);

/* --- operaciones incrementales (recomendadas para el mes a mes) --- */
// añade métricas de uno o varios meses. Fusiona a nivel de campo: si el mes ya
// tenía llamadas/WhatsApp/formularios y solo mandas visitas/apariciones/CTR
// (p.ej. desde Search Console), NO se borran los demás campos.
if (!empty($in['set_met']) && is_array($in['set_met'])) {
    $m = json_decode($cli['met_json'] ?? '{}', true); if (!is_array($m)) $m = [];
    foreach ($in['set_met'] as $mes=>$vals) {
        if ($mes==='' || !is_array($vals)) continue;
        $prev = (isset($m[$mes]) && is_array($m[$mes])) ? $m[$mes] : [];
        $m[$mes] = array_merge($prev, $vals);
    }
    $fields['met_json'] = json_encode($m, JSON_UNESCAPED_UNICODE);
}
// añade las tareas de un mes (sustituye ese mes) sin tocar los demás
if (!empty($in['set_tareas_mes']) && is_array($in['set_tareas_mes']) && !empty($in['mes'])) {
    $t = json_decode($cli['tareas_json'] ?? '{}', true); if (!is_array($t)) $t = [];
    $t[trim($in['mes'])] = $in['set_tareas_mes'];   // {completado:[...],pendiente:[...]}
    $fields['tareas_json'] = json_encode($t, JSON_UNESCAPED_UNICODE);
}
// añade un informe al final sin borrar los anteriores
if (!empty($in['add_informe']) && is_array($in['add_informe'])) {
    $arr = json_decode($cli['informes_json'] ?? '[]', true); if (!is_array($arr)) $arr = [];
    $arr[] = $in['add_informe'];   // {mes,titulo,texto,url}
    $fields['informes_json'] = json_encode($arr, JSON_UNESCAPED_UNICODE);
}

if (!$fields) out(['ok'=>false,'msg'=>'Nada que actualizar'], 400);

$set = implode(', ', array_map(fn($k)=>"$k = :$k", array_keys($fields)));
$params = $fields; $params['id'] = $cli['id'];
try {
    db()->prepare("UPDATE clients SET $set WHERE id = :id")->execute($params);
    out(['ok'=>true, 'client'=>$cli['username'], 'updated'=>array_keys($fields)]);
} catch (Exception $e) {
    out(['ok'=>false,'msg'=>'Error al guardar'], 500);
}
