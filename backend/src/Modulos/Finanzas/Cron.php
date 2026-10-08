<?php
namespace Croilab\Modulos\Finanzas;

use PDO;

/* Tareas periódicas de Finanzas para bin/cron.php. Cada una se salta si su
   interruptor (settings auto_<clave>) está a '0', y un fallo de una no impide
   las demás. Devuelve una fila por tarea: {tarea, ok, detalle, ms}. */
final class Cron
{
    public static function ejecutar(PDO $pdo): array
    {
        $m = new Modulo($pdo);
        $aj = $m->ajustes();
        $out = [];

        $out[] = self::tarea('invoice_recurring', 'Facturas recurrentes', $aj, function () use ($m) {
            $r = $m->programaciones()->ejecutar(null);
            if ($r['ocupado']) return 'otra ejecución en curso: nada que hacer';
            $txt = $r['generadas'] ? $r['generadas'] . ' factura(s) emitida(s)' : 'nada que emitir';
            if ($r['errores']) $txt .= ' · ' . count($r['errores']) . ' con error: ' . $r['errores'][0]['msg'];
            return $txt;
        });

        $out[] = self::tarea('invoice_due', 'Avisos de facturas', $aj, function () use ($pdo) {
            $antes = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE estado = 'vencida'")->fetchColumn();
            if (function_exists('notif_sync_invoices')) {
                notif_sync_invoices();
            } else {
                $pdo->exec("UPDATE invoices SET estado = 'vencida' WHERE estado = 'enviada' AND fecha_venc IS NOT NULL AND fecha_venc < CURDATE()");
            }
            $despues = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE estado = 'vencida'")->fetchColumn();
            return ($despues - $antes) . ' factura(s) pasan a vencida';
        });
        return $out;
    }

    private static function tarea(string $clave, string $nombre, Ajustes $aj, callable $f): array
    {
        if ($aj->get('auto_' . $clave, '1') === '0') return ['tarea' => $clave, 'nombre' => $nombre, 'ok' => null, 'detalle' => 'desactivada', 'ms' => 0];
        $t = microtime(true);
        try {
            $d = (string)$f();
            $ok = true;
        } catch (\Throwable $e) {
            error_log("Cron finanzas $clave: " . $e->getMessage());
            $d = $e->getMessage();
            $ok = false;
        }
        return ['tarea' => $clave, 'nombre' => $nombre, 'ok' => $ok, 'detalle' => mb_substr($d, 0, 250), 'ms' => (int)round((microtime(true) - $t) * 1000)];
    }
}
