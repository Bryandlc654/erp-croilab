<?php
/* CRM v1.3 — Fase 7: Dashboard (KPIs + 13 gráficas, con filtro por rango de fechas). */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/crm_lib.php';
ensure_crm_schema();

$STAGES   = crm_stages();
$MOTIVOS  = crm_motivos_perdida();
$ORIGENES = crm_origenes();
$responsables = db()->query('SELECT id, username FROM admins ORDER BY username')->fetchAll();
$respMap=[]; foreach($responsables as $r) $respMap[(int)$r['id']]=$r['username'];

function q1($sql){ try{ return db()->query($sql)->fetchColumn(); }catch(Exception $e){ return 0; } }
function qall($sql){ try{ return db()->query($sql)->fetchAll(); }catch(Exception $e){ return []; } }

/* ---- Filtro por rango de fechas (GET: d1=desde, d2=hasta, formato YYYY-MM-DD) ----
   Por defecto no hay rango: se muestra "Todo" (el comportamiento de siempre). */
$d1 = (string)($_GET['d1'] ?? '');
$d2 = (string)($_GET['d2'] ?? '');
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$d1)) $d1='';
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$d2)) $d2='';
$hayRango = ($d1!=='' || $d2!=='');
/* Fragmento SQL reutilizable: acota una columna de fecha al rango elegido.
   Si no hay rango, devuelve cadena vacía y la consulta no se filtra. */
function rango($col){ global $d1,$d2; $s='';
  if($d1!=='') $s.=" AND $col>='".$d1." 00:00:00'";
  if($d2!=='') $s.=" AND $col<='".$d2." 23:59:59'";
  return $s; }

/* Atajos rápidos de rango (para no teclear fechas). */
$hoy = date('Y-m-d');
$ATAJOS = [
  ['Este mes',        date('Y-m-01'),                       $hoy],
  ['Últimos 3 meses', date('Y-m-d',strtotime('-3 month')),  $hoy],
  ['Este año',        date('Y-01-01'),                      $hoy],
  ['Todo',            '',                                    ''],
];

/* ---- KPIs ---- */
$kTotal   = (int)q1('SELECT COUNT(*) FROM contacts WHERE 1=1'.rango('fecha_creacion'));
$kAbiertos= (int)q1("SELECT COUNT(*) FROM deals WHERE fase IN (SELECT slug FROM pipeline_stages WHERE tipo='abierta')".rango('fecha_creacion'));
$kPipeline= (float)q1("SELECT COALESCE(SUM(valor),0) FROM deals WHERE fase IN (SELECT slug FROM pipeline_stages WHERE tipo='abierta')".rango('fecha_creacion'));
$kGan     = (int)q1("SELECT COUNT(*) FROM deals WHERE fase='ganado'".rango('fecha_cierre_real'));
$kPerd    = (int)q1("SELECT COUNT(*) FROM deals WHERE fase='perdido'".rango('fecha_cierre_real'));
$kConv    = ($kGan+$kPerd)>0?round($kGan*100/($kGan+$kPerd)):0;
$kTicket  = (float)q1("SELECT COALESCE(AVG(valor),0) FROM deals WHERE fase='ganado' AND valor IS NOT NULL".rango('fecha_cierre_real'));
$kGanVal  = (float)q1("SELECT COALESCE(SUM(valor),0) FROM deals WHERE fase='ganado'".rango('fecha_cierre_real'));


/* ---- Series de meses ----
   Con rango: los meses del propio rango (tope 36 para no desbordar la gráfica).
   Sin rango: los últimos 6, como siempre. */
$meses=[];
if($hayRango){
  $ini = $d1!=='' ? date('Y-m-01',strtotime($d1)) : date('Y-m-01',strtotime($d2.' -5 month'));
  $fin = $d2!=='' ? date('Y-m-01',strtotime($d2)) : date('Y-m-01');
  $cur=$ini; $g=0;
  while($cur<=$fin && $g<36){ $meses[]=substr($cur,0,7); $cur=date('Y-m-01',strtotime($cur.' +1 month')); $g++; }
  if(!$meses) $meses[]=substr($ini,0,7);
}else{
  for($i=5;$i>=0;$i--){ $meses[]=date('Y-m',strtotime("first day of -$i month")); }
}
$fmtMes = count($meses)>7 ? 'M y' : 'M';
$mesLbl=array_map(fn($m)=>date($fmtMes,strtotime($m.'-01')),$meses);

/* 1. Embudo (funnel): deals abiertos+ganados por fase en orden */
$funnelL=[];$funnelV=[];$funnelC=[];
foreach($STAGES as $slug=>$s){ if(in_array($s['tipo'],['abierta','ganada'],true)){ $n=(int)q1("SELECT COUNT(*) FROM deals WHERE fase='".$slug."'".rango('fecha_creacion')); $funnelL[]=$s['nombre']; $funnelV[]=$n; $funnelC[]=$s['color']; } }

/* 2. Valor pipeline por fase (abiertas) */
$vfL=[];$vfV=[];$vfC=[];
foreach($STAGES as $slug=>$s){ if($s['tipo']==='abierta'){ $v=(float)q1("SELECT COALESCE(SUM(valor),0) FROM deals WHERE fase='".$slug."'".rango('fecha_creacion')); $vfL[]=$s['nombre']; $vfV[]=round($v); $vfC[]=$s['color']; } }

/* 3. Ganados vs perdidos por mes */
$ganM=[];$perdM=[];
foreach($meses as $m){ $ganM[]=(int)q1("SELECT COUNT(*) FROM deals WHERE fase='ganado' AND DATE_FORMAT(fecha_cierre_real,'%Y-%m')='$m'"); $perdM[]=(int)q1("SELECT COUNT(*) FROM deals WHERE fase='perdido' AND DATE_FORMAT(fecha_cierre_real,'%Y-%m')='$m'"); }

/* 4. Tasa conversión mensual */
$convM=[]; foreach($meses as $i=>$m){ $g=$ganM[$i]; $p=$perdM[$i]; $convM[]=($g+$p)>0?round($g*100/($g+$p)):0; }

/* 5. Contactos por origen */
$oL=[];$oV=[]; foreach(qall("SELECT origen_lead, COUNT(*) c FROM contacts WHERE origen_lead IS NOT NULL AND origen_lead<>''".rango('fecha_creacion')." GROUP BY origen_lead ORDER BY c DESC") as $r){ $oL[]=$r['origen_lead']; $oV[]=(int)$r['c']; }

/* 6. Contactos por sector */
$sL=[];$sV=[]; foreach(qall("SELECT sector, COUNT(*) c FROM contacts WHERE sector IS NOT NULL AND sector<>''".rango('fecha_creacion')." GROUP BY sector ORDER BY c DESC LIMIT 8") as $r){ $sL[]=$r['sector']; $sV[]=(int)$r['c']; }

/* 7. Motivos de pérdida */
$mL=[];$mV=[]; foreach(qall("SELECT motivo_perdida, COUNT(*) c FROM deals WHERE fase='perdido' AND motivo_perdida IS NOT NULL".rango('fecha_cierre_real')." GROUP BY motivo_perdida ORDER BY c DESC") as $r){ $mL[]=($MOTIVOS[$r['motivo_perdida']][0]??$r['motivo_perdida']); $mV[]=(int)$r['c']; }

/* 8. Negocios por propietario (abiertos) */
$prL=[];$prV=[]; foreach(qall("SELECT propietario_id, COUNT(*) c FROM deals WHERE fase IN (SELECT slug FROM pipeline_stages WHERE tipo='abierta')".rango('fecha_creacion')." GROUP BY propietario_id ORDER BY c DESC") as $r){ $prL[]=($respMap[(int)$r['propietario_id']]??'Sin asignar'); $prV[]=(int)$r['c']; }

/* 9. Valor ganado por mes */
$vgM=[]; foreach($meses as $m){ $vgM[]=round((float)q1("SELECT COALESCE(SUM(valor),0) FROM deals WHERE fase='ganado' AND DATE_FORMAT(fecha_cierre_real,'%Y-%m')='$m'")); }

/* 10. Contactos nuevos por mes */
$cnM=[]; foreach($meses as $m){ $cnM[]=(int)q1("SELECT COUNT(*) FROM contacts WHERE DATE_FORMAT(fecha_creacion,'%Y-%m')='$m'"); }

/* 11. Servicios más presupuestados */
$svCount=[]; foreach(qall("SELECT servicio_json FROM contacts WHERE servicio_json IS NOT NULL AND servicio_json<>''".rango('fecha_creacion')) as $r){ $a=json_decode((string)$r['servicio_json'],true); if(is_array($a))foreach($a as $s){ $svCount[$s]=($svCount[$s]??0)+1; } }
arsort($svCount); $svL=array_keys($svCount); $svV=array_values($svCount);

/* 12. Distribución contactos por embudo */
$dfL=[];$dfV=[];$dfC=[]; foreach($STAGES as $slug=>$s){ $n=(int)q1("SELECT COUNT(*) FROM contacts WHERE fase='".$slug."'".rango('fecha_creacion')); if($n>0){ $dfL[]=$s['nombre']; $dfV[]=$n; $dfC[]=$s['color']; } }

/* 13. Estancamiento: días medios en fase (abiertas) */
$stL=[];$stV=[]; foreach($STAGES as $slug=>$s){ if($s['tipo']==='abierta'){ $d=(float)q1("SELECT COALESCE(AVG(DATEDIFF(CURDATE(),fecha_entrada_fase)),0) FROM deals WHERE fase='".$slug."' AND fecha_entrada_fase IS NOT NULL".rango('fecha_creacion')); $stL[]=$s['nombre']; $stV[]=round($d); } }

$PAL=['#5b8def','#12a150','#f0872a','#e0a000','#7c9cf5','#ef4444','#12854a','#94a3b8','#a855f7','#06b6d4'];

erp_head('crm', 'CRM · Dashboard');
?>
<style>
.db-top h1{font-size:24px;margin-bottom:18px}
.db-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
@media(max-width:1000px){.db-kpis{grid-template-columns:repeat(2,1fr)}}
.db-kpi{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px 20px}
.db-kpi .lb{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:650}
.db-kpi .vl{font-size:22px;font-weight:750;color:var(--ink-strong);margin-top:5px;letter-spacing:-.5px}
.db-kpi .sub{font-size:11.5px;color:var(--muted);margin-top:2px}
/* Rejilla flexible: las tarjetas reflowen solas según el ancho disponible. El
   min-width:0 es clave: sin él, las gráficas de Chart.js empujan la columna por
   debajo de su ancho mínimo y aparecía scroll horizontal a 1024px. */
.db-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
@media(max-width:900px){.db-grid{grid-template-columns:1fr}}
.db-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px 22px;min-width:0}
.db-card.wide{grid-column:1/-1;min-width:0;max-width:100%}
.db-card h3{font-size:15px;font-weight:650;color:var(--ink-strong);margin:0 0 16px}
.db-cv{position:relative;height:240px;min-width:0}
.db-cv canvas{max-width:100%;min-width:0}
.db-empty{color:var(--muted);font-size:12.5px;text-align:center;padding:60px 0}
.db-filtro{display:flex;flex-wrap:wrap;align-items:center;gap:10px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin-bottom:20px}
.db-filtro .grp{display:flex;align-items:center;gap:7px}
.db-filtro label{font-size:12px;color:var(--muted);font-weight:650}
.db-filtro input.dpick{width:100px;border:1px solid var(--line);border-radius:9px;padding:7px 9px;font-size:13px;color:var(--ink-strong);background:#fff}
.db-filtro .apl{border:0;background:var(--accent);color:#fff;border-radius:9px;padding:8px 14px;font-size:13px;font-weight:700;cursor:pointer}
.db-filtro .sep{flex:1 1 auto}
.db-atajos{display:flex;flex-wrap:wrap;gap:6px}
.db-atajo{border:1px solid var(--line);background:#fff;color:var(--ink);border-radius:999px;padding:6px 12px;font-size:12.5px;font-weight:600;text-decoration:none;cursor:pointer}
.db-atajo:hover{background:var(--soft)}
.db-atajo.on{background:var(--accent);border-color:var(--accent);color:#fff}
/* ---- Modo oscuro (capa aditiva: solo remapea superficies y textos propios) ---- */
[data-theme=dark] .db-kpi,[data-theme=dark] .db-card,
[data-theme=dark] .db-filtro,[data-theme=dark] .db-atajo{background-color:var(--card)}
[data-theme=dark] .db-filtro input.dpick{background-color:var(--field)}
[data-theme=dark] .db-filtro .apl,[data-theme=dark] .db-atajo.on{background-color:var(--rev);color:var(--rev-fg);border-color:var(--rev)}
/* ============ MÓVIL (≤640px) ============ */
@media(max-width:640px){
  .db-top h1{font-size:21px;margin-bottom:14px}
  /* KPIs compactos: siempre 2 por fila, cajas pequeñas (no gigantes). */
  .db-kpis{grid-template-columns:repeat(2,1fr);gap:9px}
  .db-kpi{padding:12px 13px}
  .db-kpi .vl{font-size:18px}
  .db-grid{gap:12px}
  .db-card{padding:16px 14px}
  .db-cv{height:220px}
  .db-filtro{padding:13px 14px;gap:8px}
  .db-filtro .grp{flex:1 1 auto}
  .db-filtro input.dpick{width:100%}
  .db-filtro .apl{flex:1 1 100%}
}
</style>

<div class="db-top"><h1>Dashboard</h1></div>

<form class="db-filtro" method="get" id="dbFiltro">
  <input type="hidden" name="d1" id="d1v" value="<?= e($d1) ?>">
  <input type="hidden" name="d2" id="d2v" value="<?= e($d2) ?>">
  <div class="grp"><label>Desde</label><input class="dpick" data-iso="<?= e($d1) ?>" data-sync="#d1v"></div>
  <div class="grp"><label>Hasta</label><input class="dpick" data-iso="<?= e($d2) ?>" data-sync="#d2v"></div>
  <button type="submit" class="apl">Aplicar</button>
  <span class="sep"></span>
  <div class="db-atajos">
    <?php foreach($ATAJOS as $a): $act = ($d1===$a[1] && $d2===$a[2]); ?>
      <a class="db-atajo<?= $act?' on':'' ?>" href="?<?= ($a[1]===''&&$a[2]==='')?'':'d1='.$a[1].'&d2='.$a[2] ?>"><?= e($a[0]) ?></a>
    <?php endforeach; ?>
  </div>
</form>

<div class="db-kpis">
  <div class="db-kpi"><div class="lb">Contactos</div><div class="vl"><?= $kTotal ?></div><div class="sub"><?= $hayRango?'en el periodo':'total en CRM' ?></div></div>
  <div class="db-kpi"><div class="lb">Negocios abiertos</div><div class="vl"><?= $kAbiertos ?></div><div class="sub"><?= eur0($kPipeline) ?> en pipeline</div></div>
  <div class="db-kpi"><div class="lb">Ganado</div><div class="vl"><?= eur0($kGanVal) ?></div><div class="sub"><?= $kGan ?> ganados<?= $hayRango?' en el periodo':' histórico' ?></div></div>
  <div class="db-kpi"><div class="lb">Tasa conversión</div><div class="vl"><?= $kConv ?>%</div><div class="sub"><?= $kGan ?> ganados · <?= $kPerd ?> perdidos</div></div>
  <div class="db-kpi"><div class="lb">Ticket medio</div><div class="vl"><?= eur0($kTicket ?: 0) ?></div><div class="sub">negocios ganados</div></div>
  <div class="db-kpi"><div class="lb">Negocios perdidos</div><div class="vl"><?= $kPerd ?></div><div class="sub"><?= $hayRango?'en el periodo':'histórico' ?></div></div>
  <div class="db-kpi"><div class="lb">Cierres previstos</div><div class="vl"><?= (int)q1("SELECT COUNT(*) FROM deals WHERE fase IN (SELECT slug FROM pipeline_stages WHERE tipo='abierta') AND fecha_cierre_prevista IS NOT NULL AND fecha_cierre_prevista<=(CURDATE()+INTERVAL 30 DAY)") ?></div><div class="sub">próximos 30 días</div></div>
</div>

<div class="db-grid">
  <div class="db-card wide"><h3>Embudo de venta</h3><div class="db-cv"><canvas id="c1"></canvas></div></div>
  <div class="db-card"><h3>Valor en pipeline por fase</h3><div class="db-cv"><canvas id="c2"></canvas></div></div>
  <div class="db-card"><h3>Ganados vs perdidos por mes</h3><div class="db-cv"><canvas id="c3"></canvas></div></div>
  <div class="db-card"><h3>Tasa de conversión mensual</h3><div class="db-cv"><canvas id="c4"></canvas></div></div>
  <div class="db-card"><h3>Valor ganado por mes</h3><div class="db-cv"><canvas id="c10"></canvas></div></div>
  <div class="db-card"><h3>Contactos nuevos por mes</h3><div class="db-cv"><canvas id="c11"></canvas></div></div>
  <div class="db-card"><h3>Contactos por origen</h3><div class="db-cv"><canvas id="c5"></canvas></div></div>
  <div class="db-card"><h3>Contactos por sector</h3><div class="db-cv"><canvas id="c6"></canvas></div></div>
  <div class="db-card"><h3>Motivos de pérdida</h3><div class="db-cv"><canvas id="c8"></canvas></div></div>
  <div class="db-card"><h3>Negocios abiertos por propietario</h3><div class="db-cv"><canvas id="c9"></canvas></div></div>
  <div class="db-card"><h3>Servicios más presupuestados</h3><div class="db-cv"><canvas id="c12"></canvas></div></div>
  <div class="db-card"><h3>Contactos por fase del embudo</h3><div class="db-cv"><canvas id="c13"></canvas></div></div>
  <div class="db-card"><h3>Días medios en cada fase</h3><div class="db-cv"><canvas id="c14"></canvas></div></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
var PAL=<?= json_encode($PAL) ?>;
Chart.defaults.font.family="inherit"; Chart.defaults.font.size=11.5; Chart.defaults.color="#9aa0a8";
Chart.defaults.plugins.legend.labels.boxWidth=12; Chart.defaults.plugins.legend.labels.padding=12;
function grid(){return{grid:{color:'#f0f0f2'},ticks:{color:'#9aa0a8'}};}
function bar(id,labels,data,colors,horizontal){var el=document.getElementById(id);if(!el)return;
  if(!data.length||data.every(function(v){return v==0;})){el.parentNode.innerHTML='<div class="db-empty">Sin datos todavía</div>';return;}
  new Chart(el,{type:'bar',data:{labels:labels,datasets:[{data:data,backgroundColor:colors||PAL[0],borderRadius:6,maxBarThickness:46}]},
    options:{indexAxis:horizontal?'y':'x',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:grid(),y:grid()}}});}
function money(id,labels,data,color){var el=document.getElementById(id);if(!el)return;
  if(!data.length||data.every(function(v){return v==0;})){el.parentNode.innerHTML='<div class="db-empty">Sin datos todavía</div>';return;}
  new Chart(el,{type:'bar',data:{labels:labels,datasets:[{data:data,backgroundColor:color||'#12a150',borderRadius:6,maxBarThickness:46}]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return new Intl.NumberFormat('es-ES').format(c.parsed.y)+' €';}}}},scales:{x:grid(),y:Object.assign(grid(),{ticks:{color:'#9aa0a8',callback:function(v){return new Intl.NumberFormat('es-ES',{notation:'compact'}).format(v)+'€';}}})}}});}
function doughnut(id,labels,data){var el=document.getElementById(id);if(!el)return;
  if(!data.length){el.parentNode.innerHTML='<div class="db-empty">Sin datos todavía</div>';return;}
  new Chart(el,{type:'doughnut',data:{labels:labels,datasets:[{data:data,backgroundColor:PAL,borderWidth:2,borderColor:'#fff'}]},
    options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{legend:{position:'right'}}}});}
function lines(id,labels,datasets){var el=document.getElementById(id);if(!el)return;
  new Chart(el,{type:'line',data:{labels:labels,datasets:datasets},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:datasets.length>1}},scales:{x:grid(),y:Object.assign(grid(),{beginAtZero:true})}}});}

bar('c1',<?= json_encode($funnelL) ?>,<?= json_encode($funnelV) ?>,<?= json_encode($funnelC) ?>,true);
money('c2',<?= json_encode($vfL) ?>,<?= json_encode($vfV) ?>,'#5b8def');
(function(){var el=document.getElementById('c3');new Chart(el,{type:'bar',data:{labels:<?= json_encode($mesLbl) ?>,datasets:[{label:'Ganados',data:<?= json_encode($ganM) ?>,backgroundColor:'#12a150',borderRadius:5,maxBarThickness:22},{label:'Perdidos',data:<?= json_encode($perdM) ?>,backgroundColor:'#ef4444',borderRadius:5,maxBarThickness:22}]},options:{responsive:true,maintainAspectRatio:false,scales:{x:grid(),y:Object.assign(grid(),{beginAtZero:true})}}});})();
lines('c4',<?= json_encode($mesLbl) ?>,[{label:'Conversión',data:<?= json_encode($convM) ?>,borderColor:'#5b8def',backgroundColor:'rgba(91,141,239,.1)',fill:true,tension:.35}]);
money('c10',<?= json_encode($mesLbl) ?>,<?= json_encode($vgM) ?>,'#12a150');
lines('c11',<?= json_encode($mesLbl) ?>,[{label:'Nuevos',data:<?= json_encode($cnM) ?>,borderColor:'#f0872a',backgroundColor:'rgba(240,135,42,.1)',fill:true,tension:.35}]);
doughnut('c5',<?= json_encode($oL) ?>,<?= json_encode($oV) ?>);
bar('c6',<?= json_encode($sL) ?>,<?= json_encode($sV) ?>,'#7c9cf5',true);
doughnut('c8',<?= json_encode($mL) ?>,<?= json_encode($mV) ?>);
bar('c9',<?= json_encode($prL) ?>,<?= json_encode($prV) ?>,'#06b6d4',true);
bar('c12',<?= json_encode($svL) ?>,<?= json_encode($svV) ?>,'#a855f7',true);
doughnut('c13',<?= json_encode($dfL) ?>,<?= json_encode($dfV) ?>);
bar('c14',<?= json_encode($stL) ?>,<?= json_encode($stV) ?>,'#e0a000');
</script>

<?php erp_foot(); ?>
