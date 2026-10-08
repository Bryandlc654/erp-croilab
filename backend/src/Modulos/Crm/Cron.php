<?php
namespace Croilab\Modulos\Crm;

use Croilab\Correo\Fabrica;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use PDO;

/* Las tareas del CRM que hace el cron (cron.php del antiguo, cada 15 min),
   cada una con su interruptor de Ajustes › Reglas automáticas
   (settings.auto_<clave>, encendido salvo que valga '0'):
     · followups     genera los seguimientos del día.
     · daily_digest  resumen diario (L–V, una vez al día): aviso en el ERP y
                     correo a quien tiene acceso total.
     · lead_reminder avisos «Toca contactar a…» (notif_sync_leads).
   Devuelve por tarea ['ok' => bool, 'detalle' => string] con los nombres que
   usa cron_log, para que bin/cron.php lo anote. Un fallo no para las demás. */
final class Cron
{
    public static function ejecutar(PDO $pdo): array
    {
        $s = new SeguimientosServicio($pdo, new EquipoRepositorio($pdo), new Historial($pdo), fn() => Fabrica::desdeConfig());
        $out = [];
        $tarea = function (string $clave, callable $f) use ($pdo, &$out): void {
            if (!self::encendida($pdo, $clave)) return;
            try {
                $out[$clave] = ['ok' => true, 'detalle' => (string)$f()];
            } catch (\Throwable $e) {
                error_log("CRM cron $clave: " . $e->getMessage());
                $out[$clave] = ['ok' => false, 'detalle' => mb_substr($e->getMessage(), 0, 200)];
            }
        };
        $tarea('followups', fn() => $s->generar() . ' acción(es) nuevas para hoy');
        $tarea('daily_digest', function () use ($s) {
            $r = $s->ejecutarResumen(false, false);
            return match ($r['reason']) {
                'ok' => $r['acciones'] . ' acción(es) · ' . $r['correos'] . ' correo(s)',
                'fin_de_semana' => 'Fin de semana: no toca',
                'ya_enviado' => 'Ya se hizo hoy',
                'nada_hoy' => 'Nada pendiente hoy',
                'sin_dest' => 'Nadie con acceso total a quien avisar',
                default => $r['reason'],
            };
        });
        $tarea('lead_reminder', function () {
            if (!function_exists('notif_sync_leads')) return 'Sin la librería de avisos';
            notif_sync_leads();
            return 'Avisos de leads al día';
        });
        return $out;
    }

    private static function encendida(PDO $pdo, string $clave): bool
    {
        $st = $pdo->prepare('SELECT valor FROM settings WHERE clave = ?');
        $st->execute(['auto_' . $clave]);
        return (string)$st->fetchColumn() !== '0';
    }
}
