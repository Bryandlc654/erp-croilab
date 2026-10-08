<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Horas del equipo (time_entries) y su paso a gasto (puente pu_horas_a_gasto).
   Trabajo escribe las horas de las tareas en time_entries (concepto «Horas de
   la tarea»); aquí se ven por persona y mes, se añaden extras manuales y el
   gestor las vuelca a Contabilidad.
   «Gestor» = puede escribir y emitir facturas (antes bastaba general.editar,
   §17.18). Volcar exige además conta.editar.
   Corrige al antiguo: no se puede borrar un extra ya volcado (el gasto no se
   actualizaba, §17.14) y el volcado bloquea las filas para no apuntarlas dos
   veces. */
final class HorasServicio
{
    public function __construct(private readonly PDO $pdo, private readonly Caja $caja) {}

    public static function esGestor(Acceso $acc): bool
    {
        return $acc->puede('general.editar') && $acc->puede('finanzas.emitir');
    }

    public function personas(Acceso $acc): array
    {
        $acc->exigir('ver.horas');
        if (!self::esGestor($acc)) return ['items' => [self::personaItem($this->persona($acc->adminId))]];
        $st = $this->pdo->query('SELECT id, username, es_autonomo, tarifa_hora, iva_pct, irpf_pct FROM admins WHERE activo = 1 ORDER BY username');
        return ['items' => array_map([self::class, 'personaItem'], $st->fetchAll(PDO::FETCH_ASSOC))];
    }

    public function ver(Acceso $acc, ?int $adminId, string $ym): array
    {
        $acc->exigir('ver.horas');
        Validar::mes($ym);
        $gestor = self::esGestor($acc);
        $uid = $adminId ?: $acc->adminId;
        if ($uid !== $acc->adminId && !$gestor) throw HttpError::permiso();
        $p = $this->persona($uid);
        [$ini, $fin] = Validar::rangoMes($ym);
        $st = $this->pdo->prepare('SELECT e.*, t.titulo AS t_titulo, COALESCE(c.name, c2.name) AS c_nombre FROM time_entries e
                                   LEFT JOIN tasks t ON t.id = e.task_id LEFT JOIN clients c ON c.id = t.client_id LEFT JOIN clients c2 ON c2.id = e.client_id
                                   WHERE e.admin_id = ? AND e.fecha BETWEEN ? AND ? ORDER BY e.fecha DESC, e.id DESC');
        $st->execute([$uid, $ini, $fin]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        $tarifa = Dinero::c($p['tarifa_hora']);
        $min = 0;
        $extraImp = 0;
        $porTarea = [];
        $extras = [];
        $pend = ['n' => 0, 'minutos' => 0, 'importe' => 0];
        foreach ($filas as $e) {
            $m = (int)$e['minutos'];
            $imp = $e['importe'] !== null ? Dinero::c($e['importe']) : null;
            $min += $m;
            if ($imp !== null) $extraImp += $imp;
            if (!$e['acc_id']) {
                $pend['n']++;
                $pend['minutos'] += $m;
                $pend['importe'] += $imp ?? self::aEuros($m, $tarifa);
            }
            if ($e['task_id']) {
                $k = (int)$e['task_id'];
                $porTarea[$k] ??= ['task_id' => $k, 'titulo' => (string)($e['t_titulo'] ?? 'Tarea #' . $k), 'cliente' => $e['c_nombre'] !== null ? (string)$e['c_nombre'] : null, 'n' => 0, 'minutos' => 0, 'importe' => 0];
                $porTarea[$k]['n']++;
                $porTarea[$k]['minutos'] += $m;
                $porTarea[$k]['importe'] += $imp ?? self::aEuros($m, $tarifa);
            } else {
                $extras[] = ['id' => (int)$e['id'], 'concepto' => (string)$e['concepto'], 'fecha' => $e['fecha'], 'minutos' => $m,
                             'importe' => $imp, 'valor' => $imp ?? self::aEuros($m, $tarifa), 'volcada' => (bool)$e['acc_id']];
            }
        }
        $base = self::aEuros($min, $tarifa) + $extraImp;
        $t = Dinero::desdeBase($base, $p['iva_pct'], $p['irpf_pct']);
        usort($porTarea, fn($a, $b) => $b['minutos'] <=> $a['minutos']);
        return [
            'persona' => self::personaItem($p), 'mes' => $ym, 'gestor' => $gestor, 'puede_volcar' => $gestor && $acc->puede('conta.editar'),
            'calculo' => ['minutos' => $min, 'extra_importe' => $extraImp, 'base' => $t['base'], 'iva' => $t['iva'], 'irpf' => $t['irpf'], 'total' => $t['total'],
                          'n_tareas' => count($porTarea), 'n_extras' => count($extras)],
            'por_tarea' => array_values($porTarea), 'extras' => $extras,
            'pendiente' => $pend + ['fecha' => $fin], 'hay_volcadas' => count(array_filter($filas, fn($e) => $e['acc_id'])) > 0,
        ];
    }

    /** {admin_id?, concepto, fecha, tipo: horas|importe, valor} */
    public function anadirExtra(Acceso $acc, array $d): array
    {
        Validar::escribe($acc, 'tareas.horas');
        $uid = Validar::idONull($d, 'admin_id') ?? $acc->adminId;
        if ($uid !== $acc->adminId && !self::esGestor($acc)) throw HttpError::permiso();
        $this->persona($uid);
        $concepto = Validar::texto($d, 'concepto', 255);
        if ($concepto === '') throw HttpError::validacion('Escribe un concepto.', 'concepto');
        $fecha = Validar::fecha($d, 'fecha') ?? date('Y-m-d');
        $tipo = (string)($d['tipo'] ?? 'horas');
        if (!in_array($tipo, ['horas', 'importe'], true)) throw HttpError::validacion('Tipo no válido.', 'tipo');
        $valor = Dinero::exigir($d['valor'] ?? null, 'valor', $tipo === 'horas' ? 'las horas' : 'el importe', 1, $tipo === 'horas' ? 100000 : 99999999);
        $min = $tipo === 'horas' ? intdiv(Dinero::c($valor) * 60 + 50, 100) : 0;
        $imp = $tipo === 'importe' ? $valor : null;
        $this->pdo->prepare('INSERT INTO time_entries (admin_id, task_id, client_id, fecha, minutos, importe, concepto) VALUES (?, NULL, NULL, ?, ?, ?, ?)')
            ->execute([$uid, $fecha, $min, $imp, $concepto]);
        return ['id' => (int)$this->pdo->lastInsertId()];
    }

    public function borrar(Acceso $acc, int $id): void
    {
        Validar::escribe($acc, 'tareas.horas');
        $st = $this->pdo->prepare('SELECT admin_id, acc_id FROM time_entries WHERE id = ?');
        $st->execute([$id]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e) throw HttpError::noEncontrado('Registro no encontrado.');
        if ((int)$e['admin_id'] !== $acc->adminId && !self::esGestor($acc)) throw HttpError::permiso();
        if ($e['acc_id']) throw new HttpError(409, 'Ya está apuntado como gasto en Contabilidad: no se puede borrar.', 'volcada');
        $this->pdo->prepare('DELETE FROM time_entries WHERE id = ?')->execute([$id]);
    }

    /** {es_autonomo, tarifa_hora, iva_pct, irpf_pct} */
    public function tarifa(Acceso $acc, int $adminId, array $d): array
    {
        $acc->exigir('ver.horas');
        if (!self::esGestor($acc)) throw HttpError::permiso();
        $this->persona($adminId);
        $v = [
            Validar::bool($d, 'es_autonomo') ? 1 : 0,
            Dinero::exigir($d['tarifa_hora'] ?? '0', 'tarifa_hora', 'la tarifa', 0, 9999999),
            Dinero::exigir($d['iva_pct'] ?? '0', 'iva_pct', 'el IVA', 0, 10000),
            Dinero::exigir($d['irpf_pct'] ?? '0', 'irpf_pct', 'el IRPF', 0, 10000),
        ];
        $this->pdo->prepare('UPDATE admins SET es_autonomo = ?, tarifa_hora = ?, iva_pct = ?, irpf_pct = ? WHERE id = ?')->execute([...$v, $adminId]);
        return self::personaItem($this->persona($adminId));
    }

    /** Pasa a un gasto de «Equipo» las horas aún no volcadas del mes. */
    public function volcar(Acceso $acc, int $adminId, string $ym): array
    {
        $acc->exigir('ver.horas');
        if (!self::esGestor($acc)) throw HttpError::permiso();
        Validar::escribe($acc, 'conta.editar');
        Validar::mes($ym);
        $p = $this->persona($adminId);
        [$ini, $fin] = Validar::rangoMes($ym);
        return Tx::run($this->pdo, function () use ($p, $adminId, $ini, $fin) {
            $st = $this->pdo->prepare('SELECT e.id, e.minutos, e.importe, COALESCE(t.client_id, e.client_id) AS cli FROM time_entries e LEFT JOIN tasks t ON t.id = e.task_id
                                       WHERE e.admin_id = ? AND e.fecha BETWEEN ? AND ? AND e.acc_id IS NULL FOR UPDATE');
            $st->execute([$adminId, $ini, $fin]);
            $filas = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$filas) throw HttpError::validacion('No hay horas pendientes de volcar en ese periodo.', 'mes');
            $tarifa = Dinero::c($p['tarifa_hora']);
            $total = 0;
            $min = 0;
            $clientes = [];
            foreach ($filas as $e) {
                $total += $e['importe'] !== null ? Dinero::c($e['importe']) : self::aEuros((int)$e['minutos'], $tarifa);
                $min += (int)$e['minutos'];
                if ($e['cli']) $clientes[(int)$e['cli']] = true;
            }
            if ($total <= 0) throw HttpError::validacion('Esas horas suman 0 €: revisa la tarifa por hora de ' . $p['username'] . '.', 'tarifa_hora');
            $horas = rtrim(rtrim(number_format($min / 60, 2, ',', ''), '0'), ',');
            $accId = $this->caja->guardarApunte(null, [
                'fecha' => $fin, 'tipo' => 'gasto',
                'concepto' => mb_substr('Horas ' . $p['username'] . ' · ' . date('d/m/Y', strtotime($ini)) . ' – ' . date('d/m/Y', strtotime($fin)), 0, 250),
                'categoria' => 'Equipo', 'importe' => Dinero::decimal($total), 'metodo' => 'transferencia', 'legal' => 1, 'ambito' => 'empresa',
                'deducible' => 0, 'personal' => 0, 'client_id' => count($clientes) === 1 ? array_key_first($clientes) : null,
                'notas' => "$horas h del equipo", 'admin_id' => $adminId,
            ]);
            $ids = array_map(fn($e) => (int)$e['id'], $filas);
            $this->pdo->prepare('UPDATE time_entries SET acc_id = ? WHERE id IN (' . implode(',', $ids) . ')')->execute([$accId]);
            return ['id' => $accId, 'importe' => $total, 'minutos' => $min,
                    'msg' => 'Gasto de ' . Dinero::texto($total) . " € apuntado ($horas h)."];
        });
    }

    /** minutos × tarifa(céntimos/hora) / 60, redondeado a céntimo. */
    public static function aEuros(int $minutos, int $tarifaC): int
    {
        $x = $minutos * $tarifaC;
        $abs = intdiv(abs($x) + 30, 60);
        return $x < 0 ? -$abs : $abs;
    }

    private function persona(int $id): array
    {
        $st = $this->pdo->prepare('SELECT id, username, es_autonomo, tarifa_hora, iva_pct, irpf_pct FROM admins WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: throw HttpError::noEncontrado('Persona no encontrada.');
    }

    private static function personaItem(array $p): array
    {
        return ['id' => (int)$p['id'], 'username' => (string)$p['username'], 'es_autonomo' => (bool)$p['es_autonomo'],
                'tarifa_hora' => Dinero::c($p['tarifa_hora']), 'iva_pct' => Dinero::pct($p['iva_pct']), 'irpf_pct' => Dinero::pct($p['irpf_pct'])];
    }
}
