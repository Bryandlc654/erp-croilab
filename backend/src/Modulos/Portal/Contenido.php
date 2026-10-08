<?php
namespace Croilab\Modulos\Portal;

use Croilab\Http\HttpError;
use Croilab\Modulos\Clientes\ContenidoPortal;

/* Lo que el portal lee de los bloques JSON de `clients` que no cubre
   Clientes\ContenidoPortal (métricas con su año, informes, servicios,
   Looker y secciones visibles) y la validación de lo que guarda el editor en
   vivo. Sin base de datos: se prueba sola (tests/Unit/Portal). */
final class Contenido
{
    public const SECCIONES = ['metricas', 'progreso', 'informes', 'como', 'accesos', 'plan'];
    /* Los seis servicios que el portal explica en «Método» (textos en el front). */
    public const SERVICIOS_FIJOS = ['Diseño web', 'SEO', 'SEM', 'CRO', 'Tiendas online', 'Meta'];
    /* Looker Studio solo desde Google: el iframe va dentro del portal del cliente. */
    public const HOSTS_LOOKER = ['lookerstudio.google.com', 'datastudio.google.com'];

    /**
     * Métricas por mes, de la más antigua a la más nueva, con clave YYYY-MM.
     * Si dos entradas caen en el mismo mes (una «Junio» antigua y una «2026-06»),
     * gana la que dice el año.
     */
    public static function metricas(?string $json, \DateTimeImmutable $ref): array
    {
        $d = json_decode((string)$json, true);
        if (!is_array($d)) return [];
        $out = [];
        $explicita = [];
        foreach ($d as $k => $m) {
            if (!is_array($m)) continue;
            $clave = Meses::clave((string)$k, $ref);
            if ($clave === null) continue;
            $conAnio = (bool)preg_match('/\d{4}/', (string)$k);
            if (isset($out[$clave]) && $explicita[$clave] && !$conAnio) continue;
            $n = fn(string $c) => is_numeric($m[$c] ?? null) ? max(0, (int)$m[$c]) : 0;
            $fila = ['clave' => $clave, 'etiqueta' => Meses::etiqueta($clave), 'mes' => Meses::nombre($clave),
                     'll' => $n('ll'), 'wa' => $n('wa'), 'fo' => $n('fo'), 'vi' => $n('vi'), 'ap' => $n('ap'),
                     'ctr' => is_numeric($m['ctr'] ?? null) ? round((float)$m['ctr'], 2) : 0.0,
                     'src' => self::reparto($m['src'] ?? null), 'geo' => self::reparto($m['geo'] ?? null, true)];
            $fila['total'] = $fila['ll'] + $fila['wa'] + $fila['fo'];
            $out[$clave] = $fila;
            $explicita[$clave] = $conAnio;
        }
        return Meses::ordenar(array_values($out));
    }

    /** {canal|país: sesiones} con números positivos, de más a menos. */
    private static function reparto(mixed $v, bool $pais = false): array
    {
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $k => $n) {
            $k = trim((string)$k);
            if ($k === '' || !is_numeric($n) || (int)$n <= 0) continue;
            if ($pais && !preg_match('/^[A-Za-z]{2}$/', $k)) continue;
            $out[$pais ? strtoupper($k) : mb_substr($k, 0, 60)] = (int)$n;
        }
        arsort($out);
        return $out;
    }

    /** Progreso por mes (tareas_json), del más nuevo al más antiguo; «General» al final. */
    public static function progreso(?string $json, \DateTimeImmutable $ref): array
    {
        $por = [];
        foreach (ContenidoPortal::leerProgreso($json) as $m) {
            $clave = Meses::clave($m['mes'], $ref);
            $id = $clave ?? 'x:' . mb_strtolower($m['mes']);
            $por[$id] ??= ['clave' => $clave, 'etiqueta' => $clave ? Meses::etiqueta($clave) : ($m['mes'] !== '' ? $m['mes'] : 'General'), 'completado' => [], 'pendiente' => []];
            array_push($por[$id]['completado'], ...$m['completado']);
            array_push($por[$id]['pendiente'], ...$m['pendiente']);
        }
        return Meses::ordenar(array_values($por), true);
    }

    /** Informes mensuales, del más nuevo al más antiguo (como el antiguo: lo último arriba). */
    public static function informes(?string $json, \DateTimeImmutable $ref): array
    {
        $d = json_decode((string)$json, true);
        if (!is_array($d) || !array_is_list($d)) return [];
        $out = [];
        foreach (array_reverse($d) as $i) {
            if (!is_array($i)) continue;
            $mes = is_scalar($i['mes'] ?? null) ? trim((string)$i['mes']) : '';
            $titulo = is_scalar($i['titulo'] ?? null) ? trim((string)$i['titulo']) : '';
            $texto = is_scalar($i['texto'] ?? null) ? (string)$i['texto'] : '';
            if ($titulo === '' && trim($texto) === '') continue;
            $clave = Meses::clave($mes, $ref);
            $url = is_scalar($i['url'] ?? null) ? trim((string)$i['url']) : '';
            $out[] = ['clave' => $clave, 'mes' => $mes, 'etiqueta' => $clave ? Meses::etiqueta($clave) : ($mes !== '' ? $mes : 'General'),
                      'titulo' => $titulo !== '' ? $titulo : 'Informe', 'texto' => $texto, 'url' => self::urlSegura($url)];
        }
        return Meses::ordenar($out, true);
    }

    /** Lo mismo que el editor guarda, para volver a editarlo (en el orden guardado). */
    public static function informesEditables(?string $json): array
    {
        $d = json_decode((string)$json, true);
        if (!is_array($d) || !array_is_list($d)) return [];
        $out = [];
        foreach ($d as $i) {
            if (!is_array($i)) continue;
            $s = fn(string $k) => is_scalar($i[$k] ?? null) ? (string)$i[$k] : '';
            $out[] = ['mes' => trim($s('mes')), 'titulo' => trim($s('titulo')), 'texto' => $s('texto'), 'url' => self::urlSegura(trim($s('url')))];
        }
        return $out;
    }

    /** Secciones que ve el cliente: las de su tipo; sin tipo, todas y Métricas según «conversiones». */
    public static function secciones(?array $deTipo, bool $conversiones): array
    {
        $s = array_fill_keys(self::SECCIONES, true);
        if ($deTipo !== null) {
            foreach (self::SECCIONES as $k) $s[$k] = !empty($deTipo[$k]);
        } else {
            $s['metricas'] = $conversiones;
        }
        return $s;
    }

    /** Looker solo si es una URL https de Google (lo que había guardado de otra forma no se pinta). */
    public static function lookerSeguro(?string $url): string
    {
        $u = trim((string)$url);
        if ($u === '') return '';
        $p = parse_url($u);
        if (!is_array($p) || strtolower($p['scheme'] ?? '') !== 'https' || !in_array(strtolower($p['host'] ?? ''), self::HOSTS_LOOKER, true)) return '';
        return $u;
    }

    private static function urlSegura(string $u): string
    {
        if ($u === '' || $u === '#') return '';
        $p = parse_url($u);
        return is_array($p) && in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) && !empty($p['host']) && !preg_match('/\s/', $u) ? $u : '';
    }

    /* ---------- Escritura (editor en vivo) ---------- */

    /** [{mes, titulo, texto, url}] → JSON. Filas sin título ni texto se descartan. */
    public static function validarInformes(mixed $v): string
    {
        if ($v === null) return '[]';
        if (!is_array($v) || !array_is_list($v)) throw HttpError::validacion('Formato no válido.', 'informes');
        if (count($v) > 120) throw HttpError::validacion('Demasiados informes (máximo 120).', 'informes');
        $out = [];
        foreach ($v as $i) {
            if (!is_array($i) || ($i !== [] && array_is_list($i))) throw HttpError::validacion('Formato no válido.', 'informes');
            $titulo = ContenidoPortal::texto($i['titulo'] ?? '', 200, 'informes');
            $texto = rtrim(str_replace("\r\n", "\n", (string)ContenidoPortal::texto($i['texto'] ?? '', 20000, 'informes')));
            if ($titulo === '' && $texto === '') continue;
            $out[] = [
                'mes' => ContenidoPortal::texto($i['mes'] ?? '', 40, 'informes'),
                'titulo' => $titulo,
                'texto' => $texto,
                'url' => ContenidoPortal::url($i['url'] ?? '', 'informes', 'El enlace del informe «' . ($titulo ?: 'sin título') . '» no es válido (tiene que empezar por https:// o http://).'),
            ];
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** null = ve todos los servicios; lista = solo esos (sin repetidos). */
    public static function validarServicios(mixed $v): ?string
    {
        if ($v === null) return null;
        if (!is_array($v) || !array_is_list($v)) throw HttpError::validacion('Formato no válido.', 'servicios');
        if (count($v) > 40) throw HttpError::validacion('Demasiados servicios.', 'servicios');
        $out = [];
        foreach ($v as $s) {
            $s = ContenidoPortal::texto($s, 80, 'servicios');
            if ($s !== '' && !in_array(mb_strtolower($s), array_map('mb_strtolower', $out), true)) $out[] = $s;
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    public static function validarLooker(mixed $v): string
    {
        $u = ContenidoPortal::url($v, 'looker', 'El panel de Looker Studio tiene que ser un enlace https://lookerstudio.google.com/embed/…', 1000);
        if ($u !== '' && self::lookerSeguro($u) === '') {
            throw HttpError::validacion('El panel de Looker Studio tiene que ser un enlace https://lookerstudio.google.com/embed/…', 'looker');
        }
        return $u;
    }
}
