<?php
namespace Croilab\Modulos\Crm;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Configuración del CRM: fases del embudo y sectores (solo el dueño, como en
   el antiguo), etiquetas (quien edita el CRM) y vistas guardadas de Contactos. */
final class ConfigServicio
{
    public function __construct(private readonly PDO $pdo, private readonly Historial $historial) {}

    public function catalogos(Acceso $acc): array
    {
        $acc->exigir('ver.crm');
        return Catalogos::todo($this->pdo);
    }

    /* ---------- Fases del embudo ---------- */

    public function crearFase(Acceso $acc, array $d): array
    {
        $acc->exigir('admin.total');
        $nombre = $this->nombreFase($d['nombre'] ?? null);
        $prob = $this->probabilidad($d['probabilidad'] ?? 0);
        $color = $this->color($d['color'] ?? null, '#94a3b8');
        $base = substr(Catalogos::ascii($nombre), 0, 34) ?: 'fase';
        $fases = Catalogos::fases($this->pdo);
        $slug = $base;
        for ($n = 2; isset($fases[$slug]); $n++) $slug = $base . '_' . $n;

        db_tx_begin($this->pdo);
        try {
            /* Va detrás de la última abierta; las de cierre se desplazan. */
            $ultima = 0;
            foreach ($fases as $f) if ($f['tipo'] === 'abierta') $ultima = max($ultima, $f['orden']);
            $this->pdo->prepare('UPDATE pipeline_stages SET orden = orden + 1 WHERE orden > ?')->execute([$ultima]);
            $this->pdo->prepare("INSERT INTO pipeline_stages (nombre, slug, orden, probabilidad, tipo, color) VALUES (?,?,?,?,'abierta',?)")
                ->execute([$nombre, $slug, $ultima + 1, $prob, $color]);
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            throw $e;
        }
        db_tx_commit($this->pdo);
        return array_values(Catalogos::fases($this->pdo));
    }

    public function actualizarFase(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('admin.total');
        $this->fase($id);
        $c = [];
        if (array_key_exists('nombre', $d)) $c['nombre'] = $this->nombreFase($d['nombre']);
        if (array_key_exists('probabilidad', $d)) $c['probabilidad'] = $this->probabilidad($d['probabilidad']);
        if (array_key_exists('color', $d)) $c['color'] = $this->color($d['color'], null);
        if (!$c) throw HttpError::validacion('No hay nada que cambiar.');
        $this->pdo->prepare('UPDATE pipeline_stages SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($c))) . ' WHERE id = ?')
            ->execute([...array_values($c), $id]);
        return array_values(Catalogos::fases($this->pdo));
    }

    /** Reordenar: las abiertas siempre delante de las de cierre (si no, el embudo no tiene sentido). */
    public function ordenFases(Acceso $acc, array $d): array
    {
        $acc->exigir('admin.total');
        $ids = $d['ids'] ?? null;
        $fases = Catalogos::fases($this->pdo);
        $existentes = array_column($fases, 'id');
        if (!is_array($ids) || count($ids) !== count($existentes) || array_diff($existentes, array_map('intval', $ids))) {
            throw HttpError::validacion('El orden tiene que incluir todas las fases.', 'ids');
        }
        $tipo = array_column($fases, 'tipo', 'id');
        $ids = array_map('intval', $ids);
        $abiertas = array_values(array_filter($ids, fn($i) => $tipo[$i] === 'abierta'));
        $resto = array_values(array_filter($ids, fn($i) => $tipo[$i] !== 'abierta'));
        $up = $this->pdo->prepare('UPDATE pipeline_stages SET orden = ? WHERE id = ?');
        foreach ([...$abiertas, ...$resto] as $i => $id) $up->execute([$i + 1, $id]);
        return array_values(Catalogos::fases($this->pdo));
    }

    /**
     * Quitar una fase abierta que no sea estructural: sus negocios y sus
     * contactos pasan a la primera abierta que quede.
     * @return array{fases: array, destino: string}
     */
    public function borrarFase(Acceso $acc, int $id): array
    {
        $acc->exigir('admin.total');
        $f = $this->fase($id);
        if ($f['tipo'] !== 'abierta' || in_array($f['slug'], Catalogos::FASES_ESTRUCTURALES, true)) {
            throw new HttpError(409, 'Esa fase no se puede eliminar: es una fase estructural del ERP.', 'conflicto');
        }
        $fases = Catalogos::fases($this->pdo);
        $destino = null;
        foreach ($fases as $s) if ($s['tipo'] === 'abierta' && $s['id'] !== $id) { $destino = $s; break; }
        if (!$destino) throw new HttpError(409, 'No se puede eliminar: es la única fase abierta del embudo.', 'conflicto');

        db_tx_begin($this->pdo);
        try {
            $st = $this->pdo->prepare('SELECT DISTINCT contact_id FROM deals WHERE fase = ?');
            $st->execute([$f['slug']]);
            $contactos = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $this->pdo->prepare('UPDATE deals SET fase = ?, probabilidad = ? WHERE fase = ?')->execute([$destino['slug'], $destino['probabilidad'], $f['slug']]);
            /* El antiguo dejaba a los contactos con una fase que ya no existía. */
            $this->pdo->prepare('UPDATE contacts SET fase = ? WHERE fase = ?')->execute([$destino['slug'], $f['slug']]);
            $this->pdo->prepare('DELETE FROM pipeline_stages WHERE id = ?')->execute([$id]);
            $nuevas = Catalogos::fases($this->pdo);
            foreach ($contactos as $cid) $this->historial->sincronizarFase($cid, $nuevas);
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            throw $e;
        }
        db_tx_commit($this->pdo);
        return ['fases' => array_values(Catalogos::fases($this->pdo)), 'destino' => $destino['nombre']];
    }

    private function fase(int $id): array
    {
        foreach (Catalogos::fases($this->pdo) as $f) if ($f['id'] === $id) return $f;
        throw HttpError::noEncontrado('Fase no encontrada.');
    }

    private function nombreFase(mixed $v): string
    {
        $n = is_scalar($v) ? trim((string)$v) : '';
        if ($n === '') throw HttpError::validacion('Ponle un nombre a la fase.', 'nombre');
        if (mb_strlen($n) > 80) throw HttpError::validacion('El nombre es demasiado largo.', 'nombre');
        return $n;
    }

    private function probabilidad(mixed $v): int
    {
        $p = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
        if ($p === false) throw HttpError::validacion('La probabilidad va de 0 a 100.', 'probabilidad');
        return $p;
    }

    private function color(mixed $v, ?string $def): string
    {
        if (($v === null || $v === '') && $def !== null) return $def;
        if (!is_string($v) || !preg_match('/^#[0-9a-f]{6}$/i', $v)) throw HttpError::validacion('Color no válido (#rrggbb).', 'color');
        return strtolower($v);
    }

    /* ---------- Etiquetas ---------- */

    public function crearEtiqueta(Acceso $acc, array $d): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $nombre = $this->nombreEtiqueta($d['nombre'] ?? null);
        $color = $this->color($d['color'] ?? null, '#5b8def');
        if ($this->etiquetaOcupada($nombre)) throw new HttpError(409, 'Ya hay una etiqueta con ese nombre.', 'duplicado', ['campo' => 'nombre']);
        $this->pdo->prepare('INSERT INTO crm_tags (nombre, color) VALUES (?,?)')->execute([$nombre, $color]);
        $id = (int)$this->pdo->lastInsertId();
        return ['etiqueta' => ['id' => $id, 'nombre' => $nombre, 'color' => $color], 'etiquetas' => Catalogos::etiquetas($this->pdo)];
    }

    public function actualizarEtiqueta(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $this->etiqueta($id);
        $c = [];
        if (array_key_exists('nombre', $d)) {
            $c['nombre'] = $this->nombreEtiqueta($d['nombre']);
            if ($this->etiquetaOcupada($c['nombre'], $id)) throw new HttpError(409, 'Ya hay una etiqueta con ese nombre.', 'duplicado', ['campo' => 'nombre']);
        }
        if (array_key_exists('color', $d)) $c['color'] = $this->color($d['color'], null);
        if (!$c) throw HttpError::validacion('No hay nada que cambiar.');
        $this->pdo->prepare('UPDATE crm_tags SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($c))) . ' WHERE id = ?')->execute([...array_values($c), $id]);
        return ['etiquetas' => Catalogos::etiquetas($this->pdo)];
    }

    /** Se quita de todos los contactos y negocios. */
    public function borrarEtiqueta(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.borrar');
        $this->etiqueta($id);
        db_tx_begin($this->pdo);
        try {
            foreach (['contact_tags', 'deal_tags'] as $t) $this->pdo->prepare("DELETE FROM `$t` WHERE tag_id = ?")->execute([$id]);
            $this->pdo->prepare('DELETE FROM crm_tags WHERE id = ?')->execute([$id]);
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            throw $e;
        }
        db_tx_commit($this->pdo);
        return ['etiquetas' => Catalogos::etiquetas($this->pdo)];
    }

    private function etiqueta(int $id): void
    {
        $st = $this->pdo->prepare('SELECT 1 FROM crm_tags WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetchColumn()) throw HttpError::noEncontrado('Etiqueta no encontrada.');
    }

    private function nombreEtiqueta(mixed $v): string
    {
        $n = is_scalar($v) ? trim((string)$v) : '';
        if ($n === '') throw HttpError::validacion('Ponle un nombre a la etiqueta.', 'nombre');
        if (mb_strlen($n) > 80) throw HttpError::validacion('El nombre es demasiado largo.', 'nombre');
        return $n;
    }

    private function etiquetaOcupada(string $nombre, int $excepto = 0): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM crm_tags WHERE nombre = ? AND id <> ?');
        $st->execute([$nombre, $excepto]);
        return (bool)$st->fetchColumn();
    }

    /* ---------- Sectores ---------- */

    public function guardarSectores(Acceso $acc, array $d): array
    {
        $acc->exigir('admin.total');
        $s = $d['sectores'] ?? null;
        if (!is_array($s)) throw HttpError::validacion('Faltan los sectores.', 'sectores');
        $limpio = [];
        foreach ($s as $v) {
            $v = is_string($v) ? trim($v) : '';
            if ($v === '') continue;
            if (mb_strlen($v) > 80) throw HttpError::validacion('Un sector es demasiado largo.', 'sectores');
            $limpio[$v] = true;
        }
        if (count($limpio) > 100) throw HttpError::validacion('Demasiados sectores.', 'sectores');
        $this->pdo->prepare('INSERT INTO settings (clave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
            ->execute(['crm_sectors', json_encode(array_keys($limpio), JSON_UNESCAPED_UNICODE)]);
        return ['sectores' => Catalogos::sectores($this->pdo)];
    }

    /* ---------- Vistas guardadas ---------- */

    public function vistas(Acceso $acc): array
    {
        $acc->exigir('ver.crm');
        $st = $this->pdo->prepare("SELECT id, usuario_id, nombre, filtros FROM saved_views WHERE modulo = 'crm' AND (usuario_id IS NULL OR usuario_id = ?) ORDER BY nombre");
        $st->execute([$acc->adminId]);
        return array_map(function ($r) use ($acc) {
            $f = json_decode((string)$r['filtros'], true);
            try {
                $f = Filtros::normalizar(is_array($f) ? $f : []) + array_intersect_key(is_array($f) ? $f : [], ['sort' => 1, 'dir' => 1]);
            } catch (HttpError) {
                $f = [];
            }
            $propia = $r['usuario_id'] !== null && (int)$r['usuario_id'] === $acc->adminId;
            return ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre'], 'filtros' => (object)$f, 'global' => $r['usuario_id'] === null,
                'puede_borrar' => $propia || ($r['usuario_id'] === null && $acc->puede('admin.total'))];
        }, $st->fetchAll());
    }

    public function crearVista(Acceso $acc, array $d): array
    {
        $acc->exigir('ver.crm');
        $nombre = is_scalar($d['nombre'] ?? null) ? trim((string)$d['nombre']) : '';
        if ($nombre === '') throw HttpError::validacion('Ponle un nombre a la vista.', 'nombre');
        if (mb_strlen($nombre) > 120) throw HttpError::validacion('El nombre es demasiado largo.', 'nombre');
        $filtros = is_array($d['filtros'] ?? null) ? $d['filtros'] : [];
        $f = Filtros::normalizar($filtros);
        if (in_array($filtros['sort'] ?? '', ContactosServicio::ORDENES, true)) $f['sort'] = $filtros['sort'];
        if (($filtros['dir'] ?? '') === 'asc') $f['dir'] = 'asc';
        $global = filter_var($d['global'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($global) $acc->exigir('admin.total');
        $this->pdo->prepare("INSERT INTO saved_views (usuario_id, nombre, modulo, filtros) VALUES (?, ?, 'crm', ?)")
            ->execute([$global ? null : $acc->adminId, $nombre, json_encode((object)$f, JSON_UNESCAPED_UNICODE)]);
        return $this->vistas($acc);
    }

    public function borrarVista(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.crm');
        $st = $this->pdo->prepare("SELECT usuario_id FROM saved_views WHERE id = ? AND modulo = 'crm'");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r || ($r['usuario_id'] !== null && (int)$r['usuario_id'] !== $acc->adminId)) throw HttpError::noEncontrado('Vista no encontrada.');
        if ($r['usuario_id'] === null) $acc->exigir('admin.total');
        $this->pdo->prepare('DELETE FROM saved_views WHERE id = ?')->execute([$id]);
        return $this->vistas($acc);
    }
}
