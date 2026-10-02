<?php
/* Todas las rutas de la API en un sitio. Documentadas en backend/API.md.
   `true` en el tercer argumento = pública (no exige sesión; el CSRF de los
   POST se comprueba igual). */

use Croilab\Http\Router;
use Croilab\Correo\Fabrica;
use Croilab\Modulos\Auth\AuthController;
use Croilab\Modulos\Auth\RecuperacionController;
use Croilab\Modulos\Auth\RecuperacionServicio;
use Croilab\Modulos\Clientes\ClientesController;
use Croilab\Modulos\Clientes\ClientesRepositorio;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Modulos\Nav\NavController;
use Croilab\Modulos\Tareas\TareasController;
use Croilab\Modulos\Tareas\TareasRepositorio;
use Croilab\Modulos\Tareas\TareasServicio;

return function (Router $r, PDO $pdo): void {
    /* Las dependencias se crean aquí, a mano: pocas y explícitas. */
    $equipo = new EquipoRepositorio($pdo);
    $clientesRepo = new ClientesRepositorio($pdo);
    $tareas = new TareasController(new TareasServicio(new TareasRepositorio($pdo, $equipo), $clientesRepo, $equipo));
    $clientes = new ClientesController($clientesRepo);
    $auth = new AuthController($equipo);
    $nav = new NavController($clientesRepo, $equipo);
    $recuperar = new RecuperacionController(function () use ($pdo) {
        require_once __DIR__ . '/../admin/lib/marca.php';
        return new RecuperacionServicio($pdo, Fabrica::desdeConfig(), RecuperacionController::urlFront(), marca_agencia()['name']);
    });

    $r->get('/v1/auth/csrf', [$auth, 'csrf'], true);
    $r->post('/v1/auth/login', [$auth, 'login'], true);
    $r->post('/v1/auth/logout', [$auth, 'logout'], true);
    $r->get('/v1/me', [$auth, 'me']);
    $r->post('/v1/auth/recuperar', [$recuperar, 'pedir'], true);
    $r->get('/v1/auth/restablecer', [$recuperar, 'comprobar'], true);
    $r->post('/v1/auth/restablecer', [$recuperar, 'restablecer'], true);

    $r->get('/v1/nav', [$nav, 'nav']);
    $r->get('/v1/equipo', [$nav, 'equipo']);

    $r->get('/v1/clientes', [$clientes, 'listar']);
    $r->get('/v1/clientes/{id}', [$clientes, 'ver']);
    $r->post('/v1/clientes/{id}/informe', [$clientes, 'informe']);

    $r->get('/v1/tareas', [$tareas, 'listar']);
    $r->post('/v1/tareas', [$tareas, 'crear']);
    $r->get('/v1/tareas/{id}', [$tareas, 'ver']);
    $r->patch('/v1/tareas/{id}', [$tareas, 'actualizar']);
    $r->delete('/v1/tareas/{id}', [$tareas, 'borrar']);
    $r->post('/v1/papelera/{id}/restaurar', [$tareas, 'restaurar']);
};
