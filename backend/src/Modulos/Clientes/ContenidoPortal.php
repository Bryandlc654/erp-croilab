<?php
namespace Croilab\Modulos\Clientes;

use Croilab\Http\HttpError;

/* Los bloques JSON de `clients` que pinta el portal del cliente (estado, plan,
   accesos, progreso por mes). Antes se guardaban tal cual llegaban del
   formulario o del editor en vivo; aquí se validan forma, tamaños y enlaces, y
   se leen siempre con la misma forma aunque la fila tenga basura antigua.
   Sin base de datos: se prueba sola (tests/Unit/Clientes). */
final class ContenidoPortal
{
    public const ESTADOS_FASE = ['done', 'now', ''];
    public const TIPOS_ACCESO = ['figma', 'drive', 'web', 'looker', 'generic'];
    public const GRUPOS_PROGRESO = ['completado', 'pendiente'];
    public const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    /* ---------- Lectura (de la base al JSON de la API) ---------- */

    /** @return array{nombre:string, etiqueta:string, siguiente:string, fases:list<array{t:string,s:string,estado:string}>} */
    public static function leerEstado(?string $json): array
    {
        $d = self::decodificar($json);
        $fases = [];
        foreach (self::lista($d['fases'] ?? null) as $f) {
            if (!is_array($f)) continue;
            $estado = (string)($f['estado'] ?? '');
            $fases[] = ['t' => self::txt($f['t'] ?? ''), 's' => self::txt($f['s'] ?? ''), 'estado' => in_array($estado, self::ESTADOS_FASE, true) ? $estado : ''];
        }
        return ['nombre' => self::txt($d['nombre'] ?? ''), 'etiqueta' => self::txt($d['etiqueta'] ?? ''), 'siguiente' => self::txt($d['siguiente'] ?? ''), 'fases' => $fases];
    }

    /** @return array{resumen:string, items:list<array{n:string,t:string}>, detalle:list<array{h:string,p:string}>} */
    public static function leerPlan(?string $json): array
    {
        $d = self::decodificar($json);
        $items = [];
        foreach (self::lista($d['items'] ?? null) as $i) {
            if (is_array($i)) $items[] = ['n' => self::txt($i['n'] ?? ''), 't' => self::txt($i['t'] ?? '')];
        }
        $detalle = [];
        foreach (self::lista($d['detalle'] ?? null) as $i) {
            if (is_array($i)) $detalle[] = ['h' => self::txt($i['h'] ?? ''), 'p' => self::txt($i['p'] ?? '')];
        }
        return ['resumen' => self::txt($d['resumen'] ?? ''), 'items' => $items, 'detalle' => $detalle];
    }

    /** @return list<array{b:string,s:string,u:string,tipo:string}> */
    public static function leerAccesos(?string $json): array
    {
        $out = [];
        foreach (self::lista(self::decodificar($json, true)) as $a) {
            if (!is_array($a)) continue;
            $tipo = (string)($a['tipo'] ?? 'generic');
            $u = self::txt($a['u'] ?? '');
            $out[] = [
                'b' => self::txt($a['b'] ?? ''),
                's' => self::txt($a['s'] ?? ''),
                /* '#' es como el antiguo guardaba «sin enlace». */
                'u' => $u === '#' ? '' : $u,
                'tipo' => in_array($tipo, self::TIPOS_ACCESO, true) ? $tipo : 'generic',
            ];
        }
        return $out;
    }

    /** Progreso por mes: [mes => {completado:[{t,d}], pendiente:[{t,d}]}]. Como lista para no perder el orden en JSON. */
    public static function leerProgreso(?string $json): array
    {
        $d = self::decodificar($json);
        $out = [];
        foreach ($d as $mes => $grupos) {
            if (!is_array($grupos)) continue;
            $fila = ['mes' => (string)$mes, 'completado' => [], 'pendiente' => []];
            foreach (self::GRUPOS_PROGRESO as $g) {
                foreach (self::lista($grupos[$g] ?? null) as $t) {
                    if (is_array($t)) $fila[$g][] = ['t' => self::txt($t['t'] ?? ''), 'd' => self::txt($t['d'] ?? '')];
                }
            }
            $out[] = $fila;
        }
        return $out;
    }

    /** null = ve todos los servicios (el antiguo lo trataba así); lista = solo esos. */
    public static function leerServicios(?string $json): ?array
    {
        $raw = trim((string)$json);
        if ($raw === '') return null;
        $d = json_decode($raw, true);
        if (!is_array($d)) return null;
        return array_values(array_filter(array_map(fn($s) => is_scalar($s) ? trim((string)$s) : '', $d), 'strlen'));
    }

    /** Métricas por mes en el orden guardado: [{mes, ll, wa, fo, vi, ap, ctr, total}]. */
    public static function leerMetricas(?string $json): array
    {
        $out = [];
        foreach (self::decodificar($json) as $mes => $m) {
            if (!is_array($m)) continue;
            $n = fn($k) => is_numeric($m[$k] ?? null) ? (int)$m[$k] : 0;
            $fila = ['mes' => (string)$mes, 'll' => $n('ll'), 'wa' => $n('wa'), 'fo' => $n('fo'), 'vi' => $n('vi'), 'ap' => $n('ap'),
                     'ctr' => is_numeric($m['ctr'] ?? null) ? round((float)$m['ctr'], 2) : 0.0];
            $fila['total'] = $fila['ll'] + $fila['wa'] + $fila['fo'];
            $out[] = $fila;
        }
        return $out;
    }

    /* ---------- Escritura (del cuerpo de la petición a la base) ---------- */

    /** Valida el estado del proyecto y devuelve el JSON a guardar. Las fases sin nombre se descartan. */
    public static function estado(mixed $v): string
    {
        $d = self::objeto($v, 'estado');
        $fases = [];
        foreach (self::listaDe($d['fases'] ?? [], 'estado', 30) as $f) {
            $f = self::objeto($f, 'estado');
            $t = self::texto($f['t'] ?? '', 120, 'estado');
            if ($t === '') continue;
            $estado = (string)($f['estado'] ?? '');
            if (!in_array($estado, self::ESTADOS_FASE, true)) throw HttpError::validacion('Estado de fase desconocido.', 'estado');
            $fases[] = ['t' => $t, 's' => self::texto($f['s'] ?? '', 120, 'estado'), 'estado' => $estado];
        }
        return self::json([
            'nombre' => self::texto($d['nombre'] ?? '', 200, 'estado'),
            'etiqueta' => self::texto($d['etiqueta'] ?? '', 80, 'estado'),
            'siguiente' => self::texto($d['siguiente'] ?? '', 2000, 'estado'),
            'fases' => $fases,
        ]);
    }

    public static function plan(mixed $v): string
    {
        $d = self::objeto($v, 'plan');
        $items = [];
        foreach (self::listaDe($d['items'] ?? [], 'plan', 60) as $i) {
            $i = self::objeto($i, 'plan');
            $n = self::texto($i['n'] ?? '', 20, 'plan');
            $t = self::texto($i['t'] ?? '', 200, 'plan');
            if ($n !== '' || $t !== '') $items[] = ['n' => $n, 't' => $t];
        }
        $detalle = [];
        foreach (self::listaDe($d['detalle'] ?? [], 'plan', 60) as $i) {
            $i = self::objeto($i, 'plan');
            $h = self::texto($i['h'] ?? '', 200, 'plan');
            $p = self::texto($i['p'] ?? '', 4000, 'plan');
            if ($h !== '' || $p !== '') $detalle[] = ['h' => $h, 'p' => $p];
        }
        return self::json(['resumen' => self::texto($d['resumen'] ?? '', 4000, 'plan'), 'items' => $items, 'detalle' => $detalle]);
    }

    public static function accesos(mixed $v): string
    {
        $out = [];
        foreach (self::listaDe($v, 'accesos', 60) as $a) {
            $a = self::objeto($a, 'accesos');
            $b = self::texto($a['b'] ?? '', 160, 'accesos');
            if ($b === '') continue;
            $tipo = (string)($a['tipo'] ?? 'generic');
            if (!in_array($tipo, self::TIPOS_ACCESO, true)) throw HttpError::validacion('Tipo de acceso desconocido.', 'accesos');
            $u = self::url($a['u'] ?? '', 'accesos', 'El enlace de «' . $b . '» no es válido (tiene que empezar por https:// o http://).');
            /* Sin enlace se guarda '#', como el antiguo: así el portal de ahora lo lee igual. */
            $out[] = ['b' => $b, 's' => self::texto($a['s'] ?? '', 300, 'accesos'), 'u' => $u === '' ? '#' : $u, 'tipo' => $tipo];
        }
        return self::json($out);
    }

    /** Recibe la lista [{mes, completado:[{t,d}], pendiente:[{t,d}]}] y guarda el objeto por mes. */
    public static function progreso(mixed $v): string
    {
        $out = [];
        foreach (self::listaDe($v, 'tareas', 36) as $m) {
            $m = self::objeto($m, 'tareas');
            $mes = self::texto($m['mes'] ?? '', 40, 'tareas');
            $grupos = ['completado' => [], 'pendiente' => []];
            foreach (self::GRUPOS_PROGRESO as $g) {
                foreach (self::listaDe($m[$g] ?? [], 'tareas', 100) as $t) {
                    $t = self::objeto($t, 'tareas');
                    $tit = self::texto($t['t'] ?? '', 255, 'tareas');
                    if ($tit === '') continue;
                    $grupos[$g][] = ['t' => $tit, 'd' => self::texto($t['d'] ?? '', 4000, 'tareas')];
                }
            }
            if (!$grupos['completado'] && !$grupos['pendiente']) continue;
            if ($mes === '') throw HttpError::validacion('Cada tarea del progreso necesita su mes.', 'tareas');
            /* Dos filas del mismo mes se juntan: en el JSON el mes es la clave. */
            $out[$mes] ??= ['completado' => [], 'pendiente' => []];
            foreach (self::GRUPOS_PROGRESO as $g) array_push($out[$mes][$g], ...$grupos[$g]);
        }
        return $out ? self::json($out) : '{}';
    }

    /** URL http(s) o vacía. Nada de javascript:, data: ni rutas sueltas. */
    public static function url(mixed $v, string $campo, string $msg = 'El enlace no es válido.', int $max = 500): string
    {
        $u = self::texto($v, $max, $campo);
        if ($u === '' || $u === '#') return '';
        $p = parse_url($u);
        if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || preg_match('/\s/', $u)) {
            throw HttpError::validacion($msg, $campo);
        }
        return $u;
    }

    public static function texto(mixed $v, int $max, string $campo): string
    {
        if ($v !== null && !is_scalar($v)) throw HttpError::validacion('Valor no válido.', $campo);
        $t = trim((string)$v);
        if (mb_strlen($t) > $max) throw HttpError::validacion("Hay un texto demasiado largo (máximo $max caracteres).", $campo);
        return $t;
    }

    /* ---------- Ayudas ---------- */

    private static function decodificar(?string $json, bool $lista = false): array
    {
        $d = json_decode((string)$json, true);
        if (!is_array($d)) return [];
        return $lista ? (array_is_list($d) ? $d : []) : $d;
    }

    private static function lista(mixed $v): array
    {
        return is_array($v) && array_is_list($v) ? $v : [];
    }

    private static function txt(mixed $v): string
    {
        return is_scalar($v) ? trim((string)$v) : '';
    }

    private static function objeto(mixed $v, string $campo): array
    {
        if ($v === null) return [];
        if (!is_array($v) || ($v !== [] && array_is_list($v))) throw HttpError::validacion('Formato no válido.', $campo);
        return $v;
    }

    private static function listaDe(mixed $v, string $campo, int $max): array
    {
        if ($v === null) return [];
        if (!is_array($v) || !array_is_list($v)) throw HttpError::validacion('Formato no válido.', $campo);
        if (count($v) > $max) throw HttpError::validacion("Demasiadas filas (máximo $max).", $campo);
        return $v;
    }

    private static function json(array $d): string
    {
        return json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
