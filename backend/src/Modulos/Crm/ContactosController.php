<?php
namespace Croilab\Modulos\Crm;

use Croilab\Http\HttpError;
use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

/* Contactos y su ficha: HTTP ⇄ servicios. Sin reglas de negocio aquí. */
final class ContactosController
{
    public function __construct(
        private readonly ContactosServicio $contactos,
        private readonly FichaServicio $ficha,
        private readonly ConversionServicio $conversion,
        private readonly ImportarServicio $importar
    ) {}

    public function listar(Request $req): array
    {
        [$limit, $offset] = $req->paginacion(300, 1000);
        return $this->contactos->listar(Acceso::actual(), $req->query, $limit, $offset);
    }

    public function crear(Request $req): Respuesta
    {
        return new Respuesta(['contacto' => $this->contactos->crear(Acceso::actual(), $req->json())], 201);
    }

    public function ver(Request $req): array
    {
        return $this->ficha->ficha(Acceso::actual(), $req->param('id'));
    }

    public function actualizar(Request $req): array
    {
        return ['contacto' => $this->contactos->actualizar(Acceso::actual(), $req->param('id'), $req->json())];
    }

    public function borrar(Request $req): array
    {
        return ['papelera_id' => $this->contactos->borrar(Acceso::actual(), $req->param('id'))];
    }

    public function restaurar(Request $req): array
    {
        [$tipo, $id] = $this->contactos->restaurar(Acceso::actual(), $req->param('id'));
        return ['tipo' => $tipo, 'id' => $id];
    }

    public function lote(Request $req): array
    {
        return $this->contactos->lote(Acceso::actual(), $req->json());
    }

    public function exportar(Request $req): array
    {
        return $this->contactos->exportar(Acceso::actual(), $req->query);
    }

    public function ponerEtiqueta(Request $req): array
    {
        return ['contacto' => $this->contactos->etiqueta(Acceso::actual(), $req->param('id'), $req->param('tag'), true)];
    }

    public function quitarEtiqueta(Request $req): array
    {
        return ['contacto' => $this->contactos->etiqueta(Acceso::actual(), $req->param('id'), $req->param('tag'), false)];
    }

    public function facturacion(Request $req): array
    {
        return ['facturacion' => $this->ficha->facturacion(Acceso::actual(), $req->param('id'), $req->json())];
    }

    public function comentar(Request $req): Respuesta
    {
        return new Respuesta($this->ficha->comentar(Acceso::actual(), $req->param('id'), $req->json()), 201);
    }

    public function borrarComentario(Request $req): array
    {
        return $this->ficha->borrarComentario(Acceso::actual(), $req->param('id'));
    }

    public function actividad(Request $req): array
    {
        $this->ficha->interaccion(Acceso::actual(), $req->param('id'), $req->json());
        return [];
    }

    public function crearPropuesta(Request $req): Respuesta
    {
        return new Respuesta(['propuestas' => $this->ficha->crearPropuesta(Acceso::actual(), $req->param('id'), $req->json())], 201);
    }

    public function actualizarPropuesta(Request $req): array
    {
        return ['propuestas' => $this->ficha->actualizarPropuesta(Acceso::actual(), $req->param('id'), $req->json())];
    }

    public function borrarPropuesta(Request $req): array
    {
        return ['propuestas' => $this->ficha->borrarPropuesta(Acceso::actual(), $req->param('id'))];
    }

    /** multipart/form-data con el campo `archivo`. */
    public function subirAdjunto(Request $req): Respuesta
    {
        return new Respuesta(['adjuntos' => $this->ficha->subirAdjunto(Acceso::actual(), $req->param('id'), $_FILES['archivo'] ?? null)], 201);
    }

    public function borrarAdjunto(Request $req): array
    {
        return ['adjuntos' => $this->ficha->borrarAdjunto(Acceso::actual(), $req->param('id'))];
    }

    public function convertir(Request $req): array
    {
        return $this->conversion->convertir(Acceso::actual(), $req->param('id'));
    }

    public function plantilla(Request $req): array
    {
        return $this->importar->plantilla(Acceso::actual());
    }

    /**
     * multipart/form-data: `csv` (fichero), `prueba` (1 = solo validar),
     * `mapeo` (JSON {columna: campo}, opcional) y `omitir_duplicados` (1/0).
     */
    public function importar(Request $req): array
    {
        $acc = Acceso::actual();
        $acc->exigir('ver.crm', 'general.editar', 'crm.crear');
        $mapeo = null;
        if (isset($_POST['mapeo']) && is_string($_POST['mapeo']) && trim($_POST['mapeo']) !== '') {
            $mapeo = json_decode($_POST['mapeo'], true);
            if (!is_array($mapeo)) throw HttpError::validacion('Mapeo no válido.', 'mapeo');
        }
        $contenido = $this->importar->leerSubida($_FILES['csv'] ?? null);
        $si = fn(string $k) => in_array((string)($_POST[$k] ?? ''), ['1', 'true', 'si'], true);
        return $this->importar->importar($acc, $contenido, $mapeo, $si('prueba'), $si('omitir_duplicados'));
    }
}
