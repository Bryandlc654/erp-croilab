<?php
namespace Croilab\Http;

/* Trabajo que se hace DESPUÉS de mandar la respuesta (index.php cierra la
   conexión con fastcgi_finish_request / litespeed_finish_request y luego las
   ejecuta). Sirve para lo lento que no cambia la respuesta, como enviar un
   correo, y para que el tiempo de respuesta no delate nada: «te hemos enviado
   un enlace» tarda lo mismo exista o no la cuenta. */
final class Diferidas
{
    /** @var callable[] */
    private static array $cola = [];

    public static function agregar(callable $tarea): void
    {
        self::$cola[] = $tarea;
    }

    /** Ejecuta y vacía la cola. Un fallo se registra y no impide las demás. */
    public static function ejecutar(): void
    {
        while ($t = array_shift(self::$cola)) {
            try {
                $t();
            } catch (\Throwable $e) {
                error_log('Tarea diferida: ' . $e->getMessage());
            }
        }
    }

    public static function descartar(): void
    {
        self::$cola = [];
    }
}
