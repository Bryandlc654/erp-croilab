<?php
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/gcal.php';

$me = current_admin(); $meId = (int)$me['id'];
$respMap=[]; foreach(db()->query('SELECT id,username FROM admins') as $r){ $respMap[(int)$r['id']]=$r['username']; }
$MAB=['','ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
$MESL=['','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$hoy=date('Y-m-d');

/* ---------- Tareas: en proceso / atrasadas / completadas recientemente ---------- */
$tSql="SELECT t.id,t.titulo,t.due_date,t.prioridad,t.responsable_id,c.name cname FROM tasks t JOIN clients c ON c.id=t.client_id WHERE ";
$tProc = db()->query($tSql."t.estado='en proceso' ORDER BY (t.due_date IS NULL), t.due_date ASC, t.prioridad DESC LIMIT 8")->fetchAll();
$tAtr  = db()->query($tSql."t.estado<>'completada' AND t.due_date IS NOT NULL AND t.due_date<CURDATE() ORDER BY t.due_date ASC LIMIT 8")->fetchAll();
$tComp = db()->query($tSql."t.estado='completada' ORDER BY t.updated_at DESC LIMIT 8")->fetchAll();

/* ---------- Calendario del mes: actividad + detalle por día (hover) ---------- */
$mFirst=date('Y-m-01'); $mDays=(int)date('t'); $mStart=((int)date('N',strtotime($mFirst))+6)%7; $todayD=(int)date('j'); $mY=(int)date('Y'); $mM=(int)date('n');
$dayItems=[]; // día(int) => [ {t,sub,tipo,hora} ]
foreach(db()->query("SELECT t.titulo,DAY(t.due_date) d,c.name cn FROM tasks t JOIN clients c ON c.id=t.client_id WHERE t.estado<>'completada' AND t.due_date IS NOT NULL AND YEAR(t.due_date)=$mY AND MONTH(t.due_date)=$mM") as $r){ $dayItems[(int)$r['d']][]=['t'=>$r['titulo'],'sub'=>$r['cn'],'tipo'=>'tarea','hora'=>'']; }
if(gcal_connected($meId)){ foreach(gcal_events($meId,$mFirst,date('Y-m-t')) as $ge){ $d=(int)substr($ge['dia'],8,2); $dayItems[$d][]=['t'=>$ge['titulo'],'sub'=>'','tipo'=>'evento','hora'=>$ge['hora']]; } }

/* ---------- Hoy: reuniones + vencimientos de hoy ---------- */
$hoyItems=[];
if(gcal_connected($meId)){ foreach(gcal_events($meId,$hoy,$hoy) as $ge){ $hoyItems[]=['ic'=>'cal','c'=>'#4285F4','t'=>$ge['titulo'],'s'=>($ge['allday']?'todo el día':($ge['hora']!==''?$ge['hora'].' · reunión':'reunión')),'u'=>'calendar.php?view=dia&d='.$hoy]; } }
foreach(db()->query("SELECT t.id,t.titulo,c.name cn FROM tasks t JOIN clients c ON c.id=t.client_id WHERE t.estado<>'completada' AND t.due_date=CURDATE() ORDER BY t.prioridad DESC") as $r){
  $ids=task_asignados((int)$r['id']); $names=[]; foreach($ids as $i){ if(isset($respMap[$i])) $names[]=$respMap[$i]; }
  $hoyItems[]=['ic'=>'tasks','c'=>'#e0a000','t'=>$r['titulo'],'s'=>'vence hoy · '.$r['cn'],'u'=>'task.php?id='.(int)$r['id'],'av'=>$names]; }

/* ---------- Próximas reuniones (Google) emparejadas con el cliente por correo ---------- */
$proxReu=[];
if(gcal_connected($meId) && !gcal_revoked($meId)){
  $byEmail=[]; try{ foreach(db()->query("SELECT nombre,email FROM contacts WHERE email<>''") as $r) $byEmail[strtolower(trim($r['email']))]=$r; }catch(Exception $e){}
  foreach(gcal_meetings_range($meId,$hoy,date('Y-m-d',strtotime('+30 days'))) as $ev){
    if($ev['dia']<$hoy) continue;
    $cli=''; foreach($ev['emails'] as $em){ if(isset($byEmail[$em])){ $cli=$byEmail[$em]['nombre']; break; } }
    $proxReu[]=['t'=>$ev['titulo'],'dia'=>$ev['dia'],'hora'=>$ev['hora'],'cli'=>$cli,'meet'=>$ev['meet'],'link'=>$ev['link']];
  }
  usort($proxReu,function($a,$b){ return strcmp($a['dia'].$a['hora'],$b['dia'].$b['hora']); });
  $proxReu=array_slice($proxReu,0,6);
}

$DOWm=['L','M','X','J','V','S','D'];
$hora=(int)date('H'); $saludo=$hora<12?'Buenos días':($hora<20?'Buenas tardes':'Buenas noches');
$hoyTxt=['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'][(int)date('w')].' '.(int)date('j').' de '.$MESL[$mM];

/* KPIs de cabecera (resumen ejecutivo). Cada uno en su try/catch: si una tabla no
   existe en esta instalación, el KPI queda a 0 y el panel no se rompe. */
$kpiClientes = 0;
try { $kpiClientes = (int)db()->query("SELECT COUNT(*) FROM clients WHERE COALESCE(activo,1)=1")->fetchColumn(); } catch(Exception $e){}
$kpiCobrado = 0.0;
try {
  $kpiCobrado = (float)db()->query("SELECT COALESCE(SUM(it.base*(1+i.iva_pct/100-i.irpf_pct/100)),0)
      FROM invoices i JOIN (SELECT invoice_id, COALESCE(SUM(cantidad*precio),0) base FROM invoice_items GROUP BY invoice_id) it ON it.invoice_id=i.id
      WHERE i.estado='pagada' AND YEAR(i.fecha)=$mY AND MONTH(i.fecha)=$mM")->fetchColumn();
} catch(Exception $e){}

/* Accesos del grid superior. `gate=edit` = solo roles que pueden editar (Facturas y
   Contabilidad no se muestran al rol de solo lectura). */
$accesos=[
  ['ic'=>'vault','c'=>'#64748b','t'=>'Bóveda de credenciales','d'=>'Accesos y contraseñas de clientes','u'=>'credenciales.php'],
  ['ic'=>'euro','c'=>'#34c759','t'=>'Contabilidad','d'=>'Ingresos, gastos y resultado','u'=>'contabilidad.php','gate'=>'edit'],
  ['ic'=>'crm','c'=>'#5e5ce6','t'=>'CRM · Ventas','d'=>'Contactos, negocios y seguimiento','u'=>'crm.php'],
  ['ic'=>'file','c'=>'#0a84ff','t'=>'Facturas','d'=>'Emitir y controlar cobros','u'=>'facturas.php','gate'=>'edit'],
];
$accesos = array_values(array_filter($accesos, fn($a)=>($a['gate']??'')!=='edit' || can_edit()));

erp_head('dashboard', 'Inicio');
?>
<style>
/* Modo oscuro del Dashboard: solo remapea sus superficies/colores propios a las
   variables del tema. El modo claro no se toca (mayor especificidad, no reordena). */
[data-theme=dark] :is(.dsh-kpi,.dsh-card,.acc-card){background-color:var(--card)}
[data-theme=dark] .dsh-card{border-color:var(--line)}
[data-theme=dark] :is(.dsh-kpi:hover,.acc-card:hover){border-color:var(--line-strong)}
[data-theme=dark] .dsh-tab{color:var(--muted)}
[data-theme=dark] .dsh-tab.on{background-color:var(--card);color:var(--ink-strong)}
[data-theme=dark] .dsh-date.past{background-color:var(--danger-bg)}[data-theme=dark] .dsh-date.past .dd{color:var(--danger)}
[data-theme=dark] .dsh-date.ok{background-color:var(--ok-bg)}[data-theme=dark] .dsh-date.ok .dd{color:var(--ok)}
[data-theme=dark] .mcal-c.today{background-color:var(--rev);color:var(--rev-fg)}
[data-theme=dark] .mcal-mon{background-color:var(--accent-soft);color:var(--ink-strong)}
[data-theme=dark] .dsh-av2{border-color:var(--card)}
[data-theme=dark] .dsh-meet{background-color:var(--soft)}

.main .erp-wrap, .erp-wrap{max-width:none}
.dsh{width:100%}
.dsh-hi{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.dsh-hi h1{margin:0;font-size:28px;font-weight:650;letter-spacing:-.5px}
.dsh-hi .sub{color:var(--muted);font-size:13.5px;margin-top:5px;line-height:1.5}
.dsh-hi .date{font-size:12.5px;color:var(--muted)}
.dsh-hi-right{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.dsh-kpi{display:flex;flex-direction:column;gap:3px;background:#fff;border:1px solid var(--line);border-radius:13px;padding:12px 18px;text-decoration:none;transition:border-color .12s,box-shadow .12s}
.dsh-kpi:hover{border-color:#dcdee2;box-shadow:0 10px 24px -18px rgba(0,0,0,.5)}
.dsh-kpi span{font-size:11px;color:var(--muted);font-weight:600}
.dsh-kpi b{font-size:19px;font-weight:700;letter-spacing:-.4px;color:var(--ink-strong)}
.dsh-top3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:20px}
.dsh-tasks .dsh-list{display:grid;grid-template-columns:1fr 1fr;gap:0 140px}
.dsh-tasks .dsh-list .dsh-row{border-top:1px solid var(--line2)}
.dsh-card{background:#fff;border:1px solid rgba(16,19,24,.06);border-radius:18px;padding:24px 26px;box-shadow:0 1px 2px rgba(16,19,24,.03),0 12px 30px -22px rgba(16,19,24,.14)}
.dsh-card .ch{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:10px;flex-wrap:wrap}
.dsh-card h3{margin:0;font-size:16px;font-weight:650;display:flex;align-items:center;gap:8px}
.dsh-card h3 svg{width:16px;height:16px;color:var(--muted)}
.dsh-card .all{font-size:12.5px;color:var(--muted);text-decoration:none;font-weight:600}
.dsh-card .all:hover{color:var(--ink)}
.dsh-empty{color:var(--muted);font-size:12.5px;text-align:center;padding:22px 10px;display:flex;flex-direction:column;align-items:center;gap:7px;line-height:1.5}
.dsh-empty .di{width:38px;height:38px;border-radius:11px;background:var(--soft);color:var(--label);display:flex;align-items:center;justify-content:center;margin-bottom:1px}
.dsh-empty .di svg{width:19px;height:19px}
.dsh-empty b{color:var(--ink-strong);font-weight:700;font-size:13.5px}
.dsh-empty a{color:var(--accent);font-weight:600}
/* Estado vacío destacado en Hoy y Próximas reuniones: ocupa toda la tarjeta,
   grande y centrado (mejor UX cuando el día está despejado). */
.dsh-top3 .dsh-card{display:flex;flex-direction:column}
.dsh-top3 .dsh-list{flex:1;display:flex;flex-direction:column}
.dsh-top3 .dsh-empty{flex:1;justify-content:center;gap:10px;padding:24px 16px;min-height:150px}
.dsh-top3 .dsh-empty .di{width:60px;height:60px;border-radius:18px;margin-bottom:2px}
.dsh-top3 .dsh-empty .di svg{width:29px;height:29px}
.dsh-top3 .dsh-empty b{font-size:17px}
.dsh-top3 .dsh-empty span{font-size:13.5px;max-width:240px}
/* pestañas de tareas */
.dsh-tabs{display:flex;gap:2px;background:var(--soft);border-radius:10px;padding:3px}
.dsh-tab{border:none;background:none;font-family:inherit;font-size:12.5px;font-weight:600;color:#6b7280;padding:6px 12px;border-radius:8px;cursor:pointer}
.dsh-tab.on{background:#fff;color:var(--ink-strong);box-shadow:0 1px 3px rgba(0,0,0,.08)}
.dsh-pane{display:none;animation:dshfade .22s ease}
.dsh-pane.on{display:block}
@keyframes dshfade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
/* filas */
.dsh-row{display:flex;align-items:center;gap:14px;padding:15px 2px;border-top:1px solid var(--line2);text-decoration:none;color:inherit}
.dsh-list .dsh-row:first-child{border-top:none}
.dsh-row:hover .rt{color:#0071e3}
.dsh-date{width:44px;flex:none;text-align:center;background:var(--soft);border-radius:10px;padding:5px 0;line-height:1.1}
.dsh-date.past{background:#fdecec}.dsh-date.past .dd{color:#c0343a}
.dsh-date.ok{background:#e7f7ee}.dsh-date.ok .dd{color:#0f7a3d}
.dsh-date .dd{font-size:15px;font-weight:700;color:var(--ink-strong)}
.dsh-date .dm{font-size:9.5px;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:.4px}
.dsh-date.nd{background:none;color:var(--muted);font-size:11px;display:flex;align-items:center;justify-content:center;height:38px}
.dsh-rb{flex:1;min-width:0}
.dsh-rb .rt{font-size:13.5px;font-weight:600;color:var(--ink-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dsh-rb .rs{font-size:12px;color:var(--muted);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dsh-av{width:26px;height:26px;border-radius:50%;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex:none}
.dsh-avs{display:inline-flex;flex:none}
.dsh-av2{width:24px;height:24px;border-radius:50%;color:#fff;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid #fff}
.dsh-av2+.dsh-av2{margin-left:-8px}
.dsh-av2.xtra{background:#c8ccd2;color:#3c4149;font-size:8.5px}
.dsh-tag{font-size:10px;font-weight:700;color:#fff;background:#ef4444;border-radius:99px;padding:2px 7px;flex:none}
.dsh-ic{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex:none}
.dsh-ic svg{width:15px;height:15px}
.dsh-meet{font-size:10px;font-weight:700;color:#5f6672;background:var(--soft);border:1px solid var(--line);border-radius:99px;padding:2px 8px;flex:none}
/* mini calendario + hover */
.mcal-h b{font-size:13.5px}
/* Mes del calendario: etiqueta de color junto al título «Calendario». */
.mcal-mon{font-size:11.5px;font-weight:700;color:#0071e3;background:#eaf3ff;border-radius:99px;padding:4px 12px;margin-left:4px;text-transform:capitalize;letter-spacing:.1px}
.mcal-dow{display:grid;grid-template-columns:repeat(7,1fr);gap:8px;margin:24px 0 10px}
.mcal-dow span{text-align:center;font-size:10px;color:var(--muted);font-weight:700}
.mcal-days{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}
.mcal-c{aspect-ratio:1;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:12.5px;color:var(--ink);position:relative;text-decoration:none}
.mcal-c:hover{background:var(--soft)}
.mcal-c.today{background:#3c4149;color:#fff;font-weight:700}
.mcal-c.hasev{font-weight:700}
.mcal-c .adot{width:5px;height:5px;border-radius:50%;position:absolute;bottom:5px}
.mcal-c.today .adot{background:#fff!important}
.mcal-pop{position:fixed;z-index:600;background:rgba(255,255,255,.72);-webkit-backdrop-filter:blur(22px) saturate(1.6);backdrop-filter:blur(22px) saturate(1.6);color:var(--ink);border:1px solid rgba(0,0,0,.06);border-radius:16px;padding:12px 14px;width:236px;box-shadow:0 20px 50px -16px rgba(16,19,24,.28);opacity:0;transform:translateY(6px) scale(.98);pointer-events:none;transition:opacity .18s ease,transform .18s cubic-bezier(.2,.8,.2,1)}
.mcal-pop.on{opacity:1;transform:none}
.mcal-pop .pd{font-size:11px;color:var(--muted);font-weight:700;margin-bottom:8px}
.mcal-pop .pi{display:flex;align-items:center;gap:8px;padding:4px 0;font-size:12.5px}
.mcal-pop .pi .pdot{width:7px;height:7px;border-radius:50%;flex:none}
.mcal-pop .pi .ph{color:var(--muted);font-size:11px}
.mcal-pop .pi b{font-weight:600;color:var(--ink-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* grid de accesos (arriba) */
.dsh-access{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;margin-bottom:20px}
.acc-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;text-decoration:none;color:inherit;transition:border-color .12s,transform .12s,box-shadow .12s;display:flex;flex-direction:column;gap:14px}
.acc-card:hover{border-color:#dcdee2;transform:translateY(-2px);box-shadow:0 14px 30px -20px rgba(0,0,0,.4)}
.acc-ic{width:44px;height:44px;border-radius:13px;display:flex;align-items:center;justify-content:center;color:#fff}
.acc-ic svg{width:21px;height:21px}
.acc-t{font-size:15px;font-weight:650;color:var(--ink-strong)}
.acc-d{font-size:12px;color:var(--muted);margin-top:3px;line-height:1.5}
.acc-go{margin-top:auto;font-size:12.5px;font-weight:600;color:#6b7280;display:inline-flex;align-items:center;gap:5px}
.acc-card:hover .acc-go{color:var(--ink)}
@media(max-width:1100px){.dsh-top3{grid-template-columns:1fr}.dsh-tasks .dsh-list{grid-template-columns:1fr!important}.dsh-access{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.dsh-access{grid-template-columns:1fr}}
/* ---- Móvil (teléfono): tarjetas a una columna y calendario sin desbordar ---- */
@media(max-width:640px){
  .dsh-hi h1{font-size:22px}
  /* KPIs compactos: dos en una fila, número y padding pequeños (nada de cajas gigantes) */
  .dsh-hi-right{width:100%;gap:8px}
  .dsh-hi-right .dsh-kpi{flex:1;min-width:0;padding:9px 12px;border-radius:11px}
  .dsh-kpi span{font-size:10.5px}
  .dsh-kpi b{font-size:16px}
  /* Tarjetas propias más compactas para acortar el scroll */
  .dsh-card{padding:15px 15px;border-radius:14px}
  .dsh-card h3{font-size:15px}
  .dsh-card .ch{margin-bottom:12px}
  .dsh-row{padding:11px 2px;gap:11px}
  /* La lista de tareas del panel a 1 columna que SÍ se ciñe (minmax(0,1fr)): así el
     título recorta con puntos suspensivos y el avatar/prioridad no se sale a la derecha. */
  .dsh-tasks .dsh-list{grid-template-columns:minmax(0,1fr)!important;column-gap:0!important}
  .dsh-row{max-width:100%}
  .dsh-rb{min-width:0;flex:1 1 0}
  .dsh-access{gap:10px}
  .acc-card{flex-direction:row;align-items:center;padding:13px 15px;gap:12px;border-radius:14px}
  .acc-card>div{flex:1;min-width:0}
  .acc-ic{width:36px;height:36px;border-radius:11px}
  .acc-ic svg{width:18px;height:18px}
  .acc-go{display:none}
  .mcal-dow{margin:16px 0 8px;gap:5px}
  .mcal-days{gap:5px}
  .mcal-c{font-size:12px}
  .mcal-mon{font-size:11px;padding:3px 10px}
  .dsh-tabs{flex-wrap:wrap}
}
</style>

<div class="dsh">
  <div class="dsh-hi">
    <div><h1><?= $saludo ?>, <?= e($me['username']) ?> 👋</h1><div class="sub">Aquí tienes el resumen de tu agencia.</div></div>
    <div class="dsh-hi-right">
      <a class="dsh-kpi" href="index.php" title="Clientes en alta"><span>Clientes activos</span><b><?= (int)$kpiClientes ?></b></a>
      <?php /* El dinero del panel solo para quien pueda verlo: sin ver.importes se
               quita la tarjeta entera en vez de dejar un hueco tapado, porque
               además enlaza a Contabilidad. */
            if (can_edit() && puede_importes()): ?><a class="dsh-kpi" href="contabilidad.php" title="Cobrado este mes"><span>Cobrado este mes</span><b><?= eur($kpiCobrado) ?></b></a><?php endif; ?>
    </div>
  </div>

  <!-- Accesos rápidos (primera sección) -->
  <div class="dsh-access">
    <?php foreach($accesos as $a): ?>
      <a class="acc-card" href="<?= e($a['u']) ?>">
        <span class="acc-ic" style="background:<?= $a['c'] ?>"><?= ic($a['ic'],21) ?></span>
        <div><div class="acc-t"><?= e($a['t']) ?></div><div class="acc-d"><?= e($a['d']) ?></div></div>
        <span class="acc-go">Abrir <?= ic('chevron',14) ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Arriba: Hoy · Próximas reuniones · Calendario -->
  <div class="dsh-top3">
    <!-- Hoy -->
    <div class="dsh-card">
      <div class="ch"><h3><?= ic('bolt',16) ?> Hoy</h3><a class="all" href="calendar.php?view=dia">Ver día</a></div>
      <div class="dsh-list">
      <?php if(!$hoyItems): ?><div class="dsh-empty"><span class="di"><?= ic('check',19) ?></span><b>Día despejado</b><span>Sin reuniones ni vencimientos para hoy.</span></div>
      <?php else: foreach($hoyItems as $a): ?>
        <a class="dsh-row" href="<?= e($a['u']) ?>"><span class="dsh-ic" style="background:<?= $a['c'] ?>1e;color:<?= $a['c'] ?>"><?= ic($a['ic'],15) ?></span><div class="dsh-rb"><div class="rt"><?= e($a['t']) ?></div><div class="rs"><?= e($a['s']) ?></div></div><?php if(!empty($a['av'])): ?><span class="dsh-avs"><?php foreach(array_slice($a['av'],0,3) as $nm): ?><span class="dsh-av2" style="background:<?= avatar_color($nm) ?>" title="<?= e($nm) ?>"><?= e(mb_strtoupper(mb_substr($nm,0,2))) ?></span><?php endforeach; ?><?php if(count($a['av'])>3): ?><span class="dsh-av2 xtra">+<?= count($a['av'])-3 ?></span><?php endif; ?></span><?php endif; ?></a>
      <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Próximas reuniones -->
    <div class="dsh-card">
      <div class="ch"><h3><?= ic('cal',16) ?> Próximas reuniones</h3><a class="all" href="reuniones.php">Ver todas</a></div>
      <div class="dsh-list">
      <?php if(!$proxReu): ?><div class="dsh-empty"><span class="di"><?= ic('cal',19) ?></span><?php if(gcal_connected($meId)): ?><b>Sin reuniones próximas</b><span>Cuando agendes una, aparecerá aquí.</span><?php else: ?><b>Calendario sin conectar</b><span>Conéctalo en <a href="integraciones.php">Integraciones</a> para ver tus reuniones.</span><?php endif; ?></div>
      <?php else: foreach($proxReu as $r): $t=strtotime($r['dia']); ?>
        <a class="dsh-row" href="<?= $r['link']?e($r['link']):'reuniones.php' ?>"<?= $r['link']?' target="_blank" rel="noopener"':'' ?>>
          <div class="dsh-date"><div class="dd"><?= (int)date('j',$t) ?></div><div class="dm"><?= $MAB[(int)date('n',$t)] ?></div></div>
          <div class="dsh-rb"><div class="rt"><?= e($r['t']) ?></div><div class="rs"><?= $r['hora']?e($r['hora']).' · ':'' ?><?= $r['cli']?e($r['cli']):'Sin cliente' ?></div></div>
          <?php if($r['meet']): ?><span class="dsh-meet">Meet</span><?php endif; ?>
        </a>
      <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Calendario con hover -->
    <div class="dsh-card">
      <div class="ch"><h3><?= ic('cal',16) ?> Calendario <span class="mcal-mon"><?= ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'][(int)date('w')].' '.$todayD.' '.$MESL[$mM] ?></span></h3><a class="all" href="calendar.php">Abrir</a></div>
      <div class="mcal-dow"><?php foreach($DOWm as $d): ?><span><?= $d ?></span><?php endforeach; ?></div>
      <div class="mcal-days">
        <?php for($i=0;$i<$mStart;$i++): ?><span></span><?php endfor; ?>
        <?php for($d=1;$d<=$mDays;$d++): $iso=sprintf('%04d-%02d-%02d',$mY,$mM,$d); $items=$dayItems[$d]??[]; $hasEv=false; foreach($items as $it) if($it['tipo']==='evento')$hasEv=true; $col=$items?($hasEv?(count($items)>1?'#3c4149':'#6b7280'):'#e0a000'):''; ?>
          <a class="mcal-c <?= $d===$todayD?'today':'' ?> <?= $items?'hasev':'' ?>" href="calendar.php?view=dia&d=<?= $iso ?>"<?php if($items): ?> data-day="<?= $d ?>" onmouseenter="mcalHover(this,<?= $d ?>)" onmouseleave="mcalOut()"<?php endif; ?>><?= $d ?><?php if($items): ?><span class="adot" style="background:<?= $col ?>"></span><?php endif; ?></a>
        <?php endfor; ?>
      </div>
    </div>
  </div>

  <!-- Abajo: Tareas (ancho completo, en dos columnas para dar aire) -->
  <div class="dsh-card dsh-tasks">
    <div class="ch"><h3><?= ic('tasks',16) ?> Tareas</h3>
      <div class="dsh-tabs">
        <button class="dsh-tab on" onclick="dshTab(this,'proc')">En proceso</button>
        <button class="dsh-tab" onclick="dshTab(this,'atr')">Atrasadas<?= $tAtr?' ('.count($tAtr).')':'' ?></button>
        <button class="dsh-tab" onclick="dshTab(this,'comp')">Completadas</button>
      </div>
    </div>
    <?php
    $renderTasks=function($rows,$mode) use($respMap,$MAB){
      if(!$rows){ $ei=$mode==='atr'?'check':($mode==='comp'?'check':'clock'); $et=$mode==='atr'?'Nada atrasado':($mode==='comp'?'Nada completado aún':'Nada en proceso'); $es=$mode==='atr'?'Ninguna tarea se ha pasado de fecha.':($mode==='comp'?'Aquí verás las tareas que vayáis terminando.':'No hay tareas en curso ahora mismo.'); echo '<div class="dsh-empty"><span class="di">'.ic($ei,19).'</span><b>'.e($et).'</b><span>'.e($es).'</span></div>'; return; }
      echo '<div class="dsh-list">';
      foreach($rows as $t){ $nm=$respMap[$t['responsable_id']]??''; $d=$t['due_date']?strtotime($t['due_date']):0; $past=$t['due_date']&&$t['due_date']<date('Y-m-d');
        $cls=$mode==='comp'?'ok':($past?'past':''); ?>
        <a class="dsh-row" href="task.php?id=<?= (int)$t['id'] ?>&ret=">
          <?php if($d): ?><div class="dsh-date <?= $cls ?>"><div class="dd"><?= (int)date('j',$d) ?></div><div class="dm"><?= $MAB[(int)date('n',$d)] ?></div></div>
          <?php else: ?><div class="dsh-date nd">—</div><?php endif; ?>
          <div class="dsh-rb"><div class="rt"><?= e($t['titulo']) ?></div><div class="rs"><?= e($t['cname']) ?></div></div>
          <?php if($mode!=='comp' && (int)$t['prioridad']>=3): ?><span class="dsh-tag">Urgente</span><?php endif; ?>
          <?php if($nm!==''): ?><div class="dsh-av" style="background:<?= avatar_color($nm) ?>" title="<?= e($nm) ?>"><?= e(mb_strtoupper(mb_substr($nm,0,2))) ?></div><?php endif; ?>
        </a>
      <?php }
      echo '</div>';
    }; ?>
    <div class="dsh-pane on" id="pane-proc"><?php $renderTasks($tProc,'proc'); ?></div>
    <div class="dsh-pane" id="pane-atr"><?php $renderTasks($tAtr,'atr'); ?></div>
    <div class="dsh-pane" id="pane-comp"><?php $renderTasks($tComp,'comp'); ?></div>
  </div>
</div>

<div class="mcal-pop" id="mcalPop"></div>
<script>
window.DAYDATA=<?= json_encode($dayItems, JSON_UNESCAPED_UNICODE) ?: '{}' ?>;
var _MESL=<?= json_encode($MESL, JSON_UNESCAPED_UNICODE) ?>, _mm=<?= $mM ?>, _yy=<?= $mY ?>;
function dshTab(btn,id){ btn.parentNode.querySelectorAll('.dsh-tab').forEach(function(b){b.classList.remove('on');}); btn.classList.add('on');
  ['proc','atr','comp'].forEach(function(p){ document.getElementById('pane-'+p).classList.toggle('on',p===id); }); }
function mcalHover(el,day){ var items=window.DAYDATA[day]; if(!items||!items.length)return; var p=document.getElementById('mcalPop');
  var h='<div class="pd">'+day+' de '+_MESL[_mm]+'</div>';
  items.slice(0,6).forEach(function(it){ var c=it.tipo==='evento'?'#6b7280':'#e0a000';
    h+='<div class="pi"><span class="pdot" style="background:'+c+'"></span>'+(it.hora?'<span class="ph">'+it.hora+'</span> ':'')+'<b>'+escHtml(it.t)+'</b></div>'; });
  if(items.length>6) h+='<div class="pi" style="color:rgba(255,255,255,.5)">+'+(items.length-6)+' más</div>';
  p.innerHTML=h; var r=el.getBoundingClientRect();
  var left=Math.min(r.right+10, window.innerWidth-244); var top=Math.max(10, Math.min(r.top-8, window.innerHeight-160));
  p.style.left=left+'px'; p.style.top=top+'px'; p.classList.add('on'); }
function mcalOut(){ document.getElementById('mcalPop').classList.remove('on'); }
</script>
<?php erp_foot(); ?>
