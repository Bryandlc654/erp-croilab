<?php
/* Tickets de Soporte: incidencias internas / de clientes con hilo de respuestas. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
/* Borrar en el ERP no es definitivo: pasa por la papelera y se puede deshacer. */
require_once __DIR__ . '/lib/papelera.php';

$me = current_admin(); $meId = (int)$me['id'];

db()->exec("CREATE TABLE IF NOT EXISTS support_tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asunto VARCHAR(200) NOT NULL,
  cuerpo TEXT,
  client_id INT DEFAULT NULL,
  prioridad INT DEFAULT 2,
  estado VARCHAR(20) DEFAULT 'abierto',
  assignee_id INT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->exec("CREATE TABLE IF NOT EXISTS support_replies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL, admin_id INT, cuerpo TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$ESTADOS = ['abierto'=>['Abierto','#3b82f6'],'en_curso'=>['En curso','#7b68ee'],'esperando'=>['Esperando','#e0a000'],'resuelto'=>['Resuelto','#12a150'],'cerrado'=>['Cerrado','#9aa0a8']];
$PRIOS = [1=>['Baja','#94a3b8'],2=>['Normal','#3b82f6'],3=>['Alta','#f59e0b'],4=>['Urgente','#ef4444']];
$admins = db()->query('SELECT id, username FROM admins ORDER BY username')->fetchAll();
$aName=[]; foreach($admins as $a) $aName[$a['id']]=$a['username'];
$clients = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();
$cName=[]; foreach($clients as $c) $cName[$c['id']]=$c['name'];

if ($_SERVER['REQUEST_METHOD']==='POST' && can_edit()) {
  $a=$_POST['action']??'';
  if ($a==='create') {
    $asunto=trim($_POST['asunto']??'');
    $ajax = ($_POST['ajax']??'')==='1';
    if ($asunto!=='') {
      db()->prepare('INSERT INTO support_tickets (asunto,cuerpo,client_id,prioridad,estado,assignee_id,created_by) VALUES (?,?,?,?,?,?,?)')
        ->execute([$asunto, trim($_POST['cuerpo']??''), ($_POST['client_id']??'')!==''?(int)$_POST['client_id']:null, (int)($_POST['prioridad']??2), 'abierto', ($_POST['assignee_id']??'')!==''?(int)$_POST['assignee_id']:null, $meId]);
      $nid=(int)db()->lastInsertId();
      /* Si el ticket nace ya asignado a alguien, se le avisa. */
      if (($_POST['assignee_id']??'')!=='' && function_exists('notif_ticket_assigned')) notif_ticket_assigned($nid,(int)$_POST['assignee_id'],(string)($me['username']??''));
      if ($ajax) { header('Content-Type: application/json'); echo json_encode(['ok'=>1,'id'=>$nid]); exit; }
      header('Location: support.php?t='.$nid); exit;
    }
    if ($ajax) { header('Content-Type: application/json'); echo json_encode(['ok'=>0,'msg'=>'Escribe un asunto.']); exit; }
  } elseif ($a==='reply') {
    $tid=(int)($_POST['ticket_id']??0); $body=trim($_POST['cuerpo']??'');
    if ($tid && $body!=='') { db()->prepare('INSERT INTO support_replies (ticket_id,admin_id,cuerpo) VALUES (?,?,?)')->execute([$tid,$meId,$body]); db()->prepare('UPDATE support_tickets SET updated_at=NOW() WHERE id=?')->execute([$tid]); }
    header('Location: support.php?t='.$tid); exit;
  } elseif ($a==='set') {
    $tid=(int)($_POST['id']??0); $f=$_POST['field']??''; $v=$_POST['val']??'';
    if ($tid && in_array($f,['estado','prioridad','assignee_id','client_id'],true)) {
      $store = ($f==='prioridad')?(int)$v : (($f==='assignee_id'||$f==='client_id')?($v!==''?(int)$v:null):$v);
      /* Antes de escribir, mira a quién estaba asignado: solo se avisa si cambia
         de responsable (y a alguien de verdad), no en cada guardado del ticket. */
      $antesAsig = 0;
      if ($f==='assignee_id') { $qa=db()->prepare('SELECT assignee_id FROM support_tickets WHERE id=?'); $qa->execute([$tid]); $antesAsig=(int)$qa->fetchColumn(); }
      db()->prepare("UPDATE support_tickets SET `$f`=? WHERE id=?")->execute([$store,$tid]);
      if ($f==='assignee_id' && (int)$store>0 && (int)$store!==$antesAsig && function_exists('notif_ticket_assigned'))
        notif_ticket_assigned($tid,(int)$store,(string)($me['username']??''));
    }
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
  } elseif ($a==='del') {
    $tid=(int)($_POST['id']??0);
    $sn=db()->prepare('SELECT asunto FROM support_tickets WHERE id=?'); $sn->execute([$tid]); $sAsu=(string)($sn->fetchColumn() ?: '');
    /* El ticket vuelve con toda su conversación, que es lo que de verdad importa. */
    pap_borrar_flash('support_tickets', $tid, 'ticket', $sAsu, [['tabla'=>'support_replies','fk'=>'ticket_id']],
        $sAsu!=='' ? 'Ticket «'.$sAsu.'» eliminado' : 'Ticket eliminado');
    db()->prepare('DELETE FROM support_replies WHERE ticket_id=?')->execute([$tid]); db()->prepare('DELETE FROM support_tickets WHERE id=?')->execute([$tid]);
    header('Location: support.php'); exit;
  }
}

$cur = isset($_GET['t']) ? (int)$_GET['t'] : 0;
$fEstado = $_GET['fe'] ?? '';
/* ?cli=<id> filtra por cliente. Existe porque la ficha del cliente enseña sus
   tickets y el «Abrir →» tiene que llegar a esa misma lista ya filtrada, igual
   que hace facturas.php?cli=. Sin esto el enlace caía en la lista completa y
   había que volver a buscar el cliente a mano. */
$fCli = isset($_GET['cli']) ? (int)$_GET['cli'] : 0;
function reltime_s($ts){ if(!$ts)return''; $d=strtotime($ts);$diff=time()-$d; if($diff<60)return'ahora'; if($diff<3600)return floor($diff/60).' min'; if($diff<86400)return floor($diff/3600).' h'; return date('d/m/Y',$d); }

erp_head('tickets', 'Tickets de Soporte');

if ($cur) {
  $tk = db()->prepare('SELECT * FROM support_tickets WHERE id=?'); $tk->execute([$cur]); $tk=$tk->fetch();
}
?>
<style>
.sp-head{display:flex;align-items:center;gap:12px;margin-bottom:24px}
.sp-head h1{flex:1}
.sp-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:26px}
.sp-kpi{background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px 22px}
.sp-kpi .n{font-size:25px;font-weight:650;color:var(--ink-strong);letter-spacing:-.5px}
.sp-kpi .l{font-size:11.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;font-weight:600;margin-top:5px}
.sp-tbl{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
.sp-row{display:grid;grid-template-columns:1fr 150px 120px 120px 96px;gap:10px;align-items:center;padding:17px 22px;border-bottom:1px solid var(--line);cursor:pointer}
.sp-row:last-child{border-bottom:none}
.sp-row:hover{background:#fafbfc}
.sp-row.h{background:#fbfbfc;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;cursor:default}
.sp-row .su{display:flex;flex-direction:column;gap:3px;min-width:0}
.sp-row .su b{font-size:14.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sp-row .su span{font-size:11.5px;color:var(--muted)}
.sp-badge{font-size:11px;font-weight:600;padding:3px 10px;border-radius:99px;display:inline-flex;align-items:center;gap:6px;width:max-content}
.sp-badge .d{width:7px;height:7px;border-radius:50%}
.sp-prio{font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:6px}
.sp-prio .d{width:8px;height:8px;border-radius:2px}
.sp-av{width:26px;height:26px;border-radius:50%;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:9.5px;font-weight:700}
.sp-empty{padding:50px;text-align:center;color:var(--muted)}
/* detalle */
.sp-back{display:inline-flex;align-items:center;gap:7px;color:var(--muted);font-size:13px;margin-bottom:16px}
.sp-back:hover{color:var(--ink)}
.sp-detail{display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start}
.sp-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:28px 30px}
.sp-card h2{font-size:20px;font-weight:600;color:var(--ink-strong);margin-bottom:7px}
.sp-meta{font-size:12px;color:var(--muted);margin-bottom:20px}
.sp-body{font-size:14px;line-height:1.6;color:var(--ink);white-space:pre-wrap;padding-bottom:22px;border-bottom:1px solid var(--line);margin-bottom:22px}
.sp-reply{display:flex;gap:14px;margin-bottom:20px}
.sp-reply .rav{width:34px;height:34px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:650;font-size:12px;flex:none}
.sp-reply .rb{flex:1}
.sp-reply .rt{font-size:12.5px;margin-bottom:4px}.sp-reply .rt b{font-weight:600}.sp-reply .rt span{color:var(--muted);margin-left:8px;font-size:11px}
.sp-reply .rx{font-size:13.5px;line-height:1.6;color:var(--ink);white-space:pre-wrap}
.sp-replybox{margin-top:22px;display:flex;gap:10px;align-items:flex-end}
.sp-replybox textarea{flex:1;border:1px solid var(--line);border-radius:12px;padding:12px 15px;font-size:13.5px;line-height:1.55;font-family:inherit;resize:vertical;min-height:56px;outline:none}
.sp-side .b{background:#fff;border:1px solid var(--line);border-radius:14px;padding:21px 22px;margin-bottom:16px}
.sp-side .b h4{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;margin-bottom:14px}
.sp-field{margin-bottom:14px}.sp-field:last-child{margin-bottom:0}
.sp-field label{font-size:11.5px;color:var(--muted);font-weight:600;display:block;margin-bottom:6px}
.sp-field select{width:100%}
.sp-modal-ov{position:fixed;inset:0;background:rgba(20,22,28,.5);z-index:400;display:none;align-items:center;justify-content:center;padding:20px}
.sp-modal-ov.on{display:flex;animation:fadeIn .15s ease}
.sp-modal{background:#fff;border-radius:18px;width:520px;max-width:100%;box-shadow:0 30px 80px rgba(0,0,0,.28);animation:fadeUp .2s ease}
.sp-modal .mh{padding:18px 22px;border-bottom:1px solid var(--line);font-size:16px;font-weight:650}
.sp-modal .mb{padding:20px 22px}
.sp-modal .mf{padding:14px 22px;border-top:1px solid var(--line);display:flex;justify-content:flex-end;gap:8px}
.ctxmenu{position:fixed;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 46px rgba(0,0,0,.17);padding:5px;min-width:184px;z-index:500;display:none}
.ctxmenu.on{display:block;animation:pop .15s ease}
.ctxmenu a{display:block;padding:8px 12px;border-radius:8px;color:#4c515b;font-weight:500;cursor:pointer;font-size:13px}
.ctxmenu a:hover{background:var(--soft);color:var(--ink)}
.ctxmenu a.danger{color:#c0392b}.ctxmenu a.danger:hover{background:#fde8e8}
.ctxmenu .sep{height:1px;background:var(--line);margin:4px 6px}
/* ---- Modo oscuro (aditivo): remapea las superficies y textos propios ---- */
[data-theme=dark] .sp-kpi,
[data-theme=dark] .sp-tbl,
[data-theme=dark] .sp-card,
[data-theme=dark] .sp-side .b,
[data-theme=dark] .sp-modal{background-color:var(--card)}
[data-theme=dark] .sp-row.h,
[data-theme=dark] .sp-row:hover{background-color:var(--soft)}
[data-theme=dark] .sp-replybox textarea{background-color:var(--field);color:var(--ink)}
[data-theme=dark] .ctxmenu{background-color:var(--pop)}
[data-theme=dark] .ctxmenu a{color:var(--ink)}
[data-theme=dark] .ctxmenu a.danger{color:var(--danger)}
[data-theme=dark] .ctxmenu a.danger:hover{background-color:var(--danger-bg)}
/* ═══════════ MÓVIL — teléfono ≤640px: lista de filas compactas y planas ═══════════
   Cada ticket es una fila de dos líneas (asunto arriba; estado · prioridad ·
   actualizado, pequeño, debajo), sin caja por elemento y con poco padding: toda la
   fila abre el ticket. El detalle pasa a una sola columna. */
@media(max-width:640px){
  /* Cabecera: título en su fila, filtro + botón debajo con salto de línea. */
  .sp-head{flex-wrap:wrap;gap:10px;margin-bottom:16px}
  .sp-head h1{flex:1 1 100%}
  .sp-head .chip{order:3;flex:1 1 100%}
  .sp-head .mini-sel{flex:1;max-width:none!important}
  .sp-head .btn{flex:none}
  /* KPIs compactos, dos por fila. */
  .sp-kpis{grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
  .sp-kpi{padding:12px 14px}
  .sp-kpi .n{font-size:20px}
  .sp-kpi .l{font-size:10.5px;margin-top:3px}
  /* Lista plana como en Tareas: sin caja envolvente, filas a ras de página con
     solo un borde inferior fino; la cabecera de columnas fuera. */
  .sp-tbl{border:none;border-radius:0;background:none;overflow:visible}
  [data-theme=dark] .sp-tbl{background:none}
  .sp-row.h{display:none}
  .sp-row{grid-template-columns:auto auto 1fr;gap:3px 10px;padding:11px 2px;align-items:center;border-bottom:1px solid var(--line2)}
  .sp-row>*{min-width:0}
  /* Línea 1: asunto (con su #id · cliente pequeño) a todo el ancho. */
  .sp-row .su{grid-column:1 / -1;grid-row:1}
  .sp-row .su b{white-space:normal;font-size:14px}
  .sp-row .su span{font-size:11px}
  /* Línea 2: estado · prioridad a la izquierda, actualizado a la derecha. */
  .sp-row>*:nth-child(2){grid-column:1;grid-row:2;justify-self:start}   /* estado */
  .sp-row>*:nth-child(3){grid-column:2;grid-row:2;justify-self:start}   /* prioridad */
  .sp-row>*:nth-child(4){display:none}                                  /* asignado: fuera en móvil */
  .sp-row>*:nth-child(5){grid-column:3;grid-row:2;justify-self:end}     /* actualizado */
  .sp-badge{font-size:10.5px;padding:2px 8px}
  .sp-prio{font-size:11.5px}
  /* Detalle a una sola columna: propiedades arriba, conversación debajo. */
  .sp-detail{grid-template-columns:1fr;gap:16px}
  .sp-side{order:-1}
  .sp-card{padding:22px 18px}
  .sp-card h2{font-size:18px}
  /* Respuesta usable: caja a lo ancho y botón de ancho completo debajo. */
  .sp-replybox{flex-direction:column;align-items:stretch}
  .sp-replybox .btn{width:100%}
  /* Modal de nuevo ticket a lo alto cómodo. */
  .sp-modal .mb{padding:18px}
}
@media(max-width:400px){
  .sp-kpi{padding:11px 12px}
  .sp-kpi .n{font-size:19px}
  .sp-row{padding:10px 2px}
}
</style>

<?php if ($cur && $tk): $ev=$ESTADOS[$tk['estado']]??$ESTADOS['abierto']; $pv=$PRIOS[(int)$tk['prioridad']]??$PRIOS[2];
  $reps = db()->prepare('SELECT * FROM support_replies WHERE ticket_id=? ORDER BY id'); $reps->execute([$cur]); $reps=$reps->fetchAll(); ?>
  <a class="sp-back" href="support.php"><?= ic('back',15) ?> Volver a tickets</a>
  <div class="sp-detail">
    <div class="sp-card">
      <h2><?= e($tk['asunto']) ?></h2>
      <div class="sp-meta">#<?= (int)$tk['id'] ?> · Abierto por <?= e($aName[$tk['created_by']]??'—') ?> · <?= e(reltime_s($tk['created_at'])) ?><?php if($tk['client_id'] && isset($cName[$tk['client_id']])): ?> · Cliente: <b><?= e($cName[$tk['client_id']]) ?></b><?php endif; ?></div>
      <?php if(trim((string)$tk['cuerpo'])!==''): ?><div class="sp-body"><?= e($tk['cuerpo']) ?></div><?php endif; ?>
      <?php foreach($reps as $r): $nm=$aName[$r['admin_id']]??'?'; ?>
        <div class="sp-reply"><span class="rav" style="background:<?= avatar_color($nm) ?>"><?= e(mb_strtoupper(mb_substr($nm,0,2))) ?></span><div class="rb"><div class="rt"><b><?= e($nm) ?></b><span><?= e(reltime_s($r['created_at'])) ?></span></div><div class="rx"><?= e($r['cuerpo']) ?></div></div></div>
      <?php endforeach; ?>
      <?php if(!$reps): ?><p class="muted" style="font-size:12.5px">Sin respuestas todavía.</p><?php endif; ?>
      <?php if(can_edit()): ?>
      <form method="post" class="sp-replybox"><input type="hidden" name="action" value="reply"><input type="hidden" name="ticket_id" value="<?= $cur ?>">
        <textarea name="cuerpo" placeholder="Escribe una respuesta…" required></textarea>
        <button class="btn sm" type="submit">Responder</button>
      </form>
      <?php endif; ?>
    </div>
    <div class="sp-side">
      <div class="b">
        <h4>Propiedades</h4>
        <div class="sp-field"><label>Estado</label><select onchange="spSet(<?= $cur ?>,'estado',this.value)" <?= can_edit()?'':'disabled' ?>><?php foreach($ESTADOS as $k=>$v): ?><option value="<?= $k ?>" <?= $tk['estado']===$k?'selected':'' ?>><?= e($v[0]) ?></option><?php endforeach; ?></select></div>
        <div class="sp-field"><label>Prioridad</label><select onchange="spSet(<?= $cur ?>,'prioridad',this.value)" <?= can_edit()?'':'disabled' ?>><?php foreach($PRIOS as $k=>$v): ?><option value="<?= $k ?>" <?= (int)$tk['prioridad']===$k?'selected':'' ?>><?= e($v[0]) ?></option><?php endforeach; ?></select></div>
        <div class="sp-field"><label>Asignado a</label><select onchange="spSet(<?= $cur ?>,'assignee_id',this.value)" <?= can_edit()?'':'disabled' ?>><option value="">Sin asignar</option><?php foreach($admins as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$tk['assignee_id']===(int)$a['id']?'selected':'' ?>><?= e($a['username']) ?></option><?php endforeach; ?></select></div>
        <div class="sp-field"><label>Cliente</label><select onchange="spSet(<?= $cur ?>,'client_id',this.value)" <?= can_edit()?'':'disabled' ?>><option value="">— Ninguno —</option><?php foreach($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$tk['client_id']===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <?php if(can_edit()): ?><div class="b"><form method="post" onsubmit="return erpSubmitAsk(this,'¿Eliminar este ticket?')"><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= $cur ?>"><button class="btn danger sm" type="submit" style="width:100%">Eliminar ticket</button></form></div><?php endif; ?>
    </div>
  </div>
  <script>
  function spSet(id,field,val){fetch('support.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=set&id='+id+'&field='+field+'&val='+encodeURIComponent(val)}).catch(function(){});}
  </script>

<?php else: /* LISTA */
  /* Los dos filtros se combinan: se puede estar viendo «los abiertos de este
     cliente». Se montan a la vez para que elegir estado no borre el cliente. */
  $cond=[]; $args=[];
  if ($fEstado!=='') { $cond[]='estado=?';    $args[]=$fEstado; }
  if ($fCli)         { $cond[]='client_id=?'; $args[]=$fCli; }
  $where = $cond ? 'WHERE '.implode(' AND ',$cond) : '';
  /* Alcance: los tickets son de un cliente, así que se filtran por los clientes
     que esta persona puede ver. */
  $alc = function_exists('alcance_sql') ? alcance_sql('client_id') : '';
  if ($alc !== '') $where = ($where === '' ? 'WHERE 1' : $where) . $alc;
  $sql = "SELECT * FROM support_tickets $where ORDER BY FIELD(estado,'abierto','en_curso','esperando','resuelto','cerrado'), FIELD(prioridad,4,3,2,1), updated_at DESC";
  $st = db()->prepare($sql); $st->execute($args); $tickets=$st->fetchAll();
  /* Los contadores de arriba siguen al filtro de cliente: si estoy viendo un
     cliente, «3 abiertos» tiene que ser de él, no de toda la agencia. */
  $cnt=['abierto'=>0,'en_curso'=>0,'esperando'=>0,'resuelto'=>0,'cerrado'=>0];
  $cq = $fCli
      ? db()->prepare('SELECT estado, COUNT(*) n FROM support_tickets WHERE client_id=? GROUP BY estado')
      : db()->prepare('SELECT estado, COUNT(*) n FROM support_tickets GROUP BY estado');
  $cq->execute($fCli?[$fCli]:[]);
  foreach($cq as $rr){ if(isset($cnt[$rr['estado']])) $cnt[$rr['estado']]=(int)$rr['n']; }
?>
  <div class="sp-head">
    <h1>Tickets de Soporte</h1>
    <?php if($fCli && isset($cName[$fCli])): ?>
      <?php /* Sin esta etiqueta no hay forma de saber que la lista está filtrada:
               se ven pocos tickets y parece que faltan. La X vuelve a todos. */ ?>
      <span class="chip">Cliente: <?= e($cName[$fCli]) ?> <a href="support.php" style="margin-left:6px;color:var(--muted);font-weight:700">×</a></span>
    <?php endif; ?>
    <select class="mini-sel" style="max-width:200px" aria-label="Filtrar por estado" onchange="var u=new URL(location);this.value?u.searchParams.set('fe',this.value):u.searchParams.delete('fe');location.href=u"><option value="">Todos los estados</option><?php foreach($ESTADOS as $k=>$v): ?><option value="<?= $k ?>" <?= $fEstado===$k?'selected':'' ?>><?= e($v[0]) ?></option><?php endforeach; ?></select>
    <?php if(can_edit()): ?><button class="btn sm" onclick="spNew()"><?= ic('plus',14) ?> Nuevo ticket</button><?php endif; ?>
  </div>
  <div class="sp-kpis">
    <div class="sp-kpi"><div class="n"><?= $cnt['abierto'] ?></div><div class="l">Abiertos</div></div>
    <div class="sp-kpi"><div class="n"><?= $cnt['en_curso'] ?></div><div class="l">En curso</div></div>
    <div class="sp-kpi"><div class="n"><?= $cnt['esperando'] ?></div><div class="l">Esperando</div></div>
    <div class="sp-kpi"><div class="n"><?= $cnt['resuelto']+$cnt['cerrado'] ?></div><div class="l">Resueltos</div></div>
  </div>
  <div class="sp-tbl">
    <div class="sp-row h"><span>Asunto</span><span>Estado</span><span>Prioridad</span><span>Asignado</span><span>Actualizado</span></div>
    <?php if(!$tickets): ?><?= erp_empty('ticket', $fEstado!==''?'Sin tickets con este estado':'No hay tickets todavía', $fEstado!==''?'Prueba a quitar el filtro de estado.':'Cuando un cliente escriba desde su portal o crees un ticket, aparecerá aquí.', ($fEstado===''&&can_edit())?'<button type="button" class="btn" onclick="spNew();return false">'.ic('plus',15).' Crear ticket</button>':'') ?><?php endif; ?>
    <?php foreach($tickets as $t): $ev=$ESTADOS[$t['estado']]??$ESTADOS['abierto']; $pv=$PRIOS[(int)$t['prioridad']]??$PRIOS[2]; $an=$aName[$t['assignee_id']]??''; ?>
      <div class="sp-row" onclick="location.href='support.php?t=<?= (int)$t['id'] ?>'" oncontextmenu="return spMenu(event,<?= (int)$t['id'] ?>)">
        <div class="su"><b><?= e($t['asunto']) ?></b><span>#<?= (int)$t['id'] ?><?php if($t['client_id'] && isset($cName[$t['client_id']])): ?> · <?= e($cName[$t['client_id']]) ?><?php endif; ?></span></div>
        <span><span class="sp-badge" style="background:<?= $ev[1] ?>18;color:<?= $ev[1] ?>"><span class="d" style="background:<?= $ev[1] ?>"></span><?= e($ev[0]) ?></span></span>
        <span class="sp-prio" style="color:<?= $pv[1] ?>"><span class="d" style="background:<?= $pv[1] ?>"></span><?= e($pv[0]) ?></span>
        <span><?php if($an): ?><span class="sp-av" style="background:<?= avatar_color($an) ?>" title="<?= e($an) ?>"><?= e(mb_strtoupper(mb_substr($an,0,2))) ?></span><?php else: ?><span class="muted" style="font-size:12px">—</span><?php endif; ?></span>
        <span class="muted" style="font-size:12px"><?= e(reltime_s($t['updated_at'])) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if(can_edit()): ?>
  <div class="sp-modal-ov" id="spNewOv"><div class="sp-modal">
    <div class="mh">Nuevo ticket</div>
    <form method="post"><input type="hidden" name="action" value="create">
    <div class="mb">
      <label>Asunto</label><input type="text" name="asunto" placeholder="Resumen del problema" required autocomplete="off">
      <label>Descripción</label><textarea name="cuerpo" placeholder="Detalla la incidencia…"></textarea>
      <div class="row">
        <div><label>Prioridad</label><select name="prioridad"><?php foreach($PRIOS as $k=>$v): ?><option value="<?= $k ?>" <?= $k===2?'selected':'' ?>><?= e($v[0]) ?></option><?php endforeach; ?></select></div>
        <div><label>Asignar a</label><select name="assignee_id"><option value="">Sin asignar</option><?php foreach($admins as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['username']) ?></option><?php endforeach; ?></select></div>
      </div>
      <?php /* Si se llega filtrando por un cliente, el ticket nuevo ya nace suyo:
               quien viene de su ficha a abrir una incidencia no debería tener que
               volver a elegirlo en un desplegable de cien nombres. */ ?>
      <label>Cliente (opcional)</label><select name="client_id"><option value="">— Ninguno —</option><?php foreach($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $fCli===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
    </div>
    <div class="mf"><button class="btn ghost sm" type="button" onclick="document.getElementById('spNewOv').classList.remove('on')">Cancelar</button><button class="btn sm" type="submit">Crear ticket</button></div>
    </form>
  </div></div>
  <div class="ctxmenu" id="spCtx"></div>
  <form id="spDelForm" method="post" style="display:none"><input type="hidden" name="action" value="del"><input type="hidden" name="id" id="spDelId"></form>
  <form id="spEstForm" method="post" style="display:none"><input type="hidden" name="action" value="set"><input type="hidden" name="id" id="spEstId"><input type="hidden" name="field" value="estado"><input type="hidden" name="val" id="spEstVal"></form>
  <script>
  function spNew(){document.getElementById('spNewOv').classList.add('on');}
  <?php if(isset($_GET['new']) && can_edit()): ?>document.addEventListener('DOMContentLoaded',function(){spNew();});<?php endif; ?>
  document.getElementById('spNewOv').addEventListener('click',function(e){if(e.target===this)this.classList.remove('on');});
  function spMenu(e,id){e.preventDefault();var m=document.getElementById('spCtx');m.innerHTML='';
    function it(txt,fn,danger){var a=document.createElement('a');a.textContent=txt;if(danger)a.className='danger';a.onclick=function(ev){ev.stopPropagation();m.classList.remove('on');fn();};return a;}
    function setEst(v){fetch('support.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=set&id='+id+'&field=estado&val='+v}).then(function(){location.reload();});}
    m.appendChild(it('Abrir ticket',function(){location.href='support.php?t='+id;}));
    m.appendChild(it('Marcar en curso',function(){setEst('en_curso');}));
    m.appendChild(it('Marcar resuelto',function(){setEst('resuelto');}));
    var s=document.createElement('div');s.className='sep';m.appendChild(s);
    m.appendChild(it('Eliminar',function(){
      erpConfirm('Se borra el ticket con toda su conversación.',{titulo:'¿Eliminar el ticket?',danger:true}).then(function(ok){if(!ok)return;
        document.getElementById('spDelId').value=id;document.getElementById('spDelForm').submit();});},true));
    m.style.left=Math.min(e.clientX,window.innerWidth-200)+'px';m.style.top=Math.min(e.clientY,window.innerHeight-200)+'px';m.classList.add('on');return false;}
  document.addEventListener('click',function(e){if(!e.target.closest('#spCtx')){var m=document.getElementById('spCtx');if(m)m.classList.remove('on');}});
  </script>
  <?php endif; ?>
<?php endif; ?>
<?php erp_foot(); ?>
