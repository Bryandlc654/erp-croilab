<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* La navegación por carpetas de Facturas (hubs por emisor → ingresos/gastos →
   meses → lista) y la de «Por cliente». Los totales NO cuentan borradores
   (antes sí, §17.15): un borrador aparece en la lista pero no suma. */
final class ExploradorServicio
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FacturasRepositorio $facturas,
        private readonly DocumentosServicio $documentos,
        private readonly EmisoresServicio $emisores
    ) {}

    public function hubs(Acceso $acc): array
    {
        $acc->exigir('ver.finanzas');
        $filas = $this->facturas->filas(Validar::sqlAlcance($acc, 'i.client_id'), []);
        $por = [];
        foreach ($filas as $f) {
            $e = $f['emisor'];
            $por[$e] ??= ['n' => 0, 'cobrado' => 0, 'pendiente' => 0];
            $por[$e]['n']++;
            if ($f['estado'] === 'pagada') $por[$e]['cobrado'] += $f['total'];
            elseif (in_array($f['estado'], ['enviada', 'vencida'], true)) $por[$e]['pendiente'] += $f['total'];
        }
        $items = [];
        foreach ($this->emisores->lista() as $k => $n) {
            $items[] = ['clave' => $k, 'nombre' => $n, 'baja' => false] + ($por[$k] ?? ['n' => 0, 'cobrado' => 0, 'pendiente' => 0]);
            unset($por[$k]);
        }
        foreach ($por as $k => $v) $items[] = ['clave' => (string)$k, 'nombre' => $k . ' (dado de baja)', 'baja' => true] + $v;
        return ['items' => $items];
    }

    public function tipos(Acceso $acc, string $emisor): array
    {
        $acc->exigir('ver.finanzas');
        $out = [];
        foreach (['ingreso', 'gasto'] as $t) {
            $n = 0;
            $total = 0;
            foreach ($this->documentosDe($acc, $emisor, $t, null) as $d) {
                $n++;
                if (!$d['borrador']) $total += $d['importe'];
            }
            $out[$t] = ['n' => $n, 'total' => $total];
        }
        return ['emisor' => $this->cabecera($emisor), 'ingreso' => $out['ingreso'], 'gasto' => $out['gasto']];
    }

    public function meses(Acceso $acc, string $emisor, string $tipo): array
    {
        $acc->exigir('ver.finanzas');
        $tipo = $this->tipo($tipo);
        $m = [];
        foreach ($this->documentosDe($acc, $emisor, $tipo, null) as $d) {
            $ym = substr((string)$d['fecha'], 0, 7) ?: 'sin-fecha';
            $m[$ym] ??= ['mes' => $ym, 'n' => 0, 'total' => 0, 'neto' => 0];
            $m[$ym]['n']++;
            if ($d['borrador']) continue;
            $m[$ym]['total'] += $d['importe'];
            $m[$ym]['neto'] += $d['neto'];
        }
        krsort($m);
        return ['emisor' => $this->cabecera($emisor), 'items' => array_values($m)];
    }

    /** Lista del mes: facturas emitidas (y borradores) + documentos subidos. */
    public function lista(Acceso $acc, string $emisor, string $tipo, string $mes): array
    {
        $acc->exigir('ver.finanzas');
        $tipo = $this->tipo($tipo);
        [$ini, $fin] = Validar::rangoMes(Validar::mes($mes));
        $facturas = $tipo === 'ingreso'
            ? $this->facturas->filas(' AND i.emisor = ? AND i.fecha BETWEEN ? AND ?' . Validar::sqlAlcance($acc, 'i.client_id'), [$emisor, $ini, $fin])
            : [];
        $docs = $this->documentos->filas(' AND u.emisor = ? AND u.tipo = ? AND u.fecha BETWEEN ? AND ?', [$emisor, $tipo, $ini, $fin]);
        return ['emisor' => $this->cabecera($emisor), 'facturas' => $facturas, 'documentos' => $docs];
    }

    /* ---------- Por cliente ---------- */

    public function porCliente(Acceso $acc): array
    {
        $acc->exigir('ver.finanzas');
        $filas = $this->facturas->filas(' AND i.client_id IS NOT NULL' . Validar::sqlAlcance($acc, 'i.client_id'), []);
        $por = [];
        foreach ($filas as $f) {
            $c = (int)$f['client_id'];
            $por[$c] ??= ['client_id' => $c, 'nombre' => '', 'n' => 0, 'total' => 0, 'neto' => 0, 'borradores' => 0];
            $por[$c]['n']++;
            if ($f['estado'] === 'borrador') { $por[$c]['borradores']++; continue; }
            $por[$c]['total'] += $f['total'];
            $por[$c]['neto'] += $f['base'];
        }
        if ($por) {
            $st = $this->pdo->query('SELECT id, name FROM clients WHERE id IN (' . implode(',', array_keys($por)) . ')');
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$id, $n]) $por[(int)$id]['nombre'] = (string)$n;
        }
        $items = array_values($por);
        usort($items, fn($a, $b) => strcasecmp($a['nombre'], $b['nombre']));
        return ['items' => $items];
    }

    public function cliente(Acceso $acc, int $clientId): array
    {
        $acc->exigir('ver.finanzas');
        if (!$acc->veCliente($clientId)) throw HttpError::noEncontrado('Cliente no encontrado.');
        $c = $this->facturas->cliente($clientId) ?? throw HttpError::noEncontrado('Cliente no encontrado.');
        $m = [];
        foreach ($this->facturas->filas(' AND i.client_id = ?', [$clientId]) as $f) {
            $ym = substr((string)$f['fecha'], 0, 7);
            $m[$ym] ??= ['mes' => $ym, 'n' => 0, 'total' => 0, 'neto' => 0];
            $m[$ym]['n']++;
            if ($f['estado'] === 'borrador') continue;
            $m[$ym]['total'] += $f['total'];
            $m[$ym]['neto'] += $f['base'];
        }
        krsort($m);
        return ['cliente' => ClientesFacturacion::item($c), 'meses' => array_values($m)];
    }

    /* ---------- Piezas ---------- */

    /** Documentos unificados (factura o subida) con importe y neto en céntimos. */
    private function documentosDe(Acceso $acc, string $emisor, string $tipo, ?string $mes): array
    {
        $out = [];
        if ($tipo === 'ingreso') {
            foreach ($this->facturas->filas(' AND i.emisor = ?' . Validar::sqlAlcance($acc, 'i.client_id'), [$emisor]) as $f) {
                $out[] = ['fecha' => $f['fecha'], 'importe' => $f['total'], 'neto' => $f['base'], 'borrador' => $f['estado'] === 'borrador'];
            }
        }
        foreach ($this->documentos->filas(' AND u.emisor = ? AND u.tipo = ?', [$emisor, $tipo]) as $d) {
            $out[] = ['fecha' => $d['fecha'], 'importe' => $d['importe'], 'neto' => $d['importe'], 'borrador' => false];
        }
        return $out;
    }

    private function tipo(string $t): string
    {
        if (!in_array($t, ['ingreso', 'gasto'], true)) throw HttpError::validacion('Tipo no válido.', 'tipo');
        return $t;
    }

    private function cabecera(string $emisor): array
    {
        if ($emisor === '') throw HttpError::validacion('Falta el emisor.', 'emisor');
        return ['clave' => $emisor, 'nombre' => $this->emisores->existe($emisor) ? $this->emisores->nombre($emisor) : $emisor . ' (dado de baja)', 'baja' => !$this->emisores->existe($emisor)];
    }
}
