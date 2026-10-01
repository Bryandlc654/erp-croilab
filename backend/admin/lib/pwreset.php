<?php
/* Enlaces de un solo uso para que alguien se ponga su propia contraseña.

   Por qué un enlace y no un correo: este ERP **no envía correos** (lo único que
   habla con fuera es Google Calendar). Prometer «te hemos mandado un email» y
   que no llegue nada es peor que no ofrecerlo. Así que se genera un enlace, se
   copia y se le pasa por donde sea — chat, WhatsApp, en persona.

   Qué NO se puede hacer, y conviene tenerlo claro: **la contraseña que elija esa
   persona no se puede consultar después**. En `admins.password_hash` no se
   guarda la contraseña, se guarda un hash irreversible (password_hash de PHP);
   ni el Dueño ni nadie puede leerla, y así debe ser. El control equivalente sí
   lo tiene el Dueño: puede cambiársela cuando quiera, con cualquiera de las tres
   vías de team-edit.php.

   Los tokens viven en `settings`, con clave `pwreset_<token>` y valor
   `<adminId>|<caduca>`. No hace falta tabla nueva, y caducan solos.
*/

function pwreset_horas() { return 48; }

/* Crea un enlace nuevo e invalida los anteriores de esa persona: si el Dueño
   genera uno segundo, el primero deja de valer. */
function pwreset_crear($adminId) {
  $adminId = (int)$adminId;
  if (!$adminId) return '';
  pwreset_limpiar($adminId);
  $token = bin2hex(random_bytes(24));
  $caduca = time() + pwreset_horas() * 3600;
  try {
    db()->prepare("INSERT INTO settings (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")
        ->execute(['pwreset_'.$token, $adminId.'|'.$caduca]);
  } catch (Exception $e) { error_log('pwreset_crear: '.$e->getMessage()); return ''; }
  return $token;
}

/* Devuelve el id del admin si el token vale, o 0. */
function pwreset_valido($token) {
  $token = preg_replace('/[^a-f0-9]/', '', (string)$token);
  if ($token === '') return 0;
  try {
    $st = db()->prepare("SELECT valor FROM settings WHERE clave=?");
    $st->execute(['pwreset_'.$token]);
    $v = (string)$st->fetchColumn();
  } catch (Exception $e) { return 0; }
  if ($v === '') return 0;
  list($id, $caduca) = array_pad(explode('|', $v, 2), 2, '0');
  if ((int)$caduca < time()) { pwreset_borrar($token); return 0; }
  return (int)$id;
}

function pwreset_borrar($token) {
  $token = preg_replace('/[^a-f0-9]/', '', (string)$token);
  try { db()->prepare("DELETE FROM settings WHERE clave=?")->execute(['pwreset_'.$token]); } catch (Exception $e) {}
}

/* Quita los enlaces vivos de una persona (y de paso los caducados de cualquiera). */
function pwreset_limpiar($adminId = 0) {
  try {
    foreach (db()->query("SELECT clave, valor FROM settings WHERE clave LIKE 'pwreset_%'") as $r) {
      list($id, $caduca) = array_pad(explode('|', (string)$r['valor'], 2), 2, '0');
      if ((int)$caduca < time() || ($adminId && (int)$id === (int)$adminId))
        db()->prepare("DELETE FROM settings WHERE clave=?")->execute([$r['clave']]);
    }
  } catch (Exception $e) {}
}

/* La dirección completa del enlace, lista para copiar. */
function pwreset_url($token) {
  $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
  $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $dir   = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php')));
  if ($dir === '/' || $dir === '.') $dir = '';
  return ($https ? 'https' : 'http') . '://' . $host . $dir . '/reset.php?t=' . $token;
}

/* ¿Tiene un enlace vivo ahora mismo? Devuelve [token, caduca] o null. */
function pwreset_activo($adminId) {
  $adminId = (int)$adminId;
  try {
    foreach (db()->query("SELECT clave, valor FROM settings WHERE clave LIKE 'pwreset_%'") as $r) {
      list($id, $caduca) = array_pad(explode('|', (string)$r['valor'], 2), 2, '0');
      if ((int)$id === $adminId && (int)$caduca > time())
        return ['token' => substr((string)$r['clave'], 8), 'caduca' => (int)$caduca];
    }
  } catch (Exception $e) {}
  return null;
}

/* ---------- «He olvidado mi contraseña», desde el login ----------

   El ERP no manda correos, así que no se puede enviar un enlace directamente a
   quien lo pide. Lo que sí se puede es **avisar a quien sí puede dárselo**: se
   crea una notificación para todo el que gestione el equipo, con enlace directo
   a la ficha de esa persona. En dos clics le genera el enlace y se lo pasa.

   Dos cosas a propósito:
     · Se acepta el usuario **o** el correo, porque nadie recuerda cuál puso.
     · La pantalla responde SIEMPRE lo mismo, exista esa persona o no. Si dijera
       «ese usuario no existe» cualquiera podría averiguar desde fuera quién
       trabaja aquí, probando nombres.
     · Una petición por persona y día (la clave `ref` lo impide): si alguien
       pulsa diez veces, el Dueño ve un aviso, no diez. */
function pwreset_pedir($quien) {
  $quien = trim((string)$quien);
  if ($quien === '') return;

  try {
    $st = db()->prepare('SELECT id, username FROM admins WHERE username=? OR (email IS NOT NULL AND LOWER(email)=?) LIMIT 1');
    $st->execute([$quien, mb_strtolower($quien)]);
    $a = $st->fetch();
  } catch (Exception $e) { return; }
  if (!$a) return;                       // no existe: se calla y responde igual

  /* Quién puede resolverlo: el que gestiona el equipo (y el Dueño, que puede todo). */
  require_once __DIR__ . '/permisos.php';
  $roles = roles_todos();
  $puede = [];
  foreach ($roles as $clave => $r) {
    $p = $r['permisos'] ?? [];
    if (in_array('admin.total', $p, true) || in_array('equipo.gestionar', $p, true)) $puede[] = $clave;
  }
  if (!$puede) return;

  $marca = 'pwreq_'.(int)$a['id'].'_'.date('Ymd');
  try {
    $in  = implode(',', array_fill(0, count($puede), '?'));
    $st  = db()->prepare("SELECT id FROM admins WHERE role IN ($in)");
    $st->execute($puede);
    $ins = db()->prepare('INSERT IGNORE INTO notifications (admin_id,tipo,titulo,cuerpo,url,ref,tarea,actor) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($st as $r) {
      if ((int)$r['id'] === (int)$a['id']) continue;   // no avisarse a uno mismo
      $ins->execute([
        (int)$r['id'], 'info',
        'ha olvidado su contraseña',
        'Entra en su ficha y ponle una nueva, o créale un enlace para que la elija.',
        'team-edit.php?id='.(int)$a['id'],
        $marca, '', (string)$a['username'],
      ]);
    }
  } catch (Exception $e) { error_log('pwreset_pedir: '.$e->getMessage()); }
}
