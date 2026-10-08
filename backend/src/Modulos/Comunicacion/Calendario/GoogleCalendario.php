<?php
namespace Croilab\Modulos\Comunicacion\Calendario;

use Croilab\Google\Cuenta;
use Croilab\Google\ErrorGoogle;
use Croilab\Google\GoogleOAuth;
use Croilab\Http\HttpError;

/* Google Calendar de cada persona (calendars/primary), sobre GoogleOAuth /
   ClienteGoogle (src/Google, de Equipo). Sustituye a las funciones gcal_* de
   admin/lib/gcal.php con lo mismo que hacían, más:
     · zona horaria explícita (Europe/Madrid) al pedir y al crear: el antiguo
       pedía el rango en UTC («Z») y pintaba las horas en la zona del servidor,
     · los errores de Google llegan como HttpError con su mensaje,
     · el fin de los eventos de día completo (exclusivo en Google) se devuelve
       como último día incluido. */
class GoogleCalendario
{
    public const ZONA = 'Europe/Madrid';
    public const EVENTOS = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
    public const RECURRENCIAS = ['DAILY', 'WEEKLY', 'MONTHLY'];
    public const NOTA_GEMINI = '📝 En la reunión de Meet, pulsa «Tomar notas por mí» (Gemini) para el resumen automático.';

    public function __construct(private readonly GoogleOAuth $google) {}

    /** {configurado, conectado, revocado, email} de la cuenta de esa persona. */
    public function estado(int $adminId): array
    {
        return $this->google->estado(Cuenta::calendario($adminId));
    }

    /** ¿Se puede hablar con su calendario ahora mismo? */
    public function disponible(int $adminId): bool
    {
        $e = $this->estado($adminId);
        return $e['configurado'] && $e['conectado'] && !$e['revocado'];
    }

    /** Personas con el calendario conectado (y no revocado). */
    public function cuentas(): array
    {
        return array_values(array_filter($this->google->cuentasCalendario(), fn($id) => !$this->google->revocado(Cuenta::calendario($id))));
    }

    /**
     * Eventos entre dos fechas (incluidas), normalizados.
     * @return array<int, array>
     */
    public function eventos(int $adminId, string $desde, string $hasta, int $max = 250): array
    {
        $zona = new \DateTimeZone(self::ZONA);
        $j = $this->llamar(fn() => $this->google->cliente(Cuenta::calendario($adminId))->get(self::EVENTOS, [
            'timeMin' => (new \DateTimeImmutable($desde . ' 00:00:00', $zona))->format(DATE_RFC3339),
            'timeMax' => (new \DateTimeImmutable($hasta . ' 23:59:59', $zona))->format(DATE_RFC3339),
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => (string)$max,
            'timeZone' => self::ZONA,
        ]));
        $out = [];
        foreach ((array)($j['items'] ?? []) as $ev) {
            if (!is_array($ev) || ($ev['status'] ?? '') === 'cancelled') continue;
            $n = self::normalizar($ev);
            if ($n !== null) $out[] = $n;
        }
        return $out;
    }

    /** Solo las reuniones: con invitados, con Meet o con notas adjuntas. */
    public function reuniones(int $adminId, string $desde, string $hasta): array
    {
        return array_values(array_filter($this->eventos($adminId, $desde, $hasta), fn($e) => $e['invitados'] || $e['meet'] || $e['docs']));
    }

    /** Evento enlazado a una reunión del CRM (extendedProperties.private.erp_meeting). */
    public function deReunionErp(int $adminId, int $meetingId): ?array
    {
        $j = $this->llamar(fn() => $this->google->cliente(Cuenta::calendario($adminId))->get(self::EVENTOS, [
            'privateExtendedProperty' => 'erp_meeting=' . $meetingId, 'singleEvents' => 'true', 'maxResults' => '5', 'timeZone' => self::ZONA,
        ]));
        foreach ((array)($j['items'] ?? []) as $ev) {
            if (is_array($ev) && ($ev['status'] ?? '') !== 'cancelled') return self::normalizar($ev);
        }
        return null;
    }

    /**
     * Crea un evento. $o: titulo, fecha, hora, hora_fin, invitados, meet, gemini,
     * location, descripcion, recur, recordar, notificar, erp_meeting.
     * @return array{msg:string, evento:?array}
     */
    public function crear(int $adminId, array $o): array
    {
        [$body, $hayInvitados] = self::cuerpo($o, false);
        $q = [];
        if ($hayInvitados) $q['sendUpdates'] = self::avisos($o, true);
        if (!empty($o['meet'])) {
            $body['conferenceData'] = ['createRequest' => ['requestId' => bin2hex(random_bytes(8)), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]];
            $q['conferenceDataVersion'] = '1';
        }
        $ev = $this->llamar(fn() => $this->google->cliente(Cuenta::calendario($adminId))->post(self::EVENTOS, $body, $q));
        $msg = !empty($o['meet']) ? 'Reunión creada con Google Meet.' : ($hayInvitados && ($q['sendUpdates'] ?? '') === 'all' ? 'Evento creado e invitaciones enviadas.' : 'Evento creado.');
        return ['msg' => $msg, 'evento' => is_array($ev) ? self::normalizar($ev) : null];
    }

    /** Edita un evento (PATCH): lo que no viene en $o no se toca. */
    public function actualizar(int $adminId, string $id, array $o): array
    {
        [$body, $hayInvitados] = self::cuerpo($o, true);
        $url = self::EVENTOS . '/' . rawurlencode(self::id($id));
        $cli = $this->google->cliente(Cuenta::calendario($adminId));
        $q = $hayInvitados || array_key_exists('notificar', $o) ? ['sendUpdates' => self::avisos($o, $hayInvitados)] : [];
        if (!empty($o['meet'])) {
            /* Solo se pide una videollamada si aún no tiene: pedir otra cambiaría el enlace. */
            $actual = $this->llamar(fn() => $cli->get($url));
            if (empty($actual['conferenceData']) && empty($actual['hangoutLink'])) {
                $body['conferenceData'] = ['createRequest' => ['requestId' => bin2hex(random_bytes(8)), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]];
                $q['conferenceDataVersion'] = '1';
            }
        }
        $ev = $this->llamar(fn() => $cli->patch($url, $body, $q));
        return ['msg' => 'Evento actualizado.', 'evento' => is_array($ev) ? self::normalizar($ev) : null];
    }

    /** Mueve (o redimensiona) un evento: solo cambian inicio y fin. */
    public function mover(int $adminId, string $id, string $fecha, string $hora, string $horaFin, string $fechaFin = ''): array
    {
        $body = self::fechas($fecha, $hora, $horaFin, $fechaFin);
        $ev = $this->llamar(fn() => $this->google->cliente(Cuenta::calendario($adminId))->patch(self::EVENTOS . '/' . rawurlencode(self::id($id)), $body));
        return ['msg' => 'Evento movido.', 'evento' => is_array($ev) ? self::normalizar($ev) : null];
    }

    /**
     * Mover «toda la serie» desde una de sus ocurrencias: se desplaza el
     * inicio de la serie lo mismo que se ha movido la ocurrencia (y su fin lo
     * mismo que haya cambiado la duración). Mandar sin más la fecha nueva
     * haría empezar la serie en esa ocurrencia y perdería las anteriores.
     */
    public function desplazarSerie(int $adminId, string $serieId, int $deltaMin, int $deltaDurMin): array
    {
        $url = self::EVENTOS . '/' . rawurlencode(self::id($serieId));
        $cli = $this->google->cliente(Cuenta::calendario($adminId));
        $ev = $this->llamar(fn() => $cli->get($url));
        $zona = new \DateTimeZone(self::ZONA);
        if (!empty($ev['start']['dateTime'])) {
            $ini = (new \DateTimeImmutable((string)$ev['start']['dateTime']))->setTimezone($zona)->modify("$deltaMin minutes");
            $fin = (new \DateTimeImmutable((string)($ev['end']['dateTime'] ?? $ev['start']['dateTime'])))->setTimezone($zona)->modify(($deltaMin + $deltaDurMin) . ' minutes');
            if ($fin <= $ini) $fin = $ini->modify('+15 minutes');
            $body = ['start' => ['dateTime' => $ini->format('Y-m-d\TH:i:s'), 'timeZone' => self::ZONA], 'end' => ['dateTime' => $fin->format('Y-m-d\TH:i:s'), 'timeZone' => self::ZONA]];
        } else {
            $dias = intdiv($deltaMin, 1440);
            $ini = (new \DateTimeImmutable((string)$ev['start']['date']))->modify("$dias days");
            $fin = (new \DateTimeImmutable((string)($ev['end']['date'] ?? $ev['start']['date'])))->modify(($dias + intdiv($deltaDurMin, 1440)) . ' days');
            if ($fin <= $ini) $fin = $ini->modify('+1 day');
            $body = ['start' => ['date' => $ini->format('Y-m-d')], 'end' => ['date' => $fin->format('Y-m-d')]];
        }
        $ev = $this->llamar(fn() => $cli->patch($url, $body));
        return ['msg' => 'Serie movida.', 'evento' => is_array($ev) ? self::normalizar($ev) : null];
    }

    public function borrar(int $adminId, string $id, bool $avisar = true): string
    {
        $r = $this->llamar(fn() => $this->google->cliente(Cuenta::calendario($adminId))->bruta('DELETE', self::EVENTOS . '/' . rawurlencode(self::id($id)), null, ['sendUpdates' => $avisar ? 'all' : 'none']));
        /* 410: ya estaba borrado. Para quien lo pide, el resultado es el mismo. */
        if ($r->ok() || $r->estado === 410) return 'Evento eliminado.';
        if ($r->estado === 404) throw HttpError::noEncontrado('Ese evento ya no está en tu Google Calendar.');
        $j = $r->json();
        throw new HttpError(502, (string)($j['error']['message'] ?? 'Google no ha podido eliminar el evento.'), 'google');
    }

    /* ---------- Formato ---------- */

    /** Evento de Google → lo que usa el front. null si no tiene fecha. */
    public static function normalizar(array $ev): ?array
    {
        $zona = new \DateTimeZone(self::ZONA);
        $todoElDia = empty($ev['start']['dateTime']);
        if ($todoElDia) {
            $dia = substr((string)($ev['start']['date'] ?? ''), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) return null;
            $finExcl = substr((string)($ev['end']['date'] ?? ''), 0, 10);
            $diaFin = preg_match('/^\d{4}-\d{2}-\d{2}$/', $finExcl) ? (new \DateTimeImmutable($finExcl))->modify('-1 day')->format('Y-m-d') : $dia;
            if ($diaFin < $dia) $diaFin = $dia;
            $hora = $horaFin = '';
        } else {
            try {
                $ini = (new \DateTimeImmutable((string)$ev['start']['dateTime']))->setTimezone($zona);
                $fin = (new \DateTimeImmutable((string)($ev['end']['dateTime'] ?? $ev['start']['dateTime'])))->setTimezone($zona);
            } catch (\Exception $e) {
                return null;
            }
            $dia = $ini->format('Y-m-d');
            $diaFin = $fin->format('Y-m-d');
            $hora = $ini->format('H:i');
            $horaFin = $fin->format('H:i');
        }
        $invitados = [];
        foreach ((array)($ev['attendees'] ?? []) as $a) {
            if (is_array($a) && !empty($a['email']) && empty($a['self'])) $invitados[] = strtolower(trim((string)$a['email']));
        }
        $docs = [];
        foreach ((array)($ev['attachments'] ?? []) as $a) {
            $url = (string)($a['fileUrl'] ?? '');
            if (is_array($a) && preg_match('~^https://~', $url)) $docs[] = ['titulo' => (string)($a['title'] ?? 'Notas de la reunión'), 'url' => $url];
        }
        $recordar = '';
        if (isset($ev['reminders']) && empty($ev['reminders']['useDefault'])) {
            $recordar = !empty($ev['reminders']['overrides']) ? (string)(int)($ev['reminders']['overrides'][0]['minutes'] ?? 0) : 'no';
        }
        $meetUrl = (string)($ev['hangoutLink'] ?? '');
        if ($meetUrl === '') {
            foreach ((array)($ev['conferenceData']['entryPoints'] ?? []) as $p) {
                if (($p['entryPointType'] ?? '') === 'video' && preg_match('~^https://~', (string)($p['uri'] ?? ''))) { $meetUrl = (string)$p['uri']; break; }
            }
        }
        $link = (string)($ev['htmlLink'] ?? '');
        return [
            'id' => (string)($ev['id'] ?? ''),
            'titulo' => trim((string)($ev['summary'] ?? '')) !== '' ? (string)$ev['summary'] : '(sin título)',
            'dia' => $dia,
            'dia_fin' => $diaFin,
            'hora' => $hora,
            'hora_fin' => $horaFin,
            'todo_el_dia' => $todoElDia,
            'editable' => !empty($ev['organizer']['self']) || !empty($ev['creator']['self']),
            'invitados' => $invitados,
            'ubicacion' => (string)($ev['location'] ?? ''),
            'descripcion' => (string)($ev['description'] ?? ''),
            'meet' => $meetUrl !== '' || !empty($ev['conferenceData']),
            'meet_url' => $meetUrl,
            'recurrente' => !empty($ev['recurringEventId']) || !empty($ev['recurrence']),
            'serie_id' => (string)($ev['recurringEventId'] ?? ''),
            'link' => preg_match('~^https://~', $link) ? $link : '',
            'docs' => $docs,
            'recordar' => $recordar,
            'erp_meeting' => (string)($ev['extendedProperties']['private']['erp_meeting'] ?? ''),
        ];
    }

    /**
     * Cuerpo del evento a partir de las opciones. En un PATCH ($parcial) solo
     * se incluye lo que viene; al crear, todo.
     * @return array{0: array, 1: bool} [cuerpo, hay invitados]
     */
    public static function cuerpo(array $o, bool $parcial): array
    {
        $b = [];
        if (!$parcial || array_key_exists('titulo', $o)) {
            $t = trim((string)($o['titulo'] ?? ''));
            if ($t === '') throw HttpError::validacion('Escribe un título.', 'titulo');
            if (mb_strlen($t) > 300) throw HttpError::validacion('El título es demasiado largo.', 'titulo');
            $b['summary'] = $t;
        }
        if (!$parcial || array_key_exists('fecha', $o)) {
            $b += self::fechas((string)($o['fecha'] ?? ''), (string)($o['hora'] ?? ''), (string)($o['hora_fin'] ?? ''), (string)($o['fecha_fin'] ?? ''));
        }
        if (array_key_exists('ubicacion', $o)) $b['location'] = mb_substr(trim((string)$o['ubicacion']), 0, 500);
        $desc = array_key_exists('descripcion', $o) ? trim((string)$o['descripcion']) : null;
        if (!empty($o['gemini']) && ($desc === null || !str_contains($desc, 'Tomar notas por mí'))) {
            /* Google no deja activar Gemini por API: se deja el recordatorio en la descripción. */
            $desc = trim(($desc ?? '') . "\n\n" . self::NOTA_GEMINI);
        }
        if ($desc !== null) $b['description'] = mb_substr($desc, 0, 8000);
        if (!empty($o['erp_meeting'])) $b['extendedProperties'] = ['private' => ['erp_meeting' => (string)(int)$o['erp_meeting']]];
        if (array_key_exists('recur', $o)) {
            $r = strtoupper(trim((string)$o['recur']));
            if ($r !== '' && !in_array($r, self::RECURRENCIAS, true)) throw HttpError::validacion('Repetición desconocida.', 'recur');
            if ($r !== '') $b['recurrence'] = ['RRULE:FREQ=' . $r];
            elseif ($parcial) $b['recurrence'] = [];
        }
        if (array_key_exists('recordar', $o)) {
            $rec = trim((string)$o['recordar']);
            if ($rec === 'no') $b['reminders'] = ['useDefault' => false, 'overrides' => []];
            elseif (ctype_digit($rec) && (int)$rec <= 40320) $b['reminders'] = ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => (int)$rec]]];
            /* '' = «Predeterminado de Google»: al crear basta con no mandar nada; al editar hay que volver a él. */
            elseif ($rec === '' && $parcial) $b['reminders'] = ['useDefault' => true];
            elseif ($rec !== '') throw HttpError::validacion('Recordatorio no válido.', 'recordar');
        }
        $hay = false;
        if (array_key_exists('invitados', $o)) {
            $lista = self::correos($o['invitados']);
            $b['attendees'] = array_map(fn($e) => ['email' => $e], $lista);
            if (!$lista && !$parcial) unset($b['attendees']);
            $hay = (bool)$lista;
        }
        return [$b, $hay];
    }

    /** start/end con la zona de Madrid (o de día completo, fin exclusivo). */
    public static function fechas(string $fecha, string $hora, string $horaFin, string $fechaFin = ''): array
    {
        if (!self::fechaValida($fecha)) throw HttpError::validacion('Elige una fecha.', 'fecha');
        $hora = trim($hora);
        $horaFin = trim($horaFin);
        $fechaFin = trim($fechaFin) !== '' ? trim($fechaFin) : $fecha;
        if (!self::fechaValida($fechaFin) || $fechaFin < $fecha) throw HttpError::validacion('La fecha de fin no es válida.', 'fecha_fin');
        if ($hora === '') {
            return ['start' => ['date' => $fecha], 'end' => ['date' => (new \DateTimeImmutable($fechaFin))->modify('+1 day')->format('Y-m-d')]];
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) throw HttpError::validacion('Hora no válida.', 'hora');
        if ($horaFin !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaFin)) throw HttpError::validacion('Hora de fin no válida.', 'hora_fin');
        $zona = new \DateTimeZone(self::ZONA);
        $ini = new \DateTimeImmutable("$fecha $hora:00", $zona);
        $fin = $horaFin !== '' ? new \DateTimeImmutable("$fechaFin $horaFin:00", $zona) : $ini->modify('+60 minutes');
        if ($fin <= $ini) $fin = $ini->modify('+60 minutes');
        return [
            'start' => ['dateTime' => $ini->format('Y-m-d\TH:i:s'), 'timeZone' => self::ZONA],
            'end' => ['dateTime' => $fin->format('Y-m-d\TH:i:s'), 'timeZone' => self::ZONA],
        ];
    }

    /** Correos válidos de una lista (texto con comas/espacios o array), sin repetir, máx. 100. */
    public static function correos(mixed $v): array
    {
        $partes = is_array($v) ? $v : preg_split('/[,;\s]+/', (string)$v);
        $out = [];
        foreach ((array)$partes as $e) {
            $e = strtolower(trim((string)$e));
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[$e] = true;
        }
        return array_slice(array_keys($out), 0, 100);
    }

    public static function fechaValida(string $f): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m)) return false;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /** sendUpdates: lo que diga la casilla «avisar a los invitados»; si no viene, avisar si hay invitados. */
    private static function avisos(array $o, bool $hayInvitados): string
    {
        if (array_key_exists('notificar', $o)) return !empty($o['notificar']) ? 'all' : 'none';
        return $hayInvitados ? 'all' : 'none';
    }

    private static function id(string $id): string
    {
        $id = trim($id);
        if ($id === '' || strlen($id) > 1024 || !preg_match('/^[A-Za-z0-9_\-]+$/', $id)) throw HttpError::validacion('Falta el evento.', 'id');
        return $id;
    }

    /** Ejecuta una llamada a Google traduciendo sus fallos a errores de la API. */
    private function llamar(callable $f): mixed
    {
        try {
            return $f();
        } catch (ErrorGoogle $e) {
            throw match ($e->codigo) {
                'sin_conectar' => new HttpError(409, 'No estás conectado a Google Calendar.', 'google_sin_conectar'),
                'sin_configurar' => new HttpError(409, 'Google Calendar no está configurado. Se hace en Ajustes › Integraciones.', 'google_sin_configurar'),
                'revocado' => new HttpError(409, 'Tu conexión con Google ha caducado. Vuelve a conectarla.', 'google_revocado'),
                'red' => new HttpError(502, 'No se ha podido contactar con Google. Inténtalo de nuevo.', 'google_red'),
                default => $e->estadoHttp === 404 || $e->estadoHttp === 410
                    ? HttpError::noEncontrado('Ese evento ya no está en Google Calendar.')
                    : new HttpError(502, $e->getMessage() !== '' ? 'Google: ' . $e->getMessage() : 'Google ha devuelto un error.', 'google'),
            };
        }
    }
}
