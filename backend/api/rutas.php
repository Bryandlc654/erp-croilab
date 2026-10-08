<?php
/* Rutas de la API. Cada módulo tiene las suyas en api/rutas/<modulo>.php, que
   devuelve function (Router $r, Contenedor $c): void. Así un módulo nuevo se
   añade con un fichero, sin tocar los de los demás. Documentadas en API.md.
   En cada ruta, `true` como tercer argumento = pública (no exige sesión; el
   CSRF de los POST se comprueba igual). */

use Croilab\Http\Contenedor;
use Croilab\Http\Router;

return function (Router $r, PDO $pdo): void {
    $c = new Contenedor($pdo);
    $ficheros = glob(__DIR__ . '/rutas/*.php') ?: [];
    sort($ficheros, SORT_STRING);
    foreach ($ficheros as $f) (require $f)($r, $c);
};
