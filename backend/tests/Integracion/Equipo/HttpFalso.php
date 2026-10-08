<?php
namespace Croilab\Tests\Integracion\Equipo;

use Croilab\Google\Http;
use Croilab\Google\RespuestaHttp;

/* Google de mentira: devuelve las respuestas encoladas y apunta lo que se pidió. */
final class HttpFalso implements Http
{
    /** @var array<int, array{0:string,1:string,2:array,3:?string}> */
    public array $peticiones = [];
    /** @var RespuestaHttp[] */
    public array $cola = [];

    public function encolar(int $estado, array $json): self
    {
        $this->cola[] = new RespuestaHttp($estado, (string)json_encode($json));
        return $this;
    }

    public function enviar(string $metodo, string $url, array $cabeceras = [], ?string $cuerpo = null): RespuestaHttp
    {
        $this->peticiones[] = [$metodo, $url, $cabeceras, $cuerpo];
        return array_shift($this->cola) ?? new RespuestaHttp(500, '{"error":"sin respuesta preparada"}');
    }
}
