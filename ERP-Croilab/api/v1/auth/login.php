<?php
require_once __DIR__ . '/../../sesion.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'msg'=>'Método no permitido']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$user = trim((string)($data['username'] ?? $data['user'] ?? ''));
$pass = (string)($data['password'] ?? $data['pass'] ?? '');

if ($user === '' || $pass === '') {
    echo json_encode(['ok'=>false,'msg'=>'Usuario y contraseña obligatorios']);
    exit;
}

$st = db()->prepare('SELECT * FROM admins WHERE username = ?');
$st->execute([$user]);
$ad = $st->fetch();
if (!$ad || !password_verify($pass, $ad['password'])) {
    echo json_encode(['ok'=>false,'msg'=>'Credenciales incorrectas']);
    exit;
}

$_SESSION['admin_id'] = (int)$ad['id'];
$_SESSION['admin_user'] = $ad['username'];
$_SESSION['admin_role'] = $ad['role'] ?? 'member';
session_regenerate_id(true);

echo json_encode(['ok'=>true, 'csrf' => csrf_token(), 'me' => [
    'id' => (int)$ad['id'],
    'username' => $ad['username'],
    'role' => $ad['role'] ?? null,
]]);
