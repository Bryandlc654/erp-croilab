<?php
namespace Croilab\Modulos\Equipo;

use Croilab\Correo\Correo;
use Croilab\Http\Diferidas;
use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* «Mi equipo»: alta, ficha, rol, contraseña, facturación y baja.

   Fallos del antiguo que se corrigen aquí:
     · La baja era un borrado físico (mensajes, tickets y comentarios quedaban
       sin autor): ahora es lógica (activo = 0) y cierra sus sesiones.
     · «Último dueño» se miraba con role = 'owner' en la baja y con admin.total
       en el cambio de rol: ahora siempre admin.total y solo cuentas activas.
     · El alta permitía crear Dueños sin pasar por las reglas de los roles: dar
       o quitar un rol con acceso total, o tocar a alguien que lo tiene, exige
       tener acceso total.
     · Fijar o generar contraseña no cerraba las sesiones abiertas (cred_ver):
       ahora pasa por credenciales_cambiar().
     · El enlace para elegir contraseña era un token en claro en `settings`:
       ahora es password_resets (SHA-256), y se puede mandar por correo. */
class MiembrosServicio
{
    public const HORAS_ENLACE = 48;

    /** @param \Closure(): Correo $correo */
    public function __construct(
        private readonly PDO $pdo,
        private readonly EquipoRepositorio $equipo,
        private readonly \Closure $correo,
        private readonly string $urlFront,
        private readonly string $marca = 'Croilab'
    ) {}

    /* ---------- Lectura ---------- */

    public function listar(Acceso $acc, bool $bajas = false): array
    {
        $acc->exigir('equipo.gestionar');
        $items = array_map(fn($m) => $this->formato($m, $acc), $this->equipo->miembros($bajas));
        $clientes = (int)$this->pdo->query('SELECT COUNT(*) FROM clients WHERE activo = 1')->fetchColumn();
        $nBajas = (int)$this->pdo->query('SELECT COUNT(*) FROM admins WHERE activo = 0')->fetchColumn();
        return ['items' => $items, 'total' => count($items), 'clientes_alta' => $clientes, 'bajas' => $nBajas, 'roles' => self::rolesAsignables($acc)];
    }

    /** Roles para el selector: con acceso total solo si quien mira lo tiene. */
    public static function rolesAsignables(Acceso $acc): array
    {
        $out = [];
        foreach (roles_todos() as $k => $r) {
            $total = in_array('admin.total', $r['permisos'] ?? [], true);
            $out[] = ['clave' => (string)$k, 'nombre' => (string)$r['nombre'], 'descripcion' => (string)$r['descripcion'], 'total' => $total, 'asignable' => !$total || $acc->puede('admin.total')];
        }
        return $out;
    }

    public function detalle(Acceso $acc, int $id): array
    {
        $acc->exigir('equipo.gestionar');
        $m = $this->equipo->fila($id) ?? throw HttpError::noEncontrado('Esa persona no está en el equipo.');
        return $this->ficha($m, $acc);
    }

    private function ficha(array $m, Acceso $acc): array
    {
        $p = $this->equipo->perfil((int)$m['id']);
        $st = $this->pdo->prepare('SELECT expira_en FROM password_resets WHERE admin_id = ? AND usado_en IS NULL AND expira_en > NOW() ORDER BY id DESC LIMIT 1');
        $st->execute([(int)$m['id']]);
        $caduca = $st->fetchColumn();
        return $this->formato($m + ['cumple' => $p['cumple'], 'cargo' => $p['cargo']], $acc) + [
            'es_autonomo' => (int)($m['es_autonomo'] ?? 0) === 1,
            'tarifa_hora' => number_format((float)($m['tarifa_hora'] ?? 0), 2, '.', ''),
            'iva_pct' => number_format((float)($m['iva_pct'] ?? 0), 2, '.', ''),
            'irpf_pct' => number_format((float)($m['irpf_pct'] ?? 0), 2, '.', ''),
            'enlace_password' => $caduca ? ['caduca' => date('c', strtotime((string)$caduca))] : null,
        ];
    }

    private function formato(array $m, Acceso $acc): array
    {
        $roles = roles_todos();
        $rol = (string)$m['role'];
        $id = (int)$m['id'];
        return [
            'id' => $id,
            'username' => (string)$m['username'],
            'email' => ($m['email'] ?? '') !== '' ? (string)$m['email'] : null,
            'role' => $rol,
            'role_nombre' => (string)($roles[$rol]['nombre'] ?? $rol),
            'role_descripcion' => (string)($roles[$rol]['descripcion'] ?? ''),
            'es_admin_total' => self::rolTotal($rol),
            'activo' => (int)($m['activo'] ?? 1) === 1,
            'desde' => substr((string)($m['created_at'] ?? ''), 0, 7) ?: null,
            'cumple' => ($m['cumple'] ?? null) ?: null,
            'cargo' => (string)($m['cargo'] ?? ''),
            'foto' => $this->equipo->foto($id),
            'yo' => $id === $acc->adminId,
        ];
    }

    public static function rolTotal(string $rol): bool
    {
        return in_array('admin.total', roles_todos()[$rol]['permisos'] ?? [], true);
    }

    /* Solo quien tiene acceso total toca a otro con acceso total (o le da ese rol). */
    private function exigirPuedeTocar(Acceso $acc, array $m): void
    {
        if (self::rolTotal((string)$m['role']) && !$acc->puede('admin.total') && (int)$m['id'] !== $acc->adminId) {
            throw new HttpError(403, 'Solo alguien con acceso total puede cambiar a otra persona con acceso total.', 'permiso');
        }
    }

    private function exigirRolAsignable(Acceso $acc, string $rol): void
    {
        if (!isset(roles_todos()[$rol])) throw HttpError::validacion('Ese rol no existe.', 'role');
        if (self::rolTotal($rol) && !$acc->puede('admin.total')) {
            throw new HttpError(403, 'Solo alguien con acceso total puede dar un rol con acceso total.', 'permiso');
        }
    }

    private function miembro(int $id): array
    {
        $m = $this->equipo->fila($id) ?? throw HttpError::noEncontrado('Esa persona no está en el equipo.');
        return $m;
    }

    /* ---------- Alta ---------- */

    /** {username, email?, role, password?, enviar_enlace?, enviar_correo?} → {miembro, enlace?} */
    public function crear(Acceso $acc, array $d): array
    {
        $acc->exigir('equipo.gestionar');
        $username = Validar::username($d['username'] ?? '');
        $email = Validar::email($d['email'] ?? null, 'email', 'El correo de Google no tiene un formato válido.');
        $rol = trim((string)($d['role'] ?? 'editor'));
        $this->exigirRolAsignable($acc, $rol);
        $conEnlace = Validar::bool($d['enviar_enlace'] ?? false);
        $pass = (string)($d['password'] ?? '');
        if (!$conEnlace) {
            if ($pass === '') throw HttpError::validacion('Pon una contraseña, o marca que se la ponga esa persona.', 'password');
            $err = password_valida($pass);
            if ($err !== '') throw HttpError::validacion($err, 'password');
        }
        if ($this->equipo->usernameOcupado($username)) throw HttpError::validacion('Ese usuario ya existe.', 'username');
        if ($email !== null && $this->equipo->emailOcupado($email)) throw HttpError::validacion('Ese correo de Google ya está asignado a otro miembro.', 'email');

        /* Con enlace, la cuenta nace con una contraseña aleatoria que nadie
           conoce: no queda abierta mientras tanto. */
        $hash = password_hash($conEnlace ? bin2hex(random_bytes(16)) : $pass, PASSWORD_DEFAULT);
        $id = $this->equipo->crear($username, $email, $rol, $hash);
        if (function_exists('audit_log')) audit_log('equipo.alta', "cuenta #$id ($username) con rol $rol");
        $enlace = $conEnlace ? $this->crearEnlace($id, Validar::bool($d['enviar_correo'] ?? false)) : null;
        return ['miembro' => $this->detalle($acc, $id)] + ($enlace ? ['enlace' => $enlace] : []);
    }

    /* ---------- Datos de acceso, rol y facturación ---------- */

    /** {username?, email?, role?} */
    public function actualizar(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('equipo.gestionar');
        $m = $this->miembro($id);
        $this->exigirPuedeTocar($acc, $m);
        $username = array_key_exists('username', $d) ? Validar::username($d['username']) : (string)$m['username'];
        $email = array_key_exists('email', $d) ? Validar::email($d['email'], 'email', 'El correo de Google no tiene un formato válido.') : (($m['email'] ?? '') ?: null);
        if ($this->equipo->usernameOcupado($username, $id)) throw HttpError::validacion('Ese usuario ya lo tiene otra persona.', 'username');
        if ($email !== null && $this->equipo->emailOcupado($email, $id)) throw HttpError::validacion('Ese correo de Google ya está asignado a otra persona.', 'email');
        $recargar = false;
        if (array_key_exists('role', $d) && (string)$d['role'] !== (string)$m['role']) {
            $recargar = $this->cambiarRol($acc, $id, (string)$d['role'])['recargar'];
        }
        $this->equipo->actualizarAcceso($id, $username, $email);
        return ['miembro' => $this->detalle($acc, $id), 'recargar' => $recargar];
    }

    /** Cambio rápido de rol desde la lista. → {miembro, recargar} */
    public function cambiarRol(Acceso $acc, int $id, string $rol): array
    {
        $acc->exigir('equipo.gestionar');
        $m = $this->miembro($id);
        $this->exigirPuedeTocar($acc, $m);
        $rol = trim($rol);
        $this->exigirRolAsignable($acc, $rol);
        if ((int)$m['activo'] !== 1) throw HttpError::validacion('Esa persona está dada de baja.');
        if ($rol === (string)$m['role']) return ['miembro' => $this->detalle($acc, $id), 'recargar' => false];
        /* Las bajas no cuentan como dueños (rol_asignar() sí las contaba). */
        if (self::rolTotal((string)$m['role']) && !self::rolTotal($rol) && $this->equipo->duenosActivos() <= 1) {
            throw new HttpError(409, 'Es la única persona con acceso total. Dale ese rol a alguien más antes de quitárselo.', 'ultimo_dueno');
        }
        $r = rol_asignar($id, $rol);
        if (empty($r['ok'])) throw new HttpError(409, (string)$r['msg'], 'rol');
        return ['miembro' => $this->detalle($acc, $id), 'recargar' => $id === $acc->adminId];
    }

    /** {es_autonomo, tarifa_hora, iva_pct, irpf_pct} */
    public function facturacion(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('equipo.gestionar');
        $m = $this->miembro($id);
        $this->exigirPuedeTocar($acc, $m);
        $this->equipo->actualizarFacturacion(
            $id,
            Validar::bool($d['es_autonomo'] ?? false),
            Validar::decimal($d['tarifa_hora'] ?? '0', 'tarifa_hora', 0, 9999, 'La tarifa por hora'),
            Validar::decimal($d['iva_pct'] ?? '0', 'iva_pct', 0, 100, 'El IVA'),
            Validar::decimal($d['irpf_pct'] ?? '0', 'irpf_pct', 0, 100, 'El IRPF')
        );
        return ['miembro' => $this->detalle($acc, $id)];
    }

    /* ---------- Contraseña ---------- */

    /**
     * {modo:'fijar', password} · {modo:'generar'} → {password} (una sola vez) ·
     * {modo:'enlace', enviar_correo?} → {enlace:{url, caduca, enviado}}.
     * Cualquiera de las tres anula el enlace pendiente que hubiera.
     */
    public function password(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('equipo.gestionar');
        $m = $this->miembro($id);
        $this->exigirPuedeTocar($acc, $m);
        if ((int)$m['activo'] !== 1) throw HttpError::validacion('Esa persona está dada de baja.');
        $modo = (string)($d['modo'] ?? '');
        if ($modo === 'enlace') return ['enlace' => $this->crearEnlace($id, Validar::bool($d['enviar_correo'] ?? false))];
        if ($modo !== 'fijar' && $modo !== 'generar') throw HttpError::validacion('Modo desconocido.', 'modo');

        $nueva = $modo === 'generar' ? password_generar(14) : (string)($d['password'] ?? '');
        $res = credenciales_cambiar('admins', $id, $nueva, ['propia' => $id === $acc->adminId]);
        if (empty($res['ok'])) throw HttpError::validacion((string)$res['msg'], 'password');
        $this->anular($id);
        /* Si es la propia cuenta, esta sesión sigue viva con la versión nueva. */
        if ($id === $acc->adminId && function_exists('credenciales_renovar_sesion')) credenciales_renovar_sesion('admins', $id);
        return $modo === 'generar' ? ['password' => $nueva] : [];
    }

    public function anularEnlace(Acceso $acc, int $id): array
    {
        $acc->exigir('equipo.gestionar');
        $this->exigirPuedeTocar($acc, $this->miembro($id));
        $this->anular($id);
        return [];
    }

    private function anular(int $id): void
    {
        $this->pdo->prepare('DELETE FROM password_resets WHERE admin_id = ? AND usado_en IS NULL')->execute([$id]);
    }

    /** Enlace de 48 h para que la persona elija su contraseña (usa /restablecer del front). */
    private function crearEnlace(int $id, bool $enviarCorreo): array
    {
        if ($this->urlFront === '') throw new HttpError(503, 'Falta FRONT_URL en la configuración del servidor: no se pueden crear enlaces.', 'no_configurado');
        $m = $this->miembro($id);
        $this->anular($id);
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare('INSERT INTO password_resets (admin_id, token_hash, creado_en, expira_en, ip) VALUES (?, ?, NOW(), NOW() + INTERVAL ' . self::HORAS_ENLACE . ' HOUR, ?)')
            ->execute([$id, hash('sha256', $token), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        $url = $this->urlFront . '/restablecer?token=' . $token;
        $enviado = false;
        if ($enviarCorreo) {
            $email = (string)($m['email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw HttpError::validacion('Esa persona no tiene correo: copia el enlace y pásaselo.', 'enviar_correo');
            $msg = CorreosEquipo::enlacePassword($email, (string)$m['username'], $this->marca, $url, self::HORAS_ENLACE);
            $crear = $this->correo;
            Diferidas::agregar(fn() => $crear()->enviar($msg));
            $enviado = true;
        }
        if (function_exists('audit_log')) audit_log('equipo.enlace_password', "cuenta #$id" . ($enviado ? ' (por correo)' : ''));
        return ['url' => $url, 'caduca' => date('c', time() + self::HORAS_ENLACE * 3600), 'enviado' => $enviado];
    }

    /* ---------- Baja ---------- */

    public function baja(Acceso $acc, int $id): array
    {
        $acc->exigir('equipo.gestionar');
        $m = $this->miembro($id);
        if ($id === $acc->adminId) throw new HttpError(409, 'No puedes darte de baja a ti mismo.', 'uno_mismo');
        $this->exigirPuedeTocar($acc, $m);
        if ((int)$m['activo'] !== 1) return [];
        if (self::rolTotal((string)$m['role']) && $this->equipo->duenosActivos() <= 1) {
            throw new HttpError(409, 'Es la única persona con acceso total. Dale ese rol a alguien más antes de darla de baja.', 'ultimo_dueno');
        }
        $this->equipo->darDeBaja($id);
        if (function_exists('audit_log')) audit_log('equipo.baja', "cuenta #$id ({$m['username']})");
        if (function_exists('notif_duenos')) {
            foreach (notif_duenos($acc->adminId) as $d) {
                notif_add($d, 'info', $m['username'] . ' ya no tiene acceso al panel', 'Dado de baja del equipo', '/ajustes/equipo', 'baja:' . $id . ':' . date('YmdHi'), '', '', 'otras');
            }
        }
        return [];
    }

    public function reactivar(Acceso $acc, int $id): array
    {
        $acc->exigir('equipo.gestionar');
        $m = $this->miembro($id);
        $this->exigirPuedeTocar($acc, $m);
        if ($this->equipo->usernameOcupado((string)$m['username'], $id)) throw HttpError::validacion('Ese usuario ya lo tiene otra persona: cámbiaselo antes.');
        $this->equipo->reactivar($id);
        if (function_exists('audit_log')) audit_log('equipo.reactivar', "cuenta #$id");
        return ['miembro' => $this->detalle($acc, $id)];
    }
}
