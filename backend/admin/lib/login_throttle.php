<?php
/* ============================================================
   Freno a la fuerza bruta de login — persistente y por usuario+IP.

   Por qué existe: el freno anterior era un contador en $_SESSION con un
   sleep(2). Como vivía en la sesión, se saltaba con solo NO reenviar la cookie:
   cada intento empezaba de cero. Esto lo guarda en la base de datos, atado a
   (usuario, IP), así que el atacante no puede reiniciar el contador.

   Qué NO hace, a propósito: no bloquea para siempre. Tras 15 minutos de calma
   —o un login correcto— se olvida solo. Y como la clave es usuario+IP, alguien
   desde fuera NO puede dejar bloqueado al usuario de verdad (que entra desde
   otra IP). Así se frena el ataque sin dejar tirado a nadie.

   Escalado: a partir de 5 fallos seguidos, bloqueo creciente
   (1, 2, 4, 8, 15 min como tope). Se usa en login.php (cliente) y
   admin/login.php (equipo). Los identificadores van con prefijo 'cli:' / 'adm:'
   para que un cliente y un admin con el mismo usuario no se pisen.
   ============================================================ */

function login_throttle_ensure() { if (croilab_esquema_gestionado()) return;   /* el esquema lo crean las migraciones */
  static $done = false; if ($done) return; $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS login_attempts (
      ident VARCHAR(200) NOT NULL,
      ip    VARCHAR(64)  NOT NULL DEFAULT '',
      fails INT NOT NULL DEFAULT 0,
      last_at INT NOT NULL DEFAULT 0,
      blocked_until INT NOT NULL DEFAULT 0,
      PRIMARY KEY (ident, ip)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Exception $e) {}
}

/* IP del que intenta (recortada por seguridad). */
function login_throttle_ip() {
  return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

/* Normaliza el identificador (minúsculas, sin espacios). Incluye el prefijo. */
function login_throttle_key($ident) {
  return mb_substr(mb_strtolower(trim((string)$ident)), 0, 200);
}

/* Segundos de olvido tras el último intento. */
function login_throttle_ventana() { return 900; } // 15 min

/* ¿Está bloqueado ahora? Devuelve los segundos que faltan, o 0 si puede probar. */
function login_throttle_bloqueo($ident) {
  login_throttle_ensure();
  $ident = login_throttle_key($ident); if ($ident === '') return 0;
  try {
    $st = db()->prepare("SELECT blocked_until FROM login_attempts WHERE ident=? AND ip=?");
    $st->execute([$ident, login_throttle_ip()]);
    $b = (int)$st->fetchColumn();
  } catch (Exception $e) { return 0; }
  $now = time();
  return $b > $now ? $b - $now : 0;
}

/* Registra un intento fallido y, si toca, activa un bloqueo temporal. */
function login_throttle_fallo($ident) {
  login_throttle_ensure();
  $ident = login_throttle_key($ident); if ($ident === '') return;
  $ip = login_throttle_ip(); $now = time();
  try {
    $st = db()->prepare("SELECT fails, last_at FROM login_attempts WHERE ident=? AND ip=?");
    $st->execute([$ident, $ip]); $r = $st->fetch();
    /* Si el último fallo fue hace más que la ventana, se empieza a contar de nuevo. */
    $reciente = $r && ($now - (int)$r['last_at']) < login_throttle_ventana();
    $fails = $reciente ? (int)$r['fails'] + 1 : 1;
    /* A partir de 5 fallos: 60s · 2^(fails-5), con tope de 15 min. */
    $bloqueo = $fails >= 5 ? min(900, 60 * (1 << min($fails - 5, 4))) : 0;
    $blockedUntil = $bloqueo ? $now + $bloqueo : 0;
    db()->prepare("INSERT INTO login_attempts (ident, ip, fails, last_at, blocked_until)
                   VALUES (?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE fails=VALUES(fails), last_at=VALUES(last_at), blocked_until=VALUES(blocked_until)")
        ->execute([$ident, $ip, $fails, $now, $blockedUntil]);
  } catch (Exception $e) {}
}

/* Login correcto: se borra el historial de ese usuario+IP. */
function login_throttle_ok($ident) {
  login_throttle_ensure();
  $ident = login_throttle_key($ident); if ($ident === '') return;
  try { db()->prepare("DELETE FROM login_attempts WHERE ident=? AND ip=?")->execute([$ident, login_throttle_ip()]); } catch (Exception $e) {}
}

/* Mensaje legible del tiempo de espera. */
function login_throttle_msg($seg) {
  $min = max(1, (int)ceil($seg / 60));
  return $min <= 1
    ? 'Demasiados intentos. Espera un minuto y vuelve a probar.'
    : "Demasiados intentos. Espera unos $min minutos y vuelve a probar.";
}
