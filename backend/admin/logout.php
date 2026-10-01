<?php
/* Cierre de sesión del panel. Mismo criterio que el del portal: sin db.php,
   para que siga funcionando con la base de datos caída. */
require_once __DIR__ . '/../sesion.php';

/* Solo por POST y con token: por GET se podrían cerrar sesiones ajenas
   incrustando una imagen en otra web. */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: login.php');
    exit;
}
csrf_check();

/* sesion_cerrar() vacía la sesión, borra la cookie y deja la traza. Antes esto
   estaba copiado aquí y en logout.php, dos veces, y sin rastro de nada. */
sesion_cerrar('logout panel (voluntario)');

header('Location: login.php?cerrada=1');
exit;
