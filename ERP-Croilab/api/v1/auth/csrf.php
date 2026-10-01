<?php
require_once __DIR__ . '/../../sesion.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');
echo json_encode(['ok' => true, 'csrf' => csrf_token()]);
