<?php
namespace Croilab\Modulos\Equipo;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Ajustes de la agencia (settings.php): identidad y marca, contacto y vídeos
   del portal, y reglas automáticas. Todo es la tabla `settings` (clave/valor)
   y cada cambio queda en audit_log (audit_setting).

   Permisos: leer `ver.ajustes`; escribir `ajustes.editar` (el antiguo dejaba
   la identidad solo al Dueño con is_owner() aunque existiera este permiso). */
class AjustesServicio
{
    public const MAX_LOGO = 2 * 1048576;

    private const AGENCIA = [
        'agency_name' => ['nombre', 120, 'El nombre'],
        'agency_cif' => ['cif', 30, 'El CIF / NIF'],
        'agency_email' => ['email', 160, 'El email'],
        'agency_phone' => ['telefono', 40, 'El teléfono'],
        'agency_web' => ['web', 200, 'La web'],
        'agency_address' => ['direccion', 300, 'La dirección'],
        'agency_logo' => ['logo', 400, 'El logo'],
        'agency_color' => ['color', 7, 'El color'],
    ];

    /* Reglas del cron (`auto_<clave>`: '0' apagada, cualquier otro valor encendida). */
    public const REGLAS = [
        'lead_reminder' => ['Recordar leads', 'Avisa cuando toca contactar a un lead del CRM.'],
        'invoice_due' => ['Facturas vencidas', 'Avisa de las facturas que han pasado su vencimiento sin cobrarse.'],
        'monthly_report' => ['Aviso de informe mensual', 'Los primeros días de cada mes recuerda preparar los informes de los clientes.'],
        'invoice_recurring' => ['Facturas recurrentes', 'Emite solas las facturas programadas cuando les toca.'],
        'followups' => ['Seguimientos del CRM', 'Lanza los seguimientos automáticos configurados en el CRM.'],
        'daily_digest' => ['Resumen diario', 'Manda cada mañana un resumen de lo que hay pendiente.'],
        'trash_purge' => ['Vaciar la papelera', 'Borra para siempre lo que lleva más de 30 días en la papelera.'],
        'uploads_sweep' => ['Borrar archivos sin uso', 'Quita del servidor los archivos subidos que ya no usa nadie.'],
    ];

    public function __construct(private readonly PDO $pdo, private readonly Subidas $subidas) {}

    private function poner(string $clave, string $valor): void
    {
        $antes = (string)get_setting($clave, '');
        if ($antes === $valor) return;
        $this->pdo->prepare('INSERT INTO settings (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')->execute([$clave, $valor]);
        if (function_exists('audit_setting')) audit_setting($clave, mb_substr($antes, 0, 100), mb_substr($valor, 0, 100));
    }

    /* ---------- Agencia ---------- */

    public function agencia(Acceso $acc): array
    {
        $acc->exigir('ver.ajustes');
        $s = get_settings(array_keys(self::AGENCIA));
        $out = [];
        foreach (self::AGENCIA as $k => [$campo]) $out[$campo] = trim((string)$s[$k]);
        if ($out['nombre'] === '') $out['nombre'] = 'Croilab';
        return ['agencia' => $out, 'puede_editar' => $acc->puede('ajustes.editar')];
    }

    /** {nombre, cif, email, telefono, web, direccion, logo, color} (parcial) */
    public function guardarAgencia(Acceso $acc, array $d): array
    {
        $acc->exigir('ajustes.editar');
        foreach (self::AGENCIA as $clave => [$campo, $max, $etiqueta]) {
            if (!array_key_exists($campo, $d)) continue;
            $v = Validar::texto($d[$campo], $max, $campo, $etiqueta);
            if ($campo === 'nombre' && $v === '') throw HttpError::validacion('Pon el nombre de la agencia.', 'nombre');
            if ($campo === 'email' && $v !== '') $v = (string)Validar::email($v, 'email', 'El email no tiene un formato válido.');
            if ($campo === 'web' && $v !== '' && !preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
            if ($campo === 'web') $v = Validar::url($v, $max, 'web', 'La web');
            if ($campo === 'logo') $v = $this->validarLogo($v);
            if ($campo === 'color' && $v !== '') {
                if (!preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', $v)) throw HttpError::validacion('El color tiene que ser un código como #1f232a.', 'color');
                $v = '#' . strtolower(ltrim($v, '#'));
            }
            $this->poner($clave, $v);
        }
        return $this->agencia($acc);
    }

    /* Una URL https (o la ruta de un logo subido aquí). Nada de javascript:, data:… */
    private function validarLogo(string $v): string
    {
        if ($v === '' || str_starts_with($v, self::rutaPublicaLogos())) return $v;
        return Validar::url($v, 400, 'logo', 'El logo');
    }

    /** Dirección pública de los logos subidos: APP_URL/uploads/marca/ (o relativa si no hay APP_URL). */
    public static function rutaPublicaLogos(): string
    {
        return \Croilab\Google\GoogleOAuth::appUrl() . '/uploads/marca/';
    }

    public function subirLogo(Acceso $acc, mixed $archivo): array
    {
        $acc->exigir('ajustes.editar');
        $nombre = $this->subidas->imagenSubida($archivo, 'marca', 'logo', self::MAX_LOGO, 600);
        return $this->fijarLogo($acc, $nombre);
    }

    public function subirLogoDesdeRuta(Acceso $acc, string $ruta): array
    {
        $acc->exigir('ajustes.editar');
        return $this->fijarLogo($acc, $this->subidas->guardarImagen($ruta, 'marca', 'logo', self::MAX_LOGO, 600));
    }

    /* El logo lo ve también el portal del cliente sin sesión (su pantalla de
       acceso), así que va en uploads/marca/ y se sirve como fichero estático
       (uploads/.htaccess impide ejecutar nada allí). */
    private function fijarLogo(Acceso $acc, string $nombre): array
    {
        $antes = (string)get_setting('agency_logo', '');
        $this->poner('agency_logo', self::rutaPublicaLogos() . $nombre);
        $this->borrarLogoPropio($antes);
        return $this->agencia($acc);
    }

    public function quitarLogo(Acceso $acc): array
    {
        $acc->exigir('ajustes.editar');
        $antes = (string)get_setting('agency_logo', '');
        $this->poner('agency_logo', '');
        $this->borrarLogoPropio($antes);
        return $this->agencia($acc);
    }

    private function borrarLogoPropio(string $url): void
    {
        $pre = self::rutaPublicaLogos();
        if ($url !== '' && str_starts_with($url, $pre)) $this->subidas->borrar('marca', substr($url, strlen($pre)));
    }

    /* ---------- Portal: contacto y vídeos ---------- */

    public function contacto(Acceso $acc): array
    {
        $acc->exigir('ver.ajustes');
        $s = get_settings(['email', 'whatsapp', 'meeting_url']);
        return ['contacto' => ['email' => $s['email'], 'whatsapp' => $s['whatsapp'], 'meeting_url' => $s['meeting_url']], 'puede_editar' => $acc->puede('ajustes.editar')];
    }

    /** {email, whatsapp, meeting_url} */
    public function guardarContacto(Acceso $acc, array $d): array
    {
        $acc->exigir('ajustes.editar');
        if (array_key_exists('email', $d)) $this->poner('email', (string)Validar::email($d['email'], 'email', 'El email no tiene un formato válido.'));
        if (array_key_exists('whatsapp', $d)) {
            /* Solo cifras: el portal monta wa.me/<número>. */
            $w = preg_replace('/\D+/', '', Validar::texto($d['whatsapp'], 40, 'whatsapp', 'El WhatsApp'));
            if ($w !== '' && (strlen($w) < 8 || strlen($w) > 15)) throw HttpError::validacion('El WhatsApp tiene que llevar el prefijo del país y el número (entre 8 y 15 cifras).', 'whatsapp');
            $this->poner('whatsapp', $w);
        }
        if (array_key_exists('meeting_url', $d)) $this->poner('meeting_url', Validar::url($d['meeting_url'], 300, 'meeting_url', 'El enlace de reservas'));
        return $this->contacto($acc);
    }

    public function videos(Acceso $acc): array
    {
        $acc->exigir('ver.ajustes');
        require_once __DIR__ . '/../../../admin/lib/servicios_cat.php';
        $servicios = array_map(fn($s) => ['nombre' => $s['nombre'], 'video' => $s['video']], svc_catalogo());
        return ['video_id' => (string)get_setting('video_id', ''), 'servicios' => $servicios, 'puede_editar' => $acc->puede('ajustes.editar')];
    }

    /** {video_id, servicios:[{nombre, video}]}: identificador de YouTube o su URL. */
    public function guardarVideos(Acceso $acc, array $d): array
    {
        $acc->exigir('ajustes.editar');
        require_once __DIR__ . '/../../../admin/lib/servicios_cat.php';
        if (array_key_exists('video_id', $d)) {
            $raw = Validar::texto($d['video_id'], 300, 'video_id', 'El vídeo');
            $id = svc_video_id($raw);
            if ($raw !== '' && $id === '') throw HttpError::validacion('Eso no parece un vídeo de YouTube (pega el enlace o el identificador).', 'video_id');
            $this->poner('video_id', $id);
        }
        if (isset($d['servicios']) && is_array($d['servicios'])) {
            $nuevos = [];
            foreach ($d['servicios'] as $i => $s) {
                if (!is_array($s)) continue;
                $raw = Validar::texto($s['video'] ?? '', 300, "servicios.$i.video", 'El vídeo');
                $id = svc_video_id($raw);
                if ($raw !== '' && $id === '') throw HttpError::validacion('El vídeo de «' . (string)($s['nombre'] ?? '') . '» no parece de YouTube.', "servicios.$i.video");
                $nuevos[(string)($s['nombre'] ?? '')] = $id;
            }
            /* svc_guardar quita del catálogo lo que no se le pase: se le pasa el
               catálogo entero cambiando solo el vídeo de los que vienen. */
            $filas = [];
            foreach (svc_catalogo() as $s) {
                $filas[] = ['nombre' => $s['nombre'], 'video' => array_key_exists($s['nombre'], $nuevos) ? $nuevos[$s['nombre']] : $s['video']];
            }
            svc_guardar($filas);
            if (function_exists('audit_log')) audit_log('ajuste.videos_servicios', count($nuevos) . ' servicio(s)');
        }
        return $this->videos($acc);
    }

    /* ---------- Reglas automáticas ---------- */

    public function reglas(Acceso $acc): array
    {
        $acc->exigir('ver.ajustes');
        $s = get_settings(array_map(fn($k) => 'auto_' . $k, array_keys(self::REGLAS)));
        $items = [];
        foreach (self::REGLAS as $k => [$titulo, $desc]) $items[] = ['clave' => $k, 'titulo' => $titulo, 'descripcion' => $desc, 'on' => $s['auto_' . $k] !== '0'];
        return ['reglas' => $items, 'puede_editar' => $acc->puede('ajustes.editar')];
    }

    /** {clave, on} */
    public function regla(Acceso $acc, array $d): array
    {
        $acc->exigir('ajustes.editar');
        $k = (string)($d['clave'] ?? '');
        if (!isset(self::REGLAS[$k])) throw HttpError::validacion('Esa regla no existe.', 'clave');
        $this->poner('auto_' . $k, Validar::bool($d['on'] ?? false) ? '1' : '0');
        return $this->reglas($acc);
    }

    /** «Ejecutar ahora»: los avisos de leads y facturas y, los días 1–5, el del informe mensual. */
    public function ejecutarReglas(Acceso $acc): array
    {
        $acc->exigir('ajustes.editar');
        if (function_exists('notif_sync_leads') && get_setting('auto_lead_reminder', '') !== '0') notif_sync_leads();
        if (function_exists('notif_sync_invoices') && get_setting('auto_invoice_due', '') !== '0') notif_sync_invoices();
        if (get_setting('auto_monthly_report', '') !== '0' && (int)date('j') <= 5 && function_exists('notif_add')) {
            foreach ($this->pdo->query('SELECT id FROM admins WHERE activo = 1')->fetchAll(PDO::FETCH_COLUMN) as $id) {
                notif_add((int)$id, 'bell', 'Prepara los informes del mes', 'Empieza el mes: toca preparar los informes de los clientes.', '/clientes', 'report:' . date('Y-m'));
            }
        }
        if (function_exists('audit_log')) audit_log('reglas.ejecutar', '');
        return ['msg' => 'Reglas ejecutadas. Revisa tus notificaciones.'];
    }
}
