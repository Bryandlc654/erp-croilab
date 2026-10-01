<?php
/* Horas de equipo (autónomos por horas). Álvaro registra sus horas y ve lo que
   cobrará ese mes; Víctor/Gabi ven a todo el equipo y ajustan tarifas.
   Estética minimal (Apple / ClickUp). */
require_once __DIR__ . '/../auth.php';
require_admin();
require_once __DIR__ . '/erp_nav.php';
ensure_time_schema();
/* Fase 5: el puente horas -> gasto en Contabilidad. */
require_once __DIR__ . '/lib/puentes.php';
ensure_puentes_schema();

function hhmm($min){ $min=(int)$min; $h=intdiv($min,60); $m=$min%60; if($m===0) return $h.' h'; return $h.' h '.$m.'m'; }
$MESES = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

/* Quién está usando la página: las horas se guardan con su id. */
$me    = current_admin();
$meId  = (int)($me['id'] ?? 0);
$mgr   = can_edit();                     // owner/editor: ve a todos y ajusta tarifas

/* miembro objetivo */
$members = db()->query('SELECT id, username, es_autonomo, tarifa_hora, iva_pct, irpf_pct FROM admins ORDER BY es_autonomo DESC, username')->fetchAll();
$byId=[]; foreach($members as $mrow) $byId[(int)$mrow['id']]=$mrow;
$target = $meId;
if ($mgr && isset($_GET['u']) && isset($byId[(int)$_GET['u']])) $target = (int)$_GET['u'];
$member = $byId[$target] ?? $byId[$meId] ?? ['id'=>$meId,'username'=>$me['username'],'es_autonomo'=>0,'tarifa_hora'=>0,'iva_pct'=>0,'irpf_pct'=>0];

$m = (isset($_GET['m']) && preg_match('/^\d{4}-\d{2}$/',$_GET['m'])) ? $_GET['m'] : date('Y-m');

/* ---------- POST ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    $a = $_POST['action'] ?? '';
    $ret = 'fin-horas.php?u='.$target.'&m='.$m;
    if ($a==='rate_save' && $mgr) {
        db()->prepare('UPDATE admins SET es_autonomo=?, tarifa_hora=?, iva_pct=?, irpf_pct=? WHERE id=?')
            ->execute([isset($_POST['es_autonomo'])?1:0, (float)num_es($_POST['tarifa_hora']??'0',false), (float)num_es($_POST['iva_pct']??'0',false), (float)num_es($_POST['irpf_pct']??'0',false), $target]);
        header('Location: '.$ret); exit;
    }
    if ($a==='extra_add') {
        // sólo puedes añadir a lo tuyo, salvo que seas manager
        if ($mgr || $target===$meId) {
            $concepto = trim($_POST['concepto'] ?? '');
            $fecha = ($_POST['fecha'] ?? '')!=='' ? $_POST['fecha'] : date('Y-m-d');
            $tipo = $_POST['tipo'] ?? 'horas';
            $min=0; $imp=null;
            if ($tipo==='importe') { $imp = (float)num_es($_POST['valor']??'0', false); }
            else { $min = (int)round(((float)num_es($_POST['valor']??'0', false))*60); }
            if ($concepto!=='' && ($min>0 || $imp!==null)) {
                db()->prepare('INSERT INTO time_entries (admin_id, task_id, client_id, fecha, minutos, importe, concepto) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$target, null, null, $fecha, $min, $imp, $concepto]);
            }
        }
        header('Location: '.$ret); exit;
    }
    /* Puente horas -> Contabilidad. El mes entero se apunta como UN gasto de
       «Equipo» y las horas quedan marcadas para que no se puedan cobrar dos
       veces. Los límites del mes se calculan aquí porque $start/$end todavía
       no existen a esta altura del archivo. */
    if ($a==='to_expense' && $mgr) {
        $ini = $m.'-01'; $fin = date('Y-m-t', strtotime($ini));
        $res = pu_horas_a_gasto($target, $ini, $fin);
        header('Location: '.$ret.'&flash='.rawurlencode($res['msg'] ?? '').'&fok='.(!empty($res['ok'])?'1':'0')); exit;
    }
    if ($a==='entry_del') {
        $eid=(int)($_POST['eid']??0);
        $row=db()->prepare('SELECT admin_id FROM time_entries WHERE id=?'); $row->execute([$eid]); $own=$row->fetchColumn();
        if ($own!==false && ($mgr || (int)$own===$meId)) db()->prepare('DELETE FROM time_entries WHERE id=?')->execute([$eid]);
        header('Location: '.$ret); exit;
    }
}

/* ---------- navegación de mes ---------- */
$prev = date('Y-m', strtotime($m.'-01 -1 month'));
$next = date('Y-m', strtotime($m.'-01 +1 month'));
[$yy,$mm] = array_map('intval', explode('-',$m));
$mLabel = ($MESES[$mm]??'').' '.$yy;
$start=$m.'-01'; $end=date('Y-m-t',strtotime($start));

/* ---------- datos del mes ---------- */
$q=db()->prepare("SELECT te.*, t.titulo AS ttitulo, t.id AS tid, c.name AS cname
                  FROM time_entries te
                  LEFT JOIN tasks t ON t.id=te.task_id
                  LEFT JOIN clients c ON c.id=COALESCE(te.client_id,t.client_id)
                  WHERE te.admin_id=? AND te.fecha BETWEEN ? AND ?
                  ORDER BY te.fecha DESC, te.id DESC");
$q->execute([$target,$start,$end]);
$rows=$q->fetchAll();

$taskRows=[]; $extraRows=[]; $minTot=0; $impExtra=0;
foreach($rows as $r){
    $minTot += (int)$r['minutos'];
    if ($r['importe']!==null) $impExtra += (float)$r['importe'];
    if ($r['task_id']) $taskRows[]=$r; else $extraRows[]=$r;
}
$tarifa = (float)$member['tarifa_hora'];
$horas  = $minTot/60;
$baseHoras = $horas*$tarifa;
$base = $baseHoras + $impExtra;
$ivaP = (float)$member['iva_pct']; $irpfP=(float)$member['irpf_pct'];
$iva  = $base*$ivaP/100;
$irpf = $base*$irpfP/100;
$total = $base + $iva - $irpf;

/* ¿qué queda por volcar a Contabilidad y qué ya se volcó? */
$pendN=0; $pendMin=0; $pendImp=0; $accIds=[];
foreach($rows as $r){
    $ac=(int)($r['acc_id'] ?? 0);
    if($ac){ $accIds[$ac]=true; }
    else { $pendN++; $pendMin+=(int)$r['minutos']; if($r['importe']!==null) $pendImp+=(float)$r['importe']; }
}
$pendEur = $pendImp + ($pendMin/60)*$tarifa;
$accId   = $accIds ? (int)array_key_first($accIds) : 0;

/* horas por tarea agrupadas */
$byTask=[];
foreach($taskRows as $r){ $k=(int)$r['task_id']; if(!isset($byTask[$k])) $byTask[$k]=['t'=>$r,'min'=>0,'ids'=>[]]; $byTask[$k]['min']+=(int)$r['minutos']; $byTask[$k]['ids'][]=$r; }
$fmtPct = function($p){ return rtrim(rtrim(number_format((float)$p,2,',','.'),'0'),','); };

erp_head('horas', 'Horas de equipo', 'fin-canvas');
?>
<style>
/* Lienzo gris estilo Apple: tarjetas blancas flotantes sobre fondo suave. */
body.fin-canvas .main{background:#f5f5f7}
body.fin-canvas .erp-wrap{animation:none;padding:36px 48px 80px}
body.fin-canvas h1{letter-spacing:-.5px}
@keyframes finIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.hz-wrap{max-width:none}
.hz-top{animation:finIn .5s cubic-bezier(.2,.7,.3,1) both}
.hz-cards .hz-c{animation:finIn .55s cubic-bezier(.2,.7,.3,1) both}
.hz-cards .hz-c:nth-child(1){animation-delay:.06s}.hz-cards .hz-c:nth-child(2){animation-delay:.11s}.hz-cards .hz-c:nth-child(3){animation-delay:.16s}
.hz-br{animation:finIn .55s cubic-bezier(.2,.7,.3,1) .2s both}
.panel{animation:finIn .55s cubic-bezier(.2,.7,.3,1) .24s both}
.hz-br+.panel,.hz-cards+.panel{animation-delay:.24s}
.panel~.panel{animation-delay:.3s}.panel~.panel~.panel{animation-delay:.36s}
/* Cabecera */
.hz-top{display:flex;align-items:center;gap:14px;margin-bottom:22px;flex-wrap:wrap}
.hz-who{display:flex;align-items:center;gap:12px;flex:1;min-width:220px}
.hz-av{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;flex:none}
.hz-who h1{margin:0;font-size:20px;line-height:1.15}
.hz-who .sub{font-size:12.5px;color:var(--muted);margin-top:2px}
.hz-sel{position:relative}
.hz-sel select.plain{appearance:none;-webkit-appearance:none;background:#fff;border:1px solid var(--line);border-radius:11px;padding:9px 34px 9px 14px;font-size:13px;font-weight:600;color:var(--ink);font-family:inherit;cursor:pointer;transition:border-color .12s}
.hz-sel select.plain:hover{border-color:#dcdde1}
.hz-sel::after{content:"";position:absolute;right:13px;top:50%;transform:translateY(-30%);width:6px;height:6px;border-right:2px solid #9aa0a8;border-bottom:2px solid #9aa0a8;transform:translateY(-60%) rotate(45deg);pointer-events:none}
.hz-month{display:flex;align-items:center;gap:2px;background:#fff;border:1px solid var(--line);border-radius:11px;padding:4px}
.hz-month a{width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:8px;color:var(--ink);text-decoration:none;font-size:16px}
.hz-month a:hover{background:var(--soft)}
.hz-month .lbl{font-weight:600;font-size:13px;min-width:120px;text-align:center;color:var(--ink-strong);text-transform:capitalize}
.hz-month .today{font-size:12px;color:var(--muted);font-weight:600;padding:0 9px;text-decoration:none}
.hz-month .today:hover{color:var(--ink)}
/* Tarjetas resumen — todas claras y coherentes */
.hz-cards{display:grid;grid-template-columns:1fr 1fr 1.5fr;gap:18px;margin-bottom:20px}
@media(max-width:860px){.hz-cards{grid-template-columns:1fr}}
.hz-c{border:1px solid rgba(16,19,24,.06);border-radius:20px;padding:24px 26px;background:#fff;box-shadow:0 1px 2px rgba(16,19,24,.04),0 12px 30px -20px rgba(16,19,24,.14)}
.hz-c .k{font-size:11px;color:var(--muted);font-weight:650;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:8px}
.hz-c .k svg{width:14px;height:14px;color:var(--label)}
.hz-c .v{font-size:28px;font-weight:800;color:var(--ink-strong);letter-spacing:-.6px;margin-top:11px}
.hz-c .sub{font-size:12px;color:var(--muted);margin-top:5px;line-height:1.5}
.hz-c.tot{background:linear-gradient(180deg,#fbfdfc,#f6faf8);border-color:#d9ecdf}
.hz-c.tot .k svg{color:var(--ok)}
.hz-c.tot .v{color:#0f7a43}
.hz-c.tot .brk{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
.hz-c.tot .brk .chip{font-size:11.5px;background:#fff;border:1px solid #e0ede5;border-radius:99px;padding:3px 10px;color:#3c5c49;font-weight:600}
.hz-c.tot .brk .chip b{color:#0f7a43}
/* Puente a contabilidad */
.hz-br{display:flex;align-items:center;gap:14px;flex-wrap:wrap;border:1px solid rgba(16,19,24,.06);border-radius:18px;padding:15px 20px;background:#fff;margin-bottom:18px;box-shadow:0 1px 2px rgba(16,19,24,.04),0 12px 30px -20px rgba(16,19,24,.14)}
.hz-br .bi{width:38px;height:38px;border-radius:11px;background:var(--soft);color:var(--ink-strong);display:flex;align-items:center;justify-content:center;flex:none}
.hz-br .bm{flex:1;min-width:220px}
.hz-br .bm .t{font-size:13.5px;font-weight:650;color:var(--ink-strong)}
.hz-br .bm .s{font-size:12px;color:var(--muted);margin-top:3px;line-height:1.55}
.hz-br button{border:none;background:var(--accent);color:#fff;border-radius:10px;padding:10px 17px;font-size:13px;font-weight:600;cursor:pointer;flex:none;transition:filter .12s}
.hz-br button:hover{filter:brightness(1.12)}
.hz-br a.go{border:1px solid var(--line);border-radius:10px;padding:9px 15px;font-size:13px;font-weight:600;color:var(--ink);text-decoration:none;flex:none}
.hz-br a.go:hover{background:var(--soft)}
.hz-br.done{border-color:#cfeadd;background:#f7fcf9}
.hz-br.done .bi{background:#e6f6ec;color:var(--ok)}
/* Paneles */
.panel{background:#fff;border:1px solid rgba(16,19,24,.06);border-radius:20px;padding:10px 26px 24px;margin-bottom:18px;box-shadow:0 1px 2px rgba(16,19,24,.04),0 12px 30px -20px rgba(16,19,24,.14)}
.panel h2{font-size:11px;font-weight:650;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin:0;display:flex;align-items:center;gap:8px;padding:18px 0 8px;border-bottom:1px solid var(--line2)}
.panel h2 svg{width:14px;height:14px;color:var(--label)}
.panel h2 .cnt{margin-left:auto;font-size:12px;color:var(--ink);font-weight:700;text-transform:none;letter-spacing:0}
.tl{display:flex;flex-direction:column}
.tl .row{display:flex;align-items:center;gap:13px;padding:14px 2px;border-top:1px solid var(--line2)}
.tl .row:first-child{border-top:none}
.tl .ico{width:36px;height:36px;border-radius:11px;background:var(--soft);color:#6b7079;display:flex;align-items:center;justify-content:center;flex:none}
.tl .mn{flex:1;min-width:0}
.tl .mn .t{font-size:13.5px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tl .mn .t a{color:inherit;text-decoration:none}.tl .mn .t a:hover{color:var(--accent)}
.tl .mn .s{font-size:12px;color:var(--muted);margin-top:1px}
.tl .amt{font-size:14px;font-weight:750;color:var(--ink-strong);flex:none;text-align:right}
.tl .amt .e{display:block;font-size:11.5px;color:var(--muted);font-weight:600}
.tl .del{border:none;background:none;color:var(--label);cursor:pointer;padding:7px;border-radius:8px;flex:none}
.tl .del:hover{background:#fdecec;color:#e5484d}
.tl .empty{color:var(--muted);font-size:13px;padding:22px 4px;text-align:center}
/* Añadir extra */
.hz-add{display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin-top:14px;padding-top:14px;border-top:1px solid var(--line2)}
.hz-add input{border:1px solid var(--line);border-radius:10px;padding:9px 11px;font-size:13px;font-family:inherit;color:var(--ink);outline:none;transition:border-color .12s,box-shadow .12s}
.hz-add input:hover{border-color:#dcdde1}
.hz-add input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.hz-add .ci{flex:1;min-width:170px}
.hz-seg{display:inline-flex;background:#f1f2f4;border-radius:10px;padding:3px;gap:3px}
.hz-seg button{border:none;background:none;border-radius:8px;padding:7px 13px;font-size:12.5px;font-weight:600;color:#6b7079;cursor:pointer;font-family:inherit}
.hz-seg button.on{background:#fff;color:var(--ink-strong);box-shadow:0 1px 2px rgba(16,19,24,.12)}
.hz-add .vwrap{display:inline-flex;align-items:center;gap:6px}
.hz-add .vwrap .u{color:var(--muted);font-weight:600;font-size:13px;width:12px}
.hz-add button.go{border:none;background:var(--accent);color:#fff;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:600;cursor:pointer;transition:filter .12s}
.hz-add button.go:hover{filter:brightness(1.12)}
/* Tarifa */
.rate{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;margin-top:14px}
.rate .f{display:flex;flex-direction:column;gap:6px}
.rate .f label{font-size:11.5px;color:var(--muted);font-weight:600}
.rate .f input{border:1px solid var(--line);border-radius:10px;padding:9px 11px;font-size:13.5px;font-family:inherit;width:120px;outline:none;transition:border-color .12s,box-shadow .12s}
.rate .f input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.rate .chk{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink);font-weight:600;padding-bottom:9px}
.rate .chk input{width:16px;height:16px;accent-color:var(--accent)}
.rate button{border:none;background:var(--accent);color:#fff;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:600;cursor:pointer;transition:filter .12s}
.rate button:hover{filter:brightness(1.12)}
.hz-note{font-size:12px;color:var(--muted);margin-top:12px;line-height:1.5}
[data-theme=dark] body.fin-canvas .main{background-color:var(--bg)}
[data-theme=dark] .hz-sel select.plain{background-color:var(--field)}
[data-theme=dark] .hz-sel select.plain:hover{border-color:var(--line-strong)}
[data-theme=dark] .hz-month{background-color:var(--card)}
[data-theme=dark] .hz-c{background-color:var(--card)}
[data-theme=dark] .hz-c.tot{background:var(--soft);border-color:var(--ok-line)}
[data-theme=dark] .hz-c.tot .k svg{color:var(--ok)}
[data-theme=dark] .hz-c.tot .v{color:var(--ok)}
[data-theme=dark] .hz-c.tot .brk .chip{background-color:var(--card);border-color:var(--line);color:var(--ink)}
[data-theme=dark] .hz-c.tot .brk .chip b{color:var(--ok)}
[data-theme=dark] .hz-br{background-color:var(--card)}
[data-theme=dark] .hz-br button{color:var(--accent-fg)}
[data-theme=dark] .hz-br.done{border-color:var(--ok-line);background-color:var(--ok-bg)}
[data-theme=dark] .hz-br.done .bi{background-color:var(--ok-bg);color:var(--ok)}
[data-theme=dark] .panel{background-color:var(--card)}
[data-theme=dark] .tl .ico{color:var(--muted)}
[data-theme=dark] .tl .del{color:var(--muted)}
[data-theme=dark] .tl .del:hover{background-color:var(--danger-bg);color:var(--danger)}
[data-theme=dark] .hz-add input{background-color:var(--field)}
[data-theme=dark] .hz-add input:hover{border-color:var(--line-strong)}
[data-theme=dark] .hz-seg{background-color:var(--soft)}
[data-theme=dark] .hz-seg button{color:var(--muted)}
[data-theme=dark] .hz-seg button.on{background-color:var(--card);color:var(--ink-strong)}
[data-theme=dark] .hz-add button.go,[data-theme=dark] .rate button{color:var(--accent-fg)}
[data-theme=dark] .rate .f input{background-color:var(--field)}
/* ---- Móvil (teléfono) ---- */
@media(max-width:640px){
  body.fin-canvas .erp-wrap{padding:20px 16px 60px}
  .hz-top{gap:12px}
  .hz-who h1{font-size:18px}
  .hz-sel,.hz-sel select.plain{width:100%}
  .hz-month{flex:1 1 100%;justify-content:center}
  .hz-month .lbl{min-width:0;flex:1}
  .hz-cards{gap:14px}
  .hz-c{padding:18px 18px;border-radius:16px}
  .hz-c .v{font-size:24px}
  .panel{padding:8px 18px 20px}
  .hz-br{padding:14px 16px}
  .hz-br button,.hz-br a.go{width:100%;text-align:center}
  .hz-add{gap:8px}
  .hz-add .ci{flex:1 1 100%;min-width:0}
  .hz-add input.dpick{flex:1 1 auto}
  .hz-add button.go{flex:1 1 100%}
  .rate .f input{width:100%}
  .rate .f{flex:1 1 100%}
}
</style>

<div class="hz-wrap">
  <div class="hz-top">
    <div class="hz-who">
      <div class="hz-av" style="background:<?= avatar_color($member['username']) ?>" data-uid="<?= (int)$member['id'] ?>"><?= e(mb_strtoupper(mb_substr($member['username'],0,2))) ?></div>
      <div>
        <h1><?= e($member['username']) ?></h1>
        <div class="sub"><?= $tarifa>0 ? eur($tarifa).' / hora' : 'Sin tarifa configurada' ?><?= (int)$member['es_autonomo']?' · autónomo':'' ?></div>
      </div>
    </div>
    <?php if($mgr && count($members)>1): ?>
      <div class="hz-sel">
        <select class="plain" aria-label="Miembro del equipo" onchange="location.href='fin-horas.php?u='+this.value+'&m=<?= $m ?>'">
          <?php foreach($members as $mr): ?>
            <option value="<?= (int)$mr['id'] ?>" <?= (int)$mr['id']===$target?'selected':'' ?>><?= e($mr['username']) ?><?= (int)$mr['es_autonomo']?' · autónomo':'' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="hz-month">
      <a href="?u=<?= $target ?>&m=<?= $prev ?>" title="Anterior">‹</a>
      <span class="lbl"><?= e($mLabel) ?></span>
      <a href="?u=<?= $target ?>&m=<?= $next ?>" title="Siguiente">›</a>
      <a class="today" href="?u=<?= $target ?>&m=<?= date('Y-m') ?>">Hoy</a>
    </div>
  </div>

  <div class="hz-cards">
    <div class="hz-c">
      <div class="k"><?= ic('clock',14) ?> Horas del mes</div>
      <div class="v"><?= number_format($horas,2,',','.') ?> h</div>
      <div class="sub"><?= count($byTask) ?> tarea<?= count($byTask)===1?'':'s' ?> · <?= count($extraRows) ?> extra<?= count($extraRows)===1?'':'s' ?></div>
    </div>
    <div class="hz-c">
      <div class="k"><?= ic('euro',14) ?> Base</div>
      <div class="v"><?= eur($base) ?></div>
      <div class="sub"><?= $tarifa>0? number_format($horas,2,',','.').' h × '.eur($tarifa) : 'Configura la tarifa' ?><?= $impExtra>0? ' + '.eur($impExtra).' extras':'' ?></div>
    </div>
    <div class="hz-c tot">
      <div class="k"><?= ic('euro',14) ?> Total a cobrar</div>
      <div class="v"><?= eur($total) ?></div>
      <div class="brk">
        <span class="chip">Base <b><?= eur($base) ?></b></span>
        <?php if($ivaP>0): ?><span class="chip">IVA <?= $fmtPct($ivaP) ?>% <b>+<?= eur($iva) ?></b></span><?php endif; ?>
        <?php if($irpfP>0): ?><span class="chip">IRPF <?= $fmtPct($irpfP) ?>% <b>−<?= eur($irpf) ?></b></span><?php endif; ?>
      </div>
    </div>
  </div>

  <?php if($mgr): ?>
  <div class="hz-br<?= $pendN?'':' done' ?>">
    <div class="bi"><?= ic($pendN?'euro':'check',16) ?></div>
    <div class="bm">
      <div class="t"><?= $pendN ? 'Estas horas todavía no están en Contabilidad' : ($accId ? 'Este mes ya está apuntado como gasto' : 'Nada que volcar a Contabilidad') ?></div>
      <div class="s"><?php if($pendN): ?>
          <?= $pendN ?> registro<?= $pendN===1?'':'s' ?> pendiente<?= $pendN===1?'':'s' ?> · <?= hhmm($pendMin) ?> · <?= eur($pendEur) ?> se apuntará como gasto de «Equipo» con fecha <?= date('d/m/Y', strtotime($end)) ?>.
        <?php elseif($accId): ?>
          Las horas de <?= e($mLabel) ?> ya se pasaron a un gasto. Si añades más horas después, aparecerá otra vez el botón para volcar solo las nuevas.
        <?php else: ?>
          En cuanto se registren horas este mes podrás pasarlas a Contabilidad de un clic.
        <?php endif; ?></div>
    </div>
    <?php if($accId): ?><a class="go" href="contabilidad.php?y=<?= $yy ?>&em=empresa">Ver en Contabilidad</a><?php endif; ?>
    <?php if($pendN): ?>
    <form method="post" onsubmit="return erpSubmitAsk(this,'Se apuntará <?= e(eur($pendEur)) ?> como gasto de «Equipo» con fecha <?= date('d/m/Y', strtotime($end)) ?>. Las horas quedan marcadas para no cobrarlas dos veces.',{titulo:'¿Pasar las horas a Contabilidad?',ok:'Apuntar el gasto'})">
      <input type="hidden" name="action" value="to_expense">
      <button type="submit">Pasar a gasto</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="panel">
    <h2><?= ic('check',14) ?> Horas por tarea <span class="cnt"><?= hhmm(array_sum(array_map(fn($g)=>$g['min'],$byTask))) ?></span></h2>
    <div class="tl">
      <?php if(!$byTask): ?>
        <div class="empty">Aún no hay tiempo registrado en tareas este mes.<br>Se registra en el campo «Tiempo» de cada tarea.</div>
      <?php else: foreach($byTask as $g): $r=$g['t']; ?>
        <div class="row">
          <div class="ico"><?= ic('check',16) ?></div>
          <div class="mn">
            <div class="t"><a href="task.php?id=<?= (int)$r['task_id'] ?>"><?= e($r['ttitulo'] ?: 'Tarea #'.(int)$r['task_id']) ?></a></div>
            <div class="s"><?= $r['cname']? e($r['cname']).' · ':'' ?><?= count($g['ids']) ?> registro<?= count($g['ids'])===1?'':'s' ?></div>
          </div>
          <div class="amt"><?= hhmm($g['min']) ?><span class="e"><?= $tarifa>0? eur($g['min']/60*$tarifa):'—' ?></span></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div class="panel">
    <h2><?= ic('plus',14) ?> Extras manuales <span class="cnt"><?= count($extraRows) ?></span></h2>
    <div class="tl">
      <?php if(!$extraRows): ?>
        <div class="empty">Sin extras este mes.</div>
      <?php else: foreach($extraRows as $r): ?>
        <div class="row">
          <div class="ico"><?= ic($r['importe']!==null?'euro':'clock',16) ?></div>
          <div class="mn">
            <div class="t"><?= e($r['concepto'] ?: 'Extra') ?></div>
            <div class="s"><?= date('d/m/Y', strtotime($r['fecha'])) ?></div>
          </div>
          <div class="amt">
            <?php if($r['importe']!==null): ?><?= eur($r['importe']) ?><?php else: ?><?= hhmm($r['minutos']) ?><span class="e"><?= $tarifa>0? eur($r['minutos']/60*$tarifa):'—' ?></span><?php endif; ?>
          </div>
          <?php if($mgr || $target===$meId): ?>
          <form method="post" onsubmit="return erpSubmitAsk(this,'¿Eliminar este extra?')"><input type="hidden" name="action" value="entry_del"><input type="hidden" name="eid" value="<?= (int)$r['id'] ?>"><button class="del" type="submit" title="Eliminar"><?= ic('trash',15) ?></button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php if($mgr || $target===$meId): ?>
    <form method="post" class="hz-add">
      <input type="hidden" name="action" value="extra_add">
      <input class="ci" name="concepto" placeholder="Concepto (ej: reunión, desplazamiento…)" required>
      <div class="hz-seg" id="hzSeg">
        <button type="button" class="on" onclick="hzModo('horas')">Horas</button>
        <button type="button" onclick="hzModo('importe')">Importe €</button>
      </div>
      <input type="hidden" name="tipo" id="hzTipo" value="horas">
      <span class="vwrap"><input name="valor" type="text" inputmode="decimal" placeholder="0" style="width:80px" required><span class="u" id="hzUnit">h</span></span>
      <input type="text" class="dpick" data-iso="<?= date('Y-m-d') ?>" data-sync="#fhFecha" style="width:118px" autocomplete="off">
      <input type="hidden" name="fecha" id="fhFecha" value="<?= date('Y-m-d') ?>">
      <button type="submit" class="go">Añadir</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if($mgr): ?>
  <div class="panel">
    <h2><?= ic('settings',14) ?> Tarifa y fiscalidad de <?= e($member['username']) ?></h2>
    <form method="post" class="rate">
      <input type="hidden" name="action" value="rate_save">
      <label class="chk"><input type="checkbox" name="es_autonomo" <?= (int)$member['es_autonomo']?'checked':'' ?>> Autónomo por horas</label>
      <div class="f"><label>Tarifa / hora (€)</label><input name="tarifa_hora" type="text" inputmode="decimal" aria-label="Tarifa por hora en euros" value="<?= rtrim(rtrim(number_format((float)$member['tarifa_hora'],2,'.',''),'0'),'.') ?>"></div>
      <div class="f"><label>IVA %</label><input name="iva_pct" type="text" inputmode="decimal" aria-label="IVA en porcentaje" value="<?= rtrim(rtrim(number_format((float)$member['iva_pct'],2,'.',''),'0'),'.') ?>"></div>
      <div class="f"><label>IRPF %</label><input name="irpf_pct" type="text" inputmode="decimal" aria-label="IRPF en porcentaje" value="<?= rtrim(rtrim(number_format((float)$member['irpf_pct'],2,'.',''),'0'),'.') ?>"></div>
      <button type="submit">Guardar</button>
    </form>
    <div class="hz-note">Para un autónomo típico en España: IVA 21%, IRPF 15%. Déjalo a 0 si solo quieres horas × tarifa. Estimación orientativa, no sustituye a tu gestoría.</div>
  </div>
  <?php endif; ?>
</div>

<script>
/* Renombrada: el campo se llama id="hzTipo" y un manejador escrito en el HTML
   (onchange="...") busca ese nombre en el formulario antes que en el resto,
   asi que encontraba el CAMPO y no esta funcion. El id no se toca: lo usa
   getElementById. Regla: id de campo y nombre de funcion nunca iguales. */
function hzModo(v){document.getElementById('hzTipo').value=v;document.getElementById('hzUnit').textContent=(v==='importe'?'€':'h');
  var bs=document.querySelectorAll('#hzSeg button');bs[0].classList.toggle('on',v==='horas');bs[1].classList.toggle('on',v==='importe');}
</script>

<?php /* El resultado del volcado a Contabilidad se cuenta con el toast de siempre. */
      if (isset($_GET['flash']) && $_GET['flash']!==''): ?>
<script>window.addEventListener('load',function(){ if(window.toast)toast(<?= json_encode((string)$_GET['flash']) ?><?= (($_GET['fok']??'1')==='1')?'':", 'err'" ?>); });</script>
<?php endif; ?>

<?php erp_foot(); ?>
