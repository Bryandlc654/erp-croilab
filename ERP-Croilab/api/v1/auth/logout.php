<?php
require_once __DIR__ . '/../../sesion.php';
require_once __DIR__ . '/../../auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'msg'=>'Método no permitido']);
    exit;
}

sesion_cerrar('logout api');
echo json_encode(['ok'=>true]);
