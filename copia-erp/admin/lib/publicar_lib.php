<?php
/* Publicación al portal del cliente · escritor ÚNICO de tareas_json e informes_json
   ------------------------------------------------------------------------------
   Antes estas dos funciones estaban copiadas letra a letra en workspace.php y en
   task.php. Al ser copias, se desincronizaron: la de task.php ya no leía due_date
   y publicaba los informes con menos datos que la de workspace.php, así que lo que
   veía el cliente cambiaba según desde dónde se hubiera tocado la tarea.

   A partir de aquí hay un solo sitio donde se decide qué ve el cliente. Cualquier
   página que cambie tareas debe incluir este archivo y llamar a publicar_progreso().

   Nota importante: esto NO cambia el aspecto del portal del cliente ni el de los
   informes. Solo unifica de dónde salen los datos. */

if (!function_exists('publicar_informes')) {

/* Reconstruye los Informes del cliente a partir de su lista de tipo 'informe'.
   Si el cliente no tiene ninguna lista de informes no se toca nada: puede que sus
   informes los esté escribiendo n8n por su cuenta y no queremos borrárselos. */
function publicar_informes($clientId) {
    $clientId = (int)$clientId; if (!$clientId) return;
    $chk = db()->prepare("SELECT COUNT(*) FROM task_lists WHERE client_id=? AND tipo='informe'");
    $chk->execute([$clientId]);
    if (!$chk->fetchColumn()) return;

    /* due_date se seleccionaba y no se usaba (P2-10): se quita de la consulta. */
    $st = db()->prepare("SELECT t.mes, t.titulo, t.titulo_cliente, t.explicacion_cliente, t.descripcion
        FROM tasks t JOIN task_lists l ON l.id=t.list_id
        WHERE t.client_id=? AND l.tipo='informe' ORDER BY t.orden, t.id");
    $st->execute([$clientId]);

    $out = [];
    foreach ($st as $t) {
        $out[] = [
            'mes'    => trim((string)$t['mes']) !== '' ? trim($t['mes']) : 'General',
            'titulo' => trim((string)$t['titulo_cliente']) !== '' ? $t['titulo_cliente'] : $t['titulo'],
            'texto'  => trim((string)$t['explicacion_cliente']) !== '' ? $t['explicacion_cliente'] : (string)$t['descripcion'],
            /* url queda vacía a propósito: el informe se lee en el propio portal (no hay
               enlace externo ni el cliente puede abrir páginas del admin). El portal
               trata '' como «sin enlace». (P2-10) */
            'url'    => '',
        ];
    }
    db()->prepare("UPDATE clients SET informes_json=? WHERE id=?")
        ->execute([json_encode($out, JSON_UNESCAPED_UNICODE), $clientId]);
}

/* Reconstruye el bloque de progreso del portal (tareas_json) y, de paso, los
   informes. Se llama después de cualquier cambio que el cliente pueda notar:
   crear, editar, completar, mover, ocultar o borrar una tarea. */
function publicar_progreso($clientId) {
    $clientId = (int)$clientId; if (!$clientId) return;
    $st = db()->prepare("SELECT t.titulo, t.titulo_cliente, t.explicacion_cliente, t.estado, t.mes, t.orden, t.id
        FROM tasks t JOIN task_lists l ON l.id=t.list_id
        WHERE t.client_id=? AND (t.visible_cliente=1 OR l.es_cliente=1)
        ORDER BY t.mes, t.orden, t.id");
    $st->execute([$clientId]);

    $out = [];
    foreach ($st as $t) {
        $mes = trim((string)$t['mes']) !== '' ? trim($t['mes']) : 'General';
        $tit = trim((string)$t['titulo_cliente']) !== '' ? $t['titulo_cliente'] : $t['titulo'];
        $grp = ($t['estado'] === 'completada') ? 'completado' : 'pendiente';
        if (!isset($out[$mes])) $out[$mes] = ['completado'=>[], 'pendiente'=>[]];
        $out[$mes][$grp][] = ['t'=>$tit, 'd'=>(string)$t['explicacion_cliente']];
    }
    db()->prepare("UPDATE clients SET tareas_json=? WHERE id=?")
        ->execute([json_encode($out, JSON_UNESCAPED_UNICODE), $clientId]);

    publicar_informes($clientId);
}

}
