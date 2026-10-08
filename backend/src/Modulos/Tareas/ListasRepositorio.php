<?php
namespace Croilab\Modulos\Tareas;

use PDO;

/* SQL de las listas de tareas de cada cliente (task_lists). */
class ListasRepositorio
{
    public const POR_DEFECTO = [['TAREAS', 'tareas'], ['ESTRATEGIA', 'tareas'], ['TAREA CLIENTE', 'tareas'], ['INFORMES CLIENTE', 'informe']];
    /* Título fijo de la entrada especial «Informe del mes» de las listas de informe. */
    public const INFORME_MES = 'Informe del mes';

    public function __construct(private readonly PDO $pdo) {}

    public function lista(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, client_id, nombre, es_cliente, tipo, orden FROM task_lists WHERE id = ?');
        $st->execute([$id]);
        $l = $st->fetch();
        return $l ? self::forma($l) : null;
    }

    public function deCliente(int $clientId): array
    {
        $st = $this->pdo->prepare('SELECT id, client_id, nombre, es_cliente, tipo, orden FROM task_lists WHERE client_id = ? ORDER BY orden, id');
        $st->execute([$clientId]);
        return array_map([self::class, 'forma'], $st->fetchAll());
    }

    public function tieneInforme(int $clientId): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM task_lists WHERE client_id = ? AND tipo = 'informe' LIMIT 1");
        $st->execute([$clientId]);
        return (bool)$st->fetchColumn();
    }

    public function crear(int $clientId, string $nombre, string $tipo, bool $esCliente = false): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(MAX(orden), -1) + 1 FROM task_lists WHERE client_id = ?');
        $st->execute([$clientId]);
        $this->pdo->prepare('INSERT INTO task_lists (client_id, nombre, es_cliente, tipo, orden) VALUES (?, ?, ?, ?, ?)')
            ->execute([$clientId, $nombre, $esCliente ? 1 : 0, $tipo, (int)$st->fetchColumn()]);
        return (int)$this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $campos): void
    {
        if (!$campos) return;
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($campos)));
        $this->pdo->prepare("UPDATE task_lists SET $set WHERE id = ?")->execute([...array_values($campos), $id]);
    }

    public function reordenar(int $clientId, array $ids): void
    {
        $st = $this->pdo->prepare('UPDATE task_lists SET orden = ? WHERE id = ? AND client_id = ?');
        foreach (array_values($ids) as $i => $id) $st->execute([$i, (int)$id, $clientId]);
    }

    /** Copia la lista y sus tareas (sin comentarios, checklist, adjuntos ni asignados: como el antiguo). */
    public function clonar(array $l): int
    {
        $nueva = $this->crear($l['client_id'], mb_substr($l['nombre'] . ' (copia)', 0, 120), $l['tipo'], $l['es_cliente']);
        $this->pdo->prepare('INSERT INTO tasks (client_id, list_id, titulo, descripcion, descripcion_rich, estado, responsable_id, prioridad, fecha_inicio, due_date,
                                                etiquetas, visible_cliente, titulo_cliente, explicacion_cliente, mes, orden)
                             SELECT client_id, ?, titulo, descripcion, descripcion_rich, estado, responsable_id, prioridad, fecha_inicio, due_date,
                                    etiquetas, visible_cliente, titulo_cliente, explicacion_cliente, mes, orden
                             FROM tasks WHERE list_id = ? ORDER BY orden, id')
            ->execute([$nueva, $l['id']]);
        /* El responsable sigue siendo el asignado principal de cada copia. */
        $this->pdo->prepare('INSERT IGNORE INTO task_assignees (task_id, admin_id, orden) SELECT id, responsable_id, 0 FROM tasks WHERE list_id = ? AND responsable_id IS NOT NULL')
            ->execute([$nueva]);
        return $nueva;
    }

    /** La entrada «Informe del mes» de una lista y mes, o null. */
    public function informeMes(int $listId, string $mes): ?array
    {
        $st = $this->pdo->prepare('SELECT id, explicacion_cliente, descripcion, updated_at FROM tasks WHERE list_id = ? AND mes = ? AND titulo = ? ORDER BY id LIMIT 1');
        $st->execute([$listId, $mes, self::INFORME_MES]);
        return $st->fetch() ?: null;
    }

    public function guardarInformeMes(array $l, string $mes, string $texto): int
    {
        $e = $this->informeMes($l['id'], $mes);
        if ($e) {
            $this->pdo->prepare('UPDATE tasks SET explicacion_cliente = ?, descripcion = ?, descripcion_rich = NULL WHERE id = ?')->execute([$texto, $texto, $e['id']]);
            return (int)$e['id'];
        }
        $this->pdo->prepare("INSERT INTO tasks (client_id, list_id, titulo, titulo_cliente, explicacion_cliente, descripcion, estado, prioridad, mes, visible_cliente, orden)
                             VALUES (?, ?, ?, ?, ?, ?, 'atemporal', 0, ?, 0, 0)")
            ->execute([$l['client_id'], $l['id'], self::INFORME_MES, self::INFORME_MES, $texto, $texto, $mes]);
        return (int)$this->pdo->lastInsertId();
    }

    private static function forma(array $l): array
    {
        return [
            'id' => (int)$l['id'], 'client_id' => (int)$l['client_id'], 'nombre' => (string)$l['nombre'],
            'es_cliente' => (int)$l['es_cliente'] === 1, 'tipo' => (string)($l['tipo'] ?: 'tareas'), 'orden' => (int)$l['orden'],
        ];
    }
}
