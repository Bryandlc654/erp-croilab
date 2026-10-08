<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;

/* La hoja de la factura (lo que se imprime): UNA sola fuente de datos para la
   vista del equipo, la impresión, el PDF y el portal del cliente (§17.24).
   · Emitida: los datos del emisor salen SOLO de la copia congelada al emitir.
   · Borrador: de los ajustes actuales, y se marca como borrador.
   · El portal (paraCliente) nunca ve borradores ni facturas de otro cliente. */
final class HojaFactura
{
    public function __construct(private readonly FacturasRepositorio $repo, private readonly EmisoresServicio $emisores) {}

    public function paraEquipo(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.finanzas');
        $f = $this->repo->fila($id);
        if (!$f || !Validar::veCliente($acc, $f['client_id'] ? (int)$f['client_id'] : null)) throw HttpError::noEncontrado('Factura no encontrada.');
        return $this->datos($f);
    }

    /** Para el portal del cliente (sesión de cliente): solo las suyas y emitidas. */
    public function paraCliente(int $clientId, int $id): array
    {
        $f = $this->repo->fila($id);
        if (!$f || (int)$f['client_id'] !== $clientId || !in_array($f['estado'], ['enviada', 'pagada', 'vencida', 'anulada'], true) || $f['numero'] === null) {
            throw HttpError::noEncontrado('No encontramos esa factura.');
        }
        return $this->datos($f);
    }

    public function datos(array $f): array
    {
        $id = (int)$f['id'];
        $lineas = $this->repo->lineas($id);
        $t = Dinero::totales($lineas, $f['iva_pct'], $f['irpf_pct']);
        $borrador = $f['estado'] === 'borrador' || $f['numero'] === null;
        $snap = $f['emisor_json'] ? json_decode((string)$f['emisor_json'], true) : null;
        $em = !$borrador && is_array($snap) ? $snap : $this->emisores->datos((string)$f['emisor']);
        $emisor = [];
        foreach (['name', 'nif', 'dir', 'email', 'phone', 'iban', 'banco'] as $k) $emisor[$k] = (string)($em[$k] ?? '');
        $efectivo = (bool)$f['efectivo'];
        $rect = null;
        if ($f['rectifica_id'] && ($o = $this->repo->fila((int)$f['rectifica_id']))) {
            $rect = ['numero' => (string)$o['numero'], 'fecha' => $o['fecha'], 'motivo' => (string)$f['rect_motivo']];
        }
        $legales = [];
        if ($rect) $legales[] = 'Factura rectificativa de la factura Nº ' . $rect['numero'] . ' de fecha ' . date('d/m/Y', strtotime((string)$rect['fecha'])) . '. Motivo: ' . $rect['motivo'] . '.';
        if ($efectivo) $legales[] = 'Operación cobrada en efectivo.';
        if (Dinero::c($f['iva_pct']) === 0 && trim((string)$f['mencion_iva']) !== '') $legales[] = trim((string)$f['mencion_iva']);
        $integra = null;
        if (!$borrador && $f['hash'] && is_array($snap)) {
            $integra = hash_equals((string)$f['hash'], FacturasServicio::huella(FacturasServicio::contenidoFirmado($f, $lineas, $snap, $t, (string)$f['numero'])));
        }
        return [
            'id' => $id, 'numero' => $f['numero'], 'tipo' => (string)($f['tipo'] ?: 'normal'), 'estado' => (string)$f['estado'], 'borrador' => $borrador,
            'titulo' => $f['tipo'] === 'rectificativa' ? 'FACTURA RECTIFICATIVA' : 'FACTURA',
            'fecha' => $f['fecha'], 'fecha_venc' => $f['fecha_venc'] ?: null, 'periodo_ini' => $f['periodo_ini'] ?: null, 'periodo_fin' => $f['periodo_fin'] ?: null,
            'cliente' => ['nombre' => (string)$f['cliente_nombre'], 'nif' => (string)$f['cliente_nif'], 'tel' => (string)$f['cliente_tel'],
                          'dir' => (string)$f['cliente_dir'], 'email' => (string)$f['cliente_email']],
            'emisor' => $emisor,
            'lineas' => array_map(fn($l) => ['concepto' => $l['concepto'], 'cantidad' => $l['cantidad'], 'precio' => $l['precio'], 'importe' => $l['importe']], $lineas),
            'iva_pct' => Dinero::pct($f['iva_pct']), 'irpf_pct' => Dinero::pct($f['irpf_pct']), 'totales' => $t,
            'pago' => ['banco' => $emisor['banco'], 'titular' => $emisor['name'], 'forma' => $efectivo ? 'Efectivo' : 'Transferencia',
                       'condiciones' => (string)$f['cond_pago'], 'fecha_venc' => $f['fecha_venc'] ?: null, 'iban' => $efectivo ? '' : $emisor['iban']],
            'notas_legales' => $legales, 'notas' => (string)$f['notas'],
            'rectifica' => $rect, 'hash' => $f['hash'] ?: null, 'integra' => $integra,
        ];
    }
}
