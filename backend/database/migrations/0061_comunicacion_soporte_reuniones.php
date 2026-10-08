<?php
/* Soporte y reuniones: índices para lo que filtra la API nueva.

     · support_tickets (assignee_id): «asignados a mí» y el alcance (quien no ve
       todos los clientes sí ve los tickets que tiene asignados).
     · support_tickets (created_by).
     · portal_meeting_requests (estado): las pendientes salen en cada carga de
       Reuniones y en la sincronización de avisos.
     · crm_meetings (contact_id) ya lo crea el CRM; si falta (bases antiguas) se pone. */

use Croilab\Database\Esquema;

return function (PDO $pdo): void {
    Esquema::indice($pdo, 'support_tickets', 'ix_st_asignado', ['assignee_id']);
    Esquema::indice($pdo, 'support_tickets', 'ix_st_creador', ['created_by']);
    Esquema::indice($pdo, 'portal_meeting_requests', 'ix_pmr_estado', ['estado']);
    if (Esquema::tablaExiste($pdo, 'crm_meetings')) {
        $st = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_meetings' AND COLUMN_NAME = 'contact_id' AND SEQ_IN_INDEX = 1");
        if (!(int)$st->fetchColumn()) Esquema::indice($pdo, 'crm_meetings', 'ix_crmm_contacto', ['contact_id']);
    }
};
