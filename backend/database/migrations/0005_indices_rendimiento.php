<?php
/* Índices que faltan para el listado de tareas y para el alcance por
   responsable. Idempotente: si el índice ya existe por ese nombre, no se toca. */

use Croilab\Database\Esquema;

return function (PDO $pdo): void {
    if (!Esquema::tablaExiste($pdo, 'tasks')) return;

    /* (client_id, estado) ya lo cubría ix_t_cli_estado: no se duplica. */

    /* El alcance por responsable ya tiene ix_t_resp_estado, pero una versión con
       client_id al final permite resolver la consulta sin volver a la tabla. */
    Esquema::indice($pdo, 'tasks', 'ix_t_resp_cli', ['responsable_id', 'client_id'], false);

    /* contactos: el alcance suma los clientes de los contactos propios. */
    if (Esquema::tablaExiste($pdo, 'contacts')) {
        Esquema::indice($pdo, 'contacts', 'ix_cont_prop_cli', ['propietario_id', 'client_id'], false);
    }
};
