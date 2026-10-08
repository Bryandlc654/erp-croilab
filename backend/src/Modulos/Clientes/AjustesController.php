<?php
namespace Croilab\Modulos\Clientes;

use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

/* Lo de Clientes que vive en Ajustes: tipos, catálogo de servicios, marca
   blanca, y por cliente sus métricas de Google y los datos avanzados. */
class AjustesController
{
    public function __construct(
        private readonly TiposServicio $tipos,
        private readonly ServiciosServicio $servicios,
        private readonly AgenciasServicio $agencias,
        private readonly GoogleServicio $google,
        private readonly AvanzadoServicio $avanzado
    ) {}

    /* ---------- Tipos ---------- */

    public function tipos(Request $req): array
    {
        return ['items' => $this->tipos->listar(Acceso::actual())];
    }

    public function tipo(Request $req): array
    {
        return ['tipo' => $this->tipos->ver(Acceso::actual(), $req->param('id'))];
    }

    public function crearTipo(Request $req): Respuesta
    {
        [$tipo, $dup] = $this->tipos->crear(Acceso::actual(), $req->json());
        return new Respuesta(['tipo' => $tipo, 'dup' => $dup], $dup ? 200 : 201);
    }

    public function actualizarTipo(Request $req): array
    {
        return ['tipo' => $this->tipos->actualizar(Acceso::actual(), $req->param('id'), $req->json())];
    }

    public function borrarTipo(Request $req): array
    {
        return ['sin_tipo' => $this->tipos->borrar(Acceso::actual(), $req->param('id'))];
    }

    /* ---------- Servicios ---------- */

    public function servicios(Request $req): array
    {
        return $this->servicios->listar(Acceso::actual());
    }

    public function guardarServicios(Request $req): array
    {
        $acc = Acceso::actual();
        $r = $this->servicios->guardar($acc, $req->json()['servicios'] ?? null);
        return $this->servicios->listar($acc) + $r;
    }

    /* ---------- Agencias ---------- */

    public function agencias(Request $req): array
    {
        return $this->agencias->listar(Acceso::actual());
    }

    public function crearAgencia(Request $req): Respuesta
    {
        return new Respuesta(['agencia' => $this->agencias->crear(Acceso::actual(), $req->json())], 201);
    }

    public function actualizarAgencia(Request $req): array
    {
        return ['agencia' => $this->agencias->actualizar(Acceso::actual(), $req->param('id'), $req->json())];
    }

    public function borrarAgencia(Request $req): array
    {
        $this->agencias->borrar(Acceso::actual(), $req->param('id'));
        return [];
    }

    public function asignarAgencia(Request $req): array
    {
        $d = $req->json();
        $this->agencias->asignar(Acceso::actual(), $req->param('id'), $d['partner_id'] ?? null);
        return [];
    }

    /* ---------- Google ---------- */

    public function google(Request $req): array
    {
        return $this->google->ver(Acceso::actual(), $req->param('id'));
    }

    public function guardarGoogle(Request $req): array
    {
        $acc = Acceso::actual();
        $this->google->guardar($acc, $req->param('id'), $req->json());
        return $this->google->ver($acc, $req->param('id'));
    }

    public function eventosGoogle(Request $req): array
    {
        return ['eventos' => $this->google->eventos(Acceso::actual(), $req->param('id'), mb_substr($req->texto('prop'), 0, 40))];
    }

    public function sincronizarGoogle(Request $req): array
    {
        $acc = Acceso::actual();
        $msg = $this->google->sincronizar($acc, $req->param('id'));
        return ['msg' => $msg] + $this->google->ver($acc, $req->param('id'));
    }

    /* ---------- Datos avanzados ---------- */
    /* La contraseña se confirma en POST /v1/auth/reconfirmar {zona:"datos"} (Equipo). */

    public function bloquear(Request $req): array
    {
        $this->avanzado->bloquear();
        return [];
    }

    public function avanzado(Request $req): array
    {
        return ['datos' => $this->avanzado->ver(Acceso::actual(), $req->param('id'))];
    }

    public function guardarAvanzado(Request $req): array
    {
        $acc = Acceso::actual();
        $this->avanzado->guardar($acc, $req->param('id'), $req->json());
        return ['datos' => $this->avanzado->ver($acc, $req->param('id'))];
    }
}
