<?php
/* Arranque de los tests. Los de integración usan una base de datos de pruebas
   (TEST_DB_*) que se vacía en cada ejecución; si no está configurada, se
   saltan y solo corren los unitarios. */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/bootstrap.php';

define('CROILAB_TEST_DB', (string)getenv('TEST_DB_NAME') !== '');

/* Los avisos del código (índices creados, etc.) van a un fichero: en un proceso
   aislado, cualquier cosa en stderr cuenta como fallo del test. */
ini_set('error_log', sys_get_temp_dir() . '/croilab-tests.log');

if (CROILAB_TEST_DB) {
    /* config.php lee DB_* del entorno: se apuntan a la base de pruebas antes de cargarlo. */
    foreach (['HOST', 'NAME', 'USER', 'PASS'] as $k) putenv("DB_$k=" . getenv("TEST_DB_$k"));
    putenv('APP_SECRET=' . str_repeat('t', 64));
    putenv('APP_ENV=dev');
    putenv('BOVEDA_CLAVE=k1=' . str_repeat('ab', 32));
    if (!str_contains((string)getenv('TEST_DB_NAME'), 'test') && !str_contains((string)getenv('TEST_DB_NAME'), 'phpunit')) {
        fwrite(STDERR, "TEST_DB_NAME debe contener «test» o «phpunit»: los tests vacían esa base.\n");
        exit(1);
    }
    require __DIR__ . '/../db.php';
    require __DIR__ . '/../auth.php';
    require __DIR__ . '/../admin/lib/tareas_lib.php';
    require __DIR__ . '/../admin/lib/papelera.php';
    require __DIR__ . '/../admin/lib/notificaciones.php';
}
