<?php
namespace Croilab\Modulos\Portal;

use Croilab\Http\HttpError;
use PDO;

/* La sesión del cliente en su portal, aparte de la del equipo.

   El antiguo guardaba al cliente en $_SESSION['client_id'] y la versión de
   credenciales en la MISMA clave que el equipo ($_SESSION['cred_ver']): si
   alguien tenía las dos sesiones en el mismo navegador, la una tiraba la otra.
   Aquí el cliente vive en sus propias claves (`portal_cli`, `portal_ver`):
     · nunca toca `admin_id`, así que un cliente no pasa nunca por
       current_admin() ni por Acceso, y una sesión del equipo no es una sesión
       de cliente (las rutas del portal solo miran estas claves);
     · cambiar la contraseña (sube clients.cred_ver) o dar de baja al cliente
       cierra su sesión en la petición siguiente;
     · salir del portal solo borra lo del portal. */
final class PortalSesion
{
    private const CLAVE = 'portal_cli';
    private const VERSION = 'portal_ver';

    /** @var array<int, array|null> fila por id, una lectura por petición */
    private array $cache = [];

    public function __construct(private readonly PDO $pdo) {}

    public function id(): int
    {
        return (int)($_SESSION[self::CLAVE] ?? 0);
    }

    /** La fila del cliente con sesión, o null (sin sesión, contraseña cambiada o dado de baja). */
    public function cliente(): ?array
    {
        $id = $this->id();
        if (!$id) return null;
        if (!array_key_exists($id, $this->cache)) {
            $st = $this->pdo->prepare('SELECT * FROM clients WHERE id = ?');
            $st->execute([$id]);
            $this->cache[$id] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $c = $this->cache[$id];
        if (!$c || (int)($c['activo'] ?? 1) !== 1 || (int)($_SESSION[self::VERSION] ?? -1) !== (int)($c['cred_ver'] ?? 1)) {
            if (function_exists('sesion_auditar')) sesion_auditar('cierre portal', $c ? 'credenciales cambiadas o cliente de baja' : 'cliente borrado');
            $this->olvidar();
            return null;
        }
        return $c;
    }

    /** Fila del cliente o 401 (el front vuelve al acceso del portal). */
    public function exigir(): array
    {
        return $this->cliente() ?? throw new HttpError(401, 'Tu sesión ha terminado. Vuelve a entrar.', 'portal_sesion');
    }

    /** Abre la sesión del cliente (después de comprobar sus credenciales). Devuelve el CSRF nuevo. */
    public function entrar(array $c): string
    {
        self::regenerar();
        $_SESSION[self::CLAVE] = (int)$c['id'];
        $_SESSION[self::VERSION] = (int)($c['cred_ver'] ?? 1);
        $this->cache[(int)$c['id']] = $c;
        unset($_SESSION['csrf_token']);   // token nuevo con la sesión nueva
        return csrf_token();
    }

    /** Cierra solo la sesión del portal. Devuelve el CSRF nuevo. */
    public function salir(): string
    {
        $this->olvidar();
        self::regenerar();
        unset($_SESSION['csrf_token']);
        return csrf_token();
    }

    private function olvidar(): void
    {
        unset($_SESSION[self::CLAVE], $_SESSION[self::VERSION]);
        $this->cache = [];
    }

    /** ¿Hay una sesión del equipo en este navegador? (para el «modo equipo» del acceso). */
    public static function hayEquipo(): bool
    {
        return !empty($_SESSION['admin_id']);
    }

    private static function regenerar(): void
    {
        /* En consola (tests) no hay sesión de PHP que regenerar. */
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    }
}
