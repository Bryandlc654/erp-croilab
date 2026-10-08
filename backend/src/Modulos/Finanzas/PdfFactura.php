<?php
namespace Croilab\Modulos\Finanzas;

/* PDF de la factura generado en el servidor, sin dependencias: A4 con
   Helvetica (fuentes estándar del PDF, texto en WinAnsi) y el mismo diseño
   que la hoja de la pantalla (FACTURA, partes, tabla con cabecera oscura,
   totales y pago). Recibe los datos de HojaFactura::datos(), así el portal
   del cliente lo puede reutilizar tal cual. */
final class PdfFactura
{
    private const W = 595.28;
    private const H = 841.89;
    private const M = 42.0;   // margen

    private const ANCHOS = [
        'F1' => [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584],
        'F2' => [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584],
    ];

    /** @var string[] */
    private array $paginas = [];
    private string $op = '';
    private float $y = 0;

    public static function generar(array $h): string
    {
        return (new self())->construir($h);
    }

    private function construir(array $h): string
    {
        $this->nuevaPagina();
        $x0 = self::M;
        $x1 = self::W - self::M;
        $tinta = [0.067, 0.075, 0.094];
        $gris = [0.40, 0.42, 0.45];

        /* Cabecera */
        $this->texto($x0, $this->y - 30, $h['titulo'] ?? 'FACTURA', 28, 'F2', $tinta);
        $num = 'Nº ' . ($h['numero'] ?? 'BORRADOR');
        $wn = $this->ancho($num, 9.5, 'F2') + 18;
        $this->rect($x0, $this->y - 58, $wn, 18, null, $tinta, 1.2);
        $this->texto($x0 + 9, $this->y - 52, $num, 9.5, 'F2', $tinta);
        $dcha = ['Fecha: ' . self::f($h['fecha'] ?? null)];
        if (!empty($h['periodo_ini']) && !empty($h['periodo_fin'])) $dcha[] = 'Período: ' . self::f($h['periodo_ini']) . ' a ' . self::f($h['periodo_fin']);
        if (!empty($h['fecha_venc'])) $dcha[] = 'Vencimiento: ' . self::f($h['fecha_venc']);
        foreach ($dcha as $i => $t) $this->textoDcha($x1, $this->y - 18 - $i * 14, $t, 9, 'F1', $gris);
        if (!empty($h['borrador'])) $this->textoDcha($x1, $this->y - 18 - count($dcha) * 14, 'BORRADOR · SIN VALOR FISCAL', 8.5, 'F2', [0.75, 0.22, 0.17]);
        $this->y -= 80;

        /* Partes */
        $mitad = ($x1 - $x0) / 2;
        $c = $h['cliente'] ?? [];
        $e = $h['emisor'] ?? [];
        $colC = array_values(array_filter([$c['nombre'] ?: '—', $c['nif'] ? 'NIF: ' . $c['nif'] : '', $c['tel'] ?? '', $c['dir'] ?? '', $c['email'] ?? '']));
        $colE = array_values(array_filter([$e['name'] ?? '', $e['nif'] ? 'NIF: ' . $e['nif'] : '', $e['email'] ?? '', $e['phone'] ?? '', $e['dir'] ?? '']));
        $lc = $this->envolverVarias($colC, $mitad - 28, 9);
        $le = $this->envolverVarias($colE, $mitad - 28, 9);
        $alto = 30 + max(count($lc), count($le)) * 12.5;
        $this->rect($x0, $this->y - $alto, $x1 - $x0, $alto, null, $tinta, 1.2);
        $this->linea($x0 + $mitad, $this->y, $x0 + $mitad, $this->y - $alto, $tinta, 1.2);
        foreach ([[$x0, 'DATOS DEL CLIENTE', $lc], [$x0 + $mitad, ($h['tipo'] ?? '') === 'rectificativa' ? 'EMITIDA POR' : 'DATOS DEL EMISOR', $le]] as [$x, $tit, $ls]) {
            $this->texto($x + 14, $this->y - 17, $tit, 7, 'F2', $gris);
            foreach ($ls as $i => $l) $this->texto($x + 14, $this->y - 31 - $i * 12.5, $l, 9, $i === 0 ? 'F2' : 'F1', $tinta);
        }
        $this->y -= $alto + 22;

        /* Tabla */
        $cols = [$x0, $x1 - 230, $x1 - 150, $x1 - 75];   // detalle, cantidad, precio, total
        $cabecera = function () use ($x0, $x1, $cols) {
            $this->rect($x0, $this->y - 20, $x1 - $x0, 20, [0.067, 0.075, 0.094], null);
            $this->texto($x0 + 10, $this->y - 13.5, 'DETALLE', 7.5, 'F2', [1, 1, 1]);
            $this->textoDcha($cols[2] - 10, $this->y - 13.5, 'CANTIDAD', 7.5, 'F2', [1, 1, 1]);
            $this->textoDcha($cols[3] - 10, $this->y - 13.5, 'PRECIO', 7.5, 'F2', [1, 1, 1]);
            $this->textoDcha($x1 - 10, $this->y - 13.5, 'TOTAL', 7.5, 'F2', [1, 1, 1]);
            $this->y -= 20;
        };
        $cabecera();
        $lineas = $h['lineas'] ?? [];
        if (!$lineas) {
            $this->texto($x0 + 10, $this->y - 18, 'Sin líneas.', 9, 'F1', $gris);
            $this->y -= 28;
        }
        foreach ($lineas as $l) {
            $trozos = $this->envolver((string)$l['concepto'], $cols[1] - $x0 - 20, 9.5, 'F1');
            $alto = 10 + count($trozos) * 12.5;
            if ($this->y - $alto < 120) {
                $this->nuevaPagina();
                $cabecera();
            }
            foreach ($trozos as $i => $t) $this->texto($x0 + 10, $this->y - 16 - $i * 12.5, $t, 9.5, 'F1', $tinta);
            $this->textoDcha($cols[2] - 10, $this->y - 16, self::cantidad((string)$l['cantidad']), 9.5, 'F1', $tinta);
            $this->textoDcha($cols[3] - 10, $this->y - 16, self::eur(Dinero::c((string)$l['precio'])), 9.5, 'F1', $tinta);
            $this->textoDcha($x1 - 10, $this->y - 16, self::eur((int)$l['importe']), 9.5, 'F2', $tinta);
            $this->y -= $alto;
            $this->linea($x0, $this->y, $x1, $this->y, [0.93, 0.93, 0.94], 0.8);
        }

        /* Totales */
        if ($this->y < 230) $this->nuevaPagina();
        $this->y -= 14;
        $t = $h['totales'];
        $tx = $x1 - 230;
        $filas = [['Base imponible', self::eur($t['base']), 'F2'], ['IVA (+' . $h['iva_pct'] . '%)', self::eur($t['iva']), 'F1']];
        if (Dinero::c($h['irpf_pct']) > 0) $filas[] = ['IRPF (-' . $h['irpf_pct'] . '%)', '-' . self::eur($t['irpf']), 'F1'];
        foreach ($filas as [$a, $b, $fnt]) {
            $this->texto($tx, $this->y - 10, $a, 9.5, $fnt, $tinta);
            $this->textoDcha($x1, $this->y - 10, $b, 9.5, $fnt, $tinta);
            $this->y -= 17;
        }
        $this->y -= 4;
        $this->rect($tx - 10, $this->y - 30, $x1 - $tx + 10, 30, [0.067, 0.075, 0.094], null);
        $this->texto($tx, $this->y - 19, 'TOTAL', 11, 'F2', [1, 1, 1]);
        $this->textoDcha($x1 - 10, $this->y - 20, self::eur($t['total']), 15, 'F2', [1, 1, 1]);
        $this->y -= 52;

        /* Pago */
        $p = $h['pago'] ?? [];
        $pago = [];
        if (!empty($p['banco'])) $pago[] = ['Banco', $p['banco']];
        $pago[] = ['Titular', $p['titular'] ?? ''];
        $pago[] = ['Forma de pago', $p['forma'] ?? ''];
        $pago[] = ['Vencimiento', trim(($p['condiciones'] ?? '') . (!empty($p['fecha_venc']) ? ' · ' . self::f($p['fecha_venc']) : ''))];
        if (!empty($p['iban'])) $pago[] = ['IBAN', $p['iban']];
        $alto = 26 + count($pago) * 14;
        if ($this->y - $alto < 60) $this->nuevaPagina();
        $this->rect($x0, $this->y - $alto, $x1 - $x0, $alto, null, [0.88, 0.88, 0.90], 0.8);
        $this->texto($x0 + 14, $this->y - 16, 'INFORMACIÓN DE PAGO', 7, 'F2', $gris);
        foreach ($pago as $i => [$a, $b]) {
            $this->texto($x0 + 14, $this->y - 32 - $i * 14, $a, 8.5, 'F1', $gris);
            $this->textoDcha($x1 - 14, $this->y - 32 - $i * 14, (string)$b, 8.5, 'F2', $tinta);
        }
        $this->y -= $alto + 16;

        /* Notas */
        $notas = array_merge($h['notas_legales'] ?? [], array_filter(preg_split('/\r?\n/', (string)($h['notas'] ?? ''))));
        foreach ($notas as $n) {
            foreach ($this->envolver((string)$n, $x1 - $x0, 8.5, 'F1') as $l) {
                if ($this->y < 50) $this->nuevaPagina();
                $this->texto($x0, $this->y - 10, $l, 8.5, 'F1', $gris);
                $this->y -= 12;
            }
        }
        return $this->documento();
    }

    /* ---------- Primitivas ---------- */

    private function nuevaPagina(): void
    {
        if ($this->op !== '') $this->paginas[] = $this->op;
        $this->op = '';
        $this->y = self::H - self::M;
    }

    private function texto(float $x, float $y, string $t, float $tam, string $fuente, array $color): void
    {
        $this->op .= sprintf("BT %.3F %.3F %.3F rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $color[0], $color[1], $color[2], $fuente, $tam, $x, $y, self::escapar(self::win($t)));
    }

    private function textoDcha(float $xDcha, float $y, string $t, float $tam, string $fuente, array $color): void
    {
        $this->texto($xDcha - $this->ancho($t, $tam, $fuente), $y, $t, $tam, $fuente, $color);
    }

    private function rect(float $x, float $y, float $w, float $h, ?array $relleno, ?array $borde, float $grosor = 1): void
    {
        if ($relleno) $this->op .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $relleno[0], $relleno[1], $relleno[2], $x, $y, $w, $h);
        if ($borde) $this->op .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F %.2F %.2F re S\n", $borde[0], $borde[1], $borde[2], $grosor, $x, $y, $w, $h);
    }

    private function linea(float $x1, float $y1, float $x2, float $y2, array $color, float $grosor): void
    {
        $this->op .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n", $color[0], $color[1], $color[2], $grosor, $x1, $y1, $x2, $y2);
    }

    private function ancho(string $t, float $tam, string $fuente): float
    {
        $w = 0;
        $tabla = self::ANCHOS[$fuente];
        foreach (str_split(self::win($t)) as $ch) {
            $o = ord($ch);
            if ($o >= 32 && $o <= 126) $w += $tabla[$o - 32];
            else {
                $base = @iconv('Windows-1252', 'ASCII//TRANSLIT', $ch);
                $b = is_string($base) && strlen($base) === 1 ? ord($base) : 0;
                $w += ($b >= 32 && $b <= 126) ? $tabla[$b - 32] : 556;
            }
        }
        return $w * $tam / 1000;
    }

    /** @return string[] */
    private function envolver(string $t, float $max, float $tam, string $fuente): array
    {
        $out = [];
        $linea = '';
        foreach (preg_split('/\s+/u', trim($t)) ?: [] as $pal) {
            $prueba = $linea === '' ? $pal : "$linea $pal";
            if ($linea !== '' && $this->ancho($prueba, $tam, $fuente) > $max) {
                $out[] = $linea;
                $linea = $pal;
            } else {
                $linea = $prueba;
            }
        }
        if ($linea !== '') $out[] = $linea;
        return $out ?: [''];
    }

    private function envolverVarias(array $ls, float $max, float $tam): array
    {
        $out = [];
        foreach ($ls as $i => $l) foreach ($this->envolver((string)$l, $max, $tam, $i === 0 ? 'F2' : 'F1') as $t) $out[] = $t;
        return $out;
    }

    private function documento(): string
    {
        $this->nuevaPagina();
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($this->paginas as $cont) {
            $objs[$n] = "<< /Length " . strlen($cont) . " >>\nstream\n" . $cont . "endstream";
            $objs[$n + 1] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $n);
            $kids[] = ($n + 1) . ' 0 R';
            $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offs = [];
        foreach ($objs as $i => $o) {
            $offs[$i] = strlen($pdf);
            $pdf .= "$i 0 obj\n$o\nendobj\n";
        }
        $xref = strlen($pdf);
        $total = max(array_keys($objs)) + 1;
        $pdf .= "xref\n0 $total\n0000000000 65535 f \n";
        for ($i = 1; $i < $total; $i++) $pdf .= sprintf("%010d 00000 n \n", $offs[$i] ?? 0);
        $pdf .= "trailer\n<< /Size $total /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }

    /* ---------- Formatos ---------- */

    private static function win(string $t): string
    {
        $t = str_replace(['−', '—', '–'], ['-', '-', '-'], $t);
        $r = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $t);
        return $r === false ? preg_replace('/[^\x20-\x7e]/', '?', $t) : $r;
    }

    private static function escapar(string $t): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $t);
    }

    private static function f(?string $iso): string
    {
        return $iso ? date('d/m/Y', strtotime($iso)) : '—';
    }

    public static function eur(int $c): string
    {
        return Dinero::texto($c) . ' €';
    }

    private static function cantidad(string $q): string
    {
        $c = Dinero::c($q);
        if ($c % 100 === 0) return (string)intdiv($c, 100);
        return rtrim(Dinero::texto($c), '0');
    }
}
