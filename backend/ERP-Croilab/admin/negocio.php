<?php
/* CRM v1.3 — Fase 4: Negocio (embudo de venta) — tablero kanban con drag-drop.
   Nomenclatura: "Embudo de venta". Columnas = pipeline_stages. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
/* Borrar en el ERP no es definitivo: pasa por la papelera y se puede deshacer. */
require_once __DIR__ . '/lib/papelera.php';
require_once __DIR__ . '/lib/crm_lib.php';
ensure_crm_schema();
/* Fase 5: los puentes entre módulos (negocio ganado -> cliente y -> factura). */
require_once __DIR__ . '/lib/puentes.php';
ensure_puentes_schema();

$STAGES   = crm_stages();
$SERVICIOS= crm_servicios();
$MOTIVOS  = crm_motivos_perdida();
$responsables = db()->query('SELECT id, username FROM admins ORDER BY username')->fetchAll();
$respMap=[]; foreach($responsables as $r) $respMap[(int)$r['id']]=$r['username'];
$TAGS = crm_all_tags();

/* De qué contacto es un negocio. Hay que preguntarlo ANTES de borrarlo. */
function deal_contact($did){ $did=(int)$did; if(!$did) return 0;
  try{ $q=db()->prepare('SELECT contact_id FROM deals WHERE id=?'); $q->execute([$did]); return (int)$q->fetchColumn(); }catch(Exception $e){ return 0; } }

/* ---------------- POST ---------------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && can_edit()) {
  $a=$_POST['action']??'';
  if ($a==='new_deal') {
    $cid=(int)($_POST['contact_id']??0);
    if($cid){
      $ct=db()->prepare('SELECT nombre,valor,propietario_id,servicio_json FROM contacts WHERE id=?'); $ct->execute([$cid]); $c=$ct->fetch();
      $svc=json_decode((string)($c['servicio_json']??''),true); $svc=is_array($svc)&&$svc?$svc[0]:null;
      $nombre=trim($_POST['nombre']??''); if($nombre==='') $nombre=($c['nombre']??'Negocio');
      /* num_es() lee la cantidad como la escribe una persona: «12,5», «12.5» y
         «1.234,56» valen lo que parece que valen. */
      $valor=num_es($_POST['valor']??''); if($valor===null) $valor=($c['valor']!==null?(float)$c['valor']:null);
      db()->prepare('INSERT INTO deals (contact_id,nombre,valor,servicio,fase,probabilidad,fecha_cierre_prevista,propietario_id,fecha_entrada_fase,orden) VALUES (?,?,?,?,?,?,?,?,CURDATE(),0)')
        ->execute([$cid,$nombre,$valor,$svc,'lead_nuevo',(int)($STAGES['lead_nuevo']['probabilidad']??5),
                   ($_POST['fecha_cierre']??'')!==''?$_POST['fecha_cierre']:null, $c['propietario_id']?:null]);
      crm_activity($cid,(int)db()->lastInsertId(),'negocio','Negocio creado: '.$nombre);
      crm_sync_fase_contacto($cid);
      $_SESSION['crm_flash']='Negocio creado';
    }
    header('Location: negocio.php'); exit;
  }
  if ($a==='move_deal') {
    $id=(int)($_POST['id']??0); $fase=$_POST['fase']??''; $st=$STAGES[$fase]??null;
    if($id && $st){
      $prev=db()->prepare('SELECT fase,contact_id FROM deals WHERE id=?'); $prev->execute([$id]); $pd=$prev->fetch();
      $prob=(int)$st['probabilidad'];
      $extra=''; $args=[$fase,$prob,$id];
      if($st['tipo']==='ganada'){ $sql='UPDATE deals SET fase=?,probabilidad=?,fecha_cierre_real=CURDATE(),fecha_entrada_fase=CURDATE() WHERE id=?'; }
      elseif($st['tipo']==='perdida'){ $sql='UPDATE deals SET fase=?,probabilidad=?,fecha_cierre_real=CURDATE(),fecha_entrada_fase=CURDATE() WHERE id=?'; }
      else { $sql='UPDATE deals SET fase=?,probabilidad=?,fecha_entrada_fase=CURDATE(),fecha_cierre_real=NULL WHERE id=?'; }
      db()->prepare($sql)->execute($args);
      if($pd && $pd['fase']!==$fase) crm_activity((int)$pd['contact_id'],$id,'fase','Movido a '.$st['nombre']);
      /* La ficha del contacto sigue al negocio: si no, en la lista del CRM se
         quedaba como «Lead nuevo» aunque la tarjeta ya estuviera en Negociación. */
      if($pd) crm_sync_fase_contacto((int)$pd['contact_id']);
      $_SESSION['crm_flash']='Negocio movido a '.$st['nombre'];
    }
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
  }
  if ($a==='mark_lost') {
    $id=(int)($_POST['id']??0); $mot=$_POST['motivo']??''; $txt=trim($_POST['motivo_txt']??'');
    if($id && array_key_exists($mot,$MOTIVOS)){
      $meses=$MOTIVOS[$mot][1]; $react=$meses!==null?date('Y-m-d',strtotime('+'.$meses.' months')):null;
      $pd=db()->prepare('SELECT contact_id FROM deals WHERE id=?'); $pd->execute([$id]); $cid=(int)$pd->fetchColumn();
      db()->prepare('UPDATE deals SET fase=?,probabilidad=0,fecha_cierre_real=CURDATE(),fecha_entrada_fase=CURDATE(),motivo_perdida=?,motivo_perdida_txt=?,fecha_reactivacion=? WHERE id=?')
        ->execute(['perdido',$mot,$txt?:null,$react,$id]);
      crm_activity($cid,$id,'perdida','Perdido — '.$MOTIVOS[$mot][0].($txt?': '.$txt:''));
      crm_sync_fase_contacto($cid);
      $_SESSION['crm_flash']='Negocio marcado como perdido';
    }
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
  }
  if ($a==='deal_inline') {
    $id=(int)($_POST['id']??0); $f=$_POST['field']??''; $v=(string)($_POST['val']??'');
    $allow=['nombre','valor','servicio','fecha_cierre_prevista','propietario_id'];
    if($id && in_array($f,$allow,true)){
      if($f==='valor') $store=num_es($v);
      elseif($f==='propietario_id') $store=($v!==''?(int)$v:null);
      elseif($f==='fecha_cierre_prevista') $store=($v!==''?$v:null);
      else $store=$v;
      db()->prepare("UPDATE deals SET `$f`=? WHERE id=?")->execute([$store,$id]);
    }
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
  }
  /* Archivar, desarchivar o borrar un negocio también cambia la foto del
     contacto, así que su fase se recalcula en los tres casos. */
  if ($a==='archive_deal')   { $did=(int)($_POST['id']??0); $cid=deal_contact($did); db()->prepare('UPDATE deals SET archivado=1,fecha_archivado=CURDATE() WHERE id=?')->execute([$did]); crm_sync_fase_contacto($cid); $_SESSION['crm_flash']='Negocio archivado'; header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit; }
  if ($a==='unarchive_deal') { $did=(int)($_POST['id']??0); $cid=deal_contact($did); db()->prepare('UPDATE deals SET archivado=0,fecha_archivado=NULL WHERE id=?')->execute([$did]); crm_sync_fase_contacto($cid); header('Location: negocio.php?arch=1'); exit; }
  if ($a==='del_deal')       { $did=(int)($_POST['id']??0); $cid=deal_contact($did);
    $dn=db()->prepare('SELECT nombre FROM deals WHERE id=?'); $dn->execute([$did]); $dTit=(string)($dn->fetchColumn() ?: '');
    /* Se guarda en la papelera antes de borrar: un negocio perdido por un clic
       mal dado es dinero que desaparece del embudo sin dejar rastro. */
    $tid = pap_borrar_flash('deals', $did, 'negocio', $dTit, [['tabla'=>'deal_tags','fk'=>'deal_id']],
        $dTit!=='' ? 'Negocio «'.$dTit.'» eliminado' : 'Negocio eliminado');
    db()->prepare('DELETE FROM deals WHERE id=?')->execute([$did]); crm_sync_fase_contacto($cid);
    header('Content-Type: application/json'); echo json_encode(['ok'=>1,'undo'=>$tid]); exit; }
  /* Ganaste el negocio: los dos pasos que antes había que hacer a mano.
     El trabajo de verdad está en lib/puentes.php para que salga idéntico
     desde aquí y desde el CRM. */
  if ($a==='to_client') {
    $did=(int)($_POST['id']??0); $cid=deal_contact($did);
    $r = pu_lead_a_cliente($cid, $did);
    header('Content-Type: application/json'); echo json_encode($r); exit;
  }
  if ($a==='to_invoice') {
    $r = pu_negocio_a_factura((int)($_POST['id']??0));
    header('Content-Type: application/json'); echo json_encode($r); exit;
  }
  if ($a==='deal_tag') { $did=(int)($_POST['id']??0); $tid=(int)($_POST['tag_id']??0); $on=($_POST['on']??'')==='1'; if($did&&$tid){ if($on) db()->prepare('INSERT IGNORE INTO deal_tags (deal_id,tag_id) VALUES (?,?)')->execute([$did,$tid]); else db()->prepare('DELETE FROM deal_tags WHERE deal_id=? AND tag_id=?')->execute([$did,$tid]); } header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit; }
  /* Las fases del embudo salían siempre en el orden en que se crearon: la tabla ya
     tenía columna `orden` y crm_stages() ya ordenaba por ella, pero no había forma
     de cambiarla desde ninguna pantalla. Ahora se arrastra la columna por su asa,
     igual que las tareas y las listas. */
  if ($a==='stage_reorder' && is_owner()) {
    $order=$_POST['order']??[];
    if(is_array($order)){
      $up=db()->prepare('UPDATE pipeline_stages SET orden=? WHERE id=?');
      foreach($order as $i=>$sid){ $up->execute([(int)$i,(int)$sid]); }
    }
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
  }
  if ($a==='stage_save' && is_owner()) {
    $ids=(array)($_POST['sid']??[]);
    foreach($ids as $sid){ $sid=(int)$sid;
      db()->prepare('UPDATE pipeline_stages SET nombre=?, color=?, probabilidad=? WHERE id=?')
        ->execute([trim($_POST['nombre'][$sid]??'')?:'—', $_POST['color'][$sid]??'#94a3b8', max(0,min(100,(int)($_POST['prob'][$sid]??0))), $sid]);
    }
    $_SESSION['crm_flash']='Fases del embudo actualizadas';
    header('Location: negocio.php'); exit;
  }
  /* Añadir un paso nuevo al embudo. Se crea como fase «abierta» y se coloca detrás de
     la última abierta, delante de las de cierre (ganado/perdido/pausa), no al final del
     todo. El slug se genera desde el nombre y se hace único (la columna tiene índice único). */
  if ($a==='stage_add' && is_owner()) {
    $nombre=trim($_POST['nombre']??'');
    if($nombre!==''){
      $base=@iconv('UTF-8','ASCII//TRANSLIT',$nombre); if($base===false) $base=$nombre;
      $base=strtolower(preg_replace('/[^a-z0-9]+/i','_',$base)); $base=trim($base,'_'); if($base==='') $base='fase';
      /* Slugs ya usados: para no chocar con el índice único ni con los slugs reservados. */
      $usados=[]; foreach(crm_stages(true) as $sl=>$sg){ $usados[$sl]=1; }
      $slug=$base; $i=2; while(isset($usados[$slug])){ $slug=$base.'_'.$i; $i++; }
      $prob=max(0,min(100,(int)($_POST['prob']??0)));
      $color=preg_match('/^#[0-9a-fA-F]{6}$/',$_POST['color']??'')?$_POST['color']:'#94a3b8';
      /* Hueco justo tras la última fase abierta: las que van después (las de cierre)
         suben una posición para dejar sitio a la nueva. */
      $orden=(int)db()->query("SELECT COALESCE(MAX(orden),0) FROM pipeline_stages WHERE tipo='abierta'")->fetchColumn()+1;
      db()->prepare('UPDATE pipeline_stages SET orden=orden+1 WHERE orden>=?')->execute([$orden]);
      db()->prepare('INSERT INTO pipeline_stages (nombre,slug,orden,probabilidad,tipo,color) VALUES (?,?,?,?,?,?)')
        ->execute([$nombre,$slug,$orden,$prob,'abierta',$color]);
      $_SESSION['crm_flash']='Fase «'.$nombre.'» añadida';
    }
    header('Location: negocio.php'); exit;
  }
  /* Quitar un paso del embudo. Solo se permiten las fases «abiertas» (las estructurales
     —ganado, perdido, pausa— las usa la lógica del ERP por su slug). Los negocios que
     hubiera en la fase eliminada se mueven a la primera fase abierta que quede, para
     no dejarlos huérfanos. */
  if ($a==='stage_del' && is_owner()) {
    $sid=(int)($_POST['id']??0); $STG=crm_stages(true);
    $delSlug=null; $delTipo=null; $abiertas=[];
    foreach($STG as $sl=>$sg){ if(($sg['tipo']??'')==='abierta') $abiertas[]=$sl; if((int)$sg['id']===$sid){ $delSlug=$sl; $delTipo=$sg['tipo']??''; } }
    if($delSlug!==null && $delTipo==='abierta'){
      $destino=null; foreach($abiertas as $sl){ if($sl!==$delSlug){ $destino=$sl; break; } }
      if($destino!==null){
        $tprob=(int)($STG[$destino]['probabilidad']??0);
        /* Contactos afectados, para poder recalcular su foto tras mover los negocios. */
        $ci=db()->prepare('SELECT DISTINCT contact_id FROM deals WHERE fase=?'); $ci->execute([$delSlug]); $cids=$ci->fetchAll(PDO::FETCH_COLUMN);
        db()->prepare('UPDATE deals SET fase=?,probabilidad=?,fecha_entrada_fase=CURDATE() WHERE fase=?')->execute([$destino,$tprob,$delSlug]);
        db()->prepare('DELETE FROM pipeline_stages WHERE id=?')->execute([$sid]);
        foreach($cids as $cid){ crm_sync_fase_contacto((int)$cid); }
        $_SESSION['crm_flash']='Fase eliminada; sus negocios pasaron a «'.($STG[$destino]['nombre']??$destino).'»';
      } else {
        $_SESSION['crm_flash']='No se puede eliminar: es la única fase abierta del embudo';
      }
    } else {
      $_SESSION['crm_flash']='Esa fase no se puede eliminar';
    }
    header('Location: negocio.php'); exit;
  }
}

/* ---------------- Datos ---------------- */
$showArch = (($_GET['arch']??'')==='1');
$sql='SELECT d.*, c.nombre AS c_nombre, c.empresa AS c_empresa, c.sector AS c_sector, c.client_id AS c_client
      FROM deals d JOIN contacts c ON c.id=d.contact_id
      WHERE d.archivado='.($showArch?'1':'0').' ORDER BY d.orden, d.id DESC';
$deals = db()->query($sql)->fetchAll();

/* Agrupa por fase + calcula footers */
$byFase=[]; foreach($STAGES as $slug=>$s){ $byFase[$slug]=[]; }
foreach($deals as $d){ $byFase[$d['fase']][]=$d; }

/* Etiquetas (tags) por deal */
$dealTags=[]; $dealTagIds=[];
try{
  foreach(db()->query('SELECT dt.deal_id, dt.tag_id, t.nombre, t.color FROM deal_tags dt JOIN crm_tags t ON t.id=dt.tag_id') as $t){
    $dealTags[(int)$t['deal_id']][]=$t; $dealTagIds[(int)$t['deal_id']][]=(int)$t['tag_id'];
  }
}catch(Exception $e){}

/* ---------------- Métricas (6) ---------------- */
$abiertas=array_filter($deals,fn($d)=>($STAGES[$d['fase']]['tipo']??'')==='abierta');
$valPipeline=0; foreach($abiertas as $d){ $valPipeline+=(float)$d['valor']; }
$nAbiertas=count($abiertas);
$mesIni=date('Y-m-01');
$ganMes=(int)db()->query("SELECT COUNT(*) FROM deals WHERE fase='ganado' AND fecha_cierre_real>='$mesIni'")->fetchColumn();
$valGanMes=(float)db()->query("SELECT COALESCE(SUM(valor),0) FROM deals WHERE fase='ganado' AND fecha_cierre_real>='$mesIni'")->fetchColumn();
$totGan=(int)db()->query("SELECT COUNT(*) FROM deals WHERE fase='ganado'")->fetchColumn();
$totPerd=(int)db()->query("SELECT COUNT(*) FROM deals WHERE fase='perdido'")->fetchColumn();
$tasaConv = ($totGan+$totPerd)>0 ? round($totGan*100/($totGan+$totPerd)) : 0;
$ticket = $totGan>0 ? (float)db()->query("SELECT COALESCE(AVG(valor),0) FROM deals WHERE fase='ganado' AND valor IS NOT NULL")->fetchColumn() : 0;


erp_head('crm', 'CRM · Negocio');
$flash=$_SESSION['crm_flash']??''; unset($_SESSION['crm_flash']);
?>
<style>
.ng-top{display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.ng-top h1{font-size:24px;flex:none}
.ng-new{margin-left:auto;border:none;background:var(--ink-strong);color:#fff;border-radius:10px;padding:10px 16px;font-size:13.5px;font-weight:600;cursor:pointer;display:inline-flex;gap:8px;align-items:center}
.ng-new svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2.2;stroke-linecap:round}
.ng-arch{border:1px solid var(--line);background:#fff;color:var(--muted);border-radius:10px;padding:9px 13px;font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none}
.ng-arch.on{background:var(--ink-strong);color:#fff;border-color:var(--ink-strong)}
.ng-metrics{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px}
@media(max-width:1100px){.ng-metrics{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.ng-metrics{grid-template-columns:repeat(2,1fr)}}
.ng-met{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px}
.ng-met .lb{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:650}
.ng-met .vl{font-size:19px;font-weight:750;color:var(--ink-strong);margin-top:4px;letter-spacing:-.4px}
.ng-met .sub{font-size:11.5px;color:var(--muted);margin-top:1px}
.ng-board{display:flex;gap:14px;overflow-x:auto;padding-bottom:14px;align-items:flex-start}
.ng-col{flex:0 0 264px;background:var(--soft);border:1px solid var(--line);border-radius:14px;display:flex;flex-direction:column;max-height:calc(100vh - 250px)}
.ng-col.drop{outline:2px dashed #2f6df6;outline-offset:-2px;background:#eef4ff}
.ng-ch{display:flex;align-items:center;gap:8px;padding:14px 14px 10px}
.ng-ch .dot{width:9px;height:9px;border-radius:50%}
.ng-ch b{font-size:13px;color:var(--ink-strong);font-weight:650}
.ng-ch .n{font-size:11.5px;color:var(--muted);font-weight:600;margin-left:auto;background:#fff;border:1px solid var(--line);border-radius:99px;padding:1px 8px}
.ng-list{flex:1;overflow-y:auto;padding:0 10px 10px;display:flex;flex-direction:column;gap:10px;min-height:40px}
.ng-card{background:#fff;border:1px solid var(--line);border-radius:11px;padding:13px 13px;cursor:grab;box-shadow:0 1px 2px rgba(16,19,24,.03)}
.ng-card:active{cursor:grabbing}
.ng-card{position:relative}
.ng-cardmenu{position:absolute;top:5px;right:5px;border:none;background:none;color:#c2c6cd;cursor:pointer;font-size:16px;line-height:1;padding:2px 7px;border-radius:6px;opacity:0;transition:opacity .12s,background .12s}
.ng-card:hover .ng-cardmenu{opacity:1}
.ng-cardmenu:hover{background:var(--soft);color:var(--ink)}
.ng-card.drag{opacity:.4}
.ng-card .cn{font-size:13.5px;font-weight:650;color:var(--ink-strong);display:flex;align-items:center;gap:6px}
.ng-card .cco{font-size:11.5px;color:var(--muted);margin-top:1px}
.ng-crow{display:flex;align-items:center;gap:6px;margin-top:10px;flex-wrap:wrap}
.ng-val{font-size:13px;font-weight:750;color:var(--ok)}
/* .ng-chip era idéntica a .cm-chip del CRM: las dos son la .chip global. */
.ng-tag{font-size:10px;font-weight:700;border-radius:6px;padding:2px 7px;color:#fff}
.ng-cdate{font-size:11px;color:var(--muted);margin-left:auto;display:inline-flex;align-items:center;gap:4px}
.ng-cdate svg{width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2}
.ng-av{width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:9.5px;font-weight:700}
.ng-warn{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;color:#9a5410;background:#fdf1e3;border-radius:6px;padding:2px 7px}
.ng-warn.red{color:#c62a33;background:#fdeaec}
.ng-cf{border-top:1px solid var(--line);padding:11px 14px;display:flex;flex-direction:column;gap:3px;font-size:11.5px}
.ng-cf .r{display:flex;justify-content:space-between}
.ng-cf .r b{color:var(--ink-strong);font-weight:650}
.ng-cf .r .k{color:var(--muted)}
.ng-empty{color:var(--muted);font-size:12px;text-align:center;padding:14px 0}
/* modales */
.ng-mask{position:fixed;inset:0;background:rgba(16,19,24,.4);display:none;align-items:center;justify-content:center;z-index:500}
.ng-mask.on{display:flex}
.ng-modal{background:#fff;border-radius:18px;width:440px;max-width:94vw;padding:26px 28px;box-shadow:0 30px 70px rgba(0,0,0,.3)}
.ng-modal h3{font-size:18px;font-weight:650;margin:0 0 18px}
.ng-modal label{display:block;font-size:12px;color:var(--muted);font-weight:600;margin:10px 0 5px}
.ng-modal input,.ng-modal select{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font-size:14px;font-family:inherit;color:var(--ink)}
.ng-modal .row2{display:flex;gap:10px}.ng-modal .row2>div{flex:1}
.ng-modal .acts{display:flex;justify-content:flex-end;gap:9px;margin-top:20px}
.ng-modal .acts button{border-radius:10px;padding:10px 16px;font-size:13.5px;font-weight:600;cursor:pointer;border:1px solid var(--line);background:#fff;color:var(--ink)}
.ng-modal .acts button.pri{background:var(--ink-strong);color:#fff;border-color:var(--ink-strong)}
.ng-mot{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:6px}
.ng-mot button{border:1px solid var(--line);background:#fff;border-radius:10px;padding:10px;font-size:13px;font-weight:600;cursor:pointer;color:var(--ink);text-align:left}
.ng-mot button.on{border-color:#c62a33;background:#fdeaec;color:#c0343a}
.ng-ctx{position:fixed;z-index:700;background:#fff;border:1px solid var(--line);border-radius:11px;box-shadow:0 16px 44px rgba(16,19,24,.18);padding:6px;min-width:190px;display:none}
.ng-ctx.on{display:block}
.ng-ctx button,.ng-ctx a{display:flex;align-items:center;gap:9px;width:100%;border:none;background:none;text-align:left;padding:8px 11px;border-radius:8px;font-size:13px;font-weight:500;color:var(--ink);cursor:pointer;font-family:inherit;text-decoration:none}
.ng-ctx button:hover,.ng-ctx a:hover{background:var(--soft)}
.ng-ctx .sep{height:1px;background:var(--line2);margin:5px 4px}
.ng-ctx .danger{color:#c62a33}.ng-ctx .danger:hover{background:#fdeaec}
.ng-ctx .sub{font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:700;padding:6px 11px 3px}
.ng-tagbox{display:flex;gap:6px;flex-wrap:wrap}
.ng-tagchip{border:1px solid var(--line);background:#fff;color:var(--muted);border-radius:8px;padding:4px 11px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit}
.ng-tagchip:hover{background:var(--soft)}
.ng-strow{display:flex;align-items:center;gap:9px;padding:8px 0;border-bottom:1px solid var(--line2)}
.ng-strow input[type=text]{flex:1;border:1px solid var(--line);border-radius:8px;padding:7px 9px;font-size:13px;font-family:inherit}
.ng-strow input[type=color]{width:34px;height:34px;border:1px solid var(--line);border-radius:8px;padding:2px;cursor:pointer;background:#fff}
.ng-strow input[type=number]{width:64px;border:1px solid var(--line);border-radius:8px;padding:7px 8px;font-size:13px;font-family:inherit}
.ng-strow .pc{font-size:12px;color:var(--muted)}
.ng-stdel{border:none;background:none;color:var(--label);cursor:pointer;padding:4px;border-radius:7px;display:inline-flex}
.ng-stdel:hover{background:#fdeaec;color:#c62a33}
.ng-stlock{color:#d3d6db;display:inline-flex;padding:4px}
.ng-staddbtn{margin-top:10px;border:1px dashed var(--line);background:#fff;color:var(--ink);border-radius:10px;padding:9px 14px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;width:100%;justify-content:center}
.ng-staddbtn:hover{background:var(--soft)}
/* ---- Modo oscuro (capa aditiva: solo remapea superficies y textos propios) ---- */
[data-theme=dark] .ng-arch,[data-theme=dark] .ng-met,[data-theme=dark] .ng-ch .n,
[data-theme=dark] .ng-card,[data-theme=dark] .ng-modal,[data-theme=dark] .ng-modal .acts button,
[data-theme=dark] .ng-mot button,[data-theme=dark] .ng-tagchip,[data-theme=dark] .ng-staddbtn{background-color:var(--card)}
[data-theme=dark] .ng-strow input[type=color]{background-color:var(--field)}
[data-theme=dark] .ng-ctx{background-color:var(--pop)}
[data-theme=dark] .ng-col.drop{background-color:var(--soft)}
/* Estados activos «negros» → se invierten */
[data-theme=dark] .ng-new,[data-theme=dark] .ng-arch.on{background-color:var(--rev);color:var(--rev-fg);border-color:var(--rev)}
[data-theme=dark] .ng-modal .acts .pri{background-color:var(--rev)!important;color:var(--rev-fg)!important;border-color:var(--rev)!important}
/* Avisos verde/rojo */
[data-theme=dark] .ng-warn{background-color:var(--soft);color:var(--warn)}
[data-theme=dark] .ng-warn.red,[data-theme=dark] .ng-mot button.on,
[data-theme=dark] .ng-ctx .danger:hover,[data-theme=dark] .ng-stdel:hover{background-color:var(--danger-bg);color:var(--danger)}
[data-theme=dark] .ng-mot button.on{border-color:var(--danger-line)}
[data-theme=dark] .ng-ctx .danger{color:var(--danger)}
/* ============ MÓVIL (≤640px): embudo por columnas deslizables ============ */
@media(max-width:640px){
  .ng-top h1{font-size:21px}
  .ng-metrics{grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:16px}
  .ng-met{padding:13px 14px}
  .ng-met .vl{font-size:17px}
  /* El tablero se desplaza en horizontal y cada columna «encaja» (scroll-snap),
     de modo que se ve una fase a la vez sin agobiar. */
  .ng-board{gap:12px;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}
  .ng-col{flex:0 0 82vw;max-width:82vw;max-height:none;scroll-snap-align:start}
  .ng-list{max-height:none}
  /* Tarjetas de negocio más compactas dentro de cada columna. */
  .ng-card{padding:10px 12px;margin-bottom:8px}
  .ng-card .cn{font-size:13.5px}
  .ng-card .cco{font-size:11.5px}
  .ng-crow{gap:5px;margin-top:6px}
  /* Modales del negocio a casi toda la pantalla. */
  .ng-modal{width:100%!important;max-width:100%;border-radius:16px;padding:22px 18px;max-height:92vh;overflow:auto}
  .ng-modal .row2{flex-direction:column;gap:0}
  .ng-mot{grid-template-columns:1fr}
}
</style>

<div class="ng-top">
  <h1>Negocio</h1>
  <?php if(is_owner() && !$showArch): ?><button class="ng-arch" onclick="ngStages(true)" style="cursor:pointer">Editar fases</button><?php endif; ?>
  <?php if(can_edit()): ?><a href="negocio.php<?= $showArch?'':'?arch=1' ?>" class="ng-arch <?= $showArch?'on':'' ?>"><?= $showArch?'← Activos':'Archivados' ?></a><?php endif; ?>
  <?php if(can_edit() && !$showArch): ?><button class="ng-new" onclick="ngNew()"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Nuevo negocio</button><?php endif; ?>
</div>

<?php if(!$showArch): ?>
<div class="ng-metrics">
  <div class="ng-met"><div class="lb">Negocios abiertos</div><div class="vl"><?= $nAbiertas ?></div><div class="sub">en el embudo</div></div>
  <div class="ng-met"><div class="lb">Valor pipeline</div><div class="vl"><?= eur0($valPipeline) ?></div><div class="sub">bruto abierto</div></div>
  <div class="ng-met"><div class="lb">Ganado (mes)</div><div class="vl"><?= eur0($valGanMes) ?></div><div class="sub"><?= $ganMes ?> negocios</div></div>
  <div class="ng-met"><div class="lb">Tasa conversión</div><div class="vl"><?= $tasaConv ?>%</div><div class="sub">ganado vs cerrado</div></div>
  <div class="ng-met"><div class="lb">Ticket medio</div><div class="vl"><?= eur0($ticket) ?></div><div class="sub">negocios ganados</div></div>
</div>
<?php endif; ?>

<?php /* row-drag-x: el motor de arrastre común de erp_nav.php entiende que aquí las
         columnas van en horizontal, así que la marca azul de destino se dibuja a un
         lado y no arriba o abajo. Solo el dueño puede reordenar, igual que para
         renombrar las fases. */ ?>
<?php $puedeOrdenar = is_owner(); ?>
<?php /* Antes, «Archivados» sin negocios pintaba las 9 columnas del embudo vacías con
         «—», que quedaba desconcertante. Ahora muestra un estado vacío claro. */ ?>
<?php if($showArch && !$deals): ?>
<div class="card" style="padding:48px 20px;text-align:center;color:var(--muted)">
  <div style="width:46px;height:46px;border-radius:14px;background:var(--soft);display:flex;align-items:center;justify-content:center;margin:0 auto 12px"><?= ic('grid',22) ?></div>
  No hay negocios archivados. Los que archives desde el embudo aparecerán aquí.
</div>
<?php else: ?>
<div class="ng-board row-drag-x" id="ngBoard" <?= $puedeOrdenar?'data-reorder="stage_reorder" data-reorder-url="negocio.php"':'' ?>>
  <?php foreach($STAGES as $slug=>$s): $col=$byFase[$slug]; $tot=0; foreach($col as $d){ $tot+=(float)$d['valor']; } ?>
  <div class="ng-col <?= $puedeOrdenar?'row-drag':'' ?>" <?= $puedeOrdenar?'draggable="true" data-rid="'.(int)$s['id'].'"':'' ?> data-fase="<?= e($slug) ?>" data-tipo="<?= e($s['tipo']) ?>">
    <div class="ng-ch"><?= $puedeOrdenar?row_grip():'' ?><span class="dot" style="background:<?= e($s['color']) ?>"></span><b><?= e($s['nombre']) ?></b><span class="n"><?= count($col) ?></span></div>
    <div class="ng-list" data-fase="<?= e($slug) ?>">
      <?php if(!$col): ?><div class="ng-empty">—</div><?php endif; ?>
      <?php foreach($col as $d): $tags=$dealTags[(int)$d['id']]??[];
        $dias = $d['fecha_entrada_fase']?floor((time()-strtotime($d['fecha_entrada_fase']))/86400):0;
        $warn = ($s['tipo']==='abierta' && $dias>=14); $warnRed=($s['tipo']==='abierta' && $dias>=30);
        $ini=$d['c_nombre']?mb_strtoupper(mb_substr($d['c_nombre'],0,2)):'—'; ?>
      <div class="ng-card" draggable="<?= can_edit()?'true':'false' ?>" data-id="<?= (int)$d['id'] ?>" onclick="ngCardClick(event,<?= (int)$d['id'] ?>)" oncontextmenu="return ngCtx(event,<?= (int)$d['id'] ?>)">
        <?php if(can_edit()): ?><button class="ng-cardmenu" onclick="ngCardMenu(event,<?= (int)$d['id'] ?>)" title="Acciones">⋯</button><?php endif; ?>
        <div class="cn"><?= e($d['nombre']) ?></div>
        <div class="cco"><?= e($d['c_empresa']?:$d['c_nombre']) ?><?= $d['c_sector']?' · '.e($d['c_sector']):'' ?></div>
        <div class="ng-crow">
          <?php if($d['valor']!==null): ?><span class="ng-val"><?= eur0($d['valor']) ?></span><?php endif; ?>
          <?php if($d['servicio']): ?><span class="chip"><?= e($d['servicio']) ?></span><?php endif; ?>
          <?php foreach($tags as $t): ?><span class="ng-tag" style="background:<?= e($t['color']?:'#98a2b3') ?>"><?= e($t['nombre']) ?></span><?php endforeach; ?>
          <?php if($d['propietario_id'] && isset($respMap[(int)$d['propietario_id']])): $pn=$respMap[(int)$d['propietario_id']]; ?><span class="ng-av" style="background:<?= avatar_color($pn) ?>" title="<?= e($pn) ?>"><?= e(mb_strtoupper(mb_substr($pn,0,2))) ?></span><?php endif; ?>
          <?php if($d['fecha_cierre_prevista']): ?><span class="ng-cdate"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/></svg><?= date('d/m',strtotime($d['fecha_cierre_prevista'])) ?></span><?php endif; ?>
        </div>
        <?php if($warn||$warnRed): ?><div class="ng-crow"><span class="ng-warn <?= $warnRed?'red':'' ?>">⏱ <?= (int)$dias ?> días sin avanzar</span><?php if($showArch===false && can_edit()): ?><span class="ng-cdate" style="margin-left:auto"><button onclick="ngArchive(<?= (int)$d['id'] ?>,event)" style="border:none;background:none;color:var(--label);cursor:pointer;font-size:11px">archivar</button></span><?php endif; ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="ng-cf"><div class="r"><span class="k">Total</span><b><?= eur0($tot) ?></b></div></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; /* fin del estado vacío de archivados vs board */ ?>

<?php if(can_edit()): ?>
<!-- modal nuevo negocio -->
<div class="ng-mask" id="ngMask">
  <form class="ng-modal" method="post">
    <input type="hidden" name="action" value="new_deal">
    <h3>Nuevo negocio</h3>
    <label>Contacto *</label>
    <select name="contact_id" required id="ngContact"><option value="">Selecciona un contacto…</option>
      <?php foreach(db()->query('SELECT id,nombre,empresa FROM contacts ORDER BY nombre') as $c): ?>
      <option value="<?= (int)$c['id'] ?>"><?= e($c['nombre']) ?><?= $c['empresa']?' — '.e($c['empresa']):'' ?></option>
      <?php endforeach; ?>
    </select>
    <label>Nombre del negocio</label><input name="nombre" placeholder="(por defecto, el del contacto)">
    <div class="row2">
      <div><label>Valor (€)</label><input name="valor" placeholder="€"></div>
      <div><label>Cierre previsto</label><input type="text" class="dpick" data-sync="#ngFechaCierre" autocomplete="off"><input type="hidden" name="fecha_cierre" id="ngFechaCierre"></div>
    </div>
    <div class="acts"><button type="button" onclick="ngCloseNew()">Cancelar</button><button type="submit" class="pri">Crear negocio</button></div>
  </form>
</div>
<!-- modal motivo pérdida -->
<div class="ng-mask" id="ngLostMask">
  <div class="ng-modal">
    <h3>¿Por qué se perdió?</h3>
    <div class="ng-mot" id="ngMot">
      <?php foreach($MOTIVOS as $k=>$v): ?><button type="button" data-mot="<?= e($k) ?>" onclick="ngPickMot(this)"><?= e($v[0]) ?><?php if($v[1]!==null): ?><span style="display:block;font-size:10.5px;color:var(--muted);font-weight:500">reactivar en <?= (int)$v[1] ?> meses</span><?php endif; ?></button><?php endforeach; ?>
    </div>
    <label>Comentario (opcional)</label><input id="ngLostTxt" placeholder="Detalle…">
    <div class="acts"><button type="button" onclick="ngCancelLost()">Cancelar</button><button type="button" class="pri" onclick="ngConfirmLost()">Marcar perdido</button></div>
  </div>
</div>
<!-- modal detalle del negocio -->
<div class="ng-mask" id="ngDealMask">
  <div class="ng-modal" style="width:480px">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px"><h3 id="ngdTitle" style="margin:0;flex:1">Negocio</h3><button type="button" onclick="ngDealClose()" style="border:none;background:var(--soft);width:32px;height:32px;border-radius:9px;cursor:pointer;font-size:15px;color:var(--ink)">✕</button></div>
    <div id="ngdSub" style="font-size:12.5px;color:var(--muted);margin-bottom:8px"></div>
    <label>Nombre del negocio</label><input id="ngdNombre" aria-label="Nombre del negocio" onchange="ngdSave('nombre',this.value)">
    <div class="row2">
      <div><label>Valor (€)</label><input id="ngdValor" aria-label="Valor del negocio en euros" onchange="ngdSave('valor',this.value)"></div>
      <div><label>Cierre previsto</label><input type="text" class="dpick" id="ngdCierre" data-onchange="ngdCierreChg" autocomplete="off"></div>
    </div>
    <div class="row2">
      <div><label>Servicio</label><select id="ngdServicio" aria-label="Servicio" onchange="ngdSave('servicio',this.value)"><option value="">—</option><?php foreach($SERVICIOS as $sv): ?><option value="<?= e($sv) ?>"><?= e($sv) ?></option><?php endforeach; ?></select></div>
      <div><label>Propietario</label><select id="ngdProp" aria-label="Propietario" onchange="ngdSave('propietario_id',this.value)"><option value="">—</option><?php foreach($responsables as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['username']) ?></option><?php endforeach; ?></select></div>
    </div>
    <label>Fase del embudo</label>
    <select id="ngdFase" aria-label="Fase del embudo" onchange="ngdMove(this.value)"><?php foreach($STAGES as $sl=>$sg): ?><option value="<?= e($sl) ?>"><?= e($sg['nombre']) ?></option><?php endforeach; ?></select>
    <?php if($TAGS): ?><label>Etiquetas</label><div class="ng-tagbox" id="ngdTags"></div><?php endif; ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px">
      <button type="button" onclick="ngdVerContacto()" style="border:1px solid var(--line);background:#fff;border-radius:10px;padding:9px 13px;font-size:12.5px;font-weight:600;cursor:pointer;color:var(--ink)">Ver contacto</button>
      <button type="button" id="ngdBCli" onclick="ngdCliente()" style="border:1px solid var(--line);background:#fff;border-radius:10px;padding:9px 13px;font-size:12.5px;font-weight:600;cursor:pointer;color:var(--ink)">Crear cliente</button>
      <button type="button" id="ngdBInv" onclick="ngdFactura()" style="border:1px solid var(--line);background:#fff;border-radius:10px;padding:9px 13px;font-size:12.5px;font-weight:600;cursor:pointer;color:var(--ink)">Crear factura</button>
      <button type="button" onclick="ngdGanado()" style="border:1px solid #bbf7d0;background:#f0fdf4;color:var(--ok);border-radius:10px;padding:9px 13px;font-size:12.5px;font-weight:600;cursor:pointer">Marcar ganado</button>
      <button type="button" onclick="ngdPerdido()" style="border:1px solid #f6d5d7;background:#fff;color:#c62a33;border-radius:10px;padding:9px 13px;font-size:12.5px;font-weight:600;cursor:pointer">Perdido</button>
      <button type="button" onclick="ngdArchivar()" style="border:1px solid var(--line);background:#fff;border-radius:10px;padding:9px 13px;font-size:12.5px;font-weight:600;cursor:pointer;color:var(--muted)">Archivar</button>
      <button type="button" onclick="ngdEliminar()" style="border:none;background:none;color:var(--label);cursor:pointer;font-size:12.5px;margin-left:auto">Eliminar</button>
    </div>
  </div>
</div>
<!-- menú click derecho -->
<div class="ng-ctx" id="ngCtx"></div>
<?php endif; ?>

<?php if(is_owner()): ?>
<!-- editor de fases del embudo -->
<div class="ng-mask" id="ngStagesMask">
  <form class="ng-modal" method="post" style="width:520px;max-height:88vh;overflow:auto">
    <input type="hidden" name="action" value="stage_save">
    <h3>Editar fases del embudo</h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 10px">Nombre, color y probabilidad de cierre (%) de cada fase. Puedes añadir pasos nuevos o quitar los que ya no uses.</p>
    <?php foreach($STAGES as $sl=>$sg): $delOk=(($sg['tipo']??'')==='abierta'); ?>
    <div class="ng-strow">
      <input type="hidden" name="sid[]" value="<?= (int)$sg['id'] ?>">
      <input type="color" name="color[<?= (int)$sg['id'] ?>]" value="<?= e($sg['color']?:'#94a3b8') ?>">
      <input type="text" name="nombre[<?= (int)$sg['id'] ?>]" value="<?= e($sg['nombre']) ?>">
      <input type="number" name="prob[<?= (int)$sg['id'] ?>]" value="<?= (int)$sg['probabilidad'] ?>" min="0" max="100"><span class="pc">%</span>
      <?php if($delOk): ?><button type="button" class="ng-stdel" title="Quitar esta fase" onclick="ngStageDel(<?= (int)$sg['id'] ?>,<?= htmlspecialchars(json_encode($sg['nombre']),ENT_QUOTES) ?>)"><?= ic('trash',15) ?></button><?php else: ?><span class="ng-stlock" title="Fase estructural del ERP: no se puede quitar"><?= ic('bolt',13) ?></span><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <button type="button" class="ng-staddbtn" onclick="ngStageAdd()"><svg viewBox="0 0 24 24" style="width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2.2;stroke-linecap:round"><path d="M12 5v14M5 12h14"/></svg> Añadir fase</button>
    <div class="acts" style="display:flex;justify-content:flex-end;gap:9px;margin-top:16px"><button type="button" onclick="ngStages(false)" style="border:1px solid var(--line);background:#fff;border-radius:10px;padding:10px 16px;font-size:13.5px;font-weight:600;cursor:pointer">Cancelar</button><button type="submit" class="pri" style="background:var(--ink-strong);color:#fff;border:none;border-radius:10px;padding:10px 16px;font-size:13.5px;font-weight:600;cursor:pointer">Guardar</button></div>
  </form>
  <form id="ngStageAddForm" method="post" style="display:none"><input type="hidden" name="action" value="stage_add"><input type="hidden" name="nombre" id="ngStageAddName"><input type="hidden" name="prob" value="20"><input type="hidden" name="color" value="#94a3b8"></form>
  <form id="ngStageDelForm" method="post" style="display:none"><input type="hidden" name="action" value="stage_del"><input type="hidden" name="id" id="ngStageDelId"></form>
</div>
<?php endif; ?>

<?php
$NGD=[]; foreach($deals as $d){ $NGD[(int)$d['id']]=['id'=>(int)$d['id'],'cid'=>(int)$d['contact_id'],'nombre'=>$d['nombre'],'valor'=>$d['valor']!==null?(string)$d['valor']:'','servicio'=>$d['servicio']?:'','fecha'=>$d['fecha_cierre_prevista']?:'','prop'=>$d['propietario_id']!==null?(int)$d['propietario_id']:'','fase'=>$d['fase'],'cn'=>$d['c_nombre'],'ce'=>$d['c_empresa']?:'','tags'=>$dealTagIds[(int)$d['id']]??[],
  /* Puentes: si ya existe la ficha de cliente o la factura, el botón deja de
     crearlas y pasa a abrirlas. */
  'cli'=>(int)(($d['client_id']??0) ?: ($d['c_client']??0)), 'inv'=>(int)($d['invoice_id']??0)]; }
$STAGENAMES=[]; foreach($STAGES as $sl=>$sg) $STAGENAMES[$sl]=$sg['nombre'];
?>
<script>
var NGD=<?= json_encode($NGD, JSON_UNESCAPED_UNICODE) ?>;
var NG_STAGES=<?= json_encode($STAGENAMES, JSON_UNESCAPED_UNICODE) ?>;
var NG_TAGS=<?= json_encode(array_map(fn($t)=>['id'=>(int)$t['id'],'n'=>$t['nombre'],'c'=>$t['color']?:'#98a2b3'],$TAGS), JSON_UNESCAPED_UNICODE) ?>;
var NG_EDIT=<?= can_edit()?'true':'false' ?>;
var ngDidDrag=false, ngCur=null;
function post(body,cb){fetch('negocio.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body}).then(function(){if(cb)cb();}).catch(function(){});}
function ngCardClick(ev,id){ if(ngDidDrag){ngDidDrag=false;return;} if(ev&&ev.target&&ev.target.tagName==='BUTTON')return; if(!NG_EDIT)return; ngDealOpen(id); }
function ngCtx(ev,id){ if(!NG_EDIT)return true; ev.preventDefault(); ngShowCtx(ev,id); return false; }
<?php if(can_edit()): ?>
function ngNew(){document.getElementById('ngMask').classList.add('on');}
function ngCloseNew(){document.getElementById('ngMask').classList.remove('on');}
document.getElementById('ngMask').addEventListener('click',function(e){if(e.target===this)ngCloseNew();});
function ngArchive(id,ev){ev.stopPropagation();erpConfirm('Sale del tablero pero no se borra: lo tienes en el filtro de archivados.',{titulo:'¿Archivar este negocio?',ok:'Archivar'}).then(function(ok){if(ok)post('action=archive_deal&id='+id,function(){ngSoftReload();});});}

/* ---- detalle del negocio ---- */
function ngDealOpen(id){ var d=NGD[id]; if(!d)return; ngCur=id;
  document.getElementById('ngdTitle').textContent=d.nombre||'Negocio';
  document.getElementById('ngdSub').textContent=(d.ce||d.cn||'')+(NG_STAGES[d.fase]?(' · '+NG_STAGES[d.fase]):'');
  document.getElementById('ngdNombre').value=d.nombre||'';
  document.getElementById('ngdValor').value=d.valor||'';
  window.dpSet(document.getElementById('ngdCierre'),d.fecha||'');
  document.getElementById('ngdServicio').value=d.servicio||'';
  document.getElementById('ngdProp').value=d.prop||'';
  document.getElementById('ngdFase').value=d.fase||'';
  var tb=document.getElementById('ngdTags'); if(tb){ tb.innerHTML=''; var cur=d.tags||[];
    NG_TAGS.forEach(function(t){ var on=cur.indexOf(t.id)>=0; var b=document.createElement('button'); b.type='button'; b.className='ng-tagchip'+(on?' on':''); b.textContent=t.n;
      if(on){b.style.background=t.c;b.style.borderColor=t.c;b.style.color='#fff';}
      b.onclick=function(){ ngdTag(t.id,b,t.c); }; tb.appendChild(b); }); }
  /* Los dos puentes cambian de cara: crear si no existe, abrir si ya existe. */
  var bc=document.getElementById('ngdBCli'); if(bc)bc.textContent=d.cli?'Ver cliente':'Crear cliente';
  var bi=document.getElementById('ngdBInv'); if(bi)bi.textContent=d.inv?'Ver factura':'Crear factura';
  document.getElementById('ngDealMask').classList.add('on'); }
/* Puente 1: el contacto del negocio pasa a ser cliente del portal. */
function ngdCliente(){ if(!ngCur)return; var d=NGD[ngCur];
  if(d.cli){ window.location='client.php?id='+d.cli; return; }
  erpConfirm('Se crea la ficha de cliente con los datos de facturación del contacto y sus listas por defecto.',{titulo:'¿Convertir en cliente?',ok:'Convertir'}).then(function(ok){ if(!ok)return;
    fetch('negocio.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=to_client&id='+d.id})
      .then(function(r){return r.json();}).then(function(x){
        if(!x||!x.ok){ if(window.toast)toast((x&&x.msg)||'No se ha podido convertir','err'); return; }
        if(x.ya){ window.location='client.php?id='+x.id; return; }
        erpAlert('Usuario: '+x.user+'\nContraseña: '+x.pass+'\n\nApúntala ahora: no se vuelve a mostrar.',{titulo:x.msg,ok:'Abrir la ficha'})
          .then(function(){ window.location='client.php?id='+x.id; });
      }).catch(function(){ if(window.toast)toast('Error al convertir','err'); }); }); }
/* Puente 2: del negocio sale una factura EN BORRADOR, nunca emitida sin repasar. */
function ngdFactura(){ if(!ngCur)return; var d=NGD[ngCur];
  if(d.inv){ window.location='facturas.php?edit='+d.inv; return; }
  erpConfirm('Se crea en borrador con el importe y el servicio del negocio, para que la repases antes de emitirla.',{titulo:'¿Generar la factura?',ok:'Generar borrador'}).then(function(ok){ if(!ok)return;
    fetch('negocio.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=to_invoice&id='+d.id})
      .then(function(r){return r.json();}).then(function(x){
        if(!x||!x.ok){ if(window.toast)toast((x&&x.msg)||'No se ha podido crear la factura','err'); return; }
        if(window.toast)toast(x.msg); window.location='facturas.php?edit='+x.id;
      }).catch(function(){ if(window.toast)toast('Error al crear la factura','err'); }); }); }
function ngdTag(tid,b,color){ if(!ngCur)return; var d=NGD[ngCur]; d.tags=d.tags||[]; var i=d.tags.indexOf(tid); var on=i<0;
  if(on){d.tags.push(tid);b.classList.add('on');b.style.background=color;b.style.borderColor=color;b.style.color='#fff';}
  else{d.tags.splice(i,1);b.classList.remove('on');b.style.background='';b.style.borderColor='';b.style.color='';}
  post('action=deal_tag&id='+ngCur+'&tag_id='+tid+'&on='+(on?'1':'0')); }
function ngStages(on){var m=document.getElementById('ngStagesMask');if(m)m.classList.toggle('on',on);}
/* Añadir / quitar pasos del embudo (solo dueño). Se envían por su propio formulario
   (POST-redirect) para que se recarguen las columnas con el nuevo embudo. */
function ngStageAdd(){ erpPrompt('Nombre de la nueva fase','',{titulo:'Añadir fase',placeholder:'Ej: Demo agendada',ok:'Añadir'}).then(function(n){ if(!n)return; document.getElementById('ngStageAddName').value=n; document.getElementById('ngStageAddForm').submit(); }); }
function ngStageDel(id,name){ erpConfirm('Los negocios que estén en «'+name+'» pasarán a la primera fase abierta del embudo; no se pierde ninguno.',{titulo:'¿Quitar la fase «'+name+'»?',danger:true,ok:'Quitar'}).then(function(ok){ if(!ok)return; document.getElementById('ngStageDelId').value=id; document.getElementById('ngStageDelForm').submit(); }); }
function ngDealClose(){document.getElementById('ngDealMask').classList.remove('on');ngCur=null;}
/* Refresco parcial (propuesta 5): tras mover/ganar/archivar un negocio desde el
   detalle o el menú, en vez de recargar toda la página (parpadeo blanco) se re-piden
   solo las métricas y el tablero y se cambian en su sitio. El arrastre de tarjetas es
   delegado en document, así que sigue funcionando sobre el tablero nuevo.
   Conserva la posición de scroll horizontal del embudo para NO saltar al inicio. */
function ngSoftReload(){
  var bd=document.getElementById('ngBoard'); var sx=bd?bd.scrollLeft:0;
  fetch(location.href,{headers:{'X-Requested-With':'fetch'}}).then(function(r){return r.text();}).then(function(html){
    var doc=new DOMParser().parseFromString(html,'text/html');
    ['.ng-metrics','#ngBoard'].forEach(function(sel){
      var cur=document.querySelector(sel), nw=doc.querySelector(sel);
      if(cur&&nw){ if(sel==='.ng-metrics')nw.classList.add('erp-in'); cur.replaceWith(nw); } else if(cur&&!nw) cur.remove();
    });
    var nb=document.getElementById('ngBoard'); if(nb)nb.scrollLeft=sx;   // no saltar al inicio
    ['ngDealMask','ngLostMask','ngMask','ngStagesMask'].forEach(function(idm){var m=document.getElementById(idm);if(m)m.classList.remove('on');});
    ngCur=null;
  }).catch(function(){location.reload();});
}
/* Actualización en SITIO tras soltar una tarjeta: la tarjeta ya está movida en el DOM,
   así que no se reconstruye el tablero (ni salta el scroll ni parpadea). Solo se
   re-piden al servidor las métricas y los contadores/totales de cada columna. */
function ngSyncBoard(){
  fetch(location.href,{headers:{'X-Requested-With':'fetch'}}).then(function(r){return r.text();}).then(function(html){
    var doc=new DOMParser().parseFromString(html,'text/html');
    var m=document.querySelector('.ng-metrics'), nm=doc.querySelector('.ng-metrics');
    if(m&&nm){ nm.classList.add('erp-in'); m.replaceWith(nm); }
    [].forEach.call(doc.querySelectorAll('#ngBoard .ng-col'),function(nc){
      var fase=nc.getAttribute('data-fase'); if(!fase)return;
      var cur=document.querySelector('#ngBoard .ng-col[data-fase="'+fase+'"]'); if(!cur)return;
      var a=nc.querySelector('.ng-ch .n'), b=cur.querySelector('.ng-ch .n'); if(a&&b)b.textContent=a.textContent;
      var af=nc.querySelector('.ng-cf'), bf=cur.querySelector('.ng-cf'); if(af&&bf)bf.innerHTML=af.innerHTML;
    });
  }).catch(function(){});
}
document.getElementById('ngDealMask').addEventListener('click',function(e){if(e.target===this)ngDealClose();});
/* El calendario global avisa con (iso,input); el guardado espera (campo,valor). */
function ngdCierreChg(iso){ ngdSave('fecha_cierre_prevista',iso); }
function ngdSave(field,val){ if(!ngCur)return; NGD[ngCur][field==='fecha_cierre_prevista'?'fecha':(field==='propietario_id'?'prop':field)]=val; post('action=deal_inline&id='+ngCur+'&field='+field+'&val='+encodeURIComponent(val),function(){if(window.toast)toast('Guardado');}); }
function ngdMove(fase){ if(!ngCur)return; if(fase==='perdido'){ ngLostId=ngCur; document.getElementById('ngLostMask').classList.add('on'); return; } post('action=move_deal&id='+ngCur+'&fase='+fase,function(){ngSoftReload();}); }
function ngdGanado(){ if(!ngCur)return; erpConfirm('Pasa a la fase «Ganado» y cuenta en el importe cerrado.',{titulo:'¿Marcar como ganado?',ok:'Marcar ganado'}).then(function(ok){if(ok)post('action=move_deal&id='+ngCur+'&fase=ganado',function(){ngSoftReload();});}); }
function ngdPerdido(){ if(!ngCur)return; ngLostId=ngCur; ngDealClose(); document.getElementById('ngLostMask').classList.add('on'); }
function ngdArchivar(){ if(!ngCur)return; erpConfirm('Sale del tablero pero no se borra: lo tienes en el filtro de archivados.',{titulo:'¿Archivar este negocio?',ok:'Archivar'}).then(function(ok){if(ok)post('action=archive_deal&id='+ngCur,function(){ngSoftReload();});}); }
/* Ya no es «no se puede deshacer»: va a la papelera y, al recargar, el aviso de
   arriba trae su botón de Deshacer. */
function ngdEliminar(){ if(!ngCur)return; erpConfirm('El negocio se guarda en la papelera 30 días por si te arrepientes.',{titulo:'¿Eliminar este negocio?',danger:true}).then(function(ok){if(ok)post('action=del_deal&id='+ngCur,function(){location.reload();});}); }
function ngdVerContacto(){ if(ngCur)window.location='crm.php?open='+NGD[ngCur].cid; }

/* ---- menú click derecho ---- */
function ngShowCtx(ev,id){ var d=NGD[id]; if(!d)return; var m=document.getElementById('ngCtx');
  var fases=''; for(var sl in NG_STAGES){ if(sl===d.fase)continue; fases+='<button onclick="ngCtxMove(\''+escJs(sl)+'\')">'+escHtml(NG_STAGES[sl])+'</button>'; }
  m.innerHTML='<div class="sub">'+escHtml(d.nombre||'Negocio')+'</div>'
    +'<button onclick="ngCtxAct(\'open\')">✎ Editar negocio</button>'
    +'<button onclick="ngCtxAct(\'ganado\')">✓ Marcar ganado</button>'
    +'<button onclick="ngCtxAct(\'perdido\')" class="danger">✕ Marcar perdido</button>'
    +'<div class="sep"></div><div class="sub">Mover a fase</div>'+fases
    +'<div class="sep"></div><button onclick="ngCtxAct(\'contacto\')">→ Ver contacto</button>'
    +'<button onclick="ngCtxAct(\'cliente\')">'+(d.cli?'→ Ver cliente':'→ Convertir en cliente')+'</button>'
    +'<button onclick="ngCtxAct(\'factura\')">'+(d.inv?'→ Ver factura':'→ Generar factura')+'</button>'
    +'<button onclick="ngCtxAct(\'archivar\')">Archivar</button>'
    +'<button onclick="ngCtxAct(\'eliminar\')" class="danger">Eliminar</button>';
  ngCur=id; m.style.left=Math.min(ev.clientX,window.innerWidth-210)+'px'; m.style.top=Math.min(ev.clientY,window.innerHeight-320)+'px'; m.classList.add('on'); }
function ngCardMenu(ev,id){ev.stopPropagation();ngShowCtx(ev,id);}
function ngCtxHide(){document.getElementById('ngCtx').classList.remove('on');}
function ngCtxMove(fase){ngCtxHide();ngdMove(fase);}
function ngCtxAct(a){ ngCtxHide();
  if(a==='open')ngDealOpen(ngCur); else if(a==='ganado')ngdGanado(); else if(a==='perdido')ngdPerdido();
  else if(a==='contacto')ngdVerContacto(); else if(a==='cliente')ngdCliente(); else if(a==='factura')ngdFactura();
  else if(a==='archivar')ngdArchivar(); else if(a==='eliminar')ngdEliminar(); }
document.addEventListener('click',function(e){if(!e.target.closest('#ngCtx'))ngCtxHide();});

/* ---- drag & drop de tarjetas ----
   Delegado en document (no atado a los nodos): así el arrastre sigue vivo aunque el
   tablero se repinte, y no choca con el motor de columnas de erp_nav (ese ignora los
   [draggable] internos). Al soltar, el cambio de etapa se GUARDA por AJAX y se
   actualiza en sitio: sin recargar la página ni saltar al inicio del embudo. */
var ngDrag=null;
document.addEventListener('dragstart',function(e){
  var card=e.target.closest('.ng-card[draggable="true"]'); if(!card)return;
  ngDrag=card;ngDidDrag=true;card.classList.add('drag');try{e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain','x');}catch(_){}
});
document.addEventListener('dragend',function(){
  if(!ngDrag)return; ngDrag.classList.remove('drag');
  document.querySelectorAll('.ng-col.drop').forEach(function(c){c.classList.remove('drop');});
  ngDrag=null; setTimeout(function(){ngDidDrag=false;},50);
});
document.addEventListener('dragover',function(e){
  if(!ngDrag)return; var col=e.target.closest('.ng-col'); if(!col)return;
  e.preventDefault();
  document.querySelectorAll('.ng-col.drop').forEach(function(c){if(c!==col)c.classList.remove('drop');});
  col.classList.add('drop');
});
document.addEventListener('drop',function(e){
  if(!ngDrag)return; var col=e.target.closest('.ng-col'); if(!col){ngDrag.classList.remove('drag');return;}
  e.preventDefault();col.classList.remove('drop');
  var card=ngDrag, id=card.dataset.id, fase=col.dataset.fase, tipo=col.dataset.tipo;
  var srcList=card.parentNode;
  var list=col.querySelector('.ng-list'); var emp=list.querySelector('.ng-empty'); if(emp)emp.remove();
  if(tipo==='perdida'){ ngLostId=id; ngLostCard=card; document.getElementById('ngLostMask').classList.add('on'); return; }
  list.appendChild(card);
  /* Si la columna de origen se queda vacía, vuelve a mostrar su marcador «—». */
  if(srcList && srcList!==list && !srcList.querySelector('.ng-card')){ var em=document.createElement('div'); em.className='ng-empty'; em.textContent='—'; srcList.appendChild(em); }
  if(NGD[id])NGD[id].fase=fase;   // el detalle abrirá con la fase correcta
  post('action=move_deal&id='+id+'&fase='+fase,function(){ngSyncBoard();});
});
/* ---- pérdida ---- */
var ngLostId=null,ngLostCard=null,ngLostMot=null;
function ngPickMot(b){ngLostMot=b.dataset.mot;document.querySelectorAll('#ngMot button').forEach(function(x){x.classList.remove('on');});b.classList.add('on');}
function ngCancelLost(){document.getElementById('ngLostMask').classList.remove('on');ngLostId=null;ngLostMot=null;document.querySelectorAll('#ngMot button').forEach(function(x){x.classList.remove('on');});document.getElementById('ngLostTxt').value='';}
/* Falta un dato dentro de una ventana ya abierta: se avisa con el toast del ERP,
   no con la ventanita del navegador encima de la ventana del ERP. */
function ngConfirmLost(){if(!ngLostMot){if(window.toast)toast('Elige un motivo','err');return;}
  post('action=mark_lost&id='+ngLostId+'&motivo='+ngLostMot+'&motivo_txt='+encodeURIComponent(document.getElementById('ngLostTxt').value),function(){ngSoftReload();});}
document.getElementById('ngLostMask').addEventListener('click',function(e){if(e.target===this)ngCancelLost();});
<?php endif; ?>
<?php if($flash!==''): ?>setTimeout(function(){if(window.toast)toast(<?= json_encode($flash) ?>);},80);<?php endif; ?>
<?php /* Enlace directo a un negocio (lo usa el buscador global, igual que crm.php?open=). */
      if(($_GET['open']??'')!==''): ?>setTimeout(function(){ngDealOpen(<?= (int)$_GET['open'] ?>);},60);<?php endif; ?>
</script>

<?php erp_foot(); ?>
