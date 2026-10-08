<?php
namespace Croilab\Modulos\Clientes;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Marca blanca (agencias.php): agencias colaboradoras y qué cliente ve el
   portal con la marca de cuál. La resolución de la marca (lo vacío cae a la
   casa) sigue en admin/lib/marca.php, que es lo que leerá el portal. */
class AgenciasServicio
{
    public function __construct(private readonly PDO $pdo) {}

    public function listar(Acceso $acc): array
    {
        $acc->exigir('ver.ajustes');
        $agencias = [];
        $sql = 'SELECT a.*, (SELECT COUNT(*) FROM clients c WHERE c.partner_id = a.id) AS uso FROM partner_agencies a ORDER BY a.nombre, a.id';
        foreach ($this->pdo->query($sql) as $a) $agencias[] = $this->normalizar($a);
        /* La tabla de asignar solo enseña los clientes que se ven (antes, todos). */
        $clientes = [];
        foreach ($this->pdo->query('SELECT c.id, c.name, c.iniciales, c.partner_id FROM clients c WHERE 1' . $acc->sqlClientes('c.id') . ' ORDER BY c.name, c.id') as $c) {
            $clientes[] = ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'iniciales' => trim((string)$c['iniciales']), 'partner_id' => $c['partner_id'] !== null ? (int)$c['partner_id'] : null];
        }
        return ['agencias' => $agencias, 'clientes' => $clientes, 'casa' => marca_agencia()['name']];
    }

    public function crear(Acceso $acc, array $d): array
    {
        $acc->exigir('general.editar', 'marca.editar');
        $f = $this->validar($d);
        $this->pdo->prepare('INSERT INTO partner_agencies (nombre, color, logo_url, web, email, whatsapp, meeting_url, telefono) VALUES (?,?,?,?,?,?,?,?)')
            ->execute(array_values($f));
        $id = (int)$this->pdo->lastInsertId();
        if (function_exists('audit_log')) audit_log('agencia.crear', "#$id {$f['nombre']}");
        return $this->buscar($id);
    }

    public function actualizar(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('general.editar', 'marca.editar');
        $this->buscar($id) ?? throw HttpError::noEncontrado('Agencia no encontrada.');
        $f = $this->validar($d);
        $this->pdo->prepare('UPDATE partner_agencies SET nombre=?, color=?, logo_url=?, web=?, email=?, whatsapp=?, meeting_url=?, telefono=? WHERE id=?')
            ->execute([...array_values($f), $id]);
        if (function_exists('audit_log')) audit_log('agencia.editar', "#$id {$f['nombre']}");
        return $this->buscar($id);
    }

    /** Sus clientes vuelven a la marca de la casa. */
    public function borrar(Acceso $acc, int $id): void
    {
        $acc->exigir('general.editar', 'marca.editar');
        $a = $this->buscar($id) ?? throw HttpError::noEncontrado('Agencia no encontrada.');
        db_tx_begin($this->pdo);
        try {
            $this->pdo->prepare('UPDATE clients SET partner_id = NULL WHERE partner_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM partner_agencies WHERE id = ?')->execute([$id]);
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            throw $e;
        }
        db_tx_commit($this->pdo);
        if (function_exists('audit_log')) audit_log('agencia.borrar', "#$id {$a['nombre']}");
    }

    /** null = la marca de la casa. El cliente tiene que verse (antes no se miraba el alcance). */
    public function asignar(Acceso $acc, int $clientId, mixed $partnerId): void
    {
        $acc->exigir('general.editar', 'marca.editar');
        if (!$acc->veCliente($clientId)) throw HttpError::noEncontrado('Cliente no encontrado.');
        $st = $this->pdo->prepare('SELECT 1 FROM clients WHERE id = ?');
        $st->execute([$clientId]);
        if (!$st->fetchColumn()) throw HttpError::noEncontrado('Cliente no encontrado.');
        $pid = null;
        if ($partnerId !== null && $partnerId !== '' && $partnerId !== 0) {
            $pid = filter_var($partnerId, FILTER_VALIDATE_INT);
            if (!$pid || !$this->buscar($pid)) throw HttpError::validacion('Esa agencia no existe.', 'partner_id');
        }
        $this->pdo->prepare('UPDATE clients SET partner_id = ? WHERE id = ?')->execute([$pid, $clientId]);
    }

    private function buscar(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT a.*, (SELECT COUNT(*) FROM clients c WHERE c.partner_id = a.id) AS uso FROM partner_agencies a WHERE a.id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? $this->normalizar($r) : null;
    }

    /** @return array{nombre:string,color:string,logo_url:string,web:string,email:string,whatsapp:string,meeting_url:string,telefono:string} */
    public function validar(array $d): array
    {
        $nombre = ContenidoPortal::texto($d['nombre'] ?? '', 160, 'nombre');
        if ($nombre === '') throw HttpError::validacion('Pon el nombre de la agencia.', 'nombre');
        $color = ContenidoPortal::texto($d['color'] ?? '', 20, 'color');
        if ($color !== '' && !preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $color)) throw HttpError::validacion('El color tiene que ser como #7b68ee.', 'color');
        $email = ContenidoPortal::texto($d['email'] ?? '', 160, 'email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw HttpError::validacion('El email no es válido.', 'email');
        $web = ContenidoPortal::texto($d['web'] ?? '', 200, 'web');
        /* La web se acepta sin esquema («agencia.com»), pero nunca con uno que no sea http(s). */
        if ($web !== '' && preg_match('/^[a-z][a-z0-9+.-]*:/i', $web) && !preg_match('#^https?://#i', $web)) throw HttpError::validacion('La web no es válida.', 'web');
        return [
            'nombre' => $nombre,
            'color' => strtolower($color),
            'logo_url' => ContenidoPortal::url($d['logo_url'] ?? '', 'logo_url', 'El logo tiene que ser una URL https://…', 400),
            'web' => $web,
            'email' => $email,
            /* Solo dígitos, como el antiguo: es lo que espera wa.me. */
            'whatsapp' => mb_substr(preg_replace('/\D+/', '', (string)(is_scalar($d['whatsapp'] ?? '') ? $d['whatsapp'] ?? '' : '')), 0, 40),
            'meeting_url' => ContenidoPortal::url($d['meeting_url'] ?? '', 'meeting_url', 'El enlace para pedir reunión tiene que empezar por https://', 300),
            'telefono' => ContenidoPortal::texto($d['telefono'] ?? '', 40, 'telefono'),
        ];
    }

    private function normalizar(array $a): array
    {
        $s = fn($k) => trim((string)($a[$k] ?? ''));
        return [
            'id' => (int)$a['id'], 'nombre' => $s('nombre'), 'color' => $s('color'), 'logo_url' => $s('logo_url'), 'web' => $s('web'),
            'email' => $s('email'), 'whatsapp' => $s('whatsapp'), 'meeting_url' => $s('meeting_url'), 'telefono' => $s('telefono'),
            'uso' => (int)$a['uso'],
        ];
    }
}
