<?php
namespace Croilab\Http;

/* La petición en un objeto: método, ruta, query, cuerpo JSON y parámetros de
   ruta. Se construye desde las superglobales en producción y a mano en los tests. */
class Request
{
    /** @var array<string,string> */
    public array $params = [];
    private ?array $json = null;

    public function __construct(
        public readonly string $metodo,
        public readonly string $ruta,
        public readonly array $query = [],
        private readonly string $cuerpo = '',
        public readonly array $cabeceras = []
    ) {}

    public static function desdeGlobales(string $prefijo = '/api'): self
    {
        $ruta = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        /* Lo que quede antes de /api (subcarpeta del hosting) no forma parte de la ruta. */
        $p = strpos($ruta, $prefijo . '/');
        $ruta = $p === false ? $ruta : substr($ruta, $p + strlen($prefijo));
        $cab = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) $cab[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
        }
        if (isset($_SERVER['CONTENT_TYPE'])) $cab['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . trim($ruta, '/'),
            $_GET,
            (string)file_get_contents('php://input'),
            $cab
        );
    }

    public function cabecera(string $nombre): string
    {
        return $this->cabeceras[strtolower($nombre)] ?? '';
    }

    /** Cuerpo JSON como array. Un cuerpo que no es un objeto JSON es un 400. */
    public function json(): array
    {
        if ($this->json !== null) return $this->json;
        if (trim($this->cuerpo) === '') return $this->json = [];
        $d = json_decode($this->cuerpo, true);
        if (!is_array($d) || array_is_list($d) && $d !== []) throw HttpError::datos('El cuerpo no es un JSON válido.');
        return $this->json = $d;
    }

    public function texto(string $clave, string $def = ''): string
    {
        $v = $this->query[$clave] ?? $def;
        return is_string($v) ? trim($v) : $def;
    }

    public function entero(string $clave, int $def = 0): int
    {
        $v = $this->query[$clave] ?? null;
        return is_scalar($v) && preg_match('/^-?\d+$/', (string)$v) ? (int)$v : $def;
    }

    public function param(string $clave): int
    {
        return (int)($this->params[$clave] ?? 0);
    }

    /** limit/offset acotados: nadie puede pedir la tabla entera de golpe. */
    public function paginacion(int $porDefecto = 50, int $maximo = 200): array
    {
        $limit = max(1, min($maximo, $this->entero('limit', $porDefecto)));
        $offset = max(0, $this->entero('offset', 0));
        return [$limit, $offset];
    }
}
