<?php
/* CRM v1.3 — Fase 1+2+3: tabla de contactos + perfil pop-up + botones de acción.
   Estética minimal (Apple / ClickUp / Untitled UI). */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
/* Borrar en el ERP no es definitivo: pasa por la papelera y se puede deshacer. */
require_once __DIR__ . '/lib/papelera.php';
require_once __DIR__ . '/lib/crm_lib.php';
require_once __DIR__ . '/lib/imagen.php';   // reduce las fotos subidas (ahorro de espacio)
ensure_crm_schema();
/* Fase 5: los puentes entre módulos (lead -> cliente, negocio -> factura). */
require_once __DIR__ . '/lib/puentes.php';
ensure_puentes_schema();

/* Quién está usando la página. Sin esto los comentarios y las vistas guardadas
   se archivaban a nombre del usuario 0, que no existe. */
$me   = current_admin();
$meId = (int)($me['id'] ?? 0);

$STAGES   = crm_stages();
$ORIGENES = crm_origenes();
$SERVICIOS= crm_servicios();
$SECTORS  = crm_sectors();
$responsables = db()->query('SELECT id, username FROM admins ORDER BY username')->fetchAll();
$respMap=[]; foreach($responsables as $r) $respMap[(int)$r['id']]=$r['username'];
$GLOBALS['CRM_MENTION']=[]; foreach($respMap as $rid=>$rn) $GLOBALS['CRM_MENTION'][mb_strtolower($rn)]=$rn;

/* mantiene los filtros en la URL tras un POST */
function qs(){ $keep=['q','sector','origen','fase','servicio','prop','vmin','vmax','fdesde','fhasta','quick','tag','sort','dir'];
  $p=[]; foreach($keep as $k){ if(($_GET[$k]??'')!=='') $p[$k]=$_GET[$k]; } return $p?('?'.http_build_query($p)):''; }

/* ---------------- POST ---------------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && can_edit()) {
  $a=$_POST['action']??'';
  if ($a==='inline') {
    $id=(int)($_POST['id']??0); $field=$_POST['field']??''; $val=(string)($_POST['val']??'');
    /* «fase» entra aquí: antes solo se podía cambiar arrastrando la tarjeta en
       Negocio, así que un contacto sin negocio se quedaba en Lead nuevo de por vida. */
    $allowed=['nombre','empresa','sector','email','telefono','whatsapp','linkedin','web','origen_lead','valor','proxima_accion','fecha_prox','propietario_id','fecha_ultimo_contacto','servicio_json','fase'];
    if ($id && in_array($field,$allowed,true)) {
      $ok=true;
      if ($field==='valor') $store=num_es($val);   // «12.5» son 12,5 € y no 125 €
      elseif (in_array($field,['fecha_prox','fecha_ultimo_contacto'],true)) $store=($val!==''?$val:null);
      elseif ($field==='propietario_id') $store=($val!==''?(int)$val:null);
      elseif ($field==='fase') { $ok=isset($STAGES[$val]); $store=$val; }   // solo fases que existen
      else $store=$val;
      if ($ok) {
        db()->prepare("UPDATE contacts SET `$field`=? WHERE id=?")->execute([$store,$id]);
        if ($field==='fase') crm_activity($id,null,'fase','Fase cambiada a '.($STAGES[$val]['nombre']??$val));
      }
    }
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
  }
  if ($a==='new_contact') {
    $nombre=trim($_POST['nombre']??'');
    if ($nombre!==''){
      db()->prepare('INSERT INTO contacts (nombre,empresa,sector,email,telefono,origen_lead,propietario_id,fase) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$nombre, trim($_POST['empresa']??''), trim($_POST['sector']??'')?:null, trim($_POST['email']??'')?:null,
                   trim($_POST['telefono']??'')?:null, trim($_POST['origen_lead']??'')?:null,
                   ($_POST['propietario_id']??'')!==''?(int)$_POST['propietario_id']:null, 'lead_nuevo']);
      crm_activity((int)db()->lastInsertId(),null,'creado','Contacto creado');
      $_SESSION['crm_flash']='Contacto creado';
    }
    header('Location: crm.php'.qs()); exit;
  }
  if ($a==='del_contact') {
    $cId=(int)($_POST['id']??0);
    $cn=db()->prepare('SELECT nombre,empresa FROM contacts WHERE id=?'); $cn->execute([$cId]); $cRow=$cn->fetch();
    $cNom=trim((string)($cRow['nombre'] ?? '')) ?: trim((string)($cRow['empresa'] ?? ''));
    /* El contacto se guarda con sus etiquetas y su historial: al deshacer vuelve
       la ficha entera, no un nombre suelto. */
    pap_borrar_flash('contacts', $cId, 'contacto', $cNom,
        [['tabla'=>'contact_tags','fk'=>'contact_id'],['tabla'=>'activities','fk'=>'contact_id'],['tabla'=>'comments','fk'=>'contact_id']],
        $cNom!=='' ? 'Contacto «'.$cNom.'» eliminado' : 'Contacto eliminado');
    db()->prepare('DELETE FROM contacts WHERE id=?')->execute([$cId]);
    header('Location: crm.php'.qs()); exit;
  }
  if ($a==='add_comment') {
    $cid=(int)($_POST['cid']??0); $tipo=$_POST['tipo']??'nota'; $body=trim($_POST['body']??'');
    if(!array_key_exists($tipo,crm_comment_tipos())) $tipo='nota';
    if($cid && $body!==''){
      preg_match_all('/@([\p{L}0-9_.\-]+)/u',$body,$mm); $ment=$mm[1]?json_encode(array_values($mm[1])):null;
      db()->prepare('INSERT INTO comments (contact_id,autor_id,tipo,contenido,menciones,fecha) VALUES (?,?,?,?,?,NOW())')->execute([$cid,(int)$me['id'],$tipo,$body,$ment]);
      $lbl=crm_comment_tipos()[$tipo][0];
      crm_activity($cid,null,$tipo,$lbl.': '.mb_substr($body,0,80));
      if(crm_comment_tipos()[$tipo][1]) db()->prepare('UPDATE contacts SET fecha_ultimo_contacto=CURDATE() WHERE id=?')->execute([$cid]);
      crm_sync_ultima($cid);
    }
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
  }
  if ($a==='del_comment') {
    $id=(int)($_POST['id']??0); $cid=(int)($_POST['cid']??0);
    if(is_owner()) db()->prepare('DELETE FROM comments WHERE id=?')->execute([$id]);
    else db()->prepare('DELETE FROM comments WHERE id=? AND autor_id=?')->execute([$id,(int)$me['id']]);
    crm_sync_ultima($cid);
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
  }
  if ($a==='save_billing') {
    $cid=(int)($_POST['cid']??0); $field=$_POST['field']??''; $val=trim($_POST['val']??'');
    $allowed=['razon_social','cif','direccion','cp','ciudad','provincia','pais','email_facturacion','iban'];
    if($cid && in_array($field,$allowed,true)){
      db()->prepare('INSERT INTO billing_data (contact_id) VALUES (?) ON DUPLICATE KEY UPDATE contact_id=contact_id')->execute([$cid]);
      db()->prepare("UPDATE billing_data SET `$field`=? WHERE contact_id=?")->execute([$val!==''?$val:null,$cid]);
    }
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
  }
  if ($a==='add_proposal') {
    $cid=(int)($_POST['cid']??0);
    if($cid){
      db()->prepare('INSERT INTO proposals (contact_id,nombre,importe,estado,fecha_envio,url_archivo) VALUES (?,?,?,?,?,?)')
        ->execute([$cid, trim($_POST['nombre']??'')?:'Propuesta', num_es($_POST['importe']??''),
                   in_array($_POST['estado']??'',['enviada','vista','aceptada','rechazada'],true)?$_POST['estado']:'enviada',
                   ($_POST['fecha']??'')!==''?$_POST['fecha']:date('Y-m-d'), trim($_POST['url']??'')?:null]);
      crm_activity($cid,null,'propuesta','Propuesta enviada');
    }
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
  }
  if ($a==='del_proposal') { db()->prepare('DELETE FROM proposals WHERE id=?')->execute([(int)($_POST['id']??0)]); header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit; }
  if ($a==='crm_act') {
    $cid=(int)($_POST['cid']??0); $tipo=$_POST['tipo']??'nota'; crm_activity($cid,null,$tipo,trim($_POST['desc']??''));
    if(in_array($tipo,['llamada','email','whatsapp','reunion'],true)) db()->prepare('UPDATE contacts SET fecha_ultimo_contacto=CURDATE() WHERE id=?')->execute([$cid]);
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
  }
  /* Convertir el lead en cliente de verdad. El trabajo lo hace lib/puentes.php
     para que salga igual desde aquí, desde Negocio o desde la ficha del cliente. */
  if ($a==='to_client') {
    $r = pu_lead_a_cliente((int)($_POST['cid']??0));
    header('Content-Type: application/json'); echo json_encode($r); exit;
  }
  if ($a==='meeting') {
    crm_meetings_ensure();
    $cid=(int)($_POST['cid']??0); $fecha=$_POST['fecha']??''; $hora=$_POST['hora']??'';
    $iso=''; if(preg_match('#^(\d{2})/(\d{2})/(\d{4})$#',$fecha,$mm)) $iso="$mm[3]-$mm[2]-$mm[1]";
    $mid=0;
    if($cid && $iso){ db()->prepare("INSERT INTO crm_meetings (contact_id,fecha,hora,estado) VALUES (?,?,?,'agendada')")->execute([$cid,$iso,$hora]); $mid=(int)db()->lastInsertId(); }
    crm_activity($cid,null,'reunion','Reunión agendada para '.$fecha.' '.$hora);
    db()->prepare('UPDATE contacts SET fecha_ultimo_contacto=CURDATE() WHERE id=?')->execute([$cid]);
    header('Content-Type: application/json'); echo json_encode(['ok'=>1,'mid'=>$mid]); exit;
  }
  /* Trae el resumen que Gemini adjunta al evento de Google tras la reunión y lo guarda en la reunión del CRM. */
  if ($a==='meeting_notes') {
    crm_meetings_ensure();
    require_once __DIR__.'/lib/gcal.php';
    $mid=(int)($_POST['mid']??0); $cid=(int)($_POST['cid']??0);
    header('Content-Type: application/json');
    if(!$mid){ echo json_encode(['ok'=>0,'msg'=>'Falta la reunión.']); exit; }
    $res=gcal_meeting_notes($meId,$mid);
    if($res===null){ echo json_encode(['ok'=>0,'msg'=>'No estás conectado a Google Calendar.']); exit; }
    if(empty($res['found'])){ echo json_encode(['ok'=>0,'msg'=>'Todavía no encuentro el evento de esta reunión en Google. Se enlaza al agendarla desde aquí.']); exit; }
    $docs=$res['docs']??[];
    if(!$docs && ($res['descripcion']??'')===''){ echo json_encode(['ok'=>0,'msg'=>'El evento existe pero aún no tiene notas de Gemini adjuntas. Suelen aparecer un rato después de la reunión.']); exit; }
    /* Guardamos el/los enlaces al Doc del resumen; si Gemini metió texto en la descripción, lo añadimos a las notas. */
    db()->prepare('UPDATE crm_meetings SET notas_doc=? WHERE id=? AND contact_id=?')->execute([json_encode($docs,JSON_UNESCAPED_UNICODE),$mid,$cid]);
    if(($res['descripcion']??'')!==''){
      $q=db()->prepare('SELECT notas FROM crm_meetings WHERE id=?'); $q->execute([$mid]); $prev=trim((string)$q->fetchColumn());
      $desc=$res['descripcion'];
      if(mb_strpos($prev,$desc)===false){ $nuevo=$prev!==''?($prev."\n\n".$desc):$desc; db()->prepare('UPDATE crm_meetings SET notas=? WHERE id=?')->execute([$nuevo,$mid]); }
    }
    crm_activity($cid,null,'reunion','Notas de Gemini vinculadas'.($docs?(' ('.count($docs).' doc)'):''));
    echo json_encode(['ok'=>1,'docs'=>$docs,'descripcion'=>$res['descripcion']??'']); exit;
  }
  if ($a==='meeting_outcome') {
    crm_meetings_ensure();
    $mid=(int)($_POST['mid']??0); $cid=(int)($_POST['cid']??0);
    $estado=in_array($_POST['estado']??'',['realizada','no_show','cancelada'],true)?$_POST['estado']:'realizada';
    $notas=trim($_POST['notas']??''); $fase=(string)($_POST['fase']??'');
    db()->prepare('UPDATE crm_meetings SET estado=?, notas=? WHERE id=? AND contact_id=?')->execute([$estado,$notas,$mid,$cid]);
    $lbl=['realizada'=>'Reunión realizada','no_show'=>'No se presentó a la reunión','cancelada'=>'Reunión cancelada'][$estado];
    crm_activity($cid,null,'reunion',$lbl.($notas!==''?': '.mb_substr($notas,0,120):''));
    if($fase!=='' && isset($STAGES[$fase])){ db()->prepare('UPDATE contacts SET fase=? WHERE id=?')->execute([$fase,$cid]); crm_activity($cid,null,'fase','Fase cambiada a '.$STAGES[$fase]['nombre']); }
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
  }
  /* ---- acciones en lote ---- */
  if ($a==='bulk') {
    $ids=array_values(array_filter(array_map('intval',(array)($_POST['ids']??[])))); $op=$_POST['op']??'';
    if($ids){ $in=implode(',',array_fill(0,count($ids),'?'));
      if($op==='owner'){ $pid=(int)($_POST['propietario_id']??0); if($pid<=0)$pid=null; db()->prepare("UPDATE contacts SET propietario_id=? WHERE id IN ($in)")->execute(array_merge([$pid],$ids)); }
      elseif($op==='del'){ db()->prepare("DELETE FROM contacts WHERE id IN ($in)")->execute($ids); }
      elseif($op==='list'){ $lid=(int)($_POST['list_id']??0); if($lid){ $ins=db()->prepare('INSERT IGNORE INTO list_members (list_id,contact_id) VALUES (?,?)'); foreach($ids as $cid) $ins->execute([$lid,$cid]); } }
      elseif($op==='newlist'){ $nom=trim($_POST['list_name']??''); if($nom!==''){ db()->prepare("INSERT INTO lists (nombre,descripcion,tipo,condiciones) VALUES (?,?, 'estatica', '[]')")->execute([$nom,null]); $lid=(int)db()->lastInsertId(); db()->prepare('UPDATE lists SET fecha_congelado=NOW() WHERE id=?')->execute([$lid]); $ins=db()->prepare('INSERT IGNORE INTO list_members (list_id,contact_id) VALUES (?,?)'); foreach($ids as $cid) $ins->execute([$lid,$cid]); $_SESSION['crm_flash']='Lista «'.$nom.'» creada con '.count($ids).' contacto(s)'; } }
      elseif($op==='tag'){ $tid=(int)($_POST['tag_id']??0); if($tid){ $ins=db()->prepare('INSERT IGNORE INTO contact_tags (contact_id,tag_id) VALUES (?,?)'); foreach($ids as $cid) $ins->execute([$cid,$tid]); } }
      if($op!=='newlist') $_SESSION['crm_flash']=count($ids).' contacto(s) actualizados';
    }
    header('Location: crm.php'.qs()); exit;
  }
  /* ---- etiquetas ---- */
  if ($a==='tag_create'){ $n=trim($_POST['nombre']??''); $col=$_POST['color']??'#5b8def'; if($n!==''){ try{ db()->prepare('INSERT INTO crm_tags (nombre,color) VALUES (?,?)')->execute([$n,$col]); }catch(Exception $e){} } header('Location: crm.php'.qs()); exit; }
  if ($a==='tag_del'){ $tid=(int)($_POST['id']??0); db()->prepare('DELETE FROM crm_tags WHERE id=?')->execute([$tid]); db()->prepare('DELETE FROM contact_tags WHERE tag_id=?')->execute([$tid]); db()->prepare('DELETE FROM deal_tags WHERE tag_id=?')->execute([$tid]); header('Location: crm.php'.qs()); exit; }
  if ($a==='contact_tag'){ $cid=(int)($_POST['cid']??0); $tid=(int)($_POST['tag_id']??0); $on=($_POST['on']??'')==='1'; if($cid&&$tid){ if($on) db()->prepare('INSERT IGNORE INTO contact_tags (contact_id,tag_id) VALUES (?,?)')->execute([$cid,$tid]); else db()->prepare('DELETE FROM contact_tags WHERE contact_id=? AND tag_id=?')->execute([$cid,$tid]); } header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit; }
  /* ---- adjuntos ---- */
  if ($a==='upload_att'){ $cid=(int)($_POST['cid']??0);
    if($cid && isset($_FILES['file']) && $_FILES['file']['error']===UPLOAD_ERR_OK){
      $orig=$_FILES['file']['name'];
      /* lista blanca: sin esto se podría subir un .php a la carpeta pública */
      if(!upload_ext_ok($orig)){ $_SESSION['crm_flash']='Ese tipo de archivo no está permitido'; header('Location: crm.php?open='.$cid); exit; }
      $dir=__DIR__.'/../uploads/crm'; if(!is_dir($dir)) @mkdir($dir,0775,true);
      $fn=upload_nombre_seguro($orig,(string)$cid);
      if(@move_uploaded_file($_FILES['file']['tmp_name'],$dir.'/'.$fn)){
        img_optimizar($dir.'/'.$fn);
        db()->prepare('INSERT INTO attachments (contact_id,nombre,url,tipo) VALUES (?,?,?,?)')->execute([$cid,mb_substr($orig,0,240),'../archivo.php?d=crm&f='.rawurlencode($fn),$_FILES['file']['type']]);
        crm_activity($cid,null,'archivo','Archivo adjuntado: '.$orig);
      }
    }
    header('Location: crm.php?open='.$cid); exit;
  }
  if ($a==='att_del'){ $id=(int)($_POST['id']??0); $cid=(int)($_POST['cid']??0);
    $st=db()->prepare('SELECT url FROM attachments WHERE id=?'); $st->execute([$id]); $u=$st->fetchColumn();
    if($u){
      /* la url puede ser antigua (../uploads/crm/x) o nueva (../archivo.php?d=crm&f=x) */
      $nombre = (strpos($u,'archivo.php')!==false && preg_match('/[?&]f=([^&]+)/',$u,$mm))
              ? rawurldecode($mm[1]) : basename(parse_url($u,PHP_URL_PATH) ?: '');
      $nombre = basename(str_replace("\0",'',$nombre));
      $fp = __DIR__.'/../uploads/crm/'.$nombre;
      if($nombre!=='' && is_file($fp)) @unlink($fp);
    }
    db()->prepare('DELETE FROM attachments WHERE id=?')->execute([$id]);
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit; }
  /* ---- vistas guardadas ---- */
  if ($a==='save_view'){ $n=trim($_POST['nombre']??''); $f=(string)($_POST['filtros']??''); if($n!==''){ db()->prepare('INSERT INTO saved_views (usuario_id,nombre,modulo,filtros) VALUES (?,?,?,?)')->execute([(int)$me['id'],$n,'crm',$f]); } header('Location: crm.php'.$f); exit; }
  if ($a==='del_view'){ db()->prepare('DELETE FROM saved_views WHERE id=? AND usuario_id=?')->execute([(int)($_POST['id']??0),(int)$me['id']]); header('Location: crm.php'); exit; }
}

/* ---------------- Fragmento del perfil (AJAX) ---------------- */
if(($_GET['frag']??'')==='profile'){
  $cid=(int)($_GET['id']??0); $st=db()->prepare('SELECT * FROM contacts WHERE id=?'); $st->execute([$cid]); $c=$st->fetch();
  include __DIR__.'/crm_profile.php'; exit;
}

/* ---------------- Export CSV de seleccionados ---------------- */
if(($_GET['bulk_export']??'')==='1'){
  $ids=array_values(array_filter(array_map('intval',explode(',',$_GET['ids']??''))));
  $STG=crm_stages();
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="contactos.csv"');
  $out=fopen('php://output','w'); fprintf($out,chr(0xEF).chr(0xBB).chr(0xBF));
  fputcsv($out,['Nombre','Empresa','Sector','Email','Teléfono','WhatsApp','Origen','Valor','Embudo','Propietario']);
  if($ids){ $in=implode(',',array_fill(0,count($ids),'?')); $st=db()->prepare("SELECT * FROM contacts WHERE id IN ($in)"); $st->execute($ids);
    foreach($st->fetchAll() as $c){ fputcsv($out,[$c['nombre'],$c['empresa'],$c['sector'],$c['email'],$c['telefono'],$c['whatsapp'],$c['origen_lead'],$c['valor'],$STG[$c['fase']]['nombre']??$c['fase'],$respMap[(int)$c['propietario_id']]??'']); } }
  fclose($out); exit;
}

/* ---------------- Filtros (AND) ---------------- */
$q=trim($_GET['q']??''); $fSector=$_GET['sector']??''; $fOrigen=$_GET['origen']??''; $fFase=$_GET['fase']??'';
$fServ=$_GET['servicio']??''; $fProp=$_GET['prop']??''; $vmin=$_GET['vmin']??''; $vmax=$_GET['vmax']??'';
$fdesde=$_GET['fdesde']??''; $fhasta=$_GET['fhasta']??''; $quick=$_GET['quick']??''; $fTag=$_GET['tag']??'';

/* etiquetas, listas y vistas (para UI) */
$TAGS = crm_all_tags(); $TAGMAP=[]; foreach($TAGS as $t) $TAGMAP[(int)$t['id']]=$t;
$LISTS = db()->query('SELECT id,nombre FROM lists ORDER BY nombre')->fetchAll();
$VIEWS = db()->prepare('SELECT * FROM saved_views WHERE modulo=? AND (usuario_id=? OR usuario_id IS NULL) ORDER BY nombre'); $VIEWS->execute(['crm',(int)$me['id']]); $VIEWS=$VIEWS->fetchAll();

$w=[]; $p=[];
if($q!==''){ $w[]='(nombre LIKE ? OR empresa LIKE ? OR email LIKE ? OR telefono LIKE ?)'; $like="%$q%"; array_push($p,$like,$like,$like,$like); }
if($fSector!==''){ $w[]='sector=?'; $p[]=$fSector; }
if($fOrigen!==''){ $w[]='origen_lead=?'; $p[]=$fOrigen; }
if($fFase!==''){ $w[]='fase=?'; $p[]=$fFase; }
if($fServ!==''){ $w[]='servicio_json LIKE ?'; $p[]='%"'.$fServ.'"%'; }
if($fProp!==''){ $w[]='propietario_id=?'; $p[]=(int)$fProp; }
if($fTag!==''){ $w[]='id IN (SELECT contact_id FROM contact_tags WHERE tag_id=?)'; $p[]=(int)$fTag; }
if($vmin!==''){ $w[]='valor>=?'; $p[]=(float)$vmin; }
if($vmax!==''){ $w[]='valor<=?'; $p[]=(float)$vmax; }
if($fdesde!==''){ $w[]='DATE(fecha_creacion)>=?'; $p[]=$fdesde; }
if($fhasta!==''){ $w[]='DATE(fecha_creacion)<=?'; $p[]=$fhasta; }
if($quick==='sin_contactar'){ $w[]='fecha_ultimo_contacto IS NULL'; }
elseif($quick==='act7'){ $w[]='(fecha_ultimo_contacto IS NULL OR fecha_ultimo_contacto <= (CURDATE() - INTERVAL 7 DAY))'; }
elseif($quick==='act30'){ $w[]='(fecha_ultimo_contacto IS NULL OR fecha_ultimo_contacto <= (CURDATE() - INTERVAL 30 DAY))'; }
elseif($quick==='vencidas'){ $w[]='fecha_prox IS NOT NULL AND fecha_prox<=CURDATE()'; }
elseif($quick==='perdido'){ $w[]="fase='perdido'"; }

/* ordenación */
$SORTABLE=['nombre'=>'nombre','empresa'=>'empresa','sector'=>'sector','valor'=>'valor','fase'=>'fase','ult'=>'fecha_ultimo_contacto','prox'=>'fecha_prox','creado'=>'fecha_creacion'];
$sort=$_GET['sort']??''; $dir=(($_GET['dir']??'')==='asc')?'ASC':'DESC';
$orderBy = isset($SORTABLE[$sort]) ? ($SORTABLE[$sort].' '.$dir.', id DESC') : 'fecha_creacion DESC, id DESC';

$sql='SELECT * FROM contacts'.($w?(' WHERE '.implode(' AND ',$w)):'').' ORDER BY '.$orderBy;
$st=db()->prepare($sql); $st->execute($p); $rows=$st->fetchAll();
$total=(int)db()->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
$nFiltros=count(array_filter([$q,$fSector,$fOrigen,$fFase,$fServ,$fProp,$fTag,$vmin,$vmax,$fdesde,$fhasta]));
$idList=array_map(fn($r)=>(int)$r['id'],$rows);

/* etiquetas por contacto (para la tabla) */
$ctagMap=[];
if($rows){ $ids=implode(',',$idList); try{ foreach(db()->query("SELECT ct.contact_id, t.id,t.nombre,t.color FROM contact_tags ct JOIN crm_tags t ON t.id=ct.tag_id WHERE ct.contact_id IN ($ids)") as $r){ $ctagMap[(int)$r['contact_id']][]=$r; } }catch(Exception $e){} }

/* buscador global: negocios que coinciden con la búsqueda */
$dealHits=[];
if($q!==''){ try{ $dh=db()->prepare("SELECT d.id,d.nombre,d.valor,d.fase,d.contact_id,c.nombre c_nombre FROM deals d JOIN contacts c ON c.id=d.contact_id WHERE d.archivado=0 AND (d.nombre LIKE ? OR c.nombre LIKE ? OR c.empresa LIKE ?) ORDER BY d.id DESC LIMIT 8"); $dh->execute([$like,$like,$like]); $dealHits=$dh->fetchAll(); }catch(Exception $e){} }

$QUICKS=[''=>'Todos','sin_contactar'=>'Sin contactar','act7'=>'Sin actividad +7 días','act30'=>'+30 días','vencidas'=>'Acción vencida','perdido'=>'Cerrado perdido'];

function cm_sorth($key,$label,$cls=''){ global $sort,$dir; $cur=($sort===$key)?strtolower($dir):''; $next=($cur==='asc')?'desc':'asc';
  $g=$_GET; $g['sort']=$key; $g['dir']=$next; $url='crm.php?'.http_build_query($g);
  $ar=$sort===$key?($cur==='asc'?' ↑':' ↓'):'';
  return '<th class="cm-sorth'.($sort===$key?' on':'').($cls!==''?' '.$cls:'').'"><a href="'.htmlspecialchars($url).'">'.htmlspecialchars($label).$ar.'</a></th>'; }

erp_head('crm', 'CRM · Contactos');
$flash=$_SESSION['crm_flash']??''; unset($_SESSION['crm_flash']);
?>
<style>
.cm-top{display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.cm-top h1{font-size:24px;flex:none}.cm-top .cnt{color:var(--muted);font-size:13px;font-weight:600}
.cm-new{margin-left:auto;border:none;background:var(--ink-strong);color:#fff;border-radius:10px;padding:10px 16px;font-size:13.5px;font-weight:600;cursor:pointer;display:inline-flex;gap:8px;align-items:center}
.cm-new svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2.2;stroke-linecap:round}
.cm-bar{display:flex;align-items:center;gap:9px;margin-bottom:12px;flex-wrap:wrap}
.cm-search{flex:1;min-width:220px;max-width:440px;display:flex;align-items:center;gap:9px;background:#fff;border:1px solid var(--line);border-radius:11px;padding:9px 13px}
.cm-search svg{width:16px;height:16px;stroke:var(--muted);fill:none;stroke-width:2;flex:none}
.cm-search input{border:none;background:none;outline:none;font-size:14px;font-family:inherit;color:var(--ink);width:100%}
.cm-fbtn{position:relative;display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);background:#fff;border-radius:11px;padding:9px 14px;font-size:13px;font-weight:600;color:var(--ink);cursor:pointer}
.cm-fbtn:hover{background:var(--soft)}
.cm-fbtn.act{border-color:var(--ink-strong);color:var(--ink-strong)}
.cm-fbtn.open{background:var(--soft)}
.cm-fbtn svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2}
.cm-fbtn .b{background:var(--ink-strong);color:#fff;border-radius:99px;font-size:10.5px;font-weight:700;padding:1px 6px;min-width:17px;text-align:center;line-height:1.4}
.cm-quicks{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.cm-quicks a{padding:6px 12px;border-radius:99px;font-size:12px;font-weight:600;color:var(--muted);background:#fff;border:1px solid var(--line);text-decoration:none}
.cm-quicks a.on{background:var(--ink-strong);color:#fff;border-color:var(--ink-strong)}
.cm-quicks a:hover:not(.on){background:var(--soft)}
.cm-panel{background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px 22px;margin-bottom:16px;display:none;grid-template-columns:repeat(4,1fr);gap:16px 18px}
.cm-panel.on{display:grid}
@media(max-width:900px){.cm-panel.on{grid-template-columns:1fr 1fr}}
.cm-panel label{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:700;margin-bottom:5px}
.cm-panel select,.cm-panel input{width:100%;border:1px solid var(--line);border-radius:9px;padding:8px 10px;font-size:13px;font-family:inherit;color:var(--ink);background:#fff}
.cm-panel select:focus,.cm-panel input:focus{outline:none;border-color:var(--ink-strong)}
.cm-panel .two{display:flex;gap:7px}.cm-panel .two>*{flex:1;min-width:0}
.cm-panel .full{grid-column:1/-1;display:flex;justify-content:flex-end;align-items:center;border-top:1px solid var(--line2);padding-top:12px;margin-top:2px}
.cm-chips{display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.cm-fchip{display:inline-flex;align-items:center;gap:7px;background:#eef2fb;color:#33507f;border-radius:8px;padding:5px 6px 5px 11px;font-size:12px;font-weight:600}
.cm-fchip a{color:var(--label);text-decoration:none;font-weight:700;font-size:13px;line-height:1;padding:0 2px}
.cm-fchip a:hover{color:#e5484d}
.cm-clear{color:var(--muted);font-size:12.5px;text-decoration:none;font-weight:600;padding:4px 8px}
.cm-clear:hover{color:var(--accent)}
/* La tabla del CRM tiene quince columnas, así que siempre es más ancha que la
   pantalla. Antes el contenedor no tenía altura: la barra de desplazamiento
   horizontal quedaba al final de TODA la lista, así que con cien contactos había
   que bajar hasta el fondo de la página para poder moverse de lado, y de paso la
   cabecera «pegajosa» no se pegaba a nada. Con una altura máxima esto pasa a ser
   una zona de scroll de verdad: la barra queda siempre a la vista y la cabecera
   se queda fija arriba, igual que las columnas del tablero de Negocio. */
.cm-wrap{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:auto;max-height:calc(100vh - 250px);min-height:320px;overscroll-behavior:contain}
table.cm{width:100%;border-collapse:collapse;font-size:13px;white-space:nowrap}
table.cm th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:650;padding:14px 12px;border-bottom:1px solid var(--line);background:#fcfcfd;position:sticky;top:0;z-index:2}
/* Columnas fijas al hacer scroll horizontal: la casilla y el Nombre se quedan pegados
   a la izquierda para no perder de vista de quién es cada fila (alivia la queja del
   «scroll dentro del scroll» del CRM). */
table.cm td.cm-chk,table.cm th.cm-chk{position:sticky;left:0;width:40px;min-width:40px;background:#fff}
table.cm td.cm-stick,table.cm th.cm-stick{position:sticky;left:40px;background:#fff}
table.cm td.cm-chk,table.cm td.cm-stick{z-index:3}
table.cm th.cm-chk,table.cm th.cm-stick{z-index:5;background:#fcfcfd}
table.cm tr:hover td.cm-chk,table.cm tr:hover td.cm-stick{background:var(--soft)}
/* Una línea sutil marca dónde acaba la zona fija. */
table.cm td.cm-stick,table.cm th.cm-stick{box-shadow:1px 0 0 var(--line)}
table.cm td{padding:10px 12px;border-bottom:1px solid var(--line2);vertical-align:middle}
table.cm tr:last-child td{border-bottom:none}
table.cm tr:hover td{background:#fcfcfd}
.cm-name{display:flex;align-items:center;gap:10px;cursor:pointer;padding:4px 2px;border-radius:8px}
.cm-name:hover{background:var(--soft)}
.cm-ctx{position:fixed;z-index:700;background:#fff;border:1px solid var(--line);border-radius:11px;box-shadow:0 16px 44px rgba(16,19,24,.18);padding:6px;min-width:196px;display:none}
.cm-ctx.on{display:block}
.cm-ctx button,.cm-ctx a{display:flex;align-items:center;gap:9px;width:100%;border:none;background:none;text-align:left;padding:8px 11px;border-radius:8px;font-size:13px;font-weight:500;color:var(--ink);cursor:pointer;font-family:inherit;text-decoration:none;box-sizing:border-box}
.cm-ctx button:hover,.cm-ctx a:hover{background:var(--soft)}
.cm-ctx .sep{height:1px;background:var(--line2);margin:5px 4px}
.cm-ctx .sub{font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:700;padding:6px 11px 3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cm-ctx .danger{color:#e5484d}.cm-ctx .danger:hover{background:#fdeaec}
.cm-av{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:11px;flex:none}
.cm-name b{font-size:13.5px;font-weight:600;color:var(--ink-strong)}
td.ed input,td.ed select{border:1px solid transparent;background:transparent;border-radius:7px;padding:6px 8px;font-size:13px;font-family:inherit;color:var(--ink);width:100%;min-width:90px}
/* Truncado limpio en escritorio: en vez de cortar el texto a mitad de palabra
   ("sergio@lae"), la celda muestra puntos suspensivos y el valor completo se ve
   al pasar el ratón (atributo title). El modo tarjeta de móvil (≤640px) tiene sus
   propias reglas más específicas, así que no se ve afectado. */
td.ed input{max-width:200px;text-overflow:ellipsis}
td.ed input:hover,td.ed select:hover{background:var(--soft)}
td.ed input:focus,td.ed select:focus{background:#fff;outline:none;box-shadow:0 0 0 2px rgba(17,19,24,.08)}
td.ed.num input{width:88px;text-align:right}
.cm-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:99px;font-size:11.5px;font-weight:700;color:#fff}
.cm-dot{width:7px;height:7px;border-radius:50%;background:currentColor;opacity:.9}
/* Fase editable: es la etiqueta de color de siempre, pero al pulsarla se abre
   la lista de fases en el pop-up blanco del ERP. Ya no hay ningún <select>
   nativo debajo: el desplegable del navegador no se puede estilizar y se veía
   como una pieza ajena a la aplicación. */
.cm-fase{position:relative;display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:99px;font-size:11.5px;font-weight:700;color:#fff;cursor:pointer;transition:box-shadow .12s,transform .12s;user-select:none}
.cm-fase:hover{box-shadow:0 0 0 3px rgba(0,0,0,.08)}
.cm-fase.open{box-shadow:0 0 0 3px rgba(0,0,0,.14)}
.cm-fase-txt{pointer-events:none;white-space:nowrap}
.cm-fase-arw{display:flex;align-items:center;pointer-events:none;opacity:.75;margin-right:-2px;transition:transform .14s}
.cm-fase.open .cm-fase-arw{transform:rotate(180deg)}
.cm-fase-arw svg{display:block}
/* Pop-up de opciones (fases y acciones en lote): mismo lenguaje visual que el
   resto de menús flotantes del ERP. */
.cm-pop{position:fixed;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 16px 40px rgba(16,19,24,.18);padding:6px;z-index:560;display:none;min-width:198px;max-height:62vh;overflow:auto}
.cm-pop.on{display:block}
.cm-pop .ph{font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;padding:7px 10px 5px}
.cm-pop button{display:flex;align-items:center;gap:9px;width:100%;border:none;background:none;padding:8px 10px;border-radius:8px;font-size:13px;font-family:inherit;color:var(--ink);cursor:pointer;text-align:left}
.cm-pop button:hover{background:var(--soft)}
.cm-pop button .d{width:9px;height:9px;border-radius:50%;flex:none}
.cm-pop button .t{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cm-pop button .ck{display:flex;flex:none;color:var(--ink);opacity:0}
.cm-pop button.on{font-weight:650}
.cm-pop button.on .ck{opacity:1}
.cm-pop .empty{padding:9px 11px;font-size:12.5px;color:var(--muted)}
.cm-pop button.newl{color:var(--accent);font-weight:650;border-bottom:1px solid var(--line2);border-radius:8px 8px 0 0;margin-bottom:2px}
.cm-pop button.newl .d.plus{width:18px;height:18px;border-radius:50%;background:var(--accent-soft);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;line-height:1}
.cm-pop button.newl:hover{background:var(--accent-soft)}
.cm-svc{display:flex;gap:4px;flex-wrap:wrap;cursor:pointer;padding:5px 6px;border-radius:7px;min-width:90px}
.cm-svc:hover{background:var(--soft)}
/* .cm-chip era .chip con otro nombre: ahora usa la clase global de erp_nav.php. */
.cm-svc .mut{color:var(--muted);font-size:12px}
.cm-prox{display:flex;flex-direction:column;gap:2px;min-width:120px}
.cm-mut{color:var(--muted);font-size:12px}
.cm-del{border:none;background:none;color:#c2c6cd;cursor:pointer;padding:6px;border-radius:7px}
.cm-del:hover{background:#fdecec;color:#e5484d}
.cm-empty{padding:44px;text-align:center;color:var(--muted)}
.cm-mask{position:fixed;inset:0;background:rgba(16,19,24,.34);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:400;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .18s ease,visibility .18s ease}
.cm-mask.on{opacity:1;visibility:visible;pointer-events:auto}
/* Agendar reunión y Resultado se abren DESDE la ficha del contacto (#cmProfileMask, z-index 600),
   así que tienen que quedar por encima de ella o parecería que el botón «no hace nada». */
#pfMeetMask,#pfOutMask{z-index:640}
.cm-modal{background:#fff;border-radius:18px;width:440px;max-width:94vw;padding:26px 28px;box-shadow:0 30px 70px rgba(0,0,0,.3)}
.cm-modal h3{font-size:18px;font-weight:650;margin:0 0 18px}
.cm-modal label{display:block;font-size:12px;color:var(--muted);font-weight:600;margin:10px 0 5px}
.cm-modal input,.cm-modal select{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font-size:14px;font-family:inherit;color:var(--ink)}
.cm-modal .row2{display:flex;gap:10px}.cm-modal .row2>div{flex:1}
.cm-modal .acts{display:flex;justify-content:flex-end;gap:9px;margin-top:20px}
.cm-modal .acts button{border-radius:10px;padding:10px 16px;font-size:13.5px;font-weight:600;cursor:pointer;border:1px solid var(--line);background:#fff;color:var(--ink)}
.cm-modal .acts button.pri{background:var(--ink-strong);color:#fff;border-color:var(--ink-strong)}
/* Fijo a la ventana, no a la página: la tabla ahora se desplaza por dentro y un
   pop-up colocado con la posición del documento se quedaba flotando en el sitio
   equivocado en cuanto se movía la lista. */
.svc-pop{position:fixed;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 16px 40px rgba(0,0,0,.18);padding:6px;z-index:500;display:none;min-width:180px}
.svc-pop.on{display:block}
.svc-pop label{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:8px;font-size:13px;color:var(--ink);cursor:pointer}
.svc-pop label:hover{background:var(--soft)}
/* ---- Perfil pop-up ---- */
#cmProfileMask{position:fixed;inset:0;background:rgba(16,19,24,.4);-webkit-backdrop-filter:blur(5px);backdrop-filter:blur(5px);display:flex;align-items:flex-start;justify-content:center;z-index:600;padding:3vh 0;overflow:auto;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .2s ease,visibility .2s ease}
#cmProfileMask.on{opacity:1;visibility:visible;pointer-events:auto}
#cmProfileBox{background:#fff;border-radius:20px;width:1020px;max-width:95vw;max-height:94vh;overflow:auto;box-shadow:0 40px 90px rgba(0,0,0,.35)}
@media(max-width:700px){#cmProfileBox{width:100%;border-radius:0;max-height:100vh}}
.pf-head{display:flex;align-items:center;gap:14px;padding:22px 28px;border-bottom:1px solid var(--line);position:sticky;top:0;background:#fff;z-index:3}
.pf-hl{display:flex;align-items:center;gap:13px;flex:1;min-width:0}
.pf-av{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:17px;flex:none}
.pf-name{border:none;background:none;font-family:inherit;font-size:21px;font-weight:650;color:var(--ink-strong);letter-spacing:-.3px;padding:3px 6px;border-radius:8px;width:100%;max-width:360px}
.pf-name:hover{background:var(--soft)}.pf-name:focus{outline:none;box-shadow:0 0 0 2px rgba(17,19,24,.08)}
.pf-sub{font-size:12.5px;color:var(--muted);margin-top:2px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding-left:6px}
.pf-badge{color:#fff;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:99px}
.pf-nav{display:flex;align-items:center;gap:6px;flex:none}
.pf-arw{width:34px;height:34px;border:1px solid var(--line);background:#fff;border-radius:9px;font-size:18px;color:var(--ink);cursor:pointer}
.pf-arw:hover{background:var(--soft)}.pf-arw:disabled{opacity:.35;cursor:default}
.pf-pos{font-size:12px;color:var(--muted);min-width:44px;text-align:center}
.pf-x{width:34px;height:34px;border:none;background:var(--soft);border-radius:9px;font-size:15px;color:var(--ink);cursor:pointer;margin-left:4px}
.pf-x:hover{background:#eceef1}
.pf-acts{display:flex;gap:9px;flex-wrap:wrap;padding:16px 28px;border-bottom:1px solid var(--line2)}
.pf-acts a,.pf-acts button{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);background:#fff;border-radius:10px;padding:9px 14px;font-size:13px;font-weight:600;color:var(--ink);cursor:pointer;text-decoration:none}
.pf-acts a:hover,.pf-acts button:hover{background:var(--soft)}
.pf-acts svg{width:15px;height:15px;color:var(--muted)}
/* El lead que ya es cliente: se ve de un vistazo que el puente está hecho. */
.pf-acts a.pf-isclient{border-color:#bfe3cc;background:#f2fbf5;color:#12703f}
.pf-acts a.pf-isclient:hover{background:#e8f7ee}
.pf-acts a.pf-isclient svg{color:var(--ok)}
.pf-body{display:grid;grid-template-columns:1fr 1fr;gap:0}
@media(max-width:820px){.pf-body{grid-template-columns:1fr}}
/* toolbar extra */
.cm-tool{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);background:#fff;border-radius:11px;padding:9px 13px;font-size:13px;font-weight:600;color:var(--ink);cursor:pointer;position:relative}
.cm-tool:hover{background:var(--soft)}
.cm-tool svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2}
.cm-dd{position:absolute;top:calc(100% + 6px);right:0;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 16px 44px rgba(16,19,24,.16);padding:6px;min-width:220px;z-index:60;display:none}
.cm-dd.on{display:block}
.cm-dd a,.cm-dd button{display:flex;align-items:center;gap:8px;width:100%;border:none;background:none;text-align:left;padding:8px 10px;border-radius:8px;font-size:13px;color:var(--ink);cursor:pointer;font-family:inherit;text-decoration:none;box-sizing:border-box}
.cm-dd a:hover,.cm-dd button:hover{background:var(--soft)}
.cm-dd .vrow{display:flex;align-items:center}
.cm-dd .vrow a{flex:1}
.cm-dd .vrow .x{width:auto;color:var(--label);flex:none}.cm-dd .vrow .x:hover{color:#e5484d;background:none}
.cm-dd .empty{padding:10px;color:var(--muted);font-size:12.5px}
.cm-dd .hd{font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:700;padding:6px 10px 3px}
/* checkbox col */
.cm-chk{width:34px;text-align:center}
.cm-chk input{width:15px;height:15px;cursor:pointer;accent-color:var(--ink-strong)}
.cm-sorth a{color:inherit;text-decoration:none;cursor:pointer}
.cm-sorth.on a{color:var(--ink-strong)}
.cm-sorth a:hover{color:var(--accent)}
.cm-tags{display:flex;gap:4px;flex-wrap:wrap;margin:4px 0 0 40px}
.cm-tag{font-size:10px;font-weight:700;color:#fff;border-radius:5px;padding:1px 7px}
.cm-prox.due input:first-of-type{color:#c0343a!important;font-weight:600}
.cm-duedot{width:7px;height:7px;border-radius:50%;background:#e5484d;display:inline-block;margin-right:4px;flex:none}
.cm-actcol{width:34px;text-align:center}
.cm-rowmenu{border:none;background:none;color:#c2c6cd;cursor:pointer;font-size:18px;line-height:1;padding:4px 8px;border-radius:7px;opacity:0;transition:opacity .12s,background .12s}
tr:hover .cm-rowmenu{opacity:1}
.cm-rowmenu:hover{background:var(--soft);color:var(--ink)}
/* Barra de acciones en lote.
   Antes era una píldora negra con <select> nativos dentro: no se parecía a
   nada del resto del ERP y obligaba a leer tres desplegables para saber qué
   se podía hacer. Ahora es una tarjeta blanca como el resto de la aplicación,
   con botones con icono y su menú flotante (.cm-pop). */
.cm-bulk{position:fixed;left:50%;bottom:26px;transform:translateX(-50%) translateY(150%);background:#fff;border:1px solid var(--line);color:var(--ink);border-radius:14px;box-shadow:0 18px 50px rgba(16,19,24,.18);padding:7px 8px 7px 14px;display:flex;align-items:center;gap:3px;z-index:520;opacity:0;transition:transform .22s cubic-bezier(.2,.7,.3,1),opacity .18s;flex-wrap:wrap}
.cm-bulk.on{transform:translateX(-50%) translateY(0);opacity:1}
.cm-bulk .n{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;color:var(--muted);white-space:nowrap;margin-right:4px}
.cm-bulk .n b{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 6px;border-radius:99px;background:var(--accent);color:#fff;font-size:11.5px;font-weight:700}
.cm-bulk .sep{width:1px;height:20px;background:var(--line);margin:0 5px}
.cm-bulk .b{display:inline-flex;align-items:center;gap:7px;border:none;background:none;border-radius:9px;padding:8px 10px;font-size:12.5px;font-weight:600;font-family:inherit;color:var(--ink);cursor:pointer;white-space:nowrap;transition:background .12s,color .12s}
.cm-bulk .b:hover,.cm-bulk .b.open{background:var(--soft)}
.cm-bulk .b .i{display:flex;color:var(--label);transition:color .12s}
.cm-bulk .b:hover .i,.cm-bulk .b.open .i{color:var(--ink)}
.cm-bulk .b .cv{display:flex;color:#c2c6cd;margin-left:-3px}
.cm-bulk .b.danger{color:#c0343a}
.cm-bulk .b.danger .i{color:#e2999b}
.cm-bulk .b.danger:hover{background:#fdecec;color:#a52a30}
.cm-bulk .b.danger:hover .i{color:#c0343a}
.cm-bulk .close{display:flex;border:none;background:none;color:#c2c6cd;cursor:pointer;padding:7px;border-radius:8px;margin-left:2px}
.cm-bulk .close:hover{background:var(--soft);color:var(--ink)}
/* strip de negocios (buscador global) */
.cm-deals{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin-bottom:16px}
.cm-deals .h{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:700;margin-bottom:8px}
.cm-deals .row{display:flex;gap:8px;flex-wrap:wrap}
.cm-deals a{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:10px;padding:7px 11px;font-size:12.5px;color:var(--ink);text-decoration:none;font-weight:600}
.cm-deals a:hover{background:var(--soft)}
.cm-deals .dv{color:#12854a;font-weight:750}
/* modal etiquetas */
.cm-tagmodal .tglist{display:flex;flex-direction:column;gap:6px;margin:10px 0}
.cm-tagrow{display:flex;align-items:center;gap:9px;padding:7px 9px;border:1px solid var(--line2);border-radius:9px}
.cm-tagrow .sw{width:16px;height:16px;border-radius:5px;flex:none}
.cm-tagrow b{flex:1;font-size:13px}
.cm-tagrow button{border:none;background:none;color:var(--label);cursor:pointer}.cm-tagrow button:hover{color:#e5484d}
.cm-tagnew{display:flex;gap:8px;align-items:center;margin-top:8px}
.cm-tagnew input[type=text]{flex:1;border:1px solid var(--line);border-radius:9px;padding:8px 10px;font-size:13px;font-family:inherit}
.cm-tagnew input[type=color]{width:38px;height:38px;border:1px solid var(--line);border-radius:9px;padding:2px;cursor:pointer;background:#fff}
.pf-col{padding:24px 28px}.pf-col:first-child{border-right:1px solid var(--line2)}
.pf-sec{font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:650;margin:24px 0 12px;display:flex;align-items:center;gap:8px}
.pf-col>.pf-sec:first-child{margin-top:0}
.pf-mini{margin-left:auto;border:1px dashed var(--line2);background:none;border-radius:7px;padding:3px 9px;font-size:11.5px;color:var(--muted);cursor:pointer;font-weight:600}
.pf-seg{flex:1;border:1px solid var(--line);background:#fff;border-radius:9px;padding:8px 6px;font-family:inherit;font-size:12.5px;font-weight:600;color:#6b7280;cursor:pointer}
.pf-seg.on{background:#3c4149;color:#fff;border-color:#3c4149}
.pf-seg:hover:not(.on){background:var(--soft)}
.pf-mini:hover{color:var(--accent);border-color:var(--accent)}
.pf-add{background:var(--soft);border-radius:12px;padding:10px}
.pf-types{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:8px}
.pf-tp{border:1px solid var(--line);background:#fff;border-radius:8px;padding:5px 11px;font-size:12px;font-weight:600;color:var(--muted);cursor:pointer}
.pf-tp.on{background:var(--ink-strong);color:#fff;border-color:var(--ink-strong)}
.pf-add textarea{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font-size:13.5px;font-family:inherit;color:var(--ink);resize:vertical;min-height:60px;background:#fff}
.pf-add-b{display:flex;justify-content:flex-end;margin-top:8px}
.pf-pub{border:none;background:var(--ink-strong);color:#fff;border-radius:9px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer}
.pf-cms{margin-top:12px;display:flex;flex-direction:column;gap:12px}
.pf-cm{display:flex;gap:10px}
.pf-cav{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:11px;flex:none}
.pf-cmb{flex:1;min-width:0}
.pf-cmh{display:flex;align-items:center;gap:8px;font-size:12.5px}
.pf-cmh b{color:var(--ink-strong)}
.pf-tag{font-size:10px;font-weight:700;padding:2px 7px;border-radius:6px;background:#eef0f3;color:#5c616b}
.pf-tag-llamada{background:#e6f6ee;color:#12854a}.pf-tag-whatsapp{background:#e3f7ed;color:var(--ok)}.pf-tag-email{background:#e8effc;color:#2f6df6}.pf-tag-reunion{background:#fdf1e3;color:#c76a12}
.pf-date{color:var(--muted);margin-left:auto}
.pf-cmdel{border:none;background:none;color:var(--label);cursor:pointer;font-size:12px}
.pf-cmdel:hover{color:#e5484d}
.pf-cmtxt{font-size:13.5px;color:var(--ink);margin-top:3px;line-height:1.5;white-space:pre-wrap}
.pf-mention{color:var(--accent);font-weight:600;background:#eef2fb;border-radius:5px;padding:0 4px}
.pf-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 12px}
.pf-grid .pf-2{grid-column:1/-1}
.pf-grid label{display:block;font-size:11px;color:var(--muted);font-weight:600;margin-bottom:4px}
.pf-grid input,.pf-grid select{width:100%;border:1px solid var(--line);border-radius:9px;padding:8px 10px;font-size:13px;font-family:inherit;color:var(--ink);background:#fff}
.pf-grid input:focus,.pf-grid select:focus{outline:none;border-color:var(--ink-strong)}
.pf-props{display:flex;flex-direction:column;gap:6px}
.pf-prop{display:flex;align-items:center;gap:9px;border:1px solid var(--line2);border-radius:10px;padding:8px 11px}
.pf-prn{flex:1;min-width:0}.pf-prn b{font-size:13px}.pf-prn span{display:block;font-size:11.5px;color:var(--muted)}
.pf-prest{font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:6px;background:#eef0f3;color:#5c616b}
.pf-prest-aceptada{background:#e6f6ee;color:#12854a}.pf-prest-rechazada{background:#fdecec;color:#e5484d}.pf-prest-vista{background:#e8effc;color:#2f6df6}
.pf-prlink{color:var(--muted)}.pf-prlink svg{width:14px;height:14px}
.pf-lists{display:flex;gap:6px;flex-wrap:wrap}
.pf-lchip{font-size:11.5px;font-weight:600;background:#eef2fb;color:var(--accent);border-radius:7px;padding:3px 10px}
.pf-tagbox{display:flex;gap:6px;flex-wrap:wrap}
.pf-tagchip{border:1px solid var(--line);background:#fff;color:var(--muted);border-radius:8px;padding:4px 11px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit}
.pf-tagchip:hover{background:var(--soft)}
.pf-tagchip.on{background:var(--ink-strong);border-color:var(--ink-strong);color:#fff}
.pf-atts{display:flex;flex-direction:column;gap:6px}
.pf-att{display:flex;align-items:center;gap:9px;border:1px solid var(--line2);border-radius:9px;padding:8px 11px}
.pf-att svg{width:15px;height:15px;color:var(--muted);flex:none}
.pf-attn{flex:1;min-width:0;font-size:13px;color:var(--ink);text-decoration:none;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pf-attn:hover{color:var(--accent)}
.pf-attd{font-size:11.5px;color:var(--muted)}
.pf-acthist{display:flex;flex-direction:column}
.pf-actrow{display:flex;align-items:center;gap:10px;padding:7px 0;border-top:1px solid var(--line2);font-size:12.5px}
.pf-actrow:first-child{border-top:none}
.pf-actdot{width:6px;height:6px;border-radius:50%;background:#c4c8ce;flex:none}
.pf-acttx{flex:1;color:var(--ink)}.pf-actd{color:var(--muted);font-size:11.5px}
.pf-empty{color:var(--muted);font-size:12.5px;padding:8px 0}
/* ---- Modo oscuro (capa aditiva: solo remapea superficies y textos propios) ---- */
[data-theme=dark] .cm-search{background-color:var(--field)}
[data-theme=dark] .cm-fbtn,[data-theme=dark] .cm-quicks a,[data-theme=dark] .cm-tool,
[data-theme=dark] .pf-arw,[data-theme=dark] .pf-seg,[data-theme=dark] .pf-tp,
[data-theme=dark] .pf-tagchip,[data-theme=dark] .cm-deals,
[data-theme=dark] .pf-acts a,[data-theme=dark] .pf-acts button,
[data-theme=dark] .cm-panel,[data-theme=dark] .cm-wrap,
[data-theme=dark] .cm-modal,[data-theme=dark] #cmProfileBox,[data-theme=dark] .pf-head{background-color:var(--card)}
[data-theme=dark] .cm-panel select,[data-theme=dark] .cm-panel input,
[data-theme=dark] .pf-grid input,[data-theme=dark] .pf-grid select,
[data-theme=dark] .pf-add textarea,[data-theme=dark] .cm-tagnew input[type=color],
[data-theme=dark] td.ed input:focus,[data-theme=dark] td.ed select:focus{background-color:var(--field)}
[data-theme=dark] table.cm th,
[data-theme=dark] table.cm td.cm-chk,[data-theme=dark] table.cm td.cm-stick,
[data-theme=dark] table.cm th.cm-chk,[data-theme=dark] table.cm th.cm-stick{background-color:var(--card)}
[data-theme=dark] table.cm tr:hover td,
[data-theme=dark] table.cm tr:hover td.cm-chk,[data-theme=dark] table.cm tr:hover td.cm-stick{background-color:var(--soft)}
[data-theme=dark] table.cm td.cm-stick,[data-theme=dark] table.cm th.cm-stick{box-shadow:1px 0 0 var(--line)}
/* Menús y superficies flotantes */
[data-theme=dark] .cm-ctx,[data-theme=dark] .cm-pop,[data-theme=dark] .svc-pop,
[data-theme=dark] .cm-dd,[data-theme=dark] .cm-bulk{background-color:var(--pop)}
/* Estados activos «negros» → se invierten */
[data-theme=dark] .cm-new,[data-theme=dark] .cm-fbtn .b,[data-theme=dark] .cm-quicks a.on,
[data-theme=dark] .pf-seg.on,[data-theme=dark] .pf-tp.on,[data-theme=dark] .pf-pub,
[data-theme=dark] .pf-tagchip.on,[data-theme=dark] .cm-modal .acts button.pri,
[data-theme=dark] .cm-bulk .n b{background-color:var(--rev);color:var(--rev-fg)}
/* Chips claros poco legibles en oscuro */
[data-theme=dark] .cm-fchip,[data-theme=dark] .pf-lchip,[data-theme=dark] .pf-mention{background-color:var(--accent-soft);color:var(--ink)}
[data-theme=dark] .pf-tag,[data-theme=dark] .pf-prest{background-color:var(--soft);color:var(--muted)}
[data-theme=dark] .pf-seg{color:var(--muted)}
/* Avisos verde/rojo */
[data-theme=dark] .pf-acts a.pf-isclient,[data-theme=dark] .pf-acts a.pf-isclient:hover{background-color:var(--ok-bg);color:var(--ok);border-color:var(--ok-line)}
[data-theme=dark] .cm-ctx .danger{color:var(--danger)}
[data-theme=dark] .cm-del:hover,[data-theme=dark] .cm-bulk .b.danger:hover,[data-theme=dark] .cm-ctx .danger:hover{background-color:var(--danger-bg);color:var(--danger)}
[data-theme=dark] .pf-x:hover{background-color:var(--line)}
/* ============ MÓVIL (≤640px): tabla de contactos → lista de filas planas ============
   Cada contacto = una fila compacta de 2 líneas (avatar + nombre / empresa · fase).
   Sin cajas, sin celdas editables, sin etiqueta:valor. La fila entera abre la ficha. */
@media(max-width:640px){
  .cm-top h1{font-size:21px}
  .cm-search{min-width:0;max-width:none;flex:1 1 100%}
  .cm-panel.on{grid-template-columns:1fr}
  .cm-panel div[style*="span 2"]{grid-column:1/-1}
  .cm-deals .row{flex-direction:column}
  .cm-deals a{width:100%}
  .cm-wrap{max-height:none;min-height:0;border:none;background:none;border-radius:0;overflow:visible;overscroll-behavior:auto}
  table.cm{display:block;white-space:normal;font-size:13.5px}
  table.cm thead{display:none}
  table.cm tbody{display:block}
  /* La fila: plana, indentada a la izquierda para dejar hueco al avatar. */
  table.cm tr{display:block;position:relative;background:var(--card);border:none;border-radius:0;margin:0;padding:10px 44px 10px 62px;box-shadow:none;border-bottom:1px solid var(--line2);min-height:60px}
  table.cm tr:hover td{background:none}
  /* En el reflow móvil, las celdas fijas NO forman su propia caja (ni blanca ni
     oscura): la fila entera ya pone el fondo. Evita el "recuadro negro" del nombre
     en modo oscuro. */
  table.cm td.cm-stick,table.cm td.cm-chk,table.cm th.cm-stick,table.cm th.cm-chk{background:none!important;box-shadow:none!important}
  /* Por defecto, ninguna celda se ve: solo mostramos nombre, empresa y fase. */
  table.cm td{display:none;padding:0;border:none;background:none;white-space:normal}
  /* Nombre = línea 1 (avatar a la izquierda, absoluto). */
  table.cm td.cm-stick{display:block;position:static}
  table.cm td.cm-stick .cm-name{padding:0;display:block}
  table.cm td.cm-stick .cm-name:hover{background:none}
  table.cm td.cm-stick .cm-av{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:38px;height:38px;font-size:13px}
  table.cm td.cm-stick .cm-name b{font-size:15px;font-weight:600;color:var(--ink-strong);display:block;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  table.cm td.cm-stick .cm-tags{display:none}
  /* La capa transparente hace que toda la fila abra la ficha del contacto. */
  table.cm td.cm-stick .cm-name::after{content:"";position:absolute;inset:0;z-index:2}
  /* Empresa y fase = línea 2, texto pequeño apagado, sin caja. */
  table.cm tr:has(td.cm-chk) td:nth-child(3),
  table.cm tr:not(:has(td.cm-chk)) td:nth-child(2),
  table.cm tr:has(td.cm-chk) td:nth-child(10),
  table.cm tr:not(:has(td.cm-chk)) td:nth-child(9){display:inline-block;vertical-align:baseline;margin-top:2px}
  table.cm tr:has(td.cm-chk) td:nth-child(3) input,
  table.cm tr:not(:has(td.cm-chk)) td:nth-child(2) input{border:none!important;background:none!important;padding:0!important;margin:0;height:auto;font-family:inherit;font-size:12.5px;color:var(--muted);width:auto;max-width:52vw;text-overflow:ellipsis}
  /* Separador "·" antes de la fase. */
  table.cm tr:has(td.cm-chk) td:nth-child(10)::before,
  table.cm tr:not(:has(td.cm-chk)) td:nth-child(9)::before{content:"·";color:var(--muted);margin:0 5px;font-size:12.5px}
  /* La fase deja de ser pastilla de color: pasa a texto plano apagado. */
  table.cm td .cm-fase,table.cm td .cm-badge{background:none!important;color:var(--muted)!important;padding:0;font-size:12.5px;font-weight:600;box-shadow:none}
  table.cm td .cm-fase .cm-dot,table.cm td .cm-badge .cm-dot,table.cm td .cm-fase-arw{display:none}
  /* La casilla de selección va a la derecha, por encima de la capa de clic. */
  table.cm td.cm-chk{display:block;position:absolute;top:50%;transform:translateY(-50%);right:12px;left:auto;width:auto;min-width:0;padding:0;border:none;background:none;box-shadow:none;z-index:5}
  table.cm td.cm-chk input{width:20px;height:20px}
  /* Barra de acciones en lote a lo ancho de la pantalla. */
  .cm-bulk{left:8px;right:8px;bottom:8px;transform:translateY(200%);justify-content:center;padding:8px 6px}
  .cm-bulk.on{transform:translateY(0)}
  .cm-bulk .n{width:100%;justify-content:center;margin:0 0 2px}
  .cm-bulk .b{padding:9px 9px;font-size:12px}
  /* Ficha del contacto a pantalla casi completa, con scroll propio. */
  #cmProfileMask{padding:0;align-items:stretch}
  #cmProfileBox{width:100%;max-width:100%;border-radius:0;max-height:100vh;min-height:100vh}
  .pf-head{padding:16px}
  .pf-name{font-size:19px;max-width:none}
  .pf-acts{padding:12px 16px}
  .pf-col{padding:18px 16px}
  .pf-col:first-child{border-right:none;border-bottom:1px solid var(--line2)}
  .pf-grid{grid-template-columns:1fr}
  /* Modales a casi toda la pantalla. */
  .cm-modal{width:100%!important;max-width:100%;border-radius:16px}
}
</style>

<div class="cm-top">
  <h1>Contactos</h1><span class="cnt"><?= count($rows) ?><?= $nFiltros?(' de '.$total):'' ?></span>
  <?php if(can_edit()): ?><a href="crm_import.php" class="cm-tool" style="margin-left:auto;text-decoration:none"><svg viewBox="0 0 24 24"><path d="M12 16V4m0 0L8 8m4-4l4 4M4 20h16"/></svg> Importar</a><button class="cm-new" style="margin-left:0" onclick="cmNew()"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Nuevo contacto</button><?php endif; ?>
</div>

<?php
  // filtros avanzados activos (para badge + chips), la búsqueda de texto va aparte
  $adv=[];
  if($fSector!=='') $adv[]=['sector','Sector: '.$fSector];
  if($fOrigen!=='') $adv[]=['origen','Origen: '.$fOrigen];
  if($fFase!=='')   $adv[]=['fase','Embudo: '.($STAGES[$fFase]['nombre']??$fFase)];
  if($fServ!=='')   $adv[]=['servicio','Servicio: '.$fServ];
  if($fProp!=='')   $adv[]=['prop','Propietario: '.($respMap[(int)$fProp]??$fProp)];
  if($fTag!=='')    $adv[]=['tag','Etiqueta: '.($TAGMAP[(int)$fTag]['nombre']??$fTag)];
  if($vmin!=='')    $adv[]=['vmin','≥ '.$vmin.' €'];
  if($vmax!=='')    $adv[]=['vmax','≤ '.$vmax.' €'];
  if($fdesde!=='')  $adv[]=['fdesde','Desde '.$fdesde];
  if($fhasta!=='')  $adv[]=['fhasta','Hasta '.$fhasta];
  $nAdv=count($adv); $panelOpen=$nAdv>0;
?>
<form method="get" id="cmFilters">
  <?php if($quick!==''): ?><input type="hidden" name="quick" value="<?= e($quick) ?>"><?php endif; ?>
  <div class="cm-bar">
    <div class="cm-search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-3.5-3.5"/></svg>
      <input name="q" value="<?= e($q) ?>" placeholder="Buscar nombre, empresa, email, teléfono…" onchange="this.form.submit()"></div>
    <button type="button" class="cm-fbtn <?= $nAdv?'act':'' ?> <?= $panelOpen?'open':'' ?>" id="cmFBtn" onclick="cmToggleF()"><svg viewBox="0 0 24 24"><path d="M3 5h18M6 12h12M10 19h4"/></svg> Filtros<?php if($nAdv): ?><span class="b"><?= $nAdv ?></span><?php endif; ?></button>
    <div class="cm-tool" onclick="cmDD('cmViews',event)"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h10"/></svg> Vistas
      <div class="cm-dd" id="cmViews" onclick="event.stopPropagation()">
        <div class="hd">Vistas guardadas</div>
        <?php if($VIEWS): foreach($VIEWS as $v): ?><div class="vrow"><a href="crm.php<?= e($v['filtros']) ?>"><?= e($v['nombre']) ?></a><?php if((int)$v['usuario_id']===(int)$me['id']): ?><a class="x" href="#" onclick="cmDelView(<?= (int)$v['id'] ?>);return false;">✕</a><?php endif; ?></div><?php endforeach; else: ?><div class="empty">Sin vistas. Filtra y pulsa «Guardar vista».</div><?php endif; ?>
        <?php if(can_edit()): ?><div style="border-top:1px solid var(--line2);margin-top:5px;padding-top:5px"><button type="button" onclick="cmSaveView()"><?= ic('plus',13) ?> Guardar filtros actuales</button></div><?php endif; ?>
      </div>
    </div>
    <?php if(can_edit()): ?><div class="cm-tool" onclick="cmTagModal(true)"><svg viewBox="0 0 24 24"><path d="M20.59 13.41 13.42 20.6a2 2 0 0 1-2.83 0L3 13V3h10l7.59 7.59a2 2 0 0 1 0 2.82Z"/><circle cx="7.5" cy="7.5" r="1"/></svg> Etiquetas</div><?php endif; ?>
  </div>

  <div class="cm-quicks">
    <?php foreach($QUICKS as $k=>$lb): $u=qs_set('quick',$k); ?>
      <a href="<?= e($u) ?>" class="<?= $quick===$k?'on':'' ?>"><?= e($lb) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="cm-panel <?= $panelOpen?'on':'' ?>" id="cmPanel">
    <div><label>Sector</label><select name="sector" aria-label="Filtrar por sector" onchange="this.form.submit()"><option value="">Cualquiera</option><?php foreach($SECTORS as $s): ?><option value="<?= e($s) ?>" <?= $fSector===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
    <div><label>Origen</label><select name="origen" aria-label="Filtrar por origen" onchange="this.form.submit()"><option value="">Cualquiera</option><?php foreach($ORIGENES as $o): ?><option value="<?= e($o) ?>" <?= $fOrigen===$o?'selected':'' ?>><?= e($o) ?></option><?php endforeach; ?></select></div>
    <div><label>Embudo de venta</label><select name="fase" aria-label="Filtrar por embudo de venta" onchange="this.form.submit()"><option value="">Cualquiera</option><?php foreach($STAGES as $sl=>$sg): ?><option value="<?= e($sl) ?>" <?= $fFase===$sl?'selected':'' ?>><?= e($sg['nombre']) ?></option><?php endforeach; ?></select></div>
    <div><label>Servicio</label><select name="servicio" aria-label="Filtrar por servicio" onchange="this.form.submit()"><option value="">Cualquiera</option><?php foreach($SERVICIOS as $sv): ?><option value="<?= e($sv) ?>" <?= $fServ===$sv?'selected':'' ?>><?= e($sv) ?></option><?php endforeach; ?></select></div>
    <div><label>Propietario</label><select name="prop" aria-label="Filtrar por propietario" onchange="this.form.submit()"><option value="">Cualquiera</option><?php foreach($responsables as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $fProp==(string)$r['id']?'selected':'' ?>><?= e($r['username']) ?></option><?php endforeach; ?></select></div>
    <div><label>Etiqueta</label><select name="tag" aria-label="Filtrar por etiqueta" onchange="this.form.submit()"><option value="">Cualquiera</option><?php foreach($TAGS as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $fTag==(string)$t['id']?'selected':'' ?>><?= e($t['nombre']) ?></option><?php endforeach; ?></select></div>
    <div><label>Valor (€)</label><div class="two"><input name="vmin" value="<?= e($vmin) ?>" placeholder="mín" onchange="this.form.submit()"><input name="vmax" value="<?= e($vmax) ?>" placeholder="máx" onchange="this.form.submit()"></div></div>
    <?php /* Calendario del ERP en vez del nativo. Lo visible se escribe dd/mm/aa y
             se va copiando al oculto, que es el que viaja en la URL del filtro. */ ?>
    <div style="grid-column:span 2"><label>Creado entre</label><div class="two">
      <input type="text" class="dpick" data-iso="<?= e($fdesde) ?>" data-sync="#cmFDesde" data-onchange="cmFiltroFecha" placeholder="desde" autocomplete="off">
      <input type="text" class="dpick" data-iso="<?= e($fhasta) ?>" data-sync="#cmFHasta" data-onchange="cmFiltroFecha" placeholder="hasta" autocomplete="off">
      </div>
      <input type="hidden" name="fdesde" id="cmFDesde" value="<?= e($fdesde) ?>">
      <input type="hidden" name="fhasta" id="cmFHasta" value="<?= e($fhasta) ?>"></div>
    <div class="full"><a class="cm-clear" href="crm.php<?= $quick!==''?('?quick='.e($quick)):'' ?>">Limpiar filtros</a></div>
  </div>
</form>

<?php if($nAdv || $q!==''): ?>
<div class="cm-chips">
  <?php if($q!==''): ?><span class="cm-fchip">«<?= e($q) ?>» <a href="<?= e(qs_set('q','')) ?>" title="Quitar">✕</a></span><?php endif; ?>
  <?php foreach($adv as $af): ?><span class="cm-fchip"><?= e($af[1]) ?> <a href="<?= e(qs_set($af[0],'')) ?>" title="Quitar">✕</a></span><?php endforeach; ?>
  <a class="cm-clear" href="crm.php<?= $quick!==''?('?quick='.e($quick)):'' ?>">Limpiar todo</a>
</div>
<?php endif; ?>

<?php if($q!=='' && $dealHits): ?>
<div class="cm-deals">
  <div class="h">Negocios que coinciden (<?= count($dealHits) ?>)</div>
  <div class="row">
    <?php foreach($dealHits as $dh): $st=$STAGES[$dh['fase']]??['nombre'=>$dh['fase'],'color'=>'#98a2b3']; ?>
    <a href="negocio.php"><b><?= e($dh['nombre']) ?></b> <span style="color:var(--muted)"><?= e($dh['c_nombre']) ?></span><?php if($dh['valor']!==null): ?> · <span class="dv"><?= number_format((float)$dh['valor'],0,',','.') ?> €</span><?php endif; ?> <span class="cm-tag" style="background:<?= e($st['color']) ?>"><?= e($st['nombre']) ?></span></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="cm-wrap">
<?php if(!$rows): ?>
  <?= $total ? erp_empty('search','Ningún contacto coincide','Prueba a quitar algún filtro o a buscar otra cosa.') : erp_empty('crm','Aún no hay contactos','Aquí verás tus leads y contactos. Crea el primero con el botón «Nuevo contacto» de arriba.') ?>
<?php else: ?>
  <table class="cm">
    <thead><tr>
      <?php if(can_edit()): ?><th class="cm-chk"><input type="checkbox" id="cmAll" aria-label="Seleccionar todos" onclick="cmToggleAll(this)"></th><?php endif; ?>
      <?= cm_sorth('nombre','Nombre','cm-stick') ?><?= cm_sorth('empresa','Empresa') ?><?= cm_sorth('sector','Sector') ?><th>Email</th><th>Teléfono</th><th>Origen</th><th>Servicio</th><?= cm_sorth('valor','Valor') ?><?= cm_sorth('fase','Embudo de venta') ?><th>Última actualización</th><?= cm_sorth('ult','Últ. contacto') ?><?= cm_sorth('prox','Próxima acción') ?><th>Propietario</th><?= cm_sorth('creado','Creado') ?><?php if(can_edit()): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach($rows as $c): $ed=can_edit(); $svc=json_decode((string)$c['servicio_json'],true); if(!is_array($svc))$svc=[];
      $stg=$STAGES[$c['fase']]??['nombre'=>$c['fase'],'color'=>'#98a2b3']; ?>
      <?php $cts=$ctagMap[(int)$c['id']]??[]; $overdue=($c['fecha_prox']&&$c['fecha_prox']<=date('Y-m-d')); ?>
      <tr data-id="<?= (int)$c['id'] ?>" oncontextmenu="return cmCtx(event,<?= (int)$c['id'] ?>)">
        <?php if(can_edit()): ?><td class="cm-chk"><input type="checkbox" class="cm-rowchk" aria-label="Seleccionar contacto" value="<?= (int)$c['id'] ?>" onclick="cmChk()"></td><?php endif; ?>
        <td class="cm-stick"><div class="cm-name" onclick="cmOpen(<?= (int)$c['id'] ?>)"><span class="cm-av" style="background:<?= avatar_color($c['nombre']) ?>"><?= e(mb_strtoupper(mb_substr($c['nombre'],0,2))) ?></span><b title="<?= e($c['nombre']) ?>"><?= e($c['nombre']) ?></b></div><?php if($cts): ?><div class="cm-tags"><?php foreach($cts as $t): ?><span class="cm-tag" style="background:<?= e($t['color']?:'#98a2b3') ?>"><?= e($t['nombre']) ?></span><?php endforeach; ?></div><?php endif; ?></td>
        <td class="ed"><input aria-label="Empresa del contacto" title="<?= e($c['empresa']) ?>" value="<?= e($c['empresa']) ?>" <?= $ed?'':'readonly' ?> onchange="cmSave(<?= (int)$c['id'] ?>,'empresa',this.value,this)"></td>
        <td class="ed"><input aria-label="Sector del contacto" list="cmSectors" title="<?= e($c['sector']) ?>" value="<?= e($c['sector']) ?>" <?= $ed?'':'readonly' ?> onchange="cmSave(<?= (int)$c['id'] ?>,'sector',this.value,this)"></td>
        <td class="ed"><input aria-label="Email del contacto" title="<?= e($c['email']) ?>" value="<?= e($c['email']) ?>" <?= $ed?'':'readonly' ?> onchange="cmSave(<?= (int)$c['id'] ?>,'email',this.value,this)"></td>
        <td class="ed"><input aria-label="Teléfono del contacto" title="<?= e($c['telefono']) ?>" value="<?= e($c['telefono']) ?>" <?= $ed?'':'readonly' ?> onchange="cmSave(<?= (int)$c['id'] ?>,'telefono',this.value,this)"></td>
        <td class="ed"><select aria-label="Origen del lead" <?= $ed?'':'disabled' ?> onchange="cmSave(<?= (int)$c['id'] ?>,'origen_lead',this.value,this)"><option value=""></option><?php foreach($ORIGENES as $o): ?><option value="<?= e($o) ?>" <?= $c['origen_lead']===$o?'selected':'' ?>><?= e($o) ?></option><?php endforeach; ?></select></td>
        <td><div class="cm-svc" data-id="<?= (int)$c['id'] ?>" data-svc='<?= e(json_encode($svc)) ?>' <?= $ed?'onclick="cmSvc(this,event)"':'' ?>><?php if($svc): foreach($svc as $s): ?><span class="chip"><?= e($s) ?></span><?php endforeach; else: ?><span class="mut">—</span><?php endif; ?></div></td>
        <td class="ed num"><input aria-label="Valor del contacto (€)" value="<?= $c['valor']!==null?e(number_format((float)$c['valor'],0,',','.')):'' ?>" <?= $ed?'':'readonly' ?> placeholder="€" onchange="cmSave(<?= (int)$c['id'] ?>,'valor',this.value,this)"></td>
        <td><?php if($ed): ?><span class="cm-fase" data-fase="<?= e($c['fase']) ?>" style="background:<?= e($stg['color']) ?>" onclick="cmFaseOpen(event,this,<?= (int)$c['id'] ?>)"><span class="cm-dot"></span><span class="cm-fase-txt"><?= e($stg['nombre']) ?></span><span class="cm-fase-arw"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span></span><?php else: ?><span class="cm-badge" style="background:<?= e($stg['color']) ?>"><span class="cm-dot"></span><?= e($stg['nombre']) ?></span><?php endif; ?></td>
        <td class="cm-mut"><?= $c['ultima_actualizacion']?e($c['ultima_actualizacion']):'—' ?></td>
        <td class="ed"><input aria-label="Fecha del último contacto" type="text" <?= $ed?'class="dpick" data-onchange="cmSaveFecha"':'readonly' ?> data-iso="<?= e($c['fecha_ultimo_contacto']) ?>" value="<?= $c['fecha_ultimo_contacto']?e(date('d/m/y',strtotime($c['fecha_ultimo_contacto']))):'' ?>" data-cid="<?= (int)$c['id'] ?>" data-field="fecha_ultimo_contacto" autocomplete="off" style="min-width:120px"></td>
        <td><div class="cm-prox <?= $overdue?'due':'' ?>"><?php if($overdue): ?><span class="cm-duedot" title="Acción vencida"></span><?php endif; ?><input aria-label="Próxima acción" value="<?= e($c['proxima_accion']) ?>" <?= $ed?'':'readonly' ?> placeholder="Acción…" onchange="cmSave(<?= (int)$c['id'] ?>,'proxima_accion',this.value,this)" style="border:none;background:none;font-family:inherit;font-size:13px;padding:4px 6px;border-radius:6px;width:118px"><input aria-label="Fecha de la próxima acción" type="text" <?= $ed?'class="dpick" data-onchange="cmSaveFecha"':'readonly' ?> data-iso="<?= e($c['fecha_prox']) ?>" value="<?= $c['fecha_prox']?e(date('d/m/y',strtotime($c['fecha_prox']))):'' ?>" data-cid="<?= (int)$c['id'] ?>" data-field="fecha_prox" placeholder="dd/mm/aa" autocomplete="off" style="border:none;background:none;font-family:inherit;font-size:11.5px;color:<?= $overdue?'#e5484d':'var(--muted)' ?>;padding:2px 6px;width:74px"></div></td>
        <td class="ed"><select aria-label="Propietario del contacto" <?= $ed?'':'disabled' ?> onchange="cmSave(<?= (int)$c['id'] ?>,'propietario_id',this.value,this)"><option value=""></option><?php foreach($responsables as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (int)$c['propietario_id']===(int)$r['id']?'selected':'' ?>><?= e($r['username']) ?></option><?php endforeach; ?></select></td>
        <td class="cm-mut"><?= $c['fecha_creacion']?date('d/m/Y',strtotime($c['fecha_creacion'])):'' ?></td>
        <?php if($ed): ?><td class="cm-actcol"><button class="cm-rowmenu" onclick="cmRowMenu(event,<?= (int)$c['id'] ?>)" title="Acciones">⋯</button></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

<datalist id="cmSectors"><?php foreach($SECTORS as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>

<div id="cmProfileMask"><div id="cmProfileBox"></div></div>
<div class="cm-ctx" id="cmCtx"></div>

<?php if(can_edit()): ?>
<!-- barra de acciones en lote -->
<div class="cm-bulk" id="cmBulk">
  <span class="n"><b id="cmBulkN">0</b> seleccionados</span>
  <span class="sep"></span>
  <button type="button" class="b" data-menu="owner" onclick="cmBulkMenu(event,'owner')"><span class="i"><?= ic('usercheck',15) ?></span>Asignar<span class="cv"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span></button>
  <button type="button" class="b" data-menu="list" onclick="cmBulkMenu(event,'list')"><span class="i"><?= ic('list',15) ?></span>Añadir a lista<span class="cv"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span></button>
  <button type="button" class="b" data-menu="tag" onclick="cmBulkMenu(event,'tag')"><span class="i"><?= ic('flag',15) ?></span>Etiquetar<span class="cv"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span></button>
  <span class="sep"></span>
  <button type="button" class="b" onclick="cmBulkExport()"><span class="i"><?= ic('download',15) ?></span>Exportar</button>
  <button type="button" class="b danger" onclick="cmBulkDo('del')"><span class="i"><?= ic('trash',15) ?></span>Eliminar</button>
  <button type="button" class="close" onclick="cmClearSel()" title="Quitar la selección"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
</div>
<div class="cm-pop" id="cmBulkPop"></div>
<!-- agendar reunión: fecha con el calendario del ERP, no escrita a mano -->
<div class="cm-mask" id="pfMeetMask"><div class="cm-modal" style="width:400px">
  <h3>Agendar reunión</h3>
  <div class="row2">
    <div><label>Fecha</label><input type="text" class="dpick" id="pfMeetF" placeholder="dd/mm/aaaa" autocomplete="off"></div>
    <div><label>Hora</label><input type="text" id="pfMeetH" placeholder="10:00" autocomplete="off"></div>
  </div>
  <div class="acts">
    <button type="button" onclick="pfMeetClose()">Cancelar</button>
    <button type="button" class="pri" onclick="pfMeetOk()">Agendar</button>
  </div>
</div></div>
<!-- resultado de la reunión -->
<div class="cm-mask" id="pfOutMask"><div class="cm-modal" style="width:440px">
  <h3>Resultado de la reunión</h3>
  <input type="hidden" id="pfOutMid">
  <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin:6px 0 6px">¿Se realizó?</label>
  <div id="pfOutSeg" style="display:flex;gap:6px;margin-bottom:12px">
    <button type="button" class="pf-seg on" data-v="realizada" onclick="pfOutSel(this)">Realizada</button>
    <button type="button" class="pf-seg" data-v="no_show" onclick="pfOutSel(this)">No se presentó</button>
    <button type="button" class="pf-seg" data-v="cancelada" onclick="pfOutSel(this)">Cancelada</button>
  </div>
  <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin:0 0 5px">Notas</label>
  <textarea id="pfOutNotas" rows="3" placeholder="¿Qué salió de la reunión? Próximos pasos…" style="width:100%;border:1px solid var(--line);border-radius:9px;padding:9px 11px;font-family:inherit;font-size:13.5px;resize:vertical"></textarea>
  <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin:12px 0 5px">Mover a fase (opcional)</label>
  <select id="pfOutFase" aria-label="Mover a fase" style="width:100%;border:1px solid var(--line);border-radius:9px;padding:9px 11px;font-family:inherit;font-size:13.5px;background:#fff">
    <option value="">— No cambiar —</option>
    <?php foreach($STAGES as $sk=>$sv): ?><option value="<?= e($sk) ?>"><?= e($sv['nombre']) ?></option><?php endforeach; ?>
  </select>
  <div class="acts">
    <button type="button" onclick="document.getElementById('pfOutMask').classList.remove('on')">Cancelar</button>
    <button type="button" class="pri" onclick="pfOutSave()">Guardar resultado</button>
  </div>
</div></div>
<!-- modal etiquetas -->
<div class="cm-mask" id="cmTagMask"><form class="cm-modal cm-tagmodal" method="post" style="width:420px">
  <input type="hidden" name="action" value="tag_create">
  <h3>Etiquetas</h3>
  <div class="tglist">
    <?php if($TAGS): foreach($TAGS as $t): ?><div class="cm-tagrow"><span class="sw" style="background:<?= e($t['color']?:'#98a2b3') ?>"></span><b><?= e($t['nombre']) ?></b><button type="button" onclick="cmTagDel(<?= (int)$t['id'] ?>)" title="Eliminar">✕</button></div><?php endforeach; else: ?><div style="color:var(--muted);font-size:12.5px">Aún no hay etiquetas.</div><?php endif; ?>
  </div>
  <div class="cm-tagnew"><input type="color" name="color" value="#5b8def"><input type="text" name="nombre" placeholder="Nombre de la etiqueta…" required><button type="submit" class="pri" style="border:none;background:var(--ink-strong);color:#fff;border-radius:9px;padding:9px 14px;font-size:13px;font-weight:600;cursor:pointer">Crear</button></div>
  <div class="acts" style="margin-top:16px;display:flex;justify-content:flex-end"><button type="button" onclick="cmTagModal(false)" style="border:1px solid var(--line);background:#fff;border-radius:10px;padding:9px 15px;font-size:13px;font-weight:600;cursor:pointer">Cerrar</button></div>
</form></div>
<form id="cmTagDelForm" method="post" style="display:none"><input type="hidden" name="action" value="tag_del"><input type="hidden" name="id" id="cmTagDelId"></form>
<form id="cmBulkForm" method="post" style="display:none"><input type="hidden" name="action" value="bulk"><input type="hidden" name="op" id="cmBulkOp"><input type="hidden" name="propietario_id" id="cmBulkOwnerV"><input type="hidden" name="list_id" id="cmBulkListV"><input type="hidden" name="list_name" id="cmBulkListName"><input type="hidden" name="tag_id" id="cmBulkTagV"><div id="cmBulkIds"></div></form>
<form id="cmSaveViewForm" method="post" style="display:none"><input type="hidden" name="action" value="save_view"><input type="hidden" name="nombre" id="cmViewName"><input type="hidden" name="filtros" id="cmViewFiltros"></form>
<form id="cmDelViewForm" method="post" style="display:none"><input type="hidden" name="action" value="del_view"><input type="hidden" name="id" id="cmDelViewId"></form>
<?php endif; ?>

<?php if(can_edit()): ?>
<div class="cm-mask" id="cmMask">
  <form class="cm-modal" method="post">
    <input type="hidden" name="action" value="new_contact">
    <h3>Nuevo contacto</h3>
    <label>Nombre *</label><input name="nombre" required autofocus>
    <div class="row2"><div><label>Empresa</label><input name="empresa"></div><div><label>Sector</label><input list="cmSectors" name="sector"></div></div>
    <div class="row2"><div><label>Email</label><input name="email" type="email"></div><div><label>Teléfono</label><input name="telefono"></div></div>
    <div class="row2">
      <div><label>Origen del lead</label><select name="origen_lead" aria-label="Origen del lead"><option value=""></option><?php foreach($ORIGENES as $o): ?><option value="<?= e($o) ?>"><?= e($o) ?></option><?php endforeach; ?></select></div>
      <div><label>Propietario</label><select name="propietario_id" aria-label="Propietario"><option value=""></option><?php foreach($responsables as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['username']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="acts"><button type="button" onclick="cmClose()">Cancelar</button><button type="submit" class="pri">Crear contacto</button></div>
  </form>
</div>
<div class="svc-pop" id="svcPop"></div>
<div class="cm-pop" id="fasePop"></div>
<form id="cmDelForm" method="post" style="display:none"><input type="hidden" name="action" value="del_contact"><input type="hidden" name="id" id="cmDelId"></form>
<?php endif; ?>

<?php /* 'cli' = id del cliente si este lead ya se convirtió; lo usa el menú del
         botón derecho para ofrecer «Ver cliente» en vez de «Convertir». */
      $CMROW=[]; foreach($rows as $c){ $CMROW[(int)$c['id']]=['n'=>$c['nombre'],'e'=>$c['email']?:'','t'=>$c['telefono']?:'','w'=>$c['whatsapp']?:'','cli'=>(int)($c['client_id']??0)]; } ?>
<script>
var CM_SERVICIOS=<?= json_encode($SERVICIOS, JSON_UNESCAPED_UNICODE) ?>;
var CM_IDS=<?= json_encode($idList) ?>; var CM_CUR=null; var CM_TIPO='nota';
var CM_ROW=<?= json_encode($CMROW, JSON_UNESCAPED_UNICODE) ?>;
var CM_EDIT=<?= can_edit()?'true':'false' ?>;
var CM_ICPLUS=<?= json_encode(ic('plus',13)) ?>;   /* el icono «+» del ERP, para los menús que se pintan desde JS */
var CM_ICCHK=<?= json_encode(ic('check',14)) ?>;   /* la marca de verificación, para señalar la opción activa */
/* Fases del embudo, para pintar el menú de fase sin recargar nada. */
var CM_STAGES=<?php $__st=[]; foreach($STAGES as $__sl=>$__sg) $__st[]=['slug'=>(string)$__sl,'nombre'=>(string)$__sg['nombre'],'color'=>($__sg['color']?:'#98a2b3')]; echo json_encode($__st, JSON_UNESCAPED_UNICODE); ?>;
var CM_ICCLI=<?= json_encode(ic('clients',13)) ?>;
function cmToggleF(){var p=document.getElementById('cmPanel');var b=document.getElementById('cmFBtn');var on=p.classList.toggle('on');b.classList.toggle('open',on);}
/* dropdowns */
function cmDD(id,ev){if(ev)ev.stopPropagation();var d=document.getElementById(id);document.querySelectorAll('.cm-dd').forEach(function(x){if(x!==d)x.classList.remove('on');});d.classList.toggle('on');}
document.addEventListener('click',function(e){if(!e.target.closest('.cm-tool'))document.querySelectorAll('.cm-dd').forEach(function(x){x.classList.remove('on');});});
/* vistas guardadas */
function cmSaveView(){erpPrompt('Guardar vista',' ',{msg:'Se guardan los filtros que tienes puestos ahora mismo.',placeholder:'Nombre de la vista'}).then(function(n){
  if(!n)return;document.getElementById('cmViewName').value=n;document.getElementById('cmViewFiltros').value=location.search;document.getElementById('cmSaveViewForm').submit();});}
function cmDelView(id){erpConfirm('¿Eliminar esta vista?',{danger:true}).then(function(ok){if(!ok)return;document.getElementById('cmDelViewId').value=id;document.getElementById('cmDelViewForm').submit();});}
/* etiquetas */
function cmTagModal(on){var m=document.getElementById('cmTagMask');if(m)m.classList.toggle('on',on);}
function cmTagDel(id){erpConfirm('Se quita de todos los contactos y negocios.',{titulo:'¿Eliminar la etiqueta?',danger:true}).then(function(ok){if(!ok)return;document.getElementById('cmTagDelId').value=id;document.getElementById('cmTagDelForm').submit();});}
/* selección múltiple */
function cmSelIds(){return Array.prototype.slice.call(document.querySelectorAll('.cm-rowchk:checked')).map(function(c){return c.value;});}
function cmChk(){var ids=cmSelIds();var bar=document.getElementById('cmBulk');if(!bar)return;document.getElementById('cmBulkN').textContent=ids.length;bar.classList.toggle('on',ids.length>0);
  /* si la barra se esconde, su menú no puede quedarse flotando en el aire */
  if(!ids.length)cmBulkPopClose();var all=document.getElementById('cmAll');if(all){var tot=document.querySelectorAll('.cm-rowchk').length;all.checked=tot>0&&ids.length===tot;all.indeterminate=ids.length>0&&ids.length<tot;}}
function cmToggleAll(el){document.querySelectorAll('.cm-rowchk').forEach(function(c){c.checked=el.checked;});cmChk();}
function cmClearSel(){document.querySelectorAll('.cm-rowchk,#cmAll').forEach(function(c){c.checked=false;});cmBulkPopClose();cmChk();}
/* ---- Menús de la barra de lote ----
   Antes cada acción era un <select> nativo dentro de una píldora negra. Ahora
   cada botón abre el mismo pop-up blanco que el resto del ERP, con el color de
   la etiqueta o la inicial del compañero, y avisa si todavía no hay listas o
   etiquetas creadas en vez de dejar un desplegable vacío. */
var CM_BULKMENUS={
  owner:[{id:'0',nombre:'Sin propietario'}<?php foreach($responsables as $r): ?>,{id:'<?= (int)$r['id'] ?>',nombre:<?= json_encode($r['username'], JSON_UNESCAPED_UNICODE) ?>}<?php endforeach; ?>],
  list:[<?php $__f=true; foreach($LISTS as $l): echo ($__f?'':',');$__f=false; ?>{id:'<?= (int)$l['id'] ?>',nombre:<?= json_encode($l['nombre'], JSON_UNESCAPED_UNICODE) ?>}<?php endforeach; ?>],
  tag:[<?php $__f=true; foreach($TAGS as $t): echo ($__f?'':',');$__f=false; ?>{id:'<?= (int)$t['id'] ?>',nombre:<?= json_encode($t['nombre'], JSON_UNESCAPED_UNICODE) ?>,color:<?= json_encode($t['color']?:'#98a2b3') ?>}<?php endforeach; ?>]
};
var CM_BULKTIT={owner:'Asignar propietario',list:'Añadir a la lista',tag:'Poner etiqueta'};
var CM_BULKVACIO={owner:'No hay compañeros dados de alta.',list:'Aún no has creado ninguna lista.',tag:'Aún no has creado ninguna etiqueta.'};
function cmBulkPopClose(){
  var p=document.getElementById('cmBulkPop'); if(p){p.classList.remove('on');p.dataset.kind='';}
  document.querySelectorAll('.cm-bulk .b.open').forEach(function(b){b.classList.remove('open');});
}
function cmBulkMenu(ev,kind){
  ev.stopPropagation();
  var pop=document.getElementById('cmBulkPop'); if(!pop)return;
  var abierto=(pop.dataset.kind===kind && pop.classList.contains('on'));
  cmBulkPopClose(); cmFaseClose();
  if(abierto)return;
  var btn=ev.currentTarget; btn.classList.add('open');
  pop.dataset.kind=kind; pop.innerHTML='';
  var tit=document.createElement('div'); tit.className='ph'; tit.textContent=CM_BULKTIT[kind]||''; pop.appendChild(tit);
  if(kind==='list'){
    var nb=document.createElement('button'); nb.type='button'; nb.className='newl';
    nb.innerHTML='<span class="d plus">＋</span><span class="t">Crear lista nueva…</span>';
    nb.onclick=function(e){e.stopPropagation();cmBulkPopClose();cmBulkNewList();};
    pop.appendChild(nb);
  }
  var items=CM_BULKMENUS[kind]||[];
  if(kind==='list' && !items.length){
    /* con listas ya está la opción de crear; no mostramos el vacío */
  } else if(!items.length){
    var v=document.createElement('div'); v.className='empty'; v.textContent=CM_BULKVACIO[kind]||'Nada que elegir.'; pop.appendChild(v);
  } else items.forEach(function(it){
    var b=document.createElement('button'); b.type='button';
    b.innerHTML=(it.color?'<span class="d" style="background:'+escHtml(it.color)+'"></span>':'')
              + '<span class="t">'+escHtml(it.nombre)+'</span>';
    b.onclick=function(e){e.stopPropagation();cmBulkPopClose();cmBulkPick(kind,it.id);};
    pop.appendChild(b);
  });
  pop.classList.add('on');
  cmPopPos(pop,btn.getBoundingClientRect(),true);
}
function cmBulkPick(kind,val){
  var ids=cmSelIds(); if(!ids.length)return;
  cmBulkGo(kind,ids,val);
}
function cmBulkDo(op){var ids=cmSelIds();if(!ids.length)return;
  /* Borrar en bloque es lo único que no se puede deshacer, así que se pregunta
     con el diálogo del ERP y el resto de la función se ejecuta después. */
  if(op==='del'){erpConfirm('Se eliminarán '+ids.length+' contacto(s). No se puede deshacer.',{titulo:'¿Eliminar contactos?',danger:true})
    .then(function(ok){if(ok)cmBulkGo('del',ids);});return;}
  cmBulkGo(op,ids);}
function cmBulkNewList(){var ids=cmSelIds();if(!ids.length)return;
  erpPrompt('Crear lista con '+ids.length+' contacto(s)','',{msg:'¿Cómo quieres llamarla?',placeholder:'Ej: Restaurantes VIP',ok:'Crear'}).then(function(nom){
    if(nom==null)return;nom=(''+nom).trim();if(!nom)return;cmBulkGo('newlist',ids,nom);});}
function cmBulkGo(op,ids,val){
  if(op==='owner')document.getElementById('cmBulkOwnerV').value=(val==null?'':val);
  if(op==='list'){if(!val)return;document.getElementById('cmBulkListV').value=val;}
  if(op==='newlist'){if(!val)return;document.getElementById('cmBulkListName').value=val;}
  if(op==='tag'){if(!val)return;document.getElementById('cmBulkTagV').value=val;}
  document.getElementById('cmBulkOp').value=op;
  var box=document.getElementById('cmBulkIds');box.innerHTML='';ids.forEach(function(id){var i=document.createElement('input');i.type='hidden';i.name='ids[]';i.value=id;box.appendChild(i);});
  document.getElementById('cmBulkForm').submit();}
function cmBulkExport(){var ids=cmSelIds();if(!ids.length)return;window.location='crm.php?bulk_export=1&ids='+ids.join(',');}
/* Guardado en línea de la tabla.
   Antes, si el servidor fallaba, salía el aviso rojo pero el valor nuevo se
   quedaba escrito en pantalla: al recargar aparecía el viejo y daba la
   sensación de que el ERP había perdido el cambio. Ahora cada campo recuerda
   el valor bueno (_cmPrev) y se le devuelve si el guardado no sale bien.
   También se mira r.ok: un fetch solo se rompe si no hay red, un error 500
   del servidor llega como respuesta «correcta» y antes se cantaba «Guardado». */
function cmSeedPrev(root){(root||document).querySelectorAll('table.cm input,table.cm select').forEach(function(el){
  if(el._cmPrev===undefined)el._cmPrev=el.classList.contains('dpick')?(el.dataset.iso||''):el.value;});}
if(document.readyState!=='loading')cmSeedPrev();else document.addEventListener('DOMContentLoaded',function(){cmSeedPrev();});
/* Accesibilidad: los desplegables con estilo del ERP generan un botón sin texto
   cuando la casilla está vacía. Copiamos su aria-label al botón para que tenga
   nombre accesible. No cambia nada visual. */
function cmLblTrigs(root){try{(root||document).querySelectorAll('.cs-wrap > select[aria-label]').forEach(function(s){var t=s.parentNode.querySelector('.cs-trig');if(t&&!t.getAttribute('aria-label'))t.setAttribute('aria-label',s.getAttribute('aria-label'));});}catch(e){}}
/* Se lanza con un respiro para que corra DESPUÉS de que el ERP haya convertido
   los <select> en botones (csEnhance se ejecuta en el pie de la página). */
function cmBootLblTrigs(){setTimeout(function(){cmLblTrigs();},0);}
if(document.readyState!=='loading')cmBootLblTrigs();else document.addEventListener('DOMContentLoaded',cmBootLblTrigs);
function cmRevert(el){
  if(!el)return;
  if(el.classList&&el.classList.contains('dpick')){if(window.dpSet)window.dpSet(el,el._cmPrev||'');return;}
  el.value=el._cmPrev||'';
  if(el.tagName==='SELECT'&&window.csRefreshAll)window.csRefreshAll();
}
function cmSave(id,field,val,el){
  fetch('crm.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=inline&id='+id+'&field='+field+'&val='+encodeURIComponent(val)})
  .then(function(r){ if(!r.ok)throw new Error('http '+r.status);
    if(el)el._cmPrev=(el.classList&&el.classList.contains('dpick'))?(el.dataset.iso||''):el.value;
    if(window.toast)toast('Guardado'); })
  .catch(function(){ cmRevert(el); if(window.toast)toast(el?'No se pudo guardar: el campo vuelve a su valor anterior':'No se pudo guardar','err'); });
}
/* El calendario global avisa con (iso,input); aquí el id y el campo van
   escritos en el propio input para no repetirlos en cada celda. */
function cmSaveFecha(iso,inp){cmSave(parseInt(inp.dataset.cid,10),inp.dataset.field,iso,inp);}
/* Los dos filtros de fecha: al elegir día se reenvía el formulario de filtros. */
function cmFiltroFecha(iso,inp){if(inp.form)inp.form.submit();}
/* ---- Cambio de fase desde la lista ----
   Se abre el pop-up blanco del ERP con las fases y su color. La etiqueta cambia
   al instante, sin recargar; si el guardado falla vuelve sola a la fase anterior
   para no dar por bueno un cambio que el servidor no ha aceptado. */
var cmFasePill=null, cmFaseId=0;
function cmFasePaint(pill,slug,color,txt){
  if(!pill)return;
  pill.dataset.fase=slug; pill.style.background=color;
  var t=pill.querySelector('.cm-fase-txt'); if(t)t.textContent=txt;
}
function cmFaseClose(){
  var p=document.getElementById('fasePop'); if(p)p.classList.remove('on');
  if(cmFasePill)cmFasePill.classList.remove('open');
  cmFasePill=null;
}
function cmFaseOpen(ev,pill,id){
  ev.stopPropagation();
  var pop=document.getElementById('fasePop'); if(!pop)return;
  var abierto=(cmFasePill===pill && pop.classList.contains('on'));
  cmFaseClose(); if(abierto)return;
  cmBulkPopClose();
  cmFasePill=pill; cmFaseId=id; pill.classList.add('open');
  var cur=pill.dataset.fase||'';
  pop.innerHTML='';
  CM_STAGES.forEach(function(s){
    var b=document.createElement('button'); b.type='button';
    if(s.slug===cur)b.className='on';
    b.innerHTML='<span class="d" style="background:'+escHtml(s.color)+'"></span>'
              + '<span class="t">'+escHtml(s.nombre)+'</span><span class="ck">'+CM_ICCHK+'</span>';
    b.onclick=function(e){e.stopPropagation();cmFaseSet(s);};
    pop.appendChild(b);
  });
  pop.classList.add('on');
  cmPopPos(pop,pill.getBoundingClientRect());
}
function cmFaseSet(s){
  var pill=cmFasePill, id=cmFaseId;
  if(!pill){cmFaseClose();return;}
  var ant={slug:pill.dataset.fase,color:pill.style.background,
           txt:(pill.querySelector('.cm-fase-txt')||{}).textContent||''};
  cmFaseClose();
  if(ant.slug===s.slug)return;
  cmFasePaint(pill,s.slug,s.color,s.nombre);
  fetch('crm.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=inline&id='+id+'&field=fase&val='+encodeURIComponent(s.slug)})
  .then(function(r){ if(!r.ok)throw new Error('http '+r.status); if(window.toast)toast('Guardado'); })
  .catch(function(){ cmFasePaint(pill,ant.slug,ant.color,ant.txt);
    if(window.toast)toast('No se pudo guardar: la fase vuelve a la anterior','err'); });
}
/* Coloca un .cm-pop pegado a un elemento, sin que se salga de la pantalla.
   Si abajo no cabe, se abre hacia arriba. */
function cmPopPos(pop,r,arriba){
  var w=pop.offsetWidth||200, h=pop.offsetHeight||200;
  var left=Math.max(8,Math.min(r.left,window.innerWidth-w-12));
  var top;
  if(arriba || r.bottom+8+h>window.innerHeight-8) top=Math.max(8,r.top-8-h);
  else top=r.bottom+6;
  if(top+h>window.innerHeight-8) top=Math.max(8,window.innerHeight-8-h);
  pop.style.left=left+'px'; pop.style.top=top+'px';
}
<?php if(can_edit()): ?>
function cmNew(){document.getElementById('cmMask').classList.add('on');}
function cmClose(){document.getElementById('cmMask').classList.remove('on');}
document.getElementById('cmMask').addEventListener('click',function(e){if(e.target===this)cmClose();});
function cmDel(id){erpConfirm('¿Eliminar este contacto?',{danger:true}).then(function(ok){if(!ok)return;document.getElementById('cmDelId').value=id;document.getElementById('cmDelForm').submit();});}
var svcCell=null;
function cmSvc(cell,ev){ev.stopPropagation();svcCell=cell;var cur=[];try{cur=JSON.parse(cell.dataset.svc||'[]');}catch(e){}
  var pop=document.getElementById('svcPop');pop.innerHTML='';
  CM_SERVICIOS.forEach(function(s){var l=document.createElement('label');var cb=document.createElement('input');cb.type='checkbox';cb.checked=cur.indexOf(s)>=0;cb.onchange=function(){cmSvcToggle(s,cb.checked);};l.appendChild(cb);l.appendChild(document.createTextNode(s));pop.appendChild(l);});
  var r=cell.getBoundingClientRect();pop.style.left=Math.min(r.left,window.innerWidth-200)+'px';pop.style.top=(r.bottom+4)+'px';pop.classList.add('on');}
/* Si se mueve la lista por dentro, el pop-up deja de estar donde estaba la celda:
   se cierra en vez de quedarse suelto. */
document.addEventListener('DOMContentLoaded',function(){var w=document.querySelector('.cm-wrap');
  if(w)w.addEventListener('scroll',function(){var p=document.getElementById('svcPop');if(p)p.classList.remove('on');});});
function cmSvcToggle(s,on){var cur=[];try{cur=JSON.parse(svcCell.dataset.svc||'[]');}catch(e){}
  if(on){if(cur.indexOf(s)<0)cur.push(s);}else{cur=cur.filter(function(x){return x!==s;});}
  svcCell.dataset.svc=JSON.stringify(cur);
  svcCell.innerHTML=cur.length?cur.map(function(x){return '<span class="chip">'+escHtml(x)+'</span>';}).join(''):'<span class="mut">—</span>';
  cmSave(parseInt(svcCell.dataset.id),'servicio_json',JSON.stringify(cur));}
document.addEventListener('click',function(e){if(!e.target.closest('#svcPop')&&!e.target.closest('.cm-svc')){document.getElementById('svcPop').classList.remove('on');}});
/* Los dos menús nuevos (fase y lote) se cierran al pulsar fuera, con Escape y
   si la página se desplaza: si no, se quedarían pegados donde ya no hay nada. */
document.addEventListener('click',function(e){
  if(!e.target.closest('#fasePop')&&!e.target.closest('.cm-fase'))cmFaseClose();
  if(!e.target.closest('#cmBulkPop')&&!e.target.closest('.cm-bulk'))cmBulkPopClose();
});
document.addEventListener('keydown',function(e){
  if(e.key!=='Escape')return;
  var f=document.getElementById('fasePop'), b=document.getElementById('cmBulkPop');
  var hab=(f&&f.classList.contains('on'))||(b&&b.classList.contains('on'));
  cmFaseClose(); cmBulkPopClose();
  /* Si no había ningún menú abierto, Escape quita la selección de la lista. */
  if(!hab&&cmSelIds().length&&!document.querySelector('.cm-mask.on,#cmProfileMask.on'))cmClearSel();
});
window.addEventListener('scroll',function(e){
  /* ojo: el propio menú puede tener barra de desplazamiento si hay muchas
     fases; desplazarse DENTRO de él no debe cerrarlo. */
  var t=e.target;
  if(t&&t.closest&&t.closest('.cm-pop'))return;
  cmFaseClose(); cmBulkPopClose();
},true);
<?php else: ?>
function cmNew(){}
<?php endif; ?>

/* ---- perfil ---- */
function cmOpen(id){CM_CUR=id;var box=document.getElementById('cmProfileBox');box.innerHTML='<div style="padding:60px;text-align:center;color:var(--label)">Cargando…</div>';
  document.getElementById('cmProfileMask').classList.add('on');
  /* El panel llega por AJAX, así que su contenido no ha pasado por los arranques
     de la página: hay que darle a mano los desplegables con estilo y el
     calendario. Sin esto, el «Propietario» del perfil salía como desplegable
     gris del navegador mientras el resto del ERP los tiene todos igualados. */
  fetch('crm.php?frag=profile&id='+id).then(function(r){return r.text();}).then(function(h){
    box.innerHTML=h;box.scrollTop=0;
    if(window.csEnhance)window.csEnhance(box);
    cmLblTrigs(box);
    if(window.dpScan)window.dpScan(box);
    cmPos();}).catch(function(){box.innerHTML='<div style="padding:40px">Error al cargar.</div>';});}
function cmReload(){if(CM_CUR)cmOpen(CM_CUR);}
function cmCloseProfile(){document.getElementById('cmProfileMask').classList.remove('on');CM_CUR=null;}
function cmNav(dir){var i=CM_IDS.indexOf(CM_CUR);if(i<0)return;var n=i+dir;if(n>=0&&n<CM_IDS.length)cmOpen(CM_IDS[n]);}
function cmPos(){var i=CM_IDS.indexOf(CM_CUR);var p=document.getElementById('pfPos');if(p)p.textContent=(i+1)+' / '+CM_IDS.length;
  var pv=document.getElementById('pfPrev'),nx=document.getElementById('pfNext');if(pv)pv.disabled=i<=0;if(nx)nx.disabled=i>=CM_IDS.length-1;}
document.getElementById('cmProfileMask').addEventListener('click',function(e){if(e.target===this)cmCloseProfile();});
document.addEventListener('keydown',function(e){if(!CM_CUR)return;if(e.key==='Escape')cmCloseProfile();else if(e.key==='ArrowLeft')cmNav(-1);else if(e.key==='ArrowRight')cmNav(1);});
function pfPickTipo(btn){CM_TIPO=btn.dataset.tp;btn.parentNode.querySelectorAll('.pf-tp').forEach(function(x){x.classList.remove('on');});btn.classList.add('on');}
function pfPublish(cid){var t=document.getElementById('pfCmBody');var b=t.value.trim();if(!b)return;
  fetch('crm.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=add_comment&cid='+cid+'&tipo='+CM_TIPO+'&body='+encodeURIComponent(b)}).then(function(){CM_TIPO='nota';cmReload();});}
function pfDelCm(id,cid){fetch('crm.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=del_comment&id='+id+'&cid='+cid}).then(cmReload);}
function pfBill(cid,field,val){fetch('crm.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=save_billing&cid='+cid+'&field='+field+'&val='+encodeURIComponent(val)}).then(function(){if(window.toast)toast('Guardado');});}
function cmAct(cid,tipo,desc){fetch('crm.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=crm_act&cid='+cid+'&tipo='+tipo+'&desc='+encodeURIComponent(desc)});}
/* Puente CRM -> Clientes. Crea la ficha de cliente con los datos de facturación
   que ya tenía el lead y sus listas por defecto; la contraseña del portal se
   enseña UNA sola vez, aquí, porque después ya no se guarda en claro. */
function pfToCliente(cid){
  erpConfirm('Se crea la ficha con sus datos de facturación y sus listas por defecto. El contacto seguirá en el CRM, enlazado al cliente nuevo.',{titulo:'¿Convertir en cliente?',ok:'Convertir'}).then(function(ok){ if(!ok)return;
    fetch('crm.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=to_client&cid='+cid})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d||!d.ok){ if(window.toast)toast((d&&d.msg)||'No se ha podido convertir','err'); return; }
        if(d.ya){ if(window.toast)toast(d.msg); window.location='client.php?id='+d.id; return; }
        erpAlert('Usuario: '+d.user+'\nContraseña: '+d.pass+'\n\nApúntala ahora: no se vuelve a mostrar. Puedes cambiarla desde la ficha del cliente.',{titulo:d.msg,ok:'Abrir la ficha'})
          .then(function(){ window.location='client.php?id='+d.id; });
      })
      .catch(function(){ if(window.toast)toast('Error al convertir','err'); });
  });}
function pfAddProp(cid){erpPrompt('Nueva propuesta',' ',{placeholder:'Nombre de la propuesta'}).then(function(n){
  if(!n)return;
  erpPrompt('Importe de la propuesta',' ',{msg:'En euros. Puedes dejarlo vacío si aún no lo sabes.',placeholder:'0,00'}).then(function(imp){
    fetch('crm.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=add_proposal&cid='+cid+'&nombre='+encodeURIComponent(n)+'&importe='+encodeURIComponent(imp||'')}).then(cmReload);});});}
function pfDelProp(id,cid){erpConfirm('¿Eliminar propuesta?',{danger:true}).then(function(ok){if(!ok)return;
  fetch('crm.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=del_proposal&id='+id}).then(cmReload);});}
/* La reunión se pide con el calendario del ERP, no escribiendo «dd/mm/aaaa» a
   mano en una ventanita del navegador: es el mismo control que en el resto de
   fechas del proyecto. */
var _pfMeet={nombre:'',email:''};
function pfMeeting(nombre,email){
  _pfMeet={nombre:nombre||'',email:email||''};
  var m=document.getElementById('pfMeetMask');if(!m)return;
  var d=new Date();d.setDate(d.getDate()+1);
  document.getElementById('pfMeetF').value=('0'+d.getDate()).slice(-2)+'/'+('0'+(d.getMonth()+1)).slice(-2)+'/'+d.getFullYear();
  document.getElementById('pfMeetH').value='10:00';
  m.classList.add('on');}
function pfMeetClose(){var m=document.getElementById('pfMeetMask');if(m)m.classList.remove('on');}
function pfMeetOk(){
  var f=document.getElementById('pfMeetF').value.trim(),h=document.getElementById('pfMeetH').value.trim();
  if(!/^\d{2}\/\d{2}\/\d{4}$/.test(f)){if(window.toast)toast('Falta la fecha de la reunión','err');return;}
  pfMeetClose();
  var p=f.split('/'),iso=p[2]+'-'+p[1]+'-'+p[0];
  var titulo='Reunión con '+(_pfMeet.nombre||'contacto');
  /* Registra la reunión en la actividad del contacto (timeline del CRM). */
  fetch('crm.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=meeting&cid='+CM_CUR+'&fecha='+encodeURIComponent(f)+'&hora='+encodeURIComponent(h)}).then(function(r){return r.json();}).then(function(d){
    /* Abre NUESTRO calendario precargado (crea el evento en Google, invita al contacto y lo enlaza con esta reunión). */
    var mid=(d&&d.mid)?d.mid:'';
    var u='calendar.php?view=dia&d='+iso+'&new=1&hora='+encodeURIComponent(h||'10:00')+'&titulo='+encodeURIComponent(titulo)+(_pfMeet.email?('&invitados='+encodeURIComponent(_pfMeet.email)):'')+(mid?('&meeting='+mid):'');
    window.location.href=u;});}
/* Trae de Google el resumen que Gemini adjunta al evento tras la reunión y lo pinta debajo. */
function pfMeetingNotes(mid,btn){
  var t0=btn?btn.innerHTML:'';if(btn){btn.disabled=true;btn.textContent='Buscando…';}
  fetch('crm.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=meeting_notes&mid='+mid+'&cid='+CM_CUR}).then(function(r){return r.json();}).then(function(d){
    if(btn){btn.disabled=false;btn.innerHTML=t0;}
    if(!d||!d.ok){if(window.toast)toast((d&&d.msg)||'No se pudieron traer las notas','err');return;}
    var box=document.querySelector('.mt-docs[data-mid="'+mid+'"]');
    if(box){box.innerHTML='';(d.docs||[]).forEach(function(doc){ if(!doc.url)return;
      var a=document.createElement('a');a.href=doc.url;a.target='_blank';a.rel='noopener';
      a.style.cssText='display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#3c4149;background:#f2f2f3;border:1px solid var(--line);border-radius:8px;padding:5px 9px;text-decoration:none';
      a.textContent='📄 '+(doc.title||'Notas de la reunión');box.appendChild(a);});
      box.style.display=(d.docs&&d.docs.length)?'flex':'none';}
    if(window.toast)toast((d.docs&&d.docs.length)?'Notas de Gemini vinculadas ✓':'Enlazado, pero aún sin notas','plain');
    if((d.descripcion||'').trim()!==''||(d.docs&&d.docs.length)){setTimeout(function(){cmReload();},700);}
  }).catch(function(){if(btn){btn.disabled=false;btn.innerHTML=t0;}if(window.toast)toast('Error de red','err');});}
/* Registrar el resultado de una reunión pasada (realizada / no show / cancelada + notas + mover fase). */
function pfOutcome(mid){document.getElementById('pfOutMid').value=mid;
  document.querySelectorAll('#pfOutSeg .pf-seg').forEach(function(b,i){b.classList.toggle('on',i===0);});
  document.getElementById('pfOutNotas').value='';document.getElementById('pfOutFase').value='';
  document.getElementById('pfOutMask').classList.add('on');}
function pfOutSel(btn){btn.parentNode.querySelectorAll('.pf-seg').forEach(function(b){b.classList.remove('on');});btn.classList.add('on');}
function pfOutSave(){var mid=document.getElementById('pfOutMid').value;var seg=document.querySelector('#pfOutSeg .pf-seg.on');var estado=seg?seg.dataset.v:'realizada';
  var notas=document.getElementById('pfOutNotas').value,fase=document.getElementById('pfOutFase').value;
  document.getElementById('pfOutMask').classList.remove('on');
  fetch('crm.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=meeting_outcome&mid='+mid+'&cid='+CM_CUR+'&estado='+estado+'&notas='+encodeURIComponent(notas)+'&fase='+encodeURIComponent(fase)}).then(function(){if(window.toast)toast('Resultado guardado');cmReload();});}
function pfTag(cid,tid,el){var on=!el.classList.contains('on');
  fetch('crm.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=contact_tag&cid='+cid+'&tag_id='+tid+'&on='+(on?'1':'0')}).then(function(){if(window.toast)toast(on?'Etiqueta añadida':'Etiqueta quitada');});
  el.classList.toggle('on',on);}
function pfUpload(cid,input){if(!input.files||!input.files[0])return;var fd=new FormData();fd.append('action','upload_att');fd.append('cid',cid);fd.append('file',input.files[0]);
  fetch('crm.php',{method:'POST',body:fd}).then(function(){cmReload();});}
function pfDelAtt(id,cid){erpConfirm('¿Eliminar el archivo?',{danger:true}).then(function(ok){if(!ok)return;
  fetch('crm.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=att_del&id='+id+'&cid='+cid}).then(cmReload);});}

/* ---- menú click derecho en contacto ---- */
function cmCtx(ev,id){ ev.preventDefault(); var d=CM_ROW[id]; if(!d)return false; var m=document.getElementById('cmCtx');
  var h='<div class="sub">'+escHtml(d.n)+'</div>';
  h+='<button onclick="cmCtxA(\'open\','+id+')">Abrir ficha</button>';
  if(d.e)h+='<a href="https://mail.google.com/mail/?view=cm&to='+encodeURIComponent(d.e)+'" target="_blank" onclick="cmCtxHide()">Enviar email</a>';
  if(d.t)h+='<a href="tel:'+encodeURIComponent(safeTel(d.t))+'" onclick="cmCtxHide()">Llamar</a>';
  if(d.w||d.t)h+='<a href="https://wa.me/'+String(d.w||d.t).replace(/[^0-9]/g,'')+'" target="_blank" onclick="cmCtxHide()">WhatsApp</a>';
  if(d.cli)h+='<a href="client.php?id='+d.cli+'" onclick="cmCtxHide()">Ver ficha de cliente</a>';
  if(CM_EDIT){ h+='<div class="sep"></div><button onclick="cmCtxA(\'deal\','+id+')">'+CM_ICPLUS+' Crear negocio</button>';
    if(!d.cli)h+='<button onclick="cmCtxA(\'cliente\','+id+')">'+CM_ICCLI+' Convertir en cliente</button>';
    h+='<button onclick="cmCtxA(\'del\','+id+')" class="danger">Eliminar contacto</button>'; }
  m.innerHTML=h; m.style.left=Math.min(ev.clientX,window.innerWidth-214)+'px'; m.style.top=Math.min(ev.clientY,window.innerHeight-300)+'px'; m.classList.add('on'); return false; }
function cmRowMenu(ev,id){ev.stopPropagation();cmCtx(ev,id);}
function cmCtxHide(){document.getElementById('cmCtx').classList.remove('on');}
function cmCtxA(a,id){ cmCtxHide();
  if(a==='open')cmOpen(id);
  else if(a==='del'){ if(typeof cmDel==='function')cmDel(id); }
  else if(a==='cliente'){ pfToCliente(id); }
  else if(a==='deal'){ erpConfirm('¿Crear un negocio para este contacto?',{ok:'Crear negocio'}).then(function(ok){if(!ok)return;
    fetch('negocio.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=new_deal&contact_id='+id}).then(function(){window.location='negocio.php';}); }); } }
document.addEventListener('click',function(e){if(!e.target.closest('#cmCtx'))cmCtxHide();});
<?php if(($_GET['open']??'')!==''): ?>setTimeout(function(){cmOpen(<?= (int)$_GET['open'] ?>);},60);<?php endif; ?>
<?php if($flash!==''): ?>setTimeout(function(){if(window.toast)toast(<?= json_encode($flash) ?>);},80);<?php endif; ?>
/* atajos de teclado */
document.addEventListener('keydown',function(e){
  if(e.target&&/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName))return;
  if(document.getElementById('cmProfileMask')&&document.getElementById('cmProfileMask').classList.contains('on'))return;
  if(e.key==='/'){e.preventDefault();var s=document.querySelector('.cm-search input');if(s)s.focus();}
  else if(e.key==='n'&&typeof cmNew==='function'){e.preventDefault();cmNew();}
});
</script>

<?php
function qs_set($key,$val){ $keep=['q','sector','origen','fase','servicio','prop','vmin','vmax','fdesde','fhasta','quick'];
  $p=[]; foreach($keep as $k){ if(($_GET[$k]??'')!=='') $p[$k]=$_GET[$k]; }
  if($val==='') unset($p[$key]); else $p[$key]=$val;
  return 'crm.php'.($p?('?'.http_build_query($p)):''); }
erp_foot();
?>
