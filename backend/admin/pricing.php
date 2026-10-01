<?php
/* Calculadora de pricing / presupuestos rápidos. */
require_once __DIR__ . '/../auth.php';
require_admin();
require_once __DIR__ . '/erp_nav.php';

// catálogo de servicios (si existe) para autocompletar líneas
$svcs = [];
$raw = null;
try { $r=db()->prepare("SELECT valor FROM settings WHERE clave='servicios_catalogo'"); $r->execute(); $raw=$r->fetchColumn(); } catch(Exception $e){}
if ($raw) { $d=json_decode($raw,true); if(is_array($d)) foreach($d as $s){ if(!empty($s['nombre'])) $svcs[]=$s['nombre']; } }
if (!$svcs) $svcs = ['SEO','SEM','CRO','Diseño web','Tienda online','Meta Ads','Mantenimiento'];

erp_head('pricing', 'Calculadora de precios', 'fin-canvas');
?>
<style>
/* Lienzo gris estilo Apple: tarjetas blancas flotantes sobre fondo suave. */
body.fin-canvas .main{background:#f5f5f7}
body.fin-canvas .erp-wrap{animation:none;padding:36px 48px 80px}
body.fin-canvas h1{letter-spacing:-.5px;font-size:26px}
@keyframes finIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.pr-wrap{max-width:none}
.pr-lead{color:var(--muted);font-size:13.5px;line-height:1.6;margin:-2px 0 26px;animation:finIn .5s cubic-bezier(.2,.7,.3,1) .04s both}
h1{animation:finIn .5s cubic-bezier(.2,.7,.3,1) both}
.pr-seg{animation:finIn .5s cubic-bezier(.2,.7,.3,1) .08s both}
.pr-card{animation:finIn .55s cubic-bezier(.2,.7,.3,1) .14s both}
.pr-card+.pr-card{animation-delay:.2s}
.pr-sum{animation:finIn .55s cubic-bezier(.2,.7,.3,1) .18s both}
/* Segmentado estilo iOS */
.pr-seg{display:inline-flex;background:#f1f2f4;border-radius:12px;padding:4px;gap:4px;margin-bottom:22px}
.pr-seg button{border:none;background:none;border-radius:9px;padding:8px 20px;font-size:13px;font-weight:600;color:#6b7079;cursor:pointer;font-family:inherit;transition:color .15s}
.pr-seg button.on{background:#fff;color:var(--ink-strong);box-shadow:0 1px 3px rgba(16,19,24,.12)}
.pr-grid{display:grid;grid-template-columns:minmax(0,1fr) 366px;gap:22px;align-items:start}
.pr-card{background:#fff;border:1px solid rgba(16,19,24,.06);border-radius:22px;padding:26px 28px;margin-bottom:20px;box-shadow:0 1px 2px rgba(16,19,24,.04),0 12px 30px -20px rgba(16,19,24,.14)}
.pr-card h3{font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);font-weight:650;margin-bottom:18px;display:flex;align-items:center;gap:8px}
.pr-card h3 svg{width:15px;height:15px;color:var(--label)}
/* Líneas de servicio */
.pl-head,.pl-row{display:grid;grid-template-columns:1fr 66px 96px 100px 30px;gap:10px;align-items:center}
.pl-head{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--label);font-weight:650;padding:0 2px 12px}
.pl-head .lt,.pl-row .lt{text-align:right}
.pl-row{margin-bottom:9px}
.pl-row input{border:1px solid var(--line);border-radius:10px;padding:9px 11px;font-size:13.5px;font-family:inherit;width:100%;outline:none;transition:border-color .12s,box-shadow .12s;color:var(--ink)}
.pl-row input:hover{border-color:#dcdde1}
.pl-row input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.pl-row input.lt{text-align:right}
.pl-row .ptot{text-align:right;font-size:13.5px;font-weight:650;color:var(--ink-strong);padding-right:2px}
.pl-del{border:none;background:none;color:var(--label);cursor:pointer;font-size:14px;border-radius:8px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;margin-left:auto}
.pl-del:hover{background:#fde8e8;color:#c0392b}
.pr-add{border:1px dashed #d7dade;background:#fff;border-radius:11px;padding:11px;width:100%;cursor:pointer;color:var(--ink);font-weight:600;font-size:13px;margin-top:6px;transition:background .12s,border-color .12s}
.pr-add:hover{background:var(--soft);border-color:#c7cbd1}
/* Filas de campos (coste interno / ajustes) */
.pr-f{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:15px}
.pr-f:last-child{margin-bottom:0}
.pr-f label{font-size:13.5px;color:var(--ink);font-weight:500;margin:0;line-height:1.5}
.pr-f label small{display:block;color:var(--muted);font-size:11.5px;font-weight:400;margin-top:2px;line-height:1.5}
.pr-f .in{display:flex;align-items:center;gap:7px;width:120px;flex:none}
.pr-f .in input{border:1px solid var(--line);border-radius:10px;padding:9px 11px;font-size:13.5px;font-family:inherit;width:100%;text-align:right;outline:none;transition:border-color .12s,box-shadow .12s}
.pr-f .in input:hover{border-color:#dcdde1}
.pr-f .in input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.pr-f .in span{color:var(--muted);font-size:13px;width:14px}
.pr-costtot{display:flex;justify-content:space-between;align-items:center;margin-top:15px;padding-top:14px;border-top:1px solid var(--line2);font-size:13.5px}
.pr-costtot b{font-size:16px;color:var(--ink-strong);font-weight:750}
/* Resumen (presupuesto) */
.pr-sum{background:#fff;border:1px solid rgba(16,19,24,.06);border-radius:20px;padding:6px 0 0;position:sticky;top:76px;overflow:hidden;box-shadow:0 1px 2px rgba(16,19,24,.04),0 18px 44px -22px rgba(16,19,24,.28)}
.pr-sum .pr-adj{padding:16px 20px 4px}
.pr-sum .pr-body{padding:8px 20px 4px;border-top:1px solid var(--line2)}
.pr-line{display:flex;justify-content:space-between;font-size:13.5px;color:var(--ink);padding:6px 0}
.pr-line.sub{color:var(--muted);font-size:12.5px}
.pr-tot-wrap{margin:8px 20px 0;padding:16px 0 18px;border-top:2px solid var(--line)}
.pr-tot-wrap .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);font-weight:650}
.pr-tot-wrap .amt{font-size:30px;font-weight:780;color:var(--ink-strong);letter-spacing:-1px;line-height:1.1;margin-top:4px}
.pr-tot-wrap .amt small{font-size:14px;color:var(--muted);font-weight:600;letter-spacing:0}
/* Margen */
.pr-marg{margin:0 20px 18px;padding:14px 16px;border-radius:14px;background:var(--soft);border:1px solid var(--line2)}
.pr-marg .top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:9px}
.pr-marg .top .l{font-size:12px;color:var(--muted);font-weight:600}
.pr-marg .top .v{font-size:15px;font-weight:750;color:var(--ok)}
.pr-marg.bad .top .v{color:#c0343a}
.pr-marg.warn .top .v{color:#c98a00}
.pr-bar{height:7px;border-radius:99px;background:#e7e8ea;overflow:hidden}
.pr-bar i{display:block;height:100%;border-radius:99px;background:#12a150;transition:width .3s ease}
.pr-marg.bad .pr-bar i{background:#e5484d}
.pr-marg.warn .pr-bar i{background:#e0a000}
.pr-marg .note{font-size:11.5px;color:var(--muted);margin-top:8px}
/* Ayudante de margen objetivo */
.pr-goal{margin:0 20px 18px;padding:14px 16px;border-radius:14px;background:var(--accent-soft);display:flex;align-items:center;justify-content:space-between;gap:12px}
.pr-goal .gl{font-size:12.5px;color:var(--ink);font-weight:600;display:flex;align-items:center;gap:8px}
.pr-goal .gin{display:inline-flex;align-items:center;gap:4px;background:#fff;border:1px solid var(--line);border-radius:8px;padding:3px 8px}
.pr-goal .gin input{border:none;outline:none;width:44px;text-align:center;font-size:13px;font-family:inherit;font-weight:700;background:none;-moz-appearance:textfield}
.pr-goal .gin input::-webkit-outer-spin-button,.pr-goal .gin input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.pr-goal .gv{text-align:right}
.pr-goal .gv b{font-size:15px;color:var(--accent);font-weight:750;display:block}
.pr-goal .gv small{font-size:10.5px;color:var(--muted)}
@media(max-width:860px){.pr-grid{grid-template-columns:1fr}.pr-sum{position:static}}
[data-theme=dark] body.fin-canvas .main{background-color:var(--bg)}
[data-theme=dark] .pr-seg{background-color:var(--soft)}
[data-theme=dark] .pr-seg button{color:var(--muted)}
[data-theme=dark] .pr-seg button.on{background-color:var(--card);color:var(--ink-strong)}
[data-theme=dark] .pr-card{background-color:var(--card)}
[data-theme=dark] .pl-head{color:var(--muted)}
[data-theme=dark] .pl-row input{background-color:var(--field)}
[data-theme=dark] .pl-row input:hover{border-color:var(--line-strong)}
[data-theme=dark] .pl-del{color:var(--muted)}
[data-theme=dark] .pl-del:hover{background-color:var(--danger-bg);color:var(--danger)}
[data-theme=dark] .pr-add{background-color:var(--card);border-color:var(--line-strong)}
[data-theme=dark] .pr-add:hover{border-color:var(--line-strong)}
[data-theme=dark] .pr-f .in input{background-color:var(--field)}
[data-theme=dark] .pr-f .in input:hover{border-color:var(--line-strong)}
[data-theme=dark] .pr-sum{background-color:var(--card)}
[data-theme=dark] .pr-marg .top .v{color:var(--ok)}
[data-theme=dark] .pr-marg.bad .top .v{color:var(--danger)}
[data-theme=dark] .pr-marg.warn .top .v{color:var(--warn)}
[data-theme=dark] .pr-bar{background-color:var(--line)}
[data-theme=dark] .pr-bar i{background-color:var(--ok)}
[data-theme=dark] .pr-marg.bad .pr-bar i{background-color:var(--danger)}
[data-theme=dark] .pr-marg.warn .pr-bar i{background-color:var(--warn)}
[data-theme=dark] .pr-goal .gin{background-color:var(--field)}
/* ---- Móvil (teléfono) ---- */
@media(max-width:640px){
  body.fin-canvas .erp-wrap{padding:20px 16px 60px}
  body.fin-canvas h1{font-size:22px}
  .pr-card{padding:20px 16px;border-radius:18px}
  .pl-head,.pl-row{grid-template-columns:1fr 46px 66px 66px 26px;gap:6px}
  .pl-head{font-size:9px}
  .pl-row input{padding:8px 8px;font-size:12.5px}
  .pl-row .ptot{font-size:12px}
  .pr-f .in{width:100px}
  .pr-sum{border-radius:18px}
  .pr-tot-wrap .amt{font-size:26px}
}
</style>

<h1>Calculadora de precios</h1>
<div class="pr-lead">Arma un presupuesto, calcula el precio y comprueba tu margen antes de enviarlo.</div>

<div class="pr-wrap">
  <div class="pr-seg">
    <button type="button" class="on" id="mb-once" onclick="prMode('once')">Proyecto puntual</button>
    <button type="button" id="mb-month" onclick="prMode('month')">Cuota mensual</button>
  </div>
  <div class="pr-grid">
    <div>
      <div class="pr-card">
        <h3><?= ic('list',15) ?> Servicios</h3>
        <div class="pl-head"><span>Concepto</span><span class="lt">Cant.</span><span class="lt">Precio</span><span class="lt">Total</span><span></span></div>
        <div id="prLines"></div>
        <button type="button" class="pr-add" onclick="prAddLine()">＋ Añadir línea</button>
      </div>
      <div class="pr-card">
        <h3><?= ic('calc',15) ?> Coste interno</h3>
        <div class="pr-f"><label>Horas estimadas</label><div class="in"><input type="number" id="prHoras" value="0" min="0" step="1" aria-label="Horas estimadas" oninput="prCalc()"><span>h</span></div></div>
        <div class="pr-f"><label>Coste por hora</label><div class="in"><input type="number" id="prCoste" value="15" min="0" step="1" aria-label="Coste por hora" oninput="prCalc()"><span>€</span></div></div>
        <div class="pr-f"><label>Gastos fijos / herramientas</label><div class="in"><input type="number" id="prFijos" value="0" min="0" step="1" aria-label="Gastos fijos y herramientas" oninput="prCalc()"><span>€</span></div></div>
        <div class="pr-costtot"><span class="mut" style="color:var(--muted)">Coste total del trabajo</span><b id="oCoste">0,00 €</b></div>
      </div>
    </div>
    <div>
      <div class="pr-sum">
        <div class="pr-adj">
          <div class="pr-f"><label>Descuento</label><div class="in"><input type="number" id="prDto" value="0" min="0" max="100" step="1" aria-label="Descuento en porcentaje" oninput="prCalc()"><span>%</span></div></div>
          <div class="pr-f"><label>IVA</label><div class="in"><input type="number" id="prIva" value="21" min="0" max="100" step="1" aria-label="IVA en porcentaje" oninput="prCalc()"><span>%</span></div></div>
        </div>
        <div class="pr-body">
          <div class="pr-line sub"><span>Subtotal</span><span id="oSub">0,00 €</span></div>
          <div class="pr-line sub"><span>Descuento</span><span id="oDto">0,00 €</span></div>
          <div class="pr-line"><span>Base imponible</span><span id="oBase">0,00 €</span></div>
          <div class="pr-line sub"><span>IVA</span><span id="oIva">0,00 €</span></div>
        </div>
        <div class="pr-tot-wrap"><div class="lbl">Total <span id="oPer"></span></div><div class="amt" id="oTot">0,00 €</div></div>
        <div class="pr-marg" id="oMarginBox">
          <div class="top"><span class="l">Beneficio</span><span class="v" id="oMargin">0,00 € · 0%</span></div>
          <div class="pr-bar"><i id="oBar" style="width:0%"></i></div>
          <div class="note" id="oNote">Añade el coste interno para ver tu margen real.</div>
        </div>
        <div class="pr-goal">
          <div class="gl"><?= ic('trend',15) ?> Con margen del <span class="gin"><input type="number" id="prGoal" value="60" min="0" max="95" step="5" aria-label="Margen objetivo en porcentaje" oninput="prCalc()">%</span></div>
          <div class="gv"><b id="oGoal">0,00 €</b><small>precio (base) recomendado</small></div>
        </div>
      </div>
    </div>
  </div>
</div>

<datalist id="svcDL"><?php foreach($svcs as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>

<script>
var PR_MODE='once';
/* El euro va detrás del número y separado por un espacio, igual que en el resto del ERP. */
function eur(n){return (Math.round(n*100)/100).toLocaleString('es-ES',{minimumFractionDigits:2,maximumFractionDigits:2})+' €';}
function prMode(m){PR_MODE=m;document.getElementById('mb-once').classList.toggle('on',m==='once');document.getElementById('mb-month').classList.toggle('on',m==='month');document.getElementById('oPer').textContent=(m==='month'?'/ mes':'');prCalc();}
function prAddLine(name,qty,price){var box=document.getElementById('prLines');var row=document.createElement('div');row.className='pl-row';
  var c=document.createElement('input');c.type='text';c.setAttribute('list','svcDL');c.placeholder='Servicio…';c.value=name||'';c.oninput=prCalc;
  var q=document.createElement('input');q.type='number';q.min='0';q.step='1';q.value=(qty!=null?qty:1);q.className='lt';q.setAttribute('aria-label','Cantidad');q.oninput=prCalc;
  var p=document.createElement('input');p.type='number';p.min='0';p.step='1';p.value=(price!=null?price:0);p.className='lt';p.setAttribute('aria-label','Precio');p.oninput=prCalc;
  var t=document.createElement('div');t.className='ptot';t.textContent='0,00 €';
  var x=document.createElement('button');x.type='button';x.className='pl-del';x.textContent='✕';x.onclick=function(){row.remove();prCalc();};
  row.appendChild(c);row.appendChild(q);row.appendChild(p);row.appendChild(t);row.appendChild(x);box.appendChild(row);prCalc();}
function prCalc(){var rows=document.querySelectorAll('#prLines .pl-row');var sub=0;
  rows.forEach(function(r){var i=r.querySelectorAll('input');var qty=parseFloat(i[1].value)||0;var price=parseFloat(i[2].value)||0;var lt=qty*price;sub+=lt;r.querySelector('.ptot').textContent=eur(lt);});
  var dtoP=parseFloat(document.getElementById('prDto').value)||0;var ivaP=parseFloat(document.getElementById('prIva').value)||0;
  var dto=sub*dtoP/100;var base=sub-dto;var iva=base*ivaP/100;var tot=base+iva;
  var horas=parseFloat(document.getElementById('prHoras').value)||0;var ch=parseFloat(document.getElementById('prCoste').value)||0;var fijos=parseFloat(document.getElementById('prFijos').value)||0;
  var coste=horas*ch+fijos;var benef=base-coste;var margenPct=base>0?Math.round(benef/base*100):0;
  document.getElementById('oSub').textContent=eur(sub);
  document.getElementById('oDto').textContent='−'+eur(dto);
  document.getElementById('oBase').textContent=eur(base);
  document.getElementById('oIva').textContent=eur(iva);
  document.getElementById('oTot').textContent=eur(tot);
  document.getElementById('oCoste').textContent=eur(coste);
  document.getElementById('oMargin').textContent=eur(benef)+' · '+margenPct+'%';
  document.getElementById('oBar').style.width=Math.max(0,Math.min(100,margenPct))+'%';
  var box=document.getElementById('oMarginBox');box.classList.remove('bad','warn');
  var note=document.getElementById('oNote');
  if(coste<=0){ note.textContent='Añade el coste interno para ver tu margen real.'; }
  else if(benef<0){ box.classList.add('bad'); note.textContent='Estás perdiendo dinero: el precio no cubre el coste.'; }
  else if(margenPct<35){ box.classList.add('warn'); note.textContent='Margen ajustado. Lo sano en agencia suele ser 40–60%.'; }
  else { note.textContent='Margen saludable sobre la base imponible.'; }
  /* Precio recomendado para el margen objetivo (sobre la base, sin IVA). */
  var goal=parseFloat(document.getElementById('prGoal').value)||0; if(goal>95)goal=95;
  var reco=(coste>0 && goal<100)?(coste/(1-goal/100)):0;
  document.getElementById('oGoal').textContent=eur(reco);
}
prAddLine('',1,0);prCalc();
</script>
<?php erp_foot(); ?>
