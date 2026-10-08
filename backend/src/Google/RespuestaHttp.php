<?php
namespace Croilab\Google;

/* Respuesta de Google: código HTTP, cuerpo y, si no hubo respuesta, el error de red. */
final class RespuestaHttp
{
    public function __construct(
        public readonly int $estado,
        public readonly string $cuerpo,
        public readonly string $errorRed = ''
    ) {}

    public function json(): array
    {
        $d = json_decode($this->cuerpo, true);
        return is_array($d) ? $d : [];
    }

    public function ok(): bool
    {
        return $this->estado >= 200 && $this->estado < 300;
    }
}
