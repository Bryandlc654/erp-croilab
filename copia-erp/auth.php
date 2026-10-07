<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','httponly'=>true,'samesite'=>'Lax','secure'=>$secure]);
    session_name('croilab_portal');
    session_start();
}

/* ---------- CABECERAS DE SEGURIDAD (globales) ----------
   Se ponen aquí porque auth.php lo incluye casi todo (portal, panel, login,
   factura). Solo protegen; no cambian la estética. archivo.php pone además una
   CSP más estricta propia (se aplica después y manda para esa página).
     · X-Frame-Options / frame-ancestors: nadie puede meter el ERP en un iframe
       ajeno (evita el "clickjacking").
     · X-Content-Type-Options: el navegador no adivina el tipo de un archivo.
     · Referrer-Policy: no filtrar la URL completa al salir a otra web.
     · HSTS: solo cuando ya se entra por HTTPS (fuerza HTTPS en adelante).
   No se pone una CSP completa a propósito: el ERP usa mucho JS/CSS en línea y
   una CSP estricta lo rompería. Se limita a frame-ancestors, que es seguro. */
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: frame-ancestors 'self'");
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/* ---------- CLIENTE ---------- */
function current_client() {
    if (empty($_SESSION['client_id'])) return null;
    static $c = null;
    if ($c === null) {
        $st = db()->prepare('SELECT * FROM clients WHERE id = ?');
        $st->execute([$_SESSION['client_id']]);
        $c = $st->fetch() ?: null;
    }
    return $c;
}

function require_client() {
    if (!current_client()) {
        header('Location: login.php');
        exit;
    }
}

/* ---------- ADMIN ---------- */
function current_admin() {
    if (empty($_SESSION['admin_id'])) return null;
    static $a = null;
    if ($a === null) {
        $st = db()->prepare('SELECT * FROM admins WHERE id = ?');
        $st->execute([$_SESSION['admin_id']]);
        $a = $st->fetch() ?: null;
    }
    return $a;
}

function require_admin() {
    if (!current_admin()) {
        header('Location: login.php');
        exit;
    }
    /* Cada pantalla pide su permiso automáticamente: el mapa está en
       lib/permisos.php (perm_de_pagina) y se aplica aquí, así que una página no
       tiene que acordarse de comprobar nada. Solo actúa si la librería está
       cargada — el portal del cliente también incluye este archivo y no tiene
       roles ni permisos. */
    if (function_exists('perm_de_pagina') && !defined('CROILAB_SIN_PERMISOS')) {
        $bn = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $p  = perm_de_pagina($bn);
        if ($p !== null) require_perm($p);

        /* Y lo mismo con las acciones: un POST puede necesitar más permiso que
           la pantalla desde la que se lanza (ver una factura no es cobrarla).
           Se mira `action` y, para settings.php, `section`. */
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && function_exists('perm_de_accion')) {
            $acc = (string)($_POST['action'] ?? $_POST['section'] ?? '');
            $pa  = perm_de_accion($bn, $acc);
            if ($pa !== null) require_perm($pa);
        }
    }
}

/* ---------- ROLES Y PERMISOS ----------
   Los roles ya no están escritos aquí: viven en la tabla `roles` y se gestionan
   en Ajustes › Roles y permisos (admin/permisos.php, lib/permisos.php). Se
   pueden crear los que hagan falta.

   Estas cuatro funciones se quedan porque las usan ~40 archivos, pero ahora
   preguntan por PERMISOS en vez de comparar el nombre del rol:
     is_owner()  → tiene acceso total
     can_edit()  → puede guardar cambios
   Cuando `lib/permisos.php` no está cargado (el portal del cliente, el
   instalador) se cae al comportamiento antiguo de los tres roles fijos, para
   que nada dependa de que la librería exista. */
function admin_role() {
    $a = current_admin();
    return $a ? ($a['role'] ?? 'editor') : '';
}
function is_owner() {
    if (!current_admin()) return false;
    if (function_exists('can')) return can('admin.total');
    return admin_role() === 'owner';
}
function can_edit() {
    if (!current_admin()) return false;
    if (function_exists('can')) return can('general.editar');
    $r = admin_role(); return $r === 'owner' || $r === 'editor';
}

/* Exige un rol concreto. Se mantiene por compatibilidad con las páginas que ya
   lo llamaban, pero **la forma buena es require_perm('permiso')**: pedir un rol
   por su nombre deja fuera a los roles nuevos que sí tienen el permiso.
   Por eso `require_role('owner')` se entiende como «hace falta acceso total». */
function require_role($roles) {
    require_admin();
    $roles = (array)$roles;
    if (function_exists('can') && in_array('owner', $roles, true) && can('admin.total')) return;
    if (!in_array(admin_role(), $roles, true)) {
        if (function_exists('perm_pantalla_denegado')) perm_pantalla_denegado('este apartado');
        header('Location: index.php');
        exit;
    }
}
/* para acciones de guardado: corta si no puede escribir */
function require_can_edit() {
    require_admin();
    if (!can_edit()) {
        if (function_exists('perm_pantalla_denegado')) perm_pantalla_denegado('guardar cambios');
        header('Location: index.php'); exit;
    }
}
function role_label($r) {
    if (function_exists('roles_todos')) {
        $t = roles_todos();
        if (isset($t[(string)$r])) return $t[(string)$r]['nombre'];
    }
    return ['owner'=>'Dueño','editor'=>'Editor','viewer'=>'Solo lectura'][$r] ?? $r;
}

/* ---------- helpers ---------- */
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function jdecode($s, $fallback = []) { $d = json_decode((string)$s, true); return is_array($d) ? $d : $fallback; }

/* Lee una cantidad escrita por una persona y devuelve el número.
   El problema que resuelve: antes se borraban todos los puntos por sistema,
   así que quien escribía «12.5» acababa con 12.5 convertido en 125.
   Aquí se mira cuál es el ÚLTIMO separador que aparece: ese es el decimal,
   y los anteriores son de miles. Así funcionan por igual:
     1.234,56 → 1234.56    1,234.56 → 1234.56
     12,5     → 12.5       12.5     → 12.5
     1.234    → 1234       1234     → 1234
   Devuelve null si no hay ningún número que leer. */
function num_es($v, $null_si_vacio = true) {
    if (is_int($v) || is_float($v)) return (float)$v;
    $s = trim((string)$v);
    /* Fuera todo lo que no sea cifra, separador o signo: «1.200 €» debe valer. */
    $s = preg_replace('/[^0-9,.\-]/', '', $s);
    if ($s === '' || $s === '-') return $null_si_vacio ? null : 0.0;

    $neg = ($s[0] === '-');
    $s   = str_replace('-', '', $s);

    $ultComa  = strrpos($s, ',');
    $ultPunto = strrpos($s, '.');
    $ult      = max($ultComa === false ? -1 : $ultComa, $ultPunto === false ? -1 : $ultPunto);

    if ($ult < 0) {
        $num = (float)$s;                       // sin separadores: entero limpio
    } else {
        $dec = substr($s, $ult + 1);
        $ent = substr($s, 0, $ult);
        /* Si detrás del separador hay 3 cifras y no hay ningún otro separador
           distinto, es de miles: «1.234» son mil doscientos treinta y cuatro.
           Con «1.234,5» sí hay otro separador, así que la coma manda. */
        $otroDistinto = ($ultComa !== false && $ultPunto !== false);
        if (!$otroDistinto && strlen($dec) === 3 && preg_match('/^\d+$/', $dec) && $ent !== '') {
            $num = (float)preg_replace('/[^0-9]/', '', $s);
        } else {
            $num = (float)(preg_replace('/[^0-9]/', '', $ent) . '.' . preg_replace('/[^0-9]/', '', $dec));
        }
    }
    return $neg ? -$num : $num;
}

/* Un href escrito por una persona puede ser "javascript:..." y ejecutarse al
   pulsarlo. Esto deja pasar solo direcciones normales. */
function safe_url($u) {
    $u = trim((string)$u);
    if ($u === '') return '';
    if (preg_match('#^\s*(javascript|data|vbscript|file)\s*:#i', $u)) return '#';
    return $u;
}
/* Direcciones antiguas guardadas como ../uploads/xxx/archivo -> archivo.php */
function url_adjunto($u, $carpeta) {
    $u = (string)$u;
    if ($u === '') return '';
    if (strpos($u, 'archivo.php') !== false) return $u;
    if (preg_match('#uploads/[^/]+/(.+)$#', $u, $m)) {
        return '../archivo.php?d=' . rawurlencode($carpeta) . '&f=' . rawurlencode(basename($m[1]));
    }
    return safe_url($u);
}

/* ---------- SUBIDAS DE ARCHIVOS ----------
   Lista blanca única para todo el ERP. Nunca se guarda un archivo cuya
   extensión no esté aquí: así es imposible subir un .php, .phtml, .htaccess…
   El .svg queda FUERA a propósito: un SVG es XML y puede llevar <script>
   dentro, así que serviría para colar código en el navegador de otro. */
function upload_extensiones_ok() {
    return ['jpg','jpeg','png','gif','webp','avif','bmp',
            'pdf','doc','docx','xls','xlsx','ppt','pptx','odt','ods','txt','csv','rtf',
            'mp4','webm','mov','m4v','mp3','wav','m4a',
            'zip','rar','7z'];
}
/* Devuelve la extensión limpia (minúsculas, solo letras y números) o '' */
function upload_ext($nombre) {
    return strtolower(preg_replace('/[^a-z0-9]/i', '', pathinfo((string)$nombre, PATHINFO_EXTENSION)));
}
function upload_ext_ok($nombre, $permitidas = null) {
    $ext = upload_ext($nombre);
    if ($ext === '') return false;
    return in_array($ext, $permitidas ?: upload_extensiones_ok(), true);
}
/* Nombre de archivo seguro para guardar en disco: prefijo + azar + extensión.
   Nunca reutiliza el nombre original (evita colisiones y rutas raras). */
function upload_nombre_seguro($nombre, $prefijo = '') {
    $ext = upload_ext($nombre);
    $pre = preg_replace('/[^a-zA-Z0-9_-]+/', '', (string)$prefijo);
    return ($pre !== '' ? $pre . '_' : '') . bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');
}
/* Tipos que el navegador puede mostrar sin peligro (todo lo demás se descarga) */
function upload_mime_seguro($ext) {
    $m = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
          'webp'=>'image/webp','avif'=>'image/avif','bmp'=>'image/bmp','pdf'=>'application/pdf',
          'mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','m4v'=>'video/x-m4v',
          'mp3'=>'audio/mpeg','wav'=>'audio/wav','m4a'=>'audio/mp4','txt'=>'text/plain; charset=utf-8'];
    return $m[$ext] ?? null;
}

/* ---------- CSRF ----------
   Un único token por sesión. Se comprueba automáticamente en TODAS las
   peticiones POST que pasen por este archivo (ver el bloque del final).

   · Formularios normales  ->  echo csrf_field();   (o lo inyecta el JS de erp_foot)
   · fetch()               ->  cabecera X-CSRF-Token (la añade el wrapper de erp_foot)
   · Si un script necesita quedar fuera (webhooks, API), debe definir
     define('CROILAB_NO_CSRF', true);  ANTES de incluir auth.php.              */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}
function csrf_valid() {
    $given = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($given) || $given === '') return false;
    return hash_equals(csrf_token(), $given);
}
/* Corta la ejecución si el token no es válido. Responde JSON si la petición
   venía de un fetch, y una página de error legible si venía de un formulario. */
function csrf_fail() {
    http_response_code(419);
    $wantsJson = (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
              || (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
              || !empty($_SERVER['HTTP_X_CSRF_TOKEN'])
              || !empty($_POST['action']);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'csrf', 'msg' => 'La sesión ha caducado. Recarga la página.']);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Sesión caducada</title>'
           . '<div style="font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;max-width:420px;margin:18vh auto;text-align:center;color:#1a1a1a">'
           . '<div style="font-size:17px;font-weight:600;margin-bottom:6px">Sesión caducada</div>'
           . '<div style="color:#6b7280">Por seguridad no se ha guardado el cambio. Vuelve atrás y recarga la página.</div>'
           . '</div>';
    }
    exit;
}
function csrf_check() { if (!csrf_valid()) csrf_fail(); }

/* Los roles y permisos se cargan aquí, al final y a propósito: require_admin()
   los necesita ya definidos, y la mayoría de las páginas llaman a require_admin()
   antes de incluir erp_nav.php o cualquier librería. Va detrás de e() y jdecode()
   porque permisos.php las usa (solo dentro de funciones, pero así queda claro el
   orden). El `file_exists` es por si alguna instalación no tiene la carpeta. */
if (is_file(__DIR__ . '/admin/lib/permisos.php')) require_once __DIR__ . '/admin/lib/permisos.php';

/* Comprobación automática: cualquier POST que llegue a una página que incluya
   auth.php queda protegido sin tener que tocar la página una por una. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !defined('CROILAB_NO_CSRF')) {
    csrf_check();
}
