<?php
namespace Croilab\Modulos\Trabajo;

/* Trabajo periódico del área de Trabajo, para bin/cron.php:
   · avisos que no dispara ninguna acción (facturas vencidas, leads a los que
     toca llamar, solicitudes de reunión del portal, cumpleaños),
   · purga de la papelera (lo que lleva más de PAP_DIAS días) con su limpieza
     de respuestas de tickets huérfanas y de archivos sin dueño.
   Cada paso va por su cuenta: uno que falla no impide los demás. Devuelve un
   resumen legible para el registro del cron. */
final class Cron
{
    public static function ejecutar(\PDO $pdo): array
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/admin/lib/notificaciones.php';
        require_once $root . '/admin/lib/papelera.php';
        $out = [];

        foreach (['notif_sync_invoices' => 'facturas vencidas', 'notif_sync_leads' => 'leads', 'notif_sync_meeting_requests' => 'solicitudes de reunión', 'notif_sync_birthdays' => 'cumpleaños'] as $fn => $txt) {
            try {
                $fn();
                $out[] = "avisos: $txt al día";
            } catch (\Throwable $e) {
                $out[] = "avisos: $txt FALLÓ: " . $e->getMessage();
            }
        }

        try {
            $n = (int)pap_purga(true);
            $out[] = "papelera: $n elemento(s) caducado(s)";
        } catch (\Throwable $e) {
            $out[] = 'papelera: FALLÓ: ' . $e->getMessage();
        }

        /* Avisos pospuestos ya vencidos: vuelven solos a su bandeja (la consulta
           ya los trata como no pospuestos); aquí solo se limpia la marca para
           que no se acumulen fechas viejas. */
        try {
            $n = $pdo->exec('UPDATE notifications SET snooze_until = NULL WHERE snooze_until IS NOT NULL AND snooze_until <= NOW()');
            $out[] = 'avisos pospuestos devueltos: ' . (int)$n;
        } catch (\Throwable $e) {
            $out[] = 'avisos pospuestos: FALLÓ: ' . $e->getMessage();
        }
        return $out;
    }
}
