<?php
namespace Croilab\Modulos\Clientes;

use Croilab\Seguridad\Acceso;
use PDO;

/* Lectura y escritura de la tabla `clients` y sus listas, siempre filtrada por
   el alcance en lo que se lee. listar(), buscar(), listasDe(), listaDeCliente(),
   listaInformes() y contadores() los usan también Tareas y Nav: su forma no
   cambia (solo se añaden campos). */
class ClientesRepositorio
{
    /* Columnas de `clients` que se escriben desde la ficha (alta/edición). */
    public const CAMPOS_FICHA = [
        'name', 'username', 'iniciales', 'saludo', 'conversiones', 'actual', 'tipo_id', 'activo',
        'fact_nombre', 'fact_nif', 'fact_dir', 'fact_email', 'login_email',
        'estado_json', 'plan_json', 'accesos_json', 'tareas_json',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array{q?:string, activo?:?bool, con_listas?:bool, tipo?:string} $f
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
        $tipo = (string)($f['tipo'] ?? '');
        if ($tipo === 'none') $where .= ' AND c.tipo_id IS NULL';
        elseif (ctype_digit($tipo)) {
            $where .= ' AND c.tipo_id = ?';
            $p[] = (int)$tipo;
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM clients c $where");
        $st->execute($p);
        $total = (int)$st->fetchColumn();

        $st = $this->pdo->prepare("SELECT c.id, c.name, c.iniciales, c.activo, c.username, c.conversiones, c.tipo_id, c.partner_id, t.nombre AS tipo_nombre
                                     FROM clients c LEFT JOIN client_types t ON t.id = c.tipo_id
                                     $where ORDER BY c.orden, c.name, c.id LIMIT $limit OFFSET $offset");
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

    /* ---------- Listado: actividad de cada cliente ---------- */

    /**
     * Tareas abiertas, tickets abiertos y pendiente de cobro (céntimos) de esos
     * clientes, con una consulta por cifra (sin N+1).
     * @return array<int, array{tareas_abiertas:int, tickets_abiertos:int, pendiente_cobro:int}>
     */
    public function actividad(array $clientIds): array
    {
        $ids = implode(',', array_map('intval', $clientIds));
        if ($ids === '') return [];
        $out = [];
        foreach ($clientIds as $id) $out[(int)$id] = ['tareas_abiertas' => 0, 'tickets_abiertos' => 0, 'pendiente_cobro' => 0];
        foreach ($this->pdo->query("SELECT client_id, COUNT(*) n FROM tasks WHERE client_id IN ($ids) AND estado <> 'completada' GROUP BY client_id") as $r) {
            $out[(int)$r['client_id']]['tareas_abiertas'] = (int)$r['n'];
        }
        foreach ($this->pdo->query("SELECT client_id, COUNT(*) n FROM support_tickets WHERE client_id IN ($ids) AND estado IN ('abierto','en_curso','esperando') GROUP BY client_id") as $r) {
            $out[(int)$r['client_id']]['tickets_abiertos'] = (int)$r['n'];
        }
        $sql = 'SELECT i.client_id, i.iva_pct, i.irpf_pct, ' . Importes::sqlBase('i') . " AS base FROM invoices i
                WHERE i.client_id IN ($ids) AND COALESCE(i.estado, '') NOT IN ('borrador','pagada','anulada') AND i.numero IS NOT NULL";
        foreach ($this->pdo->query($sql) as $r) {
            $out[(int)$r['client_id']]['pendiente_cobro'] += Importes::total(Importes::centimos($r['base']), $r['iva_pct'], $r['irpf_pct']);
        }
        return $out;
    }

    /* ---------- Una fila entera ---------- */

    /** La fila completa del cliente, sin mirar el alcance (lo mira quien llama). */
    public function fila(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT c.*, t.nombre AS tipo_nombre FROM clients c LEFT JOIN client_types t ON t.id = c.tipo_id WHERE c.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function usuarioOcupado(string $username, int $excepto = 0): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM clients WHERE username = ? AND id <> ? LIMIT 1');
        $st->execute([$username, $excepto]);
        return (bool)$st->fetchColumn();
    }

    public function loginEmailOcupado(string $email, int $excepto = 0): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM clients WHERE login_email <> '' AND LOWER(login_email) = ? AND id <> ? LIMIT 1");
        $st->execute([mb_strtolower($email), $excepto]);
        return (bool)$st->fetchColumn();
    }

    public function tipoExiste(int $id): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM client_types WHERE id = ?');
        $st->execute([$id]);
        return (bool)$st->fetchColumn();
    }

    /** @param array<string,mixed> $campos columna => valor (columnas de confianza, las pone el servicio). */
    public function insertar(array $campos): int
    {
        $cols = array_keys($campos);
        $sql = 'INSERT INTO clients (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $this->pdo->prepare($sql)->execute(array_values($campos));
        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $campos */
    public function actualizar(int $id, array $campos): void
    {
        if (!$campos) return;
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($campos)));
        $this->pdo->prepare("UPDATE clients SET $set WHERE id = ?")->execute([...array_values($campos), $id]);
    }

    /* Las cuatro listas con las que nace cada cliente (edit.php). */
    public function crearListasPorDefecto(int $clientId): void
    {
        $st = $this->pdo->prepare('INSERT INTO task_lists (client_id, nombre, es_cliente, tipo, orden) VALUES (?, ?, 0, ?, ?)');
        foreach ([['TAREAS', 'tareas'], ['ESTRATEGIA', 'tareas'], ['TAREA CLIENTE', 'tareas'], ['INFORMES CLIENTE', 'informe']] as $i => [$nombre, $tipo]) {
            $st->execute([$clientId, $nombre, $tipo, $i]);
        }
    }

    private function normalizar(array $c): array
    {
        $out = ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'iniciales' => trim((string)$c['iniciales']), 'activo' => (int)$c['activo'] === 1];
        if (array_key_exists('username', $c)) {
            $out += [
                'username' => (string)$c['username'],
                'conversiones' => (int)$c['conversiones'] === 1,
                'tipo_id' => $c['tipo_id'] !== null ? (int)$c['tipo_id'] : null,
                'tipo_nombre' => $c['tipo_nombre'] !== null ? (string)$c['tipo_nombre'] : null,
                'partner_id' => $c['partner_id'] !== null ? (int)$c['partner_id'] : null,
            ];
        }
        return $out;
    }
}
