<?php
/* Ciclo del cron: llama al Cron de cada módulo y apunta el resultado en
   cron_log (lo enseñan CRM › Reporting y Ajustes › Reglas automáticas).

     php bin/cron.php              el ciclo completo
     php bin/cron.php crm finanzas solo esos módulos

   En Hostinger: hPanel → Avanzado → Cron jobs, cada 15 minutos:
     php /home/USUARIO/ruta/backend/bin/cron.php
   Cada módulo decide qué toca en cada vuelta (el resumen diario, una vez al
   día; las recurrentes, una vez por mes y programación): repetirlo no duplica. */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
foreach (['tareas_lib', 'papelera', 'notificaciones', 'marca', 'cron_lib', 'crm_lib', 'crm_followup', 'fin_prog'] as $lib) {
    require_once __DIR__ . '/../admin/lib/' . $lib . '.php';
}

use Croilab\Database\Migrador;

const CRON_MODULOS = [
    'trabajo'      => \Croilab\Modulos\Trabajo\Cron::class,
    'crm'          => \Croilab\Modulos\Crm\Cron::class,
    'finanzas'     => \Croilab\Modulos\Finanzas\Cron::class,
    'comunicacion' => \Croilab\Modulos\Comunicacion\Cron::class,
];

$pdo = db();
if (Migrador::hayPendientes($pdo)) {
    fwrite(STDERR, "Hay migraciones pendientes: ejecuta antes  php bin/migrate.php\n");
    exit(1);
}

/* Dos ciclos a la vez (un cron lento que se solapa con el siguiente) se
   pisarían: el segundo se va sin hacer nada. */
if ((int)$pdo->query("SELECT GET_LOCK('croilab_cron', 0)")->fetchColumn() !== 1) {
    echo "Otro ciclo del cron sigue en marcha: nada que hacer.\n";
    exit(0);
}

$pedidos = array_slice($argv, 1);
$inicio = microtime(true);
$fallos = 0;
foreach (CRON_MODULOS as $modulo => $clase) {
    if ($pedidos && !in_array($modulo, $pedidos, true)) continue;
    $t = microtime(true);
    try {
        $r = $clase::ejecutar($pdo);
        $ms = (int)round((microtime(true) - $t) * 1000);
        /* Si el módulo devuelve sus tareas como [clave => ['ok', 'detalle']],
           cada una va a su fila (CRM las enseña por nombre); si no, una fila
           con el resumen del módulo. */
        $porTarea = false;
        foreach ((array)$r as $clave => $v) {
            if (is_array($v) && array_key_exists('ok', $v)) {
                $porTarea = true;
                if ($v['ok'] === null) continue;   // tarea desactivada
                $tarea = is_string($clave) ? $clave : (string)($v['tarea'] ?? $modulo);
                cron_apunta($tarea, (bool)$v['ok'], (string)($v['detalle'] ?? ''), (int)($v['ms'] ?? 0));
                if (!$v['ok']) $fallos++;
            }
        }
        if (!$porTarea) cron_apunta($modulo, true, json_encode($r, JSON_UNESCAPED_UNICODE) ?: '', $ms);
        echo "✓ $modulo ($ms ms)\n";
    } catch (Throwable $e) {
        $fallos++;
        cron_apunta($modulo, false, $e->getMessage(), (int)round((microtime(true) - $t) * 1000));
        fwrite(STDERR, "✗ $modulo: " . $e->getMessage() . "\n");
        error_log("cron $modulo: " . $e);
    }
}
cron_apunta('ciclo', $fallos === 0, $fallos ? "$fallos fallo(s)" : 'ok', (int)round((microtime(true) - $inicio) * 1000));
$pdo->query("SELECT RELEASE_LOCK('croilab_cron')");
exit($fallos ? 1 : 0);
