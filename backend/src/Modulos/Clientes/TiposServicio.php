<?php
namespace Croilab\Modulos\Clientes;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Tipos de cliente (types.php, type-edit.php, type-delete.php): cada tipo
   decide qué secciones ve el cliente en su portal. Inicio se ve siempre. */
class TiposServicio
{
    public const SECCIONES = ['metricas', 'progreso', 'informes', 'como', 'accesos', 'plan'];

    public function __construct(private readonly PDO $pdo) {}

    /** Los tipos con cuántos clientes tiene cada uno. Los ve quien ve ajustes o clientes (el formulario los necesita). */
    public function listar(Acceso $acc): array
    {
        $this->exigirVer($acc);
        $sql = 'SELECT t.id, t.nombre, t.secciones_json, COUNT(c.id) AS uso FROM client_types t LEFT JOIN clients c ON c.tipo_id = t.id
                GROUP BY t.id, t.nombre, t.secciones_json ORDER BY t.nombre, t.id';
        return array_map([$this, 'normalizar'], $this->pdo->query($sql)->fetchAll());
    }

    public function ver(Acceso $acc, int $id): array
    {
        $this->exigirVer($acc);
        return $this->buscar($id) ?? throw HttpError::noEncontrado('Tipo no encontrado.');
    }

    /**
     * Alta. Con `rapido` (botón «+ Nuevo tipo» del formulario del cliente) un
     * nombre que ya existe devuelve el existente con dup=true en vez de fallar.
     * @return array{0: array, 1: bool} [tipo, ya existía]
     */
    public function crear(Acceso $acc, array $d): array
    {
        $acc->exigir('general.editar', 'tipos.editar');
        $nombre = $this->nombre($d['nombre'] ?? '');
        if (!empty($d['rapido'])) {
            $st = $this->pdo->prepare('SELECT id FROM client_types WHERE nombre = ? LIMIT 1');
            $st->execute([$nombre]);
            if ($id = (int)$st->fetchColumn()) return [$this->buscar($id), true];
        }
        $secciones = array_key_exists('secciones', $d) ? $this->secciones($d['secciones']) : array_fill_keys(self::SECCIONES, 1);
        $this->pdo->prepare('INSERT INTO client_types (nombre, secciones_json) VALUES (?, ?)')->execute([$nombre, json_encode($secciones)]);
        $id = (int)$this->pdo->lastInsertId();
        if (function_exists('audit_log')) audit_log('tipo.crear', "#$id $nombre");
        return [$this->buscar($id), false];
    }

    public function actualizar(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('general.editar', 'tipos.editar');
        $antes = $this->buscar($id) ?? throw HttpError::noEncontrado('Tipo no encontrado.');
        $nombre = array_key_exists('nombre', $d) ? $this->nombre($d['nombre']) : $antes['nombre'];
        $secciones = array_key_exists('secciones', $d) ? $this->secciones($d['secciones']) : array_map('intval', $antes['secciones']);
        $this->pdo->prepare('UPDATE client_types SET nombre = ?, secciones_json = ? WHERE id = ?')->execute([$nombre, json_encode($secciones), $id]);
        if (function_exists('audit_log')) audit_log('tipo.editar', "#$id $nombre");
        return $this->buscar($id);
    }

    /** Los clientes del tipo se quedan sin tipo. Sin papelera, como antes. Devuelve cuántos clientes se han quedado sin tipo. */
    public function borrar(Acceso $acc, int $id): int
    {
        $acc->exigir('general.editar', 'tipos.editar');
        $t = $this->buscar($id) ?? throw HttpError::noEncontrado('Tipo no encontrado.');
        db_tx_begin($this->pdo);
        try {
            $st = $this->pdo->prepare('UPDATE clients SET tipo_id = NULL WHERE tipo_id = ?');
            $st->execute([$id]);
            $n = $st->rowCount();
            $this->pdo->prepare('DELETE FROM client_types WHERE id = ?')->execute([$id]);
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            throw $e;
        }
        db_tx_commit($this->pdo);
        if (function_exists('audit_log')) audit_log('tipo.borrar', "#$id {$t['nombre']} ($n clientes sin tipo)");
        return $n;
    }

    private function buscar(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT t.id, t.nombre, t.secciones_json, (SELECT COUNT(*) FROM clients c WHERE c.tipo_id = t.id) AS uso FROM client_types t WHERE t.id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? $this->normalizar($r) : null;
    }

    private function exigirVer(Acceso $acc): void
    {
        if (!$acc->puede('ver.ajustes') && !$acc->puede('ver.clientes')) throw HttpError::permiso();
    }

    private function nombre(mixed $v): string
    {
        $n = ContenidoPortal::texto($v, 120, 'nombre');
        if ($n === '') throw HttpError::validacion('Pon un nombre al tipo.', 'nombre');
        return $n;
    }

    /** {metricas:bool,…} → {metricas:0|1,…}; las que falten valen 0. */
    private function secciones(mixed $v): array
    {
        if (!is_array($v) || ($v !== [] && array_is_list($v))) throw HttpError::validacion('Secciones no válidas.', 'secciones');
        $out = [];
        foreach (self::SECCIONES as $k) $out[$k] = filter_var($v[$k] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        return $out;
    }

    private function normalizar(array $r): array
    {
        $s = json_decode((string)$r['secciones_json'], true);
        $sec = [];
        foreach (self::SECCIONES as $k) $sec[$k] = is_array($s) && !empty($s[$k]);
        return ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre'], 'secciones' => $sec, 'uso' => (int)$r['uso']];
    }
}
