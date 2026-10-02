<?php
namespace Croilab\Modulos\Auth;

use Croilab\Correo\Fabrica;
use Croilab\Http\HttpError;
use Croilab\Http\Request;

/* Rutas públicas de «he olvidado mi contraseña». */
class RecuperacionController
{
    /** @var callable(): RecuperacionServicio */
    private $crear;

    /* El servicio se crea al usarlo: así una configuración de correo incompleta
       solo afecta a estas rutas, no a toda la API. */
    public function __construct(callable $crearServicio)
    {
        $this->crear = $crearServicio;
    }

    public function pedir(Request $req): array
    {
        $ident = trim((string)($req->json()['identificador'] ?? ''));
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        /* Freno por dato pedido y por IP: nadie puede usar esto para inundar el
           buzón de otra persona ni para probar cientos de usuarios. */
        foreach (['pwreq:' . mb_strtolower($ident), 'pwreq-ip'] as $clave) {
            $espera = login_throttle_bloqueo($clave);
            if ($espera > 0) throw new HttpError(429, login_throttle_msg($espera), 'bloqueo', ['espera' => (int)$espera]);
        }
        login_throttle_fallo('pwreq:' . mb_strtolower($ident));
        login_throttle_fallo('pwreq-ip');

        $this->servicio()->pedir($ident, $ip);
        return ['msg' => 'Si hay una cuenta con ese dato y tiene correo, te hemos enviado un enlace para elegir una contraseña nueva. Revisa también el correo no deseado.'];
    }

    public function comprobar(Request $req): array
    {
        return ['username' => $this->servicio()->comprobar($req->texto('token'))];
    }

    public function restablecer(Request $req): array
    {
        $d = $req->json();
        $this->servicio()->restablecer(trim((string)($d['token'] ?? '')), (string)($d['password'] ?? ''));
        sesion_auditar('contraseña restablecida', 'api');
        return [];
    }

    private function servicio(): RecuperacionServicio
    {
        try {
            return ($this->crear)();
        } catch (\RuntimeException $e) {
            error_log('Recuperación de contraseña sin configurar: ' . $e->getMessage());
            throw new HttpError(503, 'La recuperación de contraseña no está disponible ahora mismo. Avisa a quien gestiona el equipo.', 'no_configurado');
        }
    }

    /** URL pública del front (FRONT_URL), sin barra final. Nunca sale de la cabecera Host. */
    public static function urlFront(): string
    {
        $u = rtrim(Fabrica::valor('FRONT_URL'), '/');
        if (!preg_match('~^https?://[^\s/?#]+(/[^\s?#]*)?$~', $u)) throw new \RuntimeException('Falta FRONT_URL (ej. https://app.tudominio.com/admin)');
        return $u;
    }
}
