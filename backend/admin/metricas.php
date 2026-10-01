<?php
/* Métricas de Google — pantalla de Ajustes (para alguien NO técnico).
   El ERP saca de Google las visitas/apariciones (Search Console) y las conversiones
   (Analytics) de cada cliente y las pone en su portal, solo, cada mes.
   Motor en lib/google_metrics.php. Las conversiones de cada cliente se eligen en su
   propia pantalla (conversiones.php), a la que se llega desde aquí y desde su ficha. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/lib/google_metrics.php';
gm_ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/ajustes_nav.php';
require_once __DIR__ . '/lib/logos.php';   // svc_logo() para el logo de Google en la cabecera

function gm_set($k,$v){ db()->prepare('INSERT INTO settings (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')->execute([$k,$v]); }

/* La conexión con Google (OAuth) se gestiona en Integraciones → Google · Métricas, y su
   vuelta la atiende gmet_callback.php. Aquí solo se traen los números y se configura cada cliente. */

$flash=''; $flashType='ok';
if ($_SERVER['REQUEST_METHOD']==='POST' && can_edit()) {
  $a=$_POST['action']??'';
  if ($a==='save_adv') {
    gm_set('ga4_event_ll', trim($_POST['ev_ll']??'phone_call'));
    gm_set('ga4_event_wa', trim($_POST['ev_wa']??'whatsapp_click'));
    gm_set('ga4_event_fo', trim($_POST['ev_fo']??'generate_lead'));
    $flash='Opciones avanzadas guardadas.';
  } elseif ($a==='sync_one') {
    $cid=(int)($_POST['id']??0); $m=''; $months=[date('Y-m'), date('Y-m', strtotime('first day of last month'))];
    gm_sync_client($cid,$months,$m); $flash='Actualizado.';
  } elseif ($a==='sync_all') {
    $r=gm_sync_all(); $flash='Listo: '.$r['ok'].' cliente(s) actualizados'.($r['fail']?(', '.$r['fail'].' con aviso'):'').'.';
  }
  header('Location: metricas.php?ok='.rawurlencode($flash).'&t='.$flashType); exit;
}
$flash = $_GET['ok'] ?? ''; $flashType = ($_GET['t']??'ok')==='err'?'err':'ok';

$cfgOk = gm_configurada();
$oauthReady = (function_exists('gm_oauth_cfg') && gm_oauth_cfg()!==null);
$evLl=gm_setting('ga4_event_ll','phone_call'); $evWa=gm_setting('ga4_event_wa','whatsapp_click'); $evFo=gm_setting('ga4_event_fo','generate_lead');
$clientes = db()->query('SELECT id,name,gsc_site_url,ga4_property_id,ga4_ev_ll,ga4_ev_wa,ga4_ev_fo,met_sync_at,conversiones FROM clients ORDER BY name')->fetchAll();
$conWeb=0; $ultSync=null;
foreach($clientes as $c){ if(trim((string)($c['gsc_site_url']??''))!=='') $conWeb++; if($c['met_sync_at'] && (!$ultSync || $c['met_sync_at']>$ultSync)) $ultSync=$c['met_sync_at']; }

aj_head('metricas', 'Métricas de Google', '');
?>
<style>
/* Cabecera propia con el logo de Google, como en Integraciones. Ocultamos la
   cabecera estándar de Ajustes para no repetir el título. */
.set-wrap .aj-h{display:none}
/* Esta pantalla ocupa TODO el ancho de la página (las demás de Ajustes van a 1180). */
.set-wrap{max-width:none}
.mt-hero{display:flex;align-items:center;gap:16px;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px 22px;margin-bottom:16px;flex-wrap:wrap}
.mt-hero .lg{width:58px;height:58px;border-radius:15px;border:1px solid var(--line);background:#fff;display:flex;align-items:center;justify-content:center;flex:none}
.mt-hero .tt{flex:1;min-width:240px}
.mt-hero h1{margin:0 0 4px;font-size:21px;letter-spacing:-.3px;color:var(--ink-strong)}
.mt-hero .hs{font-size:13px;color:var(--muted);line-height:1.55;max-width:82ch}
.mt-hero .act{flex:none;display:flex;flex-direction:column;align-items:flex-end;gap:6px}
.mt-hero .act .when{font-size:11.5px;color:var(--muted);white-space:nowrap}
/* Lista de clientes en DOS columnas para aprovechar el ancho. Cada uno, una tarjeta. */
.mt-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 64px}
@media(max-width:1000px){.mt-grid{grid-template-columns:1fr;gap:12px}}
.mt-row{display:flex;align-items:center;gap:12px;padding:13px 15px;border:1px solid var(--line);border-radius:12px;background:var(--card)}
.mt-row:hover{border-color:#d8dade;box-shadow:0 8px 22px -14px rgba(16,19,24,.35)}
.mt-dot{width:9px;height:9px;border-radius:50%;flex:none}
.mt-dot.on{background:#12a150}.mt-dot.no{background:#d6d9de}
.mt-mid{flex:1;min-width:0}
.mt-nm{font-weight:600;color:var(--ink-strong);font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mt-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:4px}
.mt-chip{font-size:10px;font-weight:700;padding:2px 7px;border-radius:99px;background:#f0f1f3;color:var(--label);text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
.mt-chip.on{background:#e7f7ee;color:var(--ok)}
.mt-when{color:var(--muted);font-size:11px;white-space:nowrap}
.mt-cta{white-space:nowrap;flex:none}
details.mt-adv > summary{cursor:pointer;font-size:13px;font-weight:600;color:var(--muted);list-style:none;display:inline-flex;align-items:center;gap:7px}
details.mt-adv > summary::-webkit-details-marker{display:none}
details.mt-adv[open] > summary{margin-bottom:12px;color:var(--ink)}
[data-theme=dark] .mt-hero .lg{background:#fff}
[data-theme=dark] .mt-chip{background-color:var(--soft);color:var(--muted)}
[data-theme=dark] .mt-chip.on{background-color:var(--ok-bg);color:var(--ok)}
[data-theme=dark] .mt-row:hover{border-color:var(--line-strong)}
</style>

<!-- CABECERA con logo de Google -->
<div class="mt-hero">
  <div class="lg"><?= svc_logo('google',34) ?></div>
  <div class="tt">
    <h1>Métricas de Google</h1>
    <div class="hs">El ERP trae de Google las <b>visitas y apariciones</b> (Search Console) y las <b>conversiones</b> (Analytics) de cada cliente y las muestra en su portal. Se actualiza <b>solo, una vez al día</b>.</div>
  </div>
  <?php if(can_edit() && $cfgOk): ?>
  <div class="act">
    <form method="post" onsubmit="return erpSubmitAsk(this,'El ERP va a leer Google de todos tus clientes. Puede tardar un poco. ¿Seguimos?',{ok:'Sí, traer datos',danger:false})">
      <input type="hidden" name="action" value="sync_all"><?= csrf_field() ?>
      <button class="btn ghost sm" type="submit"><?= ic('bolt',14) ?> Actualizar ahora</button>
    </form>
    <?php if($ultSync): ?><span class="when">Última: <?= e(date('d/m/Y',strtotime($ultSync))) ?></span><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if($flash): ?><div class="<?= $flashType==='err'?'err-note':'ok-note' ?>"><?= e($flash) ?></div><?php endif; ?>
<?php if(!$cfgOk): ?><div class="err-note">Todavía no está conectado con Google. <a href="integraciones.php?i=gmet">Conéctalo en Integraciones</a> (se hace una sola vez) para poder traer los números.</div><?php endif; ?>

<!-- CLIENTES -->
<div class="set-card">
  <h3>Tus clientes</h3>
  <div class="h-sub">Pulsa <b>«Configurar»</b> en un cliente y ahí pones <b>todo lo suyo de Google</b> en la misma pantalla: su web, su número de Analytics y sus conversiones. El <b>punto verde</b> es que ya tiene la web puesta (<?= $conWeb ?> de <?= count($clientes) ?>).</div>
  <div class="mt-grid">
    <?php foreach($clientes as $c): $cid=(int)$c['id'];
      $tieneWeb=trim((string)($c['gsc_site_url']??''))!==''; $tieneProp=trim((string)($c['ga4_property_id']??''))!=='';
      $tieneEv=(trim((string)($c['ga4_ev_ll']??''))!=='' || trim((string)($c['ga4_ev_wa']??''))!=='' || trim((string)($c['ga4_ev_fo']??''))!=='' ); ?>
      <div class="mt-row">
        <span class="mt-dot <?= $tieneWeb?'on':'no' ?>"></span>
        <div class="mt-mid">
          <div class="mt-nm"><?= e($c['name']) ?></div>
          <div class="mt-meta">
            <?php if($tieneWeb): ?><span class="mt-chip on">Web</span><?php endif; ?>
            <?php if($tieneProp): ?><span class="mt-chip on">Analytics</span><?php endif; ?>
            <?php if($c['conversiones'] && $tieneEv): ?><span class="mt-chip on">Conversiones</span><?php endif; ?>
            <?php if(!$tieneWeb && !$tieneProp): ?><span class="mt-chip">Sin configurar</span><?php endif; ?>
            <?php if($c['met_sync_at']): ?><span class="mt-when">· <?= e(date('d/m/Y',strtotime($c['met_sync_at']))) ?></span><?php endif; ?>
          </div>
        </div>
        <?php if(can_edit()): ?>
          <a class="btn ghost sm mt-cta" href="conversiones.php?cli=<?= $cid ?>"><?= ic('settings',14) ?> Configurar</a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- OPCIONES AVANZADAS -->
<?php if(can_edit()): ?>
<div class="set-card">
  <details class="mt-adv"><summary><?= ic('settings',14) ?> Opciones avanzadas (no hace falta tocar)</summary>
    <div class="h-sub" style="margin:0 0 12px">Nombres por defecto de los eventos de conversión, para clientes que aún no tengan los suyos elegidos. Los normales ya vienen puestos.</div>
    <form method="post"><input type="hidden" name="action" value="save_adv"><?= csrf_field() ?>
      <div class="set-grid">
        <div class="set-f c4"><label>📞 Llamadas</label><input type="text" name="ev_ll" value="<?= e($evLl) ?>"></div>
        <div class="set-f c4"><label>💬 WhatsApp</label><input type="text" name="ev_wa" value="<?= e($evWa) ?>"></div>
        <div class="set-f c4"><label>📝 Formularios</label><input type="text" name="ev_fo" value="<?= e($evFo) ?>"></div>
      </div>
      <div style="margin-top:12px"><button class="btn ghost sm" type="submit"><?= ic('check',14) ?> Guardar</button></div>
    </form>
    <div class="h-sub" style="margin:14px 0 0">Actualización automática mensual (para el técnico): <code>php admin/cron_metricas.php</code></div>
  </details>
</div>
<?php endif; ?>

<?php aj_foot(); ?>
