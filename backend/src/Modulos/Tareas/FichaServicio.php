<?php
namespace Croilab\Modulos\Tareas;

use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;

/* Lo que se hace dentro de la ficha de una tarea (task.php): lista de control,
   comentarios (respuestas, reacciones, menciones, su propia checklist),
   adjuntos, ficheros de la descripción y horas imputadas.

   Permisos: ver la ficha = ver.tareas + alcance de la tarea. Escribir en ella
   (checklist, adjuntos) = general.editar + tareas.editar; comentar y
   reaccionar = general.editar (como en el antiguo); horas = tareas.horas. */
class FichaServicio
{
    public const MAX_COMENTARIO = 60000;
    public const MAX_PUNTO = 500;

    public function __construct(
        private readonly TareasRepositorio $tareas,
        private readonly FichaRepositorio $ficha,
        private readonly TareasServicio $servicio,
        private readonly EquipoRepositorio $equipo,
        private readonly ArchivosTarea $archivos
    ) {}

    /** Todo lo que pinta la ficha salvo los comentarios. */
    public function ficha(Acceso $acc, int $id): array
    {
        $t = $this->servicio->detalle($acc, $id);
        $t['checklist'] = $this->ficha->checklist($id);
        $t['adjuntos'] = $this->ficha->adjuntos($id);
        $t['tiempo'] = $this->ficha->tiempo($id);
        $t['permisos'] = [
            'editar' => $acc->puede('general.editar') && $acc->puede('tareas.editar'),
            'borrar' => $acc->puede('general.editar') && $acc->puede('tareas.borrar'),
            'comentar' => $acc->puede('general.editar'),
            'horas' => $acc->puede('general.editar') && $acc->puede('tareas.horas'),
        ];
        return $t;
    }

    /** Feed de comentarios (con `despues` solo los nuevos). */
    public function comentarios(Acceso $acc, int $id, int $despues = 0): array
    {
        $this->ver($acc, $id);
        return ['items' => $this->ficha->comentarios($id, $acc->adminId, $despues)] + $this->ficha->resumenComentarios($id);
    }

    /* ---------- Lista de control ---------- */

    public function crearPunto(Acceso $acc, int $id, array $d): array
    {
        $t = $this->editable($acc, $id);
        $texto = $this->textoPunto($d['texto'] ?? null);
        $asig = $this->servicio->idsPersonas($d['asignados'] ?? [], 'asignados');
        $chk = $this->ficha->crearPunto($id, $texto);
        if ($asig) $this->ficha->fijarAsignadosPunto($chk, $asig);
        $yo = $this->nombre($acc);
        foreach ($asig as $a) notif_check_assigned($id, $a, $texto, $yo);
        return $this->ficha->checklist((int)$t['id']);
    }

    /** {done?, texto?, asignados?} */
    public function cambiarPunto(Acceso $acc, int $id, int $chk, array $d): array
    {
        $this->editable($acc, $id);
        $p = $this->ficha->punto($id, $chk) ?? throw HttpError::noEncontrado('Ese punto ya no existe.');
        $campos = [];
        if (array_key_exists('texto', $d)) $campos['texto'] = $this->textoPunto($d['texto']);
        if (array_key_exists('done', $d)) $campos['done'] = filter_var($d['done'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $asig = array_key_exists('asignados', $d) ? $this->servicio->idsPersonas($d['asignados'], 'asignados') : null;
        if (!$campos && $asig === null) throw HttpError::validacion('No hay nada que cambiar.');
        $this->ficha->actualizarPunto($chk, $campos);
        $yo = $this->nombre($acc);
        $texto = $campos['texto'] ?? (string)$p['texto'];
        if (isset($campos['done']) && $campos['done'] !== (int)$p['done']) notif_check_done($id, $chk, $campos['done'] === 1, $texto, $yo);
        if ($asig !== null) {
            $antes = $this->ficha->asignadosPunto($chk);
            $this->ficha->fijarAsignadosPunto($chk, $asig);
            foreach (array_diff($asig, $antes) as $a) notif_check_assigned($id, (int)$a, $texto, $yo);
        }
        return $this->ficha->checklist($id);
    }

    public function borrarPunto(Acceso $acc, int $id, int $chk): array
    {
        $this->editable($acc, $id);
        if (!$this->ficha->punto($id, $chk)) throw HttpError::noEncontrado('Ese punto ya no existe.');
        $this->ficha->borrarPunto($chk);
        return $this->ficha->checklist($id);
    }

    public function ordenarPuntos(Acceso $acc, int $id, mixed $ids): array
    {
        $this->editable($acc, $id);
        if (!is_array($ids) || !$ids) throw HttpError::validacion('Falta el orden.', 'ids');
        $this->ficha->reordenarPuntos($id, array_map('intval', $ids));
        return $this->ficha->checklist($id);
    }

    /* ---------- Comentarios ---------- */

    /**
     * Comentario nuevo: cuerpo, ficheros (ya normalizados con ArchivosTarea::deFiles),
     * checklist [{texto, done, resp}] y respuesta a otro. Hace falta al menos una de
     * las tres cosas. Avisa a mencionados, al responsable, a quien se responde y a
     * los responsables de la checklist.
     */
    public function comentar(Acceso $acc, int $id, array $d, array $ficheros, bool $subidos = true): array
    {
        $acc->exigir('ver.tareas', 'general.editar');
        $this->servicio->visible($acc, $id);
        $t = $this->tareas->buscar($id) ?? throw HttpError::noEncontrado('Tarea no encontrada.');
        $cuerpo = $this->cuerpo($d['cuerpo'] ?? '');
        $checklist = $this->checklistComentario($d['checklist'] ?? null);
        $reply = (int)($d['reply_to'] ?? 0) ?: null;
        if ($reply && !$this->ficha->comentario($id, $reply)) $reply = null;
        if (trim($cuerpo) === '' && !$ficheros && !$checklist) throw HttpError::validacion('Escribe algo o adjunta un archivo.', 'cuerpo');
        if (count($ficheros) > 20) throw HttpError::validacion('Como mucho 20 archivos por comentario.', 'archivos');

        /* Primero los ficheros: si alguno no vale, no queda un comentario a medias. */
        $guardados = [];
        try {
            foreach ($ficheros as $f) $guardados[] = $this->archivos->guardar($f, $id, $subidos);
        } catch (\Throwable $e) {
            foreach ($guardados as [$fn]) $this->archivos->borrar($fn);
            throw $e;
        }
        $cid = $this->ficha->crearComentario($id, $acc->adminId ?: null, $cuerpo, $checklist ?: null, $reply);
        foreach ($guardados as [$fn, $orig, $mime]) $this->ficha->crearAdjunto($id, $cid, $fn, $orig, $mime, $acc->adminId);

        $yo = $this->nombre($acc);
        notif_comment_scan($id, $cid, $cuerpo, $yo);
        if ($reply) {
            $orig = $this->ficha->comentario($id, $reply);
            $aid = (int)($orig['admin_id'] ?? 0);
            /* Si además se le menciona, ya tiene su aviso: no se le avisa dos veces. */
            if ($aid && $aid !== $acc->adminId && !in_array($aid, notif_menciones($cuerpo), true)) {
                $resumen = TextoRico::extracto($cuerpo, 140) ?: 'un archivo';
                notif_add($aid, 'tarea', 'te ha respondido: ' . $resumen, '', '/tareas/' . $id . '#c' . $cid, 'reply:' . $cid . ':' . $aid, (string)$t['titulo'], $yo);
            }
        }
        foreach ($checklist as $it) if ($it['resp']) notif_check_assigned($id, $it['resp'], $it['texto'], $yo);
        return $this->ficha->comentarios($id, $acc->adminId, $cid - 1)[0] ?? [];
    }

    /** {cuerpo?} solo su autor; {checklist?} cualquiera que pueda comentar. */
    public function editarComentario(Acceso $acc, int $id, int $cid, array $d): array
    {
        $acc->exigir('ver.tareas', 'general.editar');
        $this->servicio->visible($acc, $id);
        $c = $this->ficha->comentario($id, $cid) ?? throw HttpError::noEncontrado('Ese comentario ya no existe.');
        $campos = [];
        $yo = $this->nombre($acc);
        if (array_key_exists('cuerpo', $d)) {
            if ((int)$c['admin_id'] !== $acc->adminId) throw new HttpError(403, 'Solo quien lo escribió puede editar un comentario.', 'permiso');
            $campos['cuerpo'] = $this->cuerpo($d['cuerpo']);
            if (trim($campos['cuerpo']) === '') throw HttpError::validacion('El comentario no puede quedar vacío.', 'cuerpo');
        }
        if (array_key_exists('checklist', $d)) $campos['checklist'] = $this->checklistComentario($d['checklist']);
        if (!$campos) throw HttpError::validacion('No hay nada que cambiar.');
        $this->ficha->editarComentario($cid, $campos);
        /* Menciones nuevas al editar: el `ref` evita repetir las ya avisadas. */
        if (isset($campos['cuerpo'])) notif_comment_scan($id, $cid, $campos['cuerpo'], $yo);
        if (isset($campos['checklist'])) {
            $antes = array_filter(array_column(FichaRepositorio::checklistComentario($c['checklist_json']), 'resp'));
            foreach ($campos['checklist'] as $it) {
                if ($it['resp'] && !in_array($it['resp'], $antes, true)) notif_check_assigned($id, $it['resp'], $it['texto'], $yo);
            }
        }
        return $this->ficha->comentarios($id, $acc->adminId, $cid - 1)[0] ?? [];
    }

    /** Marca/desmarca el punto `idx` de la checklist de un comentario. */
    public function marcarPuntoComentario(Acceso $acc, int $id, int $cid, int $idx, ?bool $hecho = null): array
    {
        $acc->exigir('ver.tareas', 'general.editar');
        $this->servicio->visible($acc, $id);
        $c = $this->ficha->comentario($id, $cid) ?? throw HttpError::noEncontrado('Ese comentario ya no existe.');
        $lista = FichaRepositorio::checklistComentario($c['checklist_json']);
        if (!isset($lista[$idx])) throw HttpError::noEncontrado('Ese punto ya no existe.');
        $lista[$idx]['done'] = $hecho ?? !$lista[$idx]['done'];
        $this->ficha->editarComentario($cid, ['checklist' => $this->paraGuardar($lista)]);
        return $lista;
    }

    public function borrarComentario(Acceso $acc, int $id, int $cid): void
    {
        $acc->exigir('ver.tareas', 'general.editar');
        $this->servicio->visible($acc, $id);
        $c = $this->ficha->comentario($id, $cid) ?? throw HttpError::noEncontrado('Ese comentario ya no existe.');
        /* El antiguo borraba los adjuntos aunque el comentario no fuera tuyo. */
        if ((int)$c['admin_id'] !== $acc->adminId) throw new HttpError(403, 'Solo quien lo escribió puede borrar un comentario.', 'permiso');
        foreach ($this->ficha->borrarComentario($cid) as $fn) {
            if (!$this->ficha->ficheroEnUso((string)$fn)) $this->archivos->borrar((string)$fn);
        }
    }

    public function reaccionar(Acceso $acc, int $id, int $cid, mixed $emoji): array
    {
        $acc->exigir('ver.tareas', 'general.editar');
        $this->servicio->visible($acc, $id);
        if (!$this->ficha->comentario($id, $cid)) throw HttpError::noEncontrado('Ese comentario ya no existe.');
        $e = is_string($emoji) ? trim($emoji) : '';
        /* Un emoji (con modificadores y ZWJ cabe en 16 bytes en la mayoría); nada de texto. */
        if ($e === '' || strlen($e) > 16 || preg_match('/[\p{L}\p{N}<>"\'&]/u', $e)) throw HttpError::validacion('Reacción no válida.', 'emoji');
        $this->ficha->alternarReaccion($cid, $acc->adminId, $e);
        return $this->ficha->comentarios($id, $acc->adminId, $cid - 1)[0]['reacciones'] ?? [];
    }

    /* ---------- Adjuntos y ficheros de la descripción ---------- */

    /** Adjuntos de la tarea (zona «Adjuntos» y soltar en la ficha). */
    public function adjuntar(Acceso $acc, int $id, array $ficheros, bool $subidos = true): array
    {
        $this->editable($acc, $id);
        if (!$ficheros) throw HttpError::validacion('No ha llegado ningún archivo.', 'archivos');
        if (count($ficheros) > 20) throw HttpError::validacion('Como mucho 20 archivos de una vez.', 'archivos');
        $guardados = [];
        try {
            foreach ($ficheros as $f) $guardados[] = $this->archivos->guardar($f, $id, $subidos);
        } catch (\Throwable $e) {
            foreach ($guardados as [$fn]) $this->archivos->borrar($fn);
            throw $e;
        }
        foreach ($guardados as [$fn, $orig, $mime]) $this->ficha->crearAdjunto($id, null, $fn, $orig, $mime, $acc->adminId);
        $this->tareas->anotar($id, $acc->adminId, 'adjunto', implode(', ', array_column($guardados, 1)));
        return $this->ficha->adjuntos($id);
    }

    public function quitarAdjunto(Acceso $acc, int $id, int $aid): array
    {
        $this->editable($acc, $id);
        $a = $this->ficha->adjunto1($id, $aid);
        if (!$a || $a['comment_id'] !== null) throw HttpError::noEncontrado('Ese adjunto ya no existe.');
        $this->ficha->borrarAdjunto($aid);
        if (!$this->ficha->ficheroEnUso((string)$a['filename'])) $this->archivos->borrar((string)$a['filename']);
        return $this->ficha->adjuntos($id);
    }

    /** Imagen o archivo incrustado en la descripción: sin fila, vive en el texto. */
    public function subirParaDescripcion(Acceso $acc, int $id, array $ficheros, bool $subidos = true): array
    {
        $this->editable($acc, $id);
        if (!$ficheros) throw HttpError::validacion('No ha llegado ningún archivo.', 'archivos');
        $out = [];
        foreach (array_slice($ficheros, 0, 20) as $f) {
            [$fn, $orig, , $img] = $this->archivos->guardar($f, $id, $subidos);
            $out[] = ['fn' => $fn, 'nombre' => $orig, 'imagen' => $img, 'url' => 'archivo.php?d=tasks&f=' . rawurlencode($fn)];
        }
        return $out;
    }

    /* ---------- Horas ---------- */

    /** {horas: "1,5" | 1.5, admin_id?} → la línea «Horas de la tarea» de esa persona. */
    public function fijarTiempo(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('ver.tareas', 'general.editar', 'tareas.horas');
        $this->servicio->visible($acc, $id);
        $t = $this->tareas->buscar($id) ?? throw HttpError::noEncontrado('Tarea no encontrada.');
        $quien = (int)($d['admin_id'] ?? 0) ?: $acc->adminId;
        if (!$this->equipo->existe($quien)) throw HttpError::validacion('Esa persona no existe.', 'admin_id');
        $minutos = self::minutos($d['horas'] ?? null);
        if ($minutos === null) throw HttpError::validacion('Escribe las horas con números (por ejemplo 1,5).', 'horas');
        if ($minutos > 1000 * 60) throw HttpError::validacion('Son demasiadas horas para una tarea.', 'horas');
        /* Corregir no cambia de mes la línea: conserva su fecha (el antiguo la
           movía a hoy y cambiaba en qué mes se facturaba). */
        $fecha = $this->ficha->fechaTiempo($id, $quien);
        $this->ficha->fijarTiempo($id, (int)$t['client_id'], $quien, $minutos);
        if ($fecha && $minutos > 0) $this->ficha->moverFechaTiempo($id, $quien, $fecha);
        $this->tareas->anotar($id, $acc->adminId, 'tiempo', $this->equipo->persona($quien)['username'] . ': ' . self::horasTexto($minutos));
        return $this->ficha->tiempo($id);
    }

    /** «1,5» / «1.5» / 2 → minutos (redondeados); «» → 0; null si no es un número. */
    public static function minutos(mixed $v): ?int
    {
        if ($v === null || $v === '') return 0;
        if (!is_scalar($v)) return null;
        $s = str_replace(',', '.', trim((string)$v));
        if (!preg_match('/^\d+(\.\d+)?$/', $s)) return null;
        return (int)round(((float)$s) * 60);
    }

    public static function horasTexto(int $min): string
    {
        $h = $min / 60;
        return rtrim(rtrim(number_format($h, 2, ',', ''), '0'), ',') . ' h';
    }

    /* ---------- Auxiliares ---------- */

    private function ver(Acceso $acc, int $id): void
    {
        $acc->exigir('ver.tareas');
        $this->servicio->visible($acc, $id);
    }

    private function editable(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.tareas', 'general.editar', 'tareas.editar');
        $this->servicio->visible($acc, $id);
        return $this->tareas->buscar($id) ?? throw HttpError::noEncontrado('Tarea no encontrada.');
    }

    private function textoPunto(mixed $v): string
    {
        if (!is_scalar($v)) throw HttpError::validacion('Escribe el punto.', 'texto');
        $t = trim(preg_replace('/\s+/u', ' ', (string)$v));
        if ($t === '') throw HttpError::validacion('Escribe el punto.', 'texto');
        if (mb_strlen($t) > self::MAX_PUNTO) throw HttpError::validacion('El punto es demasiado largo.', 'texto');
        return $t;
    }

    private function cuerpo(mixed $v): string
    {
        if ($v !== null && !is_scalar($v)) throw HttpError::validacion('Valor no válido.', 'cuerpo');
        $c = rtrim(str_replace("\r\n", "\n", (string)$v));
        if (mb_strlen($c) > self::MAX_COMENTARIO) throw HttpError::validacion('El comentario es demasiado largo.', 'cuerpo');
        return $c;
    }

    /** Checklist de un comentario: JSON (multipart) o lista; textos vacíos fuera. */
    private function checklistComentario(mixed $v): array
    {
        if ($v === null || $v === '') return [];
        if (is_string($v)) $v = json_decode($v, true);
        if (!is_array($v) || !array_is_list($v)) throw HttpError::validacion('Lista de control no válida.', 'checklist');
        $out = [];
        foreach (array_slice($v, 0, 50) as $it) {
            if (!is_array($it)) continue;
            $t = trim(preg_replace('/\s+/u', ' ', (string)($it['texto'] ?? '')));
            if ($t === '') continue;
            $resp = (int)($it['resp'] ?? 0);
            $out[] = ['texto' => mb_substr($t, 0, self::MAX_PUNTO), 'done' => !empty($it['done']), 'resp' => $resp && $this->equipo->existe($resp) ? $resp : null];
        }
        return $this->paraGuardar($out);
    }

    /* Mismo JSON que el antiguo: done 0/1. */
    private function paraGuardar(array $lista): array
    {
        return array_map(fn($it) => ['texto' => $it['texto'], 'done' => $it['done'] ? 1 : 0, 'resp' => $it['resp']], $lista);
    }

    private function nombre(Acceso $acc): string
    {
        return (string)$this->equipo->persona($acc->adminId)['username'];
    }
}
