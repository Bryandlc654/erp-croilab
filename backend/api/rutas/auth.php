<?php
/* Sesión del equipo y recuperación de contraseña. */

use Croilab\Correo\Fabrica;
use Croilab\Http\Contenedor;
use Croilab\Http\Router;
use Croilab\Modulos\Auth\AuthController;
use Croilab\Modulos\Auth\RecuperacionController;
use Croilab\Modulos\Auth\RecuperacionServicio;

return function (Router $r, Contenedor $c): void {
    $auth = new AuthController($c->equipo());
    $recuperar = new RecuperacionController(function () use ($c) {
        require_once __DIR__ . '/../../admin/lib/marca.php';
        return new RecuperacionServicio($c->pdo, Fabrica::desdeConfig(), RecuperacionController::urlFront(), marca_agencia()['name']);
    });

    $r->get('/v1/auth/csrf', [$auth, 'csrf'], true);
    $r->post('/v1/auth/login', [$auth, 'login'], true);
    $r->post('/v1/auth/logout', [$auth, 'logout'], true);
    $r->get('/v1/me', [$auth, 'me']);
    $r->post('/v1/auth/recuperar', [$recuperar, 'pedir'], true);
    $r->get('/v1/auth/restablecer', [$recuperar, 'comprobar'], true);
    $r->post('/v1/auth/restablecer', [$recuperar, 'restablecer'], true);
};
