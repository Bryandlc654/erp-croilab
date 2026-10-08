<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Proyectos internos como centros de coste (spec 02-trabajo §4.5–4.6). La caja
   (accounting.project_id) es la única fuente del balance; las facturas sin
   cobrar del proyecto se enseñan aparte como «pendiente». No sale en las
   facturas del cliente. */
final class ProyectosServicio
{
    public const PALETA = ['#2f6df6', '#12a150', '#e0a341', '#e05a4f', '#8b5cf6', '#0ea5a5', '#eb5a9a', '#f59e0b', '#14b8a6', '#a855f7'];

    public function __construct(private readonly PDO $pdo) {}

    /* ---------- Listado con rentabilidad ---------- */

    public function listar(Acceso $acc, string $anio): array
    {
        $acc->exigir('ver.proyectos');
        [$condA, $pa] = $this->condAnio($anio, 'a.fecha');
        $alc = Validar::sqlAlcance($acc, 'p.client_id');
        $st = $this->pdo->prepare("SELECT p.id, p.nombre, p.color, p.activo, p.client_id, c.name AS cliente,
                COALESCE(SUM(CASE WHEN a.tipo = 'ingreso' THEN a.importe END), 0) AS ing,
                COALESCE(SUM(CASE WHEN a.tipo = 'gasto' THEN a.importe END), 0) AS gas, COUNT(a.id) AS nmov
            FROM projects p LEFT JOIN clients c ON c.id = p.client_id
            LEFT JOIN accounting a ON a.project_id = p.id $condA
            WHERE 1=1 $alc GROUP BY p.id ORDER BY p.activo DESC, ing DESC, p.nombre");
        $st->execute($pa);
        $items = [];
        $k = ['ing' => 0, 'gas' => 0, 'ben' => 0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ing = Dinero::c($r['ing']);
            $gas = Dinero::c($r['gas']);
            $items[] = ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre'], 'color' => (string)($r['color'] ?: '#2f6df6'), 'activo' => (bool)$r['activo'],
                        'client_id' => $r['client_id'] ? (int)$r['client_id'] : null, 'cliente' => $r['cliente'] !== null ? (string)$r['cliente'] : null,
                        'ing' => $ing, 'gas' => $gas, 'ben' => $ing - $gas, 'nmov' => (int)$r['nmov']];
            $k['ing'] += $ing;
            $k['gas'] += $gas;
        }
        [$condS, $ps] = $this->condAnio($anio, 'fecha');
        $st = $this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN importe END), 0) ing, COALESCE(SUM(CASE WHEN tipo = 'gasto' THEN importe END), 0) gas, COUNT(*) n
                                   FROM accounting WHERE project_id IS NULL $condS " . Validar::sqlAlcance($acc, 'client_id'));
        $st->execute($ps);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        $k['ben'] = $k['ing'] - $k['gas'];
        return ['items' => $items, 'kpis' => $k, 'anios' => $this->anios(),
                'sin_proyecto' => ['ing' => Dinero::c($s['ing']), 'gas' => Dinero::c($s['gas']), 'nmov' => (int)$s['n']]];
    }

    /* ---------- Ficha ---------- */

    public function ficha(Acceso $acc, int $id, string $anio): array
    {
        $acc->exigir('ver.proyectos');
        $p = $this->visible($acc, $id);
        [$cond, $pa] = $this->condAnio($anio, 'a.fecha');
        $st = $this->pdo->prepare("SELECT a.id, a.fecha, a.tipo, a.concepto, a.importe, a.ambito, a.invoice_id, i.numero AS f_numero, i.estado AS f_estado,
                u.id AS u_id, u.filename AS u_file, u.orig_name AS u_orig
            FROM accounting a LEFT JOIN invoices i ON i.id = a.invoice_id LEFT JOIN invoice_uploads u ON u.id = a.upload_id
            WHERE a.project_id = ? $cond ORDER BY a.fecha DESC, a.id DESC");
        $st->execute([$id, ...$pa]);
        $movs = [];
        $ing = $gas = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $imp = Dinero::c($r['importe']);
            if ($r['tipo'] === 'ingreso') $ing += $imp; else $gas += $imp;
            $movs[] = ['id' => (int)$r['id'], 'fecha' => $r['fecha'], 'tipo' => (string)$r['tipo'], 'concepto' => (string)$r['concepto'], 'importe' => $imp,
                       'ambito' => (string)$r['ambito'],
                       'factura' => $r['invoice_id'] ? ['id' => (int)$r['invoice_id'], 'numero' => $r['f_numero'], 'estado' => (string)$r['f_estado']] : null,
                       'documento' => $r['u_id'] ? ['id' => (int)$r['u_id'], 'filename' => $r['u_file'], 'nombre' => (string)($r['u_orig'] ?: $r['u_file'])] : null];
        }
        $facturas = (new FacturasRepositorio($this->pdo))->filas(' AND i.project_id = ?', [$id]);
        $pend = 0;
        foreach ($facturas as $f) if (in_array($f['estado'], ['enviada', 'vencida'], true)) $pend += $f['total'];
        return ['proyecto' => $p, 'anios' => $this->anios(), 'kpis' => ['ing' => $ing, 'gas' => $gas, 'ben' => $ing - $gas, 'pendiente' => $pend],
                'movimientos' => $movs,
                'facturas' => array_map(fn($f) => ['id' => $f['id'], 'numero' => $f['numero'], 'cliente_nombre' => $f['cliente_nombre'], 'fecha' => $f['fecha'],
                                                    'estado' => $f['estado'], 'total' => $f['total']], $facturas)];
    }

    /* ---------- Escritura ---------- */

    public function crear(Acceso $acc, array $d): array
    {
        Validar::escribe($acc);
        $acc->exigir('ver.proyectos');
        $nombre = Validar::texto($d, 'nombre', 160);
        if ($nombre === '') throw HttpError::validacion('Ponle un nombre al proyecto.', 'nombre');
        $cli = Validar::idONull($d, 'client_id');
        if ($cli && !$acc->veCliente($cli)) throw HttpError::validacion('Cliente no encontrado.', 'client_id');
        $color = $this->color($d['color'] ?? null) ?? $this->colorSiguiente();
        $this->pdo->prepare('INSERT INTO projects (nombre, color, client_id, activo) VALUES (?, ?, ?, 1)')->execute([$nombre, $color, $cli]);
        return $this->visible($acc, (int)$this->pdo->lastInsertId());
    }

    /** Para los combobox: el proyecto con ese nombre (sin distinguir mayúsculas) o uno nuevo. */
    public function obtenerOCrear(Acceso $acc, string $nombre, ?int $clientId): int
    {
        $st = $this->pdo->prepare('SELECT id FROM projects WHERE LOWER(nombre) = LOWER(?) ORDER BY activo DESC, id LIMIT 1');
        $st->execute([$nombre]);
        $id = $st->fetchColumn();
        if ($id) return (int)$id;
        $this->pdo->prepare('INSERT INTO projects (nombre, color, client_id, activo) VALUES (?, ?, ?, 1)')->execute([mb_substr($nombre, 0, 160), $this->colorSiguiente(), $clientId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function actualizar(Acceso $acc, int $id, array $d): array
    {
        Validar::escribe($acc);
        $acc->exigir('ver.proyectos');
        $this->visible($acc, $id);
        $set = [];
        $v = [];
        if (array_key_exists('nombre', $d)) {
            $n = Validar::texto($d, 'nombre', 160);
            if ($n === '') throw HttpError::validacion('El nombre no puede quedar vacío.', 'nombre');
            $set[] = 'nombre = ?';
            $v[] = $n;
        }
        if (array_key_exists('color', $d)) {
            $c = $this->color($d['color']);
            if (!$c) throw HttpError::validacion('Color no válido.', 'color');
            $set[] = 'color = ?';
            $v[] = $c;
        }
        if (array_key_exists('activo', $d)) {
            $set[] = 'activo = ?';
            $v[] = Validar::bool($d, 'activo') ? 1 : 0;
        }
        if (array_key_exists('client_id', $d)) {
            $cli = Validar::idONull($d, 'client_id');
            if ($cli && !$acc->veCliente($cli)) throw HttpError::validacion('Cliente no encontrado.', 'client_id');
            $set[] = 'client_id = ?';
            $v[] = $cli;
        }
        if (!$set) throw HttpError::validacion('No hay nada que cambiar.');
        $this->pdo->prepare('UPDATE projects SET ' . implode(', ', $set) . ' WHERE id = ?')->execute([...$v, $id]);
        return $this->visible($acc, $id);
    }

    /** Borra el proyecto; sus movimientos y facturas quedan sin proyecto (no se borran). */
    public function borrar(Acceso $acc, int $id): void
    {
        Validar::escribe($acc);
        $acc->exigir('ver.proyectos');
        $this->visible($acc, $id);
        Tx::run($this->pdo, function () use ($id) {
            foreach (['accounting', 'invoices', 'invoice_schedules'] as $t) $this->pdo->prepare("UPDATE $t SET project_id = NULL WHERE project_id = ?")->execute([$id]);
            $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
        });
    }

    /** Apunte de caja añadido desde el proyecto (ingreso o gasto sin factura). */
    public function anadirMovimiento(Acceso $acc, int $id, array $d): array
    {
        Validar::escribe($acc, 'conta.editar');
        $p = $this->visible($acc, $id);
        $tipo = (string)($d['tipo'] ?? '');
        if (!in_array($tipo, ['ingreso', 'gasto'], true)) throw HttpError::validacion('Tipo no válido.', 'tipo');
        $concepto = Validar::texto($d, 'concepto', 250);
        if ($concepto === '') throw HttpError::validacion('Escribe un concepto.', 'concepto');
        $importe = Dinero::exigir($d['importe'] ?? null, 'importe', 'el importe', 1, 999999999);
        $fecha = Validar::fecha($d, 'fecha') ?? date('Y-m-d');
        $this->pdo->prepare("INSERT INTO accounting (fecha, tipo, concepto, categoria, importe, metodo, legal, ambito, deducible, personal, client_id, project_id, notas)
                             VALUES (?, ?, ?, ?, ?, 'transferencia', 1, 'empresa', 0, 0, ?, ?, 'Añadido desde el proyecto')")
            ->execute([$fecha, $tipo, $concepto, $tipo === 'ingreso' ? 'Cliente' : 'Gasto', $importe, $p['client_id'], $id]);
        return ['id' => (int)$this->pdo->lastInsertId()];
    }

    /** El apunte se conserva en la caja; solo deja de contar en el proyecto. */
    public function desvincularMovimiento(Acceso $acc, int $id, int $accId): void
    {
        Validar::escribe($acc, 'conta.editar');
        $this->visible($acc, $id);
        $st = $this->pdo->prepare('UPDATE accounting SET project_id = NULL WHERE id = ? AND project_id = ?');
        $st->execute([$accId, $id]);
        if (!$st->rowCount()) throw HttpError::noEncontrado('Movimiento no encontrado en este proyecto.');
    }

    public function vinculables(Acceso $acc, int $id, string $q): array
    {
        $acc->exigir('ver.proyectos', 'ver.finanzas');
        $p = $this->visible($acc, $id);
        $w = ' AND i.estado <> \'borrador\' AND (i.project_id IS NULL OR i.project_id = ?' . ($p['client_id'] ? ' OR i.client_id = ?' : '') . ')' . Validar::sqlAlcance($acc, 'i.client_id');
        $pa = [$id];
        if ($p['client_id']) $pa[] = $p['client_id'];
        if ($q !== '') {
            $w .= ' AND (i.numero LIKE ? OR i.cliente_nombre LIKE ?)';
            $like = '%' . Validar::like($q) . '%';
            array_push($pa, $like, $like);
        }
        $filas = (new FacturasRepositorio($this->pdo))->filas($w, $pa, 'i.fecha DESC, i.id DESC', 20);
        return array_map(fn($f) => ['id' => $f['id'], 'numero' => $f['numero'], 'cliente_nombre' => $f['cliente_nombre'], 'estado' => $f['estado'],
                                     'total' => $f['total'], 'vinculada' => ($f['project']['id'] ?? null) === $id], $filas);
    }

    public function vincularFactura(Acceso $acc, int $id, int $invId, bool $vincular): void
    {
        Validar::escribe($acc, 'finanzas.emitir');
        $this->visible($acc, $id);
        $st = $this->pdo->prepare('SELECT client_id, project_id FROM invoices WHERE id = ?');
        $st->execute([$invId]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f || !Validar::veCliente($acc, $f['client_id'] ? (int)$f['client_id'] : null)) throw HttpError::noEncontrado('Factura no encontrada.');
        if (!$vincular && (int)$f['project_id'] !== $id) return;
        $pid = $vincular ? $id : null;
        Tx::run($this->pdo, function () use ($pid, $invId) {
            $this->pdo->prepare('UPDATE invoices SET project_id = ? WHERE id = ?')->execute([$pid, $invId]);
            $this->pdo->prepare('UPDATE accounting SET project_id = ? WHERE invoice_id = ?')->execute([$pid, $invId]);
        });
    }

    /** Combobox: del cliente primero, activos, por actividad reciente. */
    public function buscar(Acceso $acc, string $q, ?int $clientId, int $limit = 12): array
    {
        if (!$acc->puede('ver.finanzas') && !$acc->puede('ver.proyectos') && !$acc->puede('ver.conta')) throw HttpError::permiso();
        $w = Validar::sqlAlcance($acc, 'p.client_id');
        $pa = [];
        if ($q !== '') {
            $w .= ' AND p.nombre LIKE ?';
            $pa[] = '%' . Validar::like($q) . '%';
        } else {
            $w .= ' AND p.activo = 1';
        }
        $st = $this->pdo->prepare('SELECT p.id, p.nombre, p.color, p.activo, p.client_id, COUNT(a.id) AS nmov, MAX(a.fecha) AS ult
                                   FROM projects p LEFT JOIN accounting a ON a.project_id = p.id WHERE 1=1 ' . $w . '
                                   GROUP BY p.id ORDER BY (p.client_id = ?) DESC, p.activo DESC, ult DESC, p.nombre LIMIT ' . max(1, min(50, $limit)));
        $st->execute([...$pa, $clientId ?? 0]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre'], 'color' => (string)($r['color'] ?: '#2f6df6'), 'activo' => (bool)$r['activo'],
                                     'is_client' => $clientId !== null && (int)$r['client_id'] === $clientId, 'nmov' => (int)$r['nmov']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /* ---------- Piezas ---------- */

    private function visible(Acceso $acc, int $id): array
    {
        $st = $this->pdo->prepare('SELECT p.id, p.nombre, p.color, p.activo, p.client_id, c.name AS cliente FROM projects p LEFT JOIN clients c ON c.id = p.client_id WHERE p.id = ?');
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || !Validar::veCliente($acc, $p['client_id'] ? (int)$p['client_id'] : null)) throw HttpError::noEncontrado('Proyecto no encontrado.');
        return ['id' => (int)$p['id'], 'nombre' => (string)$p['nombre'], 'color' => (string)($p['color'] ?: '#2f6df6'), 'activo' => (bool)$p['activo'],
                'client_id' => $p['client_id'] ? (int)$p['client_id'] : null, 'cliente' => $p['cliente'] !== null ? (string)$p['cliente'] : null];
    }

    /** @return array{0:string,1:array} condición de año para el JOIN/WHERE ('' = histórico). */
    private function condAnio(string $anio, string $col): array
    {
        if ($anio === 'all' || $anio === '') return ['', []];
        if (!preg_match('/^\d{4}$/', $anio)) throw HttpError::validacion('Año no válido.', 'anio');
        return [" AND $col BETWEEN ? AND ?", ["$anio-01-01", "$anio-12-31"]];
    }

    private function anios(): array
    {
        $a = array_map('intval', $this->pdo->query('SELECT DISTINCT YEAR(fecha) FROM accounting WHERE project_id IS NOT NULL AND fecha IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
        $a[] = (int)date('Y');
        $a = array_values(array_unique($a));
        rsort($a);
        return $a;
    }

    private function color(mixed $c): ?string
    {
        return is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : null;
    }

    private function colorSiguiente(): string
    {
        $n = (int)$this->pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
        return self::PALETA[$n % count(self::PALETA)];
    }
}
