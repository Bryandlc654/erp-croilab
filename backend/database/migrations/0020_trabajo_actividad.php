<?php
/* Trabajo: historial de la tarea e índices de las pantallas nuevas.

   task_activity guarda los cambios de una tarea (estado, fechas, asignados,
   prioridad…) para el panel de actividad de la ficha. El ERP antiguo solo
   enseñaba «Tarea creada»: ahora se ve quién cambió qué y cuándo. Va a la
   papelera con la tarea, como sus comentarios. */

use Croilab\Database\Esquema;

return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_activity (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        admin_id INT NULL,
        tipo VARCHAR(30) NOT NULL,
        detalle VARCHAR(300) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX ix_tact_tarea (task_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* «· editado» en los comentarios (el antiguo no lo sabía). */
    Esquema::columnas($pdo, 'task_comments', ['editado_at' => 'DATETIME NULL DEFAULT NULL']);

    /* Feed de comentarios por tarea en orden y respuestas. */
    Esquema::indice($pdo, 'task_comments', 'ix_tc_tarea_id', ['task_id', 'id']);
    /* Horas de una tarea por persona (la ficha suma «Horas de la tarea»). */
    Esquema::indice($pdo, 'time_entries', 'ix_te_tarea_admin', ['task_id', 'admin_id']);
    /* Bandejas de avisos: por persona, papelera y pospuestas. */
    Esquema::indice($pdo, 'notifications', 'ix_notif_bandeja', ['admin_id', 'borrado', 'id']);
    /* Actas: fijadas primero y por última edición. */
    Esquema::indice($pdo, 'actas', 'ix_actas_orden', ['pinned', 'updated_at']);
    /* Papelera: lo de cada persona («Deshacer») y por fecha. */
    Esquema::indice($pdo, 'trash', 'ix_trash_admin', ['admin_id', 'created_at']);
};
