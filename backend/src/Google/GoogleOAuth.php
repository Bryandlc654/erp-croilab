<?php
namespace Croilab\Google;

/* OAuth de Google para todo el ERP, en un solo sitio.

   Antes había dos copias (admin/lib/gcal.php para el calendario y
   admin/lib/google_metrics.php para las métricas), cada una con su URI de
   vuelta construida con la cabecera Host, y la de métricas sin `state` y con el
   refresh token en claro. Aquí:

     · Las credenciales del proyecto (client id / secreto) se guardan en
       `settings`; el secreto, cifrado en la bóveda y de solo escritura.
     · Los tokens van cifrados en la bóveda (propósito por integración).
     · Una única URI de vuelta, fijada por configuración (GOOGLE_REDIRECT_URI o
       APP_URL + /api/v1/integraciones/google/callback), nunca por la petición.
     · `state` firmado con APP_SECRET, con caducidad (10 min), de un solo uso y
       atado a la sesión que lo pidió.

   Interfaz (documentada en docs/migracion/api/equipo.md):
     urlAutorizacion(Cuenta, volver)  → URL de Google a la que mandar al usuario
     procesarVuelta($_GET)            → canjea el código y guarda los tokens
     estado(Cuenta)                   → configurado / conectado / revocado / email
     tokenAcceso(Cuenta)              → access token válido (refresca si caducó)
     cliente(Cuenta)                  → ClienteGoogle autenticado para llamar a las APIs
     desconectar(Cuenta)              → borra (y revoca) los tokens

   Las claves de `settings` son las mismas que usaba el ERP antiguo, así que lo
   que ya estuviera conectado sigue funcionando (y lo que estaba en claro se
   cifra la primera vez que se lee). */
class GoogleOAuth
{
    public const URL_AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const URL_TOKEN = 'https://oauth2.googleapis.com/token';
    public const URL_REVOCAR = 'https://oauth2.googleapis.com/revoke';
    public const URL_USUARIO = 'https://www.googleapis.com/oauth2/v2/userinfo';
    public const RUTA_VUELTA = '/api/v1/integraciones/google/callback';
    private const VIDA_STATE = 600;

    /* Qué guarda cada integración y dónde. `cliente` = de qué proyecto OAuth
       saca el client id/secreto (el login reutiliza el del calendario, como
       el ERP antiguo: una sola URI que registrar en Google Cloud). */
    private const DEF = [
        Cuenta::CALENDAR => [
            'cliente' => 'calendar',
            /* `openid email`: sin ellos Google no devuelve el correo y el
               «Conectado: <email>» del antiguo salía vacío. */
            'scopes' => ['https://www.googleapis.com/auth/calendar.events', 'https://www.googleapis.com/auth/calendar.readonly', 'openid', 'email'],
            'offline' => true,
        ],
        Cuenta::METRICAS => [
            'cliente' => 'metricas',
            'scopes' => ['https://www.googleapis.com/auth/webmasters.readonly', 'https://www.googleapis.com/auth/analytics.readonly', 'openid', 'email'],
            'offline' => true,
        ],
        Cuenta::LOGIN => [
            'cliente' => 'calendar',
            'scopes' => ['openid', 'email', 'profile'],
            'offline' => false,
        ],
    ];

    /* Proyectos OAuth: claves de settings y propósito de la bóveda. */
    public const CLIENTES = [
        'calendar' => ['id' => 'gcal_client_id', 'secreto' => 'gcal_client_secret', 'proposito' => 'gcal'],
        'metricas' => ['id' => 'google_oauth_client_id', 'secreto' => 'google_oauth_client_secret', 'proposito' => 'gmet'],
    ];

    /** @var array<string,string> access tokens ya pedidos en esta petición */
    private array $cacheAcceso = [];

    public function __construct(private readonly Http $http = new HttpCurl())
    {
        require_once __DIR__ . '/../../admin/lib/boveda.php';
    }

    /* ---------- Configuración ---------- */

    private static function valor(string $clave): string
    {
        $v = getenv($clave);
        if ($v === false || $v === '') $v = $GLOBALS['croilab_env'][$clave] ?? '';
        return trim((string)$v);
    }

    /** URI de vuelta que hay que registrar en Google Cloud, tal cual. '' si falta APP_URL. */
    public static function redirectUri(): string
    {
        $fija = self::valor('GOOGLE_REDIRECT_URI');
        if ($fija !== '') return $fija;
        $base = self::appUrl();
        return preg_match('~^https?://[^\s/?#]+~', $base) ? $base . self::RUTA_VUELTA : '';
    }

    /** URL pública del backend (APP_URL: entorno, y si no el .env), sin barra final. */
    public static function appUrl(): string
    {
        $v = getenv('APP_URL');
        if ($v === false || $v === '') $v = defined('APP_URL') ? (string)APP_URL : '';
        return rtrim(trim((string)$v), '/');
    }

    /** URL pública del front (FRONT_URL), sin barra final. '' si no está. */
    public static function urlFront(): string
    {
        $u = rtrim(self::valor('FRONT_URL'), '/');
        return preg_match('~^https?://[^\s/?#]+(/[^\s?#]*)?$~', $u) ? $u : '';
    }

    public function clientId(string $proyecto): string
    {
        return trim((string)get_setting(self::CLIENTES[$proyecto]['id'], ''));
    }

    private function clientSecret(string $proyecto): string
    {
        $c = self::CLIENTES[$proyecto];
        return trim(boveda_valor($c['secreto'], $c['proposito']));
    }

    /** ¿Están el client id y el secreto del proyecto? */
    public function configurado(string $proyecto): bool
    {
        return $this->clientId($proyecto) !== '' && $this->clientSecret($proyecto) !== '';
    }

    /** Lo que se puede enseñar de las credenciales: el id y si hay secreto (nunca el secreto). */
    public function credenciales(string $proyecto): array
    {
        $c = self::CLIENTES[$proyecto];
        $r = boveda_leer($c['secreto'], $c['proposito']);
        return [
            'client_id' => $this->clientId($proyecto),
            'secreto_guardado' => $r['ok'] && trim((string)$r['v']) !== '',
            'secreto_ilegible' => $r['estado'] === 'ilegible',
        ];
    }

    /**
     * Guarda el client id y, si viene, el secreto (vacío o null = no tocarlo).
     * @throws ErrorGoogle si la bóveda no puede cifrar
     */
    public function guardarCredenciales(string $proyecto, string $clientId, ?string $secreto): void
    {
        $c = self::CLIENTES[$proyecto];
        $clientId = trim($clientId);
        db()->prepare('INSERT INTO settings (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')->execute([$c['id'], $clientId]);
        if ($secreto !== null && trim($secreto) !== '') {
            if (!boveda_guardar($c['secreto'], trim($secreto), $c['proposito'])) {
                throw new ErrorGoogle('boveda', 'No se ha podido guardar el secreto cifrado. Revisa la clave de la bóveda del servidor.');
            }
        }
    }

    /* ---------- Autorización ---------- */

    /**
     * URL de Google a la que mandar al usuario. `$volver` es la ruta del front
     * a la que se vuelve después (p. ej. /ajustes/integraciones).
     * @throws ErrorGoogle sin_configurar
     */
    public function urlAutorizacion(Cuenta $cuenta, string $volver = '/'): string
    {
        $def = self::DEF[$cuenta->integracion];
        $proyecto = $def['cliente'];
        if (!$this->configurado($proyecto)) {
            throw new ErrorGoogle('sin_configurar', 'Faltan el ID de cliente y el secreto de Google. Se ponen en Ajustes › Integraciones.');
        }
        $redirect = self::redirectUri();
        if ($redirect === '') throw new ErrorGoogle('sin_configurar', 'Falta APP_URL (o GOOGLE_REDIRECT_URI) en la configuración del servidor.');

        $params = [
            'client_id' => $this->clientId($proyecto),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => implode(' ', $def['scopes']),
            'state' => $this->crearState($cuenta, $volver),
        ];
        if ($def['offline']) {
            /* consent: si no, Google solo da el refresh token la primera vez. */
            $params += ['access_type' => 'offline', 'include_granted_scopes' => 'true', 'prompt' => 'consent'];
        } else {
            $params['prompt'] = 'select_account';
        }
        return self::URL_AUTH . '?' . http_build_query($params);
    }

    private function crearState(Cuenta $cuenta, string $volver): string
    {
        $nonce = bin2hex(random_bytes(16));
        $exp = time() + self::VIDA_STATE;
        $datos = ['i' => $cuenta->integracion, 'a' => $cuenta->adminId, 'n' => $nonce, 'e' => $exp, 'v' => self::rutaSegura($volver)];
        /* Atado a la sesión: un state robado no sirve desde otro navegador. Se
           limpian de paso los caducados. */
        $vivos = array_filter((array)($_SESSION['google_oauth'] ?? []), fn($e) => (int)$e > time());
        $vivos[$nonce] = $exp;
        $_SESSION['google_oauth'] = $vivos;
        $carga = self::b64(json_encode($datos));
        return $carga . '.' . self::b64(hash_hmac('sha256', $carga, self::claveState(), true));
    }

    private static function claveState(): string
    {
        $s = defined('APP_SECRET') ? (string)APP_SECRET : self::valor('APP_SECRET');
        if ($s === '') throw new ErrorGoogle('sin_configurar', 'Falta APP_SECRET.');
        return hash('sha256', 'google-oauth-state|' . $s, true);
    }

    /** Solo rutas internas del front: nada de //otro-sitio ni URLs completas. */
    public static function rutaSegura(string $ruta): string
    {
        $ruta = trim($ruta);
        if ($ruta === '' || $ruta[0] !== '/' || str_starts_with($ruta, '//') || str_contains($ruta, '\\') || preg_match('/[\x00-\x1f]/', $ruta)) return '/';
        return mb_substr($ruta, 0, 200);
    }

    /**
     * Procesa la vuelta de Google ($_GET de la URI de vuelta).
     * Devuelve [ok, cuenta|null, volver, codigo, msg, email]:
     *   codigo: ok | cancelado | state | error
     * Para `login` no guarda nada: devuelve el correo verificado en `email`.
     */
    public function procesarVuelta(array $query): array
    {
        $fallo = fn(string $codigo, string $msg, ?Cuenta $c = null, string $volver = '/') =>
            ['ok' => false, 'cuenta' => $c, 'volver' => $volver, 'codigo' => $codigo, 'msg' => $msg, 'email' => ''];

        $datos = $this->leerState((string)($query['state'] ?? ''));
        if ($datos === null) return $fallo('state', 'La vuelta de Google no es válida o ha caducado. Inténtalo otra vez.');
        try {
            $cuenta = Cuenta::desde((string)$datos['i'], (int)$datos['a']);
        } catch (\InvalidArgumentException $e) {
            return $fallo('state', 'La vuelta de Google no es válida.');
        }
        $volver = self::rutaSegura((string)$datos['v']);
        if (isset($query['error'])) {
            return $fallo($query['error'] === 'access_denied' ? 'cancelado' : 'error', $query['error'] === 'access_denied' ? 'Has cancelado el acceso con Google.' : 'Google ha devuelto un error.', $cuenta, $volver);
        }
        $code = (string)($query['code'] ?? '');
        if ($code === '') return $fallo('error', 'Google no ha devuelto ningún código.', $cuenta, $volver);

        try {
            $tok = $this->canjear($cuenta, $code);
        } catch (ErrorGoogle $e) {
            return $fallo('error', $e->getMessage(), $cuenta, $volver);
        }
        $email = $this->emailDe((string)$tok['access_token']);

        if ($cuenta->integracion === Cuenta::LOGIN) {
            if ($email === '') return $fallo('error', 'Google no ha confirmado el correo de esa cuenta.', $cuenta, $volver);
            return ['ok' => true, 'cuenta' => $cuenta, 'volver' => $volver, 'codigo' => 'ok', 'msg' => '', 'email' => $email];
        }

        $anterior = $this->tokens($cuenta);
        $refresh = (string)($tok['refresh_token'] ?? ($anterior['refresh_token'] ?? ''));
        if ($refresh === '') {
            return $fallo('error', 'Google no ha dado el permiso permanente. Vuelve a conectar y acepta todos los permisos.', $cuenta, $volver);
        }
        $this->guardarTokens($cuenta, [
            'access_token' => (string)$tok['access_token'],
            'refresh_token' => $refresh,
            'expiry' => time() + (int)($tok['expires_in'] ?? 3600) - 60,
            'email' => $email !== '' ? $email : (string)($anterior['email'] ?? ''),
        ]);
        $this->marcarRevocado($cuenta, false);
        if (function_exists('audit_log')) audit_log('google.conectar', $cuenta->integracion . ($cuenta->adminId ? ' #' . $cuenta->adminId : ''));
        return ['ok' => true, 'cuenta' => $cuenta, 'volver' => $volver, 'codigo' => 'ok', 'msg' => '', 'email' => $email];
    }

    /** Datos del state si la firma cuadra, no ha caducado y es de esta sesión (y se gasta). */
    private function leerState(string $state): ?array
    {
        $partes = explode('.', $state);
        if (count($partes) !== 2) return null;
        [$carga, $firma] = $partes;
        if (!hash_equals(self::b64(hash_hmac('sha256', $carga, self::claveState(), true)), $firma)) return null;
        $d = json_decode((string)self::deB64($carga), true);
        if (!is_array($d) || !isset($d['i'], $d['n'], $d['e']) || (int)$d['e'] < time()) return null;
        $vivos = (array)($_SESSION['google_oauth'] ?? []);
        if (!isset($vivos[$d['n']])) return null;
        unset($vivos[$d['n']]);   // un solo uso
        $_SESSION['google_oauth'] = $vivos;
        return $d;
    }

    private function canjear(Cuenta $cuenta, string $code): array
    {
        $proyecto = self::DEF[$cuenta->integracion]['cliente'];
        $r = $this->http->enviar('POST', self::URL_TOKEN, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'code' => $code,
            'client_id' => $this->clientId($proyecto),
            'client_secret' => $this->clientSecret($proyecto),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ]));
        $j = $r->json();
        if ($r->estado === 0) throw new ErrorGoogle('red', 'No se ha podido contactar con Google.');
        if (!$r->ok() || empty($j['access_token'])) {
            /* Al registro solo el tipo de error: el cuerpo puede llevar material del token. */
            error_log('Google canje ' . $cuenta->integracion . ': ' . $r->estado . ' ' . ($j['error'] ?? '?'));
            throw new ErrorGoogle('google', 'Google no ha aceptado la conexión (' . ($j['error'] ?? $r->estado) . ').', $r->estado);
        }
        return $j;
    }

    private function emailDe(string $accessToken): string
    {
        $u = $this->http->enviar('GET', self::URL_USUARIO, ['Authorization: Bearer ' . $accessToken])->json();
        $email = strtolower(trim((string)($u['email'] ?? '')));
        if ($email === '' || (isset($u['verified_email']) && !$u['verified_email'])) return '';
        return $email;
    }

    /* ---------- Tokens ---------- */

    /** Dónde se guarda cada cosa: [clave de tokens, propósito, clave de «revocado»]. */
    private static function claves(Cuenta $c): array
    {
        return match ($c->integracion) {
            Cuenta::CALENDAR => ['gcal_tok_' . $c->adminId, 'gcal', 'gcal_revoked_' . $c->adminId],
            Cuenta::METRICAS => ['google_oauth_refresh_token', 'gmet', 'gmet_revoked'],
            default => throw new \InvalidArgumentException('Esta integración no guarda tokens.'),
        };
    }

    /** Tokens guardados (o null). Métricas guarda solo el refresh token, como el antiguo. */
    public function tokens(Cuenta $c): ?array
    {
        [$clave, $prop] = self::claves($c);
        $r = boveda_leer($clave, $prop);
        if (!$r['ok'] || $r['v'] === '') return null;
        if ($c->integracion === Cuenta::METRICAS) {
            $extra = json_decode(boveda_valor('gmet_tok', 'gmet'), true);
            $extra = is_array($extra) ? $extra : [];
            return ['refresh_token' => (string)$r['v'], 'access_token' => (string)($extra['access_token'] ?? ''),
                    'expiry' => (int)($extra['expiry'] ?? 0), 'email' => (string)($extra['email'] ?? '')];
        }
        $d = json_decode((string)$r['v'], true);
        return is_array($d) ? $d : null;
    }

    private function guardarTokens(Cuenta $c, array $tok): void
    {
        [$clave, $prop] = self::claves($c);
        if ($c->integracion === Cuenta::METRICAS) {
            $ok = boveda_guardar($clave, (string)$tok['refresh_token'], $prop)
                && boveda_guardar('gmet_tok', (string)json_encode(['access_token' => $tok['access_token'], 'expiry' => $tok['expiry'], 'email' => $tok['email']]), $prop);
        } else {
            $ok = boveda_guardar($clave, (string)json_encode($tok), $prop);
        }
        if (!$ok) throw new ErrorGoogle('boveda', 'No se ha podido guardar el permiso de Google cifrado.');
    }

    private function marcarRevocado(Cuenta $c, bool $revocado): void
    {
        [, , $clave] = self::claves($c);
        db()->prepare('INSERT INTO settings (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')->execute([$clave, $revocado ? '1' : '0']);
    }

    public function revocado(Cuenta $c): bool
    {
        [, , $clave] = self::claves($c);
        return get_setting($clave, '') === '1';
    }

    /** {configurado, conectado, revocado, email}: lo que enseñan las pantallas. */
    public function estado(Cuenta $c): array
    {
        $t = $this->tokens($c);
        return [
            'configurado' => $this->configurado(self::DEF[$c->integracion]['cliente']),
            'conectado' => $t !== null && ($t['refresh_token'] ?? '') !== '',
            'revocado' => $this->revocado($c),
            'email' => (string)($t['email'] ?? ''),
        ];
    }

    /**
     * Access token válido, refrescándolo si ha caducado.
     * @throws ErrorGoogle sin_conectar | revocado | google | red
     */
    public function tokenAcceso(Cuenta $c, bool $forzarRefresco = false): string
    {
        $k = $c->integracion . ':' . $c->adminId;
        if (!$forzarRefresco && isset($this->cacheAcceso[$k])) return $this->cacheAcceso[$k];
        $t = $this->tokens($c);
        if (!$t || ($t['refresh_token'] ?? '') === '') throw new ErrorGoogle('sin_conectar', 'Google no está conectado.');
        if (!$forzarRefresco && ($t['access_token'] ?? '') !== '' && (int)($t['expiry'] ?? 0) > time()) {
            return $this->cacheAcceso[$k] = (string)$t['access_token'];
        }
        $proyecto = self::DEF[$c->integracion]['cliente'];
        if (!$this->configurado($proyecto)) throw new ErrorGoogle('sin_configurar', 'Faltan las credenciales de Google del proyecto.');
        $r = $this->http->enviar('POST', self::URL_TOKEN, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'client_id' => $this->clientId($proyecto),
            'client_secret' => $this->clientSecret($proyecto),
            'refresh_token' => $t['refresh_token'],
            'grant_type' => 'refresh_token',
        ]));
        $j = $r->json();
        if ($r->estado === 0) throw new ErrorGoogle('red', 'No se ha podido contactar con Google.');
        if (!$r->ok() || empty($j['access_token'])) {
            if (($j['error'] ?? '') === 'invalid_grant') {
                /* Permiso retirado: se marca para pedir reconexión y no reintentar en bucle. */
                $this->marcarRevocado($c, true);
                throw new ErrorGoogle('revocado', 'Google ha retirado el permiso. Hay que volver a conectar la cuenta.', $r->estado);
            }
            error_log('Google refresco ' . $c->integracion . ': ' . $r->estado . ' ' . ($j['error'] ?? '?'));
            throw new ErrorGoogle('google', 'Google no ha renovado el acceso (' . ($j['error'] ?? $r->estado) . ').', $r->estado);
        }
        $t['access_token'] = (string)$j['access_token'];
        $t['expiry'] = time() + (int)($j['expires_in'] ?? 3600) - 60;
        if (!empty($j['refresh_token'])) $t['refresh_token'] = (string)$j['refresh_token'];
        $this->guardarTokens($c, $t);
        if ($this->revocado($c)) $this->marcarRevocado($c, false);
        return $this->cacheAcceso[$k] = $t['access_token'];
    }

    /** Cliente autenticado para llamar a las APIs de Google en nombre de esa cuenta. */
    public function cliente(Cuenta $c): ClienteGoogle
    {
        return new ClienteGoogle($this, $c, $this->http);
    }

    /** Borra los tokens y, por cortesía, los revoca en Google (si falla, no pasa nada). */
    public function desconectar(Cuenta $c): void
    {
        $t = $this->tokens($c);
        if ($t && ($t['refresh_token'] ?? '') !== '') {
            try {
                $this->http->enviar('POST', self::URL_REVOCAR, ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $t['refresh_token']]));
            } catch (\Throwable $e) {
                /* La desconexión local vale igual. */
            }
        }
        [$clave, , $rev] = self::claves($c);
        $st = db()->prepare('DELETE FROM settings WHERE clave = ?');
        foreach ([$clave, $rev] as $k) $st->execute([$k]);
        if ($c->integracion === Cuenta::METRICAS) $st->execute(['gmet_tok']);
        unset($this->cacheAcceso[$c->integracion . ':' . $c->adminId]);
        if (function_exists('audit_log')) audit_log('google.desconectar', $c->integracion . ($c->adminId ? ' #' . $c->adminId : ''));
    }

    /** Personas del equipo con Google Calendar conectado (para «Ver también…» del calendario). */
    public function cuentasCalendario(): array
    {
        $ids = [];
        foreach (db()->query("SELECT clave FROM settings WHERE clave LIKE 'gcal\\_tok\\_%' AND valor <> ''") as $r) {
            $ids[] = (int)substr((string)$r['clave'], 9);
        }
        sort($ids);
        return array_values(array_filter($ids));
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function deB64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
