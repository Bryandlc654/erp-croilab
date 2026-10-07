<?php
/* Vista de TAREA a página completa (estilo ClickUp): campos, descripción,
   lista de control (checklist), adjuntos y panel de actividad/comentarios
   con adjuntos, emojis, menciones y reacciones. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/imagen.php';   // reduce las fotos subidas (ahorro de espacio)
ensure_time_schema();

/* Descripción enriquecida (imágenes, archivos, checklist, formato): se guarda en su
   PROPIA columna con marcadores. `descripcion` conserva solo el texto plano derivado,
   que es lo que publica el portal del cliente (regla de oro 1: no romper el portal). */
function ensure_desc_rich(){ static $d=false; if($d)return; $d=true;
  try{ $c=db()->query("SHOW COLUMNS FROM tasks LIKE 'descripcion_rich'")->fetch(); if(!$c) db()->exec("ALTER TABLE tasks ADD COLUMN descripcion_rich MEDIUMTEXT NULL"); }catch(Exception $e){} }
ensure_desc_rich();
/* Respuestas tipo WhatsApp: cada comentario puede responder a otro (reply_to). */
function ensure_comment_reply(){ static $d=false; if($d)return; $d=true;
  try{ $c=db()->query("SHOW COLUMNS FROM task_comments LIKE 'reply_to'")->fetch(); if(!$c) db()->exec("ALTER TABLE task_comments ADD COLUMN reply_to INT NULL"); }catch(Exception $e){} }
ensure_comment_reply();

$ESTADOS = ['pendiente'=>['En espera','#b0b4bb'], 'en proceso'=>['En proceso','#3b82f6'], 'atemporal'=>['Atemporal','#e0a000'], 'completada'=>['Completada','#12a150']];
$PRIOS = [0=>['Ninguna','#cfd2d6'],1=>['Baja','#94a3b8'],2=>['Normal','#3b82f6'],3=>['Alta','#f59e0b'],4=>['Urgente','#ef4444']];
/* Sin .svg a propósito: un SVG lleva XML dentro y puede ejecutar código en el
   navegador de quien lo abra. La lista completa vive en auth.php. */
$ATT_OK = upload_extensiones_ok();

/* Escritor único de lo que ve el cliente (antes esto era una copia de la versión
   de workspace.php, y las dos se habían separado). */
require_once __DIR__ . '/lib/publicar_lib.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
/* Alcance: quien solo ve lo suyo no abre una tarea que no tiene asignada, ni
   escribiendo el id en la barra de direcciones. */
if ($id && function_exists('alcance_exigir_tarea')) alcance_exigir_tarea($id);
$me = current_admin(); $meId = (int)$me['id'];
$ret = $_GET['ret'] ?? '';

$st = db()->prepare("SELECT t.*, c.name AS cname, l.nombre AS lname FROM tasks t JOIN clients c ON c.id=t.client_id JOIN task_lists l ON l.id=t.list_id WHERE t.id=?");
$st->execute([$id]); $t = $st->fetch();
if (!$t) { header('Location: workspace.php?view=all'); exit; }
$cli = (int)$t['client_id'];

function store_files($taskId, $commentId, $meId, $ATT_OK) {
    if (empty($_FILES['files']) || !is_array($_FILES['files']['name'])) return;
    $dir = __DIR__.'/../uploads/tasks'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
    foreach ($_FILES['files']['name'] as $i=>$nm) {
        if ($_FILES['files']['error'][$i]!==0 || ($_FILES['files']['tmp_name'][$i]??'')==='') continue;
        $ext = strtolower(preg_replace('/[^a-z0-9]/','', pathinfo($nm, PATHINFO_EXTENSION)));
        if (!in_array($ext, $ATT_OK, true)) continue;
        $fn = $taskId.'_'.bin2hex(random_bytes(6)).'.'.$ext;
        if (@move_uploaded_file($_FILES['files']['tmp_name'][$i], $dir.'/'.$fn)) {
            img_optimizar($dir.'/'.$fn);
            db()->prepare('INSERT INTO task_attachments (task_id,comment_id,filename,orig_name,mime,admin_id) VALUES (?,?,?,?,?,?)')
               ->execute([$taskId, $commentId, $fn, mb_substr($nm,0,240), $_FILES['files']['type'][$i]??'', $meId]);
        }
    }
}
function del_file_row($row) {
    if (!$row) return;
    $p = __DIR__.'/../uploads/tasks/'.$row['filename'];
    if (is_file($p)) @unlink($p);
    db()->prepare('DELETE FROM task_attachments WHERE id=?')->execute([$row['id']]);
}

if ($_SERVER['REQUEST_METHOD']==='POST' && can_edit()) {
    $a = $_POST['action'] ?? '';

    if ($a==='time_set') {
        $min = (int)round(((float)str_replace(',','.', $_POST['horas'] ?? '0'))*60);
        /* Cada uno apunta LAS SUYAS.

           Antes esto era `$t['responsable_id'] ?: $meId`, o sea: escribieras
           quien escribieras, las horas se le cargaban al responsable. Con una
           tarea de una persona daba igual; con varias asignadas era un problema
           de dinero, no de interfaz: si Bryan se apuntaba sus 5 horas en una
           tarea cuyo responsable es Álvaro, esas 5 horas salían en la
           contabilidad como de Álvaro y **a la tarifa de Álvaro**.

           Ahora la línea es de quien la escribe. `time_entries` ya guardaba
           admin_id y Finanzas › Horas ya factura a cada uno a su tarifa, así que
           con cambiar quién firma la línea, todo lo demás cuadra solo.

           Lo que NO pasa, ni pasaba: multiplicar. Cinco horas en una tarea con
           tres asignados son cinco horas, una línea. Nunca han sido 5 para cada
           uno. */
        /* Por defecto son TUS horas; pero quien puede editar la tarea puede elegir de
           quién son (varias personas asignadas, cada una con sus horas y su tarifa). */
        $who = (int)($_POST['who'] ?? 0);
        if ($who <= 0) { $who = $meId; }
        else { $ck=db()->prepare('SELECT 1 FROM admins WHERE id=?'); $ck->execute([$who]); if(!$ck->fetchColumn()) $who=$meId; }
        /* Antes esto era «DELETE FROM time_entries WHERE task_id=?»: al corregir
           el tiempo de una tarea se borraban TODAS sus líneas, incluidas las que
           el equipo había registrado en Finanzas > Horas (las que se facturan).
           Ahora solo se reemplaza la línea propia de este campo. */
        db()->prepare('DELETE FROM time_entries WHERE task_id=? AND admin_id=? AND concepto=?')
            ->execute([$id, $who, TIME_CONCEPTO_TAREA]);
        if ($min>0) db()->prepare('INSERT INTO time_entries (admin_id, task_id, client_id, fecha, minutos, importe, concepto) VALUES (?,?,?,?,?,?,?)')->execute([$who,$id,$cli,date('Y-m-d'),$min,null,TIME_CONCEPTO_TAREA]);
        echo 'ok'; exit;
    }

    if ($a==='toggle_check') {
        $chkid=(int)($_POST['chkid']??0);
        db()->prepare('UPDATE task_checklist SET done=1-done WHERE id=? AND task_id=?')->execute([$chkid, $id]);
        // Estado nuevo + texto para el aviso
        $cq=db()->prepare('SELECT done,texto FROM task_checklist WHERE id=? AND task_id=?'); $cq->execute([$chkid,$id]); $crow=$cq->fetch();
        if($crow) notif_check_done($id,$chkid,(int)$crow['done'],(string)$crow['texto'],$me['username']);
        header('Content-Type: application/json'); echo json_encode(['ok'=>1,'done'=>$crow?(int)$crow['done']:null]); exit;
    }
    if ($a==='check_resp') {
        $rid=($_POST['rid']??'')!==''?(int)$_POST['rid']:null;
        $chkid=(int)($_POST['chkid']??0);
        db()->prepare('UPDATE task_checklist SET responsable_id=? WHERE id=? AND task_id=?')->execute([$rid,$chkid,$id]);
        chk_set_asignados($chkid, $rid?[$rid]:[]); // sincroniza el puente del punto
        if ($rid) { $ctx=db()->prepare('SELECT texto FROM task_checklist WHERE id=? AND task_id=?'); $ctx->execute([$chkid,$id]); notif_check_assigned($id,$rid,(string)$ctx->fetchColumn(),$me['username']); }
        header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
    }
    if ($a==='check_asig') { // varios responsables para un punto del checklist
        $chkid=(int)($_POST['chkid']??0);
        $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['ids']??[])))));
        $antes=chk_asignados($chkid);
        $ahora=chk_set_asignados($chkid,$ids);
        $ctx=db()->prepare('SELECT texto FROM task_checklist WHERE id=? AND task_id=?'); $ctx->execute([$chkid,$id]); $tx=(string)$ctx->fetchColumn();
        foreach(array_diff($ahora,$antes) as $nid){ notif_check_assigned($id,(int)$nid,$tx,$me['username']); } // avisar a los nuevos
        header('Content-Type: application/json'); echo json_encode(['ok'=>1,'ids'=>$ahora]); exit;
    }
    if ($a==='set_resp') {
        $rid=($_POST['rid']??'')!==''?(int)$_POST['rid']:null;
        db()->prepare('UPDATE tasks SET responsable_id=? WHERE id=?')->execute([$rid,$id]);
        task_set_asignados($id, $rid?[$rid]:[]); // mantener puente coherente
        publicar_progreso($cli);
        if ($rid && $rid!==(int)($t['responsable_id']??0)) notif_task_assigned($id,$rid,$me['username']);
        header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
    }
    if ($a==='set_asignados') {
        $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['ids']??[])))));
        $antes=task_asignados($id);
        $ahora=task_set_asignados($id,$ids);
        publicar_progreso($cli);
        foreach(array_diff($ahora,$antes) as $nid){ notif_task_assigned($id,(int)$nid,$me['username']); } // avisar solo a los nuevos
        header('Content-Type: application/json'); echo json_encode(['ok'=>1,'ids'=>$ahora]); exit;
    }
    if ($a==='set_field') {
        $field=$_POST['field']??''; $val=(string)($_POST['val']??'');
        if (in_array($field,['titulo','descripcion','estado','prioridad','fecha_inicio','due_date','etiquetas','mes'],true)) {
            if ($field==='estado' && !array_key_exists($val,$ESTADOS)) $val='pendiente';
            if ($field==='prioridad') $store=(int)$val;
            elseif (in_array($field,['fecha_inicio','due_date'],true)) $store=($val!==''?$val:null);
            else $store=trim($val);
            if (!($field==='titulo' && $store==='')) {
                db()->prepare("UPDATE tasks SET `$field`=? WHERE id=?")->execute([$store,$id]);
                publicar_progreso($cli);
                /* Avisar a los dueños: puesta en marcha o fecha nueva (ver notif_task_activity). */
                if (function_exists('notif_task_activity')) {
                    if ($field==='estado' && $val==='en proceso' && (string)($t['estado']??'')!=='en proceso')
                        notif_task_activity($id,'start','',(string)$me['username']);
                    elseif (in_array($field,['fecha_inicio','due_date'],true) && $store!==null && (string)($t[$field]??'')!==(string)$store)
                        notif_task_activity($id,'date',($field==='due_date'?'fecha límite ':'inicio ').date('d/m/Y',strtotime($store)),(string)$me['username']);
                }
            }
        }
        header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
    }
    if ($a==='react') {
        $emoji=trim((string)($_POST['emoji']??'')); $cid=(int)($_POST['cid']??0);
        if ($emoji!=='' && $cid) {
            $ex=db()->prepare('SELECT id FROM task_comment_reactions WHERE comment_id=? AND admin_id=? AND emoji=?'); $ex->execute([$cid,$meId,$emoji]);
            if ($ex->fetch()) db()->prepare('DELETE FROM task_comment_reactions WHERE comment_id=? AND admin_id=? AND emoji=?')->execute([$cid,$meId,$emoji]);
            else db()->prepare('INSERT INTO task_comment_reactions (comment_id,admin_id,emoji) VALUES (?,?,?)')->execute([$cid,$meId,$emoji]);
        }
        header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
    }
    if ($a==='save_task') {
        $fields = [
            'titulo'=>trim($_POST['titulo'] ?? ''),
            'descripcion'=>trim($_POST['descripcion'] ?? ''),
            'estado'=>array_key_exists($_POST['estado'] ?? '', $ESTADOS) ? $_POST['estado'] : 'pendiente',
            'responsable_id'=>($_POST['responsable_id'] ?? '')!=='' ? (int)$_POST['responsable_id'] : null,
            'prioridad'=>(int)($_POST['prioridad'] ?? 0),
            'fecha_inicio'=>($_POST['fecha_inicio'] ?? '')!=='' ? $_POST['fecha_inicio'] : null,
            'due_date'=>($_POST['due_date'] ?? '')!=='' ? $_POST['due_date'] : null,
            'etiquetas'=>trim($_POST['etiquetas'] ?? ''),
            'mes'=>trim($_POST['mes'] ?? ''),
        ];
        if ($fields['titulo'] !== '') {
            $set = implode(', ', array_map(fn($k)=>"$k=:$k", array_keys($fields)));
            $params = $fields; $params['id']=$id;
            db()->prepare("UPDATE tasks SET $set WHERE id=:id")->execute($params);
            publicar_progreso($cli);
            $newResp=$fields['responsable_id']?(int)$fields['responsable_id']:0;
            if ($newResp && $newResp!==(int)($t['responsable_id']??0)) notif_task_assigned($id,$newResp,$me['username']);
            /* Avisar a los dueños: puesta en marcha o fecha nueva (igual que la edición en línea). */
            if (function_exists('notif_task_activity')) {
                if ($fields['estado']==='en proceso' && (string)($t['estado']??'')!=='en proceso') notif_task_activity($id,'start','',(string)$me['username']);
                foreach(['fecha_inicio'=>'inicio','due_date'=>'fecha límite'] as $ff=>$lbl){ if(!empty($fields[$ff]) && (string)($t[$ff]??'')!==(string)$fields[$ff]) notif_task_activity($id,'date',$lbl.' '.date('d/m/Y',strtotime($fields[$ff])),(string)$me['username']); }
            }
        }
        header('Location: task.php?id='.$id.($ret?'&ret='.urlencode($ret):'')); exit;
    } elseif ($a==='set_desc') {
        /* Guarda la descripción enriquecida (marcadores) y deriva el texto plano que
           publica el portal del cliente. */
        $rich = (string)($_POST['rich'] ?? '');
        if (mb_strlen($rich) > 200000) $rich = mb_substr($rich, 0, 200000);
        $plain = desc_to_plain($rich);
        db()->prepare('UPDATE tasks SET descripcion_rich=?, descripcion=? WHERE id=?')->execute([$rich, $plain, $id]);
        /* Avisa a quien mencionas en la descripción (una sola vez por persona: la ref
           es estable, así el autoguardado no repite el aviso). */
        if (function_exists('notif_desc_scan') && trim($rich) !== '') notif_desc_scan($id, $rich, (string)$me['username']);
        /* Si esta tarea es de un cliente, republica al portal en el momento: así el
           informe/descripción que el equipo escribe aparece ya en Informes del cliente. */
        if (function_exists('publicar_progreso')) {
            $cliP = db()->prepare('SELECT client_id FROM tasks WHERE id=?'); $cliP->execute([$id]); $cliP=(int)$cliP->fetchColumn();
            if ($cliP) { try { publicar_progreso($cliP); } catch (Exception $e) {} }
        }
        header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
    } elseif ($a==='desc_upload') {
        header('Content-Type: application/json');
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== 0) { echo json_encode(['ok'=>0,'err'=>'no-file']); exit; }
        $nm = (string)$_FILES['file']['name'];
        $ext = strtolower(preg_replace('/[^a-z0-9]/','', pathinfo($nm, PATHINFO_EXTENSION)));
        if (!in_array($ext, $ATT_OK, true)) { echo json_encode(['ok'=>0,'err'=>'ext']); exit; }
        $dir = __DIR__.'/../uploads/tasks'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $fn = $id.'_'.bin2hex(random_bytes(6)).'.'.$ext;   // mismo patrón que store_files → misma limpieza al borrar
        if (@move_uploaded_file($_FILES['file']['tmp_name'], $dir.'/'.$fn)) {
            img_optimizar($dir.'/'.$fn);
            $img =in_array($ext, ['jpg','jpeg','png','gif','webp','avif','bmp'], true);
            echo json_encode(['ok'=>1,'fn'=>$fn,'orig'=>mb_substr($nm,0,240),'img'=>$img,'url'=>'../archivo.php?d=tasks&f='.rawurlencode($fn)]);
        } else { echo json_encode(['ok'=>0,'err'=>'move']); }
        exit;
    } elseif ($a==='add_comment') {
        $body = trim($_POST['cuerpo'] ?? ''); $cid=null;
        $hasFiles = !empty($_FILES['files']) && is_array($_FILES['files']['name']) && ($_FILES['files']['name'][0] ?? '')!=='';
        $chkJson=null; $clRaw=$_POST['checklist']??'';
        if ($clRaw!=='') { $arr=json_decode($clRaw,true); if(is_array($arr)){ $clean=[]; foreach($arr as $itc){ $tx=trim((string)($itc['texto']??'')); if($tx!=='') $clean[]=['texto'=>$tx,'done'=>!empty($itc['done'])?1:0,'resp'=>(isset($itc['resp'])&&$itc['resp']!==null&&$itc['resp']!=='')?(int)$itc['resp']:null]; } if($clean) $chkJson=json_encode($clean,JSON_UNESCAPED_UNICODE); } }
        $replyTo = (int)($_POST['reply_to'] ?? 0);
        if ($body !== '' || $hasFiles || $chkJson) { db()->prepare('INSERT INTO task_comments (task_id, admin_id, cuerpo, checklist_json, reply_to) VALUES (?,?,?,?,?)')->execute([$id, $meId, $body, $chkJson, $replyTo>0?$replyTo:null]); $cid=(int)db()->lastInsertId(); }
        store_files($id, $cid, $meId, $ATT_OK);
        if ($cid && $body!=='') notif_comment_scan($id,$cid,$body,$me['username']);
        /* Aviso específico al autor del comentario original: «te ha respondido». */
        if ($cid && $replyTo>0 && function_exists('notif_add')) {
            try { $oq=db()->prepare('SELECT admin_id FROM task_comments WHERE id=? AND task_id=?'); $oq->execute([$replyTo,$id]); $origAid=(int)$oq->fetchColumn(); } catch(Exception $e){ $origAid=0; }
            if ($origAid && $origAid!==$meId) {
                $plain=trim(preg_replace('/\s+/',' ', str_replace('[[img]]','[img]', strip_tags((string)$body)))); $resumen=mb_substr($plain,0,140);
                notif_add($origAid,'tarea','te ha respondido: '.$resumen,'','task.php?id='.(int)$id.'#c'.$cid,'reply:'.$cid.':'.$origAid,(string)($t['titulo']??''),(string)$me['username']);
            }
        }
        if ($cid && $chkJson) { $arr=json_decode($chkJson,true); if(is_array($arr)) foreach($arr as $itc){ if(!empty($itc['resp'])) notif_check_assigned($id,(int)$itc['resp'],(string)($itc['texto']??''),$me['username']); } }
        header('Location: task.php?id='.$id.($ret?'&ret='.urlencode($ret):'').'#act'); exit;
    } elseif ($a==='comment_check') {
        $cidv=(int)($_POST['cid']??0); $idx=(int)($_POST['idx']??-1);
        $r=db()->prepare('SELECT checklist_json FROM task_comments WHERE id=? AND task_id=?'); $r->execute([$cidv,$id]); $row=$r->fetch();
        if ($row) { $arr=json_decode($row['checklist_json']??'[]',true); if(is_array($arr)&&isset($arr[$idx])){ $arr[$idx]['done']=empty($arr[$idx]['done'])?1:0; db()->prepare('UPDATE task_comments SET checklist_json=? WHERE id=?')->execute([json_encode($arr,JSON_UNESCAPED_UNICODE),$cidv]); } }
        header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
    } elseif ($a==='comment_setchk') {
        /* Añadir/editar la lista de control DENTRO de un comentario ya publicado (#2). */
        $cidv=(int)($_POST['cid']??0); $raw=$_POST['checklist']??'';
        $clean=[]; if($raw!==''){ $arr=json_decode($raw,true); if(is_array($arr)) foreach($arr as $itc){ $tx=trim((string)($itc['texto']??'')); if($tx!=='') $clean[]=['texto'=>$tx,'done'=>!empty($itc['done'])?1:0,'resp'=>(isset($itc['resp'])&&$itc['resp']!==null&&$itc['resp']!=='')?(int)$itc['resp']:null]; } }
        $json = $clean?json_encode($clean,JSON_UNESCAPED_UNICODE):null;
        $old=db()->prepare('SELECT checklist_json FROM task_comments WHERE id=? AND task_id=?'); $old->execute([$cidv,$id]); $oldRow=$old->fetch();
        if($oldRow!==false){
            $oldResp=[]; $oa=json_decode($oldRow['checklist_json']??'[]',true); if(is_array($oa)) foreach($oa as $x){ if(!empty($x['resp']))$oldResp[(int)$x['resp']]=1; }
            db()->prepare('UPDATE task_comments SET checklist_json=? WHERE id=?')->execute([$json,$cidv]);
            /* Avisa solo a los responsables NUEVOS, para no repetir el aviso al re-guardar. */
            foreach($clean as $itc){ $rp=!empty($itc['resp'])?(int)$itc['resp']:0; if($rp && !isset($oldResp[$rp])) notif_check_assigned($id,$rp,(string)$itc['texto'],$me['username']); }
        }
        header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
    } elseif ($a==='del_comment') {
        $c=(int)($_POST['cid']??0);
        foreach (db()->query('SELECT * FROM task_attachments WHERE comment_id='.$c) as $r) del_file_row($r);
        db()->prepare('DELETE FROM task_comments WHERE id=? AND admin_id=?')->execute([$c, $meId]);
        header('Location: task.php?id='.$id.($ret?'&ret='.urlencode($ret):'').'#act'); exit;
    } elseif ($a==='edit_comment') {
        $c=(int)($_POST['cid']??0); $body=trim($_POST['cuerpo']??'');
        if ($body!=='') { db()->prepare('UPDATE task_comments SET cuerpo=? WHERE id=? AND admin_id=?')->execute([$body,$c,$meId]); notif_comment_scan($id,$c,$body,$me['username']); }
        header('Location: task.php?id='.$id.($ret?'&ret='.urlencode($ret):'').'#act'); exit;
    } elseif ($a==='upload_att') {
        store_files($id, null, $meId, $ATT_OK);
        header('Location: task.php?id='.$id.($ret?'&ret='.urlencode($ret):'')); exit;
    } elseif ($a==='del_att') {
        $r=db()->prepare('SELECT * FROM task_attachments WHERE id=? AND task_id=?'); $r->execute([(int)($_POST['aid']??0),$id]); del_file_row($r->fetch());
        header('Location: task.php?id='.$id.($ret?'&ret='.urlencode($ret):'')); exit;
    } elseif ($a==='add_check') {
        $tx=trim($_POST['texto']??''); $rid=($_POST['rid']??'')!==''?(int)$_POST['rid']:null;
        if ($tx!=='') { $mo=(int)db()->query('SELECT COALESCE(MAX(orden),0)+1 FROM task_checklist WHERE task_id='.$id)->fetchColumn();
            db()->prepare('INSERT INTO task_checklist (task_id,texto,responsable_id,orden) VALUES (?,?,?,?)')->execute([$id,$tx,$rid,$mo]);
            $ncid=(int)db()->lastInsertId();
            $rids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['rids']??[])))));
            if(!$rids && $rid) $rids=[$rid];
            if($rids){ chk_set_asignados($ncid,$rids); foreach($rids as $nid){ notif_check_assigned($id,(int)$nid,$tx,$me['username']); } } }
        header('Location: task.php?id='.$id.($ret?'&ret='.urlencode($ret):'').'#chk'); exit;
    } elseif ($a==='del_check') {
        db()->prepare('DELETE FROM task_checklist WHERE id=? AND task_id=?')->execute([(int)($_POST['chkid']??0),$id]);
        header('Location: task.php?id='.$id.($ret?'&ret='.urlencode($ret):'').'#chk'); exit;
    }
}

$responsables = db()->query('SELECT id, username FROM admins ORDER BY username')->fetchAll();
$respMap=[]; foreach($responsables as $r) $respMap[$r['id']]=$r['username'];
$asignados = task_asignados($id); // ids de todos los asignados (item: varios responsables)
$GLOBALS['MENTIONMAP']=[]; $GLOBALS['MENTIONUID']=[]; foreach($responsables as $r){ $k=mb_strtolower($r['username']); $GLOBALS['MENTIONMAP'][$k]=$r['username']; $GLOBALS['MENTIONUID'][$k]=(int)$r['id']; }
$comments = db()->prepare('SELECT c.*, a.username FROM task_comments c LEFT JOIN admins a ON a.id=c.admin_id WHERE c.task_id=? ORDER BY c.created_at ASC, c.id ASC');
$comments->execute([$id]); $comments=$comments->fetchAll();
/* Índice por id para pintar la cita (respuesta) de cada comentario. */
$cmById=[]; foreach($comments as $cc){ $cmById[(int)$cc['id']]=$cc; }
/* Extracto en texto plano de un comentario (para la cita y la preview). */
function cm_excerpt($cuerpo,$n=90){ $s=preg_replace('/\[\[img\]\]|\[\[img:[^\]]*\]\]/','📷 ', (string)$cuerpo); $s=preg_replace('/\[\[file:[^\]]*\]\]/','📎 ', $s); $s=str_replace(['**','__','*','`'],'',$s); $s=trim(preg_replace('/\s+/',' ', strip_tags($s))); return $s!==''?mb_substr($s,0,$n):'Comentario'; }
$checklist = db()->prepare('SELECT * FROM task_checklist WHERE task_id=? ORDER BY done DESC, orden, id'); $checklist->execute([$id]); $checklist=$checklist->fetchAll();
$chkDone=0; foreach($checklist as $ck){ if($ck['done'])$chkDone++; }
$chkAsgMap = chk_asignados_map($id); // chk_id => [admin_id...] para varios responsables por punto
/* asignados válidos de un punto, con fallback a responsable_id */
function chk_ids_de($ck,$chkAsgMap,$respMap){ $ids=$chkAsgMap[(int)$ck['id']]??[]; if(!$ids && !empty($ck['responsable_id'])) $ids=[(int)$ck['responsable_id']]; return array_values(array_filter($ids,fn($i)=>isset($respMap[$i]))); }
$atts = db()->prepare('SELECT * FROM task_attachments WHERE task_id=? ORDER BY id'); $atts->execute([$id]); $atts=$atts->fetchAll();
$attByComment=[]; $taskAtts=[];
foreach($atts as $at){ if($at['comment_id']) $attByComment[$at['comment_id']][]=$at; else $taskAtts[]=$at; }
$reacts=[];
try { foreach (db()->query("SELECT r.comment_id, r.emoji, COUNT(*) n, MAX(r.admin_id=".$meId.") mine FROM task_comment_reactions r JOIN task_comments c ON c.id=r.comment_id WHERE c.task_id=".$id." GROUP BY r.comment_id, r.emoji ORDER BY r.comment_id") as $rr) { $reacts[$rr['comment_id']][]=$rr; } } catch (Exception $e) {}

function ini2($s){ return e(mb_strtoupper(mb_substr((string)$s,0,2))); }
/* Formateador de texto enriquecido compartido por comentarios y descripción.
   Soporta: `código` · [texto](url) y URLs sueltas (azul) · **negrita** __subrayado__
   *cursiva* · @menciones. Es SEGURO: el contenido de cada trozo se escapa con e(),
   así que solo salen las etiquetas que generamos aquí, nunca HTML del usuario. */
function rt_format($s){
  $s=(string)$s; $store=[];
  $ph=function($html) use(&$store){ $k="\x01".count($store)."\x02"; $store[]=$html; return $k; };
  // 1) código inline `x` (su contenido no recibe más formato)
  $s=preg_replace_callback('/`([^`\n]+)`/u', function($m) use($ph){ return $ph('<code class="rt-code">'.e($m[1]).'</code>'); }, $s);
  // 2) enlaces markdown [texto](url)
  $s=preg_replace_callback('/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/u', function($m) use($ph){ return $ph('<a class="rt-link" href="'.e($m[2]).'" target="_blank" rel="noopener">'.e($m[1]).'</a>'); }, $s);
  // 3) URLs sueltas
  $s=preg_replace_callback('/(https?:\/\/[^\s<]+)/u', function($m) use($ph){ return $ph('<a class="rt-link" href="'.e($m[1]).'" target="_blank" rel="noopener">'.e($m[1]).'</a>'); }, $s);
  // 4) escapar el resto del texto
  $s=e($s);
  // 5) formato sobre texto ya escapado
  $s=preg_replace('/\*\*(.+?)\*\*/us','<b>$1</b>',$s);
  $s=preg_replace('/__(.+?)__/us','<u>$1</u>',$s);
  $s=preg_replace('/\*(?!\*)([^*]+?)\*(?!\*)/us','<i>$1</i>',$s);
  $s=preg_replace('/~~(.+?)~~/us','<s>$1</s>',$s);   // tachado
  // 6) menciones
  $s=preg_replace_callback('/@([\p{L}0-9_.\-]+)/u', function($m){ $map=$GLOBALS['MENTIONMAP']??[]; $uid=$GLOBALS['MENTIONUID']??[]; $key=mb_strtolower($m[1]); if(isset($map[$key])) return '<span class="mention" data-uid="'.(int)($uid[$key]??0).'">@'.e($map[$key]).'</span>'; return '<span class="mention plain">@'.e($m[1]).'</span>'; }, $s);
  // 7) restaurar código/enlaces
  foreach($store as $i=>$html){ $s=str_replace("\x01".$i."\x02",$html,$s); }
  return $s;
}
function fmt_comment($s){ return rt_format($s); }
/* Tabla a partir de filas markdown «| a | b |». La 1ª fila es cabecera. */
function rt_table_html($rows, callable $inline){
  $cells=function($row){ $row=trim($row); $row=preg_replace('/^\||\|$/','',$row); return array_map('trim', explode('|',$row)); };
  $h='<div class="rt-tablewrap"><table class="rt-table">';
  if($rows){ $head=$cells($rows[0]); $h.='<thead><tr>'; foreach($head as $c) $h.='<th>'.$inline($c).'</th>'; $h.='</tr></thead>'; }
  $h.='<tbody>';
  for($r=1;$r<count($rows);$r++){ $cs=$cells($rows[$r]); $h.='<tr>'; foreach($cs as $c) $h.='<td>'.$inline($c).'</td>'; $h.='</tr>'; }
  return $h.'</tbody></table></div>';
}
/* Motor de bloques compartido: encabezados (#/##/###), listas (- / N. ), lista de
   control [[chk:0/1]], cita (>), código en bloque (```), divisor (---) y tablas
   markdown; el resto son párrafos. El formato EN LÍNEA lo pone $inline (rt_format o
   desc_inline). Es backward-compatible: el texto antiguo (párrafos) se ve igual.
   $chkEditable=true pinta la lista de control con casillas de verdad (editor). */
function rt_blocks($text, callable $inline, $chk=false, $chkEditable=false){
  $lines=preg_split("/\r\n|\r|\n/", (string)$text); $n=count($lines); $i=0; $out='';
  while($i<$n){
    $raw=$lines[$i]; $t=rtrim($raw);
    if(preg_match('/^```/',$t)){ $i++; $code=[]; while($i<$n && !preg_match('/^```/',$lines[$i])){ $code[]=$lines[$i]; $i++; } if($i<$n)$i++;
      $out.='<pre class="rt-pre"><code>'.e(implode("\n",$code)).'</code></pre>'; continue; }
    if(preg_match('/^\s*\|.*\|\s*$/',$t) && $i+1<$n && preg_match('/^\s*\|[\s:|\-]+\|\s*$/',$lines[$i+1])){
      $rows=[$t]; $i+=2; while($i<$n && preg_match('/^\s*\|.*\|\s*$/',rtrim($lines[$i]))){ $rows[]=rtrim($lines[$i]); $i++; }
      $out.=rt_table_html($rows,$inline); continue; }
    if($chk && preg_match('/^\s*\[\[chk:([01])\]\]\s?(.*)$/u',$t,$m)){
      if($chkEditable) $out.='<div class="desc-chk"><input type="checkbox" '.($m[1]==='1'?'checked':'').' onchange="descAutosave()"><span class="dc-txt">'.$inline($m[2]).'</span></div>';
      else $out.='<div class="rt-chk'.($m[1]==='1'?' done':'').'"><span class="rt-cbox">'.($m[1]==='1'?'✓':'').'</span><span>'.$inline($m[2]).'</span></div>';
      $i++; continue; }
    if(preg_match('/^\s*---+\s*$/',$t)){ $out.='<hr class="rt-hr">'; $i++; continue; }
    if(preg_match('/^(#{1,3})\s+(.*)$/',$t,$m)){ $lvl=strlen($m[1]); $out.='<h'.$lvl.' class="rt-h'.$lvl.'">'.$inline($m[2]).'</h'.$lvl.'>'; $i++; continue; }
    if(preg_match('/^>\s?(.*)$/',$t)){ $items=[]; while($i<$n && preg_match('/^>\s?(.*)$/',rtrim($lines[$i]),$mm)){ $items[]=$inline($mm[1]); $i++; } $out.='<blockquote class="rt-quote">'.implode('<br>',$items).'</blockquote>'; continue; }
    if(preg_match('/^[-•]\s+(.*)$/',$t)){ $out.='<ul class="rt-ul">'; while($i<$n && preg_match('/^[-•]\s+(.*)$/',rtrim($lines[$i]),$mm)){ $out.='<li>'.$inline($mm[1]).'</li>'; $i++; } $out.='</ul>'; continue; }
    if(preg_match('/^\d+[.)]\s+(.*)$/',$t)){ $out.='<ol class="rt-ol">'; while($i<$n && preg_match('/^\d+[.)]\s+(.*)$/',rtrim($lines[$i]),$mm)){ $out.='<li>'.$inline($mm[1]).'</li>'; $i++; } $out.='</ol>'; continue; }
    if($t===''){ $out.='<div class="rt-p"><br></div>'; $i++; continue; }
    $out.='<div class="rt-p">'.$inline($raw).'</div>'; $i++;
  }
  return $out;
}
/* Renderiza el cuerpo del comentario intercalando texto e imágenes en los marcadores [[img]].
   Devuelve ['html'=>..., 'used'=>n imágenes consumidas]. */
function render_comment_body($cuerpo,$imgAtts){
    $parts=explode('[[img]]',(string)$cuerpo);
    $html=''; $n=0; $last=count($parts)-1;
    foreach($parts as $i=>$seg){
        $seg=trim($seg,"\n\r");
        if($seg!=='') $html.='<div class="txt">'.rt_blocks($seg,'fmt_comment').'</div>';
        if($i<$last && isset($imgAtts[$n])){ $html.='<div class="cimg">'.render_att($imgAtts[$n]).'</div>'; $n++; }
    }
    return ['html'=>$html,'used'=>$n];
}

/* ===== Descripción enriquecida =====
   Marcadores: **b** *i* __u__ · [[img:FN]] · [[file:FN|orig]] · línea [[chk:0/1]] texto.
   El nombre del archivo va embebido para poder recargar y volver a editar. */
function desc_fn_ok($fn){ return (bool)preg_match('/^[A-Za-z0-9_.\-]+$/',$fn); }
function desc_inline($s){
    $s=(string)$s;
    $parts=preg_split('/(\[\[img:[^\]]+\]\]|\[\[file:[^\]]+\]\])/u',$s,-1,PREG_SPLIT_DELIM_CAPTURE);
    $html='';
    foreach($parts as $p){
        if(preg_match('/^\[\[img:([^\]]+)\]\]$/u',$p,$m)){
            if(!desc_fn_ok($m[1])) continue; $url='../archivo.php?d=tasks&f='.rawurlencode($m[1]);
            $html.='<img class="desc-img" data-fn="'.e($m[1]).'" src="'.e($url).'" alt="" onclick="lightbox(this.src)">';
        } elseif(preg_match('/^\[\[file:([^|\]]+)\|?([^\]]*)\]\]$/u',$p,$m)){
            if(!desc_fn_ok($m[1])) continue; $orig=$m[2]!==''?$m[2]:$m[1]; $url='../archivo.php?d=tasks&f='.rawurlencode($m[1]);
            $html.='<span class="desc-file" data-fn="'.e($m[1]).'" data-orig="'.e($orig).'" contenteditable="false"><a href="'.e($url).'" target="_blank">'.ic('link',13).' '.e($orig).'</a></span>';
        } else {
            $html.=rt_format($p);
        }
    }
    return $html;
}
function desc_render_editable($body){
    $body=(string)$body; if(trim($body)==='') return '';
    return rt_blocks($body, 'desc_inline', true, true);   // bloques + lista de control editable
}
/* Texto plano para el cliente: sin marcadores ni imágenes; checklist como viñetas. */
function desc_to_plain($body){
    $body=(string)$body; $out=[];
    foreach(preg_split("/\r\n|\r|\n/",$body) as $ln){
        if(preg_match('/^\s*```/',$ln)) continue;                                   // valla de código: fuera
        if(preg_match('/^\s*\|[\s:|\-]+\|\s*$/',$ln)) continue;                       // fila separadora de tabla
        if(preg_match('/^\s*\[\[chk:[01]\]\]\s?(.*)$/u',$ln,$m)) $ln='• '.$m[1];
        $ln=preg_replace('/^(#{1,3})\s+/','',$ln);                                    // encabezados
        $ln=preg_replace('/^[-•]\s+/u','• ',$ln);                                     // viñetas
        $ln=preg_replace('/^\d+[.)]\s+/','• ',$ln);                                   // numeradas
        $ln=preg_replace('/^>\s?/','',$ln);                                          // cita
        if(preg_match('/^\s*---+\s*$/',$ln)) $ln='—';                                 // divisor
        if(preg_match('/^\s*\|.*\|\s*$/',$ln)) $ln=trim(str_replace('|',' ',$ln));    // tabla → texto de celdas
        $ln=preg_replace('/\[\[img:[^\]]+\]\]/u','',$ln);
        $ln=preg_replace('/\[\[file:[^\]]+\]\]/u','',$ln);
        $ln=preg_replace('/\*\*(.+?)\*\*/us','$1',$ln);
        $ln=preg_replace('/__(.+?)__/us','$1',$ln);
        $ln=preg_replace('/~~(.+?)~~/us','$1',$ln);
        $ln=preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/us','$1',$ln);
        $out[]=$ln;
    }
    return trim(implode("\n",$out));
}
/* Fecha relativa mientras es reciente y absoluta cuando ya queda lejos:
   «justo ahora» → «hace 2 minutos» → «hace 1 hora» → «ayer a las 8:10 pm»
   → «27 de jul. a las 8:10 pm». */
function reltime($ts){ if(!$ts) return '';
  $d=strtotime($ts); $diff=time()-$d; if($diff<0)$diff=0;
  if($diff<60) return 'justo ahora';
  if($diff<3600){ $m=(int)floor($diff/60); return 'hace '.$m.($m===1?' minuto':' minutos'); }
  $hoy=date('Y-m-d'); $dia=date('Y-m-d',$d);
  if($dia===$hoy){ $h=(int)floor($diff/3600); return 'hace '.$h.($h===1?' hora':' horas'); }
  if($dia===date('Y-m-d',strtotime('yesterday'))) return 'ayer a las '.date('g:i a',$d);
  $meses=[1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
  return (int)date('j',$d).' de '.$meses[(int)date('n',$d)].'. a las '.date('g:i a',$d); }
function is_img($fn){ return in_array(strtolower(pathinfo($fn,PATHINFO_EXTENSION)),['jpg','jpeg','png','gif','webp','avif','bmp'],true); }
/* Los adjuntos se sirven por archivo.php (pide sesión y fuerza descarga de lo
   que el navegador podría ejecutar), nunca por la URL directa de /uploads. */
function render_att($at){ $url='../archivo.php?d=tasks&f='.rawurlencode($at['filename']); if(is_img($at['filename'])){ return '<a href="'.e($url).'" class="att-img" onclick="return lightbox(this.href)"><img src="'.e($url).'" alt=""></a>'; }
    return '<a href="'.e($url).'" target="_blank" class="att-file"><span class="afi">'.ic('link',14).'</span><span class="afn">'.e($at['orig_name']?:$at['filename']).'</span></a>'; }
$backHref = 'workspace.php?'.($ret ?: 'view=cliente&cli='.$cli.'&list='.$t['list_id']);

/* Dos cifras distintas: el total de la tarea (todo el equipo) y la línea que
   gestiona este campo. El campo solo edita la suya; el resto se respeta. */
function h_val($min){ return $min ? str_replace('.',',', rtrim(rtrim(number_format($min/60,2,'.',''),'0'),'.')) : ''; }
$teMin=0; $teMinMio=0; $teWho=$meId; $teReparto=[];
try{
  $st=db()->prepare('SELECT COALESCE(SUM(minutos),0) FROM time_entries WHERE task_id=?'); $st->execute([$id]); $teMin=(int)$st->fetchColumn();
  $st=db()->prepare('SELECT COALESCE(SUM(minutos),0) FROM time_entries WHERE task_id=? AND admin_id=? AND concepto=?');
  $st->execute([$id,$teWho,TIME_CONCEPTO_TAREA]); $teMinMio=(int)$st->fetchColumn();
  /* Quién ha puesto cuánto. Con varias personas asignadas es LA pregunta: sin
     esto, el total de la tarea no dice nada sobre quién ha trabajado. */
  $st=db()->prepare('SELECT te.admin_id, a.username, SUM(te.minutos) min FROM time_entries te
                     LEFT JOIN admins a ON a.id=te.admin_id
                     WHERE te.task_id=? GROUP BY te.admin_id, a.username ORDER BY min DESC');
  $st->execute([$id]);
  foreach($st as $r) if((int)$r['min']>0) $teReparto[]=['id'=>(int)$r['admin_id'],'quien'=>(string)($r['username']?:'—'),'min'=>(int)$r['min']];
}catch(Exception $e){}
$teMinMap=[]; foreach($teReparto as $r) $teMinMap[(string)$r['id']]=(int)$r['min'];   // {adminId: minutos} para el selector
$teHorasVal = h_val($teMin);
$teHorasMio = h_val($teMinMio);
$teOtros    = max(0, $teMin - $teMinMio);   // lo que han puesto los demás (y lo registrado en Finanzas)
erp_head('kanban', $t['titulo'], 'side-collapse'); // en la vista de tarea el menú va recogido; se despliega al acercar el cursor
?>
<style>
.erp-wrap{max-width:none}
/* .tk-crumb está en erp_nav.php: la Bóveda de credenciales usa la misma miga. */
.tk{display:block}
.tk-left{min-width:0;padding-right:580px}
@media(max-width:940px){.tk-left{padding-right:0}}
.tk-title{width:100%;border:none;font-size:27px;font-weight:600;letter-spacing:-.5px;padding:0 0 16px;outline:none;color:var(--ink-strong);background:transparent}
/* minmax(0,1fr): sin esto, las columnas no bajan de su contenido y a ~667px la ficha
   se salía ~57px por la derecha (scroll horizontal). */
.tk-fields{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:4px 44px;border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:18px 0;margin-bottom:26px}
.tkf{display:flex;align-items:center;gap:12px;padding:10px 0;min-width:0}
.tkf .lbl{width:120px;flex:none;color:var(--muted);font-size:12.5px;display:flex;align-items:center;gap:9px;font-weight:500}
.tkf .lbl svg{width:15px;height:15px;color:var(--label)}
.tkf .val{flex:1;min-width:0;display:flex;align-items:center;gap:8px}
/* min-width:0 en los campos: como son flex-items, sin esto no encogen por debajo de su
   ancho intrínseco (~170px) y fuerzan el desborde. max-width:100% los mantiene dentro. */
.tkf select,.tkf input{border:1px solid transparent;background:transparent;border-radius:8px;padding:6px 9px;font-size:13.5px;width:auto;min-width:0;max-width:100%;font-family:inherit;color:var(--ink);cursor:pointer}
.tkf select:hover,.tkf input:hover{background:#f4f5f7}
.tkf select:focus,.tkf input:focus{background:#fff;border-color:var(--label);box-shadow:0 0 0 3px rgba(17,19,24,.07);outline:none;cursor:text}
.est-sel{background:transparent!important;border-radius:7px!important;font-weight:700!important;font-size:12px!important;letter-spacing:.2px;padding:6px 6px!important;color:var(--ink)!important}
.est-pick,.prio-pick{display:inline-flex;align-items:center;gap:8px;cursor:pointer;border:none;background:transparent;font-family:inherit;font-size:13px;padding:5px 8px;border-radius:8px;color:var(--ink);transition:background .12s}
.est-pick:hover,.prio-pick:hover{background:#f4f5f7}
.est-pick svg,.prio-pick svg{color:var(--label);flex:none}
.ep-dot{width:11px;height:11px;border-radius:50%;flex:none;display:inline-block}
.ep-lbl{font-weight:700;font-size:12px;letter-spacing:.2px}
/* Estado como pastilla RELLENA del color del estado, con texto blanco. «En espera»
   (pendiente) no lleva relleno de color: queda en gris neutro. */
.est-badge{padding:4px 12px;border-radius:99px;border:1px solid transparent;gap:6px}
.est-badge .ep-lbl{color:inherit}
.est-badge svg{color:inherit;opacity:.8}
.est-badge:hover{filter:brightness(.96)}
.ws-pop .wsp-item .ep-dot{width:12px;height:12px}
.tkf select.plain{-webkit-appearance:none!important;-moz-appearance:none!important;appearance:none!important;background:transparent!important;background-image:none!important;border:none!important;box-shadow:none!important;border-radius:8px!important;padding:6px 6px!important;width:auto!important;min-width:0!important;max-width:none!important;font-family:inherit;color:var(--ink);cursor:pointer}
.tkf select.plain:hover{background:#f4f5f7!important}
.tkf select.plain:focus{background:transparent!important;border:none!important;box-shadow:none!important}
.val-dates{display:flex;align-items:center;gap:6px}
.val-dates .dwrap{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:9px;cursor:pointer}
.val-dates .dwrap:hover{background:#f4f5f7}
.val-dates .dwrap svg{width:14px;height:14px;color:var(--label);flex:none}
/* Fecha límite: se colorea según urgencia (ámbar cerca, rojo vencida). */
.due-wrap.due-soon,.due-wrap.due-soon .dpick{color:#e0a000;font-weight:600}
.due-wrap.due-soon svg{color:#e0a000}
.due-wrap.due-late,.due-wrap.due-late .dpick{color:#e5484d;font-weight:600}
.due-wrap.due-late svg{color:#e5484d}
.val-dates .dpick{field-sizing:content;width:auto!important;min-width:46px;max-width:120px;padding:0!important;background:transparent!important;border:none!important;cursor:pointer;caret-color:transparent;user-select:none}
.val-dates .dpick:hover{background:transparent!important}
/* Sin recuadro/anillo de foco al clicar la fecha: queda mal. */
.val-dates .dpick:focus,.val-dates .dpick:focus-visible{outline:none!important;box-shadow:none!important;border:none!important;background:transparent!important}
.val-dates .arw{color:#c4c8ce;display:flex;flex:none}
.tm-field{position:relative;display:inline-block}
.tm-trigger{border:none;background:transparent;font-family:inherit;font-size:13.5px;color:var(--ink);cursor:pointer;padding:6px 9px;border-radius:8px;font-weight:600}
.tm-trigger .mut{color:var(--muted);font-weight:500}
.tm-trigger:hover{background:#f4f5f7}
/* Solo lectura: se ve el tiempo total pero el campo no parece pulsable. */
.tm-trigger.tm-ro{cursor:default}
.tm-trigger.tm-ro:hover{background:transparent}
.tm-pop{display:none;position:absolute;top:calc(100% + 6px);left:0;z-index:30;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 12px 30px -10px rgba(16,19,24,.28);padding:12px;width:236px}
.tm-pop.on{display:block}
.tm-pop-l{font-size:11.5px;color:var(--muted);font-weight:600;margin-bottom:8px}
.tm-pop-n{font-size:11px;color:var(--muted);margin-top:8px;line-height:1.4}
.tm-pop-row{display:flex;align-items:center;gap:7px}
.tm-who-row{margin-bottom:8px;position:relative}
.tm-who{display:flex;align-items:center;gap:8px;width:100%;box-sizing:border-box;border:1px solid var(--line);background:var(--soft);border-radius:9px;padding:6px 9px;font:inherit;font-size:13px;color:var(--ink);cursor:pointer;text-align:left;transition:background .12s,border-color .12s}
.tm-who:hover{background:#fff;border-color:#c9ccd1}
.tm-who-av,.tm-who-pop .a{width:22px;height:22px;flex:none;border-radius:50%;color:#fff;font-weight:700;font-size:10px;display:flex;align-items:center;justify-content:center}
.tm-who-nm{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}
.tm-who-arw{color:var(--label);flex:none}
.tm-who-pop{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:40;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 14px 34px rgba(16,19,24,.16);padding:5px;max-height:220px;overflow:auto}
.tm-who-pop.on{display:block}
.tm-who-pop button{display:flex;align-items:center;gap:9px;width:100%;border:none;background:none;text-align:left;font:inherit;font-size:13px;color:var(--ink);padding:7px 9px;border-radius:8px;cursor:pointer}
.tm-who-pop button:hover,.tm-who-pop button.sel{background:var(--accent-soft)}
.tm-who-pop button>span:nth-child(2){flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tm-who-pop .h{font-size:11.5px;color:var(--muted);font-weight:600}
.tm-pop-row input{flex:1;min-width:0;border:1px solid var(--line);border-radius:9px;padding:8px 10px;font-size:13.5px;font-family:inherit;color:var(--ink)}
.tm-pop-row input:focus{outline:none;border-color:var(--ink-strong)}
.tm-pop-row .u{color:var(--muted);font-size:12.5px}
.tm-pop-row button{border:none;background:var(--ink-strong);color:#fff;border-radius:9px;padding:8px 12px;font-size:12.5px;font-weight:600;cursor:pointer}
/* Reparto por persona dentro del popup de tiempo. */
.tm-pop{width:262px}
.tm-rep{margin-top:11px;border-top:1px solid var(--line2);padding-top:9px}
.tm-r{display:flex;align-items:center;gap:8px;padding:4px 0;font-size:12.5px;color:var(--ink)}
.tm-r .av{width:20px;height:20px;border-radius:50%;color:#fff;font-size:9.5px;font-weight:700;display:flex;align-items:center;justify-content:center;flex:none}
.tm-r .n{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tm-r b{font-weight:600;color:var(--ink-strong);font-size:12.5px}
.tm-r.yo .n{font-weight:600;color:var(--ink-strong)}
.tm-tot{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:7px;padding-top:7px;
  border-top:1px solid var(--line2);font-size:11.5px;color:var(--muted)}
.tm-tot b{font-size:12.5px;color:var(--ink-strong);font-weight:700}
.mini-av{width:24px;height:24px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;flex:none}
.tk-sec{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);font-weight:650;margin:30px 0 13px;display:flex;align-items:center;gap:10px}
.tk-sec .sp{flex:1}
.tk-desc textarea{width:100%;border:1px solid var(--line);border-radius:12px;padding:16px 18px;font-size:14px;min-height:120px;font-family:inherit;line-height:1.6;outline:none;resize:vertical;transition:background-color .14s ease,border-color .14s ease,box-shadow .14s ease}
.tk-desc textarea:hover{background:#fafafb;border-color:#e2e2e5}
.tk-desc textarea:focus{background:#fff;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
/* Editor de descripción tipo lienzo (mismas capacidades que los comentarios). */
.rt-wrap{border:none;border-bottom:1px solid var(--line);border-radius:0;background:transparent;transition:border-color .14s ease}
.rt-wrap:focus-within{border-bottom-color:var(--accent)}
.rt-editor{min-height:110px;max-height:640px;overflow:auto;padding:14px 2px;font-size:14px;line-height:1.6;outline:none;color:var(--ink);word-break:break-word}
.rt-editor:empty:before{content:attr(data-ph);color:var(--label);pointer-events:none}
.rt-editor b,.rt-editor strong{font-weight:700}.rt-editor u{text-decoration:underline}.rt-editor i,.rt-editor em{font-style:italic}
.rt-editor img.desc-img,.rt-view img.desc-img{min-width:180px;min-height:110px;max-width:min(100%,440px);max-height:360px;object-fit:contain;border-radius:10px;border:1px solid var(--line);display:block;margin:8px 0;cursor:zoom-in}
.desc-file{display:inline-flex;align-items:center;gap:5px;background:var(--soft);border:1px solid var(--line);border-radius:8px;padding:3px 9px;margin:2px 4px 2px 0;font-size:12.5px;vertical-align:middle}
.desc-file a{color:var(--ink);text-decoration:none;display:inline-flex;align-items:center;gap:5px}
.desc-file a svg{color:var(--label)}
.desc-chk{display:flex;align-items:flex-start;gap:9px;margin:4px 0}
.desc-chk input{margin-top:3px;width:15px;height:15px;accent-color:var(--accent);cursor:pointer;flex:none}
.desc-chk .dc-txt{flex:1;outline:none;min-height:1.2em}
.rt-bar{padding:4px 0 8px;display:flex;align-items:center;gap:2px}
.rt-view{font-size:14px;line-height:1.6;color:var(--ink);word-break:break-word}
.rt-view .desc-chk input{pointer-events:none}
.savebar{display:flex;gap:12px;margin-top:20px;align-items:center}
.saved-note{font-size:12px;color:var(--ok);font-weight:600;opacity:0;transition:opacity .2s ease}
.saved-note.show{opacity:1}
/* checklist */
.chk-prog{font-size:11px;color:var(--muted);font-weight:700;background:var(--soft);border-radius:99px;padding:2px 9px}
.chk-item{display:flex;align-items:center;gap:12px;padding:12px 4px;border-bottom:1px solid var(--line2)}
.chk-item:hover{background:#fafbfc}
.chk-item input[type=checkbox]{width:17px;height:17px;flex:none;cursor:pointer;accent-color:var(--accent)}
.chk-item{transition:background .25s ease}
.chk-item .ctx{flex:1;min-width:0;font-size:13.5px;color:var(--ink);transition:color .25s ease}
.chk-item .ctx .ct{position:relative;display:inline}
.chk-item .ctx .ct::after{content:'';position:absolute;left:0;right:0;top:54%;height:1.5px;background:#a9aeb6;transform:scaleX(0);transform-origin:left;transition:transform .28s ease}
.chk-item.done .ctx{color:var(--label)}
.chk-item.done .ctx .ct::after{transform:scaleX(1)}
@keyframes chkFlash{0%{background:#eafaf0}100%{background:transparent}}
.chk-item.just-done{animation:chkFlash .6s ease}
.chk-item .cres{border:1px solid transparent;background:transparent;border-radius:7px;font-size:11.5px;color:var(--muted);padding:4px 6px;cursor:pointer;max-width:120px}
.chk-item .cres:hover{background:#eef0f3}
.chk-item .cav,.chk-add .cav{width:22px;height:22px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:600;flex:none}
.chk-item .cdel{border:none;background:none;color:#c9ccd1;cursor:pointer;padding:4px;border-radius:6px}.chk-item .cdel:hover{background:#fde8e8;color:#c0392b}
.chk-add{display:flex;align-items:center;gap:9px;padding:10px 4px}
.chk-add .plus{color:var(--label);display:flex}
.chk-add input[name=texto]{flex:1;border:none;outline:none;font-size:13.5px;font-family:inherit;background:transparent}
.chk-add input[name=texto]:focus{box-shadow:none!important;border:none}
.chk-add select{border:1px solid var(--line);border-radius:8px;padding:6px 8px;font-size:12px;background:#fff}
.chk-add button[type=submit]{border:none;background:var(--accent);color:#fff;border-radius:8px;padding:6px 13px;font-size:12.5px;font-weight:600;cursor:pointer}
.chk-asg{border:none;background:none;cursor:pointer;padding:2px;border-radius:99px;display:inline-flex;flex:none}
.chk-asg:hover{background:var(--soft)}
.asg-add.sm{width:22px;height:22px;border-radius:50%;border:1.5px dashed #c4c8ce;color:var(--label);display:inline-flex;align-items:center;justify-content:center;font-size:12px}
/* adjuntos */
.att-grid{display:flex;flex-wrap:wrap;gap:10px}
.att-img{display:block;width:92px;height:92px;border-radius:10px;overflow:hidden;border:1px solid var(--line);position:relative}
.att-img img{width:100%;height:100%;object-fit:cover;display:block}
.att-file{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:10px;padding:9px 12px;font-size:12.5px;color:var(--ink);background:#fff}
.att-file:hover{background:var(--soft)}.att-file .afi{color:var(--muted);display:flex}.att-file .afn{max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.att-w{position:relative}
.att-x{position:absolute;top:-7px;right:-7px;width:20px;height:20px;border-radius:50%;background:#fff;border:1px solid var(--line);color:var(--label);font-size:12px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,.1)}
.att-x:hover{color:#c0392b}
.upl{border:1px dashed #d4d8de;border-radius:10px;padding:12px 14px;text-align:center;color:var(--muted);font-size:12.5px;cursor:pointer;transition:.15s}
.upl:hover{background:var(--soft);border-color:#c4c8ce;color:var(--ink)}
/* actividad / comentarios en gris */
.act{position:fixed;top:56px;right:0;bottom:0;width:540px;background:#f6f7f8;border-left:1px solid var(--line);padding:22px 28px;display:flex;flex-direction:column;z-index:6;animation:actReveal .2s ease .32s both}
/* El panel de actividad hace un pequeño reajuste al cargar; lo mostramos con un fundido
   justo después de que se asiente, para que no se vea el «salto» de la barra. */
@keyframes actReveal{from{opacity:0}to{opacity:1}}
@media(max-width:940px){.act{position:static;width:auto;height:auto;padding:20px 0 0;border-left:none;border-top:1px solid var(--line);margin-top:20px}}
.act h3{font-size:15px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.act h3 .n{font-size:11px;background:#e7e8ea;color:#5c616b;border-radius:99px;padding:1px 8px;font-weight:650}
.act .feed{flex:1;overflow:auto}
.cm{margin-bottom:18px;position:relative}
.cm-tools{position:absolute;top:2px;right:2px;display:flex;gap:2px;background:#fff;border:1px solid var(--line);border-radius:9px;padding:2px;box-shadow:0 3px 10px rgba(0,0,0,.08);opacity:0;transform:translateY(-2px);pointer-events:none;transition:opacity .12s ease,transform .12s ease;z-index:5}
.cm:hover .cm-tools{opacity:1;transform:none;pointer-events:auto}
.cm-tool{border:none;background:none;color:var(--label);cursor:pointer;width:26px;height:26px;border-radius:7px;display:flex;align-items:center;justify-content:center}
.cm-tool:hover{background:var(--soft);color:var(--ink)}
.cm .av{width:22px;height:22px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-size:9.5px;font-weight:600;flex:none}
.cm .bd{min-width:0}
.cm .bd .top{display:flex;align-items:center;gap:8px;font-size:12.5px}.cm .bd .top b{font-weight:600}.cm .bd .top span{color:var(--muted);font-size:11.5px}
.cm .bubble{background:#fff;border:1px solid var(--line);border-radius:12px;padding:15px 18px;transition:border-color .14s ease,background-color .14s ease,box-shadow .14s ease}
.cm:hover .bubble{border-color:#d9dade;background:#fcfcfd;box-shadow:0 2px 10px -6px rgba(16,19,24,.18)}
.cm .bubble .top{margin-bottom:13px}
.cm .txt{font-size:13.5px;color:#3a3f47;line-height:1.6;white-space:pre-wrap;word-wrap:break-word}
.cm .catt{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.cm .del{font-size:11px;color:var(--label);cursor:pointer;background:none;border:none;padding:0;margin-top:5px}
.cm .del:hover{color:#c0392b}
.act .sys{font-size:12px;color:var(--muted);padding:4px 0 12px;display:flex;gap:8px;align-items:center}
.act .sys .dot{width:6px;height:6px;border-radius:50%;background:#c8ccd2;flex:none}
.cbox{margin-top:12px;border:1px solid var(--line);border-radius:12px;padding:10px 12px;background:#fff;transition:border-color .14s ease,box-shadow .14s ease,background-color .14s ease}
.cbox:hover{border-color:#d9dade;background:#fcfcfd}
.cbox:focus-within{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px var(--accent-soft)}
.cbox textarea{width:100%;border:none;outline:none;font-size:13.5px;font-family:inherit;resize:vertical;min-height:40px;background:transparent}
.cbox .cm-editor{width:100%;border:none;outline:none;font-size:13.5px;font-family:inherit;min-height:40px;max-height:340px;overflow:auto;background:transparent;line-height:1.6;color:var(--ink);word-break:break-word;white-space:pre-wrap}
.cbox .cm-editor:empty:before{content:attr(data-ph);color:var(--label);pointer-events:none}
.cm-ii{display:block;position:relative;margin:6px 0;max-width:280px;width:max-content}
.cm-ii img{min-width:180px;min-height:110px;max-width:100%;object-fit:contain;border-radius:11px;display:block;border:1px solid var(--line)}
.cm-ii-rm{position:absolute;top:-8px;right:-8px;width:22px;height:22px;border-radius:50%;background:#fff;border:1px solid var(--line);color:var(--label);cursor:pointer;font-size:11px;line-height:1;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,.14)}
.cm-ii-rm:hover{background:#fde8e8;color:#c0392b;border-color:#f2c4c4}
.cm-other{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
.cm-other:empty{display:none}
.cm-otherchip{font-size:11.5px;color:var(--muted);background:var(--soft);border:1px solid var(--line);border-radius:8px;padding:4px 8px;display:inline-flex;align-items:center;gap:4px}
.cm-otherchip button{border:none;background:none;color:var(--label);cursor:pointer;font-size:11px}
.cm-otherchip button:hover{color:#c0392b}
.cbar{display:flex;align-items:center;gap:2px;margin-top:6px}
.cbar .cbar-sep{width:1px;height:18px;background:var(--line);margin:0 5px}
.cbox .cm-editor b,.cbox .cm-editor strong{font-weight:700}.cbox .cm-editor u{text-decoration:underline}
.act .txt b,.act .txt strong{font-weight:700}.act .txt u{text-decoration:underline}
.cbar .tool{border:none;background:none;color:var(--label);cursor:pointer;padding:6px;border-radius:7px;display:inline-flex}
.cbar .tool:hover{background:var(--soft);color:var(--ink)}.cbar .tool svg{width:16px;height:16px}
.cbar .sp{flex:1}
.cbar .send{background:var(--accent);color:#fff;border-radius:8px;padding:7px 14px;font-size:12.5px;font-weight:600;border:none;cursor:pointer}
.cfileinfo{font-size:11px;color:var(--muted);margin-top:4px}
.pop{position:absolute;bottom:46px;left:8px;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 16px 40px rgba(0,0,0,.16);padding:8px;z-index:70;display:none}
.pop.on{display:block}
.emoji-pop{display:none;grid-template-columns:repeat(8,1fr);gap:2px;max-width:280px}
.emoji-pop.on{display:grid}
.emoji-pop button{border:none;background:none;font-size:18px;cursor:pointer;padding:5px;border-radius:7px}
.emoji-pop button:hover{background:var(--soft)}
.mention-pop{min-width:170px;max-height:220px;overflow:auto}
.mention-pop button{display:flex;align-items:center;gap:8px;width:100%;border:none;background:none;cursor:pointer;padding:7px 9px;border-radius:8px;font-size:13px;text-align:left;font-family:inherit}
.mention-pop button:hover{background:var(--soft)}
.mention-pop button.sel{background:var(--accent-soft)}
.mention-pop button.sel .ma{box-shadow:0 0 0 2px var(--accent-soft),0 0 0 3.5px var(--accent)}
.mention-pop .ma{width:22px;height:22px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:600;flex:none}
.cbox-wrap{position:relative}
/* asignado picker */
.asg-pick{display:inline-flex;align-items:center;gap:8px;cursor:pointer;padding:5px 8px;border-radius:8px;transition:background .12s}
.asg-pick:hover{background:#f4f5f7}
/* Solo lectura: los asignados se ven, pero el campo no parece pulsable (sin cursor ni hover). */
.asg-pick.ro{cursor:default}
.asg-pick.ro:hover{background:transparent}
.asg-pick .asg-nm{font-size:13.5px;color:var(--ink)}.asg-pick .asg-nm.mut{color:var(--muted)}
.asg-pick .asg-add{width:24px;height:24px;border-radius:50%;border:1.5px dashed #c4c8ce;color:var(--label);display:inline-flex;align-items:center;justify-content:center;font-size:13px}
.asg-pick .mini-av{border:2px solid #fff}.asg-pick .mini-av+.mini-av{margin-left:-9px}
.ws-pop .wsp-ck{color:var(--accent);font-weight:700;width:16px;text-align:center;flex:none;font-size:13px}
.mini-av{width:24px;height:24px;border-radius:50%;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;flex:none}
/* popover reutilizable */
.ws-pop{position:fixed;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 46px rgba(0,0,0,.17);padding:5px;min-width:210px;max-height:300px;overflow:auto;z-index:200;display:none}
.ws-pop.on{display:block}
.ws-pop .wsp-item{display:flex;align-items:center;gap:10px;width:100%;border:none;background:none;cursor:pointer;padding:8px 10px;border-radius:8px;font-size:13px;color:#4c515b;font-weight:500;text-align:left;font-family:inherit}
.ws-pop .wsp-item:hover{background:var(--soft);color:var(--ink)}
.ws-pop .wsp-av{width:22px;height:22px;border-radius:50%;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:600;flex:none}
.ws-pop .wsp-mut{color:var(--muted)}
.ws-pop .rpk{border:none;background:none;font-size:20px;cursor:pointer;padding:5px;border-radius:8px}
.ws-pop .rpk:hover{background:var(--soft)}
#reactPop{min-width:0;padding:6px}#reactPop.on{display:flex;flex-wrap:wrap;max-width:236px}
/* reacciones */
.cm-react{display:flex;align-items:center;gap:6px;margin-top:7px;flex-wrap:wrap}
.rchip{border:1px solid var(--line);background:#fff;border-radius:99px;padding:2px 9px;font-size:12.5px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-family:inherit}
.rchip span{color:var(--muted);font-weight:600;font-size:11px}
.rchip:hover{background:var(--soft)}
.rchip.mine{background:var(--accent-soft);border-color:#d8d4fb}.rchip.mine span{color:var(--accent)}
/* Pie del comentario: pulgar (icono gris → emoji al reaccionar) + Responder, con línea separadora. */
.cm-foot{display:flex;align-items:center;gap:8px;margin-top:13px;padding-top:11px;border-top:1px solid var(--line2)}
.cm-foot-sp{flex:1}
.cm-thumb{border:none;background:none;cursor:pointer;display:inline-flex;align-items:center;gap:5px;color:var(--label);font-size:12.5px;font-weight:600;padding:4px 8px;border-radius:8px;font-family:inherit;line-height:1}
.cm-thumb svg{width:16px;height:16px}
.cm-thumb:hover{background:var(--soft);color:#6b7079}
.cm-thumb.has{color:#5a5f68}.cm-thumb.has span{color:var(--muted);font-weight:700}
.cm-thumb.mine{color:var(--accent)}.cm-thumb.mine span{color:var(--accent)}
.cm-reply{border:none;background:none;cursor:pointer;color:#6b7079;font-size:12.5px;font-weight:600;padding:4px 9px;border-radius:8px;font-family:inherit;display:inline-flex;align-items:center;gap:6px;line-height:1}
.cm-reply svg{color:var(--label)}
.cm-reply:hover{background:var(--soft);color:var(--ink)}.cm-reply:hover svg{color:var(--ink)}
/* Pop de reacción (tipo Slack/Linear) — anima también el contador. */
@keyframes rpop{0%{transform:scale(.72)}55%{transform:scale(1.24)}100%{transform:scale(1)}}
.rpop{animation:rpop .34s cubic-bezier(.2,1.5,.35,1)}
/* Cita de respuesta dentro del comentario (WhatsApp). */
.cm-quote{display:flex;flex-direction:column;gap:1px;border-left:3px solid var(--accent);background:var(--soft);border-radius:0 8px 8px 0;padding:6px 11px;margin:2px 0 9px;cursor:pointer;max-width:100%;transition:background .12s}
.cm-quote:hover{background:#ececed}
.cm-quote .qa{font-size:12px;font-weight:700;color:var(--accent)}
.cm-quote .qt{font-size:12.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* Barra de «respondiendo a…» en el compositor. */
.cm-replybar{display:flex;align-items:center;gap:10px;background:var(--soft);border-left:3px solid var(--accent);border-radius:0 8px 8px 0;padding:7px 10px;margin-bottom:9px}
.cm-replybar .rb-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:1px}
.cm-replybar .rb-a{font-size:12px;font-weight:700;color:var(--accent)}
.cm-replybar .rb-t{font-size:12.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cm-replybar .rb-x{border:none;background:none;color:var(--label);cursor:pointer;font-size:13px;padding:4px 6px;border-radius:7px;flex:none;line-height:1}
.cm-replybar .rb-x:hover{background:#e6e7ea;color:var(--ink)}
/* Resaltado del comentario original al pulsar la cita. */
@keyframes cmflash{0%,100%{background:#fff}25%{background:#fff6db}}
.cm-flash .bubble{animation:cmflash 1.5s ease}
/* Resaltado AZUL al llegar desde una notificación: se posa como un hover (fondo
   azul suave + borde), lo MANTIENE un rato para que veas dónde está, y se retira
   con suavidad. No parpadea: entra, se queda y se va. */
.cm-hl .bubble{background:#e9f2ff!important;box-shadow:0 0 0 2px #bcd4ff;
  transition:background .5s ease,box-shadow .5s ease}
.cm-hl.cm-hl-out .bubble{background:#fff!important;box-shadow:none}
.raddbtn{border:1px solid var(--line);background:#fff;border-radius:99px;padding:3px 6px;cursor:pointer;color:var(--muted);display:inline-flex;align-items:center;line-height:1;opacity:0;transition:opacity .12s ease,background .12s ease}
.raddbtn svg{width:15px;height:15px}
.cm:hover .raddbtn{opacity:1}
.raddbtn:hover{background:var(--soft);color:var(--ink)}
/* Mención en morado-azul (estilo Teams): quien has etiquetado se ve resaltado. */
.mention{color:#5b5fc7;font-weight:600;background:rgba(91,95,199,.10);border-radius:6px;padding:1px 5px;white-space:nowrap}
.mention.plain{color:#5b5fc7;background:rgba(91,95,199,.10)}
/* Código inline (comandos, #hex…) y enlaces en azul — en render y en los editores. */
.rt-code,.rt-editor code,.cm-editor code,.cm .txt code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;font-size:.86em;background:#f3f3f5;border:1px solid #e6e7ea;border-radius:6px;padding:1px 6px;color:#c0343a;white-space:nowrap}
.rt-link,.rt-editor a,.cm-editor a,.cm .txt a{color:#0071e3;text-decoration:none;font-weight:500;cursor:pointer}
/* ===== Bloques enriquecidos (encabezados, listas, cita, código, tabla, divisor) ===== */
.rt-editor h1,.cm-editor h1,.rt-view .rt-h1,.cm .txt .rt-h1{font-size:1.5em;font-weight:750;line-height:1.25;margin:.5em 0 .2em}
.rt-editor h2,.cm-editor h2,.rt-view .rt-h2,.cm .txt .rt-h2{font-size:1.28em;font-weight:700;line-height:1.3;margin:.5em 0 .2em}
.rt-editor h3,.cm-editor h3,.rt-view .rt-h3,.cm .txt .rt-h3{font-size:1.1em;font-weight:700;margin:.4em 0 .15em}
.rt-p{margin:0}
.rt-editor ul,.rt-editor ol,.cm-editor ul,.cm-editor ol,.rt-view .rt-ul,.rt-view .rt-ol,.cm .txt .rt-ul,.cm .txt .rt-ol{margin:.25em 0;padding-left:1.5em}
.rt-editor li,.cm-editor li,.rt-view li,.cm .txt li{margin:2px 0}
.rt-editor blockquote,.cm-editor blockquote,.rt-view .rt-quote,.cm .txt .rt-quote{border-left:3px solid #d7dae0;margin:.45em 0;padding:2px 0 2px 13px;color:#5c616b}
.rt-editor pre,.cm-editor pre,.rt-view .rt-pre,.cm .txt .rt-pre{background:#f6f7f9;border:1px solid var(--line);border-radius:8px;padding:10px 12px;margin:.45em 0;overflow:auto;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.86em;white-space:pre;color:var(--ink)}
.rt-editor pre code,.cm-editor pre code,.rt-view .rt-pre code,.cm .txt .rt-pre code{background:none;border:none;padding:0;color:inherit;white-space:pre}
.rt-editor hr,.cm-editor hr,.rt-view .rt-hr,.cm .txt .rt-hr{border:none;border-top:1px solid var(--line);margin:.7em 0}
.rt-tablewrap{overflow-x:auto;margin:.45em 0}
.rt-editor table,.cm-editor table,.rt-view .rt-table,.cm .txt .rt-table{border-collapse:collapse;font-size:.94em;min-width:200px}
.rt-editor th,.rt-editor td,.cm-editor th,.cm-editor td,.rt-view .rt-table th,.rt-view .rt-table td,.cm .txt .rt-table th,.cm .txt .rt-table td{border:1px solid var(--line);padding:5px 9px;text-align:left;vertical-align:top}
.rt-editor th,.cm-editor th,.rt-view .rt-table th,.cm .txt .rt-table th{background:var(--soft);font-weight:650}
.rt-chk{display:flex;gap:8px;align-items:flex-start;margin:2px 0}
.rt-chk .rt-cbox{width:16px;height:16px;flex:none;border:1.5px solid var(--line);border-radius:4px;font-size:11px;line-height:13px;text-align:center;color:var(--ok)}
.rt-chk.done{color:var(--muted);text-decoration:line-through}
.rt-chk.done .rt-cbox{background:#e7f7ee;border-color:#bfe6cf}
/* menú de bloques (el botón «+» de la barra) */
.rt-menu{position:fixed;z-index:2400;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 46px rgba(16,19,24,.18);padding:5px;min-width:212px;display:none;max-height:70vh;overflow:auto}
.rt-menu.on{display:block;animation:pop .14s ease}
.rt-menu button{display:flex;align-items:center;gap:11px;width:100%;border:none;background:none;text-align:left;font:inherit;font-size:13px;color:var(--ink);padding:8px 10px;border-radius:8px;cursor:pointer}
.rt-menu button:hover{background:var(--soft)}
.rt-menu .rt-mi{width:26px;height:22px;flex:none;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--muted);background:var(--soft);border-radius:6px}
.rt-menu-sep{height:1px;background:var(--line);margin:4px 6px}
.rt-more svg{display:block}
/* barra flotante de edición de tablas */
.rt-tctl{position:fixed;z-index:2500;display:none;gap:3px;background:#fff;border:1px solid var(--line);border-radius:9px;box-shadow:0 10px 30px rgba(16,19,24,.16);padding:3px}
.rt-tctl.on{display:flex}
.rt-tctl button{border:none;background:var(--soft);color:var(--ink);font:inherit;font-size:11px;font-weight:600;padding:4px 8px;border-radius:6px;cursor:pointer;white-space:nowrap}
.rt-tctl button:hover{background:var(--accent-soft)}
.rt-tctl button[data-a="colX"]:hover,.rt-tctl button[data-a="rowX"]:hover{background:#feecec;color:#c0343a}
.rt-editor table,.cm-editor table{cursor:text}
.rt-link:hover,.cm .txt a:hover{text-decoration:underline}
.mention .m-av{width:17px;height:17px;border-radius:50%;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:7.5px;font-weight:700;flex:none}
.cm-react:empty{display:none}
/* dropzone grande */
.upl{border:2px dashed #d4d8de;border-radius:14px;padding:26px 20px;text-align:center;color:var(--muted);cursor:pointer;transition:.15s;display:flex;flex-direction:column;align-items:center;gap:5px}
.upl:hover{background:var(--soft);border-color:var(--accent);color:var(--ink)}
.upl .upl-ic{color:var(--label)}.upl:hover .upl-ic{color:var(--accent)}
.upl b{font-size:13.5px;color:var(--ink)}.upl span{font-size:12px}
/* comment box drag */
.cbox.drag{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.cm-edit textarea{width:100%;border:1px solid var(--line);border-radius:10px;padding:8px 10px;font-size:13.5px;font-family:inherit;min-height:56px;outline:none}
.cm-edit textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
/* overlay a pantalla completa */
#dropOverlay{position:fixed;inset:0;background:rgba(123,104,238,.12);z-index:300;display:none;align-items:center;justify-content:center;pointer-events:none}
#dropOverlay.on{display:flex}
#dropOverlay .dz{background:#fff;border:2px dashed var(--accent);border-radius:20px;padding:40px 56px;text-align:center;color:var(--accent);display:flex;flex-direction:column;align-items:center;gap:8px;box-shadow:0 30px 80px rgba(0,0,0,.22)}
#dropOverlay .dz b{font-size:18px;color:var(--ink-strong)}#dropOverlay .dz span{color:var(--muted);font-size:13px}
/* lightbox de imagen */
#lightbox{position:fixed;inset:0;background:rgba(10,12,16,.62);-webkit-backdrop-filter:blur(7px);backdrop-filter:blur(7px);z-index:400;display:flex;align-items:center;justify-content:center;padding:30px;cursor:zoom-out;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .2s ease,visibility .2s ease}
#lightbox.on{opacity:1;visibility:visible;pointer-events:auto}
#lightbox img{max-width:92vw;max-height:92vh;border-radius:10px;box-shadow:0 24px 70px rgba(0,0,0,.55);transform:scale(.94);opacity:0;transition:transform .24s cubic-bezier(.2,.8,.3,1),opacity .2s ease}
#lightbox.on img{transform:scale(1);opacity:1}
/* preview de imagen en el comentario */
.cm-preview{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0}
.cm-preview .cmp-item img{width:60px;height:60px;object-fit:cover;border-radius:8px;border:1px solid var(--line);display:block}
.cm-preview .cmp-item{font-size:11.5px;color:var(--muted);background:var(--soft);border-radius:8px;padding:5px 9px}
.cm-preview .cmp-x{border:none;background:#fff;border:1px solid var(--line);color:var(--label);width:24px;height:24px;border-radius:8px;cursor:pointer;font-size:12px;align-self:center}
.cm-preview .cmp-x:hover{background:#fde8e8;color:#c0392b}
/* vista previa en vivo del comentario */
.cm-live{display:none;margin:8px 0 2px;animation:fadeUp .18s ease}
.cm-live.on{display:block}
.cm-live .cml-label{font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--label);margin:0 0 5px 2px;display:flex;align-items:center;gap:6px}
.cm-live .cml-label::before{content:"";width:14px;height:14px;border-radius:50%;background:var(--accent-soft);border:1.5px solid var(--accent);flex:none}
.cm-live .cml-bubble{background:var(--soft);border:1px solid var(--line);border-radius:12px;border-top-left-radius:3px;padding:9px 12px}
.cm-live .cml-txt{font-size:13.5px;color:var(--ink);line-height:1.6;word-break:break-word}
.cm-live .cml-atts{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.cm-live .cml-att{position:relative}
.cm-live .cml-att img{width:82px;height:82px;object-fit:cover;border-radius:9px;border:1px solid var(--line);display:block}
.cm-live .cml-file{font-size:11.5px;color:var(--muted);background:#fff;border:1px solid var(--line);border-radius:8px;padding:6px 10px;display:inline-block}
.cm-live .cml-rm{position:absolute;top:-7px;right:-7px;width:20px;height:20px;border-radius:50%;background:#fff;border:1px solid var(--line);color:var(--label);cursor:pointer;font-size:11px;line-height:1;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,.12)}
.cm-live .cml-rm:hover{background:#fde8e8;color:#c0392b;border-color:#f2c4c4}
/* checklist en comentario */
.cm-chk{margin:0 0 7px;padding:0 0 7px;border-bottom:1px dashed var(--line)}
/* Imagen dentro de un comentario: se ve a su tamaño real (con tope), NO recortada en un
   cuadrado. Las horizontales conservan su proporción y ganan ancho y alto para leerse. */
.cimg{margin:8px 0}
.cimg .att-img,.cimg a{display:inline-block;width:auto;height:auto;max-width:100%;border-radius:12px;overflow:hidden;border:1px solid var(--line);line-height:0;cursor:zoom-in}
.cimg .att-img img,.cimg img{width:auto;height:auto;min-width:180px;min-height:110px;max-width:min(100%,440px);max-height:360px;object-fit:contain;display:block;border:none;border-radius:0}
.cmck{display:flex;align-items:center;gap:7px;padding:1.5px 0;font-size:12px;color:var(--ink);cursor:pointer;line-height:1.35}
.cmck input{width:13px;height:13px;accent-color:var(--accent);cursor:pointer;flex:none}
.cmck>span:not(.cmck-av){flex:1;min-width:0}
.cmck.done>span{text-decoration:line-through;color:var(--label)}
.cmck-av{width:16px;height:16px;border-radius:50%;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:7.5px;font-weight:700;flex:none}
.cmc-asg{border:none;background:none;cursor:pointer;padding:0;flex:none;display:flex;align-items:center}
.cmc-asg .asg-add.sm{width:18px;height:18px;border:1.4px dashed #c4c8ce;border-radius:50%;color:var(--label);font-size:12px;display:flex;align-items:center;justify-content:center;line-height:1}
.cmc-asg .cav.xs{width:18px;height:18px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-size:7.5px;font-weight:700}
.cm-chkc{display:flex;flex-direction:column;gap:5px;margin-bottom:6px}
.cm-chkc:empty{display:none}
.cmc-row{display:flex;align-items:center;gap:8px}
.cmc-box{width:15px;height:15px;border:1.5px solid #c4c8ce;border-radius:4px;flex:none}
.cmc-inp{flex:1;border:none;outline:none;font-size:13px;font-family:inherit;background:transparent}
.cmc-x{border:none;background:none;color:var(--label);cursor:pointer;font-size:11px}
.cmc-x:hover{color:#c0392b}
/* Editor de checklist dentro de un comentario publicado (#2) */
.cm-chkedit{margin:4px 0 8px;padding:10px 12px;background:#fafbfc;border:1px solid var(--line);border-radius:12px}
.cm-chkedit .cme-rows{display:flex;flex-direction:column;gap:6px}
.cm-chkedit .cmc-row{background:#fff;border:1px solid var(--line2);border-radius:9px;padding:5px 8px}
.cm-chkedit .cmc-inp{font-size:13px}
.cme-bar{display:flex;align-items:center;gap:8px;margin-top:10px}
.cme-add{border:1px dashed #c4c8ce;background:#fff;border-radius:8px;padding:6px 11px;font-size:12px;font-weight:600;color:#6b7079;cursor:pointer}
.cme-add:hover{background:var(--soft);color:var(--ink)}
.cme-btn{border:1px solid var(--line);background:#fff;border-radius:8px;padding:6px 13px;font-size:12.5px;font-weight:600;color:var(--ink);cursor:pointer}
.cme-btn:hover{background:var(--soft)}
.cme-btn.pri{background:var(--accent);color:#fff;border-color:var(--accent)}
.cme-btn.pri:hover{filter:brightness(1.15);background:var(--accent)}
/* aviso de drop en el panel de comentarios */
#actDropHint{position:absolute;inset:8px;border:2px dashed var(--accent);border-radius:14px;background:rgba(255,255,255,.86);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);z-index:8;display:none;align-items:center;justify-content:center;pointer-events:none}
#actDropHint.on{display:flex}
#actDropHint .adh{text-align:center;color:var(--accent);display:flex;flex-direction:column;align-items:center;gap:8px}
#actDropHint .adh b{font-size:14px;color:var(--ink-strong)}
/* face-pile checklist */
.chk-asg.pile{padding:2px 6px 2px 2px}
.chk-asg.pile .pav{width:22px;height:22px;border-radius:50%;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:8.5px;font-weight:600;border:2px solid #fff;margin-left:-7px}
.chk-asg.pile .pav:first-child{margin-left:0}
.chk-asg.pile .pav.plus{background:#fff;border:1.5px dashed #c4c8ce;color:var(--label);font-size:13px}
.chk-asg.pile.filled .pav{opacity:1}
.chk-asg.pile:not(.filled) .pav:not(.plus){opacity:.32}
.chk-asg.pile .pav.xtra{background:#c8ccd2;color:#3c4149;font-size:9px}
.chk-item:hover{background:#fafbfc}
/* ===== Modo oscuro: remapea las superficies y textos propios ===== */
[data-theme=dark] .tkf select:hover,[data-theme=dark] .tkf input:hover,[data-theme=dark] .est-pick:hover,[data-theme=dark] .prio-pick:hover,[data-theme=dark] .val-dates .dwrap:hover,[data-theme=dark] .tm-trigger:hover,[data-theme=dark] .chk-item .cres:hover,[data-theme=dark] .tk-desc textarea:hover,[data-theme=dark] .chk-item:hover,[data-theme=dark] .cm:hover .bubble,[data-theme=dark] .cbox:hover,[data-theme=dark] .cm-chkedit,[data-theme=dark] .cm-quote:hover,[data-theme=dark] .cm-replybar .rb-x:hover,[data-theme=dark] .att-file:hover{background-color:var(--soft)}
[data-theme=dark] .tkf select.plain:hover{background-color:var(--soft)!important}
[data-theme=dark] .tkf select:focus,[data-theme=dark] .tkf input:focus,[data-theme=dark] .tk-desc textarea:focus,[data-theme=dark] .chk-add select{background-color:var(--field)}
[data-theme=dark] .tm-pop,[data-theme=dark] .tm-who-pop,[data-theme=dark] .ws-pop,[data-theme=dark] .pop,[data-theme=dark] .rt-menu,[data-theme=dark] .cm-tools{background-color:var(--pop)}
[data-theme=dark] .tm-who:hover{background-color:var(--field)}
[data-theme=dark] .tm-pop-row button{background-color:var(--rev);color:var(--rev-fg)}
[data-theme=dark] .att-file,[data-theme=dark] .att-x,[data-theme=dark] .cm .bubble,[data-theme=dark] .cbox,[data-theme=dark] .cbox:focus-within,[data-theme=dark] .cm-ii-rm,[data-theme=dark] .rchip,[data-theme=dark] .raddbtn,[data-theme=dark] .cm-preview .cmp-x,[data-theme=dark] .cm-live .cml-file,[data-theme=dark] .cm-live .cml-rm,[data-theme=dark] .cm-chkedit .cmc-row,[data-theme=dark] .cme-add,[data-theme=dark] .cme-btn,[data-theme=dark] #dropOverlay .dz{background-color:var(--card)}
[data-theme=dark] .att-x,[data-theme=dark] .cm-tool,[data-theme=dark] .cm .del,[data-theme=dark] .cm-thumb,[data-theme=dark] .cm-reply,[data-theme=dark] .cmc-x,[data-theme=dark] .cme-add,[data-theme=dark] .ws-pop .wsp-item,[data-theme=dark] .rt-editor:empty:before,[data-theme=dark] .cbox .cm-editor:empty:before{color:var(--muted)}
[data-theme=dark] .cm .txt{color:var(--ink)}
[data-theme=dark] .act{background-color:var(--soft)}
[data-theme=dark] .act h3 .n{background-color:var(--soft);color:var(--muted)}
[data-theme=dark] .chk-item .cdel:hover,[data-theme=dark] .cm-preview .cmp-x:hover,[data-theme=dark] .cm-live .cml-rm:hover,[data-theme=dark] .cm-ii-rm:hover{background-color:var(--danger-bg);color:var(--danger)}
[data-theme=dark] .rt-code,[data-theme=dark] .rt-editor code,[data-theme=dark] .cm-editor code,[data-theme=dark] .cm .txt code{background-color:var(--soft);border-color:var(--line)}
[data-theme=dark] .rt-editor pre,[data-theme=dark] .cm-editor pre,[data-theme=dark] .rt-view .rt-pre,[data-theme=dark] .cm .txt .rt-pre{background-color:var(--soft)}
[data-theme=dark] .rt-editor blockquote,[data-theme=dark] .cm-editor blockquote,[data-theme=dark] .rt-view .rt-quote,[data-theme=dark] .cm .txt .rt-quote{border-left-color:var(--line);color:var(--muted)}
[data-theme=dark] .rt-chk.done .rt-cbox{background-color:var(--ok-bg);border-color:var(--ok-line)}
[data-theme=dark] .cmc-box{border-color:var(--line)}
[data-theme=dark] .saved-note{color:var(--ok)}
/* Pestañas Detalles/Actividad: solo en móvil (abajo se activan). */
.tk-tabs{display:none}
/* ====== MÓVIL (≤640px): una sola columna, campos y editor cómodos ====== */
@media(max-width:640px){
  /* El panel de campos deja de ser 2 columnas */
  .tk-fields{grid-template-columns:1fr;gap:0;padding:14px 0;margin-bottom:20px}
  .tkf{padding:9px 0}
  .tkf .lbl{width:104px}
  .tk-title{font-size:23px;padding-bottom:12px}
  /* ── Pestañas Detalles / Actividad (se cambian tocando o deslizando) ── */
  .tk-tabs{display:flex;gap:4px;background:var(--soft);border:1px solid var(--line);border-radius:11px;padding:4px;margin-bottom:18px;position:sticky;top:56px;z-index:9}
  .tk-tab{flex:1;border:none;background:none;font-family:inherit;font-size:14px;font-weight:600;color:var(--muted);padding:10px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:7px}
  .tk-tab.on{background:var(--card);color:var(--ink-strong);box-shadow:0 1px 3px rgba(0,0,0,.08)}
  .tk-tabn{font-size:11px;background:var(--accent-soft);color:var(--muted);border-radius:99px;padding:1px 7px;font-weight:700}
  /* Por defecto se ve Detalles; con .show-act se ve Actividad. */
  .tk .act{display:none}
  .tk.show-act .tk-left{display:none}
  .tk.show-act .act{display:block;margin-top:0;border-top:none;padding-top:4px}
  /* La actividad ya se apila a ≤940px; aquí solo aseguramos el ancho */
  .act{padding-top:18px}
  /* Barra de herramientas del comentario: que envuelva y no se salga */
  .cbar{flex-wrap:wrap}
  .cbar .sp{flex:1 0 100%;height:0}
  .cbar .send{margin-left:auto}
  /* Popovers propios: nunca más anchos que la pantalla */
  .tm-pop{width:min(236px,calc(100vw - 28px))}
  .ws-pop,.tm-who-pop,.mention-pop{max-width:calc(100vw - 24px)}
  .emoji-pop{max-width:calc(100vw - 40px)}
  /* Adjuntos e imágenes de comentario no desbordan */
  .cm-ii{max-width:100%}
}
</style>

<div class="tk-crumb">
  <a href="<?= e($backHref) ?>"><?= ic('back',14) ?></a>
  <a href="index.php">Clientes</a><span class="sep">/</span>
  <a href="client.php?id=<?= $cli ?>"><?= e($t['cname']) ?></a><span class="sep">/</span>
  <a href="workspace.php?view=cliente&cli=<?= $cli ?>&list=<?= (int)$t['list_id'] ?>"><?= e($t['lname']) ?></a>
</div>

<div class="tk">
  <?php /* Pestañas SOLO en móvil: "Detalles" y "Actividad" (comentarios). Se cambia
           tocando o deslizando el dedo a izquierda/derecha. En escritorio no se ven. */ ?>
  <div class="tk-tabs">
    <button type="button" class="tk-tab on" data-tab="det" onclick="tkTab('det')">Detalles</button>
    <button type="button" class="tk-tab" data-tab="act" onclick="tkTab('act')">Actividad<?php if($comments): ?> <span class="tk-tabn"><?= count($comments) ?></span><?php endif; ?></button>
  </div>
  <div class="tk-left">
    <form method="post" onsubmit="return false">
      <input type="hidden" name="action" value="save_task">
      <input class="tk-title" name="titulo" value="<?= e($t['titulo']) ?>" placeholder="Nombre de la tarea" <?= can_edit()?'onchange="saveField(\'titulo\',this.value)"':'readonly' ?>>
      <div class="tk-fields">
        <?php /* Estado, prioridad, etiquetas y mes no llevaban candado: un usuario de
                 solo lectura podía cambiar el desplegable, el servidor descartaba el
                 cambio (arriba: el bloque POST entero está dentro de can_edit()) y aun
                 así la pantalla le contestaba «Guardado ✓». Al recargar, el valor
                 volvía al de antes sin explicación. Ahora se bloquean como el título. */ ?>
        <?php $estAct=$ESTADOS[$t['estado']]??$ESTADOS['pendiente'];
          $epPend=($t['estado']==='pendiente');
          $epStyle=$epPend ? 'background:#f1f2f4;color:#6b7079;border-color:#e6e7ea' : 'background:'.$estAct[1].';color:#fff;border-color:'.$estAct[1]; ?>
        <div class="tkf"><span class="lbl"><?= ic('check',15) ?> Estado</span><div class="val"><input type="hidden" name="estado" id="estHidden" value="<?= e($t['estado']) ?>"><?php if(can_edit()): ?><button type="button" class="est-pick est-badge" id="estPick" style="<?= $epStyle ?>" onclick="openEstadoT(event)"><span class="ep-lbl"><?= e(mb_strtoupper($estAct[0])) ?></span><?= ic('chevron',13) ?></button><?php else: ?><span class="est-pick est-badge" style="<?= $epStyle ?>"><span class="ep-lbl"><?= e(mb_strtoupper($estAct[0])) ?></span></span><?php endif; ?></div></div>
        <div class="tkf"><span class="lbl"><?= ic('user',15) ?> Asignados</span><div class="val"><input type="hidden" name="responsable_id" id="asgHidden" value="<?= $t['responsable_id']?(int)$t['responsable_id']:'' ?>"><div class="asg-pick<?= can_edit()?'':' ro' ?>" id="asgPick" <?= can_edit()?'onclick="openAsg(event)"':'' ?>><?php if($asignados): ?><?php foreach($asignados as $aid): if(!isset($respMap[$aid])) continue; ?><span class="mini-av" data-uid="<?= (int)$aid ?>" style="background:<?= avatar_color($respMap[$aid]) ?>" title="<?= e($respMap[$aid]) ?>"><?= ini2($respMap[$aid]) ?></span><?php endforeach; ?><span class="asg-nm"><?= count($asignados)===1 && isset($respMap[$asignados[0]]) ? e($respMap[$asignados[0]]) : count($asignados).' asignados' ?></span><?php elseif(can_edit()): ?><span class="asg-add">＋</span><span class="asg-nm mut">Asignar</span><?php else: ?><span class="asg-nm mut">Sin asignar</span><?php endif; ?></div></div></div>
        <?php /* Color de la fecha límite: rojo si ya venció, ámbar si se acerca (≤2 días).
                 Si la tarea está completada no se marca. */
          $dueCls='';
          if(trim((string)$t['due_date'])!=='' && $t['estado']!=='completada'){ $dd=strtotime($t['due_date']); $t0=strtotime('today');
            if($dd!==false){ if($dd<$t0) $dueCls='due-late'; elseif($dd<=strtotime('+2 days',$t0)) $dueCls='due-soon'; } } ?>
        <div class="tkf"><span class="lbl"><?= ic('cal',15) ?> Fechas</span><div class="val val-dates"><span class="dwrap" onclick="dpWrapClick(event,this)"><?= ic('cal',13) ?><input type="text" class="dpick" placeholder="Inicio" data-iso="<?= e($t['fecha_inicio']) ?>" data-field="fecha_inicio" data-onchange="dpTaskField" <?= can_edit()?'':'readonly' ?>></span><span class="arw"><?= ic('chevron',13) ?></span><span class="dwrap due-wrap <?= $dueCls ?>" onclick="dpWrapClick(event,this)"><?= ic('flag',13) ?><input type="text" class="dpick" placeholder="Límite" data-iso="<?= e($t['due_date']) ?>" data-field="due_date" data-onchange="dpTaskField" <?= can_edit()?'':'readonly' ?>></span></div></div>
        <div class="tkf"><span class="lbl"><?= ic('clock',15) ?> Tiempo</span><div class="val"><div class="tm-field"><?php if(can_edit()): ?><button type="button" class="tm-trigger" onclick="openTimePop(this)"><?= $teHorasVal!==''? e($teHorasVal).' h' : '<span class="mut">Añadir tiempo</span>' ?></button><div class="tm-pop"><div class="tm-pop-l">Horas en esta tarea</div><div class="tm-who-row"><button type="button" class="tm-who" id="tmWhoBtn" onclick="tmWhoOpen(event)"><span class="tm-who-av" id="tmWhoAv"></span><span class="tm-who-nm" id="tmWhoNm">Tú</span><svg class="tm-who-arw" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></button><div class="tm-who-pop" id="tmWhoPop"></div></div><div class="tm-pop-row"><input id="tmHoras" type="text" inputmode="decimal" value="<?= $teHorasMio ?>" placeholder="0" onkeydown="if(event.key==='Enter'){event.preventDefault();saveTime(this);}"><span class="u">h</span><button type="button" onclick="saveTime(document.getElementById('tmHoras'))">Guardar</button></div>
<?php /* El reparto por persona. Cada uno apunta las suyas y aquí se ven todas:
         el número grande de fuera es la suma, no lo de nadie en concreto. */
      if(count($teReparto)): ?>
  <div class="tm-rep">
    <?php foreach($teReparto as $r): ?>
      <div class="tm-r<?= $r['id']===$meId?' yo':'' ?>">
        <span class="av" style="background:<?= avatar_color($r['quien']) ?>"><?= e(mb_strtoupper(mb_substr($r['quien'],0,1))) ?></span>
        <span class="n"><?= $r['id']===$meId ? 'Tú' : e($r['quien']) ?></span>
        <b><?= e(h_val($r['min'])) ?> h</b>
      </div>
    <?php endforeach; ?>
    <div class="tm-tot"><span>Total de la tarea</span><b><?= e($teHorasVal!==''?$teHorasVal:'0') ?> h</b></div>
  </div>
<?php endif; ?>
<div class="tm-pop-n">Elige arriba <b>de quién</b> son las horas. Cada persona tiene las suyas y se facturan a su tarifa en <a id="tmFinLink" href="fin-horas.php"><?= ic('euro',12) ?> Finanzas › Horas</a>.</div></div><?php else: ?><span class="tm-trigger tm-ro"><?= $teHorasVal!==''? e($teHorasVal).' h' : '<span class="mut">Sin tiempo registrado</span>' ?></span><?php endif; ?></div></div></div>
        <?php $prAct=$PRIOS[(int)$t['prioridad']]??$PRIOS[0]; $prNone=((int)$t['prioridad']===0); ?>
        <div class="tkf"><span class="lbl"><?= ic('alert',15) ?> Prioridad</span><div class="val"><input type="hidden" name="prioridad" id="prioHidden" value="<?= (int)$t['prioridad'] ?>"><?php if(can_edit()): ?><button type="button" class="prio-pick" id="prioPick" onclick="openPrioT(event)"><?php if($prNone): ?><span class="ep-lbl" style="color:var(--muted)">Sin prioridad</span><?php else: ?><span class="ep-dot" style="background:<?= $prAct[1] ?>"></span><span class="ep-lbl" style="color:<?= $prAct[1] ?>"><?= e($prAct[0]) ?></span><?php endif; ?><?= ic('chevron',13) ?></button><?php else: ?><?php if($prNone): ?><span class="ep-lbl" style="color:var(--muted)">Sin prioridad</span><?php else: ?><span class="ep-lbl" style="color:<?= $prAct[1] ?>"><span class="ep-dot" style="background:<?= $prAct[1] ?>"></span> <?= e($prAct[0]) ?></span><?php endif; ?><?php endif; ?></div></div>
        <div class="tkf"><span class="lbl"><?= ic('list',15) ?> Etiquetas</span><div class="val"><input name="etiquetas" value="<?= e($t['etiquetas']) ?>" placeholder="Vaciar" onchange="saveField('etiquetas',this.value)" <?= can_edit()?'':'readonly' ?>></div></div>
        <div class="tkf"><span class="lbl"><?= ic('cal',15) ?> Mes (informe)</span><div class="val"><input name="mes" value="<?= e($t['mes']) ?>" placeholder="Vaciar" onchange="saveField('mes',this.value)" <?= can_edit()?'':'readonly' ?>></div></div>
      </div>

      <div class="tk-sec">Descripción</div>
      <?php $descSrc = (trim((string)($t['descripcion_rich'] ?? ''))!=='') ? $t['descripcion_rich'] : (string)$t['descripcion']; ?>
      <?php if(can_edit()): ?>
      <div class="tk-desc rt-wrap">
        <div id="descBody" class="rt-editor no-emoji" data-emoji-live contenteditable="true" data-ph="Añade una descripción… texto, imágenes, archivos y listas de control."><?= desc_render_editable($descSrc) ?></div>
        <input type="file" id="descFile" multiple style="display:none">
        <div class="cbar rt-bar">
          <button class="tool rt-more" type="button" title="Bloques: títulos, listas, cita, tabla…" onclick="rtMenu(this,'descBody')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="16" y2="6"/><line x1="3" y1="12" x2="13" y2="12"/><line x1="3" y1="18" x2="16" y2="18"/><line x1="19" y1="9" x2="19" y2="15"/><line x1="16" y1="12" x2="22" y2="12"/></svg></button>
          <button class="tool" type="button" title="Subir imagen o archivo" onclick="document.getElementById('descFile').click()"><?= ic('link',16) ?></button>
          <button class="tool" type="button" title="Lista de control" onclick="descChkAdd()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></button>
          <button class="tool" type="button" data-emoji-btn title="Emoji" onclick="erpEmojiPicker(this,null,document.getElementById('descBody'))"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5a4.5 4.5 0 0 0 7 0"/><path d="M9 9.5h.01"/><path d="M15 9.5h.01"/></svg></button>
          <span class="cbar-sep"></span>
          <button class="tool" type="button" title="Negrita (Ctrl+B)" onclick="descFmt('bold')" style="font-weight:800">B</button>
          <button class="tool" type="button" title="Cursiva (Ctrl+I)" onclick="descFmt('italic')" style="font-style:italic;font-weight:700">I</button>
          <button class="tool" type="button" title="Subrayado (Ctrl+U)" onclick="descFmt('underline')" style="text-decoration:underline;font-weight:700">U</button>
          <button class="tool" type="button" title="Tachado" onclick="descFmt('strikeThrough')" style="text-decoration:line-through;font-weight:700">S</button>
          <button class="tool" type="button" title="Enlace" onclick="rtLink('descBody')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.07 0l2.83-2.83a5 5 0 0 0-7.07-7.07l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7.07 0L4.1 13.83a5 5 0 0 0 7.07 7.07l1.5-1.5"/></svg></button>
          <button class="tool" type="button" title="Código / comando" onclick="rtCode('descBody')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 18l6-6-6-6"/><path d="M8 6l-6 6 6 6"/></svg></button>
        </div>
      </div>
      <?php else: ?>
      <div class="tk-desc"><div class="rt-view"><?= desc_render_editable($descSrc) ?: '<span class="muted" style="color:var(--muted)">Sin descripción.</span>' ?></div></div>
      <?php endif; ?>
      <div class="savebar"><a class="btn ghost sm" href="<?= e($backHref) ?>"><?= ic('back',15) ?> Volver a la lista</a><span id="savedNote" class="saved-note">Guardado ✓</span></div>
    </form>

    <!-- CHECKLIST -->
    <div id="chk" class="tk-sec">Lista de control <span class="sp"></span><?php if($checklist): ?><span class="chk-prog"><?= $chkDone ?>/<?= count($checklist) ?></span><?php endif; ?></div>
    <div>
      <?php foreach($checklist as $ck): ?>
        <div class="chk-item <?= $ck['done']?'done':'' ?>">
          <input type="checkbox" data-id="<?= (int)$ck['id'] ?>" <?= $ck['done']?'checked':'' ?> onchange="chkToggle(this)" <?= can_edit()?'':'disabled' ?>>
          <span class="ctx"><span class="ct"><?= e($ck['texto']) ?></span></span>
          <?php $cids=chk_ids_de($ck,$chkAsgMap,$respMap); ?>
          <?php if(can_edit()): ?>
          <?php if($cids): ?><button type="button" class="chk-asg pile filled" data-chk="<?= (int)$ck['id'] ?>" onclick="chkItemPick(event,<?= (int)$ck['id'] ?>)" title="Responsables"><?php foreach(array_slice($cids,0,3) as $ci): ?><span class="pav" style="background:<?= avatar_color($respMap[$ci]) ?>" title="<?= e($respMap[$ci]) ?>"><?= ini2($respMap[$ci]) ?></span><?php endforeach; ?><?php if(count($cids)>3): ?><span class="pav xtra">+<?= count($cids)-3 ?></span><?php endif; ?></button><?php else: ?><button type="button" class="chk-asg pile" data-chk="<?= (int)$ck['id'] ?>" onclick="chkItemPick(event,<?= (int)$ck['id'] ?>)" title="Asignar responsable"><?php foreach(array_slice($responsables,0,2) as $rr): ?><span class="pav" style="background:<?= avatar_color($rr['username']) ?>"><?= ini2($rr['username']) ?></span><?php endforeach; ?><span class="pav plus">+</span></button><?php endif; ?>
          <form method="post" style="margin:0" onsubmit="return erpSubmitAsk(this,'¿Borrar elemento?')"><input type="hidden" name="action" value="del_check"><input type="hidden" name="chkid" value="<?= (int)$ck['id'] ?>"><input type="hidden" name="ret" value="<?= e($ret) ?>"><button class="cdel" type="submit">✕</button></form>
          <?php else: ?><?php if($cids): ?><span class="chk-asg pile filled"><?php foreach(array_slice($cids,0,3) as $ci): ?><span class="pav" style="background:<?= avatar_color($respMap[$ci]) ?>" title="<?= e($respMap[$ci]) ?>"><?= ini2($respMap[$ci]) ?></span><?php endforeach; ?><?php if(count($cids)>3): ?><span class="pav xtra">+<?= count($cids)-3 ?></span><?php endif; ?></span><?php endif; ?><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if(can_edit()): ?>
      <form method="post" class="chk-add" id="chkAddForm"><input type="hidden" name="action" value="add_check"><input type="hidden" name="ret" value="<?= e($ret) ?>"><input type="hidden" name="rid" id="chkAddRid" value=""><span class="plus"><?= ic('plus',15) ?></span><input name="texto" id="chkAddText" placeholder="Añadir elemento…" autocomplete="off" onfocus="window._chkPicking=false" onkeydown="chkAddKey(event)" onblur="chkAddBlur()"><button type="button" class="chk-asg" id="chkAddAsg" onmousedown="window._chkPicking=true" onclick="chkAddPick(event)" title="Responsable"><span class="asg-add sm">＋</span></button></form>
      <?php endif; ?>
    </div>

    <!-- ADJUNTOS -->
    <div class="tk-sec">Adjuntos <span class="sp"></span><?php if($taskAtts): ?><span class="chk-prog"><?= count($taskAtts) ?></span><?php endif; ?></div>
    <div class="att-grid" style="margin-bottom:12px">
      <?php foreach($taskAtts as $at): ?>
        <div class="att-w"><?= render_att($at) ?><?php if(can_edit()): ?><form method="post" onsubmit="return erpSubmitAsk(this,'¿Quitar adjunto?')"><input type="hidden" name="action" value="del_att"><input type="hidden" name="aid" value="<?= (int)$at['id'] ?>"><input type="hidden" name="ret" value="<?= e($ret) ?>"><button class="att-x" type="submit">✕</button></form><?php endif; ?></div>
      <?php endforeach; ?>
    </div>
    <?php if(can_edit()): ?>
    <form method="post" enctype="multipart/form-data" id="attForm">
      <input type="hidden" name="action" value="upload_att"><input type="hidden" name="ret" value="<?= e($ret) ?>">
      <input type="file" name="files[]" id="attInput" multiple style="display:none" onchange="document.getElementById('attForm').submit()">
      <div class="upl" onclick="document.getElementById('attInput').click()">
        <span class="upl-ic"><?= ic('link',22) ?></span>
        <b>Sube imágenes, vídeos o archivos</b>
        <span>Haz clic aquí o <b>arrastra archivos a cualquier parte</b> de la pantalla</span>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <!-- ACTIVIDAD / COMENTARIOS -->
  <div class="act" id="act">
    <div id="actDropHint"><div class="adh"><?= ic('link',28) ?><b>Suelta aquí para adjuntar al comentario</b></div></div>
    <h3>Actividad <?php if($comments): ?><span class="n"><?= count($comments) ?></span><?php endif; ?></h3>
    <div class="feed">
      <div class="sys"><span class="dot"></span>Tarea creada · <?= e(reltime($t['created_at'])) ?></div>
      <?php if(!$comments): ?><p class="muted" style="font-size:12.5px">Sin comentarios todavía.</p><?php endif; ?>
      <?php foreach($comments as $c): $ca=$attByComment[$c['id']]??[]; $ccl=!empty($c['checklist_json'])?json_decode($c['checklist_json'],true):[]; if(!is_array($ccl))$ccl=[]; ?>
        <div class="cm" data-cid="<?= (int)$c['id'] ?>" data-raw="<?= base64_encode((string)$c['cuerpo']) ?>" oncontextmenu="return cmMenu(event,<?= (int)$c['id'] ?>,<?= (int)$c['admin_id']===$meId?1:0 ?>)">
          <div class="bd">
            <?php
              $imgAtts=array_values(array_filter($ca,function($a){ return is_img($a['filename']); }));
              $otherAtts=array_values(array_filter($ca,function($a){ return !is_img($a['filename']); }));
              $rb=render_comment_body($c['cuerpo'],$imgAtts);
              $belowAtts=array_merge(array_slice($imgAtts,$rb['used']),$otherAtts);
            ?>
            <div class="bubble">
              <div class="top"><div class="av" data-uid="<?= (int)$c['admin_id'] ?>" style="background:<?= avatar_color($c['username'] ?: '??') ?>"><?= ini2($c['username'] ?: '??') ?></div><b data-uid="<?= (int)$c['admin_id'] ?>" style="cursor:default"><?= e($c['username'] ?: 'Sistema') ?></b><span><?= e(reltime($c['created_at'])) ?></span></div>
              <?php if(!empty($c['reply_to']) && isset($cmById[$c['reply_to']])): $oc=$cmById[$c['reply_to']]; ?>
              <div class="cm-quote" onclick="cmQuoteGo(<?= (int)$c['reply_to'] ?>)" title="Ir al comentario"><span class="qa"><?= e($oc['username'] ?: '—') ?></span><span class="qt"><?= e(cm_excerpt($oc['cuerpo'])) ?></span></div>
              <?php endif; ?>
              <?php if($ccl): ?><div class="cm-chk"><?php foreach($ccl as $ii=>$itc): ?><label class="cmck <?= !empty($itc['done'])?'done':'' ?>"><input type="checkbox" <?= !empty($itc['done'])?'checked':'' ?> <?= can_edit()?'onchange="cmCheck('.(int)$c['id'].','.$ii.',this)"':'disabled' ?>><span><?= e($itc['texto']) ?></span><?php if(!empty($itc['resp']) && isset($respMap[$itc['resp']])): ?><span class="cmck-av" style="background:<?= avatar_color($respMap[$itc['resp']]) ?>" title="<?= e($respMap[$itc['resp']]) ?>"><?= ini2($respMap[$itc['resp']]) ?></span><?php endif; ?></label><?php endforeach; ?></div><?php endif; ?>
              <?= $rb['html'] ?>
              <?php if($belowAtts): ?><div class="catt"><?php foreach($belowAtts as $at): ?><span class="att-w"><?= render_att($at) ?><?php if(can_edit() && (int)$c['admin_id']===$meId): ?><form method="post" style="display:inline;margin:0" onsubmit="return erpSubmitAsk(this,'¿Quitar adjunto?')"><input type="hidden" name="action" value="del_att"><input type="hidden" name="aid" value="<?= (int)$at['id'] ?>"><input type="hidden" name="ret" value="<?= e($ret) ?>"><button class="att-x" type="submit">✕</button></form><?php endif; ?></span><?php endforeach; ?></div><?php endif; ?>
              <?php $rcs=$reacts[$c['id']]??[]; $thumb=null; $others=[]; foreach($rcs as $rc){ if($rc['emoji']==='👍') $thumb=$rc; else $others[]=$rc; } $tN=$thumb?(int)$thumb['n']:0; ?>
              <div class="cm-foot">
                <button type="button" class="cm-thumb <?= ($thumb && $thumb['mine'])?'mine':'' ?><?= $tN>0?' has':'' ?>" onclick="react(<?= (int)$c['id'] ?>,'👍',this)" title="Me gusta">
                  <?php if($tN>0): ?>👍 <span><?= $tN ?></span><?php else: ?><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></svg><?php endif; ?>
                </button>
                <?php foreach($others as $rc): ?><button type="button" class="rchip <?= $rc['mine']?'mine':'' ?>" onclick="react(<?= (int)$c['id'] ?>,<?= htmlspecialchars(json_encode($rc['emoji']),ENT_QUOTES) ?>)"><?= $rc['emoji'] ?> <span><?= (int)$rc['n'] ?></span></button><?php endforeach; ?>
                <span class="cm-foot-sp"></span>
                <?php if(can_edit()): ?><button type="button" class="cm-reply" onclick="cmReply(<?= (int)$c['id'] ?>,<?= htmlspecialchars(json_encode($c['username']?:''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode(cm_excerpt($c['cuerpo'],70)),ENT_QUOTES) ?>)"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg> Responder</button><?php endif; ?>
              </div>
            </div>
          </div>
          <?php if(can_edit()): ?><div class="cm-tools">
            <button type="button" class="cm-tool" title="Reaccionar" onclick="reactPick(event,<?= (int)$c['id'] ?>)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 14.5s1.4 2 4 2 4-2 4-2"/><path d="M9 9.5h.01M15 9.5h.01"/></svg></button>
            <button type="button" class="cm-tool" title="Más" onclick="cmMenu(event,<?= (int)$c['id'] ?>,<?= (int)$c['admin_id']===$meId?1:0 ?>)"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.9"/><circle cx="12" cy="12" r="1.9"/><circle cx="19" cy="12" r="1.9"/></svg></button>
          </div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (can_edit()): ?>
    <div class="cbox-wrap">
      <form method="post" enctype="multipart/form-data" class="cbox" id="cmForm" onsubmit="return cmSubmit(event)">
        <input type="hidden" name="action" value="add_comment">
        <input type="hidden" name="checklist" id="cmChkData">
        <input type="hidden" name="cuerpo" id="cmBodyHidden">
        <input type="hidden" name="reply_to" id="cmReplyTo" value="">
        <div id="cmReplyBar" class="cm-replybar" style="display:none">
          <div class="rb-body"><span class="rb-a" id="cmReplyAuthor"></span><span class="rb-t" id="cmReplyText"></span></div>
          <button type="button" class="rb-x" onclick="cmReplyCancel()" title="Cancelar respuesta">✕</button>
        </div>
        <div id="cmChkCompose" class="cm-chkc"></div>
        <div id="cmBody" class="cm-editor no-emoji" data-emoji-live contenteditable="true" data-ph="Escribe un comentario…"></div>
        <div id="cmOther" class="cm-other"></div>
        <input type="file" name="files[]" id="cmFiles" multiple style="display:none">
        <div class="cbar">
          <button class="tool" type="button" title="Adjuntar" onclick="document.getElementById('cmFiles').click()"><?= ic('link',16) ?></button>
          <?php /* El botón lleva una carita dibujada en SVG con el mismo trazo que el
                   resto de iconos de la barra, no un emoji: un emoji suelto en la
                   barra se vería de colores y de un tamaño distinto en cada sistema.
                   Los emojis de dentro del panel sí se quedan: esos no son interfaz,
                   son el contenido que se inserta en el comentario. */ ?>
          <button class="tool" type="button" data-emoji-btn title="Emoji" onclick="erpEmojiPicker(this,null,document.getElementById('cmBody'))"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5a4.5 4.5 0 0 0 7 0"/><path d="M9 9.5h.01"/><path d="M15 9.5h.01"/></svg></button>
          <button class="tool" type="button" title="Mencionar" onclick="cmMentionBtn()">@</button>
          <button class="tool" type="button" title="Lista de control" onclick="cmChkAdd()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></button>
          <button class="tool rt-more" type="button" title="Bloques: títulos, listas, cita, tabla…" onclick="rtMenu(this,'cmBody')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="16" y2="6"/><line x1="3" y1="12" x2="13" y2="12"/><line x1="3" y1="18" x2="16" y2="18"/><line x1="19" y1="9" x2="19" y2="15"/><line x1="16" y1="12" x2="22" y2="12"/></svg></button>
          <span class="cbar-sep"></span>
          <button class="tool" type="button" title="Negrita (Ctrl+B)" onclick="cmFmt('bold')" style="font-weight:800">B</button>
          <button class="tool" type="button" title="Cursiva (Ctrl+I)" onclick="cmFmt('italic')" style="font-style:italic;font-weight:700">I</button>
          <button class="tool" type="button" title="Subrayado (Ctrl+U)" onclick="cmFmt('underline')" style="text-decoration:underline;font-weight:700">U</button>
          <button class="tool" type="button" title="Tachado" onclick="cmFmt('strikeThrough')" style="text-decoration:line-through;font-weight:700">S</button>
          <button class="tool" type="button" title="Enlace" onclick="rtLink('cmBody')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.07 0l2.83-2.83a5 5 0 0 0-7.07-7.07l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7.07 0L4.1 13.83a5 5 0 0 0 7.07 7.07l1.5-1.5"/></svg></button>
          <button class="tool" type="button" title="Código / comando" onclick="rtCode('cmBody')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 18l6-6-6-6"/><path d="M8 6l-6 6 6 6"/></svg></button>
          <span class="sp"></span>
          <button class="send" type="submit">Comentar</button>
        </div>
        <div class="pop emoji-pop" id="emojiPop"><?php foreach(['👍','✅','🔥','🎉','🙌','😄','🙏','👀','💡','⚠️','❤️','😅','🚀','📌','✍️','💯'] as $em): ?><button type="button" onclick="insEmoji('<?= $em ?>')"><?= $em ?></button><?php endforeach; ?></div>
        <div class="pop mention-pop" id="mentionPop"></div>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<div id="tkPop" class="ws-pop"></div>
<div id="reactPop" class="ws-pop"></div>
<div id="dropOverlay"><div class="dz"><?= ic('link',34) ?><b>Suelta para adjuntar</b><span>En el cuadro de comentario → al comentario · en el resto → a la tarea</span></div></div>
<div id="lightbox" onclick="lbClose()"><img src="" alt=""></div>
<?php if(can_edit()): ?>
<div class="ctxmenu" id="commentCtx"></div>
<form id="editCommentForm" method="post" style="display:none"><input type="hidden" name="action" value="edit_comment"><input type="hidden" name="cid" id="ecId"><input type="hidden" name="cuerpo" id="ecBody"><input type="hidden" name="ret" value="<?= e($ret) ?>"></form>
<form id="delCommentForm" method="post" style="display:none"><input type="hidden" name="action" value="del_comment"><input type="hidden" name="cid" id="dcId"><input type="hidden" name="ret" value="<?= e($ret) ?>"></form>
<?php endif; ?>
<script>
/* ===== Móvil: pestañas Detalles / Actividad (tocar o deslizar) ===== */
(function(){
  function setTab(which){
    var tk=document.querySelector('.tk'); if(!tk) return;
    tk.classList.toggle('show-act', which==='act');
    document.querySelectorAll('.tk-tab').forEach(function(b){ b.classList.toggle('on', b.dataset.tab===which); });
  }
  window.tkTab=function(which){ setTab(which); window.scrollTo(0,0); };
  var tk=document.querySelector('.tk'); if(!tk) return;
  var x0=null,y0=null;
  tk.addEventListener('touchstart',function(e){ x0=e.touches[0].clientX; y0=e.touches[0].clientY; },{passive:true});
  tk.addEventListener('touchend',function(e){
    if(x0===null) return; var dx=e.changedTouches[0].clientX-x0, dy=e.changedTouches[0].clientY-y0; x0=null;
    if(Math.abs(dx)<70 || Math.abs(dx)<Math.abs(dy)*1.5) return;  // gesto claramente horizontal
    setTab(dx<0 ? 'act' : 'det');   // desliza a la izquierda → Actividad; a la derecha → Detalles
  },{passive:true});
})();
window.MEMBERS=[<?php foreach($responsables as $r): ?>{id:<?= (int)$r['id'] ?>,name:<?= json_encode($r['username']) ?>,color:<?= json_encode(avatar_color($r['username'])) ?>,ini:<?= json_encode(mb_strtoupper(mb_substr($r['username'],0,2))) ?>},<?php endforeach; ?>];
window.ASG=[<?php echo implode(',', array_map('intval',$asignados)); ?>];
/* Horas por persona (minutos), total y yo — para el selector de «quién echa horas». */
window.TE_MIN=<?= json_encode((object)$teMinMap, JSON_UNESCAPED_UNICODE) ?>;
window.TE_TOTAL_MIN=<?= (int)$teMin ?>;
window.TE_ME=<?= (int)$meId ?>;
<?php $chkAsgJs=[]; foreach($checklist as $ck){ $chkAsgJs[(int)$ck['id']]=array_map('intval',chk_ids_de($ck,$chkAsgMap,$respMap)); } ?>
window.CHKASG=<?= json_encode((object)$chkAsgJs) ?>;
<?php /* Checklists dentro de cada comentario, para poder editarlas con clic derecho (#2). */
  $cmChkJs=[]; foreach($comments as $c){ if(!empty($c['checklist_json'])){ $arr=json_decode($c['checklist_json'],true); if(is_array($arr)){ $out=[]; foreach($arr as $itc){ $out[]=['texto'=>(string)($itc['texto']??''),'done'=>!empty($itc['done'])?1:0,'resp'=>(isset($itc['resp'])&&$itc['resp']!==''&&$itc['resp']!==null)?(int)$itc['resp']:null]; } if($out)$cmChkJs[(int)$c['id']]=$out; } } } ?>
window.CMCHK=<?= json_encode((object)$cmChkJs,JSON_UNESCAPED_UNICODE) ?>;
function esc(s){return(''+s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
/* «Guardado ✓» solo cuando el servidor ha guardado de verdad.
   Antes el aviso salía siempre: el fetch termina bien aunque el servidor no haya
   escrito nada (a un usuario de solo lectura le devuelve la página entera con un
   200), así que la pantalla mentía. Ahora se comprueba el permiso antes de pedir
   nada y, si la respuesta no viene bien, se avisa en vez de decir que se guardó. */
var TK_PUEDE_EDITAR = <?= can_edit()?'true':'false' ?>;
function tkSaved(){var n=document.getElementById('savedNote');if(n){n.classList.add('show');clearTimeout(window._sv);window._sv=setTimeout(function(){n.classList.remove('show');},1300);}}
function tkPost(body){
  if(!TK_PUEDE_EDITAR){ if(window.toast) toast('Tu cuenta es de solo lectura: este cambio no se guarda.','err'); return; }
  fetch('task.php?id=<?= $id ?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
    .then(function(r){ if(!r.ok) throw 0; tkSaved(); })
    .catch(function(){ if(window.toast) toast('No se ha podido guardar. Recarga la página.','err'); });
}
function saveField(field,val){tkPost('action=set_field&field='+field+'&val='+encodeURIComponent(val));}
window.TESTADOS=[<?php foreach($ESTADOS as $k=>$v): ?>{k:<?= json_encode($k) ?>,label:<?= json_encode(mb_strtoupper($v[0])) ?>,color:<?= json_encode($v[1]) ?>},<?php endforeach; ?>];
window.TPRIOS=[<?php foreach($PRIOS as $k=>$v): ?>{v:<?= (int)$k ?>,label:<?= json_encode($v[0]) ?>,color:<?= json_encode($v[1]) ?>},<?php endforeach; ?>];
var TCHEV=<?= json_encode(ic('chevron',13)) ?>;
function openEstadoT(e){e.stopPropagation();var cell=document.getElementById('estPick');
  var items=window.TESTADOS.map(function(s){return {val:s.k,s:s,html:'<span class="ep-dot" style="background:'+s.color+'"></span>'+esc(s.label)};});
  tkShow(document.getElementById('tkPop'),cell,items,function(it){document.getElementById('estHidden').value=it.val;
    var pend=(it.val==='pendiente');
    cell.style.background=pend?'#f1f2f4':it.s.color;
    cell.style.color=pend?'#6b7079':'#fff';
    cell.style.borderColor=pend?'#e6e7ea':it.s.color;
    cell.innerHTML='<span class="ep-lbl">'+esc(it.s.label)+'</span>'+TCHEV;
    saveField('estado',it.val);recolorDue();});}
function openPrioT(e){e.stopPropagation();var cell=document.getElementById('prioPick');
  var items=window.TPRIOS.map(function(p){return {val:p.v,p:p,html:(p.v==0?'<span class="ep-lbl" style="color:var(--muted)">Sin prioridad</span>':'<span class="ep-dot" style="background:'+p.color+'"></span>'+esc(p.label))};});
  tkShow(document.getElementById('tkPop'),cell,items,function(it){document.getElementById('prioHidden').value=it.val;
    cell.innerHTML=(it.val==0?'<span class="ep-lbl" style="color:var(--muted)">Sin prioridad</span>':'<span class="ep-dot" style="background:'+it.p.color+'"></span><span class="ep-lbl" style="color:'+it.p.color+'">'+esc(it.p.label)+'</span>')+TCHEV;
    saveField('prioridad',it.val);});}
function tmMember(id){id=+id;for(var i=0;i<window.MEMBERS.length;i++)if(window.MEMBERS[i].id===id)return window.MEMBERS[i];return null;}
function tmName(id){id=+id;if(id===window.TE_ME)return 'Tú';var m=tmMember(id);return m?m.name:'—';}
function tmHnum(min){return Math.round((min||0)/60*100)/100;}
function tmHstr(min){var n=tmHnum(min);return n?String(n).replace('.',','):'';}
/* Personas seleccionables: tú + los asignados + quien ya tenga horas. */
function tmWhoList(){var ids=[],seen={};function add(id){id=+id;if(id&&!seen[id]){seen[id]=1;ids.push(id);}}add(window.TE_ME);(window.ASG||[]).forEach(add);Object.keys(window.TE_MIN||{}).forEach(add);return ids;}
var tmWhoId=0;
function tmAv(id){var m=tmMember(id);var ini=(m&&m.ini)?m.ini:tmName(id).slice(0,2);return {col:m?m.color:'#9aa0a8',ini:ini.charAt(0).toUpperCase()};}
function tmWhoTrig(){var a=tmAv(tmWhoId);var av=document.getElementById('tmWhoAv'),nm=document.getElementById('tmWhoNm');if(av){av.style.background=a.col;av.textContent=a.ini;}if(nm)nm.textContent=tmName(tmWhoId);var fl=document.getElementById('tmFinLink');if(fl)fl.href='fin-horas.php?u='+tmWhoId;}
function tmWhoSet(id){tmWhoId=+id;tmWhoTrig();var inp=document.getElementById('tmHoras');if(inp)inp.value=tmHstr((window.TE_MIN||{})[tmWhoId]||0);}
function tmWhoClose(){var p=document.getElementById('tmWhoPop');if(p)p.classList.remove('on');}
function tmWhoOpen(e){if(e)e.stopPropagation();var pop=document.getElementById('tmWhoPop');if(pop.classList.contains('on')){pop.classList.remove('on');return;}
  pop.innerHTML=tmWhoList().map(function(id){var a=tmAv(id);var h=tmHstr((window.TE_MIN||{})[id]||0);return '<button type="button" data-id="'+id+'"'+(id===tmWhoId?' class="sel"':'')+'><span class="a" style="background:'+a.col+'">'+esc(a.ini)+'</span><span>'+esc(tmName(id))+'</span>'+(h?'<span class="h">'+h+' h</span>':'')+'</button>';}).join('');
  Array.prototype.forEach.call(pop.querySelectorAll('button'),function(b){b.onclick=function(ev){ev.stopPropagation();tmWhoSet(b.getAttribute('data-id'));pop.classList.remove('on');var i=document.getElementById('tmHoras');if(i){i.focus();i.select();}};});
  pop.classList.add('on');}
function openTimePop(btn){closeTimePop();var pop=btn.parentNode.querySelector('.tm-pop');pop.classList.add('on');tmWhoSet(window.TE_ME);var i=document.getElementById('tmHoras');i.focus();i.select();setTimeout(function(){document.addEventListener('mousedown',_tmOut,true);},0);}
function closeTimePop(){tmWhoClose();document.querySelectorAll('.tm-pop.on').forEach(function(p){p.classList.remove('on');});document.removeEventListener('mousedown',_tmOut,true);}
function _tmOut(e){if(!e.target.closest('.tm-field')){closeTimePop();return;}if(!e.target.closest('.tm-who-row'))tmWhoClose();}
function tmRenderReparto(f){var rep=f.querySelector('.tm-rep');if(!rep)return;var TE=window.TE_MIN||{};
  var ids=Object.keys(TE).map(Number).filter(function(id){return TE[id]>0;}).sort(function(a,b){return TE[b]-TE[a];});
  var html=ids.map(function(id){var m=tmMember(id);var col=m?m.color:'#9aa0a8';var nm=tmName(id);var ini=(m?m.ini.charAt(0):nm.charAt(0)).toUpperCase();
    return '<div class="tm-r'+(id===window.TE_ME?' yo':'')+'"><span class="av" style="background:'+col+'">'+esc(ini)+'</span><span class="n">'+esc(nm)+'</span><b>'+tmHstr(TE[id])+' h</b></div>';}).join('');
  html+='<div class="tm-tot"><span>Total de la tarea</span><b>'+(tmHnum(window.TE_TOTAL_MIN)||0)+' h</b></div>';
  rep.innerHTML=html;}
function saveTime(inp){var who=tmWhoId||window.TE_ME;
  var v=inp.value.trim();var newMin=Math.round((parseFloat(v.replace(',','.'))||0)*60);
  tkPost('action=time_set&horas='+encodeURIComponent(v)+'&who='+who);
  var prev=(window.TE_MIN||{})[who]||0; window.TE_TOTAL_MIN=(window.TE_TOTAL_MIN||0)-prev+newMin;
  if(newMin>0)window.TE_MIN[who]=newMin; else delete window.TE_MIN[who];
  var f=inp.closest('.tm-field');var tr=f.querySelector('.tm-trigger');var totH=tmHnum(window.TE_TOTAL_MIN);
  tr.innerHTML=totH>0?(String(totH).replace('.',',')+' h'):'<span class="mut">Añadir tiempo</span>';
  tmRenderReparto(f);closeTimePop();}

window.dpTaskField=function(iso,inp){saveField(inp.dataset.field,iso);if(inp.dataset.field==='due_date')recolorDue();};
/* Toda el área del campo de fecha (padding e icono incluidos) abre el selector, no solo
   el texto. Si el clic no fue en el input, lo enfocamos (eso abre el datepicker) y
   frenamos la propagación para que el cierre-al-clicar-fuera no lo cierre al instante. */
function dpWrapClick(e,w){var i=w.querySelector('.dpick');if(!i||i.readOnly)return;if(e.target!==i){i.focus();e.stopPropagation();}}
/* Recalcula el color de la fecha límite (ámbar si se acerca, rojo si venció). */
function recolorDue(){var inp=document.querySelector('.dpick[data-field="due_date"]');if(!inp)return;var w=inp.closest('.dwrap');if(!w)return;
  w.classList.remove('due-late','due-soon');
  var iso=inp.dataset.iso||'';var est=document.getElementById('estHidden');var done=est&&est.value==='completada';
  if(!iso||done)return;
  var d=new Date(iso+'T00:00:00');var t0=new Date();t0.setHours(0,0,0,0);var soon=new Date(t0);soon.setDate(soon.getDate()+2);
  if(d<t0)w.classList.add('due-late');else if(d<=soon)w.classList.add('due-soon');}
/* Visor de imagen. Reinicia la animación con un reflujo forzado para que, aunque
   cierres y vuelvas a abrir al instante, siempre entre limpio (antes se «buggeaba»). */
function lightbox(url){var lb=document.getElementById('lightbox');if(!lb)return false;
  var img=lb.querySelector('img');if(img.getAttribute('src')!==url)img.setAttribute('src',url);
  lb.classList.remove('on');void lb.offsetWidth;lb.classList.add('on');
  document.addEventListener('keydown',_lbEsc);return false;}
function lbClose(){var lb=document.getElementById('lightbox');if(!lb)return;lb.classList.remove('on');document.removeEventListener('keydown',_lbEsc);}
function _lbEsc(e){if(e.key==='Escape')lbClose();}
/* Insertar código inline / enlace azul en cualquiera de los dos editores (#cmBody / #descBody). */
function _rtEsc(s){return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function _rtAfter(edId){if(edId==='descBody'&&typeof descAutosave==='function')descAutosave();}
/* ===== Menú de bloques (encabezados, listas, cita, código, tabla, divisor) =====
   Compartido por comentarios (#cmBody) y descripción (#descBody). Usa execCommand
   (formatBlock/insertUnorderedList/…) y luego cmSerialize/descSerialize lo convierten
   a marcadores, que rt_blocks vuelve a pintar. */
function rtExec(edId,cmd,val){var ed=document.getElementById(edId);if(!ed)return;ed.focus();try{document.execCommand('styleWithCSS',false,false);}catch(e){}try{document.execCommand(cmd,false,val||null);}catch(e){}_rtAfter(edId);}
function rtBlock(edId,tag){rtExec(edId,'formatBlock',tag);}
function rtTable(edId){var ed=document.getElementById(edId);if(!ed)return;ed.focus();document.execCommand('insertHTML',false,'<table class="rt-table"><thead><tr><th>Columna</th><th>Columna</th></tr></thead><tbody><tr><td><br></td><td><br></td></tr><tr><td><br></td><td><br></td></tr></tbody></table><div class="rt-p"><br></div>');_rtAfter(edId);}
var RT_BLOCKS=[
 {t:'Texto normal',i:'¶',fn:function(ed){rtBlock(ed,'P');}},
 {t:'Título grande',i:'H1',fn:function(ed){rtBlock(ed,'H1');}},
 {t:'Título mediano',i:'H2',fn:function(ed){rtBlock(ed,'H2');}},
 {t:'Título pequeño',i:'H3',fn:function(ed){rtBlock(ed,'H3');}},
 {sep:1},
 {t:'Lista con viñetas',i:'•',fn:function(ed){rtExec(ed,'insertUnorderedList');}},
 {t:'Lista numerada',i:'1.',fn:function(ed){rtExec(ed,'insertOrderedList');}},
 {t:'Lista de control',i:'☑',fn:function(ed){ed==='cmBody'?cmChkAdd():descChkAdd();}},
 {sep:1},
 {t:'Cita',i:'❝',fn:function(ed){rtBlock(ed,'BLOCKQUOTE');}},
 {t:'Bloque de código',i:'{}',fn:function(ed){rtBlock(ed,'PRE');}},
 {t:'Tabla',i:'▦',fn:function(ed){rtTable(ed);}},
 {t:'Divisor',i:'—',fn:function(ed){rtExec(ed,'insertHorizontalRule');}}
];
function rtMenuClose(){var m=document.getElementById('rtMenu');if(m)m.classList.remove('on');}
function rtMenu(btn,edId){var m=document.getElementById('rtMenu');
  if(!m){m=document.createElement('div');m.id='rtMenu';m.className='rt-menu';document.body.appendChild(m);
    document.addEventListener('mousedown',function(e){if(m.classList.contains('on')&&!e.target.closest('#rtMenu')&&!e.target.closest('.rt-more'))rtMenuClose();});}
  if(m.classList.contains('on')&&m.dataset.ed===edId){rtMenuClose();return;}
  m.dataset.ed=edId;m.innerHTML='';
  RT_BLOCKS.forEach(function(it){if(it.sep){var s=document.createElement('div');s.className='rt-menu-sep';m.appendChild(s);return;}
    var b=document.createElement('button');b.type='button';b.innerHTML='<span class="rt-mi">'+it.i+'</span>'+it.t;
    b.onmousedown=function(e){e.preventDefault();};b.onclick=function(){rtMenuClose();it.fn(edId);};m.appendChild(b);});
  var r=btn.getBoundingClientRect();m.classList.add('on');var h=m.offsetHeight;var top=r.bottom+6;if(top+h>window.innerHeight-8)top=Math.max(8,r.top-h-6);
  m.style.left=Math.max(8,Math.min(r.left,window.innerWidth-232))+'px';m.style.top=top+'px';}
/* ===== Arreglo del "fondo pegajoso" =====
   Al escribir al final de un elemento con formato en línea, el texto se metía dentro
   (seguía en negrita/código con su fondo). Ahora: Enter SIEMPRE sale del formato, y del
   código en línea (que no tiene interruptor) se sale también con espacio o al escribir. */
function rtEscInline(ed){ if(!ed)return;
  var FMT={code:1,b:1,strong:1,i:1,em:1,u:1,s:1,strike:1,del:1};
  function caretAtEnd(r,el){try{var rng=document.createRange();rng.selectNodeContents(el);rng.setStart(r.startContainer,r.startOffset);return rng.toString().replace(/[​ \s]/g,'').length===0;}catch(e){return false;}}
  ed.addEventListener('keydown',function(e){
    if(e.ctrlKey||e.metaKey||e.altKey)return;
    if(typeof cmMentionOpen==='function'&&cmMentionOpen())return;
    var isEnter=(e.key==='Enter'),isChar=(e.key===' '||e.key.length===1);
    if(!isEnter&&!isChar)return;
    var sel=window.getSelection(); if(!sel.rangeCount||!sel.isCollapsed)return;
    var r=sel.getRangeAt(0);
    var fmt=null,cur=(r.startContainer.nodeType===3?r.startContainer.parentNode:r.startContainer);
    while(cur&&cur!==ed){var t=(cur.nodeName||'').toLowerCase();
      if(FMT[t]){if(caretAtEnd(r,cur))fmt=cur;else return;}
      else if(cur.nodeType===1&&/^(div|p|li|blockquote|td|th|h1|h2|h3|pre|ul|ol)$/.test(t))break;
      cur=cur.parentNode;}
    if(!fmt)return;
    if(isEnter){var nr=document.createRange();nr.setStartAfter(fmt);nr.collapse(true);sel.removeAllRanges();sel.addRange(nr);return;}
    if((fmt.nodeName||'').toLowerCase()!=='code')return;   // espacio/letra: solo se escapa del código
    e.preventDefault();
    var ch=(e.key===' '?' ':e.key);
    var tn=document.createTextNode(ch);fmt.parentNode.insertBefore(tn,fmt.nextSibling);
    var nr2=document.createRange();nr2.setStart(tn,ch.length);nr2.collapse(true);sel.removeAllRanges();sel.addRange(nr2);
    _rtAfter(ed.id);
  });
}
rtEscInline(document.getElementById('cmBody'));
rtEscInline(document.getElementById('descBody'));

/* ===== Tablas editables: Tab entre celdas (+ fila al final) y barra flotante para
   añadir/quitar filas y columnas con el ratón. ===== */
var _rtTcell=null,_rtTedId='',_rtTHideT=null;
function rtCellFocus(c){if(!c)return;var sel=window.getSelection();var r=document.createRange();r.selectNodeContents(c);r.collapse(true);sel.removeAllRanges();sel.addRange(r);c.scrollIntoView&&c.scrollIntoView({block:'nearest'});}
function rtTableTab(ed){ if(!ed)return;
  ed.addEventListener('keydown',function(e){ if(e.key!=='Tab')return;
    var sel=window.getSelection(); if(!sel.rangeCount)return;
    var node=sel.getRangeAt(0).startContainer; var cell=(node.nodeType===3?node.parentNode:node); cell=cell.closest?cell.closest('td,th'):null;
    if(!cell||!ed.contains(cell))return; e.preventDefault();
    var table=cell.closest('table'); var cells=Array.prototype.slice.call(table.querySelectorAll('td,th')); var idx=cells.indexOf(cell);
    if(e.shiftKey){ if(idx>0)rtCellFocus(cells[idx-1]); }
    else if(idx<cells.length-1){ rtCellFocus(cells[idx+1]); }
    else { rtRowAdd(cell.parentNode); var nc=table.querySelectorAll('td,th'); rtCellFocus(nc[idx+1]); _rtAfter(ed.id); }
  });
}
function rtRowAdd(row){var n=row.children.length;var tr=document.createElement('tr');for(var i=0;i<n;i++){var td=document.createElement('td');td.innerHTML='<br>';tr.appendChild(td);}row.parentNode.insertBefore(tr,row.nextSibling);return tr;}
function rtTctlEl(){var m=document.getElementById('rtTctl');if(!m){m=document.createElement('div');m.id='rtTctl';m.className='rt-tctl';
    m.innerHTML='<button type="button" data-a="colR" title="Añadir columna a la derecha">＋col</button><button type="button" data-a="rowB" title="Añadir fila debajo">＋fila</button><button type="button" data-a="colX" title="Borrar esta columna">－col</button><button type="button" data-a="rowX" title="Borrar esta fila">－fila</button>';
    document.body.appendChild(m);
    m.addEventListener('mousedown',function(e){e.preventDefault();});
    m.addEventListener('mouseenter',function(){if(_rtTHideT){clearTimeout(_rtTHideT);_rtTHideT=null;}});
    m.addEventListener('mouseleave',function(){rtTctlHideSoon();});
    m.addEventListener('click',function(e){var b=e.target.closest('button');if(b&&_rtTcell)rtTblAction(b.getAttribute('data-a'));});
  }return m;}
function rtTctlShow(cell){if(!cell)return;var table=cell.closest('table');if(!table)return;var m=rtTctlEl();var tr=table.getBoundingClientRect();
  m.classList.add('on');var w=m.offsetWidth||168;m.style.left=Math.max(8,Math.min(tr.right-w,window.innerWidth-w-8))+'px';m.style.top=Math.max(6,tr.top-30)+'px';}
function rtTctlHideSoon(){if(_rtTHideT)clearTimeout(_rtTHideT);_rtTHideT=setTimeout(function(){var m=document.getElementById('rtTctl');if(m&&!m.matches(':hover'))m.classList.remove('on');},380);}
function rtTblAction(a){var cell=_rtTcell;if(!cell||!cell.isConnected)return;var table=cell.closest('table');var row=cell.parentNode;var idx=Array.prototype.indexOf.call(row.children,cell);
  if(a==='rowB'){rtRowAdd(row);}
  else if(a==='rowX'){if(table.querySelectorAll('tr').length>1){var sib=row.nextElementSibling||row.previousElementSibling;row.remove();_rtTcell=sib?sib.children[Math.min(idx,sib.children.length-1)]:table.querySelector('td,th');}}
  else if(a==='colR'){Array.prototype.forEach.call(table.querySelectorAll('tr'),function(tr){var isH=!!tr.closest('thead');var nc=document.createElement(isH?'th':'td');nc.innerHTML='<br>';var ref=tr.children[idx];if(ref)tr.insertBefore(nc,ref.nextSibling);else tr.appendChild(nc);});}
  else if(a==='colX'){var cols=(table.querySelector('tr')||{children:[]}).children.length;if(cols>1)Array.prototype.forEach.call(table.querySelectorAll('tr'),function(tr){if(tr.children[idx])tr.children[idx].remove();});}
  _rtAfter(_rtTedId);rtTctlShow(_rtTcell&&_rtTcell.isConnected?_rtTcell:table.querySelector('td,th'));}
function rtTableHover(ed){ if(!ed)return;
  ed.addEventListener('mouseover',function(e){var c=e.target.closest?e.target.closest('td,th'):null;if(c&&ed.contains(c)){_rtTcell=c;_rtTedId=ed.id;if(_rtTHideT){clearTimeout(_rtTHideT);_rtTHideT=null;}rtTctlShow(c);}});
  ed.addEventListener('mouseleave',function(){rtTctlHideSoon();});
}
['cmBody','descBody'].forEach(function(id){var ed=document.getElementById(id);rtTableTab(ed);rtTableHover(ed);});
function rtCode(edId){var ed=document.getElementById(edId);if(!ed)return;ed.focus();var sel=window.getSelection();var txt=(sel&&sel.rangeCount)?sel.toString():'';document.execCommand('insertHTML',false,'<code>'+_rtEsc(txt||'código')+'</code> ');_rtAfter(edId);}
function rtLink(edId){var ed=document.getElementById(edId);if(!ed)return;ed.focus();var sel=window.getSelection();var range=(sel&&sel.rangeCount&&ed.contains(sel.anchorNode))?sel.getRangeAt(0).cloneRange():null;var txt=range?range.toString():'';
  erpPrompt('Pega o escribe el enlace (URL):',(txt&&/^https?:/i.test(txt))?txt:'https://').then(function(u){if(!u)return;if(!/^https?:\/\//i.test(u))u='https://'+String(u).replace(/^\/+/,'');ed.focus();var s=window.getSelection();if(range){s.removeAllRanges();s.addRange(range);}var label=txt||u;document.execCommand('insertHTML',false,'<a href="'+_rtEsc(u)+'">'+_rtEsc(label)+'</a> ');_rtAfter(edId);});}
<?php if(can_edit()): ?>
/* ================= Editor de descripción (lienzo tipo comentarios) ================= */
function descEl(){return document.getElementById('descBody');}
function descFmt(cmd){var ed=descEl();if(!ed)return;ed.focus();try{document.execCommand('styleWithCSS',false,false);}catch(e){}try{document.execCommand(cmd,false,null);}catch(e){}descAutosave();}
function descInsertNode(node){var ed=descEl();if(!ed)return;ed.focus();var sel=window.getSelection();var r;
  if(sel&&sel.rangeCount&&ed.contains(sel.anchorNode)){r=sel.getRangeAt(0);
    /* Nunca dentro de un ítem de checklist (el serializador solo lee su texto): si el
       cursor está ahí, insertamos justo DESPUÉS del checklist. */
    var an=(sel.anchorNode.nodeType===1?sel.anchorNode:sel.anchorNode.parentNode);
    var chk=an&&an.closest?an.closest('.desc-chk'):null;
    if(chk){r=document.createRange();r.setStartAfter(chk);r.collapse(true);}else{r.deleteContents();}
  }else{r=document.createRange();r.selectNodeContents(ed);r.collapse(false);}
  r.insertNode(node);var sp=document.createTextNode(' ');r.setStartAfter(node);r.insertNode(sp);r.setStartAfter(sp);r.collapse(true);sel.removeAllRanges();sel.addRange(r);}
function descInsertImg(fn,url){var img=document.createElement('img');img.className='desc-img';img.setAttribute('data-fn',fn);img.src=url;img.setAttribute('onclick','lightbox(this.src)');descInsertNode(img);descAutosave();}
function descInsertFile(fn,orig,url){var sp=document.createElement('span');sp.className='desc-file';sp.setAttribute('data-fn',fn);sp.setAttribute('data-orig',orig);sp.setAttribute('contenteditable','false');
  var a=document.createElement('a');a.href=url;a.target='_blank';a.textContent='📎 '+orig;sp.appendChild(a);descInsertNode(sp);descAutosave();}
function descUpload(file){var fd=new FormData();fd.append('action','desc_upload');fd.append('file',file);
  return fetch('task.php?id=<?= $id ?>',{method:'POST',body:fd}).then(function(r){return r.json();});}
function descHandleFiles(list){for(var i=0;i<list.length;i++){(function(f){descUpload(f).then(function(res){if(res&&res.ok){if(res.img)descInsertImg(res.fn,res.url);else descInsertFile(res.fn,res.orig,res.url);}else if(window.toast)toast('No se pudo subir el archivo','err');}).catch(function(){});})(list[i]);}}
function descChkAdd(){var ed=descEl();if(!ed)return;ed.focus();
  var d=document.createElement('div');d.className='desc-chk';
  var cb=document.createElement('input');cb.type='checkbox';cb.setAttribute('onchange','descAutosave()');
  var sp=document.createElement('span');sp.className='dc-txt';
  d.appendChild(cb);d.appendChild(sp);descInsertNode(d);
  var sel=window.getSelection();var r=document.createRange();r.selectNodeContents(sp);r.collapse(true);sel.removeAllRanges();sel.addRange(r);}
/* Serializa el editor a marcadores (**b** *i* __u__ · [[img:FN]] · [[file:FN|orig]] · [[chk:0/1]] texto). */
function descSerialize(){var ed=descEl();if(!ed)return'';var out='';
  (function walk(node){var kids=node.childNodes;for(var i=0;i<kids.length;i++){var n=kids[i];
    if(n.nodeType===3){out+=n.nodeValue;}
    else if(n.nodeType===1){var tag=n.nodeName.toLowerCase();
      if(n.classList&&n.classList.contains('ap-e')){out+=(n.getAttribute('data-e')||'');}
      else if(tag==='br'){out+='\n';}
      else if(tag==='h1'||tag==='h2'||tag==='h3'){var _hp={h1:'# ',h2:'## ',h3:'### '}[tag];var _hs=out;out='';walk(n);var _hi=out.replace(/\s+/g,' ').trim();out=_hs;out+='\n'+_hp+_hi+'\n';}
      else if(tag==='ul'||tag==='ol'){var _lo=(tag==='ol'),_lk=1;out+='\n';for(var _lc=0;_lc<n.children.length;_lc++){var _li=n.children[_lc];if(_li.nodeName.toLowerCase()!=='li')continue;var _ls=out;out='';walk(_li);var _lin=out.replace(/\s+/g,' ').trim();out=_ls;out+=(_lo?(_lk++)+'. ':'- ')+_lin+'\n';}}
      else if(tag==='blockquote'){var _qs=out;out='';walk(n);var _qi=out.replace(/^\n+|\n+$/g,'');out=_qs;out+='\n'+_qi.split('\n').map(function(l){return '> '+l;}).join('\n')+'\n';}
      else if(tag==='pre'){out+='\n```\n'+rtText(n).replace(/```/g,'')+'\n```\n';}
      else if(tag==='hr'){out+='\n---\n';}
      else if(tag==='table'){out+='\n';var _trs=n.querySelectorAll('tr');for(var _ri=0;_ri<_trs.length;_ri++){var _cc=_trs[_ri].querySelectorAll('th,td'),_cs=[];for(var _ci=0;_ci<_cc.length;_ci++){var _ts=out;out='';walk(_cc[_ci]);_cs.push(out.replace(/\s+/g,' ').replace(/\|/g,'/').trim());out=_ts;}if(_cs.length){out+='| '+_cs.join(' | ')+' |\n';if(_ri===0){out+='|'+_cs.map(function(){return' --- ';}).join('|')+'|\n';}}}out+='\n';}
      else if(tag==='s'||tag==='strike'||tag==='del'){out+='~~';walk(n);out+='~~';}
      else if(n.classList&&n.classList.contains('mention')){out+='@'+(n.getAttribute('data-mention')||rtText(n).replace(/^@/,''));}
      else if(n.classList&&n.classList.contains('desc-chk')){var cb=n.querySelector('input[type=checkbox]');var tx=n.querySelector('.dc-txt');out+='\n[[chk:'+((cb&&cb.checked)?'1':'0')+']] '+((tx?rtText(tx):'').replace(/\n/g,' '))+'\n';}
      else if(tag==='img'&&n.getAttribute('data-fn')){out+='[[img:'+n.getAttribute('data-fn')+']]';}
      else if(n.classList&&n.classList.contains('desc-file')&&n.getAttribute('data-fn')){out+='[[file:'+n.getAttribute('data-fn')+'|'+(n.getAttribute('data-orig')||'')+']]';}
      else if(tag==='code'){out+='`'+rtText(n).replace(/`/g,'')+'`';}
      else if(tag==='a'&&n.getAttribute('href')){var _h=n.getAttribute('href')||'';var _t=n.textContent||'';out+=(/^https?:/i.test(_h)?(_t===_h?_h:'['+_t+']('+_h+')'):_t);}
      else if(tag==='b'||tag==='strong'){out+='**';walk(n);out+='**';}
      else if(tag==='u'){out+='__';walk(n);out+='__';}
      else if(tag==='i'||tag==='em'){out+='*';walk(n);out+='*';}
      else if(tag==='div'||tag==='p'){out+='\n';walk(n);}
      else{var _st=n.style||{};var _td=(_st.textDecoration||'')+' '+(_st.textDecorationLine||'');var _stk=_td.indexOf('line-through')>-1;if(_stk)out+='~~';walk(n);if(_stk)out+='~~';}
    }}})(ed);
  return out.replace(/[ \t]+\n/g,'\n').replace(/\n{3,}/g,'\n\n').replace(/^\n+|\n+$/g,'');}
var _descT=null;
function descAutosave(){if(_descT)clearTimeout(_descT);_descT=setTimeout(descSave,700);}
function descSave(){var ed=descEl();if(!ed)return;var body=new URLSearchParams();body.set('action','set_desc');body.set('rich',descSerialize());
  fetch('task.php?id=<?= $id ?>',{method:'POST',body:body}).catch(function(){});}
(function(){var ed=descEl();if(!ed)return;
  ed.addEventListener('input',descAutosave);
  ed.addEventListener('blur',descSave);
  ed.addEventListener('paste',function(e){var dt=e.clipboardData||window.clipboardData;if(!dt)return;
    if(dt.files&&dt.files.length){e.preventDefault();descHandleFiles(dt.files);return;}
    var html=dt.getData('text/html');if(html&&html.trim()){var clean=sanitizePaste(html);if(clean){e.preventDefault();document.execCommand('insertHTML',false,clean);descAutosave();return;}}
    e.preventDefault();var t=dt.getData('text/plain');document.execCommand('insertText',false,t);descAutosave();});
  ed.addEventListener('drop',function(e){if(e.dataTransfer&&e.dataTransfer.files&&e.dataTransfer.files.length){e.preventDefault();e.stopPropagation();descHandleFiles(e.dataTransfer.files);}});
  ed.addEventListener('dragover',function(e){if(e.dataTransfer&&Array.prototype.indexOf.call(e.dataTransfer.types||[],'Files')>-1){e.preventDefault();e.stopPropagation();}});
  var fi=document.getElementById('descFile');if(fi)fi.addEventListener('change',function(){if(this.files&&this.files.length){descHandleFiles(this.files);this.value='';}});
})();
<?php endif; ?>
/* Texto de un nodo recuperando los emojis Apple (spans .ap-e no llevan texto: su
   carácter vive en data-e). Se usa donde se serializa por textContent: mención,
   ítem de checklist, bloque de código y código en línea. Así no se pierde el emoji. */
function rtText(node){var s='';var k=node.childNodes;for(var i=0;i<k.length;i++){var n=k[i];
  if(n.nodeType===3){s+=n.nodeValue;}
  else if(n.nodeType===1){s+=(n.classList&&n.classList.contains('ap-e'))?(n.getAttribute('data-e')||''):rtText(n);}}
  return s;}
function tkShow(pop,anchor,items,pick){pop.innerHTML='';items.forEach(function(it){var b=document.createElement('button');b.type='button';b.className='wsp-item';b.innerHTML=it.html;b.onclick=function(ev){ev.stopPropagation();pick(it);pop.classList.remove('on');};pop.appendChild(b);});var r=anchor.getBoundingClientRect();pop.style.left=Math.min(r.left,window.innerWidth-232)+'px';pop.style.top=(r.bottom+4)+'px';pop.classList.add('on');}
function asgMember(id){for(var i=0;i<window.MEMBERS.length;i++){if(window.MEMBERS[i].id===id)return window.MEMBERS[i];}return null;}
function asgRender(){var cell=document.getElementById('asgPick');var html='';if(window.ASG.length){window.ASG.forEach(function(id){var m=asgMember(id);if(m)html+='<span class="mini-av" data-uid="'+m.id+'" style="background:'+m.color+'" title="'+esc(m.name)+'">'+esc(m.ini)+'</span>';});var m0=asgMember(window.ASG[0]);html+='<span class="asg-nm">'+(window.ASG.length===1&&m0?esc(m0.name):window.ASG.length+' asignados')+'</span>';}else{html='<span class="asg-add">＋</span><span class="asg-nm mut">Asignar</span>';}cell.innerHTML=html;var h=document.getElementById('asgHidden');if(h)h.value=window.ASG[0]||'';}
function asgSave(){var body='action=set_asignados';window.ASG.forEach(function(id){body+='&ids[]='+id;});fetch('task.php?id=<?= $id ?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body}).catch(function(){});}
function openAsg(e){e.stopPropagation();var cell=document.getElementById('asgPick');var pop=document.getElementById('tkPop');pop.innerHTML='';
  window.MEMBERS.forEach(function(m){var b=document.createElement('button');b.type='button';b.className='wsp-item';var on=window.ASG.indexOf(m.id)>=0;
    b.innerHTML='<span class="wsp-av" style="background:'+m.color+'">'+esc(m.ini)+'</span><span style="flex:1">'+esc(m.name)+'</span><span class="wsp-ck">'+(on?'✓':'')+'</span>';
    b.onclick=function(ev){ev.stopPropagation();var i=window.ASG.indexOf(m.id);if(i>=0)window.ASG.splice(i,1);else window.ASG.push(m.id);b.querySelector('.wsp-ck').textContent=window.ASG.indexOf(m.id)>=0?'✓':'';asgRender();asgSave();};
    pop.appendChild(b);});
  var r=cell.getBoundingClientRect();pop.style.left=Math.min(r.left,window.innerWidth-232)+'px';pop.style.top=(r.bottom+4)+'px';pop.classList.add('on');}
function _rpop(el){if(!el)return;el.classList.remove('rpop');void el.offsetWidth;el.classList.add('rpop');}
function react(cid,emoji,btn){if(btn)_rpop(btn);   /* pop inmediato para respuesta instantánea */
  fetch('task.php?id=<?= $id ?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=react&cid='+cid+'&emoji='+encodeURIComponent(emoji)}).then(function(){return fetch(location.pathname+location.search);}).then(function(r){return r.text();}).then(function(html){
    if(typeof cmApplyFeed!=='function'){location.reload();return;}
    cmApplyFeed(html,false);
    var cm=document.querySelector('.cm[data-cid="'+cid+'"]');if(cm){var el=(emoji==='👍')?cm.querySelector('.cm-thumb'):null;if(!el){var chips=[].slice.call(cm.querySelectorAll('.rchip'));for(var i=0;i<chips.length;i++){if(chips[i].textContent.indexOf(emoji)>=0){el=chips[i];break;}}}_rpop(el);}
  }).catch(function(){location.reload();});}
/* Reaccionar = picker global de emojis Apple (cualquier emoji; se queda abierto para
   poner varios). Se conserva reactAtXY por si algún sitio aún lo llama por coordenadas. */
function reactAtXY(x,y,cid){ if(window.erpEmojiPickerXY){ erpEmojiPickerXY(x,y,function(ch){react(cid,ch);}); return; }
  var pop=document.getElementById('reactPop');pop.innerHTML='';['👍','✅','🔥','🎉','🙌','😄','🙏','👀','❤️','😮','💯','👏'].forEach(function(em){var b=document.createElement('button');b.type='button';b.className='rpk';b.textContent=em;b.onclick=function(ev){ev.stopPropagation();pop.classList.remove('on');react(cid,em);};pop.appendChild(b);});pop.style.left=Math.min(x,window.innerWidth-236)+'px';pop.style.top=Math.min(y,window.innerHeight-70)+'px';pop.classList.add('on');}
function reactPick(e,cid){e.stopPropagation(); if(window.erpEmojiPicker){ erpEmojiPicker(e.currentTarget,function(ch){react(cid,ch);}); return; } var r=e.currentTarget.getBoundingClientRect();reactAtXY(r.left,r.bottom+4,cid);}
function cmReply(cid,username,excerpt){
  var rt=document.getElementById('cmReplyTo');if(rt)rt.value=cid||'';
  var a=document.getElementById('cmReplyAuthor');if(a)a.textContent=username||'';
  var t=document.getElementById('cmReplyText');if(t)t.textContent=excerpt||'';
  var bar=document.getElementById('cmReplyBar');if(bar)bar.style.display='flex';
  var ed=document.getElementById('cmBody');if(ed){ed.focus();ed.scrollIntoView({block:'center',behavior:'smooth'});}}
function cmReplyCancel(){var rt=document.getElementById('cmReplyTo');if(rt)rt.value='';var bar=document.getElementById('cmReplyBar');if(bar)bar.style.display='none';}
function cmQuoteGo(id){var el=document.querySelector('.cm[data-cid="'+id+'"]');if(!el)return;el.scrollIntoView({block:'center',behavior:'smooth'});el.classList.remove('cm-flash');void el.offsetWidth;el.classList.add('cm-flash');setTimeout(function(){el.classList.remove('cm-flash');},1600);}
/* Editor de checklist DENTRO de un comentario ya publicado (#2): clic derecho →
   «Añadir checklist». Reutiliza las filas del composer y el selector de asignado. */
function cmEditChk(cid){
  var cm=document.querySelector('.cm[data-cid="'+cid+'"]');if(!cm)return;
  var bubble=cm.querySelector('.bubble')||cm;
  if(bubble.querySelector('.cm-chkedit'))return;
  var items=(window.CMCHK&&window.CMCHK[cid])?window.CMCHK[cid]:[];
  var panel=document.createElement('div');panel.className='cm-chkedit';
  var rows=document.createElement('div');rows.className='cme-rows';panel.appendChild(rows);
  function paintAsg(row,asg){var rid=row.dataset.rid;if(rid===''||rid==null){asg.innerHTML='<span class="asg-add sm">＋</span>';}else{var m=asgMember(parseInt(rid,10));asg.innerHTML=m?'<span class="cav xs" style="background:'+m.color+'">'+esc(m.ini)+'</span>':'<span class="asg-add sm">＋</span>';}}
  function addRow(it){it=it||{};
    var row=document.createElement('div');row.className='cmc-row';row.dataset.done=it.done?'1':'';row.dataset.rid=(it.resp!=null&&it.resp!=='')?it.resp:'';
    var box=document.createElement('span');box.className='cmc-box';
    var inp=document.createElement('input');inp.type='text';inp.className='cmc-inp';inp.placeholder='Elemento…';inp.value=it.texto||'';
    inp.onkeydown=function(ev){if(ev.key==='Enter'){ev.preventDefault();addRow();}};
    var asg=document.createElement('button');asg.type='button';asg.className='cmc-asg';
    asg.onclick=function(ev){ev.preventDefault();ev.stopPropagation();tkShow(document.getElementById('tkPop'),asg,chkMembers(),function(sel){row.dataset.rid=sel.val;paintAsg(row,asg);});};
    var x=document.createElement('button');x.type='button';x.className='cmc-x';x.textContent='✕';x.onclick=function(){row.parentNode.removeChild(row);};
    row.appendChild(box);row.appendChild(inp);row.appendChild(asg);row.appendChild(x);rows.appendChild(row);paintAsg(row,asg);inp.focus();}
  items.forEach(addRow); if(!items.length) addRow();
  var bar=document.createElement('div');bar.className='cme-bar';
  var add=document.createElement('button');add.type='button';add.className='cme-add';add.textContent='＋ Añadir elemento';add.onclick=function(){addRow();};
  var sp=document.createElement('span');sp.style.flex='1';
  var cn=document.createElement('button');cn.type='button';cn.className='cme-btn';cn.textContent='Cancelar';cn.onclick=function(){location.reload();};
  var sv=document.createElement('button');sv.type='button';sv.className='cme-btn pri';sv.textContent='Guardar';sv.onclick=function(){cmChkSave(cid,rows);};
  bar.appendChild(add);bar.appendChild(sp);bar.appendChild(cn);bar.appendChild(sv);panel.appendChild(bar);
  var existing=bubble.querySelector('.cm-chk');
  if(existing){existing.style.display='none';existing.insertAdjacentElement('afterend',panel);}
  else{var top=bubble.querySelector('.top');if(top)top.insertAdjacentElement('afterend',panel);else bubble.insertBefore(panel,bubble.firstChild);}
  panel.scrollIntoView({block:'center',behavior:'smooth'});}
function cmChkSave(cid,rows){
  var arr=[];[].forEach.call(rows.querySelectorAll('.cmc-row'),function(r){var inp=r.querySelector('.cmc-inp');var v=inp?inp.value.trim():'';if(v){var rid=r.dataset.rid;arr.push({texto:v,done:r.dataset.done==='1'?1:0,resp:(rid&&rid!=='')?parseInt(rid,10):null});}});
  fetch('task.php?id=<?= $id ?>',{method:'POST',body:new URLSearchParams({action:'comment_setchk',cid:cid,checklist:JSON.stringify(arr)})})
    .then(function(){if(window.toast)toast('Checklist guardado ✓');setTimeout(function(){location.reload();},400);})
    .catch(function(){if(window.toast)toast('No se pudo guardar','err');});}
function cmMenu(e,cid,own){e.preventDefault();e.stopPropagation();var m=document.getElementById('commentCtx');if(!m)return true;m.innerHTML='';
  function it(txt,fn,danger){var a=document.createElement('a');a.textContent=txt;a.href='#';if(danger)a.className='danger';a.onclick=function(ev){ev.preventDefault();ev.stopPropagation();m.classList.remove('on');fn();};return a;}
  var _cmEl=document.querySelector('.cm[data-cid="'+cid+'"]');var _un=_cmEl?_cmEl.querySelector('.top b'):null;var _uname=_un?_un.textContent.trim():'';
  var _txt=_cmEl?_cmEl.querySelector('.txt'):null;var _ex=_txt?_txt.textContent.trim().slice(0,70):'';
  m.appendChild(it('Responder',function(){cmReply(cid,_uname,_ex);}));
  m.appendChild(it('Reaccionar…',function(){reactAtXY(e.clientX,e.clientY,cid);}));
  <?php if(can_edit()): ?>m.appendChild(it((window.CMCHK&&window.CMCHK[cid]&&window.CMCHK[cid].length)?'Editar checklist':'Añadir checklist',function(){cmEditChk(cid);}));<?php endif; ?>
  if(own){m.appendChild(it('Editar',function(){editComment(cid);}));var s=document.createElement('div');s.className='sep';m.appendChild(s);m.appendChild(it('Eliminar',function(){
    erpConfirm('¿Borrar este comentario?',{danger:true}).then(function(ok){if(!ok)return;
      document.getElementById('dcId').value=cid;document.getElementById('delCommentForm').submit();});},true));}
  m.style.left=Math.min(e.clientX,window.innerWidth-200)+'px';m.style.top=Math.min(e.clientY,window.innerHeight-160)+'px';m.classList.add('on');return false;}
function editComment(cid){var cm=document.querySelector('.cm[data-cid="'+cid+'"]');if(!cm)return;var txt=cm.querySelector('.txt');var cur='';try{cur=cm.dataset.raw?decodeURIComponent(escape(atob(cm.dataset.raw))):(txt?txt.textContent:'');}catch(e){cur=txt?txt.textContent:'';}
  var wrap=document.createElement('div');wrap.className='cm-edit';var ta=document.createElement('textarea');ta.value=cur;
  var row=document.createElement('div');row.style.cssText='display:flex;gap:8px;margin-top:6px';
  var sv=document.createElement('button');sv.type='button';sv.className='btn sm';sv.textContent='Guardar';sv.onclick=function(){document.getElementById('ecId').value=cid;document.getElementById('ecBody').value=ta.value;document.getElementById('editCommentForm').submit();};
  var cn=document.createElement('button');cn.type='button';cn.className='btn ghost sm';cn.textContent='Cancelar';cn.onclick=function(){location.reload();};
  row.appendChild(sv);row.appendChild(cn);wrap.appendChild(ta);wrap.appendChild(row);
  if(txt){txt.replaceWith(wrap);}else{(cm.querySelector('.bubble')||cm.querySelector('.bd')||cm).appendChild(wrap);}ta.focus();}
function chkToggle(el){var it=el.closest('.chk-item');it.classList.toggle('done',el.checked);
  fetch('task.php?id=<?= $id ?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=toggle_check&chkid='+el.dataset.id}).catch(function(){});
  if(el.checked){it.classList.add('just-done');setTimeout(function(){it.classList.remove('just-done');},650);}
  setTimeout(function(){var cont=it.parentNode;var addf=cont.querySelector('.chk-add');var items=[].slice.call(cont.querySelectorAll('.chk-item'));
    items.sort(function(a,b){return (b.classList.contains('done')?1:0)-(a.classList.contains('done')?1:0);});
    items.forEach(function(x){cont.insertBefore(x,addf||null);});},280);}
function chkMembers(){var items=[{val:'',html:'<span class="wsp-mut">Sin responsable</span>'}];window.MEMBERS.forEach(function(m){items.push({val:m.id,m:m,html:'<span class="wsp-av" style="background:'+m.color+'">'+esc(m.ini)+'</span>'+esc(m.name)});});return items;}
function chkPileHtml(ids){var h='';ids.slice(0,3).forEach(function(id){var m=asgMember(id);if(m)h+='<span class="pav" style="background:'+m.color+'" title="'+esc(m.name)+'">'+esc(m.ini)+'</span>';});if(ids.length>3)h+='<span class="pav xtra">+'+(ids.length-3)+'</span>';if(!ids.length){window.MEMBERS.slice(0,2).forEach(function(m){h+='<span class="pav" style="background:'+m.color+'">'+esc(m.ini)+'</span>';});h+='<span class="pav plus">+</span>';}return h;}
function chkRenderPile(btn,ids){btn.innerHTML=chkPileHtml(ids);btn.classList.toggle('filled',ids.length>0);}
function chkMultiPop(btn,getIds,onToggle){var pop=document.getElementById('tkPop');pop.innerHTML='';window.MEMBERS.forEach(function(m){var b=document.createElement('button');b.type='button';b.className='wsp-item';var on=getIds().indexOf(m.id)>=0;b.innerHTML='<span class="wsp-av" style="background:'+m.color+'">'+esc(m.ini)+'</span><span style="flex:1">'+esc(m.name)+'</span><span class="wsp-ck">'+(on?'✓':'')+'</span>';b.onclick=function(ev){ev.stopPropagation();onToggle(m.id);b.querySelector('.wsp-ck').textContent=getIds().indexOf(m.id)>=0?'✓':'';};pop.appendChild(b);});var r=btn.getBoundingClientRect();pop.style.left=Math.min(r.left,window.innerWidth-232)+'px';pop.style.top=(r.bottom+4)+'px';pop.classList.add('on');}
function chkItemPick(e,chkid){e.stopPropagation();var btn=e.currentTarget;if(!window.CHKASG[chkid])window.CHKASG[chkid]=[];
  chkMultiPop(btn,function(){return window.CHKASG[chkid];},function(mid){var a=window.CHKASG[chkid];var i=a.indexOf(mid);if(i>=0)a.splice(i,1);else a.push(mid);chkRenderPile(btn,a);
    var body='action=check_asig&chkid='+chkid;a.forEach(function(id){body+='&ids[]='+id;});fetch('task.php?id=<?= $id ?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body}).catch(function(){});});}
window._chkAddRids=[];
function chkAddPick(e){e.stopPropagation();window._chkPicking=true;var btn=document.getElementById('chkAddAsg');
  chkMultiPop(btn,function(){return window._chkAddRids;},function(mid){var a=window._chkAddRids;var i=a.indexOf(mid);if(i>=0)a.splice(i,1);else a.push(mid);
    if(a.length){btn.classList.add('pile','filled');btn.innerHTML=chkPileHtml(a);}else{btn.classList.remove('pile','filled');btn.innerHTML='<span class="asg-add sm">＋</span>';}
    document.getElementById('chkAddRid').value=a[0]||'';});}
function chkAddSubmit(){var t=document.getElementById('chkAddText');if(!t||t.value.trim()==='')return;var f=document.getElementById('chkAddForm');
  f.querySelectorAll('input[name="rids[]"]').forEach(function(x){x.remove();});
  (window._chkAddRids||[]).forEach(function(id){var h=document.createElement('input');h.type='hidden';h.name='rids[]';h.value=id;f.appendChild(h);});
  f.submit();}
function chkAddKey(e){if(e.key==='Enter'){e.preventDefault();chkAddSubmit();}}
function chkAddBlur(){setTimeout(function(){if(window._chkPicking)return;chkAddSubmit();},120);}
function togPop(pid){var p=document.getElementById(pid);var on=p.classList.contains('on');document.querySelectorAll('.pop').forEach(function(x){x.classList.remove('on');});if(!on)p.classList.add('on');}
function cmInsertText(txt){var ed=document.getElementById('cmBody');ed.focus();var sel=window.getSelection();if(sel&&sel.rangeCount&&ed.contains(sel.anchorNode)){var r=sel.getRangeAt(0);r.deleteContents();var tn=document.createTextNode(txt);r.insertNode(tn);r.setStartAfter(tn);r.collapse(true);sel.removeAllRanges();sel.addRange(r);}else{ed.appendChild(document.createTextNode(txt));}}
function insEmoji(em){cmInsertText(em);document.getElementById('emojiPop').classList.remove('on');}
function cmMakeMentionChip(name,mem){var s=document.createElement('span');s.className='mention';s.setAttribute('contenteditable','false');s.setAttribute('data-mention',name);if(mem){var av=document.createElement('span');av.className='m-av';av.style.background=mem.color;av.textContent=mem.ini;s.appendChild(av);}s.appendChild(document.createTextNode(name));return s;}
function insMention(n){var ed=_mentionEd||document.getElementById('cmBody');ed.focus();var mem=(window.MEMBERS||[]).filter(function(x){return x.name===n;})[0];var sel=window.getSelection();var r;
  if(sel&&sel.rangeCount){r=sel.getRangeAt(0);var node=r.startContainer;if(node.nodeType===3){var off=r.startOffset;var txt=node.nodeValue;var at=txt.lastIndexOf('@',off-1);if(at>=0){node.nodeValue=txt.slice(0,at)+txt.slice(off);r.setStart(node,at);r.collapse(true);sel.removeAllRanges();sel.addRange(r);}}}
  else{r=document.createRange();r.selectNodeContents(ed);r.collapse(false);}
  var chip=cmMakeMentionChip(mem?mem.name:n,mem);r.deleteContents();r.insertNode(chip);var sp=document.createTextNode(' ');r.setStartAfter(chip);r.insertNode(sp);r.setStartAfter(sp);r.collapse(true);sel.removeAllRanges();sel.addRange(r);
  mnClose();ed.dispatchEvent(new Event('input',{bubbles:true}));}
function cmChkAdd(){var c=document.getElementById('cmChkCompose');var row=document.createElement('div');row.className='cmc-row';
  var box=document.createElement('span');box.className='cmc-box';
  var inp=document.createElement('input');inp.type='text';inp.placeholder='Elemento…';inp.className='cmc-inp';inp.onkeydown=function(ev){if(ev.key==='Enter'){ev.preventDefault();cmChkAdd();}};
  var asg=document.createElement('button');asg.type='button';asg.className='cmc-asg';asg.innerHTML='<span class="asg-add sm">＋</span>';
  asg.onclick=function(ev){ev.preventDefault();ev.stopPropagation();tkShow(document.getElementById('tkPop'),asg,chkMembers(),function(it){row.dataset.rid=it.val;asg.innerHTML=(it.val===''?'<span class="asg-add sm">＋</span>':'<span class="cav xs" style="background:'+it.m.color+'">'+esc(it.m.ini)+'</span>');});};
  var x=document.createElement('button');x.type='button';x.className='cmc-x';x.textContent='✕';x.onclick=function(){row.parentNode.removeChild(row);};
  row.appendChild(box);row.appendChild(inp);row.appendChild(asg);row.appendChild(x);c.appendChild(row);inp.focus();}
function cmChkSerialize(){var rows=document.querySelectorAll('#cmChkCompose .cmc-row');var arr=[];for(var i=0;i<rows.length;i++){var inp=rows[i].querySelector('.cmc-inp');var v=inp?inp.value.trim():'';if(v){var rid=rows[i].dataset.rid;arr.push({texto:v,done:0,resp:(rid&&rid!=='')?parseInt(rid,10):null});}}document.getElementById('cmChkData').value=arr.length?JSON.stringify(arr):'';return true;}
function cmCheck(cid,idx,el){el.closest('.cmck').classList.toggle('done',el.checked);fetch('task.php?id=<?= $id ?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=comment_check&cid='+cid+'&idx='+idx}).catch(function(){});}
/* ---- Autocompletado de @menciones ----
   Al escribir «@ad» se filtran los miembros (admin…), el primero queda resaltado y
   con Enter o Tab se inserta; con ↑/↓ se navega y con Esc se cierra. */
var _mentionSel=0, _mentionItems=[], _mentionEd=null;
/* Popup de menciones GLOBAL (al cursor), compartido por el comentario y la descripción.
   Se posiciona en vivo junto al caret, así funciona igual en los dos editores. */
function mnPopEl(){var p=document.getElementById('mnPop');if(!p){p=document.createElement('div');p.id='mnPop';p.className='pop mention-pop';p.style.position='fixed';p.style.bottom='auto';document.body.appendChild(p);}return p;}
function mnClose(){var p=document.getElementById('mnPop');if(p)p.classList.remove('on');}
function cmMentionOpen(){var p=document.getElementById('mnPop');return p&&p.classList.contains('on');}
function cmMentionCtx(){var sel=window.getSelection();if(!sel||!sel.rangeCount)return null;var r=sel.getRangeAt(0);var node=r.startContainer;if(node.nodeType!==3)return null;var txt=node.nodeValue.slice(0,r.startOffset);var m=txt.match(/@([\p{L}0-9_.\-]*)$/u);return m?{query:m[1]}:null;}
function cmMentionHi(i){_mentionSel=i;var bs=document.querySelectorAll('#mnPop .mn-item');for(var j=0;j<bs.length;j++){bs[j].classList.toggle('sel',j===i);}if(bs[i])bs[i].scrollIntoView({block:'nearest'});}
function mnPlaceAtCaret(pop){var sel=window.getSelection();var rect=null;
  if(sel&&sel.rangeCount){var rg=sel.getRangeAt(0).cloneRange();var rr=rg.getClientRects();rect=(rr&&rr.length)?rr[rr.length-1]:rg.getBoundingClientRect();}
  if(!rect||(!rect.top&&!rect.left)){var ed=_mentionEd;if(ed){var er=ed.getBoundingClientRect();rect={left:er.left+10,bottom:er.top+24,top:er.top+24};}}
  if(rect){pop.style.left=Math.max(8,Math.min(rect.left,window.innerWidth-240))+'px';var top=rect.bottom+6;if(top>window.innerHeight-220)top=rect.top-6-Math.min(220,pop.offsetHeight||200);pop.style.top=top+'px';}}
function cmMentionShow(query){var pop=mnPopEl();var q=(query||'').toLowerCase();
  var list=(window.MEMBERS||[]).filter(function(m){return !q||m.name.toLowerCase().indexOf(q)>=0;});
  list.sort(function(a,b){var as=a.name.toLowerCase().indexOf(q)===0?0:1,bs=b.name.toLowerCase().indexOf(q)===0?0:1;return as-bs||a.name.localeCompare(b.name);});
  if(!list.length){pop.classList.remove('on');_mentionItems=[];return;}
  _mentionItems=list;_mentionSel=0;pop.innerHTML='';
  list.forEach(function(m,i){var b=document.createElement('button');b.type='button';b.className='mn-item'+(i===0?' sel':'');b.setAttribute('data-name',m.name);
    b.innerHTML='<span class="ma" style="background:'+m.color+'">'+esc(m.ini)+'</span>'+esc(m.name);
    b.addEventListener('mouseenter',function(){cmMentionHi(i);});
    b.addEventListener('mousedown',function(e){e.preventDefault();insMention(m.name);});
    pop.appendChild(b);});
  document.querySelectorAll('.pop').forEach(function(x){if(x!==pop)x.classList.remove('on');});
  pop.classList.add('on');mnPlaceAtCaret(pop);}
function cmMentionBtn(){var ed=document.getElementById('cmBody');ed.focus();_mentionEd=ed;cmInsertText('@');cmMentionShow('');}
var _cb=document.getElementById('cmBody');
if(_cb)_cb.addEventListener('input',function(e){_mentionEd=_cb;var ctx=cmMentionCtx();if(ctx){cmMentionShow(ctx.query);}else{mnClose();}});

/* La descripción de la tarea usa EXACTAMENTE la misma @mención en vivo que los
   comentarios: al escribir «@» aparece el desplegable de miembros junto al cursor,
   con ↑/↓ Enter/Tab para elegir y Esc para cerrar. */
var _db=document.getElementById('descBody');
if(_db){
  _db.addEventListener('input',function(){_mentionEd=_db;var ctx=cmMentionCtx();if(ctx){cmMentionShow(ctx.query);}else{mnClose();}});
  _db.addEventListener('keydown',function(e){
    if(!cmMentionOpen())return;
    if(e.key==='ArrowDown'){e.preventDefault();cmMentionHi(Math.min(_mentionSel+1,_mentionItems.length-1));}
    else if(e.key==='ArrowUp'){e.preventDefault();cmMentionHi(Math.max(_mentionSel-1,0));}
    else if(e.key==='Enter'||e.key==='Tab'){if(_mentionItems.length){e.preventDefault();insMention(_mentionItems[_mentionSel].name);}}
    else if(e.key==='Escape'){e.preventDefault();mnClose();}
  });
  _db.addEventListener('blur',function(){setTimeout(mnClose,150);});
}
if(_cb)_cb.addEventListener('paste',cmPaste);
/* Al pegar se CONSERVA el formato inline (negrita/cursiva/subrayado); se descarta lo
   demás (colores, fuentes, tablas, imágenes de HTML) para no ensuciar el editor. */
function cmPaste(e){var dt=e.clipboardData||window.clipboardData;if(!dt)return;
  if(dt.files&&dt.files.length){e.preventDefault();cmHandleFiles(dt.files);return;}
  var html=dt.getData('text/html');
  if(html&&html.trim()){var clean=sanitizePaste(html);if(clean){e.preventDefault();document.execCommand('insertHTML',false,clean);return;}}
  e.preventDefault();var t=dt.getData('text/plain');if(document.execCommand){document.execCommand('insertText',false,t);}else{cmInsertText(t);}}
/* Limpia HTML pegado de cualquier sitio (web, Word, Google Docs, ClickUp, Notion…)
   conservando la ESTRUCTURA: encabezados, listas, cita, tabla, código y formato en
   línea (negrita/cursiva/subrayado/tachado/enlaces). Se descarta lo peligroso o ruidoso
   (scripts, estilos, colores, fuentes). Así se puede pegar una tabla y queda una tabla. */
function sanitizePaste(html){
  var doc=new DOMParser().parseFromString(html,'text/html');
  function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
  function url(n){var h=(n.getAttribute&&n.getAttribute('href'))||'';return /^https?:\/\//i.test(h)?h:'';}
  function cleanTable(t){var rows=t.querySelectorAll('tr');if(!rows.length)return '';var h='<table class="rt-table">';var open='';
    rows.forEach(function(tr,ri){var cells=tr.querySelectorAll('th,td');if(!cells.length)return;var tc=(ri===0?'th':'td');var r='<tr>';
      cells.forEach(function(c){var inn=walk(c).replace(/<br>/g,' ').trim();r+='<'+tc+'>'+(inn||'&nbsp;')+'</'+tc+'>';});r+='</tr>';
      if(ri===0){h+='<thead>'+r+'</thead>';open='<tbody>';}else{if(open){h+=open;open='';}h+=r;}});
    return h+(open===''?'</tbody>':'')+'</table>';}
  function walk(node){var out='';var kids=node.childNodes;for(var i=0;i<kids.length;i++){var n=kids[i];
    if(n.nodeType===3){out+=esc(n.nodeValue);continue;}
    if(n.nodeType!==1)continue;
    var tag=n.nodeName.toLowerCase();
    if(tag==='br'){out+='<br>';continue;}
    if(tag==='style'||tag==='script'||tag==='head'||tag==='noscript'||tag==='img'||tag==='svg'||tag==='input'||tag==='button')continue;
    if(tag==='pre'){out+='<pre class="rt-pre"><code>'+esc(n.textContent||'')+'</code></pre>';continue;}
    if(tag==='code'||tag==='kbd'||tag==='samp'||tag==='tt'){out+='<code>'+walk(n)+'</code>';continue;}
    if(tag==='hr'){out+='<hr>';continue;}
    if(tag==='a'){var u=url(n);var ia=walk(n)||esc(n.textContent||'');out+=(u?('<a href="'+esc(u)+'">'+ia+'</a>'):ia);continue;}
    if(/^h[1-6]$/.test(tag)){var lv=Math.min(3,parseInt(tag.charAt(1),10));out+='<h'+lv+'>'+walk(n)+'</h'+lv+'>';continue;}
    if(tag==='ul'||tag==='ol'){var items='';for(var j=0;j<n.children.length;j++){var li=n.children[j];if(li.nodeName.toLowerCase()==='li')items+='<li>'+walk(li).replace(/<br>\s*$/,'')+'</li>';}if(items)out+='<'+tag+' class="rt-'+tag+'">'+items+'</'+tag+'>';continue;}
    if(tag==='blockquote'){out+='<blockquote class="rt-quote">'+walk(n)+'</blockquote>';continue;}
    if(tag==='table'){out+=cleanTable(n);continue;}
    if(tag==='thead'||tag==='tbody'||tag==='tr'||tag==='td'||tag==='th'){out+=walk(n);continue;}
    var st=(n.getAttribute&&n.getAttribute('style'))||'';
    var bold=(tag==='b'||tag==='strong'||/font-weight\s*:\s*(bold|[6-9]00)/i.test(st));
    var ital=(tag==='i'||tag==='em'||/font-style\s*:\s*italic/i.test(st));
    var und=(tag==='u'||/text-decoration[^;]*underline/i.test(st));
    var stk=(tag==='s'||tag==='strike'||tag==='del'||/text-decoration[^;]*line-through/i.test(st));
    var inner=walk(n);
    if(bold)inner='<b>'+inner+'</b>';if(ital)inner='<i>'+inner+'</i>';if(und)inner='<u>'+inner+'</u>';if(stk)inner='<s>'+inner+'</s>';
    if(tag==='p'||tag==='div'||tag==='section'||tag==='article'){out+='<div>'+inner+'</div>';}
    else out+=inner;}
    return out;}
  return walk(doc.body);}
if(_cb)_cb.addEventListener('keydown',function(e){
  if(cmMentionOpen()){
    if(e.key==='ArrowDown'){e.preventDefault();cmMentionHi(Math.min(_mentionSel+1,_mentionItems.length-1));return;}
    if(e.key==='ArrowUp'){e.preventDefault();cmMentionHi(Math.max(_mentionSel-1,0));return;}
    if(e.key==='Enter'||e.key==='Tab'){if(_mentionItems.length){e.preventDefault();insMention(_mentionItems[_mentionSel].name);return;}}
    if(e.key==='Escape'){e.preventDefault();mnClose();return;}
  }
  if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();var f=document.getElementById('cmForm');if(f){if(f.requestSubmit)f.requestSubmit();else if(cmSerialize())f.submit();}}
});
function cmDrop(e){e.preventDefault();e.stopPropagation();document.getElementById('cmForm').classList.remove('drag');if(e.dataTransfer&&e.dataTransfer.files&&e.dataTransfer.files.length){cmHandleFiles(e.dataTransfer.files);}}
function cmHandleFiles(list){for(var i=0;i<list.length;i++){var f=list[i];if((f.type||'').indexOf('image')===0)cmInsertImage(f);else cmAddOther(f);}}
function cmInsertImage(file){var ed=document.getElementById('cmBody');ed.focus();
  var fig=document.createElement('span');fig.className='cm-ii';fig.setAttribute('contenteditable','false');fig._file=file;
  var img=document.createElement('img');img.src=URL.createObjectURL(file);fig.appendChild(img);
  var rm=document.createElement('button');rm.type='button';rm.className='cm-ii-rm';rm.textContent='✕';rm.onclick=function(ev){ev.preventDefault();ev.stopPropagation();if(fig.parentNode)fig.parentNode.removeChild(fig);};fig.appendChild(rm);
  var sel=window.getSelection();var r;
  if(sel&&sel.rangeCount&&ed.contains(sel.anchorNode)){r=sel.getRangeAt(0);r.deleteContents();}else{r=document.createRange();r.selectNodeContents(ed);r.collapse(false);}
  r.insertNode(fig);
  var sp=document.createTextNode(' ');r.setStartAfter(fig);r.insertNode(sp);r.setStartAfter(sp);r.collapse(true);sel.removeAllRanges();sel.addRange(r);}
window._cmOther=[];
function cmAddOther(file){window._cmOther.push(file);cmRenderOther();}
function cmRemoveOther(idx){window._cmOther.splice(idx,1);cmRenderOther();}
function cmRenderOther(){var box=document.getElementById('cmOther');if(!box)return;box.innerHTML='';window._cmOther.forEach(function(f,i){var chip=document.createElement('span');chip.className='cm-otherchip';chip.appendChild(document.createTextNode(f.name+' '));var x=document.createElement('button');x.type='button';x.textContent='✕';x.onclick=function(){cmRemoveOther(i);};chip.appendChild(x);box.appendChild(chip);});}
function cmSerialize(){var ed=document.getElementById('cmBody');var files=[];var out='';
  (function walk(node){var kids=node.childNodes;for(var i=0;i<kids.length;i++){var n=kids[i];if(n.nodeType===3){out+=n.nodeValue;}else if(n.nodeType===1){var tag=n.nodeName.toLowerCase();if(n.classList&&n.classList.contains('ap-e')){out+=(n.getAttribute('data-e')||'');}else if(tag==='br'){out+='\n';}else if(tag==='h1'||tag==='h2'||tag==='h3'){var _hp={h1:'# ',h2:'## ',h3:'### '}[tag];var _hs=out;out='';walk(n);var _hi=out.replace(/\s+/g,' ').trim();out=_hs;out+='\n'+_hp+_hi+'\n';}else if(tag==='ul'||tag==='ol'){var _lo=(tag==='ol'),_lk=1;out+='\n';for(var _lc=0;_lc<n.children.length;_lc++){var _li=n.children[_lc];if(_li.nodeName.toLowerCase()!=='li')continue;var _ls=out;out='';walk(_li);var _lin=out.replace(/\s+/g,' ').trim();out=_ls;out+=(_lo?(_lk++)+'. ':'- ')+_lin+'\n';}}else if(tag==='blockquote'){var _qs=out;out='';walk(n);var _qi=out.replace(/^\n+|\n+$/g,'');out=_qs;out+='\n'+_qi.split('\n').map(function(l){return '> '+l;}).join('\n')+'\n';}else if(tag==='pre'){out+='\n```\n'+rtText(n).replace(/```/g,'')+'\n```\n';}else if(tag==='hr'){out+='\n---\n';}else if(tag==='table'){out+='\n';var _trs=n.querySelectorAll('tr');for(var _ri=0;_ri<_trs.length;_ri++){var _cc=_trs[_ri].querySelectorAll('th,td'),_cs=[];for(var _ci=0;_ci<_cc.length;_ci++){var _ts=out;out='';walk(_cc[_ci]);_cs.push(out.replace(/\s+/g,' ').replace(/\|/g,'/').trim());out=_ts;}if(_cs.length){out+='| '+_cs.join(' | ')+' |\n';if(_ri===0){out+='|'+_cs.map(function(){return' --- ';}).join('|')+'|\n';}}}out+='\n';}else if(tag==='s'||tag==='strike'||tag==='del'){out+='~~';walk(n);out+='~~';}else if(n.classList&&n.classList.contains('mention')){out+='@'+(n.getAttribute('data-mention')||rtText(n).replace(/^@/,''));}else if(n.classList&&n.classList.contains('cm-ii')){if(n._file){files.push(n._file);out+='\n[[img]]\n';}}else if(tag==='img'&&n._file){files.push(n._file);out+='\n[[img]]\n';}else if(tag==='code'){out+='`'+rtText(n).replace(/`/g,'')+'`';}else if(tag==='a'&&n.getAttribute('href')){var _h=n.getAttribute('href')||'';var _t=n.textContent||'';out+=(/^https?:/i.test(_h)?(_t===_h?_h:'['+_t+']('+_h+')'):_t);}else if(tag==='b'||tag==='strong'){out+='**';walk(n);out+='**';}else if(tag==='u'){out+='__';walk(n);out+='__';}else if(tag==='i'||tag==='em'){out+='*';walk(n);out+='*';}else if(tag==='div'||tag==='p'){out+='\n';walk(n);}else{var st=n.style||{};var wt=st.fontWeight;var bold=(wt==='bold'||wt==='bolder'||(parseInt(wt,10)>=600));var td=(st.textDecoration||'')+' '+(st.textDecorationLine||'');var und=td.indexOf('underline')>-1;var ital=(st.fontStyle==='italic');var strike=td.indexOf('line-through')>-1;if(bold)out+='**';if(und)out+='__';if(ital)out+='*';if(strike)out+='~~';walk(n);if(strike)out+='~~';if(ital)out+='*';if(und)out+='__';if(bold)out+='**';}}}})(ed);
  window._cmOther.forEach(function(f){files.push(f);});
  out=out.replace(/ /g,' ').replace(/[ \t]+\n/g,'\n').replace(/\n{3,}/g,'\n\n').replace(/^\s+|\s+$/g,'');
  document.getElementById('cmBodyHidden').value=out;
  var dt=new DataTransfer();for(var i=0;i<files.length;i++)dt.items.add(files[i]);document.getElementById('cmFiles').files=dt.files;
  cmChkSerialize();
  if(out===''&&files.length===0&&!document.getElementById('cmChkData').value){return false;}
  return true;}
/* Negrita/cursiva/subrayado en el editor de comentarios. styleWithCSS=false para que genere
   etiquetas <b>/<i>/<u> limpias (que cmSerialize convierte a marcadores). También funciona con
   Ctrl+B/I/U y al pegar desde Word/Docs/web (se conserva el formato, se descarta el resto). */
function cmFmt(cmd){var ed=document.getElementById('cmBody');if(!ed)return;ed.focus();try{document.execCommand('styleWithCSS',false,false);}catch(e){}try{document.execCommand(cmd,false,null);}catch(e){}}
var _cf=document.getElementById('cmFiles');if(_cf)_cf.addEventListener('change',function(){if(this.files&&this.files.length){cmHandleFiles(this.files);this.value='';}});
document.addEventListener('click',function(e){if(!e.target.closest('.cbar')&&!e.target.closest('.pop'))document.querySelectorAll('.pop').forEach(function(x){x.classList.remove('on');});if(!e.target.closest('#tkPop')&&!e.target.closest('#asgPick')){var tp=document.getElementById('tkPop');if(tp)tp.classList.remove('on');}if(!e.target.closest('#reactPop')&&!e.target.closest('.raddbtn')){var rp=document.getElementById('reactPop');if(rp)rp.classList.remove('on');}if(!e.target.closest('#commentCtx')){var cc=document.getElementById('commentCtx');if(cc)cc.classList.remove('on');}});
<?php if(can_edit()): ?>
(function(){var ov=document.getElementById('dropOverlay');var ah=document.getElementById('actDropHint');var act=document.getElementById('act');var cnt=0;
  function hasFiles(e){return e.dataTransfer&&Array.prototype.indexOf.call(e.dataTransfer.types||[],'Files')>-1;}
  function overAct(e){if(!act)return false;var r=act.getBoundingClientRect();return e.clientX>=r.left&&e.clientX<=r.right&&e.clientY>=r.top&&e.clientY<=r.bottom;}
  window.addEventListener('dragenter',function(e){if(!hasFiles(e))return;cnt++;});
  window.addEventListener('dragover',function(e){if(!hasFiles(e))return;e.preventDefault();if(overAct(e)){ov.classList.remove('on');if(ah)ah.classList.add('on');}else{if(ah)ah.classList.remove('on');ov.classList.add('on');}});
  window.addEventListener('dragleave',function(){cnt--;if(cnt<=0){cnt=0;ov.classList.remove('on');if(ah)ah.classList.remove('on');}});
  window.addEventListener('drop',function(e){cnt=0;ov.classList.remove('on');if(ah)ah.classList.remove('on');if(!hasFiles(e))return;e.preventDefault();
    if(overAct(e)){cmHandleFiles(e.dataTransfer.files);document.getElementById('cmBody').focus();}
    else{document.getElementById('attInput').files=e.dataTransfer.files;document.getElementById('attForm').submit();}});
})();
<?php endif; ?>

/* ============ Comentarios en vivo (sin recargar) + scroll al último ============ */
function cmFeedEl(){return document.querySelector('.act .feed');}
function cmScrollBottom(){var f=cmFeedEl();if(f)f.scrollTop=f.scrollHeight;}
function cmAtBottom(){var f=cmFeedEl();return !f||(f.scrollHeight-f.scrollTop-f.clientHeight<70);}
/* Sustituye el contenido del feed por el de una respuesta HTML del servidor. Los onclick
   de cada comentario son atributos en línea, así que siguen funcionando tras el reemplazo. */
function cmApplyFeed(html,toBottom){
  var doc=new DOMParser().parseFromString(html,'text/html');
  var fresh=doc.querySelector('.act .feed'),cur=cmFeedEl();
  if(!fresh||!cur)return;
  cur.innerHTML=fresh.innerHTML;
  var fh=doc.querySelector('.act h3'),ch=document.querySelector('.act h3');if(fh&&ch)ch.innerHTML=fh.innerHTML;
  if(toBottom)cmScrollBottom();
}
function cmResetComposer(){
  var ed=document.getElementById('cmBody');if(ed)ed.innerHTML='';
  var h=document.getElementById('cmBodyHidden');if(h)h.value='';
  var cd=document.getElementById('cmChkData');if(cd)cd.value='';
  var cc=document.getElementById('cmChkCompose');if(cc)cc.innerHTML='';
  window._cmOther=[];cmRenderOther();
  var cf=document.getElementById('cmFiles');if(cf)cf.value='';
  cmReplyCancel();
}
<?php if(can_edit()): ?>
function cmSubmit(e){
  if(e&&e.preventDefault)e.preventDefault();
  if(!cmSerialize())return false;              /* rellena los campos ocultos y valida que no esté vacío */
  var form=document.getElementById('cmForm');var fd=new FormData(form);
  var url=location.pathname+location.search;
  fetch(url,{method:'POST',body:fd}).then(function(r){return r.text();}).then(function(html){
    cmApplyFeed(html,true);cmResetComposer();
    var ed=document.getElementById('cmBody');if(ed)ed.focus();
  }).catch(function(){form.submit();});         /* si algo falla, envío normal como respaldo */
  return false;
}
<?php endif; ?>
/* Sondeo en directo: si aparecen comentarios nuevos (de otra pestaña o de un compañero)
   se añaden solos. Solo baja el scroll si ya estabas al final, para no molestar. */
function cmPoll(){
  var url=location.pathname+location.search;
  fetch(url,{headers:{'X-Requested-With':'fetch'}}).then(function(r){return r.text();}).then(function(html){
    var doc=new DOMParser().parseFromString(html,'text/html');
    var fresh=doc.querySelector('.act .feed');if(!fresh)return;
    var fa=fresh.querySelectorAll('.cm'),ca=document.querySelectorAll('.act .feed .cm');
    var fl=fa[fa.length-1],cl=ca[ca.length-1];
    var fid=fl?fl.getAttribute('data-cid'):'0',cid=cl?cl.getAttribute('data-cid'):'0';
    if(fa.length!==ca.length||fid!==cid){cmApplyFeed(html,cmAtBottom());}
  }).catch(function(){});
}
cmScrollBottom();
setTimeout(cmScrollBottom,250);   /* de nuevo tras cargar imágenes que cambian la altura */
setInterval(cmPoll,5000);

/* Llegada desde una notificación: la url trae #c<idComentario>. En vez de dejar
   al usuario en la tarea genérica, se baja hasta ESE comentario y se resalta en
   azul. Va después del scroll al fondo para ganarle la posición final. */
(function(){
  var m=(location.hash||'').match(/^#c(\d+)$/); if(!m) return;
  var cid=m[1], scrolled=false, fin=Date.now()+2500;
  /* Se SONDEA durante 2,5 s reponiendo el resaltado: el panel de actividad se
     re-renderiza al arrancar (poll, imágenes que cambian la altura…) y si solo se
     pusiera una vez, ese re-render se lo llevaría. Después se retira suave. */
  function tick(){
    var el=document.querySelector('.cm[data-cid="'+cid+'"]');
    if(el){
      if(!scrolled){ el.scrollIntoView({block:'center',behavior:'smooth'}); scrolled=true; }
      if(!el.classList.contains('cm-hl-out') && !el.classList.contains('cm-hl')) el.classList.add('cm-hl');
    }
    if(Date.now()<fin){ setTimeout(tick,150); }
    else if(el){ setTimeout(function(){ el.classList.add('cm-hl-out'); setTimeout(function(){ el.classList.remove('cm-hl','cm-hl-out'); },600); }, 1400); }
  }
  setTimeout(tick,250);
})();
</script>
<?php erp_foot(); ?>
