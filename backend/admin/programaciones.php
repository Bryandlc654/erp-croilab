<?php
/* Programaciones de facturas mensuales (Finanzas). Gabi/Víctor asignan facturas
   recurrentes a clientes y se generan mes a mes en el día indicado. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/fin_prog.php';
prog_ensure();

/* Quién factura se configura en Ajustes › Facturación; lo sirve fin_prog.php. */
$EMIS = fin_emisores();
$clients = db()->query('SELECT id, name, fact_nombre, fact_nif, fact_dir, fact_email, fact_tel FROM clients ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD']==='POST' && can_edit()) {
  $a=$_POST['action']??'';
  if ($a==='save') {
    $id=(int)($_POST['id']??0);
    $lin=[]; $con=$_POST['it_concepto']??[]; $can=$_POST['it_cant']??[]; $pre=$_POST['it_precio']??[];
    /* Mismo criterio que en facturas.php: la cantidad y el precio se leen tal y
       como los escribe una persona («1.234,56»), no con un (float) a secas. */
    foreach($con as $i=>$c){ $c=trim($c); if($c!=='') $lin[]=['c'=>$c,'q'=>(float)num_es($can[$i]??1,false),'p'=>(float)num_es($pre[$i]??0,false)]; }
    $f=[
      'emisor'=>fin_emisor_ok($_POST['emisor']??''),
      'serie'=>trim($_POST['serie']??''),
      'client_id'=>($_POST['client_id']??'')!==''?(int)$_POST['client_id']:null,
      'cliente_nombre'=>trim($_POST['cliente_nombre']??''),
      'cliente_nif'=>trim($_POST['cliente_nif']??''),
      'cliente_dir'=>trim($_POST['cliente_dir']??''),
      'cliente_email'=>trim($_POST['cliente_email']??''),
      'cliente_tel'=>trim($_POST['cliente_tel']??''),
      'lineas_json'=>json_encode($lin,JSON_UNESCAPED_UNICODE),
      'iva_pct'=>(float)($_POST['iva_pct']??21),
      'irpf_pct'=>(float)($_POST['irpf_pct']??0),
      'cond_pago'=>trim($_POST['cond_pago']??'Contado'),
      'dia'=>max(1,min(28,(int)($_POST['dia']??1))),
      'activo'=>isset($_POST['activo'])?1:0,
      'start_ym'=>preg_match('/^\d{4}-\d{2}$/',$_POST['start_ym']??'')?$_POST['start_ym']:date('Y-m'),
    ];
    if ($f['cliente_nombre']==='' && $f['client_id']) { foreach($clients as $c){ if((int)$c['id']===$f['client_id']) $f['cliente_nombre']=$c['name']; } }
    /* Los datos fiscales del cliente solo se ACTUALIZAN con lo que venga escrito.
       Antes se copiaba el formulario entero, así que guardar una programación
       dejando en blanco el NIF o la dirección los borraba de la ficha del cliente. */
    if ($f['client_id']) {
      $mapFis = ['fact_nombre'=>$f['cliente_nombre'],'fact_nif'=>$f['cliente_nif'],'fact_dir'=>$f['cliente_dir'],'fact_email'=>$f['cliente_email'],'fact_tel'=>$f['cliente_tel']];
      $setF=[]; $valF=[];
      foreach($mapFis as $col=>$val){ if(trim((string)$val)!==''){ $setF[]="$col=?"; $valF[]=$val; } }
      if ($setF) { $valF[]=$f['client_id']; db()->prepare('UPDATE clients SET '.implode(',',$setF).' WHERE id=?')->execute($valF); }
    }
    if ($id) { $set=implode(', ',array_map(fn($k)=>"$k=:$k",array_keys($f))); $p=$f;$p['id']=$id; db()->prepare("UPDATE invoice_schedules SET $set WHERE id=:id")->execute($p); }
    else { $cols=implode(',',array_keys($f)); $ph=implode(',',array_map(fn($k)=>":$k",array_keys($f))); db()->prepare("INSERT INTO invoice_schedules ($cols) VALUES ($ph)")->execute($f); }
    header('Location: programaciones.php'); exit;
  } elseif ($a==='del') { db()->prepare('DELETE FROM invoice_schedules WHERE id=?')->execute([(int)($_POST['id']??0)]); header('Location: programaciones.php'); exit; }
  elseif ($a==='toggle') { db()->prepare('UPDATE invoice_schedules SET activo=1-activo WHERE id=?')->execute([(int)($_POST['id']??0)]); header('Location: programaciones.php'); exit; }
  elseif ($a==='run') { $n=prog_run(); header('Location: programaciones.php?gen='.$n); exit; }
}

/* Antes se llamaba a prog_run() aquí, así que ABRIR la página (un GET) emitía
   facturas de verdad: un F5, una precarga del navegador o un rastreador podían
   duplicar emisiones (P1-06). Ahora la emisión solo la hacen el cron y el botón
   «Generar ahora» (acción POST con CSRF). La carga es de solo lectura. */
$autoGen = 0;

$edit = isset($_GET['edit']) ? (int)$_GET['edit'] : (isset($_GET['new'])?-1:0);
$sc=['id'=>0,'emisor'=>fin_emisor_ok(''),'serie'=>'','client_id'=>'','cliente_nombre'=>'','cliente_nif'=>'','cliente_dir'=>'','cliente_email'=>'','cliente_tel'=>'','lineas_json'=>'[]','iva_pct'=>'21','irpf_pct'=>'7','cond_pago'=>'Contado','dia'=>1,'activo'=>1,'start_ym'=>date('Y-m')];
if ($edit>0) { $q=db()->prepare('SELECT * FROM invoice_schedules WHERE id=?'); $q->execute([$edit]); $sc=$q->fetch()?:$sc; }

$rows = db()->query("SELECT s.*, (SELECT COUNT(*) FROM invoices i WHERE i.client_id=s.client_id) nfact FROM invoice_schedules s ORDER BY activo DESC, id DESC")->fetchAll();
erp_head('prog', 'Programaciones');
?>
<style>
.pg-wrap{max-width:none;width:100%}
.pg-head{display:flex;align-items:center;gap:12px;margin-bottom:16px}.pg-head h1{flex:1;font-size:24px}
.pg-note{background:#eef6ff;border:1px solid #d3e6fb;color:#1d4ed8;border-radius:12px;padding:14px 18px;font-size:13px;line-height:1.55;margin-bottom:22px}
.pg-card{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
.pg-row{display:grid;grid-template-columns:22px minmax(200px,1.6fr) 1fr 110px 90px 130px 220px;gap:14px;align-items:center;padding:19px 24px;border-bottom:1px solid var(--line)}
.pg-row:last-child{border-bottom:none}.pg-row:hover:not(.h){background:#fafbfc}
.pg-row.h{background:#fbfbfc;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:650}
.pg-cli{font-weight:650;color:var(--ink-strong);font-size:14.5px}
.pg-sub{font-size:11.5px;color:var(--muted);margin-top:4px;line-height:1.5}
.pg-money{font-size:15px;font-weight:750;color:var(--ink-strong)}
.pg-money small{display:block;font-size:10.5px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.3px}
.pg-dot{width:10px;height:10px;border-radius:50%}
.pg-acts a,.pg-acts button{border:none;background:none;color:var(--muted);cursor:pointer;font-size:12.5px;font-weight:600;padding:5px 9px;border-radius:8px}
.pg-acts a:hover,.pg-acts button:hover{background:var(--soft);color:var(--ink)}
.pg-empty{padding:54px;text-align:center;color:var(--muted)}
.fe-layout2{max-width:900px}
/* form */
.fe-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px 26px;margin-bottom:18px}
.fe-card h3{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;font-weight:650;margin-bottom:18px}
.fe-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}.fe-grid.g2{grid-template-columns:1fr 1fr}
.fe-grid .full{grid-column:1/-1}
.fe-f label{font-size:12px;color:var(--muted);font-weight:600;display:block;margin-bottom:6px}
.fe-f input,.fe-f select{width:100%;border:1px solid var(--line);border-radius:9px;padding:9px 11px;font-size:13px;font-family:inherit;outline:none;box-sizing:border-box}
.li-head,.li-row{display:grid;grid-template-columns:1fr 72px 100px 30px;gap:8px;align-items:center}
.li-head{font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:650;padding:0 2px 10px}
.li-row{margin-bottom:8px}.li-row input{border:1px solid var(--line);border-radius:9px;padding:8px 10px;font-size:13px;font-family:inherit;width:100%;outline:none}
.li-del{border:none;background:none;color:var(--label);cursor:pointer;font-size:14px;border-radius:7px;padding:6px}.li-del:hover{background:#fde8e8;color:#c0392b}
.fe-add{border:1px dashed #d4d8de;background:#fff;border-radius:10px;padding:9px;width:100%;cursor:pointer;color:var(--accent);font-weight:600;font-size:13px}
.fe-tg{display:inline-flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;font-weight:500}
[data-theme=dark] .pg-note{background-color:var(--soft);border-color:var(--line);color:var(--ink)}
[data-theme=dark] .pg-card{background-color:var(--card)}
[data-theme=dark] .pg-row:hover:not(.h){background-color:var(--soft)}
[data-theme=dark] .pg-row.h{background-color:var(--soft)}
[data-theme=dark] .fe-card{background-color:var(--card)}
[data-theme=dark] .fe-f input,[data-theme=dark] .fe-f select,[data-theme=dark] .li-row input{background-color:var(--field);color:var(--ink)}
[data-theme=dark] .li-del{color:var(--muted)}
[data-theme=dark] .li-del:hover{background-color:var(--danger-bg);color:var(--danger)}
[data-theme=dark] .fe-add{background-color:var(--card);border-color:var(--line-strong)}
/* ---- Móvil (teléfono) ---- */
@media(max-width:640px){
  .pg-head{flex-wrap:wrap;gap:10px}
  .pg-head h1{flex:1 1 100%;font-size:21px}
  .pg-head .btn,.pg-head form{flex:1 1 auto}
  .pg-head form .btn{width:100%}
  .pg-card{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .pg-row{min-width:780px}
  .fe-grid,.fe-grid.g2{grid-template-columns:1fr}
  .fe-card{padding:20px 16px}
}
</style>
<div class="pg-wrap">
  <div class="pg-head">
    <h1>Programaciones</h1>
    <?php if(can_edit()): ?>
    <form method="post" style="margin:0"><input type="hidden" name="action" value="run"><button class="btn ghost sm" type="submit"><?= ic('bolt',14) ?> Generar ahora</button></form>
    <a class="btn" href="programaciones.php?new=1"><?= ic('plus',16) ?> Nueva programación</a>
    <?php endif; ?>
  </div>
  <div class="pg-note">Cada mes, en el día que indiques, el sistema genera automáticamente la factura del cliente (lo hace el cron). También puedes emitir lo pendiente a mano con «Generar ahora». <?php if(isset($_GET['gen'])): ?><b>Se generaron <?= (int)$_GET['gen'] ?> factura(s).</b><?php endif; ?></div>

  <?php if($edit!==0 && can_edit()): $lin=json_decode($sc['lineas_json'],true); if(!is_array($lin))$lin=[]; ?>
  <form method="post">
    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$sc['id'] ?>">
    <div class="fe-card">
      <h3><?= $edit>0?'Editar programación':'Nueva programación' ?></h3>
      <div class="fe-grid">
        <div class="fe-f"><label>Emisor</label><select name="emisor"><?php foreach($EMIS as $k=>$v): ?><option value="<?= e($k) ?>" <?= $sc['emisor']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="fe-f"><label>Serie (prefijo)</label><input type="text" name="serie" value="<?= e($sc['serie']??'') ?>" placeholder="Ej: F, SE-, 2026-"></div>
        <div class="fe-f"><label>Cliente</label><select name="client_id" onchange="pgClient(this)"><option value="">— Manual —</option><?php foreach($clients as $c): $cHas=trim($c['fact_nombre']??'')!==''; ?><option value="<?= (int)$c['id'] ?>" data-name="<?= e($c['name']) ?>" data-has="<?= $cHas?1:0 ?>" data-fnombre="<?= e($c['fact_nombre']) ?>" data-nif="<?= e($c['fact_nif']) ?>" data-dir="<?= e($c['fact_dir']) ?>" data-email="<?= e($c['fact_email']) ?>" data-tel="<?= e($c['fact_tel']) ?>" <?= (int)$sc['client_id']===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?><?= $cHas?'':' — sin datos' ?></option><?php endforeach; ?></select></div>
        <div class="fe-f"><label>Día de emisión (1-28)</label><input type="number" name="dia" min="1" max="28" value="<?= (int)$sc['dia'] ?>"></div>
        <div class="fe-f"><label>Nombre / razón social</label><input type="text" name="cliente_nombre" id="pgNombre" value="<?= e($sc['cliente_nombre']) ?>"></div>
        <div class="fe-f"><label>NIF / CIF</label><input type="text" name="cliente_nif" id="pgNif" value="<?= e($sc['cliente_nif']) ?>"></div>
        <div class="fe-f"><label>Teléfono</label><input type="text" name="cliente_tel" id="pgTel" value="<?= e($sc['cliente_tel']) ?>"></div>
        <div class="fe-f"><label>Email</label><input type="text" name="cliente_email" id="pgEmail" value="<?= e($sc['cliente_email']) ?>"></div>
        <div class="fe-f full"><label>Dirección</label><input type="text" name="cliente_dir" id="pgDir" value="<?= e($sc['cliente_dir']) ?>"></div>
      </div>
      <div id="pgWarn" style="display:none;margin-top:12px;background:#fff4e5;border:1px solid #f3dcbf;color:#8a5a12;border-radius:10px;padding:11px 14px;font-size:12.5px">Este cliente aún no tiene datos de facturación. Rellénalos abajo (se guardarán en su ficha al guardar la programación) o edítalos en <a href="fin-ajustes.php" style="color:#8a5a12;font-weight:700">Facturación de clientes</a>.</div>
      <div style="margin-top:14px"><div class="li-head"><span>Concepto</span><span>Cant.</span><span>Precio</span><span></span></div><div id="pgLines"></div><button type="button" class="fe-add" onclick="pgAdd()">＋ Añadir línea</button></div>
      <div class="fe-grid" style="margin-top:14px">
        <div class="fe-f"><label>IVA %</label><input type="number" name="iva_pct" value="<?= e($sc['iva_pct']) ?>" step="0.01"></div>
        <div class="fe-f"><label>IRPF %</label><input type="number" name="irpf_pct" value="<?= e($sc['irpf_pct']) ?>" step="0.01"></div>
        <div class="fe-f"><label>Vencimiento (texto)</label><input type="text" name="cond_pago" value="<?= e($sc['cond_pago']) ?>" placeholder="Contado"></div>
        <div class="fe-f"><label>Empezar en (mes)</label><input type="text" name="start_ym" value="<?= e($sc['start_ym']) ?>" placeholder="<?= date('Y-m') ?>"></div>
        <div class="fe-f" style="display:flex;align-items:flex-end"><label class="fe-tg"><input type="checkbox" name="activo" <?= $sc['activo']?'checked':'' ?>> Activa</label></div>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px"><a class="btn ghost" href="programaciones.php">Cancelar</a><button class="btn" type="submit">Guardar programación</button></div>
    </div>
  </form>
  <script>
  var PG_LINES=<?= json_encode($lin,JSON_UNESCAPED_UNICODE) ?>;
  function pgClient(sel){var o=sel.options[sel.selectedIndex];if(!o)return;var nm=o.dataset.fnombre||o.dataset.name;if(nm)document.getElementById('pgNombre').value=nm;
    function s(id,v){var el=document.getElementById(id);if(el&&v)el.value=v;}s('pgNif',o.dataset.nif);s('pgDir',o.dataset.dir);s('pgEmail',o.dataset.email);s('pgTel',o.dataset.tel);
    var w=document.getElementById('pgWarn');if(w)w.style.display=(o.value&&o.dataset.has==='0')?'block':'none';}
  function pgAdd(c,q,p){var box=document.getElementById('pgLines');var row=document.createElement('div');row.className='li-row';
    var ci=document.createElement('input');ci.type='text';ci.name='it_concepto[]';ci.placeholder='Concepto…';ci.value=c||'';
    var qi=document.createElement('input');qi.type='number';qi.name='it_cant[]';qi.step='0.01';qi.value=(q!=null?q:1);
    var pi=document.createElement('input');pi.type='number';pi.name='it_precio[]';pi.step='0.01';pi.value=(p!=null?p:0);
    var x=document.createElement('button');x.type='button';x.className='li-del';x.textContent='✕';x.onclick=function(){row.remove();};
    row.appendChild(ci);row.appendChild(qi);row.appendChild(pi);row.appendChild(x);box.appendChild(row);}
  if(PG_LINES.length)PG_LINES.forEach(function(l){pgAdd(l.c,l.q,l.p);});else pgAdd();
  (function(){var ps=document.querySelector('[name=client_id]');if(ps&&ps.value)pgClient(ps);})();
  </script>
  <?php endif; ?>

  <div class="pg-card">
    <div class="pg-row h"><span></span><span>Cliente</span><span>Concepto</span><span>Emisor</span><span>Día</span><span style="text-align:right">Total / mes</span><span></span></div>
    <?php if(!$rows): ?><div class="pg-empty">Sin programaciones. <?php if(can_edit()): ?><a href="programaciones.php?new=1" style="color:var(--accent);font-weight:600">Crea la primera →</a><?php endif; ?></div><?php endif; ?>
    <?php foreach($rows as $s): $lin=json_decode($s['lineas_json'],true); if(!is_array($lin))$lin=[]; $base=0; foreach($lin as $l)$base+=((float)($l['q']??1))*((float)($l['p']??0)); $totmes=$base*(1+((float)$s['iva_pct'])/100-((float)$s['irpf_pct'])/100); $c1=count($lin)?($lin[0]['c']??''):''; ?>
      <div class="pg-row">
        <span class="pg-dot" style="background:<?= $s['activo']?'#12a150':'#cfd2d6' ?>" title="<?= $s['activo']?'Activa':'Pausada' ?>"></span>
        <div><div class="pg-cli"><?= e($s['cliente_nombre']?:'—') ?></div><div class="pg-sub">IVA <?= (float)$s['iva_pct'] ?>% · IRPF <?= (float)$s['irpf_pct'] ?>% · <?= e($s['cond_pago']?:'Contado') ?> · última: <?= $s['last_ym']?e($s['last_ym']):'—' ?></div></div>
        <span style="font-size:12.5px;color:var(--ink);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($c1?:'—') ?><?php if(count($lin)>1): ?> <span style="color:var(--muted)">+<?= count($lin)-1 ?></span><?php endif; ?></span>
        <span style="font-size:12.5px"><?= e(fin_emisor_nombre($s['emisor'])) ?></span>
        <span style="font-size:12.5px">día <?= (int)$s['dia'] ?></span>
        <span class="pg-money" style="text-align:right"><?= eur($totmes) ?><small>base <?= eur($base) ?></small></span>
        <span class="pg-acts" style="text-align:right">
          <?php if(can_edit()): ?>
          <a href="programaciones.php?edit=<?= (int)$s['id'] ?>">Editar</a>
          <form method="post" style="display:inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button type="submit"><?= $s['activo']?'Pausar':'Activar' ?></button></form>
          <form method="post" style="display:inline" onsubmit="return erpSubmitAsk(this,'¿Borrar programación?')"><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button type="submit" style="color:#c0392b">Borrar</button></form>
          <?php endif; ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php erp_foot(); ?>
