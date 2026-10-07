<?php
/* Conversiones de UN cliente — pantalla sencilla y encontrable.
   Antes esto vivía escondido en «Ajustes › Métricas de Google» dentro de un desplegable
   marcado «(opcional)». Aquí es una página propia por cliente, a la que se llega desde su
   ficha, para decir qué evento de Google Analytics cuenta como llamada / WhatsApp / formulario.
   Reutiliza el motor de lib/google_metrics.php (mismas columnas ga4_*). */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/lib/google_metrics.php';
gm_ensure_schema();

$cid = (int)($_GET['cli'] ?? 0);
/* Alcance: un miembro con rol limitado solo ve/edita las conversiones de SUS clientes. */
if ($cid && function_exists('alcance_ve_cliente') && !alcance_ve_cliente($cid)) { header('Location: index.php'); exit; }
$cli = null;
if ($cid) {
  $q = db()->prepare('SELECT id,name,gsc_site_url,ga4_property_id,ga4_ev_ll,ga4_ev_wa,ga4_ev_fo,conversiones FROM clients WHERE id=?');
  $q->execute([$cid]); $cli = $q->fetch();
}

/* AJAX: lista los eventos reales de GA4 de este cliente (para elegir sin escribir a mano).
   Acepta ?prop= para poder listar con el número que se acaba de teclear, aún sin guardar. */
if (($_GET['ajax'] ?? '') === 'ga4_events') {
  header('Content-Type: application/json; charset=utf-8');
  if (!$cli) { echo json_encode(['ok'=>false,'msg'=>'Cliente no encontrado.']); exit; }
  $prop = trim((string)($_GET['prop'] ?? ($cli['ga4_property_id'] ?? '')));
  if ($prop === '') { echo json_encode(['ok'=>false,'msg'=>'Falta el número de Analytics de este cliente.']); exit; }
  $token = gm_access_token();
  if (!$token) { echo json_encode(['ok'=>false,'msg'=>'El ERP aún no está conectado con Google. Ve a Métricas de Google y conéctalo una vez.']); exit; }
  echo json_encode(['ok'=>true,'events'=>gm_ga4_lista_eventos($token, $prop)]); exit;
}

/* Guardar (número de Analytics + qué evento es cada conversión). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can_edit() && $cli) {
  $prop = trim($_POST['prop'] ?? '');
  $site = trim($_POST['site'] ?? '');
  db()->prepare('UPDATE clients SET gsc_site_url=?, ga4_property_id=?, ga4_ev_ll=?, ga4_ev_wa=?, ga4_ev_fo=? WHERE id=?')
    ->execute([$site ?: null, $prop ?: null, trim($_POST['ev_ll'] ?? '') ?: null, trim($_POST['ev_wa'] ?? '') ?: null, trim($_POST['ev_fo'] ?? '') ?: null, (int)$cli['id']]);
  header('Location: conversiones.php?cli=' . (int)$cli['id'] . '&ok=1'); exit;
}

$saved   = isset($_GET['ok']);
$cfgOk   = gm_configurada();
require_once __DIR__ . '/erp_nav.php';
erp_head('clients', $cli ? ('Métricas · ' . $cli['name']) : 'Métricas', 'fin-canvas');
?>
<style>
body.fin-canvas .main{background:#f5f5f7}
.cv-wrap{max-width:1080px}
.cv-back{display:inline-flex;align-items:center;gap:7px;color:var(--muted);font-size:13px;text-decoration:none;margin-bottom:14px}
.cv-back:hover{color:var(--ink)}
.cv-h1{margin:0 0 6px;font-size:23px;letter-spacing:-.4px;display:flex;align-items:center;gap:10px}
.cv-lead{color:var(--muted);font-size:14px;line-height:1.6;margin:0 0 22px;max-width:60ch}
.cv-flash{border-radius:12px;padding:12px 16px;font-size:14px;margin-bottom:18px;font-weight:600;background:#eaf7ee;border:1px solid #bfe6cc;color:#166534}
.cv-warn{border-radius:12px;padding:13px 16px;font-size:13.5px;margin-bottom:18px;background:#fff6e6;border:1px solid #f2dca6;color:#8a5a00;line-height:1.55}
.cv-warn a{color:#8a5a00;font-weight:700}
.cv-card{background:#fff;border:1px solid rgba(16,19,24,.06);border-radius:18px;box-shadow:0 1px 2px rgba(16,19,24,.04),0 12px 30px -20px rgba(16,19,24,.12);padding:26px 28px;margin-bottom:18px}
.cv-card h3{font-size:17px;font-weight:650;margin:0 0 15px;color:var(--ink-strong);display:flex;align-items:center;gap:8px}
.cv-card .sub{color:var(--muted);font-size:13.5px;line-height:1.6;margin:0 0 16px}
/* Icono de ayuda «?» con tooltip: sustituye a los párrafos largos bajo cada título. */
.cv-help{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:var(--soft);border:1px solid var(--line);color:var(--muted);font-size:11px;font-weight:700;cursor:help;position:relative;flex:none}
.cv-help:hover,.cv-help:focus{background:var(--accent);color:#fff;border-color:var(--accent);outline:none}
.cv-help .cv-tip{position:absolute;bottom:calc(100% + 9px);left:0;background:#1f232a;color:#fff;font-size:12px;font-weight:400;line-height:1.5;letter-spacing:normal;text-transform:none;padding:10px 12px;border-radius:10px;width:280px;max-width:72vw;opacity:0;visibility:hidden;transition:opacity .14s ease;z-index:40;box-shadow:0 12px 34px -12px rgba(0,0,0,.55);text-align:left;pointer-events:none}
.cv-help .cv-tip code{background:rgba(255,255,255,.16);color:#fff;padding:1px 5px;border-radius:5px;font-size:11px}
.cv-help .cv-tip::after{content:"";position:absolute;top:100%;left:12px;border:6px solid transparent;border-top-color:#1f232a}
.cv-help:hover .cv-tip,.cv-help:focus .cv-tip{opacity:1;visibility:visible}
/* Dos columnas para aprovechar el ancho (izq: web + Analytics · der: conversiones). */
/* Arriba, dos tarjetas iguales (web + Analytics); debajo, conversiones a lo ancho. */
.cv-top{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;align-items:stretch}
.cv-top > .cv-card{margin:0}
@media(max-width:760px){.cv-top{grid-template-columns:1fr}}
.cv-f label{display:block;font-size:12.5px;font-weight:650;margin-bottom:8px;color:var(--ink-strong)}
.cv-f input{width:100%;max-width:300px;border:1px solid var(--line);border-radius:10px;padding:10px 13px;font-size:14px;font-family:ui-monospace,Menlo,Consolas,monospace;box-sizing:border-box;outline:none}
.cv-f input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.cv-f .hint{font-size:12px;color:#8a8f99;margin-top:8px;line-height:1.5;font-family:inherit}
.cv-tgt{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:15px 0;border-bottom:1px solid var(--line2)}
.cv-tgt:first-of-type{padding-top:4px}
.cv-tgt:last-of-type{border-bottom:none}
.cv-tgt .tl{flex:none;display:flex;flex-direction:column;gap:2px}
.cv-tgt .tl b{font-size:14px;font-weight:600;color:var(--ink-strong)}
.cv-tgt .tl small{font-weight:400;color:var(--muted);font-size:11px}
.cv-tags{flex:1;display:flex;flex-wrap:wrap;gap:6px;min-height:32px;align-items:center;justify-content:flex-end}
.cv-tag{display:inline-flex;align-items:center;gap:7px;background:var(--accent);color:#fff;border-radius:99px;padding:6px 9px 6px 13px;font-size:12px;font-family:ui-monospace,Menlo,monospace}
.cv-tag button{border:none;background:rgba(255,255,255,.25);color:#fff;border-radius:50%;width:17px;height:17px;cursor:pointer;font-size:11px;line-height:1;display:flex;align-items:center;justify-content:center;padding:0}
.cv-tags .empt{font-size:12.5px;color:var(--label)}
.cv-load{margin:18px 0 0}
.cv-ev{border:1px solid var(--line);border-radius:12px;max-height:300px;overflow:auto;margin-top:12px}
.cv-ev-row{display:flex;align-items:center;gap:10px;padding:10px 13px;border-bottom:1px solid var(--line2)}
.cv-ev-row:last-child{border-bottom:none}
.cv-ev-row .en{flex:1;min-width:0;font-family:ui-monospace,Menlo,monospace;font-size:12.5px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cv-ev-row .en b{color:var(--muted);font-weight:400;margin-left:6px}
.cv-ev-row .as{display:flex;gap:6px;flex:none}
.cv-ev-row .as button{border:1px solid var(--line);background:#fff;border-radius:9px;padding:5px 9px;font-size:14px;cursor:pointer;line-height:1}
.cv-ev-row .as button:hover{background:var(--soft)}
.cv-ev-row .as button.on{background:var(--accent);border-color:var(--accent);box-shadow:0 0 0 2px var(--accent-soft)}
.cv-btn{border:none;background:var(--accent);color:#fff;border-radius:11px;padding:12px 24px;font-size:14.5px;font-weight:650;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:filter .15s}
.cv-btn:hover{filter:brightness(1.15)}
.cv-btn.ghost{background:var(--soft);color:var(--ink);font-size:13px;padding:9px 15px;font-weight:600}
.cv-save{position:sticky;bottom:0;background:linear-gradient(transparent,#f5f5f7 40%);padding:16px 0 4px;display:flex;justify-content:flex-start;gap:10px}
/* Modo oscuro: lienzo, avisos, tarjetas y campos. El degradado del pie se
   redefine con la variable de fondo (no un gris claro fijo). Las etiquetas y el
   botón principal usan el acento y se dejan como están. */
[data-theme=dark] body.fin-canvas .main{background-color:var(--bg)}
[data-theme=dark] .cv-flash{background-color:var(--ok-bg);border-color:var(--ok-line);color:var(--ok)}
[data-theme=dark] .cv-warn{background-color:var(--soft);border-color:var(--line);color:var(--warn)}
[data-theme=dark] .cv-warn a{color:var(--warn)}
[data-theme=dark] .cv-card{background-color:var(--card);border-color:var(--line)}
[data-theme=dark] .cv-f .hint{color:var(--muted)}
[data-theme=dark] .cv-tags .empt{color:var(--muted)}
[data-theme=dark] .cv-ev-row .as button{background-color:var(--field)}
[data-theme=dark] .cv-save{background:linear-gradient(transparent,var(--bg) 40%)}
/* Móvil (≤640px): cada objetivo (llamadas/WhatsApp/formularios) pone su etiqueta
   arriba y las conversiones asignadas debajo, alineadas a la izquierda, en vez de
   empujarlas al borde derecho donde se apelotonan. El campo llena el ancho. */
@media(max-width:640px){
  .cv-card{padding:20px 18px}
  .cv-f input{max-width:none}
  .cv-tgt{flex-direction:column;align-items:flex-start;gap:8px}
  .cv-tags{justify-content:flex-start;width:100%}
  .cv-ev-row{flex-wrap:wrap}
}
</style>

<div class="cv-wrap">
<?php if (!$cli): ?>
  <?= erp_empty('clients','No encuentro ese cliente','El enlace no lleva a ningún cliente válido.','<a class="cv-btn ghost" href="index.php">Ver mis clientes</a>') ?>
<?php else: ?>
  <a class="cv-back" href="client.php?id=<?= (int)$cli['id'] ?>"><?= ic('back',14) ?> Volver a la ficha de <?= e($cli['name']) ?></a>
  <h1 class="cv-h1"><?= ic('chart',22) ?> Métricas de <?= e($cli['name']) ?></h1>
  <p class="cv-lead">Todo lo de Google de este cliente en un solo sitio. Rellena lo que tengas y pulsa <b>Guardar cambios</b>. Cada apartado tiene un <b>?</b> con la explicación.</p>

  <?php if ($saved): ?><div class="cv-flash"><?= ic('check',16) ?> Cambios guardados.</div><?php endif; ?>
  <?php if (!$cfgOk): ?><div class="cv-warn">⚠️ Todavía no está conectado Google en el ERP. Puedes dejar esto preparado, pero para ver la lista de eventos hay que conectarlo una vez en <a href="metricas.php">Métricas de Google</a>.</div><?php endif; ?>

  <form method="post" id="cvForm">
    <?= csrf_field() ?>

    <div class="cv-top">
      <div class="cv-card">
        <h3>Su web en Google <span class="cv-help" tabindex="0">?<span class="cv-tip">La dirección de su web tal como está en Google Search Console. Sirve para mostrar en su portal su visibilidad (cuánta gente la ve y la encuentra en Google).</span></span></h3>
        <div class="cv-f">
          <label>Dirección de la web</label>
          <input type="text" name="site" value="<?= e($cli['gsc_site_url'] ?? '') ?>" placeholder="https://sucliente.com/" <?= can_edit()?'':'disabled' ?>>
          <div class="hint">Ponla igual que aparece en Search Console (a veces es <code>sc-domain:sucliente.com</code>).</div>
        </div>
      </div>

      <div class="cv-card">
        <h3>Número de Analytics (GA4) <span class="cv-help" tabindex="0">?<span class="cv-tip">Es el número de la propiedad de Google Analytics de este cliente. Sin él no se pueden leer sus conversiones. Lo encuentras en Analytics → Administrar → Configuración de la propiedad.</span></span></h3>
        <div class="cv-f">
          <label>Número de la propiedad</label>
          <input type="text" name="prop" id="cvProp" value="<?= e($cli['ga4_property_id'] ?? '') ?>" placeholder="ej: 313888031" <?= can_edit()?'':'disabled' ?>>
          <div class="hint">Solo números.</div>
        </div>
      </div>
    </div>

    <div class="cv-card">
      <h3>¿Qué cuenta como cada contacto? <span class="cv-help" tabindex="0">?<span class="cv-tip">Pulsa «Ver mis eventos de Analytics» y, en cada evento, marca el iconito del tipo que sea (📞 llamada, 💬 WhatsApp, 📝 formulario). Puedes poner varios en el mismo tipo.</span></span></h3>
      <div class="cv-tgt"><div class="tl"><b>📞 Llamadas</b><small>Clic en el teléfono</small></div><div class="cv-tags" id="cvT_ll"></div></div>
      <div class="cv-tgt"><div class="tl"><b>💬 WhatsApp</b><small>Clic en WhatsApp</small></div><div class="cv-tags" id="cvT_wa"></div></div>
      <div class="cv-tgt"><div class="tl"><b>📝 Formularios</b><small>Formulario enviado</small></div><div class="cv-tags" id="cvT_fo"></div></div>
      <?php if (can_edit()): ?>
      <div class="cv-load"><button type="button" class="cv-btn ghost" id="cvLoadBtn" onclick="cvLoad()"><?= ic('search',14) ?> Ver mis eventos de Analytics</button></div>
      <div class="cv-ev" id="cvEv" style="display:none"></div>
      <?php endif; ?>
    </div>

    <input type="hidden" name="ev_ll" id="cvIn_ll">
    <input type="hidden" name="ev_wa" id="cvIn_wa">
    <input type="hidden" name="ev_fo" id="cvIn_fo">
    <?php if (can_edit()): ?>
    <div class="cv-save">
      <button class="cv-btn" type="submit" onclick="cvSync()"><?= ic('check',15) ?> Guardar cambios</button>
    </div>
    <?php endif; ?>
  </form>
<?php endif; ?>
</div>

<?php if ($cli): ?>
<script>
var cvAssign = {
  ll: cvParse(<?= json_encode((string)($cli['ga4_ev_ll'] ?? '')) ?>),
  wa: cvParse(<?= json_encode((string)($cli['ga4_ev_wa'] ?? '')) ?>),
  fo: cvParse(<?= json_encode((string)($cli['ga4_ev_fo'] ?? '')) ?>)
};
var cvEvents = [];
function cvParse(s){ return (s||'').split(',').map(function(x){return x.trim();}).filter(Boolean); }
function cvKindOf(name){ var k=null; ['ll','wa','fo'].forEach(function(x){ if(cvAssign[x].indexOf(name)>=0)k=x; }); return k; }
function cvAssignTo(name,k){ ['ll','wa','fo'].forEach(function(x){ var i=cvAssign[x].indexOf(name); if(i>=0)cvAssign[x].splice(i,1); }); cvAssign[k].push(name); cvRenderTags(); }
function cvUnassign(name,k){ var i=cvAssign[k].indexOf(name); if(i>=0)cvAssign[k].splice(i,1); cvRenderTags(); }
function cvBtn(name,k){ if(cvKindOf(name)===k) cvUnassign(name,k); else cvAssignTo(name,k); }
function cvRenderTags(){
  ['ll','wa','fo'].forEach(function(k){ var box=document.getElementById('cvT_'+k); var arr=cvAssign[k];
    if(!arr.length){ box.innerHTML='<span class="empt">Sin asignar</span>'; return; }
    box.innerHTML=arr.map(function(n){ return '<span class="cv-tag">'+escHtml(n)+'<button type="button" onclick="cvUnassign(\''+escJs(n)+'\',\''+k+'\')">&times;</button></span>'; }).join('');
  });
  cvRenderEvents();
}
function cvRenderEvents(){
  var box=document.getElementById('cvEv'); if(!box||box.style.display==='none') return;
  if(!cvEvents.length){ box.innerHTML='<div style="padding:18px;color:var(--muted);font-size:12.5px">No se han encontrado eventos en los últimos 90 días. Comprueba el número de Analytics.</div>'; return; }
  var L={ll:'📞',wa:'💬',fo:'📝'}, NM={ll:'Llamada',wa:'WhatsApp',fo:'Formulario'};
  box.innerHTML=cvEvents.map(function(ev){ var kind=cvKindOf(ev.name); var b='';
    ['ll','wa','fo'].forEach(function(k){ b+='<button type="button" title="'+NM[k]+'" class="'+(kind===k?'on':'')+'" onclick="cvBtn(\''+escJs(ev.name)+'\',\''+k+'\')">'+L[k]+'</button>'; });
    return '<div class="cv-ev-row"><span class="en">'+escHtml(ev.name)+' <b>'+ev.n+' veces</b></span><span class="as">'+b+'</span></div>';
  }).join('');
}
function cvLoad(){
  var btn=document.getElementById('cvLoadBtn'); var old=btn.innerHTML; btn.disabled=true; btn.textContent='Cargando…';
  var prop=encodeURIComponent((document.getElementById('cvProp')||{}).value||'');
  fetch('conversiones.php?ajax=ga4_events&cli=<?= (int)$cli['id'] ?>&prop='+prop).then(function(r){return r.json();}).then(function(j){
    btn.disabled=false; btn.innerHTML=old;
    if(!j||!j.ok){ if(window.toast)toast((j&&j.msg)||'No se pudo cargar.','err'); return; }
    cvEvents=j.events||[]; document.getElementById('cvEv').style.display=''; cvRenderEvents();
  }).catch(function(){ btn.disabled=false; btn.innerHTML=old; if(window.toast)toast('Error de conexión.','err'); });
}
function cvSync(){ document.getElementById('cvIn_ll').value=cvAssign.ll.join(','); document.getElementById('cvIn_wa').value=cvAssign.wa.join(','); document.getElementById('cvIn_fo').value=cvAssign.fo.join(','); }
/* Pintamos las etiquetas guardadas cuando ya ha cargado el resto de scripts del ERP
   (escHtml/escJs viven en el pie común, que se imprime DESPUÉS de este bloque). */
if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',cvRenderTags); else cvRenderTags();
</script>
<?php endif; ?>
<?php erp_foot(); ?>
