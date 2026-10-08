<?php
namespace Croilab\Modulos\Comunicacion\Reuniones;

use Croilab\Http\HttpError;
use Croilab\Modulos\Comunicacion\Alcance;
use Croilab\Modulos\Comunicacion\Calendario\CalendarioServicio;
use Croilab\Modulos\Comunicacion\Calendario\GoogleCalendario;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;
use PDO;

/* Reuniones: las de Google Calendar (con invitados, Meet o notas de Gemini),
   emparejadas con su contacto o cliente; las solicitudes que llegan del portal;
   y «agendar» (lo que hacía agendar.php: reunión en el CRM + evento en Google,
   enlazados por extendedProperties.private.erp_meeting).

   Permisos: ver con `ver.agenda`; agendar, asignar contacto y resolver
   solicitudes con `general.editar` (el antiguo: require_can_edit). Los
   contactos y clientes respetan el alcance: lo que no ves no sale emparejado
   ni se puede elegir. */
class ReunionesServicio
{
    private const ATRAS = 90;
    private const ADELANTE = 60;
    /* Grupos del selector de contactos por tipo de fase del embudo (como el antiguo). */
    private const GRUPOS = ['ganada' => 'Clientes activos', 'abierta' => 'Potenciales', 'pausa' => 'En pausa', 'perdida' => 'Cerrados perdidos', '' => 'Otros'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly GoogleCalendario $gcal,
        private readonly EquipoRepositorio $equipo
    ) {}

    /** @param string $vista 'me' | 'all' | id de un compañero */
    public function listar(Acceso $acc, string $vista): array
    {
        $acc->exigir('ver.agenda');
        $yo = $acc->adminId;
        $estado = $this->gcal->estado($yo);
        $activos = array_column($this->equipo->activos(), 'id');
        $cuentas = [];
        foreach ($this->gcal->cuentas() as $id) {
            if (in_array($id, $activos, true)) $cuentas[] = ['id' => $id, 'username' => $this->equipo->persona($id)['username']];
        }
        $ids = array_column($cuentas, 'id');
        $multi = count($cuentas) > 1;
        if (!$multi || ($vista !== 'all' && !in_array((int)$vista, $ids, true))) $vista = 'me';

        $fuentes = match (true) {
            $vista === 'all' => $ids,
            $vista !== 'me' => [(int)$vista],
            default => $estado['configurado'] && $estado['conectado'] && !$estado['revocado'] ? [$yo] : [],
        };
        $hoy = new \DateTimeImmutable('today', new \DateTimeZone(GoogleCalendario::ZONA));
        $desde = $hoy->modify('-' . self::ATRAS . ' days')->format('Y-m-d');
        $hasta = $hoy->modify('+' . self::ADELANTE . ' days')->format('Y-m-d');

        $eventos = [];
        $avisos = [];
        foreach ($fuentes as $sid) {
            try {
                foreach ($this->gcal->reuniones($sid, $desde, $hasta) as $ev) {
                    $k = $ev['id'] !== '' ? $ev['id'] : $ev['titulo'] . '|' . $ev['dia'] . $ev['hora'];
                    if (isset($eventos[$k])) continue;
                    if ($sid !== $yo) $ev['editable'] = false;
                    $ev['owner'] = $vista === 'all' ? $this->equipo->persona($sid) : null;
                    $eventos[$k] = $ev;
                }
            } catch (HttpError $e) {
                if ($sid === $yo && $e->codigo === 'google_revocado') $estado['revocado'] = true;
                $avisos[] = $sid === $yo ? $e->getMessage() : 'No se han podido leer las reuniones de ' . $this->equipo->persona($sid)['username'] . '.';
            }
        }
        $eventos = $this->emparejar($acc, array_values($eventos));

        $diaHoy = $hoy->format('Y-m-d');
        $proximas = array_values(array_filter($eventos, fn($e) => $e['dia'] >= $diaHoy));
        $pasadas = array_reverse(array_values(array_filter($eventos, fn($e) => $e['dia'] < $diaHoy)));
        $notas = array_reverse(array_values(array_filter($eventos, fn($e) => (bool)$e['docs'])));

        return [
            'google' => $estado,
            'cuentas' => $cuentas,
            'vista' => $vista,
            'proximas' => $proximas,
            'pasadas' => $pasadas,
            'notas' => $notas,
            'solicitudes' => $this->solicitudes($acc),
            'meeting_url' => trim((string)get_setting('meeting_url', '')),
            'puede_editar' => $acc->puede('general.editar'),
            'avisos' => $avisos,
        ];
    }

    /**
     * Contacto/cliente de cada reunión: la asignación a mano manda; si no, el
     * correo de un invitado que sea de un contacto; si no, el correo de
     * facturación de un cliente.
     */
    private function emparejar(Acceso $acc, array $eventos): array
    {
        if (!$eventos) return [];
        $manual = [];
        $evIds = array_values(array_filter(array_column($eventos, 'id')));
        if ($evIds) {
            $st = $this->pdo->prepare('SELECT rc.event_id, c.id, c.nombre, c.empresa, c.client_id FROM reunion_cliente rc JOIN contacts c ON c.id = rc.contact_id
                                        WHERE rc.event_id IN (' . implode(',', array_fill(0, count($evIds), '?')) . ')' . Alcance::sqlContactos($acc, 'c'));
            try {
                $st->execute($evIds);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $manual[(string)$r['event_id']] = $r;
            } catch (\PDOException $e) {
                /* Sin CRM, no hay asignaciones. */
            }
        }
        $correos = array_values(array_unique(array_merge(...array_map(fn($e) => $e['invitados'], $eventos))));
        $porCorreo = [];
        $cliPorCorreo = [];
        if ($correos) {
            $in = implode(',', array_fill(0, count($correos), '?'));
            try {
                $st = $this->pdo->prepare("SELECT id, nombre, empresa, client_id, LOWER(TRIM(email)) AS em FROM contacts c WHERE LOWER(TRIM(c.email)) IN ($in)" . Alcance::sqlContactos($acc, 'c') . ' ORDER BY id');
                $st->execute($correos);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $porCorreo[(string)$r['em']] ??= $r;
            } catch (\PDOException $e) {
            }
            $st = $this->pdo->prepare("SELECT id, name, LOWER(TRIM(fact_email)) AS em FROM clients WHERE LOWER(TRIM(fact_email)) IN ($in)" . $acc->sqlClientes('id') . ' ORDER BY id');
            $st->execute($correos);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $cliPorCorreo[(string)$r['em']] ??= $r;
        }
        $contacto = fn(array $r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre'], 'empresa' => (string)($r['empresa'] ?? ''), 'client_id' => $r['client_id'] !== null ? (int)$r['client_id'] : null];
        foreach ($eventos as &$e) {
            $e['contacto'] = null;
            $e['cliente'] = null;
            $e['emparejado'] = null;
            if (isset($manual[$e['id']])) {
                $e['contacto'] = $contacto($manual[$e['id']]);
                $e['emparejado'] = 'manual';
                continue;
            }
            foreach ($e['invitados'] as $em) if (isset($porCorreo[$em])) { $e['contacto'] = $contacto($porCorreo[$em]); $e['emparejado'] = 'contacto'; break; }
            if ($e['contacto']) continue;
            foreach ($e['invitados'] as $em) if (isset($cliPorCorreo[$em])) { $e['cliente'] = ['id' => (int)$cliPorCorreo[$em]['id'], 'nombre' => (string)$cliPorCorreo[$em]['name']]; $e['emparejado'] = 'cliente'; break; }
        }
        unset($e);
        return $eventos;
    }

    /** Solicitudes pendientes del portal (de clientes visibles), las más nuevas primero. */
    public function solicitudes(Acceso $acc): array
    {
        $acc->exigir('ver.agenda');
        $st = $this->pdo->query("SELECT r.id, r.client_id, r.fecha_deseada, r.franja, r.motivo, r.created_at, c.name AS cliente, c.fact_email, c.contact_id
                                 FROM portal_meeting_requests r JOIN clients c ON c.id = r.client_id
                                 WHERE r.estado = 'pendiente'" . $acc->sqlClientes('r.client_id') . ' ORDER BY r.id DESC LIMIT 100');
        return array_map(fn($r) => [
            'id' => (int)$r['id'], 'client_id' => (int)$r['client_id'], 'cliente' => (string)$r['cliente'],
            'email' => (string)$r['fact_email'], 'contact_id' => $r['contact_id'] ? (int)$r['contact_id'] : null,
            'fecha_deseada' => $r['fecha_deseada'] ?: null, 'franja' => (string)$r['franja'], 'motivo' => (string)($r['motivo'] ?? ''),
            'creado' => (string)$r['created_at'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Asigna (o quita, con null) el contacto de una reunión de Google. */
    public function asignar(Acceso $acc, string $eventId, ?int $contactId): void
    {
        $acc->exigir('ver.agenda', 'general.editar');
        $eventId = trim($eventId);
        if ($eventId === '' || strlen($eventId) > 255 || !preg_match('/^[A-Za-z0-9_\-]+$/', $eventId)) throw HttpError::validacion('Falta la reunión.', 'event_id');
        if ($contactId) {
            if (!Alcance::veContacto($this->pdo, $acc, $contactId)) throw HttpError::validacion('Contacto no encontrado.', 'contact_id');
            $this->pdo->prepare('INSERT INTO reunion_cliente (event_id, contact_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE contact_id = VALUES(contact_id)')->execute([$eventId, $contactId]);
        } else {
            $this->pdo->prepare('DELETE FROM reunion_cliente WHERE event_id = ?')->execute([$eventId]);
        }
    }

    /** Contactos para el selector de «asignar», agrupados por tipo de fase. */
    public function contactos(Acceso $acc, string $q): array
    {
        $acc->exigir('ver.agenda');
        $tipos = [];
        try {
            foreach ($this->pdo->query('SELECT slug, nombre, tipo FROM pipeline_stages') as $s) $tipos[(string)$s['slug']] = [(string)$s['tipo'], (string)$s['nombre']];
            $sql = 'SELECT c.id, c.nombre, c.empresa, c.fase, c.email FROM contacts c WHERE 1=1' . Alcance::sqlContactos($acc, 'c');
            $p = [];
            if (trim($q) !== '') {
                $sql .= ' AND (c.nombre LIKE ? OR c.empresa LIKE ? OR c.email LIKE ?)';
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($q)) . '%';
                $p = [$like, $like, $like];
            }
            $st = $this->pdo->prepare($sql . ' ORDER BY c.nombre LIMIT 400');
            $st->execute($p);
            $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
        $grupos = array_fill_keys(array_keys(self::GRUPOS), []);
        foreach ($filas as $c) {
            [$tipo, $fase] = $tipos[(string)$c['fase']] ?? ['', ''];
            if (!isset($grupos[$tipo])) $tipo = '';
            $grupos[$tipo][] = ['id' => (int)$c['id'], 'nombre' => (string)$c['nombre'], 'empresa' => (string)($c['empresa'] ?? ''), 'fase' => $fase, 'email' => (string)($c['email'] ?? '')];
        }
        $out = [];
        foreach ($grupos as $t => $items) if ($items) $out[] = ['grupo' => self::GRUPOS[$t], 'items' => $items];
        return $out;
    }

    /**
     * Datos para prellenar «Agendar reunión» desde un cliente y/o un contacto
     * (?nuevo=1&cli=N&contacto=N): nombre, correo, WhatsApp y el contacto a enlazar.
     */
    public function destinatario(Acceso $acc, int $cli, int $contacto): array
    {
        $acc->exigir('ver.agenda');
        $out = ['nombre' => '', 'email' => '', 'whatsapp' => '', 'contact_id' => null, 'client_id' => null];
        $out = $this->rellenarDestinatario($acc, $cli, $contacto, $out);
        /* Lo que necesita el modal además del destinatario: el enlace de reservas
           («Que elija el cliente») y si se creará también en Google. */
        return $out + ['meeting_url' => trim((string)get_setting('meeting_url', '')), 'google' => $this->gcal->disponible($acc->adminId)];
    }

    private function rellenarDestinatario(Acceso $acc, int $cli, int $contacto, array $out): array
    {
        if ($cli > 0) {
            if (!$acc->veCliente($cli)) throw HttpError::noEncontrado('Cliente no encontrado.');
            $st = $this->pdo->prepare('SELECT id, name, fact_email, fact_tel, contact_id FROM clients WHERE id = ?');
            $st->execute([$cli]);
            $c = $st->fetch(PDO::FETCH_ASSOC) ?: throw HttpError::noEncontrado('Cliente no encontrado.');
            $out = ['nombre' => (string)$c['name'], 'email' => (string)$c['fact_email'], 'whatsapp' => (string)$c['fact_tel'], 'contact_id' => null, 'client_id' => (int)$c['id']];
            if (!$contacto && $c['contact_id']) $contacto = (int)$c['contact_id'];
        }
        if ($contacto > 0) {
            if (!Alcance::veContacto($this->pdo, $acc, $contacto)) {
                if ($cli > 0) return $out;   // el del cliente puede no ser visible: se queda con los datos del cliente
                throw HttpError::noEncontrado('Contacto no encontrado.');
            }
            $st = $this->pdo->prepare('SELECT id, nombre, empresa, email, whatsapp, telefono, client_id FROM contacts WHERE id = ?');
            $st->execute([$contacto]);
            if ($k = $st->fetch(PDO::FETCH_ASSOC)) {
                $out['contact_id'] = (int)$k['id'];
                if ($out['nombre'] === '') $out['nombre'] = (string)$k['nombre'];
                if (trim((string)$k['email']) !== '') $out['email'] = (string)$k['email'];
                $wa = trim((string)($k['whatsapp'] ?: $k['telefono']));
                if ($wa !== '') $out['whatsapp'] = $wa;
                if (!$out['client_id'] && $k['client_id']) $out['client_id'] = (int)$k['client_id'];
            }
        }
        return $out;
    }

    /**
     * Agendar una reunión (sustituye a agendar.php y al «crear» de reuniones.php):
     *   · con contacto: reunión en crm_meetings (ahora también con su título),
     *     actividad en el CRM y «último contacto» = hoy,
     *   · con Google conectado: evento enlazado (erp_meeting) con Meet, invitados…
     *   · con `req_id`: aprueba esa solicitud del portal y, si no hay invitados,
     *     invita al correo de facturación del cliente.
     * @return array{msg:string, mid:?int, evento:?array, google:bool}
     */
    public function agendar(Acceso $acc, array $d): array
    {
        $acc->exigir('ver.agenda', 'general.editar');
        $titulo = trim((string)($d['titulo'] ?? ''));
        if ($titulo === '') throw HttpError::validacion('Escribe un título.', 'titulo');
        $fecha = self::fechaIso((string)($d['fecha'] ?? ''));
        if ($fecha === '') throw HttpError::validacion('Elige una fecha.', 'fecha');
        $d['fecha'] = $fecha;
        $hora = trim((string)($d['hora'] ?? ''));
        if ($hora !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) throw HttpError::validacion('Hora no válida.', 'hora');

        $contactId = (int)($d['contact_id'] ?? 0);
        if ($contactId && !Alcance::veContacto($this->pdo, $acc, $contactId)) throw HttpError::validacion('Contacto no encontrado.', 'contact_id');
        $solicitud = null;
        $reqId = (int)($d['req_id'] ?? 0);
        if ($reqId) {
            $solicitud = $this->solicitudPendiente($acc, $reqId);
            if (!$contactId && $solicitud['contact_id'] && $this->existeContacto((int)$solicitud['contact_id'])) $contactId = (int)$solicitud['contact_id'];
            if (!GoogleCalendario::correos($d['invitados'] ?? '') && $solicitud['email'] !== '') { $d['invitados'] = $solicitud['email']; $d['notificar'] = true; }
        }

        $google = $this->gcal->disponible($acc->adminId);
        if (!$contactId && !$solicitud && !$google) {
            /* Sin contacto ni Google no quedaría nada guardado. */
            throw new HttpError(409, 'Conecta Google Calendar para crear la reunión (o agéndala desde la ficha de un contacto del CRM).', 'google_sin_conectar');
        }

        $mid = null;
        $this->pdo->beginTransaction();
        try {
            if ($contactId) {
                $this->pdo->prepare("INSERT INTO crm_meetings (contact_id, fecha, hora, titulo, estado) VALUES (?, ?, ?, ?, 'agendada')")
                    ->execute([$contactId, $fecha, $hora, mb_substr($titulo, 0, 200)]);
                $mid = (int)$this->pdo->lastInsertId();
                $this->pdo->prepare("INSERT INTO activities (contact_id, deal_id, tipo, descripcion, fecha) VALUES (?, NULL, 'reunion', ?, NOW())")
                    ->execute([$contactId, mb_substr('Reunión agendada para ' . date('d/m/Y', strtotime($fecha)) . ($hora !== '' ? ' ' . $hora : ''), 0, 255)]);
                $this->pdo->prepare('UPDATE contacts SET fecha_ultimo_contacto = CURDATE() WHERE id = ?')->execute([$contactId]);
            }
            if ($solicitud) {
                $this->pdo->prepare("UPDATE portal_meeting_requests SET estado = 'aprobada', meeting_id = ? WHERE id = ?")->execute([$mid, $reqId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $evento = null;
        $msg = 'Reunión agendada.';
        if ($google) {
            $o = CalendarioServicio::opciones($d);
            if ($mid) $o['erp_meeting'] = $mid;
            if (!array_key_exists('meet', $o)) $o['meet'] = true;   // por defecto con Meet, como agendar.php
            try {
                $r = $this->gcal->crear($acc->adminId, $o);
                $evento = $r['evento'];
                $msg = $r['msg'];
            } catch (HttpError $e) {
                if (!$mid && !$solicitud) throw $e;   // no se ha guardado nada: que se vea el error
                $msg = 'Reunión guardada, pero Google dio un aviso: ' . $e->getMessage();
            }
        } else {
            $msg = 'Reunión guardada. (Conecta Google Calendar en Integraciones para crear el evento.)';
        }
        if ($solicitud) {
            if ($mid) $msg = $evento ? 'Reunión agendada y avisado el cliente.' : 'Reunión agendada (el aviso de Google falló, pero el cliente ya la ve en su portal).';
            else $msg = 'Solicitud aprobada. Ese cliente no tiene contacto en el CRM: agenda la reunión a mano en su ficha.';
        }
        if (function_exists('audit_log')) audit_log('reunion.agendar', mb_substr($titulo, 0, 120) . ($mid ? " #$mid" : ''));
        return ['msg' => $msg, 'mid' => $mid, 'evento' => $evento, 'google' => $evento !== null];
    }

    /**
     * Aprobar una solicitud del portal. Con `evento` (los datos del modal) se
     * agenda como cualquier reunión; sin él, como el antiguo «aprobar» rápido:
     * reunión en el CRM con la fecha que pidió el cliente.
     */
    public function aprobar(Acceso $acc, int $reqId, array $d): array
    {
        $acc->exigir('ver.agenda', 'general.editar');
        if (is_array($d['evento'] ?? null)) return $this->agendar($acc, ['req_id' => $reqId] + $d['evento']);
        $s = $this->solicitudPendiente($acc, $reqId);
        $mid = null;
        if ($s['contact_id'] && $this->existeContacto((int)$s['contact_id'])) {
            $this->pdo->prepare("INSERT INTO crm_meetings (contact_id, fecha, hora, titulo, estado) VALUES (?, ?, '', ?, 'agendada')")
                ->execute([(int)$s['contact_id'], $s['fecha_deseada'] ?: date('Y-m-d'), mb_substr($s['motivo'] !== '' ? $s['motivo'] : 'Reunión solicitada por el cliente', 0, 200)]);
            $mid = (int)$this->pdo->lastInsertId();
        }
        $this->pdo->prepare("UPDATE portal_meeting_requests SET estado = 'aprobada', meeting_id = ? WHERE id = ?")->execute([$mid, $reqId]);
        return ['msg' => $mid ? 'Reunión aprobada y agendada.' : 'Solicitud aprobada (ese cliente no tiene contacto en el CRM: agéndala a mano en su ficha).', 'mid' => $mid, 'evento' => null, 'google' => false];
    }

    public function rechazar(Acceso $acc, int $reqId): void
    {
        $acc->exigir('ver.agenda', 'general.editar');
        $this->solicitudPendiente($acc, $reqId);
        $this->pdo->prepare("UPDATE portal_meeting_requests SET estado = 'rechazada' WHERE id = ?")->execute([$reqId]);
    }

    private function solicitudPendiente(Acceso $acc, int $id): array
    {
        foreach ($this->solicitudes($acc) as $s) if ($s['id'] === $id) return $s;
        throw HttpError::noEncontrado('Esa solicitud ya no está pendiente.');
    }

    private function existeContacto(int $id): bool
    {
        try {
            $st = $this->pdo->prepare('SELECT 1 FROM contacts WHERE id = ?');
            $st->execute([$id]);
            return (bool)$st->fetchColumn();
        } catch (\PDOException $e) {
            return false;
        }
    }

    /** dd/mm/aaaa o aaaa-mm-dd → aaaa-mm-dd ('' si no vale). */
    public static function fechaIso(string $f): string
    {
        $f = trim($f);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $f, $m)) $f = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        return GoogleCalendario::fechaValida($f) ? $f : '';
    }
}
