<?php
// api/v1/_bootstrap.php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../sesion.php';
require_once __DIR__ . '/../../auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');

function api_ok($data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => true] + (is_array($data) ? $data : ['data' => $data]), JSON_UNESCAPED_UNICODE);
    exit;
}
function api_fail($msg, $code = 400, $extra = []) {
    http_response_code($code);
    $out = ['ok' => false, 'msg' => $msg] + $extra;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// Requiere admin para endpoints v1 (ERP interno)
if (!current_admin()) {
    api_fail('No autorizado', 401);
}
