<?php
require_once __DIR__ . '/_bootstrap.php';

$me = current_admin();
api_ok(['me' => [
    'id' => (int)$me['id'],
    'username' => $me['username'],
    'role' => $me['role'] ?? null,
]]);
