<?php
/* Redirección: el gestor de tareas ahora es el centro de trabajo workspace.php */
require_once __DIR__ . '/../auth.php';
require_admin();
$cli = isset($_GET['cli']) ? (int)$_GET['cli'] : 0;
if ($cli) { header('Location: workspace.php?view=cliente&cli=' . $cli); }
else { header('Location: workspace.php?view=all'); }
exit;
