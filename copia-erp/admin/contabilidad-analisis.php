<?php
/* Análisis contable: gráficas, trimestres y resúmenes. Solo lectura.
   La edición de movimientos vive en contabilidad.php. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/fin_prog.php';   // la lista de emisores

/* En una instalación nueva, si se entra aquí antes que en Contabilidad o Facturas,
   la tabla accounting aún no existe y la página moría. prog_ensure() la crea con
   todas sus columnas (incluidas personal y project_id). */
prog_ensure();

$METODOS = ['efectivo'=>'Efectivo','transferencia'=>'Transferencia','tarjeta'=>'Tarjeta','bizum'=>'Bizum','domiciliado'=>'Domiciliado'];
$AMBITOS = ['empresa'=>'Empresa'] + fin_emisores();

$year = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('Y');
$years = [];
foreach (db()->query('SELECT DISTINCT YEAR(fecha) y FROM accounting WHERE fecha IS NOT NULL ORDER BY y DESC') as $r) $years[]=(int)$r['y'];
if (!in_array((int)date('Y'),$years,true)) array_unshift($years,(int)date('Y'));
if (!in_array($year,$years,true)) $years[]=$year;

$emGet = (string)($_GET['em'] ?? '');
$vistos = []; try{ foreach(db()->query('SELECT DISTINCT ambito FROM accounting') as $r) $vistos[]=(string)$r['ambito']; }catch(Exception $e){}
$view = (isset($AMBITOS[$emGet]) || in_array($emGet,$vistos,true)) ? $emGet : 'empresa';
foreach($vistos as $vk) if($vk!=='' && $vk!=='empresa' && !isset($AMBITOS[$vk])) $AMBITOS[$vk] = $vk.' (baja)';
$isEmpresa = ($view==='empresa');

if ($isEmpresa) { $rows = db()->prepare('SELECT * FROM accounting WHERE YEAR(fecha)=? AND personal=0 ORDER BY fecha DESC, id DESC'); $rows->execute([$year]); }
else { $rows = db()->prepare('SELECT * FROM accounting WHERE YEAR(fecha)=? AND ambito=? ORDER BY fecha DESC, id DESC'); $rows->execute([$year,$view]); }
$rows=$rows->fetchAll();

$ing=0;$gas=0;$ingLegal=0;$ingEfectivo=0;$ded=0;
$mesIng=array_fill(1,12,0.0);$mesGas=array_fill(1,12,0.0);$catGas=[];$triIng=[0,0,0,0];$triGas=[0,0,0,0];
foreach($rows as $r){ $im=(float)$r['importe']; $mm=$r['fecha']?(int)date('n',strtotime($r['fecha'])):0; $q=($mm>=1&&$mm<=12)?intdiv($mm-1,3):0;
  if($r['tipo']==='ingreso'){ $ing+=$im; if($r['metodo']==='efectivo'||!$r['legal'])$ingEfectivo+=$im; else $ingLegal+=$im; if($mm){$mesIng[$mm]+=$im;$triIng[$q]+=$im;} }
  else{ $gas+=$im; if($r['deducible'])$ded+=$im; if($mm){$mesGas[$mm]+=$im;$triGas[$q]+=$im;} $cat=trim($r['categoria'])!==''?$r['categoria']:'Sin categoría'; $catGas[$cat]=($catGas[$cat]??0)+$im; }
}
$benef=$ing-$gas;
arsort($catGas);

/* Deducible de cada autónomo. Antes eran dos variables fijas ($dedV y $dedG) y
   un tercer socio no aparecía por ninguna parte: sus gastos deducibles no se
   sumaban al total y nadie lo notaba. Ahora es una fila por emisor. */
$dedEm=[]; foreach($AMBITOS as $ak=>$an){ if($ak!=='empresa') $dedEm[$ak]=0.0; }
$dedTot=0.0;
try{ $dq=db()->prepare("SELECT ambito, SUM(importe) t FROM accounting WHERE YEAR(fecha)=? AND tipo='gasto' AND deducible=1 GROUP BY ambito"); $dq->execute([$year]);
     foreach($dq as $r){ $ak=(string)$r['ambito']; if($ak==='empresa'||$ak==='') continue; $dedEm[$ak]=(float)$r['t']; } }catch(Exception $e){}
foreach($dedEm as $v) $dedTot+=$v;

erp_head('conta', 'Análisis contable');
?>
<style>
.co-head{display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap}.co-head h1{flex:1}
.co-yr{display:flex;gap:4px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:3px}
.co-yr a{padding:6px 12px;border-radius:8px;font-size:12.5px;font-weight:600;color:var(--muted);cursor:pointer}
.co-yr a.on{background:var(--accent);color:#fff}.co-yr a:hover:not(.on){background:var(--soft)}
.co-scope,.co-pages{display:flex;gap:4px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:3px}
.co-scope a,.co-pages a{padding:7px 15px;border-radius:8px;font-size:13px;font-weight:600;color:var(--muted);cursor:pointer}
.co-scope a.on,.co-pages a.on{background:var(--ink-strong);color:#fff}.co-scope a:hover:not(.on),.co-pages a:hover:not(.on){background:var(--soft)}
.co-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
@media(max-width:820px){.co-kpis{grid-template-columns:1fr 1fr}}
.co-k{background:#fff;border:1px solid var(--line);border-radius:14px;padding:19px 21px}
.co-k .n{font-size:23px;font-weight:700;letter-spacing:-.5px;color:var(--ink-strong)}
.co-k .n.pos{color:#12854a}.co-k .n.neg{color:#c0392b}
.co-k .l{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;font-weight:650;margin-top:5px}
.co-k .s{font-size:11.5px;color:var(--muted);margin-top:6px;line-height:1.5}
.co-charts{display:grid;grid-template-columns:1.5fr 1fr;gap:16px;margin-bottom:20px}
@media(max-width:820px){.co-charts{grid-template-columns:1fr}}
.co-box{background:#fff;border:1px solid var(--line);border-radius:14px;padding:19px 22px}
.co-box h4{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:650;margin-bottom:15px}
.ch-box{position:relative;height:250px}
.co-tri{width:100%;border-collapse:collapse}
.co-tri th,.co-tri td{padding:11px 10px;border-bottom:1px solid var(--line2);font-size:13px;text-align:right}
.co-tri th:first-child,.co-tri td:first-child{text-align:left}
.co-tri th{font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:650}
.co-tri tr:last-child td{border-bottom:none}
.co-tri .pos{color:#12854a;font-weight:600}.co-tri .neg{color:#c0392b;font-weight:600}
.co-tri tfoot td{font-weight:700;color:var(--ink-strong);border-top:2px solid var(--line)}
.co-mid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px}
@media(max-width:820px){.co-mid{grid-template-columns:1fr}}
.co-line{display:flex;justify-content:space-between;font-size:13px;padding:7px 0;color:var(--ink)}
.co-line b{font-weight:700}
.co-catl{display:flex;flex-direction:column;gap:9px;margin-top:4px}
.co-catrow{display:grid;grid-template-columns:1fr auto;gap:8px;font-size:12.5px;align-items:center}
.co-catbar{grid-column:1/-1;height:6px;border-radius:99px;background:var(--soft);overflow:hidden}
.co-catbar span{display:block;height:100%;border-radius:99px;background:var(--accent)}
[data-theme=dark] .co-yr,[data-theme=dark] .co-scope,[data-theme=dark] .co-pages{background-color:var(--card)}
[data-theme=dark] .co-yr a.on{color:var(--accent-fg)}
[data-theme=dark] .co-scope a.on,[data-theme=dark] .co-pages a.on{background-color:var(--rev);color:var(--rev-fg)}
[data-theme=dark] .co-k,[data-theme=dark] .co-box{background-color:var(--card)}
[data-theme=dark] .co-k .n.pos,[data-theme=dark] .co-tri .pos{color:var(--ok)}
[data-theme=dark] .co-k .n.neg,[data-theme=dark] .co-tri .neg{color:var(--danger)}
/* ---- Móvil (teléfono) ---- */
@media(max-width:640px){
  .co-head{gap:10px}
  .co-head h1{flex:1 1 100%;font-size:22px}
  .co-yr,.co-scope,.co-pages{max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;flex-wrap:nowrap}
  .co-yr a,.co-scope a,.co-pages a{white-space:nowrap;flex:none}
  .co-kpis{grid-template-columns:1fr 1fr;gap:12px}
  .co-k{padding:15px 15px}
  .co-k .n{font-size:20px}
  .co-box{padding:16px 16px}
  .ch-box{height:220px}
  /* La tabla de trimestres se vuelve tarjetas: cada trimestre con sus cifras
     etiquetadas, sin scroll horizontal. */
  .co-tri{min-width:0;display:block}
  .co-tri thead{display:none}
  .co-tri tbody,.co-tri tfoot{display:block}
  .co-tri tr{display:grid;grid-template-columns:1fr 1fr;gap:5px 14px;padding:13px 0;border-bottom:1px solid var(--line2)}
  .co-tri tfoot tr{border-bottom:none;border-top:2px solid var(--line);margin-top:2px}
  .co-tri td{padding:0;border:none;text-align:right;display:flex;align-items:baseline;justify-content:space-between;gap:8px;font-size:13px}
  .co-tri td:first-child{grid-column:1 / -1;text-align:left;display:block;font-weight:700;color:var(--ink-strong);font-size:13.5px}
  .co-tri td:not(:first-child)::before{content:attr(data-l);color:var(--muted);font-weight:500;text-transform:uppercase;letter-spacing:.3px;font-size:10px}
}
</style>

<div class="co-head">
  <h1>Contabilidad</h1>
  <div class="co-pages">
    <a href="contabilidad.php?em=<?= $view ?>&y=<?= $year ?>">Movimientos</a>
    <a href="contabilidad-analisis.php?em=<?= $view ?>&y=<?= $year ?>" class="on">Análisis</a>
  </div>
  <div class="co-scope">
    <a href="contabilidad-analisis.php?y=<?= $year ?>&em=empresa" class="<?= $isEmpresa?'on':'' ?>">Hub</a>
    <?php foreach($AMBITOS as $ak=>$an): if($ak==='empresa') continue; ?>
      <a href="contabilidad-analisis.php?y=<?= $year ?>&em=<?= urlencode($ak) ?>" class="<?= $view===$ak?'on':'' ?>"><?= e($an) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="co-yr"><?php foreach($years as $y): ?><a href="contabilidad-analisis.php?y=<?= $y ?>&em=<?= $view ?>" class="<?= $y===$year?'on':'' ?>"><?= $y ?></a><?php endforeach; ?></div>
</div>

<div class="co-kpis">
  <div class="co-k"><div class="n pos"><?= eur($ing) ?></div><div class="l">Ingresos<?= $isEmpresa?' (empresa)':'' ?></div><div class="s"><?= eur($ingLegal) ?> legal · <?= eur($ingEfectivo) ?> efectivo</div></div>
  <div class="co-k"><div class="n neg"><?= eur($gas) ?></div><div class="l">Gastos<?= $isEmpresa?' (empresa)':'' ?></div><div class="s"><?= $isEmpresa?'sin gastos personales':(eur($ded).' deducibles') ?></div></div>
  <div class="co-k"><div class="n <?= $benef>=0?'pos':'neg' ?>"><?= eur($benef) ?></div><div class="l">Beneficio</div><div class="s"><?= $ing>0?round($benef/$ing*100):0 ?>% margen</div></div>
  <?php if($isEmpresa): ?>
  <div class="co-k"><div class="n"><?= eur($dedTot) ?></div><div class="l">Deducible socios</div><div class="s"><?php $ps=[]; foreach($dedEm as $ak=>$av) $ps[]=e($AMBITOS[$ak] ?? $ak).' '.eur($av); echo implode(' · ',$ps) ?: '—'; ?></div></div>
  <?php else: ?>
  <div class="co-k"><div class="n"><?= eur($ded) ?></div><div class="l">Gastos deducibles</div><div class="s">que <?= e($AMBITOS[$view]) ?> se desgrava</div></div>
  <?php endif; ?>
</div>

<div class="co-charts">
  <div class="co-box"><h4>Ingresos vs Gastos por mes</h4><div class="ch-box"><canvas id="chMes"></canvas></div></div>
  <div class="co-box"><h4>Gastos por categoría</h4>
    <?php /* Un donut de una sola porción no dice nada; solo se pinta cuando hay al
             menos dos categorías. Con una o ninguna, un texto es más honesto (P3-10-bis). */ ?>
    <?php if(count($catGas) >= 2): ?>
      <div class="ch-box"><canvas id="chCat"></canvas></div>
    <?php else: ?>
      <div class="ch-box" style="display:flex;align-items:center;justify-content:center;text-align:center;color:var(--muted);font-size:13px;padding:20px">
        <?php if(!$catGas): ?>Aún no hay gastos registrados.
        <?php else: $c1=array_key_first($catGas); ?>Todos los gastos están en una sola categoría (<b><?= e($c1) ?></b>). Cuando registres gastos en categorías distintas, aquí verás el reparto.<?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="co-mid">
  <div class="co-box"><h4>Resumen por trimestres</h4>
    <table class="co-tri">
      <thead><tr><th>Trimestre</th><th>Ingresos</th><th>Gastos</th><th>Beneficio</th><th>Margen</th></tr></thead>
      <tbody>
      <?php $triN=['1T · Ene-Mar','2T · Abr-Jun','3T · Jul-Sep','4T · Oct-Dic']; for($q=0;$q<4;$q++): $ti=$triIng[$q];$tg=$triGas[$q];$tb=$ti-$tg; ?>
        <tr><td><?= $triN[$q] ?></td><td class="pos" data-l="Ingresos"><?= eur($ti) ?></td><td class="neg" data-l="Gastos"><?= eur($tg) ?></td><td class="<?= $tb>=0?'pos':'neg' ?>" data-l="Beneficio"><?= eur($tb) ?></td><td data-l="Margen"><?= $ti>0?round($tb/$ti*100):0 ?>%</td></tr>
      <?php endfor; ?>
      </tbody>
      <tfoot><tr><td>Total <?= $year ?></td><td data-l="Ingresos"><?= eur($ing) ?></td><td data-l="Gastos"><?= eur($gas) ?></td><td data-l="Beneficio"><?= eur($benef) ?></td><td data-l="Margen"><?= $ing>0?round($benef/$ing*100):0 ?>%</td></tr></tfoot>
    </table>
  </div>
  <div class="co-box"><h4>Desglose de gastos por categoría</h4>
    <?php if(!$catGas): ?><div style="color:var(--muted);font-size:13px;padding:14px 0">Sin gastos este año.</div>
    <?php else: $maxc=max($catGas); ?>
    <div class="co-catl">
      <?php foreach($catGas as $cn=>$cv): ?>
      <div>
        <div class="co-catrow"><span><?= e($cn) ?></span><b><?= eur($cv) ?></b></div>
        <div class="co-catbar"><span style="width:<?= $maxc>0?round($cv/$maxc*100):0 ?>%"></span></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="co-mid">
  <div class="co-box"><h4>Legal vs Efectivo (ingresos)</h4>
    <div class="co-line"><span>Declarado / legal</span><b><?= eur($ingLegal) ?></b></div>
    <div class="co-line"><span>Efectivo</span><b><?= eur($ingEfectivo) ?></b></div>
    <div style="height:1px;background:var(--line);margin:10px 0"></div>
    <div class="co-line"><span>Ratio declarado</span><b><?= $ing>0?round($ingLegal/$ing*100):0 ?>%</b></div>
  </div>
  <div class="co-box"><h4>Deducible de socios (año)</h4>
    <?php foreach($dedEm as $ak=>$av): ?>
      <div class="co-line"><span><?= e($AMBITOS[$ak] ?? $ak) ?></span><b><?= eur($av) ?></b></div>
    <?php endforeach; ?>
    <div style="height:1px;background:var(--line);margin:10px 0"></div>
    <div class="co-line"><span>Total deducible</span><b><?= eur($dedTot) ?></b></div>
    <div class="co-line" style="color:var(--muted);font-size:12px;margin-top:4px">Gastos personales que cada socio se desgrava (fuera de empresa).</div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function(){
  if(!window.Chart)return;
  var labels=['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
  var mi=<?= json_encode(array_map(fn($v)=>round($v,2),array_values($mesIng))) ?>;
  var mg=<?= json_encode(array_map(fn($v)=>round($v,2),array_values($mesGas))) ?>;
  var cats=<?= json_encode(array_keys($catGas), JSON_UNESCAPED_UNICODE) ?>;
  var catv=<?= json_encode(array_map(fn($v)=>round($v,2),array_values($catGas))) ?>;
  Chart.defaults.font.family='inherit';Chart.defaults.font.size=11;
  var e1=document.getElementById('chMes');
  if(e1)new Chart(e1,{type:'bar',data:{labels:labels,datasets:[{label:'Ingresos',data:mi,backgroundColor:'#12a150cc',borderRadius:5},{label:'Gastos',data:mg,backgroundColor:'#e05a4fcc',borderRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{boxWidth:12,padding:14}}},scales:{y:{beginAtZero:true,ticks:{callback:function(v){return v+' €';}}},x:{grid:{display:false}}}}});
  var e2=document.getElementById('chCat');
  if(e2){ if(cats.length){ new Chart(e2,{type:'doughnut',data:{labels:cats,datasets:[{data:catv,backgroundColor:['#2f6df6','#12a150','#e0a341','#e05a4f','#8b5cf6','#0ea5a5','#eb5a9a','#64748b','#f59e0b','#14b8a6','#a855f7','#ef4444']}]},options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{legend:{position:'right',labels:{boxWidth:12,padding:9,font:{size:10}}}}}}); } else { e2.parentNode.innerHTML='<div style="color:var(--label);font-size:13px;text-align:center;padding:40px 10px">Sin gastos este año.</div>'; } }
})();
</script>
<?php erp_foot(); ?>
