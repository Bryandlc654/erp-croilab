<?php
namespace Croilab\Modulos\Portal;

use Croilab\Correo\Correo;
use Croilab\Correo\Mensaje;
use Croilab\Google\Cuenta;
use Croilab\Google\ErrorGoogle;
use Croilab\Google\GoogleOAuth;
use Croilab\Http\Diferidas;
use Croilab\Http\HttpError;
use PDO;

/* Entrada del cliente a su portal: usuario (o su correo de Google) y
   contraseña, «Entrar con Google», salida y «he olvidado mi contraseña».

   Las mismas reglas que el login del equipo: freno de intentos por
   identificador + IP (`cli:`), tiempo de respuesta igual exista o no la
   cuenta, sesión regenerada y versión de credenciales sellada. Lo que el
   antiguo no hacía: un cliente dado de baja ya no entra, y si en este
   navegador hay una sesión del equipo no se mezclan (el acceso pasa a «modo
   equipo» y se entra al portal de un cliente por la vista previa). */
class PortalAuthServicio
{
    public const MINUTOS_ENLACE = 60;
    /* bcrypt de una contraseña aleatoria que nadie conoce (mismo truco que el login del equipo). */
    private const HASH_DE_RELLENO = '$2y$10$XkenNbVnHVolpKbFUcWNz.bqrFAHs2IK5trk4O5nX8NyaRVBDpEJe';

    /** @param callable(): Correo $correo  se crea al usarlo (una configuración de correo incompleta no tumba el login) */
    public function __construct(
        private readonly PDO $pdo,
        private readonly PortalSesion $sesion,
        private readonly ?GoogleOAuth $google = null,
        private $correo = null,
        private readonly string $urlFront = ''
    ) {}

    /** @return array{csrf:string, cliente:array} */
    public function entrar(string $ident, string $pass): array
    {
        $ident = trim($ident);
        if ($ident === '' || $pass === '') throw HttpError::validacion('Escribe tu usuario y tu contraseña.');
        if (mb_strlen($ident) > 190 || strlen($pass) > 200) throw new HttpError(401, 'Usuario o contraseña incorrectos.', 'credenciales');
        if (PortalSesion::hayEquipo()) {
            throw new HttpError(409, 'Has entrado como equipo en este navegador. Abre el portal del cliente desde su vista previa o cierra antes la sesión del equipo.', 'equipo');
        }
        $clave = 'cli:' . mb_strtolower($ident);
        $espera = login_throttle_bloqueo($clave);
        if ($espera > 0) throw new HttpError(429, login_throttle_msg($espera), 'bloqueo', ['espera' => (int)$espera]);

        $c = $this->buscar($ident);
        $ok = password_verify($pass, $c ? (string)$c['password_hash'] : self::HASH_DE_RELLENO) && $c !== null;
        if (!$ok) {
            login_throttle_fallo($clave);
            if (function_exists('sesion_auditar')) sesion_auditar('entrada fallida portal', 'usuario "' . mb_substr($ident, 0, 60) . '"');
            throw new HttpError(401, 'Usuario o contraseña incorrectos.', 'credenciales');
        }
        login_throttle_ok($clave);
        if ((int)$c['activo'] !== 1) {
            /* La contraseña era buena: se puede decir el porqué sin delatar nada. */
            throw new HttpError(403, 'Tu acceso está desactivado. Ponte en contacto con tu equipo y lo revisamos.', 'inactivo');
        }
        $csrf = $this->sesion->entrar($c);
        if (function_exists('sesion_auditar')) sesion_auditar('entrada portal', 'cliente #' . $c['id']);
        return ['csrf' => $csrf, 'cliente' => self::resumen($c)];
    }

    public function salir(): string
    {
        return $this->sesion->salir();
    }

    /** Usuario exacto primero; si no, el correo de «Entrar con Google». */
    private function buscar(string $ident): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM clients WHERE username = ? LIMIT 1');
        $st->execute([$ident]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if ($c) return $c;
        if (!str_contains($ident, '@')) return null;
        $st = $this->pdo->prepare("SELECT * FROM clients WHERE login_email <> '' AND LOWER(login_email) = ? LIMIT 2");
        $st->execute([mb_strtolower($ident)]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        return count($filas) === 1 ? $filas[0] : null;
    }

    public static function resumen(array $c): array
    {
        $ini = trim((string)$c['iniciales']);
        return [
            'id' => (int)$c['id'], 'name' => (string)$c['name'], 'saludo' => trim((string)$c['saludo']) ?: (string)$c['name'],
            'iniciales' => $ini !== '' ? $ini : 'CL',
        ];
    }

    /* ---------- Google ---------- */

    public function googleDisponible(): bool
    {
        return $this->google !== null && $this->google->configurado('calendar');
    }

    /** URL de Google para «Entrar con Google»; vuelve a /portal/login (con la marca de la agencia, si la hay). */
    public function urlGoogle(int $agencia): string
    {
        if (PortalSesion::hayEquipo()) throw new HttpError(409, 'Has entrado como equipo en este navegador.', 'equipo');
        if (!$this->google) throw new HttpError(409, 'El acceso con Google todavía no está disponible.', 'google_sin_configurar');
        try {
            return $this->google->urlAutorizacion(Cuenta::login(), '/portal/login' . ($agencia > 0 ? '?m=' . $agencia : ''));
        } catch (ErrorGoogle $e) {
            throw new HttpError(409, 'El acceso con Google todavía no está disponible.', 'google_sin_configurar');
        }
    }

    /**
     * Termina «Entrar con Google» con lo que devolvió GoogleOAuth::procesarVuelta().
     * Devuelve la ruta del front a la que ir, o null si la vuelta no era del portal.
     * La llama la vuelta común de Google (Equipo: IntegracionesServicio::vuelta).
     */
    public function vueltaGoogle(array $r): ?string
    {
        $cuenta = $r['cuenta'] ?? null;
        $volver = (string)($r['volver'] ?? '');
        if (!$cuenta instanceof Cuenta || $cuenta->integracion !== Cuenta::LOGIN || !str_starts_with($volver, '/portal')) return null;
        $login = strtok($volver, '?') ?: '/portal/login';
        parse_str((string)parse_url($volver, PHP_URL_QUERY), $q);
        $m = isset($q['m']) && ctype_digit((string)$q['m']) ? '&m=' . $q['m'] : '';
        if (empty($r['ok'])) return $login . '?ge=' . (($r['codigo'] ?? '') === 'cancelado' ? 'cancel' : 'err') . $m;
        if (PortalSesion::hayEquipo()) return $login . '?ge=equipo' . $m;

        $clave = 'cli-google';
        if (login_throttle_bloqueo($clave) > 0) return $login . '?ge=err' . $m;
        $email = mb_strtolower(trim((string)($r['email'] ?? '')));
        $st = $this->pdo->prepare("SELECT * FROM clients WHERE login_email <> '' AND LOWER(login_email) = ? LIMIT 2");
        $st->execute([$email]);
        $filas = $email !== '' ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        if (count($filas) !== 1) {
            login_throttle_fallo($clave);
            return $login . '?ge=denied' . $m;
        }
        if ((int)$filas[0]['activo'] !== 1) return $login . '?ge=inactivo' . $m;
        $this->sesion->entrar($filas[0]);
        if (function_exists('sesion_auditar')) sesion_auditar('entrada portal', 'google, cliente #' . $filas[0]['id']);
        return '/portal';
    }

    /* ---------- «He olvidado mi contraseña» ---------- */

    /** Manda un enlace de un solo uso al correo del cliente. Misma respuesta exista o no la cuenta. */
    public function pedirEnlace(string $ident, string $ip): void
    {
        $ident = trim($ident);
        if ($ident === '' || mb_strlen($ident) > 190) throw HttpError::validacion('Escribe tu usuario o tu correo.', 'identificador');
        $c = $this->buscar($ident);
        if (!$c || (int)$c['activo'] !== 1) return;
        $para = self::correoDe($c);
        if ($para === '') return;

        $this->pdo->prepare('DELETE FROM portal_password_resets WHERE client_id = ? AND usado_en IS NULL')->execute([(int)$c['id']]);
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare('INSERT INTO portal_password_resets (client_id, token_hash, creado_en, expira_en, ip)
                             VALUES (?, ?, NOW(), NOW() + INTERVAL ' . self::MINUTOS_ENLACE . ' MINUTE, ?)')
            ->execute([(int)$c['id'], hash('sha256', $token), substr($ip, 0, 45)]);

        $marca = self::marca($c);
        $pid = (int)($c['partner_id'] ?? 0);
        $enlace = $this->urlFront . '/portal/restablecer?token=' . $token . ($pid ? '&m=' . $pid : '');
        $nombre = trim((string)$c['saludo']) ?: (string)$c['name'];
        $msg = new Mensaje($para, "Restablecer tu contraseña del área de cliente de $marca",
            "Hola, $nombre:\n\nHemos recibido una petición para restablecer la contraseña de tu área de cliente de $marca (usuario: {$c['username']}).\n\n"
            . 'Para elegir una nueva, abre este enlace (vale durante ' . self::MINUTOS_ENLACE . " minutos y una sola vez):\n\n$enlace\n\n"
            . "Si no lo has pedido tú, ignora este correo: tu contraseña no cambia.\n",
            self::htmlEnlace($nombre, (string)$c['username'], $marca, $enlace));
        $correo = $this->correo;
        /* Después de responder: la respuesta tarda lo mismo exista o no la cuenta. */
        Diferidas::agregar(fn() => $correo()->enviar($msg));
    }

    /** Usuario del enlace si sigue valiendo; si no, 404. */
    public function comprobarEnlace(string $token): string
    {
        return (string)$this->enlace($token)['username'];
    }

    public function restablecer(string $token, string $nueva): void
    {
        $r = $this->enlace($token);
        $err = password_valida($nueva) ?: password_uso_reciente('clients', (int)$r['client_id'], $nueva);
        if ($err !== '') throw HttpError::validacion($err, 'password');
        $st = $this->pdo->prepare('UPDATE portal_password_resets SET usado_en = NOW() WHERE id = ? AND usado_en IS NULL AND expira_en > NOW()');
        $st->execute([(int)$r['id']]);
        if ($st->rowCount() !== 1) throw HttpError::noEncontrado('El enlace no es válido o ha caducado. Pide uno nuevo.');
        /* credenciales_cambiar sube cred_ver: se cierran las sesiones abiertas del cliente. */
        $res = credenciales_cambiar('clients', (int)$r['client_id'], $nueva);
        if (empty($res['ok'])) {
            $this->pdo->prepare('UPDATE portal_password_resets SET usado_en = NULL WHERE id = ?')->execute([(int)$r['id']]);
            throw HttpError::validacion($res['msg'] ?: 'Contraseña no válida.', 'password');
        }
        $this->pdo->prepare('DELETE FROM portal_password_resets WHERE client_id = ? AND usado_en IS NULL')->execute([(int)$r['client_id']]);
        login_throttle_ok('cli:' . mb_strtolower((string)$r['username']));
        if (function_exists('sesion_auditar')) sesion_auditar('contraseña restablecida portal', 'cliente #' . $r['client_id']);
    }

    private function enlace(string $token): array
    {
        $no = HttpError::noEncontrado('El enlace no es válido o ha caducado. Pide uno nuevo.');
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) throw $no;
        $st = $this->pdo->prepare('SELECT r.id, r.client_id, c.username FROM portal_password_resets r JOIN clients c ON c.id = r.client_id
                                   WHERE r.token_hash = ? AND r.usado_en IS NULL AND r.expira_en > NOW() AND c.activo = 1');
        $st->execute([hash('sha256', $token)]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: throw $no;
    }

    /** El correo al que escribir al cliente: el de Google y, si no, el de facturación. */
    public static function correoDe(array $c): string
    {
        foreach (['login_email', 'fact_email', 'email'] as $k) {
            $e = trim((string)($c[$k] ?? ''));
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) return $e;
        }
        return '';
    }

    private static function marca(array $c): string
    {
        return function_exists('marca_partner') ? (string)marca_partner((int)($c['partner_id'] ?? 0))['name'] : 'Croilab';
    }

    private static function htmlEnlace(string $nombre, string $usuario, string $marca, string $enlace): string
    {
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<!doctype html><html lang="es"><body style="margin:0;padding:24px;background:#f5f5f7;font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#22262c">'
            . '<div style="max-width:480px;margin:0 auto;background:#fff;border:1px solid #eeeeef;border-radius:20px;padding:28px">'
            . '<p style="margin:0 0 16px;font-size:15px">Hola, ' . $e($nombre) . ':</p>'
            . '<p style="margin:0 0 20px;font-size:15px;line-height:1.6">Hemos recibido una petición para restablecer la contraseña de tu área de cliente de ' . $e($marca) . ' (usuario: <b>' . $e($usuario) . '</b>).</p>'
            . '<p style="margin:0 0 24px"><a href="' . $e($enlace) . '" style="display:inline-block;background:#1f232a;color:#fff;text-decoration:none;font-weight:600;padding:12px 20px;border-radius:12px">Elegir una contraseña nueva</a></p>'
            . '<p style="margin:0 0 8px;font-size:13px;color:#9aa0a8;line-height:1.6">El enlace vale durante ' . self::MINUTOS_ENLACE . ' minutos y una sola vez. Si no lo has pedido tú, ignora este correo: tu contraseña no cambia.</p>'
            . '</div></body></html>';
    }
}
