<?php
/* CRM v1.3 — Fase 6: Automatizaciones (seguimientos). Vista "Hoy" + gestión. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/crm_followup.php';
/* Solo las piezas de lectura del cron: incluir cron.php aquí lanzaría el ciclo
   entero cada vez que alguien abre esta página. */
require_once __DIR__ . '/lib/cron_lib.php';
ensure_crm_schema();

/* ---------------- POST ---------------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && can_edit()) {
  $a=$_POST['action']??'';
  if ($a==='done')   { db()->prepare("UPDATE follow_up_tasks SET estado='hecha' WHERE id=?")->execute([(int)($_POST['id']??0)]); $t=db()->prepare('SELECT contact_id,canal,descripcion FROM follow_up_tasks WHERE id=?'); $t->execute([(int)($_POST['id']??0)]); if($r=$t->fetch()){ crm_activity((int)$r['contact_id'],null,$r['canal'],'Seguimiento hecho: '.$r['descripcion']); db()->prepare('UPDATE contacts SET fecha_ultimo_contacto=CURDATE() WHERE id=?')->execute([(int)$r['contact_id']]); } header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit; }
  if ($a==='snooze') { db()->prepare("UPDATE follow_up_tasks SET fecha_prevista=DATE_ADD(CURDATE(),INTERVAL ? DAY) WHERE id=?")->execute([(int)($_POST['dias']??1),(int)($_POST['id']??0)]); header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit; }
  if ($a==='skip')   { db()->prepare("UPDATE follow_up_tasks SET estado='omitida' WHERE id=?")->execute([(int)($_POST['id']??0)]); header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit; }
  if ($a==='add')    {
    $cid=(int)($_POST['contact_id']??0); $canal=$_POST['canal']??'llamar'; $desc=trim($_POST['descripcion']??''); $fecha=($_POST['fecha']??'')?:date('Y-m-d');
    if($cid && $desc!=='') crm_fu_add($cid,null,$canal,$desc,$fecha,'manual',99);
    header('Location: automatizaciones.php'); exit;
  }
  if ($a==='regen')  { crm_fu_generate(); header('Location: automatizaciones.php'); exit; }
  if ($a==='run_digest') { crm_fu_send_daily(true); header('Location: automatizaciones.php?ran=1'); exit; }
  /* Si la clave se ha filtrado (un correo, una captura), con esto deja de valer
     al instante y la línea del crontab hay que volver a copiarla. */
  if ($a==='cron_key_regen' && is_owner()) { cron_key(true); header('Location: automatizaciones.php?nk=1'); exit; }
}

/* Antes se llamaba a crm_fu_generate() aquí, así que abrir la página (un GET)
   generaba seguimientos. Ahora lo hacen el cron y el botón «Generar seguimientos»
   (POST con CSRF). La carga solo lee lo que ya existe (P1-06). */
$grouped = crm_fu_today(true);
$totHoy=0; foreach($grouped as $g) $totHoy+=count($g);
$CH = crm_fu_channels();
$colorMap=['llamar'=>'#5b8def','whatsapp'=>'#12a150','email'=>'#f0872a','reunion'=>'#e0a000'];
$upcoming = [];
try{ $upcoming = db()->query("SELECT f.*, c.nombre c_nombre, c.empresa c_empresa FROM follow_up_tasks f JOIN contacts c ON c.id=f.contact_id WHERE f.estado='pendiente' AND f.fecha_prevista>CURDATE() ORDER BY f.fecha_prevista LIMIT 40")->fetchAll(); }catch(Exception $e){}
$hechasHoy = (int)db()->query("SELECT COUNT(*) FROM follow_up_tasks WHERE estado='hecha' AND DATE(fecha_prevista)=CURDATE()")->fetchColumn();
$lastLog = null; try{ $lastLog = db()->query("SELECT * FROM email_log ORDER BY id DESC LIMIT 1")->fetch(); }catch(Exception $e){}
/* Estado del cron del servidor: es lo que separa «esto se hace solo» de «esto
   se hace cuando alguien abre la página». */
$cronUlt = cron_ultima();
$cronTar = cron_por_tarea();
/* Nombres legibles de cada tarea; la clave es la misma que el interruptor de
   Ajustes que la enciende o la apaga. */
$cronNom = ['invoice_recurring'=>'Facturas recurrentes','followups'=>'Seguimientos del CRM','daily_digest'=>'Resumen diario','lead_reminder'=>'Avisos de leads','invoice_due'=>'Avisos de facturas','monthly_report'=>'Aviso de informe mensual','trash_purge'=>'Limpieza de la papelera','uploads_sweep'=>'Archivos sin uso'];

erp_head('crm', 'CRM · Reporting', 'fin-canvas');
?>
<style>
body.fin-canvas .main{background:#f5f5f7}
body.fin-canvas .erp-wrap{animation:none;padding:32px 40px 80px}
body.fin-canvas h1{letter-spacing:-.5px}
.au-wrap{max-width:1120px;margin:0 auto}
@keyframes finIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
/* Cabecera hero */
.au-hero{display:flex;align-items:flex-start;gap:16px;margin:0 0 18px}
.au-hero .ht{flex:1;min-width:0}
.au-hero h1{font-size:25px;font-weight:650;color:var(--ink-strong);margin:0}
.au-hero p{margin:5px 0 0;font-size:13.5px;color:var(--muted)}
.au-hero .acts{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.au-btn{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--line);background:#fff;border-radius:11px;padding:9px 14px;font-size:13px;font-weight:600;color:var(--ink);cursor:pointer;text-decoration:none;transition:background .12s,border-color .12s,filter .12s}
.au-btn:hover{background:#fafafb;border-color:#d7d8db}
.au-btn svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2}
.au-btn.pri{background:var(--accent);color:#fff;border-color:var(--accent)}
.au-btn.pri:hover{filter:brightness(1.2);background:var(--accent)}
/* Chips de estado del día */
.au-stats{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 20px}
.au-chip{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1px solid rgba(16,19,24,.06);border-radius:13px;padding:9px 15px;font-size:13px;color:var(--muted);box-shadow:0 1px 2px rgba(16,19,24,.03)}
.au-chip .v{font-size:17px;font-weight:750;color:var(--ink-strong)}
/* Columnas por canal */
.au-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:22px}
.au-col{background:#fff;border:1px solid rgba(16,19,24,.06);border-radius:18px;overflow:hidden;box-shadow:0 1px 2px rgba(16,19,24,.03),0 14px 30px -22px rgba(16,19,24,.20)}
.au-ch{display:flex;align-items:center;gap:9px;padding:14px 16px;border-bottom:1px solid var(--line2)}
.au-ch .dot{width:9px;height:9px;border-radius:50%}
.au-ch b{font-size:12px;font-weight:650;letter-spacing:.5px}
.au-ch .n{margin-left:auto;font-size:11.5px;font-weight:700;color:var(--muted);background:var(--soft);border-radius:99px;padding:1px 9px;min-width:22px;text-align:center}
.au-list{display:flex;flex-direction:column}
.au-item{padding:15px 18px;border-bottom:1px solid var(--line2);animation:finIn .3s ease both}
.au-item:last-child{border-bottom:none}
.au-in{font-size:14px;font-weight:650;color:var(--ink-strong)}
.au-ic{font-size:12px;color:var(--muted);margin-top:3px}
.au-id{font-size:13px;color:var(--ink);margin-top:6px;line-height:1.55}
.au-ia{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}
.au-ia button{border:1px solid var(--line);background:#fff;border-radius:9px;padding:6px 11px;font-size:12px;font-weight:600;cursor:pointer;color:var(--ink);transition:background .12s}
.au-ia button.ok{color:#1a9d5b;border-color:#bfe9d0}.au-ia button.ok:hover{background:#f0fdf4}
.au-ia button:hover{background:var(--soft)}
.au-empty{padding:28px 16px;text-align:center;color:var(--muted);font-size:12.5px}
.au-allok{background:#fff;border:1px solid rgba(16,19,24,.06);border-radius:18px;padding:46px;text-align:center;margin-bottom:22px;box-shadow:0 1px 2px rgba(16,19,24,.03),0 14px 30px -22px rgba(16,19,24,.20)}
.au-allok .big{font-size:18px;font-weight:650;color:#1a9d5b}
/* Dos tarjetas */
.au-two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:820px){.au-two{grid-template-columns:1fr}}
.au-card{background:#fff;border:1px solid rgba(16,19,24,.06);border-radius:18px;padding:22px 24px;box-shadow:0 1px 2px rgba(16,19,24,.03),0 14px 30px -22px rgba(16,19,24,.20)}
.au-card h3{font-size:15px;font-weight:650;margin:0 0 16px;color:var(--ink-strong)}
.au-up{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--line2);font-size:13px}
.au-up:last-child{border-bottom:none}
.au-updot{width:8px;height:8px;border-radius:50%;flex:none}
.au-upd{margin-left:auto;color:var(--muted);font-size:11.5px}
.au-note{font-size:12.5px;color:#5f6672;line-height:1.65}
.au-note b{color:var(--ink-strong)}
.au-note code{background:var(--soft);border:1px solid var(--line);border-radius:5px;padding:1px 6px;font-size:11.5px}
/* Cron compacto y plegable */
.au-cron{margin-top:16px}
.cr-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.cr-head h3{margin:0;font-size:15px;font-weight:650;color:var(--ink-strong)}
.cr-pill{font-size:11px;font-weight:650;letter-spacing:.3px;border-radius:99px;padding:3px 11px}
.cr-pill.ok{background:#e9f8ef;color:#1a9d5b}
.cr-pill.bad{background:#feecec;color:#dd5b52}
.cr-when{font-size:12.5px;color:var(--muted)}
.cr-toggle{margin-left:auto;border:1px solid var(--line);background:#fff;border-radius:10px;padding:8px 13px;font-size:12.5px;font-weight:600;color:var(--ink);cursor:pointer;display:inline-flex;align-items:center;gap:7px}
.cr-toggle:hover{background:var(--soft)}
.cr-toggle svg{width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2.4;transition:transform .2s}
.au-cron.open .cr-toggle svg{transform:rotate(180deg)}
.cr-body{display:none;margin-top:16px;padding-top:16px;border-top:1px solid var(--line2)}
.au-cron.open .cr-body{display:block;animation:finIn .25s ease}
.cr-run{border:1px solid var(--line);background:#fff;border-radius:10px;padding:8px 13px;font-size:12.5px;font-weight:600;color:var(--ink);text-decoration:none;display:inline-block;margin-bottom:12px}
.cr-run:hover{background:var(--soft)}
.cr-tab{width:100%;border-collapse:collapse;margin-bottom:6px}
.cr-tab td{padding:9px 0;border-bottom:1px solid var(--line2);font-size:12.5px;vertical-align:middle}
.cr-tab tr:last-child td{border-bottom:none}
.cr-tab td.n{font-weight:650;color:var(--ink-strong);width:36%}
.cr-tab td.d{color:var(--muted)}
.cr-tab td.w{color:var(--muted);text-align:right;white-space:nowrap;padding-left:14px}
.cr-tab td.s{width:18px;text-align:right}
.cr-dot{display:inline-block;width:8px;height:8px;border-radius:50%}
.cr-copy{display:flex;align-items:center;gap:8px;margin:8px 0 12px}
.cr-copy code{flex:1;min-width:0;overflow-x:auto;white-space:nowrap;background:var(--soft);border:1px solid var(--line);border-radius:8px;padding:9px 11px;font-size:11.5px;color:var(--ink-strong)}
.cr-copy button{flex:none;border:1px solid var(--line);background:#fff;border-radius:8px;padding:9px 13px;font-size:11.5px;font-weight:600;cursor:pointer;color:var(--ink)}
.cr-copy button:hover{background:var(--soft)}
.cr-regen{border:1px solid var(--line);background:#fff;border-radius:9px;padding:7px 12px;font-size:12px;font-weight:600;color:#dd5b52;cursor:pointer}
.cr-regen:hover{background:#feecec;border-color:#f8d0d0}
/* Modal */
.au-mask{position:fixed;inset:0;background:rgba(16,19,24,.42);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);display:none;align-items:center;justify-content:center;z-index:500}
.au-mask.on{display:flex}
.au-modal{background:#fff;border-radius:18px;width:440px;max-width:94vw;padding:26px 28px;box-shadow:0 30px 80px rgba(0,0,0,.3)}
.au-modal h3{font-size:18px;font-weight:650;margin:0 0 16px}
.au-modal label{display:block;font-size:12px;color:var(--muted);font-weight:600;margin:10px 0 5px}
.au-modal input,.au-modal select{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font-size:14px;font-family:inherit;color:var(--ink);outline:none}
.au-modal input:focus,.au-modal select:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.au-modal .acts{display:flex;justify-content:flex-end;gap:9px;margin-top:20px}
.au-modal .acts button{border-radius:10px;padding:10px 16px;font-size:13.5px;font-weight:600;cursor:pointer;border:1px solid var(--line);background:#fff;color:var(--ink)}
.au-modal .acts button.pri{background:var(--accent);color:#fff;border-color:var(--accent)}
/* ---- Modo oscuro (capa aditiva: solo remapea superficies y textos propios) ---- */
[data-theme=dark] body.fin-canvas .main{background-color:var(--bg)}
[data-theme=dark] .au-btn,[data-theme=dark] .au-ia button,
[data-theme=dark] .au-modal,[data-theme=dark] .au-modal .acts button,
[data-theme=dark] .cr-toggle,[data-theme=dark] .cr-run,
[data-theme=dark] .cr-copy button,[data-theme=dark] .cr-regen{background-color:var(--card)}
[data-theme=dark] .au-chip,[data-theme=dark] .au-col,
[data-theme=dark] .au-allok,[data-theme=dark] .au-card{background-color:var(--card);border-color:var(--line)}
[data-theme=dark] .au-btn:hover{background-color:var(--soft);border-color:var(--line-strong)}
/* Estados activos / acento → se invierten */
[data-theme=dark] .au-btn.pri,[data-theme=dark] .au-btn.pri:hover,
[data-theme=dark] .au-modal .acts button.pri{background-color:var(--rev);color:var(--rev-fg);border-color:var(--rev)}
/* Avisos verde/rojo */
[data-theme=dark] .au-ia button.ok{color:var(--ok);border-color:var(--ok-line)}
[data-theme=dark] .au-ia button.ok:hover,[data-theme=dark] .cr-pill.ok{background-color:var(--ok-bg);color:var(--ok)}
[data-theme=dark] .au-allok .big{color:var(--ok)}
[data-theme=dark] .cr-pill.bad{background-color:var(--danger-bg);color:var(--danger)}
[data-theme=dark] .cr-regen{color:var(--danger)}
[data-theme=dark] .cr-regen:hover{background-color:var(--danger-bg);border-color:var(--danger-line)}
[data-theme=dark] .au-note{color:var(--muted)}
/* ============ MÓVIL (≤640px) ============ */
@media(max-width:640px){
  body.fin-canvas .erp-wrap{padding:20px 16px 60px}
  .au-hero{flex-direction:column;gap:12px}
  .au-hero h1{font-size:22px}
  .au-hero .acts{justify-content:flex-start;width:100%}
  .au-btn{flex:1 1 auto;justify-content:center}
  .au-two{grid-template-columns:1fr}
  .au-card{padding:18px 16px}
  .cr-head{gap:8px}
  .cr-toggle{margin-left:0;width:100%;justify-content:center}
  .cr-when{width:100%}
  .cr-tab td.n{width:auto}
  .au-modal{width:100%!important;max-width:100%;border-radius:16px;padding:22px 18px}
}
</style>
<div class="au-wrap">

<div class="au-hero">
  <div class="ht">
    <h1>Reporting</h1>
    <p>Los seguimientos del CRM se preparan solos desde leads nuevos, propuestas enviadas y negocios a reactivar.</p>
  </div>
  <div class="acts">
    <a class="au-btn" href="crm_followup_email.php?preview=1" target="_blank"><svg viewBox="0 0 24 24"><path d="M3 8l9 6 9-6M3 6h18v12H3z"/></svg> Ver email diario</a>
    <?php if(can_edit()): ?>
    <button class="au-btn" onclick="auAdd()"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Seguimiento manual</button>
    <form method="post" style="display:inline"><input type="hidden" name="action" value="regen"><button type="submit" class="au-btn"><svg viewBox="0 0 24 24"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.9-3.4L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15"/></svg> Generar</button></form>
    <form method="post" style="display:inline"><input type="hidden" name="action" value="run_digest"><button type="submit" class="au-btn pri"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg> Ejecutar resumen</button></form>
    <?php endif; ?>
  </div>
</div>
<div class="au-stats">
  <div class="au-chip"><span class="v"><?= $totHoy ?></span> pendiente<?= $totHoy==1?'':'s' ?> hoy</div>
  <?php if($hechasHoy): ?><div class="au-chip"><span class="v" style="color:#1a9d5b"><?= $hechasHoy ?></span> hecho<?= $hechasHoy==1?'':'s' ?> hoy</div><?php endif; ?>
  <div class="au-chip"><span class="v" style="font-size:13px;font-weight:600;color:var(--ink)"><?= date('d/m/Y') ?></span></div>
  <?php if($lastLog): ?><div class="au-chip">Último resumen: <b style="color:var(--ink);font-weight:650"><?= date('d/m H:i',strtotime($lastLog['fecha_ejecucion'])) ?></b> · <?= (int)$lastLog['n_acciones'] ?> acciones</div><?php endif; ?>
</div>

<?php if($totHoy===0): ?>
  <div class="au-allok"><div class="big">✓ No hay seguimientos pendientes hoy</div><div style="color:var(--muted);font-size:13px;margin-top:8px">Las tareas se generan solas desde leads nuevos, propuestas enviadas y negocios a reactivar.</div></div>
<?php else: ?>
<div class="au-cols">
  <?php foreach($CH as $slug=>$meta): $items=$grouped[$slug]??[]; if(!$items && $slug==='reunion') continue; ?>
  <div class="au-col">
    <div class="au-ch"><span class="dot" style="background:<?= $colorMap[$slug]??'#94a3b8' ?>"></span><b style="color:<?= $colorMap[$slug]??'#94a3b8' ?>"><?= e($meta[0]) ?></b><span class="n"><?= count($items) ?></span></div>
    <div class="au-list">
      <?php if(!$items): ?><div class="au-empty">Nada por aquí hoy.</div><?php endif; ?>
      <?php foreach($items as $it): $venc=$it['fecha_prevista']<date('Y-m-d'); ?>
      <div class="au-item" data-id="<?= (int)$it['id'] ?>">
        <div class="au-in"><?= e($it['c_nombre']) ?></div>
        <div class="au-ic"><?= e($it['c_empresa']?:'') ?><?= $venc?' · <span style="color:#e5484d;font-weight:700">vencía '.date('d/m',strtotime($it['fecha_prevista'])).'</span>':'' ?></div>
        <div class="au-id"><?= e($it['descripcion']) ?></div>
        <?php if(can_edit()): ?>
        <div class="au-ia">
          <button class="ok" onclick="auDone(<?= (int)$it['id'] ?>,this)">✓ Hecho</button>
          <button onclick="auSnooze(<?= (int)$it['id'] ?>,1,this)">+1 día</button>
          <button onclick="auSnooze(<?= (int)$it['id'] ?>,7,this)">+1 sem</button>
          <button onclick="auSkip(<?= (int)$it['id'] ?>,this)">Omitir</button>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="au-two">
  <div class="au-card">
    <h3>Próximos seguimientos</h3>
    <?php if(!$upcoming): ?><div style="color:var(--muted);font-size:12.5px">Nada programado más adelante.</div><?php else: foreach($upcoming as $u): ?>
    <div class="au-up"><span class="au-updot" style="background:<?= $colorMap[$u['canal']]??'#94a3b8' ?>"></span><b style="font-weight:600"><?= e($u['c_nombre']) ?></b> <span style="color:var(--muted)"><?= e($CH[$u['canal']][0]??$u['canal']) ?></span><span class="au-upd"><?= date('d/m',strtotime($u['fecha_prevista'])) ?></span></div>
    <?php endforeach; endif; ?>
  </div>
  <div class="au-card">
    <h3>Resumen diario por email</h3>
    <div class="au-note">
      Cada día laborable (L–V) se prepara un <b>resumen de seguimientos</b> agrupado por canal: LLAMAR HOY, ESCRIBIR WHATSAPP, ENVIAR EMAIL y REUNIONES.<br><br>
      El resumen está disponible como página en <code>crm_followup_email.php</code> y lo lanza solo el cron de abajo, junto con el resto de automatizaciones. «<b>Ejecutar resumen ahora</b>» hace lo mismo a mano, sin esperar a mañana.
    </div>
  </div>
</div>

<?php /* ---- El cron: quién ejecuta todo esto cuando nadie está mirando ----
         Sin esto, cada automatización dependía de que alguien abriera la página
         correcta. Aquí se ve si el servidor está haciendo su trabajo y, si no,
         qué hay que pegar para que empiece. */ ?>
<?php $cronOk = $cronUlt && ((time()-strtotime($cronUlt['created_at'])) < 90000); ?>
<div class="au-card au-cron" id="auCron" style="margin-top:16px">
  <div class="cr-head">
    <h3>Ejecución automática (cron)</h3>
    <?php if($cronUlt): ?>
      <span class="cr-pill <?= $cronOk?'ok':'bad' ?>"><?= $cronOk?'Funcionando':'Parado' ?></span>
      <span class="cr-when">Última ejecución <?= e(cron_hace($cronUlt['created_at'])) ?> · <?= date('d/m/Y H:i',strtotime($cronUlt['created_at'])) ?></span>
    <?php else: ?>
      <span class="cr-pill bad">Sin configurar</span>
      <span class="cr-when">Todavía no se ha ejecutado ni una vez.</span>
    <?php endif; ?>
    <button type="button" class="cr-toggle" onclick="auCronToggle()">Ver detalles <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></button>
  </div>

  <div class="cr-body">
    <?php if(can_edit()): ?><a class="cr-run" href="<?= e(cron_url()) ?>" target="_blank" rel="noopener">Ejecutar el cron ahora</a><?php endif; ?>
    <?php if($cronTar): ?>
    <table class="cr-tab">
      <?php foreach($cronTar as $t): ?>
      <tr>
        <td class="n"><?= e($cronNom[$t['tarea']] ?? $t['tarea']) ?></td>
        <td class="d"><?= e($t['detalle']!==''?$t['detalle']:'—') ?></td>
        <td class="w"><?= e(cron_hace($t['created_at'])) ?></td>
        <td class="s"><span class="cr-dot" style="background:<?= $t['ok']?'#1a9d5b':'#dd5b52' ?>"></span></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <div class="au-note" style="margin-top:14px">
      Para que todo esto ocurra solo, el servidor tiene que llamar a <code>cron.php</code> cada 15 minutos. En Hostinger se añade en <b>Avanzado → Cron jobs</b>. La línea es esta:
      <div class="cr-copy"><code id="crCli">*/15 * * * * php <?= e(cron_ruta()) ?></code><button type="button" onclick="crCopiar('crCli',this)">Copiar</button></div>
      Si el hosting no deja ejecutar PHP por línea de comandos, sirve igual llamando a la URL (lleva su propia clave, no la comparta):
      <div class="cr-copy"><code id="crUrl"><?= e(cron_url()) ?></code><button type="button" onclick="crCopiar('crUrl',this)">Copiar</button></div>
      Cada tarea se puede apagar por separado desde <a href="settings.php">Ajustes</a>; el cron respeta esos interruptores.
      <?php if(is_owner()): ?>
      <form method="post" style="margin-top:10px" onsubmit="return erpSubmitAsk(this,'La línea del cron dejará de funcionar hasta que vuelvas a copiarla.',{titulo:'¿Generar una clave nueva?',ok:'Generar',danger:true})">
        <input type="hidden" name="action" value="cron_key_regen">
        <button type="submit" class="cr-regen">Generar una clave nueva</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
</div><!-- /au-wrap -->

<script>
/* Copiar la línea del cron sin tener que seleccionarla a mano, que en una línea
   larga con una clave dentro es justo donde uno se deja un carácter. */
function crCopiar(id,btn){
  var t=(document.getElementById(id)||{}).textContent||'';
  var fin=function(){ var o=btn.textContent; btn.textContent='Copiado'; setTimeout(function(){btn.textContent=o;},1400); };
  if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(t).then(fin,function(){ if(window.toast)toast('No se ha podido copiar','err'); }); return; }
  var a=document.createElement('textarea'); a.value=t; document.body.appendChild(a); a.select();
  try{ document.execCommand('copy'); fin(); }catch(e){ if(window.toast)toast('No se ha podido copiar','err'); }
  document.body.removeChild(a);
}
function auCronToggle(){var c=document.getElementById('auCron');if(c)c.classList.toggle('open');}
</script>

<?php if(can_edit()): ?>
<div class="au-mask" id="auMask">
  <form class="au-modal" method="post">
    <input type="hidden" name="action" value="add">
    <h3>Seguimiento manual</h3>
    <label>Contacto *</label>
    <select name="contact_id" required><option value="">Selecciona…</option>
      <?php foreach(db()->query('SELECT id,nombre,empresa FROM contacts ORDER BY nombre') as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['nombre']) ?><?= $c['empresa']?' — '.e($c['empresa']):'' ?></option><?php endforeach; ?>
    </select>
    <label>Canal</label>
    <select name="canal"><?php foreach($CH as $slug=>$m): ?><option value="<?= e($slug) ?>"><?= e($m[0]) ?></option><?php endforeach; ?></select>
    <label>Descripción *</label><input name="descripcion" required placeholder="Qué hay que hacer">
    <?php /* El calendario del ERP, no el del navegador: cada navegador dibuja el suyo
             y en Firefox ni siquiera hay. El visible escribe dd/mm/aa y va sincronizando
             el oculto, que es el que viaja en el POST en formato ISO. */ ?>
    <label>Fecha</label><input type="text" class="dpick" data-iso="<?= date('Y-m-d') ?>" data-sync="#auFecha" autocomplete="off">
    <input type="hidden" name="fecha" id="auFecha" value="<?= date('Y-m-d') ?>">
    <div class="acts"><button type="button" onclick="auClose()">Cancelar</button><button type="submit" class="pri">Crear</button></div>
  </form>
</div>
<script>
function post(b,cb){fetch('automatizaciones.php',{method:'POST',body:new URLSearchParams(b)}).then(function(){if(cb)cb();});}
function auAdd(){document.getElementById('auMask').classList.add('on');}
function auClose(){document.getElementById('auMask').classList.remove('on');}
document.getElementById('auMask').addEventListener('click',function(e){if(e.target===this)auClose();});
function fade(el){var it=el.closest('.au-item');if(it){it.style.opacity=.4;setTimeout(function(){it.remove();},250);}}
function auDone(id,el){fade(el);post('action=done&id='+id);}
function auSnooze(id,d,el){fade(el);post('action=snooze&dias='+d+'&id='+id);}
function auSkip(id,el){fade(el);post('action=skip&id='+id);}
</script>
<?php endif; ?>

<?php erp_foot(); ?>
