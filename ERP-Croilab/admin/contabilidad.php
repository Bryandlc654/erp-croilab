<?php
/* Contabilidad: libro de movimientos de SOLO LECTURA. Los movimientos vienen de
   Facturas (emitidas y subidas). Vista principal = Hub (empresa); dentro Víctor y Gabi. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
/* Quién factura (y por tanto qué ámbitos contables hay) se configura en
   Ajustes › Facturación. La lista la sirve fin_prog.php. */
require_once __DIR__ . '/lib/fin_prog.php';

db()->exec("CREATE TABLE IF NOT EXISTS accounting (
  id INT AUTO_INCREMENT PRIMARY KEY, fecha DATE, tipo VARCHAR(10) DEFAULT 'gasto',
  concepto VARCHAR(250) DEFAULT '', categoria VARCHAR(80) DEFAULT '', importe DECIMAL(12,2) DEFAULT 0,
  metodo VARCHAR(20) DEFAULT 'transferencia', legal TINYINT DEFAULT 1, ambito VARCHAR(15) DEFAULT 'empresa',
  deducible TINYINT DEFAULT 0, personal TINYINT DEFAULT 0, client_id INT DEFAULT NULL, notas VARCHAR(300) DEFAULT '', invoice_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach(['invoice_id'=>"INT DEFAULT NULL",'personal'=>"TINYINT NOT NULL DEFAULT 0",'project_id'=>"INT DEFAULT NULL"] as $col=>$def){ try{ if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='accounting' AND column_name='$col'")->fetchColumn()) db()->exec("ALTER TABLE accounting ADD COLUMN $col $def"); }catch(Exception $e){} }
try{ if(!db()->query("SELECT COUNT(*) FROM settings WHERE clave='acc_personal_migrated'")->fetchColumn()){ db()->exec("UPDATE accounting SET personal=1 WHERE deducible=1"); db()->prepare("INSERT INTO settings (clave,valor) VALUES ('acc_personal_migrated','1') ON DUPLICATE KEY UPDATE valor='1'")->execute(); } }catch(Exception $e){}

$METODOS = ['efectivo'=>'Efectivo','transferencia'=>'Transferencia','tarjeta'=>'Tarjeta','bizum'=>'Bizum','domiciliado'=>'Domiciliado'];
$AMBITOS = ['empresa'=>'Empresa'] + fin_emisores();
$PROJ=[]; try{ foreach(db()->query('SELECT id,nombre FROM projects WHERE activo=1 ORDER BY nombre') as $r) $PROJ[(int)$r['id']]=$r['nombre']; }catch(Exception $e){}

$year = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('Y');
$years = [];
foreach (db()->query('SELECT DISTINCT YEAR(fecha) y FROM accounting WHERE fecha IS NOT NULL ORDER BY y DESC') as $r) $years[]=(int)$r['y'];
if (!in_array((int)date('Y'),$years,true)) array_unshift($years,(int)date('Y'));
if (!in_array($year,$years,true)) $years[]=$year;

/* Se acepta cualquier ámbito de la lista, y también el de un emisor dado de
   baja que todavía tenga apuntes: su contabilidad no se puede esconder. */
$emGet = (string)($_GET['em'] ?? '');
$vistos = []; try{ foreach(db()->query('SELECT DISTINCT ambito FROM accounting') as $r) $vistos[]=(string)$r['ambito']; }catch(Exception $e){}
$view = (isset($AMBITOS[$emGet]) || in_array($emGet,$vistos,true)) ? $emGet : 'empresa';
foreach($vistos as $vk) if($vk!=='' && $vk!=='empresa' && !isset($AMBITOS[$vk])) $AMBITOS[$vk] = $vk.' (baja)';
$isEmpresa = ($view==='empresa');

if ($isEmpresa) { $rows = db()->prepare('SELECT * FROM accounting WHERE YEAR(fecha)=? AND personal=0 ORDER BY fecha DESC, id DESC'); $rows->execute([$year]); }
else { $rows = db()->prepare('SELECT * FROM accounting WHERE YEAR(fecha)=? AND ambito=? ORDER BY fecha DESC, id DESC'); $rows->execute([$year,$view]); }
$rows=$rows->fetchAll();

/* -------- EXPORT (CSV / Excel) -------- */
if (isset($_GET['export'])) {
  $ex=($_GET['export']==='xls')?'xls':'csv';
  $fname='contabilidad_'.$view.'_'.$year;
  $ti=0;$tg=0; foreach($rows as $r){ if($r['tipo']==='ingreso')$ti+=(float)$r['importe']; else $tg+=(float)$r['importe']; }
  if($ex==='csv'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fname.'.csv"');
    $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Fecha','Concepto','Tipo','Categoría','Ámbito','Proyecto','Método','Legal','Deducible','Personal','Importe (€)'],';');
    foreach($rows as $r){ fputcsv($out,[$r['fecha'],$r['concepto'],$r['tipo']==='ingreso'?'Ingreso':'Gasto',$r['categoria'],$AMBITOS[$r['ambito']]??$r['ambito'],($PROJ[(int)($r['project_id']??0)]??''),$METODOS[$r['metodo']]??$r['metodo'],$r['legal']?'Sí':'No',$r['deducible']?'Sí':'No',$r['personal']?'Sí':'No',number_format((float)$r['importe'],2,',','.')],';'); }
    fputcsv($out,[],';');
    fputcsv($out,['','','','','','','','','','Ingresos',number_format($ti,2,',','.')],';');
    fputcsv($out,['','','','','','','','','','Gastos',number_format($tg,2,',','.')],';');
    fputcsv($out,['','','','','','','','','','Beneficio',number_format($ti-$tg,2,',','.')],';');
    fclose($out); exit;
  } else {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fname.'.xls"');
    echo "\xEF\xBB\xBF<html xmlns:x=\"urn:schemas-microsoft-com:office:excel\"><head><meta charset=\"utf-8\"></head><body>";
    echo '<table cellspacing="0" cellpadding="6" style="border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:11pt">';
    echo '<tr><td colspan="11" style="font-size:15pt;font-weight:bold;color:#1b1f26;padding:6px">Contabilidad · '.htmlspecialchars($AMBITOS[$view]??$view).' · '.$year.'</td></tr>';
    echo '<tr><td colspan="11" style="padding:2px"></td></tr>';
    $hs='background:#111318;color:#ffffff;font-weight:bold;border:1px solid #111318;padding:8px';
    echo '<tr>'; foreach(['Fecha','Concepto','Tipo','Categoría','Ámbito','Proyecto','Método','Legal','Deduc.','Personal','Importe (€)'] as $h) echo '<td style="'.$hs.'">'.$h.'</td>'; echo '</tr>';
    $i=0; foreach($rows as $r){ $bg=($i++%2)?'#ffffff':'#f4f6f9'; $isI=$r['tipo']==='ingreso'; $tc=$isI?'#12854a':'#c0392b'; $bd='border:1px solid #e3e6ea;padding:6px;background:'.$bg;
      echo '<tr>';
      echo '<td style="'.$bd.'">'.htmlspecialchars($r['fecha']).'</td>';
      echo '<td style="'.$bd.'">'.htmlspecialchars($r['concepto']).'</td>';
      echo '<td style="'.$bd.';color:'.$tc.';font-weight:bold">'.($isI?'Ingreso':'Gasto').'</td>';
      echo '<td style="'.$bd.'">'.htmlspecialchars($r['categoria']).'</td>';
      echo '<td style="'.$bd.'">'.htmlspecialchars($AMBITOS[$r['ambito']]??$r['ambito']).'</td>';
      echo '<td style="'.$bd.'">'.htmlspecialchars($PROJ[(int)($r['project_id']??0)]??'').'</td>';
      echo '<td style="'.$bd.'">'.htmlspecialchars($METODOS[$r['metodo']]??$r['metodo']).'</td>';
      echo '<td style="'.$bd.';text-align:center">'.($r['legal']?'✓':'').'</td>';
      echo '<td style="'.$bd.';text-align:center">'.($r['deducible']?'✓':'').'</td>';
      echo '<td style="'.$bd.';text-align:center">'.($r['personal']?'✓':'').'</td>';
      echo '<td style="'.$bd.';text-align:right;color:'.$tc.';font-weight:bold">'.number_format((float)$r['importe'],2,',','.').'</td>';
      echo '</tr>';
    }
    echo '<tr><td colspan="10" style="text-align:right;font-weight:bold;padding:8px;border-top:2px solid #111318">Ingresos</td><td style="text-align:right;font-weight:bold;color:#12854a;padding:8px;border-top:2px solid #111318">'.number_format($ti,2,',','.').'</td></tr>';
    echo '<tr><td colspan="10" style="text-align:right;font-weight:bold;padding:8px">Gastos</td><td style="text-align:right;font-weight:bold;color:#c0392b;padding:8px">'.number_format($tg,2,',','.').'</td></tr>';
    echo '<tr><td colspan="10" style="text-align:right;font-weight:bold;padding:8px">Beneficio</td><td style="text-align:right;font-weight:bold;padding:8px">'.number_format($ti-$tg,2,',','.').'</td></tr>';
    echo '</table></body></html>'; exit;
  }
}

/* quién es cada movimiento + enlace de origen */
$cliMap=[]; try{ foreach(db()->query('SELECT id,name FROM clients') as $c) $cliMap[(int)$c['id']]=$c['name']; }catch(Exception $e){}
$upMap=[]; try{ foreach(db()->query('SELECT acc_id,id,proveedor,emisor,tipo,fecha FROM invoice_uploads WHERE acc_id IS NOT NULL') as $u) $upMap[(int)$u['acc_id']]=$u; }catch(Exception $e){}
function co_who($r,$cliMap,$upMap){ if(!empty($r['client_id']) && isset($cliMap[(int)$r['client_id']])) return $cliMap[(int)$r['client_id']]; if(isset($upMap[(int)$r['id']]) && trim((string)$upMap[(int)$r['id']]['proveedor'])!=='') return $upMap[(int)$r['id']]['proveedor']; return ''; }

/* -------- KPIs -------- */
$ing=0;$gas=0;$ingLegal=0;$ingEfectivo=0;$ded=0;$gasPersonal=0;$gasEmpresa=0;
foreach($rows as $r){ $im=(float)$r['importe'];
  if($r['tipo']==='ingreso'){ $ing+=$im; if($r['metodo']==='efectivo'||!$r['legal'])$ingEfectivo+=$im; else $ingLegal+=$im; }
  else{ $gas+=$im; if($r['deducible'])$ded+=$im; if($r['personal'])$gasPersonal+=$im; else $gasEmpresa+=$im; }
}
$benef=$ing-$gas;
$netoIng=0; foreach($rows as $r){ if($r['tipo']==='ingreso'){ if(!empty($r['invoice_id'])){ $bq=db()->prepare('SELECT COALESCE(SUM(cantidad*precio),0) FROM invoice_items WHERE invoice_id=?'); $bq->execute([(int)$r['invoice_id']]); $netoIng+=(float)$bq->fetchColumn(); } else { $netoIng+=(float)$r['importe']; } } }
$netoBenef=$netoIng-$gas;

erp_head('conta', 'Contabilidad');
?>
<style>
.co-head{display:flex;align-items:center;gap:12px;margin-bottom:24px;flex-wrap:wrap}.co-head h1{flex:1}
.co-yr{display:flex;gap:4px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:3px}
.co-yr a{padding:6px 12px;border-radius:8px;font-size:12.5px;font-weight:600;color:var(--muted);cursor:pointer}
.co-yr a.on{background:var(--accent);color:#fff}.co-yr a:hover:not(.on){background:var(--soft)}
.co-scope,.co-pages{display:flex;gap:4px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:3px}
.co-scope a,.co-pages a{padding:7px 15px;border-radius:8px;font-size:13px;font-weight:600;color:var(--muted);cursor:pointer}
.co-scope a.on,.co-pages a.on{background:var(--ink-strong);color:#fff}.co-scope a:hover:not(.on),.co-pages a:hover:not(.on){background:var(--soft)}
.co-exp{position:relative}
.exp-menu{position:absolute;right:0;top:calc(100% + 6px);background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 14px 40px rgba(0,0,0,.15);padding:5px;min-width:150px;display:none;z-index:60}
.exp-menu.on{display:block}
.exp-menu a{display:block;padding:9px 12px;border-radius:8px;font-size:13px;color:var(--ink);text-decoration:none;font-weight:600}
.exp-menu a:hover{background:var(--soft)}
.co-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:18px;margin-bottom:26px}
@media(max-width:900px){.co-kpis{grid-template-columns:1fr 1fr}}
.co-k{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px 26px;min-width:0}
.co-k .l{font-size:12px;color:var(--muted);font-weight:500;margin-bottom:11px}
.co-k .n{font-size:27px;font-weight:700;letter-spacing:-.8px;color:var(--ink-strong)}
.co-k .s{font-size:12px;color:var(--muted);margin-top:8px;line-height:1.5}
.co-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:-2px 0 26px}
@media(max-width:820px){.co-strip{grid-template-columns:1fr}}
.co-strip .cs-i{background:#fbfbfc;border:1px solid var(--line);border-radius:14px;padding:18px 20px;display:flex;flex-direction:column;gap:7px}
.co-strip .cs-l{font-size:11.5px;color:var(--muted);font-weight:500;line-height:1.5}
.co-strip .cs-v{font-size:19px;font-weight:700;color:var(--ink-strong)}
.ss-wrap{background:#fff;border:1px solid var(--line);border-radius:16px;overflow-x:auto}
.ss{border-collapse:collapse;width:100%;min-width:920px}
.ss thead th{position:sticky;top:0;background:#fbfbfc;z-index:2;text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:600;padding:12px 16px;border-bottom:1px solid var(--line);white-space:nowrap}
.ss thead th.r{text-align:right}
.ss tbody td{border-bottom:1px solid var(--line2);padding:16px 16px;font-size:13px;color:var(--ink);vertical-align:middle;white-space:nowrap}
.ss tbody tr:last-child td{border-bottom:none}
.ss tbody tr:hover td{background:#fafbfc}
.mv{display:flex;align-items:center;gap:11px;min-width:0}
.mv-av{width:36px;height:36px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;font-weight:700}
.mv-txt{display:flex;flex-direction:column;min-width:0}
.mv-tl{display:flex;align-items:center;gap:7px;min-width:0}
.mv-t{font-weight:600;color:var(--ink-strong);font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:230px}
.mv-tag{font-size:10px;font-weight:600;padding:1px 8px;border-radius:99px;background:var(--soft);color:var(--muted);flex:none}
.mv-s{font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:320px}
.co-tp{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:500;color:var(--ink)}
.co-tp .co-tpdot{width:7px;height:7px;border-radius:50%}
.co-tp.i .co-tpdot{background:#30a46c}
.co-tp.g .co-tpdot{background:#e5484d}
.co-mut{color:var(--muted)}
.co-badge{font-size:11px;font-weight:600;padding:2px 9px;border-radius:99px;background:var(--soft);color:var(--ink)}
.ct{font-size:10.5px;font-weight:500;padding:2px 9px;border-radius:99px;margin-right:4px;white-space:nowrap;background:var(--soft);color:var(--muted)}
.co-imp{font-weight:600;text-align:right;color:var(--ink-strong)}
.co-net{display:block;font-size:11px;color:var(--muted);font-weight:500}
.co-edit{color:var(--label);display:inline-flex;padding:6px;border-radius:8px}.co-edit:hover{background:var(--soft);color:var(--accent)}.co-edit svg{width:15px;height:15px}
.co-addlink{display:flex;align-items:center;gap:8px;padding:13px 14px;color:var(--muted);font-size:13px;text-decoration:none;font-weight:500;border-top:1px solid var(--line2)}
.co-addlink:hover{color:var(--accent)}
.empty{padding:44px;text-align:center;color:var(--muted)}
[data-theme=dark] .co-yr,[data-theme=dark] .co-scope,[data-theme=dark] .co-pages{background-color:var(--card)}
[data-theme=dark] .co-yr a.on{color:var(--accent-fg)}
[data-theme=dark] .co-scope a.on,[data-theme=dark] .co-pages a.on{background-color:var(--rev);color:var(--rev-fg)}
[data-theme=dark] .exp-menu{background-color:var(--pop)}
[data-theme=dark] .co-k{background-color:var(--card)}
[data-theme=dark] .co-strip .cs-i{background-color:var(--soft)}
[data-theme=dark] .ss-wrap{background-color:var(--card)}
[data-theme=dark] .ss thead th{background-color:var(--soft)}
[data-theme=dark] .ss tbody tr:hover td{background-color:var(--soft)}
[data-theme=dark] .co-edit{color:var(--muted)}
/* ---- Móvil (teléfono) ---- */
@media(max-width:640px){
  .co-head{gap:10px}
  .co-head h1{flex:1 1 100%;font-size:22px}
  .co-head .lead{order:5;flex:1 1 100%}
  .co-yr,.co-scope,.co-pages{max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;flex-wrap:nowrap}
  .co-yr a,.co-scope a,.co-pages a{white-space:nowrap;flex:none}
  .co-exp{margin-left:auto}
  .co-kpis{grid-template-columns:1fr 1fr;gap:12px}
  .co-k{padding:16px 16px;border-radius:14px}
  .co-k .n{font-size:22px}
  .co-strip{gap:12px}
  /* La tabla de movimientos se vuelve tarjetas: el importe se ve sin arrastrar. */
  .ss-wrap{overflow-x:visible}
  .ss{min-width:0;display:block}
  .ss thead{display:none}
  .ss tbody{display:block}
  .ss tbody tr{display:flex;flex-wrap:wrap;align-items:center;gap:7px 10px;padding:15px 15px;border-bottom:1px solid var(--line2)}
  .ss tbody tr:last-child{border-bottom:none}
  .ss tbody tr:hover td{background:none}
  .ss tbody td{display:block;border:none;padding:0;white-space:normal}
  .ss tbody td:nth-child(1){flex:1 1 55%;min-width:0;order:0}
  .ss tbody td.co-imp{order:1;margin-left:auto;font-size:18px;text-align:right}
  .ss tbody td:nth-child(2),
  .ss tbody td:nth-child(3),
  .ss tbody td:nth-child(4),
  .ss tbody td:nth-child(5),
  .ss tbody td:nth-child(6){order:2}
  .ss tbody td:nth-child(8){order:3}
  .ss tbody td:nth-child(8):empty{display:none}
  .ss tbody td[colspan]{flex:1 1 100%;order:0}
  .mv-t{max-width:none}
}
</style>

<div class="co-head">
  <h1>Contabilidad</h1>
  <div class="lead" style="color:var(--muted);font-size:12.5px;margin:2px 0 4px;max-width:70ch">Criterio de <b>caja</b>: aquí solo cuenta lo que se ha <b>cobrado</b> de verdad. El total <b>facturado</b> (emitido, esté cobrado o no) está en <a href="fin-resumen.php" style="color:var(--accent);font-weight:600">Resumen mensual</a> — por eso las dos cifras no tienen por qué coincidir.</div>
  <div class="co-pages">
    <a href="contabilidad.php?em=<?= $view ?>&y=<?= $year ?>" class="on">Movimientos</a>
    <a href="contabilidad-analisis.php?em=<?= $view ?>&y=<?= $year ?>">Análisis</a>
  </div>
  <div class="co-scope">
    <a href="contabilidad.php?y=<?= $year ?>&em=empresa" class="<?= $isEmpresa?'on':'' ?>">Hub</a>
    <?php foreach($AMBITOS as $ak=>$an): if($ak==='empresa') continue; ?>
      <a href="contabilidad.php?y=<?= $year ?>&em=<?= urlencode($ak) ?>" class="<?= $view===$ak?'on':'' ?>"><?= e($an) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="co-yr"><?php foreach($years as $y): ?><a href="contabilidad.php?y=<?= $y ?>&em=<?= $view ?>" class="<?= $y===$year?'on':'' ?>"><?= $y ?></a><?php endforeach; ?></div>
  <div class="co-exp">
    <button type="button" class="btn ghost sm" onclick="document.getElementById('expMenu').classList.toggle('on')"><?= ic('download',14) ?> Exportar ▾</button>
    <div id="expMenu" class="exp-menu">
      <a href="contabilidad.php?y=<?= $year ?>&em=<?= $view ?>&export=xls">Excel (.xls)</a>
      <a href="contabilidad.php?y=<?= $year ?>&em=<?= $view ?>&export=csv">CSV (.csv)</a>
    </div>
    <script>document.addEventListener('click',function(e){var m=document.getElementById('expMenu');if(m&&!e.target.closest('.co-exp'))m.classList.remove('on');});</script>
  </div>
</div>

<div class="co-kpis">
  <div class="co-k"><div class="l">Ingresos brutos</div><div class="n"><?= eur($ing) ?></div><div class="s"><?= eur($ingLegal) ?> legal · <?= eur($ingEfectivo) ?> efectivo</div></div>
  <div class="co-k"><div class="l">Ingresos netos</div><div class="n"><?= eur($netoIng) ?></div><div class="s">Sin IVA ni IRPF</div></div>
  <div class="co-k"><div class="l">Gastos</div><div class="n"><?= eur($gas) ?></div><div class="s"><?= $isEmpresa?'de empresa':(eur($ded).' deducibles') ?></div></div>
  <div class="co-k"><div class="l">Beneficio neto</div><div class="n" style="color:<?= $netoBenef>=0?'#12854a':'#c0392b' ?>"><?= eur($netoBenef) ?></div><div class="s">bruto <?= eur($benef) ?> · <?= $netoIng>0?round($netoBenef/$netoIng*100):0 ?>% margen</div></div>
</div>

<?php if(!$isEmpresa): ?>
<div class="co-strip">
  <div class="cs-i"><span class="cs-l">De tu bolsillo · gastos personales</span><span class="cs-v"><?= eur($gasPersonal) ?></span></div>
  <div class="cs-i"><span class="cs-l">Gastos de empresa · los paga la empresa</span><span class="cs-v"><?= eur($gasEmpresa) ?></span></div>
  <div class="cs-i"><span class="cs-l">Beneficio real tuyo · solo con tus gastos</span><span class="cs-v" style="color:<?= ($netoIng-$gasPersonal)>=0?'#12854a':'#c0392b' ?>"><?= eur($netoIng-$gasPersonal) ?></span></div>
</div>
<?php endif; ?>

<div class="ss-wrap">
<table class="ss">
  <thead><tr>
    <th>Movimiento</th><th>Tipo</th><th>Ámbito</th><th>Proyecto</th><th>Fecha</th><th>Marcas</th><th class="r">Importe</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach($rows as $r): $isIng=($r['tipo']==='ingreso'); $pid=(int)($r['project_id']??0);
    $who=co_who($r,$cliMap,$upMap); $avSeed=$who!==''?$who:($r['concepto']?:'?'); $ini=mb_strtoupper(mb_substr(trim($avSeed),0,1)); if($ini==='')$ini='?';
    $sub = $who!=='' ? (($isIng?'Cliente · ':'Proveedor · ').$who) : ($r['categoria']?:'—');
    $editUrl=''; $editTit='';
    if(!empty($r['invoice_id'])){ $editUrl='facturas.php?edit='.(int)$r['invoice_id']; $editTit='Editar factura'; }
    elseif(isset($upMap[(int)$r['id']])){ $u=$upMap[(int)$r['id']]; $editUrl='facturas.php?em='.$u['emisor'].'&tipo='.$u['tipo'].'&mes='.date('Y-m',strtotime($u['fecha'])); $editTit='Ver en Facturas'; }
  ?>
    <tr>
      <td><div class="mv"><span class="mv-av" style="background:<?= avatar_color($avSeed) ?>"><?= e($ini) ?></span><div class="mv-txt"><div class="mv-tl"><span class="mv-t"><?= e($r['concepto']?:'—') ?></span><?php if($r['tipo']==='gasto'): ?><span class="mv-tag"><?= $r['personal']?'Personal':'Empresa' ?></span><?php endif; ?></div><span class="mv-s"><?= e($sub) ?></span></div></div></td>
      <td><span class="co-tp <?= $isIng?'i':'g' ?>"><span class="co-tpdot"></span><?= $isIng?'Ingreso':'Gasto' ?></span></td>
      <td><span class="co-badge"><?= e($AMBITOS[$r['ambito']]??$r['ambito']) ?></span></td>
      <td><?php if($pid && isset($PROJ[$pid])): ?><span class="co-badge"><?= e($PROJ[$pid]) ?></span><?php else: ?><span class="co-mut">—</span><?php endif; ?></td>
      <td class="co-mut"><?= $r['fecha']?e(date('d/m/Y',strtotime($r['fecha']))):'—' ?></td>
      <td><?php if($r['legal']): ?><span class="ct">Legal</span><?php endif; ?><?php if($r['deducible']): ?><span class="ct">Deduc.</span><?php endif; ?><?php if(!$r['legal']&&!$r['deducible']): ?><span class="co-mut">—</span><?php endif; ?></td>
      <td class="co-imp"><?= $isIng?'':'−' ?><?= eur($r['importe']) ?></td>
      <td style="text-align:center"><?php if($editUrl!==''): ?><a class="co-edit" href="<?= e($editUrl) ?>" title="<?= e($editTit) ?>"><?= ic('pencil',15) ?></a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if(!$rows): ?><tr><td colspan="8"><div class="empty">Sin movimientos en <?= $year ?> · <?= e($AMBITOS[$view]) ?>.</div></td></tr><?php endif; ?>
  </tbody>
</table>
<a class="co-addlink" href="facturas.php"><?= ic('plus',15) ?> Añadir más…</a>
</div>
<div class="muted" style="margin-top:10px;font-size:12px">Los movimientos se registran creando o subiendo facturas. Aquí solo se consultan; usa el lápiz para editar la factura de origen. Marca <b>Personal</b> = gasto del socio (fuera de empresa) · <b>Legal</b> = con factura · <b>Deduc.</b> = el socio se lo desgrava.</div>
<?php erp_foot(); ?>
