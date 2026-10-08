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
    public const ETIQUETA_ESTADO = ['pendiente' => 'En espera', 'en proceso' => 'En proceso', 'atemporal' => 'Atemporal', 'completada' => 'Completada'];
    public const ETIQUETA_PRIORIDAD = ['Sin prioridad', 'Baja', 'Normal', 'Alta', 'Urgente'];

    /* Campos de texto que se pueden escribir y su largo máximo. `descripcion`
       llega en el formato rico del ERP (hasta 200 000, como el antiguo). */
    private const TEXTOS = ['etiquetas' => 255, 'mes' => 40, 'titulo_cliente' => 255, 'explicacion_cliente' => 65535];
    public const MAX_DESCRIPCION = 200000;

    /* Lo que va a la papelera con la tarea, en orden de borrado: cada nieta
       antes que su madre (su condición pasa por ella). Las horas no: son de
       Finanzas y vuelven solas al restaurar (siguen apuntando a la tarea). */
    public const HIJOS_PAPELERA = [
        ['tabla' => 'task_comment_reactions', 'fk' => 'comment_id', 'en' => [['task_comments', 'task_id']]],
        ['tabla' => 'chk_assignees', 'fk' => 'chk_id', 'en' => [['task_checklist', 'task_id']]],
        ['tabla' => 'task_comments', 'fk' => 'task_id'],
        ['tabla' => 'task_checklist', 'fk' => 'task_id'],
        ['tabla' => 'task_attachments', 'fk' => 'task_id'],
        ['tabla' => 'task_assignees', 'fk' => 'task_id'],
        ['tabla' => 'task_activity', 'fk' => 'task_id'],
    ];

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
                    'fe' => $fe, 'fr' => (int)($f['fr'] ?? 0), 'yo' => $acc->adminId, 'mes' => mb_substr(trim((string)($f['mes'] ?? '')), 0, 40)];

        if ($view === 'emp' && !$filtros['emp']) throw HttpError::validacion('Falta la persona.', 'emp');
        if ($view === 'cliente') {
            $cliente = $filtros['cli'] ? $this->clientes->buscar($acc, $filtros['cli']) : null;
            if (!$cliente) throw HttpError::noEncontrado('Cliente no encontrado.');
            if (!$filtros['list']) $filtros['list'] = (int)($cliente['listas'][0]['id'] ?? 0);
            if ($filtros['list'] && !$this->clientes->listaDeCliente($filtros['list'], $filtros['cli'])) throw HttpError::noEncontrado('Lista no encontrada.');
        } else {
            $filtros['mes'] = '';
        }
        [$items, $total] = $this->tareas->listar($acc, $filtros, $limit, $offset);
        return [$items, $total, $filtros['list']];
    }

    /** Meses de una lista de informes (chips), de las tareas que se ven. */
    public function meses(Acceso $acc, int $cli, int $list): array
    {
        $acc->exigir('ver.tareas');
        if (!$this->clientes->buscar($acc, $cli) || !$this->clientes->listaDeCliente($list, $cli)) throw HttpError::noEncontrado('Lista no encontrada.');
        return $this->tareas->mesesDeLista($list);
    }

    public function detalle(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.tareas');
        $this->visible($acc, $id);
        $t = $this->tareas->detalle($id) ?? throw HttpError::noEncontrado('Tarea no encontrada.');
        $t['actividad'] = $this->tareas->actividad($id);
        return $t;
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
        $asignados = $this->asignadosDe($datos, $campos);
        unset($campos['asignados']);
        $campos['orden'] = $this->tareas->siguienteOrden($list);
        $id = $this->tareas->crear(['client_id' => $cli, 'list_id' => $list] + $campos);
        /* Sin asignados explícitos, en una vista personal la tarea es de quien la crea. */
        task_set_asignados($id, $asignados);
        $this->tareas->anotar($id, $acc->adminId, 'creada');
        $yo = $this->nombre($acc);
        foreach ($asignados as $a) $this->avisarAsignacion($id, $a, $yo);
        if (!empty($campos['descripcion_rich'])) notif_desc_scan($id, $campos['descripcion_rich'], $yo);
        tareas_publicar_progreso($cli);
        return $this->tareas->buscar($id);
    }

    public function actualizar(Acceso $acc, int $id, array $datos): array
    {
        $acc->exigir('general.editar', 'tareas.editar');
        $this->visible($acc, $id);
        $antes = $this->tareas->buscar($id) ?? throw HttpError::noEncontrado('Tarea no encontrada.');
        $campos = $this->validar($datos);
        $nuevosAsig = null;
        if (array_key_exists('asignados', $campos) || array_key_exists('responsable_id', $campos)) {
            $nuevosAsig = $this->asignadosDe($datos, $campos);
        }
        unset($campos['asignados'], $campos['responsable_id']);
        if (!$campos && $nuevosAsig === null) throw HttpError::validacion('No hay nada que cambiar.');

        $this->tareas->actualizar($id, $campos);
        $yo = $this->nombre($acc);

        if ($nuevosAsig !== null) {
            $previos = array_column($antes['asignados'], 'id');
            task_set_asignados($id, $nuevosAsig);
            if ($nuevosAsig !== $previos) {
                $this->tareas->anotar($id, $acc->adminId, 'asignados', $this->nombresDe($nuevosAsig));
                foreach (array_diff($nuevosAsig, $previos) as $a) $this->avisarAsignacion($id, (int)$a, $yo);
            }
        }
        if (isset($campos['estado']) && $campos['estado'] !== $antes['estado']) {
            $this->tareas->anotar($id, $acc->adminId, 'estado', self::ETIQUETA_ESTADO[$campos['estado']]);
            if ($campos['estado'] === 'en proceso') notif_task_activity($id, 'start', '', $yo);
        }
        if (isset($campos['prioridad']) && $campos['prioridad'] !== $antes['prioridad']) {
            $this->tareas->anotar($id, $acc->adminId, 'prioridad', self::ETIQUETA_PRIORIDAD[$campos['prioridad']]);
        }
        if (isset($campos['titulo']) && $campos['titulo'] !== $antes['titulo']) {
            $this->tareas->anotar($id, $acc->adminId, 'titulo', $campos['titulo']);
        }
        /* Fecha nueva (no vacía y distinta): aviso a los dueños, como el antiguo
           en task.php (la API anterior se lo saltaba). */
        foreach (['due_date' => 'fecha límite', 'fecha_inicio' => 'inicio'] as $f => $txt) {
            if (!array_key_exists($f, $campos) || $campos[$f] === $antes[$f]) continue;
            $this->tareas->anotar($id, $acc->adminId, $f, $campos[$f] ?? '');
            if ($campos[$f]) notif_task_activity($id, 'date', $txt . ' ' . date('d/m/Y', strtotime($campos[$f])), $yo);
        }
        if (array_key_exists('descripcion_rich', $campos)) notif_desc_scan($id, $campos['descripcion_rich'], $yo);
        tareas_publicar_progreso($antes['client_id']);
        return $this->tareas->buscar($id);
    }

    /** Reordena las tareas de una lista (arrastrar en el tablero). */
    public function reordenar(Acceso $acc, int $listId, array $ids): void
    {
        $acc->exigir('general.editar', 'tareas.editar');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) throw HttpError::validacion('Falta el orden.', 'ids');
        $deLista = $this->tareas->idsDeLista($listId, $ids);
        /* Solo las de esa lista que esta persona ve: el resto se ignora. */
        $validos = array_values(array_filter($ids, fn($i) => in_array($i, $deLista, true) && $acc->veTarea($i)));
        if (!$validos) throw HttpError::noEncontrado('Lista no encontrada.');
        $this->tareas->reordenar($validos);
    }

    /** Va a la papelera con todo lo suyo. Devuelve el id de papelera. */
    public function borrar(Acceso $acc, int $id): int
    {
        $acc->exigir('general.editar', 'tareas.borrar');
        $this->visible($acc, $id);
        $t = $this->tareas->buscar($id) ?? throw HttpError::noEncontrado('Tarea no encontrada.');
        $tid = pap_borrar('tasks', $id, 'tarea', trim($t['titulo']) !== '' ? $t['titulo'] : "Tarea #$id", self::HIJOS_PAPELERA);
        if (!$tid) throw new HttpError(500, 'No se ha podido mover la tarea a la papelera.', 'papelera');
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
     * `asignados` vuelve como lista de ids (no es una columna).
     */
    public function validar(array $datos): array
    {
        $c = [];
        if (array_key_exists('titulo', $datos)) {
            if (!is_scalar($datos['titulo'])) throw HttpError::validacion('Valor no válido.', 'titulo');
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
            $d = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
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
        if (array_key_exists('asignados', $datos)) {
            $c['asignados'] = $this->idsPersonas($datos['asignados'], 'asignados');
        }
        if (array_key_exists('visible_cliente', $datos)) {
            $c['visible_cliente'] = filter_var($datos['visible_cliente'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (array_key_exists('descripcion', $datos)) {
            if ($datos['descripcion'] !== null && !is_string($datos['descripcion'])) throw HttpError::validacion('Valor no válido.', 'descripcion');
            $v = rtrim(str_replace("\r\n", "\n", (string)$datos['descripcion']));
            if (mb_strlen($v) > self::MAX_DESCRIPCION) throw HttpError::validacion('La descripción es demasiado larga.', 'descripcion');
            /* Siempre las dos: la rica para editar y la plana para el portal.
               Escribir solo una dejaba la otra desincronizada (§6c-2). */
            $c['descripcion_rich'] = $v;
            $c['descripcion'] = TextoRico::aPlano($v);
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

    /** Lista de ids de personas existentes, sin repetir y en el orden dado. */
    public function idsPersonas(mixed $v, string $campo): array
    {
        if ($v === null) return [];
        if (!is_array($v) || !array_is_list($v)) throw HttpError::validacion('Lista de personas no válida.', $campo);
        $ids = [];
        foreach ($v as $x) {
            $i = filter_var($x, FILTER_VALIDATE_INT);
            if (!$i || !$this->equipo->existe($i)) throw HttpError::validacion('Esa persona no existe.', $campo);
            if (!in_array($i, $ids, true)) $ids[] = $i;
        }
        if (count($ids) > 50) throw HttpError::validacion('Demasiadas personas.', $campo);
        return $ids;
    }

    /* `asignados` manda; si solo viene `responsable_id`, ese es el único. */
    private function asignadosDe(array $datos, array $campos): array
    {
        if (array_key_exists('asignados', $campos)) return $campos['asignados'];
        if (array_key_exists('responsable_id', $campos)) return $campos['responsable_id'] ? [$campos['responsable_id']] : [];
        return [];
    }

    private function nombresDe(array $ids): string
    {
        $n = $this->equipo->nombres();
        return implode(', ', array_map(fn($i) => $n[$i] ?? '?', $ids));
    }

    private function nombre(Acceso $acc): string
    {
        return (string)$this->equipo->persona($acc->adminId)['username'];
    }

    /* Fuera de su alcance, una tarea responde igual que si no existiera. */
    public function visible(Acceso $acc, int $id): void
    {
        if (!$acc->veTarea($id)) throw HttpError::noEncontrado('Tarea no encontrada.');
    }

    private function avisarAsignacion(int $id, int $a, string $yo): void
    {
        notif_task_assigned($id, $a, $yo);
    }
}
