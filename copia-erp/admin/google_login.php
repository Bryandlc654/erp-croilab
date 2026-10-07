<?php
/* Arranque de «Entrar con Google» para el panel de equipo. Es PÚBLICO (el usuario aún no
   ha iniciado sesión). No registra a nadie: solo manda a Google a identificarse; luego
   gcal_callback.php comprueba que ese correo pertenece a un miembro del equipo. */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/lib/gcal.php';

if (current_admin()) { header('Location: dashboard.php'); exit; }
if (!gcal_configured()) { header('Location: login.php?ge=nocfg'); exit; }

$state = bin2hex(random_bytes(16));
$_SESSION['glogin'] = 1;
$_SESSION['glogin_state'] = $state;
header('Location: ' . gcal_login_auth_url($state));
exit;
