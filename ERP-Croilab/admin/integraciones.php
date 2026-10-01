<?php
/* Integraciones del ERP: las apps de terceros —n8n/API, Google Calendar, MCP
   (Claude)—, cada una con su logo y su configuración.

   Antes esto era un hub aparte, fuera de Ajustes, y era justo el problema: es
   configuración y se buscaba en Ajustes. Ahora es un apartado más del marco
   común, con el mismo menú lateral que Agencia, Servicios o Reglas. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/gcal.php';
require_once __DIR__ . '/lib/google_metrics.php';
require_once __DIR__ . '/lib/ajustes_nav.php';

$me = current_admin();
function inget($k){ static $c=null; if($c===null){ $c=[]; try{ foreach(db()->query('SELECT clave,valor FROM settings') as $r) $c[$r['clave']]=$r['valor']; }catch(Exception $e){} } return $c[$k] ?? ''; }
function input_set($k,$v){ db()->prepare('INSERT INTO settings (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')->execute([$k,$v]); }

/* ---- Logos reales (helper compartido) ---- */
require_once __DIR__.'/lib/logos.php';
function intlogo($k,$s=30){ return svc_logo($k,$s); }

$mcpEnabled = inget('mcp_enabled')==='1'; $mcpToken=inget('mcp_token');
$gcConfigured = gcal_configured(); $gcConnected = gcal_connected((int)$me['id']);
/* Google · Métricas (Search Console + Analytics): OAuth propio, distinto del de Calendar. */
$gmCfg = gm_oauth_cfg()!==null;      // tiene id+secreto puestos
$gmConn = gm_configurada();          // además ya autorizado (hay refresh token)

/* URL base para los endpoints (mcp.php / gcal_callback.php) — estable en /admin/. */
function admin_base_url(){ $https=(!empty($_SERVER['HTTPS'])&&strtolower($_SERVER['HTTPS'])!=='off')||(($_SERVER['SERVER_PORT']??'')=='443'); $sc=$https?'https':'http'; $host=$_SERVER['HTTP_HOST']??'localhost'; $dir=str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/admin/integraciones.php')); if($dir==='/'||$dir==='.')$dir=''; return $sc.'://'.$host.$dir; }
$mcpUrl = $mcpToken ? admin_base_url().'/mcp.php/'.$mcpToken : '';

/* ---- Guardado ---- */
$saved='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $sec=$_POST['section']??'';
  if ($sec==='api' && is_owner()) { input_set('api_token', bin2hex(random_bytes(20))); $saved='api'; }
  elseif ($sec==='gcal' && is_owner()) { input_set('gcal_client_id', trim($_POST['gcal_client_id']??'')); input_set('gcal_client_secret', trim($_POST['gcal_client_secret']??'')); $saved='gcal'; }
  elseif ($sec==='mcp' && is_owner()) { if(isset($_POST['regen'])||inget('mcp_token')==='') input_set('mcp_token', bin2hex(random_bytes(20))); input_set('mcp_enabled', isset($_POST['mcp_enabled'])?'1':'0'); $saved='mcp'; }
  elseif ($sec==='gmet' && is_owner()) {
    if (isset($_POST['disconnect'])) {
      /* Desconectar: se borra el permiso (refresh token). Las credenciales del proyecto
         se dejan puestas para poder volver a conectar con un clic. */
      db()->prepare('DELETE FROM settings WHERE clave=?')->execute(['google_oauth_refresh_token']);
      $saved='gmet_off';
    } elseif (!empty($_FILES['oauth_json']) && ($_FILES['oauth_json']['error']??1)===0 && ($_FILES['oauth_json']['tmp_name']??'')!=='') {
      /* Lo más fácil: el JSON que descarga Google lleva dentro id + secreto. */
      $j=json_decode((string)@file_get_contents($_FILES['oauth_json']['tmp_name']),true);
      $w = $j['web'] ?? $j['installed'] ?? null;
      if(is_array($w) && !empty($w['client_id']) && !empty($w['client_secret'])){
        input_set('google_oauth_client_id',$w['client_id']); input_set('google_oauth_client_secret',$w['client_secret']); $saved='gmet';
      } else { $saved='gmet_bad'; }
    } else {
      input_set('google_oauth_client_id', trim($_POST['client_id']??''));
      $sec2=trim($_POST['client_secret']??''); if($sec2!=='') input_set('google_oauth_client_secret',$sec2);   // solo cambia si escriben uno nuevo
      $saved='gmet';
    }
  }
  header('Location: integraciones.php?i='.($sec?:'').'&ok='.$saved); exit;
}

$i = $_GET['i'] ?? '';
$ok = $_GET['ok'] ?? '';
/* Dentro de una integración concreta el encabezado del apartado sobra: cada una
   tiene su propia cabecera con logo (.ig-hero) justo debajo. */
if ($i==='') aj_head('integr', 'Integraciones', 'Conecta el ERP con tus herramientas. Cada una se configura por separado.');
else         aj_head('integr');
?>
<style>
.ig-grid{display:grid;grid-template-columns:1fr;gap:16px}
.ig-card{display:flex;align-items:center;gap:18px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px 22px;text-decoration:none;color:inherit;transition:border-color .12s,box-shadow .12s}
.ig-card:hover{border-color:#d8dade;box-shadow:0 10px 26px -18px rgba(0,0,0,.35)}
.ig-logo{width:52px;height:52px;border-radius:14px;border:1px solid var(--line);background:#fff;display:flex;align-items:center;justify-content:center;flex:none}
.ig-b{flex:1;min-width:0}
.ig-t{font-size:16px;font-weight:600;color:var(--ink-strong);display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.ig-d{font-size:13px;color:var(--muted);margin-top:5px;line-height:1.55}
.ig-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;background:#f0f1f3;color:var(--label);text-transform:uppercase;letter-spacing:.3px}
.ig-badge.on{background:#e7f7ee;color:var(--ok)}
.ig-arrow{color:var(--label);flex:none}
.ig-back{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:12.5px;font-weight:600;margin-bottom:16px;text-decoration:none}
.ig-back:hover{color:var(--ink)}
/* cabecera de cada app con su logo en recuadro */
.ig-hero{display:flex;align-items:center;gap:18px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px 26px;margin-bottom:18px}
.ig-hero .ig-logo{width:60px;height:60px;border-radius:16px}
.ig-hero h2{margin:0 0 4px;font-size:20px;font-weight:600}
.ig-hero .hs{font-size:13px;color:var(--muted);line-height:1.55}
/* .set-card, .set-grid, .set-f y .ok-note los da el marco (lib/ajustes_nav.php).
   Aquí estaban repetidos con valores ligeramente distintos, que es como se acaba
   con dos pantallas de ajustes que no se parecen entre sí. */
.set-grid{margin-top:14px}
.tok{margin-top:12px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;background:var(--soft);border:1px solid var(--line);border-radius:9px;padding:11px 13px;word-break:break-all;cursor:pointer}
.nf-chk{display:flex;align-items:center;gap:10px;margin:8px 0;cursor:pointer;font-size:13.5px;color:var(--ink)}
.nf-chk input{width:17px;height:17px;flex:none;accent-color:var(--accent);cursor:pointer}
/* Modo oscuro: tarjetas de app, insignias y cabecera. El cuadro del logo se deja
   en blanco (aloja logos de marca externos pensados para fondo claro). */
[data-theme=dark] .ig-card{background-color:var(--card)}
[data-theme=dark] .ig-card:hover{border-color:var(--line-strong)}
[data-theme=dark] .ig-badge{background-color:var(--soft);color:var(--muted)}
[data-theme=dark] .ig-badge.on{background-color:var(--ok-bg);color:var(--ok)}
[data-theme=dark] .ig-arrow{color:var(--muted)}
[data-theme=dark] .ig-hero{background-color:var(--card)}
</style>

<?php if($i===''): /* ---- HUB ---- */ ?>
  <div class="ig-grid">
    <a class="ig-card" href="integraciones.php?i=api">
      <div class="ig-logo"><?= intlogo('n8n',34) ?></div>
      <div class="ig-b"><div class="ig-t">n8n / API <span class="ig-badge <?= inget('api_token')?'on':'' ?>"><?= inget('api_token')?'Activa':'Sin token' ?></span></div><div class="ig-d">Vuelca métricas, tareas o informes a n8n u otras herramientas con un token.</div></div>
      <span class="ig-arrow"><?= ic('chevron',16) ?></span>
    </a>
    <a class="ig-card" href="integraciones.php?i=gcal">
      <div class="ig-logo"><?= intlogo('gcal',32) ?></div>
      <div class="ig-b"><div class="ig-t">Google Calendar <span class="ig-badge <?= $gcConnected?'on':($gcConfigured?'on':'') ?>"><?= $gcConnected?'Conectado':($gcConfigured?'Configurado':'Sin configurar') ?></span></div><div class="ig-d">Ver tus reuniones de Google y crear/editar eventos desde el calendario del ERP.</div></div>
      <span class="ig-arrow"><?= ic('chevron',16) ?></span>
    </a>
    <a class="ig-card" href="integraciones.php?i=gmet">
      <div class="ig-logo"><?= intlogo('google',30) ?></div>
      <div class="ig-b"><div class="ig-t">Google · Métricas <span class="ig-badge <?= $gmConn?'on':'' ?>"><?= $gmConn?'Conectado':($gmCfg?'Falta autorizar':'Sin conectar') ?></span></div><div class="ig-d">Lee de Google las visitas, apariciones (Search Console) y conversiones (Analytics) para el portal de cada cliente.</div></div>
      <span class="ig-arrow"><?= ic('chevron',16) ?></span>
    </a>
    <a class="ig-card" href="integraciones.php?i=mcp">
      <div class="ig-logo"><?= intlogo('claude',30) ?></div>
      <div class="ig-b"><div class="ig-t">MCP · Claude <span class="ig-badge <?= $mcpEnabled?'on':'' ?>"><?= $mcpEnabled?'Activo':'Desactivado' ?></span></div><div class="ig-d">Conecta Claude para que gestione tus tareas: crear, etiquetar, cambiar estado y comentar.</div></div>
      <span class="ig-arrow"><?= ic('chevron',16) ?></span>
    </a>
  </div>

<?php elseif($i==='api'): /* ---- n8n / API ---- */ ?>
  <a class="ig-back" href="integraciones.php"><?= ic('back',14) ?> Integraciones</a>
  <div class="ig-hero"><div class="ig-logo"><?= intlogo('n8n',38) ?></div><div><h2>n8n / API</h2><div class="hs">Automatiza el ERP con n8n u otras herramientas.</div></div></div>
  <?php if($ok==='api'): ?><div class="ok-note">Nuevo token generado.</div><?php endif; ?>
  <div class="set-card">
    <h3>Token de API</h3><div class="h-sub">Manda la cabecera <code>X-API-Token</code> al llamar a <code>/api.php</code> para volcar métricas, tareas o informes.</div>
    <div class="tok" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.textContent.trim());this.style.borderColor='#12a150'" title="Clic para copiar"><?= e(inget('api_token') ?: '— sin token —') ?></div>
    <?php if(is_owner()): ?>
    <form method="post" style="margin-top:12px" onsubmit="return erpSubmitAsk(this,'¿Regenerar el token? El anterior dejará de funcionar.')"><input type="hidden" name="section" value="api"><button class="btn ghost sm" type="submit"><?= ic('settings',14) ?> Regenerar token</button></form>
    <?php else: ?><div class="h-sub" style="margin-top:12px">Solo el Dueño puede regenerar el token.</div><?php endif; ?>
  </div>
  <?php if(is_owner()): ?>
  <div class="set-card"><h3>Datos avanzados</h3><div class="h-sub">Edición directa de las tablas, tipo phpMyAdmin. Lo que cambies se guarda tal cual, sin validar.</div><a class="btn ghost sm" style="margin-top:12px;display:inline-flex" href="data.php"><?= ic('grid',15) ?> Abrir datos avanzados</a></div>
  <?php endif; ?>

<?php elseif($i==='gcal'): /* ---- Google Calendar ---- */ ?>
  <a class="ig-back" href="integraciones.php"><?= ic('back',14) ?> Integraciones</a>
  <div class="ig-hero"><div class="ig-logo"><?= intlogo('gcal',36) ?></div><div><h2>Google Calendar</h2><div class="hs"><?= $gcConnected?('Conectado: '.e(gcal_email((int)$me['id']))):'Ver tus reuniones y crear eventos desde el ERP.' ?></div></div></div>
  <?php if($ok==='gcal'): ?><div class="ok-note">Credenciales de Google guardadas.</div><?php endif; ?>
  <?php if(is_owner()): ?>
  <form method="post" class="set-card"><input type="hidden" name="section" value="gcal">
    <h3>Credenciales del proyecto</h3><div class="h-sub">Cada usuario conecta su propia cuenta desde el <a href="calendar.php">Calendario</a>; aquí solo van las credenciales del proyecto (una vez).</div>
    <div class="set-grid one">
      <div class="set-f"><label><?= ic('vault',15) ?> Client ID</label><input type="text" name="gcal_client_id" value="<?= e(inget('gcal_client_id')) ?>" placeholder="1234-abcd.apps.googleusercontent.com" autocomplete="off"></div>
      <div class="set-f"><label><?= ic('vault',15) ?> Client Secret</label><input type="text" name="gcal_client_secret" value="<?= e(inget('gcal_client_secret')) ?>" placeholder="GOCSPX-…" autocomplete="off"></div>
      <div class="set-f"><label><?= ic('link',15) ?> URI de redirección autorizada (cópiala en Google Cloud, tal cual)</label><input type="text" readonly value="<?= e(gcal_redirect_uri()) ?>" onclick="this.select()" style="background:var(--soft)"></div>
    </div>
    <div style="margin-top:14px"><button class="btn" type="submit"><?= ic('check',15) ?> Guardar credenciales</button></div>
  </form>
  <div class="set-card"><h3>Cómo obtener las credenciales (una vez)</h3><div class="h-sub" style="line-height:1.85">
    1. <code>console.cloud.google.com</code> → tu proyecto (o el existente).<br>
    2. «APIs y servicios → Biblioteca» → <b>Google Calendar API</b> → Habilitar.<br>
    3. «Pantalla de consentimiento OAuth» → Externo → añade a tu equipo como <b>usuarios de prueba</b>.<br>
    4. «Credenciales → Crear → ID de cliente de OAuth → Aplicación web» → pega la URI de arriba.<br>
    5. Copia Client ID y Secret aquí y guarda. Luego cada uno pulsa «Conectar» en el Calendario.
  </div></div>
  <?php else: ?><div class="set-card"><div class="h-sub">Solo el Dueño configura las credenciales. Podrás conectar tu cuenta desde el <a href="calendar.php">Calendario</a>.</div></div><?php endif; ?>

<?php elseif($i==='mcp'): /* ---- MCP / Claude ---- */ ?>
  <a class="ig-back" href="integraciones.php"><?= ic('back',14) ?> Integraciones</a>
  <div class="ig-hero"><div class="ig-logo"><?= intlogo('claude',34) ?></div><div><h2>MCP · Claude</h2><div class="hs">Que Claude gestione tus tareas por ti, de forma segura.</div></div></div>
  <?php if($ok==='mcp'): ?><div class="ok-note">Ajustes del MCP guardados.</div><?php endif; ?>
  <?php if(is_owner()): ?>
  <form method="post" class="set-card"><input type="hidden" name="section" value="mcp">
    <h3>Conectar Claude al ERP</h3><div class="h-sub">Claude podrá listar, crear, cambiar estado/prioridad/fecha, etiquetar y comentar tareas, y consultar clientes y equipo. <b>Solo tareas</b> — no toca facturación, credenciales ni ajustes, y no borra nada.</div>
    <label class="nf-chk" style="margin-top:16px"><input type="checkbox" name="mcp_enabled" <?= $mcpEnabled?'checked':'' ?>><span>Activar el servidor MCP</span></label>
    <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap"><button class="btn" type="submit"><?= ic('check',15) ?> Guardar</button><button class="btn ghost sm" type="button" onclick="mcpRegen(this)"><?= ic('settings',14) ?> Regenerar token</button></div>
  </form>
  <script>
  /* Regenerar sin confirm() nativo: usa el diálogo del kit y añade el campo
     `regen` al enviar, para no perderlo en el requestSubmit programático. */
  function mcpRegen(b){var f=b.form;if(!f)return;erpConfirm('¿Regenerar el token? El conector anterior dejará de funcionar.',{titulo:'Regenerar token',ok:'Regenerar',danger:true}).then(function(ok){if(!ok)return;var h=document.createElement('input');h.type='hidden';h.name='regen';h.value='1';f.appendChild(h);if(typeof f.requestSubmit==='function')f.requestSubmit();else f.submit();});}
  </script>
  <?php if($mcpEnabled && $mcpUrl): ?>
  <div class="set-card"><h3>Conéctalo en Claude</h3>
    <div class="h-sub">En Claude: <b>Ajustes → Conectores → Añadir conector personalizado</b>. Nombre: «Croilab ERP». URL (lleva el token dentro, clic para copiar):</div>
    <div class="tok" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.textContent.trim());this.style.borderColor='#12a150'" title="Clic para copiar"><?= e($mcpUrl) ?></div>
    <div class="h-sub" style="margin-top:12px;color:#c0343a">⚠️ Esa URL con el token es como una contraseña. Si se filtra, pulsa «Regenerar token».</div>
  </div>
  <?php endif; ?>
  <?php else: ?><div class="set-card"><div class="h-sub">Solo el Dueño puede activar el MCP.</div></div><?php endif; ?>

<?php elseif($i==='gmet'): /* ---- Google · Métricas (Search Console + Analytics) ---- */ ?>
  <a class="ig-back" href="integraciones.php"><?= ic('back',14) ?> Integraciones</a>
  <div class="ig-hero"><div class="ig-logo"><?= intlogo('google',36) ?></div><div><h2>Google · Métricas</h2><div class="hs"><?= $gmConn?'Conectado. El ERP ya lee los datos de Google de tus clientes.':'Ver visitas, apariciones y conversiones de cada cliente en su portal.' ?></div></div></div>
  <?php if($ok==='gmet'): ?><div class="ok-note">Credenciales guardadas.</div>
  <?php elseif($ok==='gmet_off'): ?><div class="ok-note">Se ha desconectado Google.</div>
  <?php elseif($ok==='gmet_bad'): ?><div class="err-note">Ese archivo no parece el de Google (no encuentro el id y el secreto).</div>
  <?php elseif($ok==='conn'): ?><div class="ok-note">¡Conectado con Google! Ya puedes traer los números.</div>
  <?php elseif($ok==='connerr'): ?><div class="err-note">No se ha podido conectar con Google. Inténtalo de nuevo.</div><?php endif; ?>

  <?php if($gmConn): ?>
    <div class="set-card">
      <h3>Estado</h3>
      <div class="ok-note" style="margin:0 0 14px;display:inline-flex;align-items:center;gap:8px"><?= ic('check',16) ?> Conectado con Google</div>
      <div class="h-sub" style="margin:0 0 14px">Para traer los números de tus clientes, ve a <a href="metricas.php">Métricas de Google</a> y pulsa «Actualizar ahora» (luego se hace solo cada mes).</div>
      <div class="set-grid one">
        <div class="set-f"><label><?= ic('link',15) ?> Dirección de retorno (tenla registrada en Google Cloud, tal cual)</label><input type="text" readonly value="<?= e(gm_redirect_uri()) ?>" onclick="this.select()" style="background:var(--soft)"></div>
      </div>
      <?php if(is_owner()): ?>
      <form method="post" style="margin-top:14px" onsubmit="return erpSubmitAsk(this,'¿Desconectar Google? El ERP dejará de leer los datos de tus clientes hasta que vuelvas a conectar.',{ok:'Desconectar'})"><input type="hidden" name="section" value="gmet"><input type="hidden" name="disconnect" value="1"><button class="btn ghost sm" type="submit"><?= ic('logout',14) ?> Desconectar</button></form>
      <?php endif; ?>
    </div>
  <?php elseif(is_owner()): ?>
    <div class="set-card">
      <h3>Conectar con Google</h3>
      <div class="h-sub">Se hace <b>una sola vez</b>. Sube el archivo que te da Google (o pega los dos códigos), pulsa «Conectar con Google» y acepta los permisos.</div>
      <div class="set-grid one">
        <div class="set-f"><label><?= ic('link',15) ?> Dirección de retorno (cópiala en Google Cloud, tal cual)</label><input type="text" readonly value="<?= e(gm_redirect_uri()) ?>" onclick="this.select()" style="background:var(--soft)"></div>
      </div>
      <form method="post" enctype="multipart/form-data" style="margin-top:14px"><input type="hidden" name="section" value="gmet">
        <div class="set-f"><label><?= ic('download',15) ?> Lo más fácil: sube el archivo <b>.json</b> que te descargaste de Google</label>
          <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:6px"><input type="file" name="oauth_json" accept=".json,application/json" style="font-size:13px"><button class="btn ghost sm" type="submit"><?= ic('check',14) ?> Subir y guardar</button></div>
        </div>
      </form>
      <details class="set-f" style="margin-top:6px"><summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--muted)">…o pega los dos códigos a mano</summary>
        <form method="post" style="margin-top:12px"><input type="hidden" name="section" value="gmet">
          <div class="set-grid">
            <div class="set-f c6"><label><?= ic('vault',15) ?> ID de cliente</label><input type="text" name="client_id" value="<?= e(gm_setting('google_oauth_client_id','')) ?>" placeholder="…apps.googleusercontent.com" autocomplete="off"></div>
            <div class="set-f c6"><label><?= ic('vault',15) ?> Secreto de cliente</label><input type="text" name="client_secret" value="" placeholder="<?= gm_setting('google_oauth_client_secret','')!==''?'(ya guardado)':'GOCSPX-…' ?>" autocomplete="off"></div>
          </div>
          <div style="margin-top:12px"><button class="btn ghost sm" type="submit"><?= ic('check',14) ?> Guardar códigos</button></div>
        </form>
      </details>
      <div style="margin-top:16px">
        <?php if($gmCfg): ?>
          <a class="btn" href="<?= e(gm_oauth_url()) ?>"><?= ic('link',16) ?> Conectar con Google</a>
          <div class="h-sub" style="margin:8px 0 0">Se abrirá Google; acepta los permisos y volverás aquí ya conectado.</div>
        <?php else: ?>
          <div class="h-sub" style="margin:0">Sube el archivo o pega los códigos y guarda; entonces aparecerá el botón «Conectar con Google».</div>
        <?php endif; ?>
      </div>
    </div>
    <div class="set-card"><h3>Cómo obtener las credenciales (una vez)</h3><div class="h-sub" style="line-height:1.85">
      1. <code>console.cloud.google.com</code> → tu proyecto.<br>
      2. «APIs y servicios → Biblioteca» → habilita <b>Search Console API</b> y <b>Google Analytics Data API</b>.<br>
      3. «Pantalla de consentimiento OAuth» → Externo → añade tu correo en <b>usuarios de prueba</b>.<br>
      4. «Credenciales → Crear → ID de cliente de OAuth → Aplicación web» → pega la dirección de retorno de arriba.<br>
      5. Descarga el JSON y súbelo aquí (o pega Client ID y Secret) y pulsa «Conectar con Google».
    </div></div>
  <?php else: ?>
    <div class="set-card"><div class="h-sub">Solo el Dueño puede conectar Google.</div></div>
  <?php endif; ?>

<?php endif; ?>
<?php aj_foot(); ?>
