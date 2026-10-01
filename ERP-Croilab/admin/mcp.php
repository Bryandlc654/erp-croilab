<?php
/* =====================================================================
   Servidor MCP del ERP de Croilab (JSON-RPC 2.0 sobre HTTP).

   Deja que Claude (u otro cliente MCP) gestione TAREAS de forma sencilla y
   segura: listar, crear, actualizar estado/prioridad/fecha, etiquetar y
   comentar; y consultar clientes y equipo. NO permite borrar, ni tocar
   facturación, credenciales ni ajustes: solo lo de trabajo del día a día.

   Autenticación por TOKEN (como el conector de WordPress):
     - Cabecera  Authorization: Bearer EL_TOKEN
     - o URL     .../admin/mcp.php/EL_TOKEN
     - o URL     .../admin/mcp.php?k=EL_TOKEN
   El token y el interruptor se gestionan en Ajustes → API e integraciones → MCP.

   NO incluye auth.php a propósito: es máquina-a-máquina, sin sesión ni CSRF.
   ===================================================================== */
require __DIR__ . '/../config.php';
require __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

/* ---------- Autenticación ---------- */
$enabled = get_setting('mcp_enabled','') === '1';
$token   = (string)get_setting('mcp_token','');
$provided = '';
$hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!$hdr && function_exists('getallheaders')) { foreach(getallheaders() as $k=>$v){ if(strcasecmp($k,'Authorization')===0){ $hdr=$v; break; } } }
if ($hdr && preg_match('/Bearer\s+(.+)/i', $hdr, $m)) $provided = trim($m[1]);
elseif (isset($_GET['k']))     $provided = (string)$_GET['k'];
elseif (isset($_GET['token'])) $provided = (string)$_GET['token'];
elseif (!empty($_SERVER['PATH_INFO'])) $provided = trim($_SERVER['PATH_INFO'], '/');

if (!$enabled || $token==='' || !hash_equals($token, $provided)) {
  http_response_code(401);
  echo json_encode(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32001,'message'=>'No autorizado. Revisa el token del MCP en Ajustes.']]);
  exit;
}

/* ---------- Lectura de la petición JSON-RPC ---------- */
$raw = file_get_contents('php://input');
$req = json_decode($raw, true);
if (!is_array($req)) { http_response_code(400); echo json_encode(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32700,'message'=>'JSON no válido']]); exit; }

$method = $req['method'] ?? '';
$id     = $req['id'] ?? null;
$params = $req['params'] ?? [];

function rpc_ok($id,$result){ echo json_encode(['jsonrpc'=>'2.0','id'=>$id,'result'=>$result], JSON_UNESCAPED_UNICODE); exit; }
function rpc_err($id,$code,$msg){ echo json_encode(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$msg]], JSON_UNESCAPED_UNICODE); exit; }
function tool_text($txt){ return ['content'=>[['type'=>'text','text'=>$txt]]]; }

/* Notificaciones (sin id): no llevan respuesta. */
if ($id===null && strpos($method,'notifications/')===0) { http_response_code(202); exit; }

$ESTADOS = ['pendiente','en proceso','atemporal','completada'];

/* ---------- Catálogo de herramientas ---------- */
function mcp_tools(){
  $cli = ['type'=>'string','description'=>'Cliente: id numérico o parte del nombre'];
  return [
    ['name'=>'listar_clientes','description'=>'Lista los clientes del ERP (id, nombre, activo).','inputSchema'=>['type'=>'object','properties'=>new stdClass()]],
    ['name'=>'listar_equipo','description'=>'Lista los miembros del equipo (id, usuario).','inputSchema'=>['type'=>'object','properties'=>new stdClass()]],
    ['name'=>'listar_listas','description'=>'Lista las listas de tareas de un cliente.','inputSchema'=>['type'=>'object','properties'=>['cliente'=>$cli],'required'=>['cliente']]],
    ['name'=>'listar_tareas','description'=>'Lista tareas. Filtros opcionales por cliente, estado, responsable o texto.','inputSchema'=>['type'=>'object','properties'=>[
        'cliente'=>$cli,'estado'=>['type'=>'string','enum'=>['pendiente','en proceso','atemporal','completada']],
        'responsable'=>['type'=>'string','description'=>'id o nombre del responsable'],'texto'=>['type'=>'string','description'=>'buscar en el título'],
        'limite'=>['type'=>'integer','description'=>'máximo de resultados (por defecto 50)']]]],
    ['name'=>'crear_tarea','description'=>'Crea una tarea en un cliente. Devuelve el id creado.','inputSchema'=>['type'=>'object','properties'=>[
        'cliente'=>$cli,'titulo'=>['type'=>'string'],'lista'=>['type'=>'string','description'=>'nombre de la lista (opcional; si no existe se usa/crea una)'],
        'estado'=>['type'=>'string','enum'=>['pendiente','en proceso','atemporal','completada']],'due_date'=>['type'=>'string','description'=>'AAAA-MM-DD'],
        'responsable'=>['type'=>'string','description'=>'id o nombre'],'prioridad'=>['type'=>'integer','description'=>'0-4'],'etiquetas'=>['type'=>'string']],'required'=>['cliente','titulo']]],
    ['name'=>'actualizar_tarea','description'=>'Actualiza campos de una tarea por id.','inputSchema'=>['type'=>'object','properties'=>[
        'id'=>['type'=>'integer'],'titulo'=>['type'=>'string'],'estado'=>['type'=>'string','enum'=>['pendiente','en proceso','atemporal','completada']],
        'prioridad'=>['type'=>'integer'],'due_date'=>['type'=>'string','description'=>'AAAA-MM-DD o vacío para quitar'],'responsable'=>['type'=>'string'],'etiquetas'=>['type'=>'string']],'required'=>['id']]],
    ['name'=>'etiquetar_tarea','description'=>'Añade etiquetas a una tarea (se suman a las que ya tenga).','inputSchema'=>['type'=>'object','properties'=>['id'=>['type'=>'integer'],'etiquetas'=>['type'=>'string','description'=>'separadas por coma']],'required'=>['id','etiquetas']]],
    ['name'=>'comentar_tarea','description'=>'Añade un comentario a una tarea.','inputSchema'=>['type'=>'object','properties'=>['id'=>['type'=>'integer'],'texto'=>['type'=>'string']],'required'=>['id','texto']]],
  ];
}

/* ---------- Utilidades ---------- */
function resolver_cliente($v){ $v=trim((string)$v); if($v==='') return 0;
  if(ctype_digit($v)){ $q=db()->prepare('SELECT id FROM clients WHERE id=?'); $q->execute([(int)$v]); return (int)$q->fetchColumn(); }
  $q=db()->prepare('SELECT id FROM clients WHERE name LIKE ? ORDER BY name LIMIT 1'); $q->execute(['%'.$v.'%']); return (int)$q->fetchColumn(); }
function resolver_admin($v){ $v=trim((string)$v); if($v==='') return null;
  if(ctype_digit($v)){ $q=db()->prepare('SELECT id FROM admins WHERE id=?'); $q->execute([(int)$v]); $r=$q->fetchColumn(); return $r?(int)$r:null; }
  $q=db()->prepare('SELECT id FROM admins WHERE username LIKE ? ORDER BY username LIMIT 1'); $q->execute(['%'.$v.'%']); $r=$q->fetchColumn(); return $r?(int)$r:null; }
function mcp_actor(){ try{ $r=db()->query("SELECT id FROM admins ORDER BY id LIMIT 1")->fetchColumn(); return $r?(int)$r:1; }catch(Exception $e){ return 1; } }

/* ---------- Ejecución de herramientas ---------- */
function mcp_call($name,$a){
  global $ESTADOS;
  switch($name){
    case 'listar_clientes':
      $rows=db()->query('SELECT id,name,COALESCE(activo,1) activo FROM clients ORDER BY name')->fetchAll();
      return json_encode($rows, JSON_UNESCAPED_UNICODE);
    case 'listar_equipo':
      $rows=db()->query('SELECT id,username FROM admins ORDER BY username')->fetchAll();
      return json_encode($rows, JSON_UNESCAPED_UNICODE);
    case 'listar_listas':
      $cid=resolver_cliente($a['cliente']??''); if(!$cid) throw new Exception('Cliente no encontrado.');
      $q=db()->prepare('SELECT id,nombre,tipo FROM task_lists WHERE client_id=? ORDER BY orden,id'); $q->execute([$cid]);
      return json_encode($q->fetchAll(), JSON_UNESCAPED_UNICODE);
    case 'listar_tareas': {
      $w=[]; $p=[];
      if(!empty($a['cliente'])){ $cid=resolver_cliente($a['cliente']); if($cid){ $w[]='t.client_id=?'; $p[]=$cid; } }
      if(!empty($a['estado']) && in_array($a['estado'],$ESTADOS,true)){ $w[]='t.estado=?'; $p[]=$a['estado']; }
      if(!empty($a['responsable'])){ $rid=resolver_admin($a['responsable']); if($rid){ $w[]='t.responsable_id=?'; $p[]=$rid; } }
      if(!empty($a['texto'])){ $w[]='t.titulo LIKE ?'; $p[]='%'.$a['texto'].'%'; }
      $lim=(int)($a['limite']??50); if($lim<1)$lim=50; if($lim>200)$lim=200;
      $sql='SELECT t.id,t.titulo,t.estado,t.prioridad,t.due_date,t.etiquetas,c.name cliente,l.nombre lista,ad.username responsable FROM tasks t JOIN clients c ON c.id=t.client_id JOIN task_lists l ON l.id=t.list_id LEFT JOIN admins ad ON ad.id=t.responsable_id'.($w?(' WHERE '.implode(' AND ',$w)):'').' ORDER BY t.updated_at DESC, t.id DESC LIMIT '.$lim;
      $q=db()->prepare($sql); $q->execute($p);
      return json_encode($q->fetchAll(), JSON_UNESCAPED_UNICODE);
    }
    case 'crear_tarea': {
      $cid=resolver_cliente($a['cliente']??''); if(!$cid) throw new Exception('Cliente no encontrado.');
      $titulo=trim((string)($a['titulo']??'')); if($titulo==='') throw new Exception('Falta el título.');
      /* lista: por nombre (existente o nueva) o la primera del cliente */
      $lid=0;
      if(!empty($a['lista'])){ $q=db()->prepare('SELECT id FROM task_lists WHERE client_id=? AND nombre LIKE ? LIMIT 1'); $q->execute([$cid,'%'.trim($a['lista']).'%']); $lid=(int)$q->fetchColumn();
        if(!$lid){ $o=(int)db()->query('SELECT COALESCE(MAX(orden),0)+1 FROM task_lists WHERE client_id='.$cid)->fetchColumn(); db()->prepare('INSERT INTO task_lists (client_id,nombre,orden) VALUES (?,?,?)')->execute([$cid,trim($a['lista']),$o]); $lid=(int)db()->lastInsertId(); } }
      if(!$lid){ $lid=(int)db()->query('SELECT id FROM task_lists WHERE client_id='.$cid.' ORDER BY orden,id LIMIT 1')->fetchColumn(); }
      if(!$lid){ db()->prepare('INSERT INTO task_lists (client_id,nombre,orden) VALUES (?,?,0)')->execute([$cid,'Tareas']); $lid=(int)db()->lastInsertId(); }
      $estado=in_array($a['estado']??'',$ESTADOS,true)?$a['estado']:'pendiente';
      $rid=isset($a['responsable'])?resolver_admin($a['responsable']):null;
      $due=(!empty($a['due_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$a['due_date']))?$a['due_date']:null;
      $prio=isset($a['prioridad'])?max(0,min(4,(int)$a['prioridad'])):0;
      $et=trim((string)($a['etiquetas']??''));
      $o=(int)db()->query('SELECT COALESCE(MAX(orden),0)+1 FROM tasks WHERE list_id='.$lid)->fetchColumn();
      db()->prepare('INSERT INTO tasks (client_id,list_id,titulo,estado,responsable_id,prioridad,due_date,etiquetas,orden) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$cid,$lid,$titulo,$estado,$rid,$prio,$due,$et,$o]);
      $nid=(int)db()->lastInsertId();
      return json_encode(['ok'=>true,'id'=>$nid,'mensaje'=>'Tarea creada','url'=>'task.php?id='.$nid], JSON_UNESCAPED_UNICODE);
    }
    case 'actualizar_tarea': {
      $tid=(int)($a['id']??0); if(!$tid) throw new Exception('Falta el id.');
      $ex=db()->prepare('SELECT id FROM tasks WHERE id=?'); $ex->execute([$tid]); if(!$ex->fetchColumn()) throw new Exception('Tarea no encontrada.');
      $set=[]; $p=[];
      if(isset($a['titulo']) && trim($a['titulo'])!==''){ $set[]='titulo=?'; $p[]=trim($a['titulo']); }
      if(isset($a['estado']) && in_array($a['estado'],$ESTADOS,true)){ $set[]='estado=?'; $p[]=$a['estado']; }
      if(isset($a['prioridad'])){ $set[]='prioridad=?'; $p[]=max(0,min(4,(int)$a['prioridad'])); }
      if(array_key_exists('due_date',$a)){ $d=$a['due_date']; $set[]='due_date=?'; $p[]=($d && preg_match('/^\d{4}-\d{2}-\d{2}$/',$d))?$d:null; }
      if(isset($a['responsable'])){ $set[]='responsable_id=?'; $p[]=resolver_admin($a['responsable']); }
      if(isset($a['etiquetas'])){ $set[]='etiquetas=?'; $p[]=trim($a['etiquetas']); }
      if(!$set) throw new Exception('Nada que actualizar.');
      $p[]=$tid; db()->prepare('UPDATE tasks SET '.implode(', ',$set).' WHERE id=?')->execute($p);
      return json_encode(['ok'=>true,'id'=>$tid,'mensaje'=>'Tarea actualizada'], JSON_UNESCAPED_UNICODE);
    }
    case 'etiquetar_tarea': {
      $tid=(int)($a['id']??0); $nue=trim((string)($a['etiquetas']??'')); if(!$tid||$nue==='') throw new Exception('Falta id o etiquetas.');
      $q=db()->prepare('SELECT etiquetas FROM tasks WHERE id=?'); $q->execute([$tid]); $cur=$q->fetch(); if($cur===false) throw new Exception('Tarea no encontrada.');
      $set=array_filter(array_map('trim', explode(',', ($cur['etiquetas']??'').','.$nue)));
      $set=array_values(array_unique($set));
      db()->prepare('UPDATE tasks SET etiquetas=? WHERE id=?')->execute([implode(', ',$set),$tid]);
      return json_encode(['ok'=>true,'id'=>$tid,'etiquetas'=>implode(', ',$set)], JSON_UNESCAPED_UNICODE);
    }
    case 'comentar_tarea': {
      $tid=(int)($a['id']??0); $txt=trim((string)($a['texto']??'')); if(!$tid||$txt==='') throw new Exception('Falta id o texto.');
      $ex=db()->prepare('SELECT id FROM tasks WHERE id=?'); $ex->execute([$tid]); if(!$ex->fetchColumn()) throw new Exception('Tarea no encontrada.');
      db()->prepare('INSERT INTO task_comments (task_id,admin_id,cuerpo) VALUES (?,?,?)')->execute([$tid,mcp_actor(),$txt]);
      return json_encode(['ok'=>true,'id'=>$tid,'mensaje'=>'Comentario añadido'], JSON_UNESCAPED_UNICODE);
    }
  }
  throw new Exception('Herramienta desconocida: '.$name);
}

/* ---------- Enrutado JSON-RPC ---------- */
switch($method){
  case 'initialize':
    rpc_ok($id, ['protocolVersion'=>'2024-11-05','capabilities'=>['tools'=>['listChanged'=>false]],'serverInfo'=>['name'=>'Croilab ERP','version'=>'1.0.0']]);
  case 'ping':
    rpc_ok($id, new stdClass());
  case 'tools/list':
    rpc_ok($id, ['tools'=>mcp_tools()]);
  case 'tools/call':
    $nm=$params['name']??''; $args=$params['arguments']??[];
    try{ $out=mcp_call($nm, is_array($args)?$args:[]); rpc_ok($id, tool_text($out)); }
    catch(Exception $e){ rpc_ok($id, ['content'=>[['type'=>'text','text'=>'Error: '.$e->getMessage()]],'isError'=>true]); }
  default:
    rpc_err($id, -32601, 'Método no soportado: '.$method);
}
