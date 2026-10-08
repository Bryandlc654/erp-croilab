<?php
namespace Croilab\Modulos\Equipo;

use Croilab\Correo\Correo;
use Croilab\Http\Diferidas;
use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Registro por enlace (team.php #registro + registro.php + lib/signup.php).

   Un enlace de un solo uso que caduca a las 48 h y lleva el ROL con el que
   entrará la persona. Nunca con acceso total. El token se busca por SHA-256;
   se guarda además cifrado con la bóveda solo para poder volver a copiarlo. */
class InvitacionesServicio
{
    public const HORAS = 48;
    private const PROPOSITO = 'invitacion';

    /** @param \Closure(): Correo $correo */
    public function __construct(
        private readonly PDO $pdo,
        private readonly EquipoRepositorio $equipo,
        private readonly \Closure $correo,
        private readonly string $urlFront,
        private readonly array $marca = ['name' => 'Croilab', 'initial' => 'C', 'logo' => '', 'color' => '']
    ) {
        require_once __DIR__ . '/../../../admin/lib/boveda.php';
    }

    private function url(string $token): string
    {
        if ($this->urlFront === '') throw new HttpError(503, 'Falta FRONT_URL en la configuración del servidor: no se pueden crear enlaces.', 'no_configurado');
        return $this->urlFront . '/registro?t=' . $token;
    }

    /** Roles que se pueden dar por enlace: todos menos los de acceso total. */
    public static function rolesInvitables(): array
    {
        $out = [];
        foreach (roles_todos() as $k => $r) {
            if (!in_array('admin.total', $r['permisos'] ?? [], true)) $out[] = ['clave' => (string)$k, 'nombre' => (string)$r['nombre']];
        }
        return $out;
    }

    public function listar(Acceso $acc): array
    {
        $acc->exigir('equipo.gestionar');
        $roles = roles_todos();
        $st = $this->pdo->query('SELECT i.id, i.rol, i.email, i.expira_en, i.creado_en, i.token_cifrado, a.username AS autor
                                 FROM team_invitations i LEFT JOIN admins a ON a.id = i.creado_por
                                 WHERE i.usado_en IS NULL AND i.expira_en > NOW() ORDER BY i.id DESC');
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                'id' => (int)$r['id'],
                'rol' => (string)$r['rol'],
                'rol_nombre' => (string)($roles[$r['rol']]['nombre'] ?? $r['rol']),
                'email' => $r['email'] ?: null,
                'caduca' => date('c', strtotime((string)$r['expira_en'])),
                'creado_por' => $r['autor'] !== null ? (string)$r['autor'] : null,
                'copiable' => (string)$r['token_cifrado'] !== '',
            ];
        }
        return ['items' => $items, 'roles' => self::rolesInvitables()];
    }

    /** {rol, email?} → {invitacion:{id, url, caduca, enviado}} */
    public function crear(Acceso $acc, array $d): array
    {
        $acc->exigir('equipo.gestionar', 'general.editar');
        $rol = trim((string)($d['rol'] ?? 'viewer'));
        $roles = roles_todos();
        if (!isset($roles[$rol])) throw HttpError::validacion('Ese rol no existe.', 'rol');
        if (in_array('admin.total', $roles[$rol]['permisos'] ?? [], true)) throw HttpError::validacion('Por enlace no se puede dar acceso total. Dale ese rol después, desde Mi equipo.', 'rol');
        $email = Validar::email($d['email'] ?? null);
        if ($email !== null && $this->equipo->emailOcupado($email)) throw HttpError::validacion('Ya hay una cuenta con ese correo.', 'email');

        $token = bin2hex(random_bytes(32));
        $url = $this->url($token);
        $cifrado = boveda_cifrar($token, self::PROPOSITO);
        $this->pdo->prepare('INSERT INTO team_invitations (token_hash, token_cifrado, rol, email, creado_por, creado_en, expira_en)
                             VALUES (?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL ' . self::HORAS . ' HOUR)')
            ->execute([hash('sha256', $token), is_string($cifrado) ? $cifrado : null, $rol, $email, $acc->adminId ?: null]);
        $id = (int)$this->pdo->lastInsertId();
        $enviado = false;
        if ($email !== null) {
            $msg = CorreosEquipo::invitacion($email, (string)$this->marca['name'], (string)$roles[$rol]['nombre'], $url, self::HORAS);
            $crear = $this->correo;
            Diferidas::agregar(fn() => $crear()->enviar($msg));
            $enviado = true;
        }
        if (function_exists('audit_log')) audit_log('equipo.invitacion', "#$id rol $rol" . ($email ? " a $email" : ''));
        return ['invitacion' => ['id' => $id, 'url' => $url, 'caduca' => date('c', time() + self::HORAS * 3600), 'enviado' => $enviado]];
    }

    /** Vuelve a dar la URL de un enlace vivo (para «Copiar enlace»). */
    public function enlace(Acceso $acc, int $id): array
    {
        $acc->exigir('equipo.gestionar');
        $st = $this->pdo->prepare('SELECT token_cifrado FROM team_invitations WHERE id = ? AND usado_en IS NULL AND expira_en > NOW()');
        $st->execute([$id]);
        $c = $st->fetchColumn();
        if ($c === false) throw HttpError::noEncontrado('Ese enlace ya no está activo.');
        $r = boveda_descifrar((string)$c, self::PROPOSITO);
        if (!$r['ok'] || !preg_match('/^[0-9a-f]{64}$/', (string)$r['v'])) {
            throw new HttpError(409, 'Este enlace no se puede volver a copiar. Anúlalo y genera otro.', 'no_copiable');
        }
        return ['url' => $this->url((string)$r['v'])];
    }

    public function anular(Acceso $acc, int $id): array
    {
        $acc->exigir('equipo.gestionar', 'general.editar');
        $this->pdo->prepare('DELETE FROM team_invitations WHERE id = ? AND usado_en IS NULL')->execute([$id]);
        return [];
    }

    /* ---------- Público: /registro?t= ---------- */

    private function buscar(string $token): array
    {
        $no = fn() => HttpError::noEncontrado('Este enlace ya no vale. Los enlaces para crear una cuenta caducan a las 48 horas y solo se pueden usar una vez. Pídele otro a quien lleve el panel.');
        if (!preg_match('/^[0-9a-f]{48}$|^[0-9a-f]{64}$/', $token)) throw $no();
        $st = $this->pdo->prepare('SELECT id, rol, email FROM team_invitations WHERE token_hash = ? AND usado_en IS NULL AND expira_en > NOW()');
        $st->execute([hash('sha256', $token)]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: throw $no();
    }

    /** Lo que enseña el formulario: rol y marca. 404 si el enlace no vale. */
    public function comprobar(string $token): array
    {
        $inv = $this->buscar($token);
        $roles = roles_todos();
        return [
            'rol_nombre' => (string)($roles[$inv['rol']]['nombre'] ?? $inv['rol']),
            'email' => $inv['email'] ?: null,
            'marca' => [
                'nombre' => (string)$this->marca['name'],
                'inicial' => (string)($this->marca['initial'] ?? mb_substr((string)$this->marca['name'], 0, 1)),
                'logo' => (string)($this->marca['logo'] ?? '') ?: null,
                'color' => (string)($this->marca['color'] ?? '') ?: null,
            ],
            'horas' => self::HORAS,
        ];
    }

    /** {token, username, email?, password} → {username}. Gasta el enlace. */
    public function registrar(array $d): array
    {
        $token = trim((string)($d['token'] ?? ''));
        $inv = $this->buscar($token);
        $username = Validar::texto($d['username'] ?? '', 200, 'username', 'El usuario');
        if (mb_strlen($username) < 2) throw HttpError::validacion('Escribe tu nombre de usuario (al menos 2 letras).', 'username');
        $username = Validar::username($username);
        $email = Validar::email($d['email'] ?? null, 'email', 'Ese correo no parece válido.');
        $pass = (string)($d['password'] ?? '');
        $err = password_valida($pass);
        if ($err !== '') throw HttpError::validacion($err, 'password');
        if ($this->equipo->usernameOcupado($username)) throw HttpError::validacion('Ya hay alguien con ese nombre de usuario. Elige otro.', 'username');
        if ($email !== null && $this->equipo->emailOcupado($email)) throw HttpError::validacion('Ya hay una cuenta con ese correo.', 'email');
        if (!isset(roles_todos()[$inv['rol']])) throw HttpError::noEncontrado('El rol de este enlace ya no existe. Pídele otro a quien lleve el panel.');

        $this->pdo->beginTransaction();
        try {
            /* Se gasta de forma atómica: dos registros a la vez con el mismo
               enlace, solo uno crea la cuenta. */
            $st = $this->pdo->prepare('UPDATE team_invitations SET usado_en = NOW() WHERE id = ? AND usado_en IS NULL AND expira_en > NOW()');
            $st->execute([(int)$inv['id']]);
            if ($st->rowCount() !== 1) throw HttpError::noEncontrado('Este enlace ya no vale. Pídele otro a quien lleve el panel.');
            $id = $this->equipo->crear($username, $email, (string)$inv['rol'], password_hash($pass, PASSWORD_DEFAULT));
            $this->pdo->prepare('UPDATE team_invitations SET usado_por = ?, token_cifrado = NULL WHERE id = ?')->execute([$id, (int)$inv['id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        if (function_exists('audit_log')) audit_log('equipo.registro', "cuenta #$id ($username) por enlace #{$inv['id']}");
        $this->avisarGestores($id, $username, (string)(roles_todos()[$inv['rol']]['nombre'] ?? $inv['rol']));
        return ['username' => $username];
    }

    /* A quien gestiona el equipo: «X se ha unido». */
    private function avisarGestores(int $id, string $username, string $rolNombre): void
    {
        if (!function_exists('notif_add')) return;
        $roles = [];
        foreach (roles_todos() as $k => $r) {
            $p = $r['permisos'] ?? [];
            if (in_array('admin.total', $p, true) || in_array('equipo.gestionar', $p, true)) $roles[] = (string)$k;
        }
        if (!$roles) return;
        $st = $this->pdo->prepare('SELECT id FROM admins WHERE activo = 1 AND id <> ? AND role IN (' . implode(',', array_fill(0, count($roles), '?')) . ')');
        $st->execute(array_merge([$id], $roles));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $g) {
            notif_add((int)$g, 'info', $username . ' se ha unido al equipo', 'Ha creado su cuenta con un enlace de registro · ' . $rolNombre, '/ajustes/equipo/' . $id, 'registro:' . $id, '', $username);
        }
    }
}
