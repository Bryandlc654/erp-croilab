<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/lib/marca.php';
require_once __DIR__ . '/lib/gcal.php';
require_once __DIR__ . '/lib/logos.php';
if (current_admin()) { header('Location: index.php'); exit; }

/* «Entrar con Google»: solo se ofrece si el Dueño ha configurado el cliente OAuth. */
$gLogin = gcal_configured();
/* Mensajes de la vuelta de Google (?ge=…). */
$gMsgs = [
  'denied' => 'Ese correo de Google no está dado de alta en el equipo. Pídele al Dueño que te invite.',
  'err'    => 'No se ha podido entrar con Google. Inténtalo de nuevo.',
  'cancel' => 'Has cancelado el acceso con Google.',
  'nocfg'  => 'El acceso con Google todavía no está configurado.',
];
$gError = $gMsgs[$_GET['ge'] ?? ''] ?? '';

/* La marca de la casa. Estaba escrita a mano en cinco sitios de este archivo:
   si alguien cambiaba el nombre de la agencia en Ajustes, esta pantalla seguía
   diciendo «Croilab». Aquí no se toca la estética, solo de dónde sale el texto. */
$M = marca_agencia();
/* El cuadrado del logo: imagen si la hay, y si no la inicial. Se deja fuera de
   marca_logo_html() a propósito para no meter el color de marca en una pantalla
   que es deliberadamente blanca, negra y gris. */
$MARK = $M['logo'] !== '' ? '<img src="' . e($M['logo']) . '" alt="' . e($M['name']) . '">' : e($M['initial']);

$error = '';
$salida = (($_GET['cerrada'] ?? '') === '1');   // aviso de "has cerrado sesión"
/* Sesión cerrada sola: cambiaron la contraseña, el rol, o se desactivó la
   cuenta. Sin este aviso la persona llegaba al formulario sin explicación. */
$sesionCorta = (($_GET['sesion'] ?? '') === '1');
$avisado = false;   // se ha pedido restablecer la contraseña

/* «He olvidado mi contraseña». Como el ERP no envía correos, no se puede mandar
   el enlace directamente: se avisa a quien gestiona el equipo, que lo genera en
   la ficha de esa persona y se lo pasa. Ver pwreset_pedir() en lib/pwreset.php. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'olvidada') {
    require_once __DIR__ . '/lib/pwreset.php';
    require_once __DIR__ . '/lib/login_throttle.php';
    /* Límite persistente por IP (no por sesión, que se saltaba tirando la cookie):
       tras varias peticiones seguidas desde la misma IP se frena. Además, a nivel
       de base de datos ya solo se crea un aviso por persona y día. */
    if (login_throttle_bloqueo('pwreq') === 0) {
        login_throttle_fallo('pwreq');
        pwreset_pedir($_POST['quien'] ?? '');
    }
    /* Siempre la misma respuesta, exista esa persona o no. */
    $avisado = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$avisado) {
    require_once __DIR__ . '/lib/login_throttle.php';
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    /* Freno persistente por usuario+IP (ver lib/login_throttle.php). */
    $espera = login_throttle_bloqueo('adm:' . $u);
    if ($espera > 0) {
        $error = login_throttle_msg($espera);
    } else {
        $st = db()->prepare('SELECT * FROM admins WHERE username = ?');
        $st->execute([$u]);
        $admin = $st->fetch();
        /* La cuenta tiene que estar activa además de tener la contraseña
           correcta: antes solo se miraba la contraseña, así que desactivar a
           alguien no impedía volver a entrar. */
        if ($admin && (int)($admin['activo'] ?? 1) === 1 && password_verify($p, $admin['password_hash'])) {
            login_throttle_ok('adm:' . $u);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin['id'];
            /* La versión de credenciales viaja en la sesión. A partir de aquí,
               cualquier cambio de contraseña, de rol o de estado la invalida. */
            cred_ver_sellar('admins', $admin);
            sesion_auditar('entrada', 'panel, ' . $admin['username']);
            header('Location: dashboard.php');
            exit;
        }
        /* Los tres casos -no existe, está desactivada, o la contraseña no
           coincide- cuentan igual y dan el mismo aviso. */
        login_throttle_fallo('adm:' . $u);
        sesion_auditar('entrada fallida', 'panel, usuario "' . $u . '"');
        $error = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel · <?= e($M['name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
/* Blanco, negro y gris, igual que el resto del panel. Esta pantalla era la única
   pintada con un amarillo de marca (#f4ce4b): el cuadrado del logo y los iconos
   salían amarillos aquí y en cuanto entrabas no volvías a ver ese color en
   ninguna otra página. El acento pasa a ser el blanco sobre el panel oscuro. */
:root{--accent:#ffffff;--ink:#16161a}
body{font-family:'Inter',-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;min-height:100vh;background:#f6f7f9;color:#16161a;-webkit-font-smoothing:antialiased}
.wrap{min-height:100vh;display:grid;grid-template-columns:1.05fr 1fr}
@media(max-width:900px){.wrap{grid-template-columns:1fr}.brand{display:none!important}}

/* Panel de marca (izquierda) */
.brand{position:relative;overflow:hidden;background:radial-gradient(120% 120% at 15% 10%,#26262e 0%,#16161a 55%,#0e0e12 100%);color:#fff;padding:56px 60px;display:flex;flex-direction:column;justify-content:space-between}
/* Los dos halos de las esquinas eran manchas amarillas. Ahora son un blanco muy
   tenue: dan la misma profundidad al fondo oscuro sin meter color. */
.brand::before{content:"";position:absolute;width:420px;height:420px;right:-120px;top:-120px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.10),transparent 68%);filter:blur(10px)}
.brand::after{content:"";position:absolute;width:340px;height:340px;left:-100px;bottom:-120px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.05),transparent 70%)}
.brand .top{position:relative;z-index:1;display:flex;align-items:center;gap:12px}
.brand .mark{width:46px;height:46px;border-radius:14px;background:var(--accent);color:#16161a;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:22px;overflow:hidden}
/* Si la agencia tiene logo, la imagen ocupa el cuadrado sin deformarse; si no,
   se ve la inicial y esta regla no llega a aplicarse. */
.mark img{width:100%;height:100%;object-fit:contain;display:block}
.brand .name{font-weight:700;font-size:16px;letter-spacing:.2px}
.brand .mid{position:relative;z-index:1}
.brand .mid h2{font-size:34px;line-height:1.15;font-weight:800;letter-spacing:-.5px;max-width:15ch}
.brand .mid p{margin-top:16px;color:#a7abb4;font-size:15px;line-height:1.6;max-width:34ch}
.brand .feats{position:relative;z-index:1;display:flex;flex-direction:column;gap:14px}
.brand .feats .f{display:flex;align-items:center;gap:12px;color:#cfd2d8;font-size:14px;font-weight:500}
.brand .feats .f .dot{width:30px;height:30px;border-radius:9px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;color:var(--accent);flex:none}
.brand .feats .f svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* Panel del formulario (derecha) */
.pane{display:flex;align-items:center;justify-content:center;padding:40px 24px}
.card{width:100%;max-width:400px}
.card .hi{font-size:26px;font-weight:800;letter-spacing:-.4px}
.card .sub{color:#8a8f99;font-size:14.5px;margin-top:6px;margin-bottom:28px}
.mob-logo{display:none;align-items:center;gap:11px;margin-bottom:24px}
/* El logo de móvil va sobre fondo claro, así que aquí el cuadrado es el negro y
   la letra la blanca — al revés que en el panel oscuro de la izquierda. Si se
   dejara var(--accent) se quedaría un cuadrado blanco sobre blanco, invisible. */
.mob-logo .mark{width:40px;height:40px;border-radius:12px;background:var(--ink);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:19px;overflow:hidden}
@media(max-width:900px){.mob-logo{display:flex}}
.field{margin-bottom:16px}
.field label{display:block;font-size:12.5px;color:#5c616b;font-weight:600;margin-bottom:8px}
.ibox{position:relative;display:flex;align-items:center}
.ibox svg.lead{position:absolute;left:14px;width:17px;height:17px;stroke:#9aa0aa;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;pointer-events:none}
.ibox input{width:100%;border:1px solid #e4e6ea;background:#fff;color:#16161a;border-radius:13px;padding:13px 15px 13px 42px;font-size:15px;font-family:inherit;outline:none;transition:border-color .15s,box-shadow .15s}
.ibox input:focus{border-color:var(--ink);box-shadow:0 0 0 4px rgba(22,22,26,.06)}
.ibox .toggle{position:absolute;right:8px;border:none;background:none;color:#9aa0aa;cursor:pointer;padding:8px;border-radius:9px;display:flex}
.ibox .toggle:hover{background:#f2f3f5;color:#16161a}
.ibox .toggle svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
button.go{width:100%;background:var(--ink);color:#fff;border:none;border-radius:13px;padding:14px;font-size:15px;font-weight:700;cursor:pointer;margin-top:8px;transition:transform .12s,box-shadow .18s;display:flex;align-items:center;justify-content:center;gap:9px}
button.go:hover{transform:translateY(-1px);box-shadow:0 10px 24px -8px rgba(22,22,26,.4)}
button.go svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.err{background:#fdeeee;color:#c0392b;border:1px solid #f6d4d4;border-radius:12px;padding:12px 15px;font-size:13.5px;margin-bottom:18px;display:flex;align-items:center;gap:9px}
.err svg{width:16px;height:16px;flex:none;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
/* Confirmación de «he olvidado mi contraseña»: verde, mismo molde que .err. */
.ok-box{background:#eef8f1;color:#2f6b4a;border:1px solid #cde8d5;border-radius:12px;padding:12px 15px;font-size:13px;line-height:1.5;margin-bottom:18px;display:flex;align-items:flex-start;gap:9px}
.ok-box svg{width:16px;height:16px;flex:none;stroke:currentColor;fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;margin-top:2px}
.divider{display:flex;align-items:center;gap:12px;margin:20px 0;color:#b7bcc4;font-size:12px;font-weight:500}
.divider::before,.divider::after{content:"";flex:1;height:1px;background:#e4e6ea}
.gbtn{width:100%;display:flex;align-items:center;justify-content:center;gap:10px;background:#fff;color:#16161a;border:1px solid #dadce0;border-radius:13px;padding:13px;font-size:14.5px;font-weight:600;cursor:pointer;text-decoration:none;transition:background .15s,box-shadow .15s}
.gbtn:hover{background:#f7f8fa;box-shadow:0 2px 10px rgba(0,0,0,.06)}
.gbtn svg{display:block}
.foot{margin-top:26px;color:#a2a7b0;font-size:12.5px;text-align:center}
</style></head>
<body>
<div class="wrap">
  <aside class="brand">
    <div class="top"><div class="mark"><?= $MARK ?></div><div class="name"><?= e($M['name']) ?></div></div>
    <div class="mid">
      <h2>Tu agencia, ordenada en un solo sitio.</h2>
      <p>Clientes, facturación, tareas y equipo. Todo en el panel de gestión de <?= e($M['name']) ?>.</p>
    </div>
    <div class="feats">
      <div class="f"><span class="dot"><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></span> Gestión de clientes y proyectos</div>
      <div class="f"><span class="dot"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span> Facturación y contabilidad</div>
      <div class="f"><span class="dot"><svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span> Tareas y equipo</div>
    </div>
  </aside>

  <main class="pane">
    <form class="card" method="post" style="display:<?= $avisado?'none':'block' ?>">
      <?= csrf_field() ?>
      <div class="mob-logo"><div class="mark"><?= $MARK ?></div><b><?= e($M['name']) ?></b></div>
      <div class="hi">Bienvenido de nuevo</div>
      <div class="sub">Entra al panel de gestión con tu cuenta de equipo.</div>

      <?php if ($salida): ?><div class="ok-box"><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>Has cerrado la sesión.</div><?php endif; ?>
<?php if ($sesionCorta): ?><div class="ok-box"><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>Tu sesión se ha cerrado porque han cambiado tu contraseña, tu rol o tu estado de la cuenta. Vuelve a entrar.</div><?php endif; ?>

      <?php if ($error || $gError): ?><div class="err"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg><?= e($error ?: $gError) ?></div><?php endif; ?>

      <div class="field">
        <label for="u">Usuario</label>
        <div class="ibox">
          <svg class="lead" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <input id="u" type="text" name="username" placeholder="Tu usuario" autocomplete="username" required autofocus>
        </div>
      </div>

      <div class="field">
        <label for="p">Contraseña</label>
        <div class="ibox">
          <svg class="lead" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <input id="p" type="password" name="password" placeholder="Tu contraseña" autocomplete="current-password" required>
          <button type="button" class="toggle" onclick="var i=document.getElementById('p');var on=i.type==='password';i.type=on?'text':'password';this.querySelector('svg').innerHTML=on?'<path d=\'M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22\'/>':'<path d=\'M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z\'/><circle cx=\'12\' cy=\'12\' r=\'3\'/>';" title="Mostrar/ocultar">
            <svg viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>

      <button class="go" type="submit">Entrar <svg viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg></button>
      <?php if ($gLogin): ?>
        <div class="divider">o</div>
        <a class="gbtn" href="google_login.php"><?= svc_logo('google',18) ?> Entrar con Google</a>
      <?php endif; ?>
      <?php /* Se pide desde aquí y le llega el aviso a quien puede resolverlo. No se
               manda un correo porque el ERP no envía correos: prometer un email que
               no llega es peor que decir la verdad. */ ?>
      <div style="text-align:center;margin-top:14px">
        <a href="#" onclick="olvidada(true);return false;" style="color:#8a8f99;font-size:12.5px;text-decoration:none">¿Has olvidado tu contraseña?</a>
      </div>
      <div class="foot">Panel privado · <?= e($M['name']) ?> © <?= date('Y') ?></div>
    </form>

    <?php /* El formulario de recuperación es OTRO formulario, no un desplegable dentro
             del de entrar: si fuera el mismo, el navegador exigiría el usuario y la
             contraseña (son `required`) antes de dejarte pedir nada. */ ?>
    <form class="card" id="olvCard" method="post" style="display:<?= $avisado?'block':'none' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="olvidada">
      <div class="mob-logo"><div class="mark"><?= $MARK ?></div><b><?= e($M['name']) ?></b></div>

      <?php if ($avisado): ?>
        <div class="hi">Aviso enviado</div>
        <div class="sub">Si esa cuenta existe, quien lleva el panel ya tiene el aviso. Te pasará un enlace para que elijas una contraseña nueva.</div>
        <div class="ok-box">
          <svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>
          <span>El enlace te llegará por donde habléis normalmente (chat o WhatsApp). Este panel no envía correos.</span>
        </div>
        <a class="go" href="login.php" style="text-decoration:none">Volver a entrar</a>
      <?php else: ?>
        <div class="hi">¿Has olvidado tu contraseña?</div>
        <div class="sub">Dinos quién eres y avisamos a quien lleva el panel para que te la restablezca.</div>
        <div class="field">
          <label for="q">Tu usuario o tu correo</label>
          <div class="ibox">
            <svg class="lead" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <input id="q" type="text" name="quien" placeholder="Lo que uses para entrar" autocomplete="username" required>
          </div>
        </div>
        <button class="go" type="submit">Pedir que me la restablezcan <svg viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg></button>
        <div style="text-align:center;margin-top:14px">
          <a href="#" onclick="olvidada(false);return false;" style="color:#8a8f99;font-size:12.5px;text-decoration:none">← Volver a entrar</a>
        </div>
      <?php endif; ?>
      <div class="foot">Panel privado · <?= e($M['name']) ?> © <?= date('Y') ?></div>
    </form>

    <script>
    /* Cambia entre entrar y recuperar sin recargar. El campo se enfoca al abrir
       porque ahí sí es lo único que hay que escribir. */
    function olvidada(mostrar){
      document.querySelectorAll('.pane > form.card')[0].style.display = mostrar ? 'none' : 'block';
      var o = document.getElementById('olvCard');
      o.style.display = mostrar ? 'block' : 'none';
      if (mostrar) { var q = document.getElementById('q'); if (q) q.focus(); }
    }
    </script>
  </main>
</div>
</body></html>
