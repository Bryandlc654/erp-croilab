<?php
/* Vuelta del consentimiento de Google (OAuth). Google redirige aquí con ?code=…&state=…
   Verificamos el state (anti-CSRF), canjeamos el code por tokens y volvemos al calendario. */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/lib/gcal.php';

/* --- Vuelta de «Entrar con Google» (el usuario TODAVÍA no tiene sesión de admin) ---
   Se distingue del flujo de calendario por el marcador de sesión `glogin`, puesto en
   google_login.php. Solo entra quien tenga su correo de Google guardado en su cuenta. */
if (!empty($_SESSION['glogin'])) {
  $saved = $_SESSION['glogin_state'] ?? '';
  unset($_SESSION['glogin'], $_SESSION['glogin_state']);
  if (($_GET['error'] ?? '') !== '') { header('Location: login.php?ge=cancel'); exit; }
  $code = $_GET['code'] ?? ''; $state = $_GET['state'] ?? '';
  if ($code === '' || $state === '' || !hash_equals((string)$saved, (string)$state)) { header('Location: login.php?ge=err'); exit; }
  $email = gcal_email_from_code($code);
  if ($email === '') { header('Location: login.php?ge=err'); exit; }
  if (($_SESSION['adm_fails'] ?? 0) >= 5) { sleep(2); }
  ensure_schema();                                   // garantiza que exista admins.email
  $st = db()->prepare('SELECT id FROM admins WHERE email IS NOT NULL AND LOWER(email)=? LIMIT 1');
  $st->execute([$email]);
  $adm = $st->fetch();
  if (!$adm) { $_SESSION['adm_fails'] = ($_SESSION['adm_fails'] ?? 0) + 1; header('Location: login.php?ge=denied'); exit; }
  unset($_SESSION['adm_fails']);
  session_regenerate_id(true);
  $_SESSION['admin_id'] = (int)$adm['id'];
  header('Location: dashboard.php'); exit;
}

/* --- Vuelta de «Entrar con Google» del CLIENTE (portal, todavía sin sesión de cliente) ---
   Marcador `gclilogin`, puesto en client_google_login.php. Deja entrar al cliente cuyo
   correo de Google coincide con el que el equipo guardó en su ficha (clients.login_email).
   El portal vive un nivel por encima de /admin/, de ahí los «../» en las redirecciones. */
if (!empty($_SESSION['gclilogin'])) {
  $saved = $_SESSION['gclilogin_state'] ?? '';
  unset($_SESSION['gclilogin'], $_SESSION['gclilogin_state']);
  if (($_GET['error'] ?? '') !== '') { header('Location: ../login.php?ge=cancel'); exit; }
  $code = $_GET['code'] ?? ''; $state = $_GET['state'] ?? '';
  if ($code === '' || $state === '' || !hash_equals((string)$saved, (string)$state)) { header('Location: ../login.php?ge=err'); exit; }
  $email = gcal_email_from_code($code);
  if ($email === '') { header('Location: ../login.php?ge=err'); exit; }
  if (($_SESSION['cli_fails'] ?? 0) >= 5) { sleep(2); }
  ensure_schema();                                   // garantiza que exista clients.login_email
  $st = db()->prepare("SELECT id FROM clients WHERE login_email<>'' AND LOWER(login_email)=? LIMIT 1");
  $st->execute([$email]);
  $cli = $st->fetch();
  if (!$cli) { $_SESSION['cli_fails'] = ($_SESSION['cli_fails'] ?? 0) + 1; header('Location: ../login.php?ge=denied'); exit; }
  unset($_SESSION['cli_fails']);
  session_regenerate_id(true);
  $_SESSION['client_id'] = (int)$cli['id'];
  header('Location: ../index.php'); exit;
}

require_admin();
require_once __DIR__ . '/erp_nav.php';

$me = current_admin(); $meId = (int)$me['id'];

/* ¿Iniciar la conexión? (enlace «Conectar»): genera state, guarda en sesión y va a Google. */
if (isset($_GET['start'])) {
  if (!gcal_configured()) { header('Location: settings.php?section=gcal'); exit; }
  $state = bin2hex(random_bytes(16));
  $_SESSION['gcal_state'] = $state;
  header('Location: ' . gcal_auth_url($state)); exit;
}

/* Desconectar. */
if (isset($_GET['disconnect'])) {
  gcal_disconnect($meId);
  header('Location: calendar.php?gc=off'); exit;
}

/* Vuelta de Google. */
$err = $_GET['error'] ?? '';
if ($err !== '') { header('Location: calendar.php?gc=err'); exit; }

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$saved = $_SESSION['gcal_state'] ?? '';
unset($_SESSION['gcal_state']);

if ($code === '' || $state === '' || !hash_equals((string)$saved, (string)$state)) {
  header('Location: calendar.php?gc=err'); exit;
}

$ok = gcal_exchange_code($code, $meId);
header('Location: calendar.php?gc=' . ($ok ? 'ok' : 'err'));
exit;
