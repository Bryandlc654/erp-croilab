<?php
namespace Croilab\Modulos\Crm;

use Croilab\Http\HttpError;

/* Filtros de contactos: los de la pantalla Contactos (03-crm.md §5.1), los
   de las vistas guardadas y las condiciones de las listas activas (§5.6), con
   un solo motor. Entra un array (query string o JSON), sale un array limpio
   y validado, y de él un fragmento SQL con sus parámetros. */
final class Filtros
{
    public const QUICK = ['sin_contactar', 'act7', 'act30', 'vencidas', 'perdido'];
    /* Claves que guarda una vista o una lista (el orden también, en las vistas). */
    public const CLAVES = ['q', 'sector', 'origen', 'fase', 'servicio', 'prop', 'tag', 'vmin', 'vmax', 'fdesde', 'fhasta', 'quick'];
    /* Las que cuentan para el contador del botón «Filtros» (ni la búsqueda ni las píldoras). */
    private const AVANZADOS = ['sector', 'origen', 'fase', 'servicio', 'prop', 'tag', 'vmin', 'vmax', 'fdesde', 'fhasta'];

    /**
     * Valida y limpia. Lo vacío desaparece. `prop` = id de persona o 'sin'
     * (sin propietario). Lanza 422 con el campo si algo no tiene sentido.
     */
    public static function normalizar(array $in): array
    {
        $f = [];
        foreach (['q' => 120, 'sector' => 80, 'origen' => 40, 'fase' => 40, 'servicio' => 80] as $k => $max) {
            $v = $in[$k] ?? '';
            if (!is_scalar($v)) throw HttpError::validacion('Filtro no válido.', $k);
            $v = trim((string)$v);
            if ($v === '') continue;
            if (mb_strlen($v) > $max) throw HttpError::validacion('Filtro demasiado largo.', $k);
            $f[$k] = $v;
        }
        $prop = $in['prop'] ?? '';
        if (is_scalar($prop) && (string)$prop !== '' && (string)$prop !== '0') {
            if ((string)$prop === 'sin') $f['prop'] = 'sin';
            elseif (preg_match('/^\d+$/', (string)$prop)) $f['prop'] = (int)$prop;
            else throw HttpError::validacion('Propietario no válido.', 'prop');
        }
        $tag = $in['tag'] ?? '';
        if (is_scalar($tag) && (string)$tag !== '' && (string)$tag !== '0') {
            if (!preg_match('/^\d+$/', (string)$tag)) throw HttpError::validacion('Etiqueta no válida.', 'tag');
            $f['tag'] = (int)$tag;
        }
        foreach (['vmin', 'vmax'] as $k) {
            if (!array_key_exists($k, $in) || $in[$k] === '' || $in[$k] === null) continue;
            $v = Dinero::leer($in[$k], $k);
            if ($v !== null) $f[$k] = $v;
        }
        foreach (['fdesde', 'fhasta'] as $k) {
            $v = $in[$k] ?? '';
            if (!is_string($v) || trim($v) === '') continue;
            $f[$k] = self::fecha(trim($v), $k);
        }
        $q = $in['quick'] ?? '';
        if (is_string($q) && $q !== '') {
            if (!in_array($q, self::QUICK, true)) throw HttpError::validacion('Filtro rápido desconocido.', 'quick');
            $f['quick'] = $q;
        }
        return $f;
    }

    public static function fecha(string $v, string $campo): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) throw HttpError::validacion('Fecha no válida (AAAA-MM-DD).', $campo);
        return $v;
    }

    /** Nº de filtros avanzados puestos (el contador del botón «Filtros»). */
    public static function avanzados(array $f): int
    {
        return count(array_intersect(array_keys($f), self::AVANZADOS));
    }

    /**
     * Fragmento « AND …» sobre contacts (alias $a) y sus parámetros.
     * @return array{0:string, 1:array}
     */
    public static function sql(array $f, string $a = 'c'): array
    {
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $a);
        $w = [];
        $p = [];
        if (isset($f['q'])) {
            $like = '%' . self::escaparLike($f['q']) . '%';
            $w[] = "({$a}.nombre LIKE ? OR {$a}.empresa LIKE ? OR {$a}.email LIKE ? OR {$a}.telefono LIKE ?)";
            array_push($p, $like, $like, $like, $like);
        }
        foreach (['sector' => 'sector', 'origen' => 'origen_lead', 'fase' => 'fase'] as $k => $col) {
            if (isset($f[$k])) {
                $w[] = "{$a}.{$col} = ?";
                $p[] = $f[$k];
            }
        }
        if (isset($f['servicio'])) {
            /* Se guarda como array JSON; el antiguo lo escribía con y sin escapar los acentos. */
            $w[] = "({$a}.servicio_json LIKE ? OR {$a}.servicio_json LIKE ?)";
            $p[] = '%' . self::escaparLike(json_encode($f['servicio'], JSON_UNESCAPED_UNICODE)) . '%';
            $p[] = '%' . self::escaparLike(json_encode($f['servicio'])) . '%';
        }
        if (isset($f['prop'])) {
            if ($f['prop'] === 'sin') $w[] = "{$a}.propietario_id IS NULL";
            else {
                $w[] = "{$a}.propietario_id = ?";
                $p[] = (int)$f['prop'];
            }
        }
        if (isset($f['tag'])) {
            $w[] = "{$a}.id IN (SELECT ct.contact_id FROM contact_tags ct WHERE ct.tag_id = ?)";
            $p[] = (int)$f['tag'];
        }
        if (isset($f['vmin'])) {
            $w[] = "{$a}.valor >= ?";
            $p[] = $f['vmin'];
        }
        if (isset($f['vmax'])) {
            $w[] = "{$a}.valor <= ?";
            $p[] = $f['vmax'];
        }
        if (isset($f['fdesde'])) {
            $w[] = "{$a}.fecha_creacion >= ?";
            $p[] = $f['fdesde'] . ' 00:00:00';
        }
        if (isset($f['fhasta'])) {
            $w[] = "{$a}.fecha_creacion < DATE_ADD(?, INTERVAL 1 DAY)";
            $p[] = $f['fhasta'];
        }
        switch ($f['quick'] ?? '') {
            case 'sin_contactar':
                $w[] = "{$a}.fecha_ultimo_contacto IS NULL";
                break;
            case 'act7':
                $w[] = "({$a}.fecha_ultimo_contacto IS NULL OR {$a}.fecha_ultimo_contacto <= DATE_SUB(CURDATE(), INTERVAL 7 DAY))";
                break;
            case 'act30':
                $w[] = "({$a}.fecha_ultimo_contacto IS NULL OR {$a}.fecha_ultimo_contacto <= DATE_SUB(CURDATE(), INTERVAL 30 DAY))";
                break;
            case 'vencidas':
                $w[] = "({$a}.fecha_prox IS NOT NULL AND {$a}.fecha_prox <= CURDATE())";
                break;
            case 'perdido':
                $w[] = "{$a}.fase = 'perdido'";
                break;
        }
        return [$w ? ' AND ' . implode(' AND ', $w) : '', $p];
    }

    public static function escaparLike(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
