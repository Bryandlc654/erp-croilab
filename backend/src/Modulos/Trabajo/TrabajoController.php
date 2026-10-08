<?php
namespace Croilab\Modulos\Trabajo;

use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

/* Inicio, avisos, búsqueda, papelera y actas. Sin reglas de negocio aquí. */
class TrabajoController
{
    public function __construct(
        private readonly InicioServicio $inicio,
        private readonly NotificacionesServicio $avisos,
        private readonly BuscarServicio $buscador,
        private readonly PapeleraServicio $papelera,
        private readonly ActasServicio $actas
    ) {}

    /* ---------- Inicio ---------- */

    public function inicio(Request $req): array
    {
        return $this->inicio->resumen(Acceso::actual());
    }

    public function agenda(Request $req): array
    {
        return $this->inicio->agenda(Acceso::actual()->adminId);
    }

    /* ---------- Avisos ---------- */

    public function notificaciones(Request $req): array
    {
        [$limit, $offset] = $req->paginacion(100, 300);
        return $this->avisos->listar(Acceso::actual(), $req->texto('bandeja', 'principal'), $limit, $offset);
    }

    public function sondeo(Request $req): array
    {
        return $this->avisos->avisos(Acceso::actual(), max(0, $req->entero('despues')));
    }

    public function accionAvisos(Request $req): array
    {
        return $this->avisos->accion(Acceso::actual(), $req->json());
    }

    public function leerTodas(Request $req): array
    {
        return $this->avisos->leerTodas(Acceso::actual());
    }

    public function leidasAPapelera(Request $req): array
    {
        return $this->avisos->leidasAPapelera(Acceso::actual());
    }

    public function vaciarAvisos(Request $req): array
    {
        return $this->avisos->vaciarPapelera(Acceso::actual());
    }

    /* ---------- Búsqueda ---------- */

    public function buscar(Request $req): array
    {
        return $this->buscador->buscar(Acceso::actual(), $req->texto('q'), $req->entero('por_grupo', 5));
    }

    /* ---------- Papelera ---------- */

    public function papelera(Request $req): array
    {
        [$limit, $offset] = $req->paginacion(100, 300);
        return $this->papelera->listar(Acceso::actual(), $limit, $offset);
    }

    public function restaurar(Request $req): array
    {
        return $this->papelera->restaurar(Acceso::actual(), $req->param('id'));
    }

    public function purgar(Request $req): array
    {
        $this->papelera->purgar(Acceso::actual(), $req->param('id'));
        return [];
    }

    public function vaciarPapelera(Request $req): array
    {
        return ['borrados' => $this->papelera->vaciar(Acceso::actual())];
    }

    /* ---------- Actas ---------- */

    public function actas(Request $req): array
    {
        [$limit, $offset] = $req->paginacion(100, 300);
        return $this->actas->listar(Acceso::actual(), $req->texto('q'), $req->entero('autor'), $limit, $offset);
    }

    public function acta(Request $req): array
    {
        return ['acta' => $this->actas->ver(Acceso::actual(), $req->param('id'))];
    }

    public function crearActa(Request $req): Respuesta
    {
        return new Respuesta(['acta' => $this->actas->crear(Acceso::actual(), $req->json())], 201);
    }

    public function guardarActa(Request $req): array
    {
        return ['acta' => $this->actas->guardar(Acceso::actual(), $req->param('id'), $req->json())];
    }

    public function fijarActa(Request $req): array
    {
        $d = $req->json();
        $f = array_key_exists('fijada', $d) ? filter_var($d['fijada'], FILTER_VALIDATE_BOOLEAN) : null;
        return ['acta' => $this->actas->fijar(Acceso::actual(), $req->param('id'), $f)];
    }

    public function borrarActa(Request $req): array
    {
        return ['papelera_id' => $this->actas->borrar(Acceso::actual(), $req->param('id'))];
    }
}
