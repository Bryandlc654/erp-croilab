<?php
require __DIR__ . '/auth.php';

/* Vista previa para el equipo: un admin puede ver el portal de cualquier
   cliente con ?cli=ID (solo lectura). El cliente normal entra con su login. */
$previewId = isset($_GET['cli']) ? (int)$_GET['cli'] : 0;
if ($previewId && current_admin()) {
    /* Alcance: un miembro con rol limitado solo previsualiza SUS clientes. Igual
       que en client.php/edit.php; sin esto, ?cli= dejaba ver el portal de cualquiera. */
    if (function_exists('alcance_ve_cliente') && !alcance_ve_cliente($previewId)) { header('Location: admin/index.php'); exit; }
    $st = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$previewId]);
    $cl = $st->fetch();
    if (!$cl) { header('Location: admin/index.php'); exit; }
} else {
    require_client();
    $cl = current_client();
}

/* --- Contacto directo del cliente ---
   El cliente puede escribir a su equipo desde el portal (sección Accesos). El
   mensaje se guarda como un ticket de Soporte del ERP (misma tabla que usa el
   equipo en admin/support.php), con su client_id, para que el equipo lo vea y
   responda. Solo un cliente REAL logueado puede crearlo; un admin en vista
   previa (?cli=) no. El CSRF ya lo validó auth.php. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['portal_action'] ?? '') === 'ticket'
    && !$previewId && current_client()) {
    header('Content-Type: application/json; charset=utf-8');
    $asuntoIn = trim((string)($_POST['asunto'] ?? ''));
    $cuerpoIn = trim((string)($_POST['cuerpo'] ?? ''));
    if ($cuerpoIn === '') { echo json_encode(['ok'=>false,'msg'=>'Escribe tu mensaje.']); exit; }
    /* La tabla la crea normalmente admin/support.php; aquí nos aseguramos de que
       exista por si el equipo aún no ha abierto esa pantalla. Mismo DDL. */
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS support_tickets (
          id INT AUTO_INCREMENT PRIMARY KEY,
          asunto VARCHAR(200) NOT NULL,
          cuerpo TEXT,
          client_id INT DEFAULT NULL,
          prioridad INT DEFAULT 2,
          estado VARCHAR(20) DEFAULT 'abierto',
          assignee_id INT DEFAULT NULL,
          created_by INT DEFAULT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $asunto = $asuntoIn !== '' ? $asuntoIn : ('Mensaje de ' . (string)$cl['name']);
        if (mb_strlen($asunto) > 200) $asunto = mb_substr($asunto, 0, 200);
        db()->prepare('INSERT INTO support_tickets (asunto,cuerpo,client_id,prioridad,estado,created_by) VALUES (?,?,?,?,?,NULL)')
            ->execute([$asunto, $cuerpoIn, (int)$cl['id'], 2, 'abierto']);
        echo json_encode(['ok'=>true]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>'No se pudo enviar. Inténtalo de nuevo.']);
    }
    exit;
}

/* --- Solicitud de reunión del cliente ---
   Mismo molde que el ticket: el cliente pide una reunión y el equipo la aprueba
   en el ERP (admin/reuniones.php). Se guarda en portal_meeting_requests (tabla
   propia porque crm_meetings exige contact_id NOT NULL, que el cliente puede no
   tener). CSRF ya validado por auth.php. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['portal_action'] ?? '') === 'meetreq'
    && !$previewId && current_client()) {
    header('Content-Type: application/json; charset=utf-8');
    $motivo = trim((string)($_POST['motivo'] ?? ''));
    $fecha  = trim((string)($_POST['fecha'] ?? ''));
    $franja = trim((string)($_POST['franja'] ?? ''));
    if ($motivo === '') { echo json_encode(['ok'=>false,'msg'=>'Cuéntanos brevemente el motivo.']); exit; }
    $fechaOk = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) ? $fecha : null;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS portal_meeting_requests (
          id INT AUTO_INCREMENT PRIMARY KEY,
          client_id INT NOT NULL,
          fecha_deseada DATE NULL,
          franja VARCHAR(30) DEFAULT '',
          motivo TEXT,
          estado VARCHAR(20) DEFAULT 'pendiente',
          meeting_id INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->prepare('INSERT INTO portal_meeting_requests (client_id,fecha_deseada,franja,motivo,estado) VALUES (?,?,?,?,\'pendiente\')')
            ->execute([(int)$cl['id'], $fechaOk, mb_substr($franja,0,30), $motivo]);
        echo json_encode(['ok'=>true]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>'No se pudo enviar. Inténtalo de nuevo.']);
    }
    exit;
}

/* Modo edición: solo si es un admin y pide ?edit=1. Es el MISMO portal
   pero con un lápiz en cada bloque para editar en vivo. */
$adminEdit = ($previewId && current_admin() && isset($_GET['edit']) && can_edit());
$editTipos = $adminEdit ? db()->query('SELECT id, nombre FROM client_types ORDER BY nombre')->fetchAll() : [];
$META = [
  'id'           => (int)$cl['id'],
  'name'         => $cl['name'],
  'username'     => $cl['username'],
  'actual'       => $cl['actual'],
  'iniciales'    => $cl['iniciales'],
  'saludo'       => $cl['saludo'],
  'conversiones' => ((int)$cl['conversiones']) === 1,
  'tipo_id'      => (isset($cl['tipo_id']) && $cl['tipo_id'] !== null) ? (int)$cl['tipo_id'] : '',
  'looker'       => $cl['looker_url'] ?? '',
];

$plan = jdecode($cl['plan_json'], ['resumen'=>'','items'=>[],'detalle'=>[]]);
$met  = jdecode($cl['met_json'], []);
$meses = array_keys($met);
$actual = $cl['actual'] !== '' ? $cl['actual'] : (count($meses) ? end($meses) : '');

/* Secciones visibles: si el cliente tiene un "tipo", manda el tipo;
   si no, se usa el interruptor de conversiones (compatibilidad). */
$secciones = ['metricas'=>true,'progreso'=>true,'informes'=>true,'como'=>true,'accesos'=>true,'plan'=>true];
$tipoId = (isset($cl['tipo_id']) && $cl['tipo_id'] !== null) ? (int)$cl['tipo_id'] : 0;
if ($tipoId) {
    try {
        $st = db()->prepare('SELECT secciones_json FROM client_types WHERE id = ?');
        $st->execute([$tipoId]);
        $tj = $st->fetchColumn();
        if ($tj) { $sj = jdecode($tj, []); foreach ($secciones as $k=>$v) $secciones[$k] = !empty($sj[$k]); }
    } catch (Exception $e) { /* si aún no existe la tabla, se ignora */ }
} else {
    $secciones['metricas'] = ((int)$cl['conversiones']) === 1;
}
$verMetricas = $secciones['metricas'];

/* --- Marca blanca: quién firma este portal y a quién escribe el cliente ---
   Todo sale de lib/marca.php, que resuelve agencia colaboradora → casa →
   valor por defecto campo a campo, para que nunca quede un hueco vacío.
   Esto cambia los datos que se pintan, no cómo se pintan. */
require_once __DIR__ . '/admin/lib/marca.php';
require_once __DIR__ . '/admin/lib/servicios_cat.php';
$BRAND    = marca_partner((int)($cl['partner_id'] ?? 0));
$CONTACTO = marca_contacto((int)($cl['partner_id'] ?? 0));

$DATA = [
  'config' => [
    'cliente'      => $cl['name'],
    'iniciales'    => $cl['iniciales'] ?: 'CL',
    'saludo'       => $cl['saludo'] !== '' ? $cl['saludo'] : $cl['name'],
    'conversiones' => $verMetricas,
    'plan'         => $plan,
  ],
  'secciones' => $secciones,
  'estado'  => jdecode($cl['estado_json'], ['nombre'=>'','etiqueta'=>'','siguiente'=>'','fases'=>[]]),
  'accesos' => jdecode($cl['accesos_json'], []),
  'informes'=> jdecode($cl['informes_json'] ?? '', []),
  'servicios'=> jdecode($cl['servicios_json'] ?? '', null),
  'looker'=> $cl['looker_url'] ?? '',
  'cfg'=> [
    /* El contacto sale de la agencia del cliente, no de los ajustes globales.
       Antes un cliente de una agencia colaboradora veía el portal con el logo
       de su agencia y el WhatsApp de la casa: llamaba a quien no debía. */
    'meeting_url'=> $CONTACTO['meeting_url'],
    'video_id'  => get_setting('video_id','J9-aEZ523bA'),
    'whatsapp'  => $CONTACTO['whatsapp'],
    'email'     => $CONTACTO['email'],
    /* El vídeo de cada servicio sale ahora del catálogo de Servicios, así que
       un servicio nuevo puede tener el suyo (antes solo lo tenían seis fijos).
       Las seis claves de siempre siguen aquí como respaldo, y svc_videos() ya
       las hereda si el catálogo aún no las lleva dentro: el portal enseña
       exactamente los mismos vídeos que antes hasta que se cambie alguno. */
    'serv_videos' => svc_videos(),
    'video_web'   => get_setting('video_web',''),
    'video_seo'   => get_setting('video_seo',''),
    'video_sem'   => get_setting('video_sem',''),
    'video_cro'   => get_setting('video_cro',''),
    'video_tienda'=> get_setting('video_tienda',''),
    'video_meta'  => get_setting('video_meta',''),
  ],
  'met'     => (object)$met,
  'meses'   => $meses,
  'actual'  => $actual,
  'tareas'  => (object)jdecode($cl['tareas_json'], []),
];

/* $BRAND y $CONTACTO se resuelven arriba con marca_partner()/marca_contacto():
   antes este bloque repetía la consulta a mano y dejaba «Croilab» escrito como
   valor por defecto aunque en Ajustes hubiera otro nombre. */

/* ===== Datos REALES del cliente para los módulos nuevos del portal =====
   Tareas (con avatares de quién las hace), facturas, reuniones y tickets, todo
   filtrado por el cliente actual. Cada bloque va en try/catch: si una tabla aún
   no existe, se ignora y el portal sigue funcionando. */
$cid = (int)$cl['id'];
if (!function_exists('pcl_avatar_color')) {
  /* Replicados del ERP (erp_nav.php) para que los avatares salgan idénticos. */
  function pcl_avatar_color($s){ $p=['#6366f1','#0ea5e9','#14b8a6','#10b981','#f59e0b','#f97316','#ef4444','#ec4899','#8b5cf6','#3b82f6']; $s=(string)$s;$h=0; for($i=0;$i<strlen($s);$i++)$h=(($h<<5)-$h+ord($s[$i]))&0x7FFFFFFF; return $p[$h%count($p)]; }
  function pcl_ini2($s){ return mb_strtoupper(mb_substr((string)$s,0,2)); }
}
$PORTAL = ['tareas'=>[], 'facturas'=>[], 'reuniones'=>[], 'tickets'=>[], 'solicitudes'=>[], 'accesos_vault'=>[]];

/* --- Tareas visibles para el cliente + asignados (avatares) --- */
try {
  $st = db()->prepare(
    "SELECT t.id, COALESCE(NULLIF(t.titulo_cliente,''),t.titulo) AS titulo,
            COALESCE(NULLIF(t.explicacion_cliente,''),t.descripcion) AS texto, t.estado, t.prioridad, t.mes, t.due_date,
            t.responsable_id, l.nombre AS lista, l.orden AS lorden
       FROM tasks t JOIN task_lists l ON l.id=t.list_id
      WHERE t.client_id=? AND (t.visible_cliente=1 OR l.es_cliente=1 OR l.tipo='informe')
      ORDER BY t.mes DESC, l.orden, t.orden, t.id");
  $st->execute([$cid]);
  foreach ($st->fetchAll() as $r) {
    $ids = [];
    try { $a=db()->prepare('SELECT admin_id FROM task_assignees WHERE task_id=? ORDER BY orden,admin_id'); $a->execute([$r['id']]); $ids=array_map('intval',array_column($a->fetchAll(),'admin_id')); } catch(Exception $e){}
    if (!$ids && !empty($r['responsable_id'])) $ids = [(int)$r['responsable_id']];
    $asig = [];
    foreach ($ids as $aid) {
      try {
        $q=db()->prepare('SELECT username FROM admins WHERE id=?'); $q->execute([$aid]); $un=(string)$q->fetchColumn();
        if ($un==='') continue;
        $foto=''; try{ $qf=db()->prepare('SELECT foto FROM admin_profiles WHERE admin_id=?'); $qf->execute([$aid]); $foto=(string)$qf->fetchColumn(); }catch(Exception $e){}
        $asig[] = ['n'=>$un,'ini'=>pcl_ini2($un),'c'=>pcl_avatar_color($un),'foto'=>$foto? ('archivo.php?d=avatars&f='.rawurlencode($foto)) : ''];
      } catch(Exception $e){}
    }
    $PORTAL['tareas'][] = ['id'=>(int)$r['id'],'titulo'=>$r['titulo'],'texto'=>(string)$r['texto'],'estado'=>$r['estado'],'prioridad'=>(int)$r['prioridad'],'mes'=>(string)$r['mes'],'due'=>$r['due_date'],'lista'=>$r['lista']?:'Tareas','asig'=>$asig];
  }
} catch (Exception $e) {}

/* --- Facturas emitidas del cliente (nunca borradores) --- */
try {
  $st = db()->prepare(
    "SELECT i.id,i.numero,i.fecha,i.fecha_venc,i.estado,
            ROUND(COALESCE(li.base,0)*(1+i.iva_pct/100-i.irpf_pct/100),2) AS total
       FROM invoices i
       LEFT JOIN (SELECT invoice_id,SUM(cantidad*precio) AS base FROM invoice_items GROUP BY invoice_id) li ON li.invoice_id=i.id
      WHERE i.client_id=? AND i.estado IN ('enviada','pagada','vencida')
      ORDER BY i.fecha DESC, i.id DESC");
  $st->execute([$cid]);
  foreach ($st->fetchAll() as $f) $PORTAL['facturas'][] = ['id'=>(int)$f['id'],'numero'=>$f['numero'],'fecha'=>$f['fecha'],'venc'=>$f['fecha_venc'],'estado'=>$f['estado'],'total'=>(float)$f['total']];
} catch (Exception $e) {}

/* --- Reuniones del cliente (tabla local crm_meetings, vía clients.contact_id) --- */
try {
  $st = db()->prepare(
    "SELECT m.id,m.fecha,m.hora,m.titulo,m.estado
       FROM crm_meetings m JOIN clients c ON c.contact_id=m.contact_id
      WHERE c.id=? ORDER BY m.fecha DESC, m.hora DESC, m.id DESC");
  $st->execute([$cid]);
  foreach ($st->fetchAll() as $m) $PORTAL['reuniones'][] = ['id'=>(int)$m['id'],'fecha'=>$m['fecha'],'hora'=>(string)$m['hora'],'titulo'=>(string)$m['titulo'],'estado'=>(string)$m['estado']];
} catch (Exception $e) {}

/* --- Tickets del cliente --- */
try {
  $st = db()->prepare(
    "SELECT t.id,t.asunto,t.estado,t.prioridad,t.created_at,
            (SELECT COUNT(*) FROM support_replies r WHERE r.ticket_id=t.id) AS nresp
       FROM support_tickets t WHERE t.client_id=?
      ORDER BY FIELD(t.estado,'abierto','en_curso','esperando','resuelto','cerrado'), t.updated_at DESC, t.id DESC");
  $st->execute([$cid]);
  foreach ($st->fetchAll() as $t) $PORTAL['tickets'][] = ['id'=>(int)$t['id'],'asunto'=>$t['asunto'],'estado'=>(string)$t['estado'],'nresp'=>(int)$t['nresp'],'fecha'=>$t['created_at']];
} catch (Exception $e) {}

/* --- Credenciales de la bóveda marcadas como visibles para el cliente --- */
try {
  $st = db()->prepare("SELECT titulo,categoria,usuario,secreto,url,nota FROM client_credentials WHERE client_id=? AND visible_cliente=1 ORDER BY orden,id");
  $st->execute([$cid]);
  foreach ($st->fetchAll() as $c) $PORTAL['accesos_vault'][] = ['t'=>(string)$c['titulo'],'cat'=>(string)$c['categoria'],'u'=>(string)$c['usuario'],'s'=>(string)$c['secreto'],'url'=>(string)$c['url'],'nota'=>(string)$c['nota']];
} catch (Exception $e) {}

/* --- Solicitudes de reunión del cliente (para mostrarle su estado) --- */
try {
  $st = db()->prepare("SELECT id,fecha_deseada,franja,motivo,estado,created_at FROM portal_meeting_requests WHERE client_id=? ORDER BY id DESC");
  $st->execute([$cid]);
  foreach ($st->fetchAll() as $s) $PORTAL['solicitudes'][] = ['id'=>(int)$s['id'],'fecha'=>$s['fecha_deseada'],'franja'=>(string)$s['franja'],'motivo'=>(string)$s['motivo'],'estado'=>(string)$s['estado']];
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<script>(function(){try{if(localStorage.getItem('portalTheme')==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>"><?php /* Lo necesita el formulario de contacto del cliente y el modo edición. */ ?>
<title><?= e($BRAND['name']) ?> · Área de cliente</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css">
<script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/js/jsvectormap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/maps/world.js"></script>
<style>
  :root{
    --bg:#f5f5f7;
    --card:#ffffff;
    --card-2:#ffffff;
    --dark:#0f1012;
    --dark-2:#1f232a;
    --ink:#22262c;
    --muted:#9aa0a8;
    --line:#eeeeef;
    --line-d:rgba(255,255,255,.12);
    /* Acento del ERP (antes azul del portal). Se conserva el nombre --yellow por
       compatibilidad: lo usan decenas de reglas. Ahora es el neutro oscuro del ERP. */
    --yellow:#1f232a;
    --yellow-d:#0f1113;
    --yellow-soft:#f2f2f3;
    --coral:#5ac8fa;
    --green:#12a150;
    --radius:20px;
    --radius-m:14px;
    --radius-s:10px;
    --shadow:0 1px 2px rgba(16,19,24,.04),0 10px 26px -18px rgba(16,19,24,.12);
    --shadow-h:0 12px 30px -18px rgba(16,19,24,.16);
    --ease:cubic-bezier(.16,1,.3,1);
    /* Curvas premium del dashboard de Métricas (se usan en toda la sección):
       --ease-expo  = entradas suaves con desaceleración marcada
       --ease-spring= pops/barras con un rebote sutil (overshoot controlado) */
    --ease-expo:cubic-bezier(.16,1,.3,1);
    --ease-spring:cubic-bezier(.34,1.56,.64,1);
    /* alias para pegar componentes del ERP sin retocar (raíl, barra, filas de tarea) */
    --soft:#f7f7f8;
    --line2:#f6f6f7;
    --ink-strong:#22262c;
    --accent:#1f232a;
    --ring:#c4c4c7;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{height:100%}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--ink);-webkit-font-smoothing:antialiased;font-size:15px;line-height:1.5;overflow-x:hidden}
  button{font-family:inherit;cursor:pointer}
  a{color:inherit;text-decoration:none}
  /* Foco unificado y limpio: sin el recuadro por defecto del navegador al pinchar;
     anillo limpio solo al navegar con teclado (:focus-visible). */
  :focus{outline:none}
  :focus-visible{outline:2px solid var(--ring);outline-offset:2px}
  input:focus-visible,textarea:focus-visible,select:focus-visible,.cs-trig:focus-visible,.selbox select:focus-visible{outline:none}
  /* Color de selección de texto (moderno, no negro) */
  ::selection{background:#e4e5e8;color:#0f1216}
  [data-theme=dark] ::selection{background:#3a3d44;color:#f5f7fa}
  .hidden{display:none !important}
  body.no-conv .conv-only{display:none !important}

  /* ---------- LOGIN ---------- */
  #login{position:fixed;inset:0;background:linear-gradient(165deg,#fbfbfd 0%,#eef1f6 60%,#e7ebf3 100%);display:flex;align-items:center;justify-content:center;padding:20px;z-index:60;overflow:hidden}
  #login .gl{position:absolute;border-radius:50%;filter:blur(90px);opacity:.45}
  #login .gl.y{width:380px;height:380px;background:#7db8ff;top:-90px;right:-60px}
  #login .gl.c{width:320px;height:320px;background:#c9b8ff;bottom:-100px;left:-40px;opacity:.4}
  .login-card{position:relative;background:rgba(255,255,255,.78);backdrop-filter:blur(26px) saturate(180%);-webkit-backdrop-filter:blur(26px) saturate(180%);border:1px solid rgba(255,255,255,.9);border-radius:28px;padding:42px 36px;width:100%;max-width:392px;box-shadow:0 30px 70px rgba(20,30,60,.16);animation:popIn .7s var(--ease) both}
  @keyframes popIn{from{opacity:0;transform:translateY(26px) scale(.96);filter:blur(10px)}to{opacity:1;transform:none;filter:blur(0)}}
  .login-card .logo{width:52px;height:52px;border-radius:17px;background:var(--yellow);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:21px;margin-bottom:22px;box-shadow:0 8px 22px rgba(16,19,24,.35)}
  .login-card h1{font-size:23px;font-weight:700;margin-bottom:5px;letter-spacing:-.3px}
  .login-card p.sub{color:var(--muted);font-size:14px;margin-bottom:26px}
  .field{margin-bottom:14px}
  .field label{display:block;font-size:12.5px;color:var(--muted);margin-bottom:7px;font-weight:600}
  .field input{width:100%;border:1px solid var(--line);background:rgba(255,255,255,.8);border-radius:14px;padding:13px 16px;font-size:14.5px;color:var(--ink);outline:none;transition:.2s}
  .field input:focus{border-color:var(--yellow-d);box-shadow:0 0 0 4px var(--yellow-soft)}
  .btn{width:100%;background:var(--dark);color:#fff;border:none;border-radius:14px;padding:14px;font-size:15px;font-weight:600;transition:.2s var(--ease)}
  .btn:hover{background:#000;transform:translateY(-2px)}
  .login-hint{margin-top:18px;font-size:12px;color:var(--muted);text-align:center;background:rgba(0,0,0,.04);border-radius:11px;padding:11px}

  /* ---------- SHELL: raíl oscuro + barra lateral clara (estilo ERP) ---------- */
  #app{display:flex;min-height:100vh}

  /* raíl oscuro fino */
  .prail{width:62px;flex:none;background:var(--dark);display:flex;flex-direction:column;align-items:center;padding:11px 0;gap:5px;height:100vh;position:sticky;top:0;z-index:30}
  .prail .rlogo{width:38px;height:38px;border-radius:11px;background:#fff;color:var(--dark);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;margin-bottom:8px;overflow:hidden;flex:none}
  .prail .rlogo img{width:100%;height:100%;object-fit:contain}
  .prail .rsp{flex:1}
  .prail .rico{width:44px;height:44px;border-radius:13px;display:flex;align-items:center;justify-content:center;color:#7e838d;transition:.18s var(--ease);position:relative;cursor:pointer;text-decoration:none;flex:none}
  .prail .rico svg{width:20px;height:20px;stroke:currentColor;fill:none}
  .prail .rico:hover{background:#1e2024;color:#fff}
  .prail .rav{width:34px;height:34px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12.5px;border:1px solid #34363c;flex:none}

  /* barra lateral clara con opciones y texto */
  .pside{width:236px;flex:none;background:#fff;border-right:1px solid var(--line);display:flex;flex-direction:column;height:100vh;position:sticky;top:0;overflow:auto;z-index:25}
  .pside::-webkit-scrollbar{width:0}
  .pside .psh{display:flex;align-items:center;gap:11px;padding:18px 18px 6px}
  .pside .psh .plogo{width:32px;height:32px;border-radius:9px;background:var(--dark);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex:none;overflow:hidden}
  .pside .psh .plogo img{width:100%;height:100%;object-fit:contain}
  .pside .psh b{font-size:15px;font-weight:700;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--ink-strong)}
  .pside .psec{font-size:10.5px;text-transform:uppercase;letter-spacing:.7px;color:#aeb2ba;font-weight:700;padding:16px 20px 5px}
  .pside .pnav{display:flex;flex-direction:column;padding:0 10px;gap:2px}
  .pside .pnav a{display:flex;align-items:center;gap:11px;padding:9px 11px;border-radius:9px;color:#5a5f68;font-size:13.5px;font-weight:500;cursor:pointer;transition:.14s var(--ease)}
  .pside .pnav a svg{width:18px;height:18px;stroke:currentColor;fill:none;flex:none;color:#a4a9b1}
  .pside .pnav a .cnt{margin-left:auto;font-size:11px;background:var(--yellow-soft);color:var(--ink);border-radius:99px;padding:0 8px;font-weight:700;min-width:20px;text-align:center}
  .pside .pnav a:hover{background:var(--soft);transform:translateX(2px)}
  .pside .pnav a:hover svg{color:var(--ink)}
  .pside .pnav a.active{background:var(--soft);color:var(--ink);font-weight:650}
  .pside .pnav a.active svg{color:var(--ink)}
  .pside .pfoot{position:sticky;bottom:0;margin-top:auto;background:#fff;border-top:1px solid var(--line);padding:9px 10px}
  .pside .pfoot .urow{display:flex;align-items:center;gap:10px;padding:7px 8px;border-radius:10px}
  .pside .pfoot .av{width:32px;height:32px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex:none}
  .pside .pfoot .ui{flex:1;min-width:0}
  .pside .pfoot .ui b{font-size:13px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--ink-strong)}
  .pside .pfoot .ui span{font-size:11px;color:var(--muted)}
  .pside .pfoot .out{color:#b5b9c1;padding:8px;border-radius:9px;display:inline-flex;text-decoration:none}
  .pside .pfoot .out:hover{color:var(--ink);background:var(--soft)}
  .pside .pfoot .out svg{width:17px;height:17px;stroke:currentColor;fill:none}

  .main{flex:1;min-width:0;display:flex;flex-direction:column;padding:14px 22px 30px}

  /* greeting header */
  .head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:8px 10px 22px;flex-wrap:wrap}
  .head .hi h1{font-size:26px;font-weight:750;letter-spacing:-.5px}
  .head .hi p{color:var(--muted);font-size:14px;margin-top:2px}
  .head .tools{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .search{display:flex;align-items:center;gap:9px;background:var(--yellow-soft);border:1px solid var(--line);border-radius:12px;padding:9px 15px;min-width:210px}
  .search:focus-within{border-color:#c9ccd1;background:#fff}
  .search svg{width:17px;height:17px;stroke:var(--muted);fill:none;flex:none}
  .search input{border:none;background:none;outline:none;font-size:14px;color:var(--ink);width:100%}
  .search input::placeholder{color:var(--muted)}
  .pill-btn{background:var(--yellow);color:#fff;border:none;border-radius:11px;padding:11px 18px;font-size:14px;font-weight:600;display:inline-flex;align-items:center;gap:8px;transition:.18s var(--ease)}
  .pill-btn:hover{filter:brightness(1.15)}
  .pill-btn svg{width:16px;height:16px;stroke:currentColor;fill:none}
  .pill-btn:hover{background:#000;transform:translateY(-2px)}
  .menu-btn{display:none;background:var(--card);border:none;border-radius:14px;padding:10px;box-shadow:var(--shadow);color:var(--ink)}

  .content{flex:1}
  .view{display:none}
  .view.active{display:block}
  .view.active > *{animation:blurUp .6s var(--ease) both}
  .view.active > *:nth-child(1){animation-delay:.02s}
  .view.active > *:nth-child(2){animation-delay:.09s}
  .view.active > *:nth-child(3){animation-delay:.16s}
  .view.active > *:nth-child(4){animation-delay:.23s}
  .view.active > *:nth-child(5){animation-delay:.30s}
  .view.active > *:nth-child(6){animation-delay:.37s}
  .view.active > *:nth-child(7){animation-delay:.44s}
  .view.active > *:nth-child(8){animation-delay:.51s}
  .view.active > *:nth-child(9){animation-delay:.58s}
  @keyframes blurUp{from{opacity:0;transform:translateY(16px);filter:blur(9px)}to{opacity:1;transform:none;filter:blur(0)}}

  h2.sec{font-size:12px;text-transform:uppercase;letter-spacing:.9px;color:var(--muted);font-weight:700;margin:26px 6px 14px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:24px 26px;box-shadow:var(--shadow)}
  .card.tight{padding:20px 22px}

  .grid2{display:grid;grid-template-columns:1.5fr 1fr;gap:14px}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:14px}

  /* glow results card */
  .glow{position:relative;overflow:hidden;background:#f1eee8}
  .glow h3{font-size:17px;font-weight:700}
  .glow .stage{position:relative;height:236px;margin-top:6px}
  .glow .gb{position:absolute;border-radius:50%;filter:blur(34px)}
  .glow .gb.y{width:190px;height:190px;background:var(--yellow);top:24px;right:60px;opacity:.85}
  .glow .gb.c{width:140px;height:140px;background:var(--coral);top:96px;right:150px;opacity:.7}
  .bubble{position:absolute;background:var(--dark);color:#fff;border-radius:20px;padding:12px 16px;text-align:center;box-shadow:0 14px 30px rgba(0,0,0,.18);animation:floaty 6s ease-in-out infinite}
  .bubble b{font-size:21px;font-weight:750;display:block;line-height:1}
  .bubble span{font-size:11px;color:#b9b6b0}
  .bubble.b-a{top:30px;right:96px;animation-delay:-1s}
  .bubble.b-b{top:120px;right:188px;background:var(--yellow);color:var(--dark);animation-delay:-3s}
  .bubble.b-b span{color:#6b5a16}
  .bubble.b-c{top:150px;right:50px;animation-delay:-2s}
  @keyframes floaty{0%,100%{transform:translateY(0)}50%{transform:translateY(-9px)}}
  .legend{display:flex;flex-direction:column;gap:9px;position:absolute;left:2px;bottom:6px}
  .legend div{display:flex;align-items:center;gap:9px;font-size:12.5px;color:var(--muted)}
  .legend i{width:22px;height:7px;border-radius:99px}

  /* dark project card */
  .dark-card{background:var(--dark);color:#fff;border-radius:var(--radius);padding:24px 26px;box-shadow:var(--shadow);position:relative;overflow:hidden}
  .dark-card h3{font-size:15px;font-weight:600;color:#fff}
  .dark-card .tag{font-size:11.5px;color:var(--yellow);background:rgba(244,206,75,.14);padding:4px 11px;border-radius:99px;font-weight:600}
  .donut-wrap{display:flex;justify-content:center;margin:14px 0 18px}
  .donut{width:152px;height:152px;border-radius:50%;display:flex;align-items:center;justify-content:center;position:relative;background:conic-gradient(var(--yellow) 0 0,rgba(255,255,255,.10) 0 100%);transition:background 1.1s var(--ease)}
  .donut .hole{width:112px;height:112px;border-radius:50%;background:var(--dark);display:flex;flex-direction:column;align-items:center;justify-content:center}
  .donut .hole b{font-size:28px;font-weight:750;line-height:1}
  .donut .hole span{font-size:11px;color:#a9a6a0;margin-top:2px}
  .dstat{margin-bottom:13px}
  .dstat .l{display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:7px}
  .dstat .l span:first-child{color:#cfccc6}
  .dstat .l span:last-child{color:#fff;font-weight:600}
  .seg{display:flex;gap:3px}
  .seg i{height:13px;flex:1;border-radius:4px;background:rgba(255,255,255,.13)}
  .seg i.on{background:var(--yellow)}

  /* hero estado (limpio) */
  .hero-status{display:flex;align-items:center;gap:30px;justify-content:space-between}
  .hero-status .hs-left{flex:1;min-width:0}
  .tag-dark{display:inline-block;font-size:11.5px;font-weight:700;color:#fff;background:var(--dark);padding:5px 13px;border-radius:99px;margin-bottom:13px}
  .hero-status h3{font-size:21px;font-weight:750;letter-spacing:-.3px}
  .hero-status .bar{height:11px;background:#e6e6ea;border-radius:99px;overflow:hidden;margin:17px 0 14px}
  .hero-status .bar>i{display:block;height:100%;width:0;background:var(--yellow);border-radius:99px;transition:width 1.2s var(--ease)}
  .hero-status .hs-next{font-size:14px;color:var(--muted)}
  .hero-status .hs-next b{color:var(--ink);font-weight:600}
  .hero-status .hs-right{flex:none}
  .hero-status .donut{width:142px;height:142px;background:conic-gradient(var(--yellow) 0 0,#e6e6ea 0 100%);transition:background 1.2s var(--ease)}
  .hero-status .donut .hole{width:106px;height:106px;background:var(--card)}
  .hero-status .donut .hole b{color:var(--ink);font-size:26px}
  .hero-status .donut .hole span{color:var(--muted)}
  @media(max-width:720px){.hero-status{flex-direction:column;align-items:flex-start;gap:18px}.hero-status .hs-right{align-self:center}}

  /* roadmap */
  .road{display:flex}
  .road .step{flex:1;position:relative;text-align:center}
  .road .step .dotn{width:34px;height:34px;border-radius:50%;margin:0 auto 9px;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;background:var(--line);color:var(--muted);position:relative;z-index:2}
  .road .step.done .dotn{background:var(--ink);color:var(--yellow)}
  .road .step.now .dotn{background:var(--yellow);color:var(--dark);box-shadow:0 0 0 6px var(--yellow-soft)}
  .road .step::before{content:"";position:absolute;top:16px;right:50%;width:100%;height:3px;background:var(--line);z-index:1;border-radius:3px}
  .road .step:first-child::before{display:none}
  .road .step.done::before,.road .step.now::before{background:var(--ink)}
  .road .step b{font-size:13px;font-weight:600;display:block;line-height:1.3}
  .road .step small{font-size:11px;color:var(--muted)}

  /* próxima reunión / pasos */
  .ttl-ic{font-size:14.5px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:9px}
  .ttl-ic svg{width:18px;height:18px;stroke:var(--ink);fill:none}
  .meet{display:flex;align-items:center;gap:15px}
  .meet .cal{width:54px;height:54px;border-radius:17px;background:var(--yellow);color:var(--dark);display:flex;flex-direction:column;align-items:center;justify-content:center;flex:none;line-height:1.1}
  .meet .cal small{font-size:9.5px;text-transform:uppercase;font-weight:800;letter-spacing:.5px}
  .meet .cal b{font-size:20px;font-weight:800}
  .meet .mt b{font-size:14.5px;display:block}
  .meet .mt span{font-size:12.5px;color:var(--muted)}
  .steps{list-style:none;display:flex;flex-direction:column;gap:12px}
  .steps li{display:flex;gap:12px;font-size:13.5px;color:#46423b;line-height:1.5}
  .steps li em{width:8px;height:8px;border-radius:50%;background:var(--yellow);box-shadow:0 0 0 4px var(--yellow-soft);margin-top:6px;flex:none}

  /* KPI soft */
  .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
  .kpi{background:var(--card);border-radius:var(--radius-m);padding:19px 20px;box-shadow:var(--shadow);transition:.24s var(--ease)}
  .kpi:hover{transform:translateY(-4px);box-shadow:var(--shadow-h)}
  .kpi .lbl{font-size:12.5px;color:var(--muted);font-weight:500}
  .kpi .num{font-size:27px;font-weight:750;margin-top:7px;line-height:1}
  .kpi .delta{font-size:11.5px;margin-top:7px;color:var(--green);font-weight:600}

  .note{font-size:12.5px;color:var(--muted);margin:0 6px 14px}

  /* HUB */
  .hub{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
  .tile{display:flex;align-items:flex-start;gap:15px;text-align:left;background:var(--card);border:none;border-radius:var(--radius);padding:20px 22px;cursor:pointer;transition:.26s var(--ease);width:100%;box-shadow:var(--shadow)}
  .tile:hover{transform:translateY(-5px);box-shadow:var(--shadow-h)}
  .tile .ic{width:46px;height:46px;border-radius:15px;background:var(--yellow-soft);color:var(--yellow-d);display:flex;align-items:center;justify-content:center;flex:none}
  .tile .ic svg{width:22px;height:22px;stroke:currentColor;fill:none}
  .tile .tx{flex:1;min-width:0}
  .tile .tx b{font-size:15.5px;font-weight:700}
  .tile .tx p{font-size:13px;color:var(--muted);margin-top:3px}
  .tile .tx .meta{font-size:12px;color:var(--ink);font-weight:700;margin-top:10px;display:inline-flex;align-items:center;gap:5px}
  .tile .arrow{margin-left:auto;color:#cfcabf;transition:.24s;flex:none}
  .tile:hover .arrow{color:var(--ink);transform:translateX(4px)}

  /* equipo */
  .team{display:grid;grid-template-columns:repeat(2,1fr);gap:13px}
  .member{display:flex;align-items:center;gap:13px;background:var(--card);border-radius:var(--radius-m);padding:14px 18px;box-shadow:var(--shadow);transition:.24s var(--ease)}
  .member:hover{transform:translateY(-3px);box-shadow:var(--shadow-h)}
  .member .mav{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:#fff;flex:none}
  .member b{font-size:14px;display:block}
  .member span{font-size:12px;color:var(--muted)}

  /* novedades */
  .feed .fitem{display:flex;gap:15px;padding-bottom:18px;position:relative}
  .feed .fitem:last-child{padding-bottom:0}
  .feed .fitem .fdot{width:12px;height:12px;border-radius:50%;background:var(--yellow);box-shadow:0 0 0 4px var(--yellow-soft);flex:none;margin-top:3px;position:relative;z-index:2}
  .feed .fitem::before{content:"";position:absolute;left:5px;top:15px;bottom:-3px;width:2px;background:var(--line)}
  .feed .fitem:last-child::before{display:none}
  .feed .fitem .fc b{font-size:13.5px;font-weight:600;display:block}
  .feed .fitem .fc span{font-size:12px;color:var(--muted)}

  /* métricas */
  .metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
  @media(max-width:560px){.metrics{grid-template-columns:1fr 1fr}}
  .metric{background:var(--card);border-radius:var(--radius-m);padding:22px 12px;text-align:center;box-shadow:var(--shadow);transition:.24s var(--ease)}
  .metric:hover{transform:translateY(-4px);box-shadow:var(--shadow-h)}
  .metric .num{font-size:27px;font-weight:750;line-height:1.1}
  .metric .lbl{font-size:13px;color:var(--muted);margin-top:6px}
  .metric .delta{font-size:11px;color:var(--green);margin-top:5px;font-weight:600}
  .total{text-align:center;margin:18px 0 4px;font-size:15px}
  .total .hi{background:var(--yellow);color:var(--dark);font-weight:700;padding:4px 13px;border-radius:99px}
  .chart-card{margin-top:14px}
  .chart-card h4{font-size:15px;font-weight:700;margin-bottom:3px}
  .chart-card p{font-size:12.5px;color:var(--muted);margin-bottom:18px}
  .bars{display:flex;align-items:flex-end;gap:16px;height:168px;padding-top:10px}
  .bars .b{flex:1;display:flex;flex-direction:column;align-items:center;gap:9px;height:100%;justify-content:flex-end}
  .bars .b .col{width:100%;max-width:48px;background:#dfe9f7;border-radius:12px 12px 8px 8px;height:0;transition:height 1s var(--ease)}
  .bars .b .col.on{background:var(--yellow)}
  .metric .delta.down{color:#d1453b}
  .bars .b .v{font-size:12px;font-weight:700}
  .bars .b .m{font-size:11.5px;color:var(--muted)}
  /* gráfico de barras con línea de evolución */
  .lc{padding:24px 4px 0}
  .lc-plot{position:relative;display:flex;align-items:flex-end;gap:16px;height:150px}
  .lc-bar{flex:1;max-width:46px;margin:0 auto;background:#dfe9f7;border-radius:12px 12px 8px 8px;position:relative;transition:height .9s var(--ease)}
  .lc-bar.on{background:var(--yellow)}
  .lc-v{position:absolute;top:-21px;left:0;right:0;text-align:center;font-size:12px;font-weight:700;color:var(--ink)}
  .lc-line{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:visible}
  .lc-months{display:flex;gap:16px;margin-top:9px}
  .lc-months span{flex:1;text-align:center;font-size:11.5px;color:var(--muted)}
  .frame{margin-top:14px;text-align:center;color:var(--muted);font-size:13px;background:linear-gradient(135deg,#ffffff 0%,#f6f6f7 100%);border-radius:var(--radius-m);padding:36px 16px;box-shadow:var(--shadow)}

  /* oportunidades destacadas */
  .opp-hero{display:flex;align-items:center;gap:18px;background:linear-gradient(135deg,var(--yellow) 0%,#3a3f47 100%);color:#fff;border-radius:var(--radius-m);padding:20px 24px;margin:14px 0;box-shadow:0 10px 26px rgba(16,19,24,.22)}
  .opp-hero .oh-num{font-size:46px;font-weight:800;line-height:1;letter-spacing:-1.5px;flex:none}
  .opp-hero .oh-tx b{font-size:16px;font-weight:700;display:block;line-height:1.2}
  .opp-hero .oh-tx span{font-size:12.5px;opacity:.9;display:block;margin-top:4px}

  /* enlaces entre secciones */
  .xlinks{display:flex;gap:10px;flex-wrap:wrap;margin:4px 0 8px}
  .xlink{display:inline-flex;align-items:center;gap:8px;font-size:13.5px;font-weight:600;color:var(--yellow);background:var(--yellow-soft);border:none;border-radius:99px;padding:10px 16px;cursor:pointer;transition:.2s var(--ease)}
  .xlink:hover{background:#e9e9eb;transform:translateY(-2px)}
  .xlink svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:1.9}

  /* nav destacado */
  .nav a.feat{background:rgba(255,255,255,.06);color:#c7ccd3}
  .nav a.feat:hover{background:rgba(255,255,255,.11);color:#fff}
  .nav a.feat.active{background:rgba(255,255,255,.16);color:#fff}

  /* cómo trabajamos: vídeo + form */
  .video-card{position:relative;max-width:520px;aspect-ratio:16/9;border-radius:var(--radius-m);overflow:hidden;cursor:pointer;box-shadow:var(--shadow);margin-top:18px;background:#000}
  .video-card img{width:100%;height:100%;object-fit:cover;display:block;transition:.35s var(--ease)}
  .video-card:hover img{transform:scale(1.05)}
  .video-card::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.05),rgba(0,0,0,.4));z-index:1}
  .vplay{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,.96);display:flex;align-items:center;justify-content:center;z-index:2;box-shadow:0 8px 24px rgba(0,0,0,.3);animation:vpulse 2s ease-in-out infinite;transition:.25s var(--ease)}
  .vplay svg{width:26px;height:26px;margin-left:3px;fill:var(--yellow)}
  .video-card:hover .vplay{transform:translate(-50%,-50%) scale(1.1)}
  @keyframes vpulse{0%,100%{box-shadow:0 0 0 0 rgba(255,255,255,.55)}50%{box-shadow:0 0 0 16px rgba(255,255,255,0)}}
  .vlabel{position:absolute;left:14px;bottom:12px;z-index:2;color:#fff;font-size:13px;font-weight:600;text-shadow:0 1px 5px rgba(0,0,0,.6)}

  /* FAQs / desplegables (mismo gesto que las tareas del ERP: chevron que gira) */
  .faq{background:var(--card);border:1px solid var(--line);border-radius:var(--radius-m);margin-bottom:10px;box-shadow:var(--shadow);overflow:hidden;transition:box-shadow .22s var(--ease),border-color .22s var(--ease)}
  .faq:hover{box-shadow:var(--shadow-h)}
  .faq.open{border-color:#e2e3e6}
  .faq-q{display:flex;align-items:center;gap:12px;padding:16px 18px;cursor:pointer;font-weight:600;font-size:14.5px;user-select:none}
  .faq-q span{flex:1}
  .faq-q .qm{color:#c2bdb2;transition:transform .28s var(--ease);flex:none}
  .faq.open .faq-q .qm{transform:rotate(180deg)}
  .faq.open .faq-q{color:var(--ink-strong,var(--ink))}
  .faq-a{max-height:0;opacity:0;overflow:hidden;transition:max-height .32s var(--ease),opacity .25s,padding .25s}
  .faq.open .faq-a{max-height:300px;opacity:1;padding:0 18px 16px}
  .faq.long.open .faq-a{max-height:1600px}
  .faq-a p{font-size:13.5px;color:var(--muted);line-height:1.6}
  .legal b{display:block;color:var(--ink);font-size:13.5px;font-weight:700;margin:15px 0 4px}
  .legal b:first-child{margin-top:0}
  .legal p{font-size:13px;color:var(--muted);line-height:1.6;margin-bottom:2px}
  .form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  .form-field{margin-bottom:14px}
  .form-field label{font-size:12.5px;color:var(--muted);font-weight:600;display:block;margin-bottom:7px}
  .form-field input,.form-field select,.form-field textarea{width:100%;border:1px solid var(--line);border-radius:12px;padding:12px 14px;font-size:14.5px;font-family:inherit;color:var(--ink);background:#fff;outline:none;transition:.18s}
  .form-field input:focus,.form-field select:focus,.form-field textarea:focus{border-color:var(--yellow);box-shadow:0 0 0 4px var(--yellow-soft)}
  .form-field textarea{min-height:94px;resize:vertical}
  .form-submit{background:var(--yellow);color:#fff;border:none;border-radius:12px;padding:13px 24px;font-size:15px;font-weight:600;transition:.2s var(--ease);box-shadow:0 8px 20px rgba(16,19,24,.25)}
  .form-submit:hover{background:var(--yellow-d);transform:translateY(-2px)}
  .form-ok{background:#e7f6ec;color:var(--green);border-radius:12px;padding:18px;font-size:14.5px;font-weight:600;text-align:center}
  @media(max-width:560px){.form-row{grid-template-columns:1fr}}

  /* calculadora de facturación */
  .calc{margin-top:14px;background:linear-gradient(135deg,#ffffff 0%,#f6f6f7 100%)}
  .calc-base{display:flex;align-items:center;justify-content:space-between;gap:14px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:13px 16px;margin-bottom:16px}
  .calc-base .cb-l b{font-size:14px;font-weight:600;display:block}
  .calc-base .cb-l span{font-size:12px;color:var(--muted)}
  .calc-base .cb-edit{display:flex;align-items:center;gap:9px;background:var(--yellow-soft);border-radius:11px;padding:8px 14px}
  .calc-base .cb-edit input{border:none;background:none;outline:none;width:66px;font-size:21px;font-weight:800;color:var(--yellow);text-align:right}
  .calc-base .cb-edit svg{width:15px;height:15px;stroke:var(--yellow);fill:none;opacity:.75}
  .calc h4{font-size:15px;font-weight:700;margin-bottom:3px}
  .calc .sub{font-size:12.5px;color:var(--muted);margin-bottom:20px}
  .calc-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px}
  .calc-field label{font-size:12.5px;color:var(--muted);font-weight:600;display:flex;justify-content:space-between;align-items:center;margin-bottom:9px}
  .calc-field label b{color:var(--yellow);font-size:14px}
  .calc-field input[type=range]{width:100%;accent-color:var(--yellow);height:6px}
  .calc-input{display:flex;align-items:center;gap:6px;border:1px solid var(--line);border-radius:12px;padding:9px 14px;background:#fff}
  .calc-input span{color:var(--muted);font-size:15px;font-weight:600}
  .calc-input input{border:none;outline:none;font-size:15px;font-weight:700;width:100%;color:var(--ink);background:none}
  .calc-out{display:flex;gap:13px;flex-wrap:wrap}
  .calc-out .o{flex:1;min-width:150px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px}
  .calc-out .o .lbl{font-size:12.5px;color:var(--muted)}
  .calc-out .o .v{font-size:30px;font-weight:800;margin-top:7px;line-height:1;color:var(--ink)}
  .calc-out .o.hi .v{color:var(--yellow)}
  .calc-note{font-size:12px;color:var(--muted);margin-top:15px}
  @media(max-width:560px){.calc-grid{grid-template-columns:1fr}}

  /* progreso */
  .months{display:flex;gap:10px;overflow-x:auto;padding:2px 2px 8px;scrollbar-width:none}
  .months::-webkit-scrollbar{display:none}
  .mchip{flex:none;border:none;background:var(--card);border-radius:99px;padding:10px 18px;font-size:13.5px;font-weight:600;color:var(--muted);transition:.22s var(--ease);box-shadow:var(--shadow)}
  .mchip:hover{color:var(--ink);transform:translateY(-2px)}
  .mchip.active{background:var(--dark);color:#fff}
  .tabs{display:flex;gap:5px;background:var(--card);border-radius:99px;padding:5px;width:max-content;margin:16px 0 6px;box-shadow:var(--shadow)}
  .tab{border:none;background:none;padding:9px 20px;border-radius:99px;font-size:13.5px;font-weight:600;color:var(--muted);transition:.22s var(--ease)}
  .tab.active{background:var(--yellow);color:#fff}
  .tab .cnt{font-size:11px;background:rgba(0,0,0,.07);color:var(--muted);border-radius:99px;padding:1px 8px;margin-left:6px}
  .tab.active .cnt{background:rgba(255,255,255,.28);color:#fff}
  #taskList{margin-top:6px}
  .task{background:var(--card);border-radius:var(--radius-m);margin-bottom:11px;overflow:hidden;box-shadow:var(--shadow);transition:.22s var(--ease);animation:blurUp .5s var(--ease) both}
  .task:hover{box-shadow:var(--shadow-h)}
  .task-head{display:flex;align-items:center;gap:14px;padding:18px 20px;cursor:pointer;user-select:none}
  .task-st{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex:none}
  .st-done{background:var(--ink);color:var(--yellow)}
  .st-pend{background:var(--yellow-soft);color:var(--yellow-d)}
  .task-head .tt{font-size:14.5px;font-weight:600;flex:1}
  .chev{color:#c2bdb2;transition:transform .25s var(--ease);flex:none}
  .task.open .chev{transform:rotate(180deg)}
  .task-body{max-height:0;opacity:0;transition:max-height .34s var(--ease),opacity .25s,padding .25s}
  .task.open .task-body{max-height:220px;opacity:1;padding:0 20px 18px 60px}
  .task-body p{font-size:13.5px;color:var(--muted);line-height:1.6}

  /* recursos */
  .res-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
  .res{display:flex;align-items:center;gap:15px;background:var(--card);border-radius:var(--radius);padding:18px 20px;transition:.26s var(--ease);box-shadow:var(--shadow)}
  .res:hover{transform:translateY(-5px);box-shadow:var(--shadow-h)}
  .res .ic{width:48px;height:48px;border-radius:15px;display:flex;align-items:center;justify-content:center;flex:none;color:#fff}
  .res .ic.brand{background:#f4f4f7}
  .res .ic.brand svg{width:24px;height:24px}
  .res .ic svg{width:23px;height:23px;stroke:currentColor;fill:none}
  .res .tx{flex:1;min-width:0}
  .res .tx b{font-size:15px;font-weight:700;display:block}
  .res .tx span{font-size:12.5px;color:var(--muted)}
  .res .go{color:#cfcabf;transition:.24s;flex:none}
  .res:hover .go{color:var(--ink);transform:translateX(4px)}
  .contact{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;background:var(--dark);color:#fff;border-radius:var(--radius);padding:24px 26px;box-shadow:var(--shadow);position:relative;overflow:hidden}
  .contact .cg{position:absolute;width:240px;height:240px;border-radius:50%;background:var(--yellow);filter:blur(70px);opacity:.22;top:-80px;right:-40px}
  .contact .ct{position:relative}
  .contact b{font-size:17px;font-weight:700}
  .contact p{font-size:13.5px;color:#b3afa8;margin-top:3px}
  .contact .acts{display:flex;gap:10px;position:relative}
  .contact .cbtn{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);color:#fff;border-radius:99px;padding:11px 18px;font-size:13.5px;font-weight:600;transition:.22s var(--ease);display:inline-flex;align-items:center;gap:8px}
  .contact .cbtn:hover{background:rgba(255,255,255,.22);transform:translateY(-2px)}
  .contact .cbtn.solid{background:var(--yellow);color:var(--dark);border-color:var(--yellow)}

  /* documentos */
  .row-item{display:flex;align-items:center;gap:14px;padding:16px 0;border-bottom:1px solid var(--line)}
  .row-item:last-child{border-bottom:none}
  .check{width:26px;height:26px;border-radius:50%;background:#e7f6ec;color:var(--green);display:flex;align-items:center;justify-content:center;font-size:13px;flex:none;font-weight:800}
  .check.y{background:var(--yellow-soft);color:var(--yellow)}
  .row-item .t{font-size:14.5px;font-weight:600}
  .row-item .s{font-size:12.5px;color:var(--muted)}
  .row-item .file{margin-left:auto;font-size:13px;color:var(--ink);font-weight:600;border:none;background:var(--line);padding:8px 16px;border-radius:99px;transition:.2s var(--ease)}
  .row-item .file:hover{background:var(--dark);color:#fff}

  /* status card (fases como barra) */
  .status-card{background:linear-gradient(135deg,#ffffff 0%,#f6f6f7 100%)}
  .status-card .sc-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:20px}
  .status-card .kicker{font-size:11.5px;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);font-weight:700}
  .status-card h3{font-size:20px;font-weight:750;margin-top:6px;letter-spacing:-.3px}
  .phasebar{display:flex;gap:7px}
  .phasebar .pseg{flex:1;height:9px;border-radius:99px;background:#e6e6ea;transform-origin:left;transition:background .5s var(--ease)}
  .phasebar .pseg.done,.phasebar .pseg.now{background:var(--yellow)}
  .phase-labels{display:flex;gap:7px;margin-top:11px}
  .phase-labels span{flex:1;min-width:0;overflow-wrap:break-word;font-size:11.5px;color:var(--muted);line-height:1.3}
  .phase-labels span.now{color:var(--ink);font-weight:700}
  .status-card .hs-next{font-size:14px;color:var(--muted);margin-top:18px;padding-top:16px;border-top:1px solid var(--line)}
  .status-card .hs-next b{color:var(--ink);font-weight:600}

  /* número grande del inicio */
  .big-result{background:linear-gradient(135deg,#ffffff 0%,#f6f6f7 100%)}
  .big-result .kicker{font-size:12px;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);font-weight:700}
  .bigrow{display:flex;align-items:center;gap:18px;margin-top:12px;flex-wrap:wrap}
  .bignum{font-size:62px;font-weight:800;line-height:.95;letter-spacing:-2px;color:var(--yellow)}
  .bigmeta b{font-size:18px;font-weight:700;display:block;line-height:1.25}
  .bigmeta .up{font-size:14px;color:var(--green);font-weight:600}
  .bigtext{font-size:14.5px;color:var(--muted);margin-top:14px;max-width:540px;line-height:1.55}
  .mini3{display:flex;gap:12px;margin-top:20px;flex-wrap:wrap}
  .mini3 .m{flex:1;min-width:100px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 12px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.04)}
  .mini3 .m .mi{width:38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 9px;color:#fff}
  .mini3 .m .mi svg{width:20px;height:20px;fill:currentColor;stroke:none}
  .mini3 .m b{font-size:23px;font-weight:750;display:block;line-height:1;color:var(--ink)}
  .mini3 .m span{font-size:12.5px;color:var(--muted);margin-top:5px;display:block}

  /* banner de pendientes (no invasivo) */
  .todo-banner{display:flex;align-items:center;gap:14px;background:linear-gradient(120deg,#ffffff 0%,#eff5ff 100%);border-radius:18px;padding:15px 18px;box-shadow:var(--shadow);cursor:pointer;transition:.22s var(--ease);margin-bottom:14px}
  .todo-banner:hover{box-shadow:var(--shadow-h);transform:translateY(-2px)}
  .todo-banner .bi{width:40px;height:40px;border-radius:12px;background:var(--yellow-soft);color:var(--yellow);display:flex;align-items:center;justify-content:center;flex:none}
  .todo-banner .bi svg{width:20px;height:20px;stroke:currentColor;fill:none}
  .todo-banner .bt{flex:1;min-width:0}
  .todo-banner .bt b{font-size:14.5px;font-weight:650;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .todo-banner .bt span{font-size:12.5px;color:var(--muted)}
  .todo-banner .ba{font-size:13px;font-weight:600;color:var(--yellow);display:inline-flex;align-items:center;gap:5px;flex:none}
  .todo-banner .ba svg{width:15px;height:15px;stroke:currentColor;fill:none}
  @media(max-width:560px){.todo-banner .ba span{display:none}}

  /* tareas con aspecto de tareas */
  .subhead{display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:700;color:var(--ink);margin:22px 6px 13px}
  .subhead .sd{width:8px;height:8px;border-radius:50%;flex:none}
  .subhead .sd.p{background:var(--yellow)}
  .subhead .sd.d{background:var(--green)}
  .subhead .c{font-size:11.5px;font-weight:700;color:var(--muted);background:var(--line);border-radius:99px;padding:2px 9px}
  .task.pend{border:1.5px solid #cfe4fb}
  .tbox{width:25px;height:25px;border-radius:8px;border:2px solid #d2d2d7;display:flex;align-items:center;justify-content:center;flex:none;font-size:13px;font-weight:800;color:#fff;transition:.2s var(--ease)}
  .task.done .tbox{background:var(--yellow);border-color:var(--yellow)}
  .task.pend .tbox{border-color:var(--yellow);border-style:dashed}
  .tname{font-size:14.5px;font-weight:600;flex:1}
  .tpill{font-size:11px;font-weight:700;padding:4px 11px;border-radius:99px;flex:none}
  .tpill.p{color:var(--yellow);background:var(--yellow-soft)}
  .tpill.d{color:var(--green);background:#e7f6ec}

  /* tu plan mensual */
  .plan-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
  .plan-item{background:var(--card);border-radius:var(--radius);padding:24px 18px;box-shadow:var(--shadow);text-align:center;transition:.24s var(--ease)}
  .plan-item:hover{transform:translateY(-4px);box-shadow:var(--shadow-h)}
  .plan-item .pic{width:46px;height:46px;border-radius:14px;color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
  .plan-item .pic svg{width:23px;height:23px;fill:currentColor;stroke:none}
  .plan-item b{font-size:36px;font-weight:800;display:block;line-height:1;color:var(--ink)}
  .plan-item span{font-size:13px;color:var(--muted);display:block;margin-top:8px}
  .plan-prog{display:flex;justify-content:space-between;font-size:13.5px;margin:16px 0 7px}
  .plan-prog:first-child{margin-top:0}
  .plan-prog b{font-weight:700}
  .pbar{height:8px;background:#e6e6ea;border-radius:99px;overflow:hidden}
  .pbar i{display:block;height:100%;background:var(--yellow);border-radius:99px}
  @media(max-width:680px){.plan-grid{grid-template-columns:1fr}}

  footer{text-align:center;color:var(--muted);font-size:12px;margin-top:34px;padding-bottom:8px;line-height:1.7}

  @media(max-width:880px){
    .grid2{grid-template-columns:1fr}
    .kpis{grid-template-columns:repeat(2,1fr)}
  }
  @media(max-width:980px){
    .prail{display:none}
    .pside{position:fixed;left:0;top:0;bottom:0;height:100vh;z-index:60;transform:translateX(-105%);transition:transform .3s var(--ease);box-shadow:0 20px 60px rgba(0,0,0,.28)}
    .pside.open{transform:none}
    .menu-btn{display:inline-flex}
    .main{padding:12px 14px 30px}
  }
  @media(max-width:720px){
    .head{padding:6px 2px 18px}
    .head .hi h1{font-size:22px}
    .search{min-width:0;flex:1}
    .hub,.res-grid,.row2,.team{grid-template-columns:1fr}
  }
  @media(max-width:520px){
    .metric .num,.kpi .num{font-size:22px}
    .bubble.b-b{right:150px}
  }
  .scrim{display:none;position:fixed;inset:0;background:rgba(20,18,14,.4);backdrop-filter:blur(4px);z-index:55}
  .scrim.open{display:block}

  /* servicios bloqueados / contratados */
  .tile.locked{opacity:.72}
  .tile.locked .ic{filter:grayscale(.35)}
  .lockbadge{font-size:12px;margin-left:5px}
  .locked-card{text-align:center;background:linear-gradient(135deg,#ffffff 0%,#f6f6f7 100%)}
  .locked-card .lock-ic{width:64px;height:64px;border-radius:20px;background:#fff;box-shadow:var(--shadow);display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto}
  .locked-card .locked-note{background:#fff7ed;border:1px solid #ffd9a8;color:#8a5a16;border-radius:14px;padding:14px 16px;margin:18px auto 0;max-width:470px;font-size:14px;line-height:1.55}
  .cta-meet{display:inline-flex;align-items:center;gap:8px;margin-top:18px;background:var(--yellow);color:#fff;font-weight:700;border-radius:14px;padding:13px 24px;font-size:15px;box-shadow:0 8px 20px rgba(16,19,24,.25);transition:.2s var(--ease)}
  .cta-meet:hover{background:var(--yellow-d);transform:translateY(-2px)}

  /* comparativa con el mes anterior */
  .mini3 .m .dl{font-size:11px;font-weight:700;margin-top:5px}
  .mini3 .m .dl.up{color:var(--green)}
  .mini3 .m .dl.down{color:#c0343a}
  .mini3 .m .dl.flat{color:var(--muted)}
  .cmp{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;border-radius:99px;padding:5px 12px}
  .cmp.up{color:var(--green);background:#e7f6ec}
  .cmp.down{color:#c0343a;background:#feecec}
  .cmp.flat{color:var(--muted);background:var(--yellow-soft)}

  /* botón secundario (Descargar PDF) */
  .pill-btn.ghost{background:var(--card);color:var(--ink);border:1px solid var(--line)}
  .pill-btn.ghost:hover{background:var(--yellow-soft);color:var(--ink);transform:translateY(-2px)}

  /* evolución: cabecera con pestañas */
  .ev-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:2px}
  .ev-head h4{font-size:15px;font-weight:700}
  .ev-empty{color:var(--muted);font-size:13.5px;padding:22px 4px}

  /* contacto directo (mensaje del cliente → ticket) */
  .msg-wrap{margin-top:4px}
  .msg-ok{display:flex;align-items:center;gap:13px;background:#e7f6ec;border-radius:var(--radius-m);padding:18px 20px}
  .msg-ok .ic{width:40px;height:40px;border-radius:12px;background:var(--green);color:#fff;display:flex;align-items:center;justify-content:center;flex:none}
  .msg-ok b{font-size:14.5px;color:#0e7a3d;display:block}
  .msg-ok span{font-size:12.5px;color:#12a150}

  /* ---------- Tarjetas de crecimiento (clicks, impresiones) ---------- */
  .grow2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  @media(max-width:680px){.grow2{grid-template-columns:1fr}}
  .grow{background:var(--card);border:1px solid var(--line);border-radius:var(--radius-m);padding:18px 20px;box-shadow:var(--shadow)}
  .grow .gh{display:flex;align-items:center;justify-content:space-between;gap:10px}
  .grow .gl{font-size:12.5px;color:var(--muted);font-weight:600}
  .grow .gd{font-size:12px;font-weight:700;border-radius:99px;padding:3px 10px}
  .grow .gd.up{color:var(--green);background:#e7f6ec}.grow .gd.down{color:#c0343a;background:#feecec}.grow .gd.flat{color:var(--muted);background:var(--yellow-soft)}
  .grow .gn{font-size:30px;font-weight:800;line-height:1;color:var(--ink-strong);margin-top:8px}
  .grow .gsp{margin-top:10px;height:46px}
  .grow .gsp svg{display:block;width:100%;height:100%;overflow:visible}

  /* ---------- Métricas: rejilla de gráficas (tráfico · mapa) ----------
     Tráfico (~5/12) y mapa (~7/12) van lado a lado en pantallas anchas. Con
     flex-grow, si una de las dos tarjetas se oculta (hidden = display:none) la
     que queda se estira y ocupa toda la fila: nunca deja hueco. En móvil, una
     sola columna. */
  .met-charts{display:flex;flex-wrap:wrap;gap:14px;align-items:start;margin-top:14px}
  .met-charts>.card{margin-top:0;min-width:0;max-width:100%}
  .met-charts>#trafficCard{flex:5 1 300px}
  .met-charts>#geoCard{flex:7 1 420px}
  @media(max-width:640px){.met-charts{flex-direction:column}.met-charts>.card{flex:1 1 auto}}

  /* ============================================================
     MÉTRICAS · DASHBOARD (rediseño denso)
     Todo el color sale de las variables del portal (var(--card),
     var(--ink), var(--muted), var(--line), var(--soft), var(--yellow)…),
     así la sección se adapta sola a claro y a oscuro sin reglas extra.
     ============================================================ */
  .mtx-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:2px 2px 16px}
  .mtx-head .mtx-t{font-size:21px;font-weight:800;letter-spacing:-.5px;color:var(--ink-strong)}
  .mtx-head .mh-sub{font-size:13px;color:var(--muted);margin-top:3px}
  .mtx-head #metMonths .tk-filter{margin:0}
  .mtx-links{margin:0 2px 16px}

  .mtx-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px;align-items:start}
  .mtx-grid>*{min-width:0;max-width:100%}
  .mcard{margin:0}
  .mcard .mc-t{font-size:15px;font-weight:700;color:var(--ink-strong)}
  .mcard .mc-d{font-size:12.5px;color:var(--muted);margin-top:3px}
  .tnum{font-variant-numeric:tabular-nums}

  /* KPIs con icono + variación */
  .metric.mkpi{grid-column:span 3;display:flex;flex-direction:column;gap:12px;text-align:left;padding:18px 20px;min-height:126px}
  .mkpi .mk-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
  .mkpi .mk-lb{font-size:12.5px;color:var(--muted);font-weight:600;line-height:1.3}
  .mkpi .mk-ic{width:34px;height:34px;border-radius:11px;background:var(--yellow-soft);color:var(--yellow-d);display:flex;align-items:center;justify-content:center;flex:none}
  .mkpi .mk-ic svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
  .mkpi .mk-n{font-size:30px;font-weight:800;line-height:1;letter-spacing:-1px;color:var(--ink-strong);margin:0}
  .mkpi .delta{font-size:12px;font-weight:700;margin:0}
  .mkpi .delta.up{color:var(--green)}
  .mkpi .delta.down{color:#c0343a}
  .mkpi .delta.flat{color:var(--muted)}
  #metGoogle{display:contents}

  .mc-evo{grid-column:span 8}
  .mc-donut{grid-column:span 4}
  .mc-bars{grid-column:span 7}
  .mc-rank{grid-column:span 5}
  .mc-full{grid-column:1/-1}
  .mtx-grid .met-charts{margin-top:0}
  .mtx-grid>.calc,.mtx-grid>.frame{margin-top:0}
  .mtx-grid .grow2:empty,.mtx-grid .met-charts:empty{display:none}

  /* Donut · contactos por canal */
  .mdonut{display:flex;align-items:center;gap:20px;margin-top:16px;flex-wrap:wrap}
  .mdonut svg{flex:none}
  .mdonut .leg{flex:1;min-width:140px;display:flex;flex-direction:column;gap:8px;list-style:none}
  .mdonut .leg li{display:flex;align-items:center;justify-content:space-between;gap:10px;background:var(--soft);border-radius:12px;padding:9px 13px}
  .mdonut .leg .nm{display:flex;align-items:center;gap:9px;font-size:13px;color:var(--muted)}
  .mdonut .leg .dot{width:10px;height:10px;border-radius:50%;flex:none}
  .mdonut .leg .vv{font-size:14px;font-weight:750;color:var(--ink-strong)}

  /* Barras · visitas por mes */
  .mbars{display:flex;align-items:flex-end;gap:14px;margin-top:18px}
  .mb-b{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;gap:8px}
  .mb-track{width:100%;height:150px;min-height:150px;flex:none;display:flex;align-items:flex-end;justify-content:center}
  .mb-track .col{width:100%;max-width:44px;height:0;background:var(--soft);border-radius:11px 11px 6px 6px;transition:height 1s var(--ease)}
  .mb-track .col.on{background:var(--yellow)}
  .mb-v{font-size:11.5px;font-weight:700;color:var(--ink)}
  .mb-m{font-size:11.5px;color:var(--muted)}

  /* Ranking · meses con más contactos */
  .mrank{display:flex;flex-direction:column;gap:13px;margin-top:16px}
  .mrank .r-top{display:flex;align-items:center;justify-content:space-between;font-size:13.5px;margin-bottom:6px}
  .mrank .r-nm{font-weight:650;color:var(--ink)}
  .mrank .r.on .r-nm{color:var(--ink-strong)}
  .mrank .r-v{font-weight:750;color:var(--ink-strong)}
  .mrank .r-bar{height:8px;border-radius:99px;background:var(--soft);overflow:hidden}
  .mrank .r-bar i{display:block;height:100%;border-radius:99px;background:var(--yellow);transition:width .9s var(--ease)}

  @media(max-width:1000px){
    .metric.mkpi{grid-column:span 6}
    .mc-evo,.mc-donut,.mc-bars,.mc-rank{grid-column:1/-1}
  }
  @media(max-width:560px){
    .metric.mkpi{grid-column:1/-1}
  }

  /* ---------- Mapa del mundo (de dónde te visitan) ---------- */
  .geo-wrap{display:grid;grid-template-columns:1.5fr 1fr;gap:18px;align-items:center}
  @media(max-width:680px){.geo-wrap{grid-template-columns:1fr}}
  .geo-map{width:100%;height:290px}
  .geo-list{display:flex;flex-direction:column;gap:11px}
  .geo-row{display:flex;align-items:center;gap:10px;font-size:13.5px}
  .geo-row .fl{font-size:19px;flex:none;line-height:1}
  .geo-row .gn{flex:1;color:var(--ink);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .geo-row .gv{font-weight:700;color:var(--ink-strong)}
  .geo-row .gp{color:var(--muted);font-weight:600;font-size:12px;margin-left:5px}
  .jvm-tooltip{background:#1f232a!important;border-radius:8px!important;font-family:inherit!important;padding:6px 10px!important;font-size:12.5px!important}

  /* ---------- De dónde viene el tráfico ---------- */
  .tr-row{margin-bottom:14px}
  .tr-row:last-child{margin-bottom:0}
  .tr-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px}
  .tr-l{font-size:13.5px;font-weight:600;color:var(--ink)}
  .tr-v{font-size:13px;font-weight:700;color:var(--ink-strong)}
  .tr-v em{font-style:normal;color:var(--muted);font-weight:600;font-size:12px;margin-left:3px}
  .tr-bar{height:10px;background:var(--yellow-soft);border-radius:99px;overflow:hidden}
  .tr-bar i{display:block;height:100%;border-radius:99px;transition:width .9s var(--ease)}

  /* ---------- Tooltip de la gráfica de evolución ---------- */
  #evoCard{position:relative}
  #bars{display:block;height:auto;padding:0}
  .evo-plot{position:relative;height:200px;width:100%}
  .evo-plot svg{display:block;width:100%;height:100%;overflow:visible}
  .evo-hot{cursor:pointer}
  .lc-bar{cursor:default}
  .lc-bar:hover{filter:brightness(1.06)}
  .evo-tip{position:absolute;transform:translate(-50%,-125%);background:var(--dark);color:#fff;padding:7px 11px;border-radius:9px;font-size:12px;pointer-events:none;z-index:6;white-space:nowrap;box-shadow:0 8px 22px rgba(0,0,0,.28);text-align:center;line-height:1.25}
  .evo-tip b{font-size:14px;font-weight:750}
  .evo-tip span{display:block;font-size:10.5px;color:#b9b6b0;margin-top:1px}

  /* ============================================================
     MÉTRICAS · pulido premium
     (alturas iguales sin huecos · animación de entrada · hover
      que sigue al ratón · tooltip único con variables del portal)
     ============================================================ */
  /* --- 1. Alturas iguales por fila y reparto del contenido --- */
  .metric.mkpi{align-self:stretch}
  .mc-evo,.mc-donut,.mc-bars,.mc-rank{align-self:stretch;display:flex;flex-direction:column}
  .mc-evo #bars{flex:1;display:flex;flex-direction:column;justify-content:center;height:auto}
  .mc-evo .evo-plot{flex:1;min-height:172px}
  .mc-donut .mdonut{flex:1;align-items:center;justify-content:center}
  .mc-bars .mbars{flex:1;align-items:flex-end}
  .mc-rank .mrank{flex:1;justify-content:center}
  .met-charts{align-items:stretch}
  .met-charts>.card{display:flex;flex-direction:column}
  .met-charts>#trafficCard #trafficBars{flex:1;display:flex;flex-direction:column;justify-content:center}
  .met-charts>#geoCard .geo-wrap{flex:1}

  /* --- 2. Tooltip único compartido (funciona en claro y oscuro) --- */
  .met-tip{position:fixed;z-index:9999;pointer-events:none;background:var(--card);color:var(--ink-strong);border:1px solid var(--line);border-radius:10px;padding:8px 11px;font-size:12px;line-height:1.35;box-shadow:0 12px 34px -10px rgba(16,19,24,.34);max-width:230px;white-space:nowrap}
  .met-tip[hidden]{display:none}
  .met-tip b{font-size:13.5px;font-weight:750;color:var(--ink-strong)}
  .met-tip .tt-k{display:flex;align-items:center;gap:7px}
  .met-tip .tt-dot{width:9px;height:9px;border-radius:50%;flex:none}
  .met-tip small{display:block;color:var(--muted);font-size:10.5px;font-weight:600;margin-top:2px}

  /* --- 3. Hover: crosshair, resaltados --- */
  .evo-cross,.sp-cross{stroke:var(--muted);stroke-width:1;stroke-dasharray:3 3;opacity:.55;pointer-events:none}
  .evo-hi,.sp-hi{pointer-events:none}
  .evo-overlay,.sp-hot{cursor:crosshair}
  .mb-b{cursor:default}
  .mb-track .col{transition:height 1s var(--ease),filter .18s var(--ease)}
  .mb-b.hot .col{filter:brightness(1.08) saturate(1.04)}
  .mrank .r{cursor:default;border-radius:9px;transition:background .18s var(--ease);padding:2px 5px;margin:0 -5px}
  .mrank .r.hot{background:var(--soft)}
  .mdonut .donut-seg{transition:stroke-width .18s var(--ease),opacity .18s var(--ease);cursor:default}
  .mdonut .leg li{cursor:default;transition:background .16s var(--ease),transform .16s var(--ease)}
  .mdonut .leg li.hot{transform:translateX(2px);background:var(--yellow-soft)}

  /* ============================================================
     4. Animaciones PREMIUM del dashboard de Métricas
     Principio de robustez: el ESTADO DE REPOSO (sin clase de
     animación) siempre es el estado final visible. Las animaciones
     son una capa aditiva; si se interrumpen o hay reduced-motion,
     nunca dejan un elemento invisible ni a 0.
     ============================================================ */

  /* --- 4.0 Entrada orquestada: las tarjetas entran en cascada --- */
  /* La rejilla ya no hace el blurUp en bloque; cada tarjeta entra sola. */
  #view-metricas.active > .mtx-grid{animation:none}
  @keyframes mtxCardIn{from{opacity:0;transform:translateY(16px) scale(.98)}to{opacity:1;transform:none}}
  /* Reposo: opacidad 1 (definido por .metric/.card). Solo con .mtx-anim anima. */
  .mtx-anim{animation:mtxCardIn .58s var(--ease-expo) both;animation-delay:var(--mi,0ms)}

  /* --- 4.1 Números (KPIs y centro del donut): flip-in con blur --- */
  @keyframes mtxNumFlip{from{filter:blur(6px);transform:translateY(6px);opacity:.35}to{filter:blur(0);transform:none;opacity:1}}
  .mtx-flip{animation:mtxNumFlip .55s var(--ease-expo) both;will-change:filter,transform}

  /* --- 4.2 Área de evolución: barrido con clip + línea con glow + halo --- */
  @keyframes mtxStroke{to{stroke-dashoffset:0}}
  @keyframes mtxSweepX{from{transform:scaleX(0)}to{transform:scaleX(1)}}
  /* Reposo del rect de clip: scaleX(1) → área totalmente visible (nunca oculta). */
  .evo-clip-rect{transform-box:fill-box;transform-origin:left center}
  .evo-clip-rect.sweep{animation:mtxSweepX .86s var(--ease-expo) both}
  .evo-glow{filter:url(#evoBlur)}
  /* Punto resaltado del hover: se agranda con un halo/glow del acento. */
  .evo-hi{filter:drop-shadow(0 0 6px color-mix(in srgb,var(--yellow) 65%,transparent))}
  @keyframes mtxPop{0%{transform:scale(0);opacity:0}70%{transform:scale(1.26)}100%{transform:scale(1);opacity:1}}
  .evo-dot.pop{transform-box:fill-box;transform-origin:center;animation:mtxPop .5s var(--ease-spring) both}
  /* Halo del punto elegido: pulsos que crecen y se desvanecen. Reposo: opacity 0. */
  @keyframes mtxHalo{0%{transform:scale(1);opacity:.55}100%{transform:scale(2.4);opacity:0}}
  .evo-halo{transform-box:fill-box;transform-origin:center;opacity:0}
  .evo-halo.pulse{animation:mtxHalo 1.15s var(--ease-expo) .62s 2 both}

  /* --- 4.3 Donut: asentamiento con scale+rotate; centro con pop --- */
  @keyframes mtxDonutSettle{from{transform:scale(.9) rotate(-8deg)}to{transform:none}}
  .mdonut svg{transform-origin:center}
  .mdonut.settle svg{animation:mtxDonutSettle .7s var(--ease-spring) both}
  @keyframes mtxCPop{0%{transform:scale(1)}45%{transform:scale(1.16)}100%{transform:scale(1)}}
  .donut-c2{transform-box:fill-box;transform-origin:center}
  .donut-c2.cpop{animation:mtxCPop .42s var(--ease-spring)}
  /* Segmento resaltado en hover: se engorda con rebote (el grosor lo pone el JS). */
  .mdonut .donut-seg{transition:stroke-width .26s var(--ease-spring),opacity .2s var(--ease)}

  /* --- 4.4 Barras de visitas: overshoot + fade; barra activa con glow --- */
  /* El alto final se fija en línea de forma síncrona (JS). El overshoot es la curva. */
  .mb-track .col{transition:height .82s var(--ease-spring),opacity .5s var(--ease),filter .18s var(--ease)}
  .mb-track .col.on{filter:drop-shadow(0 4px 10px color-mix(in srgb,var(--yellow) 45%,transparent))}
  .mb-b.hot .col{filter:brightness(1.06) saturate(1.05) drop-shadow(0 4px 12px color-mix(in srgb,var(--yellow) 38%,transparent))}

  /* --- 4.5 Ranking: barras que crecen con rebote --- */
  .mrank .r-bar i{transition:width .82s var(--ease-spring)}

  /* --- 4.6 Sparklines: trazo + punto final con glow --- */
  .sp-end{filter:drop-shadow(0 0 4px color-mix(in srgb,currentColor 60%,transparent))}
  @keyframes mtxSpHalo{0%{transform:scale(.4);opacity:0}40%{opacity:.5}100%{transform:scale(2.6);opacity:0}}
  .sp-halo{transform-box:fill-box;transform-origin:center;opacity:0}
  .sp-halo.pulse{animation:mtxSpHalo 1.2s var(--ease-expo) .55s 2 both}

  /* --- 4.7 Tooltip único: entra con opacity+scale (reposo = oculto) --- */
  .met-tip{transform-origin:center bottom;opacity:0;transform:scale(.96);transition:opacity .16s var(--ease),transform .16s var(--ease)}
  .met-tip[hidden]{display:none}
  .met-tip.in{opacity:1;transform:none}

  @media(prefers-reduced-motion:reduce){
    .mtx-anim,.mtx-flip,.evo-clip-rect.sweep,.evo-glow,.evo-dot.pop,.evo-halo.pulse,
    .mdonut.settle svg,.donut-c2.cpop,.sp-halo.pulse{animation:none!important}
    .met-tip{transition:none}
  }

  /* ---------- Avatares de asignados (estilo ERP) ---------- */
  .av-mini{width:27px;height:27px;border-radius:50%;background:#22242a;color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;flex:none;overflow:hidden}
  .av-mini img{width:100%;height:100%;object-fit:cover}
  .av-mini.av-none{background:#eef0f2;color:#aab0b8}
  .asg-stack{display:inline-flex;align-items:center}
  .asg-stack .av-mini{border:2px solid #fff}
  .asg-stack .av-mini+.av-mini{margin-left:-11px}
  .av-mini.av-extra{background:#c8ccd2;color:#3c4149;font-size:9.5px}

  /* ---------- Filas de tarea (tabla estilo ERP) ---------- */
  .tk-grp{margin-bottom:20px}
  .tk-gh{display:flex;align-items:center;gap:9px;margin:0 2px 9px}
  .tk-gh .gpill{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--ink);background:var(--soft);border-radius:7px;padding:4px 11px;display:inline-flex;align-items:center;gap:7px}
  .tk-gh .gpill .gd{width:8px;height:8px;border-radius:3px;flex:none}
  .tk-gh .gn{font-size:12.5px;color:var(--muted);font-weight:700}
  .tk-now{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:#12854a;background:#e4f6ec;border-radius:6px;padding:2px 8px}
  .tk-body{border:1px solid var(--line);border-radius:14px;overflow:hidden;background:#fff;box-shadow:var(--shadow)}
  .tk-colh,.tk-row{display:grid;grid-template-columns:1fr 200px 116px 116px;gap:10px;align-items:center;padding:13px 16px;border-bottom:1px solid var(--line)}
  .tk-colh{font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;background:#fbfbfc}
  .tk-row:last-child{border-bottom:none}
  .tk-row{cursor:pointer;transition:background .14s}
  .tk-row:hover{background:#fafbfc}
  .tk-row .nm{display:flex;align-items:center;gap:11px;min-width:0}
  .tk-row .nm .st{flex:none;display:inline-flex}
  .tk-row .nm b{font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .tk-row .asig{display:flex;align-items:center;gap:8px;min-width:0}
  .tk-row .asig .nn{font-size:12.5px;color:#4c515b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .flagp{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600}
  .flagp .fdot{width:9px;height:9px;border-radius:2px;flex:none}
  .tk-date{font-size:12.5px;color:#4c515b}
  .tk-exp{grid-column:1/-1;font-size:13px;color:var(--muted);line-height:1.6;padding:2px 0 4px 37px}
  .st-tag{font-size:10px;font-weight:700;padding:2px 9px;border-radius:20px;text-transform:uppercase;flex:none}
  @media(max-width:820px){
    .tk-colh{display:none}
    .tk-row{grid-template-columns:1fr auto;gap:10px}
    .tk-row .tk-date,.tk-row .flagp-cell{display:none}
    .tk-exp{padding-left:0}
  }

  /* Desplegable de filtro por mes (estilo ERP) */
  .tk-filter{display:flex;align-items:center;gap:10px;margin:0 2px 16px}
  .tk-filter label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
  .selbox{position:relative;display:inline-flex;align-items:center}
  .selbox select{appearance:none;-webkit-appearance:none;background:#fff;border:1px solid var(--line);border-radius:10px;padding:9px 36px 9px 14px;font-size:13.5px;font-weight:600;color:var(--ink);font-family:inherit;cursor:pointer;outline:none;transition:.15s var(--ease)}
  .selbox select:hover{background:var(--soft)}
  .selbox select:focus{border-color:var(--ink-strong);box-shadow:0 0 0 3px var(--yellow-soft)}
  .selbox .caret{position:absolute;right:12px;width:15px;height:15px;stroke:var(--muted);fill:none;stroke-width:2;pointer-events:none}

  /* Desplegable estilizado (componente .cs-*, calcado del ERP) */
  @keyframes pop{0%{transform:scale(.97)}55%{transform:scale(1.03)}100%{transform:scale(1)}}
  .cs-wrap{position:relative;display:inline-block;vertical-align:middle}
  .cs-native{position:absolute;left:0;top:0;width:100%;height:100%;opacity:0;margin:0;pointer-events:none}
  .cs-trig{display:inline-flex;align-items:center;gap:8px;width:100%;text-align:left;cursor:pointer;background:#fff;border:1px solid var(--line);border-radius:10px;padding:9px 13px;font-size:13.5px;font-family:inherit;font-weight:600;color:var(--ink);line-height:1.2}
  .cs-trig .cs-lbl{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .cs-trig .cs-arw{color:var(--muted);display:flex;flex:none;transition:transform .16s ease}
  .cs-trig .cs-arw svg{width:15px;height:15px}
  .cs-wrap.open .cs-trig{border-color:var(--ink-strong);box-shadow:0 0 0 3px var(--yellow-soft)}
  .cs-wrap.open .cs-arw{transform:rotate(180deg)}
  .cs-trig.dis{opacity:.55;cursor:default}
  .cs-pop{position:fixed;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 46px rgba(0,0,0,.17);padding:5px;min-width:160px;max-height:288px;overflow:auto;z-index:700;display:none}
  .cs-pop.on{display:block;animation:pop .14s ease}
  .cs-opt{padding:9px 12px;border-radius:8px;font-size:13px;color:#4c515b;cursor:pointer;white-space:nowrap}
  .cs-opt:hover{background:var(--soft);color:var(--ink)}
  .cs-opt.on{background:var(--yellow-soft);color:var(--ink-strong);font-weight:600}

  /* ---------- Filas de lista (facturas, reuniones, tickets) ---------- */
  .lrow{display:flex;align-items:center;gap:14px;padding:14px 18px;border:1px solid var(--line);border-radius:14px;background:#fff;box-shadow:var(--shadow);margin-bottom:10px;transition:.16s var(--ease);text-decoration:none;color:inherit;width:100%;text-align:left;cursor:pointer}
  button.lrow{font-family:inherit}
  .lrow:hover{transform:translateY(-2px);box-shadow:var(--shadow-h)}
  .lrow .lic{width:44px;height:44px;border-radius:12px;background:var(--yellow-soft);color:var(--yellow-d);display:flex;align-items:center;justify-content:center;flex:none;flex-direction:column;line-height:1}
  .lrow .lic svg{width:20px;height:20px;stroke:currentColor;fill:none}
  .lrow .lic small{font-size:9px;text-transform:uppercase;font-weight:800;letter-spacing:.4px}
  .lrow .lic b{font-size:17px;font-weight:800}
  .lrow .lx{flex:1;min-width:0}
  .lrow .lx b{font-size:14.5px;font-weight:650;display:block}
  .lrow .lx span{font-size:12.5px;color:var(--muted)}
  .lrow .lr{display:flex;align-items:center;gap:12px;flex:none}
  .lrow .lr .amt{font-size:15px;font-weight:750;color:var(--ink-strong)}
  .lrow .lr .go{color:#cfcabf;flex:none}
  .lrow:hover .lr .go{color:var(--ink)}
  .lstate{font-size:11px;font-weight:700;padding:4px 11px;border-radius:99px;color:#fff;white-space:nowrap}
  .empty-b{text-align:center;color:var(--muted);font-size:14px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:32px 22px;box-shadow:var(--shadow);line-height:1.6}
  /* estado vacío "guay" (soporte, etc.) */
  .nice-empty{text-align:center;background:linear-gradient(160deg,#ffffff 0%,#f6f7f9 100%);border:1px solid var(--line);border-radius:20px;padding:44px 26px;box-shadow:var(--shadow)}
  .nice-empty .ne-ic{width:66px;height:66px;border-radius:20px;background:var(--dark);color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;box-shadow:0 12px 26px -10px rgba(16,19,24,.5)}
  .nice-empty .ne-ic svg{width:30px;height:30px;stroke:currentColor;fill:none}
  .nice-empty b{font-size:18px;font-weight:750;color:var(--ink-strong);letter-spacing:-.3px}
  .nice-empty p{font-size:14px;color:var(--muted);line-height:1.6;max-width:420px;margin:8px auto 20px}
  .nice-empty .ne-btn{display:inline-flex;align-items:center;gap:8px;background:var(--accent);color:#fff;border:none;border-radius:12px;padding:12px 22px;font-size:14.5px;font-weight:600;cursor:pointer;transition:.18s var(--ease)}
  .nice-empty .ne-btn:hover{background:#000;transform:translateY(-2px)}
  .nice-empty .ne-btn svg{width:16px;height:16px;stroke:currentColor;fill:none}

  /* ---------- Bóveda de accesos (CLON del grid del ERP admin/credenciales.php) ---------- */
  .cred-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
  @media(max-width:680px){.cred-grid{grid-template-columns:1fr}}
  .cred{border:1px solid var(--line);border-radius:16px;background:#fff;padding:18px;transition:border-color .16s ease,box-shadow .18s ease,transform .16s ease}
  .cred:hover{border-color:#dcdcde;box-shadow:0 8px 26px rgba(0,0,0,.05);transform:translateY(-2px)}
  .cred-h{display:flex;align-items:center;gap:11px;margin-bottom:14px}
  .cred-h .ic{width:36px;height:36px;border-radius:10px;background:var(--soft);color:#22242a;display:flex;align-items:center;justify-content:center;flex:none}
  .cred-h .ic svg{width:18px;height:18px;stroke:currentColor;fill:none}
  .cred-h .tt{flex:1;min-width:0}.cred-h .tt b{font-size:14.5px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--ink-strong)}
  .cred-h .cat{font-size:9.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700}
  .cred .field{background:#f7f8fa;border-radius:11px;padding:9px 12px;margin-bottom:9px}
  .cred .field label{font-size:9.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;display:block;margin-bottom:3px}
  .cred .field .fv{display:flex;align-items:center;gap:8px}
  .cred .field .fv code{flex:1;min-width:0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .cred .field .fv button{border:none;background:none;color:#b5b9c1;cursor:pointer;padding:3px;border-radius:6px;display:inline-flex;flex:none}
  .cred .field .fv button:hover{background:#e9ebef;color:#6b7280}
  .cred .field .fv button svg{width:15px;height:15px;stroke:currentColor;fill:none}
  .cred .note{font-size:12px;color:var(--muted);font-style:italic;background:#f7f8fa;border-radius:9px;padding:8px 11px;margin-bottom:9px}
  .cred-link{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--accent);font-weight:600;margin-top:2px;text-decoration:none}
  .cred-link svg{width:14px;height:14px;stroke:currentColor;fill:none}

  /* fila de cabecera de sección con botón a la derecha */
  .sec-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:26px 6px 4px}
  .sec-row .sec{margin:0}

  /* ---------- Popups (modales) ---------- */
  .pmov{position:fixed;inset:0;background:rgba(17,19,24,.4);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;padding:24px 18px;z-index:220;opacity:0;visibility:hidden;transition:opacity .18s ease,visibility .18s ease;overflow:auto}
  .pmov.on{opacity:1;visibility:visible}
  .pmodal{background:#fff;border-radius:18px;width:480px;max-width:100%;box-shadow:0 30px 80px rgba(0,0,0,.32);transform:translateY(10px) scale(.99);transition:transform .2s cubic-bezier(.33,1,.68,1);overflow:hidden;margin:auto}
  .pmov.on .pmodal{transform:none}
  .pmh{display:flex;align-items:center;gap:12px;padding:18px 22px;border-bottom:1px solid var(--line)}
  .pmh .pmi{width:44px;height:44px;border-radius:12px;background:var(--yellow-soft);color:var(--yellow-d);display:flex;align-items:center;justify-content:center;flex:none}
  .pmh .pmi svg{width:21px;height:21px;stroke:currentColor;fill:none}
  .pmh .pmt{min-width:0}
  .pmh .pmt b{font-size:16.5px;font-weight:750;color:var(--ink-strong);display:block;letter-spacing:-.2px}
  .pmh .pmt span{font-size:12.5px;color:var(--muted)}
  .pmb{padding:18px 22px 8px;display:flex;flex-direction:column;gap:2px}
  .pmf{display:flex;justify-content:flex-end;gap:10px;padding:14px 22px;border-top:1px solid var(--line2);background:#fcfcfd}
  .pm-g{border:1px solid var(--line);background:#fff;border-radius:11px;padding:11px 18px;font-size:14px;font-weight:600;cursor:pointer;color:var(--ink);font-family:inherit;transition:.15s var(--ease)}
  .pm-g:hover{background:var(--soft)}
  .pm-p{border:none;background:var(--accent);color:#fff;border-radius:11px;padding:11px 20px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;transition:.15s var(--ease)}
  .pm-p:hover{background:#000}
  .pm-p:disabled{opacity:.5;cursor:default}

  /* aviso flotante (toast) del portal */
  .ptoast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(12px);background:var(--dark);color:#fff;padding:12px 20px;border-radius:12px;font-size:14px;font-weight:600;z-index:400;opacity:0;transition:.25s var(--ease);box-shadow:0 12px 30px rgba(0,0,0,.25);pointer-events:none;max-width:90vw;text-align:center}
  .ptoast.on{opacity:1;transform:translateX(-50%) translateY(0)}

  /* ---------- INFORME IMPRIMIBLE (Descargar PDF) ---------- */
  #printReport{display:none}
  @media print{
    @page{margin:13mm}
    html,body{background:#fff !important}
    #app,.scrim,.edbar,.edpen,.edov,.edtoast{display:none !important}
    #printReport{display:block !important}
  }
  .pr-head{display:flex;align-items:center;gap:14px;border-bottom:2px solid #111;padding-bottom:14px;margin-bottom:20px}
  .pr-logo{width:44px;height:44px;border-radius:12px;background:#111;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;flex:none;overflow:hidden}
  .pr-logo img{width:100%;height:100%;object-fit:contain}
  .pr-brand{font-size:12px;letter-spacing:.6px;text-transform:uppercase;color:#666;font-weight:700}
  .pr-title{font-size:20px;font-weight:800;color:#111;letter-spacing:-.3px}
  .pr-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:22px}
  .pr-kpi{border:1px solid #e2e2e5;border-radius:12px;padding:14px 16px}
  .pr-kpi .n{font-size:26px;font-weight:800;color:#111;line-height:1}
  .pr-kpi .l{font-size:12px;color:#666;margin-top:5px}
  .pr-kpi .d{font-size:11px;font-weight:700;margin-top:4px}
  .pr-kpi .d.up{color:#12a150}.pr-kpi .d.down{color:#c0343a}.pr-kpi .d.flat{color:#999}
  .pr-sec-t{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#888;font-weight:800;margin:22px 0 10px}
  .pr-task{display:flex;gap:10px;padding:9px 0;border-bottom:1px solid #eee;font-size:13px;color:#222}
  .pr-task .b{font-weight:800;flex:none}
  .pr-task.done .b{color:#12a150}.pr-task.pend .b{color:#111}
  .pr-note{font-size:13px;color:#333;line-height:1.6}
  .pr-foot{margin-top:26px;padding-top:12px;border-top:1px solid #ddd;font-size:11px;color:#999}

  /* ============================================================
     MODO OSCURO DEL PORTAL  (aditivo: el modo claro no se toca)
     Todo lo de aquí vive bajo [data-theme=dark]. La persistencia usa
     localStorage['portalTheme'] y es independiente del ERP.
     ============================================================ */
  /* Botón sol/luna */
  .ptheme{padding:11px}
  .ptheme svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:1.9}
  .ptheme .ic-sun{display:none}
  [data-theme=dark] .ptheme .ic-moon{display:none}
  [data-theme=dark] .ptheme .ic-sun{display:block}
  /* Animación al cambiar de tema: el tema nuevo se revela en un círculo que crece
     desde el botón pulsado (View Transitions API; sin soporte, cambia sin animar). */
  ::view-transition-old(root),::view-transition-new(root){animation:none;mix-blend-mode:normal}
  ::view-transition-old(root){z-index:0}
  ::view-transition-new(root){z-index:1;animation:.42s ease-in-out both theme-reveal}
  @keyframes theme-reveal{from{clip-path:circle(0% at var(--tx,50%) var(--ty,50%));opacity:.7}to{clip-path:circle(150% at var(--tx,50%) var(--ty,50%));opacity:1}}

  /* Paleta oscura: redefine las variables del portal. --dark, --dark-2,
     --green y --coral se quedan como estaban a propósito. --yellow (acento)
     pasa a azul para que sea legible sobre el fondo oscuro. */
  [data-theme=dark]{
    /* Negro neutro estilo shadcn (grises 100% neutros). El acento se mantiene
       en azul, que resalta y es amable para el cliente. */
    --bg:#0a0a0a;
    --card:#161616;
    --card-2:#1f1f1f;
    --soft:#242424;
    --line:#282828;
    --line2:#1c1c1c;
    --ring:#4a4a4a;
    --yellow:#3b82f6;
    --yellow-d:#2f6fd6;
    --yellow-soft:#20242e;
    --ink:#e6e6e6;
    --ink-strong:#fafafa;
    --muted:#a1a1a1;
    --shadow:0 1px 2px rgba(0,0,0,.6),0 10px 26px -18px rgba(0,0,0,.85);
    --shadow-h:0 12px 30px -18px rgba(0,0,0,.9);
    color-scheme:dark;
  }

  /* --- Superficies blancas fijas → tarjeta --- */
  [data-theme=dark] .pside,
  [data-theme=dark] .pside .pfoot,
  [data-theme=dark] .lrow,
  [data-theme=dark] .empty-b,
  [data-theme=dark] .cred,
  [data-theme=dark] .pmodal{background-color:var(--card)}
  [data-theme=dark] .search:focus-within,
  [data-theme=dark] .mini3 .m,
  [data-theme=dark] .calc-base,
  [data-theme=dark] .calc-input,
  [data-theme=dark] .calc-out .o,
  [data-theme=dark] .tk-body,
  [data-theme=dark] .pm-g{background-color:var(--card-2)}
  [data-theme=dark] .form-field input,
  [data-theme=dark] .form-field select,
  [data-theme=dark] .form-field textarea,
  [data-theme=dark] .selbox select,
  [data-theme=dark] .cs-trig,
  [data-theme=dark] .cs-pop{background-color:var(--card-2);color:var(--ink);border-color:var(--line)}

  /* --- Grises claros de zona/hover → --soft --- */
  [data-theme=dark] .glow{background-color:var(--card-2)}
  [data-theme=dark] .tk-colh,
  [data-theme=dark] .tk-row:hover,
  [data-theme=dark] .cred .field,
  [data-theme=dark] .cred .note,
  [data-theme=dark] .pmf,
  [data-theme=dark] .av-mini.av-none{background-color:var(--soft)}
  [data-theme=dark] .cred .field .fv button:hover{background-color:var(--line)}

  /* --- Barras/pistas grises fijas → --soft --- */
  [data-theme=dark] .hero-status .bar,
  [data-theme=dark] .phasebar .pseg,
  [data-theme=dark] .pbar,
  [data-theme=dark] .bars .b .col,
  [data-theme=dark] .lc-bar{background-color:var(--soft)}

  /* --- Tarjetas con degradado claro → tarjeta plana oscura --- */
  [data-theme=dark] .frame,
  [data-theme=dark] .calc,
  [data-theme=dark] .status-card,
  [data-theme=dark] .big-result,
  [data-theme=dark] .todo-banner,
  [data-theme=dark] .locked-card,
  [data-theme=dark] .nice-empty{background:var(--card)}

  /* --- Bordes claros fijos → --line --- */
  [data-theme=dark] .tbox,
  [data-theme=dark] .task.pend,
  [data-theme=dark] .cred:hover,
  [data-theme=dark] .faq.open{border-color:var(--line)}

  /* --- Texto oscuro fijo → legible --- */
  [data-theme=dark] .steps li,
  [data-theme=dark] .tk-row .asig .nn,
  [data-theme=dark] .cs-opt{color:var(--ink)}
  [data-theme=dark] .tk-date{color:var(--muted)}

  /* --- Login (por si se muestra dentro del portal) --- */
  [data-theme=dark] #login{background:var(--bg)}
  [data-theme=dark] #login .gl{opacity:.18}
  [data-theme=dark] .login-card{background:rgba(28,31,37,.82);border-color:var(--line)}
  [data-theme=dark] .field input{background:var(--card-2);color:var(--ink);border-color:var(--line)}
</style>
</head>
<body>

<!-- ================= APP ================= -->
<div id="app">

<?php
    $cliIni = e($cl['iniciales'] ?: mb_strtoupper(mb_substr((string)$cl['name'],0,2)));
    $cliCol = pcl_avatar_color((string)$cl['name']);
    $brandLogoTag = $BRAND['logo']
      ? '<img src="'.e($BRAND['logo']).'" alt="" onerror="this.replaceWith(document.createTextNode(\''.e($BRAND['initial']).'\'))">'
      : e($BRAND['initial']);
    $svgLogout = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>';
  ?>
  <!-- raíl oscuro fino -->
  <aside class="prail" id="prail">
    <div class="rlogo"<?php if($BRAND['color']): ?> style="background:<?= e($BRAND['color']) ?>"<?php endif; ?>><?= $brandLogoTag ?></div>
    <div class="rsp"></div>
    <a class="rico" href="logout.php" title="Cerrar sesión"><?= $svgLogout ?></a>
    <div class="rav" style="background:<?= e($cliCol) ?>"><?= $cliIni ?></div>
  </aside>

  <!-- barra lateral clara con opciones -->
  <aside class="pside" id="pside">
    <div class="psh"><div class="plogo"<?php if($BRAND['color']): ?> style="background:<?= e($BRAND['color']) ?>"<?php endif; ?>><?= $brandLogoTag ?></div><b><?= e($BRAND['name']) ?></b></div>

    <div class="psec">Tu proyecto</div>
    <div class="pnav">
      <a class="active" data-view="resumen" onclick="go('resumen')"><svg viewBox="0 0 24 24" stroke-width="1.8"><rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/></svg>Inicio</a>
      <a class="conv-only" data-view="metricas" onclick="go('metricas')"><svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M4 19V5M4 19h16M8 16v-4M12 16V8M16 16v-6"/></svg>Métricas</a>
      <a data-view="tareas" onclick="go('tareas')"><svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M9 6h11M9 12h11M9 18h11"/><path d="M4.5 6l1 1 2-2M4.5 12l1 1 2-2M4.5 18l1 1 2-2"/></svg>Tareas<span class="cnt" id="cntTareas" hidden></span></a>
      <a data-view="reuniones" onclick="go('reuniones')"><svg viewBox="0 0 24 24" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>Reuniones<span class="cnt" id="cntReu" hidden></span></a>
    </div>

    <div class="psec">Documentos</div>
    <div class="pnav">
      <a data-view="informes" onclick="go('informes')"><svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M14 3v5h5"/><path d="M7 3h7l5 5v11a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/></svg>Informes</a>
      <a data-view="facturas" onclick="go('facturas')"><svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M6 2h9l3 3v15l-2.2-1.5L13.6 20l-2.1-1.4L9.4 20l-2.1-1.4L5 20V4a2 2 0 0 1 1-2z"/><path d="M8.5 8h6M8.5 11.5h6"/></svg>Facturas<span class="cnt" id="cntFac" hidden></span></a>
    </div>

    <div class="psec">Ayuda</div>
    <div class="pnav">
      <a data-view="soporte" onclick="go('soporte')"><svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Soporte<span class="cnt" id="cntTk" hidden></span></a>
      <a data-view="como" onclick="go('como')"><svg viewBox="0 0 24 24" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M10 8.5l5 3.5-5 3.5z"/></svg>Método</a>
      <a data-view="accesos" onclick="go('accesos')"><svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"/></svg>Accesos</a>
      <a data-view="plan" onclick="go('plan')"><svg viewBox="0 0 24 24" stroke-width="1.8"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 13l2 2 4-4"/></svg>Plan</a>
    </div>

    <div class="pfoot">
      <div class="urow">
        <div class="av" style="background:<?= e($cliCol) ?>"><?= $cliIni ?></div>
        <div class="ui"><b><?= e($cl['name']) ?></b><span>Área de cliente</span></div>
        <a class="out" href="logout.php" title="Cerrar sesión"><?= $svgLogout ?></a>
      </div>
    </div>
  </aside>
  <div class="scrim" id="scrim" onclick="toggleMenu()"></div>

  <div class="main">
    <header class="head">
      <div style="display:flex;align-items:center;gap:12px">
        <button class="menu-btn" onclick="toggleMenu()"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        <div class="hi">
          <h1 id="greetName">Hola 👋</h1>
          <p>Echemos un vistazo a tu proyecto<?= trim((string)($cl['actual'] ?? '')) !== '' ? ' · ' . e(mb_strtolower((string)$cl['actual'])) . ' ' . date('Y') : '' ?></p>
        </div>
      </div>
      <div class="tools">
        <div class="search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input placeholder="Buscar en tu proyecto" onkeydown="if(event.key==='Enter'){event.preventDefault();portalSearch(this.value);}"></div>
        <button class="pill-btn go-informes" onclick="go('informes')"><svg viewBox="0 0 24 24"><path d="M14 3v5h5"/><path d="M7 3h7l5 5v11a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/></svg><?= trim((string)($cl['actual']??''))!=='' ? 'Informe de '.e(mb_strtolower((string)$cl['actual'])) : 'Ver informes' ?></button>
        <button class="pill-btn ghost" onclick="downloadPDF()" title="Descargar tu informe del mes en PDF"><svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14"/></svg>Descargar PDF</button>
        <button class="pill-btn ghost ptheme" onclick="portalToggleTheme()" title="Modo claro / oscuro" aria-label="Cambiar tema">
          <svg class="ic-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
          <svg class="ic-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        </button>
      </div>
    </header>

    <div class="content">

      <!-- ===== RESUMEN ===== -->
      <section class="view active" id="view-resumen">
        <div id="todoBanner"></div>
        <div class="card big-result conv-only">
          <span class="kicker" id="brKicker">Tu resultado</span>
          <div class="bigrow">
            <div class="bignum" id="brNum">0</div>
            <div class="bigmeta"><b>oportunidades de contacto</b><span class="up" id="brUp"></span></div>
          </div>
          <p class="bigtext">Son las veces que alguien os ha llamado, escrito por WhatsApp o rellenado un formulario este mes. Es lo que de verdad os trae clientes.</p>
          <div class="mini3">
            <div class="m"><div class="mi" style="background:linear-gradient(135deg,#3a3f47,#1f232a)"><svg viewBox="0 0 24 24"><path d="M6.6 10.8c1.4 2.8 3.8 5.2 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C9.8 21 3 14.2 3 5c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.2.2 2.4.6 3.6.1.4 0 .7-.2 1z"/></svg></div><b id="brLl">0</b><span>Llamadas</span><div class="dl" id="brLlD"></div></div>
            <div class="m"><div class="mi" style="background:linear-gradient(135deg,#3a3f47,#1f232a)"><svg viewBox="0 0 24 24"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.9c0 1.76.46 3.47 1.32 4.98L2 22l5.25-1.38a9.9 9.9 0 0 0 4.78 1.22h.01c5.46 0 9.9-4.45 9.9-9.9 0-2.65-1.02-5.14-2.9-7.02A9.82 9.82 0 0 0 12.04 2zm5.8 14.17c-.25.69-1.43 1.32-1.96 1.4-.5.07-1.13.1-1.83-.11-.42-.14-.96-.31-1.65-.61-2.9-1.25-4.8-4.17-4.94-4.36-.15-.19-1.19-1.58-1.19-3.01 0-1.43.75-2.14 1.02-2.43.27-.29.59-.36.78-.36l.56.01c.18.01.42-.07.66.5.25.59.84 2.04.91 2.19.07.15.12.32.02.51-.1.19-.15.31-.29.48-.15.17-.31.39-.44.52-.15.15-.3.31-.13.6.17.29.76 1.25 1.63 2.03 1.12 1 2.06 1.31 2.35 1.46.29.15.46.12.63-.07.17-.19.73-.85.92-1.14.19-.29.39-.24.66-.15.27.1 1.71.81 2 .96.29.15.49.22.56.34.07.12.07.69-.18 1.38z"/></svg></div><b id="brWa">0</b><span>WhatsApp</span><div class="dl" id="brWaD"></div></div>
            <div class="m"><div class="mi" style="background:linear-gradient(135deg,#3a3f47,#1f232a)"><svg viewBox="0 0 24 24"><path d="M8 4V3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v1h2a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2zm2 0h4V3h-4v1z"/></svg></div><b id="brFo">0</b><span>Formularios</span><div class="dl" id="brFoD"></div></div>
          </div>
        </div>

        <div class="card status-card" style="margin-top:14px">
          <div class="sc-top">
            <div><span class="kicker">Estado del proyecto</span><h3 id="estNombre"></h3></div>
            <span class="tag-dark" id="estTag"></span>
          </div>
          <div class="phasebar" id="estBar"></div>
          <div class="phase-labels" id="estLabels"></div>
          <p class="hs-next" id="estNext"></p>
          <div class="xlinks" style="margin-top:14px">
            <button class="xlink" onclick="go('tareas')"><svg viewBox="0 0 24 24"><path d="M9 6h11M9 12h11M9 18h11"/><path d="M4.5 6l1 1 2-2M4.5 12l1 1 2-2M4.5 18l1 1 2-2"/></svg>Ver el trabajo mes a mes</button>
          </div>
        </div>

        <h2 class="sec">¿Qué quieres ver?</h2>
        <div class="hub">
          <button class="tile" onclick="go('tareas')"><div class="ic"><svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M9 6h11M9 12h11M9 18h11"/><path d="M4.5 6l1 1 2-2M4.5 12l1 1 2-2M4.5 18l1 1 2-2"/></svg></div><div class="tx"><b>Lo que hemos hecho</b><p>Todo el trabajo, mes a mes.</p><span class="meta">Ver tareas →</span></div></button>
          <button class="tile conv-only" onclick="go('metricas')"><div class="ic"><svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M4 19V5M4 19h16M8 16v-4M12 16V8M16 16v-6"/></svg></div><div class="tx"><b>Tus números</b><p>Contactos y visibilidad en Google.</p><span class="meta">Ver métricas →</span></div></button>
        </div>

      </section>

      <!-- ===== CÓMO TRABAJAMOS ===== -->
      <section class="view" id="view-como">
        <div class="greet"><h1>Cómo trabajamos</h1><p>Nuestro método y cómo enfocamos cada servicio.</p></div>

        <div class="video-card" id="videoCard" onclick="playVideo()">
          <img src="https://img.youtube.com/vi/J9-aEZ523bA/hqdefault.jpg" alt="Cómo trabajamos">
          <span class="vplay"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></span>
          <span class="vlabel">▶ Cómo trabajamos · 2 min</span>
        </div>
        <p class="note" style="margin-top:10px">Vídeo provisional. <a id="ytLink" href="https://www.youtube.com/watch?v=J9-aEZ523bA" target="_blank" rel="noopener" style="color:var(--yellow);font-weight:600">Ábrelo en YouTube</a></p>

        <h2 class="sec">Nuestros servicios</h2>
        <div class="hub" id="servHub"></div>
      </section>

      <!-- ===== SERVICIO (detalle) ===== -->
      <section class="view" id="view-servicio"></section>

      <!-- ===== TU PLAN ===== -->
      <section class="view" id="view-plan">
        <div class="greet"><h1>Tu plan mensual</h1><p>Esto es exactamente lo que tienes contratado cada mes. Sin sorpresas.</p></div>

        <div class="plan-grid" id="planGrid" style="margin-top:18px"></div>

        <h2 class="sec">Qué incluye tu plan</h2>
        <div class="card" id="planResumen"></div>

        <div class="faq long" onclick="this.classList.toggle('open')" style="margin-top:12px">
          <div class="faq-q"><span>Ver el detalle completo de lo que incluye</span><svg class="qm" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg></div>
          <div class="faq-a"><div class="legal" id="planDetalle"></div></div>
        </div>
      </section>

      <!-- ===== PROGRESO ===== -->
      <section class="view" id="view-progreso">
        <h2 class="sec">Elige un mes</h2>
        <div class="months" id="months"></div>
        <div class="xlinks" id="progLinks"></div>
        <div id="taskList"></div>
      </section>

      <!-- ===== MÉTRICAS ===== -->
      <section class="view" id="view-metricas">
        <div class="mtx-head">
          <div>
            <h3 class="mtx-t">Tus métricas</h3>
            <p class="mh-sub">Tus contactos y tu visibilidad en Google, mes a mes.</p>
          </div>
          <div id="metMonths"></div>
        </div>
        <div class="xlinks mtx-links" id="metLinks"></div>
        <p class="note" id="metNote">Datos de ejemplo — se conectan en vivo a tu panel de Looker Studio.</p>

        <div class="mtx-grid">
          <!-- KPIs: Oportunidades + Visitas + Apariciones + CTR -->
          <div class="metric mkpi" id="metTotal"></div>
          <div class="metrics" id="metGoogle"></div>

          <!-- Evolución (área) + Donut de canales -->
          <div class="card mcard mc-evo" id="evoCard">
            <div class="ev-head">
              <div><h4 class="mc-t" id="evoTitle">Cómo evolucionan tus contactos</h4><p class="mc-d" id="evoSub">Evolución mes a mes · el mes elegido se resalta</p></div>
              <div class="tabs" id="evoTabs" style="margin:0"></div>
            </div>
            <div class="bars" id="bars"></div>
            <div id="evoTip" class="evo-tip" hidden></div>
          </div>
          <div class="card mcard mc-donut" id="metConv"></div>

          <!-- Barras de visitas por mes + Ranking de meses -->
          <div class="card mcard mc-bars" id="visCard">
            <h4 class="mc-t">Visitas a tu web por mes</h4>
            <p class="mc-d">Gente que ha entrado a tu web desde Google.</p>
            <div class="mbars" id="visBars"></div>
          </div>
          <div class="card mcard mc-rank" id="rankCard">
            <h4 class="mc-t">Meses con más contactos</h4>
            <p class="mc-d">Tus mejores meses del año.</p>
            <div class="mrank" id="rankList"></div>
          </div>

          <!-- Crecimiento (clics / apariciones) -->
          <div class="grow2 mc-full" id="growRow"></div>

          <!-- Tráfico + mapa (auto-fit: si están vacíos no dejan hueco) -->
          <div class="met-charts mc-full">
            <div class="card" id="trafficCard" hidden>
              <h4 class="mc-t">¿De dónde viene tu tráfico?</h4>
              <p class="mc-d" style="margin-bottom:16px">Cómo ha llegado la gente a tu web<span id="trafficWhen"></span>.</p>
              <div id="trafficBars"></div>
            </div>
            <div class="card" id="geoCard" hidden>
              <h4 class="mc-t">¿Desde dónde te visitan?</h4>
              <p class="mc-d" style="margin-bottom:14px">Países desde los que ha entrado la gente a tu web<span id="geoWhen"></span>.</p>
              <div class="geo-wrap"><div id="geoMap" class="geo-map"></div><div id="geoList" class="geo-list"></div></div>
            </div>
          </div>

          <!-- Calculadora (ancho completo) -->
          <div class="card calc mc-full">
            <h4>¿Cuánto pueden suponer tus contactos?</h4>
            <p class="sub">Cambia solo estos dos números y te decimos cuánto podrías ingresar. Es una estimación para que te hagas una idea.</p>
            <div class="calc-base">
              <div class="cb-l"><b>Contactos del mes</b><span>se pone solo según el mes elegido</span></div>
              <div class="cb-edit"><input type="number" id="ccOpp" value="80" min="0" step="1" oninput="renderCalc()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></div>
            </div>
            <div class="calc-grid">
              <div class="calc-field">
                <label>De cada 100, ¿cuántos acaban comprando?</label>
                <div class="calc-input"><input type="number" id="ccRate" value="5" min="0" max="100" step="1" oninput="renderCalc()"><span>%</span></div>
              </div>
              <div class="calc-field">
                <label>¿Cuánto te deja de media cada cliente?</label>
                <div class="calc-input"><span>€</span><input type="number" id="ccTicket" value="1500" min="0" step="50" oninput="renderCalc()"></div>
              </div>
            </div>
            <div class="calc-out">
              <div class="o"><div class="lbl">Clientes nuevos estimados</div><div class="v" id="ccClients">—</div></div>
              <div class="o hi"><div class="lbl" id="ccRevLbl">Podrías ingresar</div><div class="v" id="ccRev">—</div></div>
            </div>
            <p class="calc-note" id="ccPhrase"></p>
          </div>

          <!-- Looker (ancho completo) -->
          <div class="frame mc-full" id="lookerFrame">📊 Aquí se incrusta tu panel de Looker Studio (gráficas en vivo de la web + Google Maps)</div>
        </div>
      </section>

      <!-- ===== TAREAS ===== -->
      <section class="view" id="view-tareas">
        <h2 class="sec">Tu trabajo</h2>
        <p class="note">Esto es lo que estamos haciendo por ti, con quién lo lleva. Pulsa una tarea para ver el detalle.</p>
        <div id="tkList"></div>
      </section>

      <!-- ===== FACTURAS ===== -->
      <section class="view" id="view-facturas">
        <h2 class="sec">Tus facturas</h2>
        <p class="note">Todas tus facturas. Pulsa una para verla y descargarla en PDF.</p>
        <div id="facList"></div>
      </section>

      <!-- ===== REUNIONES ===== -->
      <section class="view" id="view-reuniones">
        <div class="sec-row">
          <h2 class="sec" style="margin:0">Tus reuniones</h2>
          <button class="pill-btn" id="reqOpenBtn" onclick="openReqModal()"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M12 14v3M10.5 15.5h3"/></svg>Solicitar reunión</button>
        </div>
        <p class="note">Aquí tienes tus reuniones con el equipo. ¿Necesitas hablar? Pide una nueva y te la confirmamos.</p>
        <div id="reqList"></div>
        <h2 class="sec" id="reuProxHd">Próximas</h2>
        <div id="reuProx"></div>
        <h2 class="sec" id="reuPasHd" hidden>Anteriores</h2>
        <div id="reuPas"></div>
      </section>

      <!-- ===== SOPORTE ===== -->
      <section class="view" id="view-soporte">
        <div class="sec-row">
          <h2 class="sec" style="margin:0">Soporte</h2>
          <button class="pill-btn" id="msgOpenBtn" onclick="openMsgModal()"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Escríbenos</button>
        </div>
        <p class="note">Cuéntanos cualquier cosa —una duda, una petición, un problema— y te respondemos. Aquí ves tus mensajes y en qué estado están.</p>
        <div id="tkHist"></div>
      </section>

      <!-- ===== INFORMES ===== -->
      <section class="view" id="view-informes">
        <h2 class="sec">Tus informes mensuales</h2>
        <p class="note">Cada mes recibes aquí tu informe con todo el análisis. Quedan guardados: puedes abrir cualquiera cuando quieras.</p>
        <div id="infList"></div>
      </section>

      <!-- ===== ACCESOS ===== -->
      <section class="view" id="view-accesos">
        <h2 class="sec" id="vaultHd" hidden>Tus contraseñas y accesos</h2>
        <p class="note" id="vaultNote" hidden>Tus claves de acceso, guardadas y seguras. Pulsa el ojo para verlas o el icono para copiarlas.</p>
        <div id="vaultList"></div>

        <h2 class="sec">Enlaces y recursos</h2>
        <p class="note">Todo lo que compartimos contigo, a un clic.</p>
        <div class="res-grid" id="resGrid"></div>

        <h2 class="sec">¿Necesitas algo?</h2>
        <div class="contact">
          <div class="cg"></div>
          <div class="ct"><b>¿Necesitas algo de tu equipo de <?= e($BRAND['name']) ?>?</b><p>Ábrenos un ticket y te respondemos por aquí. Verás el estado en Soporte.</p></div>
          <div class="acts">
            <button class="cbtn solid" onclick="go('soporte');setTimeout(openMsgModal,60)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Abrir un ticket</button>
          </div>
        </div>

        <footer><?= e($BRAND['name']) ?> · Área privada de <?= e($META['name']) ?> · Actualizado automáticamente desde el seguimiento del proyecto.</footer>
      </section>

    </div>
  </div>
</div>

<!-- Informe imprimible (se rellena al pulsar «Descargar PDF»; solo se ve al imprimir) -->
<div id="printReport"></div>

<!-- Popups (a nivel de body para que position:fixed no se rompa con las animaciones) -->
<div class="pmov" id="reqOv" onclick="if(event.target===this)pmOpen('reqOv',false)">
  <div class="pmodal">
    <div class="pmh"><span class="pmi"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span><span class="pmt"><b>Solicitar una reunión</b><span id="reqIntro">Te la confirmamos nosotros</span></span></div>
    <div class="pmb">
      <div class="form-row">
        <div class="form-field"><label>Día que prefieres <span style="font-weight:400">(opcional)</span></label><input type="date" id="reqFecha"></div>
        <div class="form-field"><label>Franja <span style="font-weight:400">(opcional)</span></label><select id="reqFranja"><option value="">Sin preferencia</option><option>Por la mañana</option><option>Al mediodía</option><option>Por la tarde</option></select></div>
      </div>
      <div class="form-field"><label>¿De qué quieres hablar?</label><textarea id="reqMotivo" placeholder="Ej. Revisar los resultados del mes y los próximos pasos"></textarea></div>
    </div>
    <div class="pmf"><button class="pm-g" type="button" onclick="pmOpen('reqOv',false)">Cancelar</button><button class="pm-p" type="button" id="reqSend" onclick="sendMeetReq()">Enviar solicitud</button></div>
  </div>
</div>
<div class="pmov" id="msgOv" onclick="if(event.target===this)pmOpen('msgOv',false)">
  <div class="pmodal">
    <div class="pmh"><span class="pmi"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span><span class="pmt"><b>Escríbenos</b><span id="msgIntro">Nos llega a tu equipo</span></span></div>
    <div class="pmb">
      <div class="form-field"><label>Asunto <span style="font-weight:400">(opcional)</span></label><input id="msgAsunto" maxlength="120" placeholder="Ej. Duda sobre el informe de este mes"></div>
      <div class="form-field"><label>Tu mensaje</label><textarea id="msgCuerpo" placeholder="Escribe aquí lo que necesitas…"></textarea></div>
    </div>
    <div class="pmf"><button class="pm-g" type="button" onclick="pmOpen('msgOv',false)">Cancelar</button><button class="pm-p" type="button" id="msgSend" onclick="sendMsg()">Enviar mensaje</button></div>
  </div>
</div>

<script>
  /* Modo claro / oscuro del portal. Persistencia independiente del ERP. */
  window.portalToggleTheme=function(){var h=document.documentElement,d=h.getAttribute('data-theme')==='dark';
    var aplicar=function(){if(d){h.removeAttribute('data-theme');}else{h.setAttribute('data-theme','dark');}try{localStorage.setItem('portalTheme',d?'light':'dark');}catch(e){}
      /* Las gráficas SVG (donut, área, barras, mapa) toman su color de las variables
         del portal en el momento de pintarse; al cambiar de tema hay que repintarlas
         para que cojan el acento nuevo (si no, en oscuro quedarían con el color claro). */
      try{ if(window.renderMetrics) window.renderMetrics('data'); }catch(e){}};
    try{var b=document.querySelector('.ptheme');if(b){var r=b.getBoundingClientRect();h.style.setProperty('--tx',(r.left+r.width/2)+'px');h.style.setProperty('--ty',(r.top+r.height/2)+'px');}}catch(e){}
    if(document.startViewTransition&&!matchMedia('(prefers-reduced-motion:reduce)').matches){
      try{ var __vt=document.startViewTransition(aplicar);
        /* Si la transición se aborta (p.ej. TimeoutError al tardar el pintado),
           su promesa se rechaza; la capturamos para no dejar un error suelto en
           consola. El DOM ya quedó actualizado por 'aplicar'. */
        if(__vt&&__vt.finished&&__vt.finished.catch)__vt.finished.catch(function(){});
        if(__vt&&__vt.updateCallbackDone&&__vt.updateCallbackDone.catch)__vt.updateCallbackDone.catch(function(){});
      }catch(e){ aplicar(); }
    }else{aplicar();}};
  var DATA=<?php echo json_encode($DATA, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
  var BRAND=<?php echo json_encode($BRAND, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
  /* true solo cuando es un cliente REAL logueado (no un admin en vista previa). El
     envío de mensajes solo funciona para el cliente; en vista previa se desactiva. */
  var CANMSG=<?php echo (!$previewId && current_client()) ? 'true':'false'; ?>;
  var PORTAL=<?php echo json_encode($PORTAL, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
  var CLIID=<?php echo (int)$cid; ?>;
  var EDIT=<?php echo $adminEdit ? 'true' : 'false'; ?>;
  var META=<?php echo json_encode($META, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
  var TIPOS=<?php echo json_encode($editTipos ?: [], JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
  var MESES=DATA.meses, ACTUAL=DATA.actual;
  var TAREAS=DATA.tareas;
  var CONFIG=DATA.config;
  var ESTADO=DATA.estado;
  var RECURSOS=DATA.accesos;
  var INFORMES=DATA.informes||[];
  var SERVICIOS=DATA.servicios;            // null = todos desbloqueados (compatibilidad)
  var CFG=DATA.cfg||{};
  var MEETING_URL=CFG.meeting_url||'#';     // enlace de reuniones (editable en Ajustes)
  var SERV_VIDEO=CFG.video_id||'J9-aEZ523bA';  // vídeo de presentación general (editable en Ajustes)
  var VIDMAP={'Diseño web':'video_web','SEO':'video_seo','SEM':'video_sem','CRO':'video_cro','Tiendas online':'video_tienda','Meta':'video_meta'};
  var SERV_VIDEOS=CFG.serv_videos||{};   // del catálogo de Servicios (Ajustes)
  /* Primero el vídeo que el servicio lleva en el catálogo; si no lo tiene, la
     clave antigua por si quedara algo sin migrar; y si tampoco, el general. */
  function servVideo(k){
    var v=(SERV_VIDEOS[k]||'').trim();
    if(!v) v=(CFG[VIDMAP[k]]||'').trim();
    return v||SERV_VIDEO;
  }
  function hasService(k){ return (SERVICIOS==null) ? true : (SERVICIOS.indexOf(k)>=0); }
  var MET=DATA.met||{};
  /* Normaliza cada mes: si Search Console aún no escribió vi/ap/ctr, o faltan
     llamadas/WhatsApp/formularios, se ponen a 0. Así un mes con datos PARCIALES
     (lo normal: GA4 y Search Console escriben en momentos distintos) no rompe la
     vista de Métricas con un TypeError. */
  for(var _m in MET){ if(MET.hasOwnProperty(_m)){ var _x=MET[_m]||{};
    ['ll','wa','fo','vi','ap','ctr'].forEach(function(k){ if(typeof _x[k]!=='number'||isNaN(_x[k])) _x[k]=0; });
    _x.total=_x.ll+_x.wa+_x.fo; MET[_m]=_x; } }
  /* --- Escapado: todo lo que venga de la base de datos pasa por aquí antes
         de entrar en un innerHTML. No cambia nada visualmente, solo evita que
         un texto con < > " o un enlace javascript: se ejecute. --- */
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function escJs(s){ return String(s==null?'':s).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'&quot;').replace(/</g,'\\u003c'); }
  function safeUrl(u){
    u=String(u==null?'':u).trim();
    if(u==='') return '#';
    if(/^(javascript|data|vbscript):/i.test(u.replace(/[\s\u0000-\u001f]/g,''))) return '#';
    return esc(u);
  }
  var LOGOS={
    figma:'<svg viewBox="0 0 38 57" width="23" height="23"><path d="M19 28.5a9.5 9.5 0 1 1 19 0 9.5 9.5 0 0 1-19 0z" fill="#1ABCFE"/><path d="M0 47.5A9.5 9.5 0 0 1 9.5 38H19v9.5a9.5 9.5 0 1 1-19 0z" fill="#0ACF83"/><path d="M19 0v19h9.5a9.5 9.5 0 1 0 0-19H19z" fill="#FF7262"/><path d="M0 9.5A9.5 9.5 0 0 0 9.5 19H19V0H9.5A9.5 9.5 0 0 0 0 9.5z" fill="#F24E1E"/><path d="M0 28.5A9.5 9.5 0 0 0 9.5 38H19V19H9.5A9.5 9.5 0 0 0 0 28.5z" fill="#A259FF"/></svg>',
    drive:'<svg viewBox="0 0 87.3 78" width="24" height="24"><path d="M6.6 66.85l3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8H0c0 1.55.4 3.1 1.2 4.5z" fill="#0066da"/><path d="M43.65 25L29.9 1.2c-1.35.8-2.5 1.9-3.3 3.3L1.2 48.5C.4 49.9 0 51.45 0 53h27.5z" fill="#00ac47"/><path d="M73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5H59.8l5.85 11.5z" fill="#ea4335"/><path d="M43.65 25L57.4 1.2C56.05.4 54.5 0 52.9 0H34.4c-1.6 0-3.15.45-4.5 1.2z" fill="#00832d"/><path d="M59.8 53H27.5L13.75 76.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.2z" fill="#2684fc"/><path d="M73.4 26.5l-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3L43.65 25 59.8 53h27.45c0-1.55-.4-3.1-1.2-4.5z" fill="#ffba00"/></svg>',
    web:'<svg viewBox="0 0 24 24" width="23" height="23"><circle cx="12" cy="12" r="10" fill="#1f232a"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20" stroke="#fff" stroke-width="1.3" fill="none"/></svg>',
    looker:'<svg viewBox="0 0 24 24" width="23" height="23"><rect x="3" y="3" width="18" height="18" rx="5" fill="#4285F4"/><path d="M7 16v-3M12 16v-6M17 16v-4" stroke="#fff" stroke-width="2.3" stroke-linecap="round" fill="none"/></svg>',
    generic:'<svg viewBox="0 0 24 24" width="22" height="22"><circle cx="12" cy="12" r="10" fill="#1f232a"/><path d="M8 12l3 3 5-6" stroke="#fff" stroke-width="2" fill="none"/></svg>'
  };
  var PLAN_STYLE=[
    {g1:'#3a3f47',g2:'#1f232a',icon:'<path d="M6 2h8l4 4v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1v4h4z"/>'},
    {g1:'#b985ff',g2:'#9a4dff',icon:'<path d="M4 4h11l5 5v11H4z"/>'},
    {g1:'#45d27e',g2:'#1e9e4a',icon:'<path d="M4 20V4h16v16zM8 16v-4M12 16V8M16 16v-6" fill="none" stroke="#fff" stroke-width="2"/>'},
    {g1:'#ff9f43',g2:'#e0892a',icon:'<path d="M3 11a1 1 0 0 1 1-1h3l6-4v12l-6-4H4a1 1 0 0 1-1-1z"/>'},
    {g1:'#2fd0c5',g2:'#0aa6a6',icon:'<path d="M5 9l1-4h12l1 4v1a2 2 0 0 1-4 0 2 2 0 0 1-4 0 2 2 0 0 1-4 0V9zm1 3h12v8H6z"/>'}
  ];
  var curMet=ACTUAL;
  function fmt(n){return (typeof n==='number'&&!isNaN(n)?n:0).toLocaleString('es-ES');}
  function delta(cur,prev,pts){if(prev==null)return null;/* Sin mes anterior con el que comparar (era 0): no hay base para el %; marcamos "nuevo" en gris en vez de +Infinity%. */if(!prev)return {neutral:true,s:'nuevo'};if(pts){var dd=cur-prev;return {s:(dd>=0?'▲ +':'▼ ')+Math.abs(dd).toFixed(1).replace('.',',')+' pts',up:dd>=0};}var pp=Math.round((cur-prev)/prev*100);if(!isFinite(pp))return {neutral:true,s:'nuevo'};return {s:(pp>=0?'▲ +':'▼ ')+Math.abs(pp)+'%',up:pp>=0};}
  /* Color desde las variables del portal: así las gráficas (SVG generado por JS)
     se leen bien en claro y en oscuro sin hex fijos. --yellow es el acento (neutro
     oscuro en claro, azul en oscuro). */
  function cssVar(n,f){try{var v=getComputedStyle(document.documentElement).getPropertyValue(n).trim();return v||f;}catch(e){return f;}}
  function accent(){return cssVar('--yellow','#1f232a');}
  /* Iconos SVG inline de los KPIs (solo trazo; el color lo pone currentColor). */
  var IC={
    opp:'<svg viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
    vi:'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg>',
    ap:'<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>',
    ctr:'<svg viewBox="0 0 24 24"><path d="M9 11l2.5 2.5L18 7"/><path d="M21 12a9 9 0 1 1-6.2-8.5"/></svg>'
  };
  /* ============================================================
     Helpers de pulido premium (compartidos por todas las gráficas):
     tooltip único que sigue al ratón, count-up con formato y draw-on.
     Todo respeta prefers-reduced-motion (pinta el estado final directo).
     ============================================================ */
  function mtxRM(){ try{ return matchMedia('(prefers-reduced-motion:reduce)').matches; }catch(e){ return false; } }
  /* Tooltip único que SIGUE al ratón con inercia (lerp ~0.22/frame). El estado de
     reposo es "oculto" (opacity 0); el rAF solo mueve mientras el ratón está encima,
     nunca fija un estado final. Con reduced-motion la posición es directa (sin lerp). */
  var __mtip=null,__mtxRaf=0,__mtxTgt={x:0,y:0},__mtxCur={x:0,y:0},__mtxOn=false,__mtxSeed=false;
  function mtxTip(){ if(!__mtip){ __mtip=document.createElement('div'); __mtip.className='met-tip'; __mtip.hidden=true; document.body.appendChild(__mtip); } return __mtip; }
  function mtxTipPlace(x,y){ var t=mtxTip(); var w=t.offsetWidth,h=t.offsetHeight,vw=window.innerWidth,vh=window.innerHeight; var L=x+16; if(L+w>vw-8) L=x-16-w; if(L<8) L=8; var T=y-h-12; if(T<8) T=y+20; if(T+h>vh-8) T=vh-8-h; t.style.left=L+'px'; t.style.top=T+'px'; }
  function mtxTipLoop(){ __mtxRaf=0; if(!__mtxOn) return; var dx=__mtxTgt.x-__mtxCur.x, dy=__mtxTgt.y-__mtxCur.y; if(Math.abs(dx)<0.5 && Math.abs(dy)<0.5){ __mtxCur.x=__mtxTgt.x; __mtxCur.y=__mtxTgt.y; } else { __mtxCur.x+=dx*0.22; __mtxCur.y+=dy*0.22; __mtxRaf=requestAnimationFrame(mtxTipLoop); } mtxTipPlace(__mtxCur.x,__mtxCur.y); }
  function mtxTipShow(html){ var t=mtxTip(); t.innerHTML=html; var wasHidden=t.hidden; t.hidden=false; __mtxOn=true; __mtxSeed=true; if(wasHidden){ requestAnimationFrame(function(){ if(__mtxOn) t.classList.add('in'); }); } else { t.classList.add('in'); } }
  function mtxTipFollow(ev){ __mtxTgt.x=ev.clientX; __mtxTgt.y=ev.clientY; var t=mtxTip(); if(t.hidden) return; if(__mtxSeed || mtxRM()){ __mtxSeed=false; __mtxCur.x=__mtxTgt.x; __mtxCur.y=__mtxTgt.y; mtxTipPlace(__mtxTgt.x,__mtxTgt.y); return; } if(!__mtxRaf) __mtxRaf=requestAnimationFrame(mtxTipLoop); }
  function mtxTipHide(){ __mtxOn=false; if(__mtxRaf){ cancelAnimationFrame(__mtxRaf); __mtxRaf=0; } if(__mtip){ __mtip.classList.remove('in'); __mtip.hidden=true; } }
  /* Formateadores para el count-up (respetan miles y decimales como fmt). */
  function mtxFmt(kind){ return kind==='fmt'?function(v){return fmt(Math.round(v));} : kind==='pct'?function(v){return (Math.round(v*10)/10).toFixed(1).replace('.',',')+'%';} : function(v){return String(Math.round(v));}; }
  function mtxCount(el,to,from,fmtFn,dur,endText,onEnd){
    if(!el) return; fmtFn=fmtFn||function(v){return String(Math.round(v));}; from=from||0; dur=dur||820;
    var fin=(endText!=null)?endText:fmtFn(to);
    /* Si no hay que animar (reduced-motion, sin cambio, o pestaña en segundo plano
       donde el rAF no correría) fijamos el valor final de forma síncrona. */
    if(mtxRM()||from===to||document.hidden){ el.textContent=fin; if(onEnd)onEnd(); return; }
    /* Flip-in: mientras cuenta, el número entra con un leve blur+desplazamiento.
       Solo en elementos HTML (los KPIs); el centro del donut es <text> SVG y lleva
       su propio "pop", así que ahí no se aplica. */
    if(!(window.SVGElement && el instanceof SVGElement)){
      el.classList.remove('mtx-flip'); void el.offsetWidth; el.classList.add('mtx-flip');
      el.addEventListener('animationend',function h(){ el.classList.remove('mtx-flip'); el.removeEventListener('animationend',h); });
    }
    var t0=0;
    function step(ts){ if(!t0)t0=ts; var p=Math.min(1,(ts-t0)/dur); var e=1-Math.pow(1-p,3); if(p<1){ el.textContent=fmtFn(from+(to-from)*e); requestAnimationFrame(step); } else { el.textContent=fin; if(onEnd)onEnd(); } }
    requestAnimationFrame(step);
  }
  /* Dibuja un trazo SVG (draw-on). Robusto: el REPOSO deja la línea dibujada
     (offset 0); la animación va de len→0. Si se interrumpe o la pestaña está en
     segundo plano, la línea NUNCA queda invisible (se fija dibujada de forma síncrona). */
  function mtxDrawOn(path,dur,delay){
    if(!path) return;
    if(mtxRM()||document.hidden){ path.style.strokeDasharray=''; path.style.strokeDashoffset=''; path.style.animation=''; return; }
    var len; try{ len=path.getTotalLength(); }catch(e){ return; } if(!len) return;
    path.style.strokeDasharray=len; path.style.strokeDashoffset=len;
    path.style.animation='none'; void path.getBoundingClientRect();
    path.style.animation='mtxStroke '+(dur||700)+'ms var(--ease-expo) '+(delay||0)+'ms both';
    path.addEventListener('animationend',function h(){ path.style.animation=''; path.style.strokeDashoffset='0'; path.removeEventListener('animationend',h); });
  }
  /* Count-up de los KPI. mode==='data' → desde el valor anterior; si no, desde 0. */
  var KPI_LAST={};
  function mtxKpis(mode){
    document.querySelectorAll('#view-metricas .mk-n[data-cv]').forEach(function(el){
      var to=parseFloat(el.getAttribute('data-cv'))||0, cf=el.getAttribute('data-cf')||'int', key=el.getAttribute('data-ck')||'';
      var from=(mode==='data' && (key in KPI_LAST))?KPI_LAST[key]:0;
      KPI_LAST[key]=to;
      mtxCount(el,to,from,mtxFmt(cf),820,el.getAttribute('data-cvtxt'));
    });
  }
  /* Hover de sparkline (capa transparente + crosshair + punto más cercano). */
  function mtxSparkHover(gsp,vals,color){
    if(!gsp) return; var svg=gsp.querySelector('.sp-svg'); if(!svg) return;
    var cross=svg.querySelector('.sp-cross'), hi=svg.querySelector('.sp-hi'), overlay=svg.querySelector('.sp-hot');
    var n=vals.length, w=320, hh=46, pad=3, mx=Math.max.apply(null,vals.concat([1]));
    var xs=vals.map(function(v,i){ return n===1? w/2 : pad+(w-2*pad)*i/(n-1); });
    var ys=vals.map(function(v){ return pad+(hh-2*pad)*(1-v/mx); });
    function at(ev){ var r=svg.getBoundingClientRect(); if(!r.width) return; var rx=(ev.clientX-r.left)/r.width*w; var bi=0,bd=1e9; for(var i=0;i<n;i++){ var dd=Math.abs(xs[i]-rx); if(dd<bd){bd=dd;bi=i;} }
      if(cross){ cross.setAttribute('x1',xs[bi].toFixed(1)); cross.setAttribute('x2',xs[bi].toFixed(1)); cross.style.opacity='1'; }
      if(hi){ hi.setAttribute('cx',xs[bi].toFixed(1)); hi.setAttribute('cy',ys[bi].toFixed(1)); hi.style.opacity='1'; }
      mtxTipShow('<div class="tt-k"><span class="tt-dot" style="background:'+color+'"></span><b>'+fmt(vals[bi])+'</b></div><small>'+esc(MESES[bi])+' 2026</small>'); mtxTipFollow(ev); }
    overlay.addEventListener('mousemove',at);
    overlay.addEventListener('mouseleave',function(){ if(cross)cross.style.opacity='0'; if(hi)hi.style.opacity='0'; mtxTipHide(); });
  }
  /* Contenido interno de un KPI (etiqueta + icono + número + variación).
     cv/cf: valor numérico y tipo de formato para el count-up. */
  function kpiInner(num,lbl,dObj,icon,cv,cf){
    var dl;
    if(!dObj){dl='<div class="delta flat">primer dato</div>';}
    else if(dObj.neutral){dl='<div class="delta flat">'+dObj.s+'</div>';}
    else{dl='<div class="delta'+(dObj.up?' up':' down')+'">'+dObj.s+'</div>';}
    var cvAttr=(cv==null)?'':(' data-cv="'+cv+'" data-cf="'+(cf||'int')+'" data-ck="'+esc(lbl)+'" data-cvtxt="'+esc(''+num)+'"');
    return '<div class="mk-top"><span class="mk-lb">'+lbl+'</span>'+(icon?'<span class="mk-ic">'+icon+'</span>':'')+'</div><div class="mk-n num tnum"'+cvAttr+'>'+num+'</div>'+dl;
  }
  function metCard(num,lbl,dObj,icon,cv,cf){return '<div class="metric mkpi">'+kpiInner(num,lbl,dObj,icon,cv,cf)+'</div>';}
  /* Donut de contactos por canal (llamadas / WhatsApp / formularios) del mes. */
  function donutBlock(ll,wa,fo,total){
    var C=2*Math.PI*54, parts=[{v:ll,c:accent(),nm:'Llamadas'},{v:wa,c:'#12a150',nm:'WhatsApp'},{v:fo,c:'#8b5cf6',nm:'Formularios'}];
    var tot=ll+wa+fo, acc=0, segs='';
    if(tot>0){ parts.forEach(function(p,pi){ if(p.v<=0) return; var len=p.v/tot*C, rot=-90+acc/tot*360, pct=Math.round(p.v/tot*100);
      segs+='<circle class="donut-seg" data-i="'+pi+'" data-nm="'+esc(p.nm)+'" data-v="'+p.v+'" data-pct="'+pct+'" data-c="'+p.c+'" data-len="'+len.toFixed(2)+'" cx="80" cy="80" r="54" fill="none" stroke="'+p.c+'" stroke-width="17" stroke-dasharray="'+len.toFixed(2)+' '+(C-len).toFixed(2)+'" stroke-dashoffset="'+len.toFixed(2)+'" transform="rotate('+rot.toFixed(2)+' 80 80)"/>';
      acc+=p.v; }); }
    var svg='<svg viewBox="0 0 160 160" width="150" height="150" role="img" aria-label="Contactos por canal"><circle cx="80" cy="80" r="54" fill="none" stroke="var(--soft)" stroke-width="17"/>'+segs+'<text class="donut-c1" x="80" y="73" text-anchor="middle" fill="var(--muted)" style="font-size:11px">Total</text><text class="donut-c2" x="80" y="97" text-anchor="middle" fill="var(--ink-strong)" style="font-size:24px;font-weight:800">0</text></svg>';
    var leg='<ul class="leg">'; parts.forEach(function(p,pi){ leg+='<li data-i="'+pi+'"><span class="nm"><span class="dot" style="background:'+p.c+'"></span>'+p.nm+'</span><span class="vv tnum">'+p.v+'</span></li>'; }); leg+='</ul>';
    return '<div class="mdonut" data-total="'+total+'">'+svg+leg+'</div>';
  }
  /* Anima el donut (segmentos que crecen barriendo + count-up del centro) y
     activa el hover: al pasar por un canal el centro muestra su nombre/valor/%. */
  var __donutLast=0;
  function initDonut(mode){
    var wrap=document.querySelector('#metConv .mdonut'); if(!wrap) return;
    var total=parseFloat(wrap.getAttribute('data-total'))||0;
    var c1=wrap.querySelector('.donut-c1'), c2=wrap.querySelector('.donut-c2');
    var segs=wrap.querySelectorAll('.donut-seg'), legs=wrap.querySelectorAll('.leg li');
    var from=(mode==='data')?__donutLast:0; __donutLast=total;
    /* Count-up del centro con un pequeño pop de escala al terminar. */
    mtxCount(c2,total,from,function(v){return String(Math.round(v));},760,null,function(){
      if(mtxRM()||!c2) return; c2.classList.remove('cpop'); void c2.getBoundingClientRect(); c2.classList.add('cpop');
      c2.addEventListener('animationend',function h(){ c2.classList.remove('cpop'); c2.removeEventListener('animationend',h); });
    });
    var rm=mtxRM();
    /* Los segmentos barren su arco (offset len→0) escalonados. Robusto: el valor
       final (offset 0 = segmento visible) se fija síncronamente si hay reduced-motion
       o la pestaña está en segundo plano; nunca queda un segmento a medio pintar. */
    segs.forEach(function(s,i){
      if(rm||document.hidden){ s.style.strokeDashoffset='0'; return; }
      var len=parseFloat(s.getAttribute('data-len'))||0;
      s.style.strokeDashoffset=len; s.style.animation='none'; void s.getBoundingClientRect();
      s.style.animation='mtxStroke .66s var(--ease-expo) '+(i*110)+'ms both';
      s.addEventListener('animationend',function h(){ s.style.animation=''; s.style.strokeDashoffset='0'; s.removeEventListener('animationend',h); });
    });
    /* El donut se asienta con un leve scale+rotate (solo al abrir, no al cambiar mes). */
    if(!rm && mode!=='data'){ var dsvg=wrap.querySelector('svg'); if(dsvg){ dsvg.classList.remove('settle'); void dsvg.getBoundingClientRect(); dsvg.classList.add('settle'); dsvg.addEventListener('animationend',function h(){ dsvg.classList.remove('settle'); dsvg.removeEventListener('animationend',h); }); } }
    function hi(i,on){
      segs.forEach(function(s){ var si=+s.getAttribute('data-i'); s.style.strokeWidth=(on&&si===i)?'21':'17'; s.style.opacity=(on&&si!==i)?'.35':'1'; });
      legs.forEach(function(l){ l.classList.toggle('hot', on&&(+l.getAttribute('data-i'))===i); });
      if(on){ var seg=wrap.querySelector('.donut-seg[data-i="'+i+'"]'); if(seg){ c1.textContent=seg.getAttribute('data-nm')+' · '+seg.getAttribute('data-pct')+'%'; c1.setAttribute('fill',seg.getAttribute('data-c')); c2.textContent=fmt(parseFloat(seg.getAttribute('data-v'))||0); } }
      else { c1.textContent='Total'; c1.setAttribute('fill','var(--muted)'); c2.textContent=String(Math.round(total)); }
    }
    function bind(el){ var i=+el.getAttribute('data-i'); el.addEventListener('mouseenter',function(){ hi(i,true); }); el.addEventListener('mouseleave',function(){ hi(i,false); }); }
    segs.forEach(bind); legs.forEach(bind);
  }
  /* Barras de visitas (vi) por mes; se resalta el mes elegido. Reusa animateBars. */
  function drawVisBars(){
    var host=document.getElementById('visBars'); if(!host) return;
    var vals=MESES.map(function(m){return MET[m].vi||0;}), mx=Math.max.apply(null,vals.concat([1])), h='';
    MESES.forEach(function(m,i){
      var on=(curMet!=='Global'&&m===curMet), pct=vals[i]>0?Math.max(4,Math.round(vals[i]/mx*100)):0;
      h+='<div class="mb-b" data-i="'+i+'"><div class="mb-track"><div class="col'+(on?' on':'')+'" data-h="'+pct+'" style="height:'+pct+'%"></div></div><div class="mb-v tnum">'+fmt(vals[i])+'</div><div class="mb-m">'+esc(m.slice(0,3))+'</div></div>';
    });
    host.innerHTML=h; animateBars();
    /* Hover: toda la columna es zona sensible (no solo el rectángulo pintado). */
    var acc=accent();
    host.querySelectorAll('.mb-b').forEach(function(b){
      var i=+b.getAttribute('data-i');
      b.addEventListener('mouseenter',function(){ b.classList.add('hot'); mtxTipShow('<div class="tt-k"><span class="tt-dot" style="background:'+acc+'"></span><b>'+fmt(vals[i])+'</b> visitas</div><small>'+esc(MESES[i])+' 2026</small>'); });
      b.addEventListener('mousemove',mtxTipFollow);
      b.addEventListener('mouseleave',function(){ b.classList.remove('hot'); mtxTipHide(); });
    });
  }
  /* Ranking de meses con más oportunidades (ll+wa+fo); barra proporcional. */
  function drawRank(){
    var host=document.getElementById('rankList'); if(!host) return;
    var arr=MESES.map(function(m){return {m:m,v:(MET[m].total||0)};}).filter(function(x){return x.v>0;});
    arr.sort(function(a,b){return b.v-a.v;}); arr=arr.slice(0,5);
    if(!arr.length){ host.innerHTML='<p class="mc-d">Aún no hay contactos registrados.</p>'; return; }
    var mx=arr[0].v||1, h='';
    arr.forEach(function(x,idx){ var on=(curMet!=='Global'&&x.m===curMet), w=Math.max(4,Math.round(x.v/mx*100)); h+='<div class="r'+(on?' on':'')+'" data-i="'+idx+'"><div class="r-top"><span class="r-nm">'+esc(x.m)+'</span><span class="r-v tnum">'+x.v+'</span></div><div class="r-bar"><i data-w="'+w+'" style="width:0'+(on?'':';opacity:.5')+'"></i></div></div>'; });
    host.innerHTML=h;
    /* Las barras crecen de ancho con rebote (--ease-spring) escalonadas. El ancho
       final es el DESTINO de la transición y se fija de forma síncrona (antes se
       ponía en un setTimeout: en segundo plano la barra quedaba clavada en 0). */
    var rm=mtxRM(), acc=accent();
    host.querySelectorAll('.r-bar i').forEach(function(b,i){ var w=b.getAttribute('data-w')+'%'; if(rm||document.hidden){ b.style.transition='none'; b.style.width=w; return; } b.style.transition='none'; b.style.width='0'; void b.offsetWidth; b.style.transition='width .82s var(--ease-spring) '+(60+i*90)+'ms'; b.style.width=w; });
    /* Hover: toda la fila es zona sensible. */
    host.querySelectorAll('.r').forEach(function(r){
      var i=+r.getAttribute('data-i'), x=arr[i];
      r.addEventListener('mouseenter',function(){ r.classList.add('hot'); mtxTipShow('<div class="tt-k"><span class="tt-dot" style="background:'+acc+'"></span><b>'+x.v+'</b> contactos</div><small>'+esc(x.m)+' 2026</small>'); });
      r.addEventListener('mousemove',mtxTipFollow);
      r.addEventListener('mouseleave',function(){ r.classList.remove('hot'); mtxTipHide(); });
    });
  }
  var MET_CERO={ll:0,wa:0,fo:0,total:0,vi:0,ap:0,ctr:0};
  function gData(){var g={ll:0,wa:0,fo:0,total:0,vi:0,ap:0};MESES.forEach(function(m){var x=MET[m]||MET_CERO;g.ll+=x.ll;g.wa+=x.wa;g.fo+=x.fo;g.total+=x.total;g.vi+=x.vi;g.ap+=x.ap;});g.ctr=g.ap>0?Math.round(g.vi/g.ap*1000)/10:0;return g;}
  function curData(){return curMet==='Global'?gData():(MET[curMet]||MET_CERO);}
  /* Entrada orquestada: al ABRIR Métricas las tarjetas entran en cascada. Es CSS
     (clase .mtx-anim + animation-delay por índice); el reposo de cada tarjeta es
     "visible", así que si no se ejecuta (reduced-motion o pestaña en segundo plano)
     se ven normales, nunca atrapadas en opacity:0. */
  function mtxCascade(){
    var grid=document.querySelector('#view-metricas .mtx-grid'); if(!grid) return;
    if(mtxRM()||document.hidden) return;
    /* #metGoogle usa display:contents: sus 3 KPIs son items reales de la rejilla,
       así que los recogemos en su posición de lectura. Saltamos tarjetas ocultas
       (crecimiento/tráfico/geo vacíos) para no dejar huecos en el escalonado. */
    var cards=[];
    Array.prototype.forEach.call(grid.children,function(ch){
      if(ch.id==='metGoogle'){ Array.prototype.forEach.call(ch.children,function(c){ cards.push(c); }); }
      else if(ch.getClientRects().length){ cards.push(ch); }
    });
    cards.forEach(function(c,i){
      c.classList.remove('mtx-anim'); c.style.setProperty('--mi',(i*64)+'ms'); void c.offsetWidth; c.classList.add('mtx-anim');
      c.addEventListener('animationend',function h(){ c.classList.remove('mtx-anim'); c.style.removeProperty('--mi'); c.removeEventListener('animationend',h); });
    });
  }
  function renderMetrics(mode){
    if(!MESES||!MESES.length){return;}
    /* Si el mes "actual" no cuadra con ningún mes de métricas, caemos al último
       disponible en vez de reventar la vista (coherencia de meses). */
    if(curMet!=='Global' && MESES.indexOf(curMet)<0){ curMet=MESES[MESES.length-1]; }
    var glob=curMet==='Global';
    var ch='<div class="tk-filter"><label>Mes</label><select onchange="pickMet(this.value)"><option value="Global"'+(glob?' selected':'')+'>Global (todos)</option>';
    MESES.slice().reverse().forEach(function(m,i){ch+='<option value="'+esc(m)+'"'+(m===curMet?' selected':'')+'>'+esc(m)+' 2026'+(i===0?' · este mes':'')+'</option>';});
    ch+='</select></div>';
    var mm=document.getElementById('metMonths'); mm.innerHTML=ch; if(window.csEnhance)csEnhance(mm);
    var i=MESES.indexOf(curMet);var p=(!glob&&i>0)?MET[MESES[i-1]]:null;var d=curData();
    var nd=glob?{neutral:true,s:'en '+MESES.length+' meses'}:null;
    document.getElementById('metTotal').innerHTML=kpiInner(d.total,'Oportunidades',glob?nd:delta(d.total,p?p.total:null),IC.opp,d.total,'int');
    var dc=document.getElementById('metConv'); if(dc) dc.innerHTML='<h4 class="mc-t">Tus contactos por canal</h4><p class="mc-d">'+(glob?('De dónde llegan tus '+d.total+' oportunidades'):('De dónde llegan las '+d.total+' de '+esc(curMet)))+'</p>'+donutBlock(d.ll,d.wa,d.fo,d.total);
    initDonut(mode);
    document.getElementById('ccOpp').value=d.total;
    var mlm=glob?ACTUAL:curMet;
    document.getElementById('metLinks').innerHTML=
      '<button class="xlink" onclick="go(\'tareas\')"><svg viewBox="0 0 24 24"><path d="M9 6h11M9 12h11M9 18h11"/><path d="M4.5 6l1 1 2-2M4.5 12l1 1 2-2M4.5 18l1 1 2-2"/></svg>Lo que hicimos'+(glob?'':' en '+curMet)+'</button>'+
      '<button class="xlink" onclick="go(\'informes\')"><svg viewBox="0 0 24 24"><path d="M14 3v5h5"/><path d="M7 3h7l5 5v11a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/></svg>'+(glob?'Ver informes':'Informe de '+curMet)+'</button>';
    document.getElementById('metGoogle').innerHTML=metCard(fmt(d.vi),'Visitas',glob?nd:delta(d.vi,p?p.vi:null),IC.vi,d.vi,'fmt')+metCard(fmt(d.ap),'Apariciones',glob?nd:delta(d.ap,p?p.ap:null),IC.ap,d.ap,'fmt')+metCard((''+d.ctr).replace('.',',')+'%','CTR',glob?nd:delta(d.ctr,p?p.ctr:null,true),IC.ctr,d.ctr,'pct');
    mtxKpis(mode);
    drawEvo(mode);
    renderGrowth();
    drawVisBars();
    drawRank();
    renderTraffic();
    renderGeo();
    renderCalc();
    /* Solo en la apertura (no al cambiar de mes/pestaña, mode==='data') y con la
       vista ya visible: lanza la entrada en cascada de las tarjetas. */
    if(mode!=='data' && document.getElementById('view-metricas').classList.contains('active')) mtxCascade();
  }
  function pickMet(m){curMet=m;renderMetrics('data');}

  /* Gráfica de evolución con pestañas: contactos / visitas / apariciones. */
  var curEvo='total';
  var EVO_DEF={
    total:{t:'Oportunidades de contacto',s:'Llamadas, WhatsApp y formularios · el mes elegido se resalta',tab:'Contactos'},
    vi:{t:'Visitas desde Google',s:'Clics a tu web desde Google · el mes elegido se resalta',tab:'Visitas'},
    ap:{t:'Apariciones en Google',s:'Veces que has salido en las búsquedas · el mes elegido se resalta',tab:'Apariciones'}
  };
  function pickEvo(k){curEvo=k;drawEvo('data');}

  /* ----- De dónde viene el tráfico (canales de GA4, campo src de met_json) ----- */
  var SRC_LABEL={'Organic Search':'Búsqueda en Google','Direct':'Directo','Paid Search':'Anuncios (Google Ads)','Organic Social':'Redes sociales','Paid Social':'Anuncios en redes','Referral':'Otras webs','Email':'Email','Display':'Display','Organic Video':'Vídeo','Paid Video':'Anuncios de vídeo','Unassigned':'Sin clasificar','Otros':'Otros','Organic Shopping':'Shopping','Paid Shopping':'Shopping de pago','Cross-network':'Varias redes'};
  var SRC_COLOR=['#1f232a','#3b82f6','#12a150','#f59e0b','#8b5cf6','#0ea5e9','#ec4899','#14b8a6','#f97316','#94a3b8'];
  function srcFor(){
    if(curMet==='Global'){ var g={}; MESES.forEach(function(m){ var s=(MET[m]&&MET[m].src)||{}; for(var k in s){ if(s.hasOwnProperty(k)) g[k]=(g[k]||0)+(s[k]||0); } }); return g; }
    return (MET[curMet]&&MET[curMet].src)||{};
  }
  function renderTraffic(){
    var card=document.getElementById('trafficCard'), box=document.getElementById('trafficBars'); if(!card) return;
    var src=srcFor();
    var keys=Object.keys(src).filter(function(k){return src[k]>0;});
    if(!keys.length){ card.hidden=true; return; }
    card.hidden=false;
    var when=document.getElementById('trafficWhen'); if(when) when.textContent=(curMet==='Global'?' en total':' en '+curMet);
    keys.sort(function(a,b){return src[b]-src[a];});
    var total=0; keys.forEach(function(k){total+=src[k];});
    var h='';
    keys.forEach(function(k,i){
      var v=src[k], pct=total?Math.round(v/total*100):0, lbl=SRC_LABEL[k]||k, col=SRC_COLOR[i%SRC_COLOR.length];
      h+='<div class="tr-row" title="'+esc(lbl)+': '+fmt(v)+' visitas ('+pct+'%)"><div class="tr-top"><span class="tr-l">'+esc(lbl)+'</span><span class="tr-v">'+fmt(v)+'<em>'+pct+'%</em></span></div><div class="tr-bar"><i style="width:'+Math.max(2,pct)+'%;background:'+col+'"></i></div></div>';
    });
    box.innerHTML=h;
  }

  /* ----- Tarjetas de crecimiento: clicks (visitas) e impresiones (apariciones) ----- */
  function growBadge(cur,prev){
    if(prev==null) return {cls:'flat',txt:'—'};
    if(prev===0) return cur>0?{cls:'up',txt:'▲ nuevo'}:{cls:'flat',txt:'—'};
    var pp=Math.round((cur-prev)/prev*100);
    if(pp>0) return {cls:'up',txt:'▲ +'+pp+'%'};
    if(pp<0) return {cls:'down',txt:'▼ '+Math.abs(pp)+'%'};
    return {cls:'flat',txt:'='};
  }
  function growCard(lbl,vals,cur,prev,col){
    var b=growBadge(cur,prev);
    return '<div class="grow"><div class="gh"><span class="gl">'+lbl+'</span><span class="gd '+b.cls+'">'+b.txt+'</span></div><div class="gn">'+fmt(cur)+'</div><div class="gsp">'+sparkline(vals,320,46,col)+'</div></div>';
  }
  function renderGrowth(){
    var row=document.getElementById('growRow'); if(!row) return;
    var viVals=MESES.map(function(m){return MET[m].vi||0;}), apVals=MESES.map(function(m){return MET[m].ap||0;});
    var glob=(curMet==='Global'), i=MESES.indexOf(curMet), d=curData(), p=(!glob&&i>0)?MET[MESES[i-1]]:null;
    var curVi=glob?viVals.reduce(function(a,b){return a+b;},0):d.vi;
    var curAp=glob?apVals.reduce(function(a,b){return a+b;},0):d.ap;
    /* Si el cliente no tiene datos de Google (solo contactos), no dejamos dos
       tarjetas vacías: se oculta la fila (y al ser mc-full no deja hueco). */
    var totVi=viVals.reduce(function(a,b){return a+b;},0), totAp=apVals.reduce(function(a,b){return a+b;},0);
    if(totVi===0 && totAp===0){ row.style.display='none'; row.innerHTML=''; return; }
    row.style.display='';
    row.innerHTML=growCard('Crecimiento de clics en Google',viVals,curVi,glob?null:(p?p.vi:null),accent())
                 +growCard('Crecimiento de apariciones',apVals,curAp,glob?null:(p?p.ap:null),'#8b5cf6');
    /* Sparklines: se trazan (draw-on) y el punto final queda con un halo que pulsa. */
    var gsps=row.querySelectorAll('.gsp');
    row.querySelectorAll('.sp-line').forEach(function(pth){ mtxDrawOn(pth,760,80); });
    row.querySelectorAll('.sp-halo').forEach(function(h){ if(mtxRM()) return; h.classList.remove('pulse'); void h.getBoundingClientRect(); h.classList.add('pulse'); h.addEventListener('animationend',function e(){ h.classList.remove('pulse'); h.removeEventListener('animationend',e); }); });
    if(gsps[0]) mtxSparkHover(gsps[0],viVals,accent());
    if(gsps[1]) mtxSparkHover(gsps[1],apVals,'#8b5cf6');
  }

  /* ----- Mapa del mundo: de dónde te visitan (campo geo de met_json) ----- */
  var CT_ES={ES:'España',US:'Estados Unidos',GB:'Reino Unido',FR:'Francia',DE:'Alemania',IT:'Italia',PT:'Portugal',MX:'México',AR:'Argentina',CO:'Colombia',CL:'Chile',PE:'Perú',BR:'Brasil',EC:'Ecuador',VE:'Venezuela',UY:'Uruguay',NL:'Países Bajos',BE:'Bélgica',CH:'Suiza',AT:'Austria',IE:'Irlanda',SE:'Suecia',NO:'Noruega',DK:'Dinamarca',FI:'Finlandia',PL:'Polonia',RO:'Rumanía',MA:'Marruecos',RU:'Rusia',CN:'China',JP:'Japón',IN:'India',CA:'Canadá',AU:'Australia',TR:'Turquía',GR:'Grecia',DO:'Rep. Dominicana',GT:'Guatemala',CR:'Costa Rica',PA:'Panamá',PY:'Paraguay',BO:'Bolivia',SV:'El Salvador',HN:'Honduras',NI:'Nicaragua',AD:'Andorra',GI:'Gibraltar'};
  function flagEmoji(code){ if(!code||code.length!==2) return '🌐'; return code.toUpperCase().replace(/[A-Z]/g,function(c){return String.fromCodePoint(127397+c.charCodeAt(0));}); }
  function geoFor(){
    if(curMet==='Global'){ var g={}; MESES.forEach(function(m){ var s=(MET[m]&&MET[m].geo)||{}; for(var k in s){ if(s.hasOwnProperty(k)) g[k]=(g[k]||0)+(s[k]||0); } }); return g; }
    return (MET[curMet]&&MET[curMet].geo)||{};
  }
  var __jvmap=null;
  function renderGeo(){
    var card=document.getElementById('geoCard'); if(!card) return;
    var geo=geoFor(), keys=Object.keys(geo).filter(function(k){return geo[k]>0;});
    if(!keys.length){ card.hidden=true; return; }
    card.hidden=false;
    var when=document.getElementById('geoWhen'); if(when) when.textContent=(curMet==='Global'?' en total':' en '+curMet);
    keys.sort(function(a,b){return geo[b]-geo[a];});
    var total=0; keys.forEach(function(k){total+=geo[k];});
    var lh=''; keys.slice(0,8).forEach(function(k){ var v=geo[k], pct=total?Math.round(v/total*100):0; lh+='<div class="geo-row"><span class="fl">'+flagEmoji(k)+'</span><span class="gn">'+esc(CT_ES[k]||k)+'</span><span class="gv">'+fmt(v)+'<span class="gp">'+pct+'%</span></span></div>'; });
    document.getElementById('geoList').innerHTML=lh;
    var mapEl=document.getElementById('geoMap');
    if(typeof jsVectorMap==='undefined'){ mapEl.style.display='none'; return; }
    mapEl.style.display='';
    /* Si la vista de Métricas aún está oculta, el contenedor mide 0 de ancho/alto.
       jsVectorMap calcula su escala dividiendo por ese tamaño y genera transforms
       con scale(NaN). No lo dibujamos ahora: se redibuja solo cuando la vista se
       muestra (go('metricas')→renderMetrics→renderGeo), con el contenedor ya
       maquetado. Así la consola queda limpia al abrir Métricas. */
    /* Exigimos un tamaño MÍNIMO real (no solo >0): jsVectorMap calcula su escala a
       partir del tamaño del contenedor y, con un contenedor diminuto o a medio
       maquetar, produce transforms con scale(NaN). Si aún no mide lo suficiente, no
       lo dibujamos: se redibuja al mostrarse la vista con el contenedor ya maquetado. */
    if(mapEl.clientWidth<120 || mapEl.clientHeight<80){ if(__jvmap){ try{__jvmap.destroy();}catch(e){} __jvmap=null; } mapEl.innerHTML=''; return; }
    mapEl.innerHTML=''; if(__jvmap){ try{__jvmap.destroy();}catch(e){} __jvmap=null; }
    var gSoft=cssVar('--soft','#eef1f5'), gAc=accent(), gCard=cssVar('--card','#ffffff'), gHov=cssVar('--muted','#c9ccd2');
    try{
      __jvmap=new jsVectorMap({selector:'#geoMap', map:'world', zoomButtons:false, backgroundColor:'transparent',
        regionStyle:{initial:{fill:gSoft, stroke:gCard, strokeWidth:.5}, hover:{fill:gHov}},
        series:{regions:[{attribute:'fill', values:geo, scale:[gSoft,gAc], normalizeFunction:'polynomial'}]},
        onRegionTooltipShow:function(ev,tooltip,code){ var v=geo[code]||0; tooltip.text((CT_ES[code]||tooltip.text())+': '+fmt(v)+(v===1?' visita':' visitas'), true); }
      });
      requestAnimationFrame(function(){ try{ if(__jvmap&&__jvmap.updateSize&&mapEl.clientWidth>120&&mapEl.clientHeight>80) __jvmap.updateSize(); }catch(e){} });
    }catch(e){ mapEl.style.display='none'; }
  }
  /* Genera el path de una curva suave (Catmull-Rom → Bézier) por una lista de puntos. */
  function smoothPath(xs,ys){
    var n=xs.length; if(n<2) return n?('M'+xs[0]+' '+ys[0]):'';
    var d='M'+xs[0].toFixed(1)+' '+ys[0].toFixed(1);
    for(var i=0;i<n-1;i++){
      var x0=(i-1<0?xs[i]:xs[i-1]), y0=(i-1<0?ys[i]:ys[i-1]), x1=xs[i],y1=ys[i], x2=xs[i+1],y2=ys[i+1], x3=(i+2>=n?x2:xs[i+2]), y3=(i+2>=n?y2:ys[i+2]);
      var c1x=x1+(x2-x0)/6,c1y=y1+(y2-y0)/6,c2x=x2-(x3-x1)/6,c2y=y2-(y3-y1)/6;
      d+=' C'+c1x.toFixed(1)+' '+c1y.toFixed(1)+' '+c2x.toFixed(1)+' '+c2y.toFixed(1)+' '+x2.toFixed(1)+' '+y2.toFixed(1);
    }
    return d;
  }
  /* Mini-gráfica de área (sparkline) para las tarjetas de crecimiento. */
  function sparkline(vals,w,h,col){
    col=col||accent(); var n=vals.length; if(!n) return '';
    var mx=Math.max.apply(null,vals.concat([1])), pad=3;
    var xs=vals.map(function(v,i){ return n===1? w/2 : pad+(w-2*pad)*i/(n-1); });
    var ys=vals.map(function(v){ return pad+(h-2*pad)*(1-v/mx); });
    var line=smoothPath(xs,ys);
    var area=line+' L'+xs[n-1].toFixed(1)+' '+(h-1)+' L'+xs[0].toFixed(1)+' '+(h-1)+' Z';
    var gid='sp'+Math.random().toString(36).slice(2,7);
    return '<svg class="sp-svg" width="'+w+'" height="'+h+'" viewBox="0 0 '+w+' '+h+'" style="color:'+col+'">'
      +'<defs><linearGradient id="'+gid+'" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="'+col+'" stop-opacity="0.22"/><stop offset="1" stop-color="'+col+'" stop-opacity="0"/></linearGradient></defs>'
      +'<path class="sp-area" d="'+area+'" fill="url(#'+gid+')"/>'
      +'<path class="sp-line" d="'+line+'" fill="none" stroke="'+col+'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
      +'<line class="sp-cross" x1="0" y1="0" x2="0" y2="'+h+'" style="opacity:0"/>'
      +'<circle class="sp-hi" r="3.2" fill="'+col+'" style="opacity:0"/>'
      +'<circle class="sp-halo" cx="'+xs[n-1].toFixed(1)+'" cy="'+ys[n-1].toFixed(1)+'" r="3" fill="none" stroke="'+col+'" stroke-width="1.6"/>'
      +'<circle class="sp-end" cx="'+xs[n-1].toFixed(1)+'" cy="'+ys[n-1].toFixed(1)+'" r="2.6" fill="'+col+'"/>'
      +'<rect class="sp-hot" x="0" y="0" width="'+w+'" height="'+h+'" fill="transparent"/></svg>';
  }
  var __evoPrev=null; /* {key, vals} del último dibujo para hacer tween al cambiar dato */
  function drawEvo(mode){
    var host=document.getElementById('bars'); if(!host) return;
    var n=MESES.length; if(!n){ host.innerHTML=''; return; }
    var glob=(curMet==='Global');
    var def=EVO_DEF[curEvo]||EVO_DEF.total;
    var ti=document.getElementById('evoTitle'); if(ti) ti.textContent=def.t;
    var su=document.getElementById('evoSub'); if(su) su.textContent=def.s;
    var tabsEl=document.getElementById('evoTabs');
    if(tabsEl){ var th=''; ['total','vi','ap'].forEach(function(k){ th+='<button class="tab'+(k===curEvo?' active':'')+'" onclick="pickEvo(\''+k+'\')">'+EVO_DEF[k].tab+'</button>'; }); tabsEl.innerHTML=th; }
    var vals=MESES.map(function(m){ return MET[m][curEvo]||0; });
    var uni={total:'contactos',vi:'visitas',ap:'apariciones'}[curEvo]||'';
    var prev=__evoPrev, canTween=(mode==='data' && prev && prev.vals.length===vals.length && !mtxRM());
    host.innerHTML='<div class="evo-plot" id="evoPlot"></div><div class="lc-months">'+MESES.map(function(m){return '<span>'+m.slice(0,3)+'</span>';}).join('')+'</div>';
    var plot=document.getElementById('evoPlot');
    var padT=16,padB=6,padX=9, W=0,H=0,xs=[],ys=[], els={};
    function calc(vv){ var mx=Math.max.apply(null,vv.concat([1])); xs=vv.map(function(v,i){ return vv.length===1? W/2 : padX+(W-2*padX)*i/(vv.length-1); }); ys=vv.map(function(v){ return padT+(H-padT-padB)*(1-v/mx); }); }
    function pathFor(vv){ calc(vv); var line=smoothPath(xs,ys); var area=line+' L'+xs[xs.length-1].toFixed(1)+' '+(H-padB)+' L'+xs[0].toFixed(1)+' '+(H-padB)+' Z'; return {line:line,area:area}; }
    var selI=-1;
    function build(){
      W=plot.clientWidth; H=plot.clientHeight; if(!W||!H) return false;
      var AC=accent(), DF=cssVar('--card','#fff');
      var p0=pathFor(canTween?prev.vals:vals);
      calc(vals);
      selI=glob?-1:MESES.indexOf(curMet);
      /* defs: gradiente del relleno, desenfoque para el glow y clip que barre el área */
      var svg='<svg width="'+W+'" height="'+H+'" viewBox="0 0 '+W+' '+H+'"><defs>'
        +'<linearGradient id="evoGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="'+AC+'" stop-opacity="0.18"/><stop offset="0.92" stop-color="'+AC+'" stop-opacity="0"/></linearGradient>'
        +'<filter id="evoBlur" x="-20%" y="-60%" width="140%" height="220%"><feGaussianBlur stdDeviation="3.4"/></filter>'
        +'<clipPath id="evoClip"><rect class="evo-clip-rect" x="0" y="0" width="'+W+'" height="'+H+'"/></clipPath>'
        +'</defs>'
        +'<path class="evo-area" clip-path="url(#evoClip)" d="'+p0.area+'" fill="url(#evoGrad)"/>'
        +'<path class="evo-glow" d="'+p0.line+'" fill="none" stroke="'+AC+'" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" opacity="0.32"/>'
        +'<path class="evo-line" d="'+p0.line+'" fill="none" stroke="'+AC+'" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>'
        +'<line class="evo-cross" x1="0" y1="'+padT+'" x2="0" y2="'+(H-padB)+'" style="opacity:0"/>';
      if(selI>=0){ svg+='<circle class="evo-halo" cx="'+xs[selI].toFixed(1)+'" cy="'+ys[selI].toFixed(1)+'" r="6" fill="none" stroke="'+AC+'" stroke-width="2"/>'; }
      MESES.forEach(function(m,i){ var on=(glob||m===curMet); svg+='<circle class="evo-dot'+(on?' evo-dot-on':'')+'" data-i="'+i+'" cx="'+xs[i].toFixed(1)+'" cy="'+ys[i].toFixed(1)+'" r="'+(on?5:3.5)+'" fill="'+DF+'" stroke="'+AC+'" stroke-width="'+(on?2.6:2)+'"/>'; });
      svg+='<circle class="evo-hi" r="5.5" fill="'+AC+'" stroke="'+DF+'" stroke-width="2" style="opacity:0"/>';
      svg+='<rect class="evo-overlay" x="0" y="0" width="'+W+'" height="'+H+'" fill="transparent"/></svg>';
      plot.innerHTML=svg;
      els.svg=plot.firstChild; els.area=plot.querySelector('.evo-area'); els.line=plot.querySelector('.evo-line');
      els.glow=plot.querySelector('.evo-glow'); els.clip=plot.querySelector('.evo-clip-rect'); els.halo=plot.querySelector('.evo-halo');
      els.cross=plot.querySelector('.evo-cross'); els.hi=plot.querySelector('.evo-hi'); els.overlay=plot.querySelector('.evo-overlay');
      els.dots=plot.querySelectorAll('.evo-dot');
      bindHover();
      /* El punto del mes elegido hace POP y su halo pulsa (tanto al abrir como al
         cambiar de dato). En Global no hay mes elegido: sin pop ni halo. */
      if(!mtxRM() && selI>=0 && els.dots[selI]){ var dd=els.dots[selI]; dd.classList.remove('pop'); void dd.getBoundingClientRect(); dd.classList.add('pop'); if(els.halo){ els.halo.classList.remove('pulse'); void els.halo.getBoundingClientRect(); els.halo.classList.add('pulse'); } }
      if(canTween){ tween(prev.vals.slice(), vals.slice()); }
      else { entrance(); }
      __evoPrev={key:curEvo, vals:vals.slice()};
      return true;
    }
    function entrance(){
      var pf=pathFor(vals); els.line.setAttribute('d',pf.line); if(els.glow) els.glow.setAttribute('d',pf.line); els.area.setAttribute('d',pf.area);
      if(mtxRM()) return;
      /* Relleno: se revela con un BARRIDO izquierda→derecha (clip rect scaleX 0→1).
         Reposo del rect = scaleX(1), así que si no anima el área queda entera. */
      if(els.clip){ els.clip.classList.remove('sweep'); void els.clip.getBoundingClientRect(); els.clip.classList.add('sweep'); }
      /* Línea + glow: se dibujan (draw-on) con --ease-expo. */
      mtxDrawOn(els.line,780,60);
      if(els.glow) mtxDrawOn(els.glow,780,60);
    }
    function tween(from,to){
      var dur=560,t0=0;
      function step(ts){ if(!t0)t0=ts; var p=Math.min(1,(ts-t0)/dur); var e=1-Math.pow(1-p,3); var vv=to.map(function(v,i){ return from[i]+(v-from[i])*e; }); var pf=pathFor(vv); els.line.setAttribute('d',pf.line); if(els.glow) els.glow.setAttribute('d',pf.line); els.area.setAttribute('d',pf.area); calc(vv); els.dots.forEach(function(dt){ var i=+dt.getAttribute('data-i'); dt.setAttribute('cx',xs[i].toFixed(1)); dt.setAttribute('cy',ys[i].toFixed(1)); }); if(els.halo && selI>=0){ els.halo.setAttribute('cx',xs[selI].toFixed(1)); els.halo.setAttribute('cy',ys[selI].toFixed(1)); } if(p<1) requestAnimationFrame(step); else { calc(to); var pf2=pathFor(to); els.line.setAttribute('d',pf2.line); if(els.glow) els.glow.setAttribute('d',pf2.line); els.area.setAttribute('d',pf2.area); } }
      requestAnimationFrame(step);
    }
    function bindHover(){
      /* Capa transparente sobre TODO el área: en cualquier X calcula el mes más
         cercano y DESLIZA el crosshair + el punto resaltado hacia él con inercia
         (lerp ~0.22/frame). El rAF solo mueve mientras el ratón está encima; el
         reposo (mouseleave) es "oculto", nunca depende del rAF. Con reduced-motion,
         posición directa (sin inercia). */
      var hx=0,hy=0,tx=0,ty=0,hraf=0,active=false;
      function put(){ if(els.cross){ els.cross.setAttribute('x1',hx.toFixed(1)); els.cross.setAttribute('x2',hx.toFixed(1)); } if(els.hi){ els.hi.setAttribute('cx',hx.toFixed(1)); els.hi.setAttribute('cy',hy.toFixed(1)); } }
      function tick(){ hraf=0; if(!active) return; var dx=tx-hx, dy=ty-hy; if(Math.abs(dx)<0.3 && Math.abs(dy)<0.3){ hx=tx; hy=ty; } else { hx+=dx*0.22; hy+=dy*0.22; hraf=requestAnimationFrame(tick); } put(); }
      function move(ev){ if(!els.svg) return; var r=els.svg.getBoundingClientRect(); if(!r.width) return; var rx=(ev.clientX-r.left)/r.width*W; calc(vals); var bi=0,bd=1e9; for(var i=0;i<xs.length;i++){ var dd=Math.abs(xs[i]-rx); if(dd<bd){bd=dd;bi=i;} }
        tx=xs[bi]; ty=ys[bi];
        if(!active || mtxRM()){ active=true; hx=tx; hy=ty; put(); }
        else if(!hraf){ hraf=requestAnimationFrame(tick); }
        els.cross.style.opacity='1'; els.hi.style.opacity='1';
        var vv=(curEvo==='total')?vals[bi]:fmt(vals[bi]);
        mtxTipShow('<div class="tt-k"><span class="tt-dot" style="background:'+accent()+'"></span><b>'+vv+'</b> '+uni+'</div><small>'+esc(MESES[bi])+' 2026</small>'); mtxTipFollow(ev); }
      els.overlay.addEventListener('mousemove',move);
      els.overlay.addEventListener('mouseleave',function(){ active=false; if(hraf){ cancelAnimationFrame(hraf); hraf=0; } els.cross.style.opacity='0'; els.hi.style.opacity='0'; mtxTipHide(); });
    }
    /* Reposiciona (sin animación) al cambiar el tamaño de la ventana. */
    window.__drawMetLine=function(){ if(!plot||!els.svg) return; if(!plot.clientWidth||!plot.clientHeight) return; W=plot.clientWidth; H=plot.clientHeight; var pf=pathFor(vals);
      els.line.style.animation=''; els.line.style.strokeDasharray=''; els.line.style.strokeDashoffset=''; els.line.setAttribute('d',pf.line);
      if(els.glow){ els.glow.style.animation=''; els.glow.style.strokeDasharray=''; els.glow.style.strokeDashoffset=''; els.glow.setAttribute('d',pf.line); }
      els.area.setAttribute('d',pf.area);
      if(els.clip){ els.clip.classList.remove('sweep'); els.clip.setAttribute('width',W); els.clip.setAttribute('height',H); }
      els.svg.setAttribute('width',W); els.svg.setAttribute('height',H); els.svg.setAttribute('viewBox','0 0 '+W+' '+H); calc(vals);
      els.dots.forEach(function(dt){ var i=+dt.getAttribute('data-i'); dt.setAttribute('cx',xs[i].toFixed(1)); dt.setAttribute('cy',ys[i].toFixed(1)); });
      if(els.halo && selI>=0){ els.halo.setAttribute('cx',xs[selI].toFixed(1)); els.halo.setAttribute('cy',ys[selI].toFixed(1)); }
      els.overlay.setAttribute('width',W); els.overlay.setAttribute('height',H); els.cross.setAttribute('y2',(H-padB)); };
    /* Reintenta hasta que el contenedor tenga tamaño real (la vista puede estar
       aún sin maquetar cuando se llama), para que la gráfica SIEMPRE se dibuje. */
    var __t=0; (function tryDraw(){ if(!plot) return; if(plot.clientWidth && plot.clientHeight){ build(); } else if(__t++ < 40){ requestAnimationFrame(tryDraw); } })();
  }

  var SERV_ORDER=['Diseño web','SEO','SEM','CRO','Tiendas online','Meta'];
  var SERV={
    'Diseño web':{short:'Tu web, rápida y que convierte.',g1:'#3a3f47',g2:'#1f232a',icon:'<path d="M2 5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5zm7 14h6l1 2H8z"/>',intro:'Creamos tu web desde cero o la renovamos: rápida, clara y pensada para que quien entre acabe contactando.',steps:['Descubrimiento: entendemos tu negocio y tus objetivos','Diseño en Figma: ves la web antes de programar nada','Desarrollo a medida y optimización de velocidad','Revisión contigo y puesta en marcha','Mejoras continuas según cómo se comporta la gente'],faqs:[{q:'¿Cuánto tarda una web?',a:'Normalmente entre 3 y 6 semanas según el tamaño. Te damos fechas desde el principio.'},{q:'¿Podré editarla yo?',a:'Sí. Te la dejamos fácil de gestionar y te enseñamos a hacer cambios básicos.'},{q:'¿Está preparada para Google?',a:'Sí, la construimos con buenas bases de SEO y velocidad desde el primer día.'}]},
    'SEO':{short:'Aparecer en Google sin pagar por clic.',g1:'#45d27e',g2:'#1e9e4a',icon:'<path d="M10 3a7 7 0 1 1-4.95 11.95l-2.34 2.34a1.5 1.5 0 0 1-2.12-2.12l2.34-2.34A7 7 0 0 1 10 3z"/>',intro:'Trabajamos para que tu negocio salga en Google cuando alguien busca lo que ofreces, sin pagar por cada clic.',steps:['Auditoría de tu web y de tu competencia','Estudio de palabras clave: qué busca tu cliente','Arquitectura de contenidos y páginas de servicio','Optimización técnica para que Google te entienda','Seguimiento mensual y ajustes'],faqs:[{q:'¿Cuándo se ven resultados?',a:'El SEO es a medio plazo: los primeros avances suelen notarse a partir de los 3-4 meses.'},{q:'¿Garantizáis salir el primero?',a:'Nadie puede garantizar el puesto 1, pero sí trabajar con método para subir de forma sostenida.'},{q:'¿Qué hacéis cada mes?',a:'Contenidos, mejoras técnicas y optimización; lo verás todo en tu informe mensual.'}]},
    'SEM':{short:'Anuncios en Google desde el primer día.',g1:'#f2a93c',g2:'#e0892a',icon:'<path d="M3 11a1 1 0 0 1 1-1h3l6-4v12l-6-4H4a1 1 0 0 1-1-1v-2zm14-3a4.5 4.5 0 0 1 0 8z"/>',intro:'Ponemos anuncios en Google para que aparezcas arriba justo cuando te buscan, desde el primer día.',steps:['Estudio de palabras y competencia','Estructura de campañas y grupos de anuncios','Redacción de anuncios y extensiones','Optimización de pujas y presupuesto','Informes y mejora continua'],faqs:[{q:'¿Cuánto hay que invertir?',a:'Lo decidimos contigo según tu objetivo; se puede empezar con poco e ir ajustando.'},{q:'¿Desde cuándo llegan clientes?',a:'Al ser anuncios, puedes empezar a recibir contactos casi de inmediato.'},{q:'¿Controláis el gasto?',a:'Sí, gestionamos el presupuesto a diario para sacarle el máximo a cada euro.'}]},
    'CRO':{short:'Más clientes con las mismas visitas.',g1:'#b985ff',g2:'#9a4dff',icon:'<path d="M13 2L4 14h6l-1 8 9-12h-6z"/>',intro:'Mejoramos tu web para que más visitas acaben contactando o comprando, sin necesidad de traer más tráfico.',steps:['Analizamos cómo se comporta la gente en tu web','Detectamos dónde se pierden los clientes','Planteamos hipótesis de mejora','Tests A/B para validar con datos','Implementamos lo que funciona'],faqs:[{q:'¿Qué es CRO en simple?',a:'Optimizar para convertir: pequeños cambios que hacen que más gente dé el paso.'},{q:'¿Cómo sabéis qué cambiar?',a:'Miramos datos y hacemos pruebas para decidir con hechos, no a ojo.'},{q:'¿Sirve para mi negocio?',a:'Si recibes visitas pero pocos contactos o ventas, es justo lo que necesitas.'}]},
    'Tiendas online':{short:'Vende más con tu ecommerce.',g1:'#2fd0c5',g2:'#0aa6a6',icon:'<path d="M5 9l1-4h12l1 4v1a2 2 0 0 1-4 0 2 2 0 0 1-4 0 2 2 0 0 1-4 0V9zm1 3h12v8H6z"/>',intro:'Montamos y hacemos crecer tu tienda online para que vendas más y mejor.',steps:['Configuración de la tienda y el catálogo','Fichas de producto optimizadas para vender y posicionar','Pasarela de pago y métodos de envío','SEO de productos y categorías','Campañas para atraer y recuperar carritos'],faqs:[{q:'¿Con qué plataforma trabajáis?',a:'La que mejor encaje contigo (WooCommerce, Shopify…); te asesoramos.'},{q:'¿Incluye las fichas de producto?',a:'Sí, las preparamos para vender y para posicionar en Google.'},{q:'¿Y los pagos y envíos?',a:'Lo dejamos todo configurado: pasarelas de pago y métodos de envío.'}]},
    'Meta':{short:'Clientes nuevos en Instagram y Facebook.',g1:'#ff7fb0',g2:'#e23f86',icon:'<path d="M2 10h3v11H2zM7 21V10l4-7a2 2 0 0 1 2 2v4h6a2 2 0 0 1 2 2.3l-1.4 7A2 2 0 0 1 17.6 21H7z"/>',intro:'Publicidad en Instagram y Facebook para que te conozca gente nueva que encaja con tu cliente ideal.',steps:['Definición del público objetivo','Creatividades que paran el scroll','Campañas de captación y retargeting','Optimización diaria de resultados','Escalado de lo que funciona'],faqs:[{q:'¿Para qué sirve?',a:'Para darte a conocer y captar clientes nuevos, y recordar a quien ya te visitó.'},{q:'¿Necesito hacer vídeos?',a:'Ayuda, pero podemos trabajar con lo que tengas; también te guiamos para crearlos.'},{q:'¿Cuándo se ven resultados?',a:'Los primeros datos llegan rápido; afinamos las campañas en las primeras semanas.'}]}
  };
  var curServ='Diseño web';
  function renderHub(){var h='';SERV_ORDER.forEach(function(k){var s=SERV[k];var has=hasService(k);var lock=has?'':' <span class="lockbadge">🔒</span>';h+='<button class="tile'+(has?'':' locked')+'" onclick="openService(\''+k+'\')"><div class="ic" style="background:var(--yellow-soft);color:var(--yellow-d)"><svg viewBox="0 0 24 24" style="fill:currentColor;stroke:none">'+s.icon+'</svg></div><div class="tx"><b>'+k+lock+'</b><p>'+s.short+'</p></div><svg class="arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></button>'});document.getElementById('servHub').innerHTML=h;}
  function openService(k){curServ=k;renderService();go('servicio');var c=document.querySelector('.pnav a[data-view="como"]');if(c)c.classList.add('active');}
  /* ¿Está disponible esa sección para este cliente? (respeta applySections y conv-only) */
  function secAvail(v){ if(v==='servicio')return secAvail('como'); var a=document.querySelector('.pnav a[data-view="'+v+'"]'); if(!a)return v==='resumen'; return getComputedStyle(a).display!=='none'; }
  /* Buscador del portal: al pulsar Enter salta a la sección o servicio que encaje. */
  function portalSearch(q){
    q=(q||'').trim().toLowerCase(); if(!q) return;
    for(var i=0;i<SERV_ORDER.length;i++){ if(SERV_ORDER[i].toLowerCase().indexOf(q)>=0 && hasService(SERV_ORDER[i]) && secAvail('como')){ openService(SERV_ORDER[i]); if(window.innerWidth<=720)closeMenu(); return; } }
    var MAP=[['metricas','metric,numero,número,conversion,conversión,conversiones,llamada,whatsapp,formulario,google,visita,visibilidad,ctr'],
             ['tareas','progreso,trabajo,tarea,tareas,hecho,avance'],
             ['informes','informe,informes,report,pdf'],
             ['como','metodo,método,como,cómo,servicio,servicios,video,vídeo,seo,web,sem,cro,meta,tienda'],
             ['accesos','acceso,accesos,contraseña,credencial,contacto,ayuda,email,correo'],
             ['plan','plan,precio,contrato,mensual,incluye'],
             ['resumen','inicio,resumen,home,portada']];
    for(var j=0;j<MAP.length;j++){ var ks=MAP[j][1].split(','); for(var k=0;k<ks.length;k++){ var t=ks[k].trim(); if(t && q.indexOf(t)>=0 && secAvail(MAP[j][0])){ go(MAP[j][0]); if(window.innerWidth<=720)closeMenu(); return; } } }
    for(var n=0;n<INFORMES.length;n++){ var it=INFORMES[n]||{}; if((((it.mes||'')+' '+(it.titulo||''))).toLowerCase().indexOf(q)>=0 && secAvail('informes')){ go('informes'); if(window.innerWidth<=720)closeMenu(); return; } }
    toastMsg('No encontré nada con «'+q+'»');
  }
  function renderService(){
    var s=SERV[curServ];
    if(!hasService(curServ)){ renderServiceLocked(s); return; }
    var st='';s.steps.forEach(function(x){st+='<li><em></em>'+x+'</li>'});
    var fq='';s.faqs.forEach(function(f){fq+='<div class="faq" onclick="this.classList.toggle(\'open\')"><div class="faq-q"><span>'+f.q+'</span><svg class="qm" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg></div><div class="faq-a"><p>'+f.a+'</p></div></div>'});
    var vid=servVideo(curServ);
    var video='<div class="video-card" id="servVideo" onclick="playServiceVideo(\''+vid+'\')"><img src="https://img.youtube.com/vi/'+vid+'/hqdefault.jpg" alt=""><span class="vplay"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></span><span class="vlabel">▶ '+curServ+' · presentación</span></div>';
    document.getElementById('view-servicio').innerHTML=
      '<button class="xlink" onclick="go(\'como\')"><svg viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg>Volver a servicios</button>'+
      '<div class="card" style="margin-top:10px;display:flex;align-items:center;gap:16px"><div style="width:54px;height:54px;border-radius:16px;background:var(--yellow-soft);color:var(--yellow-d);display:flex;align-items:center;justify-content:center;flex:none"><svg width="27" height="27" viewBox="0 0 24 24" style="fill:currentColor;stroke:none">'+s.icon+'</svg></div><div><h3 style="font-size:20px;font-weight:750;letter-spacing:-.3px">'+curServ+'</h3><p style="color:var(--muted);font-size:14px;margin-top:3px">'+s.intro+'</p></div></div>'+
      video+
      '<h2 class="sec">Cómo lo hacemos</h2><div class="card"><ul class="steps">'+st+'</ul></div>'+
      '<h2 class="sec">Preguntas frecuentes</h2>'+fq;
  }
  function playServiceVideo(vid){var c=document.getElementById('servVideo');if(c)c.innerHTML='<iframe src="https://www.youtube.com/embed/'+vid+'?autoplay=1" title="Presentación" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe>';}
  function renderServiceLocked(s){
    document.getElementById('view-servicio').innerHTML=
      '<button class="xlink" onclick="go(\'como\')"><svg viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg>Volver a servicios</button>'+
      '<div class="card locked-card" style="margin-top:10px;padding:40px 26px"><div class="lock-ic">🔒</div>'+
      '<h3 style="font-size:22px;font-weight:750;margin-top:14px">'+curServ+'</h3>'+
      '<p style="color:var(--muted);max-width:460px;margin:8px auto 0">'+s.intro+'</p>'+
      '<div class="locked-note">Este servicio no está en tu plan actual. Si te interesa, organizamos una reunión y te contamos cómo <b>'+curServ+'</b> puede ayudarte — sin compromiso.</div>'+
      '<a class="cta-meet" href="'+MEETING_URL+'" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>Reservar una reunión</a>'+
      '</div>';
  }
  /* (eliminado sendForm: era un formulario decorado que no enviaba nada y cuyo
     elemento #comoForm ya no existe. El contacto real es WhatsApp/Email en Accesos.) */
  function playVideo(){document.getElementById('videoCard').innerHTML='<iframe src="https://www.youtube.com/embed/'+SERV_VIDEO+'?autoplay=1" title="Cómo trabajamos" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe>';}
  function applyCfg(){
    var wa=document.getElementById('ctWa'); if(wa) wa.href='https://wa.me/'+(CFG.whatsapp||'');
    var em=document.getElementById('ctEmail'); if(em) em.href='mailto:'+(CFG.email||'');
    var vimg=document.querySelector('#videoCard img'); if(vimg) vimg.src='https://img.youtube.com/vi/'+SERV_VIDEO+'/hqdefault.jpg';
    var yt=document.getElementById('ytLink'); if(yt) yt.href='https://www.youtube.com/watch?v='+SERV_VIDEO;
    applyLooker();
  }
  function applyLooker(){
    var f=document.getElementById('lookerFrame');
    var note=document.getElementById('metNote');
    var url=(DATA.looker||'').trim();
    if(url){
      /* Solo se muestra el panel de Looker si ESE cliente tiene una URL puesta. */
      if(f){ f.innerHTML='<iframe src="'+safeUrl(url)+'" style="width:100%;height:600px;border:0;border-radius:14px;display:block" allowfullscreen></iframe>';
             f.style.display=''; f.style.background='none'; f.style.padding='0'; f.style.boxShadow='none'; }
      if(note) note.style.display='none';
    } else {
      /* Sin URL: NO se enseña el recuadro "aquí va tu Looker" ni la nota que lo menciona. */
      if(f) f.style.display='none';
      if(note) note.style.display='none';
    }
  }
  function goMonth(view,m){if(view==='metricas'){curMet=m;go('metricas');}else if(view==='progreso'){curMonth=m;renderMonths();renderTasks();go('progreso');}}
  function renderCalc(){
    var opp=+(document.getElementById('ccOpp').value)||0;
    var rate=+(document.getElementById('ccRate').value)||0;
    var ticket=+(document.getElementById('ccTicket').value)||0;
    var clients=opp*rate/100;
    var rev=Math.round(clients*ticket);
    document.getElementById('ccClients').textContent=(clients%1===0?clients:clients.toFixed(1).replace('.',','));
    document.getElementById('ccRev').textContent=fmt(rev)+' €';
    document.getElementById('ccRevLbl').textContent='Podrías ingresar · '+(curMet==='Global'?'Global':curMet);
    document.getElementById('ccPhrase').innerHTML='Si cierras el <b>'+rate+'%</b> de tus <b>'+opp+'</b> oportunidades, podrías ingresar <b style="color:var(--yellow)">'+fmt(rev)+' €</b>.';
  }

  var TMESES=Object.keys(TAREAS);
  var curMonth=(TMESES.indexOf(ACTUAL)>=0)?ACTUAL:(TMESES[TMESES.length-1]||ACTUAL);
  function renderMonths(){var h='';TMESES.slice().reverse().forEach(function(m){h+='<button class="mchip'+(m===curMonth?' active':'')+'" onclick="pickMonth(\''+escJs(m)+'\')">'+esc(m)+'</button>'});document.getElementById('months').innerHTML=h;}
  function pickMonth(m){curMonth=m;renderMonths();renderTasks();}
  function tItem(t,done,i){
    return '<div class="task '+(done?'done':'pend')+'" style="animation-delay:'+(i*60)+'ms" onclick="this.classList.toggle(\'open\')">'+
      '<div class="task-head">'+
        '<div class="tbox">'+(done?'✓':'')+'</div>'+
        '<div class="tname">'+esc(t.t)+'</div>'+
        '<span class="tpill '+(done?'d':'p')+'">'+(done?'Hecho':'En curso')+'</span>'+
        '<svg class="chev" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>'+
      '</div>'+
      '<div class="task-body"><p>'+esc(t.d)+'</p></div>'+
    '</div>';
  }
  function subhead(type,label,n){return '<div class="subhead"><span class="sd '+type+'"></span>'+label+'<span class="c">'+n+'</span></div>';}
  function renderTasks(){
    var d=TAREAS[curMonth]||{completado:[],pendiente:[]};
    document.getElementById('progLinks').innerHTML=
      '<button class="xlink" onclick="goMonth(\'metricas\',\''+escJs(curMonth)+'\')"><svg viewBox="0 0 24 24"><path d="M4 19V5M4 19h16M8 16v-4M12 16V8M16 16v-6"/></svg>Métricas de '+esc(curMonth)+'</button>'+
      '<button class="xlink" onclick="go(\'informes\')"><svg viewBox="0 0 24 24"><path d="M14 3v5h5"/><path d="M7 3h7l5 5v11a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/></svg>Informe de '+esc(curMonth)+'</button>';
    var box=document.getElementById('taskList');var h='';
    if(d.pendiente.length){h+=subhead('p','En curso ahora',d.pendiente.length);d.pendiente.forEach(function(t,i){h+=tItem(t,false,i)});}
    if(d.completado.length){h+=subhead('d','Completado',d.completado.length);d.completado.forEach(function(t,i){h+=tItem(t,true,i)});}
    if(!h)h='<div class="note" style="padding:24px 6px">Sin tareas registradas en '+esc(curMonth)+'.</div>';
    box.innerHTML=h;
  }
  function renderBanner(){
    var el=document.getElementById('todoBanner'); if(!el) return;
    /* Misma fuente que la página Tareas (PORTAL.tareas), para no contradecirla. */
    var pend=((PORTAL&&PORTAL.tareas)||[]).filter(function(t){return t.estado!=='completada';});
    if(pend.length){
      var sub=pend.length>1?('Tienes '+pend.length+' tareas en marcha'):'1 tarea en marcha';
      el.innerHTML='<div class="todo-banner" onclick="go(\'tareas\')"><div class="bi"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div><div class="bt"><b>En curso: '+esc(pend[0].titulo)+'</b><span>'+esc(sub)+'</span></div><div class="ba"><span>Ver tareas</span><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></div></div>';
    }else{
      el.innerHTML='<div class="todo-banner" style="cursor:default"><div class="bi" style="background:#e7f6ec;color:var(--green)"><svg viewBox="0 0 24 24"><path d="M5 12l5 5L20 7"/></svg></div><div class="bt"><b>Todo al día</b><span>No hay tareas pendientes ahora mismo</span></div></div>';
    }
  }
  function faviconFor(u){
    if(!u) return ''; u=String(u).trim(); if(u===''||u==='#') return '';
    if(!/^https?:\/\//i.test(u)){ if(u.indexOf('.')<0) return ''; u='https://'+u; }
    try{ var host=new URL(u).hostname; if(!host) return ''; return 'https://www.google.com/s2/favicons?sz=64&domain='+encodeURIComponent(host); }catch(e){ return ''; }
  }
  function renderRecursos(){
    var h='';
    RECURSOS.forEach(function(r){
      var fav=faviconFor(r.u);
      var ic=fav?('<img src="'+fav+'" alt="" width="24" height="24" style="border-radius:6px;display:block" onerror="this.style.display=\'none\'">'):(LOGOS[r.tipo]||LOGOS.generic);
      h+='<a class="res" href="'+safeUrl(r.u)+'" target="_blank" rel="noopener"><div class="ic brand">'+ic+'</div><div class="tx"><b>'+esc(r.b)+'</b><span>'+esc(r.s)+'</span></div><svg class="go" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M9 7h8v8"/></svg></a>';
    });
    document.getElementById('resGrid').innerHTML=h;
  }

  /* ----- Bóveda: credenciales del cliente marcadas como visibles ----- */
  var ICO_COPY='<svg viewBox="0 0 24 24" stroke-width="2"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/></svg>';
  var ICO_EYE='<svg viewBox="0 0 24 24" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>';
  var ICO_EXT='<svg viewBox="0 0 24 24" stroke-width="2"><path d="M7 17 17 7M9 7h8v8"/></svg>';
  var ICO_CHECK='<svg viewBox="0 0 24 24" stroke-width="2.6"><path d="M5 12l5 5L20 7"/></svg>';
  var CRED_CAT={web:'Web',correo:'Correo',hosting:'Hosting',database:'Base de datos',api:'API / Token',cms:'CMS',domain:'Dominio',social:'Redes',other:'Acceso'};
  var CRED_ICON={
    link:'<path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"/>',
    inbox:'<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13l3.5 7v6a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-6z"/>',
    lock:'<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    gear:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1z"/>'
  };
  var CAT_IC={web:'link',correo:'inbox',hosting:'lock',database:'gear',api:'gear',cms:'gear',domain:'link',social:'link',other:'lock'};
  function credIcon(cat){ var k=CAT_IC[cat]||'lock'; return '<svg viewBox="0 0 24 24" stroke-width="1.9">'+(CRED_ICON[k]||CRED_ICON.lock)+'</svg>'; }
  function renderVault(){
    var box=document.getElementById('vaultList'); if(!box) return;
    var V=(PORTAL&&PORTAL.accesos_vault)||[];
    var hd=document.getElementById('vaultHd'), note=document.getElementById('vaultNote');
    if(!V.length){ if(hd)hd.hidden=true; if(note)note.hidden=true; box.innerHTML=''; return; }
    if(hd)hd.hidden=false; if(note)note.hidden=false;
    var h='<div class="cred-grid">';
    V.forEach(function(c,i){
      h+='<div class="cred"><div class="cred-h"><div class="ic">'+credIcon(c.cat)+'</div><div class="tt"><b>'+esc(c.t)+'</b><span class="cat">'+esc(CRED_CAT[c.cat]||'Acceso')+'</span></div></div>';
      if(c.u) h+='<div class="field"><label>Usuario</label><div class="fv"><code>'+esc(c.u)+'</code><button type="button" title="Copiar" onclick="vcopy(this,'+i+',\'u\')">'+ICO_COPY+'</button></div></div>';
      if(c.s) h+='<div class="field"><label>Contraseña</label><div class="fv"><code class="pw" data-i="'+i+'">••••••••••••</code><button type="button" title="Ver" onclick="vtoggle(this)">'+ICO_EYE+'</button><button type="button" title="Copiar" onclick="vcopy(this,'+i+',\'s\')">'+ICO_COPY+'</button></div></div>';
      if(c.nota) h+='<div class="note">'+esc(c.nota)+'</div>';
      if(c.url) h+='<a class="cred-link" href="'+safeUrl(c.url)+'" target="_blank" rel="noopener">'+ICO_EXT+' Acceder al servicio</a>';
      h+='</div>';
    });
    box.innerHTML=h+'</div>';
  }
  function vtoggle(b){ var c=b.parentNode.querySelector('.pw'); if(!c) return; var i=+c.getAttribute('data-i'); var s=((PORTAL.accesos_vault[i])||{}).s||''; if(c.dataset.shown){ c.textContent='••••••••'; c.dataset.shown=''; }else{ c.textContent=s; c.dataset.shown='1'; } }
  function vcopy(b,i,k){ var v=((PORTAL.accesos_vault[i])||{})[k]||''; if(navigator.clipboard) navigator.clipboard.writeText(v); var o=b.innerHTML; b.innerHTML=ICO_CHECK; setTimeout(function(){ b.innerHTML=o; },1100); }
  var SECCIONES=DATA.secciones||{};
  function applySections(){
    ['metricas','progreso','informes','como','accesos','plan','tareas','reuniones','facturas','soporte'].forEach(function(s){
      var on = SECCIONES[s] !== false;
      document.querySelectorAll('.pnav a[data-view="'+s+'"]').forEach(function(a){ a.style.display = on ? '' : 'none'; });
      var v=document.getElementById('view-'+s); if(v && !on){ v.classList.add('hidden'); }
    });
    // "servicio" es subpágina de "Método": si Método está oculto, también
    if(SECCIONES.como === false){ var vs=document.getElementById('view-servicio'); if(vs) vs.classList.add('hidden'); }
    // el botón "Informe del mes" de la cabecera depende de Informes (el de PDF no)
    if(SECCIONES.informes === false){ document.querySelectorAll('.head .pill-btn.go-informes').forEach(function(b){ b.style.display='none'; }); }
  }
  function applyConfig(){
    document.getElementById('greetName').textContent='Hola, '+CONFIG.saludo+' 👋';
    document.body.classList.toggle('no-conv',!CONFIG.conversiones);
  }
  function renderPlan(){
    /* Tolerante: un plan_json antiguo o parcial puede no traer items/detalle. */
    var plan=CONFIG.plan||{}, items=plan.items||[], detalle=plan.detalle||[];
    var g='';items.forEach(function(it,ix){var st=PLAN_STYLE[ix%PLAN_STYLE.length];g+='<div class="plan-item"><div class="pic" style="background:var(--yellow-soft);color:var(--yellow-d)"><svg viewBox="0 0 24 24" style="fill:currentColor;stroke:none">'+st.icon+'</svg></div><b>'+esc(it.n)+'</b><span>'+esc(it.t)+'</span></div>'});
    document.getElementById('planGrid').innerHTML=g||'<p class="note" style="grid-column:1/-1;margin:0">Tu plan se mostrará aquí en cuanto tu equipo lo configure.</p>';
    document.getElementById('planResumen').innerHTML='<p class="bigtext" style="margin:0">'+esc(plan.resumen||'')+'</p>';
    var d='';detalle.forEach(function(s){d+='<b>'+esc(s.h)+'</b><p>'+esc(s.p)+'</p>'});
    document.getElementById('planDetalle').innerHTML=d;
  }

  function go(view){
    document.querySelectorAll('.view').forEach(function(v){v.classList.remove('active')});
    var vw=document.getElementById('view-'+view); if(vw) vw.classList.add('active');
    document.querySelectorAll('.pnav a').forEach(function(a){a.classList.toggle('active',a.dataset.view===view)});
    if(view==='resumen'){animateProg();animateDonut();renderBanner();}
    if(view==='metricas')renderMetrics();
    if(view==='tareas')renderTareas();
    if(view==='facturas')renderFacturas();
    if(view==='reuniones')renderReuniones();
    if(view==='soporte')renderSoporte();
    if(view==='accesos')renderVault();
    if(window.innerWidth<=980)closeMenu();
    window.scrollTo({top:0,behavior:'smooth'});
  }
  function animateProg(){var b=document.getElementById('progBar');if(!b)return;b.style.width='0';requestAnimationFrame(function(){setTimeout(function(){b.style.width=b.dataset.w+'%'},80)})}
  function animateDonut(){var d=document.getElementById('donut');if(!d)return;d.style.background='conic-gradient(var(--yellow) 0 0,#e6e6ea 0 100%)';requestAnimationFrame(function(){setTimeout(function(){d.style.background='conic-gradient(var(--yellow) 0 70%,#e6e6ea 70% 100%)'},140)})}
  function animateBars(){
    var rm=(function(){try{return matchMedia('(prefers-reduced-motion:reduce)').matches;}catch(e){return false;}})();
    document.querySelectorAll('.mb-track .col[data-h]').forEach(function(c,i){
      var hh=c.dataset.h;
      /* El efecto de «crecer desde la base» es 100% CSS y SÍNCRONO: no depende de
         requestAnimationFrame ni de setTimeout (que en pestañas en segundo plano se
         pausan y dejarían la barra clavada en 0). Ponemos 0 sin transición, forzamos
         reflujo, y fijamos el alto final hh% con una transición y un retardo escalonado.
         El valor final queda especificado al instante: la barra SIEMPRE acaba en hh%. */
      if(rm||document.hidden){ c.style.transition='none'; c.style.height=hh+'%'; c.style.opacity='1'; return; }
      c.style.transition='none';
      c.style.height='0'; c.style.opacity='0';
      void c.offsetHeight;            // reflujo con el alto en 0
      /* Overshoot con --ease-spring (rebote sutil) + fade. El alto final (hh%) y la
         opacidad 1 son el DESTINO de la transición: se fijan síncronamente, así que
         la barra siempre acaba en hh% aunque la animación se interrumpa. */
      c.style.transition='height .82s var(--ease-spring) '+(i*70)+'ms, opacity .5s var(--ease-expo) '+(i*70)+'ms, filter .18s var(--ease-expo)';
      c.style.height=hh+'%'; c.style.opacity='1';
    });
  }

  /* (eliminado enter(): manipulaba #login, que ya no existe — el login se separó a
     login.php. Nunca se ejecutaba.) */
  function logout(){window.location='logout.php';}
  function toggleMenu(){document.getElementById('pside').classList.toggle('open');document.getElementById('scrim').classList.toggle('open');}
  function closeMenu(){document.getElementById('pside').classList.remove('open');document.getElementById('scrim').classList.remove('open');}

  function renderEstado(){var es=ESTADO||{};
    var n=document.getElementById('estNombre');if(n)n.textContent=es.nombre||'';
    var tg=document.getElementById('estTag');if(tg)tg.textContent=es.etiqueta||'';
    var fases=es.fases||[];var bar='',lab='';
    fases.forEach(function(f){bar+='<div class="pseg'+(f.estado==='done'?' done':(f.estado==='now'?' now':''))+'"></div>';lab+='<span'+(f.estado==='now'?' class="now"':'')+'>'+esc(f.t||'')+'</span>';});
    var b=document.getElementById('estBar');if(b)b.innerHTML=bar;
    var l=document.getElementById('estLabels');if(l)l.innerHTML=lab;
    var nx=document.getElementById('estNext');if(nx)nx.innerHTML='<b>Lo siguiente:</b> '+esc(es.siguiente||'');
  }
  /* Pinta un mini-indicador "▲ +12% / ▼ 8% / igual" comparando con el mes previo. */
  function setDl(id,cur,prev){
    var el=document.getElementById(id); if(!el) return;
    if(prev==null){ el.className='dl'; el.textContent='mes de partida'; el.classList.add('flat'); return; }
    if(prev===0){ if(cur>0){el.className='dl up';el.textContent='▲ nuevo';}else{el.className='dl flat';el.textContent='igual';} return; }
    var pp=Math.round((cur-prev)/prev*100);
    if(pp>0){el.className='dl up';el.textContent='▲ +'+pp+'%';}
    else if(pp<0){el.className='dl down';el.textContent='▼ '+Math.abs(pp)+'%';}
    else{el.className='dl flat';el.textContent='igual';}
  }
  function renderBigResult(){var m=MET[ACTUAL];if(!m)return;
    var i=MESES.indexOf(ACTUAL),p=i>0?MET[MESES[i-1]]:null,total=m.ll+m.wa+m.fo;
    document.getElementById('brKicker').textContent='Tu resultado de '+(ACTUAL||'').toLowerCase();
    document.getElementById('brNum').textContent=total;
    document.getElementById('brLl').textContent=m.ll;document.getElementById('brWa').textContent=m.wa;document.getElementById('brFo').textContent=m.fo;
    setDl('brLlD',m.ll,p?p.ll:null); setDl('brWaD',m.wa,p?p.wa:null); setDl('brFoD',m.fo,p?p.fo:null);
    var up=document.getElementById('brUp');
    if(p){var pt=p.ll+p.wa+p.fo;
      if(pt===0){ up.textContent = total>0 ? '▲ primer mes con contactos' : 'sin contactos todavía'; }
      else { var d=Math.round((total-pt)/pt*100); up.textContent=(d>=0?'▲ '+d+'% más que el mes pasado':'▼ '+Math.abs(d)+'% que el mes pasado'); }
    }else{up.textContent='primer mes con datos';}
  }
  function renderInformes(){
    var box=document.getElementById('infList'); if(!box) return;
    if(!INFORMES||!INFORMES.length){ box.innerHTML='<div class="card tight"><p class="muted" style="margin:0">Aún no hay informes publicados. En cuanto subamos el informe del mes aparecerá aquí, y podrás abrirlo siempre que quieras.</p></div>'; return; }
    var h='';
    INFORMES.slice().reverse().forEach(function(r){
      var titulo=esc(r.titulo||('Informe de '+(r.mes||'')));
      var sub=r.mes?('<span style="color:var(--muted);font-weight:500"> · '+esc(r.mes)+'</span>'):'';
      var texto=r.texto?('<div style="white-space:pre-wrap;font-size:13.5px;color:#46423b;line-height:1.65">'+esc(r.texto)+'</div>'):'<p class="muted">Sin contenido.</p>';
      var btn=r.url?('<a class="xlink" style="margin-top:14px" href="'+safeUrl(r.url)+'" target="_blank" rel="noopener"><svg viewBox="0 0 24 24"><path d="M14 3v5h5"/><path d="M7 3h7l5 5v11a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/></svg>Abrir / descargar</a>'):'';
      h+='<div class="faq long" onclick="this.classList.toggle(\'open\')" style="margin-bottom:10px"><div class="faq-q"><span>'+titulo+sub+'</span><svg class="qm" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg></div><div class="faq-a">'+texto+btn+'</div></div>';
    });
    box.innerHTML=h;
  }
  /* ---------- Descargar informe del mes en PDF ----------
     No usa librerías: monta un informe limpio en #printReport y lanza la
     impresión del navegador (el usuario elige «Guardar como PDF»). */
  function prDelta(cur,prev){
    if(prev==null) return {cls:'flat',txt:'mes de partida'};
    if(prev===0) return cur>0?{cls:'up',txt:'▲ nuevo'}:{cls:'flat',txt:'igual'};
    var pp=Math.round((cur-prev)/prev*100);
    if(pp>0) return {cls:'up',txt:'▲ +'+pp+'% vs mes anterior'};
    if(pp<0) return {cls:'down',txt:'▼ '+Math.abs(pp)+'% vs mes anterior'};
    return {cls:'flat',txt:'igual que el mes anterior'};
  }
  function prKpi(n,l,d){return '<div class="pr-kpi"><div class="n">'+n+'</div><div class="l">'+esc(l)+'</div>'+(d?'<div class="d '+d.cls+'">'+d.txt+'</div>':'')+'</div>';}
  function buildPrintReport(mes){
    mes=mes||curMet||ACTUAL; if(mes==='Global') mes=ACTUAL;
    if(MESES.length && MESES.indexOf(mes)<0) mes=ACTUAL;
    var m=MET[mes]||MET_CERO;
    var i=MESES.indexOf(mes), p=i>0?MET[MESES[i-1]]:null;
    var brandName=esc(BRAND.name||'Croilab');
    var logo=BRAND.logo?('<img src="'+safeUrl(BRAND.logo)+'" alt="">'):esc(BRAND.initial||'C');
    var logoBg=BRAND.color?(' style="background:'+esc(BRAND.color)+'"'):'';
    var h='<div class="pr-head"><div class="pr-logo"'+logoBg+'>'+logo+'</div><div><div class="pr-brand">'+brandName+' · Informe mensual</div><div class="pr-title">'+esc(mes)+' 2026 — '+esc(CONFIG.saludo||META.name||'')+'</div></div></div>';
    if(CONFIG.conversiones){
      var total=m.ll+m.wa+m.fo, pt=p?(p.ll+p.wa+p.fo):null;
      h+='<div class="pr-sec-t">Resultados del mes</div><div class="pr-kpis">'+
         prKpi(total,'Oportunidades de contacto',prDelta(total,pt))+
         prKpi(m.ll,'Llamadas',prDelta(m.ll,p?p.ll:null))+
         prKpi(m.wa,'WhatsApp',prDelta(m.wa,p?p.wa:null))+
         prKpi(m.fo,'Formularios',prDelta(m.fo,p?p.fo:null))+
         prKpi(fmt(m.vi),'Visitas en Google',prDelta(m.vi,p?p.vi:null))+
         prKpi(fmt(m.ap),'Apariciones en Google',prDelta(m.ap,p?p.ap:null))+
         '</div>';
    }
    var es=ESTADO||{};
    if(es.nombre||es.siguiente){
      h+='<div class="pr-sec-t">Estado del proyecto</div><div class="pr-note"><b>'+esc(es.nombre||'')+'</b>'+(es.etiqueta?(' — '+esc(es.etiqueta)):'')+(es.siguiente?('<br>Lo siguiente: '+esc(es.siguiente)):'')+'</div>';
    }
    var d=TAREAS[mes]||{completado:[],pendiente:[]};
    if((d.pendiente&&d.pendiente.length)||(d.completado&&d.completado.length)){
      h+='<div class="pr-sec-t">Trabajo de '+esc(mes)+'</div>';
      (d.pendiente||[]).forEach(function(t){h+='<div class="pr-task pend"><span class="b">•</span><span>'+esc(t.t)+' <i style="color:#888">(en curso)</i></span></div>';});
      (d.completado||[]).forEach(function(t){h+='<div class="pr-task done"><span class="b">✓</span><span>'+esc(t.t)+'</span></div>';});
    }
    var hoy=new Date().toLocaleDateString('es-ES',{day:'numeric',month:'long',year:'numeric'});
    h+='<div class="pr-foot">Generado el '+hoy+' · '+brandName+' · Área de cliente</div>';
    document.getElementById('printReport').innerHTML='<div class="pr-page">'+h+'</div>';
  }
  function downloadPDF(mes){ buildPrintReport(mes); setTimeout(function(){ window.print(); }, 60); }

  /* aviso flotante ligero (el portal no carga el kit del ERP) */
  var __pt;
  function toastMsg(t){
    var el=document.getElementById('ptoast');
    if(!el){ el=document.createElement('div'); el.id='ptoast'; el.className='ptoast'; document.body.appendChild(el); }
    el.textContent=t; el.classList.add('on'); clearTimeout(__pt);
    __pt=setTimeout(function(){ el.classList.remove('on'); }, 2600);
  }

  /* ---------- Popups ---------- */
  function pmOpen(id,show){ var ov=document.getElementById(id); if(!ov) return; ov.classList.toggle('on',show); if(show){ var f=ov.querySelector('input,textarea'); if(f) setTimeout(function(){ try{f.focus();}catch(e){} },90); } }
  function openReqModal(){ var b=document.getElementById('reqSend'); if(b&&!CANMSG){ b.disabled=true; b.textContent='Vista previa'; } pmOpen('reqOv',true); }
  function openMsgModal(){ var b=document.getElementById('msgSend'); if(b&&!CANMSG){ b.disabled=true; b.textContent='Vista previa'; } pmOpen('msgOv',true); }
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'){ document.querySelectorAll('.pmov.on').forEach(function(o){ o.classList.remove('on'); }); } });

  /* ---------- Contacto directo del cliente (crea un ticket de soporte) ---------- */
  function renderContacto(){
    var intro=document.getElementById('msgIntro');
    if(intro) intro.innerHTML='Nos llega directamente a tu equipo de <b>'+esc(BRAND.name||'Croilab')+'</b>. Te respondemos por aquí o por email lo antes posible.';
    if(!CANMSG){
      var b=document.getElementById('msgSend');
      if(b){ b.disabled=true; b.style.opacity='.5'; b.style.cursor='default'; b.textContent='Vista previa — envío desactivado'; }
    }
  }
  function sendMsg(){
    if(!CANMSG){ toastMsg('En vista previa no se envían mensajes.'); return; }
    var asunto=(document.getElementById('msgAsunto').value||'').trim();
    var cuerpo=(document.getElementById('msgCuerpo').value||'').trim();
    if(!cuerpo){ toastMsg('Escribe tu mensaje antes de enviar.'); document.getElementById('msgCuerpo').focus(); return; }
    var btn=document.getElementById('msgSend'); var old=btn.textContent; btn.disabled=true; btn.textContent='Enviando…';
    var tok=(document.querySelector('meta[name=csrf-token]')||{}).content||'';
    var body=new URLSearchParams(); body.set('portal_action','ticket'); body.set('asunto',asunto); body.set('cuerpo',cuerpo); body.set('_csrf',tok);
    fetch('index.php',{method:'POST',headers:{'X-CSRF-Token':tok,'X-Requested-With':'XMLHttpRequest'},body:body})
      .then(function(r){return r.json();})
      .then(function(j){
        if(j&&j.ok){
          pmOpen('msgOv',false); btn.disabled=false; btn.textContent=old;
          document.getElementById('msgAsunto').value=''; document.getElementById('msgCuerpo').value='';
          if(PORTAL&&PORTAL.tickets){ PORTAL.tickets.unshift({id:0,asunto:(asunto||'Tu mensaje'),estado:'abierto',nresp:0,fecha:''}); renderSoporte(); }
          toastMsg('¡Mensaje enviado! Tu equipo te responderá en breve.');
        }else{ btn.disabled=false; btn.textContent=old; toastMsg((j&&j.msg)||'No se pudo enviar. Inténtalo de nuevo.'); }
      })
      .catch(function(){ btn.disabled=false; btn.textContent=old; toastMsg('No se pudo enviar. Revisa tu conexión.'); });
  }
  function sendMeetReq(){
    if(!CANMSG){ toastMsg('En vista previa no se envían solicitudes.'); return; }
    var motivo=(document.getElementById('reqMotivo').value||'').trim();
    if(!motivo){ toastMsg('Cuéntanos brevemente el motivo.'); document.getElementById('reqMotivo').focus(); return; }
    var fecha=(document.getElementById('reqFecha').value||''), franja=(document.getElementById('reqFranja').value||'');
    var btn=document.getElementById('reqSend'); var old=btn.textContent; btn.disabled=true; btn.textContent='Enviando…';
    var tok=(document.querySelector('meta[name=csrf-token]')||{}).content||'';
    var body=new URLSearchParams(); body.set('portal_action','meetreq'); body.set('motivo',motivo); body.set('fecha',fecha); body.set('franja',franja); body.set('_csrf',tok);
    fetch('index.php',{method:'POST',headers:{'X-CSRF-Token':tok,'X-Requested-With':'XMLHttpRequest'},body:body})
      .then(function(r){return r.json();})
      .then(function(j){
        if(j&&j.ok){
          pmOpen('reqOv',false); btn.disabled=false; btn.textContent=old;
          if(PORTAL&&PORTAL.solicitudes){ PORTAL.solicitudes.unshift({id:0,fecha:(fecha||''),franja:franja,motivo:motivo,estado:'pendiente'}); }
          document.getElementById('reqMotivo').value=''; document.getElementById('reqFecha').value='';
          renderReuniones();
          toastMsg('¡Solicitud enviada! Te confirmaremos la reunión.');
        }
        else{ btn.disabled=false; btn.textContent=old; toastMsg((j&&j.msg)||'No se pudo enviar.'); }
      })
      .catch(function(){ btn.disabled=false; btn.textContent=old; toastMsg('No se pudo enviar. Revisa tu conexión.'); });
  }

  /* ================= MÓDULOS NUEVOS: tareas, facturas, reuniones, tickets ================= */
  var MESN=['','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
  function fmtFecha(d){ if(!d) return '—'; var p=(''+d).split('-'); if(p.length<3) return d; return p[2].slice(0,2)+'/'+p[1]+'/'+p[0].slice(2); }
  function fmtFechaLarga(d){ if(!d) return ''; var p=(''+d).split('-'); if(p.length<3) return d; return parseInt(p[2],10)+' de '+(MESN[parseInt(p[1],10)]||p[1])+' de '+p[0]; }
  function eurP(n){ return (typeof n==='number'?n:0).toLocaleString('es-ES',{minimumFractionDigits:2,maximumFractionDigits:2})+' €'; }
  function setCount(id,n){ var el=document.getElementById(id); if(!el) return; if(n>0){ el.textContent=n; el.hidden=false; } else { el.hidden=true; } }

  /* ----- Tareas con avatares (estilo ERP) ----- */
  var TK_EST={'pendiente':['En espera','#b0b4bb'],'en proceso':['En proceso','#3b82f6'],'atemporal':['Atemporal','#e0a000'],'completada':['Completada','#12a150']};
  var TK_PRIO={0:['',''],1:['Baja','#94a3b8'],2:['Normal','#3b82f6'],3:['Alta','#f59e0b'],4:['Urgente','#ef4444']};
  function avStack(asig){
    if(!asig||!asig.length) return '<div class="asg-stack"><div class="av-mini av-none" title="Sin asignar">–</div></div>';
    var h='<div class="asg-stack">',n=Math.min(asig.length,3);
    for(var i=0;i<n;i++){ var a=asig[i];
      if(a.foto) h+='<div class="av-mini" title="'+esc(a.n)+'"><img src="'+safeUrl(a.foto)+'" alt="" onerror="this.parentNode.style.background=\''+escJs(a.c)+'\';this.replaceWith(document.createTextNode(\''+escJs(a.ini)+'\'))"></div>';
      else h+='<div class="av-mini" title="'+esc(a.n)+'" style="background:'+esc(a.c)+'">'+esc(a.ini)+'</div>';
    }
    if(asig.length>3) h+='<div class="av-mini av-extra" title="+'+(asig.length-3)+' más">+'+(asig.length-3)+'</div>';
    return h+'</div>';
  }
  function asigName(asig){ if(!asig||!asig.length) return 'Sin asignar'; if(asig.length===1) return asig[0].n; return asig[0].n+' +'+(asig.length-1); }
  function stCircle(est){
    var c=(TK_EST[est]||TK_EST['pendiente'])[1];
    if(est==='completada') return '<span class="st"><svg width="20" height="20" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="'+c+'"/><path d="M8 12l3 3 5-6" fill="none" stroke="#fff" stroke-width="2"/></svg></span>';
    return '<span class="st"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="'+c+'" stroke-width="2"'+(est==='atemporal'?' stroke-dasharray="3 3"':'')+'/></svg></span>';
  }
  var curTMes=null;
  function tkRow(t){
    var doneTag=t.estado==='completada'?'<span class="st-tag" style="background:#e4f6ec;color:#12854a">Hecho</span>':'';
    var prio=TK_PRIO[t.prioridad]||['',''];
    var pr=prio[0]?'<span class="flagp" style="color:'+prio[1]+'"><span class="fdot" style="background:'+prio[1]+'"></span>'+prio[0]+'</span>':'<span style="color:var(--muted)">—</span>';
    var exp=t.texto?('<div class="tk-exp">'+esc(t.texto)+'</div>'):'';
    return '<div class="tk-row"'+(t.texto?' onclick="this.classList.toggle(\'open\')"':'')+'>'+
      '<div class="nm">'+stCircle(t.estado)+'<b>'+esc(t.titulo)+'</b>'+doneTag+'</div>'+
      '<div class="asig">'+avStack(t.asig)+'<span class="nn">'+esc(asigName(t.asig))+'</span></div>'+
      '<div class="flagp-cell">'+pr+'</div>'+
      '<div class="tk-date">'+esc(t.due?fmtFecha(t.due):'—')+'</div>'+
      exp+'</div>';
  }
  function tMonths(){ var seen={},arr=[]; ((PORTAL&&PORTAL.tareas)||[]).forEach(function(t){var m=t.mes||'General'; if(!seen[m]){seen[m]=true;arr.push(m);}}); return arr; }
  function pickTMes(m){ curTMes=m; renderTareas(); }
  function renderTareas(){
    var box=document.getElementById('tkList'); if(!box) return;
    var T=(PORTAL&&PORTAL.tareas)||[];
    setCount('cntTareas', T.filter(function(t){return t.estado!=='completada';}).length);
    if(!T.length){ box.innerHTML='<div class="empty-b">Aún no hay tareas para ti. En cuanto empecemos a trabajar en tu proyecto aparecerán aquí, mes a mes y con quién lleva cada cosa.</div>'; return; }
    var months=tMonths();
    if(curTMes===null || (curTMes!=='__all' && months.indexOf(curTMes)<0)) curTMes=months[0]||'__all';
    var sel='<div class="tk-filter"><label>Mes</label><select onchange="pickTMes(this.value)"><option value="__all"'+(curTMes==='__all'?' selected':'')+'>Todos los meses</option>';
    months.forEach(function(m){ sel+='<option value="'+esc(m)+'"'+(m===curTMes?' selected':'')+'>'+esc(m)+(m===months[0]&&curTMes!=='__all'?' · este mes':'')+'</option>'; });
    sel+='</select></div>';
    var groups={}, order=[];
    T.forEach(function(t){ var m=t.mes||'General'; if(curTMes!=='__all' && m!==curTMes) return; if(!groups[m]){groups[m]=[];order.push(m);} groups[m].push(t); });
    if(!order.length){ box.innerHTML=sel+'<div class="empty-b">No hay tareas en ese mes.</div>'; if(window.csEnhance)csEnhance(box); return; }
    var h=sel;
    order.forEach(function(m){
      var arr=groups[m], done=arr.filter(function(t){return t.estado==='completada';}).length, esteMes=(m===months[0]);
      h+='<div class="tk-grp"><div class="tk-gh"><span class="gpill"><span class="gd" style="background:var(--yellow)"></span>'+esc(m)+'</span>'+(esteMes?'<span class="tk-now">Este mes</span>':'')+'<span class="gn">'+done+'/'+arr.length+' hechas</span></div><div class="tk-body">';
      h+='<div class="tk-colh"><span>Tarea</span><span>Quién lo lleva</span><span>Prioridad</span><span>Fecha</span></div>';
      arr.forEach(function(t){ h+=tkRow(t); });
      h+='</div></div>';
    });
    box.innerHTML=h;
    if(window.csEnhance)csEnhance(box);
  }

  /* ----- Facturas ----- */
  var FAC_EST={'enviada':['Enviada','#3b82f6'],'pagada':['Pagada','#12a150'],'vencida':['Vencida','#ef4444']};
  function renderFacturas(){
    var box=document.getElementById('facList'); if(!box) return;
    var F=(PORTAL&&PORTAL.facturas)||[];
    setCount('cntFac',F.length);
    if(!F.length){ box.innerHTML='<div class="empty-b">Todavía no tienes facturas. Cuando emitamos alguna aparecerá aquí para que la veas y la descargues.</div>'; return; }
    var suf=CANMSG?'':('&cli='+CLIID);
    var h='';
    F.forEach(function(f){
      var ev=FAC_EST[f.estado]||['','#9aa0a8'];
      h+='<a class="lrow" href="factura.php?id='+f.id+suf+'" target="_blank" rel="noopener">'+
         '<div class="lic"><svg viewBox="0 0 24 24"><path d="M6 2h9l3 3v15l-2.2-1.5L13.6 20l-2.1-1.4L9.4 20l-2.1-1.4L5 20V4a2 2 0 0 1 1-2z"/><path d="M8.5 8h6M8.5 11.5h6"/></svg></div>'+
         '<div class="lx"><b>Factura '+esc(f.numero)+'</b><span>'+fmtFechaLarga(f.fecha)+(f.venc?(' · vence '+fmtFecha(f.venc)):'')+'</span></div>'+
         '<div class="lr"><span class="lstate" style="background:'+ev[1]+'">'+ev[0]+'</span><span class="amt">'+eurP(f.total)+'</span><svg class="go" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></div>'+
         '</a>';
    });
    box.innerHTML=h;
  }

  /* ----- Reuniones ----- */
  var REU_EST={'agendada':['Agendada','#3b82f6'],'realizada':['Realizada','#12a150'],'no_show':['No realizada','#e0a000'],'cancelada':['Cancelada','#9aa0a8']};
  function reuRow(m){
    var ev=REU_EST[m.estado]||['','#9aa0a8'];
    var d=m.fecha?new Date(m.fecha+'T00:00:00'):null;
    var dd=d?d.getDate():'—', mm=d?MESN[d.getMonth()+1].slice(0,3):'';
    var tit=m.titulo||'Reunión con tu equipo';
    return '<div class="lrow" style="cursor:default">'+
      '<div class="lic"><small>'+esc(mm)+'</small><b>'+dd+'</b></div>'+
      '<div class="lx"><b>'+esc(tit)+'</b><span>'+fmtFechaLarga(m.fecha)+(m.hora?(' · '+esc(m.hora)):'')+'</span></div>'+
      '<div class="lr"><span class="lstate" style="background:'+ev[1]+'">'+ev[0]+'</span></div>'+
      '</div>';
  }
  var SOL_EST={'pendiente':['Pendiente de confirmar','#e0a000'],'aprobada':['Confirmada','#12a150'],'rechazada':['No disponible','#9aa0a8']};
  function renderSolicitudes(){
    var box=document.getElementById('reqList'); if(!box) return;
    var S=(PORTAL&&PORTAL.solicitudes)||[];
    var pend=S.filter(function(s){return s.estado==='pendiente';});
    if(!pend.length){ box.innerHTML=''; return; }
    var h='<h2 class="sec">Tus solicitudes</h2>';
    pend.forEach(function(s){
      var ev=SOL_EST[s.estado]||['','#9aa0a8'];
      var sub=(s.fecha?fmtFechaLarga(s.fecha):'Sin día preferido')+(s.franja?(' · '+esc(s.franja)):'');
      h+='<div class="lrow" style="cursor:default"><div class="lic"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></div>'+
         '<div class="lx"><b>'+esc(s.motivo||'Reunión')+'</b><span>'+sub+'</span></div>'+
         '<div class="lr"><span class="lstate" style="background:'+ev[1]+'">'+ev[0]+'</span></div></div>';
    });
    box.innerHTML=h;
  }
  function renderReuniones(){
    var prox=document.getElementById('reuProx'), pas=document.getElementById('reuPas'), pasHd=document.getElementById('reuPasHd'); if(!prox) return;
    /* Formulario: intro + desactivado en vista previa. */
    var b=document.getElementById('reqSend');
    if(b && !CANMSG){ b.disabled=true; b.style.opacity='.5'; b.style.cursor='default'; b.textContent='Vista previa — solicitud desactivada'; }
    renderSolicitudes();
    var R=(PORTAL&&PORTAL.reuniones)||[];
    var hoy=new Date(); hoy.setHours(0,0,0,0);
    var up=[],old=[];
    R.forEach(function(m){ var d=m.fecha?new Date(m.fecha+'T00:00:00'):null; if(d && d>=hoy && m.estado!=='cancelada' && m.estado!=='realizada') up.push(m); else old.push(m); });
    up.sort(function(a,b){return (a.fecha||'').localeCompare(b.fecha||'');});
    setCount('cntReu', up.length);
    prox.innerHTML = up.length? up.map(reuRow).join('') : '<div class="empty-b">No tienes reuniones programadas ahora mismo. Cuando agendemos una contigo, la verás aquí.</div>';
    if(old.length){ pasHd.hidden=false; pas.innerHTML=old.map(reuRow).join(''); } else { pasHd.hidden=true; pas.innerHTML=''; }
  }

  /* ----- Soporte / tickets ----- */
  var SUP_EST={'abierto':['Abierto','#3b82f6'],'en_curso':['En curso','#7b68ee'],'esperando':['Esperando','#e0a000'],'resuelto':['Resuelto','#12a150'],'cerrado':['Cerrado','#9aa0a8']};
  function renderSoporte(){
    renderContacto();
    var box=document.getElementById('tkHist'); if(!box) return;
    var TK=(PORTAL&&PORTAL.tickets)||[];
    setCount('cntTk', TK.filter(function(t){return t.estado!=='resuelto'&&t.estado!=='cerrado';}).length);
    if(!TK.length){ box.innerHTML='<div class="nice-empty"><div class="ne-ic"><svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div><b>Aquí verás tus conversaciones</b><p>¿Una duda, una petición o algo que no va como esperabas? Escríbenos: te respondemos por aquí y verás cada mensaje con su estado.</p>'+(CANMSG?'<button class="ne-btn" onclick="openMsgModal()"><svg viewBox="0 0 24 24" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>Escribir a tu equipo</button>':'')+'</div>'; return; }
    var h='<h2 class="sec">Tus mensajes</h2>';
    TK.forEach(function(t){
      var ev=SUP_EST[t.estado]||['','#9aa0a8'];
      var resp=t.nresp>0?('<span>'+t.nresp+' respuesta'+(t.nresp>1?'s':'')+' de tu equipo</span>'):'<span>Sin respuesta todavía</span>';
      h+='<div class="lrow" style="cursor:default">'+
         '<div class="lic"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>'+
         '<div class="lx"><b>'+esc(t.asunto)+'</b>'+resp+'</div>'+
         '<div class="lr"><span class="lstate" style="background:'+ev[1]+'">'+ev[0]+'</span></div>'+
         '</div>';
    });
    box.innerHTML=h;
  }

  /* Desplegable estilizado global (calcado del ERP): reemplaza el <select> nativo
     por un menú con estilo, manteniendo el <select> por debajo. Expone csEnhance(). */
  (function(){var openWrap=null;
    function chev(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';}
    function closeOpen(){if(openWrap){openWrap.classList.remove('open');var p=openWrap._csPop;if(p)p.classList.remove('on');openWrap=null;}}
    function enhance(sel){
      if(sel.dataset.cs||sel.multiple)return;
      if(sel.classList.contains('plain')||sel.classList.contains('cs-native'))return;
      if(sel.closest&&sel.closest('.cs-wrap'))return;
      var w=sel.offsetWidth, pw=(sel.parentNode?sel.parentNode.clientWidth:0);
      sel.dataset.cs='1';
      var wrap=document.createElement('span');wrap.className='cs-wrap';
      sel.parentNode.insertBefore(wrap,sel);wrap.appendChild(sel);
      wrap.style.width=(w>0&&pw>0&&w>=pw-6)?'100%':(w>0?w+'px':'100%');
      sel.classList.add('cs-native');
      var trig=document.createElement('button');trig.type='button';trig.className='cs-trig';
      var lbl=document.createElement('span');lbl.className='cs-lbl';
      var arw=document.createElement('span');arw.className='cs-arw';arw.innerHTML=chev();
      trig.appendChild(lbl);trig.appendChild(arw);wrap.appendChild(trig);
      /* El panel va colgado de <body> para que 'position:fixed' NO se rompa por un
         ancestro con transform (el portal anima las vistas). Se limpia en csEnhance. */
      var pop=document.createElement('div');pop.className='cs-pop';pop._csWrap=wrap;wrap._csPop=pop;document.body.appendChild(pop);
      function sync(){var o=sel.options[sel.selectedIndex];lbl.textContent=o?o.textContent:'';trig.classList.toggle('dis',sel.disabled);}
      function render(){pop.innerHTML='';Array.prototype.forEach.call(sel.options,function(o,i){var it=document.createElement('div');it.className='cs-opt'+(i===sel.selectedIndex?' on':'');it.textContent=o.textContent;it.onmousedown=function(e){e.preventDefault();e.stopPropagation();sel.selectedIndex=i;sync();var ev;try{ev=new Event('change',{bubbles:true});}catch(_){ev=document.createEvent('HTMLEvents');ev.initEvent('change',true,false);}sel.dispatchEvent(ev);closeOpen();};pop.appendChild(it);});}
      function place(){var r=trig.getBoundingClientRect();var ph=Math.min(288,(pop.scrollHeight||220));pop.style.minWidth=r.width+'px';pop.style.left=Math.max(8,Math.min(r.left,window.innerWidth-Math.max(r.width,160)-8))+'px';var below=window.innerHeight-r.bottom, above=r.top;if(below<ph+8 && above>below){pop.style.top='';pop.style.bottom=Math.max(8,(window.innerHeight-r.top+4))+'px';}else{pop.style.bottom='';pop.style.top=Math.max(8,Math.min(window.innerHeight-ph-8,r.bottom+4))+'px';}}
      function open(){if(sel.disabled)return;closeOpen();render();place();wrap.classList.add('open');pop.classList.add('on');openWrap=wrap;}
      wrap._csPlace=place;wrap._csTrig=trig;
      trig.onclick=function(e){e.stopPropagation();e.preventDefault();wrap.classList.contains('open')?closeOpen():open();};
      sel.addEventListener('change',sync);sel._csSync=sync;sync();
    }
    window.csEnhance=function(root){try{
      /* Quita paneles huérfanos (su <select> ya no está en el DOM tras un re-render). */
      document.body.querySelectorAll('.cs-pop').forEach(function(p){ if(p._csWrap && !document.body.contains(p._csWrap)){ if(openWrap===p._csWrap)openWrap=null; p.remove(); } });
      (root||document).querySelectorAll('select').forEach(enhance);
    }catch(e){}};
    document.addEventListener('click',function(e){if(!e.target.closest('.cs-wrap'))closeOpen();});
    var csTick=false;
    window.addEventListener('scroll',function(){
      if(!openWrap||csTick)return;csTick=true;
      requestAnimationFrame(function(){csTick=false;if(!openWrap)return;var t=openWrap._csTrig;if(!t){closeOpen();return;}var r=t.getBoundingClientRect();if(r.bottom<0||r.top>window.innerHeight||r.right<0||r.left>window.innerWidth){closeOpen();return;}if(openWrap._csPlace)openWrap._csPlace();});
    },true);
  })();

  /* Arranque protegido: si una parte fallara por datos parciales de un cliente,
     no debe dejar el portal entero en blanco ni bloquear a las siguientes. */
  ['applySections','applyConfig','applyCfg','renderEstado','renderBigResult','renderMonths','renderTasks','renderRecursos','renderInformes','renderBanner','renderMetrics','renderHub','renderPlan','renderTareas','renderFacturas','renderReuniones','renderSoporte','renderVault'].forEach(function(f){ try{ window[f](); }catch(e){ if(window.console) console.error('portal init',f,e); } });
  if(window.csEnhance) csEnhance(document);
  window.addEventListener('resize',function(){ if(window.__drawMetLine) window.__drawMetLine(); });
</script>

<!-- ===================== MODO EDICIÓN (solo equipo) ===================== -->
<script>
(function(){
  if (typeof EDIT === 'undefined' || !EDIT) return;
  var dirty = false;

  var css = ''
   + '.edmode{padding-top:52px}'
   + '.edbar{position:fixed;top:0;left:0;right:0;height:52px;z-index:200;background:#16161a;color:#fff;display:flex;align-items:center;gap:12px;padding:0 16px;box-shadow:0 2px 14px rgba(0,0,0,.25)}'
   + '.edbar b{font-size:14.5px}.edbar span{font-size:12px;color:#9aa0aa}.edbar .sp{flex:1}'
   + '.edbtn{border:none;border-radius:10px;padding:9px 15px;font-size:13.5px;font-weight:600;cursor:pointer;background:#1f232a;color:#fff}'
   + '.edbtn:hover{background:#0f1113}.edbtn.ghost{background:rgba(255,255,255,.14);color:#fff}'
   + '.edpen{position:absolute;top:10px;right:10px;z-index:9;background:#ff9500;color:#fff;border:none;border-radius:99px;padding:8px 14px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 6px 16px rgba(255,149,0,.4);display:inline-flex;align-items:center;gap:6px}'
   + '.edpen:hover{background:#f08600;transform:translateY(-1px)}'
   + '.ed-editable{border-radius:22px;animation:edglow 3.4s ease-in-out infinite}'
   + '.ed-editable::before{content:"";position:absolute;inset:-5px;border-radius:24px;padding:2.5px;background:linear-gradient(120deg,#ffd08a,#ff9500,#ff6a00,#ffb65c,#ff9500);background-size:300% 300%;-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude;animation:edflow 5s linear infinite;pointer-events:none;z-index:6}'
   + '@keyframes edflow{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}'
   + '@keyframes edglow{0%,100%{box-shadow:0 0 16px rgba(255,149,0,.12)}50%{box-shadow:0 0 30px rgba(255,149,0,.24)}}'
   + '.edmode .prail,.edmode .pside{top:52px;height:calc(100vh - 52px)}'
   + '.edov{position:fixed;inset:0;background:rgba(15,18,25,.45);backdrop-filter:blur(4px);z-index:300;display:flex;align-items:center;justify-content:center;padding:18px}'
   + '.edpan{background:#fff;border-radius:20px;max-width:560px;width:100%;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 30px 70px rgba(0,0,0,.3)}'
   + '.edh{font-size:17px;font-weight:750;padding:18px 22px;border-bottom:1px solid #ededef}'
   + '.edbd{padding:16px 22px;overflow:auto}'
   + '.edft{display:flex;justify-content:flex-end;gap:10px;padding:14px 22px;border-top:1px solid #ededef}'
   + '.edf{margin-bottom:12px}.edf label{display:block;font-size:12px;color:#86868b;font-weight:600;margin-bottom:5px}'
   + '.edf input,.edf textarea,.edf select{width:100%;border:1px solid #e3e3e6;border-radius:11px;padding:10px 13px;font-size:14.5px;font-family:inherit;outline:none}'
   + '.edf input:focus,.edf textarea:focus,.edf select:focus{border-color:#1f232a;box-shadow:0 0 0 4px #f2f2f3}'
   + '.edf textarea{min-height:70px;resize:vertical}'
   + '.edrow2{background:#fafbfc;border:1px solid #e9ebee;border-radius:14px;padding:14px 14px 12px;margin-bottom:12px;position:relative}'
   + '.edgrid{display:grid;grid-template-columns:1fr;gap:10px}'
   + '.edrow2 .edfl{font-size:11.5px;color:#6b7280;font-weight:700;margin-bottom:4px;display:block}'
   + '.edrow2 input,.edrow2 textarea,.edrow2 select{width:100%;border:1px solid #dfe1e5;border-radius:10px;padding:11px 13px;font-size:14.5px;font-family:inherit;outline:none;background:#fff}'
   + '.edrow2 input:focus,.edrow2 textarea:focus,.edrow2 select:focus{border-color:#1f232a;box-shadow:0 0 0 3px #f2f2f3}'
   + '.edrowtop{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}'
   + '.edrowhd{font-weight:800;font-size:12px;color:#c2660a;background:#fff3e6;padding:4px 12px;border-radius:99px}'
   + '.edrm{border:1px solid #f0caca;background:#fff;color:#a32d2d;border-radius:9px;padding:6px 12px;font-size:12.5px;font-weight:700;cursor:pointer}'
   + '.edrm:hover{background:#fcebeb}'
   + '.edhint{font-size:13.5px;color:#475569;background:#eef4ff;border:1px solid #dbe6fb;border-radius:12px;padding:11px 14px;margin-bottom:14px;line-height:1.5}'
   + '.edmonth{border:1px solid #e6e8ec;border-radius:16px;padding:14px 14px 8px;margin-bottom:14px;background:#fcfcfd}'
   + '.edmonth-hd{display:flex;gap:10px;align-items:center;margin-bottom:8px}'
   + '.edmonth-hd .edcell{flex:1}'
   + '.edmonth-hd .edfl{margin-bottom:1px;opacity:.8}'
   + '.edmonth-in{border:1.5px solid transparent;background:transparent;font-weight:800;font-size:22px;letter-spacing:-.3px;color:#1d1d1f;padding:4px 8px;border-radius:11px;width:100%;transition:.15s}'
   + '.edmonth-in::placeholder{color:#c2c6cc;font-weight:700}'
   + '.edmonth-in:hover{background:#f4f6f9}'
   + '.edmonth-in:focus{background:#fff;border-color:#1f232a;box-shadow:0 0 0 3px #f2f2f3;outline:none}'
   + '.edchips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}'
   + '.edchip{border:1px solid #d8dbe0;background:#fff;color:#1d1d1f;border-radius:99px;padding:9px 16px;font-size:14px;font-weight:700;cursor:pointer;transition:.15s}'
   + '.edchip:hover{background:#f2f4f7}'
   + '.edchip.on{background:#1f232a;color:#fff;border-color:#1f232a;box-shadow:0 4px 12px rgba(16,19,24,.3)}'
   + '.edchip.add{background:#fff7ed;color:#c2660a;border:1px dashed #ffc680}'
   + '.edgrp-t{font-weight:800;font-size:13px;margin:14px 0 8px;display:flex;align-items:center;gap:6px}'
   + '.edgrp-t.done{color:#1e9e4a}.edgrp-t.pend{color:#1f232a}'
   + '.edsec{font-weight:800;font-size:13.5px;color:#16161a;margin:18px 0 8px;padding-top:14px;border-top:1px solid #eef0f2}'
   + '.edadd{border:2px dashed #ffc680;background:#fff7ed;color:#c2660a;border-radius:12px;padding:14px;width:100%;cursor:pointer;font-weight:700;font-size:14.5px;margin-top:4px}'
   + '.edadd.sm{padding:10px;font-size:13.5px}'
   + '.edadd:hover{background:#ffedd6}'
   + '.edtoast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#16161a;color:#fff;padding:12px 20px;border-radius:12px;font-size:14px;z-index:400;opacity:0;transition:.25s}'
   + '.edtoast.on{opacity:1}'
   + '.edchk{display:flex;align-items:center;gap:8px;font-size:14px;margin-top:6px}.edchk input{width:auto}'
   + '@media(max-width:600px){.edbar{gap:7px;padding:0 10px}.edbar .sub{display:none}.edbtn{padding:8px 11px;font-size:12.5px}.edpen{padding:6px 10px;font-size:12px}.edpan{max-height:92vh}.edov{padding:10px}.edrow2{padding:12px}.edchips{gap:6px}.edchip{padding:8px 13px;font-size:13px}}';
  var stEl = document.createElement('style'); stEl.textContent = css; document.head.appendChild(stEl);
  document.body.classList.add('edmode');

  var bar = document.createElement('div');
  bar.className = 'edbar';
  bar.innerHTML = '<b>✏️ Modo edición</b><span id="edName"></span><div class="sp"></div>'
    + '<button class="edbtn" id="edSaveBtn">Guardar cambios</button>'
    + '<a class="edbtn ghost" href="admin/index.php">Salir</a>';
  document.body.appendChild(bar);
  document.getElementById('edName').textContent = META.name || '';
  document.getElementById('edSaveBtn').onclick = saveAll;

  var toast = document.createElement('div'); toast.className='edtoast'; document.body.appendChild(toast);
  function showToast(t){ toast.textContent=t; toast.classList.add('on'); setTimeout(function(){toast.classList.remove('on');},2200); }

  function penBtn(title, fn){
    var b=document.createElement('button'); b.type='button'; b.className='edpen'; b.innerHTML='✏️ '+title; b.onclick=fn; return b;
  }
  function anchor(el, title, fn){ if(!el) return; el.classList.add('ed-editable'); el.style.position='relative'; el.appendChild(penBtn(title, fn)); }

  // colocar lápices en bloques estables
  anchor(document.querySelector('.head .hi'), 'Editar identidad', openIdent);
  anchor(document.querySelector('#view-resumen .status-card'), 'Editar estado del proyecto', openEstado);
  anchor(document.getElementById('view-plan'), 'Editar plan', openPlan);
  anchor(document.getElementById('view-accesos'), 'Editar accesos', openAccesos);
  anchor(document.getElementById('view-progreso'), 'Editar tareas', openTareas);
  anchor(document.getElementById('view-informes'), 'Editar informes', openInformes);

  // ---------- helpers de modal ----------
  function modal(title, build, onApply){
    var ov=document.createElement('div'); ov.className='edov';
    var pan=document.createElement('div'); pan.className='edpan';
    var h=document.createElement('div'); h.className='edh'; h.textContent=title;
    var bd=document.createElement('div'); bd.className='edbd';
    var ft=document.createElement('div'); ft.className='edft';
    var cancel=document.createElement('button'); cancel.className='edbtn ghost'; cancel.textContent='Cancelar';
    var apply=document.createElement('button'); apply.className='edbtn'; apply.textContent='Aplicar';
    function close(){ document.body.removeChild(ov); }
    cancel.onclick=close;
    apply.onclick=function(){ if(onApply()!==false){ dirty=true; close(); } };
    ov.onclick=function(e){ if(e.target===ov) close(); };
    ft.appendChild(cancel); ft.appendChild(apply);
    pan.appendChild(h); pan.appendChild(bd); pan.appendChild(ft); ov.appendChild(pan);
    document.body.appendChild(ov);
    build(bd);
  }
  function fieldEl(label, value, type){
    var w=document.createElement('div'); w.className='edf';
    var l=document.createElement('label'); l.textContent=label; w.appendChild(l);
    var i=document.createElement(type==='textarea'?'textarea':(type==='select'?'select':'input'));
    if(type!=='textarea'&&type!=='select') i.type=(type||'text');
    if(type!=='select') i.value=(value==null?'':value);
    w.appendChild(i); w._input=i; return w;
  }
  function inp(val, ph, w){
    var i=document.createElement('input'); i.type='text'; i.value=(val==null?'':val); if(ph)i.placeholder=ph; if(w)i.style.maxWidth=w; return i;
  }
  function sel(options, val){
    var s=document.createElement('select');
    options.forEach(function(o){ var op=document.createElement('option'); op.value=o[0]; op.textContent=o[1]; if(o[0]===val)op.selected=true; s.appendChild(op); });
    return s;
  }
  function hintEl(txt){ var h=document.createElement('div'); h.className='edhint'; h.textContent=txt; return h; }
  function secEl(txt, first){ var s=document.createElement('div'); s.className='edsec'; s.textContent=txt; if(first){s.style.borderTop='none';s.style.paddingTop='0';s.style.marginTop='0';} return s; }
  // lista repetible y FÁCIL: cada fila es una tarjeta numerada con etiquetas
  // makeRow(data) -> {cells:[{label, el}], get}
  function repeat(parent, items, makeRow, addLabel, rowName, hint){
    rowName = rowName || 'Elemento';
    if(hint){ var hp=document.createElement('div'); hp.className='edhint'; hp.textContent=hint; parent.appendChild(hp); }
    var box=document.createElement('div'); var regs=[];
    function renumber(){ regs.forEach(function(x,i){ x.hd.textContent = rowName+' '+(i+1); }); }
    function row(data){
      var card=document.createElement('div'); card.className='edrow2';
      var r=makeRow(data||{});
      var top=document.createElement('div'); top.className='edrowtop';
      var hd=document.createElement('span'); hd.className='edrowhd';
      var rm=document.createElement('button'); rm.type='button'; rm.className='edrm'; rm.textContent='🗑 Quitar';
      rm.onclick=function(){ box.removeChild(card); regs=regs.filter(function(x){return x.card!==card;}); renumber(); };
      top.appendChild(hd); top.appendChild(rm);
      var grid=document.createElement('div'); grid.className='edgrid';
      r.cells.forEach(function(c){
        var cell=document.createElement('div');
        if(c.label){ var lb=document.createElement('span'); lb.className='edfl'; lb.textContent=c.label; cell.appendChild(lb); }
        cell.appendChild(c.el); grid.appendChild(cell);
      });
      card.appendChild(top); card.appendChild(grid);
      box.appendChild(card); regs.push({card:card, hd:hd, get:r.get}); renumber();
    }
    (items||[]).forEach(row);
    var add=document.createElement('button'); add.type='button'; add.className='edadd'; add.textContent=addLabel||'➕ Añadir';
    add.onclick=function(){ row({}); try{ add.scrollIntoView({behavior:'smooth',block:'nearest'}); }catch(e){} };
    parent.appendChild(box); parent.appendChild(add);
    return function(){ return regs.map(function(x){return x.get();}); };
  }

  // ---------- IDENTIDAD ----------
  function openIdent(){
    var fS,fI,fN,fU,fP,fA,selTipo,fL,chk,svcChecks={};
    modal('Identidad del cliente', function(bd){
      var hint=document.createElement('div'); hint.className='edhint'; hint.textContent='Datos básicos del cliente y cómo entra a su portal. El “Saludo” es el nombre que ve al entrar.'; bd.appendChild(hint);
      fS=fieldEl('Saludo','' ); fS._input.value=CONFIG.saludo||''; bd.appendChild(fS);
      fI=fieldEl('Iniciales (avatar)',''); fI._input.value=META.iniciales||''; fI._input.maxLength=4; bd.appendChild(fI);
      fN=fieldEl('Nombre del negocio',''); fN._input.value=META.name||''; bd.appendChild(fN);
      fU=fieldEl('Usuario (para entrar)',''); fU._input.value=META.username||''; bd.appendChild(fU);
      fP=fieldEl('Contraseña (en blanco = no cambiar)',''); fP._input.placeholder='••••••'; bd.appendChild(fP);
      fA=fieldEl('Mes actual',''); fA._input.value=META.actual||''; bd.appendChild(fA);
      var wt=document.createElement('div'); wt.className='edf';
      var lt=document.createElement('label'); lt.textContent='Tipo de cliente (qué secciones ve)'; wt.appendChild(lt);
      var opts=[['','— Sin tipo —']]; TIPOS.forEach(function(t){opts.push([String(t.id), t.nombre]);});
      selTipo=sel(opts, String(META.tipo_id||'')); selTipo.style.width='100%'; wt.appendChild(selTipo); bd.appendChild(wt);
      fL=fieldEl('Panel de Looker Studio (URL de inserción, opcional)'); fL._input.value=META.looker||''; fL._input.placeholder='https://lookerstudio.google.com/embed/...'; bd.appendChild(fL);
      var wc=document.createElement('label'); wc.className='edchk'; chk=document.createElement('input'); chk.type='checkbox'; chk.checked=!!META.conversiones;
      wc.appendChild(chk); wc.appendChild(document.createTextNode(' Mostrar Métricas y oportunidades (si no usas tipo)')); bd.appendChild(wc);
      bd.appendChild(secEl('Servicios contratados (desbloquean su vídeo en Método; el resto sale con candado)'));
      SERV_ORDER.forEach(function(k){ var l=document.createElement('label'); l.className='edchk'; var cb=document.createElement('input'); cb.type='checkbox'; cb.checked=hasService(k); l.appendChild(cb); l.appendChild(document.createTextNode(' '+k)); bd.appendChild(l); svcChecks[k]=cb; });
    }, function(){
      META.saludo=fS._input.value.trim(); CONFIG.saludo=META.saludo;
      META.iniciales=fI._input.value.trim(); CONFIG.iniciales=META.iniciales;
      META.name=fN._input.value.trim(); CONFIG.cliente=META.name;
      META.username=fU._input.value.trim();
      if(fP._input.value!=='') META.password=fP._input.value;
      META.actual=fA._input.value.trim();
      META.tipo_id=selTipo.value;
      META.looker=fL._input.value.trim(); DATA.looker=META.looker;
      META.conversiones=chk.checked; CONFIG.conversiones=chk.checked;
      var sv=[]; SERV_ORDER.forEach(function(k){ if(svcChecks[k]&&svcChecks[k].checked) sv.push(k); }); SERVICIOS=sv;
      applyConfig(); renderHub(); applyLooker();
    });
  }

  // ---------- ESTADO ----------
  function openEstado(){
    var fN,fT,fS,collect;
    modal('Estado del proyecto', function(bd){
      var hint=document.createElement('div'); hint.className='edhint'; hint.textContent='Resume en qué punto está el proyecto. Abajo defines las fases: la barra del cliente se rellena según cuáles marques como completadas o en curso.'; bd.appendChild(hint);
      var sec=document.createElement('div'); sec.className='edsec'; sec.textContent='Texto que ve el cliente'; sec.style.borderTop='none'; sec.style.paddingTop='0'; sec.style.marginTop='0'; bd.appendChild(sec);
      fN=fieldEl('Etapa actual',''); fN._input.value=ESTADO.nombre||''; bd.appendChild(fN);
      fT=fieldEl('Etiqueta',''); fT._input.value=ESTADO.etiqueta||''; bd.appendChild(fT);
      fS=fieldEl('Lo siguiente','','textarea'); fS._input.value=ESTADO.siguiente||''; bd.appendChild(fS);
      var lab=document.createElement('div'); lab.className='edsec'; lab.textContent='Fases del proyecto (la barra de progreso)'; bd.appendChild(lab);
      collect=repeat(bd, ESTADO.fases||[], function(d){
        var t=inp(d.t,'Ej: Crecimiento'); var s=inp(d.s,'Ej: y captación');
        var e=sel([['done','✓ Completada'],['now','● En curso ahora'],['','○ Pendiente']], d.estado||'');
        return {cells:[{label:'Nombre de la fase', el:t},{label:'Subtítulo (opcional)', el:s},{label:'¿En qué punto está?', el:e}], get:function(){return {t:t.value.trim(), s:s.value.trim(), estado:e.value};}};
      }, '➕ Añadir fase', 'Fase', 'Cada fase es un paso del proyecto. Marca en cuál estáis ahora; la barra del cliente se rellena sola.');
    }, function(){
      ESTADO.nombre=fN._input.value.trim(); ESTADO.etiqueta=fT._input.value.trim(); ESTADO.siguiente=fS._input.value.trim();
      ESTADO.fases=collect().filter(function(f){return f.t!=='';});
      renderEstado();
    });
  }

  // ---------- PLAN ----------
  function openPlan(){
    var fR,cItems,cDet;
    modal('Plan contratado', function(bd){
      var hint=document.createElement('div'); hint.className='edhint'; hint.textContent='Lo que el cliente tiene contratado. Primero un resumen, luego los conceptos con número (salen grandes) y por último el detalle largo.'; bd.appendChild(hint);
      var s0=document.createElement('div'); s0.className='edsec'; s0.textContent='Resumen del plan'; s0.style.borderTop='none'; s0.style.paddingTop='0'; s0.style.marginTop='0'; bd.appendChild(s0);
      fR=fieldEl('Resumen (lenguaje sencillo)','','textarea'); fR._input.value=CONFIG.plan.resumen||''; bd.appendChild(fR);
      var l1=document.createElement('div'); l1.className='edsec'; l1.textContent='Lo que incluye (número + concepto)'; bd.appendChild(l1);
      cItems=repeat(bd, CONFIG.plan.items||[], function(d){
        var n=inp(d.n,'Ej: 4'); var t=inp(d.t,'Ej: Artículos de blog al mes');
        return {cells:[{label:'Número (grande)', el:n},{label:'Concepto', el:t}], get:function(){return {n:n.value.trim(), t:t.value.trim()};}};
      }, '➕ Añadir concepto', 'Concepto', 'Lo que incluye el plan cada mes. El número sale en grande en su portal.');
      var l2=document.createElement('div'); l2.className='edsec'; l2.textContent='Detalle completo (lo que se despliega)'; bd.appendChild(l2);
      cDet=repeat(bd, CONFIG.plan.detalle||[], function(d){
        var h=inp(d.h,'Ej: Página de servicio · 1 al mes'); var p=document.createElement('textarea'); p.value=d.p||''; p.placeholder='Explica qué incluye…';
        return {cells:[{label:'Título del apartado', el:h},{label:'Descripción', el:p}], get:function(){return {h:h.value.trim(), p:p.value.trim()};}};
      }, '➕ Añadir apartado', 'Apartado', 'El desglose largo del plan (la letra pequeña que el cliente despliega).');
    }, function(){
      CONFIG.plan.resumen=fR._input.value.trim();
      CONFIG.plan.items=cItems().filter(function(x){return x.n!==''||x.t!=='';});
      CONFIG.plan.detalle=cDet().filter(function(x){return x.h!==''||x.p!=='';});
      renderPlan();
    });
  }

  // ---------- ACCESOS ----------
  function openAccesos(){
    var collect;
    modal('Accesos del cliente', function(bd){
      collect=repeat(bd, RECURSOS||[], function(d){
        var b=inp(d.b,'Ej: Diseño en Figma'); var s=inp(d.s,'Ej: Mockups de tu web'); var u=inp(d.u,'https://…');
        var t=sel([['figma','Figma'],['drive','Google Drive'],['web','Sitio web'],['looker','Looker Studio'],['generic','Otro']], d.tipo||'generic');
        return {cells:[{label:'Título del acceso', el:b},{label:'Descripción', el:s},{label:'Enlace (pega la URL)', el:u},{label:'Logo que se muestra', el:t}], get:function(){return {b:b.value.trim(), s:s.value.trim(), u:u.value.trim()||'#', tipo:t.value};}};
      }, '➕ Añadir acceso', 'Acceso', 'Enlaces que el cliente abre desde su portal (Figma, Drive, su web…).');
    }, function(){
      var arr=collect().filter(function(x){return x.b!=='';});
      RECURSOS.length=0; arr.forEach(function(x){RECURSOS.push(x);});
      renderRecursos();
    });
  }

  // ---------- TAREAS ----------
  function openInformes(){
    var collect;
    modal('Informes mensuales', function(bd){
      bd.appendChild(hintEl('Cada informe es el análisis de un mes. El cliente puede abrirlos todos cuando quiera, sin perder ninguno. (Más adelante n8n los creará solos cada mes.)'));
      collect=repeat(bd, INFORMES||[], function(d){
        var mes=inp(d.mes,'Ej: Junio');
        var tit=inp(d.titulo,'Ej: Informe de junio');
        var url=inp(d.url,'https://… (PDF, opcional)');
        var txt=document.createElement('textarea'); txt.value=d.texto||''; txt.placeholder='Pega aquí el análisis del mes (lo que ve el cliente al abrirlo)…'; txt.style.minHeight='130px';
        return {cells:[{label:'Mes', el:mes},{label:'Título', el:tit},{label:'Enlace a PDF (opcional)', el:url},{label:'Análisis del mes', el:txt}], get:function(){return {mes:mes.value.trim(), titulo:tit.value.trim(), url:url.value.trim(), texto:txt.value};}};
      }, '➕ Añadir informe', 'Informe', 'Lo más nuevo se muestra arriba en el portal. Escribe el mes (ej. Junio) para ordenarlos.');
    }, function(){
      var arr=collect().filter(function(x){return x.mes!==''||x.titulo!==''||x.texto!=='';});
      INFORMES.length=0; arr.forEach(function(x){INFORMES.push(x);});
      renderInformes();
    });
  }
  function openTareas(){
    var months=[]; var chipsBar, panelsBox, addChip;
    function taskMaker(d){
      var t=inp(d.t,'Ej: Mejoras de SEO técnico');
      var desc=document.createElement('textarea'); desc.value=d.d||''; desc.placeholder='Explícalo en lenguaje sencillo para el cliente…';
      return {cells:[{label:'Título de la tarea', el:t},{label:'Explicación para el cliente', el:desc}], get:function(){return {t:t.value.trim(), d:desc.value.trim()};}};
    }
    function chipName(mi){ return mi.value.trim() || 'Mes nuevo'; }
    function select(rec){
      months.forEach(function(m){ var on=(m===rec); m.chip.classList.toggle('on',on); m.panel.style.display=on?'':'none'; });
    }
    function removeMonth(rec){
      var i=months.indexOf(rec); if(i<0) return;
      chipsBar.removeChild(rec.chip); panelsBox.removeChild(rec.panel); months.splice(i,1);
      if(months.length) select(months[Math.max(0,i-1)]);
    }
    function addMonth(mes, comp, pend, focus){
      var chip=document.createElement('button'); chip.type='button'; chip.className='edchip';
      var panel=document.createElement('div'); panel.className='edmonth'; panel.style.display='none';
      var hd=document.createElement('div'); hd.className='edmonth-hd';
      var cell=document.createElement('div'); cell.className='edcell';
      var ml=document.createElement('span'); ml.className='edfl'; ml.textContent='✏️ Mes (pulsa para cambiarlo)'; cell.appendChild(ml);
      var mi=inp(mes,'Ej: Junio'); mi.className='edmonth-in'; cell.appendChild(mi);
      var rm=document.createElement('button'); rm.type='button'; rm.className='edrm'; rm.textContent='🗑 Quitar este mes';
      hd.appendChild(cell); hd.appendChild(rm); panel.appendChild(hd);
      var g1=document.createElement('div'); g1.className='edgrp-t done'; g1.textContent='✓ Completado (ya hecho)'; panel.appendChild(g1);
      var compCollect=repeat(panel, comp, taskMaker, '➕ Añadir tarea completada', 'Tarea');
      var g2=document.createElement('div'); g2.className='edgrp-t pend'; g2.textContent='● En curso ahora'; panel.appendChild(g2);
      var pendCollect=repeat(panel, pend, taskMaker, '➕ Añadir tarea en curso', 'Tarea');
      var rec={chip:chip, panel:panel, get:function(){ return {mes:mi.value.trim(), completado:compCollect(), pendiente:pendCollect()}; }};
      chip.textContent=chipName(mi);
      mi.oninput=function(){ chip.textContent=chipName(mi); };
      chip.onclick=function(){ select(rec); };
      rm.onclick=function(){ removeMonth(rec); };
      months.push(rec); chipsBar.insertBefore(chip, addChip); panelsBox.appendChild(panel);
      if(focus){ select(rec); mi.focus(); }
    }
    modal('Tareas por mes', function(bd){
      bd.appendChild(hintEl('Pulsa un mes arriba para editarlo (o crea uno nuevo). Solo se muestra el mes elegido, para que sea cómodo.'));
      chipsBar=document.createElement('div'); chipsBar.className='edchips'; bd.appendChild(chipsBar);
      addChip=document.createElement('button'); addChip.type='button'; addChip.className='edchip add'; addChip.textContent='➕ Mes';
      addChip.onclick=function(){ addMonth('',[],[],true); };
      chipsBar.appendChild(addChip);
      panelsBox=document.createElement('div'); bd.appendChild(panelsBox);
      var keys=Object.keys(TAREAS||{});
      keys.forEach(function(mes){ var g=TAREAS[mes]||{}; addMonth(mes, g.completado||[], g.pendiente||[], false); });
      if(months.length) select(months[0]); else addMonth('',[],[],true);
    }, function(){
      var nuevo={};
      months.forEach(function(mb){
        var g=mb.get(); if(g.mes==='') return;
        if(!nuevo[g.mes]) nuevo[g.mes]={completado:[],pendiente:[]};
        g.completado.forEach(function(t){ if(t.t!=='') nuevo[g.mes].completado.push({t:t.t,d:t.d}); });
        g.pendiente.forEach(function(t){ if(t.t!=='') nuevo[g.mes].pendiente.push({t:t.t,d:t.d}); });
      });
      for(var k in TAREAS){ if(TAREAS.hasOwnProperty(k)) delete TAREAS[k]; }
      for(var k2 in nuevo){ if(nuevo.hasOwnProperty(k2)) TAREAS[k2]=nuevo[k2]; }
      TMESES=Object.keys(TAREAS);
      if(TMESES.indexOf(curMonth)<0) curMonth=TMESES[TMESES.length-1]||ACTUAL;
      renderMonths(); renderTasks(); renderBanner();
    });
  }

  // ---------- GUARDAR EN BD ----------
  function saveAll(){
    var payload={
      id:META.id, name:META.name, username:META.username, password:META.password||'',
      iniciales:META.iniciales, saludo:META.saludo, actual:META.actual,
      tipo_id:META.tipo_id, conversiones:META.conversiones?1:0,
      estado:ESTADO, plan:CONFIG.plan, accesos:RECURSOS, tareas:TAREAS, informes:INFORMES
    };
    if(Array.isArray(SERVICIOS)) payload.servicios=SERVICIOS;
    payload.looker=META.looker||'';
    var btn=document.getElementById('edSaveBtn'); btn.textContent='Guardando…'; btn.disabled=true;
    fetch('admin/save-portal.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':(document.querySelector('meta[name="csrf-token"]')||{content:''}).content||''},body:JSON.stringify(payload)})
      .then(function(r){return r.json();})
      .then(function(res){
        btn.textContent='Guardar cambios'; btn.disabled=false;
        if(res && res.ok){ dirty=false; META.password=''; showToast('✓ Guardado. Así lo ve el cliente.'); }
        else { showToast('⚠ '+((res&&res.msg)||'No se pudo guardar')); }
      })
      .catch(function(){ btn.textContent='Guardar cambios'; btn.disabled=false; showToast('⚠ Error de conexión'); });
  }

  window.addEventListener('beforeunload', function(e){ if(dirty){ e.preventDefault(); e.returnValue=''; } });
})();
</script>
</body>
</html>
