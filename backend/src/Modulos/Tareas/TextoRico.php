<?php
namespace Croilab\Modulos\Tareas;

/* El formato de texto del ERP (descripciones, comentarios, actas): texto con
   marcadores tipo markdown, nunca HTML (docs/migracion/02-trabajo.md §5).
   Aquí solo lo que necesita el servidor: el texto plano que publica el portal,
   los extractos de listas y avisos, y los ficheros que nombra. */
final class TextoRico
{
    /** Texto plano para el portal del cliente (desc_to_plain del antiguo). */
    public static function aPlano(string $texto): string
    {
        $out = [];
        foreach (preg_split("/\r\n|\r|\n/", $texto) as $ln) {
            if (preg_match('/^\s*```/', $ln)) continue;                                // valla de código
            if (preg_match('/^\s*\|[\s:|\-]+\|\s*$/', $ln)) continue;                  // separador de tabla
            if (preg_match('/^\s*\[\[chk:[01]\]\]\s?(.*)$/u', $ln, $m)) $ln = '• ' . $m[1];
            $ln = preg_replace('/^(#{1,3})\s+/', '', $ln);
            $ln = preg_replace('/^[-•]\s+/u', '• ', $ln);
            $ln = preg_replace('/^\d+[.)]\s+/', '• ', $ln);
            $ln = preg_replace('/^>\s?/', '', $ln);
            if (preg_match('/^\s*---+\s*$/', $ln)) $ln = '—';
            if (preg_match('/^\s*\|.*\|\s*$/', $ln)) $ln = trim(preg_replace('/\s*\|\s*/', ' ', $ln));
            $ln = self::enLinea($ln);
            $out[] = $ln;
        }
        return trim(implode("\n", $out));
    }

    /** Una línea corta para tarjetas, avisos y resultados de búsqueda. */
    public static function extracto(string $texto, int $n = 160): string
    {
        $plano = trim(preg_replace('/\s+/u', ' ', str_replace('• ', '', self::aPlano($texto))));
        $plano = str_replace(['[[img]]', '—'], ['', ''], $plano);
        $plano = trim(preg_replace('/\s+/u', ' ', $plano));
        return mb_strlen($plano) > $n ? rtrim(mb_substr($plano, 0, $n - 1)) . '…' : $plano;
    }

    /** ¿Está vacío a efectos prácticos (sin texto ni ficheros)? */
    public static function vacio(string $texto): bool
    {
        return trim(preg_replace('/\[\[chk:[01]\]\]/', '', $texto)) === '';
    }

    /** Nombres de fichero incrustados: [[img:FN]] y [[file:FN|nombre]]. */
    public static function ficheros(string $texto): array
    {
        preg_match_all('/\[\[(?:img|file):([A-Za-z0-9_.\-]+)(?:\|[^\]]*)?\]\]/', $texto, $m);
        return array_values(array_unique($m[1]));
    }

    private static function enLinea(string $ln): string
    {
        $ln = preg_replace('/\[\[img:[^\]]+\]\]/u', '', $ln);
        $ln = preg_replace('/\[\[file:[^\]|]+\|([^\]]*)\]\]/u', '$1', $ln);
        $ln = preg_replace('/\[\[file:[^\]]+\]\]/u', '', $ln);
        $ln = preg_replace('/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/u', '$1', $ln);
        $ln = preg_replace('/\*\*(.+?)\*\*/us', '$1', $ln);
        $ln = preg_replace('/__(.+?)__/us', '$1', $ln);
        $ln = preg_replace('/~~(.+?)~~/us', '$1', $ln);
        $ln = preg_replace('/`([^`\n]+)`/u', '$1', $ln);
        $ln = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/us', '$1', $ln);
        return $ln;
    }
}
