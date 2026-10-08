<?php
namespace Croilab\Modulos\Portal;

use Croilab\Modulos\Clientes\Importes;
use PDO;

/* SQL del portal. TODO filtra por el id de cliente que le pasa el servicio,
   que sale siempre de la sesión del cliente (o de la vista previa del equipo
   ya comprobada con su alcance): nunca de la petición. */
class PortalRepositorio
{
    /* Estados de factura que ve el cliente (nunca borradores; las anuladas sí: tienen número). */
    public const ESTADOS_FACTURA = ['enviada', 'pagada', 'vencida', 'anulada'];

    public function __construct(private readonly PDO $pdo) {}

    public function cliente(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT c.*, t.secciones_json FROM clients c LEFT JOIN client_types t ON t.id = c.tipo_id WHERE c.id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Tareas que ve el cliente: visibles, o de una lista del cliente o de informes. */
    public function tareas(int $clientId): array
    {
        $st = $this->pdo->prepare("SELECT t.id, t.titulo, t.titulo_cliente, t.explicacion_cliente, t.estado, t.prioridad, t.mes, t.due_date,
                                          t.responsable_id, l.nombre AS lista, l.tipo AS lista_tipo
                                   FROM tasks t JOIN task_lists l ON l.id = t.list_id
                                   WHERE t.client_id = ? AND l.client_id = t.client_id
                                     AND (t.visible_cliente = 1 OR l.es_cliente = 1 OR l.tipo = 'informe')
                                   ORDER BY l.orden, t.orden, t.id");
        $st->execute([$clientId]);
        $tareas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$tareas) return [];
        $ids = implode(',', array_map(fn($t) => (int)$t['id'], $tareas));
        $asig = [];
        foreach ($this->pdo->query("SELECT a.task_id, a.admin_id FROM task_assignees a WHERE a.task_id IN ($ids) ORDER BY a.task_id, a.orden, a.admin_id") as $r) {
            $asig[(int)$r['task_id']][] = (int)$r['admin_id'];
        }
        foreach ($tareas as &$t) {
            $t['asignados'] = $asig[(int)$t['id']] ?? ($t['responsable_id'] ? [(int)$t['responsable_id']] : []);
        }
        return $tareas;
    }

    /** Personas del equipo por id: lo justo para el avatar (nombre, color, foto). */
    public function personas(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $out = [];
        $cols = $this->columnas('admins');
        $color = in_array('avatar_color', $cols, true) ? 'a.avatar_color' : "''";
        foreach ($this->pdo->query("SELECT a.id, a.username, $color AS color, p.foto FROM admins a LEFT JOIN admin_profiles p ON p.admin_id = a.id WHERE a.id IN ($in)") as $r) {
            $out[(int)$r['id']] = ['nombre' => (string)$r['username'], 'color' => (string)$r['color'], 'foto' => (string)($r['foto'] ?? '')];
        }
        return $out;
    }

    /** Facturas emitidas del cliente con su total en céntimos (misma regla que Finanzas). */
    public function facturas(int $clientId): array
    {
        $in = "'" . implode("','", self::ESTADOS_FACTURA) . "'";
        $st = $this->pdo->prepare("SELECT i.id, i.numero, i.fecha, i.fecha_venc, i.estado, i.iva_pct, i.irpf_pct, " . Importes::sqlBase('i') . " AS base
                                   FROM invoices i WHERE i.client_id = ? AND i.numero IS NOT NULL AND i.numero <> '' AND i.estado IN ($in)
                                   ORDER BY i.fecha DESC, i.id DESC");
        $st->execute([$clientId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $out[] = ['id' => (int)$f['id'], 'numero' => (string)$f['numero'], 'fecha' => $f['fecha'], 'venc' => $f['fecha_venc'] ?: null,
                      'estado' => (string)$f['estado'], 'total' => Importes::total(Importes::centimos($f['base']), $f['iva_pct'], $f['irpf_pct'])];
        }
        return $out;
    }

    /** Reuniones del CRM del contacto del que salió el cliente. */
    public function reuniones(?int $contactId): array
    {
        if (!$contactId) return [];
        $st = $this->pdo->prepare('SELECT id, fecha, hora, titulo, estado FROM crm_meetings WHERE contact_id = ? ORDER BY fecha DESC, hora DESC, id DESC LIMIT 200');
        $st->execute([$contactId]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'fecha' => $r['fecha'], 'hora' => (string)$r['hora'], 'titulo' => (string)$r['titulo'], 'estado' => (string)$r['estado']],
            $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function tickets(int $clientId): array
    {
        $st = $this->pdo->prepare("SELECT t.id, t.asunto, t.estado, t.created_at, t.updated_at,
                                          (SELECT COUNT(*) FROM support_replies r WHERE r.ticket_id = t.id) AS nresp
                                   FROM support_tickets t WHERE t.client_id = ?
                                   ORDER BY FIELD(t.estado, 'abierto', 'en_curso', 'esperando', 'resuelto', 'cerrado'), t.updated_at DESC, t.id DESC");
        $st->execute([$clientId]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'asunto' => (string)$r['asunto'], 'estado' => (string)$r['estado'], 'respuestas' => (int)$r['nresp'],
                                    'fecha' => $r['created_at'], 'actualizado' => $r['updated_at']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Un ticket del cliente con las respuestas del equipo (null si no es suyo). */
    public function ticket(int $clientId, int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, asunto, cuerpo, estado, created_at, updated_at FROM support_tickets WHERE id = ? AND client_id = ?');
        $st->execute([$id, $clientId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) return null;
        $st = $this->pdo->prepare('SELECT id, admin_id, cuerpo, created_at FROM support_replies WHERE ticket_id = ? ORDER BY id');
        $st->execute([$id]);
        $resp = $st->fetchAll(PDO::FETCH_ASSOC);
        $personas = $this->personas(array_column($resp, 'admin_id'));
        return [
            'id' => (int)$t['id'], 'asunto' => (string)$t['asunto'], 'cuerpo' => (string)$t['cuerpo'], 'estado' => (string)$t['estado'],
            'fecha' => $t['created_at'], 'actualizado' => $t['updated_at'],
            'respuestas' => array_map(fn($r) => ['id' => (int)$r['id'], 'cuerpo' => (string)$r['cuerpo'], 'fecha' => $r['created_at'],
                'autor' => $personas[(int)$r['admin_id']] ?? null], $resp),
        ];
    }

    public function solicitudes(int $clientId): array
    {
        $st = $this->pdo->prepare('SELECT id, fecha_deseada, franja, motivo, estado, created_at FROM portal_meeting_requests WHERE client_id = ? ORDER BY created_at DESC, id DESC LIMIT 100');
        $st->execute([$clientId]);
        return array_map(fn($r) => self::solicitud($r), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function crearSolicitud(int $clientId, ?string $fecha, string $franja, string $motivo): array
    {
        $this->pdo->prepare("INSERT INTO portal_meeting_requests (client_id, fecha_deseada, franja, motivo, estado) VALUES (?, ?, ?, ?, 'pendiente')")
            ->execute([$clientId, $fecha, $franja, $motivo]);
        $id = (int)$this->pdo->lastInsertId();
        $st = $this->pdo->prepare('SELECT id, fecha_deseada, franja, motivo, estado, created_at FROM portal_meeting_requests WHERE id = ?');
        $st->execute([$id]);
        return self::solicitud($st->fetch(PDO::FETCH_ASSOC));
    }

    private static function solicitud(array $r): array
    {
        return ['id' => (int)$r['id'], 'fecha' => $r['fecha_deseada'] ?: null, 'franja' => (string)$r['franja'], 'motivo' => (string)$r['motivo'],
                'estado' => (string)$r['estado'], 'creada' => $r['created_at']];
    }

    /** Credenciales visibles para el cliente, SIN el secreto (se pide aparte, bajo demanda). */
    public function credenciales(int $clientId): array
    {
        $st = $this->pdo->prepare('SELECT id, titulo, categoria, usuario, url, nota, (secreto IS NOT NULL AND secreto <> \'\') AS tiene
                                   FROM client_credentials WHERE client_id = ? AND visible_cliente = 1 ORDER BY orden, id');
        $st->execute([$clientId]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'titulo' => (string)$r['titulo'], 'categoria' => (string)$r['categoria'],
                                    'usuario' => (string)$r['usuario'], 'url' => (string)$r['url'], 'nota' => (string)$r['nota'], 'tiene_secreto' => (bool)$r['tiene']],
            $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** ¿Las tareas del cliente reescriben sus informes / su progreso? (publicar_progreso) */
    public function publicaDesdeTareas(int $clientId): array
    {
        $st = $this->pdo->prepare("SELECT COALESCE(SUM(tipo = 'informe'), 0) AS inf, COALESCE(SUM(es_cliente = 1), 0) AS cli FROM task_lists WHERE client_id = ?");
        $st->execute([$clientId]);
        $l = $st->fetch(PDO::FETCH_ASSOC);
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM tasks WHERE client_id = ? AND visible_cliente = 1');
        $st->execute([$clientId]);
        return ['informes' => (int)$l['inf'] > 0, 'progreso' => (int)$l['cli'] > 0 || (int)$st->fetchColumn() > 0];
    }

    public function tipos(): array
    {
        return array_map(fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre']],
            $this->pdo->query('SELECT id, nombre FROM client_types ORDER BY nombre, id')->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Clientes para el «modo equipo» del acceso, ya filtrados por el alcance (SQL de Acceso). */
    public function clientesEquipo(string $sqlAlcance): array
    {
        return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'activo' => (int)$r['activo'] === 1],
            $this->pdo->query('SELECT c.id, c.name, c.activo FROM clients c WHERE 1' . $sqlAlcance . ' ORDER BY c.activo DESC, c.name')->fetchAll(PDO::FETCH_ASSOC));
    }

    private function columnas(string $tabla): array
    {
        static $cache = [];
        return $cache[$tabla] ??= $this->pdo->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $this->pdo->quote($tabla))->fetchAll(PDO::FETCH_COLUMN);
    }
}
