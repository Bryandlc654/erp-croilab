<?php
namespace Croilab\Modulos\Clientes;

use Croilab\Database\Esquema;
use PDO;

/* Lo de otros módulos que enseña la ficha del cliente (client.php): listas de
   trabajo, facturas, tickets, credenciales, contacto de origen y reuniones.
   Solo lectura y siempre de UN cliente que ya se ha comprobado que se ve. */
class FichaRepositorio
{
    public function __construct(private readonly PDO $pdo) {}

    /** Listas de trabajo con su nº de tareas y las abiertas. */
    public function listas(int $clientId): array
    {
        $st = $this->pdo->prepare("SELECT l.id, l.nombre, l.tipo, l.es_cliente,
                                          COUNT(t.id) AS cnt, COALESCE(SUM(t.estado <> 'completada'), 0) AS pend
                                     FROM task_lists l LEFT JOIN tasks t ON t.list_id = l.id
                                    WHERE l.client_id = ? GROUP BY l.id, l.nombre, l.tipo, l.es_cliente, l.orden ORDER BY l.orden, l.id");
        $st->execute([$clientId]);
        return array_map(fn($l) => [
            'id' => (int)$l['id'], 'nombre' => (string)$l['nombre'], 'tipo' => (string)($l['tipo'] ?: 'tareas'),
            'es_cliente' => (int)$l['es_cliente'] === 1, 'cnt' => (int)$l['cnt'], 'pend' => (int)$l['pend'],
        ], $st->fetchAll());
    }

    /**
     * Todas las facturas para los totales y las 5 últimas para la lista.
     * @return array{n:int, cobrado:int, pendiente:int, ultimas:list<array>}
     */
    public function facturas(int $clientId): array
    {
        $st = $this->pdo->prepare('SELECT i.id, i.numero, i.fecha, i.estado, i.iva_pct, i.irpf_pct, ' . Importes::sqlBase('i') . ' AS base
                                     FROM invoices i WHERE i.client_id = ? ORDER BY i.fecha DESC, i.id DESC');
        $st->execute([$clientId]);
        $out = ['n' => 0, 'cobrado' => 0, 'pendiente' => 0, 'ultimas' => []];
        foreach ($st as $f) {
            $total = Importes::total(Importes::centimos($f['base']), $f['iva_pct'], $f['irpf_pct']);
            $estado = (string)($f['estado'] ?: 'borrador');
            $out['n']++;
            /* Borradores (sin número) y anuladas (la original y su rectificativa
               se compensan) no son ni cobro ni deuda. */
            if ($estado === 'pagada') $out['cobrado'] += $total;
            elseif ($estado !== 'borrador' && $estado !== 'anulada' && $f['numero'] !== null) $out['pendiente'] += $total;
            if (count($out['ultimas']) < 5) {
                $out['ultimas'][] = ['id' => (int)$f['id'], 'numero' => (string)$f['numero'], 'fecha' => $f['fecha'] ?: null, 'estado' => $estado, 'total' => $total];
            }
        }
        return $out;
    }

    /** Tickets: abiertos y los 4 primeros (abierto→cerrado, prioridad, recientes). */
    public function tickets(int $clientId): array
    {
        $st = $this->pdo->prepare("SELECT id, asunto, estado, prioridad, created_at, updated_at FROM support_tickets WHERE client_id = ?
                                    ORDER BY FIELD(estado,'abierto','en_curso','esperando','resuelto','cerrado'), prioridad DESC, updated_at DESC, id DESC");
        $st->execute([$clientId]);
        $abiertos = 0;
        $items = [];
        foreach ($st as $t) {
            $estado = (string)($t['estado'] ?: 'abierto');
            if (in_array($estado, ['abierto', 'en_curso', 'esperando'], true)) $abiertos++;
            if (count($items) < 4) {
                $items[] = ['id' => (int)$t['id'], 'asunto' => (string)$t['asunto'], 'estado' => $estado,
                            'prioridad' => max(1, min(4, (int)$t['prioridad'])), 'fecha' => substr((string)$t['created_at'], 0, 10) ?: null];
            }
        }
        return ['abiertos' => $abiertos, 'items' => $items];
    }

    /** Credenciales SIN el secreto: solo si hay uno guardado. Las 6 primeras. */
    public function credenciales(int $clientId): array
    {
        $st = $this->pdo->prepare("SELECT id, titulo, categoria, usuario, url, (secreto IS NOT NULL AND secreto <> '') AS tiene
                                     FROM client_credentials WHERE client_id = ? ORDER BY orden, id");
        $st->execute([$clientId]);
        $filas = $st->fetchAll();
        return [
            'total' => count($filas),
            'items' => array_map(fn($c) => [
                'id' => (int)$c['id'], 'titulo' => (string)$c['titulo'], 'categoria' => (string)($c['categoria'] ?: 'other'),
                'usuario' => (string)$c['usuario'], 'url' => (string)$c['url'], 'tiene_secreto' => (bool)$c['tiene'],
            ], array_slice($filas, 0, 6)),
        ];
    }

    /** El secreto guardado (en claro o cifrado por la bóveda), o null si no es de ese cliente. */
    public function secreto(int $clientId, int $credId): ?string
    {
        $st = $this->pdo->prepare('SELECT secreto FROM client_credentials WHERE id = ? AND client_id = ?');
        $st->execute([$credId, $clientId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    /** Contacto del CRM del que salió (clients.contact_id) o el primero que apunte a él. */
    public function contacto(int $clientId, ?int $contactId): ?array
    {
        if (!Esquema::tablaExiste($this->pdo, 'contacts')) return null;
        $c = null;
        if ($contactId) {
            $st = $this->pdo->prepare('SELECT id, nombre, empresa, email, telefono, whatsapp, origen_lead FROM contacts WHERE id = ?');
            $st->execute([$contactId]);
            $c = $st->fetch() ?: null;
        }
        if (!$c) {
            $st = $this->pdo->prepare('SELECT id, nombre, empresa, email, telefono, whatsapp, origen_lead FROM contacts WHERE client_id = ? ORDER BY id LIMIT 1');
            $st->execute([$clientId]);
            $c = $st->fetch() ?: null;
        }
        if (!$c) return null;
        return [
            'id' => (int)$c['id'], 'nombre' => (string)$c['nombre'], 'empresa' => (string)$c['empresa'], 'email' => (string)$c['email'],
            'telefono' => (string)$c['telefono'], 'whatsapp' => (string)$c['whatsapp'], 'origen_lead' => (string)$c['origen_lead'],
        ];
    }

    /** Próximas reuniones del contacto (crm_meetings) y solicitudes del portal sin contestar. */
    public function reuniones(int $clientId, ?int $contactId, string $hoy): array
    {
        $proximas = [];
        if ($contactId && Esquema::tablaExiste($this->pdo, 'crm_meetings')) {
            $st = $this->pdo->prepare("SELECT id, fecha, hora, titulo, estado FROM crm_meetings
                                        WHERE contact_id = ? AND fecha >= ? AND COALESCE(estado,'agendada') NOT IN ('cancelada','realizada')
                                        ORDER BY fecha, hora LIMIT 3");
            $st->execute([$contactId, $hoy]);
            foreach ($st as $r) {
                $proximas[] = ['id' => (int)$r['id'], 'fecha' => (string)$r['fecha'], 'hora' => (string)$r['hora'], 'titulo' => (string)$r['titulo'], 'estado' => (string)($r['estado'] ?: 'agendada')];
            }
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM portal_meeting_requests WHERE client_id = ? AND estado = 'pendiente'");
        $st->execute([$clientId]);
        return ['proximas' => $proximas, 'solicitudes' => (int)$st->fetchColumn()];
    }
}
