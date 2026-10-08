<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Http\HttpError;
use Croilab\Modulos\Clientes\Importes;

/* Dinero en céntimos enteros: nunca un float acumulado.
   Regla única (spec §14 y §17.10), la misma que Clientes\Importes y que el
   front (features/finanzas/lib/importes.ts):
     línea = round(cantidad × precio, 2)   (cantidad y precio con 2 decimales)
     base  = Σ líneas
     IVA   = round(base × iva%, 2)  ·  IRPF = round(base × irpf%, 2)
     total = base + IVA − IRPF
   Redondeo «a la mitad hacia fuera», igual que ROUND() de MySQL con DECIMAL. */
final class Dinero
{
    /** «1234.56» / 12.3 / «-3» → céntimos (o centésimas, para cantidades y %). */
    public static function c(string|int|float|null $v): int
    {
        return Importes::centimos($v);
    }

    /** Céntimos → «1234.56» para guardar en DECIMAL(…,2). */
    public static function decimal(int $c): string
    {
        $s = $c < 0 ? '-' : '';
        $a = abs($c);
        return $s . intdiv($a, 100) . '.' . str_pad((string)($a % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Importe de una línea: cantidad (centésimas) × precio (céntimos), redondeado a céntimo. */
    public static function linea(string|int|float|null $cantidad, string|int|float|null $precio): int
    {
        $x = self::c($cantidad) * self::c($precio);   // céntimos × 100
        $abs = intdiv(abs($x) + 50, 100);
        return $x < 0 ? -$abs : $abs;
    }

    /**
     * Totales de una factura.
     * @param array<int, array{cantidad: mixed, precio: mixed}> $lineas
     * @return array{base:int, iva:int, irpf:int, total:int}
     */
    public static function totales(array $lineas, string|int|float|null $iva, string|int|float|null $irpf): array
    {
        $base = 0;
        foreach ($lineas as $l) $base += self::linea($l['cantidad'] ?? 0, $l['precio'] ?? 0);
        return self::desdeBase($base, $iva, $irpf);
    }

    /** @return array{base:int, iva:int, irpf:int, total:int} */
    public static function desdeBase(int $base, string|int|float|null $iva, string|int|float|null $irpf): array
    {
        $ci = Importes::cuota($base, $iva);
        $cr = Importes::cuota($base, $irpf);
        return ['base' => $base, 'iva' => $ci, 'irpf' => $cr, 'total' => $base + $ci - $cr];
    }

    /**
     * Lee un número escrito por una persona («1.234,56», «12,5», «1234.5», 12.5)
     * y lo deja como texto decimal con 2 cifras («1234.56»). Port de num_es():
     * el ÚLTIMO separador es el decimal; «1.234» sin otro separador son miles.
     * null si no hay número.
     */
    public static function leer(mixed $v): ?string
    {
        if (is_int($v)) return $v . '.00';
        if (is_float($v)) {
            if (!is_finite($v)) return null;
            return self::decimal(self::c($v));
        }
        if (!is_string($v)) return null;
        $s = preg_replace('/[^0-9,.\-]/', '', trim($v));
        if ($s === '' || $s === '-' || $s === null) return null;
        $neg = $s[0] === '-';
        $s = str_replace('-', '', $s);
        $coma = strrpos($s, ',');
        $punto = strrpos($s, '.');
        $ult = max($coma === false ? -1 : $coma, $punto === false ? -1 : $punto);
        if ($ult < 0) {
            $ent = $s;
            $dec = '';
        } else {
            $dec = substr($s, $ult + 1);
            $ent = substr($s, 0, $ult);
            $otro = $coma !== false && $punto !== false;
            if (!$otro && strlen($dec) === 3 && ltrim($ent, '0') !== '' && ctype_digit($dec)) {
                $ent .= $dec;
                $dec = '';
            }
        }
        $ent = preg_replace('/[^0-9]/', '', $ent) ?: '0';
        $dec = preg_replace('/[^0-9]/', '', $dec);
        return self::decimal(self::c(($neg ? '-' : '') . $ent . ($dec !== '' ? '.' . $dec : '')));
    }

    /**
     * Como leer(), pero valida rango y lanza 422 con el campo.
     * $min/$max en céntimos (o centésimas).
     */
    public static function exigir(mixed $v, string $campo, string $etiqueta, ?int $min = null, ?int $max = null, bool $obligatorio = true): ?string
    {
        if ($v === null || $v === '') {
            if ($obligatorio) throw HttpError::validacion("Falta $etiqueta.", $campo);
            return null;
        }
        $d = self::leer($v);
        if ($d === null) throw HttpError::validacion(ucfirst($etiqueta) . ' no es un número válido.', $campo);
        $c = self::c($d);
        if ($min !== null && $c < $min) throw HttpError::validacion(ucfirst($etiqueta) . ' no puede ser menor que ' . self::texto($min) . '.', $campo);
        if ($max !== null && $c > $max) throw HttpError::validacion(ucfirst($etiqueta) . ' no puede ser mayor que ' . self::texto($max) . '.', $campo);
        return $d;
    }

    /** Céntimos → «1.234,56» (para mensajes y conceptos). */
    public static function texto(int $c): string
    {
        $neg = $c < 0;
        $a = abs($c);
        $ent = number_format(intdiv($a, 100), 0, ',', '.');
        return ($neg ? '-' : '') . $ent . ',' . str_pad((string)($a % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Porcentaje DECIMAL(5,2) → texto corto: 21.00 → «21», 7.50 → «7,5». */
    public static function pct(string|int|float|null $v): string
    {
        $c = self::c($v);
        if ($c % 100 === 0) return (string)intdiv($c, 100);
        return rtrim(self::texto($c), '0');
    }
}
