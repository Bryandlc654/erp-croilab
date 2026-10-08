<?php
namespace Croilab\Modulos\Equipo;

use Croilab\Seguridad\Acceso;
use PDO;

/* Personas del equipo (admins) y su ficha social (admin_profiles).

   Los cinco primeros métodos (activos, persona, existe, foto, nombres) los
   usan Tareas, Nav y el Contenedor: no cambiar su firma ni su forma. */
class EquipoRepositorio
{
    private ?array $fotos = null;
    private ?array $nombres = null;

    /* Campos de la ficha social y su largo máximo (los del antiguo). */
    public const PERFIL = ['cargo' => 120, 'departamento' => 80, 'telefono' => 60, 'ubicacion' => 120, 'web' => 160, 'skills' => 300, 'bio' => 2000];

    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int, array{id:int, username:string, foto:?string}> */
    public function activos(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT id, username FROM admins WHERE activo = 1 ORDER BY username') as $a) {
            $out[] = $this->persona((int)$a['id'], (string)$a['username']);
        }
        return $out;
    }

    /** Persona por id, aunque esté desactivada (sigue saliendo en sus tareas). */
    public function persona(int $id, ?string $username = null): array
    {
        $username ??= $this->nombres()[$id] ?? '';
        return ['id' => $id, 'username' => $username, 'foto' => $this->foto($id)];
    }

    public function existe(int $id): bool
    {
        return isset($this->nombres()[$id]);
    }

    /** Ruta relativa al backend; el front le antepone la URL de la API. */
    public function foto(int $adminId): ?string
    {
        if ($this->fotos === null) {
            $this->fotos = [];
            foreach ($this->pdo->query("SELECT admin_id, foto FROM admin_profiles WHERE foto IS NOT NULL AND foto <> ''") as $r) {
                $this->fotos[(int)$r['admin_id']] = 'archivo.php?d=avatars&f=' . rawurlencode(basename((string)$r['foto']));
            }
        }
        return $this->fotos[$adminId] ?? null;
    }

    /** @return array<int,string> id => username de todo el equipo */
    public function nombres(): array
    {
        if ($this->nombres === null) {
            $this->nombres = [];
            foreach ($this->pdo->query('SELECT id, username FROM admins') as $a) $this->nombres[(int)$a['id']] = (string)$a['username'];
        }
        return $this->nombres;
    }

    /** Tras escribir: lo que se recordaba de esta petición ya no vale. */
    public function olvidar(): void
    {
        $this->fotos = null;
        $this->nombres = null;
    }

    /* ---------- Gestión del equipo ---------- */

    /** Fila de admins (con hash) o null. */
    public function fila(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM admins WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Miembros para «Mi equipo» (activos, o los dados de baja con $bajas). Orden del antiguo: rol, usuario. */
    public function miembros(bool $bajas = false): array
    {
        $st = $this->pdo->prepare('SELECT a.id, a.username, a.email, a.role, a.activo, a.created_at, p.cumple, p.cargo
                                   FROM admins a LEFT JOIN admin_profiles p ON p.admin_id = a.id
                                   WHERE a.activo = ? ORDER BY a.role, a.username');
        $st->execute([$bajas ? 0 : 1]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function usernameOcupado(string $username, int $excepto = 0): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM admins WHERE username = ? AND id <> ? LIMIT 1');
        $st->execute([$username, $excepto]);
        return (bool)$st->fetchColumn();
    }

    /* El correo es la identidad de «Entrar con Google»: no puede repetirse. */
    public function emailOcupado(string $email, int $excepto = 0): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM admins WHERE email IS NOT NULL AND email <> '' AND LOWER(email) = LOWER(?) AND id <> ? LIMIT 1");
        $st->execute([$email, $excepto]);
        return (bool)$st->fetchColumn();
    }

    public function crear(string $username, ?string $email, string $rol, string $hash): int
    {
        $this->pdo->prepare('INSERT INTO admins (username, password_hash, email, role, activo, cred_ver, password_changed_at) VALUES (?, ?, ?, ?, 1, 1, NOW())')
            ->execute([$username, $hash, $email, $rol]);
        $this->olvidar();
        return (int)$this->pdo->lastInsertId();
    }

    public function actualizarAcceso(int $id, string $username, ?string $email): void
    {
        $this->pdo->prepare('UPDATE admins SET username = ?, email = ? WHERE id = ?')->execute([$username, $email, $id]);
        $this->olvidar();
    }

    public function actualizarFacturacion(int $id, bool $autonomo, string $tarifa, string $iva, string $irpf): void
    {
        $this->pdo->prepare('UPDATE admins SET es_autonomo = ?, tarifa_hora = ?, iva_pct = ?, irpf_pct = ? WHERE id = ?')
            ->execute([$autonomo ? 1 : 0, $tarifa, $iva, $irpf, $id]);
    }

    /** Personas activas cuyo rol tiene acceso total (las bajas no cuentan). */
    public function duenosActivos(): int
    {
        $conTotal = [];
        foreach (roles_todos() as $k => $r) if (in_array('admin.total', $r['permisos'] ?? [], true)) $conTotal[] = (string)$k;
        if (!$conTotal) return 0;
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM admins WHERE activo = 1 AND role IN (' . implode(',', array_fill(0, count($conTotal), '?')) . ')');
        $st->execute($conTotal);
        return (int)$st->fetchColumn();
    }

    /**
     * Baja lógica: deja de poder entrar (activo = 0 y cred_ver++ tumban sus
     * sesiones), sale de las salas de chat, pierde la conexión con Google y sus
     * tareas abiertas quedan sin responsable. Lo que hizo se queda donde está.
     */
    public function darDeBaja(int $id): void
    {
        $this->pdo->prepare('UPDATE admins SET activo = 0, cred_ver = cred_ver + 1 WHERE id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM chat_members WHERE admin_id = ?')->execute([$id]);
        $this->pdo->prepare("UPDATE tasks SET responsable_id = NULL WHERE responsable_id = ? AND estado <> 'completada'")->execute([$id]);
        $this->pdo->prepare("DELETE a FROM task_assignees a JOIN tasks t ON t.id = a.task_id WHERE a.admin_id = ? AND t.estado <> 'completada'")->execute([$id]);
        $this->pdo->prepare('DELETE FROM password_resets WHERE admin_id = ? AND usado_en IS NULL')->execute([$id]);
        $st = $this->pdo->prepare('DELETE FROM settings WHERE clave = ?');
        foreach (['gcal_tok_' . $id, 'gcal_revoked_' . $id, 'notifmute_' . $id] as $k) $st->execute([$k]);
        $this->olvidar();
    }

    public function reactivar(int $id): void
    {
        $this->pdo->prepare('UPDATE admins SET activo = 1, cred_ver = cred_ver + 1 WHERE id = ?')->execute([$id]);
        $this->olvidar();
    }

    /* ---------- Ficha social ---------- */

    public function perfil(int $id): array
    {
        $st = $this->pdo->prepare('SELECT cargo, departamento, telefono, ubicacion, web, skills, cumple, bio, foto FROM admin_profiles WHERE admin_id = ?');
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach (array_keys(self::PERFIL) as $k) $out[$k] = (string)($p[$k] ?? '');
        $out['cumple'] = $p['cumple'] ?? null;
        $out['foto'] = (string)($p['foto'] ?? '');
        return $out;
    }

    /** Upsert de los campos de texto + cumpleaños (ya validados). */
    public function guardarPerfil(int $id, array $c): void
    {
        $cols = array_keys(self::PERFIL);
        $vals = array_map(fn($k) => (string)($c[$k] ?? ''), $cols);
        $cols[] = 'cumple';
        $vals[] = $c['cumple'] ?? null;
        $set = implode(', ', array_map(fn($k) => "$k = VALUES($k)", $cols));
        $this->pdo->prepare('INSERT INTO admin_profiles (admin_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ") ON DUPLICATE KEY UPDATE $set")
            ->execute(array_merge([$id], $vals));
    }

    /** Cambia la foto y devuelve el nombre de la anterior ('' si no había). */
    public function fijarFoto(int $id, string $foto): string
    {
        $antes = $this->perfil($id)['foto'];
        $this->pdo->prepare('INSERT INTO admin_profiles (admin_id, foto) VALUES (?, ?) ON DUPLICATE KEY UPDATE foto = VALUES(foto)')->execute([$id, $foto]);
        $this->fotos = null;
        return $antes;
    }

    /** Presencia como el antiguo: latido < 65 s y actividad < 5 min. */
    public function presencia(int $id): array
    {
        $st = $this->pdo->prepare('SELECT UNIX_TIMESTAMP(last_seen) ls, UNIX_TIMESTAMP(last_active) la FROM chat_presence WHERE admin_id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $ahora = time();
        $ls = (int)($r['ls'] ?? 0);
        $la = (int)($r['la'] ?? 0);
        if (!$ls || $ahora - $ls > 65) {
            return ['estado' => 'offline', 'texto' => $ls ? 'últ. vez ' . self::hace($ahora - $ls) : 'sin conexión'];
        }
        if (!$la || $ahora - $la > 300) return ['estado' => 'idle', 'texto' => 'ausente'];
        return ['estado' => 'online', 'texto' => 'en línea'];
    }

    private static function hace(int $s): string
    {
        if ($s < 3600) return 'hace ' . max(1, intdiv($s, 60)) . ' min';
        if ($s < 86400) return 'hace ' . intdiv($s, 3600) . ' h';
        return 'hace ' . intdiv($s, 86400) . ' d';
    }

    /**
     * Tareas sin completar de una persona (responsable O asignada: el antiguo
     * solo miraba responsable_id), dentro de lo que puede ver quien mira.
     * @return array{items: array, total: int}
     */
    public function tareasPendientes(Acceso $acc, int $id, int $limite = 8): array
    {
        $donde = "t.estado <> 'completada' AND (t.responsable_id = ? OR EXISTS (SELECT 1 FROM task_assignees x WHERE x.task_id = t.id AND x.admin_id = ?))" . $acc->sqlTareas('t');
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM tasks t WHERE $donde");
        $st->execute([$id, $id]);
        $total = (int)$st->fetchColumn();
        $st = $this->pdo->prepare("SELECT t.id, t.titulo, t.estado, t.due_date, c.name AS cliente, l.nombre AS lista
                                   FROM tasks t LEFT JOIN clients c ON c.id = t.client_id LEFT JOIN task_lists l ON l.id = t.list_id
                                   WHERE $donde
                                   ORDER BY FIELD(t.estado, 'en proceso', 'pendiente', 'atemporal'), c.name, t.id LIMIT " . max(1, min(50, $limite)));
        $st->execute([$id, $id]);
        $items = array_map(fn($t) => [
            'id' => (int)$t['id'], 'titulo' => (string)$t['titulo'], 'estado' => (string)$t['estado'],
            'due_date' => $t['due_date'] ?: null, 'cliente' => (string)($t['cliente'] ?? ''), 'lista' => (string)($t['lista'] ?? ''),
        ], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['items' => $items, 'total' => $total];
    }
}
