<?php
namespace Croilab\Modulos\Comunicacion\Soporte;

use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

/* Traduce HTTP ⇄ SoporteServicio. */
class SoporteController
{
    public function __construct(private readonly SoporteServicio $soporte) {}

    public function listar(Request $req): array
    {
        return $this->soporte->listar(Acceso::actual(), $req->texto('estado'), $req->entero('cliente'));
    }

    public function ver(Request $req): array
    {
        return $this->soporte->detalle(Acceso::actual(), $req->param('id'));
    }

    public function crear(Request $req): Respuesta
    {
        return new Respuesta(['ticket' => $this->soporte->crear(Acceso::actual(), $req->json())], 201);
    }

    public function actualizar(Request $req): array
    {
        return ['ticket' => $this->soporte->actualizar(Acceso::actual(), $req->param('id'), $req->json())];
    }

    public function responder(Request $req): Respuesta
    {
        return new Respuesta($this->soporte->responder(Acceso::actual(), $req->param('id'), $req->json()), 201);
    }

    public function borrar(Request $req): array
    {
        return ['papelera_id' => $this->soporte->borrar(Acceso::actual(), $req->param('id'))];
    }

    public function restaurar(Request $req): array
    {
        return ['id' => $this->soporte->restaurar(Acceso::actual(), $req->param('id'))];
    }

    public function clientes(Request $req): array
    {
        return ['items' => $this->soporte->clientes(Acceso::actual())];
    }
}
