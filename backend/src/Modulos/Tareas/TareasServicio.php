<?php
namespace Croilab\Modulos\Tareas;

use Croilab\Http\HttpError;
use Croilab\Modulos\Clientes\ClientesRepositorio;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;

/* Reglas de las tareas: permisos, alcance y validación de cada campo. Es lo
   que comparten la API y cualquier otro punto de entrada (cron, scripts). */
class TareasServicio
{
    public const ESTADOS = ['pendiente', 'en proceso', 'atemporal', 'completada'];
    public const VISTAS = ['all', 'mine', 'emp', 'cliente'];

    /* Campos que se pueden escribir y cómo se validan. */
    private const TEXTOS = ['descripcion' => 65535, 'etiquetas' => 255, 'mes' => 40, 'titulo_cliente' => 255, 'explicacion_cliente' => 65535];

    public function __construct(
        private readonly TareasRepositorio $tareas,
        private readonly ClientesRepositorio $clientes,
        private readonly EquipoRepositorio $equipo
    ) {}

    /** @return array{0: array, 1: int, 2: int} [items, total, list_id] */
    public function listar(Acceso $acc, array $f, int $limit, int $offset): array
    {
        $acc->exigir('ver.tareas');
        $view = in_array($f['view'] ?? '', self::VISTAS, true) ? $f['view'] : 'all';
        $fe = (string)($f['fe'] ?? '');
        if ($fe !== '' && !in_array($fe, self::ESTADOS, true)) throw HttpError::validacion('Estado desconocido.', 'fe');
        $filtros = ['view' => $view, 'emp' => (int)($f['emp'] ?? 0), 'cli' => (int)($f['cli'] ?? 0), 'list' => (int)($f['list'] ?? 0),
                    'fe' => $fe, 'fr' => (int)($f['fr'] ?? 0), 'yo' => $acc->adminId];

        if ($view === 'emp' && !$filtros['emp']) throw HttpError::validacion('Falta la persona.', 'emp');
        if ($view === 'cliente') {
            $cliente = $filtros['cli'] ? $this->clientes->buscar($acc, $filtros['cli']) : null;
            if (!$cliente) throw HttpError::noEncontrado('Cliente no encontrado.');
            if (!$filtros['list']) $filtros['list'] = (int)($cliente['listas'][0]['id'] ?? 0);
            if ($filtros['list'] && !$this->clientes->listaDeCliente($filtros['list'], $filtros['cli'])) throw HttpError::noEncontrado('Lista no encontrada.');
        }
        [$items, $total] = $this->tareas->listar($acc, $filtros, $limit, $offset);
        return [$items, $total, $filtros['list']];
    }

    public function detalle(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.tareas');
        $this->visible($acc, $id);
        return $this->tareas->detalle($id) ?? throw HttpError::noEncontrado('Tarea no encontrada.');
    }

    public function crear(Acceso $acc, array $datos): array
    {
        $acc->exigir('general.editar', 'tareas.crear');
        $cli = (int)($datos['client_id'] ?? 0);
        $list = (int)($datos['list_id'] ?? 0);
        if (!$cli || !$this->clientes->buscar($acc, $cli)) throw HttpError::validacion('Cliente no encontrado.', 'client_id');
        /* La lista tiene que ser de ese cliente: si no, se colaba una tarea en la
           lista (y en el portal) de otro cliente. */
        if (!$list || !$this->clientes->listaDeCliente($list, $cli)) throw HttpError::validacion('La lista no es de ese cliente.', 'list_id');
        if (!array_key_exists('titulo', $datos)) throw HttpError::validacion('El título es obligatorio.', 'titulo');

        $campos = $this->validar($datos) + ['estado' => 'pendiente', 'prioridad' => 0, 'visible_cliente' => 0];
        $resp = array_key_exists('responsable_id', $campos) ? $campos['responsable_id'] : null;
        $id = $this->tareas->crear(['client_id' => $cli, 'list_id' => $list] + $campos);
        task_set_asignados($id, $resp ? [$resp] : []);
        if ($resp) $this->avisarAsignacion($id, $resp, $acc);
        tareas_publicar_progreso($cli);
        return $this->tareas->buscar($id);
    }

    public function actualizar(Acceso $acc, int $id, array $datos): array
    {
        $acc->exigir('general.editar', 'tareas.editar');
        $this->visible($acc, $id);
        $antes = $this->tareas->buscar($id) ?? throw HttpError::noEncontrado('Tarea no encontrada.');
        $campos = $this->validar($datos);
        if (!$campos) throw HttpError::validacion('No hay nada que cambiar.');

        $this->tareas->actualizar($id, $campos);
        if (array_key_exists('responsable_id', $campos)) {
            /* Elegir responsable lo deja como único asignado (o a nadie al quitarlo). */
            task_set_asignados($id, $campos['responsable_id'] ? [$campos['responsable_id']] : []);
            if ($campos['responsable_id'] && $campos['responsable_id'] !== $antes['responsable_id']) {
                $this->avisarAsignacion($id, $campos['responsable_id'], $acc);
            }
        }
        if (function_exists('notif_task_activity') && ($campos['estado'] ?? null) === 'en proceso' && $antes['estado'] !== 'en proceso') {
            @notif_task_activity($id, 'start', '', $this->equipo->persona($acc->adminId)['username']);
        }
        tareas_publicar_progreso($antes['client_id']);
        return $this->tareas->buscar($id);
    }

    /** Va a la papelera con comentarios, checklist y adjuntos. Devuelve el id de papelera. */
    public function borrar(Acceso $acc, int $id): int
    {
        $acc->exigir('general.editar', 'tareas.borrar');
        $this->visible($acc, $id);
        $t = $this->tareas->buscar($id) ?? throw HttpError::noEncontrado('Tarea no encontrada.');
        $comentarios = $this->tareas->comentarios($id);
        $tid = pap_borrar('tasks', $id, 'tarea', trim($t['titulo']) !== '' ? $t['titulo'] : "Tarea #$id", [
            ['tabla' => 'task_comments', 'fk' => 'task_id'],
            ['tabla' => 'task_checklist', 'fk' => 'task_id'],
            ['tabla' => 'task_attachments', 'fk' => 'task_id'],
        ]);
        if (!$tid) throw new HttpError(500, 'No se ha podido mover la tarea a la papelera.', 'papelera');
        /* Las reacciones cuelgan del comentario, no de la tarea: la papelera no
           las guarda. Se borran solo cuando la tarea ya está a salvo. */
        $this->tareas->borrarReacciones($comentarios);
        tareas_publicar_progreso($t['client_id']);
        return $tid;
    }

    /* «Deshacer» de un borrado: quien borró puede deshacerlo; lo borrado por
       otra persona exige el permiso de la papelera. Devuelve el id de la tarea. */
    public function restaurar(Acceso $acc, int $papeleraId): int
    {
        $acc->exigir('general.editar');
        $t = pap_elemento($papeleraId);
        if (!$t || $t['tipo'] !== 'tarea') throw HttpError::noEncontrado('Eso ya no está en la papelera.');
        if ((int)$t['admin_id'] !== $acc->adminId) $acc->exigir('papelera.restaurar');
        $r = pap_restaurar($papeleraId);
        if (empty($r['ok'])) throw new HttpError(409, $r['msg'] ?? 'No se ha podido restaurar.', 'conflicto');
        return (int)($r['id'] ?? 0);
    }

    /**
     * Valida los campos editables presentes en $datos y los devuelve listos
     * para la base de datos. Lo que no viene no se toca (PATCH).
     */
    public function validar(array $datos): array
    {
        $c = [];
        if (array_key_exists('titulo', $datos)) {
            $t = trim((string)$datos['titulo']);
            if ($t === '') throw HttpError::validacion('El título es obligatorio.', 'titulo');
            if (mb_strlen($t) > 255) throw HttpError::validacion('El título es demasiado largo.', 'titulo');
            $c['titulo'] = $t;
        }
        if (array_key_exists('estado', $datos)) {
            if (!in_array($datos['estado'], self::ESTADOS, true)) throw HttpError::validacion('Estado desconocido.', 'estado');
            $c['estado'] = $datos['estado'];
        }
        if (array_key_exists('prioridad', $datos)) {
            $p = filter_var($datos['prioridad'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4]]);
            if ($p === false) throw HttpError::validacion('La prioridad va de 0 a 4.', 'prioridad');
            $c['prioridad'] = $p;
        }
        foreach (['due_date', 'fecha_inicio'] as $f) {
            if (!array_key_exists($f, $datos)) continue;
            $v = $datos[$f];
            if ($v === null || $v === '') { $c[$f] = null; continue; }
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$v);
            if (!$d || $d->format('Y-m-d') !== $v) throw HttpError::validacion('Fecha no válida (AAAA-MM-DD).', $f);
            $c[$f] = $v;
        }
        if (array_key_exists('responsable_id', $datos)) {
            $r = $datos['responsable_id'];
            if ($r === null || $r === '' || $r === 0) $c['responsable_id'] = null;
            else {
                $r = filter_var($r, FILTER_VALIDATE_INT);
                if (!$r || !$this->equipo->existe($r)) throw HttpError::validacion('Esa persona no existe.', 'responsable_id');
                $c['responsable_id'] = $r;
            }
        }
        if (array_key_exists('visible_cliente', $datos)) {
            $c['visible_cliente'] = filter_var($datos['visible_cliente'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        foreach (self::TEXTOS as $f => $max) {
            if (!array_key_exists($f, $datos)) continue;
            if ($datos[$f] !== null && !is_scalar($datos[$f])) throw HttpError::validacion('Valor no válido.', $f);
            $v = trim((string)$datos[$f]);
            if (mb_strlen($v) > $max) throw HttpError::validacion('Texto demasiado largo.', $f);
            $c[$f] = $v;
        }
        return $c;
    }

    /* Fuera de su alcance, una tarea responde igual que si no existiera. */
    private function visible(Acceso $acc, int $id): void
    {
        if (!$acc->veTarea($id)) throw HttpError::noEncontrado('Tarea no encontrada.');
    }

    private function avisarAsignacion(int $id, int $a, Acceso $acc): void
    {
        if (function_exists('notif_task_assigned')) @notif_task_assigned($id, $a, $this->equipo->persona($acc->adminId)['username']);
    }
}
