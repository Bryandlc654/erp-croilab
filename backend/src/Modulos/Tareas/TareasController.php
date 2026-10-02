<?php
namespace Croilab\Modulos\Tareas;

use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

/* Traduce HTTP ⇄ servicio. Sin reglas de negocio aquí. */
class TareasController
{
    public function __construct(private readonly TareasServicio $servicio) {}

    public function listar(Request $req): array
    {
        [$limit, $offset] = $req->paginacion(100, 500);
        [$items, $total, $listId] = $this->servicio->listar(Acceso::actual(), [
            'view' => $req->texto('view', 'all'),
            'emp' => $req->entero('emp'),
            'cli' => $req->entero('cli'),
            'list' => $req->entero('list'),
            'fe' => $req->texto('fe'),
            'fr' => $req->entero('fr'),
        ], $limit, $offset);
        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'list_id' => $listId ?: null];
    }

    public function ver(Request $req): array
    {
        return ['tarea' => $this->servicio->detalle(Acceso::actual(), $req->param('id'))];
    }

    public function crear(Request $req): Respuesta
    {
        return new Respuesta(['tarea' => $this->servicio->crear(Acceso::actual(), $req->json())], 201);
    }

    public function actualizar(Request $req): array
    {
        return ['tarea' => $this->servicio->actualizar(Acceso::actual(), $req->param('id'), $req->json())];
    }

    public function borrar(Request $req): array
    {
        return ['papelera_id' => $this->servicio->borrar(Acceso::actual(), $req->param('id'))];
    }

    public function restaurar(Request $req): array
    {
        return ['id' => $this->servicio->restaurar(Acceso::actual(), $req->param('id'))];
    }
}
