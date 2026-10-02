<?php
namespace Croilab\Modulos\Auth;

use Croilab\Http\HttpError;
use Croilab\Http\Request;
use Croilab\Modulos\Equipo\EquipoRepositorio;

/* Entrada y salida del equipo. Mismas reglas que el login del panel antiguo:
   freno por usuario+IP, cuenta activa y versión de credenciales sellada en la
   sesión (sin ella current_admin() cierra la sesión en la petición siguiente). */
class AuthController
{
    /* Hash bcrypt de una contraseña aleatoria que nadie conoce. */
    private const HASH_DE_RELLENO = '$2y$10$XkenNbVnHVolpKbFUcWNz.bqrFAHs2IK5trk4O5nX8NyaRVBDpEJe';

    public function __construct(private readonly EquipoRepositorio $equipo) {}

    public function csrf(Request $req): array
    {
        return ['csrf' => csrf_token()];
    }

    public function login(Request $req): array
    {
        $d = $req->json();
        $user = trim((string)($d['username'] ?? ''));
        $pass = (string)($d['password'] ?? '');
        if ($user === '' || $pass === '') throw HttpError::validacion('Usuario y contraseña obligatorios.');

        $clave = 'adm:' . $user;
        $espera = login_throttle_bloqueo($clave);
        if ($espera > 0) throw new HttpError(429, login_throttle_msg($espera), 'bloqueo', ['espera' => (int)$espera]);

        $st = db()->prepare('SELECT * FROM admins WHERE username = ?');
        $st->execute([$user]);
        $ad = $st->fetch();
        /* Si el usuario no existe se verifica igual contra un hash cualquiera: así
           el tiempo de respuesta no delata qué usuarios existen. */
        $hash = $ad ? (string)$ad['password_hash'] : self::HASH_DE_RELLENO;
        $ok = password_verify($pass, $hash) && $ad && (int)$ad['activo'] === 1;
        if (!$ok) {
            login_throttle_fallo($clave);
            sesion_auditar('entrada fallida', 'api, usuario "' . $user . '"');
            throw new HttpError(401, 'Usuario o contraseña incorrectos.', 'credenciales');
        }

        login_throttle_ok($clave);
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$ad['id'];
        cred_ver_sellar('admins', $ad);
        unset($_SESSION['csrf_token']);   // token nuevo con la sesión nueva
        sesion_auditar('entrada', 'api, ' . $ad['username']);
        return ['csrf' => csrf_token(), 'me' => $this->yo()];
    }

    public function logout(Request $req): array
    {
        sesion_cerrar('logout api');
        return [];
    }

    public function me(Request $req): array
    {
        return ['me' => $this->yo(), 'csrf' => csrf_token()];
    }

    private function yo(): array
    {
        $me = current_admin() ?? throw HttpError::sesion();
        $rol = (string)($me['role'] ?? '');
        $roles = function_exists('roles_todos') ? roles_todos() : [];
        return [
            'id' => (int)$me['id'],
            'username' => (string)$me['username'],
            'role' => $rol,
            'role_nombre' => $roles[$rol]['nombre'] ?? $rol,
            'foto' => $this->equipo->foto((int)$me['id']),
            /* El front oculta lo que no puede usar; la API lo vuelve a comprobar igual. */
            'permisos' => array_values(function_exists('perm_mios') ? perm_mios() : []),
        ];
    }
}
