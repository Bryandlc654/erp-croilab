<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Facturas emitidas: borradores, emisión con número, cobros, rectificativas y
   anulaciones. Las reglas legales (§17 de la spec):
   · Un borrador no tiene número y se puede editar y borrar (a la papelera).
   · Emitir le da número (Numeracion) y congela los datos del emisor; desde ahí
     la factura NO se edita ni se borra: se rectifica (factura rectificativa en
     serie «R») o se anula (rectificativa por el total + estado «anulada»).
   · Solo cambian después de emitida: el estado de cobro, la fecha de cobro y el
     proyecto interno (que no sale en la factura). */
final class FacturasServicio
{
    public const ESTADOS = ['borrador', 'enviada', 'pagada', 'vencida', 'anulada'];
    public const COBRABLES = ['enviada', 'pagada', 'vencida'];
    private const MAX_LINEAS = 200;

    public function __construct(
        private readonly PDO $pdo,
        private readonly FacturasRepositorio $repo,
        private readonly EmisoresServicio $emisores,
        private readonly Numeracion $numeracion,
        private readonly Caja $caja,
        private readonly ProyectosServicio $proyectos,
        private readonly Ajustes $aj
    ) {}

    /* ===================== Lectura ===================== */

    /** Filtros: emisor, client_id, estado (lista), mes, desde, hasta, q, project_id, tipo, personal. */
    public function listar(Acceso $acc, array $f, int $limit = 100, int $offset = 0): array
    {
        $acc->exigir('ver.finanzas');
        [$where, $params] = $this->where($acc, $f);
        $todas = $this->repo->filas($where, $params);
        $tot = ['n' => count($todas), 'base' => 0, 'total' => 0, 'cobrado' => 0, 'pendiente' => 0];
        foreach ($todas as $i) {
            if ($i['estado'] === 'borrador') continue;
            $tot['base'] += $i['base'];
            $tot['total'] += $i['total'];
            if ($i['estado'] === 'pagada') $tot['cobrado'] += $i['total'];
            elseif (in_array($i['estado'], ['enviada', 'vencida'], true)) $tot['pendiente'] += $i['total'];
        }
        return ['items' => array_slice($todas, $offset, $limit), 'total' => count($todas), 'totales' => $tot, 'limit' => $limit, 'offset' => $offset];
    }

    /** @return array{0:string, 1:array} */
    public function where(Acceso $acc, array $f): array
    {
        $w = Validar::sqlAlcance($acc, 'i.client_id');
        $p = [];
        if (($f['emisor'] ?? '') !== '') { $w .= ' AND i.emisor = ?'; $p[] = (string)$f['emisor']; }
        if (!empty($f['client_id'])) { $w .= ' AND i.client_id = ?'; $p[] = (int)$f['client_id']; }
        if (!empty($f['project_id'])) { $w .= ' AND i.project_id = ?'; $p[] = (int)$f['project_id']; }
        if (($f['tipo'] ?? '') !== '') {
            if (!in_array($f['tipo'], ['normal', 'rectificativa'], true)) throw HttpError::validacion('Tipo desconocido.', 'tipo');
            $w .= ' AND i.tipo = ?';
            $p[] = $f['tipo'];
        }
        if (($f['estado'] ?? '') !== '') {
            $est = array_values(array_filter(explode(',', (string)$f['estado'])));
            foreach ($est as $e) if (!in_array($e, self::ESTADOS, true)) throw HttpError::validacion('Estado desconocido.', 'estado');
            $w .= ' AND i.estado IN (' . implode(',', array_fill(0, count($est), '?')) . ')';
            array_push($p, ...$est);
        }
        if (($f['mes'] ?? '') !== '') {
            [$ini, $fin] = Validar::rangoMes(Validar::mes((string)$f['mes']));
            $w .= ' AND i.fecha BETWEEN ? AND ?';
            array_push($p, $ini, $fin);
        }
        foreach (['desde' => '>=', 'hasta' => '<='] as $k => $op) {
            if (($f[$k] ?? '') === '') continue;
            if (!Validar::esFecha((string)$f[$k])) throw HttpError::validacion('Fecha no válida (AAAA-MM-DD).', $k);
            $w .= " AND i.fecha $op ?";
            $p[] = (string)$f[$k];
        }
        if (isset($f['personal']) && $f['personal'] !== '') { $w .= ' AND i.personal = ?'; $p[] = (int)(bool)$f['personal']; }
        if (($f['q'] ?? '') !== '') {
            $q = '%' . Validar::like((string)$f['q']) . '%';
            $w .= ' AND (i.numero LIKE ? OR i.cliente_nombre LIKE ?)';
            array_push($p, $q, $q);
        }
        return [$w, $p];
    }

    public function detalle(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.finanzas');
        $f = $this->visible($acc, $id);
        $lineas = $this->repo->lineas($id);
        $t = Dinero::totales($lineas, $f['iva_pct'], $f['irpf_pct']);
        $rect = null;
        if ($f['rectifica_id']) {
            $o = $this->repo->fila((int)$f['rectifica_id']);
            if ($o) $rect = ['id' => (int)$o['id'], 'numero' => $o['numero'], 'fecha' => $o['fecha']];
        }
        $hijas = [];
        foreach ($this->repo->filas(' AND i.rectifica_id = ?', [$id], 'i.id') as $h) {
            $hijas[] = ['id' => $h['id'], 'numero' => $h['numero'], 'estado' => $h['estado'], 'fecha' => $h['fecha'], 'total' => $h['total']];
        }
        $snap = $f['emisor_json'] ? json_decode((string)$f['emisor_json'], true) : null;
        return [
            'id' => $id,
            'numero' => $f['numero'],
            'serie' => (string)$f['serie'],
            'tipo' => (string)($f['tipo'] ?: 'normal'),
            'estado' => (string)($f['estado'] ?: 'borrador'),
            'editable' => $f['estado'] === 'borrador',
            'emisor' => (string)$f['emisor'],
            'emisor_nombre' => $this->emisores->nombre((string)$f['emisor']),
            'emisor_snapshot' => is_array($snap) ? $snap : null,
            'client_id' => $f['client_id'] ? (int)$f['client_id'] : null,
            'cliente' => ['nombre' => (string)$f['cliente_nombre'], 'nif' => (string)$f['cliente_nif'], 'dir' => (string)$f['cliente_dir'],
                          'email' => (string)$f['cliente_email'], 'tel' => (string)$f['cliente_tel']],
            'fecha' => $f['fecha'], 'fecha_venc' => $f['fecha_venc'] ?: null, 'fecha_pago' => $f['fecha_pago'] ?: null,
            'periodo_ini' => $f['periodo_ini'] ?: null, 'periodo_fin' => $f['periodo_fin'] ?: null,
            'cond_pago' => (string)$f['cond_pago'],
            'iva_pct' => Dinero::pct($f['iva_pct']), 'irpf_pct' => Dinero::pct($f['irpf_pct']),
            'efectivo' => (bool)$f['efectivo'], 'personal' => (bool)$f['personal'],
            'notas' => (string)$f['notas'], 'mencion_iva' => (string)$f['mencion_iva'],
            'project' => $this->repo->proyecto($f['project_id'] ? (int)$f['project_id'] : null),
            'lineas' => $lineas,
            'totales' => $t,
            'rectifica' => $rect, 'rect_motivo' => (string)$f['rect_motivo'], 'rectificativas' => $hijas,
            'emitida_at' => $f['emitida_at'], 'anulada_at' => $f['anulada_at'], 'anulada_motivo' => (string)$f['anulada_motivo'],
            'apunte_id' => $this->repo->apunteDe($id),
            'schedule_id' => $f['schedule_id'] ? (int)$f['schedule_id'] : null,
            'deal_id' => $f['deal_id'] ? (int)$f['deal_id'] : null,
            'hash' => $f['hash'],
            'created_at' => $f['created_at'],
        ];
    }

    /** Número que tendría la siguiente factura (sin reservarlo). */
    public function siguienteNumero(Acceso $acc, string $emisor, string $serie, string $fecha, string $tipo): string
    {
        $acc->exigir('ver.finanzas');
        if (!$this->emisores->existe($emisor)) throw HttpError::validacion('Ese emisor no existe.', 'emisor');
        $fecha = Validar::esFecha($fecha) ? $fecha : date('Y-m-d');
        return $this->numeracion->prevista($emisor, Numeracion::validarSerie($serie), $fecha, $tipo === 'rectificativa' ? 'rectificativa' : 'normal');
    }

    /* ===================== Borradores ===================== */

    public function crear(Acceso $acc, array $d): array
    {
        Validar::escribe($acc, 'finanzas.emitir');
        $emisor = (string)($d['emisor'] ?? '');
        if ($emisor === '') $emisor = $this->emisores->porDefecto();
        if (!$this->emisores->existe($emisor)) throw HttpError::validacion('Ese emisor no existe.', 'emisor');
        $def = $this->emisores->datos($emisor);
        $base = ['emisor' => $emisor, 'iva_pct' => $def['iva'], 'irpf_pct' => $def['irpf'], 'cond_pago' => $def['venc'], 'fecha' => date('Y-m-d')];
        $campos = $this->validar($acc, $d + $base, null);
        $lineas = $campos['__lineas'] ?? [];
        unset($campos['__lineas']);
        $campos['estado'] = 'borrador';
        $campos['tipo'] = 'normal';
        $dealId = Validar::idONull($d, 'deal_id');
        if ($dealId) {
            $ya = $this->facturaDeNegocio($dealId);
            if ($ya) throw new HttpError(409, 'Este negocio ya tenía la factura ' . ($ya['numero'] ?? 'en borrador') . '.', 'conflicto', ['factura_id' => $ya['id']]);
            $campos['deal_id'] = $dealId;
        }
        $id = Tx::run($this->pdo, function () use ($campos, $lineas, $acc) {
            $id = $this->repo->insertar($campos);
            $this->repo->guardarLineas($id, $lineas);
            $this->copiarFiscalesAlCliente($acc, $campos);
            return $id;
        });
        return $this->detalle($acc, $id);
    }

    public function actualizar(Acceso $acc, int $id, array $d): array
    {
        Validar::escribe($acc, 'finanzas.emitir');
        $f = $this->visible($acc, $id);
        if ($f['estado'] !== 'borrador') throw new HttpError(409, 'Una factura emitida no se puede modificar: haz una rectificativa.', 'emitida');
        $campos = $this->validar($acc, $d, $f);
        $lineas = $campos['__lineas'] ?? null;
        unset($campos['__lineas']);
        /* Un borrador antiguo que ya tenía número no puede cambiar de emisor ni de serie. */
        if ($f['numero'] !== null) unset($campos['emisor'], $campos['serie']);
        Tx::run($this->pdo, function () use ($id, $campos, $lineas, $acc) {
            $this->repo->actualizar($id, $campos);
            if ($lineas !== null) $this->repo->guardarLineas($id, $lineas);
            $this->copiarFiscalesAlCliente($acc, $campos);
        });
        return $this->detalle($acc, $id);
    }

    /** Solo borradores sin número, a la papelera con sus líneas. */
    public function borrar(Acceso $acc, int $id): int
    {
        Validar::escribe($acc, 'finanzas.borrar');
        $f = $this->visible($acc, $id);
        if ($f['estado'] !== 'borrador' || $f['numero'] !== null) {
            throw new HttpError(409, 'Una factura emitida no se borra: anúlala o haz una rectificativa.', 'emitida');
        }
        $titulo = 'Borrador · ' . ($f['cliente_nombre'] ?: 'sin cliente');
        $tid = pap_borrar('invoices', $id, 'factura', $titulo, [['tabla' => 'invoice_items', 'fk' => 'invoice_id']]);
        if (!$tid) throw new HttpError(500, 'No se ha podido mover la factura a la papelera.', 'papelera');
        return $tid;
    }

    /* ===================== Emisión ===================== */

    public function emitir(Acceso $acc, int $id): array
    {
        Validar::escribe($acc, 'finanzas.emitir');
        $this->visible($acc, $id);
        Tx::run($this->pdo, fn() => $this->emitirEnTx($id, $acc->adminId));
        return $this->detalle($acc, $id);
    }

    /** Emite dentro de una transacción ya abierta (también lo usan las programaciones). */
    public function emitirEnTx(int $id, ?int $adminId): void
    {
        $f = $this->repo->fila($id, true);
        if (!$f) throw HttpError::noEncontrado('Factura no encontrada.');
        if ($f['estado'] !== 'borrador') throw new HttpError(409, 'Esta factura ya está emitida.', 'emitida');
        $lineas = $this->repo->lineas($id);
        if (!$lineas) throw HttpError::validacion('Añade al menos una línea antes de emitir.', 'lineas');
        if (trim((string)$f['cliente_nombre']) === '') throw HttpError::validacion('Falta el nombre del cliente.', 'cliente_nombre');
        $t = Dinero::totales($lineas, $f['iva_pct'], $f['irpf_pct']);
        if ($t['base'] === 0) throw HttpError::validacion('La factura no puede salir a 0 €.', 'lineas');
        if ($f['tipo'] !== 'rectificativa' && $t['base'] < 0) throw HttpError::validacion('Una factura no puede ser negativa: para devolver dinero haz una rectificativa.', 'lineas');
        $emisor = (string)$f['emisor'];
        if (!$this->emisores->existe($emisor)) throw HttpError::validacion('Ese emisor ya no existe.', 'emisor');
        $snap = $this->emisores->datos($emisor);
        if (trim($snap['nif']) === '') {
            throw HttpError::validacion('Faltan los datos fiscales de ' . $this->emisores->nombre($emisor) . ' (NIF). Rellénalos en Ajustes › Facturación.', 'emisor');
        }
        $fecha = (string)$f['fecha'];
        if ($f['numero'] === null) {
            $n = $this->numeracion->asignar($emisor, (string)$f['serie'], $fecha, (string)$f['tipo']);
        } else {
            $n = ['numero' => (string)$f['numero'], 'serie' => (string)$f['serie'], 'correlativo' => $f['correlativo'] !== null ? (int)$f['correlativo'] : null];
        }
        $emJson = json_encode($snap, JSON_UNESCAPED_UNICODE);
        $campos = [
            'numero' => $n['numero'], 'serie' => $n['serie'], 'correlativo' => $n['correlativo'],
            'estado' => 'enviada', 'emisor_json' => $emJson, 'emitida_at' => date('Y-m-d H:i:s'), 'emitida_por' => $adminId ?: null,
        ];
        $campos['hash'] = self::huella(self::contenidoFirmado($f, $lineas, $snap, $t, $n['numero']));
        $this->repo->actualizar($id, $campos);
    }

    /** Lo que se firma con la huella: lo que sale impreso en la factura. */
    public static function contenidoFirmado(array $f, array $lineas, array $snap, array $t, string $numero): array
    {
        return ['numero' => $numero, 'fecha' => (string)$f['fecha'], 'emisor' => $snap, 'tipo' => (string)$f['tipo'],
                'cliente' => [(string)$f['cliente_nombre'], (string)$f['cliente_nif'], (string)$f['cliente_dir']],
                'lineas' => array_map(fn($l) => [$l['concepto'], $l['cantidad'], $l['precio']], $lineas),
                'iva' => Dinero::pct($f['iva_pct']), 'irpf' => Dinero::pct($f['irpf_pct']), 'totales' => $t];
    }

    /** Huella SHA-256 del contenido emitido: si alguien toca la base a mano, se nota. */
    public static function huella(array $contenido): string
    {
        return hash('sha256', json_encode($contenido, JSON_UNESCAPED_UNICODE));
    }

    /* ===================== Estado de cobro ===================== */

    public function cambiarEstado(Acceso $acc, int $id, array $d): array
    {
        Validar::escribe($acc, 'finanzas.emitir');
        $f = $this->visible($acc, $id);
        $nuevo = (string)($d['estado'] ?? '');
        if (!in_array($nuevo, self::COBRABLES, true)) throw HttpError::validacion('Estado no válido.', 'estado');
        if ($f['estado'] === 'borrador') throw new HttpError(409, 'Primero emite la factura.', 'borrador');
        if ($f['estado'] === 'anulada') throw new HttpError(409, 'La factura está anulada.', 'anulada');
        if ($nuevo === 'pagada' || $f['estado'] === 'pagada') $acc->exigir('finanzas.cobrar');
        $fechaPago = null;
        if ($nuevo === 'pagada') {
            $fechaPago = Validar::fecha($d, 'fecha_pago') ?? ($f['estado'] === 'pagada' && $f['fecha_pago'] ? $f['fecha_pago'] : date('Y-m-d'));
            if ($fechaPago > date('Y-m-d')) throw HttpError::validacion('La fecha de cobro no puede ser futura.', 'fecha_pago');
        }
        $antes = $f['estado'];
        Tx::run($this->pdo, function () use ($id, $nuevo, $fechaPago) {
            $this->repo->actualizar($id, ['estado' => $nuevo, 'fecha_pago' => $fechaPago]);
            $this->caja->sincronizarFactura($id);
        });
        if ($nuevo === 'pagada' && $antes !== 'pagada' && function_exists('notif_invoice_paid')) {
            @notif_invoice_paid($id, $this->nombrePersona($acc->adminId));
        }
        return $this->detalle($acc, $id);
    }

    /* ===================== Duplicar, rectificar, anular ===================== */

    public function duplicar(Acceso $acc, int $id): array
    {
        Validar::escribe($acc, 'finanzas.emitir');
        $f = $this->visible($acc, $id);
        $lineas = $this->repo->lineas($id);
        /* La copia es un borrador sin número, con fecha de hoy y la serie que el
           emisor usa ahora (no la del año de la original, §17.4). */
        $nuevo = Tx::run($this->pdo, function () use ($f, $lineas) {
            $nid = $this->repo->insertar($this->copiaBase($f, ['tipo' => 'normal', 'serie' => $this->aj->get('serie_' . $f['emisor'])]));
            $this->repo->guardarLineas($nid, array_map(fn($l) => ['concepto' => $l['concepto'], 'cantidad' => $l['cantidad'], 'precio' => $l['precio']], $lineas));
            return $nid;
        });
        return $this->detalle($acc, $nuevo);
    }

    /** Crea el borrador de una rectificativa con las líneas en negativo (anulación total); se ajustan antes de emitir. */
    public function rectificar(Acceso $acc, int $id, array $d): array
    {
        Validar::escribe($acc, 'finanzas.emitir');
        $f = $this->visible($acc, $id);
        $this->exigirRectificable($f);
        $motivo = Validar::texto($d, 'motivo', 300);
        if ($motivo === '') throw HttpError::validacion('Indica el motivo de la rectificación.', 'motivo');
        $nid = Tx::run($this->pdo, fn() => $this->crearRectificativa($f, $motivo));
        return $this->detalle($acc, $nid);
    }

    /**
     * Anula una factura emitida y no cobrada: emite una rectificativa por el
     * total (serie R) y marca las dos como «anuladas». Si ya se cobró, hay que
     * rectificar (y devolver el dinero).
     */
    public function anular(Acceso $acc, int $id, array $d): array
    {
        Validar::escribe($acc, 'finanzas.emitir');
        $f = $this->visible($acc, $id);
        $this->exigirRectificable($f);
        if ($f['estado'] === 'pagada') throw new HttpError(409, 'Está cobrada: para devolver el dinero haz una rectificativa.', 'pagada');
        $motivo = Validar::texto($d, 'motivo', 300);
        if ($motivo === '') throw HttpError::validacion('Indica el motivo de la anulación.', 'motivo');
        Tx::run($this->pdo, function () use ($f, $motivo, $acc) {
            $orig = $this->repo->fila((int)$f['id'], true);
            if (!in_array($orig['estado'], ['enviada', 'vencida'], true)) throw new HttpError(409, 'Esta factura ya no se puede anular.', 'conflicto');
            $rid = $this->crearRectificativa($orig, $motivo, date('Y-m-d'));
            $this->emitirEnTx($rid, $acc->adminId);
            $ahora = date('Y-m-d H:i:s');
            $this->repo->actualizar($rid, ['estado' => 'anulada', 'anulada_at' => $ahora, 'anulada_motivo' => $motivo]);
            $this->repo->actualizar((int)$orig['id'], ['estado' => 'anulada', 'anulada_at' => $ahora, 'anulada_motivo' => $motivo, 'fecha_pago' => null]);
            $this->caja->sincronizarFactura((int)$orig['id']);
        });
        return $this->detalle($acc, $id);
    }

    /* ===================== Proyecto ===================== */

    public function asignarProyecto(Acceso $acc, int $id, array $d): array
    {
        Validar::escribe($acc, 'finanzas.emitir');
        $f = $this->visible($acc, $id);
        $pid = $this->proyectoDe($d, $f['client_id'] ? (int)$f['client_id'] : null, $acc);
        Tx::run($this->pdo, function () use ($id, $pid) {
            $this->pdo->prepare('UPDATE invoices SET project_id = ? WHERE id = ?')->execute([$pid, $id]);
            $this->pdo->prepare('UPDATE accounting SET project_id = ? WHERE invoice_id = ?')->execute([$pid, $id]);
        });
        return $this->detalle($acc, $id);
    }

    /* ===================== Negocio → factura ===================== */

    /**
     * Prellenado del editor desde un negocio del CRM (solo lectura de deals,
     * contacts, billing_data y clients). Si ya hay factura para ese negocio,
     * la devuelve en `ya` para no crear otra.
     */
    public function desdeNegocio(Acceso $acc, int $dealId): array
    {
        $acc->exigir('ver.finanzas', 'finanzas.emitir');
        $st = $this->pdo->prepare('SELECT d.*, c.nombre AS c_nombre, c.empresa AS c_empresa, c.email AS c_email, c.telefono AS c_tel
                                   FROM deals d LEFT JOIN contacts c ON c.id = d.contact_id WHERE d.id = ?');
        $st->execute([$dealId]);
        $n = $st->fetch(PDO::FETCH_ASSOC);
        if (!$n) throw HttpError::noEncontrado('Negocio no encontrado.');
        $cli = $n['client_id'] ? (int)$n['client_id'] : null;
        if (!Validar::veCliente($acc, $cli)) throw HttpError::noEncontrado('Negocio no encontrado.');
        $ya = $this->facturaDeNegocio($dealId, $n['invoice_id'] ? (int)$n['invoice_id'] : null);

        $fis = ['nombre' => '', 'nif' => '', 'dir' => '', 'email' => '', 'tel' => ''];
        if ($cli && ($c = $this->repo->cliente($cli))) {
            $fis = ['nombre' => (string)$c['fact_nombre'], 'nif' => (string)$c['fact_nif'], 'dir' => (string)$c['fact_dir'], 'email' => (string)$c['fact_email'], 'tel' => (string)$c['fact_tel']];
        }
        if ($n['contact_id']) {
            $b = $this->pdo->prepare('SELECT * FROM billing_data WHERE contact_id = ?');
            $b->execute([(int)$n['contact_id']]);
            if ($bd = $b->fetch(PDO::FETCH_ASSOC)) {
                $dir = trim(implode(', ', array_filter([trim((string)$bd['direccion']), trim(trim((string)$bd['cp']) . ' ' . trim((string)$bd['ciudad'])), trim((string)$bd['provincia']), trim((string)$bd['pais'])])));
                $fis['nombre'] = $fis['nombre'] ?: (string)$bd['razon_social'];
                $fis['nif'] = $fis['nif'] ?: (string)$bd['cif'];
                $fis['dir'] = $fis['dir'] ?: $dir;
                $fis['email'] = $fis['email'] ?: (string)$bd['email_facturacion'];
            }
        }
        $fis['nombre'] = $fis['nombre'] ?: trim((string)($n['c_empresa'] ?: $n['c_nombre']));
        $fis['email'] = $fis['email'] ?: (string)$n['c_email'];
        $fis['tel'] = $fis['tel'] ?: (string)$n['c_tel'];
        if (!$fis['nombre'] && $cli && isset($c)) $fis['nombre'] = (string)$c['name'];

        $emisor = $this->emisores->porDefecto();
        $def = $this->emisores->datos($emisor);
        $concepto = mb_substr(trim((string)$n['nombre']) . (trim((string)$n['servicio']) !== '' ? ' · ' . trim((string)$n['servicio']) : ''), 0, 300);
        return [
            'ya' => $ya,
            'negocio' => ['id' => $dealId, 'nombre' => (string)$n['nombre']],
            'borrador' => [
                'deal_id' => $dealId, 'client_id' => $cli, 'cliente' => $fis, 'emisor' => $emisor, 'serie' => $this->aj->get("serie_$emisor"),
                'iva_pct' => $def['iva'], 'irpf_pct' => $def['irpf'], 'cond_pago' => $def['venc'],
                /* El valor del negocio se toma como base imponible (como el puente antiguo). */
                'lineas' => [['concepto' => $concepto !== '' ? $concepto : 'Servicios', 'cantidad' => '1.00', 'precio' => Dinero::decimal(Dinero::c($n['valor'] ?? '0'))]],
            ],
        ];
    }

    /* ===================== Piezas internas ===================== */

    private function facturaDeNegocio(int $dealId, ?int $invoiceIdDelNegocio = null): ?array
    {
        $st = $this->pdo->prepare('SELECT id, numero, estado FROM invoices WHERE deal_id = ? ' . ($invoiceIdDelNegocio ? 'OR id = ?' : '') . ' ORDER BY id LIMIT 1');
        $st->execute($invoiceIdDelNegocio ? [$dealId, $invoiceIdDelNegocio] : [$dealId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id' => (int)$r['id'], 'numero' => $r['numero'], 'estado' => (string)$r['estado']] : null;
    }

    private function exigirRectificable(array $f): void
    {
        if ($f['estado'] === 'borrador') throw new HttpError(409, 'Un borrador se edita directamente: no hace falta rectificarlo.', 'borrador');
        if ($f['estado'] === 'anulada') throw new HttpError(409, 'La factura ya está anulada.', 'anulada');
        if ($f['tipo'] === 'rectificativa') throw new HttpError(409, 'Una rectificativa no se rectifica: emite otra sobre la factura original.', 'rectificativa');
    }

    private function crearRectificativa(array $f, string $motivo, ?string $fecha = null): int
    {
        $lineas = $this->repo->lineas((int)$f['id']);
        $rid = $this->repo->insertar($this->copiaBase($f, [
            'tipo' => 'rectificativa', 'rectifica_id' => (int)$f['id'], 'rect_motivo' => $motivo, 'serie' => '',
            'notas' => 'Rectifica la factura ' . $f['numero'] . ' del ' . date('d/m/Y', strtotime((string)$f['fecha'])) . '. Motivo: ' . $motivo,
            'fecha' => $fecha ?? date('Y-m-d'),
        ]));
        $this->repo->guardarLineas($rid, array_map(fn($l) => [
            'concepto' => $l['concepto'], 'cantidad' => Dinero::decimal(-Dinero::c($l['cantidad'])), 'precio' => $l['precio'],
        ], $lineas));
        return $rid;
    }

    /** Campos de un borrador nuevo copiado de otra factura. */
    private function copiaBase(array $f, array $extra): array
    {
        return $extra + [
            'emisor' => $f['emisor'], 'estado' => 'borrador', 'client_id' => $f['client_id'] ?: null,
            'cliente_nombre' => $f['cliente_nombre'], 'cliente_nif' => $f['cliente_nif'], 'cliente_dir' => $f['cliente_dir'],
            'cliente_email' => $f['cliente_email'], 'cliente_tel' => $f['cliente_tel'],
            'fecha' => date('Y-m-d'), 'fecha_venc' => null, 'periodo_ini' => $f['periodo_ini'] ?: null, 'periodo_fin' => $f['periodo_fin'] ?: null,
            'cond_pago' => $f['cond_pago'] ?: 'Contado', 'iva_pct' => $f['iva_pct'], 'irpf_pct' => $f['irpf_pct'],
            'efectivo' => (int)$f['efectivo'], 'personal' => (int)$f['personal'], 'notas' => (string)$f['notas'],
            'mencion_iva' => (string)$f['mencion_iva'], 'project_id' => $f['project_id'] ?: null,
        ];
    }

    /**
     * Valida los campos de un borrador. $antes = fila actual (null al crear).
     * Devuelve las columnas y, en '__lineas', las líneas si venían.
     */
    private function validar(Acceso $acc, array $d, ?array $antes): array
    {
        $c = [];
        if (array_key_exists('emisor', $d)) {
            if (!$this->emisores->existe((string)$d['emisor'])) throw HttpError::validacion('Ese emisor no existe.', 'emisor');
            $c['emisor'] = (string)$d['emisor'];
        }
        if (array_key_exists('serie', $d)) $c['serie'] = Numeracion::validarSerie((string)$d['serie']);
        if (array_key_exists('client_id', $d)) {
            $cli = Validar::idONull($d, 'client_id');
            if ($cli && (!$this->repo->cliente($cli) || !$acc->veCliente($cli))) throw HttpError::validacion('Cliente no encontrado.', 'client_id');
            $c['client_id'] = $cli;
        }
        $cliente = is_array($d['cliente'] ?? null) ? $d['cliente'] : null;
        if ($cliente !== null) {
            $c['cliente_nombre'] = Validar::texto($cliente, 'nombre', 200);
            $c['cliente_nif'] = Validar::texto($cliente, 'nif', 40);
            $c['cliente_dir'] = Validar::texto($cliente, 'dir', 300);
            $c['cliente_email'] = Validar::email($cliente, 'email');
            $c['cliente_tel'] = Validar::texto($cliente, 'tel', 40);
        }
        /* Sin nombre pero con cliente: el nombre del cliente (como el antiguo). */
        $cliFinal = array_key_exists('client_id', $c) ? $c['client_id'] : ($antes['client_id'] ?? null);
        $nomFinal = $c['cliente_nombre'] ?? ($antes['cliente_nombre'] ?? '');
        if ($cliFinal && trim((string)$nomFinal) === '' && ($cl = $this->repo->cliente((int)$cliFinal))) {
            $c['cliente_nombre'] = (string)($cl['fact_nombre'] ?: $cl['name']);
        }
        if (array_key_exists('fecha', $d)) $c['fecha'] = Validar::fecha($d, 'fecha') ?? date('Y-m-d');
        foreach (['fecha_venc', 'periodo_ini', 'periodo_fin'] as $k) if (array_key_exists($k, $d)) $c[$k] = Validar::fecha($d, $k);
        $fecha = $c['fecha'] ?? ($antes['fecha'] ?? date('Y-m-d'));
        $venc = array_key_exists('fecha_venc', $c) ? $c['fecha_venc'] : ($antes['fecha_venc'] ?? null);
        if ($venc && $venc < $fecha) throw HttpError::validacion('El vencimiento no puede ser anterior a la fecha de la factura.', 'fecha_venc');
        $pi = array_key_exists('periodo_ini', $c) ? $c['periodo_ini'] : ($antes['periodo_ini'] ?? null);
        $pf = array_key_exists('periodo_fin', $c) ? $c['periodo_fin'] : ($antes['periodo_fin'] ?? null);
        if ($pi && $pf && $pf < $pi) throw HttpError::validacion('El período termina antes de empezar.', 'periodo_fin');
        if (array_key_exists('cond_pago', $d)) $c['cond_pago'] = Validar::texto($d, 'cond_pago', 60) ?: 'Contado';
        foreach (['iva_pct' => 'el IVA', 'irpf_pct' => 'el IRPF'] as $k => $et) {
            if (array_key_exists($k, $d)) $c[$k] = Dinero::exigir($d[$k], $k, $et, 0, 10000);
        }
        if (array_key_exists('efectivo', $d)) $c['efectivo'] = Validar::bool($d, 'efectivo') ? 1 : 0;
        if (array_key_exists('personal', $d)) $c['personal'] = Validar::bool($d, 'personal') ? 1 : 0;
        if ((int)($c['efectivo'] ?? $antes['efectivo'] ?? 0) === 1) {
            $c['iva_pct'] = '0.00';
            $c['irpf_pct'] = '0.00';
        }
        if (array_key_exists('notas', $d)) $c['notas'] = Validar::texto($d, 'notas', 2000);
        if (array_key_exists('mencion_iva', $d)) $c['mencion_iva'] = Validar::texto($d, 'mencion_iva', 250);
        if (array_key_exists('project_id', $d) || array_key_exists('project_nombre', $d)) {
            $c['project_id'] = $this->proyectoDe($d, $cliFinal ? (int)$cliFinal : null, $acc);
        }
        if (array_key_exists('lineas', $d)) {
            $rect = ($antes['tipo'] ?? 'normal') === 'rectificativa';
            $c['__lineas'] = $this->validarLineas($d['lineas'], $rect);
        }
        /* Serie propia: se recuerda para la próxima factura de ese emisor. */
        if (($c['serie'] ?? '') !== '' && (($antes['tipo'] ?? 'normal') === 'normal')) {
            $this->aj->set('serie_' . ($c['emisor'] ?? $antes['emisor'] ?? ''), $c['serie']);
        }
        return $c;
    }

    /** @return array<int, array{concepto:string, cantidad:string, precio:string}> */
    public function validarLineas(mixed $lineas, bool $negativas = false): array
    {
        if (!is_array($lineas) || !array_is_list($lineas)) throw HttpError::validacion('Líneas no válidas.', 'lineas');
        if (count($lineas) > self::MAX_LINEAS) throw HttpError::validacion('Demasiadas líneas (máximo ' . self::MAX_LINEAS . ').', 'lineas');
        $out = [];
        foreach ($lineas as $i => $l) {
            if (!is_array($l)) throw HttpError::validacion('Línea no válida.', "lineas.$i");
            $concepto = Validar::texto($l, 'concepto', 300);
            if ($concepto === '') continue;   // como el antiguo: sin concepto, la línea no cuenta
            $min = $negativas ? -99999999 : 1;
            $cant = Dinero::exigir($l['cantidad'] ?? '1', "lineas.$i.cantidad", 'la cantidad', $min, 9999999);
            if (Dinero::c($cant) === 0) throw HttpError::validacion('La cantidad no puede ser 0.', "lineas.$i.cantidad");
            $precio = Dinero::exigir($l['precio'] ?? '0', "lineas.$i.precio", 'el precio', $negativas ? -999999999 : 0, 999999999);
            $out[] = ['concepto' => $concepto, 'cantidad' => $cant, 'precio' => $precio];
        }
        return $out;
    }

    private function proyectoDe(array $d, ?int $clientId, Acceso $acc): ?int
    {
        $nombre = Validar::texto($d, 'project_nombre', 160);
        if ($nombre !== '') return $this->proyectos->obtenerOCrear($acc, $nombre, $clientId);
        $pid = Validar::idONull($d, 'project_id');
        if ($pid && !$this->repo->proyecto($pid)) throw HttpError::validacion('Ese proyecto no existe.', 'project_id');
        return $pid;
    }

    /** Copia a la ficha del cliente los datos fiscales que se han rellenado (solo los no vacíos). */
    private function copiarFiscalesAlCliente(Acceso $acc, array $c): void
    {
        $cli = $c['client_id'] ?? null;
        if (!$cli || !$acc->puede('general.editar')) return;
        $map = ['cliente_nombre' => 'fact_nombre', 'cliente_nif' => 'fact_nif', 'cliente_dir' => 'fact_dir', 'cliente_email' => 'fact_email', 'cliente_tel' => 'fact_tel'];
        $set = [];
        $vals = [];
        foreach ($map as $de => $a) {
            if (trim((string)($c[$de] ?? '')) === '') continue;
            $set[] = "$a = ?";
            $vals[] = $c[$de];
        }
        if (!$set) return;
        $this->pdo->prepare('UPDATE clients SET ' . implode(', ', $set) . ' WHERE id = ?')->execute([...$vals, $cli]);
    }

    /** Fuera de su alcance, una factura responde igual que si no existiera. */
    private function visible(Acceso $acc, int $id): array
    {
        $f = $this->repo->fila($id);
        if (!$f || !Validar::veCliente($acc, $f['client_id'] ? (int)$f['client_id'] : null)) throw HttpError::noEncontrado('Factura no encontrada.');
        return $f;
    }

    private function nombrePersona(int $id): string
    {
        $st = $this->pdo->prepare('SELECT username FROM admins WHERE id = ?');
        $st->execute([$id]);
        return (string)$st->fetchColumn();
    }
}
