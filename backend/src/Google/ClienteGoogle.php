<?php
namespace Croilab\Google;

/* Llamadas a las APIs de Google (Calendar, Search Console, Analytics…) en
   nombre de una cuenta. Pone el token, lo renueva solo si Google responde 401
   y devuelve el JSON. Lo usan Métricas (este módulo), el calendario y las
   reuniones (Comunicación) y la ficha de métricas de cada cliente (Clientes).

     $g = (new GoogleOAuth())->cliente(Cuenta::calendario($adminId));
     $eventos = $g->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', ['timeMin' => …]);

   Un error de Google lanza ErrorGoogle con el mensaje que da Google. */
final class ClienteGoogle
{
    public function __construct(
        private readonly GoogleOAuth $oauth,
        public readonly Cuenta $cuenta,
        private readonly Http $http
    ) {}

    public function get(string $url, array $query = []): array
    {
        return $this->peticion('GET', $url, null, $query);
    }

    public function post(string $url, ?array $json = null, array $query = []): array
    {
        return $this->peticion('POST', $url, $json, $query);
    }

    public function patch(string $url, ?array $json = null, array $query = []): array
    {
        return $this->peticion('PATCH', $url, $json, $query);
    }

    public function delete(string $url, array $query = []): array
    {
        return $this->peticion('DELETE', $url, null, $query);
    }

    /** Respuesta en bruto (código + cuerpo), por si hace falta mirar el código (p. ej. 410 al borrar). */
    public function bruta(string $metodo, string $url, ?array $json = null, array $query = []): RespuestaHttp
    {
        if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        $cuerpo = $json === null ? null : (string)json_encode($json, JSON_UNESCAPED_UNICODE);
        $r = $this->http->enviar($metodo, $url, $this->cabeceras($this->oauth->tokenAcceso($this->cuenta), $json !== null), $cuerpo);
        if ($r->estado === 401) {
            /* Token caducado antes de tiempo: se renueva una vez y se repite. */
            $r = $this->http->enviar($metodo, $url, $this->cabeceras($this->oauth->tokenAcceso($this->cuenta, true), $json !== null), $cuerpo);
        }
        return $r;
    }

    private function peticion(string $metodo, string $url, ?array $json, array $query): array
    {
        $r = $this->bruta($metodo, $url, $json, $query);
        if ($r->estado === 0) throw new ErrorGoogle('red', 'No se ha podido contactar con Google.');
        if (!$r->ok()) {
            $j = $r->json();
            $msg = $j['error']['message'] ?? $j['error_description'] ?? (is_string($j['error'] ?? null) ? $j['error'] : 'Error ' . $r->estado);
            throw new ErrorGoogle('google', (string)$msg, $r->estado);
        }
        return $r->json();
    }

    private function cabeceras(string $token, bool $conJson): array
    {
        $c = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        if ($conJson) $c[] = 'Content-Type: application/json';
        return $c;
    }
}
