<?php
/* Asignados de las tareas (tabla puente task_assignees + responsable_id como
   asignado principal). Vivían en admin/erp_nav.php, que se borró al dejar el
   backend solo como API: sin ellas los endpoints no guardaban los asignados. */

function task_asignados_ensure() { if (croilab_esquema_gestionado()) return;   /* el esquema lo crean las migraciones */
    static $ok = false;
    if ($ok) return;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS task_assignees (task_id INT NOT NULL, admin_id INT NOT NULL, orden INT NOT NULL DEFAULT 0,
                    PRIMARY KEY(task_id, admin_id), KEY(admin_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { error_log('task_asignados_ensure: ' . $e->getMessage()); }
    $ok = true;
}

/* Ids de los asignados, en orden. Si la tarea no tiene filas en la tabla puente,
   el responsable (compatibilidad con tareas antiguas). */
function task_asignados($taskId) {
    task_asignados_ensure();
    $taskId = (int)$taskId;
    $ids = [];
    try {
        $q = db()->prepare('SELECT admin_id FROM task_assignees WHERE task_id=? ORDER BY orden, admin_id');
        $q->execute([$taskId]);
        $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {}
    if (!$ids) {
        try {
            $q = db()->prepare('SELECT responsable_id FROM tasks WHERE id=?');
            $q->execute([$taskId]);
            $r = (int)$q->fetchColumn();
            if ($r) $ids = [$r];
        } catch (Exception $e) {}
    }
    return $ids;
}

/* Sustituye los asignados y deja responsable_id = el primero (o NULL). */
function task_set_asignados($taskId, $ids) {
    task_asignados_ensure();
    $taskId = (int)$taskId;
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
    $pdo = db();
    db_tx_begin($pdo);
    try {
        $pdo->prepare('DELETE FROM task_assignees WHERE task_id=?')->execute([$taskId]);
        $ins = $pdo->prepare('INSERT INTO task_assignees (task_id, admin_id, orden) VALUES (?,?,?)');
        foreach ($ids as $i => $aid) $ins->execute([$taskId, $aid, $i]);
        $pdo->prepare('UPDATE tasks SET responsable_id=? WHERE id=?')->execute([$ids[0] ?? null, $taskId]);
    } catch (Exception $e) {
        db_tx_rollback($pdo);
        error_log('task_set_asignados: ' . $e->getMessage());
        return task_asignados($taskId);
    }
    db_tx_commit($pdo);
    return $ids;
}

/* Refresca el progreso que ve el cliente en su portal. Es un efecto secundario:
   si falla, se registra y la petición sigue (antes un fallo aquí convertía en
   error 500 un cambio que ya se había guardado). */
function tareas_publicar_progreso($clientId) {
    if (!(int)$clientId) return;
    if (!function_exists('publicar_progreso')) require_once __DIR__ . '/publicar_lib.php';
    try { publicar_progreso((int)$clientId); }
    catch (Throwable $e) { error_log('publicar_progreso ' . (int)$clientId . ': ' . $e->getMessage()); }
}
