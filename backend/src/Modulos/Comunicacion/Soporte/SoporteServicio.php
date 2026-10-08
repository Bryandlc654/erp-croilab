<?php
namespace Croilab\Modulos\Comunicacion\Soporte;

use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;

/* Tickets de soporte.

   Permisos (el antiguo usaba `general.editar` para todo y nunca miraba
   `soporte.responder`, que existía en el catálogo):
     · ver: `ver.soporte` (+ alcance de clientes; ver SoporteRepositorio),
     · responder y cambiar el estado: `soporte.responder` («Contestar y cerrar
       los tickets»),
     · crear, asignar, prioridad, cliente y borrar: `general.editar`.
   Fuera de alcance = 404. */
class SoporteServicio
{
    public const ESTADOS = ['abierto', 'en_curso', 'esperando', 'resuelto', 'cerrado'];
    public const PRIORIDADES = [1, 2, 3, 4];

    public function __construct(
        private readonly SoporteRepositorio $tickets,
        private readonly EquipoRepositorio $equipo
    ) {}

    public function listar(Acceso $acc, string $estado, int $cliente): array
    {
        $acc->exigir('ver.soporte');
        if ($estado !== '' && !in_array($estado, self::ESTADOS, true)) throw HttpError::validacion('Estado desconocido.', 'estado');
        $clienteInfo = null;
        if ($cliente > 0) {
            if (!$acc->veCliente($cliente)) throw HttpError::noEncontrado('Cliente no encontrado.');
            $nombre = $this->tickets->nombreCliente($cliente) ?? throw HttpError::noEncontrado('Cliente no encontrado.');
            $clienteInfo = ['id' => $cliente, 'nombre' => $nombre];
        }
        $c = $this->tickets->contadores($acc, $cliente);
        return [
            'items' => array_map(fn($t) => $this->ticket($t), $this->tickets->listar($acc, $estado, $cliente)),
            'contadores' => [
                'abierto' => $c['abierto'] ?? 0,
                'en_curso' => $c['en_curso'] ?? 0,
                'esperando' => $c['esperando'] ?? 0,
                'resuelto' => ($c['resuelto'] ?? 0) + ($c['cerrado'] ?? 0),
                'total' => array_sum($c),
            ],
            'cliente' => $clienteInfo,
            'puede_editar' => $acc->puede('general.editar'),
            'puede_responder' => $acc->puede('soporte.responder'),
        ];
    }

    public function detalle(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.soporte');
        $t = $this->visible($acc, $id);
        return [
            'ticket' => $this->ticket($t),
            'respuestas' => array_map(fn($r) => $this->respuesta($r), $this->tickets->respuestas($id)),
            'puede_editar' => $acc->puede('general.editar'),
            'puede_responder' => $acc->puede('soporte.responder'),
        ];
    }

    public function crear(Acceso $acc, array $d): array
    {
        $acc->exigir('ver.soporte', 'general.editar');
        $asunto = trim((string)($d['asunto'] ?? ''));
        if ($asunto === '') throw HttpError::validacion('Escribe un asunto.', 'asunto');
        if (mb_strlen($asunto) > 200) throw HttpError::validacion('El asunto es demasiado largo (máximo 200).', 'asunto');
        $campos = [
            'asunto' => $asunto,
            'cuerpo' => $this->cuerpo($d['cuerpo'] ?? '', false),
            'client_id' => null, 'prioridad' => 2, 'estado' => 'abierto', 'assignee_id' => null, 'created_by' => $acc->adminId,
        ];
        $campos = $this->validar($acc, $d, ['prioridad', 'assignee_id', 'client_id']) + $campos;
        $id = $this->tickets->crear($campos);
        if ($campos['assignee_id']) $this->avisarAsignado($acc, $id, (int)$campos['assignee_id']);
        return $this->ticket($this->tickets->buscar($id));
    }

    public function actualizar(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('ver.soporte');
        $antes = $this->visible($acc, $id);
        if (array_key_exists('estado', $d)) $acc->exigir('soporte.responder');
        if (array_intersect(array_keys($d), ['prioridad', 'assignee_id', 'client_id'])) $acc->exigir('general.editar');
        $campos = $this->validar($acc, $d, ['estado', 'prioridad', 'assignee_id', 'client_id']);
        if (!$campos) throw HttpError::validacion('No hay nada que cambiar.');
        $this->tickets->actualizar($id, $campos);
        $nuevo = (int)($campos['assignee_id'] ?? 0);
        if (array_key_exists('assignee_id', $campos) && $nuevo && $nuevo !== (int)$antes['assignee_id']) $this->avisarAsignado($acc, $id, $nuevo);
        return $this->ticket($this->tickets->buscar($id));
    }

    public function responder(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('ver.soporte', 'soporte.responder');
        $t = $this->visible($acc, $id);
        $cuerpo = $this->cuerpo($d['cuerpo'] ?? '', true);
        $rid = $this->tickets->responder($id, $acc->adminId, $cuerpo);
        /* Aviso a quien lleva el ticket si responde otra persona (el antiguo no avisaba). */
        $asignado = (int)$t['assignee_id'];
        if ($asignado && $asignado !== $acc->adminId && function_exists('notif_add')) {
            notif_add($asignado, 'ticket', 'ha respondido en un ticket que llevas', self::extracto($cuerpo, 160), '/soporte/' . $id,
                      "tkreply:$rid:$asignado", (string)$t['asunto'], $this->equipo->persona($acc->adminId)['username']);
        }
        return ['respuesta' => $this->respuesta($this->tickets->respuesta($rid)), 'ticket' => $this->ticket($this->tickets->buscar($id))];
    }

    /** A la papelera con sus respuestas. Devuelve el id de papelera (para «Deshacer»). */
    public function borrar(Acceso $acc, int $id): int
    {
        $acc->exigir('ver.soporte', 'general.editar');
        $t = $this->visible($acc, $id);
        $pid = pap_borrar('support_tickets', $id, 'ticket', (string)$t['asunto'], [['tabla' => 'support_replies', 'fk' => 'ticket_id']]);
        if (!$pid) throw new HttpError(500, 'No se ha podido mover el ticket a la papelera.', 'papelera');
        return $pid;
    }

    /** «Deshacer» del borrado: quien borró, o quien tiene permiso de papelera. */
    public function restaurar(Acceso $acc, int $papeleraId): int
    {
        $acc->exigir('ver.soporte', 'general.editar');
        $e = pap_elemento($papeleraId);
        if (!$e || $e['tipo'] !== 'ticket') throw HttpError::noEncontrado('Eso ya no está en la papelera.');
        if ((int)$e['admin_id'] !== $acc->adminId) $acc->exigir('papelera.restaurar');
        $r = pap_restaurar($papeleraId);
        if (empty($r['ok'])) throw new HttpError(409, $r['msg'] ?? 'No se ha podido restaurar.', 'conflicto');
        return (int)($r['id'] ?? 0);
    }

    public function clientes(Acceso $acc): array
    {
        $acc->exigir('ver.soporte');
        return $this->tickets->clientes($acc);
    }

    /**
     * Ticket que abre un cliente desde su portal (para el módulo Portal). Sin
     * creador (created_by NULL), prioridad Normal, y —esto el antiguo no lo
     * hacía— aviso a quien tiene acceso total.
     */
    public function crearDesdePortal(int $clientId, string $asunto, string $cuerpo, string $nombreCliente): int
    {
        $cuerpo = trim($cuerpo);
        if ($cuerpo === '') throw HttpError::validacion('Escribe tu mensaje.', 'cuerpo');
        $asunto = trim($asunto) !== '' ? trim($asunto) : 'Mensaje de ' . $nombreCliente;
        $id = $this->tickets->crear([
            'asunto' => mb_substr($asunto, 0, 200), 'cuerpo' => mb_substr($cuerpo, 0, 20000), 'client_id' => $clientId,
            'prioridad' => 2, 'estado' => 'abierto', 'assignee_id' => null, 'created_by' => null,
        ]);
        if (function_exists('notif_duenos') && function_exists('notif_add')) {
            foreach (notif_duenos() as $uid) {
                notif_add($uid, 'ticket', 'ha abierto un ticket desde su portal', $nombreCliente, '/soporte/' . $id, "tknew:$id:$uid", mb_substr($asunto, 0, 200), $nombreCliente);
            }
        }
        return $id;
    }

    /* ---------- Internos ---------- */

    private function visible(Acceso $acc, int $id): array
    {
        $t = $id > 0 && $this->tickets->visible($acc, $id) ? $this->tickets->buscar($id) : null;
        return $t ?? throw HttpError::noEncontrado('Ticket no encontrado.');
    }

    /** Valida los campos editables que vengan en $d (solo los de $permitidos). */
    private function validar(Acceso $acc, array $d, array $permitidos): array
    {
        $c = [];
        if (in_array('estado', $permitidos, true) && array_key_exists('estado', $d)) {
            if (!in_array($d['estado'], self::ESTADOS, true)) throw HttpError::validacion('Estado desconocido.', 'estado');
            $c['estado'] = $d['estado'];
        }
        if (in_array('prioridad', $permitidos, true) && array_key_exists('prioridad', $d)) {
            $p = filter_var($d['prioridad'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]]);
            if ($p === false) throw HttpError::validacion('La prioridad va de 1 (Baja) a 4 (Urgente).', 'prioridad');
            $c['prioridad'] = $p;
        }
        if (in_array('assignee_id', $permitidos, true) && array_key_exists('assignee_id', $d)) {
            $a = (int)($d['assignee_id'] ?? 0);
            if ($a && !in_array($a, array_column($this->equipo->activos(), 'id'), true)) throw HttpError::validacion('Esa persona no está en el equipo.', 'assignee_id');
            $c['assignee_id'] = $a ?: null;
        }
        if (in_array('client_id', $permitidos, true) && array_key_exists('client_id', $d)) {
            $cli = (int)($d['client_id'] ?? 0);
            if ($cli && (!$acc->veCliente($cli) || $this->tickets->nombreCliente($cli) === null)) throw HttpError::validacion('Cliente no encontrado.', 'client_id');
            $c['client_id'] = $cli ?: null;
        }
        return $c;
    }

    private function cuerpo(mixed $v, bool $obligatorio): string
    {
        $t = trim(str_replace(["\r\n", "\r"], "\n", (string)$v));
        if ($obligatorio && $t === '') throw HttpError::validacion('Escribe una respuesta.', 'cuerpo');
        if (mb_strlen($t) > 20000) throw HttpError::validacion('El texto es demasiado largo.', 'cuerpo');
        return $t;
    }

    private function avisarAsignado(Acceso $acc, int $ticketId, int $uid): void
    {
        if ($uid === $acc->adminId || !function_exists('notif_ticket_assigned')) return;
        notif_ticket_assigned($ticketId, $uid, $this->equipo->persona($acc->adminId)['username']);
    }

    private function ticket(array $t): array
    {
        $asig = $t['assignee_id'] !== null ? (int)$t['assignee_id'] : null;
        $creador = $t['created_by'] !== null ? (int)$t['created_by'] : null;
        return [
            'id' => (int)$t['id'],
            'asunto' => (string)$t['asunto'],
            'cuerpo' => (string)($t['cuerpo'] ?? ''),
            'client_id' => $t['client_id'] !== null ? (int)$t['client_id'] : null,
            'cliente' => $t['cliente'] !== null ? (string)$t['cliente'] : null,
            'prioridad' => in_array((int)$t['prioridad'], self::PRIORIDADES, true) ? (int)$t['prioridad'] : 2,
            'estado' => in_array($t['estado'], self::ESTADOS, true) ? (string)$t['estado'] : 'abierto',
            'asignado' => $asig ? $this->equipo->persona($asig) : null,
            'creador' => $creador ? $this->equipo->persona($creador) : null,
            'desde_portal' => $creador === null,
            'creado' => (string)$t['created_at'],
            'actualizado' => (string)$t['updated_at'],
            'respuestas' => (int)$t['respuestas'],
        ];
    }

    private function respuesta(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'autor' => $r['admin_id'] !== null ? $this->equipo->persona((int)$r['admin_id']) : null,
            'cuerpo' => (string)$r['cuerpo'],
            'creado' => (string)$r['created_at'],
        ];
    }

    private static function extracto(string $t, int $n): string
    {
        $t = trim((string)preg_replace('/\s+/u', ' ', $t));
        return mb_strlen($t) > $n ? mb_substr($t, 0, $n - 1) . '…' : $t;
    }
}
