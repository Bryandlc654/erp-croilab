<?php
namespace Croilab\Modulos\Crm;

/* CSV de entrada y salida.

   Al exportar, una celda que empieza por = + - @ (o tabulador / retorno) la
   interpreta Excel como fórmula: un contacto llamado «=HYPERLINK(…)» se
   ejecutaría en el ordenador de quien abra el fichero. Esas celdas se
   neutralizan con una comilla simple delante (recomendación de OWASP). */
final class Csv
{
    public const BOM = "\xEF\xBB\xBF";

    public static function celda(mixed $v): string
    {
        $s = $v === null ? '' : (string)$v;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) $s = "'" . $s;
        return $s;
    }

    /** CSV con BOM (Excel lo abre en UTF-8). Las cifras van tal cual: las ponemos nosotros. */
    public static function generar(array $cabecera, array $filas, string $sep = ','): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, array_map([self::class, 'celda'], $cabecera), $sep, '"', '');
        foreach ($filas as $f) {
            fputcsv($h, array_map(fn($v) => is_int($v) || is_float($v) ? (string)$v : self::celda($v), $f), $sep, '"', '');
        }
        rewind($h);
        $csv = (string)stream_get_contents($h);
        fclose($h);
        return self::BOM . $csv;
    }

    /**
     * Lee un CSV: quita el BOM, adivina el separador (; si la primera línea
     * tiene ; y no ,) y pasa de Windows-1252 a UTF-8 si hace falta.
     * @return array{cabecera: string[], filas: string[][], separador: string}
     */
    public static function leer(string $contenido, int $maxFilas): array
    {
        if (str_starts_with($contenido, self::BOM)) $contenido = substr($contenido, 3);
        if (!mb_check_encoding($contenido, 'UTF-8')) $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        $primera = strtok($contenido, "\n") ?: '';
        $sep = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : (str_contains($primera, "\t") && !str_contains($primera, ',') ? "\t" : ',');

        $h = fopen('php://temp', 'r+');
        fwrite($h, $contenido);
        rewind($h);
        $cab = fgetcsv($h, 0, $sep, '"', '') ?: [];
        $cab = array_map(fn($c) => trim((string)$c), $cab);
        $filas = [];
        while (($f = fgetcsv($h, 0, $sep, '"', '')) !== false) {
            if ($f === [null] || count(array_filter($f, fn($v) => trim((string)$v) !== '')) === 0) continue;
            $filas[] = array_map(fn($v) => trim((string)$v), $f);
            if (count($filas) > $maxFilas) break;
        }
        fclose($h);
        return ['cabecera' => $cab, 'filas' => $filas, 'separador' => $sep];
    }
}
