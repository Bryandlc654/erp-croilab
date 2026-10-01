<?php
/* Elimina a un miembro del equipo. Solo POST + CSRF (auth.php lo comprueba). */
require_once __DIR__ . '/../auth.php';
/* Permiso equipo.gestionar, exigido por require_admin() (ver team.php). */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: team.php'); exit; }

$id  = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$yo  = current_admin();
$msg = '';

// no puedes borrarte a ti mismo
if ($id && $id != $yo['id']) {
    $st = db()->prepare('SELECT role FROM admins WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row) {
        // no borrar al último dueño
        $borrar = true;
        if ($row['role'] === 'owner') {
            $owners = (int)db()->query("SELECT COUNT(*) FROM admins WHERE role='owner'")->fetchColumn();
            if ($owners <= 1) { $borrar = false; $msg = 'ultimo-dueno'; }
        }
        if ($borrar) {
            /* Limpieza de huérfanos: al quitar el acceso hay que soltar todo lo que
               colgaba de este miembro, o quedan filas y archivos apuntando a un id
               que ya no existe (su ficha, su avatar, sus ajustes, sus tareas…). */
            // 1) Ficha social y su foto de avatar
            try {
                $foto = db()->query("SELECT foto FROM admin_profiles WHERE admin_id=".$id)->fetchColumn();
                if ($foto && is_file(__DIR__.'/../uploads/avatars/'.$foto)) @unlink(__DIR__.'/../uploads/avatars/'.$foto);
            } catch (Exception $e) { error_log('team-delete foto: '.$e->getMessage()); }
            try { db()->prepare('DELETE FROM admin_profiles WHERE admin_id=?')->execute([$id]); } catch (Exception $e) { error_log('team-delete perfil: '.$e->getMessage()); }
            // 2) Preferencia de avisos (settings.notifmute_<id>)
            try { db()->prepare('DELETE FROM settings WHERE clave=?')->execute(['notifmute_'.$id]); } catch (Exception $e) { error_log('team-delete notifmute: '.$e->getMessage()); }
            // 3) Pertenencia a salas de chat
            try { db()->prepare('DELETE FROM chat_members WHERE admin_id=?')->execute([$id]); } catch (Exception $e) { error_log('team-delete chat: '.$e->getMessage()); }
            // 4) Sin asignaciones fantasma: soltar sus tareas y el puente de asignados
            try { db()->prepare('UPDATE tasks SET responsable_id=NULL WHERE responsable_id=?')->execute([$id]); } catch (Exception $e) { error_log('team-delete tasks: '.$e->getMessage()); }
            try { db()->prepare('DELETE FROM task_assignees WHERE admin_id=?')->execute([$id]); } catch (Exception $e) { error_log('team-delete asignados: '.$e->getMessage()); }
            // 5) Finalmente, el acceso al panel
            db()->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
            $msg = 'miembro-eliminado';
        }
    }
} elseif ($id) {
    $msg = 'no-puedes-borrarte';
}

header('Location: team.php' . ($msg ? '?msg=' . $msg : ''));
exit;
