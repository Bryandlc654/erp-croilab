<?php
require_once __DIR__ . '/../../auth.php';
require_admin();

// Servir build de Vite
$dist = __DIR__ . '/dist';
if (!is_dir($dist) || !file_exists($dist . '/index.html')) {
    echo '<div style="padding:24px;font:14px system-ui">Build no encontrado. Ejecuta <code>npm run build</code> y vuelve a cargar.</div>';
    exit;
}
readfile($dist . '/index.html');
