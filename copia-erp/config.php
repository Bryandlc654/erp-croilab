<?php
/* ===========================================================
   CONFIGURACIÓN — RELLENA ESTO CON LOS DATOS DE TU HOSTINGER
   (hPanel → Bases de datos → MySQL: ahí ves nombre, usuario y host)
   =========================================================== */

/* Nota: si existen variables de entorno (cuando se ejecuta en Docker) se usan
   esas automáticamente. Si no (cuando lo subes a Hostinger), se usan los valores
   de la derecha, que son los que tú rellenas a mano. No necesitas tocar nada
   para que funcione en Docker. */

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');          // En Hostinger casi siempre es 'localhost'
define('DB_NAME', getenv('DB_NAME') ?: 'TU_BASE_DE_DATOS');   // Ej: u123456789_portal
define('DB_USER', getenv('DB_USER') ?: 'TU_USUARIO');         // Ej: u123456789_admin
define('DB_PASS', getenv('DB_PASS') ?: 'TU_CONTRASEÑA');      // La que pusiste al crear la base de datos

/* Base de datos SQLite: el ERP funciona sin MySQL. El archivo se crea solo en
   la primera petición. Puedes cambiar la ruta con la variable de entorno DB_SQLITE. */
define('DB_SQLITE', getenv('DB_SQLITE') ?: __DIR__ . '/data/erp.sqlite');

/* Clave para sesiones (cámbiala por cualquier texto largo y aleatorio) */
define('APP_SECRET', getenv('APP_SECRET') ?: 'cambia-esto-por-algo-largo-y-unico-2026');

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
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
    @ini_set('error_log', __DIR__ . '/php-error.log');
}
