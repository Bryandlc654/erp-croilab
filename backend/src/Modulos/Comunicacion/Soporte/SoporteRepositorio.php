<?php
namespace Croilab\Modulos\Comunicacion\Soporte;

use Croilab\Seguridad\Acceso;
use PDO;

/* SQL de los tickets (support_tickets) y sus respuestas (support_replies). */
class SoporteRepositorio
{
    private const CAMPOS = 't.id, t.asunto, t.cuerpo, t.client_id, c.name AS cliente, t.prioridad, t.estado, t.assignee_id, t.created_by, t.created_at, t.updated_at,
                            (SELECT COUNT(*) FROM support_replies r WHERE r.ticket_id = t.id) AS respuestas';

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Alcance: quien no ve todos los clientes ve los tickets de sus clientes y,
     * además, los que tiene asignados o ha abierto él (el antiguo escondía todos
     * los que no tenían cliente, también los suyos).
     */
    public function sqlAlcance(Acceso $acc): string
    {
        if ($acc->veTodo()) return '';
        $yo = (int)$acc->adminId;
        $cli = $acc->sqlClientes('t.client_id');   // " AND …" o ''
        $cond = $cli === '' ? '1=1' : ('(t.client_id IS NOT NULL ' . $cli . ')');
        return " AND ($cond OR t.assignee_id = $yo OR t.created_by = $yo) ";
    }

    public function listar(Acceso $acc, string $estado, int $cliente, int $limite = 500): array
    {
        [$where, $p] = $this->filtros($acc, $cliente);
        if ($estado === 'resuelto') $where .= " AND t.estado IN ('resuelto','cerrado')";
        elseif ($estado !== '') { $where .= ' AND t.estado = ?'; $p[] = $estado; }
        $st = $this->pdo->prepare('SELECT ' . self::CAMPOS . " FROM support_tickets t LEFT JOIN clients c ON c.id = t.client_id
            WHERE $where ORDER BY FIELD(t.estado, 'abierto', 'en_curso', 'esperando', 'resuelto', 'cerrado'), t.prioridad DESC, t.updated_at DESC, t.id DESC
            LIMIT " . max(1, $limite));
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,int> estado => nº (sin filtro de estado) */
    public function contadores(Acceso $acc, int $cliente): array
    {
        [$where, $p] = $this->filtros($acc, $cliente);
        $st = $this->pdo->prepare("SELECT t.estado, COUNT(*) n FROM support_tickets t WHERE $where GROUP BY t.estado");
        $st->execute($p);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['estado']] = (int)$r['n'];
        return $out;
    }

    private function filtros(Acceso $acc, int $cliente): array
    {
        $where = '1=1' . $this->sqlAlcance($acc);
        $p = [];
        if ($cliente > 0) { $where .= ' AND t.client_id = ?'; $p[] = $cliente; }
        return [$where, $p];
    }

    public function buscar(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::CAMPOS . ' FROM support_tickets t LEFT JOIN clients c ON c.id = t.client_id WHERE t.id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function visible(Acceso $acc, int $id): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM support_tickets t WHERE t.id = ?' . $this->sqlAlcance($acc));
        $st->execute([$id]);
        return (bool)$st->fetchColumn();
    }

    public function crear(array $c): int
    {
        $this->pdo->prepare('INSERT INTO support_tickets (asunto, cuerpo, client_id, prioridad, estado, assignee_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$c['asunto'], $c['cuerpo'], $c['client_id'], $c['prioridad'], $c['estado'], $c['assignee_id'], $c['created_by']]);
        return (int)$this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $campos): void
    {
        if (!$campos) return;
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
        /* updated_at se pone a mano: si el valor no cambia, ON UPDATE no lo movería. */
        $this->pdo->prepare("UPDATE support_tickets SET $set, updated_at = NOW() WHERE id = ?")->execute([...array_values($campos), $id]);
    }

    public function respuestas(int $ticketId): array
    {
        $st = $this->pdo->prepare('SELECT id, admin_id, cuerpo, created_at FROM support_replies WHERE ticket_id = ? ORDER BY id');
        $st->execute([$ticketId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function responder(int $ticketId, int $adminId, string $cuerpo): int
    {
        $this->pdo->prepare('INSERT INTO support_replies (ticket_id, admin_id, cuerpo) VALUES (?, ?, ?)')->execute([$ticketId, $adminId, $cuerpo]);
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE support_tickets SET updated_at = NOW() WHERE id = ?')->execute([$ticketId]);
        return $id;
    }

    public function respuesta(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, admin_id, cuerpo, created_at FROM support_replies WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Clientes que se pueden elegir (dentro del alcance), activos primero. */
    public function clientes(Acceso $acc): array
    {
        $st = $this->pdo->query('SELECT id, name, activo FROM clients WHERE 1=1' . $acc->sqlClientes('id') . ' ORDER BY activo DESC, name');
        return array_map(fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['name'], 'activo' => (int)$r['activo'] === 1], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function nombreCliente(int $id): ?string
    {
        $st = $this->pdo->prepare('SELECT name FROM clients WHERE id = ?');
        $st->execute([$id]);
        $n = $st->fetchColumn();
        return $n === false ? null : (string)$n;
    }
}
