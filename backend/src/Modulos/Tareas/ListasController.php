<?php
namespace Croilab\Modulos\Tareas;

use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

/* Listas de tareas de un cliente, «Informe del mes» y publicar al portal. */
class ListasController
{
    public function __construct(private readonly ListasServicio $servicio) {}

    public function deCliente(Request $req): array
    {
        return ['listas' => $this->servicio->deCliente(Acceso::actual(), $req->entero('cli'))];
    }

    public function crear(Request $req): Respuesta
    {
        return new Respuesta($this->servicio->crear(Acceso::actual(), $req->json()), 201);
    }

    public function actualizar(Request $req): array
    {
        return ['lista' => $this->servicio->actualizar(Acceso::actual(), $req->param('id'), $req->json())];
    }

    public function reordenar(Request $req): array
    {
        $d = $req->json();
        return ['listas' => $this->servicio->reordenar(Acceso::actual(), (int)($d['client_id'] ?? 0), $d['ids'] ?? null)];
    }

    public function clonar(Request $req): Respuesta
    {
        return new Respuesta($this->servicio->clonar(Acceso::actual(), $req->param('id')), 201);
    }

    public function borrar(Request $req): array
    {
        return ['papelera_id' => $this->servicio->borrar(Acceso::actual(), $req->param('id'))];
    }

    public function informe(Request $req): array
    {
        return ['informe' => $this->servicio->informe(Acceso::actual(), $req->param('id'), $req->texto('mes'))];
    }

    public function guardarInforme(Request $req): array
    {
        return ['informe' => $this->servicio->guardarInforme(Acceso::actual(), $req->param('id'), $req->json())];
    }

    public function publicar(Request $req): array
    {
        $this->servicio->publicar(Acceso::actual(), (int)($req->json()['client_id'] ?? 0));
        return [];
    }
}
