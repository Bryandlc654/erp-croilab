<?php
/* Resumen mensual: lo que ha entrado y lo que queda limpio tras impuestos.
   Estética minimal (Apple / ClickUp). */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/fin_prog.php';   // la lista de emisores

try{ if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='accounting' AND column_name='personal'")->fetchColumn()) db()->exec("ALTER TABLE accounting ADD COLUMN personal TINYINT NOT NULL DEFAULT 0"); }catch(Exception $e){}

$MESES = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$MESESC = [1=>'Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$AMBITOS = ['empresa'=>'Empresa'] + fin_emisores();
$ESTCOL = ['pagada'=>'#12854a','enviada'=>'#2f6df6','borrador'=>'#98a2b3','vencida'=>'#e5484d'];
$ESTLBL = ['pagada'=>'Pagada','enviada'=>'Enviada','borrador'=>'Borrador','vencida'=>'Vencida'];

/* También valen los emisores dados de baja que aún tienen facturas emitidas:
   su resumen de meses pasados tiene que seguir consultándose. */
$emGet = (string)($_GET['em'] ?? '');
$vistos = []; try{ foreach(db()->query('SELECT DISTINCT emisor FROM invoices') as $r) $vistos[]=(string)$r['emisor']; }catch(Exception $e){}
$view = (isset($AMBITOS[$emGet]) || in_array($emGet,$vistos,true)) ? $emGet : 'empresa';
foreach($vistos as $vk) if($vk!=='' && !isset($AMBITOS[$vk])) $AMBITOS[$vk] = $vk.' (baja)';
$isEmpresa = ($view==='empresa');

$m = (isset($_GET['m']) && preg_match('/^\d{4}-\d{2}$/',$_GET['m'])) ? $_GET['m'] : date('Y-m');
$prev = date('Y-m', strtotime($m.'-01 -1 month'));
$next = date('Y-m', strtotime($m.'-01 +1 month'));
[$yy,$mm] = array_map('intval', explode('-',$m));
$mLabel = ($MESES[$mm]??'').' '.$yy;

function fr_month($ym,$isEmpresa,$view){
  $start=$ym.'-01'; $end=date('Y-m-t',strtotime($start));
  if($isEmpresa){ $iq=db()->prepare("SELECT i.iva_pct,i.irpf_pct,COALESCE((SELECT SUM(cantidad*precio) FROM invoice_items WHERE invoice_id=i.id),0) base FROM invoices i WHERE i.personal=0 AND i.fecha BETWEEN ? AND ?"); $iq->execute([$start,$end]); }
  else{ $iq=db()->prepare("SELECT i.iva_pct,i.irpf_pct,COALESCE((SELECT SUM(cantidad*precio) FROM invoice_items WHERE invoice_id=i.id),0) base FROM invoices i WHERE i.emisor=? AND i.fecha BETWEEN ? AND ?"); $iq->execute([$view,$start,$end]); }
  $base=0;$iva=0;$irpf=0;$n=0; foreach($iq as $r){ $b=(float)$r['base']; $base+=$b; $iva+=$b*(float)$r['iva_pct']/100; $irpf+=$b*(float)$r['irpf_pct']/100; $n++; }
  if($isEmpresa){ $gq=db()->prepare("SELECT COALESCE(SUM(importe),0) g FROM accounting WHERE tipo='gasto' AND personal=0 AND fecha BETWEEN ? AND ?"); $gq->execute([$start,$end]); }
  else{ $gq=db()->prepare("SELECT COALESCE(SUM(importe),0) g FROM accounting WHERE tipo='gasto' AND ambito=? AND fecha BETWEEN ? AND ?"); $gq->execute([$view,$start,$end]); }
  $g=(float)$gq->fetchColumn();
  return ['base'=>$base,'iva'=>$iva,'irpf'=>$irpf,'gastos'=>$g,'n'=>$n,'cobrado'=>$base+$iva-$irpf,'impuestos'=>$iva+$irpf,'neto'=>$base-$g-$irpf];
}
$M=fr_month($m,$isEmpresa,$view);
$P=fr_month($prev,$isEmpresa,$view);
$base=$M['base'];$iva=$M['iva'];$irpf=$M['irpf'];$gastos=$M['gastos'];$nfac=$M['n'];
$cobrado=$M['cobrado'];$impuestos=$M['impuestos'];$neto=$M['neto'];$trasImp=$base-$irpf;
/* La insignia de variación solo tiene sentido si el mes EN CURSO ya tiene datos:
   antes, a principio de mes (aún sin facturas) salía un «-100 %» en rojo alarmante
   comparando con el mes anterior, como si algo hubiera ido fatal (P3-15). */
$dCob = ($P['cobrado']>0 && $M['cobrado']>0) ? round(($M['cobrado']-$P['cobrado'])/$P['cobrado']*100) : null;
$dNet = ($P['neto']!=0 && $M['neto']!=0) ? round(($M['neto']-$P['neto'])/abs($P['neto'])*100) : null;

/* tendencia 12 meses (base) */
$start12=date('Y-m-01',strtotime($m.'-01 -11 months')); $end1=date('Y-m-t',strtotime($m.'-01'));
if($isEmpresa){ $tq=db()->prepare("SELECT DATE_FORMAT(i.fecha,'%Y-%m') ym, COALESCE(SUM(it.cantidad*it.precio),0) base FROM invoices i LEFT JOIN invoice_items it ON it.invoice_id=i.id WHERE i.personal=0 AND i.fecha BETWEEN ? AND ? GROUP BY ym"); $tq->execute([$start12,$end1]); }
else{ $tq=db()->prepare("SELECT DATE_FORMAT(i.fecha,'%Y-%m') ym, COALESCE(SUM(it.cantidad*it.precio),0) base FROM invoices i LEFT JOIN invoice_items it ON it.invoice_id=i.id WHERE i.emisor=? AND i.fecha BETWEEN ? AND ? GROUP BY ym"); $tq->execute([$view,$start12,$end1]); }
$tmap=[]; foreach($tq as $r) $tmap[$r['ym']]=(float)$r['base'];
$trLabels=[];$trData=[]; for($i=11;$i>=0;$i--){ $d=strtotime($m.'-01 -'.$i.' months'); $k=date('Y-m',$d); $trLabels[]=$MESESC[(int)date('n',$d)].' '.date('y',$d); $trData[]=round($tmap[$k]??0,2); }

/* facturas del mes */
$mStart=$m.'-01'; $mEnd=date('Y-m-t',strtotime($mStart));
if($isEmpresa){ $fq=db()->prepare("SELECT id,numero,cliente_nombre,estado,fecha,iva_pct,irpf_pct,COALESCE((SELECT SUM(cantidad*precio) FROM invoice_items WHERE invoice_id=invoices.id),0) base FROM invoices WHERE personal=0 AND fecha BETWEEN ? AND ? ORDER BY fecha DESC, id DESC"); $fq->execute([$mStart,$mEnd]); }
else{ $fq=db()->prepare("SELECT id,numero,cliente_nombre,estado,fecha,iva_pct,irpf_pct,COALESCE((SELECT SUM(cantidad*precio) FROM invoice_items WHERE invoice_id=invoices.id),0) base FROM invoices WHERE emisor=? AND fecha BETWEEN ? AND ? ORDER BY fecha DESC, id DESC"); $fq->execute([$view,$mStart,$mEnd]); }
$facs=$fq->fetchAll();

erp_head('resumen', 'Resumen mensual');
?>
<style>
.rs-head{display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap}.rs-head h1{flex:1}
.co-scope{display:flex;gap:4px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:3px}
.co-scope a{padding:7px 15px;border-radius:8px;font-size:13px;font-weight:600;color:var(--muted);cursor:pointer}
.co-scope a.on{background:var(--ink-strong);color:#fff}.co-scope a:hover:not(.on){background:var(--soft)}
.rs-month{display:flex;align-items:center;gap:2px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:4px}
.rs-month a{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;color:var(--ink);text-decoration:none;font-size:17px}
.rs-month a:hover{background:var(--soft)}
.rs-month .lbl{font-weight:600;font-size:13.5px;min-width:140px;text-align:center;color:var(--ink-strong);text-transform:capitalize}
.rs-month .today{font-size:12px;color:var(--accent);font-weight:600;padding:0 8px;text-decoration:none}
.rs-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:22px}
@media(max-width:820px){.rs-cards{grid-template-columns:1fr}}
.rs-c{border:1px solid var(--line);border-radius:16px;padding:22px 24px;background:#fff}
.rs-c.in{background:#f4faf6}.rs-c.tax{background:#fbf5f3}.rs-c.net{background:#f3f6fc}
.rs-c .top{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.rs-c .ic{width:38px;height:38px;border-radius:11px;background:var(--ink-strong);color:#fff;display:flex;align-items:center;justify-content:center}
.rs-c .ic svg{width:18px;height:18px}
.rs-c .l{font-size:12.5px;color:var(--muted);font-weight:500;flex:1}
.rs-c .dlt{font-size:11.5px;font-weight:600;padding:3px 9px;border-radius:99px;background:#fff;border:1px solid var(--line)}
.rs-c .dlt.up{color:#12854a}.rs-c .dlt.down{color:#e5484d}
.rs-c .n{font-size:29px;font-weight:750;letter-spacing:-1px;color:var(--ink-strong)}
.rs-c .s{font-size:12px;color:var(--muted);margin-top:7px;line-height:1.5}
.rs-grid{display:grid;grid-template-columns:1.7fr 1fr;gap:18px;margin-bottom:22px}
@media(max-width:980px){.rs-grid{grid-template-columns:1fr}}
.rs-panel{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px 24px}
.rs-panel .ph{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.rs-panel h4{font-size:15px;font-weight:600;color:var(--ink-strong)}
.rs-panel .psub{font-size:12px;color:var(--muted)}
.ch-box{position:relative;height:270px}
.rs-list{display:flex;flex-direction:column}
.rs-li{display:flex;align-items:center;gap:11px;padding:11px 0;border-bottom:1px solid var(--line2)}
.rs-li:last-child{border-bottom:none}
.rs-av{width:34px;height:34px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12.5px;font-weight:700}
.rs-li-txt{display:flex;flex-direction:column;min-width:0;flex:1}
.rs-li-n{font-weight:600;font-size:13px;color:var(--ink-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rs-li-s{font-size:11.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rs-li-r{text-align:right;flex:none}
.rs-li-a{font-weight:650;font-size:13px;color:var(--ink-strong);display:block}
.rs-est{font-size:11px;font-weight:600}
.rs-empty{color:var(--muted);font-size:13px;text-align:center;padding:30px 0}
.wf{display:flex;flex-direction:column;gap:2px}
.wf .row{display:flex;justify-content:space-between;align-items:center;padding:13px 2px;font-size:14px;border-bottom:1px solid var(--line2)}
.wf .row:last-child{border-bottom:none}
.wf .row .t{color:var(--ink)}.wf .row .v{font-weight:650;font-variant-numeric:tabular-nums;color:var(--ink-strong)}
.wf .row.tot{border-top:1.5px solid var(--line);margin-top:4px;padding-top:14px}
.wf .row.tot .t{font-weight:700;color:var(--ink-strong)}.wf .row.tot .v{font-size:17px}
.rs-note{color:var(--muted);font-size:11.5px;margin-top:12px;line-height:1.5}
[data-theme=dark] .co-scope{background-color:var(--card)}
[data-theme=dark] .co-scope a.on{background-color:var(--rev);color:var(--rev-fg)}
[data-theme=dark] .rs-month{background-color:var(--card)}
[data-theme=dark] .rs-c{background-color:var(--card)}
[data-theme=dark] .rs-c.in,[data-theme=dark] .rs-c.tax,[data-theme=dark] .rs-c.net{background-color:var(--soft)}
[data-theme=dark] .rs-c .ic{background-color:var(--rev);color:var(--rev-fg)}
[data-theme=dark] .rs-c .dlt{background-color:var(--card)}
[data-theme=dark] .rs-panel{background-color:var(--card)}
/* ---- Móvil (teléfono) ---- */
@media(max-width:640px){
  .rs-head{gap:10px}
  .rs-head h1{flex:1 1 100%;font-size:22px}
  .rs-head .lead{order:5;flex:1 1 100%}
  .co-scope{max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;flex-wrap:nowrap}
  .co-scope a{white-space:nowrap;flex:none}
  .rs-month{flex:1 1 100%;justify-content:center}
  .rs-month .lbl{min-width:0;flex:1}
  /* Las 3 tarjetas de KPI = 2 por fila, compactas (menos scroll) */
  .rs-cards{grid-template-columns:1fr 1fr;gap:12px}
  .rs-c{padding:14px 14px;border-radius:14px}
  .rs-c .top{flex-wrap:wrap;gap:8px;margin-bottom:10px}
  .rs-c .ic{width:30px;height:30px;border-radius:9px}
  .rs-c .ic svg{width:15px;height:15px}
  .rs-c .n{font-size:21px}
  .rs-c .s{font-size:11px;margin-top:5px}
  .rs-panel{padding:18px 18px}
  .ch-box{height:230px}
  .rs-panel[style]{max-width:100%!important}
}
</style>

<div class="rs-head">
  <h1>Resumen mensual</h1>
  <div class="lead" style="color:var(--muted);font-size:12.5px;margin:2px 0 4px;max-width:70ch">Basado en lo <b>facturado</b> (devengo): cuenta todas las facturas emitidas del mes, se hayan cobrado o no. Lo <b>realmente cobrado</b> (caja) está en <a href="contabilidad.php" style="color:var(--accent);font-weight:600">Contabilidad</a> — por eso las dos cifras no tienen por qué coincidir.</div>
  <div class="co-scope">
    <a href="fin-resumen.php?m=<?= $m ?>&em=empresa" class="<?= $isEmpresa?'on':'' ?>">Hub</a>
    <?php foreach($AMBITOS as $ak=>$an): if($ak==='empresa') continue; ?>
      <a href="fin-resumen.php?m=<?= $m ?>&em=<?= urlencode($ak) ?>" class="<?= $view===$ak?'on':'' ?>"><?= e($an) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="rs-month">
    <a href="fin-resumen.php?m=<?= $prev ?>&em=<?= $view ?>" title="Mes anterior">‹</a>
    <span class="lbl"><?= e($mLabel) ?></span>
    <a href="fin-resumen.php?m=<?= $next ?>&em=<?= $view ?>" title="Mes siguiente">›</a>
    <?php if($m!==date('Y-m')): ?><a class="today" href="fin-resumen.php?em=<?= $view ?>">Hoy</a><?php endif; ?>
  </div>
</div>

<div class="rs-cards">
  <div class="rs-c in">
    <div class="top"><div class="ic"><?= ic('euro',18) ?></div><div class="l">Ha entrado</div><?php if($dCob!==null): ?><div class="dlt <?= $dCob>=0?'up':'down' ?>"><?= ($dCob>=0?'+':'').$dCob ?>%</div><?php endif; ?></div>
    <div class="n"><?= eur($cobrado) ?></div>
    <div class="s">Cobrado en cuenta · <?= $nfac ?> factura<?= $nfac==1?'':'s' ?></div>
  </div>
  <div class="rs-c tax">
    <div class="top"><div class="ic"><?= ic('file',18) ?></div><div class="l">Para Hacienda</div></div>
    <div class="n"><?= eur($impuestos) ?></div>
    <div class="s">IVA <?= eur($iva) ?> · IRPF <?= eur($irpf) ?></div>
  </div>
  <div class="rs-c net">
    <div class="top"><div class="ic"><?= ic('grid',18) ?></div><div class="l">Neto (limpio)</div><?php if($dNet!==null): ?><div class="dlt <?= $dNet>=0?'up':'down' ?>"><?= ($dNet>=0?'+':'').$dNet ?>%</div><?php endif; ?></div>
    <div class="n"><?= eur($neto) ?></div>
    <div class="s">Tras impuestos y gastos</div>
  </div>
</div>

<div class="rs-grid">
  <div class="rs-panel">
    <div class="ph"><h4>Ingresos por mes</h4><span class="psub">Últimos 12 meses · base</span></div>
    <div class="ch-box"><canvas id="chMes"></canvas></div>
  </div>
  <div class="rs-panel">
    <div class="ph"><h4>Facturas del mes</h4><span class="psub"><?= $nfac ?></span></div>
    <div class="rs-list">
      <?php if(!$facs): ?><div class="rs-empty">Sin facturas en <?= e($mLabel) ?>.</div>
      <?php else: foreach($facs as $fc): $tot=$fc['base']*(1+$fc['iva_pct']/100-$fc['irpf_pct']/100); $nm=$fc['cliente_nombre']?:'—'; $est=$fc['estado']?:'borrador'; ?>
      <a class="rs-li" href="facturas.php?v=<?= (int)$fc['id'] ?>" style="text-decoration:none">
        <span class="rs-av" style="background:<?= avatar_color($nm) ?>"><?= e(mb_strtoupper(mb_substr($nm,0,1))) ?></span>
        <div class="rs-li-txt"><span class="rs-li-n"><?= e($nm) ?></span><span class="rs-li-s"><?= e($fc['numero']) ?> · <?= e(date('d/m',strtotime($fc['fecha']))) ?></span></div>
        <div class="rs-li-r"><span class="rs-li-a"><?= eur($tot) ?></span><span class="rs-est" style="color:<?= $ESTCOL[$est]??'#98a2b3' ?>"><?= e($ESTLBL[$est]??$est) ?></span></div>
      </a>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<div class="rs-panel" style="max-width:560px">
  <div class="ph"><h4>De lo facturado a lo que te queda</h4></div>
  <div class="wf">
    <div class="row"><span class="t">Base imponible</span><span class="v"><?= eur($base) ?></span></div>
    <div class="row"><span class="t">+ IVA repercutido</span><span class="v"><?= eur($iva) ?></span></div>
    <div class="row"><span class="t">− IRPF retenido</span><span class="v">−<?= eur($irpf) ?></span></div>
    <div class="row tot"><span class="t">Cobrado en cuenta</span><span class="v"><?= eur($cobrado) ?></span></div>
    <div class="row"><span class="t">− IVA a Hacienda</span><span class="v">−<?= eur($iva) ?></span></div>
    <div class="row"><span class="t">− Gastos del mes</span><span class="v">−<?= eur($gastos) ?></span></div>
    <div class="row tot"><span class="t">Neto estimado</span><span class="v"><?= eur($neto) ?></span></div>
  </div>
  <div class="rs-note">Estimación. El IVA se cobra pero se ingresa a Hacienda; el IRPF retenido es un adelanto de tu IRPF. Para cifras oficiales, consulta con tu gestoría.</div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function(){
  if(!window.Chart)return;
  var e=document.getElementById('chMes');if(!e)return;
  var labels=<?= json_encode($trLabels, JSON_UNESCAPED_UNICODE) ?>;
  var data=<?= json_encode($trData) ?>;
  Chart.defaults.font.family='inherit';Chart.defaults.font.size=11;Chart.defaults.color='#98a2b3';
  var ctx=e.getContext('2d');var grd=ctx.createLinearGradient(0,0,0,270);grd.addColorStop(0,'rgba(17,19,24,.10)');grd.addColorStop(1,'rgba(17,19,24,0)');
  new Chart(e,{type:'line',data:{labels:labels,datasets:[{data:data,borderColor:'#111318',borderWidth:2,fill:true,backgroundColor:grd,tension:.35,pointRadius:0,pointHoverRadius:5,pointHoverBackgroundColor:'#111318'}]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{backgroundColor:'#111318',padding:10,cornerRadius:8,displayColors:false,callbacks:{label:function(c){return c.parsed.y.toLocaleString('es-ES')+' €';}}}},
      scales:{y:{beginAtZero:true,border:{display:false},grid:{color:'#f0f1f3'},ticks:{callback:function(v){return (v>=1000?(v/1000)+'K':v);}}},x:{border:{display:false},grid:{display:false}}}}});
})();
</script>
<?php erp_foot(); ?>
