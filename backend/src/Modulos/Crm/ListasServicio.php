<?php
namespace Croilab\Modulos\Crm;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Listas de contactos (listas.php; 02-trabajo.md §4.4). Una lista activa es un
   filtro guardado que se recalcula solo, más los contactos añadidos a mano;
   una estática es una foto fija. El antiguo las protegía con `ver.tareas`:
   ahora son del CRM y piden sus permisos. */
final class ListasServicio
{
    public const TIPOS = ['activa', 'estatica', 'manual'];
    /* Condiciones que admite una lista (las del antiguo, sin etiqueta ni fechas). */
    private const CONDICIONES = ['q', 'sector', 'origen', 'fase', 'servicio', 'prop', 'vmin', 'vmax', 'quick'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ContactosRepositorio $contactos,
        private readonly ContactosServicio $servicio
    ) {}

    /** Para el menú lateral: en su orden, con cuántos contactos tiene cada una. */
    public function listar(Acceso $acc): array
    {
        $acc->exigir('ver.crm');
        return array_map(fn($l) => $this->resumen($l) + ['n' => count($this->miembros($acc, $l))],
            $this->pdo->query('SELECT * FROM lists ORDER BY orden, id')->fetchAll());
    }

    public function ver(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.crm');
        $l = $this->fila($id);
        $miembros = $this->miembros($acc, $l);
        $forzados = $this->forzados($id);
        $filas = $this->contactos->porIds($acc, array_keys($miembros));
        usort($filas, fn($a, $b) => strcasecmp($a['nombre'], $b['nombre']));
        return [
            'lista' => $this->resumen($l) + ['n' => count($filas)],
            /* En una estática todos están en list_members; en una activa, solo los añadidos a mano. */
            'miembros' => array_map(fn($c) => $c + ['forzado' => isset($forzados[$c['id']])], $filas),
        ];
    }

    public function crear(Acceso $acc, array $d): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.crear');
        [$nombre, $desc] = $this->textos($d, true);
        $tipo = is_string($d['tipo'] ?? null) && in_array($d['tipo'], self::TIPOS, true) ? $d['tipo'] : 'activa';
        $cond = $this->condiciones(is_array($d['condiciones'] ?? null) ? $d['condiciones'] : []);
        $ids = [];
        if ($tipo === 'manual') {
            $pedidos = is_array($d['ids'] ?? null) ? array_map(fn($v) => is_scalar($v) ? (int)$v : 0, $d['ids']) : [];
            $ids = array_column($this->contactos->porIds($acc, array_slice($pedidos, 0, 5000)), 'id');
            if (!$ids) throw HttpError::validacion('Elige al menos un contacto para la lista.', 'ids');
        } elseif ($tipo === 'estatica') {
            $ids = $this->contactos->ids($acc, $cond, 20000);
        }

        db_tx_begin($this->pdo);
        try {
            $orden = (int)$this->pdo->query('SELECT COALESCE(MAX(orden),0)+1 FROM lists')->fetchColumn();
            $this->pdo->prepare('INSERT INTO lists (nombre, descripcion, tipo, condiciones, fecha_congelado, orden) VALUES (?,?,?,?,?,?)')
                ->execute([$nombre, $desc, $tipo === 'activa' ? 'activa' : 'estatica', $tipo === 'manual' ? null : json_encode((object)$cond, JSON_UNESCAPED_UNICODE),
                           $tipo === 'activa' ? null : date('Y-m-d H:i:s'), $orden]);
            $id = (int)$this->pdo->lastInsertId();
            $this->meter($id, $ids);
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            throw $e;
        }
        db_tx_commit($this->pdo);
        return $this->ver($acc, $id);
    }

    public function actualizar(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $this->fila($id);
        $c = [];
        if (array_key_exists('nombre', $d) || array_key_exists('descripcion', $d)) {
            [$nombre, $desc] = $this->textos($d, array_key_exists('nombre', $d));
            if (array_key_exists('nombre', $d)) $c['nombre'] = $nombre;
            if (array_key_exists('descripcion', $d)) $c['descripcion'] = $desc;
        }
        if (!$c) throw HttpError::validacion('No hay nada que cambiar.');
        $this->pdo->prepare('UPDATE lists SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($c))) . ' WHERE id = ?')->execute([...array_values($c), $id]);
        return $this->ver($acc, $id);
    }

    /** Sin papelera (como en el antiguo): la lista no tiene datos propios, solo agrupa contactos. */
    public function borrar(Acceso $acc, int $id): void
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.borrar');
        $this->fila($id);
        db_tx_begin($this->pdo);
        try {
            $this->pdo->prepare('DELETE FROM list_members WHERE list_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM lists WHERE id = ?')->execute([$id]);
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            throw $e;
        }
        db_tx_commit($this->pdo);
    }

    /** Activa → estática con los contactos que tiene ahora (todos, no solo los que ve quien congela). */
    public function congelar(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $l = $this->fila($id);
        if ($l['tipo'] !== 'activa') throw new HttpError(409, 'Esta lista ya está congelada.', 'conflicto');
        $todos = new Acceso($this->pdo, $acc->adminId, ['alcance.todos']);
        db_tx_begin($this->pdo);
        try {
            $this->meter($id, array_keys($this->miembros($todos, $l)));
            $this->pdo->prepare("UPDATE lists SET tipo = 'estatica', fecha_congelado = NOW() WHERE id = ?")->execute([$id]);
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            throw $e;
        }
        db_tx_commit($this->pdo);
        return $this->ver($acc, $id);
    }

    public function miembro(Acceso $acc, int $id, int $contactId, bool $on): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $this->fila($id);
        $this->servicio->visible($acc, $contactId);
        if ($on) $this->meter($id, [$contactId]);
        else $this->pdo->prepare('DELETE FROM list_members WHERE list_id = ? AND contact_id = ?')->execute([$id, $contactId]);
        return $this->ver($acc, $id);
    }

    public function orden(Acceso $acc, array $d): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $ids = $d['ids'] ?? null;
        if (!is_array($ids)) throw HttpError::validacion('Falta el orden.', 'ids');
        $up = $this->pdo->prepare('UPDATE lists SET orden = ? WHERE id = ?');
        $n = 0;
        foreach ($ids as $id) if (is_int($id) || (is_string($id) && ctype_digit($id))) $up->execute([++$n, (int)$id]);
        return $this->listar($acc);
    }

    public function exportar(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.crm');
        $v = $this->ver($acc, $id);
        $nombre = substr(Catalogos::ascii($v['lista']['nombre'], '-'), 0, 60) ?: 'lista';
        return ['nombre' => "lista-$nombre.csv", 'csv' => $this->servicio->csv($v['miembros'], $acc->puede('ver.importes'))];
    }

    /* ---------- Ayudas ---------- */

    /** Ids de los miembros que ve esta persona (clave = id). */
    private function miembros(Acceso $acc, array $l): array
    {
        $ids = [];
        if ($l['tipo'] === 'activa') {
            $cond = $this->condicionesGuardadas($l);
            foreach ($this->contactos->ids($acc, $cond, 20000) as $c) $ids[$c] = true;
        }
        $st = $this->pdo->prepare('SELECT m.contact_id FROM list_members m JOIN contacts c ON c.id = m.contact_id WHERE m.list_id = ?' . Alcance::sql($acc));
        $st->execute([(int)$l['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $ids[(int)$c] = true;
        return $ids;
    }

    private function forzados(int $id): array
    {
        $st = $this->pdo->prepare('SELECT contact_id FROM list_members WHERE list_id = ?');
        $st->execute([$id]);
        return array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    private function meter(int $id, array $ids): void
    {
        $st = $this->pdo->prepare('INSERT IGNORE INTO list_members (list_id, contact_id) VALUES (?,?)');
        foreach ($ids as $c) $st->execute([$id, (int)$c]);
    }

    private function fila(int $id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM lists WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: throw HttpError::noEncontrado('Lista no encontrada.');
    }

    private function resumen(array $l): array
    {
        return ['id' => (int)$l['id'], 'nombre' => (string)$l['nombre'], 'descripcion' => (string)($l['descripcion'] ?? ''),
            'tipo' => $l['tipo'] === 'activa' ? 'activa' : 'estatica', 'condiciones' => (object)$this->condicionesGuardadas($l),
            'fecha_creacion' => (string)$l['fecha_creacion'], 'fecha_congelado' => $l['fecha_congelado'] ?: null];
    }

    private function condicionesGuardadas(array $l): array
    {
        $c = json_decode((string)($l['condiciones'] ?? ''), true);
        if (!is_array($c)) return [];
        try {
            return $this->condiciones($c);
        } catch (HttpError) {
            return [];
        }
    }

    private function condiciones(array $c): array
    {
        $c = array_intersect_key($c, array_flip(self::CONDICIONES));
        $f = Filtros::normalizar($c);
        if (isset($f['quick']) && !in_array($f['quick'], ['sin_contactar', 'act30'], true)) unset($f['quick']);
        return $f;
    }

    private function textos(array $d, bool $conNombre): array
    {
        $nombre = is_scalar($d['nombre'] ?? null) ? trim((string)$d['nombre']) : '';
        if ($conNombre && $nombre === '') throw HttpError::validacion('Ponle un nombre a la lista.', 'nombre');
        if (mb_strlen($nombre) > 160) throw HttpError::validacion('El nombre es demasiado largo.', 'nombre');
        $desc = is_scalar($d['descripcion'] ?? null) ? trim((string)$d['descripcion']) : '';
        if (mb_strlen($desc) > 255) throw HttpError::validacion('La descripción es demasiado larga.', 'descripcion');
        return [$nombre, $desc === '' ? null : $desc];
    }
}
