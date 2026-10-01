<?php
/* Armazón único del ERP (estilo ClickUp): rail + barra + contenido.
   Iconos SVG (sin emojis). Lo usan _layout.php y todas las páginas del panel. */

/* La marca (nombre y logo) sale de Ajustes, no está escrita en el HTML. */
require_once __DIR__ . '/lib/marca.php';

/* Reordenar el menú de la izquierda arrastrando.
   Antes esto solo existía en Finanzas (action=fin_nav_reorder) y guardaba un
   orden GLOBAL para toda la agencia, así que si Gabi movía «Por cliente» se le
   movía también a Víctor. Ahora vale para CUALQUIER bloque del menú y el orden
   es de cada persona: se guarda en settings bajo nav_order_<idAdmin>_<bloque>.
   El nombre del bloque cabe de sobra en la columna `clave` (60 caracteres).
   Se sigue aceptando el nombre viejo de la acción por si alguien tiene una
   pestaña abierta con el JavaScript anterior en caché. */
if ((($_SERVER['REQUEST_METHOD'] ?? '')==='POST')
    && in_array(($_POST['action'] ?? ''), ['nav_reorder','fin_nav_reorder'], true)) {
    $nav = (string)($_POST['nav'] ?? '');
    if ($nav==='' && isset($_POST['grp'])) { $nav = ($_POST['grp']==='fac') ? 'fin-fac' : (($_POST['grp']==='con') ? 'fin-con' : ''); }
    $adm = function_exists('current_admin') ? current_admin() : null;
    if ($adm && preg_match('/^[a-z0-9-]{1,24}$/', $nav)) {
        $ord = [];
        foreach ((array)($_POST['order'] ?? []) as $k) {
            $k = trim((string)$k);
            if ($k!=='' && preg_match('/^[a-z0-9_-]{1,32}$/i', $k) && !in_array($k,$ord,true)) $ord[] = $k;
            if (count($ord) >= 60) break;
        }
        if ($ord) {
            try {
                db()->prepare('INSERT INTO settings (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')
                    ->execute(['nav_order_'.(int)$adm['id'].'_'.$nav, implode(',', $ord)]);
            } catch (Exception $e) {}
        }
    }
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
}

/* Etiqueta de la línea de tiempo que gestiona el campo rápido «Tiempo» de la
   ficha de tarea. Existe para que ese campo toque SOLO su propia línea: las
   horas que el equipo registra en Finanzas > Horas son las que se cobran y no
   pueden desaparecer porque alguien corrija el tiempo de una tarea. */
if (!defined('TIME_CONCEPTO_TAREA')) define('TIME_CONCEPTO_TAREA', 'Horas de la tarea');

function ensure_time_schema(){
  static $done=false; if($done) return; $done=true;
  try{
    db()->exec("CREATE TABLE IF NOT EXISTS time_entries (
      id INT AUTO_INCREMENT PRIMARY KEY,
      admin_id INT NOT NULL,
      task_id INT NULL,
      client_id INT NULL,
      fecha DATE NOT NULL,
      minutos INT NOT NULL DEFAULT 0,
      importe DECIMAL(10,2) NULL,
      concepto VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(admin_id), INDEX(task_id), INDEX(fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $cols=db()->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins'")->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('es_autonomo',$cols,true)) db()->exec("ALTER TABLE admins ADD COLUMN es_autonomo TINYINT NOT NULL DEFAULT 0");
    if(!in_array('tarifa_hora',$cols,true)) db()->exec("ALTER TABLE admins ADD COLUMN tarifa_hora DECIMAL(10,2) NOT NULL DEFAULT 0");
    if(!in_array('iva_pct',$cols,true))    db()->exec("ALTER TABLE admins ADD COLUMN iva_pct DECIMAL(5,2) NOT NULL DEFAULT 0");
    if(!in_array('irpf_pct',$cols,true))   db()->exec("ALTER TABLE admins ADD COLUMN irpf_pct DECIMAL(5,2) NOT NULL DEFAULT 0");
  }catch(Exception $e){}
}

function erp_active_for() {
    $b = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $m = [
      /* index.php era 'roles', o sea: la lista de clientes vivía dentro de
         «Gestión y ajustes». La lista de clientes es trabajo del día, no
         configuración — y desde que Ajustes es solo para quien administra, dejarla
         ahí significaba que un Editor se quedaba sin ella. Ahora Clientes es su
         propio módulo del raíl, igual que Tareas o CRM. */
      'index.php'=>'clientes','client.php'=>'clientes','edit.php'=>'clientes','delete.php'=>'clientes','duplicate.php'=>'clientes',
      'types.php'=>'roles','type-edit.php'=>'roles','type-delete.php'=>'roles',
      'team.php'=>'roles','team-edit.php'=>'roles','team-delete.php'=>'roles','data.php'=>'roles','servicios.php'=>'roles','permisos.php'=>'roles',
      'settings.php'=>'ajustes','metricas.php'=>'ajustes','credenciales.php'=>'vault','papelera.php'=>'papelera','buscar.php'=>'buscar',
      'dashboard.php'=>'dashboard','workspace.php'=>'kanban','tasks.php'=>'kanban','task.php'=>'kanban','crm.php'=>'crm','negocio.php'=>'crm','listas.php'=>'crm','crm_dashboard.php'=>'crm','automatizaciones.php'=>'crm','crm_profile.php'=>'crm','crm_import.php'=>'crm','reuniones.php'=>'reuniones',
      'chat.php'=>'chat','ia.php'=>'ia','support.php'=>'tickets','calendar.php'=>'cal','actas.php'=>'actas',
      'notifications.php'=>'notif','automations.php'=>'ajustes','facturas.php'=>'fact','contabilidad.php'=>'conta','contabilidad-analisis.php'=>'conta','proyectos.php'=>'proj','fin-resumen.php'=>'resumen','fin-horas.php'=>'horas','pricing.php'=>'pricing','agencias.php'=>'agencias',
      /* Faltaban las dos: al abrirlas desde el propio menú de Finanzas el ERP
         cambiaba al sidebar de Tareas y dejaba el rail sin nada encendido. */
      'fin-ajustes.php'=>'finaj','programaciones.php'=>'prog',
    ];
    return $m[$b] ?? '';
}

/* Iconos SVG estilo Feather/Lucide */
function ic($n, $s = 18) {
    $p = [
      'home'=>'<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
      'clients'=>'<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>',
      'crm'=>'<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
      'kanban'=>'<rect x="3" y="3" width="6" height="18" rx="1.5"/><rect x="10.5" y="3" width="6" height="12" rx="1.5"/><rect x="18" y="3" width="3" height="8" rx="1.5"/>',
      'tasks'=>'<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
      'flag'=>'<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22V15"/>',
      'cal'=>'<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
      'ia'=>'<path d="M12 3l1.6 4.8L18 9.5l-4.4 1.7L12 16l-1.6-4.8L6 9.5l4.4-1.7z"/><path d="M19 14l.7 2.1L22 17l-2.3.9L19 20l-.7-2.1L16 17l2.3-.9z"/>',
      'vault'=>'<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
      'user'=>'<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
      'settings'=>'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 0 1-4 0v-.1a1.6 1.6 0 0 0-2.7-1.1l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
      'chat'=>'<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.2A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/>',
      'ticket'=>'<path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/><path d="M13 6v2M13 16v2"/>',
      'link'=>'<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14L21 3"/>',
      'inbox'=>'<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z"/>',
      'usercheck'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M16 11l2 2 4-4"/>',
      'meet'=>'<path d="m22 8-6 4 6 4V8Z"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
      'logout'=>'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
      'plus'=>'<path d="M12 5v14M5 12h14"/>',
      'check'=>'<path d="M20 6 9 17l-5-5"/>',
      'alert'=>'<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
      'trash'=>'<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/>',
      'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
      'pencil'=>'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
      'chart'=>'<path d="M3 3v18h18"/><path d="M7 14l3-4 3 3 4-6"/>',
      'layers'=>'<path d="M12 2 2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>',
      'building'=>'<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M9 7h.01M15 7h.01M9 11h.01M15 11h.01M10 21v-4h4v4"/>',
      'download'=>'<path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/>',
      'trend'=>'<path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>',
      'search'=>'<circle cx="11" cy="11" r="7"/><path d="m21 21-3.5-3.5"/>',
      'eye'=>'<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>',
      'back'=>'<path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/>',
      'folder'=>'<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
      'list'=>'<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1"/><circle cx="3.5" cy="12" r="1"/><circle cx="3.5" cy="18" r="1"/>',
      'chevron'=>'<path d="M9 18l6-6-6-6"/>',
      'bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
      'bolt'=>'<path d="M13 2L3 14h7l-1 8 10-12h-7z"/>',
      'euro'=>'<path d="M18 7a6 6 0 1 0 0 10"/><path d="M4 11h9M4 14h9"/>',
      'file'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
      'calc'=>'<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M8 10h2M12 10h2M16 10h0M8 14h2M12 14h2M8 18h6"/>',
      'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    ];
    $d = $p[$n] ?? '<circle cx="12" cy="12" r="9"/>';
    return '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'.$d.'</svg>';
}

/* Estado vacío estándar: icono grande + título + explicación + acción opcional.
   Unifica los muchos «No hay X» sueltos del ERP en una tarjeta con buena UX. */
if (!function_exists('erp_empty')) {
function erp_empty($icon, $titulo, $texto = '', $accionHtml = '') {
    return '<div class="erp-empty"><div class="ei">'.ic($icon,28).'</div><b>'.e($titulo).'</b>'
        . ($texto!==''?'<p>'.e($texto).'</p>':'')
        . ($accionHtml!==''?'<div class="ea">'.$accionHtml.'</div>':'')
        . '</div>';
}
}

/* Asa de arrastre de una fila reordenable. Va aparte de ic() porque son puntos
   rellenos y no un trazo, y porque siempre se dibuja al mismo tamaño. */
if (!function_exists('row_grip')) {
function row_grip() {
    /* El clic se para aquí: muchas filas navegan al pulsarlas y agarrar el asa no
       debe abrir nada. */
    return '<span class="row-grip" title="Arrastra para reordenar" onclick="event.stopPropagation()"><svg viewBox="0 0 10 16" fill="currentColor">'
         . '<circle cx="2.5" cy="3" r="1.4"/><circle cx="7.5" cy="3" r="1.4"/>'
         . '<circle cx="2.5" cy="8" r="1.4"/><circle cx="7.5" cy="8" r="1.4"/>'
         . '<circle cx="2.5" cy="13" r="1.4"/><circle cx="7.5" cy="13" r="1.4"/></svg></span>';
}
}

/* color estable por persona/entidad para avatares */
function avatar_color($s) {
    $p = ['#4f46e5','#0369a1','#0f766e','#047857','#b45309','#c2410c','#dc2626','#be185d','#6d28d9','#1d4ed8'];
    $s = (string)$s; $h = 0;
    /* Se mantiene el hash dentro de un entero de 31 bits en cada paso: si no, con
       nombres largos $h se desbordaba a float y PHP 8 soltaba un «Deprecated: Implicit
       conversion from float to int» que se colaba en la cabecera de las páginas. */
    for ($i=0;$i<strlen($s);$i++) $h = (($h<<5) - $h + ord($s[$i])) & 0x7FFFFFFF;
    return $p[$h % count($p)];
}

/* ---- Dinero, escrito igual en todo el ERP ----
   Cada página se había hecho su propia función para pintar euros (eur, eurh,
   eurp, eurk...) y no todas daban el mismo resultado: unas ponían céntimos y
   otras no, y en Precios el símbolo salía delante del número («€150») mientras
   en Facturas salía detrás («150,00 €»). A partir de aquí hay una sola forma:
   miles con «.», decimales con «,» y el símbolo detrás separado por un espacio,
   que es como se escribe en España.
     eur()  → con céntimos. Facturas, contabilidad, nóminas: cualquier cifra que
              tenga que cuadrar con un banco.
     eur0() → redondeado al euro. Cifras grandes de tablero (pipeline, KPIs),
              donde los céntimos solo estorban. El formato es el mismo.
   Se declaran con function_exists por si alguna página antigua todavía trae la
   suya: así no revienta con «cannot redeclare» mientras se terminan de migrar. */
if (!function_exists('eur')) {
    function eur($n, $dec = 2){ return number_format((float)$n, (int)$dec, ',', '.').' €'; }
}
if (!function_exists('eur0')) {
    function eur0($n){ return eur($n, 0); }
}
/* Abreviado para gráficas estrechas: 12.400 € → «12,4K €». */
if (!function_exists('eurk')) {
    function eurk($n){ $n=(float)$n; if (abs($n) >= 1000) return number_format($n/1000, 1, ',', '.').'K €'; return eur0($n); }
}

/* ---- Meses en español, escritos en un solo sitio ----
   La lista de los doce meses estaba copiada en tres páginas distintas y aun así
   faltaba donde hacía falta: las pestañas de los informes de Tareas ponían
   «2026-03», que es como lo agrupa la base de datos, no como se llama un mes.
     mes_nom(3)          → «marzo»
     mes_label('2026-03')→ «Marzo 2026» (para títulos y pestañas)  */
if (!function_exists('mes_nom')) {
    function mes_nom($m){
        static $n = [1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        return $n[(int)$m] ?? '';
    }
}
if (!function_exists('mes_label')) {
    function mes_label($ym){
        $p = explode('-', (string)$ym);
        if (count($p) !== 2) return (string)$ym;
        $n = mes_nom($p[1]);
        return ($n === '' ? $p[1] : mb_convert_case($n, MB_CASE_TITLE, 'UTF-8')).' '.$p[0];
    }
}

/* ---- Notificaciones ---- */
function notif_ensure(){ static $done=false; if($done)return; $done=true;
  try{ db()->exec("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, tipo VARCHAR(20) DEFAULT 'info', titulo VARCHAR(200), cuerpo VARCHAR(400), url VARCHAR(200) DEFAULT '', ref VARCHAR(140) DEFAULT NULL, tarea VARCHAR(200) DEFAULT '', actor VARCHAR(120) DEFAULT '', leido TINYINT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq (admin_id, ref)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  foreach(['tarea'=>"VARCHAR(200) DEFAULT ''",'actor'=>"VARCHAR(120) DEFAULT ''",'snooze_until'=>"DATETIME DEFAULT NULL",'borrado'=>"TINYINT NOT NULL DEFAULT 0",'bandeja'=>"VARCHAR(12) NOT NULL DEFAULT 'principal'"] as $col=>$def){ try{ if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='notifications' AND column_name='$col'")->fetchColumn()) db()->exec("ALTER TABLE notifications ADD COLUMN $col $def"); }catch(Exception $e){} }
  /* Esta tabla no la crea ensure_schema() sino el fichero que la usa, así que
     cuando la migración de índices pasó aún no existía y sus índices se
     quedaron sin crear. Con esto se recuperan, y solo si faltan: una consulta
     de comprobación y, en el caso normal, ninguna más.
     Lo que sí era un problema abierto: la bandeja se pedía entera y ordenada por
     fecha (notifications.php), y sin índice sobre (admin_id, created_at) eso
     era un ORDER BY en memoria de todos los avisos del usuario, en cada visita. */
  if (function_exists('croilab_indices_tabla_asegurar')) croilab_indices_tabla_asegurar(db(), 'notifications');
}
/* Categoría de una notificación a efectos de silenciar. Las asignaciones/menciones de
   tareas NO son silenciables (te perderías trabajo asignado); solo el chat y los avisos. */
function notif_cat($tipo){ if($tipo==='chat') return 'chat'; if(in_array($tipo,['bell','file','inbox','info'],true)) return 'avisos'; return ''; }
/* Círculo de estado de una tarea (En espera / En proceso / Atemporal / Completada).
   Vive aquí, compartido, para que lo usen el workspace y la página de notificaciones
   (el «circulito» de cada aviso refleja el estado real de la tarea vinculada). */
if (!function_exists('estado_circle')) {
function estado_circle($ek){
    if($ek==='completada') return '<svg width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#12a150"/><path d="M7.4 12.4l3 3 6.2-6.7" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    if($ek==='en proceso') return '<svg width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="#3b82f6" stroke-width="2"/><path d="M12 12 L12 3 A9 9 0 1 1 5.64 18.36 Z" fill="#3b82f6"/></svg>';
    if($ek==='atemporal') return '<svg width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="#e0a000" stroke-width="2.4" stroke-dasharray="3.2 3.2"/></svg>';
    return '<svg width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="#b0b4bb" stroke-width="2.4"/></svg>';
}
}
function notif_add($adminId,$tipo,$titulo,$cuerpo,$url='',$ref=null,$tarea='',$actor='',$bandeja='principal'){ notif_ensure();
  /* Preferencias del destinatario: puede silenciar «chat» y/o «avisos» (propuesta 7). */
  $cat=notif_cat($tipo);
  if($cat!=='' && function_exists('get_setting')){ $mute=(string)get_setting('notifmute_'.(int)$adminId,''); if($mute!=='' && in_array($cat, array_map('trim', explode(',', $mute)), true)) return; }
  $bandeja = ($bandeja==='otras') ? 'otras' : 'principal';
  try{ db()->prepare('INSERT IGNORE INTO notifications (admin_id,tipo,titulo,cuerpo,url,ref,tarea,actor,bandeja) VALUES (?,?,?,?,?,?,?,?,?)')->execute([(int)$adminId,$tipo,$titulo,$cuerpo,$url,$ref,$tarea,$actor,$bandeja]); }catch(Exception $e){} }

/* ---- Asignados múltiples de una tarea (item: varios responsables) ----
   Fuente de verdad: tabla puente task_assignees. Para no romper las decenas de
   lecturas de tasks.responsable_id repartidas por la app, ese campo se mantiene
   sincronizado con el PRIMER asignado (el «primario»). */
function task_asignados_ensure(){ static $ok=false; if($ok) return; try{ db()->exec("CREATE TABLE IF NOT EXISTS task_assignees (task_id INT NOT NULL, admin_id INT NOT NULL, orden INT NOT NULL DEFAULT 0, PRIMARY KEY(task_id,admin_id), KEY(admin_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){} $ok=true; }
/* Devuelve array de admin_id asignados a la tarea (ordenados). Si la tabla puente
   aún no tiene filas para esa tarea, cae en responsable_id (compatibilidad). */
function task_asignados($taskId){ task_asignados_ensure(); $taskId=(int)$taskId; $ids=[];
  try{ $q=db()->prepare('SELECT admin_id FROM task_assignees WHERE task_id=? ORDER BY orden,admin_id'); $q->execute([$taskId]); $ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)); }catch(Exception $e){}
  if(!$ids){ try{ $q=db()->prepare('SELECT responsable_id FROM tasks WHERE id=?'); $q->execute([$taskId]); $r=(int)$q->fetchColumn(); if($r) $ids=[$r]; }catch(Exception $e){} }
  return $ids; }
/* Reemplaza el conjunto de asignados y sincroniza responsable_id = primario. */
function task_set_asignados($taskId,$ids){ task_asignados_ensure(); $taskId=(int)$taskId;
  $ids=array_values(array_unique(array_filter(array_map('intval',(array)$ids))));
  try{ db()->prepare('DELETE FROM task_assignees WHERE task_id=?')->execute([$taskId]);
    $ins=db()->prepare('INSERT INTO task_assignees (task_id,admin_id,orden) VALUES (?,?,?)');
    foreach($ids as $i=>$aid){ $ins->execute([$taskId,$aid,$i]); }
    db()->prepare('UPDATE tasks SET responsable_id=? WHERE id=?')->execute([$ids[0]??null,$taskId]);
  }catch(Exception $e){}
  return $ids; }
/* ---- Asignados múltiples de un punto de la lista de control ----
   Mismo patrón que las tareas: puente chk_assignees + responsable_id = primario. */
function chk_asignados_ensure(){ static $ok=false; if($ok) return; try{ db()->exec("CREATE TABLE IF NOT EXISTS chk_assignees (chk_id INT NOT NULL, admin_id INT NOT NULL, orden INT NOT NULL DEFAULT 0, PRIMARY KEY(chk_id,admin_id), KEY(admin_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){} $ok=true; }
function chk_asignados($chkId){ chk_asignados_ensure(); $chkId=(int)$chkId; $ids=[];
  try{ $q=db()->prepare('SELECT admin_id FROM chk_assignees WHERE chk_id=? ORDER BY orden,admin_id'); $q->execute([$chkId]); $ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)); }catch(Exception $e){}
  if(!$ids){ try{ $q=db()->prepare('SELECT responsable_id FROM task_checklist WHERE id=?'); $q->execute([$chkId]); $r=(int)$q->fetchColumn(); if($r) $ids=[$r]; }catch(Exception $e){} }
  return $ids; }
function chk_set_asignados($chkId,$ids){ chk_asignados_ensure(); $chkId=(int)$chkId;
  $ids=array_values(array_unique(array_filter(array_map('intval',(array)$ids))));
  try{ db()->prepare('DELETE FROM chk_assignees WHERE chk_id=?')->execute([$chkId]);
    $ins=db()->prepare('INSERT INTO chk_assignees (chk_id,admin_id,orden) VALUES (?,?,?)');
    foreach($ids as $i=>$aid){ $ins->execute([$chkId,$aid,$i]); }
    db()->prepare('UPDATE task_checklist SET responsable_id=? WHERE id=?')->execute([$ids[0]??null,$chkId]);
  }catch(Exception $e){}
  return $ids; }
/* Mapa chk_id => [admin_id...] para todos los puntos de una tarea (una sola consulta). */
function chk_asignados_map($taskId){ chk_asignados_ensure(); $m=[];
  try{ $q=db()->prepare('SELECT a.chk_id,a.admin_id FROM chk_assignees a JOIN task_checklist c ON c.id=a.chk_id WHERE c.task_id=? ORDER BY a.orden,a.admin_id'); $q->execute([(int)$taskId]); foreach($q as $r){ $m[(int)$r['chk_id']][]=(int)$r['admin_id']; } }catch(Exception $e){}
  return $m; }
/* ---- Avisos del gestor de tareas (menciones, asignaciones, comentarios) ---- */
function task_ctx($taskId){ try{ $q=db()->prepare("SELECT t.titulo, t.responsable_id, c.name AS cname FROM tasks t JOIN clients c ON c.id=t.client_id WHERE t.id=?"); $q->execute([(int)$taskId]); return $q->fetch() ?: null; }catch(Exception $e){ return null; } }
function notif_admin_map(){ static $m=null; if($m!==null) return $m; $m=[]; try{ foreach(db()->query('SELECT id,username FROM admins') as $r){ $m[mb_strtolower($r['username'])]=(int)$r['id']; } }catch(Exception $e){} return $m; }
/* Aviso: te han asignado una tarea. targetId = a quién se le asigna. */
function notif_task_assigned($taskId,$targetId,$byName=''){
  $targetId=(int)$targetId; if(!$targetId) return;
  $me=current_admin(); if($targetId===(int)($me['id']??0)) return; // no avisarte a ti mismo
  $c=task_ctx($taskId); if(!$c) return;
  notif_add($targetId,'tarea','te ha asignado esta tarea',(string)$c['cname'],'task.php?id='.(int)$taskId,null,(string)$c['titulo'],(string)$byName);
}
/* Aviso a los DUEÑOS de que alguien ha MOVIDO una tarea: la ha puesto en marcha
   (estado → «en proceso») o le ha puesto una fecha. Llega a «Principal» si ese dueño
   está asignado a la tarea; si no, a «Otras» (actividad del equipo, para enterarse).
   No avisa a quien hizo el cambio. El `ref` con la fecha del día evita repetir hoy. */
function notif_task_activity($taskId,$kind,$detalle='',$byName=''){
  $c=task_ctx($taskId); if(!$c) return;
  $me=function_exists('current_admin')?current_admin():null; $meId=(int)($me['id']??0);
  $asignados=[]; foreach(task_asignados($taskId) as $aid) $asignados[(int)$aid]=true;
  $verbos=['start'=>'ha puesto en marcha una tarea','date'=>'le ha puesto fecha a una tarea'];
  $verbo=$verbos[$kind] ?? 'ha actualizado una tarea';
  if(trim((string)$detalle)!=='') $verbo.=' · '.trim((string)$detalle);
  $hoy=date('Y-m-d');
  foreach(notif_duenos($meId) as $uid){
    $band = isset($asignados[(int)$uid]) ? 'principal' : 'otras';
    $ref='taskact:'.$kind.':'.(int)$taskId.':'.$hoy.':'.(int)$uid;
    notif_add((int)$uid,'tarea',$verbo,(string)($c['cname']??''),'task.php?id='.(int)$taskId,$ref,(string)($c['titulo']??''),(string)$byName,$band);
  }
}
/* Aviso: te han asignado un punto de la lista de control. */
function notif_check_assigned($taskId,$targetId,$texto='',$byName=''){
  $targetId=(int)$targetId; if(!$targetId) return;
  $me=current_admin(); if($targetId===(int)($me['id']??0)) return;
  $c=task_ctx($taskId);
  notif_add($targetId,'tarea','te asignó un punto de la lista de control: '.mb_substr(trim((string)$texto),0,90),(string)($c['cname']??''),'task.php?id='.(int)$taskId.'#chk',null,(string)($c['titulo']??''),(string)$byName);
}
/* Aviso: alguien marcó/desmarcó un punto de la lista de control.
   Avisa a los responsables de la tarea y al responsable del punto (menos al que lo tacha). */
function notif_check_done($taskId,$chkId,$done,$texto='',$byName=''){
  $me=current_admin(); $meId=(int)($me['id']??0);
  $c=task_ctx($taskId); if(!$c) return;
  $dest=[];
  /* responsables de la tarea (soporta varios asignados si existe la tabla) */
  foreach(task_asignados($taskId) as $aid){ $dest[(int)$aid]=true; }
  if(!empty($c['responsable_id'])) $dest[(int)$c['responsable_id']]=true;
  /* responsable del propio punto */
  try{ $q=db()->prepare('SELECT responsable_id FROM task_checklist WHERE id=? AND task_id=?'); $q->execute([(int)$chkId,(int)$taskId]); $ri=(int)$q->fetchColumn(); if($ri) $dest[$ri]=true; }catch(Exception $e){}
  unset($dest[$meId]); // no avisarte a ti mismo
  if(!$dest) return;
  $verbo = $done ? 'completó un punto de la lista de control' : 'desmarcó un punto de la lista de control';
  $t = trim((string)$texto); if($t!=='') $verbo .= ': '.mb_substr($t,0,90);
  $ref = 'chkdone:'.(int)$chkId.':'.($done?1:0); // ref única por punto+estado (INSERT IGNORE evita duplicados)
  foreach(array_keys($dest) as $uid){ notif_add((int)$uid,'tarea',$verbo,(string)($c['cname']??''),'task.php?id='.(int)$taskId.'#chk',$ref.':'.(int)$uid,(string)($c['titulo']??''),(string)$byName); }
}
/* Escanea un comentario: avisa a los mencionados (@usuario) y al responsable de la tarea. */
function notif_comment_scan($taskId,$commentId,$body,$byName=''){
  $map=notif_admin_map(); if(!$map) return;
  $me=current_admin(); $meId=(int)($me['id']??0);
  $c=task_ctx($taskId);
  $tarea=(string)($c['titulo']??'');
  $plain=trim(preg_replace('/\s+/',' ', str_replace('[[img]]','[img]', strip_tags((string)$body))));
  $resumen=mb_substr($plain,0,160);
  $notified=[];
  if(preg_match_all('/@([\p{L}0-9_.\-]+)/u',(string)$body,$mm)){
    foreach($mm[1] as $name){ $k=mb_strtolower($name); if(!isset($map[$k])) continue; $tid=$map[$k]; if($tid===$meId||isset($notified[$tid])) continue; $notified[$tid]=1;
      notif_add($tid,'tarea','te ha mencionado: '.$resumen,'','task.php?id='.(int)$taskId.'#c'.(int)$commentId,'cmt:'.(int)$commentId.':'.$tid,$tarea,(string)$byName); }
  }
  if($c && !empty($c['responsable_id'])){ $rid=(int)$c['responsable_id']; if($rid!==$meId && !isset($notified[$rid])){ $notified[$rid]=1;
    notif_add($rid,'tarea','ha comentado en tu tarea: '.$resumen,'','task.php?id='.(int)$taskId.'#c'.(int)$commentId,'cmt:'.(int)$commentId.':'.$rid,$tarea,(string)$byName); } }
}
/* Escanea la DESCRIPCIÓN de una tarea y avisa a los mencionados (@usuario). Ref estable
   por (tarea, persona): el autoguardado de la descripción no repite el aviso. */
function notif_desc_scan($taskId,$body,$byName=''){
  $map=notif_admin_map(); if(!$map) return;
  $me=current_admin(); $meId=(int)($me['id']??0);
  $c=task_ctx($taskId); $tarea=(string)($c['titulo']??'');
  $notified=[];
  if(preg_match_all('/@([\p{L}0-9_.\-]+)/u',(string)$body,$mm)){
    foreach($mm[1] as $name){ $k=mb_strtolower($name); if(!isset($map[$k])) continue; $tid=$map[$k]; if($tid===$meId||isset($notified[$tid])) continue; $notified[$tid]=1;
      notif_add($tid,'tarea','te ha mencionado en una tarea','','task.php?id='.(int)$taskId,'descmention:'.(int)$taskId.':'.$tid,$tarea,(string)$byName); }
  }
}
/* Avisos de «toca contactar». Leía la tabla crm_leads, que es la del CRM viejo
   y ya no se escribe: por eso los recordatorios no salían nunca aunque la fecha
   de próxima acción estuviera pasada. Ahora lee contacts, que es donde el CRM
   guarda de verdad, y solo cuenta las fases abiertas (ni ganado, ni perdido, ni
   en pausa) según estén configuradas en Ajustes del embudo. */
function notif_sync_leads(){ notif_ensure();
  try{
    $admins = db()->query('SELECT id FROM admins')->fetchAll(PDO::FETCH_COLUMN); if(!$admins) return;

    /* Fases que siguen vivas. Si la tabla aún no existe se avisa de todas. */
    $abiertas=[];
    try{ $abiertas = db()->query("SELECT slug FROM pipeline_stages WHERE tipo NOT IN ('ganada','perdida','pausa')")->fetchAll(PDO::FETCH_COLUMN); }catch(Exception $e){}

    $sql = "SELECT id, nombre, empresa, fecha_prox, propietario_id, proxima_accion
              FROM contacts
             WHERE fecha_prox IS NOT NULL AND fecha_prox<>'0000-00-00' AND fecha_prox<=CURDATE()";
    $par = [];
    if($abiertas){ $sql .= ' AND fase IN ('.implode(',',array_fill(0,count($abiertas),'?')).')'; $par=$abiertas; }
    $st = db()->prepare($sql); $st->execute($par); $leads = $st->fetchAll();

    $ins = db()->prepare('INSERT IGNORE INTO notifications (admin_id,tipo,titulo,cuerpo,url,ref) VALUES (?,?,?,?,?,?)');
    foreach($leads as $l){
      $targets = $l['propietario_id'] ? [(int)$l['propietario_id']] : array_map('intval',$admins);
      /* Prefijo «cto» para no chocar con los avisos que dejó el CRM viejo. */
      $ref='cto:'.$l['id'].':'.$l['fecha_prox'];
      $quien = trim((string)($l['empresa']!=='' && $l['empresa']!==null ? $l['empresa'] : $l['nombre']));
      if($quien==='') $quien='un contacto';
      $tit='Toca contactar a '.$quien;
      $cue=(trim((string)$l['proxima_accion'])!==''?$l['proxima_accion']:'Seguimiento pendiente').' · '.date('d/m/Y',strtotime($l['fecha_prox']));
      foreach($targets as $t){ $ins->execute([$t,'lead',$tit,$cue,'crm.php?open='.(int)$l['id'],$ref]); }
    }
  }catch(Exception $e){}
}
/* ---- Presencia del equipo (en línea / ausente / desconectado) ----
   last_seen = latido (pestaña abierta); last_active = última interacción real.
   Verde = activo · Naranja = en reposo (abierto pero sin tocar) · Gris = fuera. */
function chat_presence_ensure(){ static $d=false; if($d)return; $d=true;
  try{ db()->exec("CREATE TABLE IF NOT EXISTS chat_presence (admin_id INT NOT NULL PRIMARY KEY, last_seen DATETIME DEFAULT NULL, last_active DATETIME DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_presence' AND column_name='last_active'")->fetchColumn()) db()->exec("ALTER TABLE chat_presence ADD COLUMN last_active DATETIME DEFAULT NULL"); }catch(Exception $e){}
}
function chat_presence_touch($aid,$active=true){ chat_presence_ensure();
  try{ if($active) db()->prepare("INSERT INTO chat_presence (admin_id,last_seen,last_active) VALUES (?,NOW(),NOW()) ON DUPLICATE KEY UPDATE last_seen=NOW(),last_active=NOW()")->execute([(int)$aid]);
       else db()->prepare("INSERT INTO chat_presence (admin_id,last_seen) VALUES (?,NOW()) ON DUPLICATE KEY UPDATE last_seen=NOW()")->execute([(int)$aid]); }catch(Exception $e){} }
function chat_hace_pres($s){ if($s<3600) return 'hace '.max(1,(int)floor($s/60)).' min'; if($s<86400) return 'hace '.(int)floor($s/3600).' h'; return 'hace '.(int)floor($s/86400).' d'; }
function chat_presence_state($aid){ chat_presence_ensure();
  try{ $r=db()->query("SELECT UNIX_TIMESTAMP(last_seen) ls, UNIX_TIMESTAMP(last_active) la FROM chat_presence WHERE admin_id=".(int)$aid)->fetch(); }catch(Exception $e){ $r=null; }
  $now=time();
  if(!$r || !$r['ls'] || ($now-(int)$r['ls'])>65) return ['state'=>'offline','text'=>($r&&$r['ls'])?('últ. vez '.chat_hace_pres($now-(int)$r['ls'])):'sin conexión'];
  if(!$r['la'] || ($now-(int)$r['la'])>300) return ['state'=>'idle','text'=>'ausente'];
  return ['state'=>'online','text'=>'en línea']; }
function chat_presence_color($state){ return $state==='online'?'#12a150':($state==='idle'?'#f0872a':'#c0c4cb'); }

/* ---- Perfil del equipo (ficha tipo red social). Tabla compartida. ---- */
function admin_profile_ensure(){ static $d=false; if($d)return; $d=true;
  try{ db()->exec("CREATE TABLE IF NOT EXISTS admin_profiles (admin_id INT NOT NULL PRIMARY KEY, cargo VARCHAR(120) DEFAULT '', departamento VARCHAR(80) DEFAULT '', telefono VARCHAR(60) DEFAULT '', ubicacion VARCHAR(120) DEFAULT '', web VARCHAR(160) DEFAULT '', skills VARCHAR(300) DEFAULT '', cumple DATE DEFAULT NULL, bio TEXT, foto VARCHAR(160) DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  foreach(['departamento'=>"VARCHAR(80) DEFAULT ''",'web'=>"VARCHAR(160) DEFAULT ''",'skills'=>"VARCHAR(300) DEFAULT ''",'cumple'=>"DATE DEFAULT NULL",'foto'=>"VARCHAR(160) DEFAULT ''"] as $c=>$def){ try{ if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='admin_profiles' AND column_name='$c'")->fetchColumn()) db()->exec("ALTER TABLE admin_profiles ADD COLUMN $c $def"); }catch(Exception $e){} }
}
function admin_photo_url($id){ admin_profile_ensure(); static $c=[]; $id=(int)$id; if(isset($c[$id]))return $c[$id];
  try{ $f=db()->query("SELECT foto FROM admin_profiles WHERE admin_id=".$id)->fetchColumn(); }catch(Exception $e){ $f=''; }
  $c[$id]=$f?('../archivo.php?d=avatars&f='.rawurlencode($f)):''; return $c[$id]; }

/* Mensajes de chat sin leer de una persona, sumando todas sus salas. Se pinta
   en el menú para que el chat no dependa de acordarse de entrar. */
function chat_unread($aid){
  static $c=[]; $aid=(int)$aid; if(isset($c[$aid])) return $c[$aid];
  try{
    $q=db()->prepare("SELECT COUNT(*) FROM chat_messages cm
                        JOIN chat_members me ON me.room_id=cm.room_id AND me.admin_id=?
                       WHERE cm.id>me.last_read AND cm.admin_id<>?");
    $q->execute([$aid,$aid]); $c[$aid]=(int)$q->fetchColumn();
  }catch(Exception $e){ $c[$aid]=0; }
  return $c[$aid];
}
/* El globo de la campana cuenta solo lo PRIORITARIO: excluye chat de equipo,
   pospuestas y papelera (el chat tiene su propia pestaña). */
function notif_unread($aid){ notif_ensure(); try{ $q=db()->prepare("SELECT COUNT(*) FROM notifications WHERE admin_id=? AND leido=0 AND borrado=0 AND tipo<>'chat' AND (snooze_until IS NULL OR snooze_until<=NOW())"); $q->execute([(int)$aid]); return (int)$q->fetchColumn(); }catch(Exception $e){ return 0; } }
function notif_sync_invoices(){ notif_ensure();
  try{
    db()->exec("UPDATE invoices SET estado='vencida' WHERE estado='enviada' AND fecha_venc IS NOT NULL AND fecha_venc<CURDATE()");
    $admins = db()->query('SELECT id FROM admins')->fetchAll(PDO::FETCH_COLUMN); if(!$admins) return;
    $inv = db()->query("SELECT id,numero,cliente_nombre,fecha_venc FROM invoices WHERE estado='vencida'")->fetchAll();
    $ins = db()->prepare('INSERT IGNORE INTO notifications (admin_id,tipo,titulo,cuerpo,url,ref) VALUES (?,?,?,?,?,?)');
    foreach($inv as $v){ $ref='inv:'.$v['id']; foreach($admins as $t){ $ins->execute([(int)$t,'factura','Factura vencida '.$v['numero'],($v['cliente_nombre']?:'Cliente').' · vencía '.($v['fecha_venc']?date('d/m/Y',strtotime($v['fecha_venc'])):''),'facturas.php?v='.$v['id'],$ref]); } }
  }catch(Exception $e){}
}
/* Solicitudes de reunión que llegan del portal del cliente: avisa a los dueños
   (campana). Dedup por ref 'meetreq:<id>'. La tabla la crea el portal al vuelo. */
function notif_sync_meeting_requests(){ notif_ensure();
  try{
    $rows = db()->query("SELECT r.id, c.name AS cliente FROM portal_meeting_requests r JOIN clients c ON c.id=r.client_id WHERE r.estado='pendiente'")->fetchAll();
    if(!$rows || !function_exists('notif_duenos')) return;
    $duenos = notif_duenos(); if(!$duenos) return;
    $ins = db()->prepare('INSERT IGNORE INTO notifications (admin_id,tipo,titulo,cuerpo,url,ref) VALUES (?,?,?,?,?,?)');
    foreach($rows as $r){ $ref='meetreq:'.$r['id']; foreach($duenos as $t){ $ins->execute([(int)$t,'info','Nueva solicitud de reunión',($r['cliente']?:'Un cliente').' ha pedido una reunión desde su portal','reuniones.php',$ref]); } }
  }catch(Exception $e){}
}
/* ================== Avisos automáticos añadidos ==================
   Cuatro hechos que antes no avisaban: te asignan un ticket, se cobra una factura,
   se da de alta un cliente y el cumpleaños de un compañero. Se dispararon desde el
   sitio donde ocurre el hecho (support.php, facturas.php, edit.php) salvo el
   cumpleaños, que no tiene «acción» y se revisa una vez al día en el sync.

   Todos usan el mismo `notif_add()` con un `ref` único, así que un mismo hecho no
   avisa dos veces (INSERT IGNORE por (admin_id, ref)). El ticket es un aviso
   personal y directo (siempre llega); los otros tres entran en la categoría
   «avisos», que cada uno puede silenciar en Mi cuenta › Avisos. */

/* Los administradores con acceso total: a ellos les importan los hechos de
   negocio (una factura cobrada, un cliente nuevo). Se excluye a quien lo provoca:
   no tiene sentido avisarte de algo que acabas de hacer tú. */
function notif_duenos($excluir=0){
  $ids=[];
  try{
    $roles = function_exists('roles_todos') ? roles_todos() : [];
    $conTotal=[]; foreach($roles as $k=>$r){ if(in_array('admin.total',$r['permisos']??[],true)) $conTotal[]=$k; }
    if(!$conTotal) return [];
    $in=implode(',',array_fill(0,count($conTotal),'?'));
    $st=db()->prepare("SELECT id FROM admins WHERE role IN ($in)"); $st->execute($conTotal);
    foreach($st as $r){ if((int)$r['id']!==(int)$excluir) $ids[]=(int)$r['id']; }
  }catch(Exception $e){}
  return $ids;
}

/* Te han asignado un ticket de soporte. targetId = a quién. Igual que las tareas:
   no te avisa a ti mismo si te lo autoasignas. */
function notif_ticket_assigned($ticketId,$targetId,$byName=''){
  $targetId=(int)$targetId; if(!$targetId) return;
  $me=function_exists('current_admin')?current_admin():null;
  if($targetId===(int)($me['id']??0)) return;
  try{
    $q=db()->prepare("SELECT t.asunto, c.name cname FROM support_tickets t LEFT JOIN clients c ON c.id=t.client_id WHERE t.id=?");
    $q->execute([(int)$ticketId]); $r=$q->fetch(); if(!$r) return;
    notif_add($targetId,'ticket','te ha asignado este ticket',($r['cname']?(string)$r['cname']:'Sin cliente'),
              'support.php?t='.(int)$ticketId, 'tkassign:'.(int)$ticketId.':'.$targetId, (string)$r['asunto'], (string)$byName);
  }catch(Exception $e){}
}

/* Una factura se ha marcado como cobrada. Avisa a los dueños (menos a quien la
   cobró). El `ref` lleva la fecha de pago: si se descobra y se vuelve a cobrar
   otro día, es un hecho nuevo y vuelve a avisar; el mismo día no repite. */
function notif_invoice_paid($invoiceId,$byName=''){
  try{
    $q=db()->prepare("SELECT numero,cliente_nombre,fecha_pago FROM invoices WHERE id=?"); $q->execute([(int)$invoiceId]);
    $v=$q->fetch(); if(!$v) return;
    $me=function_exists('current_admin')?current_admin():null;
    $ref='invpaid:'.(int)$invoiceId.':'.($v['fecha_pago']?:date('Y-m-d'));
    $cue=trim(($v['cliente_nombre']?:'Cliente'));
    foreach(notif_duenos((int)($me['id']??0)) as $t)
      notif_add($t,'info','factura cobrada '.(string)$v['numero'],$cue,'facturas.php?v='.(int)$invoiceId,$ref,'',(string)$byName);
  }catch(Exception $e){}
}

/* Se ha dado de alta un cliente nuevo. Avisa a los dueños (menos a quien lo creó). */
function notif_client_new($clientId,$byName=''){
  try{
    $q=db()->prepare("SELECT name FROM clients WHERE id=?"); $q->execute([(int)$clientId]);
    $name=(string)$q->fetchColumn(); if($name==='') return;
    $me=function_exists('current_admin')?current_admin():null;
    foreach(notif_duenos((int)($me['id']??0)) as $t)
      notif_add($t,'info','nuevo cliente de alta',$name,'client.php?id='.(int)$clientId,'clinew:'.(int)$clientId,'',(string)$byName);
  }catch(Exception $e){}
}

/* Cumpleaños del equipo. Sin «acción» que lo dispare: se revisa una vez al día en
   el sync del sidebar. Compara mes-día (no el año), avisa a TODO EL MUNDO menos al
   cumpleañero, y el `ref` con la fecha de hoy hace que sea uno por persona y día. */
function notif_sync_birthdays(){ notif_ensure();
  try{
    $hoy = date('m-d');
    $st = db()->query("SELECT p.admin_id, a.username FROM admin_profiles p JOIN admins a ON a.id=p.admin_id
                       WHERE p.cumple IS NOT NULL AND DATE_FORMAT(p.cumple,'%m-%d')='".$hoy."'");
    $cumplen = $st->fetchAll(); if(!$cumplen) return;
    $todos = db()->query('SELECT id FROM admins')->fetchAll(PDO::FETCH_COLUMN);
    $ins = db()->prepare('INSERT IGNORE INTO notifications (admin_id,tipo,titulo,cuerpo,url,ref,actor) VALUES (?,?,?,?,?,?,?)');
    foreach($cumplen as $c){
      $ref='bday:'.(int)$c['admin_id'].':'.date('Y-m-d');
      foreach($todos as $t){
        if((int)$t===(int)$c['admin_id']) continue;   // no te felicitas a ti mismo
        $ins->execute([(int)$t,'info','🎂 hoy cumple años '.(string)$c['username'],'Felicítale cuando puedas','perfil.php?id='.(int)$c['admin_id'],$ref,(string)$c['username']]);
      }
    }
  }catch(Exception $e){}
}

function auto_on($k){ static $c=null; if($c===null){ $c=[]; try{ foreach(db()->query("SELECT clave,valor FROM settings WHERE clave LIKE 'auto\\_%'") as $r) $c[$r['clave']]=$r['valor']; }catch(Exception $e){} } $v=$c['auto_'.$k] ?? '1'; return ($v==='1'||$v===1); }

function erp_css() { ?>
:root{--ink:#3c4149;--ink-strong:#22262c;--muted:#656a72;--label:#656a72;--line:#eeeeef;--line2:#f6f6f7;--accent:#1f232a;--accent-soft:#f2f2f3;--bg:#ffffff;--card:#fff;--soft:#f7f7f8;--ring:#c4c4c7;--ring-soft:rgba(17,19,24,.07);--ok:#0f7a3d;--danger:#c62a33}
*{box-sizing:border-box;margin:0;padding:0}
a{text-decoration:none;color:inherit}
body{font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--ink);font-size:14px;display:flex;min-height:100vh;-webkit-font-smoothing:antialiased;letter-spacing:-.1px;line-height:1.5}
svg{display:block}
@keyframes fadeUp{from{opacity:0;transform:translateY(9px)}to{opacity:1;transform:none}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes pop{0%{transform:scale(.97)}55%{transform:scale(1.03)}100%{transform:scale(1)}}
@keyframes slideIn{from{opacity:0;transform:translateX(-6px)}to{opacity:1;transform:none}}
a,button,.btn,.card,input,select,textarea,.trow,.ck-row,.nav a,.rail a,.chip,.tl-tab,.cli-h,.cli-lists a,.hub-act,.pick a,.cred,.tile,.rrow,.kpi,.tbtn,.icon-btn,.ck-x{transition:background-color .16s ease,border-color .16s ease,color .16s ease,box-shadow .18s ease,transform .16s cubic-bezier(.2,.7,.3,1)}
::selection{background:#e4e5e8;color:#0f1216}
*{scrollbar-width:thin;scrollbar-color:rgba(0,0,0,.18) transparent}
::-webkit-scrollbar{width:10px;height:10px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(0,0,0,.15);border-radius:8px}
::-webkit-scrollbar-thumb:hover{background:rgba(0,0,0,.3)}
.rail::-webkit-scrollbar-thumb{background:rgba(255,255,255,.16)}
/* rail */
/* Raíl como panel flotante (estilo ClickUp): margen alrededor, esquinas redondeadas
   y padding interno reducido para que parezca embebido en un contenedor. */
.rail{width:66px;flex:none;background:#0f1012;display:flex;flex-direction:column;align-items:center;padding:12px 0;gap:9px;height:calc(100vh - 16px);position:sticky;top:8px;margin:8px 6px 8px 8px;border-radius:20px;z-index:20;overflow-y:auto;overflow-x:hidden;scrollbar-width:none}
/* Con pantallas bajas de altura los iconos no caben: en vez de aplastarse (el logo llegaba a
   medir 21px de 34), el raíl hace scroll y cada elemento conserva su tamaño. */
.rail::-webkit-scrollbar{width:0;height:0;display:none}
.rail>*:not(.rsp){flex:none}
.rail .rlogo{width:34px;height:34px;border-radius:10px;background:#fff;color:#0f1012;display:flex;align-items:center;justify-content:center;font-weight:800;margin-bottom:12px}
.rail .rlogo:hover{animation:pop .35s ease}
.rail a{width:50px;height:50px;border-radius:15px;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#7e838d;gap:3px;position:relative}
.rail a span{font-size:9px;font-weight:600;letter-spacing:.1px;text-align:center;width:100%;line-height:1.1}
.rail a:hover{background:#1e2024;color:#fff;transform:translateY(-1px)}
.rail a.on{background:#2a2c31;color:#fff}
.rail a.on::before{content:"";position:absolute;left:-9px;top:12px;bottom:12px;width:3px;border-radius:0 3px 3px 0;background:#fff}
.rail-item{width:100%;position:relative;display:flex;justify-content:center}
.rail-fly{position:fixed;left:80px;top:0;height:100vh;width:240px;background:#fff;border-right:1px solid var(--line);opacity:0;visibility:hidden;transition:opacity .12s ease .1s,visibility .12s ease .1s;z-index:55;overflow:auto;box-shadow:14px 0 34px -18px rgba(16,19,24,.25)}
/* El desplegable ya no se abre con :hover a secas, sino cuando el JS de abajo
   pone .fly-on: así hay que dejar el ratón quieto un momento la primera vez y
   el menú no salta cada vez que el cursor cruza la barra de camino a otro sitio. */
.rail-item.fly-on .rail-fly{opacity:1;visibility:visible;z-index:56;transition-delay:0s}
.rail-fly .rf-h{padding:18px 20px 6px;font-size:15.5px;font-weight:650;letter-spacing:-.2px;color:var(--ink-strong)}
.rail-fly .rf-nav{display:flex;flex-direction:column;padding:4px 10px;gap:8px;margin-top:8px}
.rail-fly .rf-nav a{display:flex;flex-direction:row;align-items:center;gap:13px;width:auto;height:auto;padding:12px 13px;border-radius:11px;color:#5a5f68;font-size:14px;font-weight:500;text-decoration:none;white-space:nowrap}
.rail-fly .rf-nav a:hover{background:var(--soft);color:var(--ink)}
.rail-fly .rf-nav a .ic{width:18px;height:18px;color:var(--label);flex:none;display:flex}
.rail-fly .rf-nav a:hover .ic{color:var(--ink)}
.rail-fly .rf-nav a.on{background:var(--soft);color:var(--ink);font-weight:600}
.rail-fly .rf-nav a.on .ic{color:var(--ink)}
.rail-fly .rf-sec{font-size:10.5px;text-transform:uppercase;letter-spacing:.7px;color:var(--label);font-weight:700;padding:15px 20px 5px}
.rail-fly .rf-empty{padding:7px 21px;font-size:12px;color:var(--muted)}
/* El acordeón de clientes dentro del flyout: cabeceras de sección con el mismo look
   que el sidebar (el estilo .sec original está limitado a .side). */
.rail-fly .cli-section{padding:0}
.rail-fly .cli-section .sec{font-size:10.5px;text-transform:uppercase;letter-spacing:.7px;color:var(--label);font-weight:700;padding:13px 14px 4px}
.rail-fly .cli-acc{padding:0 10px}
/* El acordeón cuelga dentro de <aside class="rail">, así que .rail a (columna,
   centrado, 44px) y .rail a span (texto centrado a 8.5px) le filtraban su estilo y
   rompían las carpetas (nombres centrados, pequeños y descolgados). Se resetea. */
.rail-fly .cli-lnk,.rail-fly .cli-lists a{flex-direction:row;justify-content:flex-start;width:auto;height:auto;gap:8px}
.rail-fly .cli-h .nm,.rail-fly .cli-lists a .nm2{text-align:left;width:auto;font-size:inherit;font-weight:inherit;letter-spacing:0;line-height:1.3}
.rail-fly .cli-h .fld,.rail-fly .cli-lists a .li{width:auto;font-size:0}
/* Fondo/hover/color: el raíl pinta hover OSCURO (.rail a:hover → negro) y su color
   gris propio. En el acordeón queremos el hover CLARO del menú, no el del raíl. */
.rail-fly .cli-lnk,.rail-fly .cli-lnk:hover{background:none;color:#4c515b;transform:none;box-shadow:none}
.rail-fly .cli-grp.open .cli-h .cli-lnk{color:var(--ink)}
.rail-fly .cli-lists a{background:none;color:#5c616b;transform:none;box-shadow:none}
.rail-fly .cli-lists a:hover{background:var(--soft);color:var(--ink);transform:translateX(2px)}
.rail-fly .cli-lists a.on{background:var(--soft);color:var(--ink)}
.rail-fly .cli-h .cx:hover{background:#e6e7ea;color:#5a5f68;transform:none}
.rail-fly .rf-nav a .rf-cnt{margin-left:auto;font-size:11px;background:#feecec;color:#e5484d;border-radius:99px;padding:0 8px;font-weight:700}
.rail-fly .rf-fold{font-weight:600;color:#3c4149}
.rail-fly .rf-fold .ic{color:#8a9099}
.rail-fly .rf-sub{margin:1px 0 3px 20px;padding-left:8px;border-left:1px solid var(--line2);display:flex;flex-direction:column;gap:2px}
.rail .rsp{flex:1}
.rail .rbell,.rail .rgear{width:44px;height:44px;border-radius:13px;display:flex;align-items:center;justify-content:center;color:#7e838d;position:relative;margin-bottom:6px}
.rail .rbell:hover,.rail .rgear:hover{background:#1e2024;color:#fff}
.rail .rbell.on,.rail .rgear.on{background:#2a2c31;color:#fff}
.rail .rgear{margin-bottom:10px}
.rail .rbell .rbadge{position:absolute;top:5px;right:5px;width:18px;height:18px;min-width:18px;padding:0;border-radius:50%;background:#ef4444;color:#fff;font-size:8.5px;font-weight:700;line-height:1;display:flex;align-items:center;justify-content:center;border:2px solid #0f1012;box-sizing:border-box}
.rail .rav{width:32px;height:32px;border-radius:50%;background:#2a2c31;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;border:1px solid #34363c}
/* barra clara */
.side{width:240px;flex:none;background:#fff;border-right:1px solid var(--line);display:flex;flex-direction:column;height:100vh;position:sticky;top:0;overflow:auto}
/* Páginas independientes (Reuniones, Calendario, Chat, IA, Soporte): a pantalla completa, sin menú lateral. */
.side.side-hidden{display:none}
body.side-collapse .side.side-hidden{display:none}
.side .sh{padding:18px 20px 6px;font-size:15.5px;font-weight:650;letter-spacing:-.2px;color:var(--ink-strong)}
.side .sec{font-size:10.5px;text-transform:uppercase;letter-spacing:.7px;color:var(--label);font-weight:700;padding:22px 20px 9px}
.side .nav{display:flex;flex-direction:column;padding:4px 10px;gap:8px}
.side .nav a{display:flex;align-items:center;gap:13px;padding:12px 13px;border-radius:11px;color:#5a5f68;font-size:14px;font-weight:500}
.side .nav a .ic{width:18px;height:18px;color:var(--label);flex:none;transition:color .16s ease}
.side .nav a .cnt{margin-left:auto;font-size:11px;background:#feecec;color:#e5484d;border-radius:99px;padding:0 8px;font-weight:700}
.side .nav a:hover{background:var(--soft);transform:translateX(2px)}
.side .nav a:hover .ic{color:var(--ink)}
.side .nav a.on{background:var(--soft);color:var(--ink);font-weight:600}
.side .nav a.on .ic{color:var(--ink)}
.side .portal{margin:8px 12px 4px;background:var(--soft);border:1px solid var(--line);border-radius:14px;padding:12px 14px}
.side .portal .t{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:800}
.side .portal p{font-size:11.5px;color:var(--muted);margin:4px 0 9px;line-height:1.45}
.side .portal a{display:flex;align-items:center;justify-content:center;gap:6px;background:#0f1012;color:#fff;border-radius:9px;padding:9px;font-weight:700;font-size:12px;transition:transform .16s ease,box-shadow .18s ease}
.side .portal a:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.14)}
.side .portal button{display:block;width:100%;background:#fff;border:1px solid var(--line);border-radius:9px;padding:8px;font-size:11.5px;color:var(--ink);cursor:pointer;margin-top:6px;font-weight:600}
.side .portal button:hover{background:#fafafa;border-color:#dcdcde}
/* El bloque de usuario queda FIJO abajo: la barra scrollea por dentro (overflow
   en .side) y este bloque, sticky al fondo, no se mueve por muchos elementos que
   haya en el menú. Antes bajaba con el resto y con muchas listas desaparecía. */
.side .foot{position:sticky;bottom:0;z-index:2;margin-top:auto;background:var(--bg);border-top:1px solid var(--line);padding:10px 12px}
/* Degradado + desenfoque por encima del bloque fijo: el contenido que scrollea se
   difumina al pasar por debajo, en vez de cortarse de golpe contra el borde. */
.side .foot::before{content:"";position:absolute;left:0;right:0;bottom:100%;height:26px;pointer-events:none;
  background:linear-gradient(to top, var(--bg) 22%, rgba(255,255,255,0));
  -webkit-mask-image:linear-gradient(to top, #000, transparent);mask-image:linear-gradient(to top, #000, transparent);
  -webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px)}
.side .foot .urow{display:flex;align-items:center;gap:2px}
.side .foot .u{display:flex;align-items:center;gap:10px;padding:7px 8px;border-radius:10px;cursor:pointer;flex:1;min-width:0;text-decoration:none}
.side .foot .u:hover{background:var(--soft)}
.side .foot .u .ui{flex:1;min-width:0}
.side .foot .gear{color:var(--label);display:flex;flex:none;padding:8px;border-radius:9px;transition:color .15s ease,background .15s ease}
.side .foot .gear:hover{color:var(--ink);background:var(--soft)}
.side .foot .out{padding-left:8px}
.side .foot .av{width:32px;height:32px;border-radius:50%;background:#0f1012;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px}
.side .foot b{font-size:13px;display:block}.side .foot span{font-size:11px;color:var(--muted)}
  .side .foot .out{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);margin-top:9px}.side .foot .out:hover{color:var(--ink)}
  /* El cierre de sesión es un formulario POST, no un enlace, para que no se
     pueda cerrar la sesión de otra persona desde otra web. :where() no aporta
     especificidad, así que estas reglas no pisan el aspecto de arriba. */
  form.salir{display:inline}
  form.salir :where(button){border:0;background-color:transparent;font:inherit;color:inherit;cursor:pointer;text-decoration:none}
.side .foot .out svg{width:15px;height:15px}
/* main */
.main{flex:1;min-width:0;display:flex;flex-direction:column}
.erp-top{background:#fff;border-bottom:1px solid var(--line);padding:0 24px;display:flex;align-items:center;gap:10px;height:56px;position:sticky;top:0;z-index:10}
.erp-top .sp{flex:1}
.erp-top .tbtn{border:1px solid var(--line);background:#fff;border-radius:9px;padding:7px 13px;font-size:12.5px;font-weight:600;color:#4c515b;display:inline-flex;gap:7px;align-items:center}
.erp-top .tbtn svg{width:15px;height:15px}
.erp-top .tbtn:hover{background:var(--soft);border-color:#dcdcde;transform:translateY(-1px)}
/* Buscador global: la caja de la barra superior abre la paleta (Ctrl+K). */
.erp-top .tsearch{display:inline-flex;align-items:center;gap:9px;border:1px solid var(--line);background:var(--soft);border-radius:10px;padding:8px 12px;font-family:inherit;font-size:12.5px;color:var(--muted);cursor:pointer;min-width:330px;text-align:left}
.erp-top .tsearch svg{width:15px;height:15px;color:var(--muted);flex:none}
.erp-top .tsearch span{flex:1}
.erp-top .tsearch kbd{font-family:inherit;font-size:10.5px;font-weight:700;color:var(--muted);background:#fff;border:1px solid var(--line);border-radius:6px;padding:2px 6px}
.erp-top .tsearch:hover{background:#fff;border-color:#dcdcde;color:var(--ink)}
@media(max-width:860px){.erp-top .tsearch{min-width:0}.erp-top .tsearch span,.erp-top .tsearch kbd{display:none}}
#gsOv{position:fixed;inset:0;background:rgba(16,18,22,.34);backdrop-filter:blur(2px);z-index:400;display:none;align-items:flex-start;justify-content:center;padding-top:11vh}
#gsOv.on{display:flex}
#gsOv .gs{width:min(620px,92vw);background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 24px 60px rgba(16,18,22,.22);overflow:hidden;animation:gsIn .16s cubic-bezier(.2,.7,.3,1)}
@keyframes gsIn{from{opacity:0;transform:translateY(-8px) scale(.985)}to{opacity:1;transform:none}}
#gsOv .gs-in{display:flex;align-items:center;gap:11px;padding:14px 18px;border-bottom:1px solid var(--line)}
#gsOv .gs-in svg{color:var(--muted);flex:none}
#gsOv .gs-in input{flex:1;border:none;outline:none;font-family:inherit;font-size:16px;color:var(--ink-strong);background:none}
#gsOv .gs-in input:focus{box-shadow:none;border-color:transparent}
#gsOv .gs-in .esc{font-size:10.5px;font-weight:700;color:var(--muted);background:var(--soft);border:1px solid var(--line);border-radius:6px;padding:2px 6px}
#gsOv .gs-res{max-height:56vh;overflow:auto;padding:6px}
#gsOv .gs-sec{font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;padding:10px 12px 5px}
/* Fila de resultado con aire: el icono se alinea arriba para que, cuando el
   subtítulo ocupe dos líneas, no quede el icono flotando en medio. */
#gsOv .gs-r{display:flex;align-items:flex-start;gap:12px;padding:11px 12px;border-radius:11px;text-decoration:none;cursor:pointer}
#gsOv .gs-r .ic{width:32px;height:32px;border-radius:9px;background:var(--soft);color:var(--ink-strong);display:flex;align-items:center;justify-content:center;flex:none}
#gsOv .gs-r .m{min-width:0;flex:1}
#gsOv .gs-r .t{font-size:13.5px;font-weight:600;color:var(--ink-strong);line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* El subtítulo respira (separado del título) y puede ocupar hasta DOS líneas
   cuando hay más que contar, en vez de cortarse en seco con puntos suspensivos. */
#gsOv .gs-r .s{font-size:11.5px;color:var(--muted);line-height:1.45;margin-top:4px;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
#gsOv .gs-r.sel{background:var(--accent-soft)}
#gsOv .gs-r.sel .ic{background:#fff}
#gsOv .gs-msg{padding:26px 16px;text-align:center;color:var(--muted);font-size:13px}
#gsOv .gs-foot{border-top:1px solid var(--line);padding:9px 16px;display:flex;gap:14px;font-size:11.5px;color:var(--muted)}
#gsOv .gs-foot b{font-weight:700;color:var(--ink)}
.erp-wrap{padding:36px 52px 80px;max-width:none;width:100%;animation:fadeUp .34s cubic-bezier(.2,.7,.3,1)}
@media(max-width:700px){.erp-wrap{padding:22px 18px 60px}}
/* KPIs / panels */
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:24px}
.kpi{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px 24px}
.kpi:hover{border-color:#e2e2e4;box-shadow:0 8px 26px rgba(0,0,0,.05);transform:translateY(-2px)}
.kpi .h{font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:600;display:flex;justify-content:space-between;align-items:center}
.kpi .h svg{width:17px;height:17px;color:var(--label)}
.kpi .big{font-size:31px;font-weight:640;margin-top:12px;line-height:1;letter-spacing:-.7px;color:var(--ink-strong)}
.kpi .sub{font-size:12px;color:var(--muted);margin-top:6px}
/* Una tarjeta de KPI puede ser un enlace: un número siempre invita a pulsarlo
   para ver de dónde sale, así que lleva al listado que lo produce. */
a.kpi{display:block;text-decoration:none;color:inherit}
.grid2{display:grid;grid-template-columns:1.5fr 1fr;gap:20px}
.panel{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px 24px;margin-bottom:20px}
.panel h3{font-size:16px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.panel h3 svg{width:17px;height:17px;color:var(--muted)}
.soonbox{background:#fff;border:1px dashed #d4d8de;border-radius:18px;padding:56px 26px;text-align:center;color:var(--muted)}
/* contenido de las páginas */
h1{font-size:26px;margin-bottom:8px;letter-spacing:-.5px;font-weight:600;color:var(--ink-strong)}.lead{color:var(--muted);margin-bottom:26px;font-weight:400;line-height:1.55;font-size:15px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:none;padding:24px 26px;margin-bottom:20px}
.btn{display:inline-flex;align-items:center;gap:7px;background:var(--accent);color:#fff;border:none;border-radius:10px;padding:10px 16px;font-size:13.5px;font-weight:600;cursor:pointer;text-decoration:none}
.btn:hover{transform:translateY(-1px);box-shadow:0 7px 18px rgba(0,0,0,.16)}
.btn:active{transform:translateY(0) scale(.98);box-shadow:none}
.btn.ghost{background:#fff;color:var(--ink);border:1px solid var(--line)}
.btn.ghost:hover{background:var(--soft);border-color:#dcdcde;box-shadow:0 5px 14px rgba(0,0,0,.05)}
.btn.danger{background:#fff;color:#b23b30;border:1px solid #ecd4d1}
.btn.danger:hover{background:#fbf3f2;border-color:#e2bfbb}
.btn.sm{padding:7px 12px;font-size:12.5px;border-radius:9px}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);padding:11px 12px;border-bottom:1px solid var(--line)}
td{padding:15px 12px;border-bottom:1px solid var(--line);font-size:13.5px}
tr:last-child td{border-bottom:none}
.tag{font-size:11px;font-weight:600;padding:3px 10px;border-radius:7px;background:var(--soft);color:var(--muted)}
/* Estado vacío estándar (helper erp_empty): icono grande + título + texto + acción */
.erp-empty{text-align:center;background:#fff;border:1px solid var(--line);border-radius:16px;padding:40px 24px;box-shadow:0 1px 2px rgba(16,19,24,.03)}
.erp-empty .ei{width:60px;height:60px;border-radius:18px;background:var(--soft);color:var(--label);display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
.erp-empty .ei svg{width:28px;height:28px}
.erp-empty b{font-size:18px;font-weight:600;color:var(--ink-strong);display:block;letter-spacing:-.3px}
.erp-empty p{font-size:13.5px;color:var(--muted);line-height:1.6;max-width:400px;margin:7px auto 0}
.erp-empty .ea{margin-top:16px;display:flex;justify-content:center;gap:10px;flex-wrap:wrap}
.tag.on{background:#ebebec;color:#3a3d42}.tag.off{background:#f1f1f2;color:#6b7076}
label{display:block;font-size:12.5px;color:var(--muted);font-weight:600;margin:18px 0 7px}
input[type=text],input[type=password],input[type=number],input[type=url],input[type=date],textarea,select:not(.plain){width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 13px;font-size:14px;font-family:inherit;color:var(--ink);background:#fff;outline:none}
/* Este era el motivo real de que «faltara el CSS» en unos formularios sí y en otros
   no. La regla de arriba va por tipo (input[type=text], input[type=password]…), pero
   en el proyecto hay casi cien campos escritos como <input name="x"> sin poner el
   type: son campos de texto para el navegador, pero para el CSS no son
   input[type=text] y se quedaban sin borde, sin ancho y sin relleno. Pasaba en
   Credenciales, en la ficha del CRM, en el portal del cliente y en varios modales.
   Aquí se recogen esos y los tipos que también faltaban (email, teléfono, búsqueda,
   hora, mes).

   Va dentro de :where() a propósito: así toda la regla pesa lo mismo que un simple
   `input` y cualquier clase de la propia página la sigue ganando. Sin eso, las
   celdas de edición en línea del CRM (.cell, .ed), que precisamente no llevan type,
   pasarían a heredar la caja grande de formulario y reventarían las tablas. */
input:where(:not([type]),[type=email],[type=tel],[type=search],[type=time],[type=month]){width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 13px;font-size:14px;font-family:inherit;color:var(--ink);background:#fff;outline:none}
input:focus,textarea:focus,select:not(.plain):focus{border-color:var(--ring)}
/* ---- Foco unificado y limpio (minimal) ----------------------------------
   Sin el outline por defecto del navegador (ese "recuadro" ámbar que salía al
   pinchar). Al pinchar con el ratón NO aparece ningún aro; el anillo limpio
   solo sale al navegar con TECLADO (:focus-visible), como debe ser. */
:focus{outline:none}
:focus-visible{outline:2px solid var(--ring);outline-offset:2px}
input:focus-visible,textarea:focus-visible,select:focus-visible{outline:none}
.cs-trig:focus-visible{outline:2px solid var(--ring);outline-offset:1px}
/* Campos que SON texto (edición en línea: nombres/empresa del CRM, etc.):
   nunca se "encajonan". Al editar, solo un fondo suave — sin borde ni aro. */
:is(.ed,.cell) input:focus,:is(.ed,.cell) select:focus,input.cell:focus,textarea.cell:focus,.ed:focus,.cell:focus,.pf-name:focus{box-shadow:none!important;border-color:transparent!important;background-color:var(--soft)!important}
select:not(.plain){-webkit-appearance:none;-moz-appearance:none;appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23656a72' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'><path d='M6 9l6 6 6-6'/></svg>");background-repeat:no-repeat;background-position:right 11px center;padding-right:32px}
textarea{min-height:70px;resize:vertical}
.row{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.sec-t{font-size:12px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);font-weight:650;margin:32px 0 12px}
.rep-row{display:flex;gap:8px;align-items:flex-start;margin-bottom:8px}
.rep-row > *{flex:1}
<?php /* .del y .addbtn llevaban dentro los glifos «✕» y «+» escritos a pelo, así
         que bastaba con centrar texto. Ahora llevan iconos SVG como el resto del
         ERP y necesitan ser flex para que icono y etiqueta queden alineados. */ ?>
.rep-row .del{flex:none;display:inline-flex;align-items:center;justify-content:center;background:#fff;border:1px solid #f0caca;color:#c0392b;border-radius:9px;padding:0 12px;height:40px;cursor:pointer;font-size:18px;line-height:1}
.rep-row .del:hover{background:#fdf5f4}
.addbtn{display:flex;align-items:center;justify-content:center;gap:7px;background:#fff;border:1px dashed #d4d8de;border-radius:10px;padding:10px;width:100%;cursor:pointer;color:var(--accent);font-weight:600;font-size:13.5px;font-family:inherit;margin-top:4px}
.addbtn:hover{border-color:var(--label);background:var(--soft)}
<?php /* CHIPS. Había seis implementaciones distintas de lo mismo repartidas por el
         ERP —.hub-chip en la ficha de cliente, .cm-chip en el CRM, .ng-chip en
         negocio, .chip en credenciales, .nt-chip en notificaciones— cada una con
         su tamaño, su gris y su radio. Son tres cosas, no seis:
           .chip        etiqueta pequeña (un servicio, un tipo, un dato suelto)
           .chip.data   dato con su valor dentro, en píldora («Fecha: 04/07/26»)
           .chip.pick   filtro que se pulsa y se queda marcado (.on)
           .chip.act    chip que hace algo al pulsarlo (un botón discreto)
         Las de la factura (.inv-chip, .pv-chip) NO se tocan: son parte del
         documento que ve el cliente, no del panel. */ ?>
.chips{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.chip{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;background:#eef0f3;color:#5c616b;border-radius:6px;padding:3px 8px;line-height:1.4}
.chip.data{background:#fff;border:1px solid var(--line);border-radius:99px;padding:5px 13px;font-size:12px;color:var(--ink);font-weight:600}
.chip.data b{color:var(--ink-strong)}
.chip.pick{background:#fff;border:1px solid var(--line);border-radius:99px;padding:6px 14px;font-size:12.5px;color:var(--muted);cursor:pointer;font-weight:600}
.chip.pick:hover{background:var(--soft);border-color:#dcdcde}
.chip.pick.on{background:#111318;color:#fff;border-color:#111318}
.chip.act{background:#fff;border:1px solid var(--line);border-radius:9px;padding:7px 12px;font-size:12.5px;color:var(--ink);cursor:pointer;font-weight:600}
.chip.act:hover{background:var(--soft);border-color:#dcdcde}
.muted{color:var(--muted);font-size:13px;line-height:1.55}
.check{display:flex;align-items:center;gap:9px;margin-top:14px}.check input{width:auto}
/* ---- Rejilla de formulario ----
   Estaba dentro de lib/ajustes_nav.php y solo servía en Ajustes. Es maquetación
   de formulario, la necesita cualquier pantalla con campos (docs/05 §11).

   Doce columnas: por defecto cada campo ocupa media fila, y con .c2 … .c12 se le
   da el ancho que de verdad necesita. Un NIF o un IVA no tienen por qué medir lo
   mismo que una dirección — eso es lo que hacía que los formularios de este ERP
   se vieran descolocados aunque estuvieran alineados. */
/* Rejilla de 12 columnas. Por defecto cada campo ocupa media fila (6/12), que es
   lo que había antes; pero ahora un NIF, un IVA o un teléfono pueden ocupar lo
   que de verdad necesitan en vez de estirarse media pantalla. Un campo de 4
   caracteres con 500px de ancho es lo que hacía que estos formularios se vieran
   descolocados por mucho que estuvieran «alineados». */
.set-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:18px 22px;align-items:start}
.set-grid > *{grid-column:span 6}
.set-grid .c2{grid-column:span 2}   /* IVA, IRPF, un prefijo    */
.set-grid .c3{grid-column:span 3}   /* NIF, teléfono, un código */
.set-grid .c4{grid-column:span 4}   /* nombre corto, ciudad     */
.set-grid .c5{grid-column:span 5}
.set-grid .c6{grid-column:span 6}
.set-grid .c8{grid-column:span 8}   /* email, web               */
.set-grid .c12,.set-grid .full{grid-column:1 / -1}   /* dirección, IBAN, URL */
.set-grid.one > *{grid-column:1 / -1}
.set-f label{font-size:12px;color:var(--muted);font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:7px}
/* Una etiqueta puede llevar un icono (ic) o un logo de marca (svc_logo) delante.
   El icono va en gris tenue; el logo, a su tamaño y color (es una marca, no un
   pictograma). Global para que valga en todos los formularios de Ajustes. */
.set-f label svg{width:14px;height:14px;color:var(--label);flex:none}
.set-f label .glogo{width:17px;height:17px;flex:none;display:inline-flex;align-items:center}
.set-f label .glogo svg{width:17px;height:17px;color:inherit}
.set-f input,.set-f select,.set-f textarea{width:100%}
.set-f .hint{font-size:11.5px;color:var(--label);margin-top:7px;line-height:1.5}
/* Los campos con unidad (%, €) enseñan la unidad dentro, no en la etiqueta: así
   la etiqueta dice qué es y el campo dice en qué se mide. */
.set-f.u{position:relative}
.set-f.u input{padding-right:30px}
.set-f.u:after{content:attr(data-u);position:absolute;right:11px;bottom:9px;font-size:12.5px;color:var(--muted);pointer-events:none}
/* Separador de bloques DENTRO de una tarjeta: mismo aspecto que .sec-t, más una
   línea que llega hasta el borde. Va aquí y no en lib/ajustes_nav.php porque lo
   usan pantallas que no pasan por Ajustes (team-edit.php, edit.php). */
.set-zone{font-size:12px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);font-weight:650;margin:30px 0 14px;
  display:flex;align-items:center;gap:10px}
.set-zone:after{content:"";flex:1;height:1px;background:var(--line2)}

/* ---- Interruptor .sw ----
   Estaba escrito dentro de settings.php como .set-sw y solo servía para las
   reglas automáticas. Es un control, no un adorno de esa pantalla: vive aquí y
   lo usa quien lo necesite (docs/05 §11). Uso:
     <label class="sw"><input type="checkbox" …><span class="tr"></span></label> */
.sw{position:relative;width:40px;height:23px;flex:none;cursor:pointer;display:inline-block;vertical-align:middle}
.sw input{opacity:0;width:0;height:0;position:absolute}
.sw .tr{position:absolute;inset:0;background:#d9dbe0;border-radius:99px;transition:background .18s cubic-bezier(.2,.7,.3,1)}
.sw .tr:before{content:"";position:absolute;width:17px;height:17px;border-radius:50%;background:#fff;left:3px;top:3px;
  box-shadow:0 1px 3px rgba(0,0,0,.22);transition:transform .18s cubic-bezier(.2,.7,.3,1),width .12s ease}
.sw input:checked + .tr{background:var(--accent)}
.sw input:checked + .tr:before{transform:translateX(17px)}
.sw:active .tr:before{width:20px}                     /* se estira al pulsar */
.sw input:focus-visible + .tr{box-shadow:0 0 0 3px var(--accent-soft)}
.sw input:disabled + .tr{opacity:.5;cursor:default}
.sw.sw-guardando{opacity:.5;pointer-events:none}
/* Verde solo donde el interruptor significa «esto está funcionando» (las reglas
   automáticas), no donde significa «tiene permiso». */
.sw.ok input:checked + .tr{background:#12a150}

/* ---- Segmentado .seg ----
   El selector de pestañas del tablero de Tareas (.ws-tabs), aquí como clase
   compartida: caja blanca con borde, y el activo en negro. Sirve para elegir
   entre pocas opciones sin gastar una pantalla en cada una.
     <div class="seg"><button class="on">Uno</button><button>Dos</button></div> */
.seg{display:inline-flex;gap:4px;background:#fff;border:1px solid var(--line);border-radius:11px;padding:4px;flex-wrap:wrap}
.seg > *{padding:7px 14px;border-radius:8px;font-size:13.5px;font-weight:600;color:#6b7280;border:none;background:none;
  cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:7px;text-decoration:none;
  transition:background .14s ease,color .14s ease}
.seg > *:hover:not(.on){background:var(--soft);color:var(--ink)}
.seg > *.on{background:#111318;color:#fff}
.seg > * svg{width:15px;height:15px}
.seg .cnt{font-size:11px;font-weight:700;background:var(--soft);color:var(--muted);border-radius:99px;padding:1px 7px}
.seg > *.on .cnt{background:rgba(255,255,255,.18);color:#fff}

/* ---- Fila de «añadir» en línea ----
   El patrón del tablero de Tareas (.ck-add): un «+» y un campo sin bordes al pie
   de una lista, en vez de un botón con borde discontinuo que parece un hueco. */
.inline-add{display:flex;align-items:center;gap:10px;padding:11px 18px;color:var(--label);
  border:none;background:none;width:100%;font-family:inherit;font-size:13.5px;text-align:left;cursor:pointer;
  transition:background .14s ease,color .14s ease}
.inline-add:hover{background:#fafbfc;color:var(--ink)}
.inline-add .plus{display:flex;color:var(--label);flex:none;transition:color .14s ease,transform .18s cubic-bezier(.2,.7,.3,1)}
.inline-add:hover .plus{color:var(--ink);transform:rotate(90deg)}
.flex{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.sp{flex:1}
/* ---- Piezas compartidas ----------------------------------------------------
   Estas clases estaban escritas dentro de una página concreta y usadas desde
   otras: el desplegable de filtro de Soporte salía sin ningún estilo porque su
   CSS vivía en Tareas, y los avisos verdes y la miga de pan se repetían con
   «style=» a mano en cada sitio para disimularlo. Ahora se escriben una sola vez
   aquí, que es lo que carga TODO el ERP, y así una página nueva las hereda sin
   tener que acordarse de copiarlas. */
.mini-sel{width:auto;border:1px solid var(--line);border-radius:9px;padding:7px 11px;font-size:12.5px;background:#fff;color:var(--ink);font-weight:500;max-width:185px;cursor:pointer}
.mini-sel:hover{border-color:var(--accent)}
.icon-btn{border:none;background:none;padding:5px;cursor:pointer;display:inline-flex;color:var(--label);border-radius:6px}
.icon-btn:hover{background:#eef0f3;color:#6b7280}
.icon-btn svg{width:15px;height:15px}
.ok-note{background:#eafaf0;border:1px solid #cfe9d6;color:#12854a;border-radius:10px;padding:10px 14px;font-size:13px;margin-bottom:16px}
.err-note{background:#fbeeee;border:1px solid #f0caca;color:#a32d2d;border-radius:10px;padding:10px 14px;font-size:13px;margin-bottom:16px}
.tk-crumb{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--muted);margin-bottom:16px}
/* El enlace es flex para que un icono (la flecha de «volver») quede centrado con
   el texto en vez de caer sobre la línea base y verse descuadrado. */
.tk-crumb a{color:var(--muted);display:inline-flex;align-items:center;gap:5px;text-decoration:none}
.tk-crumb a:hover{color:var(--ink)}
.tk-crumb a svg{flex:none}
.tk-crumb .sep{color:#d4d7dd}
.tk-crumb > span:last-child{color:var(--ink);font-weight:600}
.tl-tabs{display:flex;gap:2px;flex-wrap:wrap;margin-bottom:14px;align-items:center;border-bottom:1px solid var(--line);padding-bottom:0}
/* Botón de acceso rápido. Estaba escrito con seis «style=» repetidos en cada
   enlace del panel de accesos del dashboard; así se define una vez y cualquier
   página puede poner una fila de atajos con el mismo aspecto. */
.qbtn{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:10px;padding:9px 15px;font-weight:600;font-size:13px;color:var(--ink);background:#fff}
.qbtn:hover{border-color:var(--accent);color:var(--accent)}
.qbtn svg{width:16px;height:16px;color:var(--muted)}
.qbtn:hover svg{color:var(--accent)}
/* acordeón de clientes en la barra */
.side .sec{position:relative}
.side .sec .secadd{position:absolute;right:13px;top:12px;color:var(--label);font-weight:700;font-size:16px;line-height:1;padding:1px 6px;border-radius:7px}
.side .sec .secadd:hover{background:#f0f1f4;color:var(--accent)}
.cli-acc{padding:0 10px;overflow:hidden;max-height:3000px;opacity:1}
.cli-grp{display:flex;flex-direction:column;margin-bottom:3px}
.cli-h{display:flex;align-items:center;border-radius:9px}
.cli-h:hover{background:#f6f7f9}
.cli-h .cx{background:none;border:none;cursor:pointer;color:var(--label);padding:5px;margin:2px 0 2px 5px;border-radius:6px;display:flex;transition:.15s}
.cli-h .cx:hover{background:#e6e7ea;color:#5a5f68}
.cli-grp.open .cli-h .cx{transform:rotate(90deg)}
.cli-h .cli-lnk{display:flex;align-items:center;gap:8px;padding:8px 8px 8px 1px;flex:1;color:#4c515b;font-size:13.5px;font-weight:600;min-width:0}
.cli-h .fld{display:flex;color:var(--label);flex:none}
.cli-h .nm{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cli-grp.open .cli-h .cli-lnk{color:var(--ink)}
.cli-lists{display:flex;flex-direction:column;padding:1px 0 5px 24px;gap:2px;overflow:hidden;max-height:0;opacity:0}
.cli-grp.open .cli-lists{opacity:1}
/* Tras terminar de abrir, se quita el recorte para que ARRASTRAR listas/carpetas
   funcione y se vea el indicador de sitio (el overflow:hidden solo hace falta al animar). */
.cli-lists.done{overflow:visible;max-height:none}
.cli-acc.done{overflow:visible} /* la sección conserva su max-height numérica (animable); .done solo quita el recorte para arrastrar */
.cli-lists a{display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:8px;color:#5c616b;font-size:12.8px;font-weight:500}
.cli-lists a:hover{background:var(--soft);transform:translateX(2px)}
.cli-lists a.on{background:var(--soft);color:var(--ink);font-weight:600}
.cli-lists a .li{display:flex;color:var(--label);flex:none}
.cli-lists a.on .li{color:var(--accent)}
.cli-lists a .nm2{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cli-lists a .cnt{margin-left:auto;font-size:10px;background:#f0f1f4;color:#5c626c;border-radius:99px;padding:0 7px;font-weight:700;flex:none}
.cli-lists a.add{color:var(--label);opacity:.6;font-weight:500}
.cli-lists a.add .li{color:var(--label)}
.cli-lists a.add:hover{opacity:1;color:var(--accent);background:var(--soft)}
.cli-lists a.add:hover .li{color:var(--accent)}
/* Cabecera de sección de clientes (activos/no activos) — global, para que el
   acordeón se vea y se pliegue igual en el sidebar y en el desplegable del raíl. */
.cli-section .sec{display:flex;align-items:center;gap:5px;cursor:pointer;user-select:none}
.cli-section .sec .stog{border:none;background:none;color:var(--muted);cursor:pointer;padding:2px;display:flex;transition:transform .15s ease}
.cli-section.collapsed .sec .stog{transform:rotate(-90deg)}
.cli-section.collapsed .cli-acc{max-height:0;opacity:0}
.cli-dot{width:7px;height:7px;border-radius:50%;flex:none}
.cli-dot.ok{background:#12a150}
.cli-dot.off{background:#f59e0b}
.list-drag{cursor:grab}.list-drag:active{cursor:grabbing}
.drop-above{box-shadow:inset 0 3px 0 #3b82f6}
.drop-below{box-shadow:inset 0 -3px 0 #3b82f6}
.drop-left{box-shadow:inset 3px 0 0 #3b82f6}
.drop-right{box-shadow:inset -3px 0 0 #3b82f6}
.folder-drag{cursor:grab}.folder-drag:active{cursor:grabbing}
/* Filas reordenables de cualquier página. No se les pone cursor:grab en toda la
   fila porque dentro hay enlaces y campos que se escriben: el asa es la manija de
   la izquierda, que solo aparece al pasar el ratón por encima, como en ClickUp. */
.row-drag:active{cursor:grabbing}
.row-grip{display:inline-flex;align-items:center;justify-content:center;width:14px;color:#c8ccd3;opacity:0;transition:opacity .12s ease;cursor:grab;flex:none;user-select:none}
.row-drag:hover .row-grip{opacity:1}
.row-grip:active{cursor:grabbing}
.row-grip svg{width:12px;height:12px}
.cli-empty{padding:8px 12px;color:var(--muted);font-size:12.5px}
/* menú contextual (carpetas) y mini-menú añadir lista */
.ctxmenu,.addlm{position:fixed;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 46px rgba(0,0,0,.17);padding:5px;min-width:184px;z-index:200;display:none}
.ctxmenu.on,.addlm.on{display:block;animation:pop .15s ease}
.ctxmenu a,.addlm button{display:block;width:100%;text-align:left;padding:8px 12px;border-radius:8px;color:#4c515b;font-weight:500;border:none;background:none;cursor:pointer;font-family:inherit;font-size:13px;text-decoration:none}
.ctxmenu a:hover,.addlm button:hover{background:var(--soft);color:var(--ink)}
.ctxmenu a.danger{color:#c0392b}.ctxmenu a.danger:hover{background:#fde8e8}
.ctxmenu .sep{height:1px;background:var(--line);margin:4px 6px}
.addlm .alm-h{font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;padding:7px 10px 5px}
.addlm .alm-opt{display:flex;align-items:center;gap:10px;width:100%;border:none;background:none;cursor:pointer;padding:9px 11px;border-radius:8px;font-size:13px;color:#4c515b;font-weight:500;text-align:left;font-family:inherit}
.addlm .alm-opt:hover{background:var(--soft);color:var(--ink)}
.addlm .alm-opt .almdot{width:9px;height:9px;border-radius:3px;flex:none;display:inline-block}
.addlm .alm-form{display:flex;gap:6px;padding:4px 8px 8px}
.addlm .alm-form input{flex:1;min-width:0;border:1px solid var(--line);border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit;background-image:none}
.addlm .alm-create{border:none;background:var(--accent);color:#fff;border-radius:8px;padding:8px 13px;font-size:12.5px;font-weight:600;cursor:pointer;width:auto}
.addlm .alm-create:hover{filter:brightness(1.12)}
/* barra lateral colapsada (CRM): tira fina que se abre al pasar el ratón */
body.side-collapse .side{position:fixed;left:80px;top:0;height:100vh;width:16px;min-width:16px;overflow:hidden;z-index:45;border-right:1px solid var(--line);transition:width .18s cubic-bezier(.2,.7,.3,1),box-shadow .18s ease}
body.side-collapse .side>*{opacity:0;pointer-events:none;transition:opacity .12s ease}
body.side-collapse .side::after{content:"";position:absolute;left:6px;top:50%;transform:translateY(-50%);width:3px;height:42px;border-radius:3px;background:#d5d5da}
body.side-collapse .side:hover{width:240px;min-width:240px;overflow:auto;box-shadow:16px 0 46px rgba(0,0,0,.13)}
body.side-collapse .side:hover>*{opacity:1;pointer-events:auto}
body.side-collapse .side:hover::after{opacity:0}
body.side-collapse .main{margin-left:16px}
/* Botón de menú para anchos estrechos. Antes, por debajo de 900px se ocultaban el
   raíl y la barra lateral y NO había forma de recuperarlos: no se podía navegar salvo
   con Ctrl+K (defecto P2-15). Ahora el hamburguesa los muestra como panel superpuesto. */
.nav-burger{display:none;border:1px solid var(--line);background:#fff;border-radius:9px;padding:6px 9px;color:#4c515b;cursor:pointer;align-items:center}
.nav-burger:hover{background:var(--soft);border-color:#dcdcde}
#navBackdrop{display:none;position:fixed;inset:0;background:rgba(16,18,22,.42);z-index:55}
/* Tacto: los botones se hunden un pelín al pulsarse (mejora de percepción). */
.btn:active,button.go:active,.tbtn:active,.nav-burger:active{transform:translateY(1px) scale(.985)}
/* Kit de entrada/salida: filas y tarjetas que aparecen o se van con suavidad, en vez
   de saltar de golpe. Se aplican añadiendo la clase por JS tras un cambio parcial. */
@keyframes erpIn{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}
@keyframes erpOut{from{opacity:1;transform:none}to{opacity:0;transform:translateX(10px)}}
.erp-in{animation:erpIn .22s cubic-bezier(.2,.7,.3,1) both}
.erp-out{animation:erpOut .18s ease forwards;pointer-events:none}
/* Escalonado suave cuando aparece un grupo de elementos a la vez. */
.erp-in-stg>*{animation:erpIn .26s cubic-bezier(.2,.7,.3,1) both}
.erp-in-stg>*:nth-child(2){animation-delay:.03s}.erp-in-stg>*:nth-child(3){animation-delay:.06s}
.erp-in-stg>*:nth-child(4){animation-delay:.09s}.erp-in-stg>*:nth-child(5){animation-delay:.12s}
.erp-in-stg>*:nth-child(n+6){animation-delay:.15s}
@media(prefers-reduced-motion:reduce){.erp-in,.erp-out,.erp-in-stg>*{animation:none}}
/* Popups de notificación en tiempo real: se apilan en una pila arriba a la derecha.
   A partir de 3 la pila se hace scroll con un degradado (fade) arriba. */
#notifPops{position:fixed;top:56px;right:6px;z-index:600;width:362px;max-width:calc(100vw - 24px);max-height:264px;overflow-y:auto;overflow-x:hidden;display:flex;flex-direction:column;gap:11px;padding:8px 16px 18px;scrollbar-width:thin;scrollbar-color:#d9dbe0 transparent}
#notifPops::-webkit-scrollbar{width:6px}
#notifPops::-webkit-scrollbar-thumb{background:#d9dbe0;border-radius:8px}
#notifPops.many{-webkit-mask-image:linear-gradient(to bottom,transparent 0,#000 26px);mask-image:linear-gradient(to bottom,transparent 0,#000 26px)}
.notif-pop{position:relative;width:100%;flex:none;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 8px 22px -10px rgba(16,19,24,.30),0 2px 6px -2px rgba(16,19,24,.10);padding:13px 14px;display:flex;gap:11px;cursor:pointer}
.notif-pop:hover{border-color:#dcdcde}
.notif-pop .np-ic{width:34px;height:34px;border-radius:10px;background:#111318;color:#fff;display:flex;align-items:center;justify-content:center;flex:none}
.notif-pop .np-ic svg{width:18px;height:18px}
.notif-pop .np-b{min-width:0;flex:1}
.notif-pop .np-t{font-size:13px;font-weight:650;color:var(--ink-strong);line-height:1.3}
.notif-pop .np-s{font-size:12px;color:var(--muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.notif-pop .np-x{border:none;background:none;color:#c2c6cd;cursor:pointer;padding:2px;flex:none;align-self:flex-start;font-size:13px;line-height:1}
.notif-pop .np-x:hover{color:var(--ink)}
@keyframes bellPulse{0%,100%{transform:scale(1)}35%{transform:scale(1.4)}}
.rbell .rbadge.pulse{animation:bellPulse .55s ease}
/* El panel lateral en pantallas estrechas entra deslizándose, no de golpe. */
@media(max-width:900px){
  body.nav-open .rail,body.nav-open .side{animation:navSlide .2s cubic-bezier(.2,.7,.3,1)}
  @keyframes navSlide{from{transform:translateX(-14px);opacity:.4}to{transform:none;opacity:1}}
}
@media(max-width:900px){.rail,.side{display:none}.kpis{grid-template-columns:1fr 1fr}.grid2{grid-template-columns:1fr}body.side-collapse .main{margin-left:0}
  .nav-burger{display:inline-flex}
  /* ── Menú móvil: UN SOLO cajón = el raíl convertido en lista con etiquetas ──
     El segundo panel (sidebar contextual) y los flyouts de hover NO se muestran
     en móvil: en el teléfono manda la navegación principal, clara y legible. */
  body.nav-open .side{display:none}
  body.nav-open .rail{display:flex;position:fixed;left:0;top:0;height:100vh;z-index:61;
    width:min(84vw,300px);align-items:stretch;gap:3px;margin:0;border-radius:0;overflow-y:auto;
    padding:14px 12px calc(16px + env(safe-area-inset-bottom));box-shadow:0 0 60px rgba(16,19,24,.55)}
  body.nav-open .rail .rlogo{width:40px;height:40px;margin:2px 6px 12px;border-radius:11px;flex:none}
  body.nav-open .rail .rail-item{width:100%}
  body.nav-open .rail .rail-fly{display:none}
  body.nav-open .rail a,
  body.nav-open .rail .rgear,
  body.nav-open .rail .rbell,
  body.nav-open .rail .rlogout{width:100%;height:auto;flex-direction:row;justify-content:flex-start;align-items:center;gap:14px;padding:13px 14px;border-radius:12px;margin:0;color:#c9ccd1}
  body.nav-open .rail a span,
  body.nav-open .rail .rlogout span{font-size:14.5px;font-weight:600;width:auto;text-align:left;letter-spacing:-.1px;line-height:1.2}
  body.nav-open .rail a.on::before{display:none}
  body.nav-open .rail :is(a,.rgear,.rbell,.rlogout) svg{width:21px;height:21px;flex:none}
  body.nav-open .rail :is(.rgear,.rbell)::after{content:attr(title);font-size:14.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  body.nav-open .rail .rbell .rbadge{position:static;margin-left:auto;top:auto;right:auto;border:none}
  body.nav-open .rail .rsp{flex:1 0 10px}
  body.nav-open .rail .rav{width:38px;height:38px;border-radius:50%;margin:10px auto 2px;order:99;font-size:13px;flex:none}
  body.nav-open .rail .rlogout{display:flex;color:#e57373;order:100}
  body.nav-open #navBackdrop{display:block}
}
.rlogout{display:none}
/* ═══════════════ MÓVIL — cimientos globales ═══════════════
   Todo lo COMPARTIDO adaptado a teléfono (barra, rejillas, tarjetas, menús
   flotantes, áreas táctiles). Los diseños propios de cada pantalla se afinan
   aparte. Puntos de ruptura: 900 (navegación), 700 (tablet estrecha), 640 (teléfono). */
@media(max-width:900px){
  .erp-top{padding:0 14px;gap:8px}
}
@media(max-width:700px){
  /* Atajos secundarios de la barra fuera (siguen en el menú); deja hamburguesa, buscador y tema */
  .erp-top a.tbtn{display:none}
  /* Rejillas de contenido y formularios a UNA columna */
  .row,.row3,.set-grid,.grid2{grid-template-columns:1fr}
  /* Menús/popovers flotantes nunca se salen de la pantalla */
  .cs-pop,.ctxmenu,.addlm,#dpCal,.notif-pop{max-width:calc(100vw - 20px)}
  #notifPops{right:0;left:0;width:auto;padding:8px 10px 16px}
}
@media(max-width:640px){
  .kpis{grid-template-columns:1fr 1fr;gap:12px}
  .erp-wrap{padding:20px 14px 64px}
  /* Sin zoom automático de iOS al enfocar un campo: TODOS los campos ≥16px en móvil. */
  input:not([type=checkbox]):not([type=radio]),select,textarea,.cs-trig,[contenteditable]{font-size:16px}
  /* Red de seguridad móvil: nada puede provocar scroll horizontal de PÁGINA
     (elementos ocultos en flujo, popovers-plantilla, etc.). No afecta a los
     scrolls internos (tablas/kanban con su propio overflow) ni a los modales fijos. */
  .main{overflow-x:clip}
  h1{font-size:23px}
  .card,.panel{padding:18px 16px}
  /* Tablas dentro de una tarjeta: scroll horizontal contenido, sin romper la página */
  .card{overflow-x:auto}
  /* Objetivos táctiles cómodos */
  .btn{min-height:42px}
  .chip.pick,.chip.act,.tbtn,.seg>*{min-height:38px}
  /* Casillas con área táctil mayor en móvil (P-06) */
  input[type=checkbox]{width:20px;height:20px}
}
@media(max-width:420px){.kpis{grid-template-columns:1fr}}
/* selects y controles nativos coherentes en todo el ERP (excepto celdas de hoja y editor del portal) */
select:not(.cell):not(.ed):not(.plain){-webkit-appearance:none!important;-moz-appearance:none!important;appearance:none!important;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23656a72' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'><path d='M6 9l6 6 6-6'/></svg>")!important;background-repeat:no-repeat!important;background-position:right 11px center!important;padding-right:34px!important;cursor:pointer}
select:not(.cell):not(.ed):not(.plain):focus{border-color:var(--label)}
input[type=checkbox],input[type=radio]{accent-color:var(--accent);cursor:pointer}
input[type=number]::-webkit-outer-spin-button,input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
input[type=number]{-moz-appearance:textfield}
/* dropdown personalizado (reemplaza el desplegable nativo del navegador) */
.cs-wrap{position:relative;display:inline-block;vertical-align:middle}
.cs-native{position:absolute;left:0;top:0;width:100%;height:100%;opacity:0;margin:0;pointer-events:none}
.cs-trig{display:inline-flex;align-items:center;gap:8px;width:100%;text-align:left;cursor:pointer;background:#fff;border:1px solid var(--line);border-radius:10px;padding:9px 12px;font-size:13.5px;font-family:inherit;color:var(--ink);line-height:1.2}
.cs-trig .cs-lbl{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cs-trig .cs-arw{color:var(--label);display:flex;flex:none;transition:transform .16s ease}
.cs-trig .cs-arw svg{width:15px;height:15px}
.cs-wrap.open .cs-trig{border-color:var(--label);box-shadow:0 0 0 3px rgba(17,19,24,.06)}
.cs-wrap.open .cs-arw{transform:rotate(180deg)}
.cs-trig.dis{opacity:.55;cursor:default}
.cs-pop{position:fixed;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 46px rgba(0,0,0,.17);padding:5px;min-width:150px;max-height:288px;overflow:auto;z-index:700;display:none}
.cs-pop.on{display:block;animation:pop .14s ease}
.cs-opt{padding:8px 11px;border-radius:8px;font-size:13px;color:#4c515b;cursor:pointer;white-space:nowrap}
.cs-opt:hover{background:var(--soft);color:var(--ink)}
.cs-opt.on{background:var(--accent-soft);color:var(--accent);font-weight:600}
/* datepicker global */
.dpick{cursor:pointer}
#dpCal{position:fixed;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 20px 50px rgba(0,0,0,.18);padding:12px;width:252px;z-index:600;display:none;animation:pop .15s ease}
#dpCal.on{display:block}
#dpCal .dp-h{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
#dpCal .dp-h span{font-size:13px;font-weight:700;color:var(--ink-strong)}
#dpCal .dp-nav{border:none;background:none;cursor:pointer;color:#6b7280;font-size:18px;line-height:1;width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center}
#dpCal .dp-nav:hover{background:var(--soft);color:var(--ink)}
#dpCal .dp-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
#dpCal .dp-dow span{font-size:10px;font-weight:700;color:var(--label);text-align:center;padding:2px 0}
#dpCal .dp-d{border:none;background:none;cursor:pointer;font-size:12.5px;color:var(--ink);height:30px;border-radius:8px;font-family:inherit}
#dpCal .dp-d:hover{background:var(--soft)}
#dpCal .dp-d.today{color:var(--accent);font-weight:700}
#dpCal .dp-d.sel{background:var(--accent);color:#fff;font-weight:600}
#dpCal .dp-foot{display:flex;justify-content:space-between;margin-top:9px;padding-top:9px;border-top:1px solid var(--line)}
#dpCal .dp-clr,#dpCal .dp-tod{border:none;background:none;cursor:pointer;font-size:12px;font-weight:600;color:#6b7280;padding:5px 9px;border-radius:8px;font-family:inherit}
#dpCal .dp-clr:hover{background:#fde8e8;color:#c0392b}
#dpCal .dp-tod:hover{background:var(--accent-soft);color:var(--accent)}
/* ---- Interruptor de tema (sol/luna) en la barra superior ---- */
.theme-tgl{padding:7px 9px}
.theme-tgl svg{width:16px;height:16px}
.theme-tgl .ic-sun{display:none}
[data-theme=dark] .theme-tgl .ic-moon{display:none}
[data-theme=dark] .theme-tgl .ic-sun{display:block}
/* Animación al cambiar de tema: el tema nuevo se revela en un círculo que crece
   desde el botón pulsado (View Transitions API; sin soporte, cambia sin animar). */
::view-transition-old(root),::view-transition-new(root){animation:none;mix-blend-mode:normal}
::view-transition-old(root){z-index:0}
::view-transition-new(root){z-index:1;animation:.42s ease-in-out both theme-reveal}
@keyframes theme-reveal{from{clip-path:circle(0% at var(--tx,50%) var(--ty,50%));opacity:.7}to{clip-path:circle(150% at var(--tx,50%) var(--ty,50%));opacity:1}}
/* ═══════════════════ MODO OSCURO (capa aditiva) ═══════════════════
   El modo claro de todo lo de arriba NO se toca: cada regla de aquí solo
   pinta cuando el <html> lleva data-theme="dark" (lo pone el interruptor de
   la barra superior y lo recuerda el navegador). Añadir aquí = imposible
   romper el modo claro. Se usa background-color (no background) para no
   borrar las flechas de los <select> ni los degradados. */
[data-theme=dark]{
  /* Negro neutro estilo shadcn (grises 100% neutros, sin tinte azul) */
  --ink:#e6e6e6;--ink-strong:#fafafa;--muted:#a1a1a1;--label:#9a9a9a;
  --line:#282828;--line2:#1c1c1c;--accent:#e5e5e5;--accent-soft:#262626;
  --bg:#0a0a0a;--card:#161616;--soft:#242424;
  --pop:#1f1f1f;--field:#0f0f0f;--line-strong:#3a3a3a;--ring:#4a4a4a;--ring-soft:rgba(255,255,255,.08);
  --accent-fg:#171717;--rev:#e5e5e5;--rev-fg:#171717;--nav-ink:#b5b5b5;
  --badge-bg:#3a2327;--badge-fg:#f0999a;
  --ok:#54cd8e;--ok-bg:#14251c;--ok-line:#234436;
  --danger:#f08d82;--danger-bg:#2e1d1d;--danger-line:#472a2a;--warn:#efb445;
  color-scheme:dark;
}
[data-theme=dark] body{background-color:var(--bg);color:var(--ink)}
[data-theme=dark] ::selection{background:#3a3d44;color:#f5f7fa}
[data-theme=dark] *{scrollbar-color:rgba(255,255,255,.18) transparent}
[data-theme=dark] ::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15)}
[data-theme=dark] ::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.3)}
/* Superficies (lo que estaba en #fff) */
[data-theme=dark] :is(.side,.rail-fly,.erp-top,.kpi,.panel,.soonbox,.erp-empty,.notif-pop,.side .portal){background-color:var(--card)}
[data-theme=dark] .erp-empty .ei{background-color:var(--soft);color:var(--muted)}
[data-theme=dark] .kpi:hover{border-color:var(--line-strong);box-shadow:0 8px 26px rgba(0,0,0,.4)}
/* Menús / popovers flotantes */
[data-theme=dark] :is(.ctxmenu,.addlm,.cs-pop,#dpCal,#gsOv .gs,.notif-pop){border-color:var(--line)}
[data-theme=dark] :is(.ctxmenu,.addlm,.cs-pop,#dpCal,#gsOv .gs){background-color:var(--pop);box-shadow:0 18px 46px rgba(0,0,0,.5)}
[data-theme=dark] #gsOv .gs-in,[data-theme=dark] #gsOv .gs-foot{border-color:var(--line)}
[data-theme=dark] #gsOv .gs-r .ic{background-color:var(--soft);color:var(--ink-strong)}
[data-theme=dark] #gsOv .gs-r.sel{background-color:var(--accent-soft)}
/* Campos de formulario (background-color para conservar la flecha del select) */
[data-theme=dark] input,[data-theme=dark] textarea,[data-theme=dark] select,[data-theme=dark] .cs-trig,[data-theme=dark] .mini-sel,[data-theme=dark] .addlm .alm-form input{background-color:var(--field);color:var(--ink);border-color:var(--line)}
/* EXCEPCIÓN: los campos de edición en línea (celdas de la tabla del CRM, etc.) NO
   son cajas — son texto. En oscuro deben quedar transparentes como en claro, para
   que la tabla no parezca una rejilla de "recuadros". Solo se resaltan al pasar el
   ratón o al editar (fondo suave). */
[data-theme=dark] :is(.ed,.cell) input,[data-theme=dark] :is(.ed,.cell) select,[data-theme=dark] input.cell,[data-theme=dark] textarea.cell{background-color:transparent!important;border-color:transparent!important;color:var(--ink)}
[data-theme=dark] :is(.ed,.cell) input:hover,[data-theme=dark] :is(.ed,.cell) select:hover{background-color:var(--soft)!important}
[data-theme=dark] :is(input:focus,textarea:focus,select:focus,.cs-wrap.open .cs-trig){border-color:var(--line-strong)}
[data-theme=dark] :is(.erp-top .tsearch kbd,.erp-top .tsearch:hover){background-color:var(--field)}
[data-theme=dark] :is(.erp-top .tbtn,.nav-burger){background-color:var(--card);color:var(--ink);border-color:var(--line)}
[data-theme=dark] :is(.erp-top .tbtn:hover,.nav-burger:hover){background-color:var(--soft);border-color:var(--line-strong)}
/* Texto de menús y enlaces tenues */
[data-theme=dark] :is(.side .nav a,.rail-fly .rf-nav a,.cli-h .cli-lnk,.cli-lists a,.ctxmenu a,.addlm button,.addlm .alm-opt,.cs-opt,.rail-fly .rf-fold,.rail-fly .cli-lnk){color:var(--nav-ink)}
[data-theme=dark] :is(.side .nav a:hover,.rail-fly .rf-nav a:hover,.cli-lists a:hover,.ctxmenu a:hover,.addlm button:hover,.addlm .alm-opt:hover,.cs-opt:hover){background-color:var(--soft);color:var(--ink-strong)}
[data-theme=dark] :is(.side .nav a.on,.rail-fly .rf-nav a.on,.cli-lists a.on){background-color:var(--soft);color:var(--ink-strong)}
[data-theme=dark] :is(.side .sh,.rail-fly .rf-h,.side .foot b){color:var(--ink-strong)}
/* Hovers claros que quedaban en gris casi blanco */
[data-theme=dark] :is(.cli-h:hover,.cli-h .cx:hover,.icon-btn:hover,.inline-add:hover,.side .foot .u:hover,.side .foot .gear:hover){background-color:var(--soft)}
/* Chips */
[data-theme=dark] .chip{background-color:var(--soft);color:var(--ink)}
[data-theme=dark] :is(.chip.data,.chip.pick,.chip.act){background-color:var(--field);color:var(--ink);border-color:var(--line)}
[data-theme=dark] :is(.chip.pick.on,.seg>*.on,.tag.on){background-color:var(--rev);color:var(--rev-fg);border-color:var(--rev)}
[data-theme=dark] .seg{background-color:var(--card);border-color:var(--line)}
[data-theme=dark] .tag{background-color:var(--soft);color:var(--muted)}
/* Botones */
[data-theme=dark] .btn{color:var(--accent-fg)}
[data-theme=dark] .btn.ghost{background-color:var(--card);color:var(--ink);border-color:var(--line)}
[data-theme=dark] .btn.ghost:hover{background-color:var(--soft);border-color:var(--line-strong)}
[data-theme=dark] .btn.danger{background-color:var(--card);color:var(--danger);border-color:var(--danger-line)}
[data-theme=dark] :is(.addbtn,.qbtn,.mini-sel){background-color:var(--field);border-color:var(--line);color:var(--ink)}
[data-theme=dark] :is(.addlm .alm-create,#dpCal .dp-d.sel){color:var(--accent-fg)}
[data-theme=dark] .side .portal a{background-color:var(--accent);color:var(--accent-fg)}
[data-theme=dark] .side .portal button{background-color:var(--field);color:var(--ink);border-color:var(--line)}
[data-theme=dark] .side .portal button:hover{background-color:var(--soft)}
/* Iconos "negro sobre blanco" que hay que invertir */
[data-theme=dark] .notif-pop .np-ic{background-color:var(--rev);color:var(--rev-fg)}
/* Contadores / badges */
[data-theme=dark] :is(.side .nav a .cnt,.rail-fly .rf-nav a .rf-cnt){background-color:var(--badge-bg);color:var(--badge-fg)}
[data-theme=dark] .cli-lists a .cnt{background-color:var(--soft);color:var(--muted)}
/* Avisos verde / rojo */
[data-theme=dark] .ok-note{background-color:var(--ok-bg);border-color:var(--ok-line);color:var(--ok)}
[data-theme=dark] .err-note{background-color:var(--danger-bg);border-color:var(--danger-line);color:var(--danger)}
/* Interruptor .sw */
[data-theme=dark] .sw .tr{background-color:var(--line-strong)}
[data-theme=dark] .sw input:checked + .tr{background-color:var(--accent)}
[data-theme=dark] .sw .tr:before{background-color:#e9eaec}
/* Degradado del pie de la barra lateral */
[data-theme=dark] .side .foot::before{background:linear-gradient(to top,var(--bg) 22%,transparent)}
/* Datepicker / desplegable custom */
[data-theme=dark] #dpCal .dp-d{color:var(--ink)}
[data-theme=dark] .cs-opt.on{background-color:var(--accent-soft);color:var(--ink-strong)}
/* Popup social del avatar (#erpProfCard, inyectado por JS pero cuelga del <html>) */
[data-theme=dark] #erpProfCard{background-color:var(--card);border-color:var(--line)}
[data-theme=dark] :is(#erpProfCard .epc-sub,#erpProfCard .epc-row,#erpProfCard .epc-btn svg){color:var(--nav-ink)}
[data-theme=dark] #erpProfCard .epc-btn{background-color:var(--field);color:var(--ink);border-color:var(--line)}
[data-theme=dark] #erpProfCard .epc-btn:hover{background-color:var(--soft);border-color:var(--line-strong)}
[data-theme=dark] #erpProfCard .epc-dot{border-color:var(--card)}
[data-theme=dark] #erpProfCard .epc-foot{background-color:var(--soft)}
<?php }

function sb_folder($cl,$lists,$curCli,$curList){
  $open=($curCli==(int)$cl['id']); $cliHasInforme=false; foreach($lists as $ll){ if(($ll['tipo']??'')==='informe')$cliHasInforme=true; }
  ?>
  <div class="cli-grp folder-drag <?= $open?'open':'' ?>" draggable="true" data-cid="<?= (int)$cl['id'] ?>">
    <div class="cli-h" oncontextmenu="return folderMenu(event,<?= (int)$cl['id'] ?>,<?= htmlspecialchars(json_encode($cl['name']), ENT_QUOTES) ?>)">
      <button type="button" class="cx" aria-label="Desplegar u ocultar" onclick="cliFold(this)"><?= ic('chevron',13) ?></button>
      <a class="cli-lnk" href="client.php?id=<?= (int)$cl['id'] ?>" draggable="false"><span class="fld"><?= ic('folder',15) ?></span><span class="nm"><?= e($cl['name']) ?></span></a>
    </div>
    <div class="cli-lists" data-cli="<?= (int)$cl['id'] ?>">
      <a href="client.php?id=<?= (int)$cl['id'] ?>" class="<?= ($curCli==(int)$cl['id'] && !$curList)?'on':'' ?>"><span class="li"><?= ic('home',14) ?></span><span class="nm2">Ficha / Hub</span></a>
      <?php foreach ($lists as $l): $lon=($curCli==(int)$cl['id'] && $curList==(int)$l['id']); ?>
        <a href="workspace.php?view=cliente&cli=<?= (int)$cl['id'] ?>&list=<?= (int)$l['id'] ?>" class="list-drag <?= $lon?'on':'' ?>" draggable="true" data-lid="<?= (int)$l['id'] ?>" oncontextmenu="return listMenu(event,<?= (int)$cl['id'] ?>,<?= (int)$l['id'] ?>,<?= htmlspecialchars(json_encode($l['nombre']), ENT_QUOTES) ?>)"><span class="li"><?= ic(($l['tipo']??'')==='informe'?'inbox':'list',14) ?></span><span class="nm2"><?= e($l['nombre']) ?></span><?php if(($l['tipo']??'')!=='informe' && $l['pend']): ?><span class="cnt"><?= (int)$l['pend'] ?></span><?php endif; ?></a>
      <?php endforeach; ?>
      <?php if(can_edit()): ?><a class="add" style="cursor:pointer" onclick="addListMenu(event,<?= (int)$cl['id'] ?>,<?= $cliHasInforme?'true':'false' ?>)"><span class="li"><?= ic('plus',14) ?></span><span class="nm2">Añadir lista</span></a><?php endif; ?>
    </div>
  </div>
  <?php
}

/* Carpetas de cliente para el DESPLEGABLE del raíl (Tareas): MISMO markup, estética y
   plegado que el sidebar real (mismas clases .cli-* y las funciones globales
   cliFold/cliSect). Solo se omiten las acciones de edición (menú contextual, añadir,
   arrastrar), que son propias del menú de dentro. */
function fly_clients($clients, $sbLists, $curCli, $curList){
  $act = array_values(array_filter($clients, fn($c)=>(int)($c['activo']??1)===1));
  $no  = array_values(array_filter($clients, fn($c)=>(int)($c['activo']??1)===0));
  $actOpen=false; foreach($act as $c){ if($curCli==(int)$c['id']) $actOpen=true; }
  $noOpen=false;  foreach($no  as $c){ if($curCli==(int)$c['id']) $noOpen=true;  }
  $folder = function($cl) use($sbLists,$curCli,$curList){ $lists=$sbLists[$cl['id']]??[]; $open=($curCli==(int)$cl['id']); ?>
    <div class="cli-grp<?= $open?' open':'' ?>">
      <div class="cli-h"><button type="button" class="cx" aria-label="Desplegar u ocultar" onclick="cliFold(this)"><?= ic('chevron',13) ?></button><a class="cli-lnk" href="client.php?id=<?= (int)$cl['id'] ?>"><span class="fld"><?= ic('folder',15) ?></span><span class="nm"><?= e($cl['name']) ?></span></a></div>
      <div class="cli-lists"><a href="client.php?id=<?= (int)$cl['id'] ?>" class="<?= ($curCli==(int)$cl['id'] && !$curList)?'on':'' ?>"><span class="li"><?= ic('home',14) ?></span><span class="nm2">Ficha / Hub</span></a><?php foreach($lists as $l): $lon=($curCli==(int)$cl['id'] && $curList==(int)$l['id']); ?><a href="workspace.php?view=cliente&cli=<?= (int)$cl['id'] ?>&list=<?= (int)$l['id'] ?>" class="<?= $lon?'on':'' ?>"><span class="li"><?= ic(($l['tipo']??'')==='informe'?'inbox':'list',14) ?></span><span class="nm2"><?= e($l['nombre']) ?></span><?php if(($l['tipo']??'')!=='informe' && !empty($l['pend'])): ?><span class="cnt"><?= (int)$l['pend'] ?></span><?php endif; ?></a><?php endforeach; ?></div>
    </div>
  <?php };
  if($act): ?><div class="cli-section"><div class="sec" onclick="cliSect(this,event)"><button type="button" class="stog" aria-label="Plegar o desplegar sección"><?= ic('chevron',12) ?></button><span class="cli-dot ok"></span>Clientes activos</div><div class="cli-acc"><?php foreach($act as $cl) $folder($cl); ?></div></div><?php endif;
  if($no):  ?><div class="cli-section"><div class="sec" onclick="cliSect(this,event)"><button type="button" class="stog" aria-label="Plegar o desplegar sección"><?= ic('chevron',12) ?></button><span class="cli-dot off"></span>Clientes no activos <span style="margin-left:auto;font-size:11px;color:var(--label);font-weight:600"><?= count($no) ?></span></div><div class="cli-acc"><?php foreach($no as $cl) $folder($cl); ?></div></div><?php endif;
}

/* ---- Menú lateral ordenable ----------------------------------------------
   sb_order() devuelve las claves de un bloque del menú en el orden que haya
   guardado ESTA persona. Reglas de seguridad de la función:
     · si una entrada guardada ya no existe (se quitó una página), se ignora;
     · si aparece una entrada nueva que no estaba cuando se guardó el orden,
       se añade al final —nunca desaparece del menú por no estar en la lista.
   Todo el orden del usuario se lee de una sola consulta por página. */
function sb_orders() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $me = function_exists('current_admin') ? current_admin() : null;
    if (!$me) return $cache;
    try {
        $st = db()->prepare("SELECT clave,valor FROM settings WHERE clave LIKE ? OR clave IN ('fin_fac_order','fin_con_order')");
        $st->execute(['nav_order_'.(int)$me['id'].'_%']);
        foreach ($st->fetchAll() as $r) $cache[$r['clave']] = $r['valor'];
    } catch (Exception $e) {}
    $cache['__me'] = (int)$me['id'];
    return $cache;
}
function sb_order($nav, $items) {
    $all = sb_orders(); $keys = array_keys($items);
    if (!isset($all['__me'])) return $keys;
    $str = $all['nav_order_'.$all['__me'].'_'.$nav] ?? null;
    /* Herencia del orden viejo de Finanzas, que era global: si esta persona
       todavía no ha arrastrado nada, se respeta el que ya tenía puesto. */
    if ($str === null && $nav === 'fin-fac') $str = $all['fin_fac_order'] ?? null;
    if ($str === null && $nav === 'fin-con') $str = $all['fin_con_order'] ?? null;
    if ($str === null || $str === '') return $keys;
    $ord = [];
    foreach (explode(',', $str) as $k) { $k = trim($k); if (isset($items[$k]) && !in_array($k,$ord,true)) $ord[] = $k; }
    foreach ($keys as $k) if (!in_array($k,$ord,true)) $ord[] = $k;
    return $ord;
}
/* Pinta un bloque del menú ya ordenado y arrastrable.
   Cada entrada: ['ic'=>icono,'lb'=>texto,'href'=>url,'on'=>bool,
                  'cnt'=>numero|null,'cntst'=>estilo del globito,'attr'=>atributos extra]. */
/* $sortable=true SOLO para lo que el usuario crea (listas del CRM/tareas): el resto del
   menú (Dashboard, Notificaciones, etc.) NO se arrastra ni reordena. */
function sb_nav($nav, $items, $style = '', $sortable = false) {
    $ord = $sortable ? sb_order($nav, $items) : array_keys($items);
    echo '<div class="nav'.($sortable?' sb-sort':'').'" data-nav="'.e($nav).'"'.($style?' style="'.$style.'"':'').'>';
    foreach ($ord as $k) {
        $it = $items[$k]; if (!$it) continue;
        echo '<a class="sb-i'.(!empty($it['on'])?' on':'').'"'.($sortable?' draggable="true"':'').' data-key="'.e($k).'" href="'.e($it['href']).'"'
           . (!empty($it['attr']) ? ' '.$it['attr'] : '').'>'
           . '<span class="ic">'.ic($it['ic'],17).'</span>'
           . '<span class="nm2">'.e($it['lb']).'</span>';
        if (!empty($it['cnt'])) echo '<span class="cnt"'.(!empty($it['cntst'])?' style="'.e($it['cntst']).'"':'').'>'.e((string)$it['cnt']).'</span>';
        echo '</a>';
    }
    echo '</div>';
}

function erp_sidebar($active) {
    $me = current_admin();
    /* Previsualizar la felicitación de cumpleaños cuando quieras: abre cualquier
       pantalla con ?bday=test y te sale el popup (solo para ti, no avisa a nadie). */
    if (($_GET['bday'] ?? '') === 'test') $_SESSION['bday_greet'] = 1;
    /* «Clientes en alta» sale del raíl (icono Clientes) Y del menú de Ajustes. Al
       abrirlo desde Ajustes lleva ?ctx=aj y debe MANTENER el menú de Ajustes; desde
       el raíl no lleva nada y muestra el menú de trabajo. Sin esto, entrar a
       Clientes desde Ajustes te tiraba al menú de Tareas. */
    $ctxAjClientes = ($active === 'clientes' && (($_GET['ctx'] ?? '') === 'aj'));
    if (empty($_SESSION['notif_sync']) || time()-$_SESSION['notif_sync']>120) { if(auto_on('lead_reminder')) notif_sync_leads(); if(auto_on('invoice_due')) notif_sync_invoices(); notif_sync_meeting_requests(); $_SESSION['notif_sync']=time(); }
    /* Cumpleaños: basta con revisarlo una vez al día. El ref del propio aviso ya
       evita duplicados, pero esta marca evita recorrer la tabla en cada carga. */
    if (($_SESSION['bday_sync'] ?? '') !== date('Y-m-d')) {
      notif_sync_birthdays(); $_SESSION['bday_sync']=date('Y-m-d');
      /* ¿Cumple años HOY quien está mirando? Que le salte su felicitación (una vez
         al día). A los demás les avisa notif_sync_birthdays por la campana; a él,
         que no se autoavisa, le sale este popup en cuanto abre el panel. */
      if (($_SESSION['bday_greeted'] ?? '') !== date('Y-m-d')) {
        try {
          $qb=db()->prepare("SELECT 1 FROM admin_profiles WHERE admin_id=? AND cumple IS NOT NULL AND DATE_FORMAT(cumple,'%m-%d')=?");
          $qb->execute([(int)$me['id'], date('m-d')]);
          if ($qb->fetchColumn()) $_SESSION['bday_greet']=1;
        } catch(Exception $e){}
      }
    }
    $nUnread = notif_unread((int)$me['id']);
    try { $nPend = (int)db()->query("SELECT COUNT(*) FROM tasks WHERE estado<>'completada'")->fetchColumn(); } catch (Exception $e) { $nPend = 0; }
    // clientes + sus listas (para el acordeón)
    $sbClients = []; $sbLists = [];
    try {
      /* El menú también respeta hasta dónde ve cada uno: si no, la lista de la
         izquierda enseñaría clientes que luego dan «no tienes permiso» al abrirlos. */
      $sbClients = db()->query('SELECT id,name,COALESCE(activo,1) activo FROM clients WHERE 1 '
        . (function_exists('alcance_sql') ? alcance_sql('id') : '') . ' ORDER BY orden, name')->fetchAll();
      foreach (db()->query("SELECT l.id,l.client_id,l.nombre,l.es_cliente,l.tipo,
          (SELECT COUNT(*) FROM tasks t WHERE t.list_id=l.id AND t.estado<>'completada') pend
        FROM task_lists l ORDER BY l.orden,l.id")->fetchAll() as $r) { $sbLists[$r['client_id']][] = $r; }
    } catch (Exception $e) {}
    /* Cuidado con «id»: en client.php o edit.php es el id del CLIENTE, pero en
       task.php es el id de la TAREA. Cogerlo a ciegas hacía que el menú abriera
       y resaltara la carpeta del cliente número 47 cuando lo que estabas viendo
       era la tarea número 47. Aquí se mira de qué página viene. */
    $curCli  = isset($_GET['cli']) ? (int)$_GET['cli'] : 0;
    $curList = isset($_GET['list']) ? (int)$_GET['list'] : 0;
    if (!$curCli && isset($_GET['id'])) {
      $sbPag = basename($_SERVER['SCRIPT_NAME'] ?? '');
      if (in_array($sbPag, ['client.php','edit.php','duplicate.php'], true)) {
        $curCli = (int)$_GET['id'];
      } elseif ($sbPag === 'task.php') {
        /* De la tarea sacamos su cliente y su lista: así el menú abre la carpeta
           correcta y deja marcada la lista en la que está la tarea. */
        try {
          $q = db()->prepare('SELECT client_id, list_id FROM tasks WHERE id=?');
          $q->execute([(int)$_GET['id']]);
          if ($t = $q->fetch()) { $curCli = (int)$t['client_id']; if (!$curList) $curList = (int)$t['list_id']; }
        } catch (Exception $e) {}
      }
    }

    /* Reuniones, Calendario, Chat y Asistente IA son páginas INDEPENDIENTES: van como
       iconos sueltos del rail, sin submenú desplegable (no hay nada que sub-navegar). */
    /* El raíl solo enseña los módulos que el rol puede abrir. Antes estaban los
       nueve siempre: quien no tenía permiso los veía, pulsaba y se comía un
       redirect mudo. Un botón que no lleva a ningún sitio es peor que no tenerlo.
       El permiso de cada módulo se configura en Ajustes › Roles y permisos. */
    $railTodo = [
      ['dashboard','home','Inicio','dashboard.php',            null],
      ['kanban','tasks','Tareas','workspace.php?view=all',     'ver.tareas'],
      ['clientes','clients','Clientes','index.php',            'ver.clientes'],
      ['crm','crm','CRM','crm.php',                            'ver.crm'],
      ['fin','euro','Finanzas','facturas.php',                 'ver.finanzas'],
      ['reuniones','meet','Reuniones','reuniones.php',         'ver.agenda'],
      ['cal','cal','Calendario','calendar.php',                'ver.agenda'],
      ['chat','chat','Chat','chat.php',                        'ver.chat'],
      ['ia','ia','Asistente','ia.php',                         'ver.ia'],
      ['tickets','ticket','Soporte','support.php',             'ver.soporte'],
      /* Actas: notas internas del equipo. Se ve con el permiso «Ver Actas»
         (configurable en Ajustes › Roles y permisos); escribir se gatea con can_edit(). */
      ['actas','pencil','Actas','actas.php',                   'ver.actas'],
    ];
    $rail = [];
    foreach ($railTodo as $r) {
      $need = $r[4];
      if ($need === null || !function_exists('can') || can($need)) $rail[] = [$r[0],$r[1],$r[2],$r[3]];
    }
    /* «Gestión» se ha quitado del raíl: el engranaje inferior (Ajustes) abre ese
       mismo menú de «Gestión y ajustes», así que era una entrada duplicada. */
    /* Solo llevan menú de hover las secciones que de verdad tienen sub-páginas.
       Soporte, Reuniones y las herramientas NO: son una sola pantalla cada una. */
    /* Los desplegables del raíl muestran las MISMAS secciones y apartados que el
       sidebar real de cada zona, agrupados por subsección y con los mismos datos
       (emisores de Finanzas, apartados de Gestión, listas del CRM). Formato:
       $railMenus[modulo] = [ ['Título sección' | '', [ [href,icono,etiqueta], … ]], … ]. */
    require_once __DIR__ . '/lib/fin_prog.php';
    require_once __DIR__ . '/lib/ajustes_nav.php';
    $flyEmis = function_exists('fin_emisores') ? fin_emisores() : [];
    $flyFac = [['facturas.php','file','Todas']];
    foreach ($flyEmis as $ek=>$en) $flyFac[] = ['facturas.php?em='.urlencode($ek),'user',$en];
    $flyFac[] = ['facturas.php?clientes=1','clients','Por cliente'];
    $flyFac[] = ['programaciones.php','cal','Programaciones'];
    $flyCon = [['contabilidad.php','home','Hub']];
    foreach ($flyEmis as $ek=>$en) $flyCon[] = ['contabilidad.php?em='.urlencode($ek),'user',$en];
    $flyCon[] = ['contabilidad-analisis.php','chart','Análisis / gráficas'];
    $flyCrm = [];
    try { foreach (db()->query('SELECT id,nombre,tipo FROM lists ORDER BY tipo DESC, nombre') as $ll) $flyCrm[] = ['listas.php?id='.(int)$ll['id'], ($ll['tipo']==='estatica'?'layers':'list'), $ll['nombre']]; } catch (Exception $e) {}
    $railMenus = [
      'kanban'=>[['',[['workspace.php?view=all','inbox','Todas las tareas'],['workspace.php?view=mine','usercheck','Mis tareas'],['dashboard.php','home','Dashboard'],['notifications.php','bell','Notificaciones']]]],
      'crm'=>[['',[['crm.php','clients','Contactos'],['negocio.php','grid','Negocio'],['crm_dashboard.php','chart','Dashboard'],['automatizaciones.php','bolt','Reporting'],['reuniones.php','cal','Reuniones']]], ['Listas',$flyCrm]],
      'fin'=>[['General',[['fin-resumen.php','chart','Resumen mensual'],['proyectos.php','layers','Proyectos'],['fin-horas.php','clock','Horas equipo'],['pricing.php','calc','Calculadora de precios']]], ['Facturas',$flyFac], ['Contabilidad',$flyCon], ['Ajustes',[['fin-ajustes.php','euro','Facturación de clientes']]]],
      'clientes'=>[['',[['index.php','clients','Clientes en alta'],['types.php','flag','Tipos de cliente'],['credenciales.php','vault','Bóveda de credenciales']]]],
    ];
    if (function_exists('aj_menu')) { $rg=[]; foreach (aj_menu() as $zona=>$items) { $sec=[]; foreach ($items as $it) { $sec[]=[$it[3],$it[1],$it[2]]; } if($sec) $rg[]=[$zona,$sec]; } if($rg) $railMenus['roles']=$rg; }
    /* Filtro de permisos por apartado (como el raíl): si el rol no puede abrir una
       pantalla no se enseña; se descartan las secciones que quedan vacías y, si al
       final queda una sola entrada, el desplegable no aporta nada y se quita. */
    if (function_exists('can') && function_exists('perm_de_pagina')) {
      foreach ($railMenus as $rk => $secs) {
        $out=[]; $total=0;
        foreach ($secs as $sec) { list($sl,$items)=$sec; $vivos=[];
          foreach ($items as $mi) { $need = perm_de_pagina(basename(parse_url($mi[0], PHP_URL_PATH))); if ($need === null || can($need)) { $vivos[]=$mi; $total++; } }
          if ($vivos) $out[] = [$sl,$vivos];
        }
        $railMenus[$rk] = ($total > 1) ? $out : [];
      }
    }
    $railMap = ['dashboard'=>'dashboard','kanban'=>'kanban','crm'=>'crm','fact'=>'fin','conta'=>'fin','finaj'=>'fin','prog'=>'fin','proj'=>'fin','resumen'=>'fin','horas'=>'fin','pricing'=>'fin','chat'=>'chat','ia'=>'ia','tickets'=>'tickets','cal'=>'cal','reuniones'=>'reuniones','actas'=>'actas','notif'=>'kanban','agencias'=>'roles','roles'=>'roles','ajustes'=>'roles','vault'=>'roles','clientes'=>'clientes','papelera'=>'roles','integr'=>'roles'];
    $railActive = $railMap[$active] ?? '';
    /* En Clientes-desde-Ajustes manda el engranaje, no el icono Clientes del raíl. */
    if ($ctxAjClientes) $railActive = '';
    $curView = isset($_GET['cli']) ? 'cliente' : ($_GET['view'] ?? 'all');
    /* La clave de cada entrada («notif», «all»…) es la que se guarda al
       arrastrar: tiene que ser única dentro del bloque y no cambiar nunca,
       porque es lo que ata el orden guardado con la entrada del menú. */
    $top = [
      'dash' =>['ic'=>'home','lb'=>'Dashboard','href'=>'dashboard.php','on'=>$active==='dashboard'],
      'notif'=>['ic'=>'bell','lb'=>'Notificaciones','href'=>'notifications.php','on'=>$active==='notif','cnt'=>$nUnread?:null],
      'all'  =>['ic'=>'inbox','lb'=>'Todas las tareas','href'=>'workspace.php?view=all','on'=>$active==='kanban'&&$curView==='all','cnt'=>$nPend?:null,'cntst'=>'background:none;color:var(--label);padding:0'],
      'mine' =>['ic'=>'usercheck','lb'=>'Mis tareas','href'=>'workspace.php?view=mine','on'=>$active==='kanban'&&$curView==='mine'],
      /* La lista de clientes, aquí y no en Ajustes: es trabajo del día. */
      'cli'  =>['ic'=>'clients','lb'=>'Clientes','href'=>'index.php','on'=>$active==='clientes','cnt'=>count($sbClients)?:null,'cntst'=>'background:var(--soft);color:var(--label)'],
      'reu'  =>['ic'=>'meet','lb'=>'Reuniones','href'=>'reuniones.php?ctx=ops','on'=>$active==='reuniones'],
      'cred' =>['ic'=>'vault','lb'=>'Credenciales','href'=>'credenciales.php?ctx=ops','on'=>$active==='vault'],
      'cal'  =>['ic'=>'cal','lb'=>'Calendario','href'=>'calendar.php','on'=>$active==='cal'],
    ];
    /* Calendario, Chat y IA ya son iconos propios del rail: en el sidebar de ops
       solo dejamos la Bóveda de credenciales. */
    $groups = [
      'Herramientas'=>['ops-tools',[
        'vault'=>['ic'=>'vault','lb'=>'Bóveda Credenciales','href'=>'credenciales.php','on'=>$active==='vault'],
      ]],
    ];
    // módulo activo -> qué menú lateral mostrar
    /* Ajustes y la bóveda de Credenciales pasan a enseñar el menú de Gestión:
       son parte de esa sección y antes salían con el sidebar de Tareas, donde
       no aparecen listadas y no había forma de volver a ellas. */
    /* «solo» = página independiente a pantalla completa, SIN sidebar (Reuniones,
       Calendario, Chat, Asistente IA y Soporte). */
    /* La Bóveda vive en «Gestión y ajustes», pero se puede entrar desde el menú de
       Tareas (?ctx=ops): en ese caso mantenemos el menú de Tareas, no lo cambiamos. */
    $ctxOps = (in_array($active,['vault','reuniones'],true) && (($_GET['ctx']??'')==='ops'));
    $mod = $ctxOps ? 'ops' : ($ctxAjClientes ? 'roles' : (($active==='crm') ? 'crm' : (in_array($active,['roles','ajustes','vault','agencias','papelera','integr'],true) ? 'roles' : (in_array($active,['fact','conta','finaj','prog','proj','resumen','horas','pricing'],true) ? 'fin' : (in_array($active,['chat','ia','cal','tickets','reuniones','actas'],true) ? 'solo' : 'ops')))));
    $bn = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $curSeg = $_GET['seg'] ?? ''; $curEst = $_GET['est'] ?? '';
    $crmLists = [];
    try { $crmLists = db()->query('SELECT id,nombre,tipo FROM lists ORDER BY tipo DESC, nombre')->fetchAll(); } catch (Exception $e) {}
    ?>
<aside class="rail">
  <div class="rlogo">C</div>
  <?php $flyH = ['crm'=>'CRM · Ventas','roles'=>'Gestión y ajustes','fin'=>'Finanzas'];
    foreach ($rail as $r): [$k,$icn,$lb,$href]=$r; $rm=$railMenus[$k]??[]; ?>
    <div class="rail-item">
      <a href="<?= e($href) ?>" class="<?= $railActive===$k?'on':'' ?>" title="<?= e($lb) ?>"><?= ic($icn,20) ?><span><?= e($lb) ?></span></a>
      <?php if($rm && $railActive!==$k): ?><div class="rail-fly"><div class="rf-h"><?= e($flyH[$k] ?? $lb) ?></div><?php foreach($rm as $sec): [$sl,$items]=$sec; if(!$items) continue; ?><?php if($sl!==''): ?><div class="rf-sec"><?= e($sl) ?></div><?php endif; ?><div class="rf-nav"><?php foreach($items as $mi): [$mh,$mic,$ml]=$mi; $mbn=basename(parse_url($mh,PHP_URL_PATH));
        /* El chat avisa desde el propio menú: si no, hay que entrar a mirar. */
        $mbg = ($mbn==='chat.php') ? chat_unread((int)$me['id']) : 0; ?><a href="<?= e($mh) ?>" class="<?= $bn===$mbn?'on':'' ?>"><span class="ic"><?= ic($mic,17) ?></span><?= e($ml) ?><?php if($mbg): ?><span class="rf-cnt"><?= $mbg>99?'99+':$mbg ?></span><?php endif; ?></a><?php endforeach; ?></div><?php endforeach; ?><?php if($k==='kanban') fly_clients($sbClients,$sbLists,$curCli,$curList); ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <div class="rsp"></div>
  <?php /* El engranaje siempre está, pero no abre lo mismo para todos.

           Quien administra entra en «Gestión y ajustes»: la configuración del ERP.
           Quien solo trabaja aquí entra en **su cuenta** — su contraseña, su
           correo, sus avisos. Antes a esa gente el engranaje simplemente
           desaparecía y se quedaba sin ningún sitio donde cambiar lo suyo; y
           antes de eso, peor todavía: entraban en la configuración del ERP.
           Mismo botón, mismo sitio, lo que le corresponde a cada uno. */
        $gearAdmin = (!function_exists('can') || can('ver.ajustes'));
        $gearHref  = $gearAdmin ? 'settings.php' : 'perfil.php?id='.(int)$me['id'].'&modo=cuenta';
        $gearOn    = $gearAdmin ? ($active==='ajustes' || $ctxAjClientes) : ($active==='cuenta'); ?>
  <a href="<?= e($gearHref) ?>" class="rgear <?= $gearOn?'on':'' ?>" title="<?= $gearAdmin ? 'Gestión y ajustes' : 'Mi cuenta' ?>"><?= ic('settings',20) ?></a>
  <a href="notifications.php" class="rbell <?= $active==='notif'?'on':'' ?>" title="Notificaciones"><?= ic('bell',20) ?><?php if($nUnread): ?><span class="rbadge"><?= $nUnread>9?'9+':(int)$nUnread ?></span><?php endif; ?></a>
  <a class="rav" href="perfil.php?id=<?= (int)$me['id'] ?>" data-uid="<?= (int)$me['id'] ?>" title="<?= e($me['username']) ?>" style="background:<?= avatar_color($me['username']) ?>;border:none;text-decoration:none"><?= e(mb_strtoupper(mb_substr($me['username'],0,2))) ?></a>
  <?php /* Solo visible dentro del cajón de navegación en móvil (oculto en escritorio). */ ?>
  <form class="salir" method="post" action="logout.php"><?= csrf_field() ?><button class="rlogout" type="submit" title="Cerrar sesión"><?= ic('logout',20) ?><span>Cerrar sesión</span></button></form>
</aside>
<aside class="side<?= $mod==='solo'?' side-hidden':'' ?>">
  <div class="sh"><?= $mod==='crm' ? 'CRM · Ventas' : ($mod==='roles' ? 'Gestión y ajustes' : ($mod==='fin' ? 'Finanzas' : (marca_agencia()['name'] . ' ERP'))) ?></div>

  <?php if ($mod==='ops'): ?>
    <?php sb_nav('ops-top', $top, 'margin-top:8px'); ?>
    <?php $sbAct=array_filter($sbClients,fn($c)=>(int)($c['activo']??1)===1); $sbNo=array_filter($sbClients,fn($c)=>(int)($c['activo']??1)===0);
      $noOpen=false; foreach($sbNo as $c){ if($curCli==(int)$c['id']) $noOpen=true; } $actOpen=false; foreach($sbAct as $c){ if($curCli==(int)$c['id']) $actOpen=true; } ?>
    <style>
    .cli-section .sec{display:flex;align-items:center;gap:5px;cursor:pointer;user-select:none}
    .cli-section .sec .stog{border:none;background:none;color:var(--muted);cursor:pointer;padding:2px;display:flex;transition:transform .15s ease}
    .cli-section.collapsed .sec .stog{transform:rotate(-90deg)}
    .cli-section.collapsed .cli-acc{max-height:0;opacity:0}
    .cli-section .sec .secadd{margin-left:auto}
    /* Punto de color en la cabecera de cada sección de clientes. */
    .cli-dot{width:7px;height:7px;border-radius:50%;flex:none}
    .cli-dot.ok{background:#12a150}
    .cli-dot.off{background:#f59e0b}
    /* Jerarquía: los títulos de carpeta igualan tamaño/color al resto de items del
       menú (antes eran más grandes y oscuros) y las carpetas se indentan a la derecha
       para verse colgando de «Clientes activos / no activos». Las listas de dentro
       conservan su propia sangría. */
    .cli-acc[data-folders]{padding-left:28px}
    .cli-h .cli-lnk{font-size:12.8px;font-weight:500;color:#5c616b}
    </style>
    <div class="cli-section <?= $actOpen?'':'collapsed' ?>">
      <div class="sec" onclick="cliSect(this,event)"><button type="button" class="stog" aria-label="Plegar o desplegar sección"><?= ic('chevron',12) ?></button><span class="cli-dot ok"></span>Clientes activos <a class="secadd" href="edit.php" title="Añadir cliente">+</a></div>
      <div class="cli-acc" data-folders="1">
        <?php if (!$sbAct): ?>
          <div class="cli-empty">Sin clientes activos. <a href="edit.php" style="color:var(--accent);font-weight:600">Añadir →</a></div>
        <?php else: foreach ($sbAct as $cl){ sb_folder($cl,$sbLists[$cl['id']]??[],$curCli,$curList); } endif; ?>
      </div>
    </div>
    <?php if($sbNo): ?>
    <div class="cli-section <?= $noOpen?'':'collapsed' ?>">
      <div class="sec" onclick="cliSect(this,event)"><button type="button" class="stog" aria-label="Plegar o desplegar sección"><?= ic('chevron',12) ?></button><span class="cli-dot off"></span>Clientes no activos <span style="margin-left:auto;font-size:11px;color:var(--label);font-weight:600"><?= count($sbNo) ?></span></div>
      <div class="cli-acc" data-folders="1">
        <?php foreach ($sbNo as $cl){ sb_folder($cl,$sbLists[$cl['id']]??[],$curCli,$curList); } ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="ctxmenu" id="folderCtx"></div>
    <div class="ctxmenu" id="listCtx"></div>
    <div class="addlm" id="addListMenu"></div>
    <form id="nlSideForm" method="post" action="workspace.php" style="display:none"><input type="hidden" name="action" value="add_list"><input type="hidden" name="cli" id="nlSideCli"><input type="hidden" name="tipo" id="nlSideTipo"><input type="hidden" name="nombre" id="nlSideName"><input type="hidden" name="ret" id="nlSideRet"></form>
    <form id="delListForm" method="post" action="workspace.php" style="display:none"><input type="hidden" name="action" value="del_list"><input type="hidden" name="cli" id="dlCli"><input type="hidden" name="list_id" id="dlLid"><input type="hidden" name="ret" id="dlRet"></form>
    <form id="dupListForm" method="post" action="workspace.php" style="display:none"><input type="hidden" name="action" value="dup_list"><input type="hidden" name="cli" id="duCli"><input type="hidden" name="list_id" id="duLid"><input type="hidden" name="ret" id="duRet"></form>
    <form id="renListForm" method="post" action="workspace.php" style="display:none"><input type="hidden" name="action" value="rename_list"><input type="hidden" name="cli" id="reCli"><input type="hidden" name="list_id" id="reLid"><input type="hidden" name="nombre" id="reNom"><input type="hidden" name="ret" id="reRet"></form>
    <script>
    function folderMenu(e,id,name){e.preventDefault();var m=document.getElementById('folderCtx');m.innerHTML='';
      function it(txt,href,conf,danger,blank){var a=document.createElement('a');a.textContent=txt;a.href=href;if(danger)a.className='danger';if(blank)a.target='_blank';if(conf)a.onclick=function(ev){ev.preventDefault();return erpAsk(conf,{danger:!!danger,href:href});};return a;}
      /* acciones que modifican datos: siempre POST + CSRF, nunca un enlace GET */
      function act(txt,url,data,conf,danger){var a=document.createElement('a');a.textContent=txt;a.href='#';if(danger)a.className='danger';
        a.onclick=function(ev){ev.preventDefault();ev.stopPropagation();return erpAsk(conf,{post:url,data:data,danger:!!danger});};return a;}
      m.appendChild(it('Abrir ficha / hub','client.php?id='+id));
      m.appendChild(it('Editar cliente','edit.php?id='+id));
      m.appendChild(it('Ver como cliente','../index.php?cli='+id,null,false,true));
      m.appendChild(act('Duplicar','duplicate.php',{id:id},'¿Duplicar '+name+'? Podrás editar la copia.'));
      var aTog=document.createElement('a');aTog.textContent='Activar / Desactivar';aTog.href='#';aTog.onclick=function(ev){ev.preventDefault();ev.stopPropagation();var f=document.createElement('form');f.method='post';f.action='workspace.php';f.innerHTML='<input type="hidden" name="action" value="toggle_activo"><input type="hidden" name="cli" value="'+id+'">';document.body.appendChild(f);f.submit();};m.appendChild(aTog);
      var s=document.createElement('div');s.className='sep';m.appendChild(s);
      m.appendChild(act('Eliminar','delete.php',{id:id},'¿Eliminar '+name+'? Se borrarán sus tareas, listas y credenciales. No se puede deshacer.',true));
      m.style.left=Math.min(e.clientX,window.innerWidth-210)+'px';m.style.top=Math.min(e.clientY,window.innerHeight-250)+'px';m.classList.add('on');return false;}
    function addListMenu(e,cli,hasInf){e.stopPropagation();e.preventDefault();var m=document.getElementById('addListMenu');m.innerHTML='';
      var h=document.createElement('div');h.className='alm-h';h.textContent='Crear en este cliente';m.appendChild(h);
      function opt(txt,tp,color){var b=document.createElement('button');b.type='button';b.className='alm-opt';var d=document.createElement('span');d.className='almdot';d.style.background=color;b.appendChild(d);b.appendChild(document.createTextNode(txt));b.onclick=function(ev){ev.stopPropagation();nameStep(cli,tp);};return b;}
      m.appendChild(opt('Lista de tareas','tareas','#7b68ee'));
      if(!hasInf)m.appendChild(opt('Informe de cliente','informe','#0ea5e9'));
      var r=e.currentTarget.getBoundingClientRect();m.style.left=(r.right+6)+'px';m.style.top=r.top+'px';m.classList.add('on');}
    function nameStep(cli,tp){var m=document.getElementById('addListMenu');m.innerHTML='';
      var h=document.createElement('div');h.className='alm-h';h.textContent=(tp==='informe'?'Nombre del informe':'Nombre de la lista');m.appendChild(h);
      var w=document.createElement('div');w.className='alm-form';
      var inp=document.createElement('input');inp.type='text';inp.value=(tp==='informe'?'INFORMES CLIENTE':'');inp.placeholder='Escribe y pulsa Enter…';
      var go=function(){var n=inp.value.trim();if(n){document.getElementById('nlSideCli').value=cli;document.getElementById('nlSideTipo').value=tp;document.getElementById('nlSideName').value=n;document.getElementById('nlSideRet').value='view=cliente&cli='+cli;document.getElementById('nlSideForm').submit();}};
      inp.onkeydown=function(ev){if(ev.key==='Enter'){ev.preventDefault();go();}};
      var btn=document.createElement('button');btn.type='button';btn.className='alm-create';btn.textContent='Crear';btn.onclick=go;
      w.appendChild(inp);w.appendChild(btn);m.appendChild(w);setTimeout(function(){inp.focus();inp.select();},10);}
    function listMenu(e,cli,lid,name){e.preventDefault();var m=document.getElementById('listCtx');m.innerHTML='';
      function it(txt,fn,danger){var a=document.createElement('a');a.textContent=txt;a.href='#';if(danger)a.className='danger';a.onclick=function(ev){ev.preventDefault();ev.stopPropagation();fn();};return a;}
      m.appendChild(it('Abrir lista',function(){m.classList.remove('on');location.href='workspace.php?view=cliente&cli='+cli+'&list='+lid;}));
      m.appendChild(it('Renombrar',function(){renameStep(cli,lid,name);}));
      m.appendChild(it('Clonar (con sus tareas)',function(){m.classList.remove('on');document.getElementById('duCli').value=cli;document.getElementById('duLid').value=lid;document.getElementById('duRet').value='view=cliente&cli='+cli;document.getElementById('dupListForm').submit();}));
      var s=document.createElement('div');s.className='sep';m.appendChild(s);
      m.appendChild(it('Borrar lista',function(){m.classList.remove('on');
        erpConfirm('Se borran también todas sus tareas.',{titulo:'¿Borrar «'+name+'»?',danger:true}).then(function(ok){if(!ok)return;
          document.getElementById('dlCli').value=cli;document.getElementById('dlLid').value=lid;document.getElementById('dlRet').value='view=cliente&cli='+cli;document.getElementById('delListForm').submit();});},true));
      m.style.left=Math.min(e.clientX,window.innerWidth-210)+'px';m.style.top=Math.min(e.clientY,window.innerHeight-210)+'px';m.classList.add('on');return false;}
    function renameStep(cli,lid,name){var m=document.getElementById('listCtx');m.innerHTML='';
      var w=document.createElement('div');w.style.cssText='display:flex;gap:6px;padding:6px';
      var inp=document.createElement('input');inp.type='text';inp.value=name;inp.style.cssText='flex:1;min-width:0;border:1px solid #e5e5e7;border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit';
      var go=function(){var n=inp.value.trim();if(n){document.getElementById('reCli').value=cli;document.getElementById('reLid').value=lid;document.getElementById('reNom').value=n;document.getElementById('reRet').value='view=cliente&cli='+cli;document.getElementById('renListForm').submit();}};
      inp.onkeydown=function(ev){if(ev.key==='Enter'){ev.preventDefault();go();}};
      var b=document.createElement('button');b.type='button';b.textContent='Guardar';b.style.cssText='border:none;background:#111318;color:#fff;border-radius:8px;padding:8px 12px;font-size:12.5px;font-weight:600;cursor:pointer';b.onclick=go;
      w.appendChild(inp);w.appendChild(b);m.appendChild(w);setTimeout(function(){inp.focus();inp.select();},10);}
    document.addEventListener('click',function(e){
      if(!e.target.closest('#folderCtx')){var a=document.getElementById('folderCtx');if(a)a.classList.remove('on');}
      if(!e.target.closest('#listCtx')){var c=document.getElementById('listCtx');if(c)c.classList.remove('on');}
      if(!e.target.closest('#addListMenu')){var b=document.getElementById('addListMenu');if(b)b.classList.remove('on');}
    });
    /* Motor de arrastre común. Antes solo entendía dos cosas: carpetas de cliente y
       listas del menú. Ahora acepta un tercer tipo, `.row-drag`, que sirve para
       cualquier lista de filas de cualquier página sin volver a escribir todo esto:
       el contenedor dice a dónde mandar el nuevo orden con data-reorder="accion"
       (y data-reorder-url si no es workspace.php), y cada fila lleva su id en
       data-rid. Así las tareas y las etapas del CRM se reordenan con el mismo
       código, los mismos marcadores azules y el mismo guardado silencioso. */
    (function(){var dragEl=null,overEl=null,pos='',SEL='.list-drag,.folder-drag,.row-drag';
      function kindOf(el){return el.classList.contains('folder-drag')?'folder-drag':(el.classList.contains('row-drag')?'row-drag':'list-drag');}
      function clearMarks(){document.querySelectorAll('.drop-above,.drop-below,.drop-left,.drop-right').forEach(function(x){x.classList.remove('drop-above','drop-below','drop-left','drop-right');});}
      document.addEventListener('dragstart',function(e){var a=e.target.closest(SEL);
        /* Si dentro del elemento reordenable hay otra cosa arrastrable (por ejemplo
           una tarjeta de negocio dentro de su columna del embudo), manda la de
           dentro: sin esto, al arrastrar una tarjeta el motor se creería que se
           está moviendo la columna entera. */
        if(a){var inner=e.target.closest('[draggable="true"]');if(inner&&inner!==a&&a.contains(inner))return;}
        if(a){dragEl=a;e.dataTransfer.effectAllowed='move';try{e.dataTransfer.setData('text/plain','x');}catch(_){}setTimeout(function(){a.style.opacity='.35';},0);}});
      document.addEventListener('dragend',function(){clearMarks();if(dragEl){dragEl.style.opacity='';}dragEl=null;overEl=null;});
      document.addEventListener('dragover',function(e){if(!dragEl)return;var a=e.target.closest(SEL);if(!a||a===dragEl||a.parentNode!==dragEl.parentNode||!a.classList.contains(kindOf(dragEl)))return;e.preventDefault();clearMarks();var horiz=dragEl.parentNode.classList.contains('tl-tabs')||dragEl.parentNode.classList.contains('row-drag-x');var r=a.getBoundingClientRect();var after=horiz?((e.clientX-r.left)>r.width/2):((e.clientY-r.top)>r.height/2);a.classList.add(horiz?(after?'drop-right':'drop-left'):(after?'drop-below':'drop-above'));overEl=a;pos=after?'after':'before';});
      document.addEventListener('drop',function(e){if(!dragEl||!overEl){clearMarks();return;}e.preventDefault();var cont=dragEl.parentNode;overEl.parentNode.insertBefore(dragEl,pos==='after'?overEl.nextSibling:overEl);clearMarks();var body='',url='workspace.php';
        if(dragEl.classList.contains('folder-drag')){var ids=[].slice.call(cont.querySelectorAll('.folder-drag')).map(function(x){return x.dataset.cid;});body='action=reorder_clients'+ids.map(function(id){return '&order[]='+id;}).join('');}
        else if(dragEl.classList.contains('row-drag')){
          if(cont&&cont.dataset&&cont.dataset.reorder){
            var rids=[].slice.call(cont.querySelectorAll('.row-drag')).map(function(x){return x.dataset.rid;});
            body='action='+encodeURIComponent(cont.dataset.reorder)+(cont.dataset.reorderCli?('&cli='+encodeURIComponent(cont.dataset.reorderCli)):'')+rids.map(function(id){return '&order[]='+encodeURIComponent(id);}).join('');
            if(cont.dataset.reorderUrl)url=cont.dataset.reorderUrl;
          }
        }
        else if(cont&&cont.dataset&&cont.dataset.cli){var lids=[].slice.call(cont.querySelectorAll('.list-drag')).map(function(x){return x.dataset.lid;});body='action=reorder_lists&cli='+cont.dataset.cli+lids.map(function(id){return '&order[]='+id;}).join('');}
        /* El token CSRF lo pone solo el envoltorio de fetch() de erp_foot. */
        if(body)fetch(url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body}).catch(function(){});
        if(dragEl)dragEl.style.opacity='';dragEl=null;overEl=null;});
    })();
    </script>

  <?php elseif ($mod==='crm'): ?>
    <?php sb_nav('crm', [
      'contactos'=>['ic'=>'clients','lb'=>'Contactos','href'=>'crm.php','on'=>$bn==='crm.php'],
      'negocio'  =>['ic'=>'grid','lb'=>'Negocio','href'=>'negocio.php','on'=>$bn==='negocio.php'],
      'panel'    =>['ic'=>'chart','lb'=>'Dashboard','href'=>'crm_dashboard.php','on'=>$bn==='crm_dashboard.php'],
      'autom'    =>['ic'=>'bolt','lb'=>'Reporting','href'=>'automatizaciones.php','on'=>$bn==='automatizaciones.php'],
      'reuniones'=>['ic'=>'cal','lb'=>'Reuniones','href'=>'reuniones.php','on'=>$bn==='reuniones.php'],
    ], 'margin-top:10px'); ?>
    <div class="sec">Listas <?php if(can_edit()): ?><a class="secadd" href="listas.php?new=1" title="Nueva lista">+</a><?php endif; ?></div>
    <?php $curLid = ($bn==='listas.php') ? (int)($_GET['id']??0) : -1;
    if($crmLists):
      /* Las listas del CRM también se arrastran. La clave es «l» + su id, que no
         cambia aunque se renombre la lista. */
      $lItems=[];
      foreach($crmLists as $ll){
        $lItems['l'.(int)$ll['id']] = [
          'ic'=>$ll['tipo']==='estatica'?'layers':'list',
          'lb'=>$ll['nombre'],
          'href'=>'listas.php?id='.(int)$ll['id'],
          'on'=>$curLid===(int)$ll['id'],
          'attr'=>can_edit()?('oncontextmenu="return lstMenu(event,'.(int)$ll['id'].','.htmlspecialchars(json_encode($ll['nombre']),ENT_QUOTES).',\''.e($ll['tipo']).'\')"'):'',
        ];
      }
      sb_nav('crm-listas', $lItems, '', true); // las listas del CRM SÍ se reordenan
    else: ?>
      <div class="nav"><div class="cli-empty" style="padding:7px 12px;font-size:12px;color:var(--muted)">Sin listas todavía.</div></div>
    <?php endif; ?>
    <?php if(can_edit()): ?>
    <div class="ctxmenu" id="lstCtx"></div>
    <form id="lstRenForm" method="post" action="listas.php" style="display:none"><input type="hidden" name="action" value="rename_list"><input type="hidden" name="id" id="lstRenId"><input type="hidden" name="nombre" id="lstRenName"></form>
    <form id="lstFrzForm" method="post" action="listas.php" style="display:none"><input type="hidden" name="action" value="freeze"><input type="hidden" name="id" id="lstFrzId"></form>
    <form id="lstDelForm" method="post" action="listas.php" style="display:none"><input type="hidden" name="action" value="del_list"><input type="hidden" name="id" id="lstDelId"></form>
    <script>
    function lstMenu(e,id,name,tipo){e.preventDefault();var m=document.getElementById('lstCtx');m.innerHTML='';
      var it=function(txt,fn,danger){var a=document.createElement('a');a.textContent=txt;a.href='#';if(danger)a.className='danger';a.onclick=function(ev){ev.preventDefault();ev.stopPropagation();m.classList.remove('on');fn();};return a;};
      m.appendChild(it('Abrir',function(){window.location='listas.php?id='+id;}));
      m.appendChild(it('Exportar CSV',function(){window.location='listas.php?export='+id;}));
      if(tipo==='activa')m.appendChild(it('Convertir a estática',function(){
        erpConfirm('Dejará de actualizarse sola: se queda con los contactos que tiene ahora.',{titulo:'¿Congelar «'+name+'»?',ok:'Congelar'}).then(function(ok){if(!ok)return;
          document.getElementById('lstFrzId').value=id;document.getElementById('lstFrzForm').submit();});}));
      m.appendChild(it('Renombrar',function(){
        erpPrompt('Renombrar lista',name,{placeholder:'Nombre de la lista'}).then(function(n){if(!n)return;
          document.getElementById('lstRenId').value=id;document.getElementById('lstRenName').value=n;document.getElementById('lstRenForm').submit();});}));
      var s=document.createElement('div');s.className='sep';m.appendChild(s);
      m.appendChild(it('Eliminar lista',function(){
        erpConfirm('Los contactos no se borran, solo la lista.',{titulo:'¿Eliminar «'+name+'»?',danger:true}).then(function(ok){if(!ok)return;
          document.getElementById('lstDelId').value=id;document.getElementById('lstDelForm').submit();});},true));
      m.style.left=Math.min(e.clientX,window.innerWidth-210)+'px';m.style.top=Math.min(e.clientY,window.innerHeight-200)+'px';m.classList.add('on');return false;}
    document.addEventListener('click',function(e){if(!e.target.closest('#lstCtx')){var m=document.getElementById('lstCtx');if(m)m.classList.remove('on');}});
    </script><?php endif; ?>
    <?php /* El mismo motor de arrastre genérico que en el módulo de Tareas, para que
             las columnas del embudo de negocio.php (`.row-drag` con data-reorder) se
             reordenen y GUARDEN. Antes solo se emitía en $mod==='ops', así que en el
             CRM las columnas se arrastraban pero no se guardaban (defecto P2-03).
             `ops` y `crm` son ramas excluyentes: nunca coexisten dos motores. */ ?>
    <script>
    (function(){var dragEl=null,overEl=null,pos='',SEL='.list-drag,.folder-drag,.row-drag';
      function kindOf(el){return el.classList.contains('folder-drag')?'folder-drag':(el.classList.contains('row-drag')?'row-drag':'list-drag');}
      function clearMarks(){document.querySelectorAll('.drop-above,.drop-below,.drop-left,.drop-right').forEach(function(x){x.classList.remove('drop-above','drop-below','drop-left','drop-right');});}
      document.addEventListener('dragstart',function(e){var a=e.target.closest(SEL);
        if(a){var inner=e.target.closest('[draggable="true"]');if(inner&&inner!==a&&a.contains(inner))return;}
        if(a){dragEl=a;e.dataTransfer.effectAllowed='move';try{e.dataTransfer.setData('text/plain','x');}catch(_){}setTimeout(function(){a.style.opacity='.35';},0);}});
      document.addEventListener('dragend',function(){clearMarks();if(dragEl){dragEl.style.opacity='';}dragEl=null;overEl=null;});
      document.addEventListener('dragover',function(e){if(!dragEl)return;var a=e.target.closest(SEL);if(!a||a===dragEl||a.parentNode!==dragEl.parentNode||!a.classList.contains(kindOf(dragEl)))return;e.preventDefault();clearMarks();var horiz=dragEl.parentNode.classList.contains('tl-tabs')||dragEl.parentNode.classList.contains('row-drag-x');var r=a.getBoundingClientRect();var after=horiz?((e.clientX-r.left)>r.width/2):((e.clientY-r.top)>r.height/2);a.classList.add(horiz?(after?'drop-right':'drop-left'):(after?'drop-below':'drop-above'));overEl=a;pos=after?'after':'before';});
      document.addEventListener('drop',function(e){if(!dragEl||!overEl){clearMarks();return;}e.preventDefault();var cont=dragEl.parentNode;overEl.parentNode.insertBefore(dragEl,pos==='after'?overEl.nextSibling:overEl);clearMarks();var body='',url='workspace.php';
        if(dragEl.classList.contains('folder-drag')){var ids=[].slice.call(cont.querySelectorAll('.folder-drag')).map(function(x){return x.dataset.cid;});body='action=reorder_clients'+ids.map(function(id){return '&order[]='+id;}).join('');}
        else if(dragEl.classList.contains('row-drag')){
          if(cont&&cont.dataset&&cont.dataset.reorder){
            var rids=[].slice.call(cont.querySelectorAll('.row-drag')).map(function(x){return x.dataset.rid;});
            body='action='+encodeURIComponent(cont.dataset.reorder)+(cont.dataset.reorderCli?('&cli='+encodeURIComponent(cont.dataset.reorderCli)):'')+rids.map(function(id){return '&order[]='+encodeURIComponent(id);}).join('');
            if(cont.dataset.reorderUrl)url=cont.dataset.reorderUrl;
          }
        }
        else if(cont&&cont.dataset&&cont.dataset.cli){var lids=[].slice.call(cont.querySelectorAll('.list-drag')).map(function(x){return x.dataset.lid;});body='action=reorder_lists&cli='+cont.dataset.cli+lids.map(function(id){return '&order[]='+id;}).join('');}
        if(body)fetch(url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body}).catch(function(){});
        if(dragEl)dragEl.style.opacity='';dragEl=null;overEl=null;});
    })();
    </script>

  <?php elseif ($mod==='solo'): /* Reuniones/Calendario/Chat/IA/Soporte: página independiente, sin menú lateral */ ?>

  <?php elseif ($mod==='fin'): $curEm=$_GET['em']??''; $isCli=isset($_GET['clientes'])||isset($_GET['cli']);
    /* Quién factura ya no está escrito aquí: sale de Ajustes › Facturación. Antes
       eran dos entradas fijas «Víctor» y «Gabi» en cada bloque, y un tercer
       autónomo no aparecía en el menú aunque tuviera facturas. */
    require_once __DIR__ . '/lib/fin_prog.php';
    $navEmis = function_exists('fin_emisores') ? fin_emisores() : [];

    $facItems=[
      'todas' =>['ic'=>'file','lb'=>'Todas','href'=>'facturas.php','on'=>$bn==='facturas.php'&&$curEm===''&&!$isCli],
    ];
    foreach($navEmis as $ek=>$en)
      $facItems['em_'.$ek]=['ic'=>'user','lb'=>$en,'href'=>'facturas.php?em='.urlencode($ek),'on'=>$bn==='facturas.php'&&$curEm===$ek];
    $facItems['cli']  = ['ic'=>'clients','lb'=>'Por cliente','href'=>'facturas.php?clientes=1','on'=>$bn==='facturas.php'&&$isCli];
    $facItems['prog'] = ['ic'=>'cal','lb'=>'Programaciones','href'=>'programaciones.php','on'=>$bn==='programaciones.php'];

    $conItems=[
      'hub'   =>['ic'=>'home','lb'=>'Hub','href'=>'contabilidad.php','on'=>$bn==='contabilidad.php'&&$curEm===''],
    ];
    foreach($navEmis as $ek=>$en)
      $conItems['em_'.$ek]=['ic'=>'user','lb'=>$en,'href'=>'contabilidad.php?em='.urlencode($ek),'on'=>$bn==='contabilidad.php'&&$curEm===$ek];
    $conItems['anal'] = ['ic'=>'chart','lb'=>'Análisis / gráficas','href'=>'contabilidad-analisis.php','on'=>$bn==='contabilidad-analisis.php'];
  ?>
    <?php /* Finanzas tenía nueve entradas sueltas y solo dos de ellas bajo un título:
             las tres de arriba flotaban sin encabezado y los «Ajustes de Finanzas»
             colgaban del final separados por una raya, así que parecían nueve cosas
             distintas en vez de cuatro áreas. Ahora las cuatro llevan título —General,
             Facturas, Contabilidad y Ajustes— y la calculadora de pricing se ha traído
             aquí desde Herramientas: sirve para poner precio a un presupuesto, que es
             trabajo de Finanzas, no una utilidad suelta al lado del chat. */ ?>
    <div class="sec">General</div>
    <?php sb_nav('fin-gen', [
      'resumen'=>['ic'=>'chart','lb'=>'Resumen mensual','href'=>'fin-resumen.php','on'=>$bn==='fin-resumen.php'],
      'proy'   =>['ic'=>'layers','lb'=>'Proyectos','href'=>'proyectos.php','on'=>$bn==='proyectos.php'],
      'horas'  =>['ic'=>'clock','lb'=>'Horas equipo','href'=>'fin-horas.php','on'=>$bn==='fin-horas.php'],
      'pricing'=>['ic'=>'calc','lb'=>'Calculadora de precios','href'=>'pricing.php','on'=>$bn==='pricing.php'],
    ]); ?>
    <div class="sec">Facturas</div>
    <?php sb_nav('fin-fac', $facItems); ?>
    <div class="sec">Contabilidad</div>
    <?php sb_nav('fin-con', $conItems); ?>
    <div class="sec">Ajustes</div>
    <div class="nav">
      <?php /* Se llamaba «Ajustes de Finanzas» y dentro había dos pestañas: los autónomos
               emisores (que también se editaban en Ajustes, con menos campos) y los datos
               fiscales de los clientes. Los emisores se han ido a Ajustes › Facturación,
               que es donde se buscan, y aquí queda lo de los clientes con su nombre. */ ?>
      <a href="fin-ajustes.php" class="<?= $bn==='fin-ajustes.php'?'on':'' ?>"><span class="ic"><?= ic('euro',17) ?></span>Facturación de clientes</a>
    </div>

  <?php else: /* roles — «Gestión y ajustes» */ ?>
    <?php /* Este es el ÚNICO menú de los ajustes.

             Antes había dos: este de la izquierda, con diez entradas sueltas, y otro
             dentro de la pantalla de Ajustes con sus cinco apartados. Los apartados de
             settings.php (Agencia, Facturación, Contacto, Vídeos, Reglas) no se veían
             desde ninguna otra pantalla, así que para ir de Servicios a Vídeos había que
             entrar antes a Ajustes y buscar allí un segundo menú; y estando dentro de
             Ajustes se veían los dos a la vez, uno al lado del otro y con entradas
             distintas cada uno.

             Ahora los cinco apartados de settings.php son entradas de este menú, como
             cualquier otra pantalla, agrupadas por zonas. Da igual que detrás haya once
             archivos PHP: se navega como un solo sitio.

             Si añades una pantalla de configuración, añádela aquí y usa el marco de
             lib/ajustes_nav.php, que ya NO pinta menú: solo el título del apartado. */
    /* La lista NO se escribe aquí: sale de aj_menu(), en lib/ajustes_nav.php, que
       es la misma que usan el título de cada apartado y el buscador. Escribirla en
       dos sitios es exactamente cómo se acaba con dos menús que no coinciden. */
    require_once __DIR__ . '/lib/ajustes_nav.php';
    $tabAct = ($bn==='settings.php') ? (string)($_GET['tab'] ?? 'agency') : '';
    $tabAct = ['emisores'=>'facturacion','portal'=>'contacto'][$tabAct] ?? $tabAct;   // alias antiguos
    $papN   = function_exists('pap_contar') ? pap_contar() : 0;
    /* Los contadores son cosa del menú, no de la lista: se añaden por encima. */
    $cntSuave = 'background:var(--soft);color:var(--label)';
    $cnts = ['papelera'=>[$papN?:null,$cntSuave]];

    foreach (aj_menu() as $zona => $items) {
      echo '<div class="sec">'.e($zona).'</div>';
      $nav = [];
      foreach ($items as $it) {
        list($k,$icono,$lb,$href) = $it;
        $bns = $it[4] ?? null;
        /* Marcado de «estás aquí»: por lista de archivos si la entrada la trae,
           por ?tab= si es un apartado de settings.php, y si no por el archivo. */
        if     ($bns !== null)                       $on = in_array($bn,$bns,true);
        elseif (strpos($href,'settings.php?tab=')===0) $on = ($tabAct === substr($href,17));
        else                                         $on = ($bn === strtok($href,'?'));
        $nav[$k] = ['ic'=>$icono,'lb'=>$lb,'href'=>$href,'on'=>$on];
        if (isset($cnts[$k]) && $cnts[$k][0]) { $nav[$k]['cnt']=$cnts[$k][0]; $nav[$k]['cntst']=$cnts[$k][1]; }
      }
      /* Una clave de bloque por zona, estable, para que el orden que arrastre
         cada usuario se guarde donde toca. */
      sb_nav('roles-'.substr(md5($zona),0,6), $nav);
    }
    ?>
  <?php endif; ?>

  <?php /* Arrastrar para reordenar CUALQUIER bloque del menú de la izquierda.
           Esto vivía solo en Finanzas; ahora es uno solo para todo el ERP y va
           aquí abajo, fuera de los `if` de módulo, para que valga igual en
           Tareas, CRM, Soporte, Herramientas y Gestión. Cada bloque es una
           isla: no se puede sacar una entrada de «Facturas» y meterla en
           «Contabilidad», porque el orden se guarda por bloque. */ ?>
  <style>
  .side .nav a .nm2{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .side .sb-sort .sb-i{cursor:grab}
  .side .sb-sort .sb-i:active{cursor:grabbing}
  .side .sb-sort .sb-i.sb-drag{opacity:.4}
  .side .sb-sort .sb-i.drop-above{box-shadow:inset 0 2px 0 #3b82f6}
  .side .sb-sort .sb-i.drop-below{box-shadow:inset 0 -2px 0 #3b82f6}
  </style>
  <script>
  /* ---- Apertura con intención de la barra negra ----
     Antes los desplegables de la barra negra se abrían con el simple :hover del
     CSS, así que al mover el ratón por la izquierda de la pantalla se abrían y
     cerraban solos de camino a otra cosa. Ahora la PRIMERA vez hay que dejar el
     cursor quieto un momento (RAIL_ESPERA); mientras ya hay uno abierto, pasar
     de un módulo a otro es instantáneo, como en cualquier menú de escritorio.
     Al salir se deja un margen corto (RAIL_CIERRE) para que un desvío del ratón
     al ir hacia el desplegable no lo cierre en la cara. */
  (function(){
    var rail=document.querySelector('.rail');
    if(rail && !rail._flyInit){
      rail._flyInit=1;
      var RAIL_ESPERA=380, RAIL_CIERRE=200;
      var abierto=null, tAbrir=null, tCerrar=null;
      function paraRelojes(){ if(tAbrir){clearTimeout(tAbrir);tAbrir=null;} if(tCerrar){clearTimeout(tCerrar);tCerrar=null;} }
      function mostrar(it){
        if(abierto===it)return;
        if(abierto)abierto.classList.remove('fly-on');
        abierto=it;
        if(it)it.classList.add('fly-on');
      }
      function cerrarLuego(){ paraRelojes(); tCerrar=setTimeout(function(){tCerrar=null;mostrar(null);},RAIL_CIERRE); }
      rail.querySelectorAll('.rail-item').forEach(function(it){
        it.addEventListener('mouseenter',function(){
          paraRelojes();
          if(!it.querySelector('.rail-fly')){ if(abierto)cerrarLuego(); return; }
          if(abierto){ mostrar(it); return; }            /* ya hay uno abierto: al momento */
          tAbrir=setTimeout(function(){tAbrir=null;mostrar(it);},RAIL_ESPERA);
        });
        it.addEventListener('mouseleave',cerrarLuego);
      });
      rail.addEventListener('mouseleave',cerrarLuego);
      /* Al pulsar un enlace o fuera de la barra, el menú se va sin esperas. PERO si
         pulsas DENTRO del desplegable algo que no es un enlace (el chevron de plegar
         una carpeta o una cabecera de sección), NO se cierra: así se puede abrir y
         cerrar las carpetas del acordeón sin que desaparezca el menú en la cara. */
      document.addEventListener('click',function(e){
        var enFly=e.target.closest&&e.target.closest('.rail-fly');
        var esEnlace=e.target.closest&&e.target.closest('a[href]');
        if(enFly && !esEnlace) return;
        paraRelojes(); mostrar(null);
      });
      document.addEventListener('keydown',function(e){ if(e.key==='Escape'){ paraRelojes(); mostrar(null); } });
    }
  })();
  (function(){
    var side=document.querySelector('.side');
    if(!side||side._sbSort)return; side._sbSort=1;
    var dragEl=null,overEl=null,pos='';
    function clr(){side.querySelectorAll('.sb-i.drop-above,.sb-i.drop-below').forEach(function(x){x.classList.remove('drop-above','drop-below');});}
    function reset(){clr();if(dragEl)dragEl.classList.remove('sb-drag');dragEl=null;overEl=null;pos='';}
    side.addEventListener('dragstart',function(e){
      var a=e.target.closest('.sb-sort > .sb-i'); if(!a)return;
      dragEl=a; e.dataTransfer.effectAllowed='move';
      try{e.dataTransfer.setData('text/plain','x');}catch(_){}
      setTimeout(function(){if(dragEl===a)a.classList.add('sb-drag');},0);
    });
    side.addEventListener('dragend',reset);
    side.addEventListener('dragover',function(e){
      if(!dragEl)return;
      /* Mientras se arrastra una entrada del menú, el navegador no debe hacer
         lo suyo (abrir el enlace soltado). Por eso se corta siempre, aunque
         no haya un destino válido debajo del cursor. */
      e.preventDefault(); e.dataTransfer.dropEffect='move';
      var a=e.target.closest('.sb-i');
      if(!a||a===dragEl||a.parentNode!==dragEl.parentNode){clr();overEl=null;return;}
      clr();
      var r=a.getBoundingClientRect(), after=(e.clientY-r.top)>r.height/2;
      a.classList.add(after?'drop-below':'drop-above'); overEl=a; pos=after?'after':'before';
    });
    side.addEventListener('drop',function(e){
      if(!dragEl)return;
      e.preventDefault(); e.stopPropagation();
      if(!overEl){reset();return;}
      var nav=dragEl.parentNode;
      nav.insertBefore(dragEl, pos==='after'?overEl.nextSibling:overEl);
      var ids=[].slice.call(nav.children).filter(function(x){return x.classList&&x.classList.contains('sb-i');})
                 .map(function(x){return x.dataset.key;});
      /* El token CSRF lo pone solo el envoltorio de fetch() de erp_foot. */
      fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=nav_reorder&nav='+encodeURIComponent(nav.dataset.nav)+ids.map(function(k){return '&order[]='+encodeURIComponent(k);}).join('')}).catch(function(){});
      reset();
    });
    /* Soltar fuera del menú: se cancela, no se navega ni se guarda nada. */
    document.addEventListener('dragover',function(e){if(dragEl)e.preventDefault();});
    document.addEventListener('drop',function(e){if(dragEl){e.preventDefault();reset();}});
  })();
  </script>

  <div class="foot">
    <?php /* El nombre/avatar te lleva a TU perfil en modo cuenta (se ven los Ajustes:
             Cuenta y Avisos). El engranaje, aparte, abre la página de Ajustes. Antes
             todo el bloque era un único enlace al perfil social. */ ?>
    <div class="urow">
      <a class="u" href="perfil.php?id=<?= (int)$me['id'] ?>&modo=cuenta" title="Tu cuenta"><div class="av" data-uid="<?= (int)$me['id'] ?>" style="background:<?= avatar_color($me['username']) ?>"><?= e(mb_strtoupper(mb_substr($me['username'],0,2))) ?></div><div class="ui"><b><?= e($me['username']) ?></b><span><?= e(role_label(admin_role())) ?></span></div></a>
      <a class="gear" href="settings.php" title="Ajustes"><?= ic('settings',16) ?></a>
    </div>
    <form class="salir" method="post" action="logout.php"><?= csrf_field() ?><button class="out" type="submit"><?= ic('logout',15) ?> Cerrar sesión</button></form>
  </div>
</aside>
<?php }

function erp_head($active = '', $title = '', $bodyClass = '') { $GLOBALS['erp_active']=$active; ?>
<!DOCTYPE html><html lang="es"><head><script>/* Aplica el tema guardado antes de pintar, para que no haya un parpadeo blanco al cargar en oscuro. */(function(){try{if(localStorage.getItem('erpTheme')==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<?php /* El nombre sale de Ajustes → Agencia: si alguien renombra la agencia, la
         pestaña del navegador no puede seguir diciendo otra cosa. */ ?>
<title><?= e($title ?: (marca_agencia()['name'] . ' ERP')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/emoji/emoji.css?v=2">
<style><?php erp_css(); ?></style></head>
<?php /* $bodyClass permite, p.ej., abrir una página con el menú lateral recogido (side-collapse). */ ?>
<?php /* El CRM nacía con el menú lateral encogido a una tira de 16px, y era el
   único módulo que lo hacía: las pestañas Contactos, Negocio, Dashboard y
   Automatizaciones quedaban invisibles salvo que supieras pasar el ratón por
   encima. Se hacía por el ancho de la tabla, y eso ya está resuelto: la tabla
   se desplaza por dentro de su propia caja. */ ?>
<body<?= $bodyClass ? ' class="'.e($bodyClass).'"' : '' ?>>
<?php erp_sidebar($active); ?>
<div class="main">
  <div class="erp-top"><button type="button" class="nav-burger" onclick="document.body.classList.toggle('nav-open')" aria-label="Abrir menú" title="Menú"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button><button type="button" class="tsearch" onclick="gsOpen()" title="Buscar en todo el ERP (Ctrl + K)"><?= ic('search',15) ?> <span>Buscar clientes, tareas, facturas…</span><kbd>Ctrl K</kbd></button><div class="sp"></div><button type="button" class="tbtn theme-tgl" onclick="erpToggleTheme()" title="Modo claro / oscuro" aria-label="Cambiar entre modo claro y oscuro"><svg class="ic-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg><svg class="ic-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.4v2.6M12 19v2.6M4.2 4.2l1.9 1.9M17.9 17.9l1.9 1.9M2.4 12h2.6M19 12h2.6M4.2 19.8l1.9-1.9M17.9 6.1l1.9-1.9"/></svg></button><a class="tbtn" href="../login.php" target="_blank"><?= ic('eye',15) ?> Portal cliente</a><?php /* «Administrar Roles» es un atajo a Mi equipo: solo para quien gestiona el equipo. Al resto le llevaba a un 403 desde la barra de TODAS las pantallas. */ if(!function_exists('can') || can('equipo.gestionar')): ?><a class="tbtn" href="team.php"><?= ic('user',15) ?> Administrar Roles</a><?php endif; ?></div>
  <div id="navBackdrop" onclick="document.body.classList.remove('nav-open')"></div>
  <div class="erp-wrap">
<?php }

function erp_foot() { ?>
</div></div>
<script>
/* ===== Tema claro / oscuro =====
   Interruptor manual (botón sol/luna de la barra superior). Guarda la elección en
   el navegador; el <head> la vuelve a aplicar en la siguiente carga sin parpadeo. */
window.erpToggleTheme=function(){var h=document.documentElement,esOscuro=h.getAttribute('data-theme')==='dark';
  var aplicar=function(){if(esOscuro){h.removeAttribute('data-theme');}else{h.setAttribute('data-theme','dark');}try{localStorage.setItem('erpTheme',esOscuro?'light':'dark');}catch(e){}};
  try{var b=document.querySelector('.theme-tgl');if(b){var r=b.getBoundingClientRect();h.style.setProperty('--tx',(r.left+r.width/2)+'px');h.style.setProperty('--ty',(r.top+r.height/2)+'px');}}catch(e){}
  if(document.startViewTransition&&!matchMedia('(prefers-reduced-motion:reduce)').matches){document.startViewTransition(aplicar);}else{aplicar();}
};
</script>
<script>
/* ===== CSRF global =====
   1) Envuelve fetch() para añadir la cabecera X-CSRF-Token en todo POST del mismo origen.
   2) Inyecta el campo oculto _csrf en todos los <form method="post">, también en los
      que se creen después (MutationObserver), para no tener que tocar página por página. */
(function(){
  var meta=document.querySelector('meta[name="csrf-token"]');
  var TOKEN=meta?meta.getAttribute('content'):'';
  if(!TOKEN)return;
  window.CSRF_TOKEN=TOKEN;

  var _fetch=window.fetch;
  window.fetch=function(input,init){
    init=init||{};
    var url=(typeof input==='string')?input:(input&&input.url)||'';
    var method=(init.method||(input&&input.method)||'GET').toUpperCase();
    var sameOrigin=!/^https?:\/\//i.test(url)||url.indexOf(location.origin)===0;
    if(method!=='GET'&&method!=='HEAD'&&sameOrigin){
      var h=init.headers;
      if(h instanceof Headers){ if(!h.has('X-CSRF-Token')) h.set('X-CSRF-Token',TOKEN); }
      else { init.headers=Object.assign({'X-CSRF-Token':TOKEN},h||{}); }
      /* Si el cuerpo es FormData o URLSearchParams añadimos también el campo,
         por si algún handler lee $_POST['_csrf'] directamente. */
      if(init.body instanceof FormData && !init.body.has('_csrf')) init.body.append('_csrf',TOKEN);
      else if(init.body instanceof URLSearchParams && !init.body.has('_csrf')) init.body.append('_csrf',TOKEN);
    }
    return _fetch.call(this,input,init);
  };

  function stampForm(f){
    if(f.__csrf)return;
    var m=(f.getAttribute('method')||'GET').toUpperCase();
    if(m!=='POST')return;
    if(f.querySelector('input[name="_csrf"]')){f.__csrf=1;return;}
    var i=document.createElement('input');
    i.type='hidden'; i.name='_csrf'; i.value=TOKEN;
    f.appendChild(i); f.__csrf=1;
  }
  function stampAll(root){
    var fs=(root||document).querySelectorAll?(root||document).querySelectorAll('form'):[];
    for(var i=0;i<fs.length;i++) stampForm(fs[i]);
  }
  window.csrfStampForms=stampAll;
  document.addEventListener('DOMContentLoaded',function(){ stampAll(document); });
  stampAll(document);
  /* También al enviar. Los formularios que se crean más tarde -al abrir una
     ficha, un modal, una fila nueva- no estaban en el DOM cuando se selló la
     página, y sin esto su POST llegaría sin token y saldría con un 419. */
  document.addEventListener('submit',function(ev){ if(ev.target&&ev.target.tagName==='FORM') stampForm(ev.target); },true);
  try{
    new MutationObserver(function(muts){
      for(var i=0;i<muts.length;i++){
        var ns=muts[i].addedNodes;
        for(var j=0;j<ns.length;j++){
          var n=ns[j];
          if(n.nodeType!==1)continue;
          if(n.tagName==='FORM') stampForm(n);
          else stampAll(n);
        }
      }
    }).observe(document.documentElement,{childList:true,subtree:true});
  }catch(e){}
  /* Último recurso: al enviar cualquier formulario POST, sellarlo justo antes. */
  document.addEventListener('submit',function(ev){
    var f=ev.target;
    if(f&&f.tagName==='FORM'){ f.__csrf=0; stampForm(f); }
  },true);
})();

/* Datepicker global reutilizable: inputs con class "dpick" (data-iso, opcional data-sync=selector oculto, data-onchange=fn global). */
(function(){var cal=null,curInp=null,ym=null;
  function pad(n){return(n<10?'0':'')+n;}
  function isoDisp(iso){if(!iso)return'';var p=(''+iso).split('-');if(p.length!==3)return'';return p[2]+'/'+p[1]+'/'+p[0].slice(2);}
  function parse(s){s=(s||'').trim();if(!s)return'';var m=s.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/);if(!m)return null;var d=+m[1],mo=+m[2],y=+m[3];if(y<100)y+=2000;if(mo<1||mo>12||d<1||d>31)return null;return y+'-'+pad(mo)+'-'+pad(d);}
  function ensureCal(){if(cal)return;cal=document.createElement('div');cal.id='dpCal';cal.onmousedown=function(e){e.preventDefault();};document.body.appendChild(cal);}
  function open(inp){ensureCal();curInp=inp;var iso=inp.dataset.iso||'';var base=iso?new Date(iso+'T00:00:00'):new Date();ym=new Date(base.getFullYear(),base.getMonth(),1);render();var r=inp.getBoundingClientRect();cal.style.left=Math.min(Math.max(6,r.left),window.innerWidth-262)+'px';cal.style.top=Math.min(r.bottom+6,window.innerHeight-330)+'px';cal.classList.add('on');}
  function close(){if(cal)cal.classList.remove('on');}
  window.__dpNav=function(d){ym=new Date(ym.getFullYear(),ym.getMonth()+d,1);render();};
  window.__dpPick=function(iso){if(curInp)apply(curInp,iso);close();};
  function render(){var y=ym.getFullYear(),mo=ym.getMonth();var M=['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];var sel=curInp&&curInp.dataset.iso?curInp.dataset.iso:'';var td=new Date();var todayIso=td.getFullYear()+'-'+pad(td.getMonth()+1)+'-'+pad(td.getDate());
    var h='<div class="dp-h"><button type="button" class="dp-nav" onclick="__dpNav(-1)">‹</button><span>'+M[mo]+' '+y+'</span><button type="button" class="dp-nav" onclick="__dpNav(1)">›</button></div><div class="dp-grid dp-dow"><span>L</span><span>M</span><span>X</span><span>J</span><span>V</span><span>S</span><span>D</span></div><div class="dp-grid">';
    var start=(new Date(y,mo,1).getDay()+6)%7;var days=new Date(y,mo+1,0).getDate();var i;for(i=0;i<start;i++)h+='<span></span>';
    for(var d=1;d<=days;d++){var iso=y+'-'+pad(mo+1)+'-'+pad(d);var cls='dp-d';if(iso===sel)cls+=' sel';if(iso===todayIso)cls+=' today';h+='<button type="button" class="'+cls+'" onclick="__dpPick(\''+iso+'\')">'+d+'</button>';}
    h+='</div><div class="dp-foot"><button type="button" class="dp-clr" onclick="__dpPick(\'\')">Borrar</button><button type="button" class="dp-tod" onclick="__dpPick(\''+todayIso+'\')">Hoy</button></div>';cal.innerHTML=h;}
  function apply(inp,iso){inp.dataset.iso=iso;inp.value=isoDisp(iso);if(inp.dataset.sync){var s=document.querySelector(inp.dataset.sync);if(s)s.value=iso;}if(inp.dataset.onchange&&window[inp.dataset.onchange])window[inp.dataset.onchange](iso,inp);}
  function commit(inp){var v=(inp.value||'').trim();if(v===''){if((inp.dataset.iso||'')!==''){apply(inp,'');}return;}var iso=parse(v);if(iso===null){inp.value=isoDisp(inp.dataset.iso||'');return;}apply(inp,iso);}
  function attach(inp){if(inp.__dp)return;inp.__dp=1;inp.setAttribute('autocomplete','off');if(!inp.getAttribute('placeholder'))inp.setAttribute('placeholder','dd/mm/aa');if(!inp.value&&inp.dataset.iso)inp.value=isoDisp(inp.dataset.iso);
    inp.addEventListener('focus',function(){open(inp);});inp.addEventListener('click',function(){open(inp);});
    inp.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();inp.blur();}});inp.addEventListener('blur',function(){commit(inp);});}
  window.dpScan=function(root){(root||document).querySelectorAll('input.dpick').forEach(attach);};
  /* Para rellenar una fecha desde JS (los paneles que se cargan por AJAX y los
     modales que se abren con datos ya guardados). Pone el valor visible y el
     oculto sincronizado, y a propósito NO dispara el data-onchange: abrir un
     panel no es editarlo, y si lo disparara se guardaría solo al abrirlo. */
  window.dpSet=function(inp,iso){if(!inp)return;attach(inp);iso=iso||'';inp.dataset.iso=iso;inp.value=isoDisp(iso);if(inp.dataset.sync){var s=document.querySelector(inp.dataset.sync);if(s)s.value=iso;}};
  /* Cierre al hacer clic fuera. OJO: las flechas de mes hacen innerHTML y dejan el
     botón pulsado DESPRENDIDO del DOM; ese click sigue burbujeando hasta aquí y, como
     el nodo ya no cuelga de #dpCal, closest() daba null y se interpretaba como "clic
     fuera" → el calendario desaparecía al cambiar de mes. Con e.target.isConnected
     ignoramos los nodos que nuestro propio repintado acaba de quitar. */
  document.addEventListener('click',function(e){if(cal&&cal.classList.contains('on')&&e.target.isConnected&&!e.target.closest('#dpCal')&&!(e.target.classList&&e.target.classList.contains('dpick')))close();});
  if(document.readyState!=='loading')window.dpScan();else document.addEventListener('DOMContentLoaded',function(){window.dpScan();});
})();
</script>
<script>
/* Custom dropdown global: reemplaza el desplegable nativo por un menú con estilo (mantiene el <select> por debajo). */
(function(){var openWrap=null;
  function chev(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';}
  function closeOpen(){if(openWrap){openWrap.classList.remove('open');var p=openWrap.querySelector('.cs-pop');if(p)p.classList.remove('on');openWrap=null;}}
  function enhance(sel){
    if(sel.dataset.cs||sel.multiple)return;
    if(sel.classList.contains('cell')||sel.classList.contains('ed')||sel.classList.contains('plain')||sel.classList.contains('cs-native'))return;
    if(sel.closest&&sel.closest('.cs-wrap'))return;
    var w=sel.offsetWidth, pw=(sel.parentNode?sel.parentNode.clientWidth:0);
    sel.dataset.cs='1';
    var wrap=document.createElement('span');wrap.className='cs-wrap';
    sel.parentNode.insertBefore(wrap,sel);wrap.appendChild(sel);
    wrap.style.width=(w>0&&pw>0&&w>=pw-6)?'100%':(w>0?w+'px':'100%');
    sel.classList.add('cs-native');
    var trig=document.createElement('button');trig.type='button';trig.className='cs-trig';
    Array.prototype.forEach.call(sel.classList,function(c){if(c!=='cs-native'&&c!=='cs')trig.classList.add(c);});
    if(sel.style.color)trig.style.color=sel.style.color;
    var lbl=document.createElement('span');lbl.className='cs-lbl';
    var arw=document.createElement('span');arw.className='cs-arw';arw.innerHTML=chev();
    trig.appendChild(lbl);trig.appendChild(arw);wrap.appendChild(trig);
    var pop=document.createElement('div');pop.className='cs-pop';wrap.appendChild(pop);
    function sync(){var o=sel.options[sel.selectedIndex];lbl.textContent=o?o.textContent:'';if(sel.style.color)trig.style.color=sel.style.color;trig.classList.toggle('dis',sel.disabled);}
    function render(){pop.innerHTML='';Array.prototype.forEach.call(sel.options,function(o,i){var it=document.createElement('div');it.className='cs-opt'+(i===sel.selectedIndex?' on':'');it.textContent=o.textContent;it.onmousedown=function(e){e.preventDefault();e.stopPropagation();sel.selectedIndex=i;sync();var ev;try{ev=new Event('change',{bubbles:true});}catch(_){ev=document.createEvent('HTMLEvents');ev.initEvent('change',true,false);}sel.dispatchEvent(ev);closeOpen();};pop.appendChild(it);});}
    function place(){var r=trig.getBoundingClientRect();pop.style.minWidth=r.width+'px';pop.style.left=Math.max(8,Math.min(r.left,window.innerWidth-Math.max(r.width,160)-8))+'px';if(r.bottom+292>window.innerHeight){pop.style.top='';pop.style.bottom=(window.innerHeight-r.top+4)+'px';}else{pop.style.bottom='';pop.style.top=(r.bottom+4)+'px';}}
    function open(){if(sel.disabled)return;closeOpen();render();place();wrap.classList.add('open');pop.classList.add('on');openWrap=wrap;}
    /* Se guardan para poder recolocar el desplegable cuando algo se desplaza por
       debajo, en vez de cerrarlo de golpe. */
    wrap._csPlace=place;wrap._csTrig=trig;
    trig.onclick=function(e){e.stopPropagation();e.preventDefault();wrap.classList.contains('open')?closeOpen():open();};
    sel.addEventListener('change',sync);sel._csSync=sync;sync();
  }
  window.csEnhance=function(root){try{(root||document).querySelectorAll('select').forEach(enhance);}catch(e){}};
  window.csRefreshAll=function(){try{document.querySelectorAll('select.cs-native').forEach(function(s){if(s._csSync)s._csSync();});}catch(e){}};
  document.addEventListener('click',function(e){if(!e.target.closest('.cs-wrap'))closeOpen();});
  /* Antes esto era closeOpen() a secas en fase de captura: CUALQUIER desplazamiento
     interno de la página (la tabla del CRM, el menú lateral, una columna del tablero)
     cerraba el desplegable que acabaras de abrir. Como la lista va posicionada
     respecto a la ventana, lo correcto no es cerrarla sino volver a colocarla donde
     esté ahora el botón; y solo cerrarla si el botón se ha ido de la pantalla. */
  var csTick=false;
  window.addEventListener('scroll',function(){
    if(!openWrap||csTick)return;csTick=true;
    requestAnimationFrame(function(){csTick=false;if(!openWrap)return;
      var t=openWrap._csTrig;if(!t){closeOpen();return;}
      var r=t.getBoundingClientRect();
      if(r.bottom<0||r.top>window.innerHeight||r.right<0||r.left>window.innerWidth){closeOpen();return;}
      if(openWrap._csPlace)openWrap._csPlace();
    });
  },true);
  if(document.readyState!=='loading')window.csEnhance();else document.addEventListener('DOMContentLoaded',function(){window.csEnhance();});
})();
</script>
<style>
#erpToasts{position:fixed;right:18px;bottom:18px;z-index:1200;display:flex;flex-direction:column;gap:8px;align-items:flex-end}
.erp-toast{background:#22262c;color:#fff;font-size:13px;font-weight:600;padding:11px 15px;border-radius:11px;box-shadow:0 12px 34px rgba(0,0,0,.24);opacity:0;transform:translateY(10px);transition:opacity .2s,transform .2s;max-width:320px;display:flex;align-items:center;gap:9px}
.erp-toast.show{opacity:1;transform:translateY(0)}
.erp-toast::before{content:"✓";display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#12a150;color:#fff;font-size:11px;flex:none}
.erp-toast.err{background:#c0343a}.erp-toast.err::before{content:"!";background:rgba(255,255,255,.25)}
.erp-toast.plain::before{display:none}
/* «Deshacer» dentro del propio aviso: la papelera está a un clic sin salir de la página. */
.erp-toast .undo{border:none;background:rgba(255,255,255,.16);color:#fff;font:inherit;font-size:12.5px;font-weight:700;padding:5px 11px;border-radius:8px;cursor:pointer;flex:none;margin-left:2px}
.erp-toast .undo:hover{background:rgba(255,255,255,.28)}
/* Popup de notificación en vivo: más grande y pulsable, dura unos segundos. */
.erp-notif{align-items:flex-start;gap:11px;max-width:340px;padding:13px 15px;cursor:pointer}
.erp-notif::before{display:none}
.erp-notif:hover{background:#2c313a}
.erp-notif .np-ic{flex:none;width:30px;height:30px;border-radius:9px;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center}
.erp-notif .np-ic svg{width:16px;height:16px;stroke:#fff;fill:none;stroke-width:2}
.erp-notif .np-t{font-weight:650;line-height:1.35}
.erp-notif .np-t b{font-weight:800}
.erp-notif .np-b{font-weight:500;color:#c7ccd4;font-size:12px;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:280px}
.erp-notif .np-x{margin-left:auto;flex:none;border:none;background:none;color:#8a9099;cursor:pointer;font-size:14px;padding:0 2px;line-height:1}
.erp-notif .np-x:hover{color:#fff}
</style>
<div id="erpToasts"></div>
<script>
window.toast=function(msg,type){var c=document.getElementById('erpToasts');if(!c)return;
  var t=document.createElement('div');t.className='erp-toast'+(type==='err'?' err':(type==='plain'?' plain':''));t.textContent=msg;c.appendChild(t);
  requestAnimationFrame(function(){t.classList.add('show');});
  setTimeout(function(){t.classList.remove('show');setTimeout(function(){if(t.parentNode)t.parentNode.removeChild(t);},260);},2200);};

/* Popup de una notificación que acaba de llegar (aviso en vivo). Es un toast más
   grande, con icono de campana, pulsable (te lleva al origen) y con «×» para
   descartarlo; dura ~6 s. Lo dispara el sondeo de la campana. */
window.notifPopup=function(n){
  var c=document.getElementById('erpToasts'); if(!c||!n) return;
  var t=document.createElement('div'); t.className='erp-toast erp-notif show';
  var actor=n.actor?('<b>'+window.escHtml(n.actor)+'</b> '):'';
  var body=n.cuerpo?('<div class="np-b">'+window.escHtml(n.cuerpo)+'</div>'):'';
  t.innerHTML='<span class="np-ic"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg></span>'
    +'<div><div class="np-t">'+actor+window.escHtml(n.titulo||'Nueva notificación')+'</div>'+body+'</div>'
    +'<button class="np-x" type="button" aria-label="Cerrar">✕</button>';
  var cerrar=function(){ t.classList.remove('show'); setTimeout(function(){ if(t.parentNode)t.parentNode.removeChild(t); },280); };
  t.querySelector('.np-x').addEventListener('click',function(e){ e.stopPropagation(); cerrar(); });
  if(n.url) t.addEventListener('click',function(){ window.location=n.url; });
  c.appendChild(t);
  setTimeout(cerrar, 6000);
};

/* Aviso con «Deshacer»: se usa después de borrar algo que ha ido a la papelera.
   tid = id que devuelve pap_borrar(). o.then se llama si la restauración sale
   bien (normalmente para recargar o volver a pintar la fila). */
window.toastUndo=function(msg,tid,o){
  o=o||{}; var c=document.getElementById('erpToasts'); if(!c){ if(window.toast)toast(msg); return; }
  var t=document.createElement('div'); t.className='erp-toast';
  var s=document.createElement('span'); s.textContent=msg; t.appendChild(s);
  var b=document.createElement('button'); b.className='undo'; b.type='button'; b.textContent='Deshacer'; t.appendChild(b);
  c.appendChild(t); requestAnimationFrame(function(){t.classList.add('show');});
  var fuera=setTimeout(cerrar, o.ms||7000);
  function cerrar(){ clearTimeout(fuera); t.classList.remove('show'); setTimeout(function(){ if(t.parentNode)t.parentNode.removeChild(t); },260); }
  b.addEventListener('click',function(){
    b.disabled=true; b.textContent='…';
    fetch('papelera.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=restore&json=1&tid='+encodeURIComponent(tid)})
      .then(function(r){return r.json();})
      .then(function(d){
        cerrar();
        if(!d||!d.ok){ toast((d&&d.msg)||'No se ha podido deshacer','err'); return; }
        if(typeof o.then==='function') o.then(d); else location.reload();
      })
      .catch(function(){ cerrar(); toast('No se ha podido deshacer','err'); });
  });
  return t;
};
</script>

<style>
/* ===== Diálogos globales (sustituyen a confirm() y prompt() nativos) ===== */
#erpDlgOv{position:fixed;inset:0;z-index:2000;background:rgba(16,19,24,.34);backdrop-filter:saturate(140%) blur(2px);display:none;align-items:center;justify-content:center;padding:20px;opacity:0;transition:opacity .16s ease}
#erpDlgOv.on{display:flex}
#erpDlgOv.vis{opacity:1}
#erpDlgOv .dlg{background:#fff;border-radius:16px;width:100%;max-width:380px;box-shadow:0 24px 70px -18px rgba(16,19,24,.45),0 0 0 1px rgba(16,19,24,.05);padding:22px 22px 16px;transform:translateY(8px) scale(.985);transition:transform .18s cubic-bezier(.2,.7,.3,1)}
#erpDlgOv.vis .dlg{transform:none}
#erpDlgOv .dlg h4{font-size:15.5px;font-weight:650;letter-spacing:-.2px;color:var(--ink-strong);margin-bottom:6px}
#erpDlgOv .dlg p{font-size:13px;color:#6b7078;line-height:1.55;margin-bottom:16px;white-space:pre-line}
#erpDlgOv .dlg input[type=text]{width:100%;border:1px solid var(--line);background:var(--soft);border-radius:10px;padding:10px 12px;font:inherit;font-size:13.5px;color:var(--ink);margin-bottom:16px;outline:none}
#erpDlgOv .dlg input[type=text]:focus{border-color:#c9ccd1;background:#fff;box-shadow:0 0 0 3px rgba(31,35,42,.06)}
#erpDlgOv .dlg .row{display:flex;gap:8px;justify-content:flex-end}
#erpDlgOv .dlg button{border:0;font:inherit;font-size:13px;font-weight:600;padding:9px 15px;border-radius:10px;cursor:pointer;transition:background-color .14s ease,opacity .14s ease}
#erpDlgOv .dlg .no{background:var(--soft);color:#5a5f68}
#erpDlgOv .dlg .no:hover{background:#eeeef0}
#erpDlgOv .dlg .yes{background:var(--ink-strong);color:#fff}
#erpDlgOv .dlg .yes:hover{background:#000}
#erpDlgOv .dlg .yes.danger{background:#c0343a}
#erpDlgOv .dlg .yes.danger:hover{background:#a82c31}
/* ═══════════ MÓVIL (≤640px): remate de los CIMIENTOS globales ═══════════
   Este bloque va al final del CSS A PROPÓSITO: los diálogos, toasts, .cs-pop,
   #dpCal y los menús contextuales se definen antes, así que estas reglas —a igual
   especificidad— ganan por orden de origen. Solo AÑADE tacto y ancho en teléfono;
   no toca el diseño de escritorio ni los colores. */
@media(max-width:640px){
  /* Kit de diálogos: casi todo el ancho, centrado, botones táctiles apilados
     (el primario arriba con column-reverse), y 16px en el campo para que iOS no
     haga zoom al enfocar. */
  #erpDlgOv{padding:16px}
  #erpDlgOv .dlg{max-width:none;width:min(420px,94vw);padding:20px 18px 15px}
  #erpDlgOv .dlg h4{font-size:16px}
  #erpDlgOv .dlg p{font-size:13.5px}
  #erpDlgOv .dlg input[type=text]{font-size:16px;padding:12px}
  #erpDlgOv .dlg .row{flex-direction:column-reverse;gap:9px}
  #erpDlgOv .dlg button{width:100%;min-height:46px;padding:12px 15px;font-size:14px}
  /* Toasts y popup de aviso en vivo: a lo ancho con márgenes, sin taparse. */
  #erpToasts{left:10px;right:10px;bottom:12px;align-items:stretch}
  .erp-toast,.erp-notif{max-width:none}
  /* Desplegable propio y datepicker: opciones y días cómodos de tocar. */
  .cs-opt{padding:12px 13px;font-size:14px}
  #dpCal .dp-d{height:36px;font-size:13.5px}
  #dpCal .dp-nav{width:34px;height:34px}
  #dpCal .dp-clr,#dpCal .dp-tod{padding:8px 12px}
  /* Menús contextuales y menú de listas: filas de ~42px, campo sin zoom. */
  .ctxmenu a,.ctxmenu button{padding:11px 13px;font-size:14px}
  .addlm .alm-opt{padding:11px 13px;font-size:14px}
  .addlm .alm-h{padding:9px 12px 6px}
  .addlm .alm-form input{padding:11px 12px;font-size:16px}
  .addlm .alm-create{padding:11px 14px}
  /* Buscador global (Ctrl+K): resultados amplios y lista más alta. */
  #gsOv{padding-top:7vh}
  #gsOv .gs-in{padding:13px 15px}
  #gsOv .gs-res{max-height:64vh}
  #gsOv .gs-r{padding:13px 12px}
}
@media(max-width:400px){
  /* Barra superior muy estrecha: hamburguesa + buscador (icono) + tema siguen cabiendo. */
  .erp-top{padding:0 10px;gap:6px}
}
</style>
<div id="erpDlgOv"><div class="dlg" role="dialog" aria-modal="true">
  <h4 id="erpDlgT"></h4><p id="erpDlgM"></p>
  <input type="text" id="erpDlgI" style="display:none">
  <div class="row"><button type="button" class="no" id="erpDlgNo">Cancelar</button><button type="button" class="yes" id="erpDlgYes">Aceptar</button></div>
</div></div>

<?php /* Popup GLOBAL de «Agendar reunión»: el mismo en la ficha del cliente, en el CRM y
         donde haga falta. No navega: crea la reunión (y el evento de Google) por AJAX. */
require_once __DIR__ . '/lib/logos.php'; ?>
<style>
#erpAgMask,#erpTkMask,#erpTaMask{position:fixed;inset:0;background:rgba(16,19,24,.38);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:1200;padding:20px;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .2s ease,visibility .2s ease,backdrop-filter .2s ease}
#erpAgMask.on,#erpTkMask.on,#erpTaMask.on{opacity:1;visibility:visible;pointer-events:auto}
.erpag{background:#fff;border-radius:18px;width:440px;max-width:100%;box-shadow:0 30px 80px rgba(0,0,0,.35);overflow:hidden;transform:translateY(10px) scale(.985);opacity:0;transition:transform .2s cubic-bezier(.2,.8,.2,1),opacity .2s ease}
#erpAgMask.on .erpag,#erpTkMask.on .erpag,#erpTaMask.on .erpag{transform:none;opacity:1}
.erpag textarea,.erpag select{width:100%;border:1px solid var(--line);border-radius:11px;padding:11px 13px;font-size:14px;font-family:inherit;outline:none;color:var(--ink);background:#fff;resize:vertical}
.erpag-row>div{min-width:0}
.erpag :is(input,select,button){min-width:0;max-width:100%}
.erpag textarea:focus,.erpag select:focus{border-color:#0071e3;box-shadow:0 0 0 3px rgba(0,113,227,.12)}
.erpag-logo{width:44px;height:44px;border-radius:12px;background:#fff;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;flex:none}
.erpag-ht{display:flex;flex-direction:column;gap:2px}
.erpag-ht b{font-size:16px;font-weight:700;color:var(--ink-strong)}
.erpag-ht small{display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--muted);font-weight:500}
.erpag-seg{display:flex;gap:4px;margin:14px 22px 0;background:var(--soft);border-radius:11px;padding:3px}
.erpag-seg button{flex:1;border:none;background:none;font-family:inherit;font-size:12.5px;font-weight:600;color:#6b7280;padding:8px;border-radius:8px;cursor:pointer}
.erpag-seg button.on{background:#fff;color:var(--ink-strong);box-shadow:0 1px 3px rgba(0,0,0,.08)}
.erpag-send{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:600;color:#3c4149;background:#f2f2f3;border:1px solid var(--line);border-radius:10px;padding:9px 13px;text-decoration:none}
.erpag-send:hover{background:#e9e9eb}
.erpag-send.off{opacity:.4;pointer-events:none}
.erpag-send svg{width:15px;height:15px}
@keyframes erpagin{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
@keyframes erpagPanelIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
.erpag{will-change:height}
.erpag-h{display:flex;align-items:center;gap:10px;padding:17px 22px;border-bottom:1px solid var(--line);font-size:16px;font-weight:700;color:var(--ink-strong)}
.erpag-h svg{width:18px;height:18px;color:#4285F4}
.erpag-b{padding:18px 22px;display:flex;flex-direction:column;gap:15px}
.erpag-b label{font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:6px}
.erpag-b input[type=text],.erpag-b input[type=date],.erpag-b input[type=time]{width:100%;border:1px solid var(--line);border-radius:11px;padding:11px 13px;font-size:14px;font-family:inherit;outline:none;color:var(--ink);background:#fff}
.erpag-b input:focus{border-color:#0071e3;box-shadow:0 0 0 3px rgba(0,113,227,.12)}
.erpag-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.erpag-svc{display:inline-flex;align-items:center}.erpag-svc svg{display:block}
/* Interruptores en fila (logo · texto · interruptor a la derecha), el mismo estilo
   que el formulario de Reuniones. El .sw es el interruptor global de erp_nav. */
.erpag-sws{display:flex;flex-direction:column;gap:3px;border-top:1px solid var(--line2);padding-top:6px;margin-top:2px}
label.erpag-sw{display:flex;align-items:center;gap:13px;cursor:pointer;padding:11px 10px;border-radius:12px;transition:background .13s ease;margin:0}
label.erpag-sw:hover{background:var(--soft)}
.erpag-sw .lg{flex:none;width:26px;height:26px;display:inline-flex;align-items:center;justify-content:center}
.erpag-sw .lg svg{display:block}
.erpag-sw .tx{flex:1;min-width:0}
.erpag-sw .tx b{display:block;font-size:13.5px;font-weight:600;color:var(--ink-strong)}
.erpag-sw .tx em{display:block;font-style:normal;font-size:12px;color:var(--muted);line-height:1.45;margin-top:2px}
.erpag-sw .sw{flex:none}
/* Gemini se revela solo cuando Meet está activo. `label.erpag-gem` para ganarle en
   especificidad al padding de `label.erpag-sw` (si no, al colapsar quedaba un
   pellizco de 22px del relleno sin cerrar). */
label.erpag-gem{max-height:0;opacity:0;overflow:hidden;padding-top:0;padding-bottom:0;margin-top:-3px;transition:max-height .24s ease,opacity .18s ease,padding .24s ease,margin .24s ease}
label.erpag-gem.on{max-height:82px;opacity:1;padding-top:11px;padding-bottom:11px;margin-top:0}
.erpag-hint{color:var(--muted);font-weight:400;font-size:12px}
.erpta-resp{width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;border:1px solid var(--line);border-radius:11px;padding:9px 12px;background:#fff;font-family:inherit;font-size:14px;cursor:pointer;color:var(--ink)}
.erpta-resp:hover{border-color:#d5d7dc}
.erpta-respview{display:flex;align-items:center;gap:8px;min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.erpta-av{width:24px;height:24px;border-radius:50%;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex:none}
.erpta-none{color:var(--muted)}
.erpta-resplist{position:absolute;left:0;right:0;top:calc(100% + 4px);background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 16px 40px rgba(16,19,24,.16);padding:5px;z-index:10;max-height:210px;overflow:auto;display:none}
.erpta-resplist.on{display:block}
.erpta-opt{display:flex;align-items:center;gap:9px;padding:7px 9px;border-radius:8px;cursor:pointer;font-size:13.5px;color:var(--ink)}
.erpta-opt:hover{background:var(--soft)}
.erpag-env{display:flex;align-items:center;gap:7px;font-size:11.5px;color:var(--muted);margin-top:2px}.erpag-env svg{display:block}
.erpag-f{display:flex;justify-content:flex-end;gap:10px;padding:15px 22px;border-top:1px solid var(--line)}
.erpag-btn{border:none;border-radius:11px;padding:10px 18px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit}
.erpag-btn.g{background:#f2f2f3;color:var(--ink)}.erpag-btn.g:hover{background:#e9e9eb}
.erpag-btn.p{background:#18181b;color:#fff}.erpag-btn.p:hover{background:#000}
.erpag-btn:disabled{opacity:.6;cursor:default}
</style>
<div id="erpAgMask" onclick="if(event.target===this)erpAgClose()">
  <div class="erpag">
    <div class="erpag-h"><span class="erpag-logo"><?= svc_logo('meet',30) ?></span><span class="erpag-ht"><b>Agendar reunión</b><small><?= svc_logo('gcal',13) ?> Se crea en tu Google Calendar</small></span></div>
    <div class="erpag-seg">
      <button type="button" class="on" id="erpAgSegYo" onclick="erpAgMode('yo',true)">La agendo yo</button>
      <button type="button" id="erpAgSegCli" onclick="erpAgMode('cli',true)">Que elija el cliente</button>
    </div>
    <div class="erpag-b" id="erpAgPanelYo">
      <div><label>Título</label><input type="text" id="erpAgTit" placeholder="Reunión con…" autocomplete="off"></div>
      <div class="erpag-row">
        <div><label>Fecha</label><input type="date" id="erpAgFecha"></div>
        <div><label>Hora</label><input type="time" id="erpAgHora" value="10:00"></div>
      </div>
      <div><label>Invitar (correos, opcional)</label><input type="text" id="erpAgInv" placeholder="cliente@correo.com" autocomplete="off"></div>
      <div><label>Recordatorio <span class="erpag-hint">el aviso que salta antes</span></label>
        <select id="erpAgRecordar">
          <option value="">Predeterminado de Google</option>
          <option value="10">10 minutos antes</option>
          <option value="30" selected>30 minutos antes</option>
          <option value="60">1 hora antes</option>
          <option value="120">2 horas antes</option>
          <option value="1440">1 día antes</option>
          <option value="no">Sin recordatorio</option>
        </select>
      </div>
      <div class="erpag-sws">
      <label class="erpag-sw"><span class="lg"><?= svc_logo('meet',19) ?></span>
        <span class="tx"><b>Añadir videollamada de Google Meet</b></span>
        <span class="sw"><input type="checkbox" id="erpAgMeet" checked onchange="erpAgMeetTog()"><span class="tr"></span></span></label>
      <label class="erpag-sw"><span class="lg"><?= svc_logo('gcal',19) ?></span>
        <span class="tx"><b>Avisar a los invitados por correo</b><em>Les llega la invitación de Google Calendar.</em></span>
        <span class="sw"><input type="checkbox" id="erpAgNotif" checked><span class="tr"></span></span></label>
      <label class="erpag-sw erpag-gem" id="erpAgGemRow"><span class="lg"><?= svc_logo('gemini',19) ?></span>
        <span class="tx"><b>Tomar notas con Gemini</b><em>Durante la reunión de Meet.</em></span>
        <span class="sw"><input type="checkbox" id="erpAgGem"><span class="tr"></span></span></label>
      </div>
    </div>
    <div class="erpag-b" id="erpAgPanelCli" style="display:none">
      <p style="margin:0;font-size:13px;color:var(--muted);line-height:1.5">Le mandas al cliente tu enlace de reservas y él elige el hueco libre que quiera de tu agenda.</p>
      <div><label>Tu enlace de reservas</label>
        <div style="display:flex;gap:8px">
          <input type="text" id="erpAgLink" readonly value="<?= e(get_setting('meeting_url','')) ?>" style="flex:1">
          <button type="button" class="erpag-btn g" onclick="erpAgCopy()">Copiar</button>
        </div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a id="erpAgMail" class="erpag-send" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg> Enviar por email</a>
        <a id="erpAgWa" class="erpag-send" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-8.5 8.5 8.38 8.38 0 0 1-4-1L3 21l1.5-5.5a8.38 8.38 0 0 1-1-4A8.38 8.38 0 0 1 12 3a8.38 8.38 0 0 1 9 8.5z"/></svg> WhatsApp</a>
      </div>
      <div id="erpAgNoLink" style="display:none;font-size:12.5px;color:#c0343a">Aún no tienes enlace de reservas. Créalo en Google&nbsp;Calendar (<b>Crear → Horario de citas</b>) y pégalo en <b>Ajustes</b>.</div>
    </div>
    <div class="erpag-f"><button type="button" class="erpag-btn g" onclick="erpAgClose()">Cancelar</button><button type="button" class="erpag-btn p" id="erpAgGo" onclick="erpAgSave()">Agendar</button></div>
  </div>
</div>
<script>
var _erpAg={contact:0,nombre:'',email:'',wa:''};
function erpAgendar(o){o=o||{};
  _erpAg={contact:o.contactId||0,nombre:o.nombre||'',email:o.email||'',wa:(o.whatsapp||o.telefono||'')};
  erpAgMode('yo');
  document.getElementById('erpAgTit').value=o.titulo||('Reunión'+(o.nombre?(' con '+o.nombre):''));
  var d=new Date();d.setDate(d.getDate()+1);
  document.getElementById('erpAgFecha').value=d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2);
  document.getElementById('erpAgHora').value='10:00';
  document.getElementById('erpAgInv').value=o.email||'';
  document.getElementById('erpAgMeet').checked=true;
  document.getElementById('erpAgNotif').checked=true;
  document.getElementById('erpAgRecordar').value='30';
  document.getElementById('erpAgGem').checked=false; erpAgMeetTog();
  /* Modo «que elija el cliente»: preparar los botones de enviar el enlace de reservas. */
  var link=(document.getElementById('erpAgLink').value||'').trim();
  var msg='Hola'+(_erpAg.nombre?(' '+_erpAg.nombre):'')+', te paso mi enlace para agendar nuestra reunión cuando mejor te venga: '+link;
  var mail=document.getElementById('erpAgMail'), wa=document.getElementById('erpAgWa'), no=document.getElementById('erpAgNoLink');
  if(link){ no.style.display='none';
    if(_erpAg.email){ mail.href='mailto:'+encodeURIComponent(_erpAg.email)+'?subject='+encodeURIComponent('Agendar nuestra reunión')+'&body='+encodeURIComponent(msg); mail.classList.remove('off'); } else { mail.href='#'; mail.classList.add('off'); }
    var dig=(_erpAg.wa||'').replace(/[^0-9]/g,''); if(dig&&dig.length<11)dig='34'+dig;
    if(dig){ wa.href='https://wa.me/'+dig+'?text='+encodeURIComponent(msg); wa.classList.remove('off'); } else { wa.href='#'; wa.classList.add('off'); }
  } else { no.style.display='block'; mail.classList.add('off'); wa.classList.add('off'); }
  document.getElementById('erpAgMask').classList.add('on');
  setTimeout(function(){var t=document.getElementById('erpAgTit');t.focus();t.select();},40);
}
function erpAgMeetTog(){var on=document.getElementById('erpAgMeet').checked;document.getElementById('erpAgGemRow').classList.toggle('on',on);if(!on)document.getElementById('erpAgGem').checked=false;}
function erpAgMode(m,anim){var yo=m==='yo';
  var modal=document.querySelector('#erpAgMask .erpag');
  var visible=anim && modal && document.getElementById('erpAgMask').classList.contains('on');
  var h0=visible?modal.getBoundingClientRect().height:0;
  var py=document.getElementById('erpAgPanelYo'), pc=document.getElementById('erpAgPanelCli');
  py.style.display=yo?'flex':'none';
  pc.style.display=yo?'none':'flex';
  document.getElementById('erpAgSegYo').classList.toggle('on',yo);
  document.getElementById('erpAgSegCli').classList.toggle('on',!yo);
  document.getElementById('erpAgGo').style.display=yo?'':'none';
  if(visible){
    var h1=modal.getBoundingClientRect().height;
    if(Math.abs(h1-h0)>2){
      modal.style.height=h0+'px'; modal.getBoundingClientRect();
      modal.style.transition='height .3s cubic-bezier(.4,0,.2,1)';
      modal.style.height=h1+'px';
      setTimeout(function(){ modal.style.height=''; modal.style.transition=''; },320);
    }
    var pin=yo?py:pc; if(pin){ pin.style.animation='erpagPanelIn .3s ease'; setTimeout(function(){pin.style.animation='';},320); }
  }
}
function erpAgCopy(){var i=document.getElementById('erpAgLink');i.select();try{document.execCommand('copy');}catch(e){}
  if(navigator.clipboard){try{navigator.clipboard.writeText(i.value);}catch(e){}}
  if(window.toast)toast('Enlace copiado ✓');}
function erpAgClose(){document.getElementById('erpAgMask').classList.remove('on');}
function erpAgSave(){
  var tit=document.getElementById('erpAgTit').value.trim();
  var fecha=document.getElementById('erpAgFecha').value, hora=document.getElementById('erpAgHora').value;
  var inv=document.getElementById('erpAgInv').value.trim(), meet=document.getElementById('erpAgMeet').checked?'1':'0';
  if(!tit){if(window.toast)toast('Escribe un título','err');return;}
  if(!fecha){if(window.toast)toast('Elige una fecha','err');return;}
  var btn=document.getElementById('erpAgGo');btn.disabled=true;btn.textContent='Agendando…';
  var gem=document.getElementById('erpAgGem').checked?'1':'0';
  var notif=document.getElementById('erpAgNotif').checked?'1':'0';
  var recordar=document.getElementById('erpAgRecordar').value;
  fetch('agendar.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({titulo:tit,fecha:fecha,hora:hora,invitados:inv,meet:meet,gemini:gem,notificar:notif,recordar:recordar,contact_id:_erpAg.contact})}).then(function(r){return r.json();}).then(function(d){
    btn.disabled=false;btn.textContent='Agendar';
    if(d&&d.ok){erpAgClose();if(window.toast)toast('Reunión agendada ✓');if(typeof cmReload==='function')setTimeout(cmReload,300);}
    else{if(window.toast)toast((d&&d.msg)||'No se pudo agendar','err');}
  }).catch(function(){btn.disabled=false;btn.textContent='Agendar';if(window.toast)toast('Error de red','err');});
}
document.addEventListener('keydown',function(e){if(e.key==='Escape'){var m=document.getElementById('erpAgMask');if(m&&m.classList.contains('on'))erpAgClose();}});
</script>

<?php /* Popup GLOBAL de «Abrir ticket»: crea el ticket por AJAX con el cliente ya puesto. */ ?>
<div id="erpTkMask" onclick="if(event.target===this)erpTkClose()">
  <div class="erpag">
    <div class="erpag-h"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9a3 3 0 0 0 0 6v2a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2z"/></svg> Abrir ticket <span id="erpTkCli" style="font-weight:500;color:var(--muted);font-size:13px"></span></div>
    <div class="erpag-b">
      <div><label>Asunto</label><input type="text" id="erpTkAsunto" placeholder="Resumen del problema" autocomplete="off"></div>
      <div><label>Descripción</label><textarea id="erpTkCuerpo" rows="3" placeholder="Detalla la incidencia…"></textarea></div>
      <div><label>Prioridad</label><select id="erpTkPrio"><option value="1">Baja</option><option value="2" selected>Normal</option><option value="3">Alta</option><option value="4">Urgente</option></select></div>
    </div>
    <div class="erpag-f"><button type="button" class="erpag-btn g" onclick="erpTkClose()">Cancelar</button><button type="button" class="erpag-btn p" id="erpTkGo" onclick="erpTkSave()">Crear ticket</button></div>
  </div>
</div>
<script>
var _erpTkClient=0;
function erpTicket(o){o=o||{};_erpTkClient=o.clientId||0;
  document.getElementById('erpTkCli').textContent=o.clientName?('· '+o.clientName):'';
  document.getElementById('erpTkAsunto').value='';document.getElementById('erpTkCuerpo').value='';document.getElementById('erpTkPrio').value='2';
  document.getElementById('erpTkMask').classList.add('on');
  setTimeout(function(){document.getElementById('erpTkAsunto').focus();},40);}
function erpTkClose(){document.getElementById('erpTkMask').classList.remove('on');}
function erpTkSave(){var as=document.getElementById('erpTkAsunto').value.trim();
  if(!as){if(window.toast)toast('Escribe un asunto','err');return;}
  var btn=document.getElementById('erpTkGo');btn.disabled=true;btn.textContent='Creando…';
  fetch('support.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'create',ajax:'1',asunto:as,cuerpo:document.getElementById('erpTkCuerpo').value,prioridad:document.getElementById('erpTkPrio').value,client_id:_erpTkClient})}).then(function(r){return r.json();}).then(function(d){
    btn.disabled=false;btn.textContent='Crear ticket';
    if(d&&d.ok){erpTkClose();if(window.toast)toast('Ticket creado ✓');}else{if(window.toast)toast((d&&d.msg)||'No se pudo crear el ticket','err');}
  }).catch(function(){btn.disabled=false;btn.textContent='Crear ticket';if(window.toast)toast('Error de red','err');});}
document.addEventListener('keydown',function(e){if(e.key==='Escape'){var m=document.getElementById('erpTkMask');if(m&&m.classList.contains('on'))erpTkClose();}});
</script>

<?php /* Popup GLOBAL de «Nueva tarea»: título + lista del cliente + prioridad/fecha/responsable. AJAX. */
$erpAdmins=[]; $erpRoles=['owner'=>'Dueño','editor'=>'Editor','viewer'=>'Solo lectura'];
try{ foreach(db()->query('SELECT id,username,email,role FROM admins ORDER BY username') as $ra){ $ps=chat_presence_state((int)$ra['id']); $erpAdmins[]=['id'=>(int)$ra['id'],'username'=>$ra['username'],'email'=>(string)($ra['email']??''),'rol'=>($erpRoles[$ra['role']??'']??''),'color'=>avatar_color($ra['username']),'ini'=>mb_strtoupper(mb_substr($ra['username'],0,2)),'pres'=>$ps['state'],'prestxt'=>$ps['text'],'prescol'=>chat_presence_color($ps['state']),'photo'=>admin_photo_url((int)$ra['id'])]; } }
catch(Exception $e){ try{ foreach(db()->query('SELECT id,username FROM admins ORDER BY username') as $ra) $erpAdmins[]=['id'=>(int)$ra['id'],'username'=>$ra['username'],'email'=>'','rol'=>'','color'=>avatar_color($ra['username']),'ini'=>mb_strtoupper(mb_substr($ra['username'],0,2)),'pres'=>'offline','prestxt'=>'','prescol'=>'#c0c4cb']; }catch(Exception $e2){} } ?>
<div id="erpTaMask" onclick="if(event.target===this)erpTaClose()">
  <div class="erpag">
    <div class="erpag-h"><span class="erpag-logo"><?= ic('check',24) ?></span><span class="erpag-ht"><b>Nueva tarea</b><small id="erpTaCli"></small></span></div>
    <div class="erpag-b">
      <div><label>Título</label><input type="text" id="erpTaTit" placeholder="¿Qué hay que hacer? (Enter para crear)" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();erpTaSave();}"></div>
      <div><label>Descripción <span style="color:var(--muted);font-weight:400">· opcional</span></label><textarea id="erpTaDesc" rows="2" placeholder="Detalles…"></textarea></div>
      <div class="erpag-row">
        <div><label>Lista <span style="color:#ef4444">*</span></label><select id="erpTaList" onchange="this.style.borderColor=''"></select></div>
        <div><label>Prioridad</label><select id="erpTaPrio"><option value="0">Normal</option><option value="2">Media</option><option value="3">Alta</option></select></div>
      </div>
      <div class="erpag-row">
        <div><label>Fecha límite</label><input type="date" id="erpTaDue"></div>
        <div style="position:relative"><label>Responsable</label>
          <button type="button" class="erpta-resp" id="erpTaRespBtn" onclick="erpTaRespTog(event)"><span class="erpta-respview" id="erpTaRespView"><span class="erpta-none">Sin asignar</span></span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></button>
          <input type="hidden" id="erpTaResp">
          <div class="erpta-resplist" id="erpTaRespList"></div>
        </div>
      </div>
    </div>
    <div class="erpag-f"><button type="button" class="erpag-btn g" onclick="erpTaClose()">Cancelar</button><button type="button" class="erpag-btn p" id="erpTaGo" onclick="erpTaSave()">Crear tarea</button></div>
  </div>
</div>
<script>
window.ERP_ADMINS = <?= json_encode($erpAdmins, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
window.ERP_ME = <?= (int)(current_admin()['id'] ?? 0) ?>;
/* Sección del ERP en la que estás ahora (chat, kanban, crm…). El popup social del
   avatar la cuela en «Ver perfil» (?from=…) para que el perfil mantenga el menú del
   apartado desde el que has entrado. */
window.ERP_ACTIVE = <?= json_encode($GLOBALS['erp_active'] ?? '') ?>;
window.ERP_TEAM_NAME = <?= json_encode(marca_agencia()['name'], JSON_UNESCAPED_UNICODE) ?: '""' ?>;
/* Foto de perfil en TODOS los avatares del ERP: quien tenga foto la ve en su
   avatar (chat, tareas, comentarios, equipo…); quien no, se queda con la inicial. */
window.ERP_PHOTOS={}; (window.ERP_ADMINS||[]).forEach(function(a){ if(a.photo) window.ERP_PHOTOS[a.id]=a.photo; });
(function(){
  var AVSEL='.mav,.cav,.av,.rav,.mini-av,.tm-av,.et-av,.hd-av,.erpta-av,.wsp-av,.cmck-av,.pav,.m-av';
  function decorate(el){
    if(el.getAttribute('data-pdone'))return;
    var h=el.matches('[data-uid]')?el:el.closest('[data-uid]'); if(!h)return;
    var uid=h.getAttribute('data-uid'); if(!uid||uid==='0'){el.setAttribute('data-pdone','1');return;}
    el.setAttribute('data-pdone','1'); var url=window.ERP_PHOTOS[uid]; if(!url)return;
    if(el.querySelector('svg')||el.querySelector('img'))return;   // grupos/iconos o ya con imagen
    el.style.backgroundImage='url("'+url+'")'; el.style.backgroundSize='cover'; el.style.backgroundPosition='center';
    el.style.color='transparent'; el.style.textShadow='none';
  }
  function scan(root){ try{ (root||document).querySelectorAll(AVSEL).forEach(decorate); }catch(e){} }
  function boot(){ scan(document);
    try{ var mo=new MutationObserver(function(m){ var n=false; for(var i=0;i<m.length;i++){ if(m[i].addedNodes&&m[i].addedNodes.length){n=true;break;} } if(n){ clearTimeout(window._pfT); window._pfT=setTimeout(function(){scan(document);},180); } });
      mo.observe(document.body,{childList:true,subtree:true}); }catch(e){}
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',boot); else boot();
})();
/* Tarjeta de perfil al dejar el cursor sobre el avatar de un miembro del equipo
   (cualquier elemento con data-uid). Global para todo el ERP. */
(function(){
  var card=null,hideT=null,showT=null,anchor=null;
  function css(){ if(document.getElementById('erpProfCss'))return; var s=document.createElement('style'); s.id='erpProfCss';
    s.textContent='#erpProfCard{position:fixed;z-index:400;width:264px;background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:18px;box-shadow:0 24px 54px -18px rgba(16,19,24,.42),0 3px 10px -5px rgba(16,19,24,.18);opacity:0;visibility:hidden;transform:translateY(10px) scale(.93);transform-origin:top center;transition:opacity .18s ease,transform .28s cubic-bezier(.34,1.56,.64,1),visibility .18s ease;overflow:hidden}'+
      '#erpProfCard.on{opacity:1;visibility:visible;transform:translateY(0) scale(1)}'+
      '#erpProfCard *{box-sizing:border-box}'+
      '#erpProfCard .epc-head{display:flex;align-items:center;justify-content:space-between;padding:15px 16px 14px;gap:12px}'+
      '#erpProfCard .epc-id{min-width:0}'+
      '#erpProfCard .epc-nm{font-size:15.5px;font-weight:750;color:var(--ink-strong);line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'+
      '#erpProfCard .epc-sub{font-size:12px;color:#6b7079;font-weight:600;margin-top:3px;display:flex;align-items:center;gap:5px}'+
      '#erpProfCard .epc-avwrap{position:relative;flex:none}'+
      '#erpProfCard .epc-av{width:46px;height:46px;border-radius:50%;color:#fff;font-size:17px;font-weight:700;display:flex;align-items:center;justify-content:center}'+
      '#erpProfCard .epc-dot{position:absolute;right:1px;bottom:1px;width:12px;height:12px;border-radius:50%;background:#12a150;border:2.5px solid #fff}'+
      '#erpProfCard .epc-body{padding:12px 16px;border-top:1px solid var(--line2);display:flex;flex-direction:column;gap:11px;font-size:13px}'+
      '#erpProfCard .epc-row{display:flex;align-items:center;gap:10px;color:#4c515b;min-width:0}'+
      '#erpProfCard .epc-row svg{width:15px!important;height:15px!important;color:var(--label);flex:none}'+
      '#erpProfCard .epc-row span.epc-t{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'+
      '#erpProfCard .epc-mut{color:var(--muted)}'+
      '#erpProfCard .epc-foot{display:flex;gap:8px;padding:11px 12px;border-top:1px solid var(--line2);background:var(--soft)}'+
      '#erpProfCard .epc-btn{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--line);background:#fff;border-radius:10px;height:34px;font-size:12.5px;font-weight:600;color:var(--ink);text-decoration:none;cursor:pointer;transition:background .12s,border-color .12s}'+
      '#erpProfCard .epc-btn svg{width:15px!important;height:15px!important;flex:none;color:#6b7079}'+
      '#erpProfCard .epc-btn:hover{background:#f4f5f7;border-color:#d9dade}';
    document.head.appendChild(s); }
  function member(uid){ uid=parseInt(uid,10); var a=window.ERP_ADMINS||[]; for(var i=0;i<a.length;i++) if(a[i].id===uid) return a[i]; return null; }
  function ensure(){ if(card)return card; css(); card=document.createElement('div'); card.id='erpProfCard';
    card.addEventListener('mouseenter',function(){ if(hideT){clearTimeout(hideT);hideT=null;} });
    card.addEventListener('mouseleave',scheduleHide); document.body.appendChild(card); return card; }
  function nowLocal(){ var d=new Date(),h=d.getHours(),m=d.getMinutes(),ap=h<12?'am':'pm',hh=h%12; if(hh===0)hh=12; return hh+':'+(m<10?'0':'')+m+' '+ap; }
  var _esc=window.escHtml||function(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});};
  function build(m){ var name=(m.id===window.ERP_ME)?'Tú':m.username;
    var pcol=m.prescol||'#12a150'; var ptxt=m.prestxt||(m.rol||'');
    var sub='<span style="width:7px;height:7px;border-radius:50%;background:'+pcol+';display:inline-block"></span>'+_esc(ptxt)+(m.rol?' <span class="epc-mut">· '+_esc(m.rol)+'</span>':'');
    var rows='';
    if(m.email) rows+='<div class="epc-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/></svg><span class="epc-t">'+_esc(m.email)+'</span></div>';
    rows+='<div class="epc-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span class="epc-t">'+_esc(nowLocal())+' <span class="epc-mut">hora local</span></span></div>';
    if(window.ERP_TEAM_NAME) rows+='<div class="epc-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg><span class="epc-t">Equipo '+_esc(window.ERP_TEAM_NAME)+'</span></div>';
    var chatHref=(m.id===window.ERP_ME)?'chat.php':('chat.php?dm='+m.id);
    var avin=m.photo?('<img src="'+_esc(m.photo)+'" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%">'):_esc(m.ini);
    return '<div class="epc-head"><div class="epc-id"><div class="epc-nm">'+_esc(name)+'</div><div class="epc-sub">'+sub+'</div></div><div class="epc-avwrap"><span class="epc-av" style="background:'+m.color+';overflow:hidden">'+avin+'</span><span class="epc-dot" style="background:'+pcol+'"></span></div></div>'+
      '<div class="epc-body">'+rows+'</div>'+
      '<div class="epc-foot">'+((m.id===window.ERP_ME)?'':'<a class="epc-btn" href="'+chatHref+'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Chat</a>')+
      '<a class="epc-btn" href="perfil.php?id='+m.id+(window.ERP_ACTIVE?('&from='+encodeURIComponent(window.ERP_ACTIVE)):'')+'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 12 0v1"/></svg>Ver perfil</a></div>'; }
  function show(el,uid){ var m=member(uid); if(!m)return; ensure(); card.innerHTML=build(m); card.classList.add('on');
    var r=el.getBoundingClientRect(),cw=250,ch=card.offsetHeight||160;
    var left=Math.min(Math.max(8,r.left+r.width/2-cw/2),window.innerWidth-cw-8);
    var top=r.bottom+8; if(top+ch>window.innerHeight-8) top=Math.max(8,r.top-ch-8);
    card.style.left=left+'px'; card.style.top=top+'px'; }
  function scheduleHide(){ if(hideT)clearTimeout(hideT); hideT=setTimeout(function(){ if(card)card.classList.remove('on'); },180); }
  document.addEventListener('mouseover',function(e){ var el=e.target.closest?e.target.closest('[data-uid]'):null; if(!el||el===anchor)return; var uid=el.getAttribute('data-uid'); if(!uid||uid==='0')return; anchor=el; if(showT)clearTimeout(showT); if(hideT){clearTimeout(hideT);hideT=null;} showT=setTimeout(function(){show(el,uid);},450); });
  document.addEventListener('mouseout',function(e){ var from=e.target.closest?e.target.closest('[data-uid]'):null; if(!from)return; var to=(e.relatedTarget&&e.relatedTarget.closest)?e.relatedTarget.closest('[data-uid]'):null; if(to===from)return; if(showT){clearTimeout(showT);showT=null;} anchor=null; scheduleHide(); });
})();
var _erpTaClient=0;
function erpTarea(o){o=o||{};_erpTaClient=o.clientId||0;
  document.getElementById('erpTaCli').textContent=o.clientName?('Cliente: '+o.clientName):'';
  document.getElementById('erpTaTit').value='';document.getElementById('erpTaDesc').value='';document.getElementById('erpTaDue').value='';document.getElementById('erpTaPrio').value='0';
  var _l=document.getElementById('erpTaList');_l.style.borderColor='';
  var ls=document.getElementById('erpTaList');ls.innerHTML='';var lists=o.lists||[];
  lists.forEach(function(l){var op=document.createElement('option');op.value=l.id;op.textContent=l.nombre;ls.appendChild(op);});
  var noList=!lists.length;
  if(noList){var op=document.createElement('option');op.value='';op.textContent='(sin listas — créala en Tareas del cliente)';ls.appendChild(op);}
  document.getElementById('erpTaGo').disabled=noList;
  document.getElementById('erpTaResp').value='';
  document.getElementById('erpTaRespView').innerHTML='<span class="erpta-none">Sin asignar</span>';
  var rl=document.getElementById('erpTaRespList');rl.classList.remove('on');rl.innerHTML='';
  function taOpt(id,html){var d=document.createElement('div');d.className='erpta-opt';d.innerHTML=html;d.onclick=function(){document.getElementById('erpTaResp').value=id;document.getElementById('erpTaRespView').innerHTML=html;rl.classList.remove('on');};rl.appendChild(d);}
  taOpt('','<span class="erpta-none">Sin asignar</span>');
  (window.ERP_ADMINS||[]).forEach(function(a){taOpt(a.id,'<span class="erpta-av" style="background:'+a.color+'">'+escHtml(a.ini)+'</span>'+escHtml(a.username));});
  document.getElementById('erpTaMask').classList.add('on');
  setTimeout(function(){document.getElementById('erpTaTit').focus();},40);}
function erpTaRespTog(e){e.stopPropagation();document.getElementById('erpTaRespList').classList.toggle('on');}
document.addEventListener('click',function(e){var l=document.getElementById('erpTaRespList');if(l&&l.classList.contains('on')&&!e.target.closest('#erpTaRespList')&&!e.target.closest('#erpTaRespBtn'))l.classList.remove('on');});
function erpTaClose(){document.getElementById('erpTaMask').classList.remove('on');}
function erpTaSave(){var t=document.getElementById('erpTaTit').value.trim(),ls=document.getElementById('erpTaList'),list=ls.value;
  if(!t){if(window.toast)toast('Escribe un título','err');document.getElementById('erpTaTit').focus();return;}
  if(!list){ls.style.borderColor='#ef4444';ls.focus();if(window.toast)toast('¿A qué lista? Elígela para guardar','err');return;}
  var btn=document.getElementById('erpTaGo');btn.disabled=true;btn.textContent='Creando…';
  fetch('workspace.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'save_task',ajax:'1',cli:_erpTaClient,list_id:list,titulo:t,descripcion:document.getElementById('erpTaDesc').value,prioridad:document.getElementById('erpTaPrio').value,due_date:document.getElementById('erpTaDue').value,responsable_id:document.getElementById('erpTaResp').value})}).then(function(r){return r.json();}).then(function(d){
    btn.disabled=false;btn.textContent='Crear tarea';
    if(d&&d.ok){erpTaClose();if(window.toast)toast('Tarea creada ✓');}else{if(window.toast)toast((d&&d.msg)||'No se pudo crear la tarea','err');}
  }).catch(function(){btn.disabled=false;btn.textContent='Crear tarea';if(window.toast)toast('Error de red','err');});}
document.addEventListener('keydown',function(e){if(e.key==='Escape'){var m=document.getElementById('erpTaMask');if(m&&m.classList.contains('on'))erpTaClose();}});
/* Acordeones del menú: animan al abrir/cerrar y, una vez abiertos, se quita el recorte
   (.done) para que se puedan ARRASTRAR listas y carpetas y se vea el indicador de sitio. */
/* Animación de altura+fade con la Web Animations API (fiable en apertura Y cierre).
   El elemento (.cli-lists / .cli-acc) mide su contenido y anima max-height entre 0 y esa
   altura, con opacidad. Al abrir se deja `.done` (sin recorte → se puede arrastrar). */
var _EASE='cubic-bezier(.33,1,.68,1)', _DUR=300;
function _slide(el, open){                 // el ya tiene su clase de estado puesta por el llamador
  if(el._an){el._an.cancel();el._an=null;}
  el.classList.remove('done');
  var h=el.scrollHeight;                    // altura del contenido (scrollHeight ignora max-height)
  var kf = open ? [{maxHeight:'0px',opacity:0},{maxHeight:h+'px',opacity:1}]
                : [{maxHeight:h+'px',opacity:1},{maxHeight:'0px',opacity:0}];
  el._an=el.animate(kf,{duration:_DUR,easing:_EASE,fill:open?'forwards':'none'});
  el._an.onfinish=function(){ if(open) el.classList.add('done'); if(el._an){el._an.cancel();el._an=null;} };
}
function cliFold(btn){var g=btn.closest('.cli-grp'),l=g&&g.querySelector('.cli-lists');if(!l)return;
  var open=!g.classList.contains('open'); g.classList.toggle('open',open); _slide(l,open); }
function cliSect(el,e){if(e&&e.target&&e.target.closest&&e.target.closest('.secadd'))return;
  var s=el.closest('.cli-section'),acc=s&&s.querySelector('.cli-acc');if(!acc)return;
  var open=s.classList.contains('collapsed'); s.classList.toggle('collapsed',!open); _slide(acc,open); }
document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('.cli-grp.open > .cli-lists').forEach(function(l){l.classList.add('done');});
  document.querySelectorAll('.cli-section:not(.collapsed) > .cli-acc').forEach(function(a){a.classList.add('done');});
});
</script>
<script>
/* erpConfirm(msg, opts) -> Promise<bool>     opts: {titulo, ok, danger}
   erpPrompt(msg, valor, opts) -> Promise<string|null>
   erpPost(url, datos)  -> envía un POST con CSRF (para borrados y acciones)
   erpAsk(msg, opts)    -> confirma y, si opts.post, hace el POST. Para usar en onclick. */
(function(){
  var ov=document.getElementById('erpDlgOv'),T=document.getElementById('erpDlgT'),M=document.getElementById('erpDlgM'),
      I=document.getElementById('erpDlgI'),NO=document.getElementById('erpDlgNo'),YES=document.getElementById('erpDlgYes');
  var resolve=null,isPrompt=false,prevFocus=null;

  function close(val){
    ov.classList.remove('vis');
    setTimeout(function(){ ov.classList.remove('on'); },170);
    document.removeEventListener('keydown',onKey,true);
    var r=resolve; resolve=null;
    if(prevFocus&&prevFocus.focus){try{prevFocus.focus();}catch(e){}}
    if(r)r(val);
  }
  function onKey(ev){
    if(ev.key==='Escape'){ ev.preventDefault(); close(isPrompt?null:false); }
    else if(ev.key==='Enter'&&(isPrompt||document.activeElement!==NO)){ ev.preventDefault(); accept(); }
  }
  function accept(){ close(isPrompt?(I.value.trim()===''?null:I.value.trim()):true); }
  NO.onclick=function(){ close(isPrompt?null:false); };
  YES.onclick=accept;
  ov.onmousedown=function(ev){ if(ev.target===ov) close(isPrompt?null:false); };

  function open(o){
    if(resolve)close(isPrompt?null:false);
    isPrompt=!!o.prompt; prevFocus=document.activeElement;
    T.textContent=o.titulo||(o.prompt?'':'¿Seguro?');
    T.style.display=T.textContent?'':'none';
    M.textContent=o.msg||''; M.style.display=M.textContent?'':'none';
    I.style.display=o.prompt?'':'none';
    if(o.prompt){ I.value=o.valor||''; I.placeholder=o.placeholder||''; }
    YES.textContent=o.ok||(o.prompt?'Guardar':'Aceptar');
    YES.className='yes'+(o.danger?' danger':'');
    NO.textContent=o.cancel||'Cancelar';
    ov.classList.add('on');
    requestAnimationFrame(function(){ ov.classList.add('vis'); (o.prompt?I:YES).focus(); if(o.prompt)I.select(); });
    document.addEventListener('keydown',onKey,true);
    return new Promise(function(res){ resolve=res; });
  }

  window.erpConfirm=function(msg,o){ o=o||{}; return open({msg:msg,titulo:o.titulo||'¿Seguro?',ok:o.ok||(o.danger?'Eliminar':'Aceptar'),cancel:o.cancel,danger:o.danger}); };
  window.erpPrompt=function(msg,valor,o){ o=o||{}; return open({prompt:true,msg:o.msg||'',titulo:msg,valor:valor,placeholder:o.placeholder,ok:o.ok||'Guardar',cancel:o.cancel}); };
  window.erpAlert=function(msg,o){ o=o||{}; return open({msg:msg,titulo:o.titulo||'Aviso',ok:o.ok||'Entendido',cancel:null}).then(function(){return true;}); };

  window.erpPost=function(url,datos){
    var f=document.createElement('form');
    f.method='POST'; f.action=url; f.style.display='none';
    datos=datos||{};
    if(window.CSRF_TOKEN&&datos._csrf===undefined) datos._csrf=window.CSRF_TOKEN;
    Object.keys(datos).forEach(function(k){
      var i=document.createElement('input'); i.type='hidden'; i.name=k; i.value=datos[k]==null?'':datos[k]; f.appendChild(i);
    });
    document.body.appendChild(f); f.submit();
  };

  /* Para formularios:  onsubmit="return erpSubmitAsk(this,'¿Borrar la factura?')"
     Media docena de páginas usaban todavía onsubmit="return confirm(...)", que
     dibuja la ventanita gris del navegador con el dominio escrito arriba: nada
     que ver con el resto del ERP. Esto pregunta con el mismo diálogo que todo lo
     demás y, si la respuesta es que sí, vuelve a enviar el formulario.
     El aspa _erpOk evita que la segunda vuelta pregunte otra vez. */
  window.erpSubmitAsk=function(form,msg,o){
    o=o||{};
    if(form._erpOk){ form._erpOk=false; return true; }
    erpConfirm(msg,{titulo:o.titulo,ok:o.ok,danger:o.danger!==false}).then(function(ok){
      if(!ok)return;
      form._erpOk=true;
      if(typeof form.requestSubmit==='function')form.requestSubmit(); else form.submit();
    });
    return false;
  };

  /* Para enlaces:  onclick="return erpAsk('¿Eliminar X?',{post:'delete.php',data:{id:3},danger:true})" */
  window.erpAsk=function(msg,o){
    o=o||{};
    erpConfirm(msg,{titulo:o.titulo,ok:o.ok,danger:o.danger}).then(function(ok){
      if(!ok)return;
      if(o.post) erpPost(o.post,o.data);
      else if(o.href) location.href=o.href;
      else if(typeof o.then==='function') o.then();
    });
    return false;
  };
})();

/* ---- Escapado para HTML construido en JS ----
   Cualquier innerHTML que meta un nombre, email o teléfono escrito por una
   persona tiene que pasar por aquí; si no, ese texto se ejecuta como código. */
window.escHtml=function(s){
  return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
  });
};
/* Para meter un valor dentro de comillas simples de un onclick="..." en JS */
window.escJs=function(s){
  return String(s==null?'':s).replace(/\\/g,'\\\\').replace(/'/g,"\\'")
    .replace(/"/g,'&quot;').replace(/</g,'\\x3c').replace(/>/g,'\\x3e')
    .replace(/\r?\n/g,' ');
};
/* Bloquea javascript:, data: y vbscript: en href construidos en JS */
window.safeUrl=function(u){
  var s=String(u==null?'':u).trim();
  if(/^\s*(javascript|data|vbscript)\s*:/i.test(s)) return '#';
  return s;
};
/* Teléfono: solo dígitos, +, espacios y guiones */
window.safeTel=function(t){ return String(t==null?'':t).replace(/[^0-9+\-\s()]/g,''); };

/* ===== Buscador global (Ctrl+K) =====
   Una sola caja para todo el ERP. El servidor (buscar.php) decide qué hay;
   aquí solo se pinta, se navega con el teclado y se abre. */
(function(){
  var ov,inp,res,items=[],sel=-1,tmr=null,ultima='';
  function build(){
    ov=document.createElement('div'); ov.id='gsOv';
    ov.innerHTML='<div class="gs">'
      +'<div class="gs-in">'+<?= json_encode(ic('search',18)) ?>+'<input type="text" placeholder="Buscar clientes, tareas, contactos, negocios, facturas…" autocomplete="off" spellcheck="false"><span class="esc">Esc</span></div>'
      +'<div class="gs-res"></div>'
      +'<div class="gs-foot"><span><b>↑↓</b> moverse</span><span><b>Enter</b> abrir</span><span><b>Esc</b> cerrar</span></div>'
      +'</div>';
    document.body.appendChild(ov);
    inp=ov.querySelector('input'); res=ov.querySelector('.gs-res');
    ov.addEventListener('mousedown',function(e){ if(e.target===ov) gsClose(); });
    inp.addEventListener('input',function(){ clearTimeout(tmr); tmr=setTimeout(buscar,180); });
    inp.addEventListener('keydown',function(e){
      if(e.key==='ArrowDown'){ e.preventDefault(); mover(1); }
      else if(e.key==='ArrowUp'){ e.preventDefault(); mover(-1); }
      else if(e.key==='Enter'){ e.preventDefault(); abrir(); }
      else if(e.key==='Escape'){ e.preventDefault(); gsClose(); }
    });
    msg('Escribe al menos dos letras.');
  }
  function msg(t){ res.innerHTML='<div class="gs-msg">'+escHtml(t)+'</div>'; items=[]; sel=-1; }
  function mover(d){ if(!items.length)return; sel=(sel+d+items.length)%items.length; pinta(); }
  function pinta(){ for(var i=0;i<items.length;i++) items[i].el.classList.toggle('sel',i===sel);
    if(sel>=0&&items[sel].el.scrollIntoView) items[sel].el.scrollIntoView({block:'nearest'}); }
  function abrir(){
    var q=inp.value.trim();
    if(sel>=0&&items[sel]) location.href=safeUrl(items[sel].u);
    else if(q.length>=2) location.href='buscar.php?q='+encodeURIComponent(q);
  }
  function buscar(){
    var q=inp.value.trim();
    if(q.length<2){ msg('Escribe al menos dos letras.'); ultima=''; return; }
    if(q===ultima) return; ultima=q;
    fetch('buscar.php?json=1&q='+encodeURIComponent(q))
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(inp.value.trim()!==q) return;              // llegó tarde: ya se escribió otra cosa
        if(!d||!d.n){ msg('Nada coincide con «'+q+'».'); return; }
        var h=''; d.grupos.forEach(function(g){
          h+='<div class="gs-sec">'+escHtml(g.g)+'</div>';
          g.r.forEach(function(it){
            h+='<a class="gs-r" href="'+escHtml(safeUrl(it.u))+'"><span class="ic">'+GS_IC(it.i)+'</span>'
              +'<span class="m"><span class="t">'+escHtml(it.t)+'</span><span class="s">'+escHtml(it.s)+'</span></span></a>';
          });
        });
        h+='<a class="gs-r" href="buscar.php?q='+encodeURIComponent(q)+'"><span class="ic">'+GS_IC('search')+'</span>'
          +'<span class="m"><span class="t">Ver todos los resultados</span><span class="s">Página completa de búsqueda</span></span></a>';
        res.innerHTML=h;
        items=[]; res.querySelectorAll('.gs-r').forEach(function(el){ items.push({el:el,u:el.getAttribute('href')}); });
        sel=items.length?0:-1; pinta();
      })
      .catch(function(){ msg('No se ha podido buscar ahora mismo.'); });
  }
  /* Los iconos vienen del propio ic() de PHP para no duplicar el set en JS. */
  var GS_ICONS=<?= json_encode([
    'clients'=>ic('clients',16),'check'=>ic('check',16),'crm'=>ic('crm',16),'trend'=>ic('trend',16),
    'file'=>ic('file',16),'ticket'=>ic('ticket',16),'layers'=>ic('layers',16),'search'=>ic('search',16),
    'home'=>ic('home',16),'inbox'=>ic('inbox',16),'usercheck'=>ic('usercheck',16),'cal'=>ic('cal',16),
    'euro'=>ic('euro',16),'chart'=>ic('chart',16),'clock'=>ic('clock',16),'calc'=>ic('calc',16),
    'chat'=>ic('chat',16),'vault'=>ic('vault',16),'user'=>ic('user',16),'building'=>ic('building',16),
    'list'=>ic('list',16),'flag'=>ic('flag',16),'settings'=>ic('settings',16),'bolt'=>ic('bolt',16),
    'bell'=>ic('bell',16),
  ]) ?>;
  function GS_IC(k){ return GS_ICONS[k]||GS_ICONS.search; }

  window.gsOpen=function(txt){
    if(!ov) build();
    ov.classList.add('on');
    if(txt!=null){ inp.value=txt; ultima=''; buscar(); }
    inp.focus(); inp.select();
  };
  window.gsClose=function(){ if(ov) ov.classList.remove('on'); };

  document.addEventListener('keydown',function(e){
    /* Ctrl/⌘ + K abre desde cualquier parte. La barra «/» también, salvo que
       estés escribiendo en un campo. */
    if((e.ctrlKey||e.metaKey)&&(e.key==='k'||e.key==='K')){ e.preventDefault(); gsOpen(); return; }
    if(e.key==='/'&&!e.ctrlKey&&!e.metaKey&&!e.altKey){
      var t=e.target, tag=t&&t.tagName;
      if(tag==='INPUT'||tag==='TEXTAREA'||tag==='SELECT'||(t&&t.isContentEditable))return;
      e.preventDefault(); gsOpen();
    }
  });
})();
</script>
<?php /* Cuando algo se borra, la página que lo borró deja aquí el aviso con su
         «Deshacer». Va en el pie del ERP para que funcione igual en todas las
         secciones sin que cada página tenga que repetir el mismo código. */
      if (!empty($_SESSION['erp_undo']['tid'])):
        $u = $_SESSION['erp_undo']; unset($_SESSION['erp_undo']); ?>
<script>window.addEventListener('load',function(){
  if(window.toastUndo) toastUndo(<?= json_encode((string)($u['msg'] ?? 'Elemento eliminado')) ?>, <?= (int)$u['tid'] ?>);
  else if(window.toast) toast(<?= json_encode((string)($u['msg'] ?? 'Elemento eliminado')) ?>);
});</script>
<?php endif; ?>
<?php /* Felicitación de cumpleaños al propio cumpleañero. Se muestra una vez al día,
         en cuanto abre el panel. A propósito NO menciona la marca ni la empresa: es
         un guiño entre el equipo, no un mensaje corporativo. */
      if (!empty($_SESSION['bday_greet'])):
        unset($_SESSION['bday_greet']); $_SESSION['bday_greeted']=date('Y-m-d');
        $bdayMsgs = [
          '¡Feliz cumpleaños! Hoy tienes barra libre de tarta y cero remordimientos. 🎂',
          '¡Felicidades! Un año más sabio y, sobre todo, un año más guapo. Hoy mandas tú. 😎',
          '¡Feliz cumple! Que se cumplan tus deseos… empezando por que nadie te escriba «¿para hoy?». 🙏',
          '¡Felicidades! Oficialmente hoy está prohibido trabajar de más. Sopla las velas y presume. 🎈',
        ];
        $bmsg = $bdayMsgs[(int)date('z') % count($bdayMsgs)];
        $bnom = trim((string)(current_admin()['username'] ?? '')); ?>
<style>
#bdayOv{position:fixed;inset:0;z-index:1400;display:flex;align-items:center;justify-content:center;padding:20px;
  background:rgba(16,19,24,.42);-webkit-backdrop-filter:blur(5px);backdrop-filter:blur(5px);opacity:0;transition:opacity .25s ease}
#bdayOv.on{opacity:1}
#bdayOv .bd{background:#fff;border-radius:22px;width:400px;max-width:100%;padding:34px 30px 26px;text-align:center;
  box-shadow:0 34px 90px -20px rgba(16,19,24,.4);transform:translateY(14px) scale(.96);opacity:0;
  transition:transform .34s cubic-bezier(.2,.9,.3,1.1),opacity .28s ease}
#bdayOv.on .bd{transform:none;opacity:1}
#bdayOv .cake{font-size:56px;line-height:1;margin-bottom:6px;display:inline-block;animation:bdayPop .5s .12s both cubic-bezier(.2,.9,.3,1.4)}
@keyframes bdayPop{from{transform:scale(0) rotate(-18deg)}to{transform:none}}
#bdayOv h2{font-size:21px;font-weight:750;letter-spacing:-.4px;color:var(--ink-strong);margin:0 0 8px}
#bdayOv p{font-size:14px;line-height:1.55;color:#5c616b;margin:0 0 22px}
#bdayOv .bd .btn{width:100%;justify-content:center;padding:12px}
#bdayOv .conf{position:absolute;top:0;left:0;right:0;height:0;pointer-events:none}
#bdayOv .conf i{position:absolute;top:-10px;width:9px;height:14px;border-radius:2px;opacity:.9;animation:bdayFall linear forwards}
@keyframes bdayFall{to{transform:translateY(102vh) rotate(640deg);opacity:.2}}
</style>
<div id="bdayOv"><div class="conf" id="bdayConf"></div>
  <div class="bd">
    <span class="cake">🎂</span>
    <h2>¡Felicidades<?= $bnom!=='' ? ', '.e($bnom) : '' ?>!</h2>
    <p><?= e($bmsg) ?></p>
    <button type="button" class="btn" onclick="bdayClose()">¡Gracias! 🎉</button>
  </div>
</div>
<script>
(function(){
  var ov=document.getElementById('bdayOv'); if(!ov) return;
  var cols=['#ff5e7a','#ffcf3f','#38c172','#3b82f6','#a970ff','#ff9f43'];
  var box=document.getElementById('bdayConf');
  for(var i=0;i<60;i++){ var s=document.createElement('i');
    s.style.left=(Math.random()*100)+'%';
    s.style.background=cols[i%cols.length];
    s.style.animationDuration=(2.6+Math.random()*2.2)+'s';
    s.style.animationDelay=(Math.random()*.8)+'s';
    s.style.transform='translateY(0) rotate('+(Math.random()*360)+'deg)';
    box.appendChild(s);
  }
  requestAnimationFrame(function(){ requestAnimationFrame(function(){ ov.classList.add('on'); }); });
  window.bdayClose=function(){ ov.classList.remove('on'); setTimeout(function(){ ov.remove(); },260); };
  ov.addEventListener('click',function(e){ if(e.target===ov) bdayClose(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape') bdayClose(); });
})();
</script>
<?php endif; ?>
<?php /* Confirmaciones de acciones que redirigían con ?msg=/?ran=/?nk= y que nadie
         leía: la página recargaba sin decir nada y parecía que no había pasado nada
         (P2-05). Aquí se leen esos parámetros una sola vez, en el pie común, y se
         pinta el toast que corresponde. */
      $__msgMap = [
        'cliente-eliminado' => ['Cliente eliminado.', 'plain'],
        'error-borrado'     => ['No se ha podido borrar.', 'err'],
        'guardado'          => ['Cambios guardados.', 'plain'],
        'creado'            => ['Creado correctamente.', 'plain'],
        'actualizado'       => ['Actualizado correctamente.', 'plain'],
        'tipo-eliminado'    => ['Tipo eliminado.', 'plain'],
        'miembro-eliminado' => ['Miembro eliminado.', 'plain'],
        'ultimo-dueno'      => ['No puedes eliminar al último Dueño.', 'err'],
        'no-puedes-borrarte'=> ['No puedes eliminarte a ti mismo.', 'err'],
      ];
      $__toast = null;
      if (isset($_GET['msg']) && isset($__msgMap[$_GET['msg']])) $__toast = $__msgMap[$_GET['msg']];
      elseif (isset($_GET['ran'])) $__toast = ['Resumen de seguimientos enviado.', 'plain'];
      elseif (isset($_GET['nk']))  $__toast = ['Clave del cron regenerada. Actualiza la línea del crontab.', 'plain'];
      if ($__toast): ?>
<script>window.addEventListener('load',function(){ if(window.toast) toast(<?= json_encode($__toast[0], JSON_UNESCAPED_UNICODE) ?><?= $__toast[1]==='err'?", 'err'":'' ?>); });</script>
<?php endif; ?>
<?php /* Notificaciones en tiempo real: un sondeo cada 15 s actualiza el globo de la
         campana y, cuando llega algo nuevo, salta un POPUP emergente arriba a la
         derecha (dura ~6 s, se puede pulsar para ir a lo que lo provocó y cerrar con
         la ✕). No hay que recargar nada. El globo pega un latido al subir. */ ?>
<script>
(function(){
  var bell=document.querySelector('.rbell'); if(!bell) return;
  var seen=null, lastN=null;
  var BELL='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
  function setBadge(n){
    var b=bell.querySelector('.rbadge'), before=b?parseInt(b.textContent)||0:0;
    if(n>0){ if(!b){ b=document.createElement('span'); b.className='rbadge'; bell.appendChild(b); } b.textContent=n>9?'9+':n;
      if(n>before){ b.classList.remove('pulse'); void b.offsetWidth; b.classList.add('pulse'); } }
    else if(b){ b.remove(); }
  }
  /* Pila de popups (contenedor con scroll). Se crea al vuelo la primera vez. */
  function stack(){ var s=document.getElementById('notifPops'); if(!s){ s=document.createElement('div'); s.id='notifPops'; document.body.appendChild(s); } return s; }
  function reflow(s){ s.classList.toggle('many', s.children.length>3); s.scrollTop=s.scrollHeight; }
  /* Sonidito minimal (WebAudio, sin archivo): un bip suave de dos tonos. */
  var _actx=null, _muted=false;
  function blip(){ if(_muted) return; try{
      _actx=_actx||new (window.AudioContext||window.webkitAudioContext)();
      if(_actx.state==='suspended') _actx.resume();
      var t=_actx.currentTime, o=_actx.createOscillator(), g=_actx.createGain();
      o.type='sine'; o.frequency.setValueAtTime(680,t); o.frequency.exponentialRampToValueAtTime(920,t+0.07);
      g.gain.setValueAtTime(0.0001,t); g.gain.exponentialRampToValueAtTime(0.10,t+0.02); g.gain.exponentialRampToValueAtTime(0.0001,t+0.30);
      o.connect(g); g.connect(_actx.destination); o.start(t); o.stop(t+0.32);
    }catch(e){} }
  function popup(d, extra){
    var s=stack();
    while(s.children.length>=6) s.firstChild.remove(); // tope duro: como mucho 6 en memoria
    var url=(d.url||'notifications.php');
    var el=document.createElement('div'); el.className='notif-pop erp-in';
    el.innerHTML='<div class="np-ic">'+BELL+'</div><div class="np-b"><div class="np-t"></div><div class="np-s"></div></div><button class="np-x" aria-label="Cerrar">✕</button>';
    var tit=(d.titulo||'Nueva notificación'); if(d.actor) tit=d.actor+' · '+tit;
    el.querySelector('.np-t').textContent=tit;
    el.querySelector('.np-s').textContent=(d.cuerpo||'')+(extra>0?('  ·  +'+extra+' más'):'');
    var timer=setTimeout(cerrar,6500);
    function cerrar(){ clearTimeout(timer); el.classList.remove('erp-in'); el.classList.add('erp-out'); setTimeout(function(){el.remove();reflow(s);},200); }
    el.addEventListener('click',function(e){ if(e.target.closest('.np-x')){cerrar();return;} location.href=url; });
    el.addEventListener('mouseenter',function(){ clearTimeout(timer); }); // no se cierra mientras lo lees
    el.addEventListener('mouseleave',function(){ timer=setTimeout(cerrar,2500); });
    s.appendChild(el); reflow(s); blip();
  }
  function poll(){
    fetch('notifications.php?poll=1',{headers:{'X-Requested-With':'fetch'}})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d) return; var n=d.unread||0; var lid=(d.latest&&d.latest.id)?parseInt(d.latest.id):0;
        if(seen===null){ seen=lid; lastN=n; setBadge(n); return; }   // 1er sondeo: solo fija la base
        setBadge(n);
        if(lid>seen){ var extra=(n>lastN)?(n-lastN-1):0; seen=lid; if(d.latest) popup(d.latest, extra); }
        lastN=n;
      }).catch(function(){});
  }
  /* Sondeo cada 5 s (antes 15). Al volver a la pestaña, sondea al instante para
     que el aviso aparezca casi en el momento del cambio. */
  setTimeout(poll,1200); setInterval(poll,5000);
  document.addEventListener('visibilitychange',function(){ if(!document.hidden) poll(); });
})();
</script>

<?php /* Avisador GLOBAL del chat de equipo: como el chat se excluye a propósito de la
         campana, aquí va su propio sondeo. En cualquier página del ERP, cuando un
         compañero te escribe (y no estás mirando esa misma conversación), suena un
         bip, sale un popup y —si lo permites— una notificación del navegador. */ ?>
<script>
(function(){
  if(!window.ERP_ME) return;
  var KEY='chatSeen_'+window.ERP_ME, seen=null;
  try{ var st=localStorage.getItem(KEY); if(st!==null) seen=parseInt(st,10)||0; }catch(e){}
  /* Rastreo de actividad real (para «en línea» vs «en reposo/ausente»). */
  var lastAct=Date.now();
  ['mousemove','mousedown','keydown','touchstart','scroll','focus'].forEach(function(ev){ document.addEventListener(ev,function(){ lastAct=Date.now(); },{passive:true,capture:true}); });
  function isActive(){ return !document.hidden && (Date.now()-lastAct)<60000; }
  /* Sonido corto (WebAudio, sin archivo): dos tonos suaves. */
  var _ac=null;
  function blip(){ try{ _ac=_ac||new (window.AudioContext||window.webkitAudioContext)(); if(_ac.state==='suspended')_ac.resume();
    var t=_ac.currentTime,o=_ac.createOscillator(),g=_ac.createGain();
    o.type='sine';o.frequency.setValueAtTime(620,t);o.frequency.exponentialRampToValueAtTime(880,t+0.08);
    g.gain.setValueAtTime(0.0001,t);g.gain.exponentialRampToValueAtTime(0.12,t+0.02);g.gain.exponentialRampToValueAtTime(0.0001,t+0.32);
    o.connect(g);g.connect(_ac.destination);o.start(t);o.stop(t+0.34);
  }catch(e){} }
  /* Permiso de notificaciones del navegador: se pide en el primer clic/tecla. */
  function askPerm(){ try{ if(window.Notification && Notification.permission==='default') Notification.requestPermission(); }catch(e){} document.removeEventListener('click',askPerm); document.removeEventListener('keydown',askPerm); }
  document.addEventListener('click',askPerm); document.addEventListener('keydown',askPerm);
  var ICON='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
  function stack(){ var s=document.getElementById('notifPops'); if(!s){ s=document.createElement('div'); s.id='notifPops'; document.body.appendChild(s); } return s; }
  function popup(d,extra){ var s=stack(); while(s.children.length>=6) s.firstChild.remove();
    var el=document.createElement('div'); el.className='notif-pop erp-in';
    el.innerHTML='<div class="np-ic">'+ICON+'</div><div class="np-b"><div class="np-t"></div><div class="np-s"></div></div><button class="np-x" aria-label="Cerrar">✕</button>';
    el.querySelector('.np-t').textContent=(d.group? d.label+' · '+d.author : d.author);
    el.querySelector('.np-s').textContent=(d.body||'')+(extra>0?('  ·  +'+extra+' más'):'');
    var timer=setTimeout(cerrar,6500);
    function cerrar(){ clearTimeout(timer); el.classList.remove('erp-in'); el.classList.add('erp-out'); setTimeout(function(){el.remove();},200); }
    el.addEventListener('click',function(e){ if(e.target.closest('.np-x')){cerrar();return;} location.href='chat.php?room='+d.room; });
    el.addEventListener('mouseenter',function(){ clearTimeout(timer); });
    el.addEventListener('mouseleave',function(){ timer=setTimeout(cerrar,2500); });
    s.appendChild(el); s.scrollTop=s.scrollHeight;
  }
  function browserNotif(d){ try{ if(window.Notification && Notification.permission==='granted'){
      var n=new Notification((d.group? d.label : d.author), {body:(d.group?(d.author+': '):'')+ (d.body||''), tag:'chat-'+d.room, icon:''});
      n.onclick=function(){ window.focus(); location.href='chat.php?room='+d.room; n.close(); };
  } }catch(e){} }
  function fire(list){
    /* No avisar de la sala que tienes abierta si la pestaña está enfocada. */
    var relevantes=list.filter(function(m){ return !(window.CH_ROOM && m.room===window.CH_ROOM && !document.hidden); });
    if(!relevantes.length) return;
    var d=relevantes[relevantes.length-1];
    popup(d, relevantes.length-1); browserNotif(d); blip();
  }
  function poll(){
    var url='chat.php?ping=1&act='+(isActive()?1:0)+(seen!=null?('&after='+seen):'');
    fetch(url,{headers:{'X-Requested-With':'fetch'}}).then(function(r){return r.json();}).then(function(j){
      if(!j||!j.ok) return;
      if(seen===null){ seen=j.max||0; try{localStorage.setItem(KEY,seen);}catch(e){} return; } // 1ª vez: baseline sin avisar
      if(j.messages && j.messages.length){ fire(j.messages); seen=j.max||seen; try{localStorage.setItem(KEY,seen);}catch(e){} }
    }).catch(function(){});
  }
  setTimeout(poll,1500); setInterval(poll,5000);
  document.addEventListener('visibilitychange',function(){ if(!document.hidden) poll(); });
})();
</script>
<?php /* Emojis Apple (render inline + picker global). Los datos se cargan antes que
         el componente para que estén listos al inicializar. Se sirven como estáticos
         desde admin/assets/emoji/ (spritesheet + JSON compacto + JS). */ ?>
<?php /* Memoria de correos para los autocompletados de invitados (Calendario y
         Reuniones): lo que escribes una vez se recuerda para la próxima (localStorage). */ ?>
<script>
window.erpEmailMem=(function(){var KEY='erpEmailMemory';
  function all(){try{return JSON.parse(localStorage.getItem(KEY)||'[]');}catch(e){return [];}}
  function add(str){var found=(''+(str||'')).match(/[^\s,;<>()"']+@[^\s,;<>()"']+\.[^\s,;<>()"']+/g);if(!found||!found.length)return;
    var cur=all(),set={};cur.forEach(function(e){set[e.toLowerCase()]=1;});var changed=false;
    found.forEach(function(e){e=e.toLowerCase();if(!set[e]){cur.push(e);set[e]=1;changed=true;}});
    if(changed){cur=cur.slice(-300);try{localStorage.setItem(KEY,JSON.stringify(cur));}catch(e){}}}
  /* Combina una lista del servidor con la memoria, sin duplicados. */
  function pool(base){var out=[],set={};(base||[]).concat(all()).forEach(function(e){e=(''+e).toLowerCase().trim();if(e&&!set[e]){set[e]=1;out.push(e);}});out.sort();return out;}
  return {all:all,add:add,pool:pool};
})();
</script>
<script src="assets/emoji/emoji-data.js?v=1"></script>
<script src="assets/emoji/component.js?v=7"></script>
</body></html>
<?php
  /* Refresco DIARIO de las métricas de Google, en SEGUNDO PLANO. La página ya está
     enviada al navegador; gm_auto_daily() solo trabaja si toca (una vez al día) y si
     puede cerrar la respuesta antes, así que traer los datos no hace esperar a nadie.
     El chequeo del día es una simple lectura de ajuste: barato en cada carga. */
  try {
    if (function_exists('get_setting') && get_setting('gm_auto_day','') !== date('Y-m-d')) {
      require_once __DIR__.'/lib/google_metrics.php';
      if (function_exists('gm_auto_daily')) gm_auto_daily();
    }
  } catch (\Throwable $e) {}
}
