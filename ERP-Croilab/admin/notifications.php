<?php
/* Centro de notificaciones del ERP. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';

$me = current_admin(); $meId = (int)$me['id'];
notif_ensure();

/* Sondeo ligero para el aviso en vivo de la campana (P-propuesta 3): devuelve el número
   de no leídas y la más reciente, para que el pie del ERP actualice el globo y muestre un
   toast cuando llega algo nuevo sin recargar. Va antes del sync/render para ser rápido. */
if (isset($_GET['poll'])) {
    header('Content-Type: application/json');
    $unread = notif_unread($meId);
    $latest = null;
    try { $q=db()->prepare("SELECT id,titulo,cuerpo,url,actor FROM notifications WHERE admin_id=? AND leido=0 AND borrado=0 AND tipo<>'chat' AND (snooze_until IS NULL OR snooze_until<=NOW()) ORDER BY id DESC LIMIT 1"); $q->execute([$meId]); $latest=$q->fetch(PDO::FETCH_ASSOC) ?: null; }
    catch(Exception $e){}
    echo json_encode(['unread'=>(int)$unread, 'latest'=>$latest], JSON_UNESCAPED_UNICODE); exit;
}

notif_sync_leads();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $a = $_POST['action'] ?? ''; $rid=(int)($_POST['id']??0);
  if ($a==='read')      { db()->prepare('UPDATE notifications SET leido=1 WHERE id=? AND admin_id=?')->execute([$rid,$meId]); }
  elseif ($a==='unread'){ db()->prepare('UPDATE notifications SET leido=0 WHERE id=? AND admin_id=?')->execute([$rid,$meId]); }
  elseif ($a==='read_all'){ db()->prepare('UPDATE notifications SET leido=1 WHERE admin_id=? AND borrado=0 AND (snooze_until IS NULL OR snooze_until<=NOW())')->execute([$meId]); }
  elseif ($a==='snooze'){ // posponer (Más tarde): a mañana 09:00, o N horas si se indica
      $h=(int)($_POST['horas']??0);
      if($h>0) db()->prepare('UPDATE notifications SET snooze_until=DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id=? AND admin_id=?')->execute([$h,$rid,$meId]);
      else     db()->prepare("UPDATE notifications SET snooze_until=TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY),'09:00:00') WHERE id=? AND admin_id=?")->execute([$rid,$meId]); }
  elseif ($a==='unsnooze'){ db()->prepare('UPDATE notifications SET snooze_until=NULL WHERE id=? AND admin_id=?')->execute([$rid,$meId]); }
  elseif ($a==='del')   { db()->prepare('UPDATE notifications SET borrado=1 WHERE id=? AND admin_id=?')->execute([$rid,$meId]); } // a la papelera (reversible)
  elseif ($a==='restore'){ db()->prepare('UPDATE notifications SET borrado=0 WHERE id=? AND admin_id=?')->execute([$rid,$meId]); }
  elseif ($a==='del_all'){ db()->prepare('UPDATE notifications SET borrado=1 WHERE admin_id=? AND leido=1 AND borrado=0')->execute([$meId]); } // manda las leídas a la papelera
  elseif ($a==='purge') { db()->prepare('DELETE FROM notifications WHERE id=? AND admin_id=? AND borrado=1')->execute([$rid,$meId]); } // definitivo (una)
  elseif ($a==='purge_all'){ db()->prepare('DELETE FROM notifications WHERE admin_id=? AND borrado=1')->execute([$meId]); } // vaciar papelera
  elseif (in_array($a,['read_ids','unread_ids','del_ids','restore_ids','snooze_ids','unsnooze_ids','purge_ids'],true)){ // acciones en bloque
    $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['ids']??[])))));
    if($ids){ $in=implode(',',array_fill(0,count($ids),'?')); $args=array_merge([$meId],$ids);
      if($a==='purge_ids'){ db()->prepare("DELETE FROM notifications WHERE admin_id=? AND id IN ($in)")->execute($args); }
      else{ $set = $a==='read_ids'?'leido=1':($a==='unread_ids'?'leido=0':($a==='restore_ids'?'borrado=0':($a==='unsnooze_ids'?'snooze_until=NULL':($a==='snooze_ids'?"snooze_until=TIMESTAMP(DATE_ADD(CURDATE(),INTERVAL 1 DAY),'09:00:00')":'borrado=1'))));
        db()->prepare("UPDATE notifications SET $set WHERE admin_id=? AND id IN ($in)")->execute($args); } } }
  header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
}

$rows = db()->prepare('SELECT * FROM notifications WHERE admin_id=? ORDER BY created_at DESC, id DESC');
$rows->execute([$meId]); $rows=$rows->fetchAll();
/* Clasificación de cada aviso en una de las cuatro bandejas. */
$AHORA=time();
function nt_es_papelera($r){ return !empty($r['borrado']); }
function nt_es_tarde($r){ global $AHORA; return empty($r['borrado']) && !empty($r['snooze_until']) && strtotime($r['snooze_until'])>$AHORA; }
/* El chat de equipo no es prioritario: va a su propia pestaña, no a Principal. */
function nt_es_chat($r){ return ($r['tipo']??'')==='chat'; }
/* Principal = lo tuyo (tareas donde estás, menciones, lo que se te asigna).
   Otras = actividad del equipo para enterarte (tareas en las que NO estás asignado). */
function nt_es_otras($r){ return (($r['bandeja']??'principal')==='otras'); }
$nUn=0; $nOtras=0; $nLater=0; $nTrash=0; $nChat=0;
foreach($rows as $r){
  if(nt_es_papelera($r)) $nTrash++;
  elseif(nt_es_tarde($r)) $nLater++;
  elseif(nt_es_chat($r)){ if(!$r['leido']) $nChat++; }
  elseif(nt_es_otras($r)){ if(!$r['leido']) $nOtras++; }
  elseif(!$r['leido']) $nUn++;
}

/* El «circulito» de cada aviso debe reflejar el ESTADO REAL de la tarea vinculada
   (en espera / en proceso / atemporal / completada), no solo el color del tipo. Se
   saca el id de tarea de la url (task.php?id=N) y se piden sus estados de una vez. */
$taskEstado = [];
$tids = [];
foreach ($rows as $r) { if (preg_match('/task\.php\?id=(\d+)/', (string)$r['url'], $m)) $tids[(int)$m[1]] = true; }
if ($tids) {
  try { $in = implode(',', array_keys($tids));
    foreach (db()->query("SELECT id, estado FROM tasks WHERE id IN ($in)") as $t) $taskEstado[(int)$t['id']] = $t['estado'];
  } catch (Exception $e) {}
}
function nt_task_id($url){ return preg_match('/task\.php\?id=(\d+)/', (string)$url, $m) ? (int)$m[1] : 0; }

$TIPOS = [
  'lead'=>['#6d28d9','crm'],'tarea'=>['#2563eb','check'],'ticket'=>['#b45309','ticket'],
  'chat'=>['#0f7a3d','chat'],'factura'=>['#0369a1','file'],'info'=>['#656a72','bell'],
];
$MESNOM_N = [1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
function nt_bucket($ts){ global $MESNOM_N; $d=strtotime($ts); if(!$d) return 'Antiguas';
  if($d>=strtotime('today')) return 'Hoy';
  if($d>=strtotime('yesterday')) return 'Ayer';
  if($d>=strtotime('-7 days')) return 'Últimos 7 días';
  /* Más viejas: por meses (Julio, Junio…). Solo se añade el año si no es el actual. */
  $mes = ucfirst($MESNOM_N[(int)date('n',$d)] ?? '');
  return $mes . (date('Y',$d)!==date('Y') ? ' '.date('Y',$d) : ''); }
function nt_time($ts){ if(!$ts)return''; $d=strtotime($ts); if($d>=strtotime('today')) return date('H:i',$d); if($d>=strtotime('yesterday')) return 'Ayer'; return date('d/m/y',$d); }

erp_head('notif', 'Notificaciones');
?>
<style>
.main .erp-wrap, .erp-wrap{max-width:none}
.nt-wrap{width:100%;max-width:none}
.nt-tabs{display:flex;gap:0;border-bottom:1px solid var(--line);margin-bottom:0}
.nt-tab{border:none;background:none;cursor:pointer;font-family:inherit;text-align:left;color:var(--muted);padding:12px 22px 12px 0;margin-right:26px;border-bottom:2px solid transparent}
.nt-tab .tt{font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px}
.nt-tab .ts{font-size:11.5px;color:var(--label);margin-top:1px}
.nt-tab.on{color:var(--ink-strong);border-bottom-color:var(--ink-strong)}
/* El contador de no leídas es el mismo rojo que el globito de la campana de la
   barra lateral (#ef4444). Aquí era magenta: el mismo número, el mismo dato,
   con dos colores distintos según se mirase la barra o esta página. */
.nt-tab .pill{background:#ef4444;color:#fff;font-size:11px;font-weight:700;border-radius:99px;padding:1px 7px;min-width:18px;text-align:center}
.nt-toolbar{display:flex;align-items:center;gap:10px;margin:14px 0 8px}
.nt-toolbar .sp{flex:1}
/* .nt-chip era .chip.act, que ahora está en erp_nav.php. */
.nt-iconbtn{border:1px solid var(--line);background:#fff;border-radius:9px;padding:7px 9px;color:var(--muted);cursor:pointer;display:inline-flex}
.nt-iconbtn:hover{background:var(--soft)}
.nt-day{font-size:12px;font-weight:650;color:var(--muted);padding:20px 4px 10px}
.nt-list{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden}
.nt-row{display:grid;grid-template-columns:22px minmax(150px,300px) 1fr auto;align-items:center;gap:16px;padding:15px 22px;border-bottom:1px solid var(--line2);cursor:pointer;transition:background .12s ease}
.nt-row:last-child{border-bottom:none}
.nt-row:hover{background:#fafbfc}
/* Leídas: en vez de atenuar el texto (bajaba el contraste), un fondo suave las distingue
   manteniendo el texto legible (WCAG AA). El punto azul de "sin leer" ya marca las no leídas. */
.nt-row.read{background:var(--soft)}
.nt-row.read:hover{background:var(--card)}
.nt-st{width:18px;height:18px;border-radius:50%;border:2px solid #cfd2d6;display:flex;align-items:center;justify-content:center;color:#fff;flex:none}
/* Circulito que refleja el estado real de la tarea vinculada. */
.nt-st-task{width:18px;height:18px;display:flex;align-items:center;justify-content:center;flex:none}
.nt-st-task svg{width:16px;height:16px}
.nt-st.tp-tarea{border-color:#3b82f6}
.nt-st.tp-lead{border-color:#7b68ee}
.nt-st.tp-factura{border-color:#0ea5e9}
.nt-task{font-size:14px;font-weight:600;color:var(--ink-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.nt-mid{display:flex;align-items:center;gap:10px;min-width:0}
.nt-av{width:22px;height:22px;border-radius:50%;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex:none}
.nt-line{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:13px;color:var(--muted)}
.nt-line b{color:var(--ink-strong);font-weight:600}
.nt-row.unread .nt-task{font-weight:700}
.nt-right{display:flex;align-items:center;gap:10px;justify-content:flex-end;flex:none}
.nt-time{font-size:12px;color:var(--label);white-space:nowrap;min-width:52px;text-align:right}
.nt-dot{width:8px;height:8px;border-radius:50%;background:#ef4444;flex:none}
.nt-x{border:none;background:none;color:var(--label);cursor:pointer;padding:4px;border-radius:7px;opacity:0;transition:opacity .12s ease}
.nt-row:hover .nt-x{opacity:1}
.nt-x:hover{background:#fde8e8;color:#c0392b}
.nt-empty{padding:60px 20px;text-align:center;color:var(--muted)}
.nt-empty svg{width:44px;height:44px;color:#d4d7dd;margin-bottom:12px}
.nt-hide{display:none!important}
/* Primera columna: por defecto el circulito de estado; en modo selección se cambia
   por la casilla. Nunca los dos a la vez. */
.nt-first{position:relative;width:20px;height:18px;display:flex;align-items:center;justify-content:center}
.nt-sel{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:16px;height:16px;margin:0;cursor:pointer;accent-color:var(--accent);display:none}
#ntWrap.selecting .nt-sel{display:block}
#ntWrap.selecting .nt-first .nt-st,#ntWrap.selecting .nt-first .nt-st-task{visibility:hidden}
.nt-row.sel{background:var(--accent-soft)}
/* Control de selección en bloque, a la izquierda de la barra. Se ve al entrar en
   «modo selección» (botón Seleccionar); entonces se esconde el propio botón. */
.nt-bulkbtns{display:none;align-items:center;gap:8px;flex-wrap:wrap}
#ntWrap.selecting .nt-bulkbtns{display:flex}
#ntWrap.selecting #ntSelBtn{display:none}
.nt-toolbar{min-height:34px}
.nt-selall{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;color:var(--muted);font-weight:600;cursor:pointer}
.nt-selall input{width:16px;height:16px;accent-color:var(--accent);cursor:pointer}
@keyframes dshfadeb{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.nt-selall{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;color:var(--muted);font-weight:600;cursor:pointer}
.nt-selall input{width:16px;height:16px;accent-color:var(--accent);cursor:pointer}
.nt-bn{font-size:13px;font-weight:650;color:var(--ink-strong)}
.nt-bulk .sp{flex:1}
.nt-bulk .chip.act{cursor:pointer;border:1px solid var(--line);background:#fff}
.nt-bx{border:none;background:none;color:var(--label);cursor:pointer;font-size:13px;padding:6px;border-radius:8px}
.nt-bx:hover{background:var(--soft);color:var(--ink)}
/* qué acciones en bloque se ven en cada pestaña */
.nb-read,.nb-unread,.nb-later,.nb-unsnooze,.nb-restore,.nb-del,.nb-purge{display:none}
[data-mode=main] .nb-read,[data-mode=chat] .nb-read{display:inline-flex}
[data-mode=read] .nb-unread{display:inline-flex}
[data-mode=main] .nb-later,[data-mode=read] .nb-later,[data-mode=chat] .nb-later{display:inline-flex}
[data-mode=main] .nb-del,[data-mode=read] .nb-del,[data-mode=chat] .nb-del,[data-mode=later] .nb-del{display:inline-flex}
[data-mode=later] .nb-unsnooze{display:inline-flex}
[data-mode=trash] .nb-restore,[data-mode=trash] .nb-purge{display:inline-flex}
.nt-tab .pill.alt{background:#e0a000}
.nt-tab .pill.otr{background:#7b8794}
.nt-snz{font-size:11.5px;color:#c08a00;display:inline-flex;align-items:center;gap:3px;background:#fff7e6;border-radius:99px;padding:1px 8px 1px 6px;white-space:nowrap}
.nt-act{border:none;background:none;color:var(--label);cursor:pointer;padding:4px;border-radius:7px;opacity:0;transition:opacity .12s ease;display:none;align-items:center}
.nt-row:hover .nt-act{opacity:1}
.nt-act:hover{background:var(--soft);color:var(--ink)}
.nt-act.nt-x:hover,.nt-act.nt-purge:hover{background:#fde8e8;color:#c0392b}
.nt-tab .pill.chatp{background:#12a150}
/* Qué botones se ven en cada bandeja. */
[data-mode=main] .nt-snooze,[data-mode=read] .nt-snooze,[data-mode=chat] .nt-snooze{display:inline-flex}
[data-mode=main] .nt-x,[data-mode=read] .nt-x,[data-mode=chat] .nt-x,[data-mode=later] .nt-x{display:inline-flex}
[data-mode=later] .nt-unsnooze{display:inline-flex}
[data-mode=trash] .nt-restore,[data-mode=trash] .nt-purge{display:inline-flex}
/* Contextual en la barra de herramientas. */
.only-trash{display:none}
[data-mode=trash] .only-trash{display:inline-flex}
[data-mode=trash] .not-trash{display:none}

/* ===== Modo oscuro (aditivo) ===== */
[data-theme=dark] .nt-tab .ts{color:var(--muted)}
[data-theme=dark] .nt-iconbtn{border-color:var(--line);background-color:var(--card)}
[data-theme=dark] .nt-list{background-color:var(--card)}
[data-theme=dark] .nt-row:hover{background-color:var(--soft)}
[data-theme=dark] .nt-st{border-color:var(--line-strong)}
[data-theme=dark] .nt-time{color:var(--muted)}
[data-theme=dark] .nt-x{color:var(--muted)}
[data-theme=dark] .nt-x:hover{background-color:var(--danger-bg);color:var(--danger)}
[data-theme=dark] .nt-act{color:var(--muted)}
[data-theme=dark] .nt-act.nt-x:hover,[data-theme=dark] .nt-act.nt-purge:hover{background-color:var(--danger-bg);color:var(--danger)}
[data-theme=dark] .nt-bulk .chip.act{background-color:var(--card)}

/* ===== Móvil (≤640px): fila compacta de 1-2 líneas y pestañas deslizables ===== */
/* Las pestañas no caben en una fila estrecha: se deslizan en horizontal (sin
   provocar scroll de la página). Cada aviso se queda PLANO y corto: circulito de
   estado a la izquierda, el título en la línea 1 con la hora arriba a la derecha,
   y el protagonista+texto pequeño en la línea 2. Nada de apilar en tres alturas.
   Las acciones se muestran siempre (opacity:1) porque en táctil no hay «hover». */
@media(max-width:640px){
  .nt-tabs{overflow-x:auto;flex-wrap:nowrap;-webkit-overflow-scrolling:touch}
  .nt-tabs::-webkit-scrollbar{display:none}
  .nt-tab{flex:none;margin-right:18px;padding-right:0}
  .nt-toolbar{flex-wrap:wrap}
  .nt-day{padding:14px 4px 7px}
  .nt-row{grid-template-columns:20px 1fr auto;gap:2px 12px;padding:11px 16px;align-items:start}
  .nt-first{grid-column:1;grid-row:1;margin-top:1px}
  .nt-task{grid-column:2;grid-row:1;white-space:normal}
  .nt-mid{grid-column:2;grid-row:2}
  .nt-line{white-space:normal;font-size:12px}
  .nt-right{grid-column:3;grid-row:1;justify-content:flex-end;flex-wrap:wrap}
  .nt-time{min-width:0}
  .nt-act,.nt-x{opacity:1}
}
</style>

<div class="nt-wrap" id="ntWrap" data-mode="main">
  <h1 style="margin-bottom:12px">Bandeja de entrada</h1>
  <div class="nt-tabs">
    <button class="nt-tab on" id="tab_main" onclick="ntTab('main')"><span class="tt">Principal <span class="pill"<?= $nUn?'':' style="display:none"' ?>><?= $nUn ?></span></span><span class="ts" id="tsMain">para ti</span></button>
    <button class="nt-tab" id="tab_read" onclick="ntTab('read')"><span class="tt">Otras <span class="pill otr" id="pillOtras"<?= $nOtras?'':' style="display:none"' ?>><?= $nOtras ?></span></span><span class="ts">del equipo</span></button>
    <button class="nt-tab" id="tab_chat" onclick="ntTab('chat')"><span class="tt">Chat <span class="pill chatp" id="pillChat"<?= $nChat?'':' style="display:none"' ?>><?= $nChat ?></span></span><span class="ts">equipo</span></button>
    <button class="nt-tab" id="tab_later" onclick="ntTab('later')"><span class="tt">Más tarde <span class="pill alt" id="pillLater"<?= $nLater?'':' style="display:none"' ?>><?= $nLater ?></span></span><span class="ts">pospuestas</span></button>
    <button class="nt-tab" id="tab_trash" onclick="ntTab('trash')"><span class="tt">Borradas</span><span class="ts" id="tsTrash"><?= $nTrash ?> en papelera</span></button>
  </div>
  <?php /* La barra tiene DOS lados simétricos: a la IZQUIERDA el control de selección
           en bloque (seleccionar todo + acciones sobre lo elegido, «Marcar leídas»…),
           a la DERECHA las acciones globales de la bandeja. */ ?>
  <div class="nt-toolbar">
    <?php if($rows): ?>
    <span class="chip act" id="ntSelBtn" onclick="ntSelectMode(true)"><?= ic('list',13) ?> Seleccionar</span>
    <span class="nt-bulkbtns" id="ntBulkBtns">
      <label class="nt-selall"><input type="checkbox" id="ntSelAll" onclick="ntSelAll(this)"><span>Todo</span></label>
      <span class="nt-bn" id="ntBulkN"></span>
      <button type="button" class="chip act nb-read" onclick="ntBulk('read')"><?= ic('check',13) ?> Marcar leídas</button>
      <button type="button" class="chip act nb-unread" onclick="ntBulk('unread')"><?= ic('bell',13) ?> No leídas</button>
      <button type="button" class="chip act nb-later" onclick="ntBulk('snooze')"><?= ic('clock',13) ?> Posponer</button>
      <button type="button" class="chip act nb-unsnooze" onclick="ntBulk('unsnooze')"><?= ic('bell',13) ?> Traer ahora</button>
      <button type="button" class="chip act nb-restore" onclick="ntBulk('restore')"><?= ic('back',13) ?> Restaurar</button>
      <button type="button" class="chip act nb-del" onclick="ntBulk('del')"><?= ic('trash',13) ?> Borrar</button>
      <button type="button" class="chip act nb-purge" onclick="ntBulk('purge')"><?= ic('trash',13) ?> Borrar definitivo</button>
      <button type="button" class="nt-bx" onclick="ntSelectMode(false)" title="Salir de la selección"><?= ic('back',13) ?> Cancelar</button>
    </span>
    <span class="sp"></span>
    <span class="nt-iconbtn not-trash" title="Marcar todas leídas" onclick="ntReadAll()"><?= ic('check',15) ?></span>
    <span class="chip act not-trash" onclick="ntDelAll()"><?= ic('trash',13) ?> Enviar leídas a papelera</span>
    <span class="chip act only-trash" onclick="ntPurgeAll()"><?= ic('trash',13) ?> Vaciar papelera</span>
    <?php endif; ?>
  </div>

  <?php if(!$rows): ?>
    <div class="nt-list"><div class="nt-empty"><?= ic('bell',44) ?><div><b>Todo al día</b><br>No tienes notificaciones.</div></div></div>
  <?php else: ?>
    <div id="ntFeed">
      <?php $curBucket=null; foreach($rows as $r):
        $bk=nt_bucket($r['created_at']);
        if($bk!==$curBucket){ if($curBucket!==null) echo '</div></div>'; $curBucket=$bk; echo '<div class="nt-daygrp"><div class="nt-day">'.e($bk).'</div><div class="nt-list">'; }
        $hasTarea=trim((string)$r['tarea'])!==''; $actor=trim((string)($r['actor']??''));
        $taskCol=$hasTarea?$r['tarea']:$r['titulo'];
        $lineCore=$hasTarea?$r['titulo']:$r['cuerpo'];
      ?>
        <?php $isTrash=nt_es_papelera($r)?1:0; $isLater=nt_es_tarde($r)?1:0; $isChat=nt_es_chat($r)?1:0; $snz=$isLater?date('d/m H:i',strtotime($r['snooze_until'])):''; ?>
        <div class="nt-row <?= $r['leido']?'read':'unread' ?>" data-id="<?= (int)$r['id'] ?>" data-url="<?= e($r['url']) ?>" data-unread="<?= $r['leido']?'0':'1' ?>" data-borrado="<?= $isTrash ?>" data-later="<?= $isLater ?>" data-chat="<?= $isChat ?>" data-bandeja="<?= e($r['bandeja']??'principal') ?>" onclick="ntOpen(this)">
          <?php /* El circulito de estado se ve SIEMPRE. La casilla de selección solo
                   aparece al entrar en «modo selección» (botón Seleccionar): así el
                   circulito no se mezcla nunca con la casilla, que era lo confuso. */
                $ntid=nt_task_id($r['url']); $nest=($ntid && isset($taskEstado[$ntid]))?$taskEstado[$ntid]:null; ?>
          <span class="nt-first">
            <input type="checkbox" class="nt-sel" onclick="event.stopPropagation();ntSel(this)" aria-label="Seleccionar">
            <?php if($nest!==null): ?><span class="nt-st-task" title="<?= e($nest) ?>"><?= estado_circle($nest) ?></span>
            <?php else: ?><span class="nt-st tp-<?= e($r['tipo']) ?>"></span><?php endif; ?>
          </span>
          <div class="nt-task"><?= e($taskCol) ?></div>
          <div class="nt-mid">
            <?php if($actor!==''): ?><span class="nt-av" style="background:<?= avatar_color($actor) ?>"><?= e(mb_strtoupper(mb_substr($actor,0,1))) ?></span><?php endif; ?>
            <span class="nt-line"><?php if($actor!==''): ?><b><?= e($actor) ?></b> <?php endif; ?><?= e($lineCore) ?><?php if($hasTarea && trim((string)$r['cuerpo'])!==''): ?> · <?= e($r['cuerpo']) ?><?php endif; ?></span>
          </div>
          <div class="nt-right">
            <?php if(!$r['leido'] && !$isTrash && !$isLater): ?><span class="nt-dot"></span><?php endif; ?>
            <?php if($isLater): ?><span class="nt-snz" title="Vuelve el <?= e($snz) ?>"><?= ic('clock',13) ?> <?= e($snz) ?></span><?php endif; ?>
            <span class="nt-time"><?= e(nt_time($r['created_at'])) ?></span>
            <button class="nt-act nt-snooze" title="Posponer a mañana" onclick="event.stopPropagation();ntSnooze(<?= (int)$r['id'] ?>,this)"><?= ic('clock',15) ?></button>
            <button class="nt-act nt-unsnooze" title="Traer ahora" onclick="event.stopPropagation();ntUnsnooze(<?= (int)$r['id'] ?>,this)"><?= ic('bell',15) ?></button>
            <button class="nt-act nt-restore" title="Restaurar" onclick="event.stopPropagation();ntRestore(<?= (int)$r['id'] ?>,this)"><?= ic('back',15) ?></button>
            <button class="nt-act nt-x" title="Borrar" onclick="event.stopPropagation();ntDel(<?= (int)$r['id'] ?>,this)">✕</button>
            <button class="nt-act nt-purge" title="Borrar definitivamente" onclick="event.stopPropagation();ntPurge(<?= (int)$r['id'] ?>,this)"><?= ic('trash',14) ?></button>
          </div>
        </div>
      <?php endforeach; if($curBucket!==null) echo '</div></div>'; ?>
    </div>
    <div id="ntEmptyTab" class="nt-empty" style="display:none"></div>
  <?php endif; ?>
</div>

<script>
function ntPost(body){return fetch('notifications.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body});}
function ntMode(){var w=document.getElementById('ntWrap');return w?w.dataset.mode:'main';}
function ntOpen(el){ if(el.dataset.borrado==='1')return; var id=el.dataset.id,url=el.dataset.url;ntPost('action=read&id='+id).then(function(){if(url){location.href=url;}else{el.classList.remove('unread');el.classList.add('read');el.dataset.unread='0';var d=el.querySelector('.nt-dot');if(d)d.remove();ntUpdCounts();}});}
/* Anima la salida de una fila (cambia de bandeja) y re-filtra sin recargar. */
function ntFade(row,then){ if(!row){return;} row.classList.add('erp-out'); setTimeout(function(){ row.classList.remove('erp-out'); if(then)then(); ntUpdCounts(); ntRefilter(); },180); }
function ntDel(id,btn){ntPost('action=del&id='+id).then(function(){var r=btn.closest('.nt-row');if(r){r.dataset.borrado='1';ntFade(r);} if(window.toast)toast('Movida a la papelera');});}
function ntRestore(id,btn){ntPost('action=restore&id='+id).then(function(){var r=btn.closest('.nt-row');if(r){r.dataset.borrado='0';ntFade(r);} if(window.toast)toast('Restaurada');});}
function ntSnooze(id,btn){ntPost('action=snooze&id='+id).then(function(){var r=btn.closest('.nt-row');if(r){r.dataset.later='1';r.dataset.unread='0';ntFade(r);} if(window.toast)toast('Pospuesta a mañana 9:00');});}
function ntUnsnooze(id,btn){ntPost('action=unsnooze&id='+id).then(function(){var r=btn.closest('.nt-row');if(r){r.dataset.later='0';ntFade(r);} if(window.toast)toast('Traída a Principal');});}
function ntPurge(id,btn){ntPost('action=purge&id='+id).then(function(){var r=btn.closest('.nt-row');if(r)ntFade(r,function(){r.remove();});});}
function ntPurgeAll(){erpConfirm('Se borrarán definitivamente las notificaciones de la papelera. Esto no se puede deshacer.',{titulo:'¿Vaciar la papelera?',danger:true}).then(function(ok){if(!ok)return;ntPost('action=purge_all').then(function(){document.querySelectorAll('#ntFeed .nt-row').forEach(function(r){if(r.dataset.borrado==='1')r.remove();});ntUpdCounts();ntRefilter();});});}
function ntReadAll(){ntPost('action=read_all').then(function(){document.querySelectorAll('#ntFeed .nt-row').forEach(function(r){if(r.dataset.borrado!=='1'&&r.dataset.later!=='1'){r.classList.remove('unread');r.classList.add('read');r.dataset.unread='0';var d=r.querySelector('.nt-dot');if(d)d.remove();}});ntUpdCounts();ntRefilter();if(window.toast)toast('Todas marcadas como leídas');});}
function ntDelAll(){erpConfirm('Las que aún no has leído se quedan. Podrás recuperarlas desde «Borradas».',{titulo:'¿Enviar las leídas a la papelera?',danger:true}).then(function(ok){if(!ok)return;ntPost('action=del_all').then(function(){document.querySelectorAll('#ntFeed .nt-row').forEach(function(r){if(r.dataset.unread==='0'&&r.dataset.borrado!=='1'&&r.dataset.later!=='1')r.dataset.borrado='1';});ntUpdCounts();ntRefilter();if(window.toast)toast('Leídas enviadas a la papelera');});});}
/* ---- Selección en bloque ---- */
function ntSelectedRows(){ return [].slice.call(document.querySelectorAll('#ntFeed .nt-row.sel')); }
function ntSel(cb){ var r=cb.closest('.nt-row'); r.classList.toggle('sel',cb.checked); ntBulkUpd(); }
function ntSelAll(cb){ [].slice.call(document.querySelectorAll('#ntFeed .nt-row:not(.nt-hide)')).forEach(function(r){ r.classList.toggle('sel',cb.checked); var c=r.querySelector('.nt-sel'); if(c)c.checked=cb.checked; }); ntBulkUpd(); }
function ntBulkClear(){ ntSelectedRows().forEach(function(r){ r.classList.remove('sel'); var c=r.querySelector('.nt-sel'); if(c)c.checked=false; }); var sa=document.getElementById('ntSelAll'); if(sa)sa.checked=false; ntBulkUpd(); }
/* Entrar/salir del modo selección: aparecen las casillas y las acciones en bloque.
   Al salir se limpia lo seleccionado. */
function ntSelectMode(on){
  var w=document.getElementById('ntWrap'); if(!w)return;
  w.classList.toggle('selecting',on);
  if(!on) ntBulkClear();
  ntBulkUpd();
}
function ntBulkUpd(){ var s=ntSelectedRows();
  var n=document.getElementById('ntBulkN'); if(n)n.textContent=s.length+(s.length===1?' seleccionada':' seleccionadas');
  var vis=[].slice.call(document.querySelectorAll('#ntFeed .nt-row:not(.nt-hide)')); var sa=document.getElementById('ntSelAll'); if(sa)sa.checked=vis.length>0&&vis.every(function(r){return r.classList.contains('sel');}); }
var _ntActions={read:'read_ids',unread:'unread_ids',snooze:'snooze_ids',unsnooze:'unsnooze_ids',del:'del_ids',restore:'restore_ids',purge:'purge_ids'};
var _ntMsg={read:'marcadas como leídas',unread:'marcadas como no leídas',snooze:'pospuestas a mañana',unsnooze:'traídas a Principal',del:'movidas a la papelera',restore:'restauradas',purge:'borradas definitivamente'};
function ntBulkDo(kind){ var rows=ntSelectedRows(); if(!rows.length)return; var ids=rows.map(function(r){return r.dataset.id;});
  var body='action='+_ntActions[kind]; ids.forEach(function(id){ body+='&ids[]='+id; });
  ntPost(body).then(function(){
    rows.forEach(function(r){ var c=r.querySelector('.nt-sel'); if(c)c.checked=false; r.classList.remove('sel');
      if(kind==='read'){ r.classList.remove('unread'); r.classList.add('read'); r.dataset.unread='0'; var d=r.querySelector('.nt-dot'); if(d)d.remove(); }
      else if(kind==='unread'){ r.classList.add('unread'); r.classList.remove('read'); r.dataset.unread='1'; }
      else if(kind==='snooze'){ r.dataset.later='1'; r.dataset.unread='0'; }
      else if(kind==='unsnooze'){ r.dataset.later='0'; }
      else if(kind==='del'){ r.dataset.borrado='1'; }
      else if(kind==='restore'){ r.dataset.borrado='0'; }
      else if(kind==='purge'){ r.remove(); }
    });
    ntUpdCounts(); ntRefilter(); ntBulkUpd();
    if(window.toast)toast(rows.length+' '+_ntMsg[kind]);
  }); }
function ntBulk(kind){ if(!ntSelectedRows().length)return;
  if(kind==='purge'){ erpConfirm('Se borrarán definitivamente las seleccionadas. No se puede deshacer.',{titulo:'¿Borrar definitivamente?',danger:true}).then(function(ok){ if(ok)ntBulkDo('purge'); }); return; }
  ntBulkDo(kind); }
/* Recuenta las cuatro bandejas y el globo de la campana a partir del DOM. */
function ntUpdCounts(){
  var rows=[].slice.call(document.querySelectorAll('#ntFeed .nt-row'));
  function activo(r){return r.dataset.borrado!=='1'&&r.dataset.later!=='1'&&r.dataset.chat!=='1';}
  var un=rows.filter(function(r){return r.dataset.unread==='1'&&activo(r)&&r.dataset.bandeja!=='otras';}).length;
  var otras=rows.filter(function(r){return r.dataset.unread==='1'&&activo(r)&&r.dataset.bandeja==='otras';}).length;
  var chat=rows.filter(function(r){return r.dataset.chat==='1'&&r.dataset.unread==='1'&&r.dataset.borrado!=='1'&&r.dataset.later!=='1';}).length;
  var later=rows.filter(function(r){return r.dataset.later==='1'&&r.dataset.borrado!=='1';}).length;
  var trash=rows.filter(function(r){return r.dataset.borrado==='1';}).length;
  var pill=document.querySelector('#tab_main .pill'); if(pill){pill.textContent=un;pill.style.display=un?'':'none';}
  var po=document.getElementById('pillOtras'); if(po){po.textContent=otras;po.style.display=otras?'':'none';}
  var pc=document.getElementById('pillChat'); if(pc){pc.textContent=chat;pc.style.display=chat?'':'none';}
  var pl=document.getElementById('pillLater'); if(pl){pl.textContent=later;pl.style.display=later?'':'none';}
  var tt=document.getElementById('tsTrash'); if(tt)tt.textContent=trash+' en papelera';
  /* El globo de la campana refleja TODO lo sin leer (Principal + Otras). */
  var bb=document.querySelector('.rbell .rbadge'); if(bb){var tot=un+otras; if(tot>0)bb.textContent=tot>9?'9+':tot;else bb.remove();}
}
function ntVisible(r,t){ var trash=r.dataset.borrado==='1',later=r.dataset.later==='1',chat=r.dataset.chat==='1',otras=r.dataset.bandeja==='otras';
  if(t==='trash')return trash;
  if(t==='later')return !trash&&later;
  if(t==='chat')return !trash&&!later&&chat;
  if(t==='read')return !trash&&!later&&!chat&&otras;   /* «Otras» = actividad del equipo */
  return !trash&&!later&&!chat&&!otras; /* Principal: para ti */ }
function ntRefilter(){ var t=ntMode();
  document.querySelectorAll('#ntFeed .nt-row').forEach(function(r){ var hide=!ntVisible(r,t); r.classList.toggle('nt-hide',hide); if(hide&&r.classList.contains('sel')){ r.classList.remove('sel'); var c=r.querySelector('.nt-sel'); if(c)c.checked=false; } });
  document.querySelectorAll('#ntFeed .nt-daygrp').forEach(function(g){ var vis=g.querySelectorAll('.nt-row:not(.nt-hide)').length; g.classList.toggle('nt-hide',vis===0); });
  var any=document.querySelectorAll('#ntFeed .nt-row:not(.nt-hide)').length;
  var em=document.getElementById('ntEmptyTab'); if(em){ em.style.display=any?'none':'block'; em.textContent=({main:'Nada para ti por ahora.',read:'Sin actividad del equipo por ahora.',chat:'No hay mensajes del chat de equipo.',later:'No has pospuesto ninguna notificación.',trash:'La papelera está vacía.'})[t]||'Nada por aquí.'; }
  if(typeof ntBulkUpd==='function') ntBulkUpd();
}
function ntTab(t){ var w=document.getElementById('ntWrap'); if(w)w.dataset.mode=t;
  ['main','read','chat','later','trash'].forEach(function(x){var b=document.getElementById('tab_'+x);if(b)b.classList.toggle('on',x===t);});
  ntRefilter();
}
document.addEventListener('DOMContentLoaded',function(){ntUpdCounts();ntRefilter();});
</script>
<?php erp_foot(); ?>
