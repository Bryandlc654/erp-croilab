<?php
/* Candado para install.php y migrate.php.
   Estos dos scripts crean tablas y usuarios, así que no pueden quedar
   abiertos en el servidor. Reglas:

     · Instalación nueva (todavía no hay ningún admin en la base) -> se deja
       pasar, que es justo para lo que sirven.
     · Ya hay admins -> solo pasa si has iniciado sesión como dueño.

   Con esto se puede dejar el archivo subido sin que nadie de fuera lo ejecute.
   Aun así, lo recomendable sigue siendo borrarlos cuando termines. */

require_once __DIR__ . '/db.php';

function setup_guard() {
    $hayAdmins = false;
    try {
        $n = db()->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        $hayAdmins = ((int)$n > 0);
    } catch (PDOException $e) {
        /* Solo se considera «instalación nueva» si la tabla admins NO existe
           (SQLSTATE 42S02 en MySQL o «no such table» en SQLite). Ante cualquier
           OTRO error (BBDD caída, permisos, etc.) se asume que SÍ hay admins y se
           CIERRA: nunca abrir install/migrate por un fallo transitorio. */
        $sinTabla = ($e->getCode() === '42S02') || (stripos($e->getMessage(), 'no such table') !== false);
        $hayAdmins = !$sinTabla;
    } catch (Exception $e) {
        $hayAdmins = true;   // error inesperado: por seguridad, cerrado
    }

    if (!$hayAdmins) return;   // primera vez: adelante

    require_once __DIR__ . '/auth.php';
    if (function_exists('is_owner') && is_owner()) return;   // el dueño puede repetirlo

    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>No disponible</title>'
       . '<div style="font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;max-width:440px;margin:18vh auto;text-align:center;color:#1a1a1a">'
       . '<div style="font-size:17px;font-weight:600;margin-bottom:6px">Esta página está cerrada</div>'
       . '<div style="color:#6b7280">La instalación ya está hecha. Si necesitas volver a ejecutarla, '
       . 'entra antes en el panel como dueño.</div>'
       . '<div style="margin-top:18px"><a href="admin/login.php" style="color:#2f6df6;text-decoration:none">Ir al panel →</a></div>'
       . '</div>';
    exit;
}

setup_guard();
