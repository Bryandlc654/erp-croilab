<?php
/* Servidor de archivos con permiso.
   Todo lo que se sube al ERP (adjuntos de tareas, del CRM y facturas) se sirve
   SIEMPRE por aquí, nunca por la URL directa de /uploads. Así:
     · hay que estar identificado para ver un archivo,
     · no se puede salir de la carpeta con ../,
     · lo que el navegador podría ejecutar (svg, html…) se fuerza a descarga.

   Uso:  archivo.php?d=tasks|crm|facturas&f=nombre.ext[&dl=1]                 */
require_once __DIR__ . '/auth.php';

/* Los tres almacenes son de uso interno: basta con ser del equipo. */
if (!current_admin()) { http_response_code(403); exit('Necesitas iniciar sesión.'); }

$CARPETAS = ['tasks' => 'tasks', 'crm' => 'crm', 'facturas' => 'facturas', 'chat' => 'chat', 'avatars' => 'avatars'];

$d = (string)($_GET['d'] ?? '');
$f = (string)($_GET['f'] ?? '');

if (!isset($CARPETAS[$d])) { http_response_code(404); exit('No encontrado.'); }

/* basename() + comprobación explícita: ni ../ ni rutas absolutas ni bytes nulos */
$f = str_replace("\0", '', $f);
$f = basename($f);
if ($f === '' || $f === '.' || $f === '..' || strpos($f, '/') !== false || strpos($f, '\\') !== false) {
    http_response_code(404); exit('No encontrado.');
}

$base = realpath(__DIR__ . '/uploads/' . $CARPETAS[$d]);
$ruta = $base ? realpath($base . '/' . $f) : false;

/* el archivo tiene que estar realmente dentro de su carpeta */
if (!$ruta || !is_file($ruta) || strpos($ruta, $base . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404); exit('No encontrado.');
}

$ext  = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
$mime = upload_mime_seguro($ext);
$forzarDescarga = ($mime === null) || (($_GET['dl'] ?? '') === '1');

header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; sandbox');
header('Content-Type: ' . ($forzarDescarga ? 'application/octet-stream' : $mime));
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: ' . ($forzarDescarga ? 'attachment' : 'inline')
     . '; filename="' . preg_replace('/[^\x20-\x7e]/', '_', $f) . '"');
header('Cache-Control: private, max-age=600');

readfile($ruta);
