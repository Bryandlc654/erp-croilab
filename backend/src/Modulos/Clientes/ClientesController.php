<?php
namespace Croilab\Modulos\Clientes;

use Croilab\Http\HttpError;
use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

class ClientesController
{
    public function __construct(private readonly ClientesRepositorio $repo) {}

    public function listar(Request $req): array
    {
        [$limit, $offset] = $req->paginacion(50, 200);
        $activo = $req->texto('activo');
        [$items, $total] = $this->repo->listar(Acceso::actual(), [
            'q' => mb_substr($req->texto('q'), 0, 80),
            'activo' => $activo === '' ? null : $activo === '1',
            'con_listas' => $req->texto('con_listas') === '1',
        ], $limit, $offset);
        return compact('items', 'total', 'limit', 'offset');
    }

    public function ver(Request $req): array
    {
        $c = $this->repo->buscar(Acceso::actual(), $req->param('id'));
        if (!$c) throw HttpError::noEncontrado('Cliente no encontrado.');
        return ['cliente' => $c];
    }

    /* Antes lo hacía tasks/index.php con un GET (?informe=1), sin CSRF. */
    public function informe(Request $req): Respuesta
    {
        $acc = Acceso::actual();
        $acc->exigir('general.editar', 'tareas.crear');
        $id = $req->param('id');
        if (!$this->repo->buscar($acc, $id)) throw HttpError::noEncontrado('Cliente no encontrado.');
        [$listId, $creada] = $this->repo->listaInformes($id);
        return new Respuesta(['list_id' => $listId, 'created' => $creada], $creada ? 201 : 200);
    }
}
