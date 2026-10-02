<?php
namespace Croilab\Correo;

/* El envío de correo según la configuración (entorno o .env):
     MAIL_DRIVER  smtp (por defecto) | archivo
     SMTP_HOST, SMTP_PORT, SMTP_SEGURIDAD (tls | ssl | none), SMTP_USER, SMTP_PASS
     MAIL_FROM, MAIL_FROM_NOMBRE
     MAIL_DIR     carpeta de los .eml con MAIL_DRIVER=archivo */
final class Fabrica
{
    public static function valor(string $clave, string $def = ''): string
    {
        $v = getenv($clave);
        if ($v === false || $v === '') $v = $GLOBALS['croilab_env'][$clave] ?? $def;
        return trim((string)$v);
    }

    /** @throws \RuntimeException si falta la configuración */
    public static function desdeConfig(): Correo
    {
        $driver = self::valor('MAIL_DRIVER', 'smtp');
        if ($driver === 'archivo') {
            return new CorreoArchivo(self::valor('MAIL_DIR', sys_get_temp_dir() . '/croilab-correo'), self::valor('MAIL_FROM', 'erp@localhost'));
        }
        if ($driver !== 'smtp') throw new \RuntimeException("MAIL_DRIVER desconocido: $driver");

        $host = self::valor('SMTP_HOST');
        $de = self::valor('MAIL_FROM');
        if ($host === '' || $de === '') throw new \RuntimeException('Correo sin configurar: faltan SMTP_HOST o MAIL_FROM');
        $seg = strtolower(self::valor('SMTP_SEGURIDAD', 'tls'));
        $puerto = (int)self::valor('SMTP_PORT', $seg === 'ssl' ? '465' : ($seg === 'tls' ? '587' : '25'));
        return new Smtp($host, $puerto, $seg, self::valor('SMTP_USER'), self::valor('SMTP_PASS'), $de, self::valor('MAIL_FROM_NOMBRE'));
    }
}
