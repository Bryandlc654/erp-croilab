<?php
/* Punto de entrada único de la API. Apache manda aquí todo /api/* (ver
   .htaccess); en desarrollo:  php -S localhost:8000 -t backend backend/api/index.php
   Todo lo que ocurre en cada petición está en src/Http/Kernel.php. */

define('CROILAB_API', true);
/* El CSRF lo comprueba el Kernel para POST, PATCH y DELETE; auth.php solo
   miraba los POST. */
define('CROILAB_NO_CSRF', true);

/* Con el servidor de PHP de desarrollo, lo que no es la API se sirve tal cual. */
if (PHP_SAPI === 'cli-server' && !str_starts_with((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/api/')) {
    return false;
}

ini_set('display_errors', '0');   // un aviso de PHP no puede colarse en el JSON

require_once __DIR__ . '/../src/bootstrap.php';

use Croilab\Http\Kernel;
use Croilab\Http\Request;
use Croilab\Http\Router;

$req = Request::desdeGlobales('/api');
try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../sesion.php';
    require_once __DIR__ . '/../auth.php';
    require_once __DIR__ . '/../admin/lib/tareas_lib.php';
    require_once __DIR__ . '/../admin/lib/papelera.php';
    require_once __DIR__ . '/../admin/lib/login_throttle.php';

    $router = new Router();
    (require __DIR__ . '/rutas.php')($router, db());
    Kernel::emitir((new Kernel($router))->manejar($req));
    /* Se cierra la conexión con el cliente y después se hace el trabajo diferido
       (envío de correos). Sin FastCGI/LiteSpeed se hace igual, con el cliente
       esperando. */
    session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    \Croilab\Http\Diferidas::ejecutar();
} catch (Throwable $e) {
    /* Falla el arranque (configuración, base de datos caída): también en JSON. */
    error_log('API arranque: ' . $e->getMessage());
    $origen = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origen !== '' && in_array($origen, Kernel::origenesPermitidos(), true)) {
        header('Access-Control-Allow-Origin: ' . $origen);
        header('Access-Control-Allow-Credentials: true');
    }
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'El servidor no está disponible en este momento.', 'error' => 'no_disponible'], JSON_UNESCAPED_UNICODE);
}
