<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Resumen mensual (criterio de devengo: lo FACTURADO en el mes, cobrado o no).
   Corrige al antiguo (§17.15): los borradores no cuentan como facturado y la
   cifra se llama «Facturado», no «Ha entrado / Cobrado en cuenta». */
final class ResumenServicio
{
    public function __construct(private readonly PDO $pdo, private readonly FacturasRepositorio $facturas, private readonly EmisoresServicio $emisores) {}

    public function mensual(Acceso $acc, string $ambito, string $ym): array
    {
        $acc->exigir('ver.finanzas');
        Validar::mes($ym);
        if ($ambito !== 'empresa' && !$this->emisores->existe($ambito)) throw HttpError::validacion('Ámbito no válido.', 'ambito');
        $mes = $this->calculo($acc, $ambito, $ym, true);
        $ant = $this->calculo($acc, $ambito, Validar::mesSiguiente($ym, -1), false);
        $delta = [
            'facturado' => $mes['facturado'] > 0 && $ant['facturado'] > 0 ? (int)round(($mes['facturado'] - $ant['facturado']) / $ant['facturado'] * 100) : null,
            'neto' => $mes['neto'] !== 0 && $ant['neto'] !== 0 ? (int)round(($mes['neto'] - $ant['neto']) / abs($ant['neto']) * 100) : null,
        ];
        $tend = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = Validar::mesSiguiente($ym, -$i);
            $tend[] = ['mes' => $m, 'base' => $this->calculo($acc, $ambito, $m, false)['base']];
        }
        $facturas = $mes['facturas'];
        unset($mes['facturas'], $ant['facturas']);
        $ambitos = [['clave' => 'empresa', 'nombre' => 'Hub']];
        foreach ($this->emisores->lista() as $k => $n) $ambitos[] = ['clave' => $k, 'nombre' => $n];
        return ['ambito' => $ambito, 'mes' => $ym, 'actual' => $mes, 'anterior' => $ant, 'delta' => $delta, 'tendencia' => $tend, 'facturas' => $facturas, 'ambitos' => $ambitos];
    }

    private function calculo(Acceso $acc, string $ambito, string $ym, bool $conLista): array
    {
        [$ini, $fin] = Validar::rangoMes($ym);
        $w = " AND i.estado <> 'borrador' AND i.fecha BETWEEN ? AND ?" . Validar::sqlAlcance($acc, 'i.client_id');
        $p = [$ini, $fin];
        if ($ambito === 'empresa') $w .= ' AND i.personal = 0';
        else { $w .= ' AND i.emisor = ?'; $p[] = $ambito; }
        $filas = $this->facturas->filas($w, $p);
        $r = ['base' => 0, 'iva' => 0, 'irpf' => 0, 'n' => 0];
        foreach ($filas as $f) {
            $r['base'] += $f['base'];
            $r['iva'] += $f['iva'];
            $r['irpf'] += $f['irpf'];
            $r['n']++;
        }
        $wg = " AND a.tipo = 'gasto' AND a.fecha BETWEEN ? AND ?" . Validar::sqlAlcance($acc, 'a.client_id');
        $pg = [$ini, $fin];
        if ($ambito === 'empresa') $wg .= ' AND a.personal = 0';
        else { $wg .= ' AND a.ambito = ?'; $pg[] = $ambito; }
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(a.importe), 0) FROM accounting a WHERE 1=1' . $wg);
        $st->execute($pg);
        $r['gastos'] = Dinero::c($st->fetchColumn());
        $r['facturado'] = $r['base'] + $r['iva'] - $r['irpf'];
        $r['impuestos'] = $r['iva'] + $r['irpf'];
        $r['neto'] = $r['base'] - $r['gastos'] - $r['irpf'];
        if ($conLista) $r['facturas'] = $filas;
        return $r;
    }
}
