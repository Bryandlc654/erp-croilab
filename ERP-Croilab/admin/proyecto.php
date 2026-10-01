<?php
/* Ficha de proyecto — gestión financiera completa de un proyecto:
   balance desde la caja, movimientos vinculados, facturas de venta y pendiente
   de cobro. La fuente de verdad es la CAJA (accounting); todo el modelo vive en
   lib/proyectos_lib.php. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/lib/proyectos_lib.php';
require_once __DIR__ . '/erp_nav.php';

$id = (int)($_GET['id'] ?? 0);
$P  = proj_get($id);
if (!$P) { header('Location: proyectos.php'); exit; }

/* Estados de factura (mismo mapa que facturas.php): [etiqueta, color]. */
$ESTADOS = ['borrador'=>['Borrador','#9aa0a8'],'enviada'=>['Enviada','#3b82f6'],'pagada'=>['Pagada','#12a150'],'vencida'=>['Vencida','#ef4444']];
/* Paleta de colores del proyecto (igual que proyectos.php). */
$PAL = ['#2f6df6','#12a150','#e0a341','#e05a4f','#8b5cf6','#0ea5a5','#eb5a9a','#f59e0b','#14b8a6','#a855f7'];

/* ---- POST (solo si se puede editar) ---- */
if ($_SERVER['REQUEST_METHOD']==='POST' && can_edit()) {
  $a = $_POST['action'] ?? '';
  if ($a==='add_mov') {
    $tipo = ($_POST['tipo']??'gasto')==='ingreso' ? 'ingreso' : 'gasto';
    proj_add_mov($id, $tipo, $_POST['concepto']??'', (float)num_es($_POST['importe']??'',false), $_POST['fecha']??null);
  } elseif ($a==='unlink_acc') {
    proj_unlink_acc((int)($_POST['acc_id']??0));
  } elseif ($a==='link_invoice') {
    proj_link_invoice($id, (int)($_POST['invoice_id']??0));
  } elseif ($a==='unlink_invoice') {
    proj_unlink_invoice((int)($_POST['invoice_id']??0));
  } elseif ($a==='rename') {
    $nn=trim($_POST['nombre']??''); if($nn!=='') db()->prepare('UPDATE projects SET nombre=? WHERE id=?')->execute([$nn,$id]);
  } elseif ($a==='color') {
    $cc=trim($_POST['color']??''); if($cc!=='') db()->prepare('UPDATE projects SET color=? WHERE id=?')->execute([$cc,$id]);
  } elseif ($a==='toggle') {
    db()->prepare('UPDATE projects SET activo=1-activo WHERE id=?')->execute([$id]);
  } elseif ($a==='del') {
    /* Borrar el proyecto: los movimientos y facturas quedan SIN proyecto (no se borran). */
    db()->prepare('UPDATE accounting SET project_id=NULL WHERE project_id=?')->execute([$id]);
    db()->prepare('UPDATE invoices SET project_id=NULL WHERE project_id=?')->execute([$id]);
    db()->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
    header('Location: proyectos.php'); exit;
  }
  header('Location: proyecto.php?id='.$id.((($_POST['y']??'')!=='')?'&y='.$_POST['y']:'')); exit;
}

/* ---- Año seleccionado ---- */
$yParam = $_GET['y'] ?? (string)date('Y');
$histo  = ($yParam==='all');
$year   = $histo ? 0 : (int)$yParam;
$years  = [];
foreach(db()->query('SELECT DISTINCT YEAR(fecha) y FROM accounting WHERE fecha IS NOT NULL ORDER BY y DESC') as $r) $years[]=(int)$r['y'];
if(!in_array((int)date('Y'),$years,true)) array_unshift($years,(int)date('Y'));

/* Renderiza las filas de facturas vinculables (reutilizado por el fragmento AJAX). */
function pf_render_vinc($rows, $ESTADOS, $projId, $yStr){
  if(!$rows){ echo '<div class="pf-vmpty">No hay facturas emitidas para vincular.</div>'; return; }
  foreach($rows as $r){
    $ev = $ESTADOS[$r['estado']] ?? $ESTADOS['borrador'];
    $ya = ($r['project_id']!==null && (int)$r['project_id']===(int)$projId);
    echo '<button type="button" class="pf-vrow'.($ya?' ya':'').'"'.($ya?' disabled':' onclick="pfLink('.(int)$r['id'].')"').'>';
    echo '<span class="pf-vnum">'.e($r['numero']?:('#'.$r['id'])).'</span>';
    echo '<span class="pf-vcli">'.e($r['cliente_nombre']?:'—').'</span>';
    echo '<span class="pf-vst" style="background:'.e($ev[1]).'1f;color:'.e($ev[1]).'">'.e($ev[0]).'</span>';
    echo '<span class="pf-vtot">'.eur((float)$r['total']).'</span>';
    echo $ya ? '<span class="pf-vok">Ya vinculada</span>' : '<span class="pf-vadd">'.ic('plus',14).'</span>';
    echo '</button>';
  }
}

/* ---- Fragmento AJAX del buscador de facturas ---- */
if (($_GET['frag']??'')==='vinc') {
  header('Content-Type: text/html; charset=utf-8');
  $rows = proj_invoices_vinculables($P['client_id'], (string)($_GET['q']??''), 20);
  pf_render_vinc($rows, $ESTADOS, $id, $histo?'all':(string)$year);
  exit;
}

/* ---- Datos de la ficha ---- */
$bal   = proj_balance($id, $year);
$movs  = proj_movimientos($id, $year);
$facs  = proj_facturas($id);
$pend  = proj_pendiente($id);
$vinc  = proj_invoices_vinculables($P['client_id'], '', 20);
$yStr  = $histo ? 'all' : (string)$year;
$yLabel= $histo ? 'histórico' : $year;
$color = $P['color'] ?: '#2f6df6';

erp_head('proj', $P['nombre'], 'fin-canvas');
?>
<style>
body.fin-canvas .main{background:#f5f5f7}
body.fin-canvas .erp-wrap{animation:none;padding:36px 48px 80px}
body.fin-canvas h1{letter-spacing:-.5px;font-size:26px}
@keyframes finIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.pf-wrap{max-width:none}
.pf-back{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:12.5px;font-weight:600;text-decoration:none;margin-bottom:14px;transition:color .12s}
.pf-back:hover{color:var(--ink)}
.pf-head{display:flex;align-items:center;gap:14px;margin-bottom:8px;flex-wrap:wrap;animation:finIn .5s cubic-bezier(.2,.7,.3,1) both}
.pf-head h1{flex:1;margin:0;display:flex;align-items:center;gap:12px;min-width:0}
.pf-dot{width:14px;height:14px;border-radius:50%;flex:none}
.pf-lead{color:var(--muted);font-size:13.5px;line-height:1.6;margin:0 0 26px;max-width:720px}
.co-yr{display:inline-flex;gap:3px;background:#ececf0;border-radius:11px;padding:4px}
.co-yr a{padding:6px 13px;border-radius:8px;font-size:12.5px;font-weight:600;color:var(--label);cursor:pointer;text-decoration:none;transition:color .15s}
.co-yr a.on{background:#fff;color:var(--ink-strong);box-shadow:0 1px 3px rgba(16,19,24,.12)}
.co-yr a:hover:not(.on){color:var(--ink)}
/* KPIs */
.pf-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-bottom:22px}
@media(max-width:900px){.pf-kpis{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.pf-kpis{grid-template-columns:1fr}}
.pf-k{background:#fff;border:1px solid rgba(16,19,24,.05);border-radius:20px;padding:22px 24px;box-shadow:0 1px 2px rgba(16,19,24,.04),0 12px 30px -20px rgba(16,19,24,.12);animation:finIn .55s cubic-bezier(.2,.7,.3,1) both}
.pf-k:nth-child(1){animation-delay:.06s}.pf-k:nth-child(2){animation-delay:.1s}.pf-k:nth-child(3){animation-delay:.14s}.pf-k:nth-child(4){animation-delay:.18s}
.pf-k .kh{display:flex;align-items:center;gap:8px;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;font-weight:650;margin-bottom:12px}
.pf-k .kh svg{width:15px;height:15px}
.pf-k.i .kh svg{color:var(--ok)}.pf-k.g .kh svg{color:var(--danger)}.pf-k.b .kh svg{color:var(--label)}.pf-k.p .kh svg{color:#3b82f6}
.pf-k .n{font-size:25px;font-weight:770;letter-spacing:-.6px;color:var(--ink-strong)}
.pf-k .n.pos{color:var(--ok)}.pf-k .n.neg{color:var(--danger)}.pf-k .n.pend{color:#3b6fd0}
.pf-k .sub{font-size:11px;color:var(--muted);font-weight:500;margin-top:5px}
/* Tarjetas */
.pf-card{background:#fff;border:1px solid rgba(16,19,24,.05);border-radius:22px;box-shadow:0 1px 2px rgba(16,19,24,.04),0 16px 40px -24px rgba(16,19,24,.18);margin-bottom:22px;animation:finIn .55s cubic-bezier(.2,.7,.3,1) .2s both}
.pf-cap{display:flex;align-items:center;gap:12px;padding:20px 26px 16px;flex-wrap:wrap}
.pf-cap .tt{font-size:15px;font-weight:600;color:var(--ink-strong)}
.pf-cap .sp{flex:1}
.pf-btn{border:none;background:var(--accent);color:#fff;border-radius:10px;padding:9px 15px;font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:filter .12s}
.pf-btn:hover{filter:brightness(1.14)}
.pf-btn.i{background:#e7f6ee;color:#12813f}.pf-btn.g{background:#fdeceb;color:#c0343a}
.pf-btn.ghost{background:var(--soft);color:var(--ink)}
/* Tabla */
.pf-scroll{overflow-x:auto}
.pf-tbl{width:100%;border-collapse:collapse;min-width:620px}
.pf-tbl th{font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--label);font-weight:650;text-align:right;padding:8px 26px 14px;border-bottom:1px solid var(--line2);white-space:nowrap}
.pf-tbl th.l{text-align:left}
.pf-tbl td{padding:18px 26px;border-bottom:1px solid var(--line2);font-size:13.5px;text-align:right;vertical-align:middle;white-space:nowrap}
.pf-tbl td.l{text-align:left}
.pf-tbl tbody tr{transition:background .14s}
.pf-tbl tbody tr:hover{background:#fafbfc}
.pf-tbl tbody tr:last-child td{border-bottom:none}
.pf-cpt{display:flex;align-items:center;gap:10px;min-width:0}
.pf-cpt .t{font-weight:600;color:var(--ink-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pf-chip{font-size:10px;font-weight:700;padding:3px 9px;border-radius:99px;text-decoration:none;white-space:nowrap;flex:none;display:inline-flex;align-items:center;gap:4px}
.pf-chip.fac{background:#eaf1ff;color:#2f61d6}
.pf-chip.doc{background:#eef7f0;color:#12813f}
.pf-chip.man{background:var(--soft);color:var(--label)}
.pf-amt.ing{color:var(--ok);font-weight:700}.pf-amt.gas{color:var(--danger);font-weight:700}
.pf-st{font-size:10.5px;font-weight:700;padding:3px 10px;border-radius:99px;white-space:nowrap}
.pf-sit{font-size:11.5px;font-weight:600}.pf-sit.ok{color:var(--ok)}.pf-sit.pe{color:#c98a12}
.pf-del{border:none;background:none;color:var(--label);cursor:pointer;width:30px;height:30px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;transition:background .12s,color .12s}
.pf-del:hover{background:#fde8e8;color:#c0343a}
.pf-actc{width:52px}
.pf-empty{padding:52px 20px;text-align:center;color:var(--muted)}
.pf-empty svg{width:40px;height:40px;color:#d6d9de;margin-bottom:12px}
/* Fila de alta inline */
.pf-newrow{display:none;padding:14px 26px;border-bottom:1px solid var(--line2);background:#fbfcfd;gap:10px;align-items:center;flex-wrap:wrap}
.pf-newrow.on{display:flex}
.pf-newrow .cap{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;flex:none}
.pf-newrow.i .cap{color:#12813f}.pf-newrow.g .cap{color:#c0343a}
.pf-newrow input{border:1px solid var(--line);border-radius:10px;padding:9px 12px;font-size:13.5px;font-family:inherit;outline:none}
.pf-newrow input.con{flex:1;min-width:160px}
.pf-newrow input.imp{width:120px}
.pf-newrow input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.pf-newrow button{border:none;border-radius:10px;padding:9px 15px;font-size:13px;font-weight:600;cursor:pointer}
.pf-newrow .ok{background:var(--accent);color:#fff}
.pf-newrow .cx{background:var(--soft);color:var(--muted)}
/* Modal vincular */
.pf-ov{position:fixed;inset:0;background:rgba(16,19,24,.42);backdrop-filter:blur(2px);z-index:120;display:none;align-items:flex-start;justify-content:center;padding:60px 16px}
.pf-ov.on{display:flex}
.pf-modal{background:#fff;border-radius:20px;width:560px;max-width:100%;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 30px 80px -20px rgba(16,19,24,.5);animation:finIn .2s ease both}
.pf-mh{display:flex;align-items:center;gap:12px;padding:20px 22px 14px;border-bottom:1px solid var(--line2)}
.pf-mh .tt{font-size:15px;font-weight:600;flex:1}
.pf-mx{border:none;background:var(--soft);width:30px;height:30px;border-radius:8px;cursor:pointer;color:var(--muted);font-size:16px}
.pf-msrch{padding:14px 22px 6px}
.pf-msrch input{width:100%;border:1px solid var(--line);border-radius:11px;padding:11px 14px;font-size:13.5px;font-family:inherit;outline:none}
.pf-msrch input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.pf-mlist{overflow-y:auto;padding:8px 14px 16px}
.pf-vrow{display:flex;align-items:center;gap:10px;width:100%;border:1px solid var(--line2);background:#fff;border-radius:12px;padding:11px 14px;margin-top:8px;cursor:pointer;font-family:inherit;text-align:left;transition:border-color .12s,background .12s}
.pf-vrow:hover:not(.ya){border-color:var(--accent);background:#fafbff}
.pf-vrow.ya{opacity:.6;cursor:default}
.pf-vnum{font-weight:700;font-size:13px;color:var(--ink-strong)}
.pf-vcli{flex:1;font-size:12.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.pf-vst{font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;flex:none}
.pf-vtot{font-weight:700;font-size:13px;flex:none}
.pf-vadd{color:var(--ok);display:inline-flex;flex:none}
.pf-vok{font-size:11px;font-weight:600;color:var(--label);flex:none}
.pf-vmpty{padding:30px 10px;text-align:center;color:var(--muted);font-size:13px}
/* Menú de gestión del proyecto (cabecera) */
.pf-mwrap{position:relative}
.pf-kebab{border:none;background:none;color:var(--label);cursor:pointer;width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;transition:background .12s,color .12s}
.pf-kebab:hover{background:var(--soft);color:var(--ink)}
.pf-menu{position:fixed;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 16px 40px -12px rgba(16,19,24,.32);padding:6px;min-width:200px;z-index:2600;display:none;text-align:left}
.pf-menu.on{display:block;animation:pjm .12s ease}
@keyframes pjm{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.pf-menu button{display:flex;align-items:center;gap:9px;width:100%;border:none;background:none;cursor:pointer;padding:8px 10px;border-radius:8px;font-size:13px;color:var(--ink);font-family:inherit;text-align:left}
.pf-menu button svg{width:15px;height:15px;color:var(--label);flex:none}
.pf-menu button:hover{background:var(--soft)}
.pf-menu button.del{color:#c0343a}.pf-menu button.del svg{color:#c0343a}.pf-menu button.del:hover{background:#fde8e8}
.pf-menu .sep{height:1px;background:var(--line2);margin:5px 4px}
.pf-menu .clbl{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;padding:4px 10px 6px}
.pf-cols{display:flex;gap:7px;flex-wrap:wrap;padding:0 10px 8px}
.pf-cols .cd{width:20px;height:20px;border-radius:50%;cursor:pointer;border:2px solid #fff;box-shadow:0 0 0 1px var(--line);transition:transform .1s}
.pf-cols .cd:hover{transform:scale(1.15)}
/* ---- Modo oscuro: remapea las superficies y textos propios ---- */
[data-theme=dark] body.fin-canvas .main{background-color:var(--bg)}
[data-theme=dark] .co-yr{background-color:var(--soft)}
[data-theme=dark] .co-yr a.on{background-color:var(--card)}
[data-theme=dark] .pf-k,[data-theme=dark] .pf-card,[data-theme=dark] .pf-modal,[data-theme=dark] .pf-vrow{background-color:var(--card);border-color:var(--line)}
[data-theme=dark] .pf-btn,[data-theme=dark] .pf-newrow .ok{color:var(--accent-fg)}
[data-theme=dark] .pf-btn.i{background-color:var(--ok-bg);color:var(--ok)}
[data-theme=dark] .pf-btn.g{background-color:var(--danger-bg);color:var(--danger)}
[data-theme=dark] .pf-tbl tbody tr:hover,[data-theme=dark] .pf-newrow,[data-theme=dark] .pf-vrow:hover:not(.ya),[data-theme=dark] .pf-chip.fac,[data-theme=dark] .pf-chip.doc{background-color:var(--soft)}
[data-theme=dark] .pf-del:hover,[data-theme=dark] .pf-menu button.del:hover{background-color:var(--danger-bg);color:var(--danger)}
[data-theme=dark] .pf-menu{background-color:var(--pop)}
[data-theme=dark] .pf-cols .cd{border-color:var(--card)}
/* ====== MÓVIL (≤640px) ====== */
@media(max-width:640px){
  body.fin-canvas .erp-wrap{padding:20px 14px 60px}
  body.fin-canvas h1{font-size:20px}
  .pf-head h1{font-size:20px;gap:9px}
  .co-yr{max-width:100%;overflow-x:auto;flex-wrap:nowrap}
  .pf-lead{margin-bottom:18px}
  /* KPIs: dos por fila y compactos, sin cajas enormes */
  .pf-kpis{grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px}
  .pf-k{padding:13px 14px;border-radius:14px;box-shadow:none}
  .pf-k .n{font-size:18px}
  /* Cabeceras de tarjeta: botones a lo ancho para el pulgar */
  .pf-cap{padding:16px 16px 12px}
  .pf-cap .pf-btn{flex:1;justify-content:center}
  /* ── Tablas → tarjetas: cada movimiento/factura es una tarjeta con sus datos como
        filas etiqueta·valor. Evita el scroll horizontal y las columnas escondidas. ── */
  .pf-scroll{overflow-x:visible}
  .pf-tbl{min-width:0;display:block}
  .pf-tbl thead{display:none}
  .pf-tbl tbody,.pf-tbl tr,.pf-tbl td{display:block;width:auto}
  .pf-tbl tbody tr{position:relative;border:1px solid var(--line);border-radius:14px;margin:12px 16px;padding:12px 16px}
  .pf-tbl td{padding:6px 0;border:none;text-align:right;white-space:normal}
  .pf-tbl td:empty{display:none}
  .pf-tbl td[data-lbl]{display:flex;align-items:center;justify-content:space-between;gap:14px}
  .pf-tbl td[data-lbl]::before{content:attr(data-lbl);color:var(--label);font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:650;flex:none}
  .pf-tbl td .pf-cpt{justify-content:flex-end;min-width:0;flex-wrap:wrap}
  /* Acción (desvincular): fila propia, alineada a la derecha */
  .pf-actc{text-align:right;padding-top:8px!important}
  /* Altas inline: campos apilados */
  .pf-newrow{padding:14px 16px}
  .pf-newrow input.con{flex:1 1 100%;min-width:0}
  .pf-newrow input.imp{flex:1;width:auto}
  .pf-newrow button{flex:1}
  /* Modal vincular factura: casi pantalla completa */
  .pf-ov{padding:24px 10px}
  .pf-modal{width:100%;max-height:calc(100vh - 48px)}
  .pf-vrow{flex-wrap:wrap}
  .pf-vcli{flex:1 1 100%;order:3}
}
</style>

<div class="pf-wrap">
  <a class="pf-back" href="proyectos.php<?= $histo?'?y=all':($year?('?y='.$year):'') ?>"><?= ic('back',15) ?> Proyectos</a>
  <div class="pf-head">
    <h1><span class="pf-dot" style="background:<?= e($color) ?>"></span><span><?= e($P['nombre']) ?></span><?php if(!$P['activo']): ?> <span style="font-size:11px;color:var(--label);font-weight:700;text-transform:uppercase;letter-spacing:.5px;background:var(--soft);padding:3px 9px;border-radius:99px">archivado</span><?php endif; ?></h1>
    <div class="co-yr">
      <?php foreach($years as $yy): ?><a href="proyecto.php?id=<?= $id ?>&y=<?= $yy ?>" class="<?= (!$histo&&$yy===$year)?'on':'' ?>"><?= $yy ?></a><?php endforeach; ?>
      <a href="proyecto.php?id=<?= $id ?>&y=all" class="<?= $histo?'on':'' ?>">Histórico</a>
    </div>
    <?php if(can_edit()): ?>
    <div class="pf-mwrap">
      <button type="button" class="pf-kebab" onclick="pfMenu(event)" title="Opciones del proyecto"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.9"/><circle cx="12" cy="12" r="1.9"/><circle cx="19" cy="12" r="1.9"/></svg></button>
      <div class="pf-menu" id="pfMenu">
        <button type="button" onclick="pfRename()"><?= ic('pencil',15) ?> Renombrar</button>
        <div class="clbl">Color</div>
        <div class="pf-cols"><?php foreach($PAL as $c): ?><span class="cd" style="background:<?= $c ?>" onclick="pfColor('<?= $c ?>')"></span><?php endforeach; ?></div>
        <div class="sep"></div>
        <form method="post" style="margin:0"><input type="hidden" name="action" value="toggle"><input type="hidden" name="y" value="<?= $histo?'all':$year ?>"><button type="submit"><?= $P['activo']?ic('folder',15).' Archivar':ic('check',15).' Activar' ?></button></form>
        <form method="post" style="margin:0" onsubmit="return erpSubmitAsk(this,'¿Borrar el proyecto «'+<?= htmlspecialchars(json_encode($P['nombre']),ENT_QUOTES) ?>+'»? Los movimientos y facturas quedan sin proyecto (no se borran).',{ok:'Borrar proyecto',danger:true})"><input type="hidden" name="action" value="del"><button type="submit" class="del"><?= ic('trash',15) ?> Borrar proyecto</button></form>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <p class="pf-lead">Rentabilidad de este proyecto (<?= $yLabel ?>). Los movimientos salen de la caja; las facturas sin cobrar se muestran aparte como pendiente. Nada de esto aparece en las facturas del cliente.</p>

  <div class="pf-kpis">
    <div class="pf-k i"><div class="kh"><?= ic('trend',15) ?> Ingresos</div><div class="n"><?= eur($bal['ing']) ?></div></div>
    <div class="pf-k g"><div class="kh"><?= ic('euro',15) ?> Costes</div><div class="n"><?= eur($bal['gas']) ?></div></div>
    <div class="pf-k b"><div class="kh"><?= ic('chart',15) ?> Balance</div><div class="n <?= $bal['ben']>=0?'pos':'neg' ?>"><?= eur($bal['ben']) ?></div></div>
    <div class="pf-k p"><div class="kh"><?= ic('clock',15) ?> Pendiente</div><div class="n pend"><?= eur($pend) ?></div><div class="sub">facturas sin cobrar</div></div>
  </div>

  <!-- Movimientos -->
  <div class="pf-card">
    <div class="pf-cap">
      <span class="tt">Movimientos de caja</span>
      <span class="sp"></span>
      <?php if(can_edit()): ?>
      <button type="button" class="pf-btn i" onclick="pfNew('i')"><?= ic('plus',14) ?> Añadir ingreso</button>
      <button type="button" class="pf-btn g" onclick="pfNew('g')"><?= ic('plus',14) ?> Añadir gasto</button>
      <?php endif; ?>
    </div>
    <?php if(can_edit()): ?>
    <form method="post" class="pf-newrow i" id="pfRowI"><input type="hidden" name="action" value="add_mov"><input type="hidden" name="tipo" value="ingreso"><input type="hidden" name="y" value="<?= e($yStr) ?>"><?= csrf_field() ?>
      <span class="cap">Ingreso</span>
      <input type="text" class="con" name="concepto" placeholder="Concepto (ej: Anticipo cliente)…" autocomplete="off">
      <input type="text" class="imp" name="importe" placeholder="0,00 €" autocomplete="off">
      <input type="date" name="fecha" value="<?= date('Y-m-d') ?>">
      <button type="submit" class="ok">Guardar</button>
      <button type="button" class="cx" onclick="pfNew('i',false)">Cancelar</button>
    </form>
    <form method="post" class="pf-newrow g" id="pfRowG"><input type="hidden" name="action" value="add_mov"><input type="hidden" name="tipo" value="gasto"><input type="hidden" name="y" value="<?= e($yStr) ?>"><?= csrf_field() ?>
      <span class="cap">Gasto</span>
      <input type="text" class="con" name="concepto" placeholder="Concepto (ej: Licencia, subcontrata)…" autocomplete="off">
      <input type="text" class="imp" name="importe" placeholder="0,00 €" autocomplete="off">
      <input type="date" name="fecha" value="<?= date('Y-m-d') ?>">
      <button type="submit" class="ok">Guardar</button>
      <button type="button" class="cx" onclick="pfNew('g',false)">Cancelar</button>
    </form>
    <?php endif; ?>

    <?php if(!$movs): ?>
      <div class="pf-empty"><?= ic('layers',40) ?><div><b>Sin movimientos</b><br>Añade un ingreso o un gasto, o vincula una factura cobrada.</div></div>
    <?php else: ?>
    <div class="pf-scroll">
    <table class="pf-tbl">
      <thead><tr><th class="l">Fecha</th><th class="l">Concepto</th><th class="l">Ámbito</th><th>Importe</th><?php if(can_edit()): ?><th class="pf-actc"></th><?php endif; ?></tr></thead>
      <tbody>
        <?php foreach($movs as $m): $ing=($m['tipo']==='ingreso');
          /* Destino al hacer clic en la fila: la factura, o el documento del gasto. */
          $mvUrl = !empty($m['factura_numero']) ? 'facturas.php?v='.(int)$m['invoice_id']
                 : (!empty($m['doc_file']) ? '../archivo.php?d=facturas&f='.urlencode($m['doc_file']) : '');
        ?>
        <tr class="pf-row"<?php if($mvUrl): ?> data-href="<?= e($mvUrl) ?>" style="cursor:pointer"<?php endif; ?> onclick="pfRowGo(event,this)" oncontextmenu="return pfRowMenu(event,this)">
          <td class="l" style="color:var(--muted)" data-lbl="Fecha"><?= e(date('d/m/Y', strtotime($m['fecha']))) ?></td>
          <td class="l" data-lbl="Concepto"><div class="pf-cpt">
            <span class="t"><?= e($m['concepto']?:'—') ?></span>
            <?php if(!empty($m['factura_numero'])): ?>
              <a class="pf-chip fac" href="facturas.php?v=<?= (int)$m['invoice_id'] ?>"><?= ic('file',12) ?> Factura nº <?= e($m['factura_numero']) ?></a>
            <?php elseif(!empty($m['doc_file'])): ?>
              <a class="pf-chip doc" href="../archivo.php?d=facturas&f=<?= urlencode($m['doc_file']) ?>&dl=1"><?= ic('download',12) ?> Documento</a>
            <?php else: ?>
              <span class="pf-chip man">Manual</span>
            <?php endif; ?>
          </div></td>
          <td class="l" style="color:var(--muted);font-size:12.5px" data-lbl="Ámbito"><?= e($m['ambito']?:'—') ?></td>
          <td class="pf-amt <?= $ing?'ing':'gas' ?>" data-lbl="Importe"><?= ($ing?'+':'−').eur((float)$m['importe']) ?></td>
          <?php if(can_edit()): ?>
          <td class="pf-actc">
            <form method="post" style="margin:0" data-unlink onsubmit="return erpSubmitAsk(this,'¿Desvincular este movimiento del proyecto? El apunte de caja se conserva, solo deja de contar aquí.',{ok:'Desvincular'})"><input type="hidden" name="action" value="unlink_acc"><input type="hidden" name="acc_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="y" value="<?= e($yStr) ?>"><?= csrf_field() ?><button type="submit" class="pf-del" title="Desvincular"><?= ic('link',15) ?></button></form>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Facturas vinculadas -->
  <div class="pf-card">
    <div class="pf-cap">
      <span class="tt">Facturas vinculadas</span>
      <span class="sp"></span>
      <?php if(can_edit()): ?><button type="button" class="pf-btn ghost" onclick="pfOpenVinc()"><?= ic('link',14) ?> Vincular factura</button><?php endif; ?>
    </div>
    <?php if(!$facs): ?>
      <div class="pf-empty"><?= ic('file',40) ?><div><b>Sin facturas vinculadas</b><br>Vincula una factura de venta para seguir su cobro desde aquí.</div></div>
    <?php else: ?>
    <div class="pf-scroll">
    <table class="pf-tbl">
      <thead><tr><th class="l">Nº</th><th class="l">Cliente</th><th class="l">Fecha</th><th class="l">Estado</th><th>Total</th><th class="l">Situación</th><?php if(can_edit()): ?><th class="pf-actc"></th><?php endif; ?></tr></thead>
      <tbody>
        <?php foreach($facs as $f): $ev=$ESTADOS[$f['estado']]??$ESTADOS['borrador']; ?>
        <tr class="pf-row" data-href="facturas.php?v=<?= (int)$f['id'] ?>" style="cursor:pointer" onclick="pfRowGo(event,this)" oncontextmenu="return pfRowMenu(event,this)">
          <td class="l" data-lbl="Nº"><a href="facturas.php?v=<?= (int)$f['id'] ?>" style="font-weight:700;color:var(--ink-strong);text-decoration:none"><?= e($f['numero']?:('#'.$f['id'])) ?></a></td>
          <td class="l" style="color:var(--ink)" data-lbl="Cliente"><?= e($f['cliente_nombre']?:'—') ?></td>
          <td class="l" style="color:var(--muted)" data-lbl="Fecha"><?= $f['fecha']?e(date('d/m/Y',strtotime($f['fecha']))):'—' ?></td>
          <td class="l" data-lbl="Estado"><span class="pf-st" style="background:<?= e($ev[1]) ?>1f;color:<?= e($ev[1]) ?>"><?= e($ev[0]) ?></span></td>
          <td style="font-weight:700" data-lbl="Total"><?= eur((float)$f['total']) ?></td>
          <td class="l" data-lbl="Situación"><?php if($f['en_caja']): ?><span class="pf-sit ok"><?= ic('check',13) ?> En caja</span><?php else: ?><span class="pf-sit pe"><?= ic('clock',13) ?> Pendiente</span><?php endif; ?></td>
          <?php if(can_edit()): ?>
          <td class="pf-actc">
            <form method="post" style="margin:0" data-unlink onsubmit="return erpSubmitAsk(this,'¿Desvincular esta factura del proyecto?',{ok:'Desvincular'})"><input type="hidden" name="action" value="unlink_invoice"><input type="hidden" name="invoice_id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="y" value="<?= e($yStr) ?>"><?= csrf_field() ?><button type="submit" class="pf-del" title="Desvincular"><?= ic('link',15) ?></button></form>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
/* Filas de la ficha: clic → abre el movimiento (factura o documento); clic derecho
   → menú con "Abrir" y "Desvincular". Disponible también en solo-lectura. */
function pfRowGo(e,row){ if(e.target.closest('a,button,form,input,.pf-actc'))return; var h=row.getAttribute('data-href'); if(h)location.href=h; }
var pfCtxEl=null;
function pfCloseCtx(){ if(pfCtxEl){pfCtxEl.remove();pfCtxEl=null;} }
document.addEventListener('click',pfCloseCtx);
window.addEventListener('scroll',pfCloseCtx,true);
window.addEventListener('resize',pfCloseCtx);
function pfRowMenu(e,row){ if(e){e.preventDefault();e.stopPropagation();} pfCloseCtx();
  var href=row.getAttribute('data-href'); var form=row.querySelector('form[data-unlink]');
  if(!href&&!form)return false;
  var EYE='<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
  var LNK='<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg>';
  var m=document.createElement('div'); m.className='pf-menu on';
  if(href){ var lbl=href.indexOf('archivo')>=0?'Ver documento':(href.indexOf('v=')>=0?'Ver factura':'Abrir');
    var b=document.createElement('button'); b.type='button'; b.innerHTML=EYE+' '+lbl; b.onclick=function(){location.href=href;}; m.appendChild(b); }
  if(form){ var d=document.createElement('button'); d.type='button'; d.className='del'; d.innerHTML=LNK+' Desvincular';
    d.onclick=function(){ pfCloseCtx(); if(form.requestSubmit)form.requestSubmit(); else form.submit(); }; m.appendChild(d); }
  document.body.appendChild(m); pfCtxEl=m; m.style.position='fixed';
  var mw=m.offsetWidth,mh=m.offsetHeight;
  m.style.left=Math.max(8,Math.min(e.clientX,window.innerWidth-mw-8))+'px';
  m.style.top =Math.max(8,Math.min(e.clientY,window.innerHeight-mh-8))+'px';
  return false;
}
</script>

<?php if(can_edit()): ?>
<!-- Modal: vincular factura -->
<div class="pf-ov" id="pfOv" onclick="if(event.target===this)pfCloseVinc()">
  <div class="pf-modal">
    <div class="pf-mh"><span class="tt">Vincular factura</span><button type="button" class="pf-mx" onclick="pfCloseVinc()">✕</button></div>
    <div class="pf-msrch"><input type="text" id="pfSrch" placeholder="Buscar por número o cliente…" autocomplete="off" oninput="pfSearch(this.value)"></div>
    <div class="pf-mlist" id="pfList"><?php pf_render_vinc($vinc, $ESTADOS, $id, $yStr); ?></div>
  </div>
</div>
<script>
var PF_ID=<?= (int)$id ?>, PF_Y=<?= json_encode($yStr) ?>;
function pfNew(k,show){
  if(show===undefined)show=true;
  var r=document.getElementById(k==='i'?'pfRowI':'pfRowG'); if(!r)return;
  r.classList.toggle('on',show);
  if(show){var i=r.querySelector('input.con'); if(i)i.focus();}
}
function pfOpenVinc(){document.getElementById('pfOv').classList.add('on');var s=document.getElementById('pfSrch');if(s){s.value='';s.focus();}}
function pfCloseVinc(){document.getElementById('pfOv').classList.remove('on');}
document.addEventListener('keydown',function(e){if(e.key==='Escape')pfCloseVinc();});
var pfT=null;
function pfSearch(q){
  clearTimeout(pfT);
  pfT=setTimeout(function(){
    fetch('proyecto.php?id='+PF_ID+'&frag=vinc&q='+encodeURIComponent(q))
      .then(function(r){return r.text();})
      .then(function(h){document.getElementById('pfList').innerHTML=h;})
      .catch(function(){toast('No se pudo buscar','err');});
  },200);
}
function pfLink(invId){
  erpPost('proyecto.php',{action:'link_invoice',invoice_id:invId,id:PF_ID,y:PF_Y});
}
/* Menú de gestión del proyecto (renombrar · color · archivar · borrar). */
function pfMenu(e){ if(e){e.preventDefault&&e.preventDefault();e.stopPropagation&&e.stopPropagation();}
  var m=document.getElementById('pfMenu'); if(!m)return;
  if(m.classList.contains('on')){m.classList.remove('on');return;}
  if(m.parentElement!==document.body)document.body.appendChild(m);
  m.classList.add('on');
  var r=e.currentTarget.getBoundingClientRect(); var mw=m.offsetWidth,mh=m.offsetHeight;
  m.style.left=Math.max(8,Math.min(r.right-mw,window.innerWidth-mw-8))+'px';
  m.style.top =Math.max(8,Math.min(r.bottom+6,window.innerHeight-mh-8))+'px';
}
document.addEventListener('click',function(e){ if(!e.target.closest('#pfMenu')&&!e.target.closest('.pf-kebab')){var m=document.getElementById('pfMenu');if(m)m.classList.remove('on');} });
function pfRename(){ erpPrompt('Renombrar proyecto',<?= json_encode($P['nombre'], JSON_UNESCAPED_UNICODE) ?>,{placeholder:'Nombre del proyecto'}).then(function(n){if(!n)return;document.getElementById('pfrName').value=n;document.getElementById('pfRenameForm').submit();}); }
function pfColor(c){ document.getElementById('pfcColor').value=c; document.getElementById('pfColorForm').submit(); }
</script>
<form id="pfRenameForm" method="post" style="display:none"><input type="hidden" name="action" value="rename"><input type="hidden" name="nombre" id="pfrName"><input type="hidden" name="y" value="<?= $histo?'all':$year ?>"></form>
<form id="pfColorForm" method="post" style="display:none"><input type="hidden" name="action" value="color"><input type="hidden" name="color" id="pfcColor"><input type="hidden" name="y" value="<?= $histo?'all':$year ?>"></form>
<?php endif; ?>
<?php erp_foot(); ?>
