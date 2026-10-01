<?php
/* Arranque de «Entrar con Google» para el PORTAL DEL CLIENTE. Es PÚBLICO (el cliente
   aún no ha iniciado sesión). No registra a nadie: manda a Google a identificarse y
   luego gcal_callback.php comprueba que ese correo coincide con el que el equipo guardó
   en la ficha del cliente (clients.login_email).

   Vive dentro de /admin/ a propósito: así la URL de redirección que se le pasa a Google
   sigue siendo /admin/gcal_callback.php, la única registrada en Google Cloud. No hay que
   dar de alta ninguna URL nueva. */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/lib/gcal.php';

if (current_client()) { header('Location: ../index.php'); exit; }
if (!gcal_configured()) { header('Location: ../login.php?ge=nocfg'); exit; }

$state = bin2hex(random_bytes(16));
$_SESSION['gclilogin'] = 1;
$_SESSION['gclilogin_state'] = $state;
header('Location: ' . gcal_login_auth_url($state));
exit;
