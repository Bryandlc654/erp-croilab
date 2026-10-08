<?php
/* Portal del cliente (su propia sesión) y, para el equipo, la vista previa y
   el editor en vivo del portal de un cliente. Documentado en
   docs/migracion/api/portal.md.

   OJO: las rutas del cliente son públicas para el Kernel (no hay
   current_admin): cada acción exige la sesión del portal (PortalSesion) y
   toma el cliente de ahí. Las de /v1/portal/equipo/… son del equipo. */

require_once __DIR__ . '/../../admin/lib/login_throttle.php';
require_once __DIR__ . '/../../admin/lib/marca.php';
require_once __DIR__ . '/../../admin/lib/servicios_cat.php';
require_once __DIR__ . '/../../admin/lib/boveda.php';

use Croilab\Correo\Fabrica;
use Croilab\Google\GoogleOAuth;
use Croilab\Http\Contenedor;
use Croilab\Http\Router;
use Croilab\Modulos\Clientes\ClientesServicio;
use Croilab\Modulos\Clientes\FichaRepositorio;
use Croilab\Modulos\Comunicacion\Soporte\SoporteRepositorio;
use Croilab\Modulos\Comunicacion\Soporte\SoporteServicio;
use Croilab\Modulos\Equipo\BovedaServicio;
use Croilab\Modulos\Finanzas\Modulo;
use Croilab\Modulos\Portal\EditorServicio;
use Croilab\Modulos\Portal\PortalAuthServicio;
use Croilab\Modulos\Portal\PortalController;
use Croilab\Modulos\Portal\PortalRepositorio;
use Croilab\Modulos\Portal\PortalServicio;
use Croilab\Modulos\Portal\PortalSesion;

return function (Router $r, Contenedor $c): void {
    $sesion = $c->unico('portal.sesion', fn(Contenedor $c) => new PortalSesion($c->pdo));
    $repo = new PortalRepositorio($c->pdo);
    /* Lo de otros módulos se crea al usarlo, con la misma instancia que ellos si ya existe. */
    $portal = $c->unico('portal.servicio', fn(Contenedor $c) => new PortalServicio(
        $c->pdo,
        $repo,
        fn() => $c->unico('finanzas', fn(Contenedor $c) => new Modulo($c->pdo))->hoja(),
        fn() => new SoporteServicio(new SoporteRepositorio($c->pdo), $c->equipo()),
        fn() => new BovedaServicio($c->pdo)
    ));
    $clientes = $c->unico('clientes.servicio', fn(Contenedor $c) => new ClientesServicio($c->pdo, $c->clientes(), new FichaRepositorio($c->pdo), $c->equipo()));
    $auth = fn() => $c->unico('portal.auth', fn(Contenedor $c) => new PortalAuthServicio(
        $c->pdo,
        $sesion,
        $c->unico('google', fn() => new GoogleOAuth()),
        fn() => Fabrica::desdeConfig(),
        rtrim(Fabrica::valor('FRONT_URL'), '/')
    ));
    $p = new PortalController($sesion, $auth, $portal, new EditorServicio($c->pdo, $clientes, $c->clientes(), $repo));

    /* Cliente (públicas para el Kernel; CSRF en los POST igual que siempre). */
    $r->get('/v1/portal/sesion', [$p, 'sesion'], true);
    $r->post('/v1/portal/auth/login', [$p, 'entrar'], true);
    $r->post('/v1/portal/auth/logout', [$p, 'salir'], true);
    $r->get('/v1/portal/auth/google', [$p, 'google'], true);
    $r->post('/v1/portal/auth/recuperar', [$p, 'recuperar'], true);
    $r->get('/v1/portal/auth/restablecer', [$p, 'comprobarEnlace'], true);
    $r->post('/v1/portal/auth/restablecer', [$p, 'restablecer'], true);
    $r->get('/v1/portal', [$p, 'datos'], true);
    $r->get('/v1/portal/facturas/{id}', [$p, 'factura'], true);
    $r->get('/v1/portal/facturas/{id}/pdf', [$p, 'pdf'], true);
    $r->post('/v1/portal/tickets', [$p, 'escribir'], true);
    $r->get('/v1/portal/tickets/{id}', [$p, 'ticket'], true);
    $r->post('/v1/portal/reuniones/solicitudes', [$p, 'solicitarReunion'], true);
    $r->post('/v1/portal/credenciales/{id}/secreto', [$p, 'secreto'], true);

    /* Equipo (sesión del equipo, permisos y alcance). */
    $r->get('/v1/portal/equipo/clientes', [$p, 'clientesEquipo']);
    $r->get('/v1/portal/equipo/clientes/{id}', [$p, 'vistaPrevia']);
    $r->get('/v1/portal/equipo/clientes/{id}/facturas/{factura}', [$p, 'facturaVistaPrevia']);
    $r->get('/v1/portal/equipo/clientes/{id}/facturas/{factura}/pdf', [$p, 'pdfVistaPrevia']);
    $r->get('/v1/portal/equipo/clientes/{id}/tickets/{ticket}', [$p, 'ticketVistaPrevia']);
    $r->get('/v1/portal/equipo/clientes/{id}/editor', [$p, 'editor']);
    $r->patch('/v1/portal/equipo/clientes/{id}/editor', [$p, 'guardarEditor']);
};
