<?php
namespace Croilab\Modulos\Trabajo;

use PDO;

/* SQL de la bandeja de avisos (tabla notifications). Todo filtrado por la
   persona: nadie toca los avisos de otro. */
class NotificacionesRepositorio
{
    /* Bandeja de cada fila, en este orden (§2.3): borradas → más tarde → chat → otras → principal. */
    private const BANDEJA = "CASE WHEN n.borrado = 1 THEN 'papelera'
                                  WHEN n.snooze_until IS NOT NULL AND n.snooze_until > NOW() THEN 'tarde'
                                  WHEN n.tipo = 'chat' THEN 'chat'
                                  WHEN n.bandeja = 'otras' THEN 'otras'
                                  ELSE 'principal' END";

    public function __construct(private readonly PDO $pdo) {}

    /** @return array{0: array, 1: int} */
    public function listar(int $adminId, string $bandeja, int $limit, int $offset): array
    {
        $b = self::BANDEJA;
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM notifications n WHERE n.admin_id = ? AND ($b) = ?");
        $st->execute([$adminId, $bandeja]);
        $total = (int)$st->fetchColumn();
        $orden = $bandeja === 'tarde' ? 'n.snooze_until, n.id DESC' : 'n.created_at DESC, n.id DESC';
        $st = $this->pdo->prepare("SELECT n.id, n.tipo, n.titulo, n.cuerpo, n.url, n.tarea, n.actor, n.leido, n.snooze_until, n.created_at
                                   FROM notifications n WHERE n.admin_id = ? AND ($b) = ? ORDER BY $orden LIMIT $limit OFFSET $offset");
        $st->execute([$adminId, $bandeja]);
        return [$st->fetchAll(), $total];
    }

    /** Contadores de cada pestaña: no leídas (principal, otras, chat) y totales (tarde, papelera). */
    public function contadores(int $adminId): array
    {
        $b = self::BANDEJA;
        $st = $this->pdo->prepare("SELECT ($b) AS caja, COUNT(*) total, SUM(n.leido = 0) no_leidas FROM notifications n WHERE n.admin_id = ? GROUP BY caja");
        $st->execute([$adminId]);
        $out = [];
        foreach (['principal', 'otras', 'chat', 'tarde', 'papelera'] as $k) $out[$k] = ['total' => 0, 'no_leidas' => 0];
        foreach ($st->fetchAll() as $r) $out[$r['caja']] = ['total' => (int)$r['total'], 'no_leidas' => (int)$r['no_leidas']];
        return $out;
    }

    /** Lo del globo: sin leer, ni borradas, ni chat, ni pospuestas. */
    public function noLeidas(int $adminId): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE admin_id = ? AND leido = 0 AND borrado = 0 AND tipo <> 'chat'
                                   AND (snooze_until IS NULL OR snooze_until <= NOW())");
        $st->execute([$adminId]);
        return (int)$st->fetchColumn();
    }

    /** Avisos nuevos para el sondeo (id > $despues), del más antiguo al más nuevo. */
    public function nuevos(int $adminId, int $despues, int $max = 6): array
    {
        $st = $this->pdo->prepare("SELECT id, tipo, titulo, cuerpo, url, tarea, actor FROM notifications
                                   WHERE admin_id = ? AND id > ? AND leido = 0 AND borrado = 0 AND tipo <> 'chat'
                                     AND (snooze_until IS NULL OR snooze_until <= NOW())
                                   ORDER BY id DESC LIMIT " . max(1, $max));
        $st->execute([$adminId, $despues]);
        return array_reverse($st->fetchAll());
    }

    public function ultimoId(int $adminId): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM notifications WHERE admin_id = ?');
        $st->execute([$adminId]);
        return (int)$st->fetchColumn();
    }

    /** Aplica una acción a los ids de la persona. Devuelve cuántas filas cambian. */
    public function accion(int $adminId, string $accion, array $ids, ?string $hasta = null): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return 0;
        $in = implode(',', $ids);
        [$set, $extra] = match ($accion) {
            'leer' => ['leido = 1', ''],
            'no_leer' => ['leido = 0', ''],
            'posponer' => ['snooze_until = ?', ' AND borrado = 0'],
            'traer' => ['snooze_until = NULL', ''],
            'borrar' => ['borrado = 1', ''],
            'restaurar' => ['borrado = 0', ''],
            default => [null, ''],
        };
        if ($accion === 'purgar') {
            /* Solo lo que ya está en la papelera (el antiguo purgaba cualquiera). */
            $st = $this->pdo->prepare("DELETE FROM notifications WHERE admin_id = ? AND borrado = 1 AND id IN ($in)");
            $st->execute([$adminId]);
            return $st->rowCount();
        }
        if ($set === null) return 0;
        $p = $accion === 'posponer' ? [$hasta, $adminId] : [$adminId];
        $st = $this->pdo->prepare("UPDATE notifications SET $set WHERE admin_id = ? AND id IN ($in)$extra");
        $st->execute($p);
        return $st->rowCount();
    }

    public function leerTodas(int $adminId): int
    {
        $st = $this->pdo->prepare("UPDATE notifications SET leido = 1 WHERE admin_id = ? AND leido = 0 AND borrado = 0
                                   AND (snooze_until IS NULL OR snooze_until <= NOW())");
        $st->execute([$adminId]);
        return $st->rowCount();
    }

    public function leidasAPapelera(int $adminId): int
    {
        $st = $this->pdo->prepare('UPDATE notifications SET borrado = 1 WHERE admin_id = ? AND leido = 1 AND borrado = 0');
        $st->execute([$adminId]);
        return $st->rowCount();
    }

    public function vaciarPapelera(int $adminId): int
    {
        $st = $this->pdo->prepare('DELETE FROM notifications WHERE admin_id = ? AND borrado = 1');
        $st->execute([$adminId]);
        return $st->rowCount();
    }

    /** Estado de las tareas enlazadas (solo de las que se pasan, ya filtradas por alcance). */
    public function estadosTareas(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $out = [];
        foreach ($this->pdo->query('SELECT id, estado FROM tasks WHERE id IN (' . implode(',', $ids) . ')') as $r) $out[(int)$r['id']] = (string)$r['estado'];
        return $out;
    }

    /** username => foto de quien firma los avisos (actor es texto). */
    public function fotosPorNombre(array $nombres): array
    {
        $nombres = array_values(array_unique(array_filter($nombres)));
        if (!$nombres) return [];
        $st = $this->pdo->prepare('SELECT a.id, a.username, p.foto FROM admins a LEFT JOIN admin_profiles p ON p.admin_id = a.id
                                   WHERE a.username IN (' . implode(',', array_fill(0, count($nombres), '?')) . ')');
        $st->execute($nombres);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(string)$r['username']] = ['id' => (int)$r['id'], 'foto' => $r['foto'] ? 'archivo.php?d=avatars&f=' . rawurlencode(basename((string)$r['foto'])) : null];
        }
        return $out;
    }
}
