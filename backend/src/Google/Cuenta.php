<?php
namespace Croilab\Google;

/* A nombre de quién se habla con Google.
     calendar  cada persona del equipo conecta su propio Google Calendar
     metricas  una sola conexión de la agencia (Search Console + GA4)
     login     solo identidad («Entrar con Google»): no guarda tokens */
final class Cuenta
{
    public const CALENDAR = 'calendar';
    public const METRICAS = 'metricas';
    public const LOGIN = 'login';

    private function __construct(public readonly string $integracion, public readonly int $adminId) {}

    public static function calendario(int $adminId): self
    {
        if ($adminId <= 0) throw new \InvalidArgumentException('Cuenta de calendario sin persona.');
        return new self(self::CALENDAR, $adminId);
    }

    public static function metricas(): self
    {
        return new self(self::METRICAS, 0);
    }

    public static function login(): self
    {
        return new self(self::LOGIN, 0);
    }

    public static function desde(string $integracion, int $adminId): self
    {
        return match ($integracion) {
            self::CALENDAR => self::calendario($adminId),
            self::METRICAS => self::metricas(),
            self::LOGIN => self::login(),
            default => throw new \InvalidArgumentException("Integración desconocida: $integracion"),
        };
    }
}
