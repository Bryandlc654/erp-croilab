<?php
/* Reuniones: apartado central para ver TODAS las reuniones (vengan del CRM o creadas
   directamente en Google Calendar) y sus NOTAS de Gemini. Ahora también permite CREAR
   una reunión (se crea en Google Calendar y aparece en el Calendario del ERP). */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/gcal.php';
require_once __DIR__ . '/lib/crm_lib.php';
require_once __DIR__ . '/lib/logos.php';

$me   = current_admin();
$meId = (int)$me['id'];
$gcConnected = gcal_configured() && gcal_connected($meId);
$gcRevoked   = gcal_revoked($meId);
$ctxOps = (($_GET['ctx']??'')==='ops');
$ctxQ = $ctxOps ? '?ctx=ops' : '';   // conservar el contexto del menú en la navegación interna

/* Asignación MANUAL de una reunión de Google a un contacto (para las que no cuadran por correo). */
function reu_asig_ensure(){ static $ok=false; if($ok) return; try{ db()->exec("CREATE TABLE IF NOT EXISTS reunion_cliente (event_id VARCHAR(255) PRIMARY KEY, contact_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){} $ok=true; }
reu_asig_ensure();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  require_can_edit();
  $a = $_POST['action'] ?? '';
  /* Crear / editar / borrar una reunión de Google Calendar (la fuente de verdad).
     El backend de las tres cosas ya vive en lib/gcal.php; aquí solo se recogen los
     campos. `notificar` = la casilla «avisar a los invitados» (sendUpdates). */
  if ($a==='create' || $a==='update') {
    $o = [
      'titulo'      => trim($_POST['titulo'] ?? ''),
      'fecha'       => trim($_POST['fecha'] ?? '') ?: date('Y-m-d'),
      'hora'        => trim($_POST['hora'] ?? ''),
      'hora_fin'    => trim($_POST['hora_fin'] ?? ''),
      'invitados'   => trim($_POST['invitados'] ?? ''),
      'meet'        => !empty($_POST['meet']),
      'gemini'      => !empty($_POST['gemini']),
      'descripcion' => trim($_POST['descripcion'] ?? ''),
      'notificar'   => !empty($_POST['notificar']),
      'recordar'    => trim($_POST['recordar'] ?? ''),
    ];
    if ($o['hora']!=='' && $o['hora_fin']==='' && !empty($_POST['dur'])) {
      $dur=(int)$_POST['dur']; $o['hora_fin']=date('H:i', strtotime($o['fecha'].'T'.$o['hora'].':00 +'.$dur.' minutes'));
    }
    if ($a==='update') { [$ok,$msg] = gcal_update_event($meId, trim($_POST['event_id'] ?? ''), $o); }
    else {
      /* Si viene de APROBAR una solicitud del portal (req_id): doble escritura —
         la reunión real en crm_meetings (para que la vea el cliente en su portal) +
         el evento en Google Calendar (con invitación al cliente), enlazados por
         erp_meeting. Así aparece en los dos sitios. */
      $reqId=(int)($_POST['req_id']??0); $mid=null;
      if ($reqId) {
        try {
          $rq=db()->prepare('SELECT client_id FROM portal_meeting_requests WHERE id=?'); $rq->execute([$reqId]); $rqCli=(int)$rq->fetchColumn();
          if ($rqCli) {
            $cc=db()->prepare('SELECT contact_id, fact_email FROM clients WHERE id=?'); $cc->execute([$rqCli]); $crow=$cc->fetch() ?: [];
            $contactId=(int)($crow['contact_id']??0);
            if ($contactId>0) {
              if (function_exists('crm_meetings_ensure')) crm_meetings_ensure();
              db()->prepare("INSERT INTO crm_meetings (contact_id,fecha,hora,titulo,estado) VALUES (?,?,?,?,'agendada')")
                  ->execute([$contactId, $o['fecha'], $o['hora'], $o['titulo']?:'Reunión con el cliente']);
              $mid=(int)db()->lastInsertId(); $o['erp_meeting']=(string)$mid;
            }
            if (trim($o['invitados'])==='' && !empty($crow['fact_email'])) { $o['invitados']=(string)$crow['fact_email']; $o['notificar']=true; }
          }
        } catch (Exception $e) {}
      }
      [$ok,$msg] = gcal_create_event($meId, $o);
      if ($reqId) {
        try { db()->prepare("UPDATE portal_meeting_requests SET estado='aprobada', meeting_id=? WHERE id=?")->execute([$mid,$reqId]); } catch (Exception $e) {}
        if ($mid) $msg = $ok ? 'Reunión agendada y avisado el cliente.' : 'Reunión agendada (el aviso de Google falló, pero el cliente ya la ve en su portal).';
        else      $msg = 'Solicitud aprobada. Ese cliente no tiene contacto en el CRM: agenda la reunión a mano en su ficha.';
        $ok = true;
      }
    }
    header('Location: reuniones.php'.($ctxOps?'?ctx=ops&':'?').'flash='.rawurlencode($msg).'&fok='.($ok?'1':'0')); exit;
  }
  if ($a==='delete') {
    [$ok,$msg] = gcal_delete_event($meId, trim($_POST['event_id'] ?? ''));
    header('Location: reuniones.php'.($ctxOps?'?ctx=ops&':'?').'flash='.rawurlencode($msg).'&fok='.($ok?'1':'0')); exit;
  }
  /* Solicitudes de reunión que llegan del portal del cliente: aprobar o rechazar.
     Aprobar = crear la reunión real en crm_meetings (si el cliente tiene contacto
     CRM) y marcar la solicitud. Tabla propia portal_meeting_requests. */
  if ($a==='meetreq_approve' || $a==='meetreq_reject') {
    $rid=(int)($_POST['req_id']??0);
    try { db()->exec("CREATE TABLE IF NOT EXISTS portal_meeting_requests (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, fecha_deseada DATE NULL, franja VARCHAR(30) DEFAULT '', motivo TEXT, estado VARCHAR(20) DEFAULT 'pendiente', meeting_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}
    if ($rid) {
      if ($a==='meetreq_reject') {
        db()->prepare("UPDATE portal_meeting_requests SET estado='rechazada' WHERE id=?")->execute([$rid]);
        $msg='Solicitud rechazada.';
      } else {
        $rq=db()->prepare('SELECT * FROM portal_meeting_requests WHERE id=?'); $rq->execute([$rid]); $rq=$rq->fetch();
        $mid=null;
        if ($rq) {
          $cc=db()->prepare('SELECT contact_id FROM clients WHERE id=?'); $cc->execute([(int)$rq['client_id']]); $contactId=(int)$cc->fetchColumn();
          if ($contactId>0) {
            crm_meetings_ensure();
            $fecha=$rq['fecha_deseada'] ?: date('Y-m-d');
            db()->prepare("INSERT INTO crm_meetings (contact_id,fecha,hora,titulo,estado) VALUES (?,?,?,?,'agendada')")
                ->execute([$contactId,$fecha,'','Reunión solicitada por el cliente']);
            $mid=(int)db()->lastInsertId();
          }
        }
        db()->prepare("UPDATE portal_meeting_requests SET estado='aprobada', meeting_id=? WHERE id=?")->execute([$mid,$rid]);
        $msg = $mid ? 'Reunión aprobada y agendada.' : 'Solicitud aprobada (ese cliente no tiene contacto CRM: agéndala a mano en su ficha).';
      }
      header('Location: reuniones.php'.($ctxOps?'?ctx=ops&':'?').'flash='.rawurlencode($msg).'&fok=1'); exit;
    }
  }
  /* Asignar/quitar cliente a una reunión de Google. */
  $evId=trim($_POST['event_id']??''); $cc=(int)($_POST['contact_id']??0);
  if ($evId!==''){
    if ($cc>0) db()->prepare('INSERT INTO reunion_cliente (event_id,contact_id) VALUES (?,?) ON DUPLICATE KEY UPDATE contact_id=VALUES(contact_id)')->execute([$evId,$cc]);
    else       db()->prepare('DELETE FROM reunion_cliente WHERE event_id=?')->execute([$evId]);
  }
  header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
}

/* Rango: 90 días atrás y 60 adelante. */
$desde = date('Y-m-d', strtotime('-90 days'));
$hasta = date('Y-m-d', strtotime('+60 days'));

/* #8 — Filtro por usuario. Admins del equipo con Google conectado. */
$connAdmins = [];   // id => nombre
if (gcal_configured()) {
  try { foreach (db()->query("SELECT id,username FROM admins ORDER BY username") as $ad){
    $aid=(int)$ad['id'];
    if (gcal_connected($aid) && !gcal_revoked($aid)) $connAdmins[$aid]=$ad['username'];
  } } catch(Exception $e){}
}
$multi = count($connAdmins) > 1;   // solo tiene sentido el selector si hay varias cuentas

/* Vista: 'me' (por defecto), 'all' (equipo) o el id de un empleado concreto. */
$vu = (string)($_GET['u'] ?? 'me');
if ($vu!=='all' && $vu!=='me' && !isset($connAdmins[(int)$vu])) $vu='me';
if (!$multi) $vu='me';

/* Cuentas de las que traer reuniones según la vista. */
if      ($vu==='all')                       $srcIds = array_keys($connAdmins);
elseif  ($vu!=='me' && isset($connAdmins[(int)$vu])) $srcIds = [(int)$vu];
else                                        $srcIds = ($gcConnected && !$gcRevoked) ? [$meId] : [];

/* Fusiona las reuniones de todas las cuentas elegidas, deduplicando el mismo evento. */
$eventos = []; $seen = [];
foreach ($srcIds as $sid) {
  foreach (gcal_meetings_range($sid, $desde, $hasta) as $ev) {
    $k = ($ev['id']!=='') ? $ev['id'] : ($ev['titulo'].'|'.$ev['ini']);
    if (isset($seen[$k])) continue; $seen[$k]=1;
    $ev['_owner'] = $connAdmins[$sid] ?? (($sid===$meId)?($me['username']??''):'');
    $eventos[] = $ev;
  }
}
$REU_SHOW_OWNER = ($vu==='all' && $multi);   // en la vista de equipo, mostrar de quién es cada reunión

/* Solicitudes de reunión pendientes hechas por clientes desde el portal. */
$reqPend = [];
try {
  db()->exec("CREATE TABLE IF NOT EXISTS portal_meeting_requests (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, fecha_deseada DATE NULL, franja VARCHAR(30) DEFAULT '', motivo TEXT, estado VARCHAR(20) DEFAULT 'pendiente', meeting_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $reqPend = db()->query("SELECT r.*, c.name AS cliente, c.fact_email FROM portal_meeting_requests r JOIN clients c ON c.id=r.client_id WHERE r.estado='pendiente' ORDER BY r.id DESC")->fetchAll();
} catch (Exception $e) {}

/* Índices correo→cliente/contacto para asignar cada reunión sin trabajo manual. */
$byEmail = [];
try { foreach (db()->query("SELECT id,nombre,empresa,email FROM contacts WHERE email<>''") as $c) $byEmail[strtolower(trim($c['email']))] = $c; } catch (Exception $e) {}
$cliByEmail = [];
try { foreach (db()->query("SELECT id,name,fact_email FROM clients WHERE fact_email<>''") as $c) $cliByEmail[strtolower(trim($c['fact_email']))] = $c; } catch (Exception $e) {}

/* Contactos del CRM agrupados por TIPO de fase, para el popup de asignar (#4). */
$stageType = []; $stageName = [];
try { foreach (db()->query("SELECT slug,nombre,tipo FROM pipeline_stages") as $s){ $stageType[$s['slug']]=$s['tipo']; $stageName[$s['slug']]=$s['nombre']; } } catch(Exception $e){}
$TIPO_LABEL = ['ganada'=>'Clientes activos','abierta'=>'Potenciales','pausa'=>'En pausa','perdida'=>'Cerrados perdidos'];
$TIPO_ORDER = ['ganada','abierta','pausa','perdida',''];
$grupos = []; // tipo => [ {id,nombre,empresa,fase} ]
try {
  foreach (db()->query("SELECT id,nombre,empresa,fase FROM contacts ORDER BY nombre") as $c){
    $tipo = $stageType[$c['fase']] ?? '';
    $grupos[$tipo][] = ['id'=>(int)$c['id'],'nombre'=>$c['nombre'],'empresa'=>$c['empresa']?:'','fase'=>$stageName[$c['fase']]??''];
  }
} catch(Exception $e){}
$grupos_js = [];
foreach ($TIPO_ORDER as $t){ if(!empty($grupos[$t])) $grupos_js[] = ['label'=>($TIPO_LABEL[$t]??'Otros'), 'items'=>$grupos[$t]]; }

$asigMap = [];
try { foreach (db()->query("SELECT rc.event_id, c.id, c.nombre, c.empresa FROM reunion_cliente rc JOIN contacts c ON c.id=rc.contact_id") as $r) $asigMap[$r['event_id']] = $r; } catch (Exception $e) {}

function reu_match($emails, $byEmail, $cliByEmail){
  foreach ($emails as $em) if (isset($byEmail[$em]))   { $c=$byEmail[$em];   return ['nombre'=>$c['nombre'], 'sub'=>$c['empresa']?:'Contacto', 'href'=>'crm.php?open='.(int)$c['id']]; }
  foreach ($emails as $em) if (isset($cliByEmail[$em])){ $c=$cliByEmail[$em]; return ['nombre'=>$c['name'],   'sub'=>'Cliente',                 'href'=>'client.php?id='.(int)$c['id']]; }
  return null;
}

$hoy = date('Y-m-d');
$proximas = []; $pasadas = []; $conNotas = [];
foreach ($eventos as $ev) {
  if (isset($asigMap[$ev['id']])) { $a=$asigMap[$ev['id']]; $ev['cli']=['nombre'=>$a['nombre'],'sub'=>$a['empresa']?:'Contacto','href'=>'crm.php?open='.(int)$a['id']]; }
  else { $ev['cli'] = reu_match($ev['emails'], $byEmail, $cliByEmail); }
  if (!empty($ev['docs'])) $conNotas[] = $ev;
  if ($ev['dia'] >= $hoy)  $proximas[] = $ev; else $pasadas[] = $ev;
}
usort($proximas, function($a,$b){ return strcmp($a['ini'],$b['ini']); });   // próximas: la más cercana primero
usort($pasadas,  function($a,$b){ return strcmp($b['ini'],$a['ini']); });   // pasadas: la más reciente primero
usort($conNotas, function($a,$b){ return strcmp($b['ini'],$a['ini']); });

$MES = ['','ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
function reu_fecha($iso){ global $MES; $t=strtotime($iso); return (int)date('j',$t).' '.$MES[(int)date('n',$t)].' '.date('Y',$t); }

/* Renderiza una tarjeta de reunión (reutilizada en las tres pestañas). */
function reu_card($ev){
  global $MES, $REU_SHOW_OWNER; $t=strtotime($ev['ini']);
  /* Datos que necesita el menú contextual para editar/borrar sin recargar. */
  $evData = [
    'id'=>$ev['id'], 'titulo'=>$ev['titulo'], 'fecha'=>$ev['dia'],
    'hora'=>$ev['hora'], 'hora_fin'=>$ev['hora_fin'] ?? '',
    'invitados'=>implode(', ', $ev['emails'] ?? []),
    'meet'=>!empty($ev['meet']), 'recordar'=>$ev['recordar'] ?? '', 'link'=>$ev['link'] ?? '',
  ];
  $evJson = htmlspecialchars(json_encode($evData, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>
  <div class="reu-card" data-mk="<?= date('Y-n',$t) ?>"<?= can_edit() ? ' oncontextmenu="reuMenu(event,this)" data-ev="'.$evJson.'"' : '' ?>>
    <div class="reu-day"><div class="d"><?= (int)date('j',$t) ?></div><div class="m"><?= $MES[(int)date('n',$t)] ?></div></div>
    <div class="reu-body">
      <div class="reu-title"><?= e($ev['titulo']) ?></div>
      <div class="reu-meta">
        <?php if($ev['cli']): ?><a class="reu-cli" href="<?= e($ev['cli']['href']) ?>"><?= ic('user',13) ?> <?= e($ev['cli']['nombre']) ?></a>
        <?php else: ?><span class="reu-cli none"><?= ic('user',13) ?> Sin cliente</span><?php endif; ?>
        <?php if(can_edit()): ?><button type="button" class="reu-asbtn" onclick="reuAsignar(event,'<?= e($ev['id']) ?>')"><?= $ev['cli']?'cambiar':'asignar' ?></button><?php endif; ?>
        <?php if($ev['meet']): ?><span class="reu-badge"><?= svc_logo('meet',14) ?> Meet</span><?php endif; ?>
        <?php if(!empty($REU_SHOW_OWNER) && !empty($ev['_owner'])): ?><span class="reu-owner"><?= ic('user',12) ?> <?= e($ev['_owner']) ?></span><?php endif; ?>
        <span class="reu-when"><?= reu_fecha($ev['ini']) ?><?= $ev['hora']?' · '.e($ev['hora']):'' ?></span>
      </div>
      <?php $docs=array_filter($ev['docs'],fn($d)=>!empty($d['url'])); if($docs || $ev['link']): ?>
      <div class="reu-docs">
        <?php foreach($docs as $doc): ?><a class="reu-doc" href="<?= e($doc['url']) ?>" target="_blank" rel="noopener"><?= ic('file',14) ?> <?= e($doc['title']?:'Notas de la reunión') ?></a><?php endforeach; ?>
        <?php if($ev['link']): ?><a class="reu-doc ghost" href="<?= e($ev['link']) ?>" target="_blank" rel="noopener"><?= ic('link',14) ?> Ver en Google</a><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php }

/* Renderiza una lista de reuniones con un separador cuando cambia el mes. */
function reu_list($items){
  static $MFULL=['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
  $cur='';
  foreach($items as $ev){ $t=strtotime($ev['ini']); $mk=date('Y-n',$t);
    if($mk!==$cur){ $cur=$mk; echo '<div class="reu-month" data-mk="'.$mk.'">'.$MFULL[(int)date('n',$t)].' '.date('Y',$t).'</div>'; }
    reu_card($ev);
  }
}

/* Correos ya usados, para autocompletar «Invitados»: los de reuniones
   anteriores + los de contactos del CRM + los de facturación de clientes.
   Se ofrecen mientras se escribe, como en Google Calendar. */
$reuEmails = [];
foreach ($eventos as $ev) foreach (($ev['emails'] ?? []) as $em) { $em=strtolower(trim($em)); if($em!=='') $reuEmails[$em]=1; }
foreach (array_keys($byEmail) as $em)    if($em!=='') $reuEmails[$em]=1;
foreach (array_keys($cliByEmail) as $em) if($em!=='') $reuEmails[$em]=1;
/* Y todos los correos ya conocidos: cuentas del equipo (admins), contactos y clientes. */
foreach (['admins','contacts','clients'] as $tbl) { try { foreach (db()->query("SELECT DISTINCT email FROM $tbl WHERE email IS NOT NULL AND email<>''") as $r) { $e=strtolower(trim((string)$r['email'])); if($e!=='' && strpos($e,'@')!==false) $reuEmails[$e]=1; } } catch(Exception $ex){} }
$reuEmails = array_keys($reuEmails); sort($reuEmails);

erp_head('reuniones','Reuniones');
?>
<style>
.reu-wrap{max-width:1000px;margin:0 auto;padding:4px 0 60px}
.reu-hero{display:flex;align-items:center;gap:16px;margin:6px 0 22px}
.reu-logo{width:52px;height:52px;border-radius:15px;background:#fff;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;flex:none;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.reu-hero h1{font-size:24px;font-weight:600;color:var(--ink-strong);margin:0;letter-spacing:-.3px}
.reu-hero p{margin:3px 0 0;font-size:13.5px;color:var(--muted)}
.reu-hero .sp{flex:1}
.reu-new{border:none;background:var(--accent);color:#fff;border-radius:11px;padding:10px 17px;font-size:13.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:filter .12s}
.reu-new:hover{filter:brightness(1.14)}
.reu-tabrow{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:20px}
.reu-tabs{display:inline-flex;background:#f1f2f4;border-radius:12px;padding:4px;gap:4px}
.reu-monthsel{margin-left:auto;border:1px solid var(--line);background:#fff;border-radius:11px;padding:9px 34px 9px 13px;font-size:13px;font-weight:600;color:var(--ink);font-family:inherit;cursor:pointer;outline:none;-webkit-appearance:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239aa0a8' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center}
.reu-monthsel:hover{border-color:#d7d8db}
.reu-tabs button{border:none;background:none;border-radius:9px;padding:8px 18px;font-size:13px;font-weight:600;color:#6b7079;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:7px}
.reu-tabs button.on{background:#fff;color:var(--ink-strong);box-shadow:0 1px 3px rgba(16,19,24,.12)}
.reu-tabs .cnt{font-size:11px;background:var(--soft);color:var(--label);border-radius:99px;padding:0 7px;font-weight:700}
.reu-tabs button.on .cnt{background:#eef0f2}
.reu-pane{display:none}.reu-pane.on{display:block;animation:reuIn .25s ease}
.reu-month{display:flex;align-items:center;gap:12px;font-size:12px;font-weight:650;letter-spacing:.4px;text-transform:uppercase;color:var(--label);margin:26px 2px 14px}
.reu-month::after{content:"";flex:1;height:1px;background:var(--line)}
.reu-pane > .reu-month:first-child{margin-top:2px}
@keyframes reuIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.reu-card{display:flex;align-items:flex-start;gap:16px;padding:18px 20px;background:#fff;border:1px solid var(--line);border-radius:16px;margin-bottom:13px;box-shadow:0 1px 2px rgba(16,19,24,.03),0 10px 26px -20px rgba(16,19,24,.14);transition:box-shadow .15s}
.reu-card:hover{box-shadow:0 2px 4px rgba(16,19,24,.05),0 16px 34px -20px rgba(16,19,24,.22)}
.reu-day{flex:none;width:50px;text-align:center;border-right:1px solid var(--line2);padding-right:12px}
.reu-day .d{font-size:21px;font-weight:750;color:var(--ink-strong);line-height:1}
.reu-day .m{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;margin-top:2px}
.reu-body{flex:1;min-width:0}
.reu-title{font-size:14.5px;font-weight:650;color:var(--ink-strong);margin-bottom:6px}
.reu-meta{display:flex;flex-wrap:wrap;align-items:center;gap:8px;font-size:12.5px;color:var(--muted)}
.reu-cli{display:inline-flex;align-items:center;gap:6px;font-weight:600;color:#3c4149;background:var(--soft);border:1px solid var(--line);border-radius:99px;padding:3px 10px;text-decoration:none}
.reu-cli:hover{background:#ececee}
.reu-cli.none{color:var(--muted);font-weight:500;background:none;border-style:dashed}
.reu-asbtn{border:none;background:none;color:#0071e3;font-size:12px;font-weight:600;cursor:pointer;padding:2px 6px;border-radius:6px;font-family:inherit}
.reu-asbtn:hover{background:#eef4fe}
.reu-badge{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;color:#5f6672}
.reu-owner{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;color:#5f6672;background:#f2f2f3;border:1px solid var(--line);border-radius:99px;padding:2px 9px}
.reu-usel{border:1px solid var(--line);background:#fff;border-radius:11px;padding:9px 32px 9px 13px;font-size:13px;font-weight:600;color:var(--ink);font-family:inherit;cursor:pointer;outline:none;-webkit-appearance:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239aa0a8' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 11px center}
.reu-usel:hover{border-color:#d7d8db}
.reu-usel:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.reu-docs{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}
.reu-doc{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;color:#3c4149;background:#f2f2f3;border:1px solid var(--line);border-radius:9px;padding:6px 11px;text-decoration:none}
.reu-doc:hover{background:#e9e9eb}.reu-doc.ghost{background:none}
.reu-when{font-size:12px;color:var(--muted);margin-left:auto;flex:none;white-space:nowrap}
.reu-empty{padding:34px 20px;text-align:center;color:var(--muted);font-size:13.5px;background:#fff;border:1px dashed var(--line);border-radius:16px}
.reu-note{display:flex;gap:12px;align-items:center;padding:16px 18px;background:#fffaf0;border:1px solid #f0e0bf;border-radius:14px;color:#7a5b12;font-size:13.5px}
.reu-note a{color:#3c4149;font-weight:700}
/* Popup de asignar (por tipo del CRM) */
.reu-pop{position:fixed;z-index:200;width:280px;max-height:340px;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 18px 46px -14px rgba(16,19,24,.34);display:none;flex-direction:column;overflow:hidden}
.reu-pop.on{display:flex}
.reu-pop .ph{padding:9px 11px;border-bottom:1px solid var(--line2)}
.reu-pop .ph input{width:100%;border:1px solid var(--line);border-radius:9px;padding:8px 10px;font-size:13px;font-family:inherit;outline:none}
.reu-pop .ph input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.reu-pop .list{overflow:auto;padding:6px}
.reu-pop .glbl{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:650;padding:8px 8px 4px}
.reu-pop .opt{display:flex;align-items:center;gap:9px;width:100%;border:none;background:none;cursor:pointer;padding:7px 8px;border-radius:8px;font-size:13px;text-align:left;font-family:inherit;color:var(--ink)}
.reu-pop .opt:hover{background:var(--soft)}
.reu-pop .opt .av{width:24px;height:24px;border-radius:50%;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex:none}
.reu-pop .opt .nm{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.reu-pop .opt .nm small{color:var(--muted);font-weight:500}
.reu-pop .opt.clear{color:#c0343a}
/* Modal de crear reunión */
.reu-ov{position:fixed;inset:0;background:rgba(17,19,24,.4);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;padding:24px 18px;z-index:220;opacity:0;visibility:hidden;transition:opacity .18s ease,visibility .18s ease;overflow:auto}
.reu-ov.on{opacity:1;visibility:visible}
.reu-modal{background:#fff;border-radius:18px;width:520px;max-width:100%;box-shadow:0 30px 80px rgba(0,0,0,.3);transform:translateY(8px) scale(.99);transition:transform .2s cubic-bezier(.33,1,.68,1);overflow:hidden}
.reu-ov.on .reu-modal{transform:none}
/* Cabecera con el logo de Meet y el subtítulo, igual que el popup de «Agendar
   reunión» de la ficha del cliente. */
.reu-mh{display:flex;align-items:center;gap:11px;padding:17px 22px;border-bottom:1px solid var(--line)}
.reu-mh-logo{width:44px;height:44px;border-radius:12px;background:#fff;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;flex:none}
.reu-mh-logo svg{display:block}
.reu-mh-t{display:flex;flex-direction:column;gap:2px;min-width:0}
.reu-mh-t b{font-size:16px;font-weight:600;color:var(--ink-strong)}
.reu-mh-t small{display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--muted);font-weight:500}
.reu-mh-t small svg{display:block}
.reu-modal .mb{padding:16px 22px 8px;display:flex;flex-direction:column;gap:15px}
/* Segmentado «La agendo yo / Que elija el cliente», igual que el popup de la ficha. */
.reu-seg{display:flex;gap:4px;margin:14px 22px 0;background:var(--soft);border-radius:11px;padding:3px}
.reu-seg button{flex:1;border:none;background:none;font-family:inherit;font-size:12.5px;font-weight:600;color:#6b7280;padding:8px;border-radius:8px;cursor:pointer}
.reu-seg button.on{background:#fff;color:var(--ink-strong);box-shadow:0 1px 3px rgba(0,0,0,.08)}
.reu-modal{will-change:height}
@keyframes reuPanelIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
.reu-modal label{display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px}
.reu-modal input[type=text],.reu-modal textarea,.reu-modal select{width:100%;border:1px solid var(--line);border-radius:10px;padding:9px 12px;font-size:13.5px;font-family:inherit;outline:none}
.reu-modal input:focus,.reu-modal textarea:focus,.reu-modal select:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.reu-modal .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.reu-modal .row3{display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:12px}
.reu-tog{display:flex;align-items:center;gap:9px;font-size:13px;color:var(--ink);font-weight:500;cursor:pointer}
.reu-tog input{width:16px;height:16px;accent-color:var(--accent)}
.reu-modal .mf{display:flex;justify-content:flex-end;gap:10px;padding:14px 22px;border-top:1px solid var(--line2);background:#fcfcfd;margin-top:8px}
.reu-modal .mf .g{border:1px solid var(--line);background:#fff;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:600;cursor:pointer;color:var(--ink)}
.reu-modal .mf .p{border:none;background:var(--accent);color:#fff;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:600;cursor:pointer}
.reu-modal .mf .dz{border:1px solid #f0caca;background:#fff;color:#c0343a;border-radius:10px;padding:10px 14px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.reu-modal .mf .dz:hover{background:#feecec}
.reu-tog .tsub{color:var(--muted);font-weight:400;font-size:12px}
.reu-inv{position:relative}
.reu-inv label .sub{font-weight:400;text-transform:none;color:var(--muted)}
/* Sugerencias de correo mientras escribes (autocompletado de invitados). */
.reu-inv-pop{position:absolute;left:0;right:0;top:calc(100% + 4px);background:#fff;border:1px solid var(--line);border-radius:11px;
  box-shadow:0 16px 40px rgba(16,19,24,.16);padding:5px;z-index:30;display:none;max-height:220px;overflow:auto}
.reu-inv-pop.on{display:block}
.reu-inv-pop button{display:block;width:100%;text-align:left;border:none;background:none;padding:8px 11px;border-radius:8px;
  font-family:inherit;font-size:13px;color:var(--ink);cursor:pointer}
.reu-inv-pop button:hover,.reu-inv-pop button.sel{background:var(--accent-soft);color:var(--ink-strong)}
/* Interruptores del modal (usan .sw de erp_nav). */
/* Interruptores: cada uno en su fila con el logo a la izquierda, el texto en medio
   y el interruptor a la derecha (patrón tipo ajustes de iOS). Con aire entre filas
   y realce al pasar el ratón, para que no se vean apelotonados. */
.reu-sws{display:flex;flex-direction:column;gap:3px;margin-top:4px;border-top:1px solid var(--line2);padding-top:8px}
/* `label.reu-sw` para ganarle en especificidad a `.reu-modal label{display:block}`,
   que si no dejaba las filas en vertical (logo arriba, interruptor abajo). */
label.reu-sw{display:flex;align-items:center;gap:13px;cursor:pointer;margin:0;padding:11px 8px;border-radius:12px;transition:background .13s ease}
label.reu-sw:hover{background:var(--soft)}
.reu-sw .lg{flex:none;width:26px;height:26px;display:inline-flex;align-items:center;justify-content:center}
.reu-sw .lg svg{display:block}
.reu-sw .tx{flex:1;min-width:0}
.reu-sw .tx b{display:block;font-size:13.5px;font-weight:600;color:var(--ink-strong)}
.reu-sw .tx em{display:block;font-style:normal;font-size:12px;color:var(--muted);line-height:1.45;margin-top:2px}
.reu-sw .sw{flex:none}
.reu-modal label .sub{font-weight:400;text-transform:none;color:var(--muted)}

/* Filtro de meses: un desplegable con el mismo aire de pastilla que las pestañas,
   a su derecha (el hueco de la fila, 14px, lo separa como otro grupo). */
.reu-mdd{position:relative}
.reu-mdd-btn{display:inline-flex;align-items:center;gap:8px;background:#f1f2f4;border:none;border-radius:12px;
  padding:9px 13px 9px 15px;font-family:inherit;font-size:13px;font-weight:600;color:var(--ink);cursor:pointer;transition:background .12s ease}
.reu-mdd-btn:hover{background:#e8e9ec}
.reu-mdd-btn > svg:first-child{color:var(--muted);flex:none}
.reu-mdd-btn .chev{width:14px;height:14px;stroke:var(--muted);stroke-width:2.4;fill:none;stroke-linecap:round;stroke-linejoin:round;transition:transform .16s ease;margin-left:1px}
.reu-mdd.on .reu-mdd-btn{background:#e4e5e8}
.reu-mdd.on .reu-mdd-btn .chev{transform:rotate(180deg)}
.reu-mdd-pop{position:absolute;top:calc(100% + 6px);left:0;min-width:180px;max-height:280px;overflow:auto;background:#fff;
  border:1px solid var(--line);border-radius:12px;box-shadow:0 16px 42px rgba(16,19,24,.17);padding:5px;z-index:40;display:none}
.reu-mdd.on .reu-mdd-pop{display:block;animation:pop .14s ease}
.reu-mdd-pop button{display:block;width:100%;text-align:left;border:none;background:none;font-family:inherit;font-size:13px;
  color:var(--ink);font-weight:500;padding:8px 11px;border-radius:8px;cursor:pointer;white-space:nowrap}
.reu-mdd-pop button:hover{background:var(--soft)}
.reu-mdd-pop button.on{background:var(--accent);color:#fff;font-weight:600}
/* ---- Modo oscuro: remapea las superficies y textos propios ---- */
[data-theme=dark] .reu-logo,[data-theme=dark] .reu-tabs button.on,[data-theme=dark] .reu-card,[data-theme=dark] .reu-empty,[data-theme=dark] .reu-modal,[data-theme=dark] .reu-seg button.on,[data-theme=dark] .reu-modal .mf .g,[data-theme=dark] .reu-modal .mf .dz{background-color:var(--card)}
[data-theme=dark] .reu-monthsel,[data-theme=dark] .reu-usel{background-color:var(--field)}
[data-theme=dark] .reu-tabs,[data-theme=dark] .reu-tabs button.on .cnt,[data-theme=dark] .reu-cli:hover,[data-theme=dark] .reu-asbtn:hover,[data-theme=dark] .reu-owner,[data-theme=dark] .reu-doc,[data-theme=dark] .reu-doc:hover,[data-theme=dark] .reu-note,[data-theme=dark] .reu-modal .mf,[data-theme=dark] .reu-mdd-btn,[data-theme=dark] .reu-mdd-btn:hover,[data-theme=dark] .reu-mdd.on .reu-mdd-btn{background-color:var(--soft)}
[data-theme=dark] .reu-tabs button,[data-theme=dark] .reu-tabs .cnt,[data-theme=dark] .reu-month,[data-theme=dark] .reu-badge,[data-theme=dark] .reu-owner,[data-theme=dark] .reu-seg button{color:var(--muted)}
[data-theme=dark] .reu-cli,[data-theme=dark] .reu-doc,[data-theme=dark] .reu-note a{color:var(--ink)}
[data-theme=dark] .reu-note{border-color:var(--line);color:var(--warn)}
[data-theme=dark] .reu-new,[data-theme=dark] .reu-modal .mf .p,[data-theme=dark] .reu-mdd-pop button.on{color:var(--accent-fg)}
[data-theme=dark] .reu-pop,[data-theme=dark] .reu-inv-pop,[data-theme=dark] .reu-mdd-pop{background-color:var(--pop)}
[data-theme=dark] .reu-modal .mf .dz{border-color:var(--danger-line)}
[data-theme=dark] .reu-modal .mf .dz:hover{background-color:var(--danger-bg)}
/* ====== MÓVIL (≤640px) ====== */
@media(max-width:640px){
  .reu-hero{flex-wrap:wrap;gap:12px;margin-bottom:18px}
  .reu-hero h1{font-size:20px}
  .reu-hero .sp{display:none}
  .reu-new{margin-left:auto}
  .reu-tabrow{gap:10px}
  .reu-tabs{flex:1}
  .reu-tabs button{flex:1;justify-content:center;padding:8px 10px}
  .reu-monthsel,.reu-mdd{margin-left:0}
  /* Reuniones: filas planas y compactas, sin caja ni sombra por elemento */
  .reu-card{border:none;border-radius:0;box-shadow:none!important;padding:11px 2px;margin-bottom:0;border-bottom:1px solid var(--line2);gap:12px}
  .reu-day{width:40px;padding-right:10px}
  .reu-day .d{font-size:18px}
  .reu-title{font-size:14px;margin-bottom:4px}
  .reu-when{margin-left:0}
  /* Modal: rejillas a una columna y casi pantalla completa con scroll interno */
  .reu-ov{align-items:flex-start;padding:16px 10px}
  .reu-modal{width:100%;max-height:calc(100vh - 32px);display:flex;flex-direction:column}
  .reu-modal .mb{flex:1;overflow-y:auto}
  .reu-modal .row2,.reu-modal .row3{grid-template-columns:1fr}
  .reu-modal .mf{flex-wrap:wrap}
  .reu-modal .mf .p,.reu-modal .mf .g{flex:1;text-align:center;justify-content:center}
  /* Popovers propios no más anchos que la pantalla */
  .reu-pop{width:auto;max-width:calc(100vw - 24px)}
}
</style>

<div class="reu-wrap">
  <div class="reu-hero">
    <div class="reu-logo"><?= svc_logo('gcal',30) ?></div>
    <div>
      <h1>Reuniones</h1>
      <p>Tus reuniones de Google en un sitio, con las notas de Gemini y para crear nuevas.</p>
    </div>
    <div class="sp"></div>
    <?php if($multi): $base='reuniones.php'.($ctxOps?'?ctx=ops&u=':'?u='); ?>
    <select class="reu-usel" onchange="if(this.value)location.href=this.value" title="Ver reuniones de…">
      <option value="<?= e($base.'all') ?>"<?= $vu==='all'?' selected':'' ?>>Todo el equipo</option>
      <option value="<?= e($base.'me') ?>"<?= $vu==='me'?' selected':'' ?>>Solo yo</option>
      <?php foreach($connAdmins as $aid=>$un): if($aid===$meId) continue; ?>
      <option value="<?= e($base.$aid) ?>"<?= $vu===(string)$aid?' selected':'' ?>><?= e($un) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if($gcConnected && !$gcRevoked && can_edit()): ?><button type="button" class="reu-new" onclick="reuNew(true)"><?= ic('plus',15) ?> Crear reunión</button><?php endif; ?>
  </div>

  <?php if($reqPend): $MESN=['','ENE','FEB','MAR','ABR','MAY','JUN','JUL','AGO','SEP','OCT','NOV','DIC']; ?>
  <div style="margin-bottom:20px">
    <div style="display:flex;align-items:center;gap:9px;margin:0 2px 11px"><?= ic('bell',18) ?><b style="font-size:14.5px;color:var(--ink-strong)">Solicitudes de reunión</b><span style="font-size:11.5px;color:#c2660a;background:#fff3e6;border-radius:99px;padding:2px 11px;font-weight:700"><?= count($reqPend) ?> pendiente<?= count($reqPend)>1?'s':'' ?></span></div>
    <?php foreach($reqPend as $r): $d=$r['fecha_deseada']?strtotime($r['fecha_deseada']):0; ?>
    <div class="reu-card">
      <div class="reu-day"><?php if($d): ?><div class="d"><?= date('d',$d) ?></div><div class="m"><?= $MESN[(int)date('n',$d)] ?></div><?php else: ?><div class="d" style="color:var(--muted)">–</div><div class="m">día</div><?php endif; ?></div>
      <div class="reu-body">
        <div class="reu-title"><?= e($r['motivo'] ?: 'Reunión solicitada por el cliente') ?></div>
        <div class="reu-meta"><span class="reu-cli"><?= ic('clients',13) ?> <?= e($r['cliente']) ?></span><?php if($d): ?><span class="reu-badge"><?= ic('cal',13) ?> <?= e(date('d/m/Y',$d)) ?><?= $r['franja']!==''?(' · '.e($r['franja'])):'' ?></span><?php else: ?><span class="reu-badge"><?= ic('cal',13) ?> Sin día preferido</span><?php endif; ?></div>
      </div>
      <?php if(can_edit()): ?>
      <div style="display:flex;gap:8px;flex:none;align-items:center;align-self:center">
        <form method="post" style="margin:0" onsubmit="return erpSubmitAsk(this,'¿Rechazar esta solicitud?')"><input type="hidden" name="action" value="meetreq_reject"><input type="hidden" name="req_id" value="<?= (int)$r['id'] ?>"><?= csrf_field() ?><button class="btn ghost sm" type="submit">Rechazar</button></form>
        <button type="button" class="btn sm" data-req="<?= htmlspecialchars(json_encode(['req_id'=>(int)$r['id'],'cliente'=>$r['cliente'],'motivo'=>$r['motivo'],'fecha'=>$r['fecha_deseada'],'email'=>$r['fact_email']], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>" onclick="reuAprobar(JSON.parse(this.getAttribute('data-req')))"><?= ic('check',14) ?> Aprobar</button>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if(!$gcConnected): ?>
    <div class="reu-note"><?= ic('alert',20) ?><div>Conecta Google Calendar para ver y crear reuniones aquí. Ve a <a href="integraciones.php">Integraciones</a>.</div></div>
  <?php elseif($gcRevoked): ?>
    <div class="reu-note"><?= ic('alert',20) ?><div>Google pide volver a conectar la cuenta. Reconéctala en <a href="integraciones.php">Integraciones</a>.</div></div>
  <?php else: ?>

    <?php /* Dos columnas: el filtro de meses en su PROPIO panel a la IZQUIERDA, y a
             su derecha (con un pequeño espacio) las pestañas Próximas/Pasadas/Notas
             y las reuniones. */ ?>
    <?php /* Dos grupos segmentados en la misma fila: las pestañas a la izquierda y
             el filtro de meses a su derecha, con un pequeño hueco para que se lean
             como dos bloques distintos. Mismo estilo de pastilla que las pestañas. */ ?>
    <div class="reu-tabrow">
      <div class="reu-tabs">
        <button type="button" class="on" data-tab="prox" onclick="reuTab('prox')"><?= ic('cal',14) ?> Próximas <span class="cnt"><?= count($proximas) ?></span></button>
        <button type="button" data-tab="pas" onclick="reuTab('pas')"><?= ic('clock',14) ?> Pasadas <span class="cnt"><?= count($pasadas) ?></span></button>
        <button type="button" data-tab="notas" onclick="reuTab('notas')"><?= ic('file',14) ?> Notas <span class="cnt"><?= count($conNotas) ?></span></button>
      </div>
      <div class="reu-mdd" id="reuMonths">
        <button type="button" class="reu-mdd-btn" onclick="reuMonthToggle(event)"><?= ic('cal',14) ?> <span id="reuMonthLbl">Todos los meses</span><svg class="chev" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></button>
        <div class="reu-mdd-pop" id="reuMddPop"><!-- meses, rellenados por JS --></div>
      </div>
    </div>

    <div class="reu-pane on" id="pane-prox">
      <?php if(!$proximas): ?><?= erp_empty('cal','No tienes reuniones próximas','Cuando agendes una o apruebes una solicitud, aparecerá aquí.', ($gcConnected && !$gcRevoked && can_edit())?'<button type="button" class="btn" onclick="reuNew(true)">'.ic('plus',15).' Crear reunión</button>':'') ?>
      <?php else: reu_list($proximas); endif; ?>
    </div>
    <div class="reu-pane" id="pane-pas">
      <?php if(!$pasadas): ?><?= erp_empty('clock','Sin reuniones pasadas','No hay reuniones en los últimos 90 días.') ?>
      <?php else: reu_list($pasadas); endif; ?>
    </div>
    <div class="reu-pane" id="pane-notas">
      <?php if(!$conNotas): ?><?= erp_empty('file','Aún no hay notas','Activa «Tomar notas por mí» (Gemini) en una reunión de Meet y aquí aparecerá su documento.') ?>
      <?php else: reu_list($conNotas); endif; ?>
    </div>

  <?php endif; ?>
</div>

<?php if(can_edit()): ?>
<!-- Popup de asignar cliente por tipo del CRM -->
<div class="reu-pop" id="reuPop">
  <div class="ph"><input type="text" id="reuPopSearch" placeholder="Buscar contacto…" oninput="reuPopFilter()" autocomplete="off"></div>
  <div class="list" id="reuPopList"></div>
</div>

<!-- Modal de crear / editar reunión -->
<div class="reu-ov" id="reuOv" onclick="if(event.target===this)reuNew(false)">
  <div class="reu-modal">
    <form method="post" id="reuForm">
      <input type="hidden" name="action" id="reuAction" value="create">
      <input type="hidden" name="event_id" id="reuEventId" value="">
      <input type="hidden" name="req_id" id="reuReqId" value=""><?php /* si viene de aprobar una solicitud del portal */ ?>
      <div class="reu-mh">
        <span class="reu-mh-logo"><?= svc_logo('meet',28) ?></span>
        <span class="reu-mh-t"><b id="reuModalTit">Crear reunión</b><small><?= svc_logo('gcal',13) ?> Se crea en tu Google Calendar</small></span>
      </div>
      <?php /* Dos modos, como el popup de la ficha de cliente: la agendo yo o le mando
               al cliente mi enlace de reservas para que elija hueco. Solo al CREAR. */ ?>
      <div class="reu-seg" id="reuSeg">
        <button type="button" class="on" id="reuSegYo" onclick="reuMode('yo',true)">La agendo yo</button>
        <button type="button" id="reuSegCli" onclick="reuMode('cli',true)">Que elija el cliente</button>
      </div>
      <div class="mb" id="reuPanelCli" style="display:none">
        <p style="margin:0;font-size:13px;color:var(--muted);line-height:1.5">Le mandas al cliente tu enlace de reservas y elige el hueco libre que quiera de tu agenda.</p>
        <?php $reuLink = get_setting('meeting_url',''); ?>
        <div><label>Tu enlace de reservas</label>
          <div style="display:flex;gap:8px">
            <input type="text" id="reuLink" readonly value="<?= e($reuLink) ?>" style="flex:1">
            <button type="button" class="g" onclick="reuCopyLink()" style="border:1px solid var(--line);background:#fff;border-radius:10px;padding:0 16px;font-size:13px;font-weight:600;cursor:pointer;color:var(--ink)">Copiar</button>
          </div>
          <?php if($reuLink===''): ?><div style="margin-top:8px;font-size:12.5px;color:#c0343a">Aún no tienes enlace de reservas. Créalo en Google Calendar (<b>Crear → Horario de citas</b>) y pégalo en <a href="settings.php?tab=contacto">Ajustes</a>.</div><?php endif; ?>
        </div>
      </div>
      <div class="mb" id="reuPanelYo">
        <div><label>Título</label><input type="text" name="titulo" id="reuTit" placeholder="Ej: Llamada con Cliente X" required autocomplete="off"></div>
        <div class="row3">
          <div><label>Fecha</label><input type="text" class="dpick" id="reuFechaVis" data-iso="<?= date('Y-m-d') ?>" data-sync="#reuFecha" autocomplete="off"><input type="hidden" name="fecha" id="reuFecha" value="<?= date('Y-m-d') ?>"></div>
          <div><label>Hora</label><input type="text" name="hora" id="reuHora" placeholder="10:00" value="10:00" autocomplete="off"></div>
          <div><label>Duración</label><select name="dur" id="reuDur"><option value="30">30 min</option><option value="60" selected>1 hora</option><option value="90">1 h 30</option><option value="120">2 horas</option></select></div>
        </div>
        <?php /* Autocompletado de invitados: se sugieren correos ya usados a medida
                 que se escribe cada uno (separados por coma). */ ?>
        <div class="reu-inv"><label>Invitados <span class="sub">correos separados por coma</span></label>
          <input type="text" name="invitados" id="reuInv" placeholder="cliente@empresa.com, otro@…" autocomplete="off" oninput="reuInvType()" onkeydown="reuInvKey(event)">
          <div class="reu-inv-pop" id="reuInvPop"></div>
        </div>
        <div><label>Recordatorio <span class="sub">el aviso que salta antes de la reunión</span></label>
          <select name="recordar" id="reuRecordar">
            <option value="">Predeterminado de Google</option>
            <option value="10">10 minutos antes</option>
            <option value="30" selected>30 minutos antes</option>
            <option value="60">1 hora antes</option>
            <option value="120">2 horas antes</option>
            <option value="1440">1 día antes</option>
            <option value="no">Sin recordatorio</option>
          </select>
        </div>
        <?php /* Los tres toggles son INTERRUPTORES (.sw de erp_nav), cada uno con su
                 logo y con aire entre ellos, en el estilo del popup de «Agendar». */ ?>
        <div class="reu-sws">
          <label class="reu-sw"><span class="lg"><?= svc_logo('meet',19) ?></span>
            <span class="tx"><b>Añadir videollamada de Google Meet</b></span>
            <span class="sw"><input type="checkbox" name="meet" id="reuMeet" checked><span class="tr"></span></span></label>
          <label class="reu-sw"><span class="lg"><?= svc_logo('gcal',19) ?></span>
            <span class="tx"><b>Avisar a los invitados por correo</b><em>Les llega la invitación de Google Calendar.</em></span>
            <span class="sw"><input type="checkbox" name="notificar" id="reuNotif" checked><span class="tr"></span></span></label>
          <label class="reu-sw"><span class="lg"><?= svc_logo('gemini',19) ?></span>
            <span class="tx"><b>Tomar notas con Gemini</b><em>Deja un aviso para pulsar «Tomar notas» en la reunión de Meet.</em></span>
            <span class="sw"><input type="checkbox" name="gemini" id="reuGemini"><span class="tr"></span></span></label>
        </div>
      </div>
      <div class="mf">
        <button type="button" class="dz" id="reuDelBtn" onclick="reuDelete()" style="display:none"><?= ic('trash',15) ?> Borrar</button>
        <span class="sp" style="flex:1"></span>
        <button type="button" class="g" onclick="reuNew(false)">Cancelar</button>
        <button type="submit" class="p" id="reuSubmit">Crear en Google Calendar</button>
      </div>
    </form>
  </div>
</div>

<!-- Menú contextual de una reunión -->
<div class="ctxmenu" id="reuCtx"></div>
<script>window.REU_EMAILS = <?= json_encode($reuEmails, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;</script>
<?php endif; ?>

<script>
window.REU_GROUPS = <?= json_encode($grupos_js, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
function reuTab(t){document.querySelectorAll('.reu-tabs button').forEach(function(b){b.classList.toggle('on',b.dataset.tab===t);});
  document.querySelectorAll('.reu-pane').forEach(function(p){p.classList.remove('on');});
  var pane=document.getElementById('pane-'+t);if(pane)pane.classList.add('on');
  reuMonthPop();}
/* Rellena el DESPLEGABLE de meses (a la derecha de las pestañas) con los del panel
   activo y quita el filtro. */
var _reuMonth='', _reuMonthLbls={};
function reuMonthPop(){var pane=document.querySelector('.reu-pane.on');var box=document.getElementById('reuMddPop');var wrap=document.getElementById('reuMonths');if(!pane||!box)return;
  var seen=[],lbl={};
  pane.querySelectorAll('.reu-month').forEach(function(m){var mk=m.getAttribute('data-mk');if(mk&&!lbl[mk]){lbl[mk]=m.textContent.replace(/\s+/g,' ').trim();seen.push(mk);}});
  _reuMonthLbls=lbl; _reuMonthLbls['']='Todos los meses';
  var h='<button type="button" class="on" data-mk="" onclick="reuMonthFilter(\'\')">Todos los meses</button>';
  /* Las etiquetas son nombres de mes del servidor (sin datos del usuario): no hace
     falta escapar, y así esta función corre en el parse, antes de que erp_foot()
     defina escHtml. */
  seen.forEach(function(mk){h+='<button type="button" data-mk="'+mk+'" onclick="reuMonthFilter(\''+mk+'\')">'+lbl[mk]+'</button>';});
  box.innerHTML=h;
  wrap.style.display = seen.length>1 ? '' : 'none';   // si solo hay un mes, no hace falta filtro
  _reuMonth='';reuMonthFilter('');}
/* Modo del modal de crear: «La agendo yo» (formulario) o «Que elija el cliente»
   (le paso el enlace de reservas). En «cliente» se oculta el botón de guardar
   porque no se crea nada: lo crea el cliente al elegir hueco. */
function reuMode(m,anim){var yo=m==='yo';
  var modal=document.querySelector('.reu-modal');
  var py=document.getElementById('reuPanelYo'), pc=document.getElementById('reuPanelCli');
  /* Animación fluida: se mide la altura antes y después del cambio y se transiciona
     entre las dos, con un fundido del panel que entra. Así no da un salto seco. */
  var visible=anim && modal && document.getElementById('reuOv').classList.contains('on');
  var h0=visible?modal.getBoundingClientRect().height:0;
  if(py) py.style.display=yo?'flex':'none';
  if(pc) pc.style.display=yo?'none':'flex';
  document.getElementById('reuSegYo').classList.toggle('on',yo);
  document.getElementById('reuSegCli').classList.toggle('on',!yo);
  document.getElementById('reuSubmit').style.display=yo?'':'none';
  if(visible){
    var h1=modal.getBoundingClientRect().height;
    if(Math.abs(h1-h0)>2){
      modal.style.height=h0+'px'; modal.getBoundingClientRect();
      modal.style.transition='height .3s cubic-bezier(.4,0,.2,1)';
      modal.style.height=h1+'px';
      setTimeout(function(){ modal.style.height=''; modal.style.transition=''; },320);
    }
    var pin=yo?py:pc; if(pin){ pin.style.animation='reuPanelIn .3s ease'; setTimeout(function(){pin.style.animation='';},320); }
  }
}
function reuCopyLink(){var i=document.getElementById('reuLink');if(!i||!i.value){if(window.toast)toast('No tienes enlace de reservas','err');return;}
  i.select();try{document.execCommand('copy');}catch(e){} if(navigator.clipboard){try{navigator.clipboard.writeText(i.value);}catch(e){}}
  if(window.toast)toast('Enlace copiado ✓');}
function reuMonthToggle(e){if(e)e.stopPropagation();document.getElementById('reuMonths').classList.toggle('on');}
function reuMonthClose(){var w=document.getElementById('reuMonths');if(w)w.classList.remove('on');}
function reuMonthFilter(v){_reuMonth=v;
  var lblEl=document.getElementById('reuMonthLbl');if(lblEl)lblEl.textContent=_reuMonthLbls[v]||'Todos los meses';
  var pane=document.querySelector('.reu-pane.on');
  if(pane) pane.querySelectorAll('.reu-month,.reu-card').forEach(function(el){var mk=el.getAttribute('data-mk');el.style.display=(!v||mk===v)?'':'none';});
  document.querySelectorAll('#reuMddPop button').forEach(function(b){b.classList.toggle('on',b.getAttribute('data-mk')===v);});
  reuMonthClose();}
document.addEventListener('click',function(e){if(!e.target.closest('#reuMonths'))reuMonthClose();});

/* Abrir el modal: en modo CREAR se limpia; reuEdit() lo deja en modo EDITAR. */
function reuNew(show){var ov=document.getElementById('reuOv');if(!ov)return;
  if(show){ reuResetForm(); }
  ov.classList.toggle('on',show);reuInvClose();
  if(show){var t=document.getElementById('reuTit');if(t)setTimeout(function(){t.focus();},60);}}
function reuResetForm(){
  document.getElementById('reuAction').value='create';
  document.getElementById('reuEventId').value='';
  var rq=document.getElementById('reuReqId'); if(rq) rq.value='';
  document.getElementById('reuModalTit').textContent='Crear reunión';
  document.getElementById('reuSubmit').textContent='Crear en Google Calendar';
  document.getElementById('reuDelBtn').style.display='none';
  document.getElementById('reuTit').value='';
  document.getElementById('reuHora').value='10:00';
  document.getElementById('reuInv').value='';
  document.getElementById('reuMeet').checked=true;
  document.getElementById('reuNotif').checked=true;
  document.getElementById('reuGemini').checked=false;
  reuSetRecordar('30');
  var dur=document.getElementById('reuDur'); if(dur) dur.value='60';
  var vis=document.getElementById('reuFechaVis'); if(vis&&window.dpSet) dpSet(vis, todayIso());
  else { document.getElementById('reuFecha').value=todayIso(); }
  /* En crear se ofrecen los dos modos; se arranca en «La agendo yo». */
  document.getElementById('reuSeg').style.display='';
  reuMode('yo');
}
/* Aprobar una solicitud del portal: abre el MISMO popup «Crear reunión» ya
   pre-relleno (título, fecha e invitado), en modo «La agendo yo», y con el req_id
   para que al crear se agende la reunión real (crm_meetings + Google) y el cliente
   la vea. */
function reuAprobar(data){
  data=data||{};
  reuNew(true);
  var rq=document.getElementById('reuReqId'); if(rq) rq.value=data.req_id||'';
  document.getElementById('reuModalTit').textContent='Aprobar y agendar reunión';
  document.getElementById('reuSubmit').textContent='Crear y avisar al cliente';
  document.getElementById('reuTit').value=data.motivo||('Reunión con '+(data.cliente||'el cliente'));
  if(data.email){ var inv=document.getElementById('reuInv'); if(inv) inv.value=data.email; }
  var seg=document.getElementById('reuSeg'); if(seg) seg.style.display='none';
  if(window.reuMode) reuMode('yo');
  if(data.fecha){ var vis=document.getElementById('reuFechaVis'); if(vis&&window.dpSet) dpSet(vis,data.fecha); else { var f=document.getElementById('reuFecha'); if(f) f.value=data.fecha; } }
}
function todayIso(){var d=new Date();return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');}
/* Fija el recordatorio en el desplegable; si la reunión trae un valor que no está
   entre las opciones (p. ej. 45 min), se le añade su propia opción para no perderlo. */
function reuSetRecordar(v){
  var rec=document.getElementById('reuRecordar'); if(!rec) return;
  v=(v==null?'':String(v));
  var has=[].some.call(rec.options,function(o){return o.value===v;});
  if(!has && /^\d+$/.test(v)){
    var min=+v, lbl;
    if(min%1440===0){var d=min/1440;lbl=d+(d>1?' días':' día')+' antes';}
    else if(min%60===0){var h=min/60;lbl=h+(h>1?' horas':' hora')+' antes';}
    else lbl=min+' minutos antes';
    var opt=document.createElement('option');opt.value=v;opt.textContent=lbl;rec.appendChild(opt);
  }
  rec.value=v;
}

/* Editar: abre el modal ya relleno con los datos de esa reunión. */
function reuEdit(data){
  reuResetForm();
  document.getElementById('reuAction').value='update';
  document.getElementById('reuEventId').value=data.id||'';
  document.getElementById('reuModalTit').textContent='Editar reunión';
  document.getElementById('reuSubmit').textContent='Guardar cambios';
  document.getElementById('reuDelBtn').style.display='';
  /* Editar no tiene modos: solo el formulario. */
  document.getElementById('reuSeg').style.display='none';
  reuMode('yo');
  document.getElementById('reuTit').value=data.titulo||'';
  document.getElementById('reuHora').value=data.hora||'';
  document.getElementById('reuInv').value=data.invitados||'';
  document.getElementById('reuMeet').checked=!!data.meet;
  document.getElementById('reuNotif').checked=true;
  reuSetRecordar(data.recordar);
  var vis=document.getElementById('reuFechaVis'); if(vis&&window.dpSet&&data.fecha) dpSet(vis, data.fecha); else if(data.fecha) document.getElementById('reuFecha').value=data.fecha;
  /* La duración se deduce de hora/hora_fin si están. */
  if(data.hora&&data.hora_fin){ var a=data.hora.split(':'),b=data.hora_fin.split(':'); var mins=(b[0]*60+ +b[1])-(a[0]*60+ +a[1]); var dur=document.getElementById('reuDur'); if(dur&&mins>0){ var opt=[...dur.options].find(function(o){return +o.value===mins;}); dur.value=opt?String(mins):'60'; } }
  document.getElementById('reuOv').classList.add('on');
  setTimeout(function(){document.getElementById('reuTit').focus();},60);
}

/* Menú contextual (clic derecho) sobre una reunión. */
function reuMenu(e, card){
  if(e.target.closest('a,button')) return true;   // clic derecho sobre un enlace real
  e.preventDefault();
  var data={}; try{ data=JSON.parse(card.getAttribute('data-ev')||'{}'); }catch(_){}
  var m=document.getElementById('reuCtx'); if(!m) return false; m.innerHTML='';
  function it(txt,danger,fn){var a=document.createElement('a');a.textContent=txt;a.href='#';if(danger)a.className='danger';
    a.onclick=function(ev){ev.preventDefault();m.classList.remove('on');fn();};m.appendChild(a);}
  function sep(){var s=document.createElement('div');s.className='sep';m.appendChild(s);}
  it('Editar reunión',false,function(){reuEdit(data);});
  it('Asignar cliente',false,function(){var b=card.querySelector('.reu-asbtn');if(b)b.click();});
  if(data.link){var a=document.createElement('a');a.textContent='Abrir en Google Calendar';a.href=data.link;a.target='_blank';a.rel='noopener';a.onclick=function(){m.classList.remove('on');};m.appendChild(a);}
  sep();
  it('Borrar reunión',true,function(){reuAskDelete(data);});
  m.style.left=Math.min(e.clientX,window.innerWidth-210)+'px';
  m.style.top=Math.min(e.clientY,window.innerHeight-200)+'px';
  m.classList.add('on');
  return false;
}
document.addEventListener('click',function(e){if(!e.target.closest('#reuCtx')){var m=document.getElementById('reuCtx');if(m)m.classList.remove('on');}});

/* Borrar: desde el menú (con confirmación) o desde el propio modal de edición. */
function reuAskDelete(data){
  erpConfirm('Se elimina «'+(data.titulo||'esta reunión')+'» de Google Calendar y se avisa a los invitados.',{titulo:'Borrar reunión',ok:'Borrar',danger:true}).then(function(ok){
    if(ok) reuSubmitDelete(data.id);
  });
}
function reuDelete(){ reuAskDelete({id:document.getElementById('reuEventId').value, titulo:document.getElementById('reuTit').value}); }
function reuSubmitDelete(id){
  if(!id) return;
  /* El formulario se crea y se envía en el acto, ANTES de que el MutationObserver
     de erp_nav.php le ponga el _csrf; por eso hay que añadirlo a mano, o el POST
     se rechaza con «La sesión ha caducado». */
  var tok=(document.querySelector('meta[name="csrf-token"]')||{}).content||'';
  var f=document.createElement('form');f.method='post';f.style.display='none';
  f.innerHTML='<input type="hidden" name="_csrf" value="'+escHtml(tok)+'"><input type="hidden" name="action" value="delete"><input type="hidden" name="event_id" value="'+escHtml(id)+'">';
  document.body.appendChild(f);f.submit();
}

/* ---- Autocompletado de invitados ---- */
var _reuSel=-1;
function reuInvTokens(){return document.getElementById('reuInv').value.split(',');}
function reuInvType(){
  var inp=document.getElementById('reuInv');
  var toks=inp.value.split(','); var cur=(toks[toks.length-1]||'').trim().toLowerCase();
  var pop=document.getElementById('reuInvPop');
  if(cur.length<1){ reuInvClose(); return; }
  var yaPuestos=toks.slice(0,-1).map(function(t){return t.trim().toLowerCase();});
  var _pool=(window.erpEmailMem?erpEmailMem.pool(window.REU_EMAILS||[]):(window.REU_EMAILS||[]));
  var m=_pool.filter(function(em){return em.indexOf(cur)>-1 && yaPuestos.indexOf(em)<0;}).slice(0,6);
  if(!m.length){ reuInvClose(); return; }
  _reuSel=-1;
  pop.innerHTML=m.map(function(em,i){return '<button type="button" data-i="'+i+'" onmousedown="event.preventDefault();reuInvPick('+i+')">'+escHtml(em)+'</button>';}).join('');
  pop.dataset.opts=JSON.stringify(m);
  pop.classList.add('on');
}
function reuInvPick(i){
  var pop=document.getElementById('reuInvPop');var opts=[];try{opts=JSON.parse(pop.dataset.opts||'[]');}catch(_){}
  var em=opts[i]; if(!em) return;
  var inp=document.getElementById('reuInv');var toks=inp.value.split(',');toks[toks.length-1]=' '+em;
  inp.value=toks.join(',').replace(/^\s+/,'')+', ';
  reuInvClose(); inp.focus();
}
function reuInvKey(e){
  var pop=document.getElementById('reuInvPop'); if(!pop.classList.contains('on')) return;
  var btns=pop.querySelectorAll('button');
  if(e.key==='ArrowDown'){e.preventDefault();_reuSel=Math.min(_reuSel+1,btns.length-1);}
  else if(e.key==='ArrowUp'){e.preventDefault();_reuSel=Math.max(_reuSel-1,0);}
  else if(e.key==='Enter'&&_reuSel>=0){e.preventDefault();reuInvPick(_reuSel);return;}
  else if(e.key==='Escape'){reuInvClose();return;}
  else return;
  btns.forEach(function(b,i){b.classList.toggle('sel',i===_reuSel);});
}
function reuInvClose(){var p=document.getElementById('reuInvPop');if(p){p.classList.remove('on');p.innerHTML='';}}
/* Recuerda los correos escritos para la próxima vez (memoria de autocompletado). */
(function(){var f=document.getElementById('reuForm');if(f)f.addEventListener('submit',function(){var i=document.getElementById('reuInv');if(i&&window.erpEmailMem)erpEmailMem.add(i.value);});})();

document.addEventListener('keydown',function(e){if(e.key==='Escape'){reuNew(false);reuPopClose();var m=document.getElementById('reuCtx');if(m)m.classList.remove('on');}});

/* ---- Popup de asignar cliente por tipo (#4) ---- */
var _reuEv=null;
function reuAsignar(e,evId){e.stopPropagation();_reuEv=evId;var pop=document.getElementById('reuPop');
  reuPopRender('');
  var r=e.currentTarget.getBoundingClientRect();
  pop.style.left=Math.min(r.left,window.innerWidth-292)+'px';
  pop.style.top=Math.min(r.bottom+6,window.innerHeight-352)+'px';
  pop.classList.add('on');
  var s=document.getElementById('reuPopSearch');s.value='';setTimeout(function(){s.focus();},40);}
function reuPopClose(){var p=document.getElementById('reuPop');if(p)p.classList.remove('on');}
function reuPopFilter(){reuPopRender(document.getElementById('reuPopSearch').value.toLowerCase());}
function reuPopRender(q){var box=document.getElementById('reuPopList');var h='<button type="button" class="opt clear" onclick="reuAssignPick(0)">✕ Sin cliente</button>';
  (window.REU_GROUPS||[]).forEach(function(g){
    var items=g.items.filter(function(c){return !q||(c.nombre+' '+c.empresa).toLowerCase().indexOf(q)>-1;});
    if(!items.length)return;
    h+='<div class="glbl">'+escHtml(g.label)+'</div>';
    items.forEach(function(c){var ini=(c.nombre||'?').trim().slice(0,2).toUpperCase();
      h+='<button type="button" class="opt" onclick="reuAssignPick('+c.id+')"><span class="av" style="background:'+(window.avatarColor?avatarColor(c.nombre):'#8b9099')+'">'+escHtml(ini)+'</span><span class="nm">'+escHtml(c.nombre)+(c.empresa?(' <small>· '+escHtml(c.empresa)+'</small>'):'')+'</span></button>';});
  });
  box.innerHTML=h;}
function reuAssignPick(cid){if(!_reuEv)return;
  fetch('reuniones.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({event_id:_reuEv,contact_id:cid})}).then(function(r){return r.json();}).then(function(){
    if(window.toast)toast(cid?'Reunión asignada ✓':'Asignación quitada');reuPopClose();setTimeout(function(){location.reload();},300);});}
document.addEventListener('click',function(e){if(!e.target.closest('#reuPop')&&!e.target.closest('.reu-asbtn'))reuPopClose();});
reuMonthPop();
</script>

<?php if (isset($_GET['flash']) && $_GET['flash']!==''): ?>
<script>window.addEventListener('load',function(){ if(window.toast)toast(<?= json_encode((string)$_GET['flash']) ?><?= (($_GET['fok']??'1')==='1')?'':", 'err'" ?>); });</script>
<?php endif; ?>

<?php erp_foot(); ?>
