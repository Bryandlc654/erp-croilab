<?php
namespace Croilab\Modulos\Equipo;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Bóveda de credenciales de los clientes (credenciales.php).

   Fallos del antiguo que se corrigen:
     · El secreto se guardaba en claro y se mandaba entero al HTML (data-v):
       ahora va cifrado (bóveda, propósito «cred_cliente») y la lista nunca lo
       lleva; se revela bajo demanda, con la contraseña confirmada hace menos
       de 30 min (zona «boveda»), y cada revelado queda en audit_log.
     · Sin alcance: ahora solo se ven los clientes que la persona puede ver.
     · Borrado físico: ahora va a la papelera.

   Para el portal del cliente (otro módulo): secretoEnClaro() descifra una
   credencial `visible_cliente`. */
class BovedaServicio
{
    public const PROPOSITO = 'cred_cliente';
    public const CATEGORIAS = ['web' => 'Web', 'correo' => 'Correo', 'hosting' => 'Hosting', 'database' => 'Base de datos', 'api' => 'API / Token',
                               'cms' => 'CMS', 'domain' => 'Dominio', 'social' => 'Redes', 'other' => 'Otro'];

    public function __construct(private readonly PDO $pdo)
    {
        require_once __DIR__ . '/../../../admin/lib/boveda.php';
    }

    /** Selector: clientes visibles con su número de credenciales. */
    public function clientes(Acceso $acc): array
    {
        $acc->exigir('ver.credenciales');
        $st = $this->pdo->query('SELECT c.id, c.name, c.iniciales, c.activo, COUNT(k.id) n FROM clients c LEFT JOIN client_credentials k ON k.client_id = c.id
                                 WHERE 1=1' . $acc->sqlClientes('c.id') . ' GROUP BY c.id, c.name, c.iniciales, c.activo ORDER BY c.activo DESC, c.name');
        return ['items' => array_map(fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['name'], 'iniciales' => $r['iniciales'] ?: null,
                                               'activo' => (int)$r['activo'] === 1, 'n' => (int)$r['n']], $st->fetchAll(PDO::FETCH_ASSOC))];
    }

    private function cliente(Acceso $acc, int $id): array
    {
        $st = $this->pdo->prepare('SELECT id, name, iniciales FROM clients WHERE id = ?');
        $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        /* Fuera de alcance = 404, como si no existiera. */
        if (!$c || !$acc->veCliente($id)) throw HttpError::noEncontrado('Cliente no encontrado.');
        return $c;
    }

    private function credencial(Acceso $acc, int $id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM client_credentials WHERE id = ?');
        $st->execute([$id]);
        $k = $st->fetch(PDO::FETCH_ASSOC);
        if (!$k || !$acc->veCliente((int)$k['client_id'])) throw HttpError::noEncontrado('Credencial no encontrada.');
        return $k;
    }

    private static function formato(array $k): array
    {
        return [
            'id' => (int)$k['id'],
            'client_id' => (int)$k['client_id'],
            'titulo' => (string)$k['titulo'],
            'categoria' => isset(self::CATEGORIAS[$k['categoria']]) ? (string)$k['categoria'] : 'other',
            'usuario' => (string)($k['usuario'] ?? ''),
            'tiene_secreto' => (string)($k['secreto'] ?? '') !== '',
            'url' => (string)($k['url'] ?? ''),
            'nota' => (string)($k['nota'] ?? ''),
            'visible_cliente' => (int)$k['visible_cliente'] === 1,
        ];
    }

    public function listar(Acceso $acc, int $clientId): array
    {
        $acc->exigir('ver.credenciales');
        $c = $this->cliente($acc, $clientId);
        $st = $this->pdo->prepare('SELECT * FROM client_credentials WHERE client_id = ? ORDER BY orden, id');
        $st->execute([$clientId]);
        return [
            'cliente' => ['id' => (int)$c['id'], 'nombre' => (string)$c['name'], 'iniciales' => $c['iniciales'] ?: null],
            'items' => array_map([self::class, 'formato'], $st->fetchAll(PDO::FETCH_ASSOC)),
            'puede_editar' => $acc->puede('general.editar'),
            'categorias' => array_map(fn($k, $v) => ['clave' => $k, 'nombre' => $v], array_keys(self::CATEGORIAS), self::CATEGORIAS),
        ];
    }

    private function validar(array $d, bool $parcial): array
    {
        $out = [];
        if (!$parcial || array_key_exists('titulo', $d)) {
            $out['titulo'] = Validar::texto($d['titulo'] ?? '', 160, 'titulo', 'El título');
            if ($out['titulo'] === '') throw HttpError::validacion('Ponle un título (ej: WordPress de la web).', 'titulo');
        }
        if (!$parcial || array_key_exists('categoria', $d)) {
            $cat = (string)($d['categoria'] ?? 'other');
            if (!isset(self::CATEGORIAS[$cat])) throw HttpError::validacion('Categoría desconocida.', 'categoria');
            $out['categoria'] = $cat;
        }
        foreach (['usuario' => [255, 'El usuario'], 'nota' => [500, 'La nota']] as $k => [$max, $et]) {
            if (!$parcial || array_key_exists($k, $d)) $out[$k] = Validar::texto($d[$k] ?? '', $max, $k, $et);
        }
        if (!$parcial || array_key_exists('url', $d)) {
            $u = Validar::texto($d['url'] ?? '', 500, 'url', 'La URL');
            if ($u !== '' && !preg_match('~^[a-z][a-z0-9+.-]*://~i', $u)) $u = 'https://' . $u;
            /* Solo http(s): el enlace «Acceder al servicio» no puede ser javascript:. */
            $out['url'] = $u === '' ? '' : Validar::url($u, 500, 'url', 'La URL de acceso');
        }
        if (!$parcial || array_key_exists('visible_cliente', $d)) $out['visible_cliente'] = Validar::bool($d['visible_cliente'] ?? false) ? 1 : 0;
        /* Secreto: vacío o ausente = no cambiar (en el alta, sin secreto). */
        if (isset($d['secreto']) && is_string($d['secreto']) && $d['secreto'] !== '') {
            if (mb_strlen($d['secreto']) > 4000) throw HttpError::validacion('La contraseña es demasiado larga.', 'secreto');
            $cifrado = boveda_cifrar($d['secreto'], self::PROPOSITO);
            if (!is_string($cifrado) || $cifrado === '') {
                /* Nunca en claro: si no se puede cifrar, no se guarda. */
                throw new HttpError(500, 'No se ha podido cifrar la contraseña. Revisa la clave de la bóveda del servidor.', 'boveda');
            }
            $out['secreto'] = $cifrado;
        }
        if (Validar::bool($d['quitar_secreto'] ?? false)) $out['secreto'] = '';
        return $out;
    }

    public function crear(Acceso $acc, int $clientId, array $d): array
    {
        $acc->exigir('ver.credenciales', 'general.editar');
        $this->cliente($acc, $clientId);
        $c = $this->validar($d, false) + ['secreto' => ''];
        $orden = (int)$this->pdo->query('SELECT COALESCE(MAX(orden), 0) + 1 FROM client_credentials WHERE client_id = ' . $clientId)->fetchColumn();
        $this->pdo->prepare('INSERT INTO client_credentials (client_id, titulo, categoria, usuario, secreto, url, nota, visible_cliente, orden) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$clientId, $c['titulo'], $c['categoria'], $c['usuario'], $c['secreto'], $c['url'], $c['nota'], $c['visible_cliente'], $orden]);
        $id = (int)$this->pdo->lastInsertId();
        if (function_exists('audit_log')) audit_log('boveda.crear', "credencial #$id del cliente #$clientId");
        return ['credencial' => self::formato($this->credencial($acc, $id))];
    }

    public function actualizar(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('ver.credenciales', 'general.editar');
        $this->credencial($acc, $id);
        $c = $this->validar($d, true);
        if ($c) {
            $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($c)));
            $this->pdo->prepare("UPDATE client_credentials SET $set WHERE id = ?")->execute([...array_values($c), $id]);
            if (function_exists('audit_log')) audit_log('boveda.editar', "credencial #$id" . (isset($c['secreto']) ? ' (contraseña cambiada)' : ''));
        }
        return ['credencial' => self::formato($this->credencial($acc, $id))];
    }

    /** A la papelera (se puede restaurar desde Ajustes › Papelera). */
    public function borrar(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.credenciales', 'general.editar');
        $k = $this->credencial($acc, $id);
        $tid = function_exists('pap_borrar') ? pap_borrar('client_credentials', $id, 'credencial', (string)$k['titulo']) : 0;
        if (!$tid) $this->pdo->prepare('DELETE FROM client_credentials WHERE id = ?')->execute([$id]);
        if (function_exists('audit_log')) audit_log('boveda.borrar', "credencial #$id del cliente #{$k['client_id']}");
        return ['papelera_id' => $tid ?: null];
    }

    /** Revela el secreto: exige contraseña reciente y queda registrado. */
    public function revelar(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.credenciales');
        $k = $this->credencial($acc, $id);
        Reautenticacion::exigir('boveda');
        $v = $this->descifrar($id, (string)($k['secreto'] ?? ''));
        if (function_exists('audit_log')) audit_log('boveda.revelar', "credencial #$id «" . mb_substr((string)$k['titulo'], 0, 80) . "» del cliente #{$k['client_id']}");
        return ['secreto' => $v];
    }

    /** Para el portal del cliente: el secreto de una credencial visible para él. */
    public function secretoEnClaro(int $id, int $clientId): ?string
    {
        $st = $this->pdo->prepare('SELECT secreto FROM client_credentials WHERE id = ? AND client_id = ? AND visible_cliente = 1');
        $st->execute([$id, $clientId]);
        $s = $st->fetchColumn();
        return $s === false ? null : $this->descifrar($id, (string)$s);
    }

    private function descifrar(int $id, string $guardado): string
    {
        if ($guardado === '') return '';
        $r = boveda_descifrar($guardado, self::PROPOSITO);
        if (!$r['ok']) throw new HttpError(500, 'Esta contraseña está guardada pero no se puede leer: falta la clave de la bóveda del servidor.', 'boveda');
        /* Lo que quedara en claro (anterior a la migración 0051) se cifra ya. */
        if ($r['migrar']) {
            $c = boveda_cifrar((string)$r['v'], self::PROPOSITO);
            if (is_string($c) && $c !== '') $this->pdo->prepare('UPDATE client_credentials SET secreto = ? WHERE id = ?')->execute([$c, $id]);
        }
        return (string)$r['v'];
    }
}
