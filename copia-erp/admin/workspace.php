<?php
/* Centro de trabajo (Kanban & Workspaces) dentro del armazón único del ERP. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
/* Borrar en el ERP no es definitivo: pasa por la papelera y se puede deshacer. */
require_once __DIR__ . '/lib/papelera.php';

$me = current_admin(); $meId = (int)$me['id'];
/* Título fijo de la entrada especial «Informe del mes» (el texto narrativo que
   el equipo escribe y el cliente ve en Portal › Informes). Ver save_informe_mes. */
const INFORME_MES_TITULO = 'Informe del mes';
$ESTADOS = ['pendiente'=>['En espera','#64748b'], 'en proceso'=>['En proceso','#2563eb'], 'atemporal'=>['Atemporal','#a16207'], 'completada'=>['Completada','#0f7a3d']];
$PRIOS = [0=>['Ninguna','#94a3b8'],1=>['Baja','#64748b'],2=>['Normal','#2563eb'],3=>['Alta','#b45309'],4=>['Urgente','#b91c1c']];

/* Marcador circular de estado: completada = relleno verde + check, en proceso = reloj azul (~2,5 cuartos),
   atemporal = anillo ámbar punteado, pendiente = anillo gris.
   El azul es el mismo #3b82f6 que $ESTADOS da a «En proceso»: antes el circulito
   se dibujaba morado y la etiqueta de al lado azul, en la misma fila. */
function estado_circle($ek){
    if($ek==='completada') return '<svg width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#12a150"/><path d="M7.4 12.4l3 3 6.2-6.7" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    if($ek==='en proceso') return '<svg width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="#3b82f6" stroke-width="2"/><path d="M12 12 L12 3 A9 9 0 1 1 5.64 18.36 Z" fill="#3b82f6"/></svg>';
    if($ek==='atemporal') return '<svg width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="#e0a000" stroke-width="2.4" stroke-dasharray="3.2 3.2"/></svg>';
    return '<svg width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="#b0b4bb" stroke-width="2.4"/></svg>';
}

/* publicar_progreso() y publicar_informes() viven ahora en lib/publicar_lib.php:
   son el único sitio del proyecto que decide qué ve el cliente en su portal. */
require_once __DIR__ . '/lib/publicar_lib.php';

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can_edit()) {
    $a = $_POST['action'] ?? '';
    $cli = (int)($_POST['cli'] ?? 0);
    if ($a === 'reorder_lists') {
        $order = $_POST['order'] ?? [];
        if (is_array($order) && $cli) { foreach ($order as $i=>$lid) { db()->prepare('UPDATE task_lists SET orden=? WHERE id=? AND client_id=?')->execute([(int)$i,(int)$lid,$cli]); } }
        header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
    }
    /* Las tareas ya se guardaban con un número de orden y la consulta las pedía
       «ORDER BY orden, id», pero no había ninguna forma de cambiar ese número: el
       orden lo decidía la fecha de creación y punto. Las listas del menú y las
       carpetas de cliente sí se arrastraban desde hacía tiempo. */
    if ($a === 'reorder_tasks') {
        $order = $_POST['order'] ?? [];
        if (is_array($order) && $cli) {
            $up = db()->prepare('UPDATE tasks SET orden=? WHERE id=? AND client_id=?');
            foreach ($order as $i=>$tid) { $up->execute([(int)$i, (int)$tid, $cli]); }
        }
        header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
    }
    if ($a === 'reorder_clients') {
        $order = $_POST['order'] ?? [];
        if (is_array($order)) { foreach ($order as $i=>$cidv) { db()->prepare('UPDATE clients SET orden=? WHERE id=?')->execute([(int)$i,(int)$cidv]); } }
        header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
    }
    if ($a === 'toggle_activo') {
        if ($cli) { try { db()->prepare('UPDATE clients SET activo=1-COALESCE(activo,1) WHERE id=?')->execute([$cli]); } catch(Exception $e){} }
        header('Location: workspace.php?view=all'); exit;
    }
    if ($a === 'inline_task') {
        $tid=(int)($_POST['id']??0); $field=$_POST['field']??''; $val=(string)($_POST['val']??'');
        if ($tid && in_array($field,['responsable_id','due_date','prioridad'],true)) {
            if ($field==='responsable_id') $store=($val!==''?(int)$val:null);
            elseif ($field==='prioridad') $store=(int)$val;
            else $store=($val!==''?$val:null);
            $oldResp = ($field==='responsable_id') ? (int)db()->query('SELECT responsable_id FROM tasks WHERE id='.$tid)->fetchColumn() : 0;
            db()->prepare("UPDATE tasks SET `$field`=? WHERE id=?")->execute([$store,$tid]);
            if ($field==='responsable_id') task_set_asignados($tid, $store?[(int)$store]:[]); // sincroniza el puente de asignados múltiples
            $ci=(int)db()->query('SELECT client_id FROM tasks WHERE id='.$tid)->fetchColumn();
            if ($ci) publicar_progreso($ci);
            if ($field==='responsable_id' && $store && (int)$store!==$oldResp) notif_task_assigned($tid,(int)$store,$me['username']);
        }
        header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
    }
    if ($a === 'set_asignados') { // asignar a varios desde la lista
        $tid=(int)($_POST['id']??0);
        $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['ids']??[])))));
        if($tid){ $antes=task_asignados($tid); $ahora=task_set_asignados($tid,$ids);
          $ci=(int)db()->query('SELECT client_id FROM tasks WHERE id='.$tid)->fetchColumn(); if($ci) publicar_progreso($ci);
          foreach(array_diff($ahora,$antes) as $nid){ notif_task_assigned($tid,(int)$nid,$me['username']); } }
        header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
    }
    if ($a === 'add_list') {
        $n = trim($_POST['nombre'] ?? ''); $ec = isset($_POST['es_cliente']) ? 1 : 0;
        $tp = ($_POST['tipo'] ?? '')==='informe' ? 'informe' : 'tareas';
        if ($n !== '' && $cli) db()->prepare('INSERT INTO task_lists (client_id, nombre, es_cliente, tipo) VALUES (?,?,?,?)')->execute([$cli, $n, $ec, $tp]);
    } elseif ($a === 'default_lists' && $cli) {
        db()->prepare('INSERT INTO task_lists (client_id, nombre, es_cliente, tipo, orden) VALUES (?,?,?,?,?)')->execute([$cli,'TAREAS',0,'tareas',0]);
        db()->prepare('INSERT INTO task_lists (client_id, nombre, es_cliente, tipo, orden) VALUES (?,?,?,?,?)')->execute([$cli,'ESTRATEGIA',0,'tareas',1]);
        db()->prepare('INSERT INTO task_lists (client_id, nombre, es_cliente, tipo, orden) VALUES (?,?,?,?,?)')->execute([$cli,'TAREA CLIENTE',0,'tareas',2]);
        db()->prepare('INSERT INTO task_lists (client_id, nombre, es_cliente, tipo, orden) VALUES (?,?,?,?,?)')->execute([$cli,'INFORMES CLIENTE',0,'informe',3]);
    } elseif ($a === 'toggle_list_cliente') {
        $lid=(int)($_POST['list_id']??0);
        db()->prepare('UPDATE task_lists SET es_cliente = 1-es_cliente WHERE id=? AND client_id=?')->execute([$lid,$cli]);
        publicar_progreso($cli);
    } elseif ($a === 'del_list') {
        $lid = (int)($_POST['list_id'] ?? 0);
        /* Una lista se lleva sus tareas por delante: se guardan las dos cosas
           juntas para que «Deshacer» devuelva la lista con su contenido. */
        $ln = db()->prepare('SELECT nombre FROM task_lists WHERE id=? AND client_id=?'); $ln->execute([$lid,$cli]);
        $lNom = (string)($ln->fetchColumn() ?: '');
        /* Antes de llevarse las tareas de la lista, se limpian los hijos de cada
           tarea (comentarios, checklist, adjuntos, reacciones) para que no queden
           filas huérfanas (P1-01). */
        $tidsQ = db()->prepare('SELECT id FROM tasks WHERE list_id=? AND client_id=?'); $tidsQ->execute([$lid,$cli]);
        pap_borrar_hijos_tareas($tidsQ->fetchAll(PDO::FETCH_COLUMN));
        if ($lNom!=='' || $lid) pap_borrar_flash('task_lists', $lid, 'lista', $lNom, [['tabla'=>'tasks','fk'=>'list_id']], 'Lista «'.$lNom.'» eliminada');
        db()->prepare('DELETE FROM tasks WHERE list_id=? AND client_id=?')->execute([$lid, $cli]);
        db()->prepare('DELETE FROM task_lists WHERE id=? AND client_id=?')->execute([$lid, $cli]);
        publicar_progreso($cli);
    } elseif ($a === 'dup_list') {
        $lid = (int)($_POST['list_id'] ?? 0);
        $src = db()->prepare('SELECT * FROM task_lists WHERE id=? AND client_id=?'); $src->execute([$lid,$cli]); $L=$src->fetch();
        if ($L) {
            $mo=(int)db()->query('SELECT COALESCE(MAX(orden),0)+1 FROM task_lists WHERE client_id='.$cli)->fetchColumn();
            db()->prepare('INSERT INTO task_lists (client_id,nombre,es_cliente,tipo,orden) VALUES (?,?,?,?,?)')->execute([$cli,$L['nombre'].' (copia)',$L['es_cliente'],$L['tipo']??'tareas',$mo]);
            $newId=(int)db()->lastInsertId();
            $ts=db()->prepare('SELECT * FROM tasks WHERE list_id=? AND client_id=? ORDER BY orden,id'); $ts->execute([$lid,$cli]);
            foreach ($ts as $tk) {
                db()->prepare('INSERT INTO tasks (client_id,list_id,titulo,descripcion,estado,responsable_id,prioridad,fecha_inicio,due_date,etiquetas,visible_cliente,titulo_cliente,explicacion_cliente,mes,orden) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                  ->execute([$cli,$newId,$tk['titulo'],$tk['descripcion'],$tk['estado'],$tk['responsable_id'],$tk['prioridad'],$tk['fecha_inicio'],$tk['due_date'],$tk['etiquetas'],$tk['visible_cliente'],$tk['titulo_cliente'],$tk['explicacion_cliente'],$tk['mes'],$tk['orden']]);
            }
            publicar_progreso($cli);
        }
    } elseif ($a === 'rename_list') {
        $lid=(int)($_POST['list_id']??0); $nn=trim($_POST['nombre']??'');
        if ($nn!=='') db()->prepare('UPDATE task_lists SET nombre=? WHERE id=? AND client_id=?')->execute([$nn,$lid,$cli]);
    } elseif ($a === 'save_task') {
        $id = (int)($_POST['id'] ?? 0);
        $fields = [
            'list_id'=>(int)($_POST['list_id'] ?? 0),
            'titulo'=>trim($_POST['titulo'] ?? ''),
            'descripcion'=>trim($_POST['descripcion'] ?? ''),
            'estado'=>array_key_exists($_POST['estado'] ?? '', $ESTADOS) ? $_POST['estado'] : 'pendiente',
            'responsable_id'=>($_POST['responsable_id'] ?? '')!=='' ? (int)$_POST['responsable_id'] : null,
            'prioridad'=>(int)($_POST['prioridad'] ?? 0),
            'fecha_inicio'=>($_POST['fecha_inicio'] ?? '')!=='' ? $_POST['fecha_inicio'] : null,
            'due_date'=>($_POST['due_date'] ?? '')!=='' ? $_POST['due_date'] : null,
            'etiquetas'=>trim($_POST['etiquetas'] ?? ''),
            'visible_cliente'=>isset($_POST['visible_cliente']) ? 1 : 0,
            'titulo_cliente'=>trim($_POST['titulo_cliente'] ?? ''),
            'explicacion_cliente'=>trim($_POST['explicacion_cliente'] ?? ''),
            'mes'=>trim($_POST['mes'] ?? ''),
        ];
        if ($fields['titulo'] !== '' && $fields['list_id'] && $cli) {
            $newResp = $fields['responsable_id'] ? (int)$fields['responsable_id'] : 0;
            if ($id) {
                $oldResp = (int)db()->query('SELECT responsable_id FROM tasks WHERE id='.$id.' AND client_id='.$cli)->fetchColumn();
                $set = implode(', ', array_map(fn($k)=>"$k=:$k", array_keys($fields)));
                $params = $fields; $params['id']=$id; $params['cli']=$cli;
                db()->prepare("UPDATE tasks SET $set WHERE id=:id AND client_id=:cli")->execute($params);
                if ($newResp && $newResp!==$oldResp) notif_task_assigned($id,$newResp,$me['username']);
            } else {
                $fields['client_id']=$cli;
                $cols = implode(',', array_keys($fields));
                $ph = implode(',', array_map(fn($k)=>":$k", array_keys($fields)));
                db()->prepare("INSERT INTO tasks ($cols) VALUES ($ph)")->execute($fields);
                $id=(int)db()->lastInsertId();
                if ($newResp) notif_task_assigned($id,$newResp,$me['username']);
            }
            publicar_progreso($cli);
            if (($_POST['ajax'] ?? '')==='1') { header('Content-Type: application/json'); echo json_encode(['ok'=>1,'id'=>$id]); exit; }
        } elseif (($_POST['ajax'] ?? '')==='1') { header('Content-Type: application/json'); echo json_encode(['ok'=>0,'msg'=>'Falta el título o la lista.']); exit; }
    } elseif ($a === 'quick_estado') {
        $id=(int)($_POST['id']??0); $eN=$_POST['estado']??'pendiente';
        if (array_key_exists($eN,$ESTADOS)) { db()->prepare('UPDATE tasks SET estado=? WHERE id=? AND client_id=?')->execute([$eN,$id,$cli]); publicar_progreso($cli); }
    } elseif ($a === 'del_task') {
        $tId=(int)($_POST['id']??0);
        $tn = db()->prepare('SELECT titulo FROM tasks WHERE id=? AND client_id=?'); $tn->execute([$tId,$cli]);
        $tTit = (string)($tn->fetchColumn() ?: '');
        if ($tTit!=='') {
            /* Las reacciones cuelgan del comentario, no de la tarea: se sueltan
               antes (no se restauran). Los comentarios, la checklist y los
               adjuntos sí se fotografían para que «Deshacer» devuelva la tarea
               entera (P1-01). */
            try { db()->exec("DELETE r FROM task_comment_reactions r JOIN task_comments c ON c.id=r.comment_id WHERE c.task_id=".$tId); } catch (Exception $e) { error_log('del_task reactions: '.$e->getMessage()); }
            pap_borrar_flash('tasks', $tId, 'tarea', $tTit, [['tabla'=>'task_comments','fk'=>'task_id'],['tabla'=>'task_checklist','fk'=>'task_id'],['tabla'=>'task_attachments','fk'=>'task_id']], 'Tarea «'.$tTit.'» eliminada');
        }
        db()->prepare('DELETE FROM tasks WHERE id=? AND client_id=?')->execute([$tId, $cli]); publicar_progreso($cli);
    } elseif ($a === 'save_informe_mes') {
        /* Guarda el texto narrativo del «Informe del mes» como una entrada especial
           de la lista informe (título fijo). publicar_informes() ya la vuelca a
           informes_json, así que sale en Portal › Informes sin tocar nada más. */
        $listId=(int)($_POST['list_id']??0); $mes=trim($_POST['mes']??''); $texto=(string)($_POST['texto']??'');
        if (mb_strlen($texto)>60000) $texto=mb_substr($texto,0,60000);
        if ($listId && $mes!=='') {
            $q=db()->prepare('SELECT id FROM tasks WHERE client_id=? AND list_id=? AND mes=? AND titulo=? LIMIT 1');
            $q->execute([$cli,$listId,$mes,INFORME_MES_TITULO]); $eid=(int)$q->fetchColumn();
            if ($eid) {
                db()->prepare('UPDATE tasks SET explicacion_cliente=?, descripcion=? WHERE id=? AND client_id=?')->execute([$texto,$texto,$eid,$cli]);
            } else {
                db()->prepare("INSERT INTO tasks (client_id,list_id,titulo,titulo_cliente,explicacion_cliente,descripcion,estado,prioridad,mes,visible_cliente,orden) VALUES (?,?,?,?,?,?,'atemporal',0,?,0,0)")
                    ->execute([$cli,$listId,INFORME_MES_TITULO,INFORME_MES_TITULO,$texto,$texto,$mes]);
            }
            publicar_progreso($cli);
        }
    } elseif ($a === 'publicar') {
        publicar_progreso($cli); $flash='Progreso publicado en el portal del cliente.';
    }
    if ($a !== 'publicar') { header('Location: workspace.php?'.($_POST['ret'] ?? '')); exit; }
}

$clientsAll = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();
$responsables = db()->query('SELECT id, username FROM admins ORDER BY username')->fetchAll();
$respMap=[]; foreach($responsables as $r) $respMap[$r['id']]=$r['username'];

$view = $_GET['view'] ?? 'all';
$cli  = isset($_GET['cli']) ? (int)$_GET['cli'] : 0;
if ($cli) $view = 'cliente';
$fEstado = $_GET['fe'] ?? ''; $fResp = $_GET['fr'] ?? '';
$emp = isset($_GET['emp']) ? (int)$_GET['emp'] : 0;
$curClient=null; $lists=[]; $curList=0; $porEstado=[]; $rows=[];

if ($view==='cliente' && $cli) {
    $st=db()->prepare('SELECT * FROM clients WHERE id=?'); $st->execute([$cli]); $curClient=$st->fetch();
    if(!$curClient){ header('Location: workspace.php?view=all'); exit; }
    $ls=db()->prepare('SELECT * FROM task_lists WHERE client_id=? ORDER BY orden,id'); $ls->execute([$cli]); $lists=$ls->fetchAll();
    $curList = isset($_GET['list'])?(int)$_GET['list']:(count($lists)?(int)$lists[0]['id']:0);
    /* Acceso directo «Escribir informe del mes» (desde la ficha del cliente): abre
       ya la lista de informe. Si el cliente AÚN NO la tiene, se crea sola (así el
       botón funciona en TODOS los clientes, no solo en los que la crearon a mano). */
    if (isset($_GET['informe'])) {
        $infId=0; foreach($lists as $ll){ if(($ll['tipo']??'')==='informe'){ $infId=(int)$ll['id']; break; } }
        if (!$infId && can_edit()) {
            db()->prepare("INSERT INTO task_lists (client_id,nombre,es_cliente,tipo,orden) VALUES (?, 'INFORMES CLIENTE', 0, 'informe', ?)")->execute([$cli, count($lists)]);
            $infId=(int)db()->lastInsertId();
            $ls->execute([$cli]); $lists=$ls->fetchAll();
        }
        if ($infId) $curList=$infId;
    }
    $porEstado=['pendiente'=>[],'en proceso'=>[],'atemporal'=>[],'completada'=>[]];
    $mesGroups=[]; $curListTipo='tareas';
    foreach($lists as $ll){ if($ll['id']==$curList) $curListTipo=$ll['tipo']??'tareas'; }
    if($curList){ $t=db()->prepare('SELECT * FROM tasks WHERE list_id=? AND client_id=? ORDER BY orden,id'); $t->execute([$curList,$cli]);
        foreach($t as $r){ $e=$r['estado']; if(!isset($porEstado[$e]))$e='pendiente'; $porEstado[$e][]=$r;
            $mm = trim((string)$r['mes'])!==''?trim($r['mes']):'Sin mes'; $mesGroups[$mm][]=$r; } }
} else {
    $w=[]; $p=[];
    if($view==='mine'){ $w[]='t.responsable_id=?'; $p[]=$meId; }
    if($view==='emp' && $emp){ $w[]='t.responsable_id=?'; $p[]=$emp; }
    if($fEstado!==''){ $w[]='t.estado=?'; $p[]=$fEstado; }
    if($fResp!==''){ $w[]='t.responsable_id=?'; $p[]=(int)$fResp; }
    /* Las bandejas (Todas / Mis tareas / Tareas de un empleado) son de trabajo:
       por defecto ocultan las completadas en TODAS ellas. Si filtras explícitamente
       por un estado en el desplegable, se respeta ese filtro (incluida «Completada»). */
    if($fEstado===''){ $w[]="t.estado<>'completada'"; }
    $where=$w?('WHERE '.implode(' AND ',$w)):'';
    /* Alcance: quien no tenga «Ve todos los clientes» solo ve las tareas que
       tiene asignadas, como en ClickUp. Se añade al WHERE ya montado. */
    $alc = function_exists('alcance_sql_tareas') ? alcance_sql_tareas('t') : '';
    if ($alc !== '') $where = ($where === '' ? 'WHERE 1' : $where) . $alc;
    $sql="SELECT t.*, c.name AS clientname, l.nombre AS listname FROM tasks t JOIN clients c ON c.id=t.client_id JOIN task_lists l ON l.id=t.list_id $where ORDER BY c.name, FIELD(t.estado,'en proceso','pendiente','atemporal','completada'), t.id";
    $stt=db()->prepare($sql); $stt->execute($p); $rows=$stt->fetchAll();
}
/* Asignados múltiples: carga en bloque los responsables de las tareas visibles (item: varios asignados). */
$asgMap=[];
if(!empty($rows)){ task_asignados_ensure();
  $ids=array_values(array_unique(array_map(fn($r)=>(int)$r['id'],$rows)));
  if($ids){ $in=implode(',',array_fill(0,count($ids),'?'));
    try{ $qa=db()->prepare("SELECT task_id,admin_id FROM task_assignees WHERE task_id IN ($in) ORDER BY orden,admin_id"); $qa->execute($ids);
      foreach($qa as $ra){ $asgMap[(int)$ra['task_id']][]=(int)$ra['admin_id']; } }catch(Exception $e){}
  }
}
function ini2($s){ return e(mb_strtoupper(mb_substr((string)$s,0,2))); }
/* Asignados múltiples de una fila: ids válidos (con fallback a responsable_id) y su render apilado. */
function ws_asg_ids($t,$asgMap,$respMap){ $ids=$asgMap[(int)$t['id']]??[]; if(!$ids && !empty($t['responsable_id'])) $ids=[(int)$t['responsable_id']]; return array_values(array_filter($ids,fn($i)=>isset($respMap[$i]))); }
function ws_asg_avs($ids,$respMap){ $shown=array_slice($ids,0,3); $extra=count($ids)-count($shown); $h='<div class="asg-stack">'; foreach($shown as $i){ $h.='<div class="av-mini" title="'.e($respMap[$i]).'" style="background:'.avatar_color($respMap[$i]).'">'.ini2($respMap[$i]).'</div>'; } if($extra>0) $h.='<div class="av-mini av-extra" title="+'.$extra.' más">+'.$extra.'</div>'; return $h.'</div>'; }
$retNow = http_build_query(array_filter(['view'=>$view,'cli'=>$cli?:null,'list'=>$curList?:null,'fe'=>$fEstado?:null,'fr'=>$fResp?:null,'emp'=>$emp?:null]));

erp_head('kanban', 'Tareas');
?>
<style>
.ws-head{display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.ws-tabs{display:flex;gap:4px;background:#fff;border:1px solid var(--line);border-radius:11px;padding:4px}
.ws-tabs a{padding:7px 14px;border-radius:8px;font-size:13.5px;font-weight:600;color:#6b7280;display:inline-flex;gap:7px;align-items:center}
.ws-tabs a svg{width:15px;height:15px}
.ws-tabs a.on{background:#111318;color:#fff}
.ws-empwrap{position:relative}
.ws-emptab{padding:7px 12px;border-radius:8px;font-size:13.5px;font-weight:600;color:#6b7280;display:inline-flex;gap:7px;align-items:center;border:none;background:none;cursor:pointer;font-family:inherit}
.ws-emptab svg{width:15px;height:15px}
.ws-emptab.on{background:#111318;color:#fff}
.ws-emptab .et-av,.ws-empmenu .et-av,.hd-av{width:20px;height:20px;border-radius:50%;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:8.5px;font-weight:700;flex:none}
.hd-av{width:26px;height:26px;font-size:10px}
.ws-empmenu{position:absolute;top:calc(100% + 6px);left:0;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 46px rgba(0,0,0,.16);padding:5px;min-width:210px;max-height:320px;overflow:auto;z-index:160;display:none;animation:pop .15s ease}
.ws-empmenu.on{display:block}
.ws-empmenu a{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:8px;font-size:13px;color:#4c515b;font-weight:500}
.ws-empmenu a:hover{background:var(--soft);color:var(--ink)}
.ws-empmenu a.on{background:var(--accent-soft);color:var(--accent)}
.ws-cli{margin-left:auto;border:1px solid var(--line);border-radius:9px;padding:8px 11px;font-size:13px;background:#fff;max-width:230px;color:var(--ink)}
.tl-tab{background:none;border:none;border-bottom:2px solid transparent;padding:8px 12px;font-weight:600;font-size:13px;color:var(--muted);cursor:pointer;margin-bottom:-1px}
.tl-tab:hover{color:var(--ink)}
.tl-tab.on{color:var(--ink);border-bottom-color:var(--accent)}
.tl-tab .cli-tag{font-size:9px;background:var(--accent-soft);color:var(--accent);border-radius:5px;padding:1px 6px;margin-left:6px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
.trow{display:flex;align-items:center;gap:12px;background:transparent;border:none;border-bottom:1px solid var(--line2);border-radius:0;padding:15px 6px;margin:0;transition:.1s}
.trow:hover{background:#f7f7f9}
.trow .tt{flex:1;min-width:0}
.trow .tt b{font-size:13.5px;font-weight:600;display:block;cursor:pointer}
.trow .tt b:hover{color:var(--accent)}
.trow .tt .meta{font-size:11.5px;color:var(--muted);margin-top:3px;display:flex;gap:9px;flex-wrap:wrap;align-items:center}
.prio{font-size:11px;font-weight:600;padding:2px 8px;border-radius:6px;color:var(--muted);background:var(--soft);display:inline-flex;align-items:center;gap:5px}
.prio .gd{width:6px;height:6px;border-radius:2px;flex:none}
.pill-est{font-size:10.5px;font-weight:700;padding:4px 10px;border-radius:6px;color:var(--ink);background:var(--soft);display:inline-flex;align-items:center;gap:6px}
.pill-est .gd{width:7px;height:7px;border-radius:2px;flex:none}
.av-mini{width:27px;height:27px;border-radius:50%;background:#22242a;color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;flex:none}
.av-mini.av-none{background:#eef0f2;color:var(--label)}
.asg-stack{display:inline-flex;align-items:center}.asg-stack .av-mini{border:2px solid #fff}.asg-stack .av-mini+.av-mini{margin-left:-11px}
.av-mini.av-extra{background:#c8ccd2;color:#3c4149;font-size:9.5px}
.av-mini.av-none svg{width:14px;height:14px}
.vis-badge{font-size:9.5px;font-weight:600;color:var(--muted);background:#f2f3f5;border-radius:5px;padding:2px 6px;letter-spacing:.2px;text-transform:uppercase}
/* .mini-sel, .icon-btn y .tl-tabs viven ahora en erp_nav.php: los usan también
   Soporte y el propio menú lateral, y aquí solo estaban de prestado. */
.addtask{display:flex;gap:8px;margin:6px 0 4px}
.addtask input{flex:1;border:1px solid var(--line);border-radius:9px;padding:9px 12px;font-size:13.5px}
.grp{background:#fff;border:1px solid var(--line);border-radius:14px;margin-bottom:20px;overflow:hidden}
.grp-h{font-weight:600;font-size:13px;padding:15px 18px;display:flex;align-items:center;gap:10px;color:var(--ink);background:#fbfbfc;border-bottom:1px solid var(--line2);cursor:pointer;user-select:none}
.grp-h:hover{background:#f4f5f7}
.grp-h .cd{width:24px;height:24px;border-radius:7px;color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:700}
/* Plegado por cliente en «Todas las tareas»: chevron, contador y ocultado. */
.grp-cv{display:inline-flex;color:var(--muted);transition:transform .15s ease}
.grp.collapsed .grp-cv{transform:rotate(-90deg)}
.grp.collapsed .grp-body{display:none}
.grp.collapsed .grp-h{border-bottom:none}
.grp-nm{flex:0 1 auto}
.grp-n{margin-left:auto;font-size:11.5px;color:var(--muted);background:var(--soft);border-radius:99px;padding:1px 9px;font-weight:600}
.grp-body{padding:0}
.grp-body .ck-row:last-child{border-bottom:none}
.grp-body .ck-colh{border-top:1px solid var(--line2)}
.ck-listtag{font-size:11px;color:var(--label);background:var(--soft);border-radius:6px;padding:1px 7px;font-weight:600;white-space:nowrap;flex:none}
/* modal estilo ClickUp */
.tm-ov{position:fixed;inset:0;background:rgba(15,18,25,.4);display:none;align-items:flex-start;justify-content:center;z-index:100;padding:40px 18px;overflow:auto}
.tm-ov.on{display:flex}
.tm{background:#fff;border-radius:16px;max-width:680px;width:100%;padding:26px 30px;box-shadow:0 30px 80px rgba(0,0,0,.25)}
.tm .tmt{width:100%;border:none;font-size:23px;font-weight:650;padding:2px 0 12px;outline:none;color:var(--ink)}
.tm-fields{display:grid;grid-template-columns:1fr 1fr;gap:4px 30px;border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:12px 0;margin-bottom:6px}
.tmf{display:flex;align-items:center;gap:10px;padding:7px 0}
.tmf .lbl{width:120px;flex:none;color:var(--muted);font-size:12.5px;display:flex;align-items:center;gap:8px}
.tmf .lbl svg{width:15px;height:15px;color:var(--label)}
.tmf select,.tmf input{border:1px solid transparent;background:transparent;border-radius:8px;padding:6px 8px;font-size:13.5px;width:100%;font-family:inherit}
.tmf select:hover,.tmf input:hover{background:#f6f7f9}
.tmf select:focus,.tmf input:focus{background:#fff;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft);outline:none}
.tm-desc{margin-top:12px}
.tm-desc textarea{width:100%;border:1px solid var(--line);border-radius:12px;padding:12px 14px;font-size:14px;min-height:90px;font-family:inherit}
.cli-box{background:#f7f9ff;border:1px solid #dbe6fb;border-radius:14px;padding:14px 16px;margin-top:14px}
.cli-box .chk{display:flex;align-items:center;gap:8px;font-size:13.5px;font-weight:600}.cli-box .chk input{width:auto}
.cli-box label{margin:10px 0 5px}
/* tabla estilo ClickUp (vista de lista por estado) */
.ck-grp{margin-bottom:26px}
.ck-gh{display:flex;align-items:center;gap:9px;padding:5px 4px 8px;cursor:pointer;user-select:none}
.ck-gh .gchev{color:var(--label);transition:.15s;display:flex}
.ck-grp.closed .gchev{transform:rotate(-90deg)}
.ck-grp.closed .ck-body{display:none}
.ck-gh .gpill{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--ink);background:var(--soft);border-radius:7px;padding:4px 11px;display:inline-flex;align-items:center;gap:7px}
.ck-gh .gpill .gd{width:8px;height:8px;border-radius:3px;flex:none}
.ck-gh .gn{font-size:12.5px;color:var(--muted);font-weight:650}
.ck-body{border:1px solid var(--line);border-radius:12px;overflow:hidden;background:#fff}
.ck-colh,.ck-row{display:grid;grid-template-columns:1fr 168px 132px 118px 84px;gap:10px;align-items:center;padding:12px 16px;border-bottom:1px solid var(--line)}
.ck-colh{font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:650;background:#fbfbfc}
.ck-row{cursor:pointer}
.ck-row:hover{background:#fafbfc}
.ck-row .nm{display:flex;align-items:center;gap:10px;min-width:0}
.ck-row .nm .st{flex:none;display:flex}
.ck-row .nm b{font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ck-row .nm b:hover{color:var(--accent)}
.ck-row .asig{display:flex;align-items:center;gap:8px;min-width:0}
.ck-row .asig .nn{font-size:12.5px;color:#4c515b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ck-row .cell{font-size:12.5px;color:#6b7280}
.ck-row .cell.mut{color:var(--label)}
.ck-row .acts{display:flex;align-items:center;gap:5px;justify-content:flex-end}
.ck-mini-sel{border:1px solid transparent;border-radius:7px;padding:5px 6px;font-size:12px;background:transparent;color:var(--ink);cursor:pointer;max-width:120px}
.ck-mini-sel:hover{background:#eef0f3}
.ck-x{border:none;background:none;color:var(--label);cursor:pointer;font-size:14px;padding:3px 5px;border-radius:6px;line-height:1;display:inline-flex;align-items:center}
.ck-x:hover{background:#fde8e8;color:#c0392b}
.flagp{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;color:var(--ink)}
.flagp .fdot{width:9px;height:9px;border-radius:2px;flex:none}
.ck-add{display:flex;align-items:center;gap:9px;padding:10px 14px;color:var(--label)}
.ck-add .plus{display:flex;color:var(--label);flex:none}
.ck-add input{border:none;background:transparent;font-size:13.5px;flex:1;outline:none;padding:2px 0;color:var(--ink)}
.ck-add input::placeholder{color:var(--label)}
.ck-add input:focus{box-shadow:none!important;background:transparent;border:none}
.quickadd input:focus{box-shadow:none!important}
.ck-add button{border:none;background:var(--accent);color:#fff;border-radius:8px;padding:6px 13px;font-size:12.5px;font-weight:600;cursor:pointer;transition:transform .16s ease,box-shadow .18s ease}
.ck-add button:hover{transform:translateY(-1px);box-shadow:0 5px 13px rgba(0,0,0,.15)}
.ck-add:focus-within .plus{color:var(--ink)}
.ck-addbtn{width:100%;border:none;background:none;cursor:pointer;text-align:left;font-family:inherit;color:var(--label);font-size:13.5px;transition:background-color .14s ease,color .14s ease}
.ck-addbtn:hover{background:#fafbfc;color:var(--ink)}
/* celdas editables en la fila */
.ck-row .nm{cursor:pointer}
.ws-cell{cursor:pointer;border-radius:8px;transition:background .12s ease;min-height:30px;display:flex;align-items:center;padding:0 4px;margin:-3px 0}
.ws-cell:hover{background:#eef0f3}
.ws-cell .cell.mut{color:var(--label)}
.ws-date{border:none;background:transparent;font-size:12.5px;color:#6b7280;font-family:inherit;cursor:pointer;padding:5px 4px;border-radius:7px;width:100%}
.ws-date:hover{background:#eef0f3}
.ws-date:focus{outline:none;background:#fff;box-shadow:inset 0 0 0 2px var(--accent)}
#wsPop{position:fixed;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 46px rgba(0,0,0,.17);padding:5px;min-width:212px;max-height:300px;overflow:auto;z-index:150;display:none;animation:pop .15s ease}
#wsPop.on{display:block}
#wsPop .wsp-item{display:flex;align-items:center;gap:10px;width:100%;border:none;background:none;cursor:pointer;padding:8px 10px;border-radius:8px;font-size:13px;color:#4c515b;font-weight:500;text-align:left;font-family:inherit}
#wsPop .wsp-item:hover{background:var(--soft);color:var(--ink)}
#wsPop .wsp-av{width:22px;height:22px;border-radius:50%;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:600;flex:none}
#wsPop .wsp-ck{color:var(--accent);font-weight:700;width:16px;text-align:center;flex:none;font-size:13px}
#wsPop .wsp-dot{width:11px;height:11px;border-radius:3px;flex:none;display:inline-block}
#wsPop .wsp-mut{color:var(--muted)}
#wsPop .wsp-st{display:inline-flex;align-items:center;flex:none}
/* círculo de estado clicable + etiqueta */
.ck-row .nm .st{flex:none;display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;cursor:pointer;transition:background .12s ease;padding:0;margin:-3px 0}
.ck-row .nm .st:hover{background:#eef0f3}
.st-tag{font-size:10.5px;font-weight:700;letter-spacing:.02em;padding:2px 8px;border-radius:20px;flex:none;text-transform:uppercase}
.st-tag.done{background:#e4f6ec;color:#12854a}
/* input de fecha estilo texto */
.ws-date-txt{border:none;background:transparent;font-size:12.5px;color:#6b7280;font-family:inherit;cursor:pointer;padding:5px 6px;border-radius:7px;width:100%;outline:none}
.ws-date-txt::placeholder{color:var(--label)}
.ws-date-txt:hover{background:#eef0f3}
.ws-date-txt:focus{background:#fff;box-shadow:inset 0 0 0 2px var(--accent);color:var(--ink)}
/* Celdas editables de las filas compactas de «Todas las tareas» / «Mis tareas».
   No se reutiliza .ws-cell porque impone alto mínimo y relleno propios: sobre un
   pill o dentro de la línea de datos de la tarea deformaba la fila entera. Aquí
   basta con marcar que se puede pulsar y encender el fondo al pasar por encima. */
.ws-pick{cursor:pointer}
.pill-est.ws-pick:hover{background:#e7e9ed}
.prio.ws-pick:hover{background:#e7e9ed;color:var(--ink)}
.prio .pmut{color:var(--label);font-weight:400}
.tro-asig{display:flex;align-items:center;gap:7px;padding:2px;border-radius:9px;flex:none}
.tro-asig.ws-pick:hover{background:#eef0f3}
.tro-asig .nn{display:none}
.tro-date{display:inline-flex;align-items:center;gap:4px;width:92px;flex:none}
.tro-date svg{flex:none}
.tro-date .ws-date-txt{font-size:11.5px;padding:2px 4px}
/* calendario moderno */
#wsCal{position:fixed;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 20px 50px rgba(0,0,0,.18);padding:12px;width:252px;z-index:170;display:none;animation:pop .15s ease}
#wsCal.on{display:block}
#wsCal .cal-h{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
#wsCal .cal-h span{font-size:13px;font-weight:700;color:var(--ink)}
#wsCal .cal-nav{border:none;background:none;cursor:pointer;color:#6b7280;font-size:18px;line-height:1;width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center}
#wsCal .cal-nav:hover{background:var(--soft);color:var(--ink)}
#wsCal .cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
#wsCal .cal-dow span{font-size:10px;font-weight:700;color:var(--label);text-align:center;padding:2px 0}
#wsCal .cal-d{border:none;background:none;cursor:pointer;font-size:12.5px;color:var(--ink);height:30px;border-radius:8px;font-family:inherit;transition:background .12s ease}
#wsCal .cal-d:hover{background:var(--soft)}
#wsCal .cal-d.today{color:var(--accent);font-weight:700}
#wsCal .cal-d.sel{background:var(--accent);color:#fff;font-weight:600}
#wsCal .cal-foot{display:flex;justify-content:space-between;margin-top:9px;padding-top:9px;border-top:1px solid var(--line)}
#wsCal .cal-clr,#wsCal .cal-tod{border:none;background:none;cursor:pointer;font-size:12px;font-weight:600;color:#6b7280;padding:5px 9px;border-radius:8px;font-family:inherit}
#wsCal .cal-clr:hover{background:#fde8e8;color:#c0392b}
#wsCal .cal-tod:hover{background:var(--accent-soft);color:var(--accent)}
.quickadd{display:flex;align-items:center;gap:11px;border:1px solid var(--line);border-radius:12px;padding:11px 15px;margin-bottom:18px;background:#fff;transition:border-color .16s ease,box-shadow .18s ease}
.quickadd:focus-within{border-color:#c7c7ca;box-shadow:0 0 0 3px rgba(17,19,24,.05)}
.quickadd .qa-plus{color:var(--label);display:flex;flex:none}
.quickadd input{flex:1;border:none;outline:none;font-size:14px;background:transparent;color:var(--ink)}
.quickadd input::placeholder{color:var(--label)}
.quickadd button{border:none;background:var(--accent);color:#fff;border-radius:9px;padding:8px 16px;font-weight:600;font-size:13px;cursor:pointer;transition:transform .16s ease,box-shadow .18s ease;flex:none}
.quickadd button:hover{transform:translateY(-1px);box-shadow:0 6px 15px rgba(0,0,0,.16)}
.quickadd button:active{transform:translateY(0) scale(.98)}
/* botones de texto minimal arriba */
.ws-topact{display:flex;align-items:center;gap:16px;margin-left:auto}
.txtbtn{background:none;border:none;color:var(--muted);font-size:12.5px;font-weight:600;cursor:pointer;padding:4px 2px;display:inline-flex;gap:6px;align-items:center;font-family:inherit}
.txtbtn svg{width:14px;height:14px}
.txtbtn:hover{color:var(--ink-strong);text-decoration:underline;text-underline-offset:3px}
/* botón secundario nueva lista */
.addlist{background:none;border:none;color:var(--muted);font-size:12.5px;font-weight:600;cursor:pointer;padding:8px 10px;display:inline-flex;gap:6px;align-items:center;border-radius:8px;font-family:inherit;margin-bottom:-1px}
.addlist:hover{background:var(--soft);color:var(--ink)}
.newlist{display:none;gap:10px;align-items:center;background:var(--soft);border-radius:11px;padding:10px 12px;margin-bottom:14px}
.newlist input[name=nombre]{border:1px solid var(--line);border-radius:8px;padding:8px 11px;width:220px;font-size:13.5px;background:#fff}
.newlist label{display:flex;gap:6px;align-items:center;font-size:12.5px;color:var(--muted);margin:0}
.newlist label input{width:auto}
.newlist select{border:1px solid var(--line);border-radius:8px;padding:8px 10px;font-size:13px;background:#fff;font-family:inherit}
/* menú crear (Lista / Informe) */
.addlist-wrap{position:relative}
.lmenu{position:absolute;top:calc(100% + 5px);left:0;background:#fff;border:1px solid var(--line);border-radius:13px;box-shadow:0 18px 44px rgba(0,0,0,.15);padding:6px;min-width:278px;z-index:60;display:none;animation:pop .16s ease}
.lmenu.on{display:block}
.lmenu-h{font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:650;padding:8px 10px 5px}
.lmi{display:flex;align-items:flex-start;gap:12px;width:100%;border:none;background:none;cursor:pointer;padding:10px;border-radius:10px;text-align:left;font-family:inherit}
.lmi:hover{background:var(--soft)}
.lmi .li{flex:none;display:flex;margin-top:1px}.lmi .li svg{width:18px;height:18px}
.lmi b{font-size:13.5px;font-weight:600;display:block;color:var(--ink)}
.lmi small{font-size:11.5px;color:var(--muted);display:block;margin-top:2px;line-height:1.35}
.lmenu-form{display:none;gap:6px;padding:8px 8px 4px;border-top:1px solid var(--line);margin-top:4px}
.lmenu-form.on{display:flex}
.lmenu-form input{flex:1;min-width:0;border:1px solid var(--line);border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit}
/* selector de meses (listas tipo informe) */
.mes-tabs{display:flex;gap:2px;flex-wrap:wrap;align-items:center;border-bottom:1px solid var(--line);margin-bottom:14px}
.mes-tab{display:inline-flex;align-items:center;gap:6px;padding:8px 13px;border-bottom:2px solid transparent;margin-bottom:-1px;font-size:13px;font-weight:600;color:var(--muted)}
.mes-tab svg{width:13px;height:13px;color:var(--label)}
.mes-tab:hover{color:var(--ink)}
.mes-tab.on{color:var(--ink)}.mes-tab.on{border-bottom-color:var(--accent)}
.mes-tab .mc{font-size:10px;background:var(--soft);color:var(--label);border-radius:99px;padding:0 6px;font-weight:700}
/* modal crear tarea — overlay con desenfoque y fundido de entrada Y salida */
.tm-ov{position:fixed;inset:0;background:rgba(17,19,24,.34);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);display:flex;align-items:flex-start;justify-content:center;z-index:120;padding:56px 18px;overflow:auto;opacity:0;visibility:hidden;transition:opacity .18s ease,visibility .18s ease}
.tm-ov.on{opacity:1;visibility:visible}
.tm{background:#fff;border-radius:16px;max-width:640px;width:100%;box-shadow:0 30px 90px rgba(0,0,0,.28);overflow:hidden;display:flex;flex-direction:column;max-height:calc(100vh - 90px);transform:translateY(8px) scale(.99);transition:transform .2s cubic-bezier(.33,1,.68,1)}
.tm-ov.on .tm{transform:none}
.tm form{display:flex;flex-direction:column;min-height:0;flex:1}
.tm .tm-body{padding:22px 24px 4px;overflow:auto;flex:1}
.tm .tm-list{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--muted);background:var(--soft);border-radius:7px;padding:5px 10px;margin-bottom:14px}
.tm .tmt{width:100%;border:none;font-size:23px;font-weight:650;letter-spacing:-.4px;padding:2px 0;outline:none;color:var(--ink-strong);background:transparent}
.tm .tmd{width:100%;border:none;outline:none;font-size:14px;font-family:inherit;resize:none;min-height:52px;padding:6px 0 8px;color:var(--ink);background:transparent;line-height:1.6}
.tmg{display:grid;grid-template-columns:1fr 1fr;gap:0 34px;border-top:1px solid var(--line);padding:8px 0 4px;margin-top:6px}
.tmr{display:flex;align-items:center;gap:10px;padding:6px 0}
.tmr .tl{width:110px;flex:none;color:var(--muted);font-size:12.5px;display:flex;align-items:center;gap:8px;font-weight:500}
.tmr .tl svg{width:15px;height:15px;color:var(--label)}
.tmr select,.tmr input{flex:1;min-width:0;border:1px solid transparent;background:transparent;border-radius:8px;padding:6px 8px;font-size:13.5px;font-family:inherit;color:var(--ink);cursor:pointer}
.tmr select:hover,.tmr input:hover{background:#f4f5f7}
.tmr select:focus,.tmr input:focus{background:#fff;border-color:var(--label);box-shadow:0 0 0 3px rgba(17,19,24,.06);outline:none;cursor:text}
.tm-cli{border-top:1px solid var(--line);margin-top:8px;padding-top:14px}
.tm-cli .chk{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:600;color:var(--ink)}.tm-cli .chk input{width:auto}
.tm-cli .g2{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px}
.tm-cli label{display:block;font-size:12px;color:var(--muted);font-weight:600;margin:0 0 5px}
.tm-cli input{width:100%;border:1px solid var(--line);border-radius:9px;padding:8px 11px;font-size:13px;font-family:inherit}
.tm-foot{display:flex;align-items:center;justify-content:flex-end;gap:10px;border-top:1px solid var(--line);padding:14px 24px;background:#fcfcfd;flex:none}
/* ---- Modo oscuro: remapea las superficies y textos propios ---- */
[data-theme=dark] .ws-tabs{background-color:var(--card)}
[data-theme=dark] .ws-tabs a,[data-theme=dark] .ws-emptab{color:var(--muted)}
[data-theme=dark] .ws-tabs a.on,[data-theme=dark] .ws-emptab.on{background-color:var(--rev);color:var(--rev-fg)}
[data-theme=dark] .ws-empmenu,[data-theme=dark] #wsPop,[data-theme=dark] #wsCal,[data-theme=dark] .lmenu{background-color:var(--pop)}
[data-theme=dark] .ws-empmenu a,[data-theme=dark] #wsPop .wsp-item,[data-theme=dark] .lmi b{color:var(--ink)}
[data-theme=dark] .ws-cli,[data-theme=dark] .newlist input[name=nombre],[data-theme=dark] .newlist select{background-color:var(--field)}
[data-theme=dark] .trow:hover,[data-theme=dark] .grp-h,[data-theme=dark] .grp-h:hover,[data-theme=dark] .ck-colh,[data-theme=dark] .ck-row:hover,[data-theme=dark] .ck-mini-sel:hover,[data-theme=dark] .ck-addbtn:hover,[data-theme=dark] .ws-cell:hover,[data-theme=dark] .ws-date:hover,[data-theme=dark] .ws-date-txt:hover,[data-theme=dark] .pill-est.ws-pick:hover,[data-theme=dark] .prio.ws-pick:hover,[data-theme=dark] .tro-asig.ws-pick:hover,[data-theme=dark] .ck-row .nm .st:hover,[data-theme=dark] .tmf select:hover,[data-theme=dark] .tmf input:hover,[data-theme=dark] .tmr select:hover,[data-theme=dark] .tmr input:hover,[data-theme=dark] .cli-box,[data-theme=dark] .vis-badge,[data-theme=dark] .tm-foot{background-color:var(--soft)}
[data-theme=dark] .grp,[data-theme=dark] .quickadd,[data-theme=dark] .tm,[data-theme=dark] .ck-body{background-color:var(--card)}
[data-theme=dark] .grp-h{color:var(--ink)}
[data-theme=dark] .cli-box{border-color:var(--line)}
[data-theme=dark] .ck-listtag,[data-theme=dark] .ck-row .cell,[data-theme=dark] .ck-add,[data-theme=dark] .ck-add .plus,[data-theme=dark] .ck-add input::placeholder,[data-theme=dark] .ck-addbtn,[data-theme=dark] .ck-x,[data-theme=dark] .ws-date,[data-theme=dark] .ws-date-txt,[data-theme=dark] .ws-date-txt::placeholder,[data-theme=dark] .quickadd input::placeholder,[data-theme=dark] .quickadd .qa-plus,[data-theme=dark] #wsCal .cal-nav,[data-theme=dark] #wsCal .cal-dow span,[data-theme=dark] #wsCal .cal-clr,[data-theme=dark] #wsCal .cal-tod{color:var(--muted)}
[data-theme=dark] .ck-row .asig .nn{color:var(--ink)}
[data-theme=dark] .ck-addbtn:hover,[data-theme=dark] .prio.ws-pick:hover{color:var(--ink)}
[data-theme=dark] .tmf select:focus,[data-theme=dark] .tmf input:focus,[data-theme=dark] .tmr select:focus,[data-theme=dark] .tmr input:focus,[data-theme=dark] .ws-date:focus,[data-theme=dark] .ws-date-txt:focus{background-color:var(--field)}
[data-theme=dark] .ws-date-txt:focus{color:var(--ink)}
[data-theme=dark] .av-mini.av-none{background-color:var(--soft);color:var(--muted)}
[data-theme=dark] .av-mini.av-extra{background-color:var(--soft);color:var(--ink)}
[data-theme=dark] .asg-stack .av-mini{border-color:var(--card)}
[data-theme=dark] .st-tag.done{background-color:var(--ok-bg);color:var(--ok)}
[data-theme=dark] .ck-x:hover,[data-theme=dark] #wsCal .cal-clr:hover{background-color:var(--danger-bg);color:var(--danger)}
/* ====== MÓVIL (≤640px): filas de tarea apiladas tipo app, nada se solapa ====== */
@media(max-width:640px){
  /* Barra superior: pestañas que envuelven y selector de cliente a lo ancho */
  .ws-tabs{flex-wrap:wrap}
  .ws-topact{margin-left:0;width:100%;flex-wrap:wrap}
  .ws-cli{margin-left:0;max-width:100%;width:100%}
  /* En móvil no hay columnas: la cabecera de columnas sobra */
  .ck-colh{display:none!important}
  /* Cada tarea = FILA COMPACTA y plana (2 líneas): título arriba (una línea con
     ellipsis) y metadatos pequeños y apagados debajo. Sin caja; toda la fila abre la tarea. */
  .ck-row{display:flex!important;flex-wrap:wrap;align-items:center;gap:3px 10px;padding:11px 14px}
  .ck-row .nm{flex:1 1 100%;min-width:0;gap:9px}
  .ck-row .nm b{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.3;font-size:14.5px}
  .ck-row .asig,.ck-row .ws-cell,.ck-row .fdate,.ck-row .cell{flex:0 0 auto;font-size:12px;color:var(--muted);margin:0;min-height:0}
  .ck-row .asig .nn{font-size:12px;color:var(--muted)}
  /* Fecha y celdas editables: TEXTO PLANO en móvil, sin caja de input */
  .ck-row .ws-cell,.ck-row .fdate,.ck-row .fdate .ws-date-txt,.ck-row .ws-cell input,.ck-row .ws-cell select{border:none!important;background:none!important;padding:0!important;min-height:0!important;min-width:0!important;width:auto!important;height:auto!important;box-shadow:none!important}
  /* En móvil la fila entera abre la tarea → los iconos de acción sobran */
  .ck-row .acts{display:none}
  .ck-listtag{max-width:120px;font-size:9.5px}
  /* .trow (legado) por si alguna vista lo usa */
  .trow{flex-wrap:wrap}
  .trow .tt{flex:1 1 100%}
  /* Modal de crear/editar tarea: una sola columna y casi pantalla completa */
  .tm-ov{padding:18px 10px}
  .tmg,.tm-fields,.tm-cli .g2{grid-template-columns:1fr}
  .tmr .tl,.tmf .lbl{width:96px}
  /* Formulario de nueva lista: que quepa sin desbordar */
  .newlist{flex-wrap:wrap}
  .newlist input[name=nombre]{width:100%}
  .lmenu{min-width:0;width:min(320px,calc(100vw - 28px))}
}
</style>

<div class="ws-head">
  <div class="ws-tabs">
    <a href="workspace.php?view=all" class="<?= $view==='all'?'on':'' ?>"><?= ic('inbox',15) ?> Todas las tareas</a>
    <a href="workspace.php?view=mine" class="<?= $view==='mine'?'on':'' ?>"><?= ic('usercheck',15) ?> Mis tareas</a>
    <div class="ws-empwrap">
      <button type="button" class="ws-emptab <?= $view==='emp'?'on':'' ?>" onclick="toggleEmpMenu(event)"><?php if($view==='emp' && isset($respMap[$emp])): ?><span class="et-av" style="background:<?= avatar_color($respMap[$emp]) ?>"><?= ini2($respMap[$emp]) ?></span><?= e($respMap[$emp]) ?><?php else: ?><?= ic('user',15) ?> Tareas de…<?php endif; ?> <?= ic('chevron',12) ?></button>
      <div class="ws-empmenu" id="empMenu"><?php foreach($responsables as $r): ?><a href="workspace.php?view=emp&emp=<?= (int)$r['id'] ?>" class="<?= ($view==='emp'&&$emp===(int)$r['id'])?'on':'' ?>"><span class="et-av" style="background:<?= avatar_color($r['username']) ?>"><?= ini2($r['username']) ?></span><?= e($r['username']) ?></a><?php endforeach; ?></div>
    </div>
  </div>
  <script>
  function toggleEmpMenu(e){e.stopPropagation();var m=document.getElementById('empMenu');if(m)m.classList.toggle('on');}
  document.addEventListener('click',function(e){if(!e.target.closest('.ws-empwrap')){var m=document.getElementById('empMenu');if(m)m.classList.remove('on');}});
  </script>
  <?php if ($view==='cliente' && $curClient): ?>
  <div class="ws-topact">
    <a class="txtbtn" href="../index.php?cli=<?= $cli ?>" target="_blank"><?= ic('eye',14) ?> Ver portal</a>
    <?php if (can_edit()): ?><form method="post" style="margin:0" onsubmit="return erpSubmitAsk(this,'¿Publicar ahora al Progreso del cliente?')"><input type="hidden" name="action" value="publicar"><input type="hidden" name="cli" value="<?= $cli ?>"><button class="txtbtn" type="submit"><?= ic('link',14) ?> Publicar al portal</button></form><?php endif; ?>
  </div>
  <?php endif; ?>
  <select class="ws-cli" aria-label="Filtrar por cliente" onchange="if(this.value)location.href='workspace.php?view=cliente&cli='+this.value">
    <option value="">Ir a un cliente…</option>
    <?php foreach ($clientsAll as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cli==$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
  </select>
</div>
<?php if ($flash): ?><div class="card" style="border:1px solid #cfe9d6;color:var(--ok)"><?= e($flash) ?></div><?php endif; ?>

<?php if ($view==='cliente' && $curClient): ?>
  <h1 style="font-size:20px;margin-bottom:14px"><?= e($curClient['name']) ?></h1>

  <?php if (!$lists): ?>
    <div class="card"><p class="muted">Sin listas todavía.</p>
    <?php if (can_edit()): ?><form method="post" style="margin-top:10px"><input type="hidden" name="action" value="default_lists"><input type="hidden" name="cli" value="<?= $cli ?>"><input type="hidden" name="ret" value="<?= e($retNow) ?>"><button class="btn" type="submit">Crear listas por defecto (Tareas · Estrategia · Tarea cliente · Informes)</button></form><?php endif; ?></div>
  <?php else: ?>
    <?php $hasInforme=false; foreach($lists as $l){ if(($l['tipo']??'')==='informe') $hasInforme=true; } ?>
    <div class="tl-tabs" data-cli="<?= $cli ?>">
      <?php foreach ($lists as $l): ?><a class="tl-tab list-drag <?= $l['id']==$curList?'on':'' ?>" draggable="true" data-lid="<?= (int)$l['id'] ?>" oncontextmenu="return listMenu(event,<?= $cli ?>,<?= (int)$l['id'] ?>,<?= htmlspecialchars(json_encode($l['nombre']), ENT_QUOTES) ?>)" href="workspace.php?view=cliente&cli=<?= $cli ?>&list=<?= (int)$l['id'] ?>"><?php if(($l['tipo']??'')==='informe'): ?><span style="display:inline-flex;vertical-align:-2px;color:#0ea5e9;margin-right:5px"><?= ic('inbox',13) ?></span><?php endif; ?><?= e($l['nombre']) ?></a><?php endforeach; ?>
      <?php if (can_edit()): ?>
        <div class="addlist-wrap">
          <button class="addlist" type="button" onclick="document.getElementById('lmenu').classList.toggle('on')"><?= ic('plus',14) ?> Añadir</button>
          <div class="lmenu" id="lmenu">
            <div class="lmenu-h">Crear en este cliente</div>
            <button type="button" class="lmi" onclick="pickType('tareas')"><span class="li" style="color:#7b68ee"><?= ic('list',18) ?></span><span><b>Lista</b><small>Seguimiento de tareas y elementos.</small></span></button>
            <?php if(!$hasInforme): ?><button type="button" class="lmi" onclick="pickType('informe')"><span class="li" style="color:#0ea5e9"><?= ic('inbox',18) ?></span><span><b>Informe de cliente</b><small>Entregables por meses · va al portal.</small></span></button><?php endif; ?>
            <form method="post" class="lmenu-form" id="lmenuForm"><input type="hidden" name="action" value="add_list"><input type="hidden" name="cli" value="<?= $cli ?>"><input type="hidden" name="ret" value="<?= e($retNow) ?>"><input type="hidden" name="tipo" id="nlTipo"><input name="nombre" id="nlName" placeholder="Nombre…" autocomplete="off"><button class="btn sm" type="submit">Crear</button></form>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php if (can_edit()): ?>
    <script>
    function pickType(tp){document.getElementById('nlTipo').value=tp;var f=document.getElementById('lmenuForm');f.classList.add('on');var n=document.getElementById('nlName');n.value=(tp==='informe'?'INFORMES CLIENTE':'');n.focus();}
    document.addEventListener('click',function(e){var m=document.getElementById('lmenu');if(m&&!e.target.closest('.addlist-wrap')){m.classList.remove('on');var f=document.getElementById('lmenuForm');if(f)f.classList.remove('on');}});
    </script>
    <?php endif; ?>

    <?php if ($curList): $thisList=null; foreach($lists as $l){ if($l['id']==$curList)$thisList=$l; } $isInforme=($thisList && ($thisList['tipo']??'')==='informe'); ?>
    <?php if (!$isInforme): ?>
    <div class="flex" style="margin-bottom:6px"><div class="sp muted"><?= array_sum(array_map('count',$porEstado)) ?> tarea(s)</div>
      <?php if (can_edit()): ?>
        <button type="button" class="btn sm" onclick="openCreate('pendiente')"><?= ic('plus',15) ?> Añadir tarea</button>
        <form method="post" style="margin:0" onsubmit="return erpSubmitAsk(this,'La lista y sus tareas van a la papelera; podrás deshacerlo.')"><input type="hidden" name="action" value="del_list"><input type="hidden" name="cli" value="<?= $cli ?>"><input type="hidden" name="list_id" value="<?= $curList ?>"><input type="hidden" name="ret" value="<?= e(http_build_query(['view'=>'cliente','cli'=>$cli])) ?>"><button class="btn danger sm" type="submit">Borrar lista</button></form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($curListTipo==='informe'):
      $meses=array_keys($mesGroups); $curMes=isset($_GET['mes'])?$_GET['mes']:(count($meses)?$meses[0]:'');
      /* Si la lista de informe está vacía (aún no hay ningún mes), por defecto el
         mes actual, para que el editor «Informe del mes» SIEMPRE aparezca. */
      if ($curMes==='') { $MESES_ES=[1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre']; $curMes=$MESES_ES[(int)date('n')].' '.date('Y'); }
      $mesAll=$mesGroups[$curMes]??[];
      $informeMes=null; foreach($mesAll as $t){ if(($t['titulo']??'')===INFORME_MES_TITULO){ $informeMes=$t; break; } }
      $entries=array_values(array_filter($mesAll, fn($t)=>($t['titulo']??'')!==INFORME_MES_TITULO));
      $infTexto=(string)($informeMes['explicacion_cliente'] ?? $informeMes['descripcion'] ?? ''); $infPub=trim($infTexto)!=='';
    ?>
      <style>
      .inf-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}
      .inf-months{display:flex;gap:8px;flex-wrap:wrap}
      .inf-mchip{border:1px solid var(--line);background:#fff;border-radius:99px;padding:7px 15px;font-size:13px;font-weight:600;color:var(--muted);text-decoration:none;transition:.14s ease}
      .inf-mchip:hover{background:var(--soft);color:var(--ink)}
      .inf-mchip.on{background:#111318;color:#fff;border-color:#111318}
      .inf-editor{background:linear-gradient(180deg,#fff,#fbfbfd);border:1px solid var(--line);border-radius:18px;padding:20px 22px;margin-bottom:22px;box-shadow:0 1px 2px rgba(16,19,24,.04),0 16px 34px -22px rgba(16,19,24,.22)}
      .inf-eh{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:15px}
      .inf-eh-l{display:flex;align-items:center;gap:12px;min-width:0}
      .inf-ic{width:42px;height:42px;border-radius:12px;background:var(--soft);display:flex;align-items:center;justify-content:center;font-size:20px;flex:none}
      .inf-eh-l b{font-size:17px;font-weight:600;color:var(--ink-strong);display:block;letter-spacing:-.2px}
      .inf-eh-l span{font-size:12.5px;color:var(--muted)}
      .inf-pill{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);background:var(--soft);border-radius:99px;padding:5px 12px;flex:none}
      .inf-pill.on{color:#12854a;background:#e4f6ec}
      .inf-ta{width:100%;min-height:250px;border:1px solid var(--line);border-radius:14px;padding:16px 18px;font-size:15px;font-family:inherit;line-height:1.75;color:var(--ink-strong);outline:none;resize:vertical;background:#fff;transition:border-color .15s,box-shadow .15s}
      .inf-ta:focus{border-color:var(--ink-strong);box-shadow:0 0 0 4px var(--accent-soft)}
      .inf-ta::placeholder{color:var(--label)}
      .inf-ef{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px;flex-wrap:wrap}
      .inf-hint{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--muted)}
      .inf-hint svg{width:14px;height:14px}
      .inf-tasks-h{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:650;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin:2px 2px 12px}
      .inf-tasks-h svg{width:15px;height:15px}
      .inf-count{background:var(--soft);color:var(--ink);border-radius:99px;padding:1px 9px;font-size:11.5px}
      /* ---- Modo oscuro ---- */
      [data-theme=dark] .inf-mchip{background-color:var(--card)}
      [data-theme=dark] .inf-mchip.on{background-color:var(--rev);color:var(--rev-fg);border-color:var(--rev)}
      [data-theme=dark] .inf-editor{background-image:none;background-color:var(--card)}
      [data-theme=dark] .inf-ta{background-color:var(--field);color:var(--ink-strong)}
      [data-theme=dark] .inf-ta::placeholder{color:var(--muted)}
      [data-theme=dark] .inf-pill.on{background-color:var(--ok-bg);color:var(--ok)}
      </style>
      <div class="inf-top">
        <div class="inf-months">
          <?php $shown=false; foreach($meses as $mm): if($mm===$curMes)$shown=true; ?><a class="inf-mchip <?= $mm===$curMes?'on':'' ?>" href="workspace.php?view=cliente&cli=<?= $cli ?>&list=<?= $curList ?>&mes=<?= rawurlencode($mm) ?>"><?= e(mes_label($mm)) ?></a><?php endforeach; ?>
          <?php if(!$shown): ?><span class="inf-mchip on"><?= e(mes_label($curMes)) ?></span><?php endif; ?>
        </div>
        <?php if (can_edit()): ?><form method="post" style="margin:0" onsubmit="return erpSubmitAsk(this,'La lista de informes y sus entradas van a la papelera; podrás deshacerlo.')"><input type="hidden" name="action" value="del_list"><input type="hidden" name="cli" value="<?= $cli ?>"><input type="hidden" name="list_id" value="<?= $curList ?>"><input type="hidden" name="ret" value="<?= e(http_build_query(['view'=>'cliente','cli'=>$cli])) ?>"><button class="btn ghost sm" type="submit" title="Borrar lista de informes"><?= ic('trash',14) ?></button></form><?php endif; ?>
      </div>

      <?php if (can_edit()): ?>
      <form method="post" class="inf-editor">
        <input type="hidden" name="action" value="save_informe_mes">
        <input type="hidden" name="cli" value="<?= $cli ?>">
        <input type="hidden" name="list_id" value="<?= $curList ?>">
        <input type="hidden" name="mes" value="<?= e($curMes) ?>">
        <input type="hidden" name="ret" value="<?= e($retNow) ?>">
        <div class="inf-eh">
          <div class="inf-eh-l"><span class="inf-ic">✍️</span><div style="min-width:0"><b>Informe de <?= e(mes_label($curMes)) ?></b><span>Lo que el cliente lee en su Portal › Informes</span></div></div>
          <span class="inf-pill <?= $infPub?'on':'' ?>"><?= $infPub?'Publicado':'Sin publicar' ?></span>
        </div>
        <textarea name="texto" class="inf-ta" placeholder="Cuéntale al cliente cómo ha ido el mes: qué hemos hecho, los resultados que ha traído y qué viene el mes que viene…"><?= e($infTexto) ?></textarea>
        <div class="inf-ef"><span class="inf-hint"><?= ic('eye',14) ?> Se publica al guardar · el cliente lo ve al instante</span><button class="btn" type="submit"><?= ic('check',15) ?> Guardar y publicar</button></div>
      </form>
      <?php endif; ?>

      <div class="inf-tasks-h"><?= ic('check',14) ?> Tareas de <?= e(mes_label($curMes)) ?> que el cliente también ve <span class="inf-count"><?= count($entries) ?></span></div>
      <div class="ck-body">
        <div class="ck-colh" style="grid-template-columns:2.4fr 1fr 1fr 70px"><span>Tarea</span><span>Responsable</span><span>Fecha</span><span></span></div>
        <?php foreach($entries as $t): $turl='task.php?id='.(int)$t['id'].'&ret='.rawurlencode($retNow); ?>
          <div class="ck-row" style="grid-template-columns:2.4fr 1fr 1fr 70px">
            <div class="nm"><span class="st" style="color:var(--label)"><?= ic('inbox',15) ?></span><a href="<?= e($turl) ?>" style="color:inherit;min-width:0"><b><?= e($t['titulo']) ?></b></a></div>
            <div class="asig"><?php $ai=ws_asg_ids($t,$asgMap,$respMap); if($ai): ?><?= ws_asg_avs($ai,$respMap) ?><?php else: ?><span class="cell mut">—</span><?php endif; ?></div>
            <?php /* La fecha se escribía tal cual sale de MySQL (2026-03-04). En la
                     tabla de al lado, la de tareas, la misma columna ya se veía
                     04/03/26. Aquí se escribe igual que en el resto del ERP. */ ?>
            <div class="cell <?= $t['due_date']?'':'mut' ?>"><?= $t['due_date']?e(date('d/m/y',strtotime($t['due_date']))):'—' ?></div>
            <div class="acts"><a class="ck-x" title="Abrir" href="<?= e($turl) ?>"><?= ic('search',14) ?></a><?php if(can_edit()): ?><form method="post" style="margin:0" onsubmit="return erpSubmitAsk(this,'La entrada va a la papelera; podrás deshacerlo.')"><input type="hidden" name="action" value="del_task"><input type="hidden" name="cli" value="<?= $cli ?>"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="ret" value="<?= e($retNow) ?>"><button class="ck-x" type="submit">✕</button></form><?php endif; ?></div>
          </div>
        <?php endforeach; ?>
        <?php if(!$entries): ?><div class="muted" style="padding:14px 16px">Sin tareas en este mes. El informe de arriba es lo principal; añade tareas solo si quieres que el cliente vea acciones concretas.</div><?php endif; ?>
        <?php if(can_edit()): ?><button type="button" class="ck-add ck-addbtn" onclick="openCreate('pendiente','<?= e($curMes) ?>')"><span class="plus"><?= ic('plus',15) ?></span>Añadir tarea del mes</button><?php endif; ?>
      </div>
    <?php else: ?>
    <?php $totalTasks = array_sum(array_map('count',$porEstado)); ?>
    <?php foreach ($ESTADOS as $ek=>$ev): $items=$porEstado[$ek];
      if ($totalTasks===0) { if($ek!=='pendiente') continue; }
      else { if(count($items)===0) continue; }
    ?>
      <div class="ck-grp <?= $ek==='completada'?'closed':'' ?>">
        <div class="ck-gh" onclick="this.closest('.ck-grp').classList.toggle('closed')">
          <span class="gchev"><?= ic('chevron',14) ?></span>
          <span class="gpill"><span class="gd" style="background:<?= $ev[1] ?>"></span><?= e($ev[0]) ?></span>
          <span class="gn"><?= count($items) ?></span>
        </div>
        <?php /* data-reorder dice al motor de arrastre común (erp_nav.php) a qué acción
                 mandar el orden nuevo. Las tareas se colocan a mano dentro de su estado:
                 antes salían siempre por fecha de creación y no había manera de subir
                 la urgente arriba del todo. */ ?>
        <div class="ck-body" <?= can_edit()?'data-reorder="reorder_tasks" data-reorder-cli="'.$cli.'"':'' ?>>
          <div class="ck-colh"><span>Nombre</span><span>Persona asignada</span><span>Fecha límite</span><span>Prioridad</span><span></span></div>
          <?php foreach ($items as $t): $pr=$PRIOS[(int)$t['prioridad']]??$PRIOS[0]; $turl='task.php?id='.(int)$t['id'].'&ret='.rawurlencode($retNow); ?>
            <div class="ck-row <?= can_edit()?'row-drag':'' ?>" <?= can_edit()?'draggable="true" data-rid="'.(int)$t['id'].'"':'' ?> onclick="location.href='<?= e($turl) ?>'" <?= can_edit()?'oncontextmenu="return taskMenu(event,'.(int)$t['id'].',\''.e($turl).'\','.htmlspecialchars(json_encode($t['titulo']),ENT_QUOTES).','.(int)$cli.')"':'' ?>>
              <div class="nm"><?= can_edit()?row_grip():'' ?><span class="st ws-cell" <?= can_edit()?'onclick="openEstado(event,this,'.(int)$t['id'].','.(int)$cli.')"':'' ?> title="Cambiar estado"><?= estado_circle($ek) ?></span><b><?= e($t['titulo']) ?></b><?php if($ek==='completada'): ?><span class="st-tag done">Completada</span><?php endif; ?><?php if($t['visible_cliente']): ?> <span class="vis-badge">cliente</span><?php endif; ?></div>
              <div class="asig ws-cell" <?= can_edit()?'onclick="openAssign(event,this,'.(int)$t['id'].')"':'' ?>><?php $ai=ws_asg_ids($t,$asgMap,$respMap); if($ai): ?><?= ws_asg_avs($ai,$respMap) ?><span class="nn"><?= count($ai)===1?e($respMap[$ai[0]]):count($ai).' asignados' ?></span><?php else: ?><span class="cell mut">＋ Asignar</span><?php endif; ?></div>
              <div class="ws-cell fdate" onclick="event.stopPropagation()"><input type="text" class="ws-date-txt" data-tid="<?= (int)$t['id'] ?>" data-iso="<?= e($t['due_date']) ?>" value="<?= $t['due_date']?e(date('d/m/y',strtotime($t['due_date']))):'' ?>" placeholder="dd/mm/aa" <?= can_edit()?'onfocus="openCal(this)" onkeydown="if(event.key===\'Enter\'){event.preventDefault();this.blur();}" onblur="calCommit(this)"':'readonly' ?>></div>
              <div class="ws-cell" <?= can_edit()?'onclick="openPrio(event,this,'.(int)$t['id'].')"':'' ?>><?php if((int)$t['prioridad']>0): ?><span class="flagp"><span class="fdot" style="background:<?= $pr[1] ?>"></span><?= e($pr[0]) ?></span><?php else: ?><span class="cell mut">＋</span><?php endif; ?></div>
              <div class="acts" onclick="event.stopPropagation()">
                <a class="ck-x" title="Abrir" href="<?= e($turl) ?>"><?= ic('search',14) ?></a>
                <?php if (can_edit()): ?><form method="post" style="margin:0" onsubmit="return erpSubmitAsk(this,'La tarea va a la papelera; podrás deshacerlo.')"><input type="hidden" name="action" value="del_task"><input type="hidden" name="cli" value="<?= $cli ?>"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="ret" value="<?= e($retNow) ?>"><button class="ck-x" type="submit">✕</button></form><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (can_edit()): ?>
          <form method="post" class="ck-add" onsubmit="if(!this.titulo.value.trim()){openCreate('pendiente','');return false;}return true;"><input type="hidden" name="action" value="save_task"><input type="hidden" name="cli" value="<?= $cli ?>"><input type="hidden" name="list_id" value="<?= $curList ?>"><input type="hidden" name="estado" value="pendiente"><input type="hidden" name="ret" value="<?= e($retNow) ?>"><span class="plus"><?= ic('plus',15) ?></span><input name="titulo" placeholder="Añadir tarea…" autocomplete="off"><button type="submit">Añadir</button></form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php endif; /* tipo informe/tareas */ ?>
    <?php endif; /* curList */ ?>
  <?php endif; /* lists */ ?>

<?php else: /* TODAS / MIS TAREAS */ ?>
  <div class="flex" style="margin-bottom:14px">
    <h1 style="font-size:20px;flex:1;display:flex;align-items:center;gap:9px"><?php if($view==='emp' && isset($respMap[$emp])): ?><span class="hd-av" style="background:<?= avatar_color($respMap[$emp]) ?>"><?= ini2($respMap[$emp]) ?></span>Tareas de <?= e($respMap[$emp]) ?><?php elseif($view==='mine'): ?>Mis tareas<?php else: ?>Todas las tareas<?php endif; ?></h1>
    <select class="mini-sel" aria-label="Filtrar por estado" onchange="wsFilter('fe',this.value)"><option value="">Todos los estados</option><?php foreach($ESTADOS as $sk=>$sv): ?><option value="<?= $sk ?>" <?= $fEstado==$sk?'selected':'' ?>><?= e($sv[0]) ?></option><?php endforeach; ?></select>
    <?php if($view==='all'): ?><select class="mini-sel" aria-label="Filtrar por responsable" onchange="wsFilter('fr',this.value)"><option value="">Todos los responsables</option><?php foreach($responsables as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (string)$fResp===(string)$r['id']?'selected':'' ?>><?= e($r['username']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <script>/* Filtro robusto: no usa new URL() porque en esta página el objeto URL está pisado. */
    function wsFilter(k,v){var parts=location.search.replace(/^\?/,'').split('&').filter(function(x){return x&&x.split('=')[0]!==k;});if(v)parts.push(k+'='+encodeURIComponent(v));location.search=parts.join('&');}</script>
  </div>
  <?php if(!$rows): ?><div class="card"><p class="muted">No hay tareas con estos filtros. Elige un cliente arriba para crear tareas.</p></div>
  <?php else: $grp=''; foreach($rows as $t): if($t['clientname']!==$grp){ if($grp!=='')echo '</div></div>'; $grp=$t['clientname']; echo '<div class="grp" data-cli="'.htmlspecialchars($grp,ENT_QUOTES).'"><div class="grp-h" onclick="wsToggleGrp(this)"><span class="grp-cv">'.ic('chevron',13).'</span><span class="cd" style="background:'.avatar_color($grp).'">'.ini2($grp).'</span><span class="grp-nm">'.e($grp).'</span><span class="grp-n"></span></div><div class="grp-body"><div class="ck-colh"><span>Nombre</span><span>Persona asignada</span><span>Fecha límite</span><span>Prioridad</span><span></span></div>'; } $pr=$PRIOS[(int)$t['prioridad']]??$PRIOS[0]; $turl='task.php?id='.(int)$t['id'].'&ret='.rawurlencode($retNow); $cidRow=(int)$t['client_id']; $editable=can_edit(); ?>
      <div class="ck-row" onclick="location.href='<?= e($turl) ?>'" <?= $editable?'oncontextmenu="return taskMenu(event,'.(int)$t['id'].',\''.e($turl).'\','.htmlspecialchars(json_encode($t['titulo']),ENT_QUOTES).','.$cidRow.')"':'' ?>>
        <div class="nm"><span class="st ws-cell" <?= $editable?'onclick="openEstado(event,this,'.(int)$t['id'].','.$cidRow.')"':'' ?> title="Cambiar estado"><?= estado_circle($t['estado']) ?></span><b><?= e($t['titulo']) ?></b><?php if($t['estado']==='completada'): ?><span class="st-tag done">Completada</span><?php endif; ?><?php if(!empty($t['visible_cliente'])): ?> <span class="vis-badge">cliente</span><?php endif; ?><span class="ck-listtag"><?= e($t['listname']) ?></span></div>
        <div class="asig ws-cell" <?= $editable?'onclick="openAssign(event,this,'.(int)$t['id'].')"':'' ?>><?php $ai=ws_asg_ids($t,$asgMap,$respMap); if($ai): ?><?= ws_asg_avs($ai,$respMap) ?><span class="nn"><?= count($ai)===1?e($respMap[$ai[0]]):count($ai).' asignados' ?></span><?php else: ?><span class="cell mut">＋ Asignar</span><?php endif; ?></div>
        <div class="ws-cell fdate" onclick="event.stopPropagation()"><input type="text" class="ws-date-txt" data-tid="<?= (int)$t['id'] ?>" data-iso="<?= e($t['due_date']) ?>" value="<?= $t['due_date']?e(date('d/m/y',strtotime($t['due_date']))):'' ?>" placeholder="dd/mm/aa" <?= $editable?'onfocus="openCal(this)" onkeydown="if(event.key===\'Enter\'){event.preventDefault();this.blur();}" onblur="calCommit(this)"':'readonly' ?>></div>
        <div class="ws-cell" <?= $editable?'onclick="openPrio(event,this,'.(int)$t['id'].')"':'' ?>><?php if((int)$t['prioridad']>0): ?><span class="flagp"><span class="fdot" style="background:<?= $pr[1] ?>"></span><?= e($pr[0]) ?></span><?php else: ?><span class="cell mut">＋</span><?php endif; ?></div>
        <div class="acts" onclick="event.stopPropagation()">
          <a class="ck-x" title="Abrir" href="<?= e($turl) ?>"><?= ic('search',14) ?></a>
          <?php if($editable): ?><form method="post" style="margin:0" onsubmit="return erpSubmitAsk(this,'La tarea va a la papelera; podrás deshacerlo.')"><input type="hidden" name="action" value="del_task"><input type="hidden" name="cli" value="<?= $cidRow ?>"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="ret" value="<?= e($retNow) ?>"><button class="ck-x" type="submit">✕</button></form><?php endif; ?>
        </div>
      </div>
  <?php endforeach; echo '</div></div>'; endif; ?>
  <?php /* Plegar por cliente + contador + recordar qué grupos dejaste cerrados. */ ?>
  <script>
  function wsToggleGrp(h){var g=h.closest('.grp');if(!g)return;g.classList.toggle('collapsed');
    try{var k='ws_collapsed',s=JSON.parse(localStorage.getItem(k)||'[]'),c=g.dataset.cli,i=s.indexOf(c);
      if(g.classList.contains('collapsed')){if(i<0)s.push(c);}else if(i>=0)s.splice(i,1);
      localStorage.setItem(k,JSON.stringify(s));}catch(e){}}
  (function(){var s=[];try{s=JSON.parse(localStorage.getItem('ws_collapsed')||'[]');}catch(e){}
    document.querySelectorAll('.grp').forEach(function(g){
      var n=g.querySelectorAll('.grp-body .ck-row').length,b=g.querySelector('.grp-n');if(b)b.textContent=n;
      if(s.indexOf(g.dataset.cli)>=0)g.classList.add('collapsed');});})();
  </script>
<?php endif; ?>

<?php if ($view==='cliente' && $curList && can_edit()): ?>
<div class="tm-ov" id="taskModal"><div class="tm">
  <form method="post">
    <input type="hidden" name="action" value="save_task"><input type="hidden" name="cli" value="<?= $cli ?>"><input type="hidden" name="list_id" value="<?= $curList ?>"><input type="hidden" name="ret" value="<?= e($retNow) ?>">
    <div class="tm-body">
      <span class="tm-list"><?= ic('list',13) ?> <?= e($thisList['nombre'] ?? 'Lista') ?></span>
      <input class="tmt" name="titulo" id="tm_titulo" placeholder="Nombre de la tarea" required autocomplete="off">
      <textarea class="tmd" name="descripcion" placeholder="Añade una descripción…"></textarea>
      <div class="tmg">
        <div class="tmr"><span class="tl"><?= ic('check',15) ?> Estado</span><select name="estado" id="tm_estado"><?php foreach($ESTADOS as $sk=>$sv): ?><option value="<?= $sk ?>"><?= e($sv[0]) ?></option><?php endforeach; ?></select></div>
        <div class="tmr"><span class="tl"><?= ic('user',15) ?> Asignado</span><select name="responsable_id"><option value="">Sin asignar</option><?php foreach($responsables as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['username']) ?></option><?php endforeach; ?></select></div>
        <div class="tmr"><span class="tl"><?= ic('cal',15) ?> Inicio</span><input type="text" class="dpick" data-iso="" data-sync="#tmFi"><input type="hidden" name="fecha_inicio" id="tmFi"></div>
        <div class="tmr"><span class="tl"><?= ic('cal',15) ?> Fecha límite</span><input type="text" class="dpick" data-iso="" data-sync="#tmDd"><input type="hidden" name="due_date" id="tmDd"></div>
        <div class="tmr"><span class="tl"><?= ic('alert',15) ?> Prioridad</span><select name="prioridad"><?php foreach($PRIOS as $pk=>$pv): ?><option value="<?= $pk ?>"><?= e($pv[0]) ?></option><?php endforeach; ?></select></div>
        <div class="tmr"><span class="tl"><?= ic('list',15) ?> Etiquetas</span><input name="etiquetas" placeholder="Vaciar"></div>
        <div class="tmr"><span class="tl"><?= ic('cal',15) ?> Mes (informe)</span><input name="mes" id="tm_mes" placeholder="Ej: Junio"></div>
      </div>
    </div>
    <div class="tm-foot"><button class="btn ghost sm" type="button" onclick="document.getElementById('taskModal').classList.remove('on')">Cancelar</button><button class="btn sm" type="submit">Crear tarea</button></div>
  </form>
</div></div>
<script>
function openCreate(est,mes){var m=document.getElementById('taskModal');if(!m)return;if(est)document.getElementById('tm_estado').value=est;var mm=document.getElementById('tm_mes');if(mm&&mes!==undefined)mm.value=mes||'';if(window.csRefreshAll)window.csRefreshAll();m.classList.add('on');setTimeout(function(){document.getElementById('tm_titulo').focus();},60);}
(function(){var m=document.getElementById('taskModal');if(m){m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('on');});document.addEventListener('keydown',function(e){if(e.key==='Escape')m.classList.remove('on');});}})();
</script>
<?php endif; /* fin del modal de crear tarea: eso sí es exclusivo de la lista de un cliente */ ?>

<?php /* Lo que viene ahora (los desplegables, el calendario y el menú de clic derecho)
         estaba metido dentro del mismo `if` que el modal de crear tarea, así que solo
         existía dentro de la lista de un cliente. Ese era el motivo real de que en
         «Todas las tareas» y «Mis tareas» no se pudiera tocar nada: no es que
         estuvieran pensadas como solo lectura, es que se les quedaba fuera el
         mecanismo. Ahora se cargan siempre que la cuenta pueda editar. */ ?>
<?php if (can_edit()): ?>
<div id="wsPop"></div>
<div id="wsCal" onmousedown="event.preventDefault()"></div>
<script>
window.MEMBERS=[<?php foreach($responsables as $r): ?>{id:<?= (int)$r['id'] ?>,name:<?= json_encode($r['username']) ?>,color:<?= json_encode(avatar_color($r['username'])) ?>,ini:<?= json_encode(mb_strtoupper(mb_substr($r['username'],0,2))) ?>},<?php endforeach; ?>];
window.WPRIOS=[<?php foreach($PRIOS as $pk=>$pv): ?>{v:<?= (int)$pk ?>,label:<?= json_encode($pv[0]) ?>,color:<?= json_encode($pv[1]) ?>},<?php endforeach; ?>];
function esc(s){return (''+s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
function wsShow(cell,items,pick){var p=document.getElementById('wsPop');p.innerHTML='';items.forEach(function(it){var b=document.createElement('button');b.type='button';b.className='wsp-item';b.innerHTML=it.html;b.onclick=function(ev){ev.stopPropagation();pick(it);p.classList.remove('on');};p.appendChild(b);});var r=cell.getBoundingClientRect();p.style.left=Math.min(r.left,window.innerWidth-232)+'px';p.style.top=(r.bottom+4)+'px';p.classList.add('on');}
/* Las filas de «Todas las tareas» / «Mis tareas» son mucho más bajas que las de la
   lista de un cliente, así que la misma celda se repinta de dos maneras: en la lista
   del cliente con el nombre al lado del avatar, y en la fila compacta solo con el
   avatar. La marca es data-mini="1". Sin esto, al asignar a alguien desde «Todas las
   tareas» la fila daba un salto y luego, al recargar, volvía a su sitio. */
window.WS_AVNONE=<?= json_encode('<div class="av-mini av-none" title="Sin asignar">'.ic('user',13).'</div>') ?>;
window.WSASG=<?php $wsAsgJs=[]; foreach($rows as $rr){ $wsAsgJs[(int)$rr['id']]=array_map('intval',ws_asg_ids($rr,$asgMap,$respMap)); } echo json_encode((object)$wsAsgJs) ?: '{}'; ?>;
function wsMember(id){for(var i=0;i<window.MEMBERS.length;i++){if(window.MEMBERS[i].id===id)return window.MEMBERS[i];}return null;}
function wsAvHtml(ids){var h='<div class="asg-stack">';ids.slice(0,3).forEach(function(id){var m=wsMember(id);if(m)h+='<div class="av-mini" title="'+esc(m.name)+'" style="background:'+m.color+'">'+esc(m.ini)+'</div>';});if(ids.length>3)h+='<div class="av-mini av-extra">+'+(ids.length-3)+'</div>';return h+'</div>';}
function wsAsgRender(cell,ids){
  if(cell.dataset.mini==='1'){cell.innerHTML=ids.length?wsAvHtml(ids):window.WS_AVNONE;return;}
  if(!ids.length){cell.innerHTML='<span class="cell mut">＋ Asignar</span>';return;}
  var nm=ids.length===1?((wsMember(ids[0])||{}).name||''):ids.length+' asignados';
  cell.innerHTML=wsAvHtml(ids)+'<span class="nn">'+esc(nm)+'</span>';
}
/* Multi-selección de asignados desde la lista (varios responsables). */
function openAssign(e,cell,tid){e.stopPropagation();if(!window.WSASG[tid])window.WSASG[tid]=[];
  var pop=document.getElementById('wsPop');pop.innerHTML='';
  window.MEMBERS.forEach(function(m){var b=document.createElement('button');b.type='button';b.className='wsp-item';
    var on=window.WSASG[tid].indexOf(m.id)>=0;
    b.innerHTML='<span class="wsp-av" style="background:'+m.color+'">'+esc(m.ini)+'</span><span style="flex:1">'+esc(m.name)+'</span><span class="wsp-ck">'+(on?'✓':'')+'</span>';
    b.onclick=function(ev){ev.stopPropagation();var a=window.WSASG[tid];var i=a.indexOf(m.id);if(i>=0)a.splice(i,1);else a.push(m.id);
      b.querySelector('.wsp-ck').textContent=a.indexOf(m.id)>=0?'✓':'';wsAsgRender(cell,a);
      var body='action=set_asignados&id='+tid;a.forEach(function(id){body+='&ids[]='+id;});
      fetch('workspace.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body}).catch(function(){});};
    pop.appendChild(b);});
  var r=cell.getBoundingClientRect();pop.style.left=Math.min(r.left,window.innerWidth-232)+'px';pop.style.top=(r.bottom+4)+'px';pop.classList.add('on');}
function openPrio(e,cell,tid){e.stopPropagation();var items=window.WPRIOS.map(function(p){return {val:p.v,p:p,html:'<span class="wsp-dot" style="background:'+p.color+'"></span>'+esc(p.label)};});
  wsShow(cell,items,function(it){inlineTask(tid,'prioridad',it.val);
    if(cell.dataset.mini==='1'){cell.innerHTML=(it.val==0?'<span class="pmut">＋</span>':'<span class="gd" style="background:'+it.p.color+'"></span>'+esc(it.p.label));return;}
    cell.innerHTML=(it.val==0?'<span class="cell mut">＋</span>':'<span class="flagp" style="color:'+it.p.color+'"><span class="fdot" style="background:'+it.p.color+'"></span>'+esc(it.p.label)+'</span>');});}
function inlineTask(tid,field,val){fetch('workspace.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=inline_task&id='+tid+'&field='+field+'&val='+encodeURIComponent(val)}).catch(function(){});}
window.WESTADOS=[<?php foreach($ESTADOS as $sk=>$sv): ?>{k:<?= json_encode($sk) ?>,label:<?= json_encode($sv[0]) ?>,color:<?= json_encode($sv[1]) ?>},<?php endforeach; ?>];
function stMarkup(ek){
  if(ek==='completada')return '<svg width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#12a150"/><path d="M7.4 12.4l3 3 6.2-6.7" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  if(ek==='en proceso')return '<svg width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="#3b82f6" stroke-width="2"/><path d="M12 12 L12 3 A9 9 0 1 1 5.64 18.36 Z" fill="#3b82f6"/></svg>';
  if(ek==='atemporal')return '<svg width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="#e0a000" stroke-width="2.4" stroke-dasharray="3.2 3.2"/></svg>';
  return '<svg width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="#b0b4bb" stroke-width="2.4"/></svg>';
}
/* `cid` es el cliente de esa tarea. En la lista de un cliente todas son del mismo y
   el formulario ya lo trae puesto, pero «Todas las tareas» mezcla tareas de varios
   clientes: el servidor comprueba `AND client_id=?` al cambiar el estado o borrar,
   así que sin mandar el cliente de la fila la operación no tocaba nada y la pantalla
   se recargaba igual, como si hubiera funcionado. */
function openEstado(e,cell,tid,cid){e.stopPropagation();var items=window.WESTADOS.map(function(s){return {val:s.k,s:s,html:'<span class="wsp-st">'+stMarkup(s.k)+'</span>'+esc(s.label)};});
  wsShow(cell,items,function(it){
    cell.innerHTML=(cell.dataset.mini==='1'?'<span class="gd" style="background:'+it.s.color+'"></span>'+esc(it.s.label):stMarkup(it.val));
    var qi=document.getElementById('qeId'),qe=document.getElementById('qeEstado'),qc=document.getElementById('qeCli');
    if(qc&&cid!==undefined&&cid!==null)qc.value=cid;
    if(qi&&qe){qi.value=tid;qe.value=it.val;document.getElementById('qeForm').submit();}});}
/* --- calendario moderno editable --- */
var _calInput=null,_calYM=null;
function _pad(n){return (n<10?'0':'')+n;}
function isoToDisp(iso){if(!iso)return '';var p=(''+iso).split('-');if(p.length!==3)return '';return p[2]+'/'+p[1]+'/'+p[0].slice(2);}
function parseDisp(s){s=(s||'').trim();if(!s)return '';var m=s.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/);if(!m)return null;var d=+m[1],mo=+m[2],y=+m[3];if(y<100)y+=2000;if(mo<1||mo>12||d<1||d>31)return null;return y+'-'+_pad(mo)+'-'+_pad(d);}
function openCal(inp){_calInput=inp;var iso=inp.dataset.iso||'';var base=iso?new Date(iso+'T00:00:00'):new Date();_calYM=new Date(base.getFullYear(),base.getMonth(),1);renderCal();var cal=document.getElementById('wsCal');var r=inp.getBoundingClientRect();cal.style.left=Math.min(r.left,window.innerWidth-262)+'px';cal.style.top=(r.bottom+6)+'px';cal.classList.add('on');}
function calNav(delta){_calYM=new Date(_calYM.getFullYear(),_calYM.getMonth()+delta,1);renderCal();}
function renderCal(){var cal=document.getElementById('wsCal');var y=_calYM.getFullYear(),mo=_calYM.getMonth();var M=['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];var sel=_calInput&&_calInput.dataset.iso?_calInput.dataset.iso:'';var td=new Date();var todayIso=td.getFullYear()+'-'+_pad(td.getMonth()+1)+'-'+_pad(td.getDate());
  var h='<div class="cal-h"><button type="button" class="cal-nav" onclick="calNav(-1)">‹</button><span>'+M[mo]+' '+y+'</span><button type="button" class="cal-nav" onclick="calNav(1)">›</button></div>';
  h+='<div class="cal-grid cal-dow"><span>L</span><span>M</span><span>X</span><span>J</span><span>V</span><span>S</span><span>D</span></div>';
  var start=(new Date(y,mo,1).getDay()+6)%7;var days=new Date(y,mo+1,0).getDate();
  h+='<div class="cal-grid">';var i;for(i=0;i<start;i++)h+='<span></span>';
  for(var d=1;d<=days;d++){var iso=y+'-'+_pad(mo+1)+'-'+_pad(d);var cls='cal-d';if(iso===sel)cls+=' sel';if(iso===todayIso)cls+=' today';h+='<button type="button" class="'+cls+'" onclick="calPick(\''+iso+'\')">'+d+'</button>';}
  h+='</div><div class="cal-foot"><button type="button" class="cal-clr" onclick="calPick(\'\')">Borrar</button><button type="button" class="cal-tod" onclick="calPick(\''+todayIso+'\')">Hoy</button></div>';
  cal.innerHTML=h;}
function calPick(iso){if(!_calInput)return;_calInput.dataset.iso=iso;_calInput.value=isoToDisp(iso);inlineTask(_calInput.dataset.tid,'due_date',iso);document.getElementById('wsCal').classList.remove('on');}
function calCommit(inp){var v=(inp.value||'').trim();if(v===''){if(inp.dataset.iso!==''){inp.dataset.iso='';inlineTask(inp.dataset.tid,'due_date','');}return;}var iso=parseDisp(v);if(iso===null){inp.value=isoToDisp(inp.dataset.iso);return;}if(iso!==inp.dataset.iso){inp.dataset.iso=iso;inlineTask(inp.dataset.tid,'due_date',iso);}inp.value=isoToDisp(iso);}
function taskMenu(e,tid,turl,name,cid){e.preventDefault();var m=document.getElementById('taskCtx');m.innerHTML='';
  function setCli(id){var c=document.getElementById(id);if(c&&cid!==undefined&&cid!==null)c.value=cid;}
  function it(txt,fn,danger){var a=document.createElement('a');a.textContent=txt;a.href='#';if(danger)a.className='danger';a.onclick=function(ev){ev.preventDefault();ev.stopPropagation();m.classList.remove('on');fn();};return a;}
  m.appendChild(it('Abrir tarea',function(){location.href=turl;}));
  m.appendChild(it('Marcar completada',function(){setCli('qeCli');document.getElementById('qeId').value=tid;document.getElementById('qeEstado').value='completada';document.getElementById('qeForm').submit();}));
  var s=document.createElement('div');s.className='sep';m.appendChild(s);
  m.appendChild(it('Borrar tarea',function(){
    erpConfirm('Se borran también sus comentarios y sus horas.',{titulo:'¿Borrar «'+name+'»?',danger:true}).then(function(ok){if(!ok)return;
      setCli('dtCli');document.getElementById('dtId').value=tid;document.getElementById('dtForm').submit();});},true));
  m.style.left=Math.min(e.clientX,window.innerWidth-210)+'px';m.style.top=Math.min(e.clientY,window.innerHeight-190)+'px';m.classList.add('on');return false;}
document.addEventListener('click',function(e){if(!e.target.closest('.ws-cell')&&!e.target.closest('.ws-pick')&&!e.target.closest('#wsPop')){var p=document.getElementById('wsPop');if(p)p.classList.remove('on');}if(e.target.isConnected&&!e.target.closest('.fdate')&&!e.target.closest('#wsCal')){var cal=document.getElementById('wsCal');if(cal)cal.classList.remove('on');}/* isConnected: las flechas de mes hacen innerHTML y desprenden el botón; sin este guard el calendario se cerraba al cambiar de mes */if(!e.target.closest('#taskCtx')){var tc=document.getElementById('taskCtx');if(tc)tc.classList.remove('on');}});
</script>
<div class="ctxmenu" id="taskCtx"></div>
<?php /* El cliente de estos dos formularios ya no puede ser fijo: en «Todas las tareas»
         no hay un cliente de la página, cada fila trae el suyo y lo escribe aquí antes
         de enviar. En la lista de un cliente sigue viniendo relleno de serie. */ ?>
<form id="dtForm" method="post" style="display:none"><input type="hidden" name="action" value="del_task"><input type="hidden" name="cli" id="dtCli" value="<?= $cli ?>"><input type="hidden" name="id" id="dtId"><input type="hidden" name="ret" value="<?= e($retNow) ?>"></form>
<form id="qeForm" method="post" style="display:none"><input type="hidden" name="action" value="quick_estado"><input type="hidden" name="cli" id="qeCli" value="<?= $cli ?>"><input type="hidden" name="id" id="qeId"><input type="hidden" name="estado" id="qeEstado"><input type="hidden" name="ret" value="<?= e($retNow) ?>"></form>
<?php endif; ?>
<?php erp_foot(); ?>
