<?php
namespace Croilab\Modulos\Comunicacion\Calendario;

use Croilab\Google\Http;
use Croilab\Google\RespuestaHttp;

/* Google de mentira para desarrollo y tests: OAuth (canje, refresco, correo)
   y lo que se usa de Calendar v3 (listar, crear, leer, editar y borrar
   eventos), guardado en un fichero JSON. Sin red ni credenciales reales.

   Solo se usa si APP_ENV=dev y COMUNICACION_GOOGLE_FALSO apunta a un fichero
   (api/rutas/comunicacion.php), o desde los tests. El «código» de la vuelta
   de OAuth es el correo de la cuenta: así cada persona tiene su calendario. */
final class GoogleFalso implements Http
{
    /** @var array<int, array{0:string,1:string,2:array,3:?string}> */
    public array $peticiones = [];
    private array $datos = ['eventos' => []];

    public function __construct(private readonly ?string $fichero = null)
    {
        if ($fichero !== null && is_file($fichero)) {
            $d = json_decode((string)file_get_contents($fichero), true);
            if (is_array($d)) $this->datos = $d + ['eventos' => []];
        }
    }

    public function enviar(string $metodo, string $url, array $cabeceras = [], ?string $cuerpo = null): RespuestaHttp
    {
        $this->peticiones[] = [$metodo, $url, $cabeceras, $cuerpo];
        $u = parse_url($url);
        parse_str((string)($u['query'] ?? ''), $q);
        $ruta = (string)($u['path'] ?? '');
        $host = (string)($u['host'] ?? '');

        if ($host === 'oauth2.googleapis.com' && $ruta === '/token') {
            parse_str((string)$cuerpo, $f);
            $email = ($f['grant_type'] ?? '') === 'refresh_token' ? substr((string)($f['refresh_token'] ?? ''), 4) : (string)($f['code'] ?? '');
            if ($email === 'revocado@ejemplo.test') return self::json(400, ['error' => 'invalid_grant']);
            return self::json(200, ['access_token' => 'falso:' . base64_encode($email), 'expires_in' => 3600, 'refresh_token' => 'ref:' . $email]);
        }
        if ($host === 'oauth2.googleapis.com' && $ruta === '/revoke') return self::json(200, []);
        $email = $this->cuenta($cabeceras);
        if ($email === null) return self::json(401, ['error' => ['message' => 'Sin token']]);
        if (str_ends_with($ruta, '/oauth2/v2/userinfo')) return self::json(200, ['email' => $email, 'verified_email' => true]);

        if (!preg_match('~/calendar/v3/calendars/primary/events(?:/([^/]+))?$~', $ruta, $m)) return self::json(404, ['error' => ['message' => 'No simulado']]);
        $id = isset($m[1]) ? rawurldecode($m[1]) : '';
        $evs = &$this->datos['eventos'][$email];
        $evs ??= [];
        $body = $cuerpo !== null ? (json_decode($cuerpo, true) ?: []) : [];

        if ($id === '' && $metodo === 'GET') {
            $min = isset($q['timeMin']) ? strtotime((string)$q['timeMin']) : 0;
            $max = isset($q['timeMax']) ? strtotime((string)$q['timeMax']) : PHP_INT_MAX;
            $prop = (string)($q['privateExtendedProperty'] ?? '');
            $items = array_values(array_filter($evs, function ($e) use ($min, $max, $prop) {
                if ($prop !== '') {
                    [$k, $v] = array_pad(explode('=', $prop, 2), 2, '');
                    return (string)($e['extendedProperties']['private'][$k] ?? '') === $v;
                }
                $ini = strtotime((string)($e['start']['dateTime'] ?? $e['start']['date'] ?? ''));
                return $ini !== false && $ini <= $max && $ini >= $min - 86400;
            }));
            usort($items, fn($a, $b) => strcmp(self::inicio($a), self::inicio($b)));
            return self::json(200, ['items' => $items]);
        }
        if ($id === '' && $metodo === 'POST') {
            $nuevo = $body + ['id' => bin2hex(random_bytes(10))];
            $nuevo['status'] = 'confirmed';
            $nuevo['organizer'] = ['email' => $email, 'self' => true];
            $nuevo['creator'] = ['email' => $email, 'self' => true];
            $nuevo['htmlLink'] = 'https://calendar.google.com/calendar/event?eid=' . $nuevo['id'];
            if (isset($body['conferenceData']['createRequest'])) {
                $nuevo['hangoutLink'] = 'https://meet.google.com/abc-defg-' . substr($nuevo['id'], 0, 3);
                $nuevo['conferenceData'] = ['entryPoints' => [['entryPointType' => 'video', 'uri' => $nuevo['hangoutLink']]]];
            }
            $evs[$nuevo['id']] = $nuevo;
            $this->guardar();
            return self::json(200, $nuevo);
        }
        if (!isset($evs[$id])) return self::json(404, ['error' => ['message' => 'Not Found']]);
        if ($metodo === 'GET') return self::json(200, $evs[$id]);
        if ($metodo === 'DELETE') {
            unset($evs[$id]);
            $this->guardar();
            return new RespuestaHttp(204, '');
        }
        if ($metodo === 'PATCH') {
            foreach ($body as $k => $v) $evs[$id][$k] = $v;
            if (isset($body['conferenceData']['createRequest'])) {
                $evs[$id]['hangoutLink'] = 'https://meet.google.com/xyz-' . substr($id, 0, 4);
                $evs[$id]['conferenceData'] = ['entryPoints' => [['entryPointType' => 'video', 'uri' => $evs[$id]['hangoutLink']]]];
            }
            $this->guardar();
            return self::json(200, $evs[$id]);
        }
        return self::json(405, ['error' => ['message' => 'Método no simulado']]);
    }

    /** Mete un evento tal cual (para preparar datos de prueba). */
    public function sembrar(string $email, array $evento): array
    {
        $evento += ['id' => bin2hex(random_bytes(10)), 'status' => 'confirmed', 'organizer' => ['email' => $email, 'self' => true]];
        $evento['htmlLink'] ??= 'https://calendar.google.com/calendar/event?eid=' . $evento['id'];
        $this->datos['eventos'][$email][$evento['id']] = $evento;
        $this->guardar();
        return $evento;
    }

    private function cuenta(array $cabeceras): ?string
    {
        foreach ($cabeceras as $c) {
            if (preg_match('/^Authorization:\s*Bearer\s+falso:(\S+)$/i', $c, $m)) return base64_decode($m[1]) ?: null;
        }
        return null;
    }

    private static function inicio(array $e): string
    {
        return (string)($e['start']['dateTime'] ?? $e['start']['date'] ?? '');
    }

    private function guardar(): void
    {
        if ($this->fichero !== null) file_put_contents($this->fichero, json_encode($this->datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private static function json(int $estado, array $d): RespuestaHttp
    {
        return new RespuestaHttp($estado, (string)json_encode($d, JSON_UNESCAPED_UNICODE));
    }
}
