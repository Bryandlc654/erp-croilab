<?php
/* Pantalla donde alguien se pone su propia contraseña con un enlace de un solo
   uso que le ha pasado el Dueño (se generan en Ajustes › Mi equipo).

   Es la ÚNICA página de admin/ que se abre sin sesión: por eso no llama a
   require_admin(). Lo que la protege es el token —48 horas, un solo uso, y se
   invalida al usarlo o al generar otro—, no la sesión.

   Tampoco emite el armazón del ERP: quien llega aquí no ha entrado todavía, así
   que ve una pantalla de acceso, igual que el login. */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/lib/pwreset.php';
require_once __DIR__ . '/lib/marca.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$uid   = pwreset_valido($token);
$err   = '';
$hecho = false;

if ($uid && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $p1 = (string)($_POST['p1'] ?? '');
  $p2 = (string)($_POST['p2'] ?? '');
  if (strlen($p1) < 6)      $err = 'La contraseña debe tener al menos 6 caracteres.';
  elseif ($p1 !== $p2)      $err = 'Las dos contraseñas no coinciden.';
  else {
    try {
      db()->prepare('UPDATE admins SET password_hash=? WHERE id=?')
          ->execute([password_hash($p1, PASSWORD_DEFAULT), $uid]);
      pwreset_borrar($token);   // un solo uso
      $hecho = true;
    } catch (Exception $e) { $err = 'No se ha podido guardar. Inténtalo otra vez.'; }
  }
}

$quien = '';
if ($uid) { try { $q = db()->prepare('SELECT username FROM admins WHERE id=?'); $q->execute([$uid]); $quien = (string)$q->fetchColumn(); } catch (Exception $e) {} }
$M = marca_agencia();
?><!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Elegir contraseña · <?= e($M['name']) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#3c4149;background:#f5f5f7;
  display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.rs{background:#fff;border-radius:18px;box-shadow:0 24px 70px -20px rgba(16,19,24,.28),0 0 0 1px rgba(16,19,24,.05);
  width:390px;max-width:100%;padding:30px 30px 24px;text-align:center}
.rs .mk{width:52px;height:52px;border-radius:16px;background:#1f232a;color:#fff;display:flex;align-items:center;justify-content:center;
  font-weight:800;font-size:23px;margin:0 auto 16px;overflow:hidden}
.rs .mk img{width:100%;height:100%;object-fit:contain}
.rs h1{font-size:18px;margin:0 0 6px;color:#22262c}
.rs p{font-size:13px;color:#6b7078;line-height:1.55;margin:0 0 20px}
.rs label{display:block;text-align:left;font-size:12px;color:#9aa0a8;font-weight:600;margin:0 0 5px}
.rs input{width:100%;border:1px solid #eeeeef;background:#f7f7f8;border-radius:10px;padding:11px 12px;font:inherit;font-size:14px;
  color:#3c4149;outline:none;margin-bottom:12px;transition:border-color .12s,background .12s,box-shadow .14s}
.rs input:focus{border-color:#c9ccd1;background:#fff;box-shadow:0 0 0 3px rgba(31,35,42,.06)}
/* Campo de contraseña: candado a la izquierda y ojo a la derecha, los dos DENTRO
   de la caja. Escribir una contraseña a ciegas y que además no se vea cuál de
   las dos cajas es cuál era pedir que se equivocara. */
.rs .cja{position:relative;display:flex;align-items:center}
.rs .cja .cd{position:absolute;left:13px;width:16px;height:16px;stroke:#9aa0a8;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;pointer-events:none}
.rs .cja input{padding-left:39px;padding-right:44px;margin-bottom:12px}
.rs .ojo{position:absolute;right:6px;top:5px;width:auto;border:none;background:none;padding:7px;border-radius:8px;color:#9aa0a8;cursor:pointer;display:flex;transition:background .12s,color .12s}
.rs .ojo:hover{background:#f2f2f3;color:#1d1d1f;transform:none;box-shadow:none}
.rs .ojo.on{color:#1d1d1f}
.rs .ojo svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.rs .coinci{font-size:12px;text-align:left;min-height:16px;margin:-4px 0 10px;font-weight:600}
.rs .coinci.ok{color:#12a150}
.rs .coinci.mal{color:#c0343a}
.rs button{width:100%;border:none;background:#1f232a;color:#fff;border-radius:10px;padding:12px;font:inherit;font-size:14px;
  font-weight:600;cursor:pointer;transition:transform .16s,box-shadow .18s}
.rs button:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.16)}
.rs .err{background:#feecec;border:1px solid #f6cfcf;color:#c0343a;border-radius:10px;padding:9px 12px;font-size:12.5px;margin-bottom:14px;text-align:left}
.rs .ok{width:52px;height:52px;border-radius:50%;background:#e7f7ee;color:#12a150;display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
.rs .ok svg{width:26px;height:26px}
.rs a.volver{display:inline-block;margin-top:16px;font-size:13px;color:#6b7078;text-decoration:none;font-weight:600}
.rs a.volver:hover{color:#22262c}
.rs .pie{margin:18px 0 0;font-size:11.5px;color:#9aa0a8}
</style></head><body>
<div class="rs">
<?php if ($hecho): ?>
  <div class="ok"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></div>
  <h1>Contraseña guardada</h1>
  <p>Ya puedes entrar al panel con tu usuario <b><?= e($quien) ?></b> y la contraseña que acabas de poner.</p>
  <a class="volver" href="login.php">Ir a la pantalla de acceso →</a>

<?php elseif (!$uid): ?>
  <div class="mk"><?= $M['logo'] ? '<img src="'.e($M['logo']).'" alt="">' : e(mb_strtoupper(mb_substr($M['name'],0,1))) ?></div>
  <h1>Este enlace ya no vale</h1>
  <p>Los enlaces para elegir contraseña caducan a las <?= (int)pwreset_horas() ?> horas y solo se pueden usar una vez.<br>
     Pídele otro a quien lleve el panel.</p>
  <a class="volver" href="login.php">Ir a la pantalla de acceso →</a>

<?php else: ?>
  <div class="mk"><?= $M['logo'] ? '<img src="'.e($M['logo']).'" alt="">' : e(mb_strtoupper(mb_substr($M['name'],0,1))) ?></div>
  <h1>Elige tu contraseña</h1>
  <p>Hola <b><?= e($quien) ?></b>. Pon la contraseña con la que quieras entrar al panel de <?= e($M['name']) ?>.</p>
  <?php if ($err !== ''): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="t" value="<?= e($token) ?>">
    <label for="p1">Contraseña nueva</label>
    <div class="cja">
      <svg class="cd" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      <input id="p1" type="password" name="p1" required minlength="6" autocomplete="new-password" autofocus placeholder="Al menos 6 caracteres">
      <button type="button" class="ojo" onclick="ver('p1',this)" title="Ver la contraseña" aria-label="Ver la contraseña"><svg viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg></button>
    </div>
    <?php /* Antes esta etiqueta decía solo «Repítela» y el campo iba sin marcador:
             mirando el formulario de golpe, dos cajas de contraseña seguidas no
             dicen cuál es cuál. Ahora lo pone en los dos sitios. */ ?>
    <label for="p2">Repite la contraseña nueva</label>
    <div class="cja">
      <svg class="cd" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      <input id="p2" type="password" name="p2" required minlength="6" autocomplete="new-password" placeholder="Vuelve a escribir la misma">
      <button type="button" class="ojo" onclick="ver('p2',this)" title="Ver la contraseña" aria-label="Ver la contraseña"><svg viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg></button>
    </div>
    <div class="coinci" id="coinci"></div>
    <button type="submit">Guardar contraseña</button>
  </form>
  <script>
  var OJO_ON  = '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>';
  var OJO_OFF = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22"/>';
  function ver(id, btn){
    var i=document.getElementById(id), oculto=(i.type==='password');
    i.type = oculto ? 'text' : 'password';
    btn.querySelector('svg').innerHTML = oculto ? OJO_OFF : OJO_ON;
    btn.classList.toggle('on', oculto);
  }
  /* Decirlo mientras escribe, no después de enviar: si las dos no coinciden lo
     sabe ya, sin perder lo escrito en una recarga. */
  var a=document.getElementById('p1'), b=document.getElementById('p2'), c=document.getElementById('coinci');
  function mirar(){
    if(!b.value){ c.textContent=''; c.className='coinci'; return; }
    var ok = a.value===b.value;
    c.textContent = ok ? 'Las dos coinciden' : 'Las dos contraseñas no coinciden';
    c.className = 'coinci ' + (ok ? 'ok' : 'mal');
  }
  a.addEventListener('input',mirar); b.addEventListener('input',mirar);
  </script>
  <div class="pie">Este enlace caduca a las <?= (int)pwreset_horas() ?> horas y solo sirve una vez.</div>
<?php endif; ?>
</div>
</body></html>
