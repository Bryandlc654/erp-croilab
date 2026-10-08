<?php
namespace Croilab\Modulos\Equipo;

use Croilab\Http\HttpError;

/* Validaciones que comparten las pantallas del equipo, con los textos del antiguo. */
final class Validar
{
    public static function texto(mixed $v, int $max, string $campo, string $etiqueta = 'El texto'): string
    {
        if ($v !== null && !is_scalar($v)) throw HttpError::validacion("$etiqueta no es válido.", $campo);
        $s = trim(str_replace("\0", '', (string)$v));
        if (mb_strlen($s) > $max) throw HttpError::validacion("$etiqueta es demasiado largo (máximo $max caracteres).", $campo);
        return $s;
    }

    public static function username(mixed $v, string $campo = 'username'): string
    {
        $u = self::texto($v, 200, $campo, 'El usuario');
        if ($u === '') throw HttpError::validacion('El usuario es obligatorio.', $campo);
        if (mb_strlen($u) < 2) throw HttpError::validacion('El usuario debe tener al menos 2 letras.', $campo);
        if (mb_strlen($u) > 80) throw HttpError::validacion('El nombre de usuario es demasiado largo.', $campo);
        if (preg_match('/[\x00-\x1f<>]/', $u)) throw HttpError::validacion('El usuario tiene caracteres no permitidos.', $campo);
        return $u;
    }

    /** Correo opcional en minúsculas, o null si viene vacío. */
    public static function email(mixed $v, string $campo = 'email', string $msg = 'El correo no tiene un formato válido.'): ?string
    {
        $e = mb_strtolower(self::texto($v, 190, $campo, 'El correo'));
        if ($e === '') return null;
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) throw HttpError::validacion($msg, $campo);
        return $e;
    }

    /** Número decimal con coma o punto («12,50»), entre $min y $max; devuelve "12.50". */
    public static function decimal(mixed $v, string $campo, float $min, float $max, string $etiqueta): string
    {
        if ($v === null || $v === '') return '0.00';
        if (!is_scalar($v)) throw HttpError::validacion("$etiqueta no es un número.", $campo);
        $s = str_replace([' ', '€', '%'], '', trim((string)$v));
        /* 1.234,56 → 1234.56; 12,5 → 12.5 */
        if (str_contains($s, ',')) $s = str_replace(',', '.', str_replace('.', '', $s));
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $s)) throw HttpError::validacion("$etiqueta no es un número válido (máximo dos decimales).", $campo);
        $n = (float)$s;
        if ($n < $min || $n > $max) throw HttpError::validacion("$etiqueta tiene que estar entre " . (int)$min . ' y ' . (int)$max . '.', $campo);
        /* Se devuelve como texto: DECIMAL en la base, nunca float acumulado. */
        [$ent, $dec] = array_pad(explode('.', $s), 2, '');
        return ltrim($ent, '0') === '' ? '0.' . str_pad($dec, 2, '0') : ltrim($ent, '0') . '.' . str_pad($dec, 2, '0');
    }

    public static function bool(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
    }

    /** Fecha YYYY-MM-DD válida o null. */
    public static function fecha(mixed $v, string $campo): ?string
    {
        if ($v === null || $v === '') return null;
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            throw HttpError::validacion('La fecha no es válida.', $campo);
        }
        return $v;
    }

    /** URL http(s) o vacío. */
    public static function url(mixed $v, int $max, string $campo, string $etiqueta = 'La dirección'): string
    {
        $u = self::texto($v, $max, $campo, $etiqueta);
        if ($u === '') return '';
        if (!preg_match('~^https?://[^\s<>"]+$~i', $u) || !filter_var($u, FILTER_VALIDATE_URL)) {
            throw HttpError::validacion("$etiqueta tiene que empezar por https:// y ser una dirección válida.", $campo);
        }
        return $u;
    }
}
