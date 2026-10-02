<?php
/* ===========================================================
   SESIÃ“N Y CSRF â€” sin base de datos.
   Vive aparte de auth.php a propÃ³sito: logout.php tambiÃ©n lo necesita, y si
   cargara auth.php arrastrarÃ­a db.php, con lo que el cierre de sesiÃ³n
   fallarÃ­a justo cuando la base de datos estÃ¡ caÃ­da.
   =========================================================== */

if (session_status() === PHP_SESSION_NONE) {
    /* DetecciÃ³n segura de HTTPS, compatible con proxies inversos (Cloudflare, LB). */
    $isHttps = false;
    if ((!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
        || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) === 'on')
        || (!empty($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https')) {
        $isHttps = true;
    }
    $secure = $isHttps;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => $_SERVER['HTTP_HOST'] ?? null, /* evita compartir cookie entre subdominios no deseados */
        'httponly' => true,
        'samesite' => 'None',
        'secure'   => $secure,
    ]);
    session_name('croilab_portal');
    session_start();
}

/* ---------- CSRF ----------
   Un Ãºnico token por sesiÃ³n. Se comprueba automÃ¡ticamente en TODAS las
   peticiones POST que pasen por auth.php (ver el bloque del final).

   Â· Formularios normales  ->  echo csrf_field();   (o lo inyecta el JS de erp_foot)
   Â· fetch()               ->  cabecera X-CSRF-Token (la aÃ±ade el wrapper de erp_foot)
   Â· Si un script necesita quedar fuera (webhooks, API), debe definir
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
/* Corta la ejecuciÃ³n si el token no es vÃ¡lido. Responde JSON si la peticiÃ³n
   venÃ­a de un fetch, y una pÃ¡gina de error legible si venÃ­a de un formulario. */
function csrf_fail() {
    http_response_code(419);
    $wantsJson = (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
              || (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
              || !empty($_SERVER['HTTP_X_CSRF_TOKEN'])
              || !empty($_POST['action']);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'csrf', 'msg' => 'La sesiÃ³n ha caducado. Recarga la pÃ¡gina.']);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>SesiÃ³n caducada</title>'
           . '<div style="font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;max-width:420px;margin:18vh auto;text-align:center;color:#1a1a1a">'
           . '<div style="font-size:17px;font-weight:600;margin-bottom:6px">SesiÃ³n caducada</div>'
           . '<div style="color:#6b7280">Por seguridad no se ha guardado el cambio. Vuelve atrÃ¡s y recarga la pÃ¡gina.</div>'
           . '</div>';
    }
    exit;
}
  function csrf_check() { if (!csrf_valid()) csrf_fail(); }

  /* ---------- TRAZA DE SESIONES ----------
     El cierre de sesiÃ³n y los cambios de credenciales dejan rastro, pero sin
     tocar la base de datos: logout.php tiene que funcionar precisamente cuando
     la base de datos estÃ¡ caÃ­da, que es cuando mÃ¡s hace falta poder revisar quÃ©
     pasÃ³. Por eso la traza va a un fichero de texto, como el log de errores.

     Nunca debe romper la pÃ¡gina que audita: si no se puede escribir, se recurre
     al log de PHP y, si tampoco, se.calla. Todas las llamadas van envueltas. */
  function sesion_log_destino() {
      static $destino = false;
      if ($destino !== false) return $destino;
      $destino = null;
      /* Fuera de la carpeta pÃºblica, siempre. El primer sitio que se prueba es el
         directorio padre del docroot; si no es escribible, la carpeta temporal
         del sistema. Nunca el propio docroot, que es servible por HTTP. */
      foreach (array(dirname(__DIR__) . '/sesion.log', sys_get_temp_dir() . '/croilab-sesion.log') as $cand) {
          if (is_dir(dirname($cand)) && is_writable(dirname($cand))) { $destino = $cand; break; }
      }
      return $destino;
  }
  function sesion_auditar($evento, $detalle = '') {
      static $n = 0;
      /* Un tope por peticiÃ³n. Si un bucle cierra sesiones sin querer, esto no
         se convierte en un disco lleno ni en una ralentizaciÃ³n. */
      if (++$n > 20) return;
      try {
          $quien = $_SESSION['admin_id'] ?? ($_SESSION['client_id'] ?? null);
          $tipo  = isset($_SESSION['admin_id'])  ? 'admin'
                 : (isset($_SESSION['client_id']) ? 'cliente' : 'anonimo');
          $linea = sprintf(
              "%s\t%s\t%s\t%s\t%s\t%s\n",
              date('Y-m-d H:i:s'),
              str_replace(array("\t", "\n"), ' ', substr((string)$evento, 0, 60)),
              $tipo,
              $quien !== null ? (int)$quien : '-',
              substr((string)($_SERVER['REMOTE_ADDR'] ?? '-'), 0, 45),
              str_replace(array("\t", "\n"), ' ', substr((string)$detalle, 0, 200))
          );
          $destino = sesion_log_destino();
          if ($destino !== null) { @file_put_contents($destino, $linea, FILE_APPEND | LOCK_EX); return; }
          @error_log('sesion: ' . $linea);
      } catch (Throwable $e) { /* auditar nunca rompe la pÃ¡gina */ }
  }

  /* Cierra la sesiÃ³n en curso y deja constancia. Sin base de datos a propÃ³sito.
     $motivo se usa dos veces: en la traza de aquÃ­ y, si la tabla de auditorÃ­a
     existe y estÃ¡ cargada, en audit_log(). */
  function sesion_cerrar($motivo = '') {
      if ($motivo !== '') $GLOBALS['sesion_motivo'] = $motivo;
      sesion_auditar('cierre', $motivo);
      if (session_status() === PHP_SESSION_ACTIVE) {
          $_SESSION = [];
          if (ini_get('session.use_cookies')) {
              $p = session_get_cookie_params();
              setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
          }
          session_destroy();
      }
      if (function_exists('audit_log') && $motivo !== '') audit_log('sesion.cerrada', $motivo);
  }

