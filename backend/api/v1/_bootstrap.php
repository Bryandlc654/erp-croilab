<?php
// Allowlist de orígenes permitidos (CORS para dominios separados)
 = [
  'http://localhost:5173',        // dev
  'https://app.tudominio.com',    // prod front
];
 = ['HTTP_ORIGIN'] ?? '';
if ( !== '' && in_array(, , true)) {
    header("Access-Control-Allow-Origin: ");
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ((['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
