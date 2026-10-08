<?php
namespace Croilab\Modulos\Portal;

/* Los meses del portal con su año.

   El ERP antiguo guardaba las métricas, el progreso y los informes con el
   nombre del mes a secas («Junio») y el portal les pegaba «2026» escrito a
   mano: en enero la sincronización pisaba el enero del año anterior, los
   meses salían en el orden en que se habían insertado (los «vs mes anterior»
   comparaban con lo que no era) y en 2027 todo seguiría diciendo 2026.

   Aquí cada etiqueta se convierte en una clave `YYYY-MM`:
     · «2026-06», «Junio 2026», «junio de 2026», «Jun 2026» → la que dice;
     · «Junio» (sin año) → el último junio que no sea posterior a la fecha de
       referencia (para métricas, la de la última sincronización; para el
       trabajo y los informes, un mes por delante, porque se planifica).
   «General» o un texto que no es un mes no tiene clave (null) y va al final.
   Sin base de datos: se prueba sola (tests/Unit/Portal). */
final class Meses
{
    public const NOMBRES = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio',
        8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];

    /** Clave YYYY-MM de una etiqueta de mes, o null si no es un mes. */
    public static function clave(string $etiqueta, \DateTimeImmutable $ref): ?string
    {
        $t = mb_strtolower(trim($etiqueta));
        if ($t === '') return null;
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $t, $m)) return $m[1] . '-' . $m[2];
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        if (!preg_match('/^([a-z]+)\.?(?:\s+(?:de\s+|del\s+)?(\d{4}))?$/u', $t, $m)) return null;
        $mes = self::numero($m[1]);
        if ($mes === null) return null;
        if (!empty($m[2])) return sprintf('%04d-%02d', (int)$m[2], $mes);
        $anio = (int)$ref->format('Y');
        if ($mes > (int)$ref->format('n')) $anio--;
        return sprintf('%04d-%02d', $anio, $mes);
    }

    /** «2026-06» → «Junio 2026». */
    public static function etiqueta(string $clave): string
    {
        return self::nombre($clave) . ' ' . substr($clave, 0, 4);
    }

    /** «2026-06» → «Junio». */
    public static function nombre(string $clave): string
    {
        return self::NOMBRES[(int)substr($clave, 5, 2)] ?? $clave;
    }

    /** La clave del mes anterior: «2026-01» → «2025-12». */
    public static function anterior(string $clave): string
    {
        return (new \DateTimeImmutable(substr($clave, 0, 7) . '-01'))->modify('-1 month')->format('Y-m');
    }

    /** Número de mes (1-12) de un nombre en español (completo o abreviado a 3 letras). */
    private static function numero(string $nombre): ?int
    {
        /* «setiembre» también se usa. */
        if ($nombre === 'setiembre' || $nombre === 'set') return 9;
        foreach (self::NOMBRES as $n => $completo) {
            $c = strtr(mb_strtolower($completo), ['á' => 'a']);
            if ($nombre === $c || (strlen($nombre) >= 3 && str_starts_with($c, $nombre))) return $n;
        }
        return null;
    }

    /**
     * Ordena filas por clave: las que tienen mes, de la más antigua a la más
     * nueva (o al revés con $desc); las que no (General), al final, en el orden
     * en que venían.
     * @param list<array{clave:?string}> $filas
     */
    public static function ordenar(array $filas, bool $desc = false): array
    {
        $con = array_values(array_filter($filas, fn($f) => $f['clave'] !== null));
        $sin = array_values(array_filter($filas, fn($f) => $f['clave'] === null));
        usort($con, fn($a, $b) => $desc ? strcmp($b['clave'], $a['clave']) : strcmp($a['clave'], $b['clave']));
        return array_merge($con, $sin);
    }
}
