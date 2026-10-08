<?php
namespace Croilab\Modulos\Equipo;

use Croilab\Google\Cuenta;
use Croilab\Google\ErrorGoogle;
use Croilab\Google\GoogleOAuth;
use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Integraciones (integraciones.php): n8n/API, Google Calendar, Google ·
   Métricas y MCP.

   Cambios respecto al antiguo:
     · Escribir pide `integraciones.editar` (el antiguo exigía además ser
       Dueño, con lo que el permiso no servía de nada).
     · Los secretos son de solo escritura: el client secret de Calendar ya no
       se pinta en el formulario, y los tokens (API, MCP) solo se enseñan al
       regenerarlos o tras confirmar la contraseña (zona «integraciones»).
     · Métricas usa `state` y guarda el secreto y el refresh token cifrados.
     · Desconectar es DELETE con CSRF (antes un GET). */
class IntegracionesServicio
{
    public function __construct(private readonly PDO $pdo, private readonly GoogleOAuth $google) {}

    private function poner(string $clave, string $valor): void
    {
        $this->pdo->prepare('INSERT INTO settings (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')->execute([$clave, $valor]);
    }

    public function estado(Acceso $acc): array
    {
        $acc->exigir('ver.ajustes');
        $s = get_settings(['api_token', 'mcp_enabled', 'mcp_token']);
        $cal = $this->google->estado(Cuenta::calendario(max(1, $acc->adminId)));
        $met = $this->google->estado(Cuenta::metricas());
        $ultima = $this->pdo->query('SELECT MAX(met_sync_at) FROM clients')->fetchColumn();
        return [
            'puede_editar' => $acc->puede('integraciones.editar'),
            'redirect_uri' => GoogleOAuth::redirectUri(),
            'api' => ['activa' => trim($s['api_token']) !== ''],
            'calendar' => $this->google->credenciales('calendar') + [
                'configurado' => $cal['configurado'],
                'conectado' => $cal['conectado'],
                'revocado' => $cal['revocado'],
                'email' => $cal['email'] ?: null,
                'cuentas' => count($this->google->cuentasCalendario()),
            ],
            'metricas' => $this->google->credenciales('metricas') + [
                'configurado' => $met['configurado'],
                'conectado' => $met['conectado'],
                'revocado' => $met['revocado'],
                'email' => $met['email'] ?: null,
                'ultima_sync' => $ultima ? substr((string)$ultima, 0, 10) : null,
            ],
            'mcp' => ['activo' => $s['mcp_enabled'] === '1' && trim($s['mcp_token']) !== ''],
        ];
    }

    /* ---------- n8n / API ---------- */

    public function verTokenApi(Acceso $acc): array
    {
        $acc->exigir('integraciones.editar');
        Reautenticacion::exigir('integraciones');
        return ['token' => (string)get_setting('api_token', '')];
    }

    public function regenerarTokenApi(Acceso $acc): array
    {
        $acc->exigir('integraciones.editar');
        $t = bin2hex(random_bytes(20));
        /* En claro a propósito: api.php (n8n) lo compara tal cual. */
        $this->poner('api_token', $t);
        if (function_exists('audit_log')) audit_log('integracion.api_token', 'regenerado');
        return ['token' => $t];
    }

    /* ---------- Google ---------- */

    /** {client_id, client_secret?}: el secreto vacío deja el guardado. */
    public function credencialesGoogle(Acceso $acc, string $proyecto, array $d): array
    {
        $acc->exigir('integraciones.editar');
        $id = Validar::texto($d['client_id'] ?? '', 200, 'client_id', 'El ID de cliente');
        $secreto = Validar::texto($d['client_secret'] ?? '', 200, 'client_secret', 'El secreto');
        if ($id === '') throw HttpError::validacion('Pon el ID de cliente.', 'client_id');
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $id)) throw HttpError::validacion('El ID de cliente no tiene buena pinta (suele acabar en .apps.googleusercontent.com).', 'client_id');
        if ($secreto === '' && !$this->google->credenciales($proyecto)['secreto_guardado']) throw HttpError::validacion('Pon también el secreto de cliente.', 'client_secret');
        try {
            $this->google->guardarCredenciales($proyecto, $id, $secreto === '' ? null : $secreto);
        } catch (ErrorGoogle $e) {
            throw new HttpError(500, $e->getMessage(), 'boveda');
        }
        if (function_exists('audit_log')) audit_log('integracion.google', $proyecto . ' credenciales');
        return $this->estado($acc);
    }

    /** El .json que descarga Google Cloud (web o installed). */
    public function credencialesDesdeJson(Acceso $acc, string $proyecto, string $contenido): array
    {
        $j = json_decode($contenido, true);
        $c = is_array($j) ? ($j['web'] ?? $j['installed'] ?? null) : null;
        if (!is_array($c) || empty($c['client_id']) || empty($c['client_secret'])) {
            throw HttpError::validacion('Ese archivo no parece el de Google (no encuentro el id y el secreto).', 'archivo');
        }
        return $this->credencialesGoogle($acc, $proyecto, ['client_id' => (string)$c['client_id'], 'client_secret' => (string)$c['client_secret']]);
    }

    private function url(Cuenta $c, string $volver): array
    {
        try {
            return ['url' => $this->google->urlAutorizacion($c, $volver)];
        } catch (ErrorGoogle $e) {
            throw new HttpError(409, $e->getMessage(), $e->codigo);
        }
    }

    /** Cada persona conecta SU calendario (lo usa Comunicación). */
    public function conectarCalendar(Acceso $acc, string $volver): array
    {
        $acc->exigir('ver.agenda');
        return $this->url(Cuenta::calendario($acc->adminId), $volver ?: '/calendario');
    }

    public function desconectarCalendar(Acceso $acc): array
    {
        $this->google->desconectar(Cuenta::calendario($acc->adminId));
        return [];
    }

    public function conectarMetricas(Acceso $acc): array
    {
        $acc->exigir('integraciones.editar');
        return $this->url(Cuenta::metricas(), '/ajustes/integraciones?i=metricas');
    }

    public function desconectarMetricas(Acceso $acc): array
    {
        $acc->exigir('integraciones.editar');
        $this->google->desconectar(Cuenta::metricas());
        return [];
    }

    /**
     * Vuelta de Google (ruta pública: puede llegar con la sesión caducada).
     * Devuelve la URL del front a la que redirigir, con ?google=ok|cancelado|error.
     */
    public function vuelta(array $query): string
    {
        $r = $this->google->procesarVuelta($query);
        $front = GoogleOAuth::urlFront();
        /* «Entrar con Google» del portal del cliente usa la misma URI de vuelta:
           si era eso, lo resuelve el portal (abre la sesión del cliente). */
        if (class_exists(\Croilab\Modulos\Portal\PortalAuthServicio::class)) {
            require_once __DIR__ . '/../../../admin/lib/login_throttle.php';
            $p = (new \Croilab\Modulos\Portal\PortalAuthServicio($this->pdo, new \Croilab\Modulos\Portal\PortalSesion($this->pdo)))->vueltaGoogle($r);
            if ($p !== null) return $front . $p;
        }
        $volver = $r['volver'] ?? '/';
        /* Si quien vuelve ya no tiene sesión o no es quien empezó, no se guarda
           nada (procesarVuelta ya comprobó que el state es de esta sesión). */
        $sep = str_contains($volver, '?') ? '&' : '?';
        $q = 'google=' . ($r['ok'] ? 'ok' : rawurlencode((string)$r['codigo']));
        if (!$r['ok'] && $r['msg'] !== '') $q .= '&msg=' . rawurlencode((string)$r['msg']);
        return ($front !== '' ? $front : '') . $volver . $sep . $q;
    }

    /* ---------- MCP ---------- */

    /** {enabled} → genera el token si falta. */
    public function mcp(Acceso $acc, array $d): array
    {
        $acc->exigir('integraciones.editar');
        $on = Validar::bool($d['enabled'] ?? false);
        $this->poner('mcp_enabled', $on ? '1' : '0');
        $nuevo = null;
        if ($on && trim((string)get_setting('mcp_token', '')) === '') {
            $nuevo = bin2hex(random_bytes(20));
            $this->poner('mcp_token', $nuevo);
        }
        if (function_exists('audit_log')) audit_log('integracion.mcp', $on ? 'activado' : 'desactivado');
        return ['activo' => $on] + ($nuevo ? ['url' => self::urlMcp($nuevo)] : []);
    }

    public function verMcp(Acceso $acc): array
    {
        $acc->exigir('integraciones.editar');
        Reautenticacion::exigir('integraciones');
        $t = trim((string)get_setting('mcp_token', ''));
        return ['url' => $t !== '' ? self::urlMcp($t) : null];
    }

    public function regenerarMcp(Acceso $acc): array
    {
        $acc->exigir('integraciones.editar');
        $t = bin2hex(random_bytes(20));
        /* En claro: el servidor MCP lo compara con hash_equals. */
        $this->poner('mcp_token', $t);
        if (function_exists('audit_log')) audit_log('integracion.mcp', 'token regenerado');
        return ['url' => self::urlMcp($t)];
    }

    /* La URL del conector. El servidor MCP lo migra Comunicación: si cambia de
       ruta, cambiar solo esto. */
    public static function urlMcp(string $token): string
    {
        return \Croilab\Google\GoogleOAuth::appUrl() . '/api/v1/mcp?k=' . $token;
    }
}
