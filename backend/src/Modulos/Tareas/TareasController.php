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
            'mes' => $req->texto('mes'),
        ], $limit, $offset);
        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'list_id' => $listId ?: null];
    }

    public function meses(Request $req): array
    {
        return ['items' => $this->servicio->meses(Acceso::actual(), $req->entero('cli'), $req->entero('list'))];
    }

    public function crear(Request $req): Respuesta
    {
        return new Respuesta(['tarea' => $this->servicio->crear(Acceso::actual(), $req->json())], 201);
    }

    public function actualizar(Request $req): array
    {
        return ['tarea' => $this->servicio->actualizar(Acceso::actual(), $req->param('id'), $req->json())];
    }

    public function reordenar(Request $req): array
    {
        $d = $req->json();
        $this->servicio->reordenar(Acceso::actual(), (int)($d['list_id'] ?? 0), is_array($d['ids'] ?? null) ? $d['ids'] : []);
        return [];
    }

    public function borrar(Request $req): array
    {
        return ['papelera_id' => $this->servicio->borrar(Acceso::actual(), $req->param('id'))];
    }
}
