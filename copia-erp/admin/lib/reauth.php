<?php
/* Volver a pedir la contraseña antes de entrar a una pantalla peligrosa.

   Para qué: «Datos avanzados» escribe en la base de datos sin comprobar nada.
   Basta con un descuido —el portátil abierto un momento, una sesión que se queda
   viva en un ordenador compartido— para que alguien borre una tabla entera desde
   ahí. Que la sesión sea de un administrador no significa que sea ÉL quien está
   delante en este momento.

   Cómo funciona: la pantalla se pinta entera pero borrosa y sin poder tocarla, y
   encima sale una ventanita pidiendo la contraseña. Al acertar, queda desbloqueada
   30 minutos y luego vuelve a pedirla. Se ve el contenido de fondo a propósito:
   así uno sabe que ha llegado donde quería y por qué le piden la clave, en vez de
   encontrarse una pantalla en blanco.

   Importante — esto NO sustituye a un permiso. La pantalla ya exige
   `datos.avanzado` en auth.php; esto es la segunda puerta, no la primera. Y el
   bloqueo es de verdad: si no está confirmado, la página TERMINA aquí (exit) y
   el HTML con los datos ni siquiera se llega a generar. Un blur en el navegador
   se quita con el inspector en dos segundos.

   Uso, justo después de require_admin():
     require_once __DIR__.'/lib/reauth.php';
     reauth('datos', 'Datos avanzados');
*/

/* Cuánto dura la confirmación, en segundos. */
function reauth_minutos() { return 30; }

function reauth_ok($zona) {
  $t = (int)($_SESSION['reauth'][$zona] ?? 0);
  return $t > 0 && (time() - $t) < reauth_minutos() * 60;
}

/* Exige la contraseña si no está confirmada. Si no lo está, pinta la pantalla
   de bloqueo y CORTA: nada de lo que venga después se ejecuta ni se envía. */
function reauth($zona, $titulo = 'esta pantalla') {
  $me = current_admin();
  if (!$me) { header('Location: login.php'); exit; }

  $err = '';
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['__reauth'] ?? '') !== '') {
    $pass = (string)$_POST['__reauth'];
    if (password_verify($pass, (string)($me['password_hash'] ?? ''))) {
      $_SESSION['reauth'][$zona] = time();
      /* Redirect a la misma URL para no dejar la contraseña en un POST que se
         reenvía al recargar (y para que la página se pinte limpia). */
      header('Location: ' . ($_SERVER['REQUEST_URI'] ?? basename($_SERVER['SCRIPT_NAME'])));
      exit;
    }
    /* Un intento fallido no debe poder repetirse mil veces por segundo. */
    usleep(400000);
    $err = 'La contraseña no es correcta.';
    error_log('reauth: contraseña incorrecta para «'.$zona.'» de '.($me['username'] ?? '?'));
  }

  if (reauth_ok($zona)) return;
  reauth_pantalla($zona, $titulo, $err, $me);
  exit;
}

/* Cerrar la confirmación a mano («Bloquear otra vez»). */
function reauth_cerrar($zona) { unset($_SESSION['reauth'][$zona]); }

function reauth_pantalla($zona, $titulo, $err, $me) {
  erp_head(function_exists('erp_active_for') ? erp_active_for() : '', $titulo);
  ?>
<style>
/* Una cabecera normal y la tarjeta centrada en el alto que queda.

   Van dos intentos fallidos aquí. El primero pintaba detrás un «esqueleto» de
   cajas difuminadas: con blur y opacidad se fundían en un rectángulo gris plano
   que parecía un error de carga. El segundo lo quitó, pero dejó la tarjeta sola
   en una página en blanco y **sin título**, que se veía igual de rara: una
   pantalla del ERP sin encabezado no parece una pantalla, parece que algo falló.

   Esta lleva su h1 y su explicación como cualquier otra, y la tarjeta se centra
   en el espacio que sobra. El 250px que se descuenta es lo que ocupan la barra
   superior, el encabezado y el respiro de abajo del .erp-wrap. */
.ra-cab{margin-bottom:4px}
.ra-cab .lead{max-width:62ch}
.ra-zona{min-height:calc(100vh - 250px);display:flex;align-items:center;justify-content:center;padding:10px 20px 30px}
@media(max-height:640px){ .ra-zona{min-height:340px} }

/* La tarjeta entra como los diálogos del ERP (#erpDlgOv en erp_nav.php): con una
   transición que dispara una clase puesta por JS, no con una `animation` que
   arranca sola en el primer fotograma. */
.ra-box{background:#fff;border-radius:16px;width:390px;max-width:100%;
  box-shadow:0 24px 70px -18px rgba(16,19,24,.28),0 0 0 1px rgba(16,19,24,.06);
  padding:28px 28px 22px;text-align:center;
  opacity:0;transform:translateY(8px) scale(.985);transition:opacity .18s ease,transform .2s cubic-bezier(.2,.7,.3,1)}
.ra-zona.vis .ra-box{opacity:1;transform:none}
.ra-ic{width:50px;height:50px;border-radius:15px;background:#111318;color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto 15px}
.ra-box h2{font-size:16.5px;font-weight:650;letter-spacing:-.2px;color:var(--ink-strong);margin:0 0 6px}
.ra-box .s{font-size:13px;color:#6b7078;line-height:1.55;margin-bottom:18px}
.ra-box .who{display:inline-flex;align-items:center;gap:8px;background:var(--soft);border-radius:99px;padding:4px 12px 4px 4px;margin-bottom:16px}
.ra-box .who .av{width:24px;height:24px;border-radius:50%;color:#fff;font-weight:700;font-size:11px;display:flex;align-items:center;justify-content:center}
.ra-box .who b{font-size:12.5px;font-weight:600;color:var(--ink)}
/* El campo, con el candado dentro a la izquierda y el ojo a la derecha — el
   mismo molde que la pantalla de entrar, para que no parezca otra aplicación.
   Antes iba centrado y con letra espaciada: sin ningún icono, una caja gris con
   puntos en medio no se lee como «aquí va tu contraseña». */
.ra-cja{position:relative;display:flex;align-items:center}
.ra-cja .cd{position:absolute;left:13px;width:16px;height:16px;stroke:#9aa0a8;fill:none;stroke-width:2;
  stroke-linecap:round;stroke-linejoin:round;pointer-events:none}
.ra-box input[type=password],.ra-box input[type=text]{width:100%;text-align:left;font-size:15px;
  padding:12px 46px 12px 39px;border:1px solid var(--line);background:var(--soft);border-radius:10px;
  font-family:inherit;color:var(--ink);outline:none;
  transition:border-color .12s ease,background .12s ease,box-shadow .14s ease}
.ra-box input:focus{border-color:#c9ccd1;background:#fff;box-shadow:0 0 0 3px rgba(31,35,42,.06)}
.ra-ojo{position:absolute;right:6px;border:none;background:none;padding:7px;border-radius:8px;color:#9aa0a8;
  cursor:pointer;display:flex;transition:background .12s ease,color .12s ease}
.ra-ojo:hover{background:#eeeef0;color:var(--ink-strong)}
.ra-ojo.on{color:var(--ink-strong)}
.ra-ojo svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.ra-box .btn{width:100%;justify-content:center;margin-top:12px}
.ra-box .out{display:inline-block;margin-top:14px;font-size:12.5px;color:var(--muted);text-decoration:none}
.ra-box .out:hover{color:var(--ink)}
.ra-err{background:#feecec;border:1px solid #f6cfcf;color:#c0343a;border-radius:10px;padding:9px 12px;font-size:12.5px;margin-bottom:14px;
  animation:pop .22s ease}
</style>

<?php /* Encabezado igual que el de cualquier otra pantalla: dice dónde estás y
         por qué está bloqueada, antes de pedirte nada. */ ?>
<div class="ra-cab">
  <h1><?= e($titulo) ?></h1>
  <div class="lead">Esta pantalla está protegida con una segunda contraseña porque escribe directamente en la base de datos.</div>
</div>

<div class="ra-zona" id="raZona">
  <div class="ra-box">
    <div class="ra-ic"><?= ic('vault',24) ?></div>
    <h2>Confirma que eres tú</h2>
    <?php /* Sin repetir lo que ya dice el encabezado de la pantalla: aquí solo lo
             que hace falta saber para actuar. */ ?>
    <div class="s">Que la sesión esté abierta no prueba que estés tú delante. Escribe tu contraseña para entrar.</div>

    <div class="who">
      <span class="av" style="background:<?= avatar_color((string)$me['username']) ?>"><?= e(mb_strtoupper(mb_substr((string)$me['username'],0,1))) ?></span>
      <b><?= e((string)$me['username']) ?></b>
    </div>

    <?php if($err!==''): ?><div class="ra-err"><?= e($err) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <div class="ra-cja">
        <svg class="cd" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <input type="password" name="__reauth" id="raPass" placeholder="Tu contraseña" autocomplete="current-password" required>
        <button type="button" class="ra-ojo" id="raOjo" onclick="raVer()" title="Ver la contraseña" aria-label="Ver la contraseña"><svg viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg></button>
      </div>
      <button class="btn" type="submit"><?= ic('check',15) ?> Desbloquear</button>
    </form>
    <a class="out" href="settings.php">← Volver a Ajustes</a>
    <div class="s" style="margin:14px 0 0;font-size:11.5px">Se queda desbloqueado <?= (int)reauth_minutos() ?> minutos.</div>
  </div>
</div>
<script>
(function(){
  /* La clase se pone en el siguiente fotograma, no ahora: si se pusiera ya, el
     navegador pintaría el estado final directamente y no habría transición
     ninguna. Es el mismo truco que usa el kit de diálogos de erp_nav.php. */
  var z = document.getElementById('raZona');
  requestAnimationFrame(function(){ requestAnimationFrame(function(){ z.classList.add('vis'); }); });
  /* NO se enfoca el campo solo. Llegar a una pantalla y encontrarte el cursor ya
     metido en una caja de contraseña, con el aviso del gestor de contraseñas
     saltando encima, se ve descuidado — y aquí además conviene que primero leas
     dónde estás y por qué te la piden. */
  /* Escape sale de aquí en vez de dejarte en una pantalla que no puedes usar
     (aunque ahora el menú de la izquierda también funciona). */
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') location.href = 'settings.php'; });
})();
/* Ver lo que estás escribiendo: en un campo que no perdona una errata y sin
   segunda casilla donde comprobarla, esconderla del todo solo hace fallar. */
function raVer(){
  var i=document.getElementById('raPass'), b=document.getElementById('raOjo'), oculto=(i.type==='password');
  i.type = oculto ? 'text' : 'password';
  b.querySelector('svg').innerHTML = oculto
    ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22"/>'
    : '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>';
  b.classList.toggle('on', oculto);
  i.focus();
}
</script>
<?php
  erp_foot();
}
