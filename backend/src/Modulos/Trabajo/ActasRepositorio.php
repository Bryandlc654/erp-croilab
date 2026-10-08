<?php
namespace Croilab\Modulos\Trabajo;

use PDO;

/* SQL de las actas internas del equipo (tabla actas). */
class ActasRepositorio
{
    public function __construct(private readonly PDO $pdo) {}

    /** Todas, fijadas primero y luego por última edición (como el antiguo). Filtros opcionales q y autor. */
    public function listar(string $q, int $autor, int $limit, int $offset): array
    {
        $w = [];
        $p = [];
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
            $w[] = '(titulo LIKE ? OR contenido LIKE ?)';
            array_push($p, $like, $like);
        }
        if ($autor > 0) { $w[] = 'admin_id = ?'; $p[] = $autor; }
        elseif ($autor === -1) { $w[] = 'admin_id IS NULL'; }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM actas $where");
        $st->execute($p);
        $total = (int)$st->fetchColumn();
        $st = $this->pdo->prepare("SELECT id, titulo, contenido, admin_id, pinned, created_at, updated_at FROM actas $where
                                   ORDER BY pinned DESC, updated_at DESC, id DESC LIMIT $limit OFFSET $offset");
        $st->execute($p);
        return [$st->fetchAll(), $total];
    }

    /** Autores con su número de actas (chips «Todos · Ana · …»). */
    public function autores(): array
    {
        return $this->pdo->query('SELECT admin_id, COUNT(*) n FROM actas GROUP BY admin_id ORDER BY n DESC')->fetchAll();
    }

    public function buscar(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, titulo, contenido, admin_id, pinned, created_at, updated_at FROM actas WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function crear(string $titulo, string $contenido, int $adminId): int
    {
        $this->pdo->prepare('INSERT INTO actas (titulo, contenido, admin_id) VALUES (?, ?, ?)')->execute([$titulo, $contenido, $adminId ?: null]);
        return (int)$this->pdo->lastInsertId();
    }

    public function guardar(int $id, string $titulo, string $contenido): void
    {
        $this->pdo->prepare('UPDATE actas SET titulo = ?, contenido = ? WHERE id = ?')->execute([$titulo, $contenido, $id]);
    }

    /* Fijar no cuenta como edición: updated_at se queda como estaba. */
    public function fijar(int $id, bool $fijada): void
    {
        $this->pdo->prepare('UPDATE actas SET pinned = ?, updated_at = updated_at WHERE id = ?')->execute([$fijada ? 1 : 0, $id]);
    }
}
