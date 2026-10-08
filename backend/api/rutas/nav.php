<?php
/* Contadores de la barra lateral y equipo. */

use Croilab\Http\Contenedor;
use Croilab\Http\Router;
use Croilab\Modulos\Nav\NavController;

return function (Router $r, Contenedor $c): void {
    $nav = new NavController($c->clientes(), $c->equipo());
    $r->get('/v1/nav', [$nav, 'nav']);
    $r->get('/v1/equipo', [$nav, 'equipo']);
};
