<?php
/* Endpoint compartido para AGENDAR una reunión desde cualquier pantalla (ficha de
   cliente, CRM…). Crea la reunión en el CRM si viene un contacto, y el evento en
   Google Calendar (con Meet) enlazado. Responde JSON. No navega: lo llama un popup. */
require_once __DIR__ . '/../auth.php';
require_admin();
require_can_edit();
require_once __DIR__ . '/lib/gcal.php';
require_once __DIR__ . '/lib/crm_lib.php';

header('Content-Type: application/json');
$me = current_admin(); $meId = (int)$me['id'];

$titulo    = trim($_POST['titulo'] ?? '');
$fecha     = trim($_POST['fecha'] ?? '');
$hora      = trim($_POST['hora'] ?? '');
$invitados = trim($_POST['invitados'] ?? '');
$meet      = ($_POST['meet'] ?? '1') === '1';
$contactId = (int)($_POST['contact_id'] ?? 0);

/* Fecha: acepta dd/mm/aaaa o aaaa-mm-dd (input date nativo). */
$iso = '';
if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $fecha, $m)) $iso = "$m[3]-$m[2]-$m[1]";
elseif (preg_match('#^\d{4}-\d{2}-\d{2}$#', $fecha)) $iso = $fecha;
if ($titulo === '' || $iso === '') { echo json_encode(['ok'=>0,'msg'=>'Falta el título o la fecha.']); exit; }

/* Reunión del CRM (para verla en la ficha del contacto y poder registrar su resultado). */
$mid = 0;
if ($contactId > 0) {
  crm_meetings_ensure();
  db()->prepare("INSERT INTO crm_meetings (contact_id,fecha,hora,estado) VALUES (?,?,?,'agendada')")->execute([$contactId,$iso,$hora]);
  $mid = (int)db()->lastInsertId();
  crm_activity($contactId, null, 'reunion', 'Reunión agendada para '.date('d/m/Y', strtotime($iso)).($hora?' '.$hora:''));
  db()->prepare('UPDATE contacts SET fecha_ultimo_contacto=CURDATE() WHERE id=?')->execute([$contactId]);
}

/* Evento en Google Calendar. */
$msg = 'Reunión agendada.';
if (gcal_connected($meId) && !gcal_revoked($meId)) {
  $o = ['titulo'=>$titulo,'fecha'=>$iso,'hora'=>$hora,'hora_fin'=>'','invitados'=>$invitados,'meet'=>$meet,'gemini'=>($_POST['gemini']??'')==='1',
        'notificar'=>!empty($_POST['notificar']), 'recordar'=>trim($_POST['recordar'] ?? '')];
  if ($mid) $o['erp_meeting'] = $mid;
  list($ok, $gmsg) = gcal_create_event($meId, $o);
  $msg = $ok ? $gmsg : ('Reunión guardada, pero Google dio un aviso: '.$gmsg);
} else {
  $msg = 'Reunión guardada. (Conecta Google Calendar en Integraciones para crear el evento.)';
}

echo json_encode(['ok'=>1, 'mid'=>$mid, 'msg'=>$msg]);
exit;
