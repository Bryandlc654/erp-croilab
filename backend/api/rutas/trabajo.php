<?php
/* Trabajo: inicio, bandeja de avisos (y su sondeo), búsqueda global,
   papelera y actas. */

use Croilab\Http\Contenedor;
use Croilab\Http\Router;
use Croilab\Modulos\Trabajo\ActasRepositorio;
use Croilab\Modulos\Trabajo\ActasServicio;
use Croilab\Modulos\Trabajo\BuscarServicio;
use Croilab\Modulos\Trabajo\InicioServicio;
use Croilab\Modulos\Trabajo\NotificacionesRepositorio;
use Croilab\Modulos\Trabajo\NotificacionesServicio;
use Croilab\Modulos\Trabajo\PapeleraServicio;
use Croilab\Modulos\Trabajo\TrabajoController;

return function (Router $r, Contenedor $c): void {
    $t = new TrabajoController(
        new InicioServicio($c->pdo, $c->equipo()),
        new NotificacionesServicio(new NotificacionesRepositorio($c->pdo)),
        new BuscarServicio($c->pdo),
        new PapeleraServicio($c->pdo),
        new ActasServicio(new ActasRepositorio($c->pdo), $c->equipo())
    );

    $r->get('/v1/inicio', [$t, 'inicio']);
    $r->get('/v1/inicio/agenda', [$t, 'agenda']);

    $r->get('/v1/notificaciones', [$t, 'notificaciones']);
    $r->get('/v1/notificaciones/avisos', [$t, 'sondeo']);
    $r->post('/v1/notificaciones/acciones', [$t, 'accionAvisos']);
    $r->post('/v1/notificaciones/leer-todas', [$t, 'leerTodas']);
    $r->post('/v1/notificaciones/leidas-a-papelera', [$t, 'leidasAPapelera']);
    $r->post('/v1/notificaciones/vaciar-papelera', [$t, 'vaciarAvisos']);

    $r->get('/v1/buscar', [$t, 'buscar']);

    $r->get('/v1/papelera', [$t, 'papelera']);
    $r->delete('/v1/papelera', [$t, 'vaciarPapelera']);
    $r->post('/v1/papelera/{id}/restaurar', [$t, 'restaurar']);
    $r->delete('/v1/papelera/{id}', [$t, 'purgar']);

    $r->get('/v1/actas', [$t, 'actas']);
    $r->post('/v1/actas', [$t, 'crearActa']);
    $r->get('/v1/actas/{id}', [$t, 'acta']);
    $r->patch('/v1/actas/{id}', [$t, 'guardarActa']);
    $r->post('/v1/actas/{id}/fijar', [$t, 'fijarActa']);
    $r->delete('/v1/actas/{id}', [$t, 'borrarActa']);
};
