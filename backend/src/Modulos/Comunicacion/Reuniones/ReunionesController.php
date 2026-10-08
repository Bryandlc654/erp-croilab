<?php
namespace Croilab\Modulos\Comunicacion\Reuniones;

use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

/* Traduce HTTP ⇄ ReunionesServicio. */
class ReunionesController
{
    public function __construct(private readonly ReunionesServicio $reuniones) {}

    public function listar(Request $req): array
    {
        return $this->reuniones->listar(Acceso::actual(), $req->texto('vista', 'me'));
    }

    public function agendar(Request $req): Respuesta
    {
        return new Respuesta($this->reuniones->agendar(Acceso::actual(), $req->json()), 201);
    }

    /* {event_id, contact_id|null} */
    public function asignar(Request $req): array
    {
        $d = $req->json();
        $this->reuniones->asignar(Acceso::actual(), (string)($d['event_id'] ?? ''), ((int)($d['contact_id'] ?? 0)) ?: null);
        return [];
    }

    public function contactos(Request $req): array
    {
        return ['grupos' => $this->reuniones->contactos(Acceso::actual(), $req->texto('q'))];
    }

    public function destinatario(Request $req): array
    {
        return ['destinatario' => $this->reuniones->destinatario(Acceso::actual(), $req->entero('cli'), $req->entero('contacto'))];
    }

    public function solicitudes(Request $req): array
    {
        return ['items' => $this->reuniones->solicitudes(Acceso::actual())];
    }

    public function aprobar(Request $req): array
    {
        return $this->reuniones->aprobar(Acceso::actual(), $req->param('id'), $req->json());
    }

    public function rechazar(Request $req): array
    {
        $this->reuniones->rechazar(Acceso::actual(), $req->param('id'));
        return ['msg' => 'Solicitud rechazada.'];
    }
}
