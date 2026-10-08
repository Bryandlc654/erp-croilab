<?php
/* Equipo y ajustes: miembros, invitaciones y registro por enlace, perfiles y
   cuenta propia, roles, ajustes de la agencia y del portal, integraciones
   (Google OAuth, API, MCP) y bóveda de credenciales de clientes.
   Documentado en docs/migracion/api/equipo.md. */

use Croilab\Http\Contenedor;
use Croilab\Http\Router;
use Croilab\Modulos\Equipo\EquipoController;
use Croilab\Modulos\Equipo\Servicios;

return function (Router $r, Contenedor $c): void {
    $e = new EquipoController(new Servicios($c));

    /* Mi equipo */
    $r->get('/v1/equipo/miembros', [$e, 'miembros']);
    $r->post('/v1/equipo/miembros', [$e, 'crearMiembro']);
    $r->get('/v1/equipo/miembros/{id}', [$e, 'miembro']);
    $r->patch('/v1/equipo/miembros/{id}', [$e, 'actualizarMiembro']);
    $r->delete('/v1/equipo/miembros/{id}', [$e, 'bajaMiembro']);
    $r->post('/v1/equipo/miembros/{id}/rol', [$e, 'rolMiembro']);
    $r->patch('/v1/equipo/miembros/{id}/facturacion', [$e, 'facturacionMiembro']);
    $r->post('/v1/equipo/miembros/{id}/password', [$e, 'passwordMiembro']);
    $r->delete('/v1/equipo/miembros/{id}/password-enlace', [$e, 'anularEnlaceMiembro']);
    $r->post('/v1/equipo/miembros/{id}/reactivar', [$e, 'reactivarMiembro']);

    /* Registro por enlace */
    $r->get('/v1/equipo/invitaciones', [$e, 'invitaciones']);
    $r->post('/v1/equipo/invitaciones', [$e, 'crearInvitacion']);
    $r->get('/v1/equipo/invitaciones/{id}/enlace', [$e, 'enlaceInvitacion']);
    $r->delete('/v1/equipo/invitaciones/{id}', [$e, 'anularInvitacion']);
    $r->get('/v1/registro', [$e, 'comprobarRegistro'], true);
    $r->post('/v1/registro', [$e, 'registrar'], true);

    /* Volver a pedir la contraseña (zonas sensibles) */
    $r->post('/v1/auth/reconfirmar', [$e, 'reconfirmar']);

    /* Perfiles y cuenta propia */
    $r->get('/v1/perfiles/{id}', [$e, 'perfil']);
    $r->patch('/v1/perfiles/{id}', [$e, 'actualizarPerfil']);
    $r->post('/v1/perfiles/{id}/foto', [$e, 'subirFoto']);
    $r->delete('/v1/perfiles/{id}/foto', [$e, 'quitarFoto']);
    $r->get('/v1/me/cuenta', [$e, 'cuenta']);
    $r->patch('/v1/me/cuenta', [$e, 'actualizarCuenta']);
    $r->post('/v1/me/password', [$e, 'cambiarPassword']);
    $r->get('/v1/me/avisos', [$e, 'avisos']);
    $r->patch('/v1/me/avisos', [$e, 'guardarAvisos']);

    /* Roles (las claves de rol son texto: van en el cuerpo o en ?rol=) */
    $r->get('/v1/roles', [$e, 'roles']);
    $r->post('/v1/roles', [$e, 'crearRol']);
    $r->patch('/v1/roles', [$e, 'renombrarRol']);
    $r->delete('/v1/roles', [$e, 'borrarRol']);
    $r->post('/v1/roles/permisos', [$e, 'permisoRol']);
    $r->post('/v1/roles/grupos', [$e, 'grupoRol']);

    /* Ajustes de la agencia y del portal */
    $r->get('/v1/ajustes/agencia', [$e, 'agencia']);
    $r->patch('/v1/ajustes/agencia', [$e, 'guardarAgencia']);
    $r->post('/v1/ajustes/agencia/logo', [$e, 'subirLogo']);
    $r->delete('/v1/ajustes/agencia/logo', [$e, 'quitarLogo']);
    $r->get('/v1/ajustes/portal/contacto', [$e, 'contacto']);
    $r->patch('/v1/ajustes/portal/contacto', [$e, 'guardarContacto']);
    $r->get('/v1/ajustes/portal/videos', [$e, 'videos']);
    $r->patch('/v1/ajustes/portal/videos', [$e, 'guardarVideos']);
    $r->get('/v1/ajustes/automatizaciones', [$e, 'reglas']);
    $r->patch('/v1/ajustes/automatizaciones', [$e, 'regla']);
    $r->post('/v1/ajustes/automatizaciones/ejecutar', [$e, 'ejecutarReglas']);
    $r->get('/v1/ajustes/metricas', [$e, 'metricas']);
    $r->patch('/v1/ajustes/metricas', [$e, 'eventosMetricas']);
    $r->post('/v1/ajustes/metricas/sync', [$e, 'sincronizarMetricas']);
    $r->post('/v1/ajustes/metricas/sync/{id}', [$e, 'sincronizarMetricasCliente']);

    /* Integraciones */
    $r->get('/v1/integraciones', [$e, 'integraciones']);
    $r->get('/v1/integraciones/api/token', [$e, 'verTokenApi']);
    $r->post('/v1/integraciones/api/token', [$e, 'regenerarTokenApi']);
    $r->patch('/v1/integraciones/google-calendar/credenciales', [$e, 'credencialesCalendar']);
    $r->get('/v1/integraciones/google-calendar/conectar', [$e, 'conectarCalendar']);
    $r->delete('/v1/integraciones/google-calendar/conexion', [$e, 'desconectarCalendar']);
    $r->patch('/v1/integraciones/metricas/credenciales', [$e, 'credencialesMetricas']);
    $r->post('/v1/integraciones/metricas/credenciales', [$e, 'credencialesMetricasJson']);
    $r->get('/v1/integraciones/metricas/conectar', [$e, 'conectarMetricas']);
    $r->delete('/v1/integraciones/metricas/conexion', [$e, 'desconectarMetricas']);
    $r->get('/v1/integraciones/google/callback', [$e, 'vueltaGoogle'], true);
    $r->patch('/v1/integraciones/mcp', [$e, 'mcp']);
    $r->get('/v1/integraciones/mcp/url', [$e, 'verMcp']);
    $r->post('/v1/integraciones/mcp/token', [$e, 'regenerarMcp']);

    /* Bóveda de credenciales de clientes */
    $r->get('/v1/credenciales/clientes', [$e, 'bovedaClientes']);
    $r->get('/v1/credenciales/clientes/{id}', [$e, 'credenciales']);
    $r->post('/v1/credenciales/clientes/{id}', [$e, 'crearCredencial']);
    $r->patch('/v1/credenciales/{id}', [$e, 'actualizarCredencial']);
    $r->delete('/v1/credenciales/{id}', [$e, 'borrarCredencial']);
    $r->post('/v1/credenciales/{id}/revelar', [$e, 'revelarCredencial']);
};
