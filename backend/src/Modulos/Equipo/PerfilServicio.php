<?php
namespace Croilab\Modulos\Equipo;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Perfil social (perfil.php) y centro de cuenta propio (cuenta, contraseña,
   avisos).

   Cambios respecto al antiguo:
     · El rol se enseña con su nombre real (antes los roles propios salían
       como «Miembro»).
     · Las tareas pendientes cuentan también las asignadas (task_assignees) y
       respetan el alcance de quien mira.
     · Cambiar la propia contraseña cierra las demás sesiones (cred_ver) y deja
       viva la actual. */
class PerfilServicio
{
    public const DEPARTAMENTOS = ['Dirección', 'Cuentas', 'SEO', 'Contenidos', 'Diseño', 'Desarrollo', 'Publicidad', 'Administración', 'Soporte'];
    public const MAX_FOTO = 8 * 1048576;

    public function __construct(
        private readonly PDO $pdo,
        private readonly EquipoRepositorio $equipo,
        private readonly Subidas $subidas
    ) {}

    private function puedeEditar(Acceso $acc, int $id): bool
    {
        return $id === $acc->adminId || $acc->puede('equipo.gestionar');
    }

    private function exigirEditar(Acceso $acc, int $id): array
    {
        $m = $this->equipo->fila($id) ?? throw HttpError::noEncontrado('Esa persona no está en el equipo.');
        if (!$this->puedeEditar($acc, $id)) throw HttpError::permiso();
        /* Quien gestiona el equipo sin acceso total no edita a un Dueño. */
        if ($id !== $acc->adminId && MiembrosServicio::rolTotal((string)$m['role']) && !$acc->puede('admin.total')) throw HttpError::permiso();
        return $m;
    }

    public function ver(Acceso $acc, int $id): array
    {
        $m = $this->equipo->fila($id) ?? throw HttpError::noEncontrado('Esa persona no está en el equipo.');
        $roles = roles_todos();
        $p = $this->equipo->perfil($id);
        $tareas = $acc->puede('ver.tareas') ? $this->equipo->tareasPendientes($acc, $id) : ['items' => [], 'total' => 0];
        return ['perfil' => [
            'id' => $id,
            'username' => (string)$m['username'],
            'email' => ($m['email'] ?? '') !== '' ? (string)$m['email'] : null,
            'role' => (string)$m['role'],
            'role_nombre' => (string)($roles[$m['role']]['nombre'] ?? $m['role']),
            'es_admin_total' => MiembrosServicio::rolTotal((string)$m['role']),
            'activo' => (int)$m['activo'] === 1,
            'foto' => $this->equipo->foto($id),
            'cargo' => $p['cargo'],
            'departamento' => $p['departamento'],
            'telefono' => $p['telefono'],
            'ubicacion' => $p['ubicacion'],
            'web' => $p['web'],
            'skills' => array_values(array_filter(array_map('trim', explode(',', $p['skills'])), fn($s) => $s !== '')),
            'cumple' => $p['cumple'],
            'bio' => $p['bio'],
            'presencia' => $this->equipo->presencia($id),
            'yo' => $id === $acc->adminId,
            'puede_editar' => $this->puedeEditar($acc, $id),
            'tareas' => $tareas,
        ], 'departamentos' => self::DEPARTAMENTOS];
    }

    /** {cargo, departamento, telefono, ubicacion, web, skills, cumple, bio} */
    public function actualizar(Acceso $acc, int $id, array $d): array
    {
        $this->exigirEditar($acc, $id);
        $actual = $this->equipo->perfil($id);
        $c = [];
        foreach (EquipoRepositorio::PERFIL as $k => $max) {
            $v = array_key_exists($k, $d) ? $d[$k] : $actual[$k];
            if ($k === 'skills' && is_array($v)) $v = implode(', ', array_map(fn($s) => is_scalar($s) ? trim((string)$s) : '', $v));
            $c[$k] = Validar::texto($v, $max, $k, ['cargo' => 'El cargo', 'departamento' => 'El departamento', 'telefono' => 'El teléfono', 'ubicacion' => 'La ubicación', 'web' => 'La web', 'skills' => 'Las habilidades', 'bio' => 'El texto'][$k]);
        }
        /* «ejemplo.com» → https://ejemplo.com (el antiguo lo añadía al pintar). */
        if ($c['web'] !== '' && !preg_match('~^https?://~i', $c['web'])) $c['web'] = 'https://' . $c['web'];
        if ($c['web'] !== '') $c['web'] = Validar::url($c['web'], 160, 'web', 'La web');
        $c['skills'] = implode(', ', array_slice(array_values(array_unique(array_filter(array_map('trim', explode(',', $c['skills'])), fn($s) => $s !== ''))), 0, 30));
        $c['cumple'] = array_key_exists('cumple', $d) ? Validar::fecha($d['cumple'], 'cumple') : $actual['cumple'];
        $this->equipo->guardarPerfil($id, $c);
        return $this->ver($acc, $id);
    }

    public function subirFoto(Acceso $acc, int $id, mixed $archivo): array
    {
        $this->exigirEditar($acc, $id);
        $nombre = $this->subidas->imagenSubida($archivo, 'avatars', 'a' . $id, self::MAX_FOTO, 800);
        $this->cambiarFoto($id, $nombre);
        return ['foto' => $this->equipo->foto($id)];
    }

    /** Para tests y procesos internos: la misma validación con un fichero en disco. */
    public function subirFotoDesdeRuta(Acceso $acc, int $id, string $ruta): array
    {
        $this->exigirEditar($acc, $id);
        $this->cambiarFoto($id, $this->subidas->guardarImagen($ruta, 'avatars', 'a' . $id, self::MAX_FOTO, 800));
        return ['foto' => $this->equipo->foto($id)];
    }

    private function cambiarFoto(int $id, string $nombre): void
    {
        $antes = $this->equipo->fijarFoto($id, $nombre);
        if ($antes !== '' && $antes !== $nombre) $this->subidas->borrar('avatars', $antes);
    }

    public function quitarFoto(Acceso $acc, int $id): array
    {
        $this->exigirEditar($acc, $id);
        $this->cambiarFoto($id, '');
        return ['foto' => null];
    }

    /* ---------- Mi cuenta ---------- */

    public function cuenta(Acceso $acc): array
    {
        $m = $this->equipo->fila($acc->adminId) ?? throw HttpError::sesion();
        return ['cuenta' => ['username' => (string)$m['username'], 'email' => ($m['email'] ?? '') ?: null,
                             'password_changed_at' => $m['password_changed_at'] ? date('c', strtotime((string)$m['password_changed_at'])) : null]];
    }

    /** {username, email} — único sitio donde cada uno cambia su usuario y su correo. */
    public function actualizarCuenta(Acceso $acc, array $d): array
    {
        $m = $this->equipo->fila($acc->adminId) ?? throw HttpError::sesion();
        $u = Validar::texto($d['username'] ?? $m['username'], 200, 'username', 'El nombre');
        if ($u === '') throw HttpError::validacion('El nombre no puede quedar vacío.', 'username');
        $u = Validar::username($u);
        $email = array_key_exists('email', $d) ? Validar::email($d['email']) : (($m['email'] ?? '') ?: null);
        if ($this->equipo->usernameOcupado($u, $acc->adminId)) throw HttpError::validacion('Ese nombre de usuario ya existe.', 'username');
        if ($email !== null && $this->equipo->emailOcupado($email, $acc->adminId)) throw HttpError::validacion('Ese correo ya está asignado a otro miembro.', 'email');
        $this->equipo->actualizarAcceso($acc->adminId, $u, $email);
        if (function_exists('audit_log') && ($u !== $m['username'] || $email !== (($m['email'] ?? '') ?: null))) audit_log('cuenta.datos', 'cuenta #' . $acc->adminId);
        return $this->cuenta($acc);
    }

    /** {actual, nueva} */
    public function cambiarPassword(Acceso $acc, array $d): array
    {
        $m = $this->equipo->fila($acc->adminId) ?? throw HttpError::sesion();
        $actual = (string)($d['actual'] ?? '');
        $nueva = (string)($d['nueva'] ?? '');
        $clave = 'pwcambio:' . $acc->adminId;
        if (function_exists('login_throttle_bloqueo') && ($espera = login_throttle_bloqueo($clave)) > 0) {
            throw new HttpError(429, login_throttle_msg($espera), 'bloqueo', ['espera' => (int)$espera]);
        }
        if (!password_verify($actual, (string)$m['password_hash'])) {
            if (function_exists('login_throttle_fallo')) login_throttle_fallo($clave);
            throw HttpError::validacion('La contraseña actual no es correcta.', 'actual');
        }
        if (function_exists('login_throttle_ok')) login_throttle_ok($clave);
        $res = credenciales_cambiar('admins', $acc->adminId, $nueva, ['propia' => true]);
        if (empty($res['ok'])) throw HttpError::validacion((string)$res['msg'], 'nueva');
        $this->pdo->prepare('DELETE FROM password_resets WHERE admin_id = ? AND usado_en IS NULL')->execute([$acc->adminId]);
        /* Las demás sesiones caen; esta sigue (con la versión nueva). */
        credenciales_renovar_sesion('admins', $acc->adminId);
        return ['csrf' => function_exists('csrf_token') && session_status() === PHP_SESSION_ACTIVE ? csrf_token() : null];
    }

    /** {silenciar: ('chat'|'avisos')[]} */
    public function avisos(Acceso $acc): array
    {
        $v = (string)get_setting('notifmute_' . $acc->adminId, '');
        return ['silenciar' => array_values(array_intersect(['chat', 'avisos'], array_map('trim', explode(',', $v))))];
    }

    public function guardarAvisos(Acceso $acc, array $d): array
    {
        $lista = is_array($d['silenciar'] ?? null) ? $d['silenciar'] : [];
        $ok = array_values(array_intersect(['chat', 'avisos'], array_map(fn($x) => is_string($x) ? $x : '', $lista)));
        $this->pdo->prepare('INSERT INTO settings (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
            ->execute(['notifmute_' . $acc->adminId, implode(',', $ok)]);
        return ['silenciar' => $ok];
    }
}
