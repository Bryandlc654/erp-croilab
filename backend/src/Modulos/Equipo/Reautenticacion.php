<?php
namespace Croilab\Modulos\Equipo;

use Croilab\Http\HttpError;

/* «Confirma que eres tú» (lib/reauth.php del antiguo) para la API.

   Que la sesión esté abierta no prueba que la persona esté delante. Para lo
   más delicado (revelar una contraseña de la bóveda, ver los tokens de las
   integraciones, datos avanzados) se vuelve a pedir la contraseña, y la
   confirmación vale 30 minutos para esa zona.

   Uso desde cualquier módulo:
     Reautenticacion::exigir('boveda');   // 403 {error:"reauth", zona} si falta
   El front responde a ese 403 con el diálogo de contraseña y
   POST /v1/auth/reconfirmar {password, zona}. */
final class Reautenticacion
{
    public const MINUTOS = 30;
    public const ZONAS = ['boveda', 'integraciones', 'datos'];

    public static function vigente(string $zona): bool
    {
        $t = (int)($_SESSION['reauth'][$zona] ?? 0);
        return $t > 0 && time() - $t < self::MINUTOS * 60;
    }

    /** @throws HttpError 403 'reauth' si no se ha confirmado hace poco */
    public static function exigir(string $zona): void
    {
        if (!self::vigente($zona)) {
            throw new HttpError(403, 'Confirma tu contraseña para seguir.', 'reauth', ['zona' => $zona]);
        }
    }

    /** Comprueba la contraseña de la persona y abre la zona. Devuelve hasta cuándo (unix). */
    public static function confirmar(array $admin, string $password, string $zona): int
    {
        if (!in_array($zona, self::ZONAS, true)) throw HttpError::validacion('Zona desconocida.', 'zona');
        $clave = 'reauth:' . (int)$admin['id'];
        if (function_exists('login_throttle_bloqueo')) {
            $espera = login_throttle_bloqueo($clave);
            if ($espera > 0) throw new HttpError(429, login_throttle_msg($espera), 'bloqueo', ['espera' => (int)$espera]);
        }
        if ($password === '' || !password_verify($password, (string)($admin['password_hash'] ?? ''))) {
            /* Un intento fallido no se puede repetir mil veces por segundo. */
            if (PHP_SAPI !== 'cli') usleep(400000);
            if (function_exists('login_throttle_fallo')) login_throttle_fallo($clave);
            if (function_exists('audit_log')) audit_log('reauth.fallo', $zona);
            throw HttpError::validacion('La contraseña no es correcta.', 'password');
        }
        if (function_exists('login_throttle_ok')) login_throttle_ok($clave);
        $_SESSION['reauth'][$zona] = time();
        if (function_exists('audit_log')) audit_log('reauth', $zona);
        return time() + self::MINUTOS * 60;
    }

    public static function cerrar(string $zona): void
    {
        unset($_SESSION['reauth'][$zona]);
    }
}
