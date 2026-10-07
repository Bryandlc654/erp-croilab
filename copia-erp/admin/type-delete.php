<?php
/* Elimina un tipo de cliente. Solo POST + CSRF (auth.php lo comprueba). */
require_once __DIR__ . '/../auth.php';
require_can_edit();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: types.php'); exit; }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id) {
    // los clientes con este tipo quedan sin tipo (no se borran)
    db()->prepare('UPDATE clients SET tipo_id = NULL WHERE tipo_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM client_types WHERE id = ?')->execute([$id]);
}
header('Location: types.php?msg=tipo-eliminado');
exit;
