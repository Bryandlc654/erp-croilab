<?php
/* Tareas: tablero, ficha (checklist, comentarios, adjuntos, horas) y listas
   de cada cliente. La papelera general está en trabajo.php. */

use Croilab\Http\Contenedor;
use Croilab\Http\Router;
use Croilab\Modulos\Tareas\ArchivosTarea;
use Croilab\Modulos\Tareas\FichaController;
use Croilab\Modulos\Tareas\FichaRepositorio;
use Croilab\Modulos\Tareas\FichaServicio;
use Croilab\Modulos\Tareas\ListasController;
use Croilab\Modulos\Tareas\ListasRepositorio;
use Croilab\Modulos\Tareas\ListasServicio;
use Croilab\Modulos\Tareas\TareasController;
use Croilab\Modulos\Tareas\TareasRepositorio;
use Croilab\Modulos\Tareas\TareasServicio;

return function (Router $r, Contenedor $c): void {
    $repo = $c->unico('tareas.repo', fn($c) => new TareasRepositorio($c->pdo, $c->equipo()));
    $servicio = $c->unico('tareas.servicio', fn($c) => new TareasServicio($repo, $c->clientes(), $c->equipo()));
    $tareas = new TareasController($servicio);
    $ficha = new FichaController(new FichaServicio($repo, new FichaRepositorio($c->pdo, $c->equipo()), $servicio, $c->equipo(), ArchivosTarea::porDefecto()));
    $listas = new ListasController(new ListasServicio(new ListasRepositorio($c->pdo), $c->clientes()));

    /* Tablero */
    $r->get('/v1/tareas', [$tareas, 'listar']);
    $r->post('/v1/tareas', [$tareas, 'crear']);
    $r->get('/v1/tareas/meses', [$tareas, 'meses']);
    $r->post('/v1/tareas/orden', [$tareas, 'reordenar']);
    $r->get('/v1/tareas/{id}', [$ficha, 'ver']);
    $r->patch('/v1/tareas/{id}', [$tareas, 'actualizar']);
    $r->delete('/v1/tareas/{id}', [$tareas, 'borrar']);

    /* Ficha */
    $r->post('/v1/tareas/{id}/checklist', [$ficha, 'crearPunto']);
    $r->post('/v1/tareas/{id}/checklist/orden', [$ficha, 'ordenarPuntos']);
    $r->patch('/v1/tareas/{id}/checklist/{chk}', [$ficha, 'cambiarPunto']);
    $r->delete('/v1/tareas/{id}/checklist/{chk}', [$ficha, 'borrarPunto']);
    $r->get('/v1/tareas/{id}/comentarios', [$ficha, 'comentarios']);
    $r->post('/v1/tareas/{id}/comentarios', [$ficha, 'comentar']);
    $r->patch('/v1/tareas/{id}/comentarios/{cid}', [$ficha, 'editarComentario']);
    $r->delete('/v1/tareas/{id}/comentarios/{cid}', [$ficha, 'borrarComentario']);
    $r->post('/v1/tareas/{id}/comentarios/{cid}/reacciones', [$ficha, 'reaccionar']);
    $r->post('/v1/tareas/{id}/comentarios/{cid}/checklist/{idx}', [$ficha, 'marcarPuntoComentario']);
    $r->post('/v1/tareas/{id}/adjuntos', [$ficha, 'adjuntar']);
    $r->delete('/v1/tareas/{id}/adjuntos/{aid}', [$ficha, 'quitarAdjunto']);
    $r->post('/v1/tareas/{id}/archivos', [$ficha, 'subirParaDescripcion']);
    $r->post('/v1/tareas/{id}/tiempo', [$ficha, 'tiempo']);

    /* Listas de un cliente */
    $r->get('/v1/tareas/listas', [$listas, 'deCliente']);
    $r->post('/v1/tareas/listas', [$listas, 'crear']);
    $r->post('/v1/tareas/listas/orden', [$listas, 'reordenar']);
    $r->post('/v1/tareas/publicar', [$listas, 'publicar']);
    $r->patch('/v1/tareas/listas/{id}', [$listas, 'actualizar']);
    $r->delete('/v1/tareas/listas/{id}', [$listas, 'borrar']);
    $r->post('/v1/tareas/listas/{id}/clonar', [$listas, 'clonar']);
    $r->get('/v1/tareas/listas/{id}/informe', [$listas, 'informe']);
    $r->post('/v1/tareas/listas/{id}/informe', [$listas, 'guardarInforme']);
};
