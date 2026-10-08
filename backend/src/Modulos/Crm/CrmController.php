<?php
namespace Croilab\Modulos\Crm;

use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

/* Negocios, configuración, listas, dashboard y seguimientos: HTTP ⇄ servicios. */
final class CrmController
{
    public function __construct(
        private readonly NegociosServicio $negocios,
        private readonly ConfigServicio $config,
        private readonly ListasServicio $listas,
        private readonly DashboardServicio $dashboard,
        private readonly SeguimientosServicio $seguimientos,
        private readonly ContactosServicio $contactos,
        private readonly ConversionServicio $conversion
    ) {}

    /* ---------- Catálogos y configuración ---------- */

    public function catalogos(Request $req): array
    {
        return $this->config->catalogos(Acceso::actual());
    }

    public function crearFase(Request $req): Respuesta
    {
        return new Respuesta(['fases' => $this->config->crearFase(Acceso::actual(), $req->json())], 201);
    }

    public function actualizarFase(Request $req): array
    {
        return ['fases' => $this->config->actualizarFase(Acceso::actual(), $req->param('id'), $req->json())];
    }

    public function ordenFases(Request $req): array
    {
        return ['fases' => $this->config->ordenFases(Acceso::actual(), $req->json())];
    }

    public function borrarFase(Request $req): array
    {
        return $this->config->borrarFase(Acceso::actual(), $req->param('id'));
    }

    public function crearEtiqueta(Request $req): Respuesta
    {
        return new Respuesta($this->config->crearEtiqueta(Acceso::actual(), $req->json()), 201);
    }

    public function actualizarEtiqueta(Request $req): array
    {
        return $this->config->actualizarEtiqueta(Acceso::actual(), $req->param('id'), $req->json());
    }

    public function borrarEtiqueta(Request $req): array
    {
        return $this->config->borrarEtiqueta(Acceso::actual(), $req->param('id'));
    }

    public function sectores(Request $req): array
    {
        return $this->config->guardarSectores(Acceso::actual(), $req->json());
    }

    public function vistas(Request $req): array
    {
        return ['vistas' => $this->config->vistas(Acceso::actual())];
    }

    public function crearVista(Request $req): Respuesta
    {
        return new Respuesta(['vistas' => $this->config->crearVista(Acceso::actual(), $req->json())], 201);
    }

    public function borrarVista(Request $req): array
    {
        return ['vistas' => $this->config->borrarVista(Acceso::actual(), $req->param('id'))];
    }

    /* ---------- Negocios ---------- */

    public function negocios(Request $req): array
    {
        return $this->negocios->listar(Acceso::actual(), $req->entero('archivados') === 1);
    }

    public function crearNegocio(Request $req): Respuesta
    {
        return new Respuesta(['negocio' => $this->negocios->crear(Acceso::actual(), $req->json())], 201);
    }

    public function negocio(Request $req): array
    {
        return ['negocio' => $this->negocios->detalle(Acceso::actual(), $req->param('id'))];
    }

    public function actualizarNegocio(Request $req): array
    {
        return ['negocio' => $this->negocios->actualizar(Acceso::actual(), $req->param('id'), $req->json())];
    }

    public function moverNegocio(Request $req): array
    {
        return ['negocio' => $this->negocios->mover(Acceso::actual(), $req->param('id'), $req->json())];
    }

    public function perderNegocio(Request $req): array
    {
        return ['negocio' => $this->negocios->perder(Acceso::actual(), $req->param('id'), $req->json())];
    }

    public function borrarNegocio(Request $req): array
    {
        return ['papelera_id' => $this->negocios->borrar(Acceso::actual(), $req->param('id'))];
    }

    public function etiquetaNegocio(Request $req): array
    {
        return ['negocio' => $this->negocios->etiqueta(Acceso::actual(), $req->param('id'), $req->param('tag'), true)];
    }

    public function quitarEtiquetaNegocio(Request $req): array
    {
        return ['negocio' => $this->negocios->etiqueta(Acceso::actual(), $req->param('id'), $req->param('tag'), false)];
    }

    public function convertirNegocio(Request $req): array
    {
        $acc = Acceso::actual();
        $d = $this->negocios->cruda($acc, $req->param('id'));
        return $this->conversion->convertir($acc, (int)$d['contact_id'], (int)$d['id']);
    }

    /* ---------- Listas ---------- */

    public function listas(Request $req): array
    {
        return ['listas' => $this->listas->listar(Acceso::actual())];
    }

    public function crearLista(Request $req): Respuesta
    {
        return new Respuesta($this->listas->crear(Acceso::actual(), $req->json()), 201);
    }

    public function lista(Request $req): array
    {
        return $this->listas->ver(Acceso::actual(), $req->param('id'));
    }

    public function actualizarLista(Request $req): array
    {
        return $this->listas->actualizar(Acceso::actual(), $req->param('id'), $req->json());
    }

    public function borrarLista(Request $req): array
    {
        $this->listas->borrar(Acceso::actual(), $req->param('id'));
        return [];
    }

    public function congelarLista(Request $req): array
    {
        return $this->listas->congelar(Acceso::actual(), $req->param('id'));
    }

    public function ponerMiembro(Request $req): array
    {
        return $this->listas->miembro(Acceso::actual(), $req->param('id'), $req->param('contacto'), true);
    }

    public function quitarMiembro(Request $req): array
    {
        return $this->listas->miembro(Acceso::actual(), $req->param('id'), $req->param('contacto'), false);
    }

    public function ordenListas(Request $req): array
    {
        return ['listas' => $this->listas->orden(Acceso::actual(), $req->json())];
    }

    public function exportarLista(Request $req): array
    {
        return $this->listas->exportar(Acceso::actual(), $req->param('id'));
    }

    /* ---------- Dashboard ---------- */

    public function dashboard(Request $req): array
    {
        return $this->dashboard->datos(Acceso::actual(), $req->texto('desde'), $req->texto('hasta'));
    }

    /* ---------- Seguimientos ---------- */

    public function seguimientos(Request $req): array
    {
        return $this->seguimientos->bandeja(Acceso::actual());
    }

    public function crearSeguimiento(Request $req): Respuesta
    {
        return new Respuesta($this->seguimientos->crear(Acceso::actual(), $req->json(), $this->contactos), 201);
    }

    public function seguimientoHecho(Request $req): array
    {
        $this->seguimientos->hecho(Acceso::actual(), $req->param('id'));
        return [];
    }

    public function posponerSeguimiento(Request $req): array
    {
        $this->seguimientos->posponer(Acceso::actual(), $req->param('id'), $req->json()['dias'] ?? null);
        return [];
    }

    public function omitirSeguimiento(Request $req): array
    {
        $this->seguimientos->omitir(Acceso::actual(), $req->param('id'));
        return [];
    }

    public function generarSeguimientos(Request $req): array
    {
        $acc = Acceso::actual();
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $n = $this->seguimientos->generar();
        return ['creados' => $n] + $this->seguimientos->bandeja($acc);
    }

    public function resumen(Request $req): array
    {
        return $this->seguimientos->resumen(Acceso::actual());
    }

    /** «Ejecutar resumen» a mano: forzado (aunque sea fin de semana o ya se hiciera hoy). */
    public function ejecutarResumen(Request $req): array
    {
        $acc = Acceso::actual();
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        return ['resultado' => $this->seguimientos->ejecutarResumen(true, true)] + $this->seguimientos->bandeja($acc);
    }
}
