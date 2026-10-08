<?php
namespace Croilab\Modulos\Clientes;

/* Totales de factura en céntimos enteros, sin floats. Regla (spec de Finanzas
   §14, recomendada): importe de línea = round(q·p, 2) (lo hace MySQL en
   DECIMAL), base = Σ líneas, IVA = round(base·iva%), IRPF = round(base·irpf%),
   total = base + IVA − IRPF. Clientes solo lee facturas: si Finanzas fija otra
   regla, se cambia aquí y en ningún sitio más. */
final class Importes
{
    /** SQL de la base de cada factura (DECIMAL) para usar como columna: requiere el alias de invoices. */
    public static function sqlBase(string $alias = 'i'): string
    {
        $a = preg_replace('/[^a-z_]/i', '', $alias);
        return "(SELECT COALESCE(SUM(ROUND(COALESCE(it.cantidad,0) * COALESCE(it.precio,0), 2)), 0) FROM invoice_items it WHERE it.invoice_id = $a.id)";
    }

    /** «1234.56», «-3.5», 12.3 → céntimos. Redondea a la mitad hacia fuera si trae más decimales. */
    public static function centimos(string|int|float|null $v): int
    {
        if ($v === null || $v === '') return 0;
        $s = is_float($v) ? number_format($v, 6, '.', '') : trim((string)$v);
        if (!preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $s, $m)) return 0;
        $dec = str_pad($m[3] ?? '', 3, '0');
        $c = (int)($m[2] ?: '0') * 100 + (int)substr($dec, 0, 2) + ((int)$dec[2] >= 5 ? 1 : 0);
        return $m[1] === '-' ? -$c : $c;
    }

    /** Cuota de un porcentaje (DECIMAL(5,2) como «21.00») sobre una base en céntimos, redondeada a céntimo. */
    public static function cuota(int $baseC, string|int|float|null $pct): int
    {
        $p = self::centimos($pct);           // 21.00 → 2100 (centésimas de punto)
        $x = $baseC * $p;                    // céntimos × 10 000
        $abs = intdiv(abs($x) + 5000, 10000);
        return $x < 0 ? -$abs : $abs;
    }

    public static function total(int $baseC, string|int|float|null $iva, string|int|float|null $irpf): int
    {
        return $baseC + self::cuota($baseC, $iva) - self::cuota($baseC, $irpf);
    }
}
