<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Modulos\Clientes\Importes;
use PDO;

/* SQL de facturas. Los totales salen siempre de Dinero (céntimos): la base
   por factura la suma MySQL en DECIMAL con ROUND(q·p, 2) por línea (la misma
   regla) y las cuotas se calculan aquí. */
final class FacturasRepositorio
{
    public const COLS = 'i.id, i.numero, i.serie, i.tipo, i.estado, i.emisor, i.client_id, i.cliente_nombre, i.fecha, i.fecha_venc,
        i.fecha_pago, i.iva_pct, i.irpf_pct, i.efectivo, i.personal, i.project_id, i.rectifica_id, i.created_at, i.emitida_at,
        p.nombre AS project_nombre, p.color AS project_color';

    public function __construct(private readonly PDO $pdo) {}

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Filas de lista con sus importes. $where empieza por « AND …».
     * @return array<int, array>
     */
    public function filas(string $where, array $params, string $orden = 'i.fecha DESC, i.id DESC', int $limit = 0, int $offset = 0): array
    {
        $sql = 'SELECT ' . self::COLS . ', ' . Importes::sqlBase('i') . ' AS base_dec
                FROM invoices i LEFT JOIN projects p ON p.id = i.project_id WHERE 1=1 ' . $where . " ORDER BY $orden";
        if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return array_map([self::class, 'item'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function contar(string $where, array $params): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM invoices i WHERE 1=1 ' . $where);
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    public static function item(array $r): array
    {
        $t = Dinero::desdeBase(Dinero::c($r['base_dec'] ?? '0'), $r['iva_pct'], $r['irpf_pct']);
        return [
            'id' => (int)$r['id'],
            'numero' => $r['numero'] !== null && $r['numero'] !== '' ? (string)$r['numero'] : null,
            'serie' => (string)($r['serie'] ?? ''),
            'tipo' => (string)($r['tipo'] ?: 'normal'),
            'estado' => (string)($r['estado'] ?: 'borrador'),
            'emisor' => (string)$r['emisor'],
            'client_id' => $r['client_id'] !== null ? (int)$r['client_id'] : null,
            'cliente_nombre' => (string)($r['cliente_nombre'] ?? ''),
            'fecha' => $r['fecha'] ?: null,
            'fecha_venc' => $r['fecha_venc'] ?: null,
            'fecha_pago' => $r['fecha_pago'] ?: null,
            'iva_pct' => Dinero::pct($r['iva_pct']),
            'irpf_pct' => Dinero::pct($r['irpf_pct']),
            'efectivo' => (bool)$r['efectivo'],
            'personal' => (bool)$r['personal'],
            'project' => $r['project_id'] ? ['id' => (int)$r['project_id'], 'nombre' => (string)($r['project_nombre'] ?? ''), 'color' => (string)($r['project_color'] ?: '#2f6df6')] : null,
            'rectifica_id' => $r['rectifica_id'] ? (int)$r['rectifica_id'] : null,
            'base' => $t['base'], 'iva' => $t['iva'], 'irpf' => $t['irpf'], 'total' => $t['total'],
        ];
    }

    public function fila(int $id, bool $bloquear = false): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM invoices WHERE id = ?' . ($bloquear ? ' FOR UPDATE' : ''));
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<int, array{id:int, concepto:string, cantidad:string, precio:string, importe:int}> */
    public function lineas(int $id): array
    {
        $st = $this->pdo->prepare('SELECT id, concepto, cantidad, precio FROM invoice_items WHERE invoice_id = ? ORDER BY orden, id');
        $st->execute([$id]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $out[] = ['id' => (int)$l['id'], 'concepto' => (string)$l['concepto'], 'cantidad' => Dinero::decimal(Dinero::c($l['cantidad'])),
                      'precio' => Dinero::decimal(Dinero::c($l['precio'])), 'importe' => Dinero::linea($l['cantidad'], $l['precio'])];
        }
        return $out;
    }

    /** Totales en céntimos de una factura guardada. */
    public function totales(int $id): array
    {
        $f = $this->fila($id);
        if (!$f) return ['base' => 0, 'iva' => 0, 'irpf' => 0, 'total' => 0];
        return Dinero::totales($this->lineas($id), $f['iva_pct'], $f['irpf_pct']);
    }

    /** Sustituye las líneas (solo en borradores). @param array<int,array{concepto:string,cantidad:string,precio:string}> $lineas */
    public function guardarLineas(int $id, array $lineas): void
    {
        $this->pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
        $ins = $this->pdo->prepare('INSERT INTO invoice_items (invoice_id, concepto, cantidad, precio, orden) VALUES (?, ?, ?, ?, ?)');
        foreach (array_values($lineas) as $i => $l) $ins->execute([$id, $l['concepto'], $l['cantidad'], $l['precio'], $i]);
    }

    public function insertar(array $campos): int
    {
        $cols = array_keys($campos);
        $this->pdo->prepare('INSERT INTO invoices (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($campos));
        return (int)$this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $campos): void
    {
        if (!$campos) return;
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($campos)));
        $this->pdo->prepare("UPDATE invoices SET $set, updated_at = NOW() WHERE id = ?")->execute([...array_values($campos), $id]);
    }

    public function apunteDe(int $id): ?int
    {
        $st = $this->pdo->prepare('SELECT id FROM accounting WHERE invoice_id = ?');
        $st->execute([$id]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    /** Proyecto por id (o null). */
    public function proyecto(?int $id): ?array
    {
        if (!$id) return null;
        $st = $this->pdo->prepare('SELECT id, nombre, color, client_id, activo FROM projects WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        return $p ? ['id' => (int)$p['id'], 'nombre' => (string)$p['nombre'], 'color' => (string)($p['color'] ?: '#2f6df6')] : null;
    }

    /** Cliente con sus datos fiscales (o null). */
    public function cliente(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, name, fact_nombre, fact_nif, fact_dir, fact_email, fact_tel FROM clients WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
