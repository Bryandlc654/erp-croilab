<?php
namespace Croilab\Modulos\Trabajo;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;

/* Bandeja de avisos (notifications.php) y el sondeo de avisos nuevos que
   alimenta el globo y los pop-ups. Cada persona solo ve y toca los suyos.
   No hace falta permiso de pantalla: cualquiera del equipo tiene bandeja. */
class NotificacionesServicio
{
    public const BANDEJAS = ['principal', 'otras', 'chat', 'tarde', 'papelera'];
    public const ACCIONES = ['leer', 'no_leer', 'posponer', 'traer', 'borrar', 'restaurar', 'purgar'];

    public function __construct(private readonly NotificacionesRepositorio $repo) {}

    public function listar(Acceso $acc, string $bandeja, int $limit, int $offset): array
    {
        if (!in_array($bandeja, self::BANDEJAS, true)) throw HttpError::validacion('Bandeja desconocida.', 'bandeja');
        [$filas, $total] = $this->repo->listar($acc->adminId, $bandeja, $limit, $offset);

        /* Círculo con el estado REAL de la tarea enlazada, pero solo si esa
           tarea entra en su alcance (el antiguo lo enseñaba de cualquiera). */
        $tareas = [];
        foreach ($filas as $f) if ($t = self::tareaDeUrl((string)$f['url'])) $tareas[$t] = true;
        $visibles = array_values(array_filter(array_keys($tareas), fn($t) => $acc->veTarea($t)));
        $estados = $this->repo->estadosTareas($visibles);
        $fotos = $this->repo->fotosPorNombre(array_column($filas, 'actor'));

        $items = array_map(function ($f) use ($estados, $fotos) {
            $tid = self::tareaDeUrl((string)$f['url']);
            $actor = (string)$f['actor'];
            return [
                'id' => (int)$f['id'],
                'tipo' => (string)$f['tipo'],
                'titulo' => (string)$f['titulo'],
                'cuerpo' => (string)$f['cuerpo'],
                'url' => (string)$f['url'],
                'tarea' => (string)$f['tarea'],
                'actor' => $actor,
                'actor_foto' => $fotos[$actor]['foto'] ?? null,
                'leido' => (int)$f['leido'] === 1,
                'snooze_until' => $f['snooze_until'],
                'created_at' => (string)$f['created_at'],
                'tarea_id' => $tid,
                'tarea_estado' => $tid ? ($estados[$tid] ?? null) : null,
            ];
        }, $filas);
        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'contadores' => $this->repo->contadores($acc->adminId)];
    }

    /**
     * Sondeo ligero: cuántas sin leer y los avisos posteriores a `despues`.
     * Con despues = 0 (primer sondeo de la pestaña) no devuelve avisos: solo
     * fija la línea base, para no saltar con lo que ya había. Corre de paso las
     * sincronizaciones (facturas vencidas, leads, reuniones, cumpleaños), que
     * se limitan solas a una vez cada 10 minutos.
     */
    public function avisos(Acceso $acc, int $despues): array
    {
        if (function_exists('notif_sync_todo')) {
            try { notif_sync_todo(); } catch (\Throwable $e) { error_log('notif_sync_todo: ' . $e->getMessage()); }
        }
        $ultimo = $this->repo->ultimoId($acc->adminId);
        $nuevos = $despues > 0 && $ultimo > $despues ? $this->repo->nuevos($acc->adminId, $despues) : [];
        return [
            'no_leidas' => $this->repo->noLeidas($acc->adminId),
            'ultimo_id' => $ultimo,
            'nuevos' => array_map(fn($n) => [
                'id' => (int)$n['id'], 'tipo' => (string)$n['tipo'], 'titulo' => (string)$n['titulo'], 'cuerpo' => (string)$n['cuerpo'],
                'url' => (string)$n['url'], 'tarea' => (string)$n['tarea'], 'actor' => (string)$n['actor'],
            ], $nuevos),
        ];
    }

    /** {accion, ids[], horas?}. Posponer sin horas = mañana a las 9:00. */
    public function accion(Acceso $acc, array $d): array
    {
        $accion = (string)($d['accion'] ?? '');
        if (!in_array($accion, self::ACCIONES, true)) throw HttpError::validacion('Acción desconocida.', 'accion');
        $ids = $d['ids'] ?? null;
        if (!is_array($ids) || !$ids) throw HttpError::validacion('No has elegido ninguna notificación.', 'ids');
        if (count($ids) > 1000) throw HttpError::validacion('Demasiadas de una vez.', 'ids');
        $hasta = null;
        if ($accion === 'posponer') $hasta = self::hastaPosponer($d['horas'] ?? null);
        $n = $this->repo->accion($acc->adminId, $accion, $ids, $hasta);
        return ['cambiadas' => $n, 'hasta' => $hasta, 'no_leidas' => $this->repo->noLeidas($acc->adminId)];
    }

    public function leerTodas(Acceso $acc): array
    {
        return ['cambiadas' => $this->repo->leerTodas($acc->adminId), 'no_leidas' => $this->repo->noLeidas($acc->adminId)];
    }

    public function leidasAPapelera(Acceso $acc): array
    {
        return ['cambiadas' => $this->repo->leidasAPapelera($acc->adminId), 'no_leidas' => $this->repo->noLeidas($acc->adminId)];
    }

    public function vaciarPapelera(Acceso $acc): array
    {
        return ['cambiadas' => $this->repo->vaciarPapelera($acc->adminId)];
    }

    /** 'AAAA-MM-DD HH:MM:SS' de hasta cuándo se pospone. */
    public static function hastaPosponer(mixed $horas, ?\DateTimeImmutable $ahora = null): string
    {
        $ahora ??= new \DateTimeImmutable();
        if ($horas === null || $horas === '') return $ahora->modify('+1 day')->setTime(9, 0)->format('Y-m-d H:i:s');
        $h = filter_var($horas, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 24 * 30]]);
        if ($h === false) throw HttpError::validacion('Horas no válidas.', 'horas');
        return $ahora->modify("+$h hours")->format('Y-m-d H:i:s');
    }

    /** Id de tarea de la URL de un aviso: '/tareas/12#c3' o 'task.php?id=12'. */
    public static function tareaDeUrl(string $url): ?int
    {
        if (preg_match('~^/tareas/(\d+)(?:[?#]|$)~', $url, $m)) return (int)$m[1];
        if (preg_match('~(?:^|/)task\.php\?(?:[^#]*&)?id=(\d+)~', $url, $m)) return (int)$m[1];
        return null;
    }
}
