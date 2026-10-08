<?php
namespace Croilab\Modulos\Tareas;

use Croilab\Modulos\Equipo\EquipoRepositorio;
use PDO;

/* SQL de lo que cuelga de una tarea: lista de control, comentarios con sus
   reacciones y adjuntos, adjuntos de la tarea y horas imputadas. */
class FichaRepositorio
{
    /* La línea de horas que gestiona la ficha (una por persona y tarea). Las
       demás líneas de time_entries son de Finanzas › Horas y no se tocan. */
    public const CONCEPTO_TIEMPO = 'Horas de la tarea';

    public function __construct(
        private readonly PDO $pdo,
        private readonly EquipoRepositorio $equipo
    ) {}

    /* ---------- Lista de control ---------- */

    /** Orden del antiguo: hechos arriba no; aquí pendientes y luego hechos, cada grupo por `orden`. */
    public function checklist(int $taskId): array
    {
        $st = $this->pdo->prepare('SELECT id, texto, done, responsable_id, orden FROM task_checklist WHERE task_id = ? ORDER BY done, orden, id');
        $st->execute([$taskId]);
        $filas = $st->fetchAll();
        if (!$filas) return [];
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $filas));
        $asig = [];
        foreach ($this->pdo->query("SELECT chk_id, admin_id FROM chk_assignees WHERE chk_id IN ($ids) ORDER BY orden, admin_id") as $a) {
            $asig[(int)$a['chk_id']][] = (int)$a['admin_id'];
        }
        return array_map(fn($r) => [
            'id' => (int)$r['id'],
            'texto' => (string)$r['texto'],
            'done' => (int)$r['done'] === 1,
            'orden' => (int)$r['orden'],
            'asignados' => $asig[(int)$r['id']] ?? ($r['responsable_id'] ? [(int)$r['responsable_id']] : []),
        ], $filas);
    }

    public function punto(int $taskId, int $chkId): ?array
    {
        $st = $this->pdo->prepare('SELECT id, texto, done, responsable_id FROM task_checklist WHERE id = ? AND task_id = ?');
        $st->execute([$chkId, $taskId]);
        return $st->fetch() ?: null;
    }

    public function crearPunto(int $taskId, string $texto): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(MAX(orden), 0) + 1 FROM task_checklist WHERE task_id = ?');
        $st->execute([$taskId]);
        $this->pdo->prepare('INSERT INTO task_checklist (task_id, texto, done, orden) VALUES (?, ?, 0, ?)')->execute([$taskId, $texto, (int)$st->fetchColumn()]);
        return (int)$this->pdo->lastInsertId();
    }

    public function actualizarPunto(int $chkId, array $campos): void
    {
        if (!$campos) return;
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($campos)));
        $this->pdo->prepare("UPDATE task_checklist SET $set WHERE id = ?")->execute([...array_values($campos), $chkId]);
    }

    /** Asignados de un punto: tabla puente + responsable_id = el primero. */
    public function asignadosPunto(int $chkId): array
    {
        $st = $this->pdo->prepare('SELECT admin_id FROM chk_assignees WHERE chk_id = ? ORDER BY orden, admin_id');
        $st->execute([$chkId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($ids) return $ids;
        $st = $this->pdo->prepare('SELECT responsable_id FROM task_checklist WHERE id = ?');
        $st->execute([$chkId]);
        $r = (int)$st->fetchColumn();
        return $r ? [$r] : [];
    }

    public function fijarAsignadosPunto(int $chkId, array $ids): void
    {
        $this->pdo->prepare('DELETE FROM chk_assignees WHERE chk_id = ?')->execute([$chkId]);
        $ins = $this->pdo->prepare('INSERT INTO chk_assignees (chk_id, admin_id, orden) VALUES (?, ?, ?)');
        foreach (array_values($ids) as $i => $a) $ins->execute([$chkId, $a, $i]);
        $this->pdo->prepare('UPDATE task_checklist SET responsable_id = ? WHERE id = ?')->execute([$ids[0] ?? null, $chkId]);
    }

    /* El antiguo borraba el punto y dejaba sus asignados huérfanos. */
    public function borrarPunto(int $chkId): void
    {
        $this->pdo->prepare('DELETE FROM chk_assignees WHERE chk_id = ?')->execute([$chkId]);
        $this->pdo->prepare('DELETE FROM task_checklist WHERE id = ?')->execute([$chkId]);
    }

    public function reordenarPuntos(int $taskId, array $ids): void
    {
        $st = $this->pdo->prepare('UPDATE task_checklist SET orden = ? WHERE id = ? AND task_id = ?');
        foreach (array_values($ids) as $i => $id) $st->execute([$i + 1, (int)$id, $taskId]);
    }

    /* ---------- Comentarios ---------- */

    /** Feed completo de la tarea, con adjuntos y reacciones (3 consultas, sin N+1). */
    public function comentarios(int $taskId, int $yo, int $despues = 0): array
    {
        $st = $this->pdo->prepare('SELECT id, admin_id, cuerpo, checklist_json, reply_to, editado_at, created_at FROM task_comments
                                   WHERE task_id = ? AND id > ? ORDER BY created_at, id');
        $st->execute([$taskId, $despues]);
        $filas = $st->fetchAll();
        if (!$filas) return [];
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $filas));

        $adj = [];
        foreach ($this->pdo->query("SELECT id, comment_id, filename, orig_name, mime, admin_id, created_at FROM task_attachments WHERE comment_id IN ($ids) ORDER BY id") as $a) {
            $adj[(int)$a['comment_id']][] = self::adjunto($a);
        }
        $nombres = $this->equipo->nombres();
        $reac = [];
        foreach ($this->pdo->query("SELECT comment_id, emoji, admin_id FROM task_comment_reactions WHERE comment_id IN ($ids) ORDER BY id") as $r) {
            $c = (int)$r['comment_id'];
            $e = (string)$r['emoji'];
            $reac[$c][$e] ??= ['emoji' => $e, 'n' => 0, 'mia' => false, 'quienes' => []];
            $reac[$c][$e]['n']++;
            if ((int)$r['admin_id'] === $yo) $reac[$c][$e]['mia'] = true;
            if (isset($nombres[(int)$r['admin_id']])) $reac[$c][$e]['quienes'][] = $nombres[(int)$r['admin_id']];
        }

        return array_map(function ($c) use ($adj, $reac, $yo) {
            $id = (int)$c['id'];
            $autor = $c['admin_id'] !== null ? (int)$c['admin_id'] : null;
            return [
                'id' => $id,
                'autor' => $autor !== null && $this->equipo->existe($autor) ? $this->equipo->persona($autor) : null,
                'cuerpo' => (string)$c['cuerpo'],
                'checklist' => self::checklistComentario($c['checklist_json']),
                'reply_to' => $c['reply_to'] !== null ? (int)$c['reply_to'] : null,
                'editado' => $c['editado_at'] !== null,
                'created_at' => (string)$c['created_at'],
                'adjuntos' => $adj[$id] ?? [],
                'reacciones' => array_values($reac[$id] ?? []),
                'mio' => $autor !== null && $autor === $yo,
            ];
        }, $filas);
    }

    /** Número de comentarios y el último id (para el sondeo del panel). */
    public function resumenComentarios(int $taskId): array
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) n, COALESCE(MAX(id), 0) ultimo FROM task_comments WHERE task_id = ?');
        $st->execute([$taskId]);
        $r = $st->fetch();
        return ['n' => (int)$r['n'], 'ultimo' => (int)$r['ultimo']];
    }

    public function comentario(int $taskId, int $cid): ?array
    {
        $st = $this->pdo->prepare('SELECT id, task_id, admin_id, cuerpo, checklist_json, reply_to FROM task_comments WHERE id = ? AND task_id = ?');
        $st->execute([$cid, $taskId]);
        return $st->fetch() ?: null;
    }

    public function crearComentario(int $taskId, ?int $adminId, string $cuerpo, ?array $checklist, ?int $replyTo): int
    {
        $this->pdo->prepare('INSERT INTO task_comments (task_id, admin_id, cuerpo, checklist_json, reply_to) VALUES (?, ?, ?, ?, ?)')
            ->execute([$taskId, $adminId, $cuerpo, $checklist ? json_encode($checklist, JSON_UNESCAPED_UNICODE) : null, $replyTo]);
        return (int)$this->pdo->lastInsertId();
    }

    public function editarComentario(int $cid, array $campos): void
    {
        $set = [];
        $v = [];
        if (array_key_exists('cuerpo', $campos)) { $set[] = 'cuerpo = ?'; $v[] = $campos['cuerpo']; $set[] = 'editado_at = NOW()'; }
        if (array_key_exists('checklist', $campos)) {
            $set[] = 'checklist_json = ?';
            $v[] = $campos['checklist'] ? json_encode($campos['checklist'], JSON_UNESCAPED_UNICODE) : null;
        }
        if (!$set) return;
        $this->pdo->prepare('UPDATE task_comments SET ' . implode(', ', $set) . ' WHERE id = ?')->execute([...$v, $cid]);
    }

    /** Borra el comentario con sus reacciones y las filas de sus adjuntos; devuelve los ficheros. */
    public function borrarComentario(int $cid): array
    {
        $st = $this->pdo->prepare('SELECT filename FROM task_attachments WHERE comment_id = ?');
        $st->execute([$cid]);
        $ficheros = $st->fetchAll(PDO::FETCH_COLUMN);
        $this->pdo->prepare('DELETE FROM task_attachments WHERE comment_id = ?')->execute([$cid]);
        $this->pdo->prepare('DELETE FROM task_comment_reactions WHERE comment_id = ?')->execute([$cid]);
        $this->pdo->prepare('DELETE FROM task_comments WHERE id = ?')->execute([$cid]);
        /* Las respuestas a este comentario se quedan, sin cita. */
        $this->pdo->prepare('UPDATE task_comments SET reply_to = NULL WHERE reply_to = ?')->execute([$cid]);
        return $ficheros;
    }

    /** Pone o quita una reacción (por persona y emoji). Devuelve si queda puesta. */
    public function alternarReaccion(int $cid, int $adminId, string $emoji): bool
    {
        $st = $this->pdo->prepare('DELETE FROM task_comment_reactions WHERE comment_id = ? AND admin_id = ? AND emoji = ?');
        $st->execute([$cid, $adminId, $emoji]);
        if ($st->rowCount() > 0) return false;
        $this->pdo->prepare('INSERT IGNORE INTO task_comment_reactions (comment_id, admin_id, emoji) VALUES (?, ?, ?)')->execute([$cid, $adminId, $emoji]);
        return true;
    }

    /* ---------- Adjuntos ---------- */

    /** Adjuntos de la tarea (no los de los comentarios). */
    public function adjuntos(int $taskId): array
    {
        $st = $this->pdo->prepare('SELECT id, comment_id, filename, orig_name, mime, admin_id, created_at FROM task_attachments
                                   WHERE task_id = ? AND comment_id IS NULL ORDER BY id');
        $st->execute([$taskId]);
        return array_map([self::class, 'adjunto'], $st->fetchAll());
    }

    public function crearAdjunto(int $taskId, ?int $commentId, string $fn, string $orig, string $mime, int $adminId): int
    {
        $this->pdo->prepare('INSERT INTO task_attachments (task_id, comment_id, filename, orig_name, mime, admin_id) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$taskId, $commentId, $fn, mb_substr($orig, 0, 240), mb_substr($mime, 0, 120), $adminId ?: null]);
        return (int)$this->pdo->lastInsertId();
    }

    public function adjunto1(int $taskId, int $aid): ?array
    {
        $st = $this->pdo->prepare('SELECT id, comment_id, filename, orig_name, mime, admin_id, created_at FROM task_attachments WHERE id = ? AND task_id = ?');
        $st->execute([$aid, $taskId]);
        return $st->fetch() ?: null;
    }

    public function borrarAdjunto(int $aid): void
    {
        $this->pdo->prepare('DELETE FROM task_attachments WHERE id = ?')->execute([$aid]);
    }

    /** ¿Lo nombra alguien más (otra fila o algún texto de tareas, comentarios o actas)? */
    public function ficheroEnUso(string $fn): bool
    {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $fn) . '%';
        foreach ([
            ['SELECT 1 FROM task_attachments WHERE filename = ? LIMIT 1', $fn],
            ['SELECT 1 FROM tasks WHERE descripcion_rich LIKE ? LIMIT 1', $like],
            ['SELECT 1 FROM task_comments WHERE cuerpo LIKE ? LIMIT 1', $like],
            ['SELECT 1 FROM actas WHERE contenido LIKE ? LIMIT 1', $like],
            ['SELECT 1 FROM trash WHERE datos LIKE ? LIMIT 1', $like],
        ] as [$sql, $v]) {
            $st = $this->pdo->prepare($sql);
            $st->execute([$v]);
            if ($st->fetchColumn()) return true;
        }
        return false;
    }

    /* ---------- Horas ---------- */

    /** Minutos de la línea «Horas de la tarea» de cada persona y el total de la tarea. */
    public function tiempo(int $taskId): array
    {
        $st = $this->pdo->prepare('SELECT admin_id, SUM(minutos) m FROM time_entries WHERE task_id = ? AND concepto = ? GROUP BY admin_id ORDER BY m DESC');
        $st->execute([$taskId, self::CONCEPTO_TIEMPO]);
        $reparto = [];
        $total = 0;
        foreach ($st->fetchAll() as $r) {
            $m = (int)$r['m'];
            if ($m <= 0) continue;
            $total += $m;
            $reparto[] = ['persona' => $this->equipo->persona((int)$r['admin_id']), 'minutos' => $m];
        }
        return ['total_min' => $total, 'reparto' => $reparto];
    }

    /** Sustituye la línea de horas de esa persona en esa tarea (0 = quitarla). */
    public function fijarTiempo(int $taskId, int $clientId, int $adminId, int $minutos): void
    {
        $this->pdo->prepare('DELETE FROM time_entries WHERE task_id = ? AND admin_id = ? AND concepto = ?')->execute([$taskId, $adminId, self::CONCEPTO_TIEMPO]);
        if ($minutos > 0) {
            $this->pdo->prepare('INSERT INTO time_entries (admin_id, task_id, client_id, fecha, minutos, importe, concepto) VALUES (?, ?, ?, CURDATE(), ?, NULL, ?)')
                ->execute([$adminId, $taskId, $clientId ?: null, $minutos, self::CONCEPTO_TIEMPO]);
        }
    }

    /** Fecha de la línea que ya existía (para no moverla de mes al corregir). */
    public function fechaTiempo(int $taskId, int $adminId): ?string
    {
        $st = $this->pdo->prepare('SELECT MIN(fecha) FROM time_entries WHERE task_id = ? AND admin_id = ? AND concepto = ?');
        $st->execute([$taskId, $adminId, self::CONCEPTO_TIEMPO]);
        $f = $st->fetchColumn();
        return $f ? (string)$f : null;
    }

    public function moverFechaTiempo(int $taskId, int $adminId, string $fecha): void
    {
        $this->pdo->prepare('UPDATE time_entries SET fecha = ? WHERE task_id = ? AND admin_id = ? AND concepto = ?')
            ->execute([$fecha, $taskId, $adminId, self::CONCEPTO_TIEMPO]);
    }

    /* ---------- Formas de salida ---------- */

    public static function adjunto(array $a): array
    {
        $fn = (string)$a['filename'];
        $nombre = (string)($a['orig_name'] ?: $fn);
        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
        return [
            'id' => (int)$a['id'],
            'nombre' => $nombre,
            'filename' => $fn,
            'url' => 'archivo.php?d=tasks&f=' . rawurlencode($fn),
            'mime' => (string)($a['mime'] ?? '') ?: (upload_mime_seguro($ext) ?? ''),
            'es_imagen' => in_array($ext, ArchivosTarea::IMAGENES, true),
            'admin_id' => $a['admin_id'] !== null ? (int)$a['admin_id'] : null,
            'created_at' => (string)($a['created_at'] ?? ''),
        ];
    }

    /** checklist_json → [{texto, done, resp}] limpio. */
    public static function checklistComentario(?string $json): array
    {
        $d = json_decode((string)$json, true);
        if (!is_array($d)) return [];
        $out = [];
        foreach ($d as $it) {
            if (!is_array($it)) continue;
            $t = trim((string)($it['texto'] ?? ''));
            if ($t === '') continue;
            $out[] = ['texto' => $t, 'done' => !empty($it['done']), 'resp' => !empty($it['resp']) ? (int)$it['resp'] : null];
        }
        return $out;
    }
}
