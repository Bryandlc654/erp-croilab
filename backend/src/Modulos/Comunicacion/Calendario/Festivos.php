<?php
namespace Croilab\Modulos\Comunicacion\Calendario;

/* Festivos nacionales de España (los mismos que pintaba calendar.php):
   fijos más el Viernes Santo, que depende de la Pascua. */
final class Festivos
{
    private const FIJOS = [
        '01-01' => 'Año Nuevo', '01-06' => 'Reyes', '05-01' => 'Día del Trabajo', '08-15' => 'Asunción',
        '10-12' => 'Fiesta Nacional', '11-01' => 'Todos los Santos', '12-06' => 'Constitución',
        '12-08' => 'Inmaculada', '12-25' => 'Navidad',
    ];

    /** @return array<string,string> fecha (YYYY-MM-DD) => nombre, entre las dos fechas incluidas */
    public static function entre(string $desde, string $hasta): array
    {
        $out = [];
        $a1 = (int)substr($desde, 0, 4);
        $a2 = (int)substr($hasta, 0, 4);
        for ($a = $a1; $a <= $a2; $a++) {
            $todos = [];
            foreach (self::FIJOS as $md => $n) $todos["$a-$md"] = $n;
            $todos[self::viernesSanto($a)] = 'Viernes Santo';
            foreach ($todos as $f => $n) if ($f >= $desde && $f <= $hasta) $out[$f] = $n;
        }
        ksort($out);
        return $out;
    }

    /** Domingo de Pascua (algoritmo de Gauss/Meeus, calendario gregoriano) − 2 días. */
    public static function viernesSanto(int $anio): string
    {
        $a = $anio % 19;
        $b = intdiv($anio, 100);
        $c = $anio % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
        return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $anio, $mes, $dia)))->modify('-2 days')->format('Y-m-d');
    }
}
