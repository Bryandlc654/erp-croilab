<?php
namespace Croilab\Modulos\Comunicacion\Calendario;

use Croilab\Http\HttpError;
use Croilab\Modulos\Comunicacion\Alcance;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;
use PDO;

/* Calendario: fechas de entrega de tareas + Google Calendar de cada uno (y de
   los compañeros que se quieran ver) + festivos.

   Permiso `ver.agenda`. Los eventos son del calendario de cada persona, así que
   crearlos, moverlos o borrarlos no exige `general.editar` (como el antiguo).
   Lo que se corrige: las tareas salían todas, sin alcance; ahora solo las que
   la persona puede ver (y solo con `ver.tareas`). */
class CalendarioServicio
{
    /* Colores de los compañeros (los del antiguo); los míos van en azul Google. */
    public const PALETA = ['#8e44ad', '#e67e22', '#16a085', '#d35400', '#2980b9', '#c0392b', '#0f9d58'];
    public const COLOR_MIO = '#4285F4';
    private const MAX_DIAS = 100;

    public function __construct(
        private readonly PDO $pdo,
        private readonly GoogleCalendario $gcal,
        private readonly EquipoRepositorio $equipo
    ) {}

    /** @param int[] $equipo compañeros cuyos eventos también se quieren ver */
    public function datos(Acceso $acc, string $desde, string $hasta, array $equipo): array
    {
        $acc->exigir('ver.agenda');
        if (!GoogleCalendario::fechaValida($desde) || !GoogleCalendario::fechaValida($hasta) || $hasta < $desde) throw HttpError::validacion('Rango de fechas no válido.', 'desde');
        if ((new \DateTimeImmutable($desde))->diff(new \DateTimeImmutable($hasta))->days > self::MAX_DIAS) throw HttpError::validacion('Como mucho ' . self::MAX_DIAS . ' días de golpe.', 'hasta');

        $yo = $acc->adminId;
        $estado = $this->gcal->estado($yo);
        $conectadas = $this->gcal->cuentas();
        $activos = array_column($this->equipo->activos(), 'id');
        $companeros = [];
        $i = 0;
        foreach ($conectadas as $id) {
            if ($id === $yo || !in_array($id, $activos, true)) continue;
            $companeros[] = ['id' => $id, 'username' => $this->equipo->persona($id)['username'], 'color' => self::PALETA[$i++ % count(self::PALETA)]];
        }

        $eventos = [];
        $avisos = [];
        if ($estado['conectado'] && !$estado['revocado'] && $estado['configurado']) {
            try {
                foreach ($this->gcal->eventos($yo, $desde, $hasta) as $e) $eventos[] = $e + ['color' => self::COLOR_MIO, 'mio' => true, 'owner' => null];
            } catch (HttpError $e) {
                $avisos[] = $e->getMessage();
                if ($e->codigo === 'google_revocado') $estado['revocado'] = true;
            }
        }
        $vistos = array_column($eventos, 'id');
        foreach ($companeros as $c) {
            if (!in_array($c['id'], $equipo, true)) continue;
            try {
                foreach ($this->gcal->eventos($c['id'], $desde, $hasta) as $e) {
                    if (in_array($e['id'], $vistos, true)) continue;   // una reunión conjunta sale una vez (la mía)
                    $e['editable'] = false;   // de un compañero: solo lectura
                    $eventos[] = $e + ['color' => $c['color'], 'mio' => false, 'owner' => ['id' => $c['id'], 'username' => $c['username']]];
                }
            } catch (HttpError $e) {
                $avisos[] = 'No se ha podido leer el calendario de ' . $c['username'] . '.';
            }
        }

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'tareas' => $acc->puede('ver.tareas') ? $this->tareas($acc, $desde, $hasta) : [],
            'eventos' => $eventos,
            'festivos' => (object)Festivos::entre($desde, $hasta),
            'google' => $estado,
            'companeros' => $companeros,
            'puede_configurar' => $acc->puede('integraciones.editar'),
            'avisos' => $avisos,
        ];
    }

    /** Tareas con fecha de entrega en el rango, dentro del alcance. */
    private function tareas(Acceso $acc, string $desde, string $hasta): array
    {
        $st = $this->pdo->prepare('SELECT t.id, t.titulo, t.estado, t.due_date, t.client_id, t.responsable_id, c.name AS cliente
            FROM tasks t LEFT JOIN clients c ON c.id = t.client_id
            WHERE t.due_date BETWEEN ? AND ?' . $acc->sqlTareas('t') . '
            ORDER BY t.due_date, FIELD(t.estado, \'en proceso\', \'pendiente\', \'atemporal\', \'completada\'), t.id LIMIT 1000');
        $st->execute([$desde, $hasta]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        $asignados = [];
        if ($filas) {
            $ids = array_map(fn($f) => (int)$f['id'], $filas);
            $q = $this->pdo->prepare('SELECT task_id, admin_id FROM task_assignees WHERE task_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY task_id, admin_id');
            $q->execute($ids);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) $asignados[(int)$a['task_id']][] = (int)$a['admin_id'];
        }
        return array_map(function ($f) use ($asignados) {
            $ids = $asignados[(int)$f['id']] ?? ($f['responsable_id'] ? [(int)$f['responsable_id']] : []);
            return [
                'id' => (int)$f['id'], 'titulo' => (string)$f['titulo'], 'estado' => (string)$f['estado'], 'fecha' => (string)$f['due_date'],
                'client_id' => $f['client_id'] !== null ? (int)$f['client_id'] : null, 'cliente' => (string)($f['cliente'] ?? ''),
                'asignados' => array_map(fn($id) => $this->equipo->persona($id), $ids),
            ];
        }, $filas);
    }

    public function crear(Acceso $acc, array $d): array
    {
        $acc->exigir('ver.agenda');
        $this->exigirConectado($acc);
        return $this->gcal->crear($acc->adminId, self::opciones($d));
    }

    /** Editar (todo) o mover (`solo_fechas`): siempre en MI calendario. */
    public function actualizar(Acceso $acc, array $d): array
    {
        $acc->exigir('ver.agenda');
        $this->exigirConectado($acc);
        $id = trim((string)($d['id'] ?? ''));
        if (!empty($d['solo_fechas']) && is_array($d['origen'] ?? null)) {
            /* Toda la serie: se desplaza lo que se ha movido la ocurrencia (`origen` = dónde estaba). */
            [$deltaIni, $deltaDur] = self::desplazamiento($d['origen'], $d);
            return $this->gcal->desplazarSerie($acc->adminId, $id, $deltaIni, $deltaDur);
        }
        if (!empty($d['solo_fechas'])) {
            return $this->gcal->mover($acc->adminId, $id, (string)($d['fecha'] ?? ''), (string)($d['hora'] ?? ''), (string)($d['hora_fin'] ?? ''), (string)($d['fecha_fin'] ?? ''));
        }
        return $this->gcal->actualizar($acc->adminId, $id, self::opciones($d, true));
    }

    public function borrar(Acceso $acc, string $id, bool $avisar = true): string
    {
        $acc->exigir('ver.agenda');
        $this->exigirConectado($acc);
        return $this->gcal->borrar($acc->adminId, $id, $avisar);
    }

    /**
     * Correos para el autocompletado de invitados: equipo, contactos del CRM y
     * clientes (correo de facturación), dentro del alcance.
     */
    public function correos(Acceso $acc): array
    {
        $acc->exigir('ver.agenda');
        $out = [];
        $add = function (string $email, string $nombre, string $tipo) use (&$out) {
            $e = strtolower(trim($email));
            if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL) || isset($out[$e])) return;
            $out[$e] = ['email' => $e, 'nombre' => $nombre, 'tipo' => $tipo];
        };
        foreach ($this->pdo->query("SELECT username, email FROM admins WHERE activo = 1 AND email IS NOT NULL AND email <> '' ORDER BY username") as $r) $add((string)$r['email'], (string)$r['username'], 'equipo');
        foreach ($this->gcal->cuentas() as $id) {
            $e = $this->gcal->estado($id)['email'] ?? '';
            if ($e !== '') $add($e, $this->equipo->persona($id)['username'], 'equipo');
        }
        if ($acc->puede('ver.crm')) {
            $sql = "SELECT c.nombre, c.empresa, c.email FROM contacts c WHERE c.email IS NOT NULL AND c.email <> ''" . Alcance::sqlContactos($acc, 'c');
            try {
                foreach ($this->pdo->query($sql . ' ORDER BY c.nombre LIMIT 2000') as $r) $add((string)$r['email'], trim((string)$r['nombre'] . ((string)$r['empresa'] !== '' ? ' · ' . $r['empresa'] : '')), 'contacto');
            } catch (\PDOException $e) {
                /* Sin CRM no hay contactos que sugerir. */
            }
        }
        foreach ($this->pdo->query("SELECT name, fact_email FROM clients WHERE fact_email <> ''" . $acc->sqlClientes('id') . ' ORDER BY name LIMIT 2000') as $r) $add((string)$r['fact_email'], (string)$r['name'], 'cliente');
        return array_values($out);
    }

    private function exigirConectado(Acceso $acc): void
    {
        $e = $this->gcal->estado($acc->adminId);
        if (!$e['configurado']) throw new HttpError(409, 'Google Calendar no está configurado. Se hace en Ajustes › Integraciones.', 'google_sin_configurar');
        if (!$e['conectado']) throw new HttpError(409, 'No estás conectado a Google Calendar.', 'google_sin_conectar');
        if ($e['revocado']) throw new HttpError(409, 'Tu conexión con Google ha caducado. Vuelve a conectarla.', 'google_revocado');
    }

    /**
     * Minutos que se ha movido el inicio y que ha cambiado la duración entre
     * `$antes` y `$ahora` ({fecha, hora, fecha_fin?, hora_fin?}; sin hora = día completo).
     * @return array{0:int, 1:int}
     */
    public static function desplazamiento(array $antes, array $ahora): array
    {
        $punto = function (array $x, bool $fin): int {
            $f = (string)($fin ? (($x['fecha_fin'] ?? '') ?: ($x['fecha'] ?? '')) : ($x['fecha'] ?? ''));
            if (!GoogleCalendario::fechaValida($f)) throw HttpError::validacion('Fecha no válida.', 'fecha');
            $h = (string)($fin ? ($x['hora_fin'] ?? '') : ($x['hora'] ?? ''));
            if ($h === '' && ($x['hora'] ?? '') !== '' && $fin) $h = (string)$x['hora'];
            $m = preg_match('/^(\d{2}):(\d{2})$/', $h, $mm) ? (int)$mm[1] * 60 + (int)$mm[2] : 0;
            return intdiv((new \DateTimeImmutable($f . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(), 60) + $m;
        };
        $ini0 = $punto($antes, false);
        $fin0 = $punto($antes, true);
        $ini1 = $punto($ahora, false);
        $fin1 = $punto($ahora, true);
        return [$ini1 - $ini0, ($fin1 - $ini1) - ($fin0 - $ini0)];
    }

    /** Opciones del evento desde el cuerpo JSON (solo las claves conocidas). */
    public static function opciones(array $d, bool $parcial = false): array
    {
        $o = [];
        foreach (['titulo', 'fecha', 'fecha_fin', 'hora', 'hora_fin', 'ubicacion', 'descripcion', 'recur', 'recordar', 'invitados'] as $k) {
            if (array_key_exists($k, $d)) $o[$k] = is_array($d[$k]) ? $d[$k] : (string)($d[$k] ?? '');
        }
        foreach (['meet', 'gemini', 'notificar'] as $k) if (array_key_exists($k, $d)) $o[$k] = !empty($d[$k]);
        if (!empty($d['erp_meeting'])) $o['erp_meeting'] = (int)$d['erp_meeting'];
        if (!$parcial && !array_key_exists('fecha', $o)) $o['fecha'] = '';
        /* Duración en minutos en lugar de hora de fin (lo que usa el modal de reuniones). */
        if (!empty($d['duracion']) && ($o['hora'] ?? '') !== '' && empty($o['hora_fin']) && GoogleCalendario::fechaValida((string)($o['fecha'] ?? ''))
            && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$o['hora'])) {
            $min = max(5, min(24 * 60, (int)$d['duracion']));
            $ini = new \DateTimeImmutable($o['fecha'] . ' ' . $o['hora']);
            $fin = $ini->modify("+$min minutes");
            $o['hora_fin'] = $fin->format('H:i');
            if ($fin->format('Y-m-d') !== $o['fecha']) $o['fecha_fin'] = $fin->format('Y-m-d');
        }
        return $o;
    }
}
