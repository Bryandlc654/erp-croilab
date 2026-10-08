<?php
namespace Croilab\Google;

/* Cómo se habla con Google por HTTP. Es una interfaz para que los tests puedan
   sustituir la red por respuestas preparadas (ver tests/Unit/Equipo). */
interface Http
{
    /** @param string[] $cabeceras líneas «Nombre: valor» */
    public function enviar(string $metodo, string $url, array $cabeceras = [], ?string $cuerpo = null): RespuestaHttp;
}
