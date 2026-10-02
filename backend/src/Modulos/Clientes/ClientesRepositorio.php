<?php
namespace Croilab\Modulos\Clientes;

use Croilab\Seguridad\Acceso;
use PDO;

/* Lectura de clientes y sus listas, siempre filtrada por el alcance. */
class ClientesRepositorio
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array{q?:string, activo?:?bool, con_listas?:bool} $f
     * @return array{0: array<int,array>, 1: int} [items, total]
     */
    public function listar(Acceso $acceso, array $f, int $limit, int $offset): array
    {
        $where = 'WHERE 1' . $acceso->sqlClientes('c.id');
        $p = [];
        if (($f['q'] ?? '') !== '') {
            /* Prefijo, como el buscador antiguo: usa el índice por nombre. */
            $where .= ' AND c.name LIKE ?';
            $p[] = addcslashes((string)$f['q'], '%_\\') . '%';
        }
        if (isset($f['activo']) && $f['activo'] !== null) {
            $where .= ' AND c.activo = ?';
            $p[] = $f['activo'] ? 1 : 0;
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM clients c $where");
        $st->execute($p);
        $total = (int)$st->fetchColumn();

        $st = $this->pdo->prepare("SELECT c.id, c.name, c.iniciales, c.activo FROM clients c $where ORDER BY c.orden, c.name, c.id LIMIT $limit OFFSET $offset");
        $st->execute($p);
        $items = array_map([$this, 'normalizar'], $st->fetchAll());

        if (!empty($f['con_listas']) && $items) {
            $listas = $this->listasDe($acceso, array_column($items, 'id'));
            foreach ($items as &$c) $c['listas'] = $listas[$c['id']] ?? [];
        }
        return [$items, $total];
    }

    public function buscar(Acceso $acceso, int $id): ?array
    {
        if (!$acceso->veCliente($id)) return null;
        $st = $this->pdo->prepare('SELECT id, name, iniciales, activo FROM clients WHERE id = ?');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) return null;
        $c = $this->normalizar($c);
        $c['listas'] = $this->listasDe($acceso, [$id])[$id] ?? [];
        return $c;
    }

    /** @return array<int, array> client_id => listas con sus pendientes visibles */
    public function listasDe(Acceso $acceso, array $clientIds): array
    {
        $ids = implode(',', array_map('intval', $clientIds)) ?: '0';
        $out = [];
        $sql = "SELECT l.id, l.client_id, l.nombre, l.tipo,
                       (SELECT COUNT(*) FROM tasks t WHERE t.list_id = l.id AND t.estado <> 'completada'" . $acceso->sqlTareas('t') . ") AS pend
                FROM task_lists l WHERE l.client_id IN ($ids) ORDER BY l.orden, l.id";
        foreach ($this->pdo->query($sql) as $l) {
            $out[(int)$l['client_id']][] = [
                'id' => (int)$l['id'], 'nombre' => (string)$l['nombre'], 'tipo' => (string)($l['tipo'] ?: 'tareas'), 'pend' => (int)$l['pend'],
            ];
        }
        return $out;
    }

    public function listaDeCliente(int $listId, int $clientId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM task_lists WHERE id = ? AND client_id = ?');
        $st->execute([$listId, $clientId]);
        return (bool)$st->fetchColumn();
    }

    /** La lista de informes del cliente; se crea si no existe. [id, creada] */
    public function listaInformes(int $clientId): array
    {
        $st = $this->pdo->prepare("SELECT id FROM task_lists WHERE client_id = ? AND tipo = 'informe' ORDER BY orden, id LIMIT 1");
        $st->execute([$clientId]);
        if ($id = (int)$st->fetchColumn()) return [$id, false];
        $st = $this->pdo->prepare('SELECT COALESCE(MAX(orden), 0) + 1 FROM task_lists WHERE client_id = ?');
        $st->execute([$clientId]);
        $this->pdo->prepare("INSERT INTO task_lists (client_id, nombre, es_cliente, tipo, orden) VALUES (?, 'INFORMES CLIENTE', 0, 'informe', ?)")
            ->execute([$clientId, (int)$st->fetchColumn()]);
        return [(int)$this->pdo->lastInsertId(), true];
    }

    /** @return array{total:int, activos:int, inactivos:int} */
    public function contadores(Acceso $acceso): array
    {
        $r = $this->pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(c.activo = 1), 0) AS activos FROM clients c WHERE 1' . $acceso->sqlClientes('c.id'))->fetch();
        return ['total' => (int)$r['total'], 'activos' => (int)$r['activos'], 'inactivos' => (int)$r['total'] - (int)$r['activos']];
    }

    private function normalizar(array $c): array
    {
        return ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'iniciales' => trim((string)$c['iniciales']), 'activo' => (int)$c['activo'] === 1];
    }
}
