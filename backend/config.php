<?php
/* ===========================================================
   CONFIGURACIÓN — Los valores ya NO viven aquí.

   Se leen de dos sitios, en este orden:
     1. Variables de entorno (Docker, CI).
     2. El fichero `.env` de esta misma carpeta.

   Copia `.env.ejemplo` a `.env`, rellénalo y súbelo. La raíz tiene un
   `.htaccess` que impide que el servidor sirva ese fichero.
   =========================================================== */

/* Lee un `.env`: solo pares CLAVE=valor, ignora comentarios y líneas vacías,
   y quita las comillas si las lleva. */
function croilab_cargar_env($ruta)
{
    if (!is_readable($ruta)) return array();
    $out = array();
    foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#') continue;
        $p = strpos($linea, '=');
        if ($p === false) continue;
        $k = trim(substr($linea, 0, $p));
        $v = trim(substr($linea, $p + 1));
        if (strlen($v) >= 2) {
            $a = $v[0];
            if (($a === '"' || $a === "'") && substr($v, -1) === $a) $v = substr($v, 1, -1);
        }
        if ($k !== '') $out[$k] = $v;
    }
    return $out;
}

$croilab_env = croilab_cargar_env(__DIR__ . '/.env');

/* Un valor obligatorio: variable de entorno, luego `.env`. Si no está ninguno,
   se para y se dice cuál falta. No hay ningún valor de ejemplo de repuesto,
   que es justo lo que dejó el secreto y la contraseña puestos en el código. */
function croilab_valor($clave, array $env)
{
    $v = getenv($clave);
    if ($v === false || $v === '') $v = isset($env[$clave]) ? $env[$clave] : '';
    if ($v === '') {
        /* En la API (y en la consola) se lanza: quien llama responde en JSON o
           con un mensaje de terminal. Solo las páginas antiguas pintan HTML. */
        if (defined('CROILAB_API') || PHP_SAPI === 'cli') throw new RuntimeException("Falta la configuración: $clave");
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        exit('<h1>Falta la configuración</h1><p>No está definido <code>' . htmlspecialchars($clave, ENT_QUOTES, 'UTF-8')
            . '</code>. Ponlo en el fichero <code>.env</code> de esta carpeta o como variable de entorno.</p>');
    }
    return $v;
}

define('DB_HOST', croilab_valor('DB_HOST', $croilab_env));
define('DB_NAME', croilab_valor('DB_NAME', $croilab_env));
define('DB_USER', croilab_valor('DB_USER', $croilab_env));
define('DB_PASS', croilab_valor('DB_PASS', $croilab_env));

/* Clave de cifrado y de sesión. 64 caracteres aleatorios. Cambiarla invalida
   los tokens de Google Calendar ya guardados, salvo que estén re-cifrados. */
define('APP_SECRET', croilab_valor('APP_SECRET', $croilab_env));

/* URL pública de la aplicación. La usan el enlace de restablecimiento de
   contraseña, la redirección de Google y las tareas programadas, para no
   construirlas con la cabecera `Host` de la petición, que el atacante elige.
   Se deja vacía a propósito: hasta que se rellene, esas pantallas siguen
   usando la cabecera, y así se nota al probar. */
define('APP_URL', isset($croilab_env['APP_URL']) ? rtrim($croilab_env['APP_URL'], '/') : '');

/* Ruta del fichero de clave de la bóveda (opcional). Vacío: el directorio padre
   del público. Ver admin/lib/boveda.php. */
if (!defined('BOVEDA_CLAVE_FICHERO')) {
    $croilab_boveda = getenv('BOVEDA_CLAVE_FICHERO') ?: ($croilab_env['BOVEDA_CLAVE_FICHERO'] ?? '');
    if ($croilab_boveda !== '') define('BOVEDA_CLAVE_FICHERO', $croilab_boveda);
    unset($croilab_boveda);
}

/* Zona horaria */
date_default_timezone_set('Europe/Madrid');

/* Entorno: 'dev' muestra errores (Docker), 'prod' los oculta al cliente y los
   guarda en un log. En Hostinger no hay variable => 'prod' automáticamente. */
define('APP_ENV', getenv('APP_ENV') ?: 'prod');
if (APP_ENV === 'dev') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    /* E_ALL incluye los avisos de obsolescencia de PHP y los "notice". Con una
       versión de PHP nueva, cada llamada antigua que quede en el ERP genera
       líneas de este tipo y el log se llena de avisos que no son fallos: cuando
       haya un problema de verdad queda enterrado. Se separan los dos. */
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_NOTICE);
    ini_set('ignore_repeat_errors', '1');
    ini_set('log_errors', '1');
    /* El log fuera de la carpeta pública. Antes caía aquí dentro, con lo que
       cualquiera podía pedírselo al servidor y leerlo. Si no hay sitio
       escribible, se deja de escribir en fichero: es peor perder el log que
       publicarlo. */
    $croilab_log = null;
    foreach (array(dirname(__DIR__) . '/php-error.log', sys_get_temp_dir() . '/croilab-php-error.log') as $cand) {
        if (is_dir(dirname($cand)) && is_writable(dirname($cand))) { $croilab_log = $cand; break; }
    }
    if ($croilab_log !== null) @ini_set('error_log', $croilab_log);
    else { ini_set('log_errors', '0'); }
    unset($croilab_log);
}
