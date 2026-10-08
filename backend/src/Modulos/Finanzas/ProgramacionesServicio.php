<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Facturas recurrentes (programaciones). Cada mes, el día indicado, se emite
   la factura del cliente. Corrige el prog_run() antiguo (§17.21):
   · Un solo generador a la vez (GET_LOCK): el cron y «Generar ahora» no se pisan.
   · Cada mes es una transacción: crear la factura, numerarla y avanzar last_ym
     van juntos (o nada).
   · Idempotente por programación+mes: índice único (schedule_id, schedule_ym);
     si la factura de ese mes ya existe, solo se avanza last_ym.
   · Los meses atrasados se emiten con fecha de hoy (nunca con fecha pasada que
     rompa el orden de la serie) y con el período del mes que cubren. */
final class ProgramacionesServicio
{
    public const BLOQUEO = 'croilab_prog_run';

    public function __construct(
        private readonly PDO $pdo,
        private readonly EmisoresServicio $emisores,
        private readonly FacturasRepositorio $repo,
        private readonly FacturasServicio $facturas,
        private readonly Numeracion $numeracion,
        private readonly ProyectosServicio $proyectos
    ) {}

    public function listar(Acceso $acc): array
    {
        $acc->exigir('ver.finanzas');
        $st = $this->pdo->query('SELECT s.*, c.fact_nombre AS c_fact, p.nombre AS p_nombre, p.color AS p_color FROM invoice_schedules s LEFT JOIN clients c ON c.id = s.client_id LEFT JOIN projects p ON p.id = s.project_id
                                 WHERE 1=1 ' . Validar::sqlAlcance($acc, 's.client_id') . ' ORDER BY s.activo DESC, s.id DESC');
        return ['items' => array_map([$this, 'item'], $st->fetchAll(PDO::FETCH_ASSOC))];
    }

    public function item(array $s): array
    {
        $lineas = self::lineasDe((string)$s['lineas_json']);
        $t = Dinero::totales($lineas, $s['iva_pct'], $s['irpf_pct']);
        return [
            'id' => (int)$s['id'], 'emisor' => (string)$s['emisor'], 'emisor_nombre' => $this->emisores->nombre((string)$s['emisor']),
            'serie' => (string)$s['serie'], 'client_id' => $s['client_id'] ? (int)$s['client_id'] : null,
            'cliente' => ['nombre' => (string)$s['cliente_nombre'], 'nif' => (string)$s['cliente_nif'], 'dir' => (string)$s['cliente_dir'],
                          'email' => (string)$s['cliente_email'], 'tel' => (string)$s['cliente_tel']],
            'cliente_sin_datos' => $s['client_id'] && trim((string)($s['c_fact'] ?? '')) === '',
            'lineas' => array_map(fn($l) => $l + ['importe' => Dinero::linea($l['cantidad'], $l['precio'])], $lineas),
            'iva_pct' => Dinero::pct($s['iva_pct']), 'irpf_pct' => Dinero::pct($s['irpf_pct']), 'cond_pago' => (string)$s['cond_pago'],
            'dia' => (int)$s['dia'], 'activo' => (bool)$s['activo'], 'start_ym' => (string)$s['start_ym'], 'last_ym' => (string)$s['last_ym'] ?: null,
            'venc_dias' => $s['venc_dias'] !== null ? (int)$s['venc_dias'] : null,
            'project_id' => $s['project_id'] ? (int)$s['project_id'] : null,
            'project' => $s['project_id'] && ($s['p_nombre'] ?? null) !== null ? ['id' => (int)$s['project_id'], 'nombre' => (string)$s['p_nombre'], 'color' => (string)($s['p_color'] ?: '#2f6df6')] : null,
            'base_mes' => $t['base'], 'total_mes' => $t['total'],
            'proxima' => $s['activo'] ? $this->proxima($s) : null,
        ];
    }

    /** @return array<int, array{concepto:string, cantidad:string, precio:string}> */
    public static function lineasDe(string $json): array
    {
        $arr = json_decode($json, true);
        $out = [];
        foreach (is_array($arr) ? $arr : [] as $l) {
            if (!is_array($l)) continue;
            $c = trim((string)($l['c'] ?? ''));
            if ($c === '') continue;
            $out[] = ['concepto' => $c, 'cantidad' => Dinero::leer($l['q'] ?? '1') ?? '1.00', 'precio' => Dinero::leer($l['p'] ?? '0') ?? '0.00'];
        }
        return $out;
    }

    public function crear(Acceso $acc, array $d): array
    {
        Validar::escribe($acc, 'finanzas.programar');
        $c = $this->validar($acc, $d, null);
        $cols = array_keys($c);
        $this->pdo->prepare('INSERT INTO invoice_schedules (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($c));
        return $this->detalle($acc, (int)$this->pdo->lastInsertId());
    }

    public function actualizar(Acceso $acc, int $id, array $d): array
    {
        Validar::escribe($acc, 'finanzas.programar');
        $antes = $this->fila($acc, $id);
        $c = $this->validar($acc, $d, $antes);
        if ($c) {
            $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($c)));
            $this->pdo->prepare("UPDATE invoice_schedules SET $set WHERE id = ?")->execute([...array_values($c), $id]);
        }
        return $this->detalle($acc, $id);
    }

    public function activar(Acceso $acc, int $id, bool $activo): array
    {
        Validar::escribe($acc, 'finanzas.programar');
        $this->fila($acc, $id);
        $this->pdo->prepare('UPDATE invoice_schedules SET activo = ? WHERE id = ?')->execute([$activo ? 1 : 0, $id]);
        return $this->detalle($acc, $id);
    }

    /** Borra la programación; las facturas ya emitidas se quedan. */
    public function borrar(Acceso $acc, int $id): void
    {
        Validar::escribe($acc, 'finanzas.programar');
        $this->fila($acc, $id);
        $this->pdo->prepare('DELETE FROM invoice_schedules WHERE id = ?')->execute([$id]);
    }

    public function detalle(Acceso $acc, int $id): array
    {
        return $this->item($this->fila($acc, $id));
    }

    /** «Generar ahora». */
    public function generar(Acceso $acc): array
    {
        Validar::escribe($acc, 'finanzas.programar', 'finanzas.emitir');
        return $this->ejecutar($acc->adminId);
    }

    /**
     * Emite lo pendiente de todas las programaciones activas hasta hoy.
     * Lo llaman el cron (sin persona) y «Generar ahora».
     * @return array{generadas:int, facturas:array, errores:array, ocupado:bool}
     */
    public function ejecutar(?int $adminId = null, ?string $hoy = null): array
    {
        $res = ['generadas' => 0, 'facturas' => [], 'errores' => [], 'ocupado' => false];
        $lock = (int)$this->pdo->query("SELECT GET_LOCK('" . self::BLOQUEO . "', 0)")->fetchColumn();
        if ($lock !== 1) {
            $res['ocupado'] = true;
            return $res;
        }
        try {
            $hoy ??= date('Y-m-d');
            $ids = $this->pdo->query('SELECT id FROM invoice_schedules WHERE activo = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $sid) {
                for ($guard = 0; $guard < 60; $guard++) {
                    try {
                        $r = Tx::run($this->pdo, fn() => $this->generarSiguiente((int)$sid, $hoy, $adminId));
                    } catch (HttpError $e) {
                        $res['errores'][] = ['programacion_id' => (int)$sid, 'msg' => $e->getMessage()];
                        break;
                    }
                    if ($r === null) break;
                    if ($r['id']) {
                        $res['generadas']++;
                        $res['facturas'][] = $r;
                    }
                }
            }
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::BLOQUEO . "')")->fetchAll();
        }
        return $res;
    }

    /** Genera el mes siguiente de una programación (dentro de su transacción). null = nada que hacer. */
    private function generarSiguiente(int $sid, string $hoy, ?int $adminId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM invoice_schedules WHERE id = ? FOR UPDATE');
        $st->execute([$sid]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s || !(int)$s['activo']) return null;
        $cur = substr($hoy, 0, 7);
        $ym = $this->mesSiguiente($s, $cur);
        $dia = max(1, min(28, (int)$s['dia']));
        if ($ym > $cur || ($ym === $cur && (int)substr($hoy, 8, 2) < $dia)) return null;

        $ya = $this->pdo->prepare('SELECT id, numero FROM invoices WHERE schedule_id = ? AND schedule_ym = ?');
        $ya->execute([$sid, $ym]);
        if ($ya->fetch()) {
            /* Ya se emitió (otra ejecución a medias): solo se avanza. */
            $this->pdo->prepare('UPDATE invoice_schedules SET last_ym = ? WHERE id = ?')->execute([$ym, $sid]);
            return ['id' => 0, 'numero' => null, 'mes' => $ym];
        }
        $lineas = self::lineasDe((string)$s['lineas_json']);
        if (!$lineas) throw HttpError::validacion('La programación no tiene líneas.', 'lineas');
        $emisor = (string)$s['emisor'];
        if (!$this->emisores->existe($emisor)) throw HttpError::validacion('El emisor de la programación ya no existe.', 'emisor');

        $fecha = $ym === $cur ? $ym . '-' . str_pad((string)$dia, 2, '0', STR_PAD_LEFT) : $hoy;
        $ultima = $this->numeracion->ultimaFecha($this->numeracion->serie($emisor, (string)$s['serie'], $fecha));
        if ($ultima !== null && $ultima > $fecha) $fecha = min($ultima, $hoy);
        [$pIni, $pFin] = Validar::rangoMes($ym);

        $cli = [
            'nombre' => (string)$s['cliente_nombre'], 'nif' => (string)$s['cliente_nif'], 'dir' => (string)$s['cliente_dir'],
            'email' => (string)$s['cliente_email'], 'tel' => (string)$s['cliente_tel'],
        ];
        if ($s['client_id'] && ($c = $this->repo->cliente((int)$s['client_id']))) {
            foreach (['nombre' => 'fact_nombre', 'nif' => 'fact_nif', 'dir' => 'fact_dir', 'email' => 'fact_email', 'tel' => 'fact_tel'] as $k => $col) {
                if (trim($cli[$k]) === '') $cli[$k] = (string)$c[$col];
            }
            if (trim($cli['nombre']) === '') $cli['nombre'] = (string)$c['name'];
        }
        $id = $this->repo->insertar([
            'emisor' => $emisor, 'serie' => Numeracion::validarSerie((string)$s['serie']), 'tipo' => 'normal', 'estado' => 'borrador',
            'client_id' => $s['client_id'] ?: null, 'cliente_nombre' => $cli['nombre'], 'cliente_nif' => $cli['nif'], 'cliente_dir' => $cli['dir'],
            'cliente_email' => $cli['email'], 'cliente_tel' => $cli['tel'], 'fecha' => $fecha,
            'fecha_venc' => $s['venc_dias'] !== null ? date('Y-m-d', strtotime($fecha . ' +' . (int)$s['venc_dias'] . ' days')) : null,
            'periodo_ini' => $pIni, 'periodo_fin' => $pFin, 'cond_pago' => (string)$s['cond_pago'] ?: 'Contado',
            'iva_pct' => $s['iva_pct'], 'irpf_pct' => $s['irpf_pct'], 'efectivo' => 0, 'personal' => 0,
            'notas' => 'Generada por programación mensual', 'project_id' => $s['project_id'] ?: null,
            'schedule_id' => $sid, 'schedule_ym' => $ym,
        ]);
        $this->repo->guardarLineas($id, $lineas);
        $this->facturas->emitirEnTx($id, $adminId);
        $this->pdo->prepare('UPDATE invoice_schedules SET last_ym = ? WHERE id = ?')->execute([$ym, $sid]);
        $f = $this->repo->fila($id);
        return ['id' => $id, 'numero' => $f['numero'], 'mes' => $ym, 'programacion_id' => $sid];
    }

    /** Mes que toca: el siguiente al último emitido, pero nunca antes de «Empezar en». */
    private function mesSiguiente(array $s, string $cur): string
    {
        $start = preg_match('/^\d{4}-\d{2}$/', (string)$s['start_ym']) ? (string)$s['start_ym'] : $cur;
        $last = (string)$s['last_ym'];
        if (!preg_match('/^\d{4}-\d{2}$/', $last)) return $start;
        $sig = Validar::mesSiguiente($last);
        return $sig > $start ? $sig : $start;
    }

    private function proxima(array $s): string
    {
        $cur = date('Y-m');
        $ym = $this->mesSiguiente($s, $cur);
        $dia = max(1, min(28, (int)$s['dia']));
        if ($ym < $cur) return date('Y-m-d');
        if ($ym === $cur && (int)date('j') >= $dia) return date('Y-m-d');
        return $ym . '-' . str_pad((string)$dia, 2, '0', STR_PAD_LEFT);
    }

    private function fila(Acceso $acc, int $id): array
    {
        $st = $this->pdo->prepare('SELECT s.*, c.fact_nombre AS c_fact, p.nombre AS p_nombre, p.color AS p_color FROM invoice_schedules s LEFT JOIN clients c ON c.id = s.client_id LEFT JOIN projects p ON p.id = s.project_id WHERE s.id = ?');
        $st->execute([$id]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s || !Validar::veCliente($acc, $s['client_id'] ? (int)$s['client_id'] : null)) throw HttpError::noEncontrado('Programación no encontrada.');
        return $s;
    }

    private function validar(Acceso $acc, array $d, ?array $antes): array
    {
        $c = [];
        $nuevo = $antes === null;
        if ($nuevo || array_key_exists('emisor', $d)) {
            $e = (string)($d['emisor'] ?? $this->emisores->porDefecto());
            if (!$this->emisores->existe($e)) throw HttpError::validacion('Ese emisor no existe.', 'emisor');
            $c['emisor'] = $e;
        }
        if (array_key_exists('serie', $d)) $c['serie'] = Numeracion::validarSerie((string)$d['serie']);
        if (array_key_exists('client_id', $d)) {
            $cli = Validar::idONull($d, 'client_id');
            if ($cli && (!$this->repo->cliente($cli) || !$acc->veCliente($cli))) throw HttpError::validacion('Cliente no encontrado.', 'client_id');
            $c['client_id'] = $cli;
        }
        if (is_array($d['cliente'] ?? null)) {
            $k = $d['cliente'];
            $c['cliente_nombre'] = Validar::texto($k, 'nombre', 200);
            $c['cliente_nif'] = Validar::texto($k, 'nif', 40);
            $c['cliente_dir'] = Validar::texto($k, 'dir', 300);
            $c['cliente_email'] = Validar::email($k, 'email');
            $c['cliente_tel'] = Validar::texto($k, 'tel', 40);
        }
        $cliFinal = array_key_exists('client_id', $c) ? $c['client_id'] : ($antes['client_id'] ?? null);
        $nomFinal = $c['cliente_nombre'] ?? ($antes['cliente_nombre'] ?? '');
        if (!$cliFinal && trim((string)$nomFinal) === '') throw HttpError::validacion('Elige un cliente o escribe su nombre.', 'client_id');
        if ($nuevo || array_key_exists('lineas', $d)) {
            $l = $this->facturas->validarLineas($d['lineas'] ?? []);
            if (!$l) throw HttpError::validacion('Añade al menos una línea.', 'lineas');
            $c['lineas_json'] = json_encode(array_map(fn($x) => ['c' => $x['concepto'], 'q' => $x['cantidad'], 'p' => $x['precio']], $l), JSON_UNESCAPED_UNICODE);
        }
        $def = $this->emisores->datos($c['emisor'] ?? (string)$antes['emisor']);
        foreach (['iva_pct' => ['el IVA', $def['iva']], 'irpf_pct' => ['el IRPF', $def['irpf']]] as $k => [$et, $dv]) {
            if (array_key_exists($k, $d) || $nuevo) $c[$k] = Dinero::exigir($d[$k] ?? $dv, $k, $et, 0, 10000);
        }
        if (array_key_exists('cond_pago', $d) || $nuevo) $c['cond_pago'] = Validar::texto($d, 'cond_pago', 60) ?: $def['venc'];
        if (array_key_exists('dia', $d) || $nuevo) {
            $dia = filter_var($d['dia'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 28]]);
            if ($dia === false) throw HttpError::validacion('El día de emisión va del 1 al 28.', 'dia');
            $c['dia'] = $dia;
        }
        if (array_key_exists('start_ym', $d) || $nuevo) {
            $v = (string)($d['start_ym'] ?? date('Y-m'));
            $c['start_ym'] = Validar::mes($v, 'start_ym');
        }
        if (array_key_exists('activo', $d) || $nuevo) $c['activo'] = Validar::bool($d, 'activo', true) ? 1 : 0;
        if (array_key_exists('venc_dias', $d)) {
            $v = $d['venc_dias'];
            if ($v === null || $v === '') $c['venc_dias'] = null;
            else {
                $n = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 365]]);
                if ($n === false) throw HttpError::validacion('Los días de vencimiento van de 0 a 365.', 'venc_dias');
                $c['venc_dias'] = $n;
            }
        }
        if (array_key_exists('project_id', $d) || array_key_exists('project_nombre', $d)) {
            $nombre = Validar::texto($d, 'project_nombre', 160);
            $c['project_id'] = $nombre !== '' ? $this->proyectos->obtenerOCrear($acc, $nombre, $cliFinal ? (int)$cliFinal : null) : Validar::idONull($d, 'project_id');
        }
        /* Los datos fiscales que se rellenan aquí se guardan en la ficha del cliente (solo los no vacíos). */
        if ($cliFinal && isset($c['cliente_nombre'])) {
            $map = ['cliente_nombre' => 'fact_nombre', 'cliente_nif' => 'fact_nif', 'cliente_dir' => 'fact_dir', 'cliente_email' => 'fact_email', 'cliente_tel' => 'fact_tel'];
            foreach ($map as $de => $a) {
                if (trim((string)$c[$de]) === '') continue;
                $this->pdo->prepare("UPDATE clients SET $a = ? WHERE id = ?")->execute([$c[$de], $cliFinal]);
            }
        }
        return $c;
    }
}
