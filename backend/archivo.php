<?php
/* Servidor de archivos con permiso.
   Todo lo que se sube al ERP (adjuntos de tareas, del CRM y facturas) se sirve
   SIEMPRE por aquí, nunca por la URL directa de /uploads. Así:
     · hay que estar identificado para ver un archivo,
     · no se puede salir de la carpeta con ../,
     · lo que el navegador podría ejecutar (svg, html…) se fuerza a descarga.

   Uso:  archivo.php?d=tasks|crm|facturas&f=nombre.ext[&dl=1]                 */
require_once __DIR__ . '/auth.php';

if (!current_admin()) { http_response_code(403); exit('Necesitas iniciar sesión.'); }

/* Cada almacén pide el permiso de su módulo: ser del equipo no basta para abrir
   las facturas o el CRM si el rol no tiene ese módulo. null = cualquiera del
   equipo (las fotos de perfil salen en todas las pantallas). */
$CARPETAS = [
    'tasks'    => ['tasks',    'ver.tareas'],
    'crm'      => ['crm',      'ver.crm'],
    'facturas' => ['facturas', 'ver.finanzas'],
    'chat'     => ['chat',     'ver.chat'],
    'avatars'  => ['avatars',  null],
];

$d = (string)($_GET['d'] ?? '');
$f = (string)($_GET['f'] ?? '');

if (!isset($CARPETAS[$d])) { http_response_code(404); exit('No encontrado.'); }
[$carpeta, $permiso] = $CARPETAS[$d];
if ($permiso !== null && (!function_exists('can') || !can($permiso))) {
    http_response_code(403); exit('No tienes permiso para ver este archivo.');
}

/* basename() + comprobación explícita: ni ../ ni rutas absolutas ni bytes nulos */
$f = str_replace("\0", '', $f);
$f = basename($f);
if ($f === '' || $f === '.' || $f === '..' || strpos($f, '/') !== false || strpos($f, '\\') !== false) {
    http_response_code(404); exit('No encontrado.');
}

$base = realpath(__DIR__ . '/uploads/' . $carpeta);
$ruta = $base ? realpath($base . '/' . $f) : false;

/* el archivo tiene que estar realmente dentro de su carpeta */
if (!$ruta || !is_file($ruta) || strpos($ruta, $base . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404); exit('No encontrado.');
}

/* Un adjunto de tarea se ve solo si se ve su tarea (mismo alcance que el
   tablero). Las imágenes incrustadas en descripciones y comentarios no están
   en task_attachments; su nombre lleva 64 bits aleatorios (upload_nombre_seguro). */
if ($d === 'tasks' && function_exists('alcance_ve_tarea') && !alcance_todo()) {
    $st = db()->prepare('SELECT task_id FROM task_attachments WHERE filename = ?');
    $st->execute([$f]);
    $tareas = $st->fetchAll(PDO::FETCH_COLUMN);
    if ($tareas && !array_filter($tareas, 'alcance_ve_tarea')) {
        http_response_code(404); exit('No encontrado.');
    }
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
