<?php
namespace Croilab\Modulos\Crm;

use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;
use PDO;

/* Dashboard del CRM (crm_dashboard.php): 7 KPIs y 13 gráficas para un rango
   de fechas. El antiguo lanzaba una consulta por fase y por mes; aquí todo
   va agregado con GROUP BY y respetando el alcance.

   Definiciones (03-crm.md §5.4 y riesgo 20):
   · Contactos: por fecha de alta.
   · Abiertos / pipeline / cierres previstos / días en fase: negocios en fases
     abiertas y NO archivados (lo que está en el tablero).
   · Ganado, perdidos, conversión, ticket y motivos: por fecha de cierre real,
     archivados incluidos (se cerraron igual). */
final class DashboardServicio
{
    public function __construct(private readonly PDO $pdo, private readonly EquipoRepositorio $equipo) {}

    public function datos(Acceso $acc, string $desde, string $hasta): array
    {
        $acc->exigir('ver.crm');
        $desde = $desde !== '' ? Filtros::fecha($desde, 'desde') : '';
        $hasta = $hasta !== '' ? Filtros::fecha($hasta, 'hasta') : '';
        if ($desde !== '' && $hasta !== '' && $desde > $hasta) throw HttpError::validacion('«Desde» no puede ser posterior a «Hasta».', 'desde');
        $hayRango = $desde !== '' || $hasta !== '';

        $fases = Catalogos::fases($this->pdo);
        $in = fn(array $s) => $s ? implode(',', array_map(fn($x) => $this->pdo->quote($x), $s)) : "''";
        $abiertas = $in(Catalogos::slugsDeTipo($fases, 'abierta'));
        $ganadas = $in(Catalogos::slugsDeTipo($fases, 'ganada'));
        $perdidas = $in(Catalogos::slugsDeTipo($fases, 'perdida'));
        $alc = Alcance::sql($acc);
        $D = 'FROM deals d JOIN contacts c ON c.id = d.contact_id WHERE 1=1' . $alc;
        $C = 'FROM contacts c WHERE 1=1' . $alc;
        [$rC, $pC] = $this->rango('c.fecha_creacion', $desde, $hasta, true);
        [$rD, $pD] = $this->rango('d.fecha_creacion', $desde, $hasta, true);
        [$rR, $pR] = $this->rango('d.fecha_cierre_real', $desde, $hasta, false);

        $uno = fn(string $sql, array $p = []) => $this->fila($sql, $p);
        $contactos = (int)$uno("SELECT COUNT(*) n $C $rC", $pC)['n'];
        $ab = $uno("SELECT COUNT(*) n, COALESCE(SUM(d.valor),0) v $D AND d.archivado = 0 AND d.fase IN ($abiertas) $rD", $pD);
        $ga = $uno("SELECT COUNT(*) n, COALESCE(SUM(d.valor),0) v, AVG(d.valor) m $D AND d.fase IN ($ganadas) $rR", $pR);
        $pe = (int)$uno("SELECT COUNT(*) n $D AND d.fase IN ($perdidas) $rR", $pR)['n'];
        $prev = (int)$uno("SELECT COUNT(*) n $D AND d.archivado = 0 AND d.fase IN ($abiertas) AND d.fecha_cierre_prevista IS NOT NULL
                           AND d.fecha_cierre_prevista <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")['n'];
        $ng = (int)$ga['n'];

        $meses = $this->meses($desde, $hasta);
        $ini = $meses[0] . '-01';
        $porMes = function (string $sql, array $p) use ($meses): array {
            $out = array_fill_keys($meses, 0.0);
            foreach ($this->filas($sql, $p) as $r) if (isset($out[$r['m']])) $out[$r['m']] = (float)$r['v'];
            return array_values($out);
        };
        $finMes = (new \DateTimeImmutable(end($meses) . '-01'))->modify('first day of next month')->format('Y-m-d');
        $ganMes = $porMes("SELECT DATE_FORMAT(d.fecha_cierre_real,'%Y-%m') m, COUNT(*) v $D AND d.fase IN ($ganadas) AND d.fecha_cierre_real >= ? AND d.fecha_cierre_real < ? GROUP BY m", [$ini, $finMes]);
        $perMes = $porMes("SELECT DATE_FORMAT(d.fecha_cierre_real,'%Y-%m') m, COUNT(*) v $D AND d.fase IN ($perdidas) AND d.fecha_cierre_real >= ? AND d.fecha_cierre_real < ? GROUP BY m", [$ini, $finMes]);
        $valMes = $porMes("SELECT DATE_FORMAT(d.fecha_cierre_real,'%Y-%m') m, COALESCE(SUM(d.valor),0) v $D AND d.fase IN ($ganadas) AND d.fecha_cierre_real >= ? AND d.fecha_cierre_real < ? GROUP BY m", [$ini, $finMes]);
        $nuevos = $porMes("SELECT DATE_FORMAT(c.fecha_creacion,'%Y-%m') m, COUNT(*) v $C AND c.fecha_creacion >= ? AND c.fecha_creacion < ? GROUP BY m", [$ini, $finMes]);
        $tasa = array_map(fn($g, $p) => $g + $p > 0 ? round($g * 100 / ($g + $p)) : 0, $ganMes, $perMes);

        /* Embudo: nº de negocios por fase abierta (tablero) y ganada. */
        $porFase = [];
        foreach ($this->filas("SELECT d.fase f, COUNT(*) n, COALESCE(SUM(d.valor),0) v, AVG(DATEDIFF(CURDATE(), d.fecha_entrada_fase)) dias
                               $D AND ((d.archivado = 0 AND d.fase IN ($abiertas)) OR d.fase IN ($ganadas)) $rD GROUP BY d.fase", $pD) as $r) $porFase[$r['f']] = $r;
        $embudo = $valorFase = $dias = ['labels' => [], 'data' => [], 'colors' => []];
        foreach ($fases as $slug => $f) {
            if (!in_array($f['tipo'], ['abierta', 'ganada'], true)) continue;
            $r = $porFase[$slug] ?? null;
            $this->meter($embudo, $f['nombre'], (int)($r['n'] ?? 0), $f['color']);
            if ($f['tipo'] === 'abierta') {
                $this->meter($valorFase, $f['nombre'], (float)($r['v'] ?? 0), $f['color']);
                $this->meter($dias, $f['nombre'], $r && $r['dias'] !== null ? round((float)$r['dias'], 1) : 0, $f['color']);
            }
        }

        $grupo = function (string $col, string $vacio, int $max = 0) use ($C, $rC, $pC): array {
            $rows = $this->filas("SELECT COALESCE(NULLIF(TRIM($col),''), ?) k, COUNT(*) n $C $rC GROUP BY k ORDER BY n DESC, k" . ($max ? " LIMIT $max" : ''), [$vacio, ...$pC]);
            return ['labels' => array_map(fn($r) => (string)$r['k'], $rows), 'data' => array_map(fn($r) => (int)$r['n'], $rows)];
        };

        $motivos = ['labels' => [], 'data' => []];
        foreach ($this->filas("SELECT COALESCE(d.motivo_perdida, 'otro') k, COUNT(*) n $D AND d.fase IN ($perdidas) $rR GROUP BY k ORDER BY n DESC", $pR) as $r) {
            $motivos['labels'][] = Catalogos::MOTIVOS_PERDIDA[$r['k']][0] ?? 'Otro';
            $motivos['data'][] = (int)$r['n'];
        }

        $nombres = $this->equipo->nombres();
        $props = ['labels' => [], 'data' => []];
        foreach ($this->filas("SELECT d.propietario_id p, COUNT(*) n $D AND d.archivado = 0 AND d.fase IN ($abiertas) GROUP BY d.propietario_id ORDER BY n DESC") as $r) {
            $props['labels'][] = $r['p'] !== null ? ($nombres[(int)$r['p']] ?? 'Sin asignar') : 'Sin asignar';
            $props['data'][] = (int)$r['n'];
        }

        $svc = [];
        foreach ($this->filas("SELECT c.servicio_json s $C AND c.servicio_json IS NOT NULL AND c.servicio_json <> '' $rC", $pC) as $r) {
            $a = json_decode((string)$r['s'], true);
            if (is_array($a)) foreach ($a as $s) if (is_string($s) && $s !== '') $svc[$s] = ($svc[$s] ?? 0) + 1;
        }
        arsort($svc);

        $porFaseC = [];
        foreach ($this->filas("SELECT c.fase f, COUNT(*) n $C $rC GROUP BY c.fase", $pC) as $r) $porFaseC[$r['f']] = (int)$r['n'];
        $faseC = ['labels' => [], 'data' => [], 'colors' => []];
        foreach ($fases as $slug => $f) if (($porFaseC[$slug] ?? 0) > 0) $this->meter($faseC, $f['nombre'], $porFaseC[$slug], $f['color']);

        $serie = fn(array $g, string $label = '', ?string $color = null) => ['labels' => $g['labels'], 'series' => [['label' => $label, 'data' => $g['data'], 'color' => $g['colors'] ?? $color]]];
        return [
            'rango' => ['desde' => $desde ?: null, 'hasta' => $hasta ?: null],
            'kpis' => [
                'contactos' => $contactos, 'hay_rango' => $hayRango,
                'abiertos' => (int)$ab['n'], 'valor_pipeline' => Dinero::num($ab['v']) ?? 0.0,
                'ganado' => Dinero::num($ga['v']) ?? 0.0, 'n_ganados' => $ng,
                'conversion' => $ng + $pe > 0 ? (int)round($ng * 100 / ($ng + $pe)) : 0, 'n_perdidos' => $pe,
                'ticket_medio' => Dinero::num($ga['m']) ?? 0.0, 'cierres_previstos' => $prev,
            ],
            'meses' => $meses,
            'series' => [
                'embudo' => $serie($embudo, 'Negocios'),
                'valor_fase' => $serie($valorFase, 'Valor'),
                'ganados_perdidos' => ['labels' => $meses, 'series' => [
                    ['label' => 'Ganados', 'data' => $ganMes, 'color' => '#12a150'], ['label' => 'Perdidos', 'data' => $perMes, 'color' => '#ef4444']]],
                'conversion_mes' => ['labels' => $meses, 'series' => [['label' => 'Conversión', 'data' => $tasa, 'color' => '#5b8def']]],
                'valor_ganado_mes' => ['labels' => $meses, 'series' => [['label' => 'Ganado', 'data' => $valMes, 'color' => '#12a150']]],
                'contactos_mes' => ['labels' => $meses, 'series' => [['label' => 'Contactos', 'data' => $nuevos, 'color' => '#f0872a']]],
                'origen' => $serie($grupo('c.origen_lead', 'Sin origen'), 'Contactos', null),
                'sector' => $serie($grupo('c.sector', 'Sin sector', 8), 'Contactos', '#5b8def'),
                'motivos' => $serie($motivos, 'Negocios', null),
                'propietarios' => $serie($props, 'Negocios', '#7c9cf5'),
                'servicios' => $serie(['labels' => array_keys($svc), 'data' => array_values($svc)], 'Contactos', '#12a150'),
                'fase_contactos' => $serie($faseC, 'Contactos'),
                'dias_fase' => $serie($dias, 'Días'),
            ],
        ];
    }

    private function meter(array &$g, string $label, float|int $v, string $color): void
    {
        $g['labels'][] = $label;
        $g['data'][] = $v;
        $g['colors'][] = $color;
    }

    /** Meses del rango (máx. 36) o los últimos 6, como 'AAAA-MM'. */
    private function meses(string $desde, string $hasta): array
    {
        $fin = (new \DateTimeImmutable($hasta ?: 'today'))->modify('first day of this month');
        $ini = $desde !== '' ? (new \DateTimeImmutable($desde))->modify('first day of this month') : $fin->modify('-5 months');
        if ($ini > $fin) $ini = $fin;
        $out = [];
        for ($m = $ini; $m <= $fin && count($out) < 36; $m = $m->modify('+1 month')) $out[] = $m->format('Y-m');
        /* Más de 36: los 36 últimos. */
        if ($m <= $fin) {
            $out = [];
            for ($m = $fin->modify('-35 months'); $m <= $fin; $m = $m->modify('+1 month')) $out[] = $m->format('Y-m');
        }
        return $out;
    }

    /** « AND col BETWEEN …» del rango. $datetime: la columna es TIMESTAMP (hasta = fin del día). */
    private function rango(string $col, string $desde, string $hasta, bool $datetime): array
    {
        $w = '';
        $p = [];
        if ($desde !== '') {
            $w .= " AND $col >= ?";
            $p[] = $desde;
        }
        if ($hasta !== '') {
            $w .= $datetime ? " AND $col < DATE_ADD(?, INTERVAL 1 DAY)" : " AND $col <= ?";
            $p[] = $hasta;
        }
        return [$w, $p];
    }

    private function fila(string $sql, array $p): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->fetch() ?: [];
    }

    private function filas(string $sql, array $p = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }
}
