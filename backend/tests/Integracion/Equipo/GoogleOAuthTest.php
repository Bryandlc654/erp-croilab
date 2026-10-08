<?php
namespace Croilab\Tests\Integracion\Equipo;

use Croilab\Google\Cuenta;
use Croilab\Google\ErrorGoogle;
use Croilab\Google\GoogleOAuth;
use Croilab\Google\Metricas;
use Croilab\Tests\Integracion\BaseDatosTestCase;

/* OAuth de Google sin Google: el transporte HTTP es de mentira (HttpFalso).
   Comprueba el state (firma, sesión, un solo uso), que los tokens se guardan
   cifrados, el refresco, la revocación y el motor de métricas. */
class GoogleOAuthTest extends BaseDatosTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('nueva');
        putenv('APP_URL=https://api.ejemplo.com');
        putenv('FRONT_URL=https://app.ejemplo.com');
        self::pdo()->exec("INSERT INTO admins (id, username, password_hash, role) VALUES (1, 'duena', 'x', 'owner')");
        self::pdo()->exec("INSERT INTO clients (id, name, activo, gsc_site_url, ga4_property_id, met_json) VALUES (1, 'Alfa', 1, 'https://alfa.com/', '123', '{\"Enero\":{\"vi\":5,\"nota\":\"se conserva\"}}')");
    }

    public static function tearDownAfterClass(): void
    {
        putenv('APP_URL');
        putenv('FRONT_URL');
    }

    protected function setUp(): void
    {
        $_SESSION = [];
        self::pdo()->exec("DELETE FROM settings WHERE clave LIKE 'gcal%' OR clave LIKE 'google_oauth%' OR clave LIKE 'gmet%'");
    }

    private function configurar(GoogleOAuth $g, string $proyecto = 'calendar'): void
    {
        $g->guardarCredenciales($proyecto, 'id-de-prueba.apps.googleusercontent.com', 'secreto-de-prueba');
    }

    private function state(string $url): string
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        return (string)$q['state'];
    }

    public function testSinCredencialesNoHayUrl(): void
    {
        $this->expectException(ErrorGoogle::class);
        (new GoogleOAuth(new HttpFalso()))->urlAutorizacion(Cuenta::calendario(1));
    }

    public function testElSecretoDelProyectoVaCifradoYNoSeDevuelve(): void
    {
        $g = new GoogleOAuth(new HttpFalso());
        $this->configurar($g);
        $guardado = (string)self::pdo()->query("SELECT valor FROM settings WHERE clave = 'gcal_client_secret'")->fetchColumn();
        $this->assertStringStartsWith('bx1:', $guardado);
        $c = $g->credenciales('calendar');
        $this->assertSame(['client_id' => 'id-de-prueba.apps.googleusercontent.com', 'secreto_guardado' => true, 'secreto_ilegible' => false], $c);
        $this->assertTrue($g->configurado('calendar'));
        $this->assertFalse($g->configurado('metricas'));
    }

    public function testFlujoCompletoDeCalendario(): void
    {
        $http = new HttpFalso();
        $g = new GoogleOAuth($http);
        $this->configurar($g);
        $url = $g->urlAutorizacion(Cuenta::calendario(1), '/calendario');
        $this->assertStringContainsString('redirect_uri=' . rawurlencode('https://api.ejemplo.com/api/v1/integraciones/google/callback'), $url);
        $this->assertStringContainsString('access_type=offline', $url);

        $http->encolar(200, ['access_token' => 'acc-1', 'refresh_token' => 'ref-1', 'expires_in' => 3600])
             ->encolar(200, ['email' => 'Duena@Gmail.com', 'verified_email' => true]);
        $r = $g->procesarVuelta(['state' => $this->state($url), 'code' => 'codigo']);
        $this->assertTrue($r['ok'], $r['msg']);
        $this->assertSame('/calendario', $r['volver']);
        $this->assertSame(['configurado' => true, 'conectado' => true, 'revocado' => false, 'email' => 'duena@gmail.com'], $g->estado(Cuenta::calendario(1)));
        $guardado = (string)self::pdo()->query("SELECT valor FROM settings WHERE clave = 'gcal_tok_1'")->fetchColumn();
        $this->assertStringStartsWith('bx1:', $guardado);
        $this->assertStringNotContainsString('ref-1', $guardado);
        $this->assertSame([1], $g->cuentasCalendario());

        /* El mismo state no se puede usar dos veces. */
        $otra = $g->procesarVuelta(['state' => $this->state($url), 'code' => 'codigo']);
        $this->assertFalse($otra['ok']);
        $this->assertSame('state', $otra['codigo']);

        /* Token vigente: no se llama a Google. Caducado: se refresca. */
        $n = count($http->peticiones);
        $this->assertSame('acc-1', $g->tokenAcceso(Cuenta::calendario(1)));
        $this->assertCount($n, $http->peticiones);
        $http->encolar(200, ['access_token' => 'acc-2', 'expires_in' => 3600]);
        $this->assertSame('acc-2', $g->tokenAcceso(Cuenta::calendario(1), true));
        $this->assertStringContainsString('grant_type=refresh_token', (string)end($http->peticiones)[3]);

        /* El cliente reintenta una vez con un 401. */
        $http->encolar(401, ['error' => ['message' => 'caducado']])
             ->encolar(200, ['access_token' => 'acc-3', 'expires_in' => 3600])
             ->encolar(200, ['items' => [['id' => 'ev1']]]);
        $eventos = $g->cliente(Cuenta::calendario(1))->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', ['maxResults' => 5]);
        $this->assertSame('ev1', $eventos['items'][0]['id']);
        $this->assertContains('Authorization: Bearer acc-3', end($http->peticiones)[2]);

        $g->desconectar(Cuenta::calendario(1));
        $this->assertFalse($g->estado(Cuenta::calendario(1))['conectado']);
    }

    public function testStateFalsificadoOdeOtraSesionNoVale(): void
    {
        $g = new GoogleOAuth(new HttpFalso());
        $this->configurar($g);
        $url = $g->urlAutorizacion(Cuenta::calendario(1));
        $st = $this->state($url);
        [$carga, $firma] = explode('.', $st);
        $this->assertSame('state', $g->procesarVuelta(['state' => $carga . 'x.' . $firma, 'code' => 'c'])['codigo']);
        $_SESSION = [];   // otro navegador
        $this->assertSame('state', $g->procesarVuelta(['state' => $st, 'code' => 'c'])['codigo']);
        $this->assertSame('/', GoogleOAuth::rutaSegura('//malo.com/x'));
        $this->assertSame('/', GoogleOAuth::rutaSegura('https://malo.com'));
        $this->assertSame('/ajustes', GoogleOAuth::rutaSegura('/ajustes'));
    }

    public function testCancelarYPermisoRevocado(): void
    {
        $http = new HttpFalso();
        $g = new GoogleOAuth($http);
        $this->configurar($g);
        $url = $g->urlAutorizacion(Cuenta::calendario(1));
        $this->assertSame('cancelado', $g->procesarVuelta(['state' => $this->state($url), 'error' => 'access_denied'])['codigo']);

        $url = $g->urlAutorizacion(Cuenta::calendario(1));
        $http->encolar(200, ['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 0])->encolar(200, []);
        $g->procesarVuelta(['state' => $this->state($url), 'code' => 'c']);
        $http->encolar(400, ['error' => 'invalid_grant']);
        try {
            $g->tokenAcceso(Cuenta::calendario(1));
            $this->fail('Debería pedir reconectar');
        } catch (ErrorGoogle $e) {
            $this->assertSame('revocado', $e->codigo);
        }
        $this->assertTrue($g->estado(Cuenta::calendario(1))['revocado']);
    }

    public function testMetricasSincronizaYFusionaMetJson(): void
    {
        $http = new HttpFalso();
        $g = new GoogleOAuth($http);
        $this->configurar($g, 'metricas');
        $url = $g->urlAutorizacion(Cuenta::metricas(), '/ajustes/integraciones');
        $this->assertStringContainsString('webmasters.readonly', urldecode($url));
        $http->encolar(200, ['access_token' => 'm-acc', 'refresh_token' => 'm-ref', 'expires_in' => 3600])->encolar(200, ['email' => 'agencia@gmail.com']);
        $this->assertTrue($g->procesarVuelta(['state' => $this->state($url), 'code' => 'c'])['ok']);
        $this->assertSame('m-ref', boveda_valor('google_oauth_refresh_token', 'gmet'));

        $m = new Metricas($g, self::pdo());
        $this->assertTrue($m->conectado());
        /* Search Console, 3 eventos de GA4, canales y países. */
        $http->encolar(200, ['rows' => [['clicks' => 120, 'impressions' => 3400, 'ctr' => 0.0353]]])
             ->encolar(200, ['rows' => [['metricValues' => [['value' => '7']]]]])
             ->encolar(200, ['rows' => [['metricValues' => [['value' => '3']]]]])
             ->encolar(200, [])
             ->encolar(200, ['rows' => [['dimensionValues' => [['value' => 'Organic Search']], 'metricValues' => [['value' => '90']]]]])
             ->encolar(200, ['rows' => [['dimensionValues' => [['value' => 'es']], 'metricValues' => [['value' => '80']]]]]);
        [$ok, $msg] = $m->sincronizarCliente(1, ['2026-01']);
        $this->assertTrue($ok, $msg);
        $met = json_decode((string)self::pdo()->query('SELECT met_json FROM clients WHERE id = 1')->fetchColumn(), true);
        $this->assertSame(['vi' => 120, 'nota' => 'se conserva', 'ap' => 3400, 'ctr' => 3.53, 'll' => 7, 'wa' => 3, 'fo' => 0, 'src' => ['Organic Search' => 90], 'geo' => ['ES' => 80]], $met['Enero']);
        $this->assertNotNull(self::pdo()->query('SELECT met_sync_at FROM clients WHERE id = 1')->fetchColumn());
        /* Los eventos por defecto se usan cuando el cliente no tiene los suyos. */
        $this->assertStringContainsString('phone_call', (string)$http->peticiones[count($http->peticiones) - 5][3]);
    }

    /* admin/lib/google_metrics.php (cron y pantallas antiguas) lee lo mismo,
       cifrado, a través de la bóveda. */
    public function testLaLibreriaAntiguaDeMetricasLeeLosSecretosCifrados(): void
    {
        require_once __DIR__ . '/../../../admin/lib/google_metrics.php';
        $g = new GoogleOAuth(new HttpFalso());
        $this->configurar($g, 'metricas');
        $this->assertFalse(gm_configurada(), 'Sin refresh token no está configurada');
        self::pdo()->exec("INSERT INTO settings (clave, valor) VALUES ('google_oauth_refresh_token', '1//en-claro-antiguo')");
        $this->assertSame(['id' => 'id-de-prueba.apps.googleusercontent.com', 'secret' => 'secreto-de-prueba'], gm_oauth_cfg());
        $this->assertTrue(gm_configurada());
        /* Lo que quedaba en claro se ha cifrado al leerlo. */
        $this->assertStringStartsWith('bx1:', (string)self::pdo()->query("SELECT valor FROM settings WHERE clave = 'google_oauth_refresh_token'")->fetchColumn());
        $this->assertSame('1//en-claro-antiguo', gm_secreto('google_oauth_refresh_token'));
    }
}
