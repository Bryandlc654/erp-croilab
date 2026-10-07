<?php
/* =====================================================================
   Integración con Google Calendar (OAuth2 + Calendar API v3).

   - Las credenciales del proyecto (Client ID / Client Secret) son globales
     y las pega el DUEÑO en Ajustes → Google Calendar.
   - Cada usuario del ERP conecta SU PROPIA cuenta de Google (tokens por usuario).
   - Sirve para: ver los eventos/reuniones de Google dentro del calendario del
     ERP y crear eventos nuevos desde ahí.

   No guarda nada raro: usa la tabla `settings` que ya existe.
   ===================================================================== */

if (!function_exists('gc_set')) {
function gc_set($k, $v){ try{ db()->prepare('INSERT INTO settings (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')->execute([$k, $v]); }catch(Exception $e){} }
}
function gc_del($k){ try{ db()->prepare('DELETE FROM settings WHERE clave=?')->execute([$k]); }catch(Exception $e){} }

function gcal_client_id(){ return trim((string)get_setting('gcal_client_id','')); }
function gcal_client_secret(){ return trim((string)get_setting('gcal_client_secret','')); }
/* ¿Ha puesto el dueño las credenciales del proyecto? */
function gcal_configured(){ return gcal_client_id()!=='' && gcal_client_secret()!==''; }

/* URI de redirección OAuth. DEBE coincidir EXACTAMENTE con la registrada en Google
   Cloud. Es estable porque siempre apunta al mismo archivo dentro de /admin/. */
function gcal_redirect_uri(){
  $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS'])!=='off') || (($_SERVER['SERVER_PORT']??'')=='443') || (($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
  $scheme = $https ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $dir = str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/calendar.php'));
  if ($dir==='/'||$dir==='.') $dir='';
  return $scheme.'://'.$host.$dir.'/gcal_callback.php';
}

/* Clave donde se guardan los tokens de un usuario. */
function gcal_tok_key($adminId){ return 'gcal_tok_'.(int)$adminId; }
/* Cifrado en reposo de los tokens (AES-256-CBC). Los tokens son como
   contraseñas: no deben quedar en claro en la base de datos.

   La clave sale de gcal_key_material():
     · Si hay un APP_SECRET PROPIO (el que se pone en el servidor) se usa ese
       → comportamiento de siempre, sin cambios.
     · Si APP_SECRET sigue siendo el de EJEMPLO (o está vacío), el código genera
       UNA clave aleatoria fuerte y la guarda en `settings` (clave 'gcal_key').
       Así el "cifrado en reposo" nunca depende de una clave pública, aunque en
       el servidor se olvide poner APP_SECRET.
   gcal_dec prueba primero la clave actual y, si no, la HEREDADA (APP_SECRET), para
   que los tokens ya guardados sigan descifrándose y nadie tenga que reconectar. */
function gcal_secret_ejemplo(){ return 'cambia-esto-por-algo-largo-y-unico-2026'; } // el de config.php
function gcal_key_material(){
  $s = defined('APP_SECRET') ? (string)APP_SECRET : '';
  if ($s !== '' && $s !== gcal_secret_ejemplo()) return $s;   // secreto propio: se respeta
  $k = get_setting('gcal_key','');
  if ($k === '') { $k = bin2hex(random_bytes(32)); gc_set('gcal_key', $k); }
  return $k;
}
function gcal_secret(){ return hash('sha256', gcal_key_material().'|gcal', true); }
/* Clave heredada, por si hay tokens cifrados con el APP_SECRET (de ejemplo o real)
   o con el viejo 'croilab-fallback'. */
function gcal_secret_legacy(){ return [
  hash('sha256', (defined('APP_SECRET')?APP_SECRET:'croilab-fallback').'|gcal', true),
  hash('sha256', 'croilab-fallback|gcal', true),
]; }
function gcal_enc($plain){ if(!function_exists('openssl_encrypt')) return $plain; $iv=random_bytes(16); $c=openssl_encrypt((string)$plain,'aes-256-cbc',gcal_secret(),OPENSSL_RAW_DATA,$iv); return 'enc:'.base64_encode($iv.$c); }
function gcal_dec($stored){
  if(strpos((string)$stored,'enc:')!==0) return $stored;
  if(!function_exists('openssl_decrypt')) return '';
  $raw=base64_decode(substr($stored,4)); if($raw===false||strlen($raw)<17) return '';
  $iv=substr($raw,0,16); $c=substr($raw,16);
  /* Prueba la clave actual y luego las heredadas: así no se pierde ningún token. */
  foreach(array_merge([gcal_secret()], gcal_secret_legacy()) as $key){
    $p=openssl_decrypt($c,'aes-256-cbc',$key,OPENSSL_RAW_DATA,$iv);
    if($p!==false) return $p;
  }
  return '';
}
function gcal_save_tokens($adminId,$tok){ gc_set(gcal_tok_key($adminId), gcal_enc(json_encode($tok))); }
function gcal_tokens($adminId){ $raw=get_setting(gcal_tok_key($adminId),''); if($raw==='') return null;
  $wasEnc = strpos((string)$raw,'enc:')===0; $json=gcal_dec($raw); $d=json_decode((string)$json,true); if(!is_array($d)) return null;
  if(!$wasEnc){ gcal_save_tokens($adminId,$d); } // migra en caliente los que estaban en claro
  return $d; }
function gcal_connected($adminId){ $t=gcal_tokens($adminId); return $t && !empty($t['refresh_token']); }
function gcal_revoked($adminId){ return get_setting('gcal_revoked_'.(int)$adminId,'')==='1'; }
function gcal_email($adminId){ $t=gcal_tokens($adminId); return $t['email']??''; }
function gcal_disconnect($adminId){ gc_del(gcal_tok_key($adminId)); }

/* ---- HTTP con cURL (código del servidor, no una búsqueda web). ---- */
function gc_http($method, $url, $headers=[], $body=null){
  $ch=curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST=>$method,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>$headers,
    CURLOPT_TIMEOUT=>15,
    CURLOPT_CONNECTTIMEOUT=>8,
  ]);
  if ($body!==null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  $out=curl_exec($ch); $code=(int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
  return ['code'=>$code, 'body'=>$out, 'err'=>$err, 'json'=>json_decode((string)$out, true)];
}

/* ---- Flujo OAuth ---- */
function gcal_auth_url($state){
  $params = http_build_query([
    'client_id'=>gcal_client_id(),
    'redirect_uri'=>gcal_redirect_uri(),
    'response_type'=>'code',
    'scope'=>'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly',
    'access_type'=>'offline',
    'include_granted_scopes'=>'true',
    'prompt'=>'consent',
    'state'=>$state,
  ]);
  return 'https://accounts.google.com/o/oauth2/v2/auth?'.$params;
}

/* Intercambia el «code» por tokens y los guarda para $adminId. Devuelve true/false. */
function gcal_exchange_code($code, $adminId){
  $r = gc_http('POST', 'https://oauth2.googleapis.com/token',
    ['Content-Type: application/x-www-form-urlencoded'],
    http_build_query([
      'code'=>$code,
      'client_id'=>gcal_client_id(),
      'client_secret'=>gcal_client_secret(),
      'redirect_uri'=>gcal_redirect_uri(),
      'grant_type'=>'authorization_code',
    ]));
  $j=$r['json']; if(!$j || empty($j['access_token'])) return false;
  $tok = [
    'access_token'=>$j['access_token'],
    'refresh_token'=>$j['refresh_token'] ?? (gcal_tokens($adminId)['refresh_token'] ?? ''),
    'expiry'=>time() + (int)($j['expires_in'] ?? 3600) - 60,
    'email'=>'',
  ];
  /* Correo de la cuenta conectada (para mostrarlo). */
  $u = gc_http('GET', 'https://www.googleapis.com/oauth2/v2/userinfo', ['Authorization: Bearer '.$tok['access_token']]);
  if (!empty($u['json']['email'])) $tok['email']=$u['json']['email'];
  gcal_save_tokens($adminId, $tok);
  return true;
}

/* ---- «Entrar con Google» (solo identidad, no calendario) ----
   Reutiliza el MISMO cliente OAuth y el MISMO redirect (gcal_callback.php) para no tener
   que registrar otra URI en Google Cloud. Solo pide correo/perfil: no toca el calendario. */
function gcal_login_auth_url($state){
  $params = http_build_query([
    'client_id'=>gcal_client_id(),
    'redirect_uri'=>gcal_redirect_uri(),
    'response_type'=>'code',
    'scope'=>'openid email profile',
    'prompt'=>'select_account',
    'state'=>$state,
  ]);
  return 'https://accounts.google.com/o/oauth2/v2/auth?'.$params;
}
/* Canjea el «code» de un login por el correo de la cuenta Google. No guarda tokens
   (el usuario aún no es un admin de sesión). Devuelve el email en minúsculas o ''. */
function gcal_email_from_code($code){
  $r = gc_http('POST', 'https://oauth2.googleapis.com/token',
    ['Content-Type: application/x-www-form-urlencoded'],
    http_build_query([
      'code'=>$code,
      'client_id'=>gcal_client_id(),
      'client_secret'=>gcal_client_secret(),
      'redirect_uri'=>gcal_redirect_uri(),
      'grant_type'=>'authorization_code',
    ]));
  $j=$r['json']; if(!$j || empty($j['access_token'])) return '';
  $u = gc_http('GET', 'https://www.googleapis.com/oauth2/v2/userinfo', ['Authorization: Bearer '.$j['access_token']]);
  $email = $u['json']['email'] ?? '';
  /* Solo aceptamos correos verificados por Google. */
  if($email==='' || (isset($u['json']['verified_email']) && !$u['json']['verified_email'])) return '';
  return strtolower(trim($email));
}

/* Devuelve un access_token válido (refrescándolo si hace falta) o '' si no se puede. */
function gcal_access_token($adminId){
  $t=gcal_tokens($adminId); if(!$t) return '';
  if (!empty($t['access_token']) && ($t['expiry']??0) > time()) return $t['access_token'];
  if (empty($t['refresh_token'])) return '';
  $r = gc_http('POST', 'https://oauth2.googleapis.com/token',
    ['Content-Type: application/x-www-form-urlencoded'],
    http_build_query([
      'client_id'=>gcal_client_id(),
      'client_secret'=>gcal_client_secret(),
      'refresh_token'=>$t['refresh_token'],
      'grant_type'=>'refresh_token',
    ]));
  $j=$r['json'];
  if(!$j || empty($j['access_token'])){
    /* Google ha revocado el permiso: marca la cuenta para pedir reconexión y no reintentar en bucle. */
    if(($j['error']??'')==='invalid_grant') gc_set('gcal_revoked_'.(int)$adminId,'1');
    return '';
  }
  gc_set('gcal_revoked_'.(int)$adminId,'0'); // ok: limpia cualquier marca previa
  $t['access_token']=$j['access_token']; $t['expiry']=time()+(int)($j['expires_in']??3600)-60;
  gcal_save_tokens($adminId, $t);
  return $t['access_token'];
}

/* ---- API de calendario ---- */
/* Lista eventos entre dos fechas (YYYY-MM-DD). Devuelve array normalizado. */
function gcal_events($adminId, $desde, $hasta){
  $at=gcal_access_token($adminId); if($at==='') return [];
  $qs=http_build_query([
    'timeMin'=>$desde.'T00:00:00Z',
    'timeMax'=>$hasta.'T23:59:59Z',
    'singleEvents'=>'true',
    'orderBy'=>'startTime',
    'maxResults'=>'250',
  ]);
  $r=gc_http('GET', 'https://www.googleapis.com/calendar/v3/calendars/primary/events?'.$qs,
    ['Authorization: Bearer '.$at]);
  $items=$r['json']['items'] ?? []; $out=[];
  foreach($items as $ev){
    if(($ev['status']??'')==='cancelled') continue;
    $start=$ev['start']['dateTime'] ?? ($ev['start']['date'] ?? '');
    $end  =$ev['end']['dateTime'] ?? ($ev['end']['date'] ?? '');
    $allDay=empty($ev['start']['dateTime']);
    $day=substr($start,0,10); if($day==='') continue;
    $hora = $allDay ? '' : date('H:i', strtotime($ev['start']['dateTime']));
    $horaFin = $allDay ? '' : date('H:i', strtotime($ev['end']['dateTime'] ?? $start));
    /* Editable si eres el organizador (para invitados solo mostramos «ver en Google»). */
    $editable = !empty($ev['organizer']['self']) || !empty($ev['creator']['self']);
    $invitados=[]; foreach(($ev['attendees']??[]) as $a){ if(!empty($a['email']) && empty($a['self'])) $invitados[]=$a['email']; }
    $out[]=[
      'id'=>$ev['id']??'',
      'titulo'=>$ev['summary'] ?? '(sin título)',
      'dia'=>$day,
      'hora'=>$hora,
      'hora_fin'=>$horaFin,
      'ini'=>$start,
      'fin'=>$end,
      'allday'=>$allDay,
      'editable'=>$editable,
      'invitados'=>implode(', ', $invitados),
      'location'=>$ev['location'] ?? '',
      'descripcion'=>$ev['description'] ?? '',
      'meet'=>!empty($ev['hangoutLink']) || !empty($ev['conferenceData']),
      'recurring'=>!empty($ev['recurringEventId']),
      'masterId'=>$ev['recurringEventId'] ?? '',
      'link'=>$ev['htmlLink'] ?? '',
    ];
  }
  return $out;
}

/* Busca el evento de Google enlazado a una reunión del CRM (por extendedProperties)
   y devuelve las NOTAS que Gemini adjunta tras la reunión (un Google Doc) + la
   descripción del evento. Devuelve null si no hay evento o no hay conexión.
   Con el permiso de calendario basta: el adjunto (enlace al Doc) viene en el propio evento. */
function gcal_meeting_notes($adminId, $mid){
  $at=gcal_access_token($adminId); if($at==='') return null;
  $mid=trim((string)$mid); if($mid==='') return null;
  $qs=http_build_query([
    'privateExtendedProperty'=>'erp_meeting='.$mid,
    'singleEvents'=>'true',
    'maxResults'=>'5',
  ]);
  $r=gc_http('GET','https://www.googleapis.com/calendar/v3/calendars/primary/events?'.$qs,
    ['Authorization: Bearer '.$at]);
  $items=$r['json']['items'] ?? [];
  foreach($items as $it){ if(($it['status']??'')!=='cancelled'){ $items=[$it]; break; } }
  if(!$items) return ['found'=>false,'docs'=>[],'descripcion'=>'','link'=>''];
  $ev=$items[0];
  $docs=[];
  foreach(($ev['attachments']??[]) as $a){
    $docs[]=['title'=>$a['title'] ?? 'Notas de la reunión', 'url'=>$a['fileUrl'] ?? ''];
  }
  return [
    'found'=>true,
    'docs'=>$docs,                                  // adjuntos (las notas de Gemini son un Google Doc)
    'descripcion'=>trim((string)($ev['description'] ?? '')),
    'link'=>$ev['htmlLink'] ?? '',
    'titulo'=>$ev['summary'] ?? '',
  ];
}

/* Lista los eventos de un rango pensados como REUNIONES (para la página central de
   Reuniones): devuelve por evento sus invitados (correos, para emparejar con un cliente)
   y sus adjuntos (el Doc de notas de Gemini). $desde/$hasta = YYYY-MM-DD. */
function gcal_meetings_range($adminId, $desde, $hasta){
  $at=gcal_access_token($adminId); if($at==='') return [];
  $qs=http_build_query([
    'timeMin'=>$desde.'T00:00:00Z','timeMax'=>$hasta.'T23:59:59Z',
    'singleEvents'=>'true','orderBy'=>'startTime','maxResults'=>'250',
  ]);
  $r=gc_http('GET','https://www.googleapis.com/calendar/v3/calendars/primary/events?'.$qs,
    ['Authorization: Bearer '.$at]);
  $items=$r['json']['items'] ?? []; $out=[];
  foreach($items as $ev){
    if(($ev['status']??'')==='cancelled') continue;
    $start=$ev['start']['dateTime'] ?? ($ev['start']['date'] ?? '');
    $day=substr($start,0,10); if($day==='') continue;
    $allDay=empty($ev['start']['dateTime']);
    $emails=[]; foreach(($ev['attendees']??[]) as $a){ if(!empty($a['email']) && empty($a['self'])) $emails[]=strtolower(trim($a['email'])); }
    $docs=[]; foreach(($ev['attachments']??[]) as $a){ $docs[]=['title'=>$a['title'] ?? 'Notas de la reunión','url'=>$a['fileUrl'] ?? '']; }
    $meet=!empty($ev['hangoutLink']) || !empty($ev['conferenceData']);
    /* Recordatorio actual: '' = predeterminado de Google; 'no' = ninguno; número
       = minutos antes del primer aviso propio. Sirve para pre-rellenar la edición. */
    $recordar='';
    if(isset($ev['reminders']) && empty($ev['reminders']['useDefault'])){
      if(!empty($ev['reminders']['overrides'])) $recordar=(string)($ev['reminders']['overrides'][0]['minutes'] ?? '');
      else $recordar='no';
    }
    /* Solo interesan las REUNIONES: con invitados, con Meet o con notas adjuntas. */
    if(!$emails && !$meet && !$docs) continue;
    $out[]=[
      'id'=>$ev['id']??'',
      'titulo'=>$ev['summary'] ?? '(sin título)',
      'dia'=>$day,
      'hora'=>$allDay?'':date('H:i', strtotime($ev['start']['dateTime'])),
      'hora_fin'=>($allDay || empty($ev['end']['dateTime']))?'':date('H:i', strtotime($ev['end']['dateTime'])),
      'ini'=>$start,
      'emails'=>$emails,
      'docs'=>$docs,
      'meet'=>$meet,
      'recordar'=>$recordar,
      'link'=>$ev['htmlLink'] ?? '',
      'erp_meeting'=>$ev['extendedProperties']['private']['erp_meeting'] ?? '',
    ];
  }
  return $out;
}

/* Cómo avisar a los invitados: si $o trae la clave 'notificar', manda; si no, se
   comporta como siempre (avisar cuando hay invitados). Es lo que da la casilla de
   «avisar por correo» al crear/editar, igual que la de Google Calendar. */
function gcal_sendmode($o, $hasAts){
  if (array_key_exists('notificar', (array)$o)) return !empty($o['notificar']) ? 'all' : 'none';
  return $hasAts ? 'all' : 'none';
}

/* Edita un evento existente (PATCH). $o: mismo array que crear. */
function gcal_update_event($adminId, $id, $o){
  $at=gcal_access_token($adminId); if($at==='') return [false,'No conectado a Google.'];
  $id=trim($id); if($id==='') return [false,'Falta el evento.'];
  if(trim((string)($o['titulo']??''))==='') return [false,'Falta el título.'];
  list($body,$hasAts)=gcal_build_body($o);
  $qs = $hasAts ? ('?sendUpdates='.gcal_sendmode($o,$hasAts)) : '';
  $r=gc_http('PATCH','https://www.googleapis.com/calendar/v3/calendars/primary/events/'.rawurlencode($id).$qs,
    ['Authorization: Bearer '.$at,'Content-Type: application/json'], json_encode($body));
  if($r['code']>=200 && $r['code']<300) return [true,'Evento actualizado.'];
  return [false, $r['json']['error']['message'] ?? 'Error al actualizar el evento.'];
}

/* Borra un evento. */
function gcal_delete_event($adminId, $id){
  $at=gcal_access_token($adminId); if($at==='') return [false,'No conectado a Google.'];
  $id=trim($id); if($id==='') return [false,'Falta el evento.'];
  $r=gc_http('DELETE','https://www.googleapis.com/calendar/v3/calendars/primary/events/'.rawurlencode($id).'?sendUpdates=all',
    ['Authorization: Bearer '.$at]);
  if($r['code']>=200 && $r['code']<300 || $r['code']===410) return [true,'Evento eliminado.'];
  return [false, $r['json']['error']['message'] ?? 'Error al eliminar el evento.'];
}

/* Crea un evento. $fecha=YYYY-MM-DD, $hora='' (todo el día) o 'HH:MM'.
   $invitados = lista de correos (separados por coma) a los que se invita: así el
   evento les aparece en SU Google Calendar (y en su calendario del ERP). Devuelve [ok,msg]. */
/* Monta el cuerpo del evento a partir de un array de opciones ($o):
   titulo, fecha (AAAA-MM-DD), hora ('' = todo el día) / hora_fin, invitados (coma),
   meet (bool), location, descripcion, recur ('DAILY'|'WEEKLY'|'MONTHLY'|''). */
function gcal_build_body($o){
  $titulo=trim((string)($o['titulo']??'')); $fecha=$o['fecha']??''; $hora=$o['hora']??''; $horaFin=$o['hora_fin']??'';
  $body=['summary'=>$titulo];
  if($hora!==''){
    $tz=date_default_timezone_get() ?: 'Europe/Madrid';
    $ini=$fecha.'T'.$hora.':00';
    $fin=$horaFin!=='' ? $fecha.'T'.$horaFin.':00' : date('Y-m-d\TH:i:s', strtotime($ini.' +60 minutes'));
    if(strtotime($fin)<=strtotime($ini)) $fin=date('Y-m-d\TH:i:s', strtotime($ini.' +60 minutes'));
    $body['start']=['dateTime'=>$ini,'timeZone'=>$tz]; $body['end']=['dateTime'=>$fin,'timeZone'=>$tz];
  } else {
    $body['start']=['date'=>$fecha]; $body['end']=['date'=>date('Y-m-d', strtotime($fecha.' +1 day'))];
  }
  if(!empty($o['location'])) $body['location']=trim($o['location']);
  if(!empty($o['descripcion'])) $body['description']=trim($o['descripcion']);
  if(!empty($o['erp_meeting'])) $body['extendedProperties']=['private'=>['erp_meeting'=>(string)$o['erp_meeting']]]; // enlaza con la reunión del CRM
  if(!empty($o['gemini'])){ // Google no deja activar Gemini por API: se deja recordatorio en la descripción
    $g='📝 En la reunión de Meet, pulsa «Tomar notas por mí» (Gemini) para el resumen automático.';
    $body['description']=trim((($body['description']??'')!=='') ? ($body['description']."\n\n".$g) : $g);
  }
  $recur=strtoupper((string)($o['recur']??'')); if(in_array($recur,['DAILY','WEEKLY','MONTHLY'],true)) $body['recurrence']=['RRULE:FREQ='.$recur];
  /* Recordatorio: el aviso que salta X minutos antes (como en Google Calendar).
     '' = no tocar (deja el predeterminado de Google); 'no' = sin recordatorio;
     un número = una notificación (popup) a esos minutos. En un PATCH, '' hace que
     no se incluya el campo, así que editar sin tocarlo NO borra el recordatorio. */
  if(array_key_exists('recordar',(array)$o)){
    $rec=(string)$o['recordar'];
    if($rec==='no')            $body['reminders']=['useDefault'=>false,'overrides'=>[]];
    elseif(ctype_digit($rec))  $body['reminders']=['useDefault'=>false,'overrides'=>[['method'=>'popup','minutes'=>(int)$rec]]];
  }
  $ats=[]; foreach(preg_split('/[,\s]+/', (string)($o['invitados']??'')) as $em){ $em=trim($em); if($em!=='' && filter_var($em, FILTER_VALIDATE_EMAIL)) $ats[]=['email'=>$em]; }
  if($ats) $body['attendees']=$ats;
  return [$body, count($ats)>0];
}
function gcal_create_event($adminId, $o){
  $at=gcal_access_token($adminId); if($at==='') return [false,'No conectado a Google.'];
  if(trim((string)($o['titulo']??''))==='') return [false,'Falta el título.'];
  list($body,$hasAts)=gcal_build_body($o);
  $conf=0; if(!empty($o['meet'])){ $body['conferenceData']=['createRequest'=>['requestId'=>bin2hex(random_bytes(8)),'conferenceSolutionKey'=>['type'=>'hangoutsMeet']]]; $conf=1; }
  $q=[]; if($hasAts)$q[]='sendUpdates='.gcal_sendmode($o,$hasAts); if($conf)$q[]='conferenceDataVersion=1'; $qs=$q?('?'.implode('&',$q)):'';
  $r=gc_http('POST','https://www.googleapis.com/calendar/v3/calendars/primary/events'.$qs,
    ['Authorization: Bearer '.$at,'Content-Type: application/json'], json_encode($body));
  if($r['code']>=200 && $r['code']<300) return [true, (!empty($o['meet'])?'Reunión creada con Google Meet.':($hasAts?'Evento creado e invitaciones enviadas.':'Evento creado.'))];
  return [false, $r['json']['error']['message'] ?? 'Error al crear el evento.'];
}
/* Mueve un evento a otro día/hora (PATCH solo de fechas: conserva título e invitados). */
function gcal_move_event($adminId, $id, $fecha, $hora='', $horaFin=''){
  $at=gcal_access_token($adminId); if($at==='') return [false,'No conectado a Google.'];
  $id=trim($id); if($id==='') return [false,'Falta el evento.'];
  if($hora!==''){
    $tz=date_default_timezone_get() ?: 'Europe/Madrid';
    $ini=$fecha.'T'.$hora.':00';
    $fin=$horaFin!==''?$fecha.'T'.$horaFin.':00':date('Y-m-d\TH:i:s',strtotime($ini.' +1 hour'));
    $body=['start'=>['dateTime'=>$ini,'timeZone'=>$tz],'end'=>['dateTime'=>$fin,'timeZone'=>$tz]];
  } else {
    $body=['start'=>['date'=>$fecha],'end'=>['date'=>date('Y-m-d', strtotime($fecha.' +1 day'))]];
  }
  $r=gc_http('PATCH','https://www.googleapis.com/calendar/v3/calendars/primary/events/'.rawurlencode($id),
    ['Authorization: Bearer '.$at,'Content-Type: application/json'], json_encode($body));
  if($r['code']>=200 && $r['code']<300) return [true,'Evento movido.'];
  return [false, $r['json']['error']['message'] ?? 'Error al mover el evento.'];
}
