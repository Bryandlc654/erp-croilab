<?php
namespace Croilab\Modulos\Finanzas;

use PDO;

/* La caja (tabla accounting): solo lo cobrado o pagado de verdad.
   · Una factura apunta su ingreso SOLO cuando está «pagada», con fecha de caja
     = fecha de cobro. Se actualiza el mismo apunte (no se borra y reinserta:
     el id se mantiene, §17.12) y se retira si deja de estar pagada.
   · Una rectificativa cobrada (devolución) apunta un ingreso NEGATIVO, que
     resta de los ingresos sin inflar los gastos. */
final class Caja
{
    public function __construct(private readonly PDO $pdo, private readonly FacturasRepositorio $facturas) {}

    public function sincronizarFactura(int $id): ?int
    {
        $f = $this->facturas->fila($id);
        $apunte = $this->facturas->apunteDe($id);
        if (!$f || $f['estado'] !== 'pagada' || $f['numero'] === null) {
            if ($apunte) $this->pdo->prepare('DELETE FROM accounting WHERE id = ?')->execute([$apunte]);
            return null;
        }
        $t = $this->facturas->totales($id);
        $efectivo = (int)$f['efectivo'];
        $datos = [
            'fecha' => $f['fecha_pago'] ?: $f['fecha'],
            'tipo' => 'ingreso',
            'concepto' => mb_substr(($f['tipo'] === 'rectificativa' ? 'Rectificativa ' : 'Factura ') . $f['numero'] . ' · ' . $f['cliente_nombre'], 0, 250),
            'categoria' => 'Cliente',
            'importe' => Dinero::decimal($t['total']),
            'metodo' => $efectivo ? 'efectivo' : 'transferencia',
            'legal' => $efectivo ? 0 : 1,
            'ambito' => (string)$f['emisor'],
            'deducible' => 0,
            'personal' => (int)$f['personal'],
            'project_id' => $f['project_id'] ?: null,
            'client_id' => $f['client_id'] ?: null,
        ];
        if ($apunte) {
            $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($datos)));
            $this->pdo->prepare("UPDATE accounting SET $set WHERE id = ?")->execute([...array_values($datos), $apunte]);
            return $apunte;
        }
        $datos['invoice_id'] = $id;
        $datos['notas'] = '';
        $cols = array_keys($datos);
        $this->pdo->prepare('INSERT INTO accounting (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($datos));
        return (int)$this->pdo->lastInsertId();
    }

    /** Inserta o actualiza un apunte suelto. Devuelve su id. */
    public function guardarApunte(?int $id, array $datos): int
    {
        if ($id) {
            $st = $this->pdo->prepare('SELECT 1 FROM accounting WHERE id = ?');
            $st->execute([$id]);
            if ($st->fetchColumn()) {
                $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($datos)));
                $this->pdo->prepare("UPDATE accounting SET $set WHERE id = ?")->execute([...array_values($datos), $id]);
                return $id;
            }
        }
        $cols = array_keys($datos);
        $this->pdo->prepare('INSERT INTO accounting (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($datos));
        return (int)$this->pdo->lastInsertId();
    }
}
