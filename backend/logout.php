<?php
/* Cierre de sesión del portal de cliente.
   No carga db.php a propósito: si la base de datos está caída, el cierre tiene
   que funcionar igualmente. Sesión y CSRF salen de sesion.php, que no depende
   de la base de datos. */
require_once __DIR__ . '/sesion.php';

/* Solo por POST y con token: por GET se podrían cerrar sesiones ajenas
   incrustando una imagen en otra web. */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: login.php');
    exit;
}
csrf_check();

/* sesion_cerrar() vacía la sesión, borra la cookie y deja la traza. Antes esto
   estaba copiado aquí y en admin/logout.php, dos veces, y sin rastro de nada. */
sesion_cerrar('logout portal (voluntario)');

header('Location: login.php?cerrada=1');
exit;
