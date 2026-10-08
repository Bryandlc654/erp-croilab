<?php
namespace Croilab\Modulos\Comunicacion\Chat;

use PDO;

/* SQL del chat (chat_rooms, chat_members, chat_messages, chat_reactions,
   chat_typing). Sin reglas: las comprobaciones están en ChatServicio. */
class ChatRepositorio
{
    public function __construct(private readonly PDO $pdo) {}

    public function ahora(): string
    {
        return (string)$this->pdo->query('SELECT NOW(3)')->fetchColumn();
    }

    /* ---------- Salas ---------- */

    public function sala(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, name, type, created_by, created_at FROM chat_rooms WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function esMiembro(int $salaId, int $adminId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM chat_members WHERE room_id = ? AND admin_id = ?');
        $st->execute([$salaId, $adminId]);
        return (bool)$st->fetchColumn();
    }

    /**
     * Mis salas con el último mensaje y los no leídos. Orden: la del mensaje
     * más reciente primero; las vacías al final.
     */
    public function salasDe(int $adminId): array
    {
        $st = $this->pdo->prepare(
            'SELECT r.id, r.name, r.type, r.created_by, r.created_at, me.last_read,
                    lm.id AS ult_id, lm.body AS ult_body, lm.admin_id AS ult_autor, lm.created_at AS ult_creado,
                    lm.deleted AS ult_borrado, lm.attach AS ult_adjuntos,
                    (SELECT COUNT(*) FROM chat_messages cm WHERE cm.room_id = r.id AND cm.id > me.last_read AND cm.admin_id <> me.admin_id AND cm.deleted = 0) AS no_leidos
               FROM chat_members me
               JOIN chat_rooms r ON r.id = me.room_id
               LEFT JOIN chat_messages lm ON lm.id = (SELECT MAX(x.id) FROM chat_messages x WHERE x.room_id = r.id)
              WHERE me.admin_id = ?
              ORDER BY lm.id IS NULL, lm.id DESC, r.id DESC'
        );
        $st->execute([$adminId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, int[]> sala => ids de sus miembros */
    public function miembros(array $salaIds): array
    {
        $salaIds = array_values(array_filter(array_map('intval', $salaIds)));
        if (!$salaIds) return [];
        $st = $this->pdo->prepare('SELECT room_id, admin_id FROM chat_members WHERE room_id IN (' . implode(',', array_fill(0, count($salaIds), '?')) . ') ORDER BY admin_id');
        $st->execute($salaIds);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['room_id']][] = (int)$r['admin_id'];
        return $out;
    }

    /** Directo entre dos personas (exactamente esas dos), o 0. */
    public function dmEntre(int $a, int $b): int
    {
        $st = $this->pdo->prepare("SELECT r.id FROM chat_rooms r
            JOIN chat_members m1 ON m1.room_id = r.id AND m1.admin_id = ?
            JOIN chat_members m2 ON m2.room_id = r.id AND m2.admin_id = ?
            WHERE r.type = 'dm' ORDER BY r.id LIMIT 1");
        $st->execute([$a, $b]);
        return (int)$st->fetchColumn();
    }

    /** @param int[] $miembros */
    public function crearSala(string $tipo, string $nombre, int $creador, array $miembros): int
    {
        $this->pdo->prepare('INSERT INTO chat_rooms (name, type, created_by) VALUES (?, ?, ?)')->execute([$nombre, $tipo, $creador]);
        $id = (int)$this->pdo->lastInsertId();
        $ins = $this->pdo->prepare('INSERT IGNORE INTO chat_members (room_id, admin_id) VALUES (?, ?)');
        foreach (array_unique(array_merge([$creador], $miembros)) as $m) $ins->execute([$id, (int)$m]);
        return $id;
    }

    public function renombrar(int $salaId, string $nombre): void
    {
        $this->pdo->prepare("UPDATE chat_rooms SET name = ? WHERE id = ? AND type = 'group'")->execute([$nombre, $salaId]);
    }

    public function agregarMiembro(int $salaId, int $adminId): void
    {
        /* Entra sin «no leídos» de todo el historial: lo anterior lo puede leer, pero no le avisa. */
        $this->pdo->prepare('INSERT IGNORE INTO chat_members (room_id, admin_id, last_read) VALUES (?, ?, (SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE room_id = ?))')
            ->execute([$salaId, $adminId, $salaId]);
    }

    public function quitarMiembro(int $salaId, int $adminId): void
    {
        $this->pdo->prepare('DELETE FROM chat_members WHERE room_id = ? AND admin_id = ?')->execute([$salaId, $adminId]);
        $this->pdo->prepare('DELETE FROM chat_typing WHERE room_id = ? AND admin_id = ?')->execute([$salaId, $adminId]);
    }

    /* ---------- Mensajes ---------- */

    private const CAMPOS = 'id, room_id, admin_id, body, created_at, reply_to, edited, deleted, attach';

    public function mensaje(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::CAMPOS . ' FROM chat_messages WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<int, array> id => fila */
    public function mensajesPorIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $st = $this->pdo->prepare('SELECT ' . self::CAMPOS . ' FROM chat_messages WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r;
        return $out;
    }

    /** Los $limite mensajes anteriores a $antes (0 = los últimos), en orden de llegada. */
    public function historial(int $salaId, int $antes, int $limite): array
    {
        $sql = 'SELECT ' . self::CAMPOS . ' FROM chat_messages WHERE room_id = ?' . ($antes > 0 ? ' AND id < ?' : '') . ' ORDER BY id DESC LIMIT ' . max(1, $limite);
        $st = $this->pdo->prepare($sql);
        $st->execute($antes > 0 ? [$salaId, $antes] : [$salaId]);
        return array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function despues(int $salaId, int $despues, int $limite): array
    {
        $st = $this->pdo->prepare('SELECT ' . self::CAMPOS . ' FROM chat_messages WHERE room_id = ? AND id > ? ORDER BY id LIMIT ' . max(1, $limite));
        $st->execute([$salaId, $despues]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Mensajes ya conocidos (id ≤ $hasta) que han cambiado desde el cursor. */
    public function cambiados(int $salaId, string $cursor, int $hasta): array
    {
        $st = $this->pdo->prepare('SELECT ' . self::CAMPOS . ' FROM chat_messages WHERE room_id = ? AND cambiado_en > ? AND id <= ? ORDER BY id LIMIT 200');
        $st->execute([$salaId, $cursor, $hasta]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertar(int $salaId, int $autor, string $texto, ?int $respondeA, ?string $adjuntos): int
    {
        $this->pdo->prepare('INSERT INTO chat_messages (room_id, admin_id, body, reply_to, attach) VALUES (?, ?, ?, ?, ?)')
            ->execute([$salaId, $autor, $texto, $respondeA, $adjuntos]);
        return (int)$this->pdo->lastInsertId();
    }

    public function editar(int $id, string $texto): void
    {
        $this->pdo->prepare('UPDATE chat_messages SET body = ?, edited = 1, cambiado_en = NOW(3) WHERE id = ? AND deleted = 0')->execute([$texto, $id]);
    }

    public function borrar(int $id): void
    {
        $this->pdo->prepare("UPDATE chat_messages SET deleted = 1, body = '', attach = NULL, cambiado_en = NOW(3) WHERE id = ?")->execute([$id]);
        $this->pdo->prepare('DELETE FROM chat_reactions WHERE message_id = ?')->execute([$id]);
    }

    public function tocar(int $id): void
    {
        $this->pdo->prepare('UPDATE chat_messages SET cambiado_en = NOW(3) WHERE id = ?')->execute([$id]);
    }

    /* ---------- Reacciones ---------- */

    /** @return array<int, array> filas {message_id, admin_id, emoji} en orden de llegada */
    public function reacciones(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $st = $this->pdo->prepare('SELECT message_id, admin_id, emoji FROM chat_reactions WHERE message_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY created_at, emoji');
        $st->execute($ids);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Pone o quita mi reacción. Devuelve true si queda puesta. */
    public function alternarReaccion(int $mensajeId, int $adminId, string $emoji): bool
    {
        $st = $this->pdo->prepare('DELETE FROM chat_reactions WHERE message_id = ? AND admin_id = ? AND emoji = ?');
        $st->execute([$mensajeId, $adminId, $emoji]);
        $puesta = $st->rowCount() === 0;
        if ($puesta) $this->pdo->prepare('INSERT IGNORE INTO chat_reactions (message_id, admin_id, emoji) VALUES (?, ?, ?)')->execute([$mensajeId, $adminId, $emoji]);
        $this->tocar($mensajeId);
        return $puesta;
    }

    /* ---------- Leído / no leído ---------- */

    /** Solo avanza: un sondeo que llega tarde no «desmarca» lo leído. */
    public function marcarLeido(int $salaId, int $adminId, int $hasta): void
    {
        if ($hasta <= 0) return;
        $this->pdo->prepare('UPDATE chat_members SET last_read = GREATEST(last_read, ?) WHERE room_id = ? AND admin_id = ?')->execute([$hasta, $salaId, $adminId]);
    }

    /** Hasta dónde han leído TODOS los demás (para el doble tic). */
    public function leidoPorTodos(int $salaId, int $excepto): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(MIN(last_read), 0) FROM chat_members WHERE room_id = ? AND admin_id <> ?');
        $st->execute([$salaId, $excepto]);
        return (int)$st->fetchColumn();
    }

    /** @return array<int,int> sala => mensajes de otros sin leer */
    public function noLeidosPorSala(int $adminId): array
    {
        $st = $this->pdo->prepare('SELECT cm.room_id, COUNT(*) n FROM chat_members me
            JOIN chat_messages cm ON cm.room_id = me.room_id AND cm.id > me.last_read
            WHERE me.admin_id = ? AND cm.admin_id <> ? AND cm.deleted = 0 GROUP BY cm.room_id');
        $st->execute([$adminId, $adminId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['room_id']] = (int)$r['n'];
        return $out;
    }

    public function noLeidosTotal(int $adminId): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM chat_members me
            JOIN chat_messages cm ON cm.room_id = me.room_id AND cm.id > me.last_read
            WHERE me.admin_id = ? AND cm.admin_id <> ? AND cm.deleted = 0');
        $st->execute([$adminId, $adminId]);
        return (int)$st->fetchColumn();
    }

    /* ---------- Escribiendo ---------- */

    public function marcarEscribiendo(int $salaId, int $adminId, int $hasta): void
    {
        $this->pdo->prepare('INSERT INTO chat_typing (room_id, admin_id, until_ts) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE until_ts = VALUES(until_ts)')
            ->execute([$salaId, $adminId, $hasta]);
    }

    public function dejarDeEscribir(int $salaId, int $adminId): void
    {
        $this->pdo->prepare('DELETE FROM chat_typing WHERE room_id = ? AND admin_id = ?')->execute([$salaId, $adminId]);
    }

    /** @return int[] quién está escribiendo ahora en la sala (menos yo) */
    public function escribiendo(int $salaId, int $excepto, int $ahora): array
    {
        $st = $this->pdo->prepare('SELECT admin_id FROM chat_typing WHERE room_id = ? AND admin_id <> ? AND until_ts > ?');
        $st->execute([$salaId, $excepto, $ahora]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public function limpiarEscribiendo(int $antesDe): int
    {
        $st = $this->pdo->prepare('DELETE FROM chat_typing WHERE until_ts < ?');
        $st->execute([$antesDe]);
        return $st->rowCount();
    }

    /* ---------- Avisador global ---------- */

    /** Último id de mensaje en mis salas (la línea base del avisador). */
    public function ultimoIdEnMisSalas(int $adminId): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(MAX(cm.id), 0) FROM chat_members me JOIN chat_messages cm ON cm.room_id = me.room_id WHERE me.admin_id = ?');
        $st->execute([$adminId]);
        return (int)$st->fetchColumn();
    }

    /** Mensajes de otros en mis salas posteriores a $despues. */
    public function nuevosParaMi(int $adminId, int $despues, int $limite): array
    {
        $st = $this->pdo->prepare('SELECT m.id, m.room_id, m.admin_id, m.body, m.attach, m.deleted, r.type, r.name
              FROM chat_members me
              JOIN chat_messages m ON m.room_id = me.room_id AND m.id > ?
              JOIN chat_rooms r ON r.id = m.room_id
             WHERE me.admin_id = ? AND m.admin_id <> ? AND m.deleted = 0
             ORDER BY m.id LIMIT ' . max(1, $limite));
        $st->execute([$despues, $adminId, $adminId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ---------- Avisos de la campana ---------- */

    public function borrarAviso(int $adminId, string $ref): void
    {
        $this->pdo->prepare('DELETE FROM notifications WHERE admin_id = ? AND ref = ?')->execute([$adminId, $ref]);
    }
}
