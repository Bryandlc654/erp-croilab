<?php
namespace Croilab\Modulos\Portal;

use Croilab\Http\HttpError;
use Croilab\Modulos\Clientes\ContenidoPortal;
use Croilab\Modulos\Comunicacion\Soporte\SoporteServicio;
use Croilab\Modulos\Equipo\BovedaServicio;
use Croilab\Modulos\Finanzas\HojaFactura;
use Croilab\Modulos\Finanzas\PdfFactura;
use PDO;

/* El portal del cliente: lo que ve (todo de una vez, como el antiguo, que lo
   inyectaba en la página) y lo poco que puede hacer (escribir a soporte, pedir
   una reunión, abrir sus facturas, ver una contraseña suya).

   Cada método recibe el id del cliente: el controlador lo saca de la sesión del
   cliente o, en la vista previa, del cliente que el equipo puede ver (alcance
   comprobado). Solo sale lo que está marcado para el cliente. Lo de otros
   módulos se pide a sus servicios (Finanzas: la hoja y el PDF de la factura;
   Comunicación: el ticket; Equipo: el secreto de la bóveda). */
class PortalServicio
{
    private const FRANJAS = ['', 'Sin preferencia', 'Por la mañana', 'Al mediodía', 'Por la tarde'];
    private const VIDEO_DEFECTO = 'J9-aEZ523bA';

    /**
     * @param \Closure(): HojaFactura $hoja
     * @param \Closure(): SoporteServicio $soporte
     * @param \Closure(): BovedaServicio $boveda
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly PortalRepositorio $repo,
        private readonly \Closure $hoja,
        private readonly \Closure $soporte,
        private readonly \Closure $boveda,
        private readonly ?\DateTimeImmutable $hoy = null
    ) {}

    private function hoy(): \DateTimeImmutable
    {
        return $this->hoy ?? new \DateTimeImmutable('today');
    }

    private function fila(int $clientId): array
    {
        return $this->repo->cliente($clientId) ?? throw HttpError::noEncontrado('Cliente no encontrado.');
    }

    /** Todo lo que pinta el portal. $vistaPrevia = lo abre el equipo (no se puede enviar nada). */
    public function datos(int $clientId, bool $vistaPrevia = false): array
    {
        $c = $this->fila($clientId);
        $hoy = $this->hoy();
        /* Las métricas solo existen hasta el mes de la última sincronización; el
           trabajo y los informes pueden ir un mes por delante (se planifican). */
        $refMet = $c['met_sync_at'] ? new \DateTimeImmutable(substr((string)$c['met_sync_at'], 0, 10)) : $hoy;
        if ($refMet > $hoy) $refMet = $hoy;
        $refTrabajo = $hoy->modify('first day of next month');

        $tipo = $c['tipo_id'] !== null && $c['secciones_json'] !== null ? (json_decode((string)$c['secciones_json'], true) ?: []) : null;
        $secciones = Contenido::secciones(is_array($tipo) ? $tipo : null, (int)$c['conversiones'] === 1);
        $metricas = Contenido::metricas($c['met_json'], $refMet);
        $actual = Meses::clave((string)$c['actual'], $refTrabajo);
        if ($actual === null && $metricas) $actual = end($metricas)['clave'];
        $actual ??= $hoy->format('Y-m');

        $pid = (int)($c['partner_id'] ?? 0);
        $marca = function_exists('marca_partner') ? marca_partner($pid) : ['name' => 'Croilab', 'initial' => 'C', 'logo' => '', 'color' => '', 'web' => '', 'propia' => true];
        $contacto = function_exists('marca_contacto') ? marca_contacto($pid) : ['meeting_url' => '', 'whatsapp' => '', 'email' => '', 'telefono' => ''];

        return [
            'cliente' => PortalAuthServicio::resumen($c) + ['username' => (string)$c['username'], 'actual' => $actual, 'actual_etiqueta' => Meses::etiqueta($actual)],
            'vista_previa' => $vistaPrevia,
            'puede_enviar' => !$vistaPrevia,
            'secciones' => $secciones,
            'marca' => self::marca($marca),
            'contacto' => [
                'meeting_url' => self::url((string)$contacto['meeting_url']),
                'whatsapp' => preg_replace('/\D+/', '', (string)$contacto['whatsapp']),
                'email' => filter_var((string)$contacto['email'], FILTER_VALIDATE_EMAIL) ? (string)$contacto['email'] : '',
                'telefono' => (string)$contacto['telefono'],
            ],
            'videos' => $this->videos(),
            'catalogo' => $this->catalogo(),
            'servicios' => ContenidoPortal::leerServicios($c['servicios_json']),
            'estado' => ContenidoPortal::leerEstado($c['estado_json']),
            'plan' => ContenidoPortal::leerPlan($c['plan_json']),
            'accesos' => array_map(fn($a) => ['u' => self::url($a['u'])] + $a, ContenidoPortal::leerAccesos($c['accesos_json'])),
            'looker' => $secciones['metricas'] ? Contenido::lookerSeguro($c['looker_url']) : '',
            /* Lo que el tipo de cliente oculta no se manda. */
            'metricas' => $secciones['metricas'] ? $metricas : [],
            'progreso' => $secciones['progreso'] ? Contenido::progreso($c['tareas_json'], $refTrabajo) : [],
            'informes' => $secciones['informes'] ? Contenido::informes($c['informes_json'], $refTrabajo) : [],
            'tareas' => $this->tareas($clientId, $refTrabajo),
            'facturas' => $this->repo->facturas($clientId),
            'reuniones' => $this->repo->reuniones($c['contact_id'] !== null ? (int)$c['contact_id'] : null),
            'solicitudes' => $this->repo->solicitudes($clientId),
            'tickets' => $this->repo->tickets($clientId),
            'credenciales' => $secciones['accesos'] ? $this->repo->credenciales($clientId) : [],
        ];
    }

    /** Las tareas reales que ve el cliente, por mes (lo más nuevo arriba), con quién las lleva. */
    private function tareas(int $clientId, \DateTimeImmutable $ref): array
    {
        $filas = $this->repo->tareas($clientId);
        $personas = $this->repo->personas(array_merge(...array_map(fn($t) => $t['asignados'], $filas ?: [['asignados' => []]])));
        $fotos = $this->fotos($personas);
        $out = [];
        foreach ($filas as $t) {
            $clave = Meses::clave((string)$t['mes'], $ref);
            $mes = trim((string)$t['mes']);
            $out[] = [
                'id' => (int)$t['id'],
                'titulo' => trim((string)$t['titulo_cliente']) !== '' ? (string)$t['titulo_cliente'] : (string)$t['titulo'],
                /* Solo la explicación para el cliente: la descripción interna no sale nunca (el antiguo caía a ella). */
                'texto' => (string)$t['explicacion_cliente'],
                'estado' => (string)$t['estado'],
                'prioridad' => (int)$t['prioridad'],
                'clave' => $clave,
                'mes' => $clave ? Meses::etiqueta($clave) : ($mes !== '' ? $mes : 'Sin mes'),
                'due' => $t['due_date'] ?: null,
                'asignados' => array_values(array_filter(array_map(fn($id) => isset($personas[$id]) ? $personas[$id] + ['foto' => $fotos[$id] ?? null] : null, $t['asignados']))),
            ];
        }
        return Meses::ordenar($out, true);
    }

    /**
     * Fotos de perfil como data: URL. archivo.php solo sirve a la sesión del
     * equipo; el cliente las recibe incrustadas (pequeñas y solo las de quien
     * lleva sus tareas).
     */
    private function fotos(array $personas): array
    {
        $out = [];
        $dir = realpath(__DIR__ . '/../../../uploads/avatars');
        if (!$dir) return [];
        $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        foreach ($personas as $id => $p) {
            $f = basename((string)$p['foto']);
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if ($f === '' || !isset($mimes[$ext])) continue;
            $ruta = realpath($dir . DIRECTORY_SEPARATOR . $f);
            if (!$ruta || !str_starts_with($ruta, $dir . DIRECTORY_SEPARATOR) || !is_file($ruta) || filesize($ruta) > 150_000) continue;
            $out[$id] = 'data:' . $mimes[$ext] . ';base64,' . base64_encode((string)file_get_contents($ruta));
        }
        return $out;
    }

    private function videos(): array
    {
        $general = function_exists('svc_video_id') ? svc_video_id((string)get_setting('video_id', '')) : '';
        $serv = function_exists('svc_videos') ? (array)svc_videos() : [];
        return ['general' => $general !== '' ? $general : self::VIDEO_DEFECTO, 'servicios' => (object)$serv];
    }

    private function catalogo(): array
    {
        if (!function_exists('svc_catalogo')) return [];
        return array_map(fn($s) => ['nombre' => (string)$s['nombre'], 'desc' => (string)$s['desc']], svc_catalogo());
    }

    private static function marca(array $m): array
    {
        $color = (string)$m['color'];
        return [
            'name' => (string)$m['name'], 'initial' => (string)$m['initial'],
            /* El logo y el color se pintan en el portal: solo URLs http(s) y colores #hex. */
            'logo' => self::url((string)$m['logo']),
            'color' => preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color) ? $color : '',
            'web' => self::url((string)$m['web']), 'propia' => (bool)$m['propia'],
        ];
    }

    private static function url(string $u): string
    {
        $u = trim($u);
        if ($u === '' || $u === '#') return '';
        $p = parse_url($u);
        return is_array($p) && in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) && !empty($p['host']) ? $u : '';
    }

    /** Marca para la pantalla de acceso (sin sesión): ?m=<agencia>. */
    public static function marcaAcceso(int $agencia): array
    {
        if (!function_exists('marca_partner')) return ['name' => 'Croilab', 'initial' => 'C', 'logo' => '', 'color' => '', 'web' => '', 'propia' => true];
        return self::marca(marca_partner(max(0, $agencia)));
    }

    /* ---------- Acciones del cliente ---------- */

    /** «Escríbenos»: ticket nuevo para el equipo (con aviso, lo pone Comunicación). */
    public function escribir(int $clientId, array $d): array
    {
        $c = $this->fila($clientId);
        $asunto = ContenidoPortal::texto($d['asunto'] ?? '', 120, 'asunto');
        $cuerpo = is_string($d['cuerpo'] ?? null) ? trim($d['cuerpo']) : '';
        if ($cuerpo === '') throw HttpError::validacion('Escribe tu mensaje.', 'cuerpo');
        if (mb_strlen($cuerpo) > 10000) throw HttpError::validacion('El mensaje es demasiado largo (máximo 10.000 caracteres).', 'cuerpo');
        $id = ($this->soporte)()->crearDesdePortal($clientId, $asunto, $cuerpo, (string)$c['name']);
        return $this->repo->ticket($clientId, $id) ?? throw HttpError::noEncontrado('Mensaje no encontrado.');
    }

    public function ticket(int $clientId, int $id): array
    {
        return $this->repo->ticket($clientId, $id) ?? throw HttpError::noEncontrado('No encontramos ese mensaje.');
    }

    /** «Solicitar reunión»: queda pendiente hasta que el equipo la confirma en Reuniones. */
    public function solicitarReunion(int $clientId, array $d): array
    {
        $c = $this->fila($clientId);
        $motivo = is_string($d['motivo'] ?? null) ? trim($d['motivo']) : '';
        if ($motivo === '') throw HttpError::validacion('Cuéntanos brevemente el motivo.', 'motivo');
        if (mb_strlen($motivo) > 2000) throw HttpError::validacion('El motivo es demasiado largo (máximo 2.000 caracteres).', 'motivo');
        $fecha = is_string($d['fecha'] ?? null) ? trim($d['fecha']) : '';
        if ($fecha !== '') {
            $f = \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
            if (!$f || $f->format('Y-m-d') !== $fecha) throw HttpError::validacion('La fecha no es válida.', 'fecha');
            if ($f < $this->hoy()) throw HttpError::validacion('Elige un día a partir de hoy.', 'fecha');
        }
        $franja = is_string($d['franja'] ?? null) ? trim($d['franja']) : '';
        if (!in_array($franja, self::FRANJAS, true)) throw HttpError::validacion('Franja no válida.', 'franja');
        if ($franja === 'Sin preferencia') $franja = '';
        /* Freno: nadie llena la bandeja del equipo de solicitudes. */
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM portal_meeting_requests WHERE client_id = ? AND estado = 'pendiente'");
        $st->execute([$clientId]);
        if ((int)$st->fetchColumn() >= 10) throw new HttpError(429, 'Ya tienes varias solicitudes pendientes. Te escribimos en cuanto las revisemos.', 'demasiadas');

        $s = $this->repo->crearSolicitud($clientId, $fecha !== '' ? $fecha : null, $franja, $motivo);
        /* El antiguo no avisaba: quedaba esperando a que alguien abriera Reuniones.
           Misma ref que notif_sync_meeting_requests (no se duplica). */
        if (function_exists('notif_duenos') && function_exists('notif_add')) {
            foreach (notif_duenos() as $uid) {
                notif_add($uid, 'info', 'Nueva solicitud de reunión', ((string)$c['name'] ?: 'Un cliente') . ' ha pedido una reunión desde su portal', '/reuniones', 'meetreq:' . $s['id']);
            }
        }
        return $s;
    }

    /** La hoja de una factura suya (emitida). */
    public function factura(int $clientId, int $id): array
    {
        return ($this->hoja)()->paraCliente($clientId, $id);
    }

    /** {nombre, mime, base64} del PDF de una factura suya. */
    public function pdf(int $clientId, int $id): array
    {
        $h = $this->factura($clientId, $id);
        $nombre = ($h['tipo'] === 'rectificativa' ? 'Rectificativa ' : 'Factura ') . ($h['numero'] ?? '') . '.pdf';
        return ['nombre' => preg_replace('/[^\w .\-]/u', '_', $nombre), 'mime' => 'application/pdf', 'base64' => base64_encode(PdfFactura::generar($h))];
    }

    /** Contraseña de una credencial visible para el cliente, bajo demanda (y auditada). */
    public function secreto(int $clientId, int $credId): string
    {
        $v = ($this->boveda)()->secretoEnClaro($credId, $clientId);
        if ($v === null) throw HttpError::noEncontrado('Credencial no encontrada.');
        if (function_exists('audit_log')) audit_log('portal.credencial', "cliente #$clientId · credencial #$credId");
        return $v;
    }
}
