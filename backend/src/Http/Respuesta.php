<?php
namespace Croilab\Http;

/* Lo que devuelve una acción cuando necesita un código distinto de 200 o
   cabeceras propias. Si devuelve un array, se entiende 200. */
class Respuesta
{
    public function __construct(
        public readonly array $datos = [],
        public readonly int $status = 200,
        public readonly array $cabeceras = []
    ) {}
}
