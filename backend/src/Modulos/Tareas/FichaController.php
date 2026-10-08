<?php
namespace Croilab\Modulos\Tareas;

use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

/* La ficha de una tarea: checklist, comentarios, adjuntos y horas. Los
   comentarios y las subidas llegan en multipart (campos en $_POST, ficheros
   en $_FILES['archivos'] / $_FILES['archivo']); el resto, en JSON. */
class FichaController
{
    public function __construct(private readonly FichaServicio $servicio) {}

    public function ver(Request $req): array
    {
        return ['tarea' => $this->servicio->ficha(Acceso::actual(), $req->param('id'))];
    }

    public function comentarios(Request $req): array
    {
        return $this->servicio->comentarios(Acceso::actual(), $req->param('id'), max(0, $req->entero('despues')));
    }

    public function comentar(Request $req): Respuesta
    {
        $d = $this->campos($req);
        $c = $this->servicio->comentar(Acceso::actual(), $req->param('id'), $d, ArchivosTarea::deFiles($_FILES['archivos'] ?? null));
        return new Respuesta(['comentario' => $c], 201);
    }

    public function editarComentario(Request $req): array
    {
        return ['comentario' => $this->servicio->editarComentario(Acceso::actual(), $req->param('id'), $req->param('cid'), $req->json())];
    }

    public function borrarComentario(Request $req): array
    {
        $this->servicio->borrarComentario(Acceso::actual(), $req->param('id'), $req->param('cid'));
        return [];
    }

    public function marcarPuntoComentario(Request $req): array
    {
        $d = $req->json();
        $hecho = array_key_exists('done', $d) ? filter_var($d['done'], FILTER_VALIDATE_BOOLEAN) : null;
        return ['checklist' => $this->servicio->marcarPuntoComentario(Acceso::actual(), $req->param('id'), $req->param('cid'), $req->param('idx'), $hecho)];
    }

    public function reaccionar(Request $req): array
    {
        return ['reacciones' => $this->servicio->reaccionar(Acceso::actual(), $req->param('id'), $req->param('cid'), $req->json()['emoji'] ?? null)];
    }

    public function crearPunto(Request $req): Respuesta
    {
        return new Respuesta(['checklist' => $this->servicio->crearPunto(Acceso::actual(), $req->param('id'), $req->json())], 201);
    }

    public function cambiarPunto(Request $req): array
    {
        return ['checklist' => $this->servicio->cambiarPunto(Acceso::actual(), $req->param('id'), $req->param('chk'), $req->json())];
    }

    public function borrarPunto(Request $req): array
    {
        return ['checklist' => $this->servicio->borrarPunto(Acceso::actual(), $req->param('id'), $req->param('chk'))];
    }

    public function ordenarPuntos(Request $req): array
    {
        return ['checklist' => $this->servicio->ordenarPuntos(Acceso::actual(), $req->param('id'), $req->json()['ids'] ?? null)];
    }

    public function adjuntar(Request $req): Respuesta
    {
        $f = ArchivosTarea::deFiles($_FILES['archivos'] ?? $_FILES['archivo'] ?? null);
        return new Respuesta(['adjuntos' => $this->servicio->adjuntar(Acceso::actual(), $req->param('id'), $f)], 201);
    }

    public function quitarAdjunto(Request $req): array
    {
        return ['adjuntos' => $this->servicio->quitarAdjunto(Acceso::actual(), $req->param('id'), $req->param('aid'))];
    }

    public function subirParaDescripcion(Request $req): Respuesta
    {
        $f = ArchivosTarea::deFiles($_FILES['archivos'] ?? $_FILES['archivo'] ?? null);
        return new Respuesta(['archivos' => $this->servicio->subirParaDescripcion(Acceso::actual(), $req->param('id'), $f)], 201);
    }

    public function tiempo(Request $req): array
    {
        return ['tiempo' => $this->servicio->fijarTiempo(Acceso::actual(), $req->param('id'), $req->json())];
    }

    /* JSON si viene en JSON; si no, los campos del formulario multipart. */
    private function campos(Request $req): array
    {
        return str_contains(strtolower($req->cabecera('content-type')), 'application/json') ? $req->json() : $_POST;
    }
}
