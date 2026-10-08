<?php
namespace Croilab\Modulos\Comunicacion\Calendario;

use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

/* Traduce HTTP ⇄ CalendarioServicio. Los ids de Google son texto: el router
   solo admite parámetros numéricos, así que van en el cuerpo o en ?id=. */
class CalendarioController
{
    public function __construct(private readonly CalendarioServicio $cal) {}

    public function datos(Request $req): array
    {
        $equipo = array_values(array_filter(array_map('intval', explode(',', $req->texto('equipo')))));
        return $this->cal->datos(Acceso::actual(), $req->texto('desde'), $req->texto('hasta'), $equipo);
    }

    public function crear(Request $req): Respuesta
    {
        return new Respuesta($this->cal->crear(Acceso::actual(), $req->json()), 201);
    }

    public function actualizar(Request $req): array
    {
        return $this->cal->actualizar(Acceso::actual(), $req->json());
    }

    public function borrar(Request $req): array
    {
        return ['msg' => $this->cal->borrar(Acceso::actual(), $req->texto('id'), $req->texto('avisar', '1') !== '0')];
    }

    public function correos(Request $req): array
    {
        return ['items' => $this->cal->correos(Acceso::actual())];
    }
}
