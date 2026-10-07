<?php
/* ===========================================================
   INSTALADOR — ábrelo UNA vez en el navegador: tudominio/install.php
   Crea las tablas y el usuario del dueño (eliges tú el usuario y la
   contraseña). El ERP arranca VACÍO: sin clientes ni datos de ejemplo.
   ⚠️ BÓRRALO del servidor cuando termine.
   Con candado: si la base ya tiene admins, solo lo abre el dueño.
   =========================================================== */
require_once __DIR__ . '/_setup_guard.php';
require_once __DIR__ . '/db.php';

$pdo = db();
$msg = [];
$err = '';

// 1) Tablas
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

$hayAdmin = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
$usuario  = trim((string)($_POST['usuario'] ?? ''));

// 2) Usuario del dueño: solo en una instalación nueva y cuando se envía el formulario
if (!$hayAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass  = (string)($_POST['pass'] ?? '');
    $pass2 = (string)($_POST['pass2'] ?? '');
    if (!preg_match('/^[\p{L}\p{N}._@-]{3,60}$/u', $usuario)) {
        $err = 'El usuario debe tener entre 3 y 60 caracteres (letras, números, punto, guion o @).';
    } elseif (mb_strlen($pass) < 8) {
        $err = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($pass !== $pass2) {
        $err = 'Las dos contraseñas no coinciden.';
    } else {
        $pdo->prepare("INSERT INTO admins (username, password_hash, role) VALUES (?, ?, 'owner')")
            ->execute([$usuario, password_hash($pass, PASSWORD_DEFAULT)]);
        $hayAdmin = true;
        $msg[] = 'Usuario del dueño creado: <b>' . htmlspecialchars($usuario, ENT_QUOTES, 'UTF-8') . '</b>';
    }
}

// 3) Tipos de cliente por defecto (configuración, no datos)
if ($hayAdmin) {
    $msg[] = 'Tablas creadas/verificadas.';
    $todas   = ['metricas'=>1,'progreso'=>1,'informes'=>1,'como'=>1,'accesos'=>1,'plan'=>1];
    $soloWeb = ['metricas'=>0,'progreso'=>1,'informes'=>0,'como'=>1,'accesos'=>1,'plan'=>1];
    if ((int)$pdo->query('SELECT COUNT(*) FROM client_types')->fetchColumn() === 0) {
        $insT = $pdo->prepare('INSERT INTO client_types (nombre, secciones_json) VALUES (?, ?)');
        $insT->execute(['SEO completo', json_encode($todas, JSON_UNESCAPED_UNICODE)]);
        $insT->execute(['Solo web', json_encode($soloWeb, JSON_UNESCAPED_UNICODE)]);
        $insT->execute(['SEM (campañas)', json_encode($todas, JSON_UNESCAPED_UNICODE)]);
        $msg[] = 'Tipos de cliente por defecto creados (SEO completo, Solo web, SEM). Puedes cambiarlos en Ajustes.';
    }
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Instalación</title>
<style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f7f7f8;color:#22262c;max-width:520px;margin:40px auto;padding:0 20px;line-height:1.6}
.card{background:#fff;border:1px solid #eeeeef;border-radius:14px;padding:26px 28px}
h1{font-size:21px;margin:0 0 6px}.ok{color:#12a150;margin:4px 0}.err{color:#c0343a;background:#feecec;border-radius:8px;padding:8px 12px}
label{display:block;font-size:13px;font-weight:600;margin:14px 0 4px}
input{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #dcdce0;border-radius:8px;font:inherit}
button{margin-top:18px;width:100%;padding:11px;border:0;border-radius:8px;background:#1f232a;color:#fff;font:inherit;font-weight:600;cursor:pointer}
a{color:#1f232a;font-weight:600}code{background:#f2f2f3;padding:2px 6px;border-radius:6px}.muted{color:#6b7078;font-size:14px}</style></head>
<body><div class="card">
<?php if (!$hayAdmin): ?>
  <h1>Instalación del ERP</h1>
  <p class="muted">Crea el usuario del dueño (acceso total). Con él entrarás al panel y darás de alta al resto del equipo.</p>
  <?php if ($err): ?><p class="err"><?= $err ?></p><?php endif; ?>
  <form method="post" autocomplete="off">
    <label for="u">Usuario</label>
    <input id="u" name="usuario" required value="<?= htmlspecialchars($usuario, ENT_QUOTES, 'UTF-8') ?>">
    <label for="p">Contraseña (mínimo 8 caracteres)</label>
    <input id="p" name="pass" type="password" required minlength="8" autocomplete="new-password">
    <label for="p2">Repite la contraseña</label>
    <input id="p2" name="pass2" type="password" required minlength="8" autocomplete="new-password">
    <button type="submit">Instalar</button>
  </form>
<?php else: ?>
  <h1>✅ Instalación completada</h1>
  <?php foreach ($msg as $m) echo "<p class='ok'>• $m</p>"; ?>
  <hr style="border:0;border-top:1px solid #eeeeef;margin:18px 0">
  <p><b>Ahora:</b></p>
  <p>1. Entra al panel de administración en <a href="admin/">/admin/</a> con el usuario que acabas de crear.</p>
  <p>2. En <b>Ajustes</b> rellena los datos de tu empresa y da de alta a tu equipo y a tus clientes.</p>
  <p style="color:#c0343a"><b>3. Borra este archivo <code>install.php</code> del servidor por seguridad.</b></p>
<?php endif; ?>
</div></body></html>
