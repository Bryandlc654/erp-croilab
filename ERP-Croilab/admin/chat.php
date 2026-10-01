<?php
/* Chat de equipo: grupos y mensajes directos. Funcional con polling AJAX. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/imagen.php';   // reduce las fotos subidas (ahorro de espacio)

$me = current_admin(); $meId = (int)$me['id'];

/* --- Esquema propio del chat (idempotente) --- */
db()->exec("CREATE TABLE IF NOT EXISTS chat_rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) DEFAULT '',
  type VARCHAR(10) NOT NULL DEFAULT 'group',
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->exec("CREATE TABLE IF NOT EXISTS chat_members (
  room_id INT NOT NULL, admin_id INT NOT NULL, last_read INT NOT NULL DEFAULT 0,
  PRIMARY KEY(room_id, admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->exec("CREATE TABLE IF NOT EXISTS chat_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL, admin_id INT NOT NULL, body TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
/* Indicador «escribiendo…»: cada quien renueva su marca mientras teclea. */
db()->exec("CREATE TABLE IF NOT EXISTS chat_typing (
  room_id INT NOT NULL, admin_id INT NOT NULL, until_ts INT NOT NULL DEFAULT 0,
  PRIMARY KEY(room_id, admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
/* Reacciones (emoji) a un mensaje, una por persona y emoji. */
db()->exec("CREATE TABLE IF NOT EXISTS chat_reactions (
  message_id INT NOT NULL, admin_id INT NOT NULL, emoji VARCHAR(16) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(message_id, admin_id, emoji)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
/* (La tabla chat_presence y sus funciones viven ahora en erp_nav.php, compartidas.) */
/* Columnas nuevas de los mensajes: responder, editado, borrado y adjuntos. */
foreach(['reply_to'=>"INT DEFAULT NULL",'edited'=>"TINYINT NOT NULL DEFAULT 0",'deleted'=>"TINYINT NOT NULL DEFAULT 0",'attach'=>"TEXT DEFAULT NULL"] as $col=>$def){
  try{ if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_messages' AND column_name='$col'")->fetchColumn()) db()->exec("ALTER TABLE chat_messages ADD COLUMN $col $def"); }catch(Exception $e){}
}

$admins = db()->query('SELECT id, username FROM admins ORDER BY username')->fetchAll();
$adminName = []; foreach ($admins as $a) $adminName[$a['id']] = $a['username'];
$namesLower = array_map(fn($n)=>mb_strtolower($n), array_values($adminName));

function chat_dm_room($meId, $otherId) {
  // busca sala dm con exactamente estos dos miembros
  $q = db()->prepare("SELECT r.id FROM chat_rooms r
      JOIN chat_members m1 ON m1.room_id=r.id AND m1.admin_id=?
      JOIN chat_members m2 ON m2.room_id=r.id AND m2.admin_id=?
      WHERE r.type='dm' LIMIT 1");
  $q->execute([$meId, $otherId]);
  $rid = (int)$q->fetchColumn();
  if ($rid) return $rid;
  db()->prepare("INSERT INTO chat_rooms (name,type,created_by) VALUES ('','dm',?)")->execute([$meId]);
  $rid = (int)db()->lastInsertId();
  $ins = db()->prepare('INSERT IGNORE INTO chat_members (room_id,admin_id) VALUES (?,?)');
  $ins->execute([$rid,$meId]); $ins->execute([$rid,$otherId]);
  return $rid;
}
function chat_is_member($rid,$meId){ $q=db()->prepare('SELECT 1 FROM chat_members WHERE room_id=? AND admin_id=?'); $q->execute([$rid,$meId]); return (bool)$q->fetchColumn(); }

/* ---- Helpers de mensajes ricos (adjuntos, reacciones, presencia, formato) ---- */
$CHAT_ATT_OK = ['jpg','jpeg','png','gif','webp','avif','bmp','pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip','rar','mp4','mov','webm','mp3','ogg','wav','m4a'];
function chat_is_img($fn){ return in_array(strtolower(pathinfo($fn,PATHINFO_EXTENSION)),['jpg','jpeg','png','gif','webp','avif','bmp'],true); }

/* Emoji admitidos como reaccion. Esta lista es la autoridad: la del cliente
   (EMOJI_SET) es solo una ayuda visual y se puede saltar con una peticion
   directa. Generada a partir de ella, para que no se separen.
   Sin esta lista, el emoji se guardaba tal cual y se volcaba en el HTML de
   cada visitante (XSS almacenado) y dentro de un onclick. */
function chat_emoji_ok($em){
  static $set = null;
  if ($set === null) {
    $set = array_flip([
      '😀',      '😁',      '😂',      '🤣',
      '😊',      '😍',      '😘',      '😎',
      '🤩',      '🥳',      '😉',      '🙂',
      '🙃',      '😅',      '😇',      '🤗',
      '🤔',      '🤨',      '😐',      '😴',
      '😌',      '😜',      '🤪',      '😝',
      '😏',      '😒',      '🙄',      '😤',
      '😡',      '🤬',      '😱',      '😰',
      '😭',      '😢',      '😩',      '🥺',
      '😳',      '🤯',      '🤫',      '🤥',
      '😬',      '🙈',      '🙉',      '🙊',
      '💪',      '👌',      '✌️',      '🤞',
      '👍',      '👎',      '👏',      '🙏',
      '🤝',      '💯',      '🔥',      '✨',
      '⭐',      '🎉',      '🎊',      '❤️',
      '🧡',      '💛',      '💚',      '💙',
      '💜',      '🖤',      '💔',      '✅',
      '❌',      '⚠️',      '💡',      '📌',
      '📎',      '🚀',      '⏰',      '💰',
      '📈',      '📉',      '☕',      '🍕',
    ]);
  }
  return is_string($em) && $em !== '' && isset($set[$em]);
}
/* Reactions ya guardadas antes de la lista blanca. Se descartan para que un
   valor antiguo malicioso no siga sirviéndose en cada carga de la pagina. */
function chat_emoji_purge(){
  $marca = sys_get_temp_dir() . '/croilab_chat_emoji_purged';
  if (is_file($marca)) return 0;
  try {
    $rs = db()->query('SELECT message_id, admin_id, emoji FROM chat_reactions');
    $del = db()->prepare('DELETE FROM chat_reactions WHERE message_id=? AND admin_id=? AND emoji=?');
    $n = 0;
    while ($r = $rs->fetch()) { if (!chat_emoji_ok($r['emoji'])) { $del->execute([$r['message_id'],$r['admin_id'],$r['emoji']]); $n++; } }
    @file_put_contents($marca, (string)time());
    return $n;
  } catch (Exception $e) { return 0; }
}
chat_emoji_purge();   /* sanea la tabla una vez, no en cada carga */
function chat_store_files($meId){
  global $CHAT_ATT_OK; $out=[];
  if(empty($_FILES['files']) || !is_array($_FILES['files']['name'])) return $out;
  $dir=__DIR__.'/../uploads/chat'; if(!is_dir($dir)) @mkdir($dir,0775,true);
  $n=count($_FILES['files']['name']);
  for($i=0;$i<$n;$i++){
    if(($_FILES['files']['error'][$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) continue;
    $orig=(string)$_FILES['files']['name'][$i]; $ext=strtolower(pathinfo($orig,PATHINFO_EXTENSION));
    if(!in_array($ext,$CHAT_ATT_OK,true)) continue;
    if((int)($_FILES['files']['size'][$i]??0) > 25*1024*1024) continue;
    /* El nombre en disco es del todo aleatorio y solo lleva la extension, que ya
       viene de la lista blanca. El nombre de origen no se guarda en el disco: suele
       traer dentro el nombre del cliente o del proyecto, y se queda solo en la base
       de datos, que es donde ya se usaba para enseñarlo. Asi no puede colarse un
       punto de mas que hide una segunda extension. */
    $fn=bin2hex(random_bytes(12)).'.'.$ext;
    if(@move_uploaded_file($_FILES['files']['tmp_name'][$i], $dir.'/'.$fn)){ img_optimizar($dir.'/'.$fn); $out[]=['fn'=>$fn,'orig'=>$orig,'img'=>chat_is_img($fn)?1:0]; }
  }
  return $out;
}
function chat_reactions_for($ids,$meId){
  $map=[]; $ids=array_values(array_filter(array_map('intval',$ids))); if(!$ids) return $map;
  $in=implode(',',$ids);
  try{ foreach(db()->query("SELECT message_id,emoji,admin_id FROM chat_reactions WHERE message_id IN ($in) ORDER BY created_at") as $r){
    $mid=(int)$r['message_id']; $em=$r['emoji'];
    /* Defensa en profundidad: aunque hoy no se guarde nada fuera de la lista
       blanca, una fila antigua o insertada a mano no debe llegar al cliente. */
    if(!chat_emoji_ok($em)) continue;
    if(!isset($map[$mid]))$map[$mid]=[];
    if(!isset($map[$mid][$em]))$map[$mid][$em]=['emoji'=>$em,'count'=>0,'mine'=>0];
    $map[$mid][$em]['count']++; if((int)$r['admin_id']===$meId)$map[$mid][$em]['mine']=1;
  } }catch(Exception $e){}
  foreach($map as $mid=>$ems) $map[$mid]=array_values($ems);
  return $map;
}
function chat_body_html($text,$namesLower){
  $s=htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8');
  $s=preg_replace_callback('#(https?://[^\s<]+)#u',function($m){ $u=rtrim($m[1],'.,);'); return '<a href="'.$u.'" target="_blank" rel="noopener">'.$u.'</a>'; },$s);
  if($namesLower) $s=preg_replace_callback('/@([\p{L}0-9_.\-]{2,40})/u',function($m) use($namesLower){ return in_array(mb_strtolower($m[1]),$namesLower,true)?'<span class="chm">@'.$m[1].'</span>':$m[0]; },$s);
  return nl2br($s,false);
}
/* Payload JSON de un mensaje (mismo formato en carga inicial y en poll). */
function chat_msg_payload($m,$ctx){
  $mid=(int)$m['id']; $aid=(int)$m['admin_id']; $mine=($aid===$ctx['meId']);
  $nm=$ctx['adminName'][$aid]??'?'; $deleted=!empty($m['deleted']);
  $reply=null;
  if(!empty($m['reply_to']) && isset($ctx['msgById'][(int)$m['reply_to']])){ $om=$ctx['msgById'][(int)$m['reply_to']];
    $ex = !empty($om['deleted']) ? 'mensaje eliminado' : preg_replace('/\s+/u',' ',(string)$om['body']);
    $reply=['id'=>(int)$m['reply_to'],'author'=>$ctx['adminName'][(int)$om['admin_id']]??'?','ex'=>mb_substr($ex,0,80)]; }
  $attach=[]; if(!$deleted && !empty($m['attach'])){ $arr=json_decode($m['attach'],true); if(is_array($arr)) foreach($arr as $a){ $fn=$a['fn']??''; if($fn==='')continue;
    $attach[]=['orig'=>$a['orig']??$fn,'img'=>!empty($a['img'])?1:0,'url'=>'../archivo.php?d=chat&f='.rawurlencode($fn)]; } }
  $t=strtotime($m['created_at']);
  return ['id'=>$mid,'aid'=>$aid,'mine'=>$mine?1:0,'author'=>$nm,'color'=>avatar_color($nm),'ini'=>mb_strtoupper(mb_substr($nm,0,2)),
    'html'=>$deleted?'':chat_body_html((string)$m['body'],$ctx['namesLower']),
    'time'=>date('H:i',$t),'day'=>date('Y-m-d',$t),'datel'=>date('d/m/Y',$t),
    'reply'=>$reply,'attach'=>$attach,'react'=>array_values($ctx['reactMap'][$mid]??[]),
    'edited'=>!empty($m['edited'])?1:0,'deleted'=>$deleted?1:0];
}

/* --- Acciones (AJAX/POST) --- */
/* Permisos del chat (decisión de la auditoría, defecto P1-04): el chat es
   comunicación interna del equipo, no un dato de negocio, así que enviar mensajes,
   abrir un directo y leer (poll) se permiten a TODOS los roles, incluido «solo
   lectura» — es una excepción deliberada. Lo único estructural, crear un grupo,
   sí exige permiso de edición. */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $a = $_POST['action'] ?? '';
  header('Content-Type: application/json');
  if ($a==='send') {
    $rid=(int)($_POST['room_id']??0); $body=trim((string)($_POST['body']??''));
    $replyTo=(int)($_POST['reply_to']??0);
    if ($rid && chat_is_member($rid,$meId)) {
      $files=chat_store_files($meId);
      if ($body==='' && !$files) { echo json_encode(['ok'=>0]); exit; }
      if ($replyTo>0){ $chk=db()->prepare('SELECT 1 FROM chat_messages WHERE id=? AND room_id=?'); $chk->execute([$replyTo,$rid]); if(!$chk->fetchColumn()) $replyTo=0; }
      $attJson = $files?json_encode($files,JSON_UNESCAPED_UNICODE):null;
      db()->prepare('INSERT INTO chat_messages (room_id,admin_id,body,reply_to,attach) VALUES (?,?,?,?,?)')->execute([$rid,$meId,$body,$replyTo>0?$replyTo:null,$attJson]);
      $mid=(int)db()->lastInsertId();
      db()->prepare('UPDATE chat_members SET last_read=? WHERE room_id=? AND admin_id=?')->execute([$mid,$rid,$meId]);
      try{ db()->prepare('DELETE FROM chat_typing WHERE room_id=? AND admin_id=?')->execute([$rid,$meId]); }catch(Exception $e){}
      /* Un aviso vivo por persona (mismo ref), reemplazado por el mensaje más reciente. */
      try {
        $rm = db()->prepare('SELECT name,type FROM chat_rooms WHERE id=?'); $rm->execute([$rid]); $room=$rm->fetch();
        $sala = ($room && ($room['type']??'')==='group' && trim((string)$room['name'])!=='') ? trim((string)$room['name']) : '';
        $mem = db()->prepare('SELECT admin_id FROM chat_members WHERE room_id=? AND admin_id<>?'); $mem->execute([$rid,$meId]);
        $resumen = $body!=='' ? mb_substr(preg_replace('/\s+/u',' ', $body), 0, 120) : '📎 Adjunto';
        foreach ($mem->fetchAll(PDO::FETCH_COLUMN) as $uid) {
          $uid = (int)$uid; if(!$uid) continue;
          $ref = 'chat:'.$rid.':'.$uid;
          db()->prepare('DELETE FROM notifications WHERE admin_id=? AND ref=?')->execute([$uid,$ref]);
          notif_add($uid, 'chat',
            $sala!=='' ? 'ha escrito en «'.$sala.'»' : 'te ha escrito por chat',
            $resumen, 'chat.php?room='.$rid, $ref, $sala, (string)$me['username']);
        }
      } catch (Exception $e) {}
      echo json_encode(['ok'=>1,'id'=>$mid]); exit;
    }
    echo json_encode(['ok'=>0]); exit;
  }
  if ($a==='react') {
    $mid=(int)($_POST['mid']??0); $em=mb_substr(trim((string)($_POST['emoji']??'')),0,16);
    /* Lista blanca en el servidor. Confiar en EMOJI_SET del cliente no vale:
       se salta con una petición directa y el valor acaba almacenado. */
    $ok = chat_emoji_ok($em) && $mid>0;
    if($ok){ $rq=db()->prepare("SELECT 1 FROM chat_messages m JOIN chat_members me ON me.room_id=m.room_id AND me.admin_id=? WHERE m.id=?"); $rq->execute([$meId,$mid]); $ok=(bool)$rq->fetchColumn(); }
    if($ok){ $ex=db()->prepare('SELECT 1 FROM chat_reactions WHERE message_id=? AND admin_id=? AND emoji=?'); $ex->execute([$mid,$meId,$em]);
      if($ex->fetchColumn()) db()->prepare('DELETE FROM chat_reactions WHERE message_id=? AND admin_id=? AND emoji=?')->execute([$mid,$meId,$em]);
      else db()->prepare('INSERT IGNORE INTO chat_reactions (message_id,admin_id,emoji) VALUES (?,?,?)')->execute([$mid,$meId,$em]);
    }
    echo json_encode(['ok'=>$ok?1:0]); exit;
  }
  if ($a==='edit_msg') {
    $mid=(int)($_POST['mid']??0); $body=trim((string)($_POST['body']??''));
    if($mid && $body!==''){ db()->prepare('UPDATE chat_messages SET body=?, edited=1 WHERE id=? AND admin_id=? AND deleted=0')->execute([$body,$mid,$meId]); }
    echo json_encode(['ok'=>1]); exit;
  }
  if ($a==='del_msg') {
    $mid=(int)($_POST['mid']??0);
    if($mid){ $st=db()->prepare("UPDATE chat_messages SET deleted=1, body='', attach=NULL WHERE id=? AND admin_id=?"); $st->execute([$mid,$meId]);
      if($st->rowCount()){ try{ db()->prepare('DELETE FROM chat_reactions WHERE message_id=?')->execute([$mid]); }catch(Exception $e){} } }
    echo json_encode(['ok'=>1]); exit;
  }
  if ($a==='grp_rename') { $rid=(int)($_POST['room_id']??0); $name=trim((string)($_POST['name']??''));
    if($rid && $name!=='' && chat_is_member($rid,$meId)) db()->prepare("UPDATE chat_rooms SET name=? WHERE id=? AND type='group'")->execute([mb_substr($name,0,120),$rid]);
    echo json_encode(['ok'=>1]); exit; }
  if ($a==='grp_add') { $rid=(int)($_POST['room_id']??0); $uid=(int)($_POST['uid']??0);
    if($rid && $uid && chat_is_member($rid,$meId) && isset($adminName[$uid])) db()->prepare('INSERT IGNORE INTO chat_members (room_id,admin_id) VALUES (?,?)')->execute([$rid,$uid]);
    echo json_encode(['ok'=>1]); exit; }
  if ($a==='grp_remove') { $rid=(int)($_POST['room_id']??0); $uid=(int)($_POST['uid']??0);
    if($rid && $uid && $uid!==$meId && chat_is_member($rid,$meId)) db()->prepare('DELETE FROM chat_members WHERE room_id=? AND admin_id=?')->execute([$rid,$uid]);
    echo json_encode(['ok'=>1]); exit; }
  if ($a==='grp_leave') { $rid=(int)($_POST['room_id']??0);
    if($rid && chat_is_member($rid,$meId)) db()->prepare('DELETE FROM chat_members WHERE room_id=? AND admin_id=?')->execute([$rid,$meId]);
    echo json_encode(['ok'=>1]); exit; }
  if ($a==='create_group') {
    if (!can_edit()) { echo json_encode(['ok'=>0,'msg'=>'Sin permiso para crear grupos.']); exit; }
    $name=trim((string)($_POST['name']??'')); $mem=$_POST['members']??[];
    if ($name!=='') {
      db()->prepare("INSERT INTO chat_rooms (name,type,created_by) VALUES (?,'group',?)")->execute([$name,$meId]);
      $rid=(int)db()->lastInsertId();
      $ins=db()->prepare('INSERT IGNORE INTO chat_members (room_id,admin_id) VALUES (?,?)');
      $ins->execute([$rid,$meId]);
      if (is_array($mem)) foreach($mem as $mi){ $mi=(int)$mi; if($mi && $mi!==$meId) $ins->execute([$rid,$mi]); }
      echo json_encode(['ok'=>1,'room'=>$rid]); exit;
    }
    echo json_encode(['ok'=>0]); exit;
  }
  if ($a==='open_dm') {
    $other=(int)($_POST['other']??0);
    if ($other && isset($adminName[$other])) { echo json_encode(['ok'=>1,'room'=>chat_dm_room($meId,$other)]); exit; }
    echo json_encode(['ok'=>0]); exit;
  }
  if ($a==='typing') {
    $rid=(int)($_POST['room_id']??0);
    if ($rid && chat_is_member($rid,$meId)) {
      $until=time()+6;
      db()->prepare('INSERT INTO chat_typing (room_id,admin_id,until_ts) VALUES (?,?,?) ON DUPLICATE KEY UPDATE until_ts=VALUES(until_ts)')->execute([$rid,$meId,$until]);
      echo json_encode(['ok'=>1]); exit;
    }
    echo json_encode(['ok'=>0]); exit;
  }
  if ($a==='poll') {
    $rid=(int)($_POST['room_id']??0); $after=(int)($_POST['after']??0);
    if ($rid && chat_is_member($rid,$meId)) {
      chat_presence_touch($meId, (($_POST['act']??'1')==='1'));
      $q=db()->prepare('SELECT * FROM chat_messages WHERE room_id=? AND id>? ORDER BY id'); $q->execute([$rid,$after]); $rows=$q->fetchAll();
      if ($rows) { $last=(int)$rows[count($rows)-1]['id']; db()->prepare('UPDATE chat_members SET last_read=? WHERE room_id=? AND admin_id=?')->execute([$last,$rid,$meId]); }
      /* payloads de los mensajes nuevos (con adjuntos, reacciones, cita…) */
      $msgById=[]; foreach($rows as $r) $msgById[(int)$r['id']]=$r;
      $need=[]; foreach($rows as $r){ if(!empty($r['reply_to']) && !isset($msgById[(int)$r['reply_to']])) $need[(int)$r['reply_to']]=1; }
      if($need){ $in=implode(',',array_map('intval',array_keys($need))); foreach(db()->query("SELECT * FROM chat_messages WHERE id IN ($in)") as $rr) $msgById[(int)$rr['id']]=$rr; }
      $ids=array_map(fn($r)=>(int)$r['id'],$rows);
      $ctx=['meId'=>$meId,'adminName'=>$adminName,'namesLower'=>$namesLower,'msgById'=>$msgById,'reactMap'=>chat_reactions_for($ids,$meId)];
      $payloads=array_map(fn($r)=>chat_msg_payload($r,$ctx),$rows);
      /* estados de mensajes ya visibles que pueden cambiar (reacción / editado / borrado) */
      $states=[];
      try{
        $srows=db()->query("SELECT * FROM chat_messages WHERE room_id=".(int)$rid." AND (edited=1 OR deleted=1 OR id IN (SELECT message_id FROM chat_reactions)) ORDER BY id DESC LIMIT 150")->fetchAll();
        $sids=array_map(fn($r)=>(int)$r['id'],$srows); $sById=[]; foreach($srows as $r) $sById[(int)$r['id']]=$r;
        $sctx=['meId'=>$meId,'adminName'=>$adminName,'namesLower'=>$namesLower,'msgById'=>$sById,'reactMap'=>chat_reactions_for($sids,$meId)];
        foreach($srows as $r){ $p=chat_msg_payload($r,$sctx); $states[]=['id'=>$p['id'],'html'=>$p['html'],'react'=>$p['react'],'edited'=>$p['edited'],'deleted'=>$p['deleted']]; }
      }catch(Exception $e){}
      /* escribiendo… (menos yo) */
      $typing=[]; $now=time();
      $tq=db()->prepare('SELECT admin_id FROM chat_typing WHERE room_id=? AND admin_id<>? AND until_ts>?'); $tq->execute([$rid,$meId,$now]);
      foreach($tq->fetchAll(PDO::FETCH_COLUMN) as $tid) $typing[]=$adminName[(int)$tid]??'?';
      $readUpto=(int)db()->query("SELECT COALESCE(MIN(last_read),0) FROM chat_members WHERE room_id=".(int)$rid." AND admin_id<>".$meId)->fetchColumn();
      $unreadMap=[];
      $uq=db()->prepare("SELECT cm.room_id, COUNT(*) n FROM chat_messages cm JOIN chat_members me ON me.room_id=cm.room_id AND me.admin_id=? WHERE cm.id>me.last_read AND cm.admin_id<>? GROUP BY cm.room_id");
      $uq->execute([$meId,$meId]); foreach($uq as $u) $unreadMap[(int)$u['room_id']]=(int)$u['n'];
      /* mapa de presencia de todo el equipo (para los puntos verde/naranja/gris) */
      $presmap=[];
      foreach($adminName as $aid=>$nm){ $ps=chat_presence_state((int)$aid); $presmap[(int)$aid]=['s'=>$ps['state'],'t'=>$ps['text'],'c'=>chat_presence_color($ps['state'])]; }
      echo json_encode(['ok'=>1,'messages'=>$payloads,'states'=>$states,'typing'=>$typing,'read'=>$readUpto,'unread'=>$unreadMap,'presmap'=>$presmap]); exit;
    }
    echo json_encode(['ok'=>0,'messages'=>[]]); exit;
  }
  echo json_encode(['ok'=>0]); exit;
}

/* --- Endpoint GLOBAL de aviso (GET): mensajes nuevos en cualquier sala del usuario,
       para el sonido + notificación del navegador app-wide (desde erp_nav). --- */
if (($_GET['ping']??'')==='1') {
  header('Content-Type: application/json');
  chat_presence_touch($meId, (($_GET['act']??'1')==='1'));
  $after=(int)($_GET['after']??0);
  $rows=[]; $maxid=$after;
  try {
    $q=db()->prepare("SELECT m.id,m.room_id,m.admin_id,m.body,r.type,r.name
        FROM chat_messages m
        JOIN chat_members me ON me.room_id=m.room_id AND me.admin_id=?
        JOIN chat_rooms r ON r.id=m.room_id
        WHERE m.id>? AND m.admin_id<>? ORDER BY m.id");
    $q->execute([$meId,$after,$meId]);
    foreach($q as $r){ $maxid=max($maxid,(int)$r['id']);
      $grp = ($r['type']==='group' && trim((string)$r['name'])!=='');
      $rows[]=['id'=>(int)$r['id'],'room'=>(int)$r['room_id'],'author'=>$adminName[(int)$r['admin_id']]??'?',
               'body'=>mb_substr(preg_replace('/\s+/u',' ',(string)$r['body']),0,120),
               'label'=>$grp?$r['name']:($adminName[(int)$r['admin_id']]??'Directo'),'group'=>$grp?1:0];
    }
    $unread=(int)db()->query("SELECT COUNT(*) FROM chat_messages cm JOIN chat_members me ON me.room_id=cm.room_id AND me.admin_id=".$meId." WHERE cm.id>me.last_read AND cm.admin_id<>".$meId)->fetchColumn();
  } catch(Exception $e){ $unread=0; }
  echo json_encode(['ok'=>1,'messages'=>$rows,'max'=>$maxid,'unread'=>$unread]); exit;
}

/* --- Datos para render --- */
$rooms = db()->prepare("SELECT r.*,
    (SELECT COUNT(*) FROM chat_messages cm WHERE cm.room_id=r.id AND cm.id>me.last_read AND cm.admin_id<>?) unread,
    (SELECT MAX(id) FROM chat_messages cm WHERE cm.room_id=r.id) lastmsg,
    lm.body lastbody, lm.admin_id lastfrom, lm.created_at lasttime, lm.deleted lastdel, lm.attach lastatt
  FROM chat_rooms r JOIN chat_members me ON me.room_id=r.id AND me.admin_id=?
  LEFT JOIN chat_messages lm ON lm.id=(SELECT MAX(id) FROM chat_messages cm WHERE cm.room_id=r.id)
  ORDER BY lastmsg IS NULL, lastmsg DESC, r.id DESC");
$rooms->execute([$meId,$meId]); $rooms = $rooms->fetchAll();
/* Texto de vista previa del último mensaje de una sala (estilo iMessage/WhatsApp). */
function chat_preview($r,$adminName,$meId){
  if(empty($r['lasttime'])) return '';
  if(!empty($r['lastdel'])) return 'Mensaje eliminado';
  $body=trim(preg_replace('/\s+/u',' ', (string)$r['lastbody']));
  if($body==='') $body = !empty($r['lastatt']) ? '📎 Adjunto' : '';
  $body=str_replace(['**','__','`'],'',$body);
  $pre='';
  if((int)$r['lastfrom']===$meId) $pre='Tú: ';
  elseif($r['type']==='group') $pre=($adminName[(int)$r['lastfrom']]??'?').': ';
  return $pre.$body;
}
function chat_shorttime($ts){ if(!$ts) return ''; $d=strtotime($ts); $today=strtotime('today');
  if($d>=$today) return date('H:i',$d);
  if($d>=strtotime('-1 day',$today)) return 'ayer';
  if($d>=strtotime('-6 days',$today)){ $dd=['','lun','mar','mié','jue','vie','sáb','dom']; return $dd[(int)date('N',$d)]; }
  return date('d/m',$d); }

// nombre + miembros por sala
$roomMembers = [];
foreach (db()->query('SELECT room_id, admin_id FROM chat_members')->fetchAll() as $rm) $roomMembers[$rm['room_id']][] = (int)$rm['admin_id'];
function room_label($r,$roomMembers,$adminName,$meId){
  if ($r['type']==='dm') { foreach(($roomMembers[$r['id']]??[]) as $mid){ if($mid!==$meId) return $adminName[$mid]??'Directo'; } return 'Yo'; }
  return $r['name'] ?: 'Grupo';
}

/* Abrir un directo desde la tarjeta de perfil (?dm=<idAdmin>). */
if (isset($_GET['dm'])) { $dmId=(int)$_GET['dm']; if($dmId && $dmId!==$meId && isset($adminName[$dmId])){ header('Location: chat.php?room='.chat_dm_room($meId,$dmId)); exit; } }
$curRoom = isset($_GET['room']) ? (int)$_GET['room'] : (count($rooms)?(int)$rooms[0]['id']:0);
if ($curRoom && !chat_is_member($curRoom,$meId)) $curRoom = 0;
$msgs = []; $readUpto = 0; $CH_MSGS = []; $curPres=''; $curOtherId=0;
if ($curRoom) {
  $q=db()->prepare('SELECT * FROM chat_messages WHERE room_id=? ORDER BY id'); $q->execute([$curRoom]); $msgs=$q->fetchAll();
  $last=$msgs?(int)$msgs[count($msgs)-1]['id']:0;
  db()->prepare('UPDATE chat_members SET last_read=? WHERE room_id=? AND admin_id=?')->execute([$last,$curRoom,$meId]);
  $readUpto=(int)db()->query("SELECT COALESCE(MIN(last_read),0) FROM chat_members WHERE room_id=".(int)$curRoom." AND admin_id<>".$meId)->fetchColumn();
  /* Al abrir la sala el aviso de la campana sobra: ya lo has visto. */
  try { db()->prepare('DELETE FROM notifications WHERE admin_id=? AND ref=?')->execute([$meId,'chat:'.$curRoom.':'.$meId]); } catch (Exception $e) {}
  chat_presence_touch($meId, true);
  /* Payloads iniciales para el render en cliente. */
  $msgById=[]; foreach($msgs as $m) $msgById[(int)$m['id']]=$m;
  $ids=array_map(fn($m)=>(int)$m['id'],$msgs);
  $ctx=['meId'=>$meId,'adminName'=>$adminName,'namesLower'=>$namesLower,'msgById'=>$msgById,'reactMap'=>chat_reactions_for($ids,$meId)];
  $CH_MSGS=array_map(fn($m)=>chat_msg_payload($m,$ctx),$msgs);
}
$curRoomData = null; foreach($rooms as $r){ if((int)$r['id']===$curRoom) $curRoomData=$r; }
$curPresState='offline';
if ($curRoomData && $curRoomData['type']==='dm'){ foreach(($roomMembers[$curRoom]??[]) as $mid){ if($mid!==$meId){ $curOtherId=$mid; $ps=chat_presence_state($mid); $curPres=$ps['text']; $curPresState=$ps['state']; break; } } }
/* Presencia de todo el equipo para los puntos de la barra lateral (verde/naranja/gris). */
$presAll=[]; foreach($adminName as $aid=>$nm){ $ps=chat_presence_state((int)$aid); $presAll[(int)$aid]=['s'=>$ps['state'],'c'=>chat_presence_color($ps['state'])]; }

function reltime_chat($ts){ if(!$ts) return ''; $d=strtotime($ts); $diff=time()-$d; if($diff<60)return 'ahora'; if($diff<3600)return floor($diff/60).' min'; if($diff<86400)return floor($diff/3600).' h'; return date('d/m H:i',$d); }

erp_head('chat', 'Chat de equipo');
?>
<style>
.erp-wrap{padding:0;max-width:none;height:calc(100vh - 56px);display:flex;overflow:hidden}
.ch-list{width:290px;flex:none;border-right:1px solid var(--line);display:flex;flex-direction:column;background:#fff}
.ch-list .lh{padding:16px 16px 10px;display:flex;align-items:center;gap:8px}
.ch-list .lh h2{font-size:17px;font-weight:600;color:var(--ink-strong);flex:1}
.ch-newbtn{border:none;background:var(--accent);color:#fff;width:30px;height:30px;border-radius:9px;cursor:pointer;font-size:17px;display:flex;align-items:center;justify-content:center}
.ch-newbtn:hover{transform:translateY(-1px);box-shadow:0 5px 13px rgba(0,0,0,.16)}
.ch-newbtn svg{width:17px;height:17px}
.ch-search{margin:0 14px 10px;position:relative;display:flex;align-items:center}
.ch-search .si{position:absolute;left:11px;color:var(--label);pointer-events:none}
.ch-search input{width:100%;border:none;background:#f0f0f2;border-radius:10px;padding:9px 11px 9px 33px;font-size:13.5px;font-family:inherit;outline:none;color:var(--ink);transition:box-shadow .15s}
.ch-search input:focus{box-shadow:none;border:none}
.ch-scroll{flex:1;overflow:auto;padding:2px 8px 12px}
.ch-noconv{padding:40px 20px;text-align:center;color:var(--muted);font-size:13px}
.ch-noconv button{margin-top:12px;border:none;background:var(--accent);color:#fff;border-radius:10px;padding:9px 16px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}
.ch-item{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:12px;cursor:pointer;color:#4c515b;margin-bottom:4px}
.ch-item:last-child{margin-bottom:0}
.ch-item:hover{background:var(--soft)}
.ch-item.on{background:var(--accent-soft)}
.ch-item .cav{width:44px;height:44px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex:none}
.ch-item .cav.grp{border-radius:14px}
.ch-item .cavwrap{width:44px;height:44px}
.ch-item .cavwrap .cav{width:44px;height:44px}
.ch-item .mid{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.ch-item .row1{display:flex;align-items:baseline;gap:8px}
.ch-item .row1 b{flex:1;min-width:0;font-size:14.5px;font-weight:600;color:var(--ink-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ch-item .row1 .tm{font-size:11.5px;color:var(--muted);flex:none;font-weight:500}
.ch-item .row2{display:flex;align-items:center;gap:8px}
.ch-item .prev{flex:1;min-width:0;font-size:13px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ch-item.unread .row1 b{font-weight:650}
.ch-item.unread .prev{color:#3c4149;font-weight:500}
.ch-item.unread .tm{color:var(--accent);font-weight:700}
.ch-item .ub{background:#12a150;color:#fff;border-radius:99px;font-size:11px;font-weight:700;min-width:20px;height:20px;padding:0 6px;display:flex;align-items:center;justify-content:center;flex:none}
/* Redactar (compose) */
.cmp-modal{width:392px;max-width:100%;display:flex;flex-direction:column;max-height:82vh}
.cmp-modal button:focus,.cmp-modal input:focus{outline:none}
.cmp-head{display:flex;align-items:center;justify-content:space-between;padding:17px 18px 10px}
.cmp-head b{font-size:17px;font-weight:650;color:var(--ink-strong)}
.cmp-x{border:none;background:none;color:var(--label);cursor:pointer;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center}
.cmp-x:hover{background:var(--soft);color:var(--ink)}
.cmp-search{margin:0 18px;display:flex;align-items:center;gap:8px;background:#f0f0f2;border:none;border-radius:10px;padding:8px 12px}
.cmp-search svg{flex:none;color:var(--label);width:15px;height:15px}
#cmpSearch{flex:1;min-width:0;border:none!important;background:none!important;outline:none;box-shadow:none!important;font-size:14px;font-family:inherit;color:var(--ink);padding:0!important;margin:0!important;border-radius:0!important;height:auto}
.cmp-hint{font-size:12px;color:var(--muted);padding:9px 20px 2px;line-height:1.4}
.cmp-hint b{color:#5f6672;font-weight:650}
.cmp-list{flex:1;overflow:auto;padding:4px 10px 8px}
.cmp-person{display:flex;align-items:center;gap:13px;width:100%;border:none;background:none;padding:10px 11px;border-radius:13px;cursor:pointer;font-family:inherit;text-align:left;color:var(--ink);-webkit-tap-highlight-color:transparent;margin-bottom:4px}
.cmp-person:last-child{margin-bottom:0}
.cmp-person:hover{background:var(--soft)}
.cmp-person .cavwrap{width:44px;height:44px;position:relative;flex:none}
.cmp-person .cavwrap .cav{width:44px;height:44px;font-size:15px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700}
.cmp-person .pdot{position:absolute;bottom:0;right:0;width:12px;height:12px;border-radius:50%;box-shadow:0 0 0 2.5px #fff}
.cmp-person .nm{flex:1;min-width:0;font-size:15px;font-weight:550;color:var(--ink-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cmp-person .cmp-chk{width:24px;height:24px;border-radius:50%;border:2px solid #d3d3d9;flex:none;display:flex;align-items:center;justify-content:center;transition:background .15s,border-color .15s}
.cmp-person .cmp-chk svg{opacity:0;transition:opacity .1s}
.cmp-person.sel .cmp-chk{background:var(--accent);border-color:var(--accent)}
.cmp-person.sel .cmp-chk svg{opacity:1}
.cmp-person.sel{background:var(--accent-soft)}
.cmp-namewrap{max-height:0;opacity:0;overflow:hidden;padding:0 18px;transition:max-height .3s cubic-bezier(.4,0,.2,1),opacity .22s ease,padding .3s ease}
.cmp-namewrap.on{max-height:80px;opacity:1;padding:10px 18px 2px}
#cmpGroupName{width:100%;border:1px solid #e2e2e6;background:#fff;border-radius:12px;padding:12px 14px;font-size:15px;font-family:inherit;outline:none;margin:0!important;transition:border-color .15s,box-shadow .15s}
#cmpGroupName:focus{border-color:var(--accent)!important;box-shadow:0 0 0 4px var(--accent-soft)!important}
.cmp-foot{display:flex;gap:10px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--line2);background:#fcfcfd}
.cmp-cancel{border:none;background:#e8e8ed;color:var(--ink);border-radius:11px;padding:11px 18px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit}
.cmp-cancel:hover{background:#deded5}
.cmp-go{border:none;background:var(--accent);color:#fff;border-radius:11px;padding:11px 20px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;transition:opacity .15s}
.cmp-go:disabled{background:#c7c7cc;cursor:default}
/* panel */
.ch-main{flex:1;display:flex;flex-direction:column;min-width:0;background:linear-gradient(#fcfcfd,#fff)}
.ch-top{height:60px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px;padding:0 20px;background:#fff;flex:none}
.ch-top .cav{width:38px;height:38px;border-radius:12px;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex:none}
.ch-top .ti b{font-size:15px;color:var(--ink-strong);display:block}
.ch-top .ti span{font-size:12px;color:var(--muted)}
.ch-feed{flex:1;overflow:auto;padding:26px 30px;display:flex;flex-direction:column;gap:4px}
.ch-day{align-self:center;font-size:11px;color:var(--muted);background:#fff;border:1px solid var(--line);border-radius:99px;padding:4px 13px;margin:14px 0}
.ch-msg{display:flex;gap:11px;max-width:74%;margin-top:14px}
.ch-msg .mav{width:32px;height:32px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:650;font-size:11px;flex:none}
.ch-msg .mb{background:#fff;border:1px solid var(--line);border-radius:14px;border-top-left-radius:4px;padding:10px 15px}
.ch-msg .mnm{font-size:12px;font-weight:600;margin-bottom:3px}
.ch-msg .mtx{font-size:13.5px;color:var(--ink);line-height:1.55;word-break:break-word;white-space:pre-wrap}
.ch-msg .mt{font-size:10px;color:var(--muted);margin-top:4px}
.ch-msg.mine{align-self:flex-end;flex-direction:row-reverse}
.ch-msg.mine .mb{background:var(--accent);border-color:var(--accent);border-radius:14px;border-top-right-radius:4px}
.ch-msg.mine .mtx,.ch-msg.mine .mnm{color:#fff}
.ch-msg.mine .mt{text-align:right;color:var(--label)}
.ch-msg.mine .mav{display:none}
/* tics de leído (estilo WhatsApp) */
.ch-msg .mt .mck{display:inline-flex;vertical-align:middle;margin-left:3px}
.ch-msg .mt .mck svg{width:15px;height:11px;display:block}
.ch-msg.mine .mt .mck{color:#cdd4dd}
.ch-msg.mine .mt .mck.read{color:#53bdeb}
/* indicador escribiendo… */
.ch-typing{flex:none;height:0;overflow:hidden;padding:0 26px;display:flex;align-items:center;gap:9px;font-size:12px;color:var(--muted);background:#fff;transition:height .16s ease}
.ch-typing.on{height:30px}
.ch-typing .tdots{display:inline-flex;gap:3px}
.ch-typing .tdots i{width:6px;height:6px;border-radius:50%;background:#b7bcc4;display:inline-block;animation:chBlink 1.2s infinite}
.ch-typing .tdots i:nth-child(2){animation-delay:.2s}
.ch-typing .tdots i:nth-child(3){animation-delay:.4s}
@keyframes chBlink{0%,60%,100%{opacity:.28;transform:translateY(0)}30%{opacity:1;transform:translateY(-2px)}}
.ch-compose{border-top:1px solid var(--line);padding:14px 18px;background:#fff;display:flex;gap:10px;align-items:flex-end;flex:none}
.ch-compose textarea{flex:1;border:1px solid var(--line);border-radius:12px;padding:11px 15px;font-size:13.5px;line-height:1.5;font-family:inherit;resize:none;max-height:120px;outline:none;min-height:44px}
.ch-compose textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.ch-send{border:none;background:var(--accent);color:#fff;width:42px;height:42px;border-radius:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex:none}
.ch-send:hover{transform:translateY(-1px);box-shadow:0 6px 15px rgba(0,0,0,.18)}
.ch-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--muted);gap:10px;text-align:center}
/* modal nuevo grupo */
.ch-ov{position:fixed;inset:0;background:rgba(20,22,28,.5);z-index:400;display:none;align-items:center;justify-content:center;padding:20px}
.ch-ov.on{display:flex;animation:fadeIn .15s ease}
.ch-modal{background:#fff;border-radius:18px;width:440px;max-width:100%;box-shadow:0 30px 80px rgba(0,0,0,.28);overflow:hidden;animation:fadeUp .2s ease}
.ch-modal .mh{padding:18px 20px;border-bottom:1px solid var(--line);font-size:15.5px;font-weight:650}
.ch-modal .mbdy{padding:18px 20px;max-height:60vh;overflow:auto}
.ch-modal input[type=text]{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 13px;font-size:14px;font-family:inherit;margin-bottom:14px;outline:none}
.ch-mem{display:flex;align-items:center;gap:10px;padding:10px 11px;border-radius:9px;cursor:pointer;margin-bottom:4px}
.ch-mem:last-child{margin-bottom:0}
.ch-mem:hover{background:var(--soft)}
.ch-mem .cav{width:30px;height:30px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex:none}
.ch-mem b{flex:1;font-size:13.5px;font-weight:500;color:var(--ink)}
.ch-mem input{width:18px;height:18px;accent-color:var(--accent)}
.ch-modal .mf{padding:14px 20px;border-top:1px solid var(--line);display:flex;justify-content:flex-end;gap:8px}
/* presencia + menú de cabecera */
.ch-top .ti{flex:1;min-width:0}
.ch-top .ti span.online{color:#1a9d5b;font-weight:600}
.ch-top .pdot{width:9px;height:9px;border-radius:50%;background:#1a9d5b;display:inline-block;margin-right:5px;vertical-align:middle;box-shadow:0 0 0 2px #fff}
.ch-hdbtn{border:none;background:none;color:var(--muted);width:36px;height:36px;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex:none}
.ch-hdbtn:hover{background:var(--soft);color:var(--ink)}
.ch-item .pdot{width:8px;height:8px;border-radius:50%;background:#1a9d5b;position:absolute;bottom:1px;right:1px;box-shadow:0 0 0 2px #fff}
.ch-item .cavwrap{position:relative;flex:none}
/* bloque de mensaje: reacciones, cita, adjuntos, menú */
.ch-msg{position:relative}
.ch-msg .mb{position:relative;max-width:100%}
.ch-msg .mtx a{color:#2f6fed;text-decoration:underline}
.ch-msg.mine .mtx a{color:#dbe8ff}
.ch-msg .mtx .chm{font-weight:700;color:#2f6fed}
.ch-msg.mine .mtx .chm{color:#dbe8ff}
.ch-msg .medit{font-size:10px;opacity:.7;margin-left:5px;font-style:italic}
.ch-msg .mdel{font-style:italic;color:var(--muted)!important}
.ch-msg.mine .mdel{color:#cfd4dc!important}
/* cita (responder) */
.ch-quote{border-left:3px solid #2f6fed;background:rgba(47,111,237,.07);border-radius:6px;padding:4px 9px;margin-bottom:5px;font-size:12px;cursor:pointer;max-width:100%;overflow:hidden}
.ch-msg.mine .ch-quote{border-left-color:#cfe0ff;background:rgba(255,255,255,.16)}
.ch-quote .qa{font-weight:700;color:#2f6fed;display:block;font-size:11px}
.ch-msg.mine .ch-quote .qa{color:#eaf1ff}
.ch-quote .qx{color:var(--muted);display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ch-msg.mine .ch-quote .qx{color:#e6ecf6}
/* adjuntos */
.ch-atts{display:flex;flex-direction:column;gap:6px;margin-top:6px}
.ch-att-img{display:block;max-width:260px;border-radius:10px;overflow:hidden;line-height:0;cursor:zoom-in}
.ch-att-img img{width:100%;height:auto;display:block;max-height:320px;object-fit:cover}
.ch-att-file{display:flex;align-items:center;gap:9px;background:rgba(0,0,0,.05);border-radius:9px;padding:8px 11px;text-decoration:none;color:inherit;font-size:12.5px;font-weight:600;max-width:280px}
.ch-msg.mine .ch-att-file{background:rgba(255,255,255,.18);color:#fff}
.ch-att-file .fi{flex:none;opacity:.8}.ch-att-file .fn{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* reacciones */
.ch-reacts{display:flex;gap:4px;flex-wrap:wrap;margin-top:5px}
.ch-rc{display:inline-flex;align-items:center;gap:3px;background:#fff;border:1px solid var(--line);border-radius:99px;padding:1px 7px;font-size:12px;cursor:pointer;line-height:1.6}
.ch-rc.mine{background:var(--accent-soft);border-color:#c7d2fe}
.ch-rc .n{font-size:10.5px;font-weight:700;color:var(--muted)}
.ch-msg.mine .ch-reacts{justify-content:flex-end}
/* acciones al pasar por encima */
.ch-msg .macts{position:absolute;top:2px;display:flex;gap:2px;opacity:0;transition:opacity .12s;background:#fff;border:1px solid var(--line);border-radius:9px;padding:2px;box-shadow:0 4px 12px rgba(16,19,24,.12);z-index:4}
.ch-msg:not(.mine) .macts{right:-4px;transform:translateX(100%)}
.ch-msg.mine .macts{left:-4px;transform:translateX(-100%)}
.ch-msg:hover .macts{opacity:1}
.ch-macts-btn{border:none;background:none;color:var(--label);width:26px;height:26px;border-radius:7px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.ch-macts-btn:hover{background:var(--soft);color:var(--ink)}
.ch-macts-btn svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2}
/* menú contextual del mensaje */
.ch-ctx{position:fixed;z-index:600;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 16px 40px rgba(16,19,24,.2);padding:5px;min-width:172px;display:none}
.ch-ctx.on{display:block}
.ch-ctx a{display:flex;align-items:center;gap:9px;padding:8px 11px;border-radius:8px;font-size:13px;color:var(--ink);cursor:pointer;text-decoration:none}
.ch-ctx a:hover{background:var(--soft)}
.ch-ctx a.danger{color:#dd5b52}
.ch-ctx .emojis{display:flex;gap:2px;padding:4px 5px 7px;border-bottom:1px solid var(--line2);margin-bottom:4px}
.ch-ctx .emojis button{border:none;background:none;font-size:19px;cursor:pointer;padding:3px;border-radius:7px;line-height:1}
.ch-ctx .emojis button:hover{background:var(--soft);transform:scale(1.15)}
.ch-ctx .emojis .rmore{color:var(--muted);display:inline-flex;align-items:center;justify-content:center}
.ch-ctx .emojis .rmore:hover{color:var(--ink);transform:none}
/* barra de responder en el compositor */
.ch-replybar{display:none;align-items:center;gap:10px;padding:8px 18px;background:#f6f7f9;border-top:1px solid var(--line)}
.ch-replybar.on{display:flex}
.ch-replybar .rb-l{width:3px;align-self:stretch;background:#2f6fed;border-radius:2px}
.ch-replybar .rb-b{flex:1;min-width:0}
.ch-replybar .rb-a{font-size:12px;font-weight:700;color:#2f6fed}
.ch-replybar .rb-t{font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ch-replybar .rb-x{border:none;background:none;color:var(--muted);cursor:pointer;font-size:15px;padding:4px}
/* previsualización de adjuntos antes de enviar */
.ch-pending{display:none;gap:8px;flex-wrap:wrap;padding:8px 18px 0}
.ch-pending.on{display:flex}
.ch-pend{position:relative;background:var(--soft);border:1px solid var(--line);border-radius:9px;padding:6px 26px 6px 9px;font-size:12px;font-weight:600;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ch-pend img{width:34px;height:34px;object-fit:cover;border-radius:6px;vertical-align:middle;margin-right:6px}
.ch-pend .px{position:absolute;right:5px;top:50%;transform:translateY(-50%);border:none;background:none;color:var(--muted);cursor:pointer;font-size:13px}
/* botones extra del compositor */
.ch-tool{border:none;background:none;color:var(--muted);width:38px;height:38px;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex:none}
.ch-tool:hover{background:var(--soft);color:var(--ink)}
.ch-tool svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2}
/* selector de emojis del compositor */
.ch-emojipop{position:absolute;bottom:64px;left:14px;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 18px 44px rgba(16,19,24,.2);padding:8px;display:none;grid-template-columns:repeat(8,1fr);gap:2px;z-index:30;width:320px}
.ch-emojipop.on{display:grid}
.ch-emojipop button{border:none;background:none;font-size:20px;cursor:pointer;padding:5px;border-radius:8px;line-height:1}
.ch-emojipop button:hover{background:var(--soft)}
/* botón bajar al final */
.ch-fab{position:absolute;right:22px;bottom:82px;width:40px;height:40px;border-radius:50%;background:#fff;border:1px solid var(--line);box-shadow:0 6px 18px rgba(16,19,24,.16);color:var(--ink);cursor:pointer;display:none;align-items:center;justify-content:center;z-index:20}
.ch-fab.on{display:flex}
.ch-fab .fbadge{position:absolute;top:-5px;right:-5px;background:var(--accent);color:#fff;border-radius:99px;font-size:10px;font-weight:700;min-width:18px;height:18px;display:flex;align-items:center;justify-content:center;padding:0 5px}
.ch-main{position:relative}
/* mención autocompletar */
.ch-mentionpop{position:absolute;bottom:64px;left:14px;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 16px 40px rgba(16,19,24,.2);padding:5px;display:none;z-index:30;min-width:200px;max-height:220px;overflow:auto}
.ch-mentionpop.on{display:block}
.ch-mentionpop button{display:flex;align-items:center;gap:9px;width:100%;border:none;background:none;padding:7px 9px;border-radius:8px;cursor:pointer;font-size:13px;text-align:left}
.ch-mentionpop button:hover,.ch-mentionpop button.sel{background:var(--soft)}
.ch-mentionpop .mav{width:26px;height:26px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700}
/* divisor mensajes nuevos */
.ch-newdiv{align-self:center;font-size:10.5px;font-weight:700;letter-spacing:.5px;color:#2f6fed;background:rgba(47,111,237,.08);border-radius:99px;padding:3px 14px;margin:8px 0;text-transform:uppercase}
/* puntos de presencia (verde=activo, naranja=reposo, gris=fuera) */
.ch-item .cavwrap{position:relative;flex:none}
.ch-item .pdot{position:absolute;bottom:-1px;right:-1px;width:11px;height:11px;border-radius:50%;box-shadow:0 0 0 2.5px #fff}
.ch-top .cavwrap{position:relative}
.ch-top .pdot.top-dot{position:absolute;bottom:0;right:0;width:12px;height:12px;border-radius:50%;box-shadow:0 0 0 2.5px #fff}
#chSub .pdot.inl{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:5px;vertical-align:middle}
#chSub .online{color:var(--ok);font-weight:600}
/* avatares con perfil al pasar el ratón */
.ch-msg .mav[data-uid]{cursor:pointer}
.ch-msg .mnm[data-uid]{cursor:pointer}
/* bloques: ocultar el avatar en mensajes consecutivos del mismo autor */
.ch-msg.cont{margin-top:2px}
.ch-msg.cont .mav{visibility:hidden;height:0}
.ch-msg.cont .mnm{display:none}
.ch-msg.cont .mb{border-top-left-radius:14px}
.ch-msg.mine.cont .mb{border-top-right-radius:14px}
/* micrófono grabando */
.ch-tool.rec{color:#fff;background:#ef4444;animation:chMicPulse 1.1s infinite}
.ch-tool.rec:hover{background:#dc2626}
@keyframes chMicPulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.5)}50%{box-shadow:0 0 0 6px rgba(239,68,68,0)}}
/* ---- Modo oscuro (aditivo): remapea las superficies y textos propios ---- */
[data-theme=dark] .ch-list,
[data-theme=dark] .ch-top,
[data-theme=dark] .ch-typing,
[data-theme=dark] .ch-compose,
[data-theme=dark] .ch-modal,
[data-theme=dark] .ch-fab{background-color:var(--card)}
[data-theme=dark] .ch-main{background-image:none;background-color:var(--bg)}
[data-theme=dark] .ch-msg:not(.mine) .mb{background-color:var(--card)}
[data-theme=dark] .ch-search input,
[data-theme=dark] .cmp-search,
[data-theme=dark] #cmpGroupName,
[data-theme=dark] .ch-compose textarea,
[data-theme=dark] .ch-modal input[type=text]{background-color:var(--field);color:var(--ink)}
[data-theme=dark] .ch-day,
[data-theme=dark] .cmp-foot,
[data-theme=dark] .cmp-cancel,
[data-theme=dark] .ch-replybar,
[data-theme=dark] .ch-rc,
[data-theme=dark] .ch-att-file{background-color:var(--soft)}
[data-theme=dark] .cmp-cancel:hover{background-color:var(--line)}
[data-theme=dark] .cmp-go:disabled{background-color:var(--line);color:var(--muted)}
[data-theme=dark] .ch-msg .macts,
[data-theme=dark] .ch-ctx,
[data-theme=dark] .ch-emojipop,
[data-theme=dark] .ch-mentionpop{background-color:var(--pop)}
[data-theme=dark] .ch-ctx a.danger{color:var(--danger)}
[data-theme=dark] .ch-newbtn,
[data-theme=dark] .ch-noconv button,
[data-theme=dark] .ch-send,
[data-theme=dark] .ch-fab .fbadge{color:var(--accent-fg)}
[data-theme=dark] .ch-msg.mine .mtx,
[data-theme=dark] .ch-msg.mine .mnm{color:var(--accent-fg)}
[data-theme=dark] .ch-item.unread .prev,
[data-theme=dark] .cmp-hint b{color:var(--ink)}
[data-theme=dark] .ch-item .pdot,
[data-theme=dark] .ch-top .pdot,
[data-theme=dark] .ch-top .pdot.top-dot,
[data-theme=dark] .cmp-person .pdot{box-shadow:0 0 0 2.5px var(--card)}
/* Botón «volver a conversaciones»: existe solo en móvil (lo inyecta el JS). */
.ch-mback{display:none}
/* ═══════════ MÓVIL — teléfono ≤640px: chat a pantalla completa tipo app ═══════════
   La lista de salas y la conversación no caben a la vez, así que se comportan como
   WhatsApp/Telegram: la conversación se ve a pantalla completa superpuesta sobre la
   lista; el botón «volver» destapa de nuevo la lista, y tocar una sala la abre. */
@media(max-width:640px){
  .erp-wrap{position:relative}
  /* La lista ocupa todo el ancho; ya no hay separador vertical. */
  .ch-list{width:100%;border-right:none}
  .ch-list .lh{padding:14px 16px 8px}
  .ch-scroll{padding:2px 8px 18px}
  /* La conversación se superpone a la lista; ocupa todo el hueco. */
  .ch-main{position:absolute;inset:0;z-index:15;background:#fff}
  /* Con la lista destapada (sin sala, o tras pulsar «volver») se oculta la conversación. */
  .erp-wrap.ch-show-list .ch-main{display:none}
  /* Botón «volver» dentro de la cabecera de la conversación. */
  .ch-mback{display:flex;align-items:center;justify-content:center;border:none;background:none;color:var(--ink);width:36px;height:36px;border-radius:10px;cursor:pointer;flex:none;margin-left:-6px}
  .ch-mback:hover{background:var(--soft)}
  .ch-top{height:56px;padding:0 12px;gap:10px}
  .ch-feed{padding:14px 12px 18px}
  .ch-msg{max-width:86%}
  /* Compositor cómodo y anclado abajo (respeta el borde seguro del teléfono). */
  .ch-compose{padding:9px 10px;padding-bottom:calc(9px + env(safe-area-inset-bottom));gap:6px}
  .ch-tool{width:36px;height:36px}
  .ch-tool svg{width:19px;height:19px}
  .ch-send{width:44px;height:44px}
  .ch-compose textarea{font-size:16px}   /* 16px evita el zoom automático de iOS al enfocar */
  /* Barras auxiliares alineadas al nuevo margen del compositor. */
  .ch-typing{padding:0 14px}
  .ch-replybar{padding-left:12px;padding-right:12px}
  .ch-pending{padding-left:12px;padding-right:12px}
  .ch-fab{right:14px;bottom:90px}
  /* Popovers flotantes del compositor sin salirse de la pantalla. */
  .ch-emojipop{left:10px;width:min(320px,calc(100vw - 20px))}
  .ch-mentionpop{left:10px;max-width:calc(100vw - 20px)}
  /* Modales de redactar / grupo a lo alto cómodo. */
  .cmp-modal{max-height:86vh}
  .ch-modal .mbdy{max-height:64vh}
}
@media(max-width:400px){
  .ch-feed{padding:12px 10px 16px}
  .ch-msg{max-width:90%;gap:8px}
  .ch-compose{gap:4px}
  .ch-tool{width:34px;height:34px}
}
</style>

<div class="ch-list">
  <div class="lh"><h2>Chat</h2><button class="ch-newbtn" title="Nuevo mensaje" onclick="chComposeOpen()"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button></div>
  <div class="ch-search"><svg class="si" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg><input type="text" id="chSearch" placeholder="Buscar" oninput="chFilter(this.value)"></div>
  <div class="ch-scroll" id="chScroll">
    <?php if(!$rooms): ?><div class="ch-noconv"><div>Aún no tienes conversaciones</div><button type="button" onclick="chComposeOpen()">Empezar una</button></div><?php endif; ?>
    <?php foreach($rooms as $r): $isG=($r['type']==='group'); $lbl=room_label($r,$roomMembers,$adminName,$meId);
      $oid=0; if(!$isG){ foreach(($roomMembers[$r['id']]??[]) as $mm){ if($mm!==$meId){ $oid=$mm; break; } } }
      $pc=$presAll[$oid]['c']??'#c0c4cb'; $prev=chat_preview($r,$adminName,$meId); if($prev===''&&$isG)$prev=count($roomMembers[$r['id']]??[]).' miembros';
      $tm=chat_shorttime($r['lasttime']??''); ?>
      <a class="ch-item<?= (int)$r['id']===$curRoom?' on':'' ?><?= $r['unread']?' unread':'' ?>" href="chat.php?room=<?= (int)$r['id'] ?>" data-name="<?= e(mb_strtolower($lbl.' '.$prev)) ?>">
        <?php if($isG): ?><span class="cav grp" style="background:<?= avatar_color('g'.$r['id'].$lbl) ?>"><?= e(mb_strtoupper(mb_substr($lbl,0,2))) ?></span>
        <?php else: ?><span class="cavwrap" data-uid="<?= (int)$oid ?>"><span class="cav" style="background:<?= avatar_color($lbl) ?>;border-radius:50%"><?= e(mb_strtoupper(mb_substr($lbl,0,2))) ?></span><span class="pdot" style="background:<?= $pc ?>"></span></span><?php endif; ?>
        <span class="mid">
          <span class="row1"><b><?= e($lbl) ?></b><span class="tm"><?= e($tm) ?></span></span>
          <span class="row2"><span class="prev"><?= e($prev) ?></span><?php if($r['unread']): ?><span class="ub"><?= (int)$r['unread'] ?></span><?php endif; ?></span>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Redactar: elige 1 persona (directo) o varias (grupo) -->
<div class="ch-ov" id="chCompose"><div class="ch-modal cmp-modal">
  <div class="cmp-head"><b>Nuevo mensaje</b><button type="button" class="cmp-x" onclick="chCompClose()"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div>
  <div class="cmp-search"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg><input type="text" id="cmpSearch" placeholder="Buscar persona…" oninput="chCmpFilter(this.value)" autocomplete="off"></div>
  <div class="cmp-hint">Elige <b>una persona</b> para un chat directo, o <b>varias</b> para crear un grupo.</div>
  <div class="cmp-list" id="cmpList">
    <?php foreach($admins as $a): if((int)$a['id']===$meId) continue; $pc=$presAll[(int)$a['id']]['c']??'#c0c4cb'; ?>
      <button type="button" class="cmp-person" data-id="<?= (int)$a['id'] ?>" data-name="<?= e(mb_strtolower($a['username'])) ?>" onclick="chCmpToggle(this)">
        <span class="cavwrap" data-uid="<?= (int)$a['id'] ?>"><span class="cav" style="background:<?= avatar_color($a['username']) ?>;border-radius:50%"><?= e(mb_strtoupper(mb_substr($a['username'],0,2))) ?></span><span class="pdot" style="background:<?= $pc ?>"></span></span>
        <span class="nm"><?= e($a['username']) ?></span>
        <span class="cmp-chk"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
      </button>
    <?php endforeach; ?>
  </div>
  <div class="cmp-namewrap" id="cmpNameWrap"><input type="text" id="cmpGroupName" placeholder="Nombre del grupo…" autocomplete="off"></div>
  <div class="cmp-foot"><button type="button" class="cmp-cancel" onclick="chCompClose()">Cancelar</button><button type="button" class="cmp-go" id="cmpGo" onclick="chCmpGo()" disabled>Elige a alguien</button></div>
</div></div>

<div class="ch-main">
  <?php if($curRoom && $curRoomData): $lbl=room_label($curRoomData,$roomMembers,$adminName,$meId); $isGrp=($curRoomData['type']==='group'); $onlineNow=($curPres==='en línea'); ?>
    <div class="ch-top">
      <span class="cavwrap"<?= $isGrp?'':' data-uid="'.(int)$curOtherId.'"' ?> style="flex:none"><span class="cav" style="background:<?= avatar_color($isGrp?'g'.$curRoom.$lbl:$lbl) ?>;<?= $isGrp?'':'border-radius:50%' ?>"><?= e(mb_strtoupper(mb_substr($lbl,0,2))) ?></span><?php if(!$isGrp): ?><span class="pdot top-dot" id="chTopDot" style="background:<?= chat_presence_color($curPresState) ?>"></span><?php endif; ?></span>
      <div class="ti"><b><?php if($isGrp): ?><?= e($lbl) ?><?php else: ?><span data-uid="<?= (int)$curOtherId ?>"><?= e($lbl) ?></span><?php endif; ?></b><span id="chSub" data-def="<?= e($isGrp ? count($roomMembers[$curRoom]??[]).' miembros' : ($curPres?:'Mensaje directo')) ?>"><?php if(!$isGrp): ?><span class="pdot inl" id="chSubDot" style="background:<?= chat_presence_color($curPresState) ?>"></span><span<?= $curPresState==='online'?' class="online"':'' ?>><?= e($curPres?:'Mensaje directo') ?></span><?php else: ?><?= (int)count($roomMembers[$curRoom]??[]) ?> miembros<?php endif; ?></span></div>
      <button class="ch-hdbtn" title="Opciones" onclick="<?= $isGrp?'chGrpOpen()':'' ?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.9"/><circle cx="12" cy="12" r="1.9"/><circle cx="12" cy="19" r="1.9"/></svg></button>
    </div>
    <div class="ch-feed" id="chFeed"></div>
    <button class="ch-fab" id="chFab" onclick="chFeedBottom(true)"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg><span class="fbadge" id="chFabN" style="display:none">0</span></button>
    <div class="ch-typing" id="chTyping"><span class="tdots"><i></i><i></i><i></i></span><span id="chTypingTx"></span></div>
    <div class="ch-replybar" id="chReplyBar"><span class="rb-l"></span><div class="rb-b"><div class="rb-a" id="chReplyA"></div><div class="rb-t" id="chReplyT"></div></div><button class="rb-x" onclick="chReplyCancel()">✕</button></div>
    <div class="ch-pending" id="chPending"></div>
    <div class="ch-compose">
      <button type="button" class="ch-tool" data-emoji-btn title="Emoji" onclick="erpEmojiPicker(this,null,document.getElementById('chBody'))"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5a4.5 4.5 0 0 0 7 0"/><path d="M9 9.5h.01M15 9.5h.01"/></svg></button>
      <button class="ch-tool" title="Adjuntar" onclick="document.getElementById('chFiles').click()"><svg viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a5 5 0 0 1-7.07-7.07l9.19-9.19a3 3 0 0 1 4.24 4.24l-9.2 9.19a1 1 0 0 1-1.41-1.41l8.49-8.49"/></svg></button>
      <button class="ch-tool" id="chMicBtn" title="Dictar por voz" onclick="chVoiceToggle()"><svg viewBox="0 0 24 24"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/></svg></button>
      <input type="file" id="chFiles" multiple style="display:none" onchange="chPickFiles(this.files);this.value=''">
      <textarea id="chBody" class="no-emoji" placeholder="Escribe un mensaje…" rows="1" oninput="chGrow(this);chTyperPing();chMentionScan()" onkeydown="chKey(event)" onpaste="chPaste(event)"></textarea>
      <button class="ch-send" onclick="chSend()" title="Enviar"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg></button>
      <div class="ch-emojipop" id="chEmojiPop"></div>
      <div class="ch-mentionpop" id="chMentionPop"></div>
    </div>
  <?php else: ?>
    <div class="ch-empty"><?= ic('chat',40) ?><div><b>Selecciona una conversación</b><br>o empieza un grupo / mensaje directo.</div></div>
  <?php endif; ?>
</div>
<div class="ch-ctx" id="chCtx"></div>

<div class="ch-ov" id="chNew"><div class="ch-modal">
  <div class="mh">Nuevo grupo</div>
  <div class="mbdy">
    <input type="text" id="grpName" placeholder="Nombre del grupo (ej: Equipo SEO)">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--label);font-weight:700;margin-bottom:6px">Miembros</div>
    <?php foreach($admins as $a): if((int)$a['id']===$meId) continue; ?>
      <label class="ch-mem"><span class="cav" style="background:<?= avatar_color($a['username']) ?>"><?= e(mb_strtoupper(mb_substr($a['username'],0,2))) ?></span><b><?= e($a['username']) ?></b><input type="checkbox" value="<?= (int)$a['id'] ?>"></label>
    <?php endforeach; ?>
  </div>
  <div class="mf"><button class="btn ghost sm" onclick="chCloseNew()">Cancelar</button><button class="btn sm" onclick="chCreateGroup()">Crear grupo</button></div>
</div></div>

<?php if($curRoom && $curRoomData && $curRoomData['type']==='group'): $gm=$roomMembers[$curRoom]??[]; ?>
<div class="ch-ov" id="chGrp"><div class="ch-modal">
  <div class="mh">Info del grupo</div>
  <div class="mbdy">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--label);font-weight:700;margin-bottom:6px">Nombre</div>
    <div style="display:flex;gap:8px;margin-bottom:16px"><input type="text" id="grpRename" value="<?= e($curRoomData['name']) ?>" style="margin:0"><button class="btn sm" onclick="chGrpRename()">Guardar</button></div>
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--label);font-weight:700;margin-bottom:6px">Miembros (<?= count($gm) ?>)</div>
    <?php foreach($gm as $mid): if(!isset($adminName[$mid])) continue; ?>
      <div class="ch-mem"><span class="cav" style="background:<?= avatar_color($adminName[$mid]) ?>"><?= e(mb_strtoupper(mb_substr($adminName[$mid],0,2))) ?></span><b><?= e($adminName[$mid]) ?><?= $mid===$meId?' (tú)':'' ?></b><?php if($mid!==$meId): ?><button class="btn ghost sm" onclick="chGrpRemove(<?= (int)$mid ?>)">Quitar</button><?php endif; ?></div>
    <?php endforeach; ?>
    <?php $noIn=array_filter($admins,function($a) use($gm){ return !in_array((int)$a['id'],$gm,true); }); if($noIn): ?>
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--label);font-weight:700;margin:14px 0 6px">Añadir</div>
    <?php foreach($noIn as $a): ?>
      <div class="ch-mem"><span class="cav" style="background:<?= avatar_color($a['username']) ?>"><?= e(mb_strtoupper(mb_substr($a['username'],0,2))) ?></span><b><?= e($a['username']) ?></b><button class="btn sm" onclick="chGrpAdd(<?= (int)$a['id'] ?>)">Añadir</button></div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <div class="mf"><button class="btn ghost sm" style="color:#dd5b52" onclick="chGrpLeave()">Salir del grupo</button><span style="flex:1"></span><button class="btn ghost sm" onclick="chGrpClose()">Cerrar</button></div>
</div></div>
<?php endif; ?>

<script>
var CH_ROOM=<?= (int)$curRoom ?>;
var CH_LAST=<?= $msgs?(int)$msgs[count($msgs)-1]['id']:0 ?>;
var CH_ME=<?= $meId ?>;
var CH_ISGROUP=<?= ($curRoomData && $curRoomData['type']==='group')?'true':'false' ?>;
var CH_READ=<?= (int)$readUpto ?>;
var CH_MSGS=<?= json_encode($CH_MSGS, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
var CH_MEMBERS=[<?php foreach($admins as $a): ?>{id:<?= (int)$a['id'] ?>,name:<?= json_encode($a['username'],JSON_UNESCAPED_UNICODE) ?>,color:<?= json_encode(avatar_color($a['username'])) ?>,ini:<?= json_encode(mb_strtoupper(mb_substr($a['username'],0,2))) ?>},<?php endforeach; ?>];
window.CH_ROOM=CH_ROOM;
var REACT_QUICK=['👍','❤️','😂','😮','😢','🙏','🔥','👏'];
var EMOJI_SET=['😀','😁','😂','🤣','😊','😍','😘','😎','🤩','🥳','😉','🙂','🙃','😅','😇','🤗','🤔','🤨','😐','😴','😌','😜','🤪','😝','😏','😒','🙄','😤','😡','🤬','😱','😰','😭','😢','😩','🥺','😳','🤯','🤫','🤥','😬','🙈','🙉','🙊','💪','👌','✌️','🤞','👍','👎','👏','🙏','🤝','💯','🔥','✨','⭐','🎉','🎊','❤️','🧡','💛','💚','💙','💜','🖤','💔','✅','❌','⚠️','💡','📌','📎','🚀','⏰','💰','📈','📉','☕','🍕'];
var CH_TICK1='<svg viewBox="0 0 18 13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 7l4 4 8-9"/></svg>';
var CH_TICK2='<svg viewBox="0 0 22 13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 7l4 4 8-9"/><path d="M9 11l2 0 8-9"/></svg>';
var CH_MSGMAP={};      // id -> payload (para responder/editar/copiar)
function esc(s){return(''+s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function ini2(s){return esc((''+s).substring(0,2).toUpperCase());}

/* ---------- Render ---------- */
function chCont(m){var i=CH_MSGS.indexOf(m);if(i<=0)return false;var p=CH_MSGS[i-1];return !!(p&&p.aid===m.aid&&p.day===m.day);}
function chBuildMsg(m){
  CH_MSGMAP[m.id]=m;
  var cont=chCont(m);
  var d=document.createElement('div'); d.className='ch-msg'+(m.mine?' mine':'')+(cont?' cont':''); d.setAttribute('data-mid',m.id);
  var av='<span class="mav" data-uid="'+m.aid+'" style="background:'+m.color+'">'+ini2(m.ini)+'</span>';
  var inner='';
  if(!m.mine && CH_ISGROUP && !cont) inner+='<div class="mnm" data-uid="'+m.aid+'" style="color:'+m.color+'">'+esc(m.author)+'</div>';
  if(m.reply) inner+='<div class="ch-quote" onclick="chGoto('+m.reply.id+')"><span class="qa">'+esc(m.reply.author)+'</span><span class="qx">'+esc(m.reply.ex)+'</span></div>';
  if(m.deleted){ inner+='<div class="mtx mdel">🚫 Este mensaje fue eliminado</div>'; }
  else{
    if(m.html) inner+='<div class="mtx">'+m.html+'</div>';
    if(m.attach&&m.attach.length) inner+=chAttHtml(m.attach);
  }
  var tick=m.mine?' <span class="mck" data-mid="'+m.id+'"></span>':'';
  var ed=m.edited?'<span class="medit">editado</span>':'';
  inner+='<div class="mt">'+esc(m.time)+ed+tick+'</div>';
  var reacts=(!m.deleted&&m.react&&m.react.length)?chReactHtml(m.id,m.react):null;
  var acts=m.deleted?'':('<div class="macts">'
      +'<button class="ch-macts-btn" title="Reaccionar" onclick="chQuickReact(event,'+m.id+')"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5a4.5 4.5 0 0 0 7 0"/><path d="M9 9.5h.01M15 9.5h.01"/></svg></button>'
      +'<button class="ch-macts-btn" title="Más" onclick="chMsgMenu(event,'+m.id+')"><svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><circle cx="5" cy="12" r="1.9"/><circle cx="12" cy="12" r="1.9"/><circle cx="19" cy="12" r="1.9"/></svg></button>'
    +'</div>');
  d.innerHTML=av+'<div class="mb">'+inner+'</div>'+acts;
  if(reacts) d.insertBefore(reacts,d.querySelector('.macts')||null);
  d.oncontextmenu=function(e){ if(!m.deleted) chMsgMenu(e,m.id); return m.deleted; };
  return d;
}
function chAttHtml(atts){var h='<div class="ch-atts">';atts.forEach(function(a){
  if(a.img) h+='<a class="ch-att-img" href="'+esc(a.url)+'" onclick="return chImg(this.href)"><img src="'+esc(a.url)+'" alt=""></a>';
  else h+='<a class="ch-att-file" href="'+esc(a.url)+'&dl=1"><span class="fi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg></span><span class="fn">'+esc(a.orig)+'</span></a>';
});return h+'</div>';}
/* El emoji va como textContent y se pasa por data-attributes, nunca dentro de
   un onclick con comillas: si un valor antiguoTraversaliese fuera de la lista
   blanca, al menos no romperia el HTML ni ejecutaria codigo. */
function chReactHtml(mid,react){
  var box=document.createElement('div');box.className='ch-reacts';
  react.forEach(function(r){
    var em=String(r.emoji);
    var s=document.createElement('span');
    s.className='ch-rc'+(r.mine?' mine':'');
    s.setAttribute('data-mid',String(mid));s.setAttribute('data-emoji',em);
    s.appendChild(document.createTextNode(em));
    var n=document.createElement('span');n.className='n';n.textContent=String(r.count);
    s.appendChild(n);
    s.addEventListener('click',function(){chReact(mid,em);});
    box.appendChild(s);
  });
  return box;
}
function chImg(url){ if(window.lightbox) return lightbox(url); window.open(url,'_blank'); return false; }
function chGoto(id){var el=document.querySelector('#chFeed .ch-msg[data-mid="'+id+'"]');if(!el)return;el.scrollIntoView({block:'center',behavior:'smooth'});el.style.transition='background .3s';el.style.background='rgba(47,111,237,.10)';setTimeout(function(){el.style.background='';},900);}
function chRenderAll(){var f=document.getElementById('chFeed');if(!f)return;f.innerHTML='';var lastDay='';
  CH_MSGS.forEach(function(m){ if(m.day!==lastDay){lastDay=m.day;var dv=document.createElement('div');dv.className='ch-day';dv.textContent=m.datel;f.appendChild(dv);}
    f.appendChild(chBuildMsg(m)); });
  chApplyRead(CH_READ);chFeedBottom();}
function chAppendNew(m){var f=document.getElementById('chFeed');if(!f)return;
  if(document.querySelector('#chFeed .ch-msg[data-mid="'+m.id+'"]'))return;
  var last=CH_MSGS.length?CH_MSGS[CH_MSGS.length-1]:null;
  if(!last||last.day!==m.day){var dv=document.createElement('div');dv.className='ch-day';dv.textContent=m.datel;f.appendChild(dv);}
  CH_MSGS.push(m); f.appendChild(chBuildMsg(m));}

/* estados: reacción / editado / borrado de mensajes ya visibles */
function chApplyStates(states){ if(!states)return; states.forEach(function(s){
  var el=document.querySelector('#chFeed .ch-msg[data-mid="'+s.id+'"]'); if(!el)return; var m=CH_MSGMAP[s.id]; if(!m)return;
  var changed=false;
  if(s.deleted&&!m.deleted){ m.deleted=1;m.react=[];m.attach=[];m.html=''; changed=true; }
  else{
    if(s.edited&&!m.edited){ m.edited=1; changed=true; }
    if(s.html!==undefined && s.html!==m.html){ m.html=s.html; changed=true; }
    if(JSON.stringify(s.react||[])!==JSON.stringify(m.react||[])){ m.react=s.react||[]; changed=true; }
  }
  if(changed){ var nu=chBuildMsg(m); el.replaceWith(nu); }
});}

/* ---------- Tics / typing ---------- */
function chApplyRead(upto){if(upto==null)return;CH_READ=Math.max(CH_READ,upto);
  document.querySelectorAll('#chFeed .mck').forEach(function(s){var mid=parseInt(s.getAttribute('data-mid'),10)||0;var read=mid<=CH_READ;s.innerHTML=read?CH_TICK2:CH_TICK1;s.classList.toggle('read',read);});}
var CH_OTHER=<?= (int)$curOtherId ?>;
window._chPresmap=null; window._chTypingOn=false;
function chApplyPresmap(pm){ if(pm)window._chPresmap=pm; pm=window._chPresmap; if(!pm)return;
  document.querySelectorAll('#chScroll .cavwrap[data-uid]').forEach(function(w){var p=pm[w.getAttribute('data-uid')];var dot=w.querySelector('.pdot');if(dot&&p)dot.style.background=p.c;});
  if(!CH_ISGROUP && CH_OTHER && pm[CH_OTHER] && !window._chTypingOn){ var p=pm[CH_OTHER];
    var td=document.getElementById('chTopDot'); if(td)td.style.background=p.c;
    var sub=document.getElementById('chSub'); if(sub){ sub.setAttribute('data-def',p.t); sub.innerHTML='<span class="pdot inl" style="background:'+p.c+'"></span><span'+(p.s==='online'?' class="online"':'')+'>'+esc(p.t)+'</span>'; } } }
function chShowTyping(names){var bar=document.getElementById('chTyping'),tx=document.getElementById('chTypingTx'),sub=document.getElementById('chSub');
  if(!bar)return;
  if(names&&names.length){window._chTypingOn=true;tx.textContent=names.length===1?(names[0]+' está escribiendo…'):(names.length+' escribiendo…');bar.classList.add('on');if(sub)sub.textContent='escribiendo…';}
  else{window._chTypingOn=false;bar.classList.remove('on');
    if(sub){ if(CH_ISGROUP) sub.textContent=sub.getAttribute('data-def')||''; else chApplyPresmap(); }}}
var _chTypeLast=0;
function chTyperPing(){if(!CH_ROOM)return;var now=Date.now();if(now-_chTypeLast<2500)return;_chTypeLast=now;
  var fd=new URLSearchParams();fd.set('action','typing');fd.set('room_id',CH_ROOM);fetch('chat.php',{method:'POST',body:fd}).catch(function(){});}

/* ---------- Compositor ---------- */
window._chPending=[]; var CH_REPLY=0;
function chFeedBottom(force){var f=document.getElementById('chFeed');if(f){f.scrollTop=f.scrollHeight;}var fab=document.getElementById('chFab');if(fab){fab.classList.remove('on');var n=document.getElementById('chFabN');if(n)n.style.display='none';}}
function chGrow(t){t.style.height='auto';t.style.height=Math.min(t.scrollHeight,120)+'px';}
function chKey(e){ if(chMentionNav(e))return; if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();chSend();} }
function chReply(id){var m=CH_MSGMAP[id];if(!m)return;CH_REPLY=id;document.getElementById('chReplyA').textContent=m.mine?'Tú':m.author;
  document.getElementById('chReplyT').textContent=m.deleted?'mensaje eliminado':(m.html?m.html.replace(/<[^>]+>/g,''):'📎 Adjunto');
  document.getElementById('chReplyBar').classList.add('on');document.getElementById('chBody').focus();}
function chReplyCancel(){CH_REPLY=0;window._chEditing=0;document.getElementById('chReplyBar').classList.remove('on');}
function chPickFiles(files){for(var i=0;i<files.length;i++)window._chPending.push(files[i]);chRenderPending();}
function chPaste(e){var dt=e.clipboardData;if(!dt||!dt.files||!dt.files.length)return;var imgs=[];for(var i=0;i<dt.files.length;i++)if((dt.files[i].type||'').indexOf('image')===0)imgs.push(dt.files[i]);if(imgs.length){e.preventDefault();imgs.forEach(function(f){window._chPending.push(f);});chRenderPending();}}
function chRenderPending(){var box=document.getElementById('chPending');if(!box)return;box.innerHTML='';box.classList.toggle('on',window._chPending.length>0);
  window._chPending.forEach(function(f,idx){var el=document.createElement('div');el.className='ch-pend';
    if((f.type||'').indexOf('image')===0){var u=URL.createObjectURL(f);el.innerHTML='<img src="'+u+'">'+esc(f.name);}else el.textContent='📎 '+f.name;
    var x=document.createElement('button');x.className='px';x.textContent='✕';x.onclick=function(){window._chPending.splice(idx,1);chRenderPending();};el.appendChild(x);box.appendChild(el);});}
function chSend(){var t=document.getElementById('chBody');var b=t.value.trim();if((!b&&!window._chPending.length)||!CH_ROOM)return;
  var fd=new FormData();fd.append('action','send');fd.append('room_id',CH_ROOM);fd.append('body',b);if(CH_REPLY)fd.append('reply_to',CH_REPLY);
  window._chPending.forEach(function(f){fd.append('files[]',f);});
  t.value='';t.style.height='auto';window._chPending=[];chRenderPending();chReplyCancel();
  fetch('chat.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){if(j.ok)chPoll();}).catch(function(){});}

/* ---------- Reacciones / menú de mensaje ---------- */
function chReact(mid,em){var fd=new URLSearchParams();fd.set('action','react');fd.set('mid',mid);fd.set('emoji',em);
  fetch('chat.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(){chPoll();}).catch(function(){});chCtxClose();}
function chQuickReact(e,mid){e.stopPropagation();chMsgMenu(e,mid,true);}
function chMsgMenu(e,mid,onlyEmoji){e.preventDefault();e.stopPropagation();var m=CH_MSGMAP[mid];if(!m||m.deleted)return;
  var c=document.getElementById('chCtx');c.innerHTML='';
  var er=document.createElement('div');er.className='emojis';REACT_QUICK.forEach(function(em){var b=document.createElement('button');b.textContent=em;b.onclick=function(ev){ev.stopPropagation();chReact(mid,em);};er.appendChild(b);});
  /* «＋»: abre el picker global para reaccionar con CUALQUIER emoji (se queda abierto). */
  var more=document.createElement('button');more.className='rmore';more.title='Más emojis';more.innerHTML='<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 6v12M6 12h12"/></svg>';
  more.onclick=function(ev){ev.stopPropagation(); if(window.erpEmojiPicker){ erpEmojiPicker(more,function(ch){chReact(mid,ch);}); chCtxClose(); }};
  er.appendChild(more);
  c.appendChild(er);
  if(!onlyEmoji){
    function it(txt,fn,cls){var a=document.createElement('a');a.textContent=txt;if(cls)a.className=cls;a.onclick=function(ev){ev.stopPropagation();chCtxClose();fn();};c.appendChild(a);}
    it('Responder',function(){chReply(mid);});
    it('Copiar',function(){var tmp=(m.html||'').replace(/<[^>]+>/g,'');try{navigator.clipboard.writeText(tmp);}catch(e){}if(window.toast)toast('Copiado');});
    if(!m.mine) it('Ver perfil de '+m.author,function(){location.href='perfil.php?id='+m.aid;});
    if(m.mine){ it('Editar',function(){chEditMsg(mid);}); it('Eliminar',function(){chDelMsg(mid);},'danger'); }
  }
  c.classList.add('on');
  var x=e.clientX||(e.touches&&e.touches[0].clientX)||200, y=e.clientY||200;
  var w=c.offsetWidth,h=c.offsetHeight;
  c.style.left=Math.min(x,window.innerWidth-w-8)+'px';c.style.top=Math.min(y,window.innerHeight-h-8)+'px';}
function chCtxClose(){var c=document.getElementById('chCtx');if(c)c.classList.remove('on');}
document.addEventListener('click',function(e){ if(!e.target.closest('#chCtx')) chCtxClose(); });
function chEditMsg(mid){var m=CH_MSGMAP[mid];if(!m)return;var cur=(m.html||'').replace(/<br\s*\/?>(?=)/gi,'\n').replace(/<[^>]+>/g,'');
  var t=document.getElementById('chBody');t.value=cur;chGrow(t);t.focus();window._chEditing=mid;
  document.getElementById('chReplyA').textContent='Editando mensaje';document.getElementById('chReplyT').textContent=cur.slice(0,60);document.getElementById('chReplyBar').classList.add('on');CH_REPLY=0;}
function chDelMsg(mid){ (window.erpConfirm?erpConfirm('¿Eliminar este mensaje para todos?',{titulo:'Eliminar mensaje',danger:true,ok:'Eliminar'}):Promise.resolve(confirm('¿Eliminar mensaje?'))).then(function(ok){if(!ok)return;
  var fd=new URLSearchParams();fd.set('action','del_msg');fd.set('mid',mid);fetch('chat.php',{method:'POST',body:fd}).then(function(){chPoll();});});}

/* editar: si estamos editando, chSend cambia de acción */
var _origSend=chSend;
chSend=function(){ if(window._chEditing){ var t=document.getElementById('chBody');var b=t.value.trim();var mid=window._chEditing;
    if(!b){window._chEditing=0;chReplyCancel();t.value='';return;}
    var fd=new URLSearchParams();fd.set('action','edit_msg');fd.set('mid',mid);fd.set('body',b);
    t.value='';t.style.height='auto';window._chEditing=0;chReplyCancel();
    fetch('chat.php',{method:'POST',body:fd}).then(function(){chPoll();});return; }
  _origSend(); };
function chReplyCancelWrap(){window._chEditing=0;chReplyCancel();}

/* ---------- Emoji picker del compositor ---------- */
function chEmojiToggle(e){e.stopPropagation();var p=document.getElementById('chEmojiPop');if(!p)return;
  if(!p.dataset.built){EMOJI_SET.forEach(function(em){var b=document.createElement('button');b.textContent=em;b.onclick=function(ev){ev.stopPropagation();chInsert(em);};p.appendChild(b);});p.dataset.built='1';}
  p.classList.toggle('on');}
function chInsert(txt){var t=document.getElementById('chBody');var s=t.selectionStart||t.value.length;t.value=t.value.slice(0,s)+txt+t.value.slice(t.selectionEnd||s);t.focus();t.selectionStart=t.selectionEnd=s+txt.length;chGrow(t);}
document.addEventListener('click',function(e){var p=document.getElementById('chEmojiPop');if(p&&p.classList.contains('on')&&!e.target.closest('#chEmojiPop')&&!e.target.closest('[title=Emoji]'))p.classList.remove('on');});

/* ---------- Dictado por voz (reconocimiento del navegador, es-ES) ---------- */
var _chRec=null,_chRecOn=false,_chRecBase='';
function chVoiceToggle(){
  var SR=window.SpeechRecognition||window.webkitSpeechRecognition;var btn=document.getElementById('chMicBtn');
  if(!SR){ if(window.toast)toast('Tu navegador no permite el dictado por voz (prueba con Chrome)','err'); return; }
  if(_chRecOn){ try{_chRec.stop();}catch(e){} return; }
  _chRec=new SR();_chRec.lang='es-ES';_chRec.continuous=true;_chRec.interimResults=true;
  var t=document.getElementById('chBody');_chRecBase=t.value?(t.value.replace(/\s+$/,'')+' '):'';
  _chRec.onstart=function(){_chRecOn=true;if(btn){btn.classList.add('rec');btn.title='Detener dictado';}};
  _chRec.onerror=function(e){ if(e&&e.error==='not-allowed'){ if(window.toast)toast('Da permiso al micrófono para dictar','err'); } };
  _chRec.onend=function(){_chRecOn=false;if(btn){btn.classList.remove('rec');btn.title='Dictar por voz';}};
  _chRec.onresult=function(ev){var fin='',intr='';for(var i=ev.resultIndex;i<ev.results.length;i++){var r=ev.results[i];if(r.isFinal)fin+=r[0].transcript;else intr+=r[0].transcript;}
    if(fin)_chRecBase=(_chRecBase.replace(/\s+$/,'')+' '+fin.trim()+' ');
    t.value=(_chRecBase+intr).replace(/^\s+/,'');chGrow(t);};
  try{_chRec.start();}catch(e){}
}

/* ---------- Menciones ---------- */
var _mentSel=-1,_mentItems=[];
function chMentionScan(){var t=document.getElementById('chBody');var pop=document.getElementById('chMentionPop');if(!pop)return;
  var pos=t.selectionStart;var pre=t.value.slice(0,pos);var mm=pre.match(/@([\p{L}0-9_.\-]*)$/u);
  if(!mm){pop.classList.remove('on');_mentItems=[];return;}
  var q=mm[1].toLowerCase();_mentItems=CH_MEMBERS.filter(function(m){return m.id!==CH_ME&&m.name.toLowerCase().indexOf(q)>=0;}).slice(0,6);
  if(!_mentItems.length){pop.classList.remove('on');return;}
  pop.innerHTML='';_mentItems.forEach(function(m,i){var b=document.createElement('button');b.innerHTML='<span class="mav" style="background:'+m.color+'">'+esc(m.ini)+'</span>'+esc(m.name);b.onclick=function(){chMentionPick(i);};pop.appendChild(b);});
  _mentSel=0;chMentionHi();pop.classList.add('on');}
function chMentionHi(){var bs=document.querySelectorAll('#chMentionPop button');bs.forEach(function(b,i){b.classList.toggle('sel',i===_mentSel);});}
function chMentionNav(e){var pop=document.getElementById('chMentionPop');if(!pop||!pop.classList.contains('on'))return false;
  if(e.key==='ArrowDown'){e.preventDefault();_mentSel=(_mentSel+1)%_mentItems.length;chMentionHi();return true;}
  if(e.key==='ArrowUp'){e.preventDefault();_mentSel=(_mentSel-1+_mentItems.length)%_mentItems.length;chMentionHi();return true;}
  if(e.key==='Enter'||e.key==='Tab'){e.preventDefault();chMentionPick(_mentSel);return true;}
  if(e.key==='Escape'){pop.classList.remove('on');return true;}
  return false;}
function chMentionPick(i){var m=_mentItems[i];if(!m)return;var t=document.getElementById('chBody');var pos=t.selectionStart;var pre=t.value.slice(0,pos);
  pre=pre.replace(/@([\p{L}0-9_.\-]*)$/u,'@'+m.name+' ');t.value=pre+t.value.slice(pos);t.focus();t.selectionStart=t.selectionEnd=pre.length;
  document.getElementById('chMentionPop').classList.remove('on');chGrow(t);}

/* ---------- Sidebar + FAB ---------- */
function chUpdSidebar(unread){if(!unread)return;
  document.querySelectorAll('#chScroll a.ch-item').forEach(function(a){var m=(a.getAttribute('href')||'').match(/room=(\d+)/);if(!m)return;var rid=parseInt(m[1],10);var n=unread[rid]||0;var ub=a.querySelector('.ub');
    if(n>0){if(!ub){ub=document.createElement('span');ub.className='ub';a.appendChild(ub);}ub.textContent=n;}else if(ub){ub.remove();}});}
var _chNewCount=0;
function chFeedNear(){var f=document.getElementById('chFeed');if(!f)return true;return (f.scrollHeight-f.scrollTop-f.clientHeight)<120;}
(function(){var f=document.getElementById('chFeed');if(f)f.addEventListener('scroll',function(){var fab=document.getElementById('chFab');if(!fab)return;if(chFeedNear()){fab.classList.remove('on');_chNewCount=0;var n=document.getElementById('chFabN');if(n)n.style.display='none';}else fab.classList.add('on');});})();

/* ---------- Poll ---------- */
function chPoll(){if(!CH_ROOM)return;var fd=new URLSearchParams();fd.set('action','poll');fd.set('room_id',CH_ROOM);fd.set('after',CH_LAST);
  fetch('chat.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){if(!j.ok)return;
    var near=chFeedNear();
    if(j.messages&&j.messages.length){ j.messages.forEach(function(m){chAppendNew(m);CH_LAST=Math.max(CH_LAST,parseInt(m.id,10));if(!m.mine&&!near){_chNewCount++;}});
      if(near)chFeedBottom(); else{var fab=document.getElementById('chFab');if(fab){fab.classList.add('on');if(_chNewCount>0){var n=document.getElementById('chFabN');if(n){n.style.display='flex';n.textContent=_chNewCount;}}}} }
    chApplyStates(j.states);
    chApplyRead(j.read);
    chShowTyping(j.typing||[]);
    chUpdSidebar(j.unread);
    chApplyPresmap(j.presmap);
  }).catch(function(){});}

/* ---------- Sidebar acciones (grupo, dm) ---------- */
function chOpenNew(){document.getElementById('chNew').classList.add('on');document.getElementById('grpName').focus();}
function chCloseNew(){document.getElementById('chNew').classList.remove('on');}
function chCreateGroup(){var name=document.getElementById('grpName').value.trim();if(!name){if(window.toast)toast('Ponle un nombre al grupo','err');return;}
  var mem=[];document.querySelectorAll('#chNew input[type=checkbox]:checked').forEach(function(c){mem.push(c.value);});
  var fd=new URLSearchParams();fd.set('action','create_group');fd.set('name',name);mem.forEach(function(m){fd.append('members[]',m);});
  fetch('chat.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){if(j.ok)location.href='chat.php?room='+j.room;});}
function chOpenDm(other){var fd=new URLSearchParams();fd.set('action','open_dm');fd.set('other',other);
  fetch('chat.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){if(j.ok)location.href='chat.php?room='+j.room;});}
function chFilter(v){v=(v||'').toLowerCase();document.querySelectorAll('#chScroll .ch-item').forEach(function(it){var n=it.getAttribute('data-name')||'';it.style.display=(!v||n.indexOf(v)>=0)?'':'none';});}
var _cmpSel=[];
function chComposeOpen(){var c=document.getElementById('chCompose');if(!c)return;
  _cmpSel=[];document.querySelectorAll('#chCompose .cmp-person.sel').forEach(function(b){b.classList.remove('sel');});
  var gn=document.getElementById('cmpGroupName');if(gn)gn.value='';chCmpUpdate();
  c.classList.add('on');var s=document.getElementById('cmpSearch');if(s){s.value='';chCmpFilter('');setTimeout(function(){s.focus();},60);}}
function chCompClose(){var c=document.getElementById('chCompose');if(c)c.classList.remove('on');}
function chCmpFilter(v){v=(v||'').toLowerCase();document.querySelectorAll('#chCompose .cmp-person').forEach(function(it){var n=it.getAttribute('data-name')||'';it.style.display=(!v||n.indexOf(v)>=0)?'':'none';});}
function chCmpToggle(btn){var id=parseInt(btn.getAttribute('data-id'),10);var i=_cmpSel.indexOf(id);
  if(i>=0){_cmpSel.splice(i,1);btn.classList.remove('sel');}else{_cmpSel.push(id);btn.classList.add('sel');}chCmpUpdate();}
function chCmpUpdate(){var go=document.getElementById('cmpGo'),nw=document.getElementById('cmpNameWrap');if(!go)return;
  if(_cmpSel.length===0){go.disabled=true;go.textContent='Elige a alguien';nw.classList.remove('on');}
  else if(_cmpSel.length===1){go.disabled=false;go.textContent='Enviar mensaje';nw.classList.remove('on');}
  else{go.disabled=false;go.textContent='Crear grupo · '+_cmpSel.length;nw.classList.add('on');}}
function chCmpGo(){ if(_cmpSel.length===1){chOpenDm(_cmpSel[0]);return;}
  if(_cmpSel.length>=2){var name=(document.getElementById('cmpGroupName').value||'').trim();
    if(!name){if(window.toast)toast('Ponle un nombre al grupo','err');document.getElementById('cmpGroupName').focus();return;}
    var fd=new URLSearchParams();fd.set('action','create_group');fd.set('name',name);_cmpSel.forEach(function(m){fd.append('members[]',m);});
    fetch('chat.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){if(j.ok)location.href='chat.php?room='+j.room;});}}
/* ---------- Gestión de grupo ---------- */
function chGrpOpen(){var g=document.getElementById('chGrp');if(g)g.classList.add('on');}
function chGrpClose(){var g=document.getElementById('chGrp');if(g)g.classList.remove('on');}
function chGrpPost(action,extra){var fd=new URLSearchParams();fd.set('action',action);fd.set('room_id',CH_ROOM);for(var k in (extra||{}))fd.set(k,extra[k]);return fetch('chat.php',{method:'POST',body:fd}).then(function(r){return r.json();});}
function chGrpRename(){var n=document.getElementById('grpRename').value.trim();if(!n)return;chGrpPost('grp_rename',{name:n}).then(function(){location.reload();});}
function chGrpAdd(uid){chGrpPost('grp_add',{uid:uid}).then(function(){location.reload();});}
function chGrpRemove(uid){chGrpPost('grp_remove',{uid:uid}).then(function(){location.reload();});}
function chGrpLeave(){(window.erpConfirm?erpConfirm('¿Salir de este grupo?',{titulo:'Salir del grupo',danger:true,ok:'Salir'}):Promise.resolve(confirm('¿Salir?'))).then(function(ok){if(ok)chGrpPost('grp_leave',{}).then(function(){location.href='chat.php';});});}

var _chNew=document.getElementById('chNew');if(_chNew)_chNew.addEventListener('click',function(e){if(e.target===this)chCloseNew();});
var _chCmp=document.getElementById('chCompose');if(_chCmp)_chCmp.addEventListener('click',function(e){if(e.target===this)chCompClose();});
var _chGrp=document.getElementById('chGrp');if(_chGrp)_chGrp.addEventListener('click',function(e){if(e.target===this)chGrpClose();});

/* ── Adaptación a móvil: navegación tipo app entre lista y conversación ──
   En teléfono no caben las dos vistas; «volver» destapa la lista sin recargar,
   y tocar una sala (enlace normal) abre su conversación a pantalla completa. */
function chMobBack(){var w=document.querySelector('.erp-wrap');if(w)w.classList.add('ch-show-list');}
(function(){
  var top=document.querySelector('.ch-main .ch-top');
  if(top){
    var b=document.createElement('button');b.type='button';b.className='ch-mback';
    b.setAttribute('aria-label','Volver a conversaciones');b.title='Conversaciones';
    b.innerHTML='<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>';
    b.onclick=chMobBack;top.insertBefore(b,top.firstChild);
  }
  /* Sin sala abierta, en móvil se arranca en la lista, no en la conversación vacía. */
  if(!CH_ROOM){var w=document.querySelector('.erp-wrap');if(w)w.classList.add('ch-show-list');}
})();

chRenderAll();
if(CH_ROOM)setInterval(chPoll,2500);
</script>
<?php erp_foot(); ?>
