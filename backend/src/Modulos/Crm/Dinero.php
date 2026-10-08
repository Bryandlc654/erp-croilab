<?php
namespace Croilab\Modulos\Crm;

use Croilab\Http\HttpError;

/* Importes del CRM (DECIMAL(12,2)). Se manejan como texto «1234.56» de punta a
   punta para no arrastrar errores de coma flotante; solo se convierten a
   número al responder. El parseo es el de num_es() del ERP antiguo: «1.234,56»,
   «12,5», «12.5», «1.234» (miles), «1.200 €». */
final class Dinero
{
    private const MAX = 9999999999.99;

    /** Texto o número → «1234.56», o null si viene vacío. Lanza 422 si no es un importe. */
    public static function leer(mixed $v, string $campo = 'valor'): ?string
    {
        if ($v === null) return null;
        if (is_bool($v) || is_array($v) || is_object($v)) throw HttpError::validacion('Importe no válido.', $campo);
        if (is_int($v) || is_float($v)) {
            if (!is_finite((float)$v)) throw HttpError::validacion('Importe no válido.', $campo);
            return self::acotar(number_format((float)$v, 2, '.', ''), $campo);
        }
        $s = trim((string)$v);
        if ($s === '') return null;
        $n = self::normalizar($s);
        if ($n === null) throw HttpError::validacion('Importe no válido.', $campo);
        return self::acotar($n, $campo);
    }

    /** Como leer() pero sin lanzar: null si no se entiende (importación CSV). */
    public static function intentar(string $s): ?string
    {
        try {
            return self::leer($s);
        } catch (HttpError) {
            return null;
        }
    }

    /* «1.234,56» → «1234.56» sin pasar por float. */
    private static function normalizar(string $s): ?string
    {
        $s = preg_replace('/[^0-9,.\-]/', '', $s);
        if ($s === '' || $s === '-') return null;
        $neg = $s[0] === '-';
        $s = str_replace('-', '', $s);
        if ($s === '') return null;
        $uc = strrpos($s, ',');
        $up = strrpos($s, '.');
        $ult = max($uc === false ? -1 : $uc, $up === false ? -1 : $up);
        if ($ult < 0) {
            $ent = $s;
            $dec = '';
        } else {
            $dec = substr($s, $ult + 1);
            $ent = substr($s, 0, $ult);
            $dos = $uc !== false && $up !== false;
            /* Tres cifras detrás de un único tipo de separador = miles («1.234»). */
            if (!$dos && strlen($dec) === 3 && $ent !== '' && ltrim($ent, '0') !== '') {
                $ent .= $dec;
                $dec = '';
            }
            $ent = preg_replace('/[^0-9]/', '', $ent);
            $dec = preg_replace('/[^0-9]/', '', $dec);
        }
        if ($ent === '' && $dec === '') return null;
        $ent = ltrim($ent, '0');
        if ($ent === '') $ent = '0';
        if (strlen($ent) > 12) return null;
        /* Redondeo a céntimos sobre el texto (mitad hacia arriba). */
        $dec = str_pad($dec, 3, '0');
        $cent = (int)$ent * 100 + (int)substr($dec, 0, 2) + ((int)$dec[2] >= 5 ? 1 : 0);
        $txt = intdiv($cent, 100) . '.' . str_pad((string)($cent % 100), 2, '0', STR_PAD_LEFT);
        return ($neg && $cent > 0 ? '-' : '') . $txt;
    }

    private static function acotar(string $n, string $campo): string
    {
        if (abs((float)$n) > self::MAX) throw HttpError::validacion('Ese importe es demasiado grande.', $campo);
        if ((float)$n < 0) throw HttpError::validacion('El importe no puede ser negativo.', $campo);
        return $n;
    }

    /** DECIMAL de la base (texto) → número para el JSON, o null. */
    public static function num(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        return round((float)$v, 2);
    }
}
