<?php
namespace Croilab\Modulos\Crm;

use Croilab\Correo\Correo;
use Croilab\Correo\Mensaje;
use Croilab\Http\Diferidas;
use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;
use PDO;

/* Seguimientos del CRM (lib/crm_followup.php + automatizaciones.php): reglas
   fijas que preparan llamadas, WhatsApps y emails, la bandeja del día y el
   resumen diario.

   Reglas (idempotentes):
     A) lead nuevo sin contactar → llamar hoy.
     B) negocio en «propuesta» → llamar +2 días, WhatsApp +5, email +9 desde
        que entró en la fase.
     C) negocio perdido con fecha de reactivación cumplida → llamar hoy.
   El antiguo solo miraba si había uno PENDIENTE igual: al «Omitir» uno, la
   siguiente generación lo volvía a crear. Ahora una regla no repite un
   seguimiento que ya existió (en cualquier estado) para el mismo ciclo. */
final class SeguimientosServicio
{
    private const PROPUESTA = [[2, 'llamar', 'Llamar para confirmar recepción de la propuesta'], [5, 'whatsapp', 'WhatsApp de seguimiento de la propuesta'], [9, 'email', 'Email de último intento / cierre']];
    private const DIAS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

    /** @param \Closure(): Correo $correo */
    public function __construct(
        private readonly PDO $pdo,
        private readonly EquipoRepositorio $equipo,
        private readonly Historial $historial,
        private readonly \Closure $correo
    ) {}

    /* ---------- Motor ---------- */

    /** Aplica las reglas. Devuelve cuántos seguimientos nuevos ha creado. */
    public function generar(): int
    {
        $hoy = date('Y-m-d');
        $n = 0;
        $insertar = $this->pdo->prepare("INSERT INTO follow_up_tasks (contact_id, deal_id, tipo_accion, canal, descripcion, fecha_prevista, estado, secuencia_id, ciclo)
                                         VALUES (?,?,?,?,?,?,'pendiente',?,1)");
        $existe = $this->pdo->prepare('SELECT 1 FROM follow_up_tasks WHERE contact_id = ? AND deal_id <=> ? AND canal = ? AND secuencia_id = ? AND fecha_prevista >= ? LIMIT 1');
        $crear = function (int $cid, ?int $did, string $canal, string $desc, string $fecha, string $sec, string $desdeCiclo) use ($insertar, $existe, &$n): void {
            $existe->execute([$cid, $did, $canal, $sec, $desdeCiclo]);
            if ($existe->fetchColumn()) return;
            $insertar->execute([$cid, $did, $canal, $canal, $desc, $fecha, $sec]);
            $n++;
        };

        db_tx_begin($this->pdo);
        try {
            foreach ($this->pdo->query("SELECT id FROM contacts WHERE fase = 'lead_nuevo' AND fecha_ultimo_contacto IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                $crear((int)$cid, null, 'llamar', 'Primer contacto con el lead nuevo', $hoy, 'lead_nuevo', '1000-01-01');
            }
            foreach ($this->pdo->query("SELECT id, contact_id, fecha_entrada_fase FROM deals WHERE fase = 'propuesta' AND archivado = 0")->fetchAll() as $d) {
                $base = $d['fecha_entrada_fase'] ?: $hoy;
                foreach (self::PROPUESTA as [$dias, $canal, $desc]) {
                    $crear((int)$d['contact_id'], (int)$d['id'], $canal, $desc, date('Y-m-d', strtotime("$base +$dias days")), 'propuesta', $base);
                }
            }
            foreach ($this->pdo->query("SELECT id, contact_id, fecha_reactivacion FROM deals WHERE fase = 'perdido' AND archivado = 0
                                         AND fecha_reactivacion IS NOT NULL AND fecha_reactivacion <= CURDATE()")->fetchAll() as $d) {
                $crear((int)$d['contact_id'], (int)$d['id'], 'llamar', 'Reactivar: revisar si es buen momento ahora', $hoy, 'reactivacion', (string)$d['fecha_reactivacion']);
            }
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            throw $e;
        }
        db_tx_commit($this->pdo);
        return $n;
    }

    /* ---------- Bandeja ---------- */

    public function bandeja(Acceso $acc): array
    {
        $acc->exigir('ver.crm');
        $alc = Alcance::sql($acc);
        $hoy = date('Y-m-d');
        $grupos = array_fill_keys(array_keys(Catalogos::CANALES), []);
        foreach ($this->filas("SELECT f.*, c.nombre c_nombre, c.empresa c_empresa, c.sector c_sector, c.telefono c_tel, c.whatsapp c_wa, c.email c_email
                                 FROM follow_up_tasks f JOIN contacts c ON c.id = f.contact_id
                                WHERE f.estado = 'pendiente' AND f.fecha_prevista <= CURDATE() $alc ORDER BY f.fecha_prevista, f.id") as $r) {
            $grupos[isset($grupos[$r['canal']]) ? $r['canal'] : 'llamar'][] = $this->item($r, $hoy);
        }
        $proximos = array_map(fn($r) => $this->item($r, $hoy), $this->filas("SELECT f.*, c.nombre c_nombre, c.empresa c_empresa, c.sector c_sector, c.telefono c_tel, c.whatsapp c_wa, c.email c_email
                                 FROM follow_up_tasks f JOIN contacts c ON c.id = f.contact_id
                                WHERE f.estado = 'pendiente' AND f.fecha_prevista > CURDATE() $alc ORDER BY f.fecha_prevista, f.id LIMIT 40"));
        $hechos = (int)$this->pdo->query("SELECT COUNT(*) FROM follow_up_tasks f JOIN contacts c ON c.id = f.contact_id
                                          WHERE f.estado = 'hecha' AND f.fecha_hecha = CURDATE() $alc")->fetchColumn();
        $ult = $this->pdo->query('SELECT fecha_ejecucion, n_acciones, enviado FROM email_log ORDER BY id DESC LIMIT 1')->fetch();
        return [
            'hoy' => $hoy,
            'grupos' => array_map(fn($k, $items) => ['canal' => $k, 'titulo' => Catalogos::CANALES[$k][0], 'color' => Catalogos::CANALES[$k][2], 'items' => $items], array_keys($grupos), $grupos),
            'pendientes_hoy' => array_sum(array_map('count', $grupos)),
            'hechos_hoy' => $hechos,
            'proximos' => $proximos,
            'ultimo_resumen' => $ult ? ['fecha' => (string)$ult['fecha_ejecucion'], 'n_acciones' => (int)$ult['n_acciones'], 'enviado' => (int)$ult['enviado']] : null,
            'cron' => $this->cron(),
        ];
    }

    private function item(array $r, string $hoy): array
    {
        return [
            'id' => (int)$r['id'], 'contact_id' => (int)$r['contact_id'], 'deal_id' => $r['deal_id'] !== null ? (int)$r['deal_id'] : null,
            'canal' => (string)$r['canal'], 'descripcion' => (string)$r['descripcion'], 'fecha_prevista' => (string)$r['fecha_prevista'],
            'estado' => (string)$r['estado'], 'secuencia' => (string)($r['secuencia_id'] ?? ''), 'vencida' => (string)$r['fecha_prevista'] < $hoy,
            'contacto' => ['nombre' => (string)$r['c_nombre'], 'empresa' => (string)($r['c_empresa'] ?? ''), 'sector' => (string)($r['c_sector'] ?? ''),
                'telefono' => (string)($r['c_tel'] ?? ''), 'whatsapp' => (string)($r['c_wa'] ?? ''), 'email' => (string)($r['c_email'] ?? '')],
        ];
    }

    /** Estado del cron (cron_log): funcionando si el último ciclo fue hace menos de 25 h. */
    private function cron(): array
    {
        $ultima = null;
        $tareas = [];
        try {
            $ultima = $this->pdo->query("SELECT created_at FROM cron_log WHERE tarea = 'ciclo' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null;
            $tareas = $this->pdo->query("SELECT c.tarea, c.ok, c.detalle, c.created_at FROM cron_log c
                                          JOIN (SELECT tarea, MAX(id) mid FROM cron_log WHERE tarea <> 'ciclo' GROUP BY tarea) u ON u.mid = c.id
                                         ORDER BY c.created_at DESC LIMIT 12")->fetchAll();
        } catch (\PDOException) {
            /* Sin tabla de registro: el cron nunca se ha montado. */
        }
        $estado = $ultima === null ? 'sin_configurar' : (time() - strtotime((string)$ultima) < 25 * 3600 ? 'funcionando' : 'parado');
        return [
            'estado' => $estado, 'ultima' => $ultima,
            'tareas' => array_map(fn($t) => ['tarea' => (string)$t['tarea'], 'ok' => (int)$t['ok'] === 1, 'detalle' => (string)$t['detalle'], 'fecha' => (string)$t['created_at']], $tareas),
            'linea' => '*/15 * * * * php ' . str_replace('\\', '/', dirname(__DIR__, 3)) . '/bin/cron.php',
        ];
    }

    /* ---------- Acciones ---------- */

    public function crear(Acceso $acc, array $d, ContactosServicio $contactos): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.crear');
        $cid = filter_var($d['contact_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$cid) throw HttpError::validacion('Elige un contacto.', 'contact_id');
        try {
            $contactos->visible($acc, $cid);
        } catch (HttpError) {
            throw HttpError::validacion('Ese contacto no existe.', 'contact_id');
        }
        $canal = is_string($d['canal'] ?? null) && isset(Catalogos::CANALES[$d['canal']]) ? $d['canal'] : '';
        if ($canal === '') throw HttpError::validacion('Canal desconocido.', 'canal');
        $desc = is_scalar($d['descripcion'] ?? null) ? trim((string)$d['descripcion']) : '';
        if ($desc === '') throw HttpError::validacion('Escribe qué hay que hacer.', 'descripcion');
        if (mb_strlen($desc) > 255) throw HttpError::validacion('La descripción es demasiado larga.', 'descripcion');
        $fecha = ($d['fecha'] ?? '') === '' || ($d['fecha'] ?? null) === null ? date('Y-m-d') : Filtros::fecha(is_string($d['fecha']) ? $d['fecha'] : '', 'fecha');
        $this->pdo->prepare("INSERT INTO follow_up_tasks (contact_id, deal_id, tipo_accion, canal, descripcion, fecha_prevista, estado, secuencia_id, ciclo)
                             VALUES (?, NULL, ?, ?, ?, ?, 'pendiente', 'manual', 99)")->execute([$cid, $canal, $canal, $desc, $fecha]);
        return $this->bandeja($acc);
    }

    public function hecho(Acceso $acc, int $id): void
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $f = $this->pendiente($acc, $id);
        $this->pdo->prepare("UPDATE follow_up_tasks SET estado = 'hecha', fecha_hecha = CURDATE() WHERE id = ?")->execute([$id]);
        /* El antiguo anotaba el slug del canal («llamar»); el historial usa «llamada». */
        $tipo = Catalogos::CANALES[$f['canal']][1] ?? 'nota';
        $this->historial->anotar((int)$f['contact_id'], $f['deal_id'] !== null ? (int)$f['deal_id'] : null, $tipo, 'Seguimiento hecho: ' . $f['descripcion']);
        $this->historial->contactado((int)$f['contact_id']);
    }

    public function posponer(Acceso $acc, int $id, mixed $dias): void
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $this->pendiente($acc, $id);
        $n = filter_var($dias, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 90]]);
        if ($n === false) throw HttpError::validacion('Se pospone de 1 a 90 días.', 'dias');
        $this->pdo->prepare('UPDATE follow_up_tasks SET fecha_prevista = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE id = ?')->execute([$n, $id]);
    }

    public function omitir(Acceso $acc, int $id): void
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $this->pendiente($acc, $id);
        $this->pdo->prepare("UPDATE follow_up_tasks SET estado = 'omitida' WHERE id = ?")->execute([$id]);
    }

    private function pendiente(Acceso $acc, int $id): array
    {
        $st = $this->pdo->prepare('SELECT f.* FROM follow_up_tasks f JOIN contacts c ON c.id = f.contact_id WHERE f.id = ?' . Alcance::sql($acc));
        $st->execute([$id]);
        $f = $st->fetch() ?: throw HttpError::noEncontrado('Seguimiento no encontrado.');
        if ($f['estado'] !== 'pendiente') throw new HttpError(409, 'Ese seguimiento ya no está pendiente.', 'conflicto');
        return $f;
    }

    /* ---------- Resumen diario ---------- */

    /** Lo que lleva el resumen de hoy (vista previa: no genera nada, solo lee). */
    public function resumen(?Acceso $acc = null): array
    {
        $acc?->exigir('ver.crm');
        $alc = $acc ? Alcance::sql($acc) : '';
        $grupos = [];
        foreach (Catalogos::CANALES as $k => [$titulo, , $color]) $grupos[$k] = ['canal' => $k, 'titulo' => $titulo, 'color' => $color, 'items' => []];
        foreach ($this->filas("SELECT f.canal, f.descripcion, c.nombre, c.empresa, c.sector, c.telefono, c.whatsapp, c.email
                                 FROM follow_up_tasks f JOIN contacts c ON c.id = f.contact_id
                                WHERE f.estado = 'pendiente' AND f.fecha_prevista <= CURDATE() $alc ORDER BY f.fecha_prevista, f.id") as $r) {
            $k = isset($grupos[$r['canal']]) ? $r['canal'] : 'llamar';
            $dato = $k === 'whatsapp' ? ($r['whatsapp'] ?: $r['telefono']) : ($k === 'email' ? $r['email'] : $r['telefono']);
            $grupos[$k]['items'][] = ['nombre' => (string)$r['nombre'], 'dato' => (string)($dato ?? ''),
                'sub' => implode(' · ', array_filter([(string)($r['empresa'] ?? ''), (string)($r['sector'] ?? '')])), 'descripcion' => (string)$r['descripcion']];
        }
        $grupos = array_values(array_filter($grupos, fn($g) => $g['items']));
        return [
            'fecha' => date('Y-m-d'), 'dia' => self::DIAS[(int)date('N') - 1],
            'total' => array_sum(array_map(fn($g) => count($g['items']), $grupos)),
            'grupos' => $grupos, 'agencia' => $this->agencia(),
        ];
    }

    /**
     * Ejecuta el resumen: genera, avisa a quien tiene acceso total (aviso del
     * ERP y, si tiene correo, email de verdad) y lo anota en email_log. Una vez
     * por día laborable salvo que se fuerce.
     * @return array{sent: bool, acciones: int, reason: string, correos: int}
     */
    public function ejecutarResumen(bool $forzar, bool $diferirCorreo): array
    {
        if (!$forzar && (int)date('N') >= 6) return ['sent' => false, 'acciones' => 0, 'reason' => 'fin_de_semana', 'correos' => 0];
        if (!$forzar && (int)$this->pdo->query('SELECT COUNT(*) FROM email_log WHERE DATE(fecha_ejecucion) = CURDATE()')->fetchColumn() > 0) {
            return ['sent' => false, 'acciones' => 0, 'reason' => 'ya_enviado', 'correos' => 0];
        }
        $this->generar();
        $r = $this->resumen();
        $n = $r['total'];
        $dest = $this->destinatarios();
        $avisados = 0;
        $correos = [];
        if ($n > 0) {
            $hoy = date('Y-m-d');
            foreach ($dest as $p) {
                if (function_exists('notif_add')) {
                    notif_add($p['id'], 'bell', 'Resumen de seguimientos de hoy', $n . ' seguimiento(s) que tocan hoy: llamadas, WhatsApp y emails del CRM.',
                        '/crm/reporting', 'digest:' . $hoy . ':' . $p['id'], '', 'Sistema');
                }
                $avisados++;
                if ($p['email'] !== '' && filter_var($p['email'], FILTER_VALIDATE_EMAIL)) $correos[] = $p['email'];
            }
        }
        $this->pdo->prepare('INSERT INTO email_log (enviado, n_acciones, destinatarios) VALUES (?,?,?)')
            ->execute([$avisados, $n, mb_substr(implode(',', array_column($dest, 'username')), 0, 255)]);

        if ($correos) {
            $asunto = 'Seguimientos de hoy · ' . $n . ' acción' . ($n === 1 ? '' : 'es');
            $html = self::html($r);
            $texto = self::texto($r);
            $enviar = function () use ($correos, $asunto, $html, $texto): void {
                $c = ($this->correo)();
                foreach ($correos as $para) {
                    try {
                        $c->enviar(new Mensaje($para, $asunto, $texto, $html));
                    } catch (\Throwable $e) {
                        error_log('CRM resumen diario a ' . $para . ': ' . $e->getMessage());
                    }
                }
            };
            if ($diferirCorreo) Diferidas::agregar($enviar);
            else {
                try {
                    $enviar();
                } catch (\Throwable $e) {
                    error_log('CRM resumen diario: ' . $e->getMessage());
                }
            }
        }
        return ['sent' => $avisados > 0, 'acciones' => $n, 'reason' => $avisados > 0 ? 'ok' : ($n > 0 ? 'sin_dest' : 'nada_hoy'), 'correos' => count($correos)];
    }

    /* Quien tiene acceso total (el antiguo miraba role='owner' a pelo). */
    private function destinatarios(): array
    {
        $roles = [];
        if (function_exists('roles_todos')) {
            foreach (roles_todos() as $k => $r) if (in_array('admin.total', $r['permisos'] ?? [], true)) $roles[] = (string)$k;
        }
        if (!$roles) $roles = ['owner'];
        $st = $this->pdo->prepare('SELECT id, username, email FROM admins WHERE activo = 1 AND role IN (' . implode(',', array_fill(0, count($roles), '?')) . ') ORDER BY id');
        $st->execute($roles);
        return array_map(fn($a) => ['id' => (int)$a['id'], 'username' => (string)$a['username'], 'email' => trim((string)($a['email'] ?? ''))], $st->fetchAll());
    }

    private function agencia(): string
    {
        if (function_exists('marca_agencia')) {
            try {
                return (string)(marca_agencia()['name'] ?? 'Croilab');
            } catch (\Throwable) {
            }
        }
        return 'Croilab';
    }

    /** HTML del correo (estilos en línea: los clientes de correo no leen hojas de estilo). */
    public static function html(array $r): string
    {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        [$y, $m, $d] = explode('-', $r['fecha']);
        $total = $r['total'];
        $h = '<div style="font-family:Inter,-apple-system,Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;color:#22262c">'
            . '<div style="margin-bottom:24px"><div style="font-size:11.5px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:#a4a9b1">' . $e($r['dia']) . " · $d/$m/$y</div>"
            . '<div style="font-size:23px;font-weight:750;letter-spacing:-.4px;margin-top:5px">Seguimientos de hoy</div>'
            . '<div style="font-size:14px;color:#6b7079;margin-top:4px">' . ($total === 0 ? 'Nada pendiente por ahora.' : $total . ' acción' . ($total === 1 ? '' : 'es') . ' por hacer.') . '</div></div>';
        if ($total === 0) {
            $h .= '<div style="background:#f0faf4;border:1px solid #cdeede;border-radius:14px;padding:28px;text-align:center;color:#1a9d5b;font-size:15px;font-weight:650">✓ No hay seguimientos pendientes hoy.</div>';
        }
        foreach ($r['grupos'] as $g) {
            $c = $e($g['color']);
            $h .= '<div style="margin:0 0 9px"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . $c . ';vertical-align:middle;margin-right:9px"></span>'
                . '<span style="font-size:12px;font-weight:800;letter-spacing:.5px;color:' . $c . ';vertical-align:middle">' . $e($g['titulo']) . '</span>'
                . '<span style="font-size:12px;font-weight:700;color:#c0c4cb;margin-left:8px;vertical-align:middle">' . count($g['items']) . '</span></div>'
                . '<div style="border:1px solid #ececee;border-radius:14px;overflow:hidden;margin-bottom:22px">';
            foreach ($g['items'] as $i => $it) {
                $h .= '<div style="' . ($i > 0 ? 'border-top:1px solid #f4f4f5;' : '') . 'padding:13px 16px">'
                    . '<div style="font-weight:650;font-size:14.5px;color:#22262c">' . $e($it['nombre']) . ($it['dato'] !== '' ? ' <span style="color:#a4a9b1;font-weight:500;font-size:12.5px">' . $e($it['dato']) . '</span>' : '') . '</div>'
                    . ($it['sub'] !== '' ? '<div style="color:#a4a9b1;font-size:12px;margin-top:2px">' . $e($it['sub']) . '</div>' : '')
                    . '<div style="font-size:13px;color:#3c4149;margin-top:5px;line-height:1.4">' . $e($it['descripcion']) . '</div></div>';
            }
            $h .= '</div>';
        }
        return $h . '<div style="border-top:1px solid #f2f2f3;margin-top:6px;padding-top:15px;color:#c0c4cb;font-size:11px">' . $e($r['agencia']) . ' · CRM · resumen automático diario</div></div>';
    }

    public static function texto(array $r): string
    {
        $t = "Seguimientos de hoy ({$r['dia']} " . date('d/m/Y', strtotime($r['fecha'])) . ")\n\n";
        if ($r['total'] === 0) return $t . "No hay seguimientos pendientes hoy.\n";
        foreach ($r['grupos'] as $g) {
            $t .= $g['titulo'] . ' (' . count($g['items']) . ")\n";
            foreach ($g['items'] as $it) $t .= '· ' . $it['nombre'] . ($it['dato'] !== '' ? ' — ' . $it['dato'] : '') . ': ' . $it['descripcion'] . "\n";
            $t .= "\n";
        }
        return $t . $r['agencia'] . " · CRM · resumen automático diario\n";
    }

    private function filas(string $sql, array $p = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }
}
