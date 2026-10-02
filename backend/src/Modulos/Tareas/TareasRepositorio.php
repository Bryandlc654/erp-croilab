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
        if ($f['view'] === 'mine') { $w[] = 't.responsable_id = ?'; $p[] = $f['yo']; }
        if ($f['view'] === 'emp') { $w[] = 't.responsable_id = ?'; $p[] = $f['emp']; }
        if ($f['view'] === 'cliente') { $w[] = 't.client_id = ? AND t.list_id = ?'; array_push($p, $f['cli'], $f['list']); }
        if ($f['fe'] !== '') { $w[] = 't.estado = ?'; $p[] = $f['fe']; }
        /* En las vistas generales, sin filtro de estado no salen las completadas. */
        elseif ($f['view'] !== 'cliente') { $w[] = "t.estado <> 'completada'"; }
        if ($f['view'] === 'all' && $f['fr']) { $w[] = 't.responsable_id = ?'; $p[] = $f['fr']; }
        $where = 'WHERE 1' . ($w ? ' AND ' . implode(' AND ', $w) : '') . $acceso->sqlTareas('t');

        $st = $this->pdo->prepare("SELECT COUNT(*) FROM tasks t $where");
        $st->execute($p);
        $total = (int)$st->fetchColumn();

        $orden = $f['view'] === 'cliente' ? 't.orden, t.id' : 'c.name, ' . self::ORDEN_ESTADOS . ', t.id';
        $st = $this->pdo->prepare($this->select() . " $where ORDER BY $orden LIMIT $limit OFFSET $offset");
        $st->execute($p);
        return [$this->conAsignados($st->fetchAll()), $total];
    }

    public function buscar(int $id): ?array
    {
        $st = $this->pdo->prepare($this->select() . ' WHERE t.id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? $this->conAsignados([$r])[0] : null;
    }

    public function detalle(int $id): ?array
    {
        $t = $this->buscar($id);
        if (!$t) return null;
        $st = $this->pdo->prepare('SELECT descripcion, etiquetas, mes, titulo_cliente, explicacion_cliente FROM tasks WHERE id = ?');
        $st->execute([$id]);
        $t += array_map(fn($v) => $v ?? '', $st->fetch() ?: []);

        $st = $this->pdo->prepare('SELECT c.id, c.admin_id, a.username, c.cuerpo, c.created_at FROM task_comments c
                                   LEFT JOIN admins a ON a.id = c.admin_id WHERE c.task_id = ? ORDER BY c.created_at, c.id');
        $st->execute([$id]);
        $t['comentarios'] = array_map(fn($c) => [
            'id' => (int)$c['id'], 'admin_id' => $c['admin_id'] === null ? null : (int)$c['admin_id'],
            'username' => $c['username'], 'cuerpo' => (string)$c['cuerpo'], 'created_at' => (string)$c['created_at'],
        ], $st->fetchAll());

        $st = $this->pdo->prepare('SELECT id, texto, done FROM task_checklist WHERE task_id = ? ORDER BY done DESC, orden, id');
        $st->execute([$id]);
        $t['checklist'] = array_map(fn($c) => ['id' => (int)$c['id'], 'texto' => (string)$c['texto'], 'done' => (int)$c['done'] === 1], $st->fetchAll());

        $st = $this->pdo->prepare('SELECT id, orig_name, filename FROM task_attachments WHERE task_id = ? ORDER BY id');
        $st->execute([$id]);
        $t['adjuntos'] = array_map(fn($a) => ['id' => (int)$a['id'], 'nombre' => (string)($a['orig_name'] ?: $a['filename']), 'filename' => (string)$a['filename']], $st->fetchAll());
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

    private function select(): string
    {
        return 'SELECT t.id, t.client_id, t.list_id, t.titulo, t.estado, t.prioridad, t.responsable_id, t.due_date, t.fecha_inicio,
                       t.visible_cliente, c.name AS client_name, c.iniciales AS client_iniciales, l.nombre AS list_name
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
                'client_name' => $r['client_name'],
                'client_iniciales' => trim((string)$r['client_iniciales']),
                'list_name' => $r['list_name'],
                'asignados' => array_values(array_map(
                    fn($aid) => $this->equipo->persona($aid),
                    array_filter($asig, fn($aid) => isset($nombres[$aid]))
                )),
            ];
        }, $filas);
    }
}
