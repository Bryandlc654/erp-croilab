<?php
namespace Croilab\Modulos\Trabajo;

use Croilab\Modulos\Clientes\Importes;
use Croilab\Modulos\Comunicacion\Chat\ChatRepositorio;
use Croilab\Modulos\Comunicacion\Soporte\SoporteRepositorio;
use Croilab\Modulos\Crm\Alcance as AlcanceCrm;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;
use PDO;

/* El panel de inicio (dashboard.php). El antiguo no aplicaba el alcance: una
   persona limitada veía las tareas de todos. Aquí todo pasa por Acceso.
   Lo de Google Calendar va en agenda(), aparte, para que el panel no espere a
   Google: el front lo pide después. */
class InicioServicio
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly EquipoRepositorio $equipo
    ) {}

    public function resumen(Acceso $acc, ?\DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new \DateTimeImmutable('today');
        $dia = $hoy->format('Y-m-d');
        $out = ['hoy' => $dia, 'kpis' => ['clientes_activos' => null, 'cobrado_mes' => null], 'pulso' => $this->pulso($acc, $hoy),
                'tareas' => null, 'hoy_tareas' => [], 'calendario' => []];

        if ($acc->puede('ver.clientes')) {
            $out['kpis']['clientes_activos'] = (int)$this->pdo->query('SELECT COUNT(*) FROM clients c WHERE COALESCE(c.activo, 1) = 1' . $acc->sqlClientes('c.id'))->fetchColumn();
        }
        /* Cobrado este mes por FECHA DE COBRO (el antiguo sumaba por fecha de
           emisión), solo facturas emitidas y pagadas (ni borradores ni
           anuladas), en céntimos con la regla de redondeo de las facturas. */
        if ($acc->puede('ver.finanzas') && $acc->puede('ver.importes')) {
            $out['kpis']['cobrado_mes'] = $this->cobradoMes($acc, $hoy);
        }
        if (!$acc->puede('ver.tareas')) return $out;

        $alc = $acc->sqlTareas('t');
        $base = 'SELECT t.id, t.titulo, t.estado, t.prioridad, t.due_date, t.responsable_id, c.name AS cname
                 FROM tasks t LEFT JOIN clients c ON c.id = t.client_id WHERE ';
        $enProceso = $this->tareas($base . "t.estado = 'en proceso'$alc ORDER BY (t.due_date IS NULL), t.due_date, t.prioridad DESC, t.id LIMIT 8");
        $st = $this->pdo->prepare($base . "t.estado <> 'completada' AND t.due_date IS NOT NULL AND t.due_date < ?$alc ORDER BY t.due_date, t.id LIMIT 8");
        $st->execute([$dia]);
        $atrasadas = $this->conAsignados($st->fetchAll());
        /* El contador de la pestaña era el de las 8 que se pintaban. */
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM tasks t WHERE t.estado <> 'completada' AND t.due_date IS NOT NULL AND t.due_date < ?$alc");
        $st->execute([$dia]);
        $nAtrasadas = (int)$st->fetchColumn();
        $completadas = $this->tareas($base . "t.estado = 'completada'$alc ORDER BY t.updated_at DESC, t.id DESC LIMIT 8");
        $out['tareas'] = ['en_proceso' => $enProceso, 'atrasadas' => $atrasadas, 'atrasadas_total' => $nAtrasadas, 'completadas' => $completadas];

        $st = $this->pdo->prepare($base . "t.estado <> 'completada' AND t.due_date = ?$alc ORDER BY t.prioridad DESC, t.id");
        $st->execute([$dia]);
        $out['hoy_tareas'] = $this->conAsignados($st->fetchAll());

        /* Calendario del mes: vencimientos de tareas abiertas por día. */
        $st = $this->pdo->prepare("SELECT t.id, t.titulo, t.due_date, c.name AS cname FROM tasks t LEFT JOIN clients c ON c.id = t.client_id
                                   WHERE t.estado <> 'completada' AND t.due_date BETWEEN ? AND ?$alc ORDER BY t.due_date, t.prioridad DESC, t.id");
        $st->execute([$hoy->format('Y-m-01'), $hoy->format('Y-m-t')]);
        foreach ($st->fetchAll() as $r) {
            $out['calendario'][] = ['dia' => (string)$r['due_date'], 'titulo' => (string)$r['titulo'], 'sub' => (string)($r['cname'] ?? ''), 'tipo' => 'tarea', 'hora' => '', 'url' => '/tareas/' . (int)$r['id']];
        }
        return $out;
    }

    /**
     * Agenda de Google de quien mira: eventos de hoy, reuniones de los próximos
     * 30 días (con el cliente emparejado por el correo de sus contactos) y los
     * eventos del mes para el calendario.
     */
    public function agenda(int $adminId, ?\DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new \DateTimeImmutable('today');
        $vacio = ['conectado' => false, 'hoy' => [], 'reuniones' => [], 'calendario' => []];
        if (!function_exists('gcal_connected')) require_once dirname(__DIR__, 3) . '/admin/lib/gcal.php';
        try {
            if (!gcal_connected($adminId) || gcal_revoked($adminId)) return $vacio;
            $dia = $hoy->format('Y-m-d');
            $out = $vacio;
            $out['conectado'] = true;
            foreach (gcal_events($adminId, $hoy->format('Y-m-01'), $hoy->format('Y-m-t')) as $ev) {
                $e = ['dia' => (string)$ev['dia'], 'titulo' => (string)$ev['titulo'], 'sub' => '', 'tipo' => 'evento', 'hora' => (string)$ev['hora'], 'url' => '/calendario?view=dia&d=' . $ev['dia']];
                $out['calendario'][] = $e;
                if ($ev['dia'] === $dia) $out['hoy'][] = $e + ['todo_el_dia' => (bool)$ev['allday'], 'link' => (string)$ev['link']];
            }
            $porCorreo = [];
            try {
                foreach ($this->pdo->query("SELECT nombre, empresa, email FROM contacts WHERE email IS NOT NULL AND email <> ''") as $r) {
                    $porCorreo[strtolower(trim((string)$r['email']))] = (string)($r['empresa'] ?: $r['nombre']);
                }
            } catch (\PDOException $e) {}
            $reu = [];
            foreach (gcal_meetings_range($adminId, $dia, $hoy->modify('+30 days')->format('Y-m-d')) as $ev) {
                if ($ev['dia'] < $dia) continue;
                $cli = '';
                foreach ($ev['emails'] as $em) if (isset($porCorreo[$em])) { $cli = $porCorreo[$em]; break; }
                $reu[] = ['titulo' => (string)$ev['titulo'], 'dia' => (string)$ev['dia'], 'hora' => (string)$ev['hora'], 'cliente' => $cli, 'meet' => (bool)$ev['meet'], 'link' => (string)$ev['link']];
            }
            usort($reu, fn($a, $b) => strcmp($a['dia'] . $a['hora'], $b['dia'] . $b['hora']));
            $out['reuniones'] = array_slice($reu, 0, 6);
            return $out;
        } catch (\Throwable $e) {
            error_log('Inicio agenda: ' . $e->getMessage());
            return $vacio + ['error' => 'No se ha podido leer tu calendario de Google ahora mismo.'];
        }
    }

    /**
     * Lo que pide atención en los demás módulos, con el mismo alcance que cada
     * pantalla (si no, la cifra del inicio y la de la pantalla no casarían).
     * null = sin permiso para ese módulo (el front no pinta la tarjeta). Un
     * módulo que falle no tumba el panel: su cifra se queda en null.
     */
    private function pulso(Acceso $acc, \DateTimeImmutable $hoy): array
    {
        $p = ['tickets' => null, 'crm_hoy' => null, 'cobros' => null, 'chat_no_leidos' => null];
        $uno = function (string $sql, array $args = []): int {
            $st = $this->pdo->prepare($sql);
            $st->execute($args);
            return (int)$st->fetchColumn();
        };

        if ($acc->puede('ver.soporte')) {
            try {
                $alc = (new SoporteRepositorio($this->pdo))->sqlAlcance($acc);
                $abiertos = "FROM support_tickets t WHERE t.estado IN ('abierto','en_curso','esperando')$alc";
                $p['tickets'] = [
                    'abiertos' => $uno("SELECT COUNT(*) $abiertos"),
                    'sin_asignar' => $uno("SELECT COUNT(*) $abiertos AND t.assignee_id IS NULL"),
                    'mios' => $uno("SELECT COUNT(*) $abiertos AND t.assignee_id = ?", [$acc->adminId]),
                ];
            } catch (\PDOException $e) { error_log('Inicio tickets: ' . $e->getMessage()); }
        }

        /* «Toca contactar hoy»: la misma bandeja de CRM › Reporting. */
        if ($acc->puede('ver.crm')) {
            try {
                $alc = AlcanceCrm::sql($acc, 'c');
                $base = "FROM follow_up_tasks f JOIN contacts c ON c.id = f.contact_id WHERE f.estado = 'pendiente' AND f.fecha_prevista <= ?$alc";
                $p['crm_hoy'] = [
                    'total' => $uno("SELECT COUNT(*) $base", [$hoy->format('Y-m-d')]),
                    'atrasados' => $uno("SELECT COUNT(*) $base AND f.fecha_prevista < ?", [$hoy->format('Y-m-d'), $hoy->format('Y-m-d')]),
                ];
            } catch (\PDOException $e) { error_log('Inicio CRM: ' . $e->getMessage()); }
        }

        /* Pendiente de cobro y vencidas: facturas emitidas (con número), ni
           pagadas ni anuladas. Vencida = marcada por el cron o con la fecha de
           vencimiento ya pasada aunque el cron aún no haya corrido. Los importes
           solo con ver.importes. */
        if ($acc->puede('ver.finanzas')) {
            try {
                $alc = $acc->veTodo() ? '' : ' AND i.client_id IS NOT NULL' . $acc->sqlClientes('i.client_id');
                $st = $this->pdo->prepare('SELECT i.estado, i.fecha_venc, i.iva_pct, i.irpf_pct, ' . Importes::sqlBase('i') . " AS base FROM invoices i
                                           WHERE i.estado IN ('enviada','vencida') AND i.numero IS NOT NULL AND i.numero <> ''$alc");
                $st->execute();
                $dia = $hoy->format('Y-m-d');
                $c = ['pendiente' => ['n' => 0, 'total' => 0], 'vencidas' => ['n' => 0, 'total' => 0]];
                foreach ($st->fetchAll() as $f) {
                    $total = Importes::total(Importes::centimos($f['base']), $f['iva_pct'], $f['irpf_pct']);
                    $c['pendiente']['n']++;
                    $c['pendiente']['total'] += $total;
                    if ($f['estado'] === 'vencida' || ($f['fecha_venc'] && $f['fecha_venc'] < $dia)) {
                        $c['vencidas']['n']++;
                        $c['vencidas']['total'] += $total;
                    }
                }
                if (!$acc->puede('ver.importes')) {
                    $c['pendiente']['total'] = null;
                    $c['vencidas']['total'] = null;
                }
                $p['cobros'] = $c;
            } catch (\PDOException $e) { error_log('Inicio cobros: ' . $e->getMessage()); }
        }

        if ($acc->puede('ver.chat')) {
            try {
                $p['chat_no_leidos'] = (new ChatRepositorio($this->pdo))->noLeidosTotal($acc->adminId);
            } catch (\PDOException $e) { error_log('Inicio chat: ' . $e->getMessage()); }
        }
        return $p;
    }

    private function cobradoMes(Acceso $acc, \DateTimeImmutable $hoy): int
    {
        try {
            $alc = $acc->veTodo() ? '' : ' AND i.client_id IS NOT NULL' . $acc->sqlClientes('i.client_id');
            $st = $this->pdo->prepare('SELECT i.iva_pct, i.irpf_pct, ' . Importes::sqlBase('i') . " AS base FROM invoices i
                                       WHERE i.estado = 'pagada' AND i.numero IS NOT NULL AND i.numero <> ''
                                         AND i.fecha_pago BETWEEN ? AND ?$alc");
            $st->execute([$hoy->format('Y-m-01'), $hoy->format('Y-m-t')]);
            $total = 0;
            foreach ($st->fetchAll() as $f) $total += Importes::total(Importes::centimos($f['base']), $f['iva_pct'], $f['irpf_pct']);
            return $total;
        } catch (\PDOException $e) {
            return 0;
        }
    }

    private function tareas(string $sql): array
    {
        return $this->conAsignados($this->pdo->query($sql)->fetchAll());
    }

    /* Asignados de cada tarea (tabla puente o, si no hay, el responsable). */
    private function conAsignados(array $filas): array
    {
        if (!$filas) return [];
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $filas));
        $por = [];
        foreach ($this->pdo->query("SELECT task_id, admin_id FROM task_assignees WHERE task_id IN ($ids) ORDER BY orden, admin_id") as $a) $por[(int)$a['task_id']][] = (int)$a['admin_id'];
        $nombres = $this->equipo->nombres();
        return array_map(function ($r) use ($por, $nombres) {
            $asig = $por[(int)$r['id']] ?? ($r['responsable_id'] ? [(int)$r['responsable_id']] : []);
            return [
                'id' => (int)$r['id'], 'titulo' => (string)$r['titulo'], 'estado' => (string)$r['estado'], 'prioridad' => (int)$r['prioridad'],
                'due_date' => $r['due_date'], 'cliente' => (string)($r['cname'] ?? ''),
                'asignados' => array_values(array_map(fn($a) => $this->equipo->persona($a), array_filter($asig, fn($a) => isset($nombres[$a])))),
            ];
        }, $filas);
    }
}
