<?php
/* Auto-registro por enlace temporal.

   El Dueño genera un enlace en Ajustes › Mi equipo; quien lo abre crea su PROPIA
   cuenta de equipo (usuario, correo y contraseña). Sin un enlace vivo, nadie puede
   darse de alta: es la misma garantía que da reset.php para las contraseñas.

   A diferencia de pwreset (que apunta a un admin que YA existe), aquí el token no
   lleva admin_id —el usuario todavía no existe—, lleva el ROL que tendrá la cuenta
   nueva. Vive en `settings`, clave `signup_<token>`, valor `<rol>|<caduca>`.
   Caduca solo y es de un solo uso (se borra al completarse el registro). */

function signup_horas() { return 48; }

/* Crea un enlace nuevo para el rol dado. Devuelve el token, o '' si falla. */
function signup_crear($rol) {
  $rol = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$rol));
  if ($rol === '') $rol = 'viewer';
  $token  = bin2hex(random_bytes(24));
  $caduca = time() + signup_horas() * 3600;
  try {
    db()->prepare("INSERT INTO settings (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")
        ->execute(['signup_'.$token, $rol.'|'.$caduca]);
  } catch (Exception $e) { error_log('signup_crear: '.$e->getMessage()); return ''; }
  return $token;
}

/* Devuelve el rol si el token vale, o '' si no vale / caducó. */
function signup_valido($token) {
  $token = preg_replace('/[^a-f0-9]/', '', (string)$token);
  if ($token === '') return '';
  try {
    $st = db()->prepare("SELECT valor FROM settings WHERE clave=?");
    $st->execute(['signup_'.$token]);
    $v = (string)$st->fetchColumn();
  } catch (Exception $e) { return ''; }
  if ($v === '') return '';
  list($rol, $caduca) = array_pad(explode('|', $v, 2), 2, '0');
  if ((int)$caduca < time()) { signup_borrar($token); return ''; }
  return $rol !== '' ? $rol : 'viewer';
}

function signup_borrar($token) {
  $token = preg_replace('/[^a-f0-9]/', '', (string)$token);
  try { db()->prepare("DELETE FROM settings WHERE clave=?")->execute(['signup_'.$token]); } catch (Exception $e) {}
}

/* Enlaces vivos, para listarlos y poder anularlos. Limpia de paso los caducados. */
function signup_activos() {
  $out = [];
  try {
    foreach (db()->query("SELECT clave, valor FROM settings WHERE clave LIKE 'signup_%'") as $r) {
      list($rol, $caduca) = array_pad(explode('|', (string)$r['valor'], 2), 2, '0');
      if ((int)$caduca < time()) { db()->prepare("DELETE FROM settings WHERE clave=?")->execute([$r['clave']]); continue; }
      $out[] = ['token' => substr((string)$r['clave'], 7), 'rol' => $rol, 'caduca' => (int)$caduca];
    }
  } catch (Exception $e) {}
  return $out;
}

/* La dirección completa del enlace, lista para copiar (apunta a registro.php). */
function signup_url($token) {
  $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
  $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $dir   = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php')));
  if ($dir === '/' || $dir === '.') $dir = '';
  return ($https ? 'https' : 'http') . '://' . $host . $dir . '/registro.php?t=' . $token;
}
