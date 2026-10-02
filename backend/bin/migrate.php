<?php
/* Aplica las migraciones pendientes de database/migrations.

     php bin/migrate.php            aplica lo pendiente
     php bin/migrate.php --estado   solo dice qué falta

   En Hostinger: hPanel → Avanzado → SSH, o un cron de una sola vez. Se ejecuta
   después de cada despliegue; mientras falte alguna, la API responde 503. */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../db.php';

use Croilab\Database\Migrador;

try {
    $pdo = db();
    $todas = array_keys(Migrador::archivos());
    $hechas = Migrador::aplicadas($pdo);
    $faltan = array_values(array_diff($todas, $hechas));

    if (in_array('--estado', $argv, true)) {
        echo 'Aplicadas: ' . (count($hechas) ? implode(', ', $hechas) : 'ninguna') . PHP_EOL;
        echo 'Pendientes: ' . (count($faltan) ? implode(', ', $faltan) : 'ninguna') . PHP_EOL;
        exit($faltan ? 1 : 0);
    }
    if (!$faltan) { echo "El esquema ya está al día.\n"; exit(0); }

    $nuevas = Migrador::migrar($pdo, fn($l) => print($l . PHP_EOL));
    echo 'Listo: ' . count($nuevas) . " migración(es) aplicada(s).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
