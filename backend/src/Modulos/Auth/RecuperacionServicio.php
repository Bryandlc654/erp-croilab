<?php
namespace Croilab\Modulos\Auth;

use Croilab\Correo\Correo;
use Croilab\Correo\Mensaje;
use Croilab\Http\Diferidas;
use Croilab\Http\HttpError;
use PDO;

/* «He olvidado mi contraseña».

   1. pedir(): si el usuario o correo es de una cuenta activa con correo, se le
      manda un enlace de un solo uso que caduca en 60 minutos. La respuesta es
      siempre la misma: no se puede averiguar qué cuentas existen.
   2. comprobar(): el front pregunta si el enlace sigue valiendo antes de
      enseñar el formulario.
   3. restablecer(): cambia la contraseña (política, historial y cred_ver, que
      cierra todas las sesiones abiertas de esa cuenta) y gasta el enlace. */
class RecuperacionServicio
{
    public const MINUTOS = 60;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Correo $correo,
        private readonly string $urlFront,
        private readonly string $marca = 'Croilab'
    ) {}

    public function pedir(string $identificador, string $ip): void
    {
        $identificador = trim($identificador);
        if ($identificador === '' || mb_strlen($identificador) > 190) throw HttpError::validacion('Escribe tu usuario o tu correo.', 'identificador');

        $st = $this->pdo->prepare('SELECT id, username, email FROM admins
                                   WHERE activo = 1 AND (username = ? OR (email <> \'\' AND LOWER(email) = LOWER(?))) LIMIT 1');
        $st->execute([$identificador, $identificador]);
        $a = $st->fetch();
        if (!$a || !filter_var((string)$a['email'], FILTER_VALIDATE_EMAIL)) return;   // misma respuesta: no se delata nada

        /* Un enlace vivo por cuenta: pedir otro invalida el anterior. */
        $this->pdo->prepare('DELETE FROM password_resets WHERE admin_id = ? AND usado_en IS NULL')->execute([(int)$a['id']]);
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare('INSERT INTO password_resets (admin_id, token_hash, creado_en, expira_en, ip)
                             VALUES (?, ?, NOW(), NOW() + INTERVAL ' . self::MINUTOS . ' MINUTE, ?)')
            ->execute([(int)$a['id'], hash('sha256', $token), substr($ip, 0, 45)]);

        $enlace = $this->urlFront . '/restablecer?token=' . $token;
        $mensaje = new Mensaje(
            (string)$a['email'],
            "Restablecer tu contraseña de {$this->marca}",
            $this->textoEnlace((string)$a['username'], $enlace),
            $this->htmlEnlace((string)$a['username'], $enlace)
        );
        /* Después de responder: así la respuesta tarda lo mismo exista o no la cuenta. */
        Diferidas::agregar(fn() => $this->correo->enviar($mensaje));
    }

    /** Usuario del enlace si sigue valiendo; si no, 404 (caducado, usado o inventado). */
    public function comprobar(string $token): string
    {
        return (string)$this->buscar($token)['username'];
    }

    public function restablecer(string $token, string $nueva): void
    {
        $r = $this->buscar($token);
        /* La contraseña se valida ANTES de gastar el enlace: si no vale, se puede
           volver a intentar con el mismo enlace. */
        $err = password_valida($nueva) ?: password_uso_reciente('admins', (int)$r['admin_id'], $nueva);
        if ($err !== '') throw HttpError::validacion($err, 'password');

        /* Se gasta de forma atómica: de dos peticiones a la vez con el mismo
           enlace, solo una llega a cambiar la contraseña. */
        $st = $this->pdo->prepare('UPDATE password_resets SET usado_en = NOW() WHERE id = ? AND usado_en IS NULL AND expira_en > NOW()');
        $st->execute([(int)$r['id']]);
        if ($st->rowCount() !== 1) throw HttpError::noEncontrado('El enlace no es válido o ha caducado. Pide uno nuevo.');

        $res = credenciales_cambiar('admins', (int)$r['admin_id'], $nueva, ['propia' => true]);
        if (empty($res['ok'])) {
            $this->pdo->prepare('UPDATE password_resets SET usado_en = NULL WHERE id = ?')->execute([(int)$r['id']]);
            throw HttpError::validacion($res['msg'] ?: 'Contraseña no válida.', 'password');
        }
        /* Cualquier otro enlace que quedara de esa cuenta deja de valer. */
        $this->pdo->prepare('DELETE FROM password_resets WHERE admin_id = ? AND usado_en IS NULL')->execute([(int)$r['admin_id']]);
        /* Si estaba bloqueada por intentos fallidos, ya puede entrar. */
        if (function_exists('login_throttle_ok')) login_throttle_ok('adm:' . $r['username']);

        if (filter_var((string)$r['email'], FILTER_VALIDATE_EMAIL)) {
            /* El cambio ya está hecho: el aviso es un extra, si falla solo queda en el log. */
            $aviso = new Mensaje((string)$r['email'], "Tu contraseña de {$this->marca} ha cambiado", $this->textoAviso((string)$r['username']));
            Diferidas::agregar(fn() => $this->correo->enviar($aviso));
        }
    }

    private function buscar(string $token): array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) throw HttpError::noEncontrado('El enlace no es válido o ha caducado. Pide uno nuevo.');
        $st = $this->pdo->prepare('SELECT r.id, r.admin_id, a.username, a.email FROM password_resets r JOIN admins a ON a.id = r.admin_id
                                   WHERE r.token_hash = ? AND r.usado_en IS NULL AND r.expira_en > NOW() AND a.activo = 1');
        $st->execute([hash('sha256', $token)]);
        return $st->fetch() ?: throw HttpError::noEncontrado('El enlace no es válido o ha caducado. Pide uno nuevo.');
    }

    private function textoEnlace(string $usuario, string $enlace): string
    {
        return "Hola, $usuario:\n\nHemos recibido una petición para restablecer la contraseña de tu cuenta en el panel de {$this->marca}.\n\n"
            . "Para elegir una nueva, abre este enlace (vale durante " . self::MINUTOS . " minutos y una sola vez):\n\n$enlace\n\n"
            . "Si no lo has pedido tú, ignora este correo: tu contraseña no cambia.\n";
    }

    private function htmlEnlace(string $usuario, string $enlace): string
    {
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<!doctype html><html lang="es"><body style="margin:0;padding:24px;background:#f9fafb;font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#111827">'
            . '<div style="max-width:480px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px">'
            . '<p style="margin:0 0 16px;font-size:15px">Hola, ' . $e($usuario) . ':</p>'
            . '<p style="margin:0 0 20px;font-size:15px;line-height:1.6">Hemos recibido una petición para restablecer la contraseña de tu cuenta en el panel de ' . $e($this->marca) . '.</p>'
            . '<p style="margin:0 0 24px"><a href="' . $e($enlace) . '" style="display:inline-block;background:#141518;color:#fff;text-decoration:none;font-weight:600;padding:12px 20px;border-radius:10px">Elegir una contraseña nueva</a></p>'
            . '<p style="margin:0 0 8px;font-size:13px;color:#6b7280;line-height:1.6">El enlace vale durante ' . self::MINUTOS . ' minutos y una sola vez. Si no lo has pedido tú, ignora este correo: tu contraseña no cambia.</p>'
            . '<p style="margin:16px 0 0;font-size:12px;color:#9ca3af;word-break:break-all">' . $e($enlace) . '</p>'
            . '</div></body></html>';
    }

    private function textoAviso(string $usuario): string
    {
        return "Hola, $usuario:\n\nLa contraseña de tu cuenta en el panel de {$this->marca} se acaba de cambiar y se han cerrado las sesiones abiertas.\n\n"
            . "Si no has sido tú, avisa cuanto antes a quien gestiona el equipo.\n";
    }
}
