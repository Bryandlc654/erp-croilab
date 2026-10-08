<?php
namespace Croilab\Modulos\Clientes;

use Croilab\Http\HttpError;
use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

/* Traduce HTTP ⇄ servicio para los clientes. Sin reglas de negocio aquí. */
class ClientesController
{
    public function __construct(private readonly ClientesRepositorio $repo, private readonly ClientesServicio $servicio) {}

    public function listar(Request $req): array
    {
        [$limit, $offset] = $req->paginacion(50, 200);
        $activo = $req->texto('activo');
        [$items, $total] = $this->servicio->listar(Acceso::actual(), [
            'q' => mb_substr($req->texto('q'), 0, 80),
            'activo' => $activo === '' ? null : $activo === '1',
            'con_listas' => $req->texto('con_listas') === '1',
            'tipo' => $req->texto('tipo'),
        ], $limit, $offset, $req->texto('actividad') === '1');
        return compact('items', 'total', 'limit', 'offset');
    }

    /* Forma corta (con sus listas) que usan Tareas y la barra lateral. */
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

    public function ficha(Request $req): array
    {
        return $this->servicio->ficha(Acceso::actual(), $req->param('id'));
    }

    public function datos(Request $req): array
    {
        return ['cliente' => $this->servicio->datos(Acceso::actual(), $req->param('id'))];
    }

    public function crear(Request $req): Respuesta
    {
        $id = $this->servicio->crear(Acceso::actual(), $req->json());
        return new Respuesta(['id' => $id], 201);
    }

    public function actualizar(Request $req): array
    {
        $acc = Acceso::actual();
        $id = $req->param('id');
        $this->servicio->actualizar($acc, $id, $req->json());
        return ['cliente' => $this->servicio->datos($acc, $id)];
    }

    public function borrar(Request $req): array
    {
        return ['papelera_id' => $this->servicio->borrar(Acceso::actual(), $req->param('id'))];
    }

    public function restaurar(Request $req): array
    {
        return ['id' => $this->servicio->restaurar(Acceso::actual(), $req->param('id'))];
    }

    public function duplicar(Request $req): Respuesta
    {
        [$id, $password] = $this->servicio->duplicar(Acceso::actual(), $req->param('id'));
        return new Respuesta(['id' => $id, 'password' => $password], 201);
    }

    public function password(Request $req): array
    {
        return ['password' => $this->servicio->restablecerPassword(Acceso::actual(), $req->param('id'))];
    }

    public function secreto(Request $req): array
    {
        return ['secreto' => $this->servicio->secreto(Acceso::actual(), $req->param('id'), $req->param('cred'))];
    }
}
