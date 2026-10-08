<?php
/* Clientes: listado, ficha, alta/edición, papelera, duplicar, contraseña del
   portal; y en Ajustes sus tipos, el catálogo de servicios, la marca blanca,
   las métricas de Google de cada uno y los datos avanzados.
   Documentadas en docs/migracion/api/clientes.md. */

require_once __DIR__ . '/../../admin/lib/marca.php';
require_once __DIR__ . '/../../admin/lib/servicios_cat.php';
require_once __DIR__ . '/../../admin/lib/boveda.php';

use Croilab\Google\GoogleOAuth;
use Croilab\Google\Metricas;
use Croilab\Http\Contenedor;
use Croilab\Http\Router;
use Croilab\Modulos\Clientes\AgenciasServicio;
use Croilab\Modulos\Clientes\AjustesController;
use Croilab\Modulos\Clientes\AvanzadoServicio;
use Croilab\Modulos\Clientes\ClientesController;
use Croilab\Modulos\Clientes\ClientesServicio;
use Croilab\Modulos\Clientes\FichaRepositorio;
use Croilab\Modulos\Clientes\GoogleServicio;
use Croilab\Modulos\Clientes\ServiciosServicio;
use Croilab\Modulos\Clientes\TiposServicio;

return function (Router $r, Contenedor $c): void {
    /* El servicio queda en el contenedor por si otro módulo lo necesita (p. ej. el portal). */
    $servicio = $c->unico('clientes.servicio', fn(Contenedor $c) => new ClientesServicio($c->pdo, $c->clientes(), new FichaRepositorio($c->pdo), $c->equipo()));
    $clientes = new ClientesController($c->clientes(), $servicio);
    $ajustes = new AjustesController(
        new TiposServicio($c->pdo),
        new ServiciosServicio(),
        new AgenciasServicio($c->pdo),
        /* Google: la misma instancia de GoogleOAuth que usa Equipo. */
        new GoogleServicio($c->pdo, $servicio, new Metricas($c->unico('google', fn() => new GoogleOAuth()), $c->pdo)),
        new AvanzadoServicio($c->pdo, $servicio)
    );

    /* Ajustes de clientes (rutas fijas antes que las de /{id}; no chocan porque {id} es numérico). */
    $r->get('/v1/clientes/tipos', [$ajustes, 'tipos']);
    $r->post('/v1/clientes/tipos', [$ajustes, 'crearTipo']);
    $r->get('/v1/clientes/tipos/{id}', [$ajustes, 'tipo']);
    $r->patch('/v1/clientes/tipos/{id}', [$ajustes, 'actualizarTipo']);
    $r->delete('/v1/clientes/tipos/{id}', [$ajustes, 'borrarTipo']);
    $r->get('/v1/clientes/servicios', [$ajustes, 'servicios']);
    $r->patch('/v1/clientes/servicios', [$ajustes, 'guardarServicios']);
    $r->get('/v1/clientes/agencias', [$ajustes, 'agencias']);
    $r->post('/v1/clientes/agencias', [$ajustes, 'crearAgencia']);
    $r->patch('/v1/clientes/agencias/{id}', [$ajustes, 'actualizarAgencia']);
    $r->delete('/v1/clientes/agencias/{id}', [$ajustes, 'borrarAgencia']);
    $r->post('/v1/clientes/avanzado/bloquear', [$ajustes, 'bloquear']);
    $r->post('/v1/clientes/papelera/{id}/restaurar', [$clientes, 'restaurar']);

    $r->get('/v1/clientes', [$clientes, 'listar']);
    $r->post('/v1/clientes', [$clientes, 'crear']);
    $r->get('/v1/clientes/{id}', [$clientes, 'ver']);
    $r->patch('/v1/clientes/{id}', [$clientes, 'actualizar']);
    $r->delete('/v1/clientes/{id}', [$clientes, 'borrar']);
    $r->get('/v1/clientes/{id}/ficha', [$clientes, 'ficha']);
    $r->get('/v1/clientes/{id}/ficha/secreto/{cred}', [$clientes, 'secreto']);
    $r->get('/v1/clientes/{id}/datos', [$clientes, 'datos']);
    $r->post('/v1/clientes/{id}/duplicar', [$clientes, 'duplicar']);
    $r->post('/v1/clientes/{id}/password', [$clientes, 'password']);
    $r->post('/v1/clientes/{id}/informe', [$clientes, 'informe']);
    $r->patch('/v1/clientes/{id}/agencia', [$ajustes, 'asignarAgencia']);
    $r->get('/v1/clientes/{id}/google', [$ajustes, 'google']);
    $r->patch('/v1/clientes/{id}/google', [$ajustes, 'guardarGoogle']);
    $r->get('/v1/clientes/{id}/google/eventos', [$ajustes, 'eventosGoogle']);
    $r->post('/v1/clientes/{id}/google/sync', [$ajustes, 'sincronizarGoogle']);
    $r->get('/v1/clientes/{id}/avanzado', [$ajustes, 'avanzado']);
    $r->patch('/v1/clientes/{id}/avanzado', [$ajustes, 'guardarAvanzado']);
};
