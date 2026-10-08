<?php
namespace Croilab\Modulos\Comunicacion;

use Croilab\Modulos\Comunicacion\Chat\ChatRepositorio;
use PDO;

/* Trabajo periódico de Comunicación (lo llama el cron general):
     · borra las marcas de «escribiendo» caducadas (el antiguo las dejaba para siempre),
     · avisa a quien tiene acceso total de las solicitudes de reunión nuevas del
       portal (notif_sync_meeting_requests; idempotente por `ref`),
     · avisa de los tickets abiertos que llevan más de 24 h sin asignar (una vez
       por ticket), para que no se queden sin respuesta.
   Devuelve un resumen para el registro del cron. */
final class Cron
{
    public static function ejecutar(PDO $pdo): array
    {
        $out = ['escribiendo_borrados' => 0, 'solicitudes' => 0, 'tickets_sin_asignar' => 0];
        $out['escribiendo_borrados'] = (new ChatRepositorio($pdo))->limpiarEscribiendo(time() - 60);

        try {
            $out['solicitudes'] = (int)$pdo->query("SELECT COUNT(*) FROM portal_meeting_requests WHERE estado = 'pendiente'")->fetchColumn();
            if ($out['solicitudes'] && function_exists('notif_sync_meeting_requests')) notif_sync_meeting_requests();
        } catch (\PDOException $e) {
            error_log('Cron comunicación (solicitudes): ' . $e->getMessage());
        }

        try {
            $filas = $pdo->query("SELECT t.id, t.asunto, c.name AS cliente FROM support_tickets t LEFT JOIN clients c ON c.id = t.client_id
                                  WHERE t.estado = 'abierto' AND t.assignee_id IS NULL AND t.created_at < NOW() - INTERVAL 24 HOUR")->fetchAll(PDO::FETCH_ASSOC);
            $out['tickets_sin_asignar'] = count($filas);
            if ($filas && function_exists('notif_duenos') && function_exists('notif_add')) {
                $duenos = notif_duenos();
                foreach ($filas as $t) foreach ($duenos as $uid) {
                    notif_add($uid, 'ticket', 'Ticket sin asignar desde hace más de un día', $t['cliente'] ? (string)$t['cliente'] : 'Sin cliente',
                              '/soporte/' . (int)$t['id'], 'tknoasig:' . (int)$t['id'] . ':' . $uid, (string)$t['asunto']);
                }
            }
        } catch (\PDOException $e) {
            error_log('Cron comunicación (tickets): ' . $e->getMessage());
        }
        return $out;
    }
}
