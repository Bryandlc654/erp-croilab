<?php
/* Dirección de retorno (callback OAuth) de «Google · Métricas».
   Google redirige aquí tras dar permiso, con ?code=... (o ?error=... si se cancela).
   Canjea el permiso por el token y vuelve a Integraciones ya conectado.

   Es un archivo propio y ESTABLE a propósito: así la conexión puede vivir en la
   pantalla que sea (hoy Integraciones) sin tener que volver a tocar Google Cloud.
   Mismo patrón que gcal_callback.php para el Calendario. */
require_once __DIR__ . '/../auth.php';
require_admin();
require_once __DIR__ . '/lib/google_metrics.php';

if (!can_edit()) { header('Location: integraciones.php?i=gmet'); exit; }
if (isset($_GET['error'])) { header('Location: integraciones.php?i=gmet&ok=connerr'); exit; }

$code = $_GET['code'] ?? '';
if ($code === '') { header('Location: integraciones.php?i=gmet'); exit; }

$r = gm_oauth_exchange($code);
header('Location: integraciones.php?i=gmet&ok=' . ($r === true ? 'conn' : 'connerr'));
exit;
