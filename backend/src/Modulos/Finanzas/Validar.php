<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;

/* Validaciones y piezas que repiten todos los servicios de Finanzas. */
final class Validar
{
    public static function texto(array $d, string $campo, int $max, string $def = ''): string
    {
        if (!array_key_exists($campo, $d) || $d[$campo] === null) return $def;
        if (!is_scalar($d[$campo])) throw HttpError::validacion('Valor no válido.', $campo);
        $v = trim((string)$d[$campo]);
        if (mb_strlen($v) > $max) throw HttpError::validacion("Demasiado largo (máximo $max caracteres).", $campo);
        return $v;
    }

    public static function email(array $d, string $campo, string $def = ''): string
    {
        $v = self::texto($d, $campo, 160, $def);
        if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) throw HttpError::validacion('Ese correo no es válido.', $campo);
        return $v;
    }

    /** 'AAAA-MM-DD' o null. */
    public static function fecha(array $d, string $campo, bool $obligatoria = false): ?string
    {
        $v = $d[$campo] ?? null;
        if ($v === null || $v === '') {
            if ($obligatoria) throw HttpError::validacion('Falta la fecha.', $campo);
            return null;
        }
        if (!is_string($v) || !self::esFecha($v)) throw HttpError::validacion('Fecha no válida (AAAA-MM-DD).', $campo);
        return $v;
    }

    public static function esFecha(string $v): bool
    {
        $f = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $f !== false && $f->format('Y-m-d') === $v;
    }

    /** 'AAAA-MM' válido. */
    public static function mes(?string $v, string $campo = 'mes'): string
    {
        if ($v === null || !preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $v)) throw HttpError::validacion('Mes no válido (AAAA-MM).', $campo);
        return $v;
    }

    public static function bool(array $d, string $campo, bool $def = false): bool
    {
        if (!array_key_exists($campo, $d)) return $def;
        return filter_var($d[$campo], FILTER_VALIDATE_BOOLEAN);
    }

    public static function idONull(array $d, string $campo): ?int
    {
        $v = $d[$campo] ?? null;
        if ($v === null || $v === '' || $v === 0 || $v === '0') return null;
        $i = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($i === false) throw HttpError::validacion('Identificador no válido.', $campo);
        return $i;
    }

    /** Escribir siempre exige además `general.editar` («sin esto solo puede mirar»). */
    public static function escribe(Acceso $acc, string ...$permisos): void
    {
        $acc->exigir('general.editar', ...$permisos);
    }

    /**
     * Alcance de clientes en Finanzas: lo que no es de ningún cliente (gastos,
     * facturas sin cliente) es de la empresa y lo ve quien tiene el módulo; lo
     * que es de un cliente, solo si ese cliente está en su alcance.
     */
    public static function sqlAlcance(Acceso $acc, string $columna): string
    {
        $ids = $acc->clientesVisibles();
        if ($ids === null) return '';
        $col = preg_replace('/[^a-zA-Z0-9_.]/', '', $columna);
        if (!$ids) return " AND $col IS NULL ";
        return " AND ($col IS NULL OR $col IN (" . implode(',', array_map('intval', $ids)) . ')) ';
    }

    public static function veCliente(Acceso $acc, ?int $clientId): bool
    {
        return !$clientId || $acc->veCliente($clientId);
    }

    /** Primer y último día de 'AAAA-MM'. */
    public static function rangoMes(string $ym): array
    {
        $ini = $ym . '-01';
        return [$ini, date('Y-m-t', strtotime($ini))];
    }

    public static function mesSiguiente(string $ym, int $n = 1): string
    {
        return date('Y-m', strtotime($ym . '-01 ' . ($n >= 0 ? '+' : '') . $n . ' month'));
    }

    public static function like(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }
}
