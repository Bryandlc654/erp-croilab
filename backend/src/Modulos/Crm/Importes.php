<?php
namespace Croilab\Modulos\Crm;

use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

/* Sin `ver.importes` («Ve las cantidades de dinero») no salen cifras de dinero
   del CRM: valor de contactos y negocios, importes de propuestas, métricas y
   gráficas de euros (como hace Clientes). Se aplica a la salida de todas las
   rutas del módulo desde api/rutas/crm.php. */
final class Importes
{
    private const CLAVES = ['valor', 'importe', 'valor_pipeline', 'ganado_mes', 'ticket_medio', 'ganado'];
    private const SERIES = ['valor_fase', 'valor_ganado_mes'];

    public static function filtrar(mixed $r, Acceso $acc): mixed
    {
        if ($acc->puede('ver.importes')) return $r;
        if ($r instanceof Respuesta) return new Respuesta(self::limpiar($r->datos), $r->status, $r->cabeceras);
        return is_array($r) ? self::limpiar($r) : $r;
    }

    private static function limpiar(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_string($k) && in_array($k, self::CLAVES, true) && (is_int($v) || is_float($v) || $v === null)) $a[$k] = null;
            elseif (is_string($k) && in_array($k, self::SERIES, true) && is_array($v)) $a[$k] = ['labels' => [], 'series' => []];
            elseif (is_array($v)) $a[$k] = self::limpiar($v);
        }
        return $a;
    }
}
