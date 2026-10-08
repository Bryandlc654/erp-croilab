<?php
namespace Croilab\Modulos\Comunicacion\Chat;

use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;

/* Reglas del chat del equipo.

   Permisos (decisión del antiguo, que se mantiene): con `ver.chat` cualquiera
   lee, escribe y abre directos, también «Solo lectura». Crear grupos exige
   `general.editar`. Lo que se decide aquí (antes cualquiera podía): sacar a
   OTRA persona de un grupo solo lo puede quien lo creó o quien gestiona el
   equipo; salir, cualquiera. Toda acción sobre una sala exige ser miembro
   (si no, 404: ni se confirma que exista).

   Lo que cambia respecto al antiguo:
     · el texto se devuelve tal cual (el front lo pinta sin HTML), no `html`,
     · el sondeo pide solo lo nuevo y lo cambiado desde su cursor,
     · los adjuntos se sirven comprobando que eres de la sala,
     · las @menciones en un grupo avisan a la persona mencionada,
     · borrar un mensaje borra también sus ficheros. */
class ChatServicio
{
    public const MAX_TEXTO = 8000;
    public const ESCRIBIENDO_SEG = 6;
    private const HISTORIAL = 50;

    public function __construct(
        private readonly ChatRepositorio $chat,
        private readonly EquipoRepositorio $equipo,
        private readonly Presencia $presencia,
        private readonly Adjuntos $adjuntos
    ) {}

    /* ---------- Salas ---------- */

    public function salas(Acceso $acc, bool $activo = true): array
    {
        $acc->exigir('ver.chat');
        $yo = $acc->adminId;
        $this->presencia->tocar($yo, $activo);
        $filas = $this->chat->salasDe($yo);
        $miembros = $this->chat->miembros(array_column($filas, 'id'));
        $salas = array_map(fn($f) => $this->sala($f, $miembros[(int)$f['id']] ?? [], $yo), $filas);

        $personas = [];
        foreach ($this->equipo->activos() as $p) if ($p['id'] !== $yo) $personas[] = $p;
        $ids = array_unique(array_merge(array_column($personas, 'id'), ...array_map(fn($s) => array_column($s['miembros'], 'id'), $salas ?: [['miembros' => []]])));
        return [
            'salas' => $salas,
            'personas' => $personas,
            'presencia' => (object)$this->presencia->estados($ids),
            'yo' => $yo,
            'puede_crear_grupo' => $acc->puede('general.editar'),
            'puede_gestionar' => $acc->puede('equipo.gestionar'),
        ];
    }

    /** Una sala (si soy miembro) en el mismo formato que la lista. */
    public function verSala(Acceso $acc, int $salaId): array
    {
        $acc->exigir('ver.chat');
        $this->exigirMiembro($acc, $salaId);
        foreach ($this->chat->salasDe($acc->adminId) as $f) {
            if ((int)$f['id'] === $salaId) return $this->sala($f, $this->chat->miembros([$salaId])[$salaId] ?? [], $acc->adminId);
        }
        throw HttpError::noEncontrado('Conversación no encontrada.');
    }

    private function sala(array $f, array $miembros, int $yo): array
    {
        $esDm = $f['type'] === 'dm';
        $otro = null;
        if ($esDm) foreach ($miembros as $m) if ($m !== $yo) { $otro = $m; break; }
        $nombre = $esDm ? ($otro ? $this->equipo->persona($otro)['username'] : 'Yo') : (trim((string)$f['name']) !== '' ? (string)$f['name'] : 'Grupo');
        $ultimo = null;
        if (!empty($f['ult_id'])) {
            $ultimo = [
                'id' => (int)$f['ult_id'],
                'texto' => !empty($f['ult_borrado']) ? '' : self::extracto((string)$f['ult_body'], 140),
                'autor_id' => (int)$f['ult_autor'],
                'autor' => $this->equipo->persona((int)$f['ult_autor'])['username'] ?: '?',
                'creado' => (string)$f['ult_creado'],
                'borrado' => !empty($f['ult_borrado']),
                'adjunto' => empty($f['ult_borrado']) && !empty(self::leerAdjuntos($f['ult_adjuntos'] ?? null)),
            ];
        }
        return [
            'id' => (int)$f['id'],
            'tipo' => $esDm ? 'dm' : 'grupo',
            'nombre' => $nombre,
            'miembros' => array_map(fn($m) => $this->equipo->persona($m), $miembros),
            'otro_id' => $otro,
            'creado_por' => $f['created_by'] !== null ? (int)$f['created_by'] : null,
            'ultimo' => $ultimo,
            'no_leidos' => (int)$f['no_leidos'],
        ];
    }

    /** Directo con alguien (lo crea si no existe). Devuelve el id de la sala. */
    public function abrirDirecto(Acceso $acc, int $con): int
    {
        $acc->exigir('ver.chat');
        if ($con === $acc->adminId) throw HttpError::validacion('No puedes abrir un chat contigo.', 'con');
        if (!$this->activo($con)) throw HttpError::validacion('Esa persona no está en el equipo.', 'con');
        return $this->chat->dmEntre($acc->adminId, $con) ?: $this->chat->crearSala('dm', '', $acc->adminId, [$con]);
    }

    /** @param int[] $miembros */
    public function crearGrupo(Acceso $acc, string $nombre, array $miembros): int
    {
        $acc->exigir('ver.chat');
        if (!$acc->puede('general.editar')) throw new HttpError(403, 'Sin permiso para crear grupos.', 'permiso');
        $nombre = trim($nombre);
        if ($nombre === '') throw HttpError::validacion('Ponle un nombre al grupo.', 'nombre');
        if (mb_strlen($nombre) > 120) throw HttpError::validacion('El nombre es demasiado largo (máximo 120).', 'nombre');
        $ids = [];
        foreach ($miembros as $m) {
            $m = (int)$m;
            if ($m === $acc->adminId || $m <= 0) continue;
            if (!$this->activo($m)) throw HttpError::validacion('Alguna de esas personas no está en el equipo.', 'miembros');
            $ids[$m] = true;
        }
        if (!$ids) throw HttpError::validacion('Elige al menos a otra persona.', 'miembros');
        return $this->chat->crearSala('group', $nombre, $acc->adminId, array_keys($ids));
    }

    public function renombrar(Acceso $acc, int $salaId, string $nombre): array
    {
        $sala = $this->grupo($acc, $salaId);
        $nombre = trim($nombre);
        if ($nombre === '') throw HttpError::validacion('El nombre no puede quedar vacío.', 'nombre');
        if (mb_strlen($nombre) > 120) throw HttpError::validacion('El nombre es demasiado largo (máximo 120).', 'nombre');
        $this->chat->renombrar((int)$sala['id'], $nombre);
        return $this->verSala($acc, $salaId);
    }

    public function agregarMiembro(Acceso $acc, int $salaId, int $adminId): array
    {
        $this->grupo($acc, $salaId);
        if (!$this->activo($adminId)) throw HttpError::validacion('Esa persona no está en el equipo.', 'admin_id');
        $this->chat->agregarMiembro($salaId, $adminId);
        return $this->verSala($acc, $salaId);
    }

    /** Quitar a alguien (o a mí: salir). Devuelve la sala, o null si he salido. */
    public function quitarMiembro(Acceso $acc, int $salaId, int $adminId): ?array
    {
        $sala = $this->grupo($acc, $salaId);
        if ($adminId !== $acc->adminId) {
            $esCreador = (int)($sala['created_by'] ?? 0) === $acc->adminId;
            if (!$esCreador && !$acc->puede('equipo.gestionar')) {
                throw new HttpError(403, 'Solo quien creó el grupo puede sacar a otras personas.', 'permiso');
            }
            if (!$this->chat->esMiembro($salaId, $adminId)) throw HttpError::noEncontrado('Esa persona no está en el grupo.');
        }
        $this->chat->quitarMiembro($salaId, $adminId);
        return $adminId === $acc->adminId ? null : $this->verSala($acc, $salaId);
    }

    /* ---------- Mensajes ---------- */

    /**
     * Historial (paginado hacia atrás). Sin $antes es la apertura de la sala:
     * marca como leído y quita el aviso de la campana de esa sala.
     */
    public function historial(Acceso $acc, int $salaId, int $antes, int $limite = self::HISTORIAL): array
    {
        $acc->exigir('ver.chat');
        $this->exigirMiembro($acc, $salaId);
        $limite = max(1, min(100, $limite));
        $filas = $this->chat->historial($salaId, $antes, $limite + 1);
        $hayMas = count($filas) > $limite;
        if ($hayMas) array_shift($filas);
        if ($antes <= 0) {
            $ultimo = $filas ? (int)end($filas)['id'] : 0;
            $this->chat->marcarLeido($salaId, $acc->adminId, $ultimo);
            $this->chat->borrarAviso($acc->adminId, "chat:$salaId:" . $acc->adminId);
            $this->presencia->tocar($acc->adminId, true);
        }
        return [
            'mensajes' => $this->formatear($filas, $acc->adminId),
            'hay_mas' => $hayMas,
            'leido_hasta' => $this->chat->leidoPorTodos($salaId, $acc->adminId),
            'cursor' => $this->cursor(),
        ];
    }

    /**
     * Sondeo de la sala abierta: lo nuevo (id > $despues), lo cambiado desde el
     * cursor (editados, borrados, reacciones), quién escribe y hasta dónde han leído.
     * Con $leer (pestaña visible) marca lo nuevo como leído.
     */
    public function novedades(Acceso $acc, int $salaId, int $despues, string $cursor, bool $activo, bool $leer): array
    {
        $acc->exigir('ver.chat');
        $this->exigirMiembro($acc, $salaId);
        $yo = $acc->adminId;
        $this->presencia->tocar($yo, $activo);
        $nuevo = $this->cursor();
        $nuevos = $this->chat->despues($salaId, $despues, 100);
        $cambiados = self::cursorValido($cursor) && $despues > 0 ? $this->chat->cambiados($salaId, $cursor, $despues) : [];
        if ($nuevos && $leer) {
            $this->chat->marcarLeido($salaId, $yo, (int)end($nuevos)['id']);
            $this->chat->borrarAviso($yo, "chat:$salaId:$yo");
        }
        $miembros = $this->chat->miembros([$salaId])[$salaId] ?? [];
        $escriben = array_map(fn($id) => $this->equipo->persona($id), $this->chat->escribiendo($salaId, $yo, time()));
        return [
            'mensajes' => $this->formatear($nuevos, $yo),
            'cambios' => $this->formatear($cambiados, $yo),
            'escribiendo' => $escriben,
            'leido_hasta' => $this->chat->leidoPorTodos($salaId, $yo),
            'cursor' => $nuevo,
            'no_leidos' => (object)$this->chat->noLeidosPorSala($yo),
            'presencia' => (object)$this->presencia->estados(array_diff($miembros, [$yo])),
        ];
    }

    /**
     * Envía un mensaje. $ficheros: lista de Adjuntos::desdeFiles().
     */
    public function enviar(Acceso $acc, int $salaId, string $texto, ?int $respondeA, array $ficheros = []): array
    {
        $acc->exigir('ver.chat');
        $sala = $this->exigirMiembro($acc, $salaId);
        $texto = self::normalizar($texto);
        if (mb_strlen($texto) > self::MAX_TEXTO) throw HttpError::validacion('El mensaje es demasiado largo.', 'texto');
        if ($texto === '' && !$ficheros) throw HttpError::validacion('Escribe un mensaje o adjunta algo.', 'texto');
        if ($respondeA) {
            $orig = $this->chat->mensaje($respondeA);
            if (!$orig || (int)$orig['room_id'] !== $salaId) $respondeA = null;   // como el antiguo: se ignora
        }
        $adj = $ficheros ? $this->adjuntos->guardar($ficheros) : [];
        try {
            $id = $this->chat->insertar($salaId, $acc->adminId, $texto, $respondeA ?: null, $adj ? json_encode($adj, JSON_UNESCAPED_UNICODE) : null);
        } catch (\Throwable $e) {
            $this->adjuntos->borrar($adj);
            throw $e;
        }
        $this->chat->marcarLeido($salaId, $acc->adminId, $id);
        $this->chat->dejarDeEscribir($salaId, $acc->adminId);
        $this->avisar($acc, $sala, $id, $texto, (bool)$adj);
        return $this->formatear([$this->chat->mensaje($id)], $acc->adminId)[0];
    }

    public function editar(Acceso $acc, int $mensajeId, string $texto): array
    {
        $m = $this->propio($acc, $mensajeId);
        if (!empty($m['deleted'])) throw new HttpError(409, 'Ese mensaje ya está eliminado.', 'conflicto');
        $texto = self::normalizar($texto);
        if ($texto === '' && empty(self::leerAdjuntos($m['attach']))) throw HttpError::validacion('El mensaje no puede quedar vacío.', 'texto');
        if (mb_strlen($texto) > self::MAX_TEXTO) throw HttpError::validacion('El mensaje es demasiado largo.', 'texto');
        if ($texto !== (string)$m['body']) $this->chat->editar($mensajeId, $texto);
        return $this->formatear([$this->chat->mensaje($mensajeId)], $acc->adminId)[0];
    }

    public function borrar(Acceso $acc, int $mensajeId): array
    {
        $m = $this->propio($acc, $mensajeId);
        if (empty($m['deleted'])) {
            $this->chat->borrar($mensajeId);
            /* El antiguo dejaba los ficheros en el disco hasta el barrido de huérfanos. */
            $this->adjuntos->borrar(self::leerAdjuntos($m['attach']));
        }
        return $this->formatear([$this->chat->mensaje($mensajeId)], $acc->adminId)[0];
    }

    public function reaccionar(Acceso $acc, int $mensajeId, string $emoji): array
    {
        $acc->exigir('ver.chat');
        $m = $this->chat->mensaje($mensajeId);
        if (!$m || !$this->chat->esMiembro((int)$m['room_id'], $acc->adminId)) throw HttpError::noEncontrado('Mensaje no encontrado.');
        if (!empty($m['deleted'])) throw new HttpError(409, 'Ese mensaje está eliminado.', 'conflicto');
        $emoji = trim($emoji);
        if ($emoji === '' || mb_strlen($emoji) > 16 || strlen($emoji) > 64 || preg_match('/[\s<>&"\'\x00-\x1f]/u', $emoji)) {
            throw HttpError::validacion('Esa reacción no vale.', 'emoji');
        }
        $this->chat->alternarReaccion($mensajeId, $acc->adminId, $emoji);
        return $this->formatear([$this->chat->mensaje($mensajeId)], $acc->adminId)[0];
    }

    public function escribiendo(Acceso $acc, int $salaId): void
    {
        $acc->exigir('ver.chat');
        $this->exigirMiembro($acc, $salaId);
        $this->chat->marcarEscribiendo($salaId, $acc->adminId, time() + self::ESCRIBIENDO_SEG);
    }

    public function marcarLeido(Acceso $acc, int $salaId, int $hasta): void
    {
        $acc->exigir('ver.chat');
        $this->exigirMiembro($acc, $salaId);
        $this->chat->marcarLeido($salaId, $acc->adminId, $hasta);
        $this->chat->borrarAviso($acc->adminId, "chat:$salaId:" . $acc->adminId);
    }

    /**
     * Fichero de un adjunto si quien lo pide es de la sala: [ruta, mime, nombre, en línea].
     * El antiguo los servía a cualquiera del equipo que supiera el nombre.
     */
    public function adjunto(Acceso $acc, int $mensajeId, int $indice): array
    {
        $acc->exigir('ver.chat');
        $m = $this->chat->mensaje($mensajeId);
        if (!$m || !$this->chat->esMiembro((int)$m['room_id'], $acc->adminId) || !empty($m['deleted'])) throw HttpError::noEncontrado('Adjunto no encontrado.');
        $a = self::leerAdjuntos($m['attach'])[$indice] ?? null;
        $ruta = $a ? $this->adjuntos->ruta($a['fn']) : null;
        if (!$ruta) throw HttpError::noEncontrado('Adjunto no encontrado.');
        [$mime, $enLinea] = Adjuntos::comoServir($a['fn']);
        return [$ruta, $mime, $a['orig'], $enLinea];
    }

    /* ---------- Avisador global ---------- */

    /**
     * Mensajes de otros en mis salas desde $despues (para el pop-up de
     * cualquier página). Con $despues < 0 (la primera vez) fija la línea base
     * (`max`) y no devuelve nada.
     */
    public function avisos(Acceso $acc, int $despues, bool $activo): array
    {
        $acc->exigir('ver.chat');
        $yo = $acc->adminId;
        $this->presencia->tocar($yo, $activo);
        $silencio = array_map('trim', explode(',', (string)get_setting('notifmute_' . $yo, '')));
        $out = ['mensajes' => [], 'max' => $despues, 'no_leidos' => $this->chat->noLeidosTotal($yo), 'silenciado' => in_array('chat', $silencio, true)];
        if ($despues < 0) {
            $out['max'] = $this->chat->ultimoIdEnMisSalas($yo);
            return $out;
        }
        foreach ($this->chat->nuevosParaMi($yo, $despues, 30) as $r) {
            $autor = $this->equipo->persona((int)$r['admin_id']);
            $grupo = $r['type'] === 'group';
            $texto = self::extracto((string)$r['body'], 120);
            $out['mensajes'][] = [
                'id' => (int)$r['id'],
                'sala_id' => (int)$r['room_id'],
                'autor_id' => $autor['id'],
                'autor' => $autor['username'] ?: '?',
                'foto' => $autor['foto'],
                'texto' => $texto !== '' ? $texto : (self::leerAdjuntos($r['attach']) ? '📎 Adjunto' : ''),
                'grupo' => $grupo,
                'sala' => $grupo ? (trim((string)$r['name']) ?: 'Grupo') : ($autor['username'] ?: 'Directo'),
            ];
            $out['max'] = max($out['max'], (int)$r['id']);
        }
        return $out;
    }

    /** Mensajes sin leer de una persona en todas sus salas (contador del menú: /v1/nav). */
    public function noLeidos(int $adminId): int
    {
        return $this->chat->noLeidosTotal($adminId);
    }

    /** Latido + estado de todo el equipo activo: {id: {estado, texto}}. */
    public function presenciaEquipo(Acceso $acc, bool $activo): object
    {
        $this->presencia->tocar($acc->adminId, $activo);
        return (object)$this->presencia->estados(array_column($this->equipo->activos(), 'id'));
    }

    /* ---------- Formato ---------- */

    /** Mensajes en el formato de la API (con cita, adjuntos y reacciones). */
    public function formatear(array $filas, int $yo): array
    {
        $filas = array_values(array_filter($filas));
        if (!$filas) return [];
        $ids = array_map(fn($f) => (int)$f['id'], $filas);
        $citas = $this->chat->mensajesPorIds(array_filter(array_map(fn($f) => (int)($f['reply_to'] ?? 0), $filas)));
        $reacc = [];
        foreach ($this->chat->reacciones($ids) as $r) {
            $mid = (int)$r['message_id'];
            $e = (string)$r['emoji'];
            $reacc[$mid][$e] ??= ['emoji' => $e, 'total' => 0, 'mio' => false, 'personas' => []];
            $reacc[$mid][$e]['total']++;
            $reacc[$mid][$e]['personas'][] = $this->equipo->persona((int)$r['admin_id'])['username'];
            if ((int)$r['admin_id'] === $yo) $reacc[$mid][$e]['mio'] = true;
        }
        $out = [];
        foreach ($filas as $f) {
            $id = (int)$f['id'];
            $borrado = !empty($f['deleted']);
            $cita = null;
            if (!empty($f['reply_to']) && isset($citas[(int)$f['reply_to']])) {
                $c = $citas[(int)$f['reply_to']];
                $cita = [
                    'id' => (int)$c['id'],
                    'autor' => $this->equipo->persona((int)$c['admin_id'])['username'] ?: '?',
                    'extracto' => !empty($c['deleted']) ? 'mensaje eliminado' : (self::extracto((string)$c['body'], 80) ?: (self::leerAdjuntos($c['attach']) ? '📎 Adjunto' : '')),
                ];
            }
            $adj = [];
            if (!$borrado) {
                foreach (self::leerAdjuntos($f['attach']) as $i => $a) {
                    [$mime] = Adjuntos::comoServir($a['fn']);
                    $adj[] = ['indice' => $i, 'nombre' => $a['orig'], 'imagen' => (bool)$a['img'], 'mime' => $a['mime'] ?: $mime,
                              'tamano' => $a['size'], 'url' => "/api/v1/chat/adjuntos/$id/$i"];
                }
            }
            $autor = $this->equipo->persona((int)$f['admin_id']);
            $out[] = [
                'id' => $id,
                'sala_id' => (int)$f['room_id'],
                'autor_id' => (int)$f['admin_id'],
                'autor' => $autor['username'] !== '' ? $autor['username'] : '?',
                'foto' => $autor['foto'],
                'texto' => $borrado ? '' : (string)$f['body'],
                'creado' => (string)$f['created_at'],
                'editado' => !empty($f['edited']),
                'borrado' => $borrado,
                'responde_a' => $cita,
                'adjuntos' => $adj,
                'reacciones' => array_values($reacc[$id] ?? []),
            ];
        }
        return $out;
    }

    /** @return array<int, array{fn:string, orig:string, img:bool, mime:string, size:?int}> */
    public static function leerAdjuntos(mixed $json): array
    {
        if (!is_string($json) || $json === '') return [];
        $d = json_decode($json, true);
        if (!is_array($d)) return [];
        $out = [];
        foreach ($d as $a) {
            if (!is_array($a) || trim((string)($a['fn'] ?? '')) === '') continue;
            $out[] = ['fn' => (string)$a['fn'], 'orig' => (string)($a['orig'] ?? $a['fn']), 'img' => !empty($a['img']),
                      'mime' => (string)($a['mime'] ?? ''), 'size' => isset($a['size']) ? (int)$a['size'] : null];
        }
        return $out;
    }

    public static function extracto(string $texto, int $n): string
    {
        $t = trim((string)preg_replace('/\s+/u', ' ', $texto));
        return mb_strlen($t) > $n ? rtrim(mb_substr($t, 0, $n - 1)) . '…' : $t;
    }

    /** Saltos de línea normalizados, sin espacios sobrantes al final ni caracteres de control. */
    public static function normalizar(string $t): string
    {
        $t = str_replace(["\r\n", "\r"], "\n", $t);
        $t = (string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $t);
        return trim($t);
    }

    /** @return string[] nombres de usuario mencionados con @ (sin repetir, en minúsculas) */
    public static function menciones(string $texto): array
    {
        if (!preg_match_all('/(?<![\p{L}0-9_.\-])@([\p{L}0-9_.\-]{2,40})/u', $texto, $m)) return [];
        return array_values(array_unique(array_map(fn($n) => mb_strtolower(rtrim($n, '.-')), $m[1])));
    }

    /* ---------- Internos ---------- */

    private function cursor(): string
    {
        /* Dos segundos de solape: un cambio que se confirmó justo mientras se
           leía no se pierde (como mucho llega dos veces y el front lo sustituye). */
        $t = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $this->chat->ahora()) ?: new \DateTimeImmutable();
        return $t->modify('-2 seconds')->format('Y-m-d H:i:s.v');
    }

    private static function cursorValido(string $c): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $c);
    }

    private function exigirMiembro(Acceso $acc, int $salaId): array
    {
        $sala = $salaId > 0 ? $this->chat->sala($salaId) : null;
        if (!$sala || !$this->chat->esMiembro($salaId, $acc->adminId)) throw HttpError::noEncontrado('Conversación no encontrada.');
        return $sala;
    }

    private function grupo(Acceso $acc, int $salaId): array
    {
        $acc->exigir('ver.chat');
        $sala = $this->exigirMiembro($acc, $salaId);
        if ($sala['type'] !== 'group') throw new HttpError(409, 'Esto solo se puede hacer en un grupo.', 'conflicto');
        return $sala;
    }

    private function propio(Acceso $acc, int $mensajeId): array
    {
        $acc->exigir('ver.chat');
        $m = $this->chat->mensaje($mensajeId);
        if (!$m || !$this->chat->esMiembro((int)$m['room_id'], $acc->adminId)) throw HttpError::noEncontrado('Mensaje no encontrado.');
        if ((int)$m['admin_id'] !== $acc->adminId) throw new HttpError(403, 'Solo puedes cambiar tus propios mensajes.', 'permiso');
        return $m;
    }

    private function activo(int $id): bool
    {
        foreach ($this->equipo->activos() as $p) if ($p['id'] === $id) return true;
        return false;
    }

    /**
     * Aviso en la campana para cada miembro (uno vivo por sala y persona, que se
     * sustituye por el último mensaje) y aviso aparte a quien mencionan en un grupo.
     */
    private function avisar(Acceso $acc, array $sala, int $mensajeId, string $texto, bool $conAdjuntos): void
    {
        if (!function_exists('notif_add')) return;
        $salaId = (int)$sala['id'];
        $yo = $acc->adminId;
        $autor = $this->equipo->persona($yo)['username'];
        $nombreSala = $sala['type'] === 'group' ? trim((string)$sala['name']) : '';
        $resumen = $texto !== '' ? self::extracto($texto, 120) : ($conAdjuntos ? '📎 Adjunto' : '');
        $miembros = array_diff($this->chat->miembros([$salaId])[$salaId] ?? [], [$yo]);
        foreach ($miembros as $uid) {
            $ref = "chat:$salaId:$uid";
            $this->chat->borrarAviso($uid, $ref);
            notif_add($uid, 'chat', $nombreSala !== '' ? "ha escrito en «{$nombreSala}»" : 'te ha escrito por chat', $resumen, "/chat/$salaId", $ref, $nombreSala, $autor);
        }
        if ($sala['type'] !== 'group' || $texto === '') return;
        $porNombre = [];
        foreach ($miembros as $uid) $porNombre[mb_strtolower($this->equipo->persona($uid)['username'])] = $uid;
        foreach (self::menciones($texto) as $n) {
            $uid = $porNombre[$n] ?? 0;
            if (!$uid) continue;
            notif_add($uid, 'mencion', 'te ha mencionado en «' . ($nombreSala ?: 'Grupo') . '»', $resumen, "/chat/$salaId", "chatmen:$mensajeId:$uid", $nombreSala, $autor);
        }
    }
}
