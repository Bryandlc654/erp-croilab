<?php
/* Proyectos (uso interno): asigna facturas y gastos a un proyecto y mira su rentabilidad. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';

db()->exec("CREATE TABLE IF NOT EXISTS projects (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(160) NOT NULL, color VARCHAR(16) DEFAULT '#2f6df6', client_id INT DEFAULT NULL, activo TINYINT DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try{ if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='accounting' AND column_name='project_id'")->fetchColumn()) db()->exec("ALTER TABLE accounting ADD COLUMN project_id INT DEFAULT NULL"); }catch(Exception $e){}

$PAL = ['#2f6df6','#12a150','#e0a341','#e05a4f','#8b5cf6','#0ea5a5','#eb5a9a','#f59e0b','#14b8a6','#a855f7'];

if ($_SERVER['REQUEST_METHOD']==='POST' && can_edit()) {
  $a=$_POST['action']??'';
  if ($a==='add') {
    $n=trim($_POST['nombre']??''); $c=trim($_POST['color']??'#2f6df6');
    if($n!=='') db()->prepare('INSERT INTO projects (nombre,color) VALUES (?,?)')->execute([$n,$c!==''?$c:'#2f6df6']);
  } elseif ($a==='rename') {
    db()->prepare('UPDATE projects SET nombre=? WHERE id=?')->execute([trim($_POST['nombre']??''),(int)($_POST['id']??0)]);
  } elseif ($a==='color') {
    db()->prepare('UPDATE projects SET color=? WHERE id=?')->execute([trim($_POST['color']??'#2f6df6'),(int)($_POST['id']??0)]);
  } elseif ($a==='toggle') {
    db()->prepare('UPDATE projects SET activo=1-activo WHERE id=?')->execute([(int)($_POST['id']??0)]);
  } elseif ($a==='del') {
    $id=(int)($_POST['id']??0);
    db()->prepare('UPDATE accounting SET project_id=NULL WHERE project_id=?')->execute([$id]);
    db()->prepare('UPDATE invoices SET project_id=NULL WHERE project_id=?')->execute([$id]);
    db()->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
  }
  header('Location: proyectos.php'.((($_POST['y']??'')!=='')?'?y='.$_POST['y']:'')); exit;
}

$yParam = $_GET['y'] ?? (string)date('Y');
$histo = ($yParam==='all');
$year = $histo ? 0 : (int)$yParam;
$years=[]; foreach(db()->query('SELECT DISTINCT YEAR(fecha) y FROM accounting WHERE fecha IS NOT NULL ORDER BY y DESC') as $r) $years[]=(int)$r['y'];
if(!in_array((int)date('Y'),$years,true)) array_unshift($years,(int)date('Y'));

$cond = $histo ? '' : ' AND YEAR(a.fecha)='.$year;
$sql = "SELECT p.id,p.nombre,p.color,p.activo,
   COALESCE(SUM(CASE WHEN a.tipo='ingreso' THEN a.importe END),0) ing,
   COALESCE(SUM(CASE WHEN a.tipo='gasto' THEN a.importe END),0) gas,
   COUNT(a.id) nmov
 FROM projects p LEFT JOIN accounting a ON a.project_id=p.id$cond
 GROUP BY p.id, p.nombre, p.color, p.activo ORDER BY p.activo DESC, ing DESC, p.nombre";
$projs = db()->query($sql)->fetchAll();

$condS = $histo ? '' : ' AND YEAR(fecha)='.$year;
$sinP = db()->query("SELECT COALESCE(SUM(CASE WHEN tipo='ingreso' THEN importe END),0) ing, COALESCE(SUM(CASE WHEN tipo='gasto' THEN importe END),0) gas FROM accounting WHERE project_id IS NULL$condS")->fetch();

$totIng=0;$totGas=0; foreach($projs as $p){ $totIng+=(float)$p['ing']; $totGas+=(float)$p['gas']; }
$totBen=$totIng-$totGas;
$yLabel = $histo ? 'histórico' : $year;
$sinBen = (float)$sinP['ing'] - (float)$sinP['gas'];

erp_head('proj', 'Proyectos', 'fin-canvas');
?>
<style>
/* Lienzo gris estilo Apple: tarjetas blancas flotantes sobre fondo suave. */
body.fin-canvas .main{background:#f5f5f7}
body.fin-canvas .erp-wrap{animation:none;padding:36px 48px 80px}
body.fin-canvas h1{letter-spacing:-.5px;font-size:26px}
@keyframes finIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.pj-wrap{max-width:none}
.pj-head{display:flex;align-items:center;gap:14px;margin-bottom:8px;flex-wrap:wrap;animation:finIn .5s cubic-bezier(.2,.7,.3,1) both}.pj-head h1{flex:1;margin:0}
.pj-lead{color:var(--muted);font-size:13.5px;line-height:1.6;margin:0 0 28px;max-width:720px;animation:finIn .5s cubic-bezier(.2,.7,.3,1) .04s both}
.co-yr{display:inline-flex;gap:3px;background:#ececf0;border-radius:11px;padding:4px}
.co-yr a{padding:6px 13px;border-radius:8px;font-size:12.5px;font-weight:600;color:var(--label);cursor:pointer;text-decoration:none;transition:color .15s}
.co-yr a.on{background:#fff;color:var(--ink-strong);box-shadow:0 1px 3px rgba(16,19,24,.12)}
.co-yr a:hover:not(.on){color:var(--ink)}
/* KPIs */
.pj-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:22px}
@media(max-width:720px){.pj-kpis{grid-template-columns:1fr}}
.pj-k{background:#fff;border:1px solid rgba(16,19,24,.05);border-radius:20px;padding:24px 26px;box-shadow:0 1px 2px rgba(16,19,24,.04),0 12px 30px -20px rgba(16,19,24,.12);animation:finIn .55s cubic-bezier(.2,.7,.3,1) both}
.pj-k:nth-child(1){animation-delay:.06s}.pj-k:nth-child(2){animation-delay:.11s}.pj-k:nth-child(3){animation-delay:.16s}
.pj-k .kh{display:flex;align-items:center;gap:8px;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;font-weight:650;margin-bottom:12px}
.pj-k .kh svg{width:15px;height:15px}
.pj-k.i .kh svg{color:var(--ok)}.pj-k.g .kh svg{color:var(--danger)}.pj-k.b .kh svg{color:var(--label)}
.pj-k .n{font-size:27px;font-weight:770;letter-spacing:-.7px;color:var(--ink-strong)}
.pj-k .n.pos{color:var(--ok)}.pj-k .n.neg{color:var(--danger)}
/* Tabla de proyectos */
.pj-tablecard{background:#fff;border:1px solid rgba(16,19,24,.05);border-radius:22px;box-shadow:0 1px 2px rgba(16,19,24,.04),0 16px 40px -24px rgba(16,19,24,.18);animation:finIn .55s cubic-bezier(.2,.7,.3,1) .2s both}
.pj-tcap{display:flex;align-items:center;gap:12px;padding:20px 26px 16px}
.pj-tcap .tt{font-size:15px;font-weight:600;color:var(--ink-strong)}
.pj-tcap .sp{flex:1}
.pj-newbtn{border:none;background:var(--accent);color:#fff;border-radius:10px;padding:9px 16px;font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:filter .12s}
.pj-newbtn:hover{filter:brightness(1.14)}
.pj-tscroll{overflow-x:auto}
.pj-tbl{width:100%;border-collapse:collapse;min-width:640px}
.pj-tbl th{font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--label);font-weight:650;text-align:right;padding:8px 26px 14px;border-bottom:1px solid var(--line2);white-space:nowrap}
.pj-tbl th.l{text-align:left}
.pj-tbl td{padding:19px 26px;border-bottom:1px solid var(--line2);font-size:13.5px;text-align:right;vertical-align:middle;white-space:nowrap}
.pj-tbl td.l{text-align:left}
.pj-tbl tbody tr{transition:background .14s;animation:finIn .5s cubic-bezier(.2,.7,.3,1) both}
.pj-tbl tbody tr:hover{background:#fafbfc}
.pj-tbl tbody tr:last-child td{border-bottom:none}
.pj-tbl tbody tr.off{opacity:.55}
.pj-nm{display:flex;align-items:center;gap:11px;min-width:0}
.pj-nm .dot{width:11px;height:11px;border-radius:50%;flex:none}
.pj-nm .t{font-weight:650;color:var(--ink-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:14px}
.pj-nm .badge{font-size:9.5px;color:var(--label);font-weight:700;text-transform:uppercase;letter-spacing:.5px;background:var(--soft);padding:2px 7px;border-radius:99px;flex:none}
.pj-nm .mv{font-size:11.5px;color:var(--muted);font-weight:500;flex:none}
.pj-ing{color:var(--ok);font-weight:600}.pj-ing.z{color:#c4c8ce}
.pj-gas{color:var(--danger);font-weight:600}.pj-gas.z{color:#c4c8ce}
.pj-ben{font-weight:750;font-size:14px}.pj-ben.pos{color:var(--ok)}.pj-ben.neg{color:var(--danger)}
.pj-marg{display:inline-flex;align-items:center;gap:11px;justify-content:flex-end}
.pj-marg .bar{width:74px;height:6px;border-radius:99px;background:#eef0f2;overflow:hidden;display:flex;flex:none}
.pj-marg .bar .i{background:#25b56b}.pj-marg .bar .g{background:#e8756c}
.pj-marg .pct{font-weight:700;min-width:40px;text-align:right;color:var(--ink)}
.pj-actc{width:46px;position:relative;padding-left:0!important;padding-right:14px!important}
.pj-kebab{border:none;background:none;color:var(--label);cursor:pointer;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin-left:auto;transition:background .12s,color .12s}
.pj-tbl tbody tr:hover .pj-kebab{color:var(--label)}
.pj-kebab:hover{background:var(--soft);color:var(--ink)!important}
.pj-tbl tr.sinp td{background:#fafafb}
.pj-tbl tr.sinp .pj-nm .t{color:var(--muted)}
/* Menú contextual */
.pj-menu{position:absolute;top:40px;right:14px;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 16px 40px -12px rgba(16,19,24,.32);padding:6px;min-width:190px;z-index:40;display:none;text-align:left}
.pj-menu.on{display:block;animation:pjm .12s ease}
@keyframes pjm{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.pj-menu button{display:flex;align-items:center;gap:9px;width:100%;border:none;background:none;cursor:pointer;padding:8px 10px;border-radius:8px;font-size:13px;color:var(--ink);font-family:inherit;text-align:left}
.pj-menu button svg{width:15px;height:15px;color:var(--label);flex:none}
.pj-menu button:hover{background:var(--soft)}
.pj-menu button.del{color:#c0343a}.pj-menu button.del svg{color:#c0343a}.pj-menu button.del:hover{background:#fde8e8}
.pj-menu .sep{height:1px;background:var(--line2);margin:5px 4px}
.pj-menu .clbl{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;padding:4px 10px 6px}
.pj-cols{display:flex;gap:7px;flex-wrap:wrap;padding:0 10px 8px}
.pj-cols .cd{width:20px;height:20px;border-radius:50%;cursor:pointer;border:2px solid #fff;box-shadow:0 0 0 1px var(--line);transition:transform .1s}
.pj-cols .cd:hover{transform:scale(1.15)}
/* Crear inline */
.pj-newrow{display:none;padding:12px 22px;border-bottom:1px solid var(--line2);background:#fbfcfd}
.pj-newrow.on{display:flex;gap:10px;align-items:center}
.pj-newrow input{flex:1;border:1px solid var(--line);border-radius:10px;padding:10px 13px;font-size:13.5px;font-family:inherit;outline:none}
.pj-newrow input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.pj-newrow button{border:none;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:600;cursor:pointer}
.pj-newrow .ok{background:var(--accent);color:#fff}
.pj-newrow .cx{background:var(--soft);color:var(--muted)}
.pj-empty{padding:56px 20px;text-align:center;color:var(--muted)}
.pj-empty svg{width:40px;height:40px;color:#d6d9de;margin-bottom:12px}
/* ---- Modo oscuro: remapea las superficies y textos propios ---- */
[data-theme=dark] body.fin-canvas .main{background-color:var(--bg)}
[data-theme=dark] .co-yr{background-color:var(--soft)}
[data-theme=dark] .co-yr a.on{background-color:var(--card)}
[data-theme=dark] .pj-k,[data-theme=dark] .pj-tablecard{background-color:var(--card);border-color:var(--line)}
[data-theme=dark] .pj-newbtn,[data-theme=dark] .pj-newrow .ok{color:var(--accent-fg)}
[data-theme=dark] .pj-tbl tbody tr:hover,[data-theme=dark] .pj-tbl tr.sinp td,[data-theme=dark] .pj-newrow,[data-theme=dark] .pj-kebab:hover,[data-theme=dark] .pj-marg .bar{background-color:var(--soft)}
[data-theme=dark] .pj-menu{background-color:var(--pop)}
[data-theme=dark] .pj-menu button.del:hover{background-color:var(--danger-bg)}
[data-theme=dark] .pj-cols .cd{border-color:var(--card)}
[data-theme=dark] .pj-newrow input{background-color:var(--field)}
/* ====== MÓVIL (≤640px) ====== */
@media(max-width:640px){
  body.fin-canvas .erp-wrap{padding:20px 14px 60px}
  body.fin-canvas h1,.pj-head h1{font-size:21px}
  /* Selector de año: que se pueda desplazar si hay muchos */
  .pj-head{gap:10px}
  .co-yr{max-width:100%;overflow-x:auto;flex-wrap:nowrap}
  .pj-lead{margin-bottom:18px}
  /* KPIs: dos por fila y compactos, sin cajas enormes */
  .pj-kpis{grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px}
  .pj-k{padding:13px 14px;border-radius:14px;box-shadow:none}
  .pj-k .n{font-size:19px}
  /* Cabecera de la tabla: botón a lo ancho */
  .pj-tcap{flex-wrap:wrap;padding:16px 16px 12px}
  .pj-newbtn{margin-left:auto}
  .pj-tcap .tt{font-size:14px}
  /* Crear proyecto en línea: apilado para que quepa */
  .pj-newrow.on{flex-wrap:wrap;padding:12px 16px}
  .pj-newrow input{flex:1 1 100%}
  .pj-newrow button{flex:1}
  /* ── Tabla → tarjetas: cada proyecto es una tarjeta con el nombre arriba y sus
        cifras (ingresos·costes·beneficio·margen) como filas etiqueta·valor. Así no
        hay scroll horizontal ni columnas escondidas en el móvil. ── */
  .pj-tscroll{overflow-x:visible}
  .pj-tbl{min-width:0;display:block}
  .pj-tbl thead{display:none}
  .pj-tbl tbody,.pj-tbl tr,.pj-tbl td{display:block;width:auto}
  .pj-tbl tbody tr{position:relative;border:1px solid var(--line);border-radius:14px;margin:0 16px 12px;padding:14px 16px}
  .pj-tbl td{padding:7px 0;border:none;text-align:right;white-space:normal}
  .pj-tbl td:empty{display:none}
  /* Nombre del proyecto: título de la tarjeta, a lo ancho y con hueco para el menú */
  .pj-tbl td.l{padding:0 34px 10px 0;border-bottom:1px solid var(--line2);margin-bottom:4px}
  .pj-nm .t{white-space:normal}
  /* Filas de dato: etiqueta a la izquierda, valor a la derecha */
  .pj-tbl td[data-lbl]{display:flex;align-items:center;justify-content:space-between;gap:12px}
  .pj-tbl td[data-lbl]::before{content:attr(data-lbl);color:var(--label);font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:650}
  .pj-tbl td .pj-marg{justify-content:flex-end}
  /* Menú de acciones: esquina superior derecha de la tarjeta y siempre visible */
  .pj-actc{position:absolute!important;top:10px;right:10px;padding:0!important;width:auto!important}
  .pj-tbl .pj-kebab{color:var(--muted)}
}
</style>

<div class="pj-wrap">
  <div class="pj-head">
    <h1>Proyectos</h1>
    <div class="co-yr">
      <?php foreach($years as $yy): ?><a href="proyectos.php?y=<?= $yy ?>" class="<?= (!$histo&&$yy===$year)?'on':'' ?>"><?= $yy ?></a><?php endforeach; ?>
      <a href="proyectos.php?y=all" class="<?= $histo?'on':'' ?>">Histórico</a>
    </div>
  </div>
  <p class="pj-lead">Rentabilidad interna por proyecto (<?= $yLabel ?>). Asigna facturas y gastos a un proyecto desde Facturas y Contabilidad; aquí ves lo que gana cada uno. No aparece en las facturas del cliente.</p>

  <div class="pj-kpis">
    <div class="pj-k i"><div class="kh"><?= ic('trend',15) ?> Ingresos</div><div class="n"><?= eur($totIng) ?></div></div>
    <div class="pj-k g"><div class="kh"><?= ic('euro',15) ?> Costes</div><div class="n"><?= eur($totGas) ?></div></div>
    <div class="pj-k b"><div class="kh"><?= ic('chart',15) ?> Beneficio</div><div class="n <?= $totBen>=0?'pos':'neg' ?>"><?= eur($totBen) ?></div></div>
  </div>

  <div class="pj-tablecard">
    <div class="pj-tcap">
      <span class="tt">Rentabilidad por proyecto</span>
      <span class="sp"></span>
      <?php if(can_edit()): ?><button type="button" class="pj-newbtn" onclick="pjNew(true)"><?= ic('plus',14) ?> Crear proyecto</button><?php endif; ?>
    </div>
    <?php if(can_edit()): ?>
    <form method="post" class="pj-newrow" id="pjNewRow"><input type="hidden" name="action" value="add"><input type="hidden" name="color" value="<?= $PAL[count($projs)%count($PAL)] ?>"><input type="hidden" name="y" value="<?= $histo?'all':$year ?>">
      <input type="text" name="nombre" id="pjNewName" placeholder="Nombre del proyecto (ej: Web de Cliente X)…" autocomplete="off">
      <button type="submit" class="ok">Crear</button>
      <button type="button" class="cx" onclick="pjNew(false)">Cancelar</button>
    </form>
    <?php endif; ?>

    <?php if(!$projs): ?>
      <div class="pj-empty"><?= ic('layers',40) ?><div><b>Aún no hay proyectos</b><br>Crea el primero y luego asígnalo a tus facturas y gastos.</div></div>
    <?php else: ?>
    <div class="pj-tscroll">
    <table class="pj-tbl">
      <thead><tr>
        <th class="l">Proyecto</th><th>Ingresos</th><th>Costes</th><th>Beneficio</th><th>Margen</th><th class="pj-actc"></th>
      </tr></thead>
      <tbody>
        <?php $ri=0; foreach($projs as $p): $ing=(float)$p['ing'];$gas=(float)$p['gas'];$ben=$ing-$gas;$tot=$ing+$gas; $marg=$ing>0?round($ben/$ing*100):0; $pid=(int)$p['id']; ?>
        <tr class="<?= $p['activo']?'':'off' ?>" style="cursor:pointer;animation-delay:<?= number_format(0.28+($ri++)*0.05,2) ?>s" onclick="pjRow(event,<?= $pid ?>)"<?php if(can_edit()): ?> oncontextmenu="return pjMenu(event,<?= $pid ?>)"<?php endif; ?>>
          <td class="l"><div class="pj-nm"><span class="dot" style="background:<?= e($p['color']) ?>"></span><a class="t" href="proyecto.php?id=<?= $pid ?>" style="text-decoration:none"><?= e($p['nombre']) ?></a><?php if(!$p['activo']): ?><span class="badge">archivado</span><?php endif; ?><span class="mv"><?= (int)$p['nmov'] ?> mov.</span></div></td>
          <td class="pj-ing <?= $ing>0?'':'z' ?>" data-lbl="Ingresos"><?= eur($ing) ?></td>
          <td class="pj-gas <?= $gas>0?'':'z' ?>" data-lbl="Costes"><?= eur($gas) ?></td>
          <td class="pj-ben <?= $ben>=0?'pos':'neg' ?>" data-lbl="Beneficio"><?= eur($ben) ?></td>
          <td data-lbl="Margen"><div class="pj-marg"><div class="bar"><?php if($tot>0): ?><span class="i" style="width:<?= round($ing/$tot*100) ?>%"></span><span class="g" style="width:<?= round($gas/$tot*100) ?>%"></span><?php endif; ?></div><span class="pct"><?= $marg ?>%</span></div></td>
          <td class="pj-actc">
            <?php if(can_edit()): ?>
            <button type="button" class="pj-kebab" onclick="pjMenu(event,<?= $pid ?>)" title="Opciones"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.9"/><circle cx="12" cy="12" r="1.9"/><circle cx="19" cy="12" r="1.9"/></svg></button>
            <div class="pj-menu" id="pjm<?= $pid ?>">
              <button type="button" onclick="location.href='proyecto.php?id=<?= $pid ?>'"><?= ic('eye',15) ?> Ver proyecto</button>
              <div class="sep"></div>
              <button type="button" onclick="pjRename(<?= $pid ?>,<?= htmlspecialchars(json_encode($p['nombre']),ENT_QUOTES) ?>)"><?= ic('pencil',15) ?> Renombrar</button>
              <div class="clbl">Color</div>
              <div class="pj-cols"><?php foreach($PAL as $c): ?><span class="cd" style="background:<?= $c ?>" onclick="pjColor(<?= $pid ?>,'<?= $c ?>')"></span><?php endforeach; ?></div>
              <div class="sep"></div>
              <form method="post" style="margin:0"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="y" value="<?= $histo?'all':$year ?>"><button type="submit"><?= $p['activo']?ic('folder',15).' Archivar':ic('check',15).' Activar' ?></button></form>
              <form method="post" style="margin:0" onsubmit="return erpSubmitAsk(this,'¿Borrar el proyecto? Los movimientos quedan sin proyecto.')"><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="y" value="<?= $histo?'all':$year ?>"><button type="submit" class="del"><?= ic('trash',15) ?> Borrar</button></form>
            </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(($sinP['ing']+$sinP['gas'])>0): ?>
        <tr class="sinp" style="animation-delay:<?= number_format(0.28+$ri*0.05,2) ?>s">
          <td class="l"><div class="pj-nm"><span class="dot" style="background:#c8ccd2"></span><span class="t">Sin proyecto</span></div></td>
          <td class="pj-ing <?= (float)$sinP['ing']>0?'':'z' ?>" data-lbl="Ingresos"><?= eur((float)$sinP['ing']) ?></td>
          <td class="pj-gas <?= (float)$sinP['gas']>0?'':'z' ?>" data-lbl="Costes"><?= eur((float)$sinP['gas']) ?></td>
          <td class="pj-ben <?= $sinBen>=0?'pos':'neg' ?>" data-lbl="Beneficio"><?= eur($sinBen) ?></td>
          <td></td><td class="pj-actc"></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if(can_edit()): ?>
<form id="pjRenameForm" method="post" style="display:none"><input type="hidden" name="action" value="rename"><input type="hidden" name="id" id="pjrId"><input type="hidden" name="nombre" id="pjrName"><input type="hidden" name="y" value="<?= $histo?'all':$year ?>"></form>
<form id="pjColorForm" method="post" style="display:none"><input type="hidden" name="action" value="color"><input type="hidden" name="id" id="pjcId"><input type="hidden" name="color" id="pjcColor"><input type="hidden" name="y" value="<?= $histo?'all':$year ?>"></form>
<script>
function pjNew(show){var r=document.getElementById('pjNewRow');if(!r)return;r.classList.toggle('on',show);if(show){var i=document.getElementById('pjNewName');if(i)i.focus();}}
/* Abre la ficha al hacer clic en cualquier parte de la fila (salvo controles). */
function pjRow(e,id){ if(e.target.closest('.pj-kebab,.pj-menu,a,button,form,.pj-cols,input'))return; location.href='proyecto.php?id='+id; }
/* Menú de opciones: por el botón (⋮) o por clic derecho en la fila. Se coloca
   SIEMPRE dentro de la ventana (antes se iba fuera con el kebab pegado al borde). */
function pjMenu(e,id){ if(e){ if(e.preventDefault)e.preventDefault(); if(e.stopPropagation)e.stopPropagation(); }
  var m=document.getElementById('pjm'+id); if(!m)return false;
  var open=m.classList.contains('on');
  document.querySelectorAll('.pj-menu.on').forEach(function(x){x.classList.remove('on');});
  if(open)return false;
  /* Al <body>: la fila tiene transform (animación) y rompería el position:fixed. */
  if(m.parentElement!==document.body)document.body.appendChild(m);
  m.style.position='fixed';m.style.right='auto';m.style.bottom='auto';m.classList.add('on');
  var mw=m.offsetWidth,mh=m.offsetHeight,ax,ay;
  if(e&&e.type==='contextmenu'){ ax=e.clientX; ay=e.clientY; }
  else if(e&&e.currentTarget&&e.currentTarget.getBoundingClientRect){ var r=e.currentTarget.getBoundingClientRect(); ax=r.right-mw; ay=r.bottom+6; }
  else { ax=window.innerWidth-mw-12; ay=64; }
  m.style.left=Math.max(8,Math.min(ax,window.innerWidth-mw-8))+'px';
  m.style.top =Math.max(8,Math.min(ay,window.innerHeight-mh-8))+'px';
  return false;
}
document.addEventListener('click',function(e){if(!e.target.closest('.pj-menu')&&!e.target.closest('.pj-kebab'))document.querySelectorAll('.pj-menu.on').forEach(function(x){x.classList.remove('on');});});
window.addEventListener('resize',function(){document.querySelectorAll('.pj-menu.on').forEach(function(x){x.classList.remove('on');});});
function pjRename(id,cur){erpPrompt('Renombrar proyecto',cur,{placeholder:'Nombre del proyecto'}).then(function(n){if(!n)return;
  document.getElementById('pjrId').value=id;document.getElementById('pjrName').value=n;document.getElementById('pjRenameForm').submit();});}
function pjColor(id,color){document.getElementById('pjcId').value=id;document.getElementById('pjcColor').value=color;document.getElementById('pjColorForm').submit();}
</script>
<?php endif; ?>
<?php erp_foot(); ?>
