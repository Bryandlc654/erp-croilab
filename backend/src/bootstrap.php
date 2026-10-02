<?php
/* Arranque del código nuevo (src/). Autocarga propia, sin Composer en
   producción: el hosting solo necesita subir los ficheros. Composer se usa
   únicamente para las herramientas de desarrollo (PHPUnit). */

spl_autoload_register(function (string $clase): void {
    $prefijo = 'Croilab\\';
    if (strncmp($clase, $prefijo, strlen($prefijo)) !== 0) return;
    $ruta = __DIR__ . '/' . str_replace('\\', '/', substr($clase, strlen($prefijo))) . '.php';
    if (is_file($ruta)) require $ruta;
});
