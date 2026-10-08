<?php
namespace Croilab\Modulos\Tareas;

use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;
use PDO;

/* SQL de las tareas. No decide permisos ni valida: eso es del servicio. */
class TareasRepositorio
{
    private const ORDEN_ESTADOS = "FIELD(t.estado, 'en proceso', 'pendiente', 'atemporal', 'completada')";

    public function __construct(
        private readonly PDO $pdo,
        private readonly EquipoRepositorio $equipo
    ) {}

    /**
     * @param array{view:string, emp:int, cli:int, list:int, fe:string, fr:int, yo:int} $f
     * @return array{0: array<int,array>, 1: int}
     */
    public function listar(Acceso $acceso, array $f, int $limit, int $offset): array
    {
        $w = [];
        $p = [];
        /* «Mis tareas» y «Tareas de…» cuentan también las asignadas por
           task_assignees (no solo el responsable principal). */
        if ($f['view'] === 'mine' || $f['view'] === 'emp') {
            $w[] = '(t.responsable_id = ? OR EXISTS (SELECT 1 FROM task_assignees xa WHERE xa.task_id = t.id AND xa.admin_id = ?))';
            $quien = $f['view'] === 'mine' ? $f['yo'] : $f['emp'];
            array_push($p, $quien, $quien);
        }
        if ($f['view'] === 'cliente') { $w[] = 't.client_id = ? AND t.list_id = ?'; array_push($p, $f['cli'], $f['list']); }
        if ($f['fe'] !== '') { $w[] = 't.estado = ?'; $p[] = $f['fe']; }
        /* En las vistas generales, sin filtro de estado no salen las completadas. */
        elseif ($f['view'] !== 'cliente') { $w[] = "t.estado <> 'completada'"; }
        if ($f['view'] === 'all' && $f['fr']) {
            $w[] = '(t.responsable_id = ? OR EXISTS (SELECT 1 FROM task_assignees xr WHERE xr.task_id = t.id AND xr.admin_id = ?))';
            array_push($p, $f['fr'], $f['fr']);
        }
        if (($f['mes'] ?? '') !== '') { $w[] = 't.mes = ?'; $p[] = $f['mes']; }
        $where = 'WHERE 1' . ($w ? ' AND ' . implode(' AND ', $w) : '') . $acceso->sqlTareas('t');

        $st = $this->pdo->prepare("SELECT COUNT(*) FROM tasks t $where");
        $st->execute($p);
        $total = (int)$st->fetchColumn();

        $orden = $f['view'] === 'cliente' ? 't.orden, t.id' : 'c.name, ' . self::ORDEN_ESTADOS . ', t.orden, t.id';
        $st = $this->pdo->prepare($this->select() . " $where ORDER BY $orden LIMIT $limit OFFSET $offset");
        $st->execute($p);
        return [$this->conAsignados($st->fetchAll()), $total];
    }

    /** Meses (texto libre de `mes`) con tareas en una lista, en orden de aparición. */
    public function mesesDeLista(int $listId): array
    {
        $st = $this->pdo->prepare("SELECT mes, COUNT(*) n, MIN(id) primero FROM tasks WHERE list_id = ? AND mes IS NOT NULL AND TRIM(mes) <> '' GROUP BY mes ORDER BY primero");
        $st->execute([$listId]);
        return array_map(fn($r) => ['mes' => (string)$r['mes'], 'n' => (int)$r['n']], $st->fetchAll());
    }

    public function buscar(int $id): ?array
    {
        $st = $this->pdo->prepare($this->select() . ' WHERE t.id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? $this->conAsignados([$r])[0] : null;
    }

    /** Ficha completa (sin comentarios: van en su propio feed). */
    public function detalle(int $id): ?array
    {
        $t = $this->buscar($id);
        if (!$t) return null;
        $st = $this->pdo->prepare('SELECT descripcion, descripcion_rich, titulo_cliente, explicacion_cliente, created_at, updated_at FROM tasks WHERE id = ?');
        $st->execute([$id]);
        $x = $st->fetch() ?: [];
        /* La versión rica manda; las tareas que solo tienen texto plano se
           editan a partir de él (el plano es un subconjunto del formato). */
        $rich = (string)($x['descripcion_rich'] ?? '');
        $t['descripcion'] = trim($rich) !== '' ? $rich : (string)($x['descripcion'] ?? '');
        $t['titulo_cliente'] = (string)($x['titulo_cliente'] ?? '');
        $t['explicacion_cliente'] = (string)($x['explicacion_cliente'] ?? '');
        $t['created_at'] = (string)($x['created_at'] ?? '');
        $t['updated_at'] = (string)($x['updated_at'] ?? '');
        return $t;
    }

    public function crear(array $campos): int
    {
        $cols = array_keys($campos);
        $this->pdo->prepare('INSERT INTO tasks (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($campos));
        return (int)$this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $campos): void
    {
        if (!$campos) return;
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($campos)));
        $this->pdo->prepare("UPDATE tasks SET $set WHERE id = ?")->execute([...array_values($campos), $id]);
    }

    /** Siguiente `orden` al final de una lista (las tareas nuevas van abajo). */
    public function siguienteOrden(int $listId): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(MAX(orden), 0) + 1 FROM tasks WHERE list_id = ?');
        $st->execute([$listId]);
        return (int)$st->fetchColumn();
    }

    /** Tareas de esa lista entre los ids dados (para reordenar sin tocar otras). */
    public function idsDeLista(int $listId, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        $st = $this->pdo->prepare('SELECT id FROM tasks WHERE list_id = ? AND id IN (' . implode(',', $ids) . ')');
        $st->execute([$listId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public function reordenar(array $ids): void
    {
        $st = $this->pdo->prepare('UPDATE tasks SET orden = ? WHERE id = ?');
        foreach (array_values($ids) as $i => $id) $st->execute([$i + 1, (int)$id]);
    }

    /** Ids de los comentarios (para limpiar sus reacciones al borrar). */
    public function comentarios(int $id): array
    {
        $st = $this->pdo->prepare('SELECT id FROM task_comments WHERE task_id = ?');
        $st->execute([$id]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public function borrarReacciones(array $commentIds): void
    {
        if (!$commentIds) return;
        $this->pdo->exec('DELETE FROM task_comment_reactions WHERE comment_id IN (' . implode(',', array_map('intval', $commentIds)) . ')');
    }

    /* ---------- Actividad (historial de la ficha) ---------- */

    public function anotar(int $taskId, ?int $adminId, string $tipo, string $detalle = ''): void
    {
        $this->pdo->prepare('INSERT INTO task_activity (task_id, admin_id, tipo, detalle) VALUES (?, ?, ?, ?)')
            ->execute([$taskId, $adminId ?: null, mb_substr($tipo, 0, 30), mb_substr($detalle, 0, 300)]);
    }

    public function actividad(int $taskId): array
    {
        $st = $this->pdo->prepare('SELECT id, admin_id, tipo, detalle, created_at FROM task_activity WHERE task_id = ? ORDER BY id');
        $st->execute([$taskId]);
        $nombres = $this->equipo->nombres();
        return array_map(fn($a) => [
            'id' => (int)$a['id'],
            'tipo' => (string)$a['tipo'],
            'detalle' => (string)$a['detalle'],
            'actor' => $a['admin_id'] !== null ? ($nombres[(int)$a['admin_id']] ?? null) : null,
            'created_at' => (string)$a['created_at'],
        ], $st->fetchAll());
    }

    private function select(): string
    {
        return 'SELECT t.id, t.client_id, t.list_id, t.titulo, t.estado, t.prioridad, t.responsable_id, t.due_date, t.fecha_inicio,
                       t.visible_cliente, t.mes, t.etiquetas, t.orden, c.name AS client_name, c.iniciales AS client_iniciales,
                       l.nombre AS list_name, l.tipo AS list_tipo
                FROM tasks t LEFT JOIN clients c ON c.id = t.client_id LEFT JOIN task_lists l ON l.id = t.list_id';
    }

    /* Asignados de cada tarea en una sola consulta: task_assignees y, si la
       tarea no tiene filas ahí (tareas antiguas), su responsable. */
    private function conAsignados(array $filas): array
    {
        if (!$filas) return [];
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $filas));
        $por = [];
        foreach ($this->pdo->query("SELECT task_id, admin_id FROM task_assignees WHERE task_id IN ($ids) ORDER BY orden, admin_id") as $a) {
            $por[(int)$a['task_id']][] = (int)$a['admin_id'];
        }
        $nombres = $this->equipo->nombres();
        return array_map(function ($r) use ($por, $nombres) {
            $asig = $por[(int)$r['id']] ?? ($r['responsable_id'] ? [(int)$r['responsable_id']] : []);
            return [
                'id' => (int)$r['id'],
                'client_id' => (int)$r['client_id'],
                'list_id' => (int)$r['list_id'],
                'titulo' => (string)$r['titulo'],
                'estado' => (string)$r['estado'],
                'prioridad' => (int)$r['prioridad'],
                'responsable_id' => $r['responsable_id'] === null ? null : (int)$r['responsable_id'],
                'due_date' => $r['due_date'],
                'fecha_inicio' => $r['fecha_inicio'],
                'visible_cliente' => (int)$r['visible_cliente'] === 1,
                'mes' => (string)($r['mes'] ?? ''),
                'etiquetas' => (string)($r['etiquetas'] ?? ''),
                'orden' => (int)$r['orden'],
                'client_name' => $r['client_name'],
                'client_iniciales' => trim((string)$r['client_iniciales']),
                'list_name' => $r['list_name'],
                'list_tipo' => (string)($r['list_tipo'] ?: 'tareas'),
                'asignados' => array_values(array_map(
                    fn($aid) => $this->equipo->persona($aid),
                    array_filter($asig, fn($aid) => isset($nombres[$aid]))
                )),
            ];
        }, $filas);
    }
}
