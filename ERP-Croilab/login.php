<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/admin/lib/marca.php';
require_once __DIR__ . '/admin/lib/gcal.php';
require_once __DIR__ . '/admin/lib/logos.php';
if (current_client()) { header('Location: index.php'); exit; }

/* «Entrar con Google»: solo se ofrece si el Dueño ha configurado el cliente OAuth
   (el mismo que ya se usa en el login del equipo y en el calendario). */
$gLogin = gcal_configured();
/* Mensajes de la vuelta de Google (?ge=…), en el lenguaje del cliente. */
$gMsgs = [
  'denied' => 'Ese correo de Google no está asociado a tu cuenta. Escríbenos y lo activamos.',
  'err'    => 'No se ha podido entrar con Google. Inténtalo de nuevo.',
  'cancel' => 'Has cancelado el acceso con Google.',
  'nocfg'  => 'El acceso con Google todavía no está disponible.',
];
$gError = $gMsgs[$_GET['ge'] ?? ''] ?? '';

/* Si quien abre el portal ya ha entrado como EQUIPO (admin), no tiene sentido
   pedirle la contraseña de un cliente: le mostramos un selector para ver el
   portal de cualquier cliente directamente (vista previa con ?cli=), sin clave. */
$isAdmin  = (bool) current_admin();
$adminName = $isAdmin ? (current_admin()['username'] ?? '') : '';

/* Marca de esta pantalla. Antes de entrar no sabemos de quién es el cliente,
   así que la agencia colaboradora puede pasar la suya con  login.php?m=3 . */
$M  = marca_partner((int)($_GET['m'] ?? 0));
$MQ = !empty($_GET['m']) ? '?m=' . (int)$_GET['m'] : '';
$MARK = $M['logo'] !== '' ? '<img src="' . e($M['logo']) . '" alt="' . e($M['name']) . '">' : e($M['initial']);

/* Lista de clientes para el modo equipo. */
$clientesAdmin = [];
if ($isAdmin) {
    try { $clientesAdmin = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll(); } catch (Exception $e) {}
}

$error = '';
/* Aviso de "has cerrado sesión": si no, el cierre de sesión y una recarga
   cualquiera se ven igual. */
$salida = (($_GET['cerrada'] ?? '') === '1');
/* Sesión cerrada sola, sin que la persona haya pulsado "salir": cambiaron sus
   credenciales, se desactivó la cuenta o la sesión caducó. Sin esto llegaba aquí
   sin ninguna explicación y parecía un fallo. */
$sesionCorta = (($_GET['sesion'] ?? '') === '1');
if (!$isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/admin/lib/login_throttle.php';
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    /* Freno persistente por usuario+IP (ver admin/lib/login_throttle.php). */
    $espera = login_throttle_bloqueo('cli:' . $u);
    if ($espera > 0) {
        $error = login_throttle_msg($espera);
    } else {
        $st = db()->prepare('SELECT * FROM clients WHERE username = ?');
        $st->execute([$u]);
        $client = $st->fetch();
        if ($client && (int)($client['activo'] ?? 1) === 1 && password_verify($p, $client['password_hash'])) {
            login_throttle_ok('cli:' . $u);
            session_regenerate_id(true);
            $_SESSION['client_id'] = $client['id'];
            cred_ver_sellar('clients', $client);
            sesion_auditar('entrada', 'portal, cliente ' . $client['username']);
            header('Location: index.php');
            exit;
        }
        /* Cualquier otro caso -no existe, está desactivada, o la contraseña no
           es la correcta- cuenta como un intento fallido y da el mismo aviso,
           para no confirmar qué cuentas existen. */
        login_throttle_fallo('cli:' . $u);
        sesion_auditar('entrada fallida', 'portal, usuario "' . $u . '"');
        $error = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html><html lang="es"><head><script>(function(){try{if(localStorage.getItem('portalTheme')==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Área de cliente · <?= e($M['name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--accent:#ffffff;--ink:#16161a;--ring:#c4c4c7}
:focus{outline:none}
:focus-visible{outline:2px solid var(--ring);outline-offset:2px}
input:focus-visible,select:focus-visible,.ibox select:focus-visible{outline:none}
::selection{background:#e4e5e8;color:#0f1216}
[data-theme=dark] ::selection{background:#3a3d44;color:#f5f7fa}
body{font-family:'Inter',-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;min-height:100vh;background:#f6f7f9;color:#16161a;-webkit-font-smoothing:antialiased}
.wrap{min-height:100vh;display:grid;grid-template-columns:1.05fr 1fr}
@media(max-width:900px){.wrap{grid-template-columns:1fr}.brand{display:none!important}}

/* Panel de marca (izquierda) */
.brand{position:relative;overflow:hidden;background:radial-gradient(120% 120% at 15% 10%,#26262e 0%,#16161a 55%,#0e0e12 100%);color:#fff;padding:56px 60px;display:flex;flex-direction:column;justify-content:space-between}
.brand::before{content:"";position:absolute;width:420px;height:420px;right:-120px;top:-120px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.10),transparent 68%);filter:blur(10px)}
.brand::after{content:"";position:absolute;width:340px;height:340px;left:-100px;bottom:-120px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.05),transparent 70%)}
.brand .top{position:relative;z-index:1;display:flex;align-items:center;gap:12px}
.brand .mark{width:46px;height:46px;border-radius:14px;background:var(--accent);color:#16161a;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:22px;overflow:hidden}
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
.mob-logo .mark{width:40px;height:40px;border-radius:12px;background:var(--ink);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:19px;overflow:hidden}
@media(max-width:900px){.mob-logo{display:flex}}
.field{margin-bottom:16px}
.field label{display:block;font-size:12.5px;color:#5c616b;font-weight:600;margin-bottom:8px}
.ibox{position:relative;display:flex;align-items:center}
.ibox svg.lead{position:absolute;left:14px;width:17px;height:17px;stroke:#9aa0aa;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;pointer-events:none;z-index:1}
.ibox input,.ibox select{width:100%;border:1px solid #e4e6ea;background:#fff;color:#16161a;border-radius:13px;padding:13px 15px 13px 42px;font-size:15px;font-family:inherit;outline:none;transition:border-color .15s,box-shadow .15s}
.ibox select{appearance:none;-webkit-appearance:none;cursor:pointer;padding-right:38px}
.ibox .caret{position:absolute;right:14px;width:16px;height:16px;stroke:#9aa0aa;fill:none;stroke-width:2;pointer-events:none}
.ibox input:focus,.ibox select:focus{border-color:var(--ink);box-shadow:0 0 0 4px rgba(22,22,26,.06)}
.ibox .toggle{position:absolute;right:8px;border:none;background:none;color:#9aa0aa;cursor:pointer;padding:8px;border-radius:9px;display:flex}
.ibox .toggle:hover{background:#f2f3f5;color:#16161a}
.ibox .toggle svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
button.go{width:100%;background:var(--ink);color:#fff;border:none;border-radius:13px;padding:14px;font-size:15px;font-weight:700;cursor:pointer;margin-top:8px;transition:transform .12s,box-shadow .18s;display:flex;align-items:center;justify-content:center;gap:9px;text-decoration:none;font-family:inherit}
button.go:hover{transform:translateY(-1px);box-shadow:0 10px 24px -8px rgba(22,22,26,.4)}
button.go svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.err{background:#fdeeee;color:#c0392b;border:1px solid #f6d4d4;border-radius:12px;padding:12px 15px;font-size:13.5px;margin-bottom:18px;display:flex;align-items:center;gap:9px}
.err svg{width:16px;height:16px;flex:none;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.note{background:#eef2fb;color:#2f4b7a;border:1px solid #d9e2f4;border-radius:12px;padding:12px 15px;font-size:13px;line-height:1.5;margin-bottom:18px;display:flex;align-items:flex-start;gap:9px}
.note svg{width:16px;height:16px;flex:none;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;margin-top:2px}
.divider{display:flex;align-items:center;gap:12px;margin:20px 0;color:#b7bcc4;font-size:12px;font-weight:500}
.divider::before,.divider::after{content:"";flex:1;height:1px;background:#e4e6ea}
.gbtn{width:100%;display:flex;align-items:center;justify-content:center;gap:10px;background:#fff;color:#16161a;border:1px solid #dadce0;border-radius:13px;padding:13px;font-size:14.5px;font-weight:600;cursor:pointer;text-decoration:none;transition:background .15s,box-shadow .15s}
.gbtn:hover{background:#f7f8fa;box-shadow:0 2px 10px rgba(0,0,0,.06)}
.gbtn svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
/* Los logos de marca (Google…) vienen con sus propios colores de relleno y SIN
   trazo. La regla de arriba, pensada para los iconos de línea del modo equipo, les
   metía un borde oscuro de 2px que ensuciaba la «G». Se distinguen por su aria-label. */
.gbtn svg[aria-label]{width:18px;height:18px;stroke:none;fill:none}
.foot{margin-top:26px;color:#a2a7b0;font-size:12.5px;text-align:center}
  .foot a{color:#6b7280;text-decoration:none;font-weight:600}
  .foot a:hover{color:#16161a}
  /* El cierre de sesión de equipo es un formulario POST contra admin/logout.php:
     con un enlace GET no cerraba nada, porque desde esta pantalla la sesión de
     administrador nunca ha existido. :where() no aporta especificidad. */
  .foot button{color:#6b7280;text-decoration:none;font-weight:600;cursor:pointer;border:0;background-color:transparent;font-size:12.5px;padding:0}
  .foot button:hover{color:#16161a}
  form.salir{display:inline}

/* ===== MODO OSCURO (aditivo; el modo claro no se toca) =====
   Persistencia en localStorage['portalTheme'], independiente del ERP. */
.ptheme{position:fixed;top:18px;right:18px;z-index:5;width:40px;height:40px;border-radius:12px;border:1px solid #e4e6ea;background:#fff;color:#16161a;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .15s,border-color .15s}
.ptheme:hover{background:#f2f3f5}
.ptheme svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.ptheme .ic-sun{display:none}
[data-theme=dark] .ptheme{background:#1f1f1f;color:#e6e6e6;border-color:#282828}
[data-theme=dark] .ptheme:hover{background:#242424}
[data-theme=dark] .ptheme .ic-moon{display:none}
[data-theme=dark] .ptheme .ic-sun{display:block}
/* Animación de barrido circular al cambiar de tema (View Transitions API) */
::view-transition-old(root),::view-transition-new(root){animation:none;mix-blend-mode:normal}
::view-transition-old(root){z-index:0}
::view-transition-new(root){z-index:1;animation:.42s ease-in-out both theme-reveal}
@keyframes theme-reveal{from{clip-path:circle(0% at var(--tx,50%) var(--ty,50%));opacity:.7}to{clip-path:circle(150% at var(--tx,50%) var(--ty,50%));opacity:1}}

html[data-theme=dark]{color-scheme:dark}
[data-theme=dark] body{background:#0a0a0a;color:#e6e6e6}
[data-theme=dark] .card .hi{color:#fafafa}
[data-theme=dark] .card .sub{color:#a1a1a1}
[data-theme=dark] .field label{color:#a1a1a1}
[data-theme=dark] .ibox input,[data-theme=dark] .ibox select{background:#1f1f1f;color:#e6e6e6;border-color:#282828}
[data-theme=dark] .ibox svg.lead{stroke:#a1a1a1}
[data-theme=dark] .ibox .caret{stroke:#a1a1a1}
[data-theme=dark] .ibox input:focus,[data-theme=dark] .ibox select:focus{border-color:#3b82f6;box-shadow:0 0 0 4px rgba(59,130,246,.18)}
[data-theme=dark] .ibox .toggle:hover{background:#282828;color:#e6e6e6}
[data-theme=dark] button.go{background:#3b82f6;color:#fff}
[data-theme=dark] .gbtn{background:#1f1f1f;color:#e6e6e6;border-color:#282828}
[data-theme=dark] .gbtn:hover{background:#242424}
[data-theme=dark] .divider{color:#6b7280}
[data-theme=dark] .divider::before,[data-theme=dark] .divider::after{background:#282828}
[data-theme=dark] .foot{color:#a1a1a1}
[data-theme=dark] .foot a{color:#a1a1a1}
[data-theme=dark] .foot a:hover{color:#e6e6e6}
[data-theme=dark] .mob-logo .mark{background:#3b82f6;color:#fff}
</style></head>
<body>
<button class="ptheme" type="button" onclick="portalToggleTheme()" title="Modo claro / oscuro" aria-label="Cambiar tema">
  <svg class="ic-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
  <svg class="ic-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
</button>
<script>window.portalToggleTheme=function(){var h=document.documentElement,d=h.getAttribute('data-theme')==='dark';
var aplicar=function(){if(d){h.removeAttribute('data-theme');}else{h.setAttribute('data-theme','dark');}try{localStorage.setItem('portalTheme',d?'light':'dark');}catch(e){}};
try{var b=document.querySelector('.ptheme');if(b){var r=b.getBoundingClientRect();h.style.setProperty('--tx',(r.left+r.width/2)+'px');h.style.setProperty('--ty',(r.top+r.height/2)+'px');}}catch(e){}
if(document.startViewTransition&&!matchMedia('(prefers-reduced-motion:reduce)').matches){document.startViewTransition(aplicar);}else{aplicar();}};</script>
<div class="wrap">
  <aside class="brand">
    <div class="top"><div class="mark"><?= $MARK ?></div><div class="name"><?= e($M['name']) ?></div></div>
    <div class="mid">
      <h2>El estado de tu proyecto, en un solo sitio.</h2>
      <p>Tus métricas, el trabajo mes a mes, informes, reuniones y facturas de <?= e($M['name']) ?>.</p>
    </div>
    <div class="feats">
      <div class="f"><span class="dot"><svg viewBox="0 0 24 24"><path d="M4 19V5M4 19h16M8 16v-4M12 16V8M16 16v-6"/></svg></span> Tus métricas y resultados</div>
      <div class="f"><span class="dot"><svg viewBox="0 0 24 24"><path d="M9 6h11M9 12h11M9 18h11M4.5 6l1 1 2-2M4.5 12l1 1 2-2"/></svg></span> El trabajo de tu proyecto, mes a mes</div>
      <div class="f"><span class="dot"><svg viewBox="0 0 24 24"><path d="M14 3v5h5"/><path d="M7 3h7l5 5v11a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/></svg></span> Informes, reuniones y facturas</div>
    </div>
  </aside>

  <main class="pane">
  <?php if ($isAdmin): ?>
    <!-- Modo EQUIPO: ya has entrado como admin, no hace falta contraseña -->
    <div class="card">
      <div class="mob-logo"><div class="mark"><?= $MARK ?></div><b><?= e($M['name']) ?></b></div>
      <div class="hi">Hola<?= $adminName!=='' ? ', '.e($adminName) : '' ?> 👋</div>
      <div class="sub">Has entrado como equipo. Elige un cliente para ver su portal — sin contraseña.</div>
      <div class="note">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
        <span>Entras en modo vista previa (solo lectura). Verás su portal tal cual lo ve el cliente.</span>
      </div>
      <?php if ($clientesAdmin): ?>
      <div class="field">
        <label for="cliSel">Cliente</label>
        <div class="ibox">
          <svg class="lead" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <select id="cliSel">
            <?php foreach ($clientesAdmin as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
          </select>
          <svg class="caret" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
        </div>
      </div>
      <button class="go" type="button" onclick="verCliente()">Ver su portal <svg viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg></button>
      <?php else: ?>
      <div class="note" style="background:#fdeeee;color:#c0392b;border-color:#f6d4d4"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg><span>Todavía no hay clientes dados de alta.</span></div>
      <?php endif; ?>
      <div class="divider">o</div>
      <a class="gbtn" href="admin/index.php"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg> Ir al panel de gestión</a>
      <div class="foot"><form class="salir" method="post" action="admin/logout.php"><?= csrf_field() ?><button type="submit">Cerrar sesión de equipo</button></form></div>
    </div>
    <script>
      function verCliente(){ var s=document.getElementById('cliSel'); if(s&&s.value){ location.href='index.php?cli='+encodeURIComponent(s.value); } }
    </script>
  <?php else: ?>
    <!-- Modo CLIENTE: login normal -->
    <form class="card" method="post" action="login.php<?= $MQ ?>">
      <?= csrf_field() ?>
      <div class="mob-logo"><div class="mark"><?= $MARK ?></div><b><?= e($M['name']) ?></b></div>
      <div class="hi">Área de cliente</div>
      <div class="sub">Entra para ver el estado de tu proyecto con <?= e($M['name']) ?>.</div>

      <?php if ($salida): ?><div class="ok-box"><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>Has cerrado la sesión.</div><?php endif; ?>
<?php if ($sesionCorta): ?><div class="ok-box"><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>Tu sesión se ha cerrado porque han cambiado tus datos de acceso. Vuelve a entrar.</div><?php endif; ?>

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
        <a class="gbtn" href="admin/client_google_login.php"><?= svc_logo('google',18) ?> Entrar con Google</a>
      <?php endif; ?>

      <div style="text-align:center;margin-top:14px">
        <a href="#" onclick="var h=document.getElementById('recu');h.style.display=h.style.display==='block'?'none':'block';return false;" style="color:#8a8f99;font-size:12.5px;text-decoration:none">¿No puedes entrar?</a>
      </div>
      <div id="recu" style="display:none;margin-top:10px;font-size:12.5px;color:#8a8f99;text-align:center;line-height:1.5">Ponte en contacto con <b><?= e($M['name']) ?></b> y te restablecen la contraseña.</div>

      <div class="foot"><a href="admin/">Acceso del equipo →</a></div>
    </form>
  <?php endif; ?>
  </main>
</div>
</body></html>
