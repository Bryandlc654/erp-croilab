<?php
namespace Croilab\Http;

use Croilab\Database\Migrador;

/* Todo lo que pasa en cada petición de la API, en un solo sitio y en orden:
   CORS → preflight → esquema al día → sesión → CSRF → ruta → JSON.
   Cualquier excepción acaba en JSON con su código; nunca sale HTML. */
class Kernel
{
    private const METODOS_CON_CSRF = ['POST', 'PATCH', 'PUT', 'DELETE'];

    public function __construct(private readonly Router $router) {}

    /** @return array{0:int, 1:array<string,string>, 2:string} status, cabeceras, cuerpo */
    public function manejar(Request $req): array
    {
        $cab = $this->cabecerasCors($req);
        if ($req->metodo === 'OPTIONS') return [204, $cab, ''];

        ob_start();   // lo que imprima código antiguo (avisos, echo sueltos) no rompe el JSON
        try {
            if (Migrador::hayPendientes(db())) {
                throw new HttpError(503, 'El servidor se está actualizando. Inténtalo en unos minutos.', 'mantenimiento');
            }
            [$accion, $publica, $conCsrf] = $this->router->resolver($req);
            if ($conCsrf && in_array($req->metodo, self::METODOS_CON_CSRF, true) && !csrf_valid()) throw HttpError::csrf();
            if (!$publica && !current_admin()) throw HttpError::sesion();

            $r = $accion($req);
            $r = $r instanceof Respuesta ? $r : new Respuesta(is_array($r) ? $r : []);
            $status = $r->status;
            $cuerpo = ['ok' => true] + $r->datos;
            $cab += $r->cabeceras;
        } catch (HttpError $e) {
            Diferidas::descartar();   // una petición fallida no deja trabajo pendiente (ni correos)
            $status = $e->status;
            $cuerpo = ['ok' => false, 'msg' => $e->getMessage(), 'error' => $e->codigo] + $e->extra;
            if ($e->status === 405 && isset($e->extra['permitidos'])) $cab['Allow'] = implode(', ', $e->extra['permitidos']);
        } catch (\Throwable $e) {
            Diferidas::descartar();
            error_log(sprintf('API %s %s: %s en %s:%d', $req->metodo, $req->ruta, $e->getMessage(), $e->getFile(), $e->getLine()));
            $status = 500;
            $cuerpo = ['ok' => false, 'msg' => 'Ha ocurrido un error en el servidor.', 'error' => 'interno'];
            if (defined('APP_ENV') && APP_ENV === 'dev') $cuerpo['detalle'] = $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        }
        $sobrante = ob_get_clean();
        if ($sobrante !== '' && $sobrante !== false) error_log('API salida inesperada en ' . $req->ruta . ': ' . substr($sobrante, 0, 500));

        $cab['Content-Type'] = 'application/json; charset=utf-8';
        $cab['Cache-Control'] = 'no-store';
        return [$status, $cab, json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)];
    }

    /** Orígenes permitidos: CORS_ORIGINS (entorno o .env) + el servidor de Vite. */
    public static function origenesPermitidos(): array
    {
        $cfg = getenv('CORS_ORIGINS');
        if ($cfg === false || $cfg === '') $cfg = $GLOBALS['croilab_env']['CORS_ORIGINS'] ?? '';
        return array_values(array_filter(array_map(fn($o) => rtrim(trim($o), '/'), explode(',', 'http://localhost:5173,' . $cfg))));
    }

    private function cabecerasCors(Request $req): array
    {
        $cab = [
            'Vary' => 'Origin',
            'Access-Control-Allow-Headers' => 'Content-Type, X-CSRF-Token, X-Requested-With',
            'Access-Control-Allow-Methods' => 'GET, POST, PATCH, DELETE, OPTIONS',
            'Access-Control-Max-Age' => '600',
        ];
        $origen = $req->cabecera('origin');
        if ($origen !== '' && in_array($origen, self::origenesPermitidos(), true)) {
            $cab['Access-Control-Allow-Origin'] = $origen;
            $cab['Access-Control-Allow-Credentials'] = 'true';
        }
        return $cab;
    }

    /** Envía la respuesta al cliente (lo único que toca la salida real). */
    public static function emitir(array $respuesta): void
    {
        [$status, $cab, $cuerpo] = $respuesta;
        http_response_code($status);
        foreach ($cab as $k => $v) header("$k: $v");
        echo $cuerpo;
    }
}
