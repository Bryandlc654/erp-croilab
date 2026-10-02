<?php
namespace Croilab\Seguridad;

use Croilab\Http\HttpError;
use PDO;

/* Qué puede hacer y qué puede ver una persona. Es la única implementación del
   alcance: las funciones alcance_*() de admin/lib/permisos.php delegan aquí.
   Recibe el usuario y sus permisos de forma explícita (en vez de leer la
   sesión) para que se pueda probar con cualquier persona.

   Alcance: con `alcance.todos` se ve todo el ERP. Sin él, solo las tareas de las
   que se es responsable o asignado, y los clientes de esas tareas (más los de
   los contactos del CRM de los que se es propietario). */
class Acceso
{
    private ?array $clientes = null;
    private bool $clientesCalculados = false;
    private static ?self $actual = null;

    /** @param string[] $permisos */
    public function __construct(
        private readonly PDO $pdo,
        public readonly int $adminId,
        private readonly array $permisos
    ) {}

    /** La persona de la sesión actual (una instancia por petición). */
    public static function actual(): self
    {
        if (self::$actual === null || self::$actual->adminId !== (int)(current_admin()['id'] ?? 0)) {
            $a = current_admin();
            self::$actual = new self(db(), (int)($a['id'] ?? 0), $a && function_exists('perm_mios') ? perm_mios() : []);
        }
        return self::$actual;
    }

    public function puede(string $permiso): bool
    {
        return in_array('admin.total', $this->permisos, true) || in_array($permiso, $this->permisos, true);
    }

    public function exigir(string ...$permisos): void
    {
        foreach ($permisos as $p) if (!$this->puede($p)) throw HttpError::permiso();
    }

    public function veTodo(): bool
    {
        return $this->puede('alcance.todos');
    }

    /** Ids de clientes visibles. null = todos (no filtrar). [] = ninguno. */
    public function clientesVisibles(): ?array
    {
        if ($this->veTodo()) return null;
        if ($this->clientesCalculados) return $this->clientes;
        $this->clientesCalculados = true;
        if (!$this->adminId) return $this->clientes = [];

        $ids = [];
        $st = $this->pdo->prepare(
            'SELECT DISTINCT t.client_id FROM tasks t LEFT JOIN task_assignees a ON a.task_id = t.id
             WHERE t.client_id IS NOT NULL AND (t.responsable_id = ? OR a.admin_id = ?)'
        );
        $st->execute([$this->adminId, $this->adminId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $ids[] = (int)$c;
        try {
            $st = $this->pdo->prepare('SELECT DISTINCT client_id FROM contacts WHERE client_id IS NOT NULL AND propietario_id = ?');
            $st->execute([$this->adminId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $ids[] = (int)$c;
        } catch (\PDOException $e) {
            /* Sin módulo de CRM no hay contactos que sumar. */
        }
        return $this->clientes = array_values(array_unique(array_filter($ids)));
    }

    public function veCliente(int $clientId): bool
    {
        $ids = $this->clientesVisibles();
        return $ids === null || in_array($clientId, $ids, true);
    }

    public function veTarea(int $taskId): bool
    {
        if ($this->veTodo()) return true;
        if (!$this->adminId) return false;
        $st = $this->pdo->prepare('SELECT 1 FROM tasks t LEFT JOIN task_assignees a ON a.task_id = t.id
                                   WHERE t.id = ? AND (t.responsable_id = ? OR a.admin_id = ?) LIMIT 1');
        $st->execute([$taskId, $this->adminId, $this->adminId]);
        return (bool)$st->fetchColumn();
    }

    /** Fragmento SQL (" AND …") para filtrar clientes por la columna dada. */
    public function sqlClientes(string $columna): string
    {
        $ids = $this->clientesVisibles();
        if ($ids === null) return '';
        if (!$ids) return ' AND 1=0 ';
        return ' AND ' . self::identificador($columna) . ' IN (' . implode(',', array_map('intval', $ids)) . ') ';
    }

    /** Fragmento SQL (" AND …") para filtrar tareas por alias de la tabla tasks. */
    public function sqlTareas(string $alias = 't'): string
    {
        if ($this->veTodo()) return '';
        if (!$this->adminId) return ' AND 1=0 ';
        $a = self::identificador($alias);
        $yo = $this->adminId;
        return " AND ({$a}.responsable_id={$yo} OR EXISTS(SELECT 1 FROM task_assignees za WHERE za.task_id={$a}.id AND za.admin_id={$yo})) ";
    }

    /** Los alias y columnas los pone el código, pero se limpian igual. */
    private static function identificador(string $s): string
    {
        return preg_replace('/[^a-zA-Z0-9_.]/', '', $s);
    }
}
