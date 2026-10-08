<?php
namespace Croilab\Modulos\Comunicacion\Chat;

use Croilab\Http\HttpError;
use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Modulos\Comunicacion\Descarga;
use Croilab\Seguridad\Acceso;

/* Traduce HTTP ⇄ ChatServicio. */
class ChatController
{
    public function __construct(private readonly ChatServicio $chat) {}

    public function salas(Request $req): array
    {
        return $this->chat->salas(Acceso::actual(), $req->texto('activo', '1') !== '0');
    }

    public function sala(Request $req): array
    {
        return ['sala' => $this->chat->verSala(Acceso::actual(), $req->param('id'))];
    }

    /* {tipo:'dm', con} → sala existente o nueva · {tipo:'grupo', nombre, miembros[]} */
    public function crear(Request $req): Respuesta
    {
        $acc = Acceso::actual();
        $d = $req->json();
        $tipo = (string)($d['tipo'] ?? '');
        if ($tipo === 'dm') {
            $id = $this->chat->abrirDirecto($acc, (int)($d['con'] ?? 0));
        } elseif ($tipo === 'grupo') {
            $miembros = is_array($d['miembros'] ?? null) ? $d['miembros'] : [];
            $id = $this->chat->crearGrupo($acc, (string)($d['nombre'] ?? ''), $miembros);
        } else {
            throw HttpError::validacion('Tipo de conversación desconocido.', 'tipo');
        }
        return new Respuesta(['sala' => $this->chat->verSala($acc, $id)], 201);
    }

    public function renombrar(Request $req): array
    {
        return ['sala' => $this->chat->renombrar(Acceso::actual(), $req->param('id'), (string)($req->json()['nombre'] ?? ''))];
    }

    public function agregarMiembro(Request $req): array
    {
        return ['sala' => $this->chat->agregarMiembro(Acceso::actual(), $req->param('id'), (int)($req->json()['admin_id'] ?? 0))];
    }

    public function quitarMiembro(Request $req): array
    {
        return ['sala' => $this->chat->quitarMiembro(Acceso::actual(), $req->param('id'), $req->param('admin'))];
    }

    public function historial(Request $req): array
    {
        return $this->chat->historial(Acceso::actual(), $req->param('id'), $req->entero('antes'), $req->entero('limit', 50));
    }

    public function novedades(Request $req): array
    {
        return $this->chat->novedades(Acceso::actual(), $req->param('id'), $req->entero('despues'), $req->texto('cursor'),
            $req->texto('activo', '1') !== '0', $req->texto('leer', '1') !== '0');
    }

    /* JSON {texto, responde_a?} o multipart {texto, responde_a?, adjuntos[]}. */
    public function enviar(Request $req): Respuesta
    {
        $multipart = str_starts_with(strtolower($req->cabecera('content-type')), 'multipart/form-data');
        if ($multipart) {
            $texto = (string)($_POST['texto'] ?? '');
            $respondeA = (int)($_POST['responde_a'] ?? 0);
            $ficheros = Adjuntos::desdeFiles($_FILES['adjuntos'] ?? null);
        } else {
            $d = $req->json();
            $texto = (string)($d['texto'] ?? '');
            $respondeA = (int)($d['responde_a'] ?? 0);
            $ficheros = [];
        }
        return new Respuesta(['mensaje' => $this->chat->enviar(Acceso::actual(), $req->param('id'), $texto, $respondeA ?: null, $ficheros)], 201);
    }

    public function editar(Request $req): array
    {
        return ['mensaje' => $this->chat->editar(Acceso::actual(), $req->param('id'), (string)($req->json()['texto'] ?? ''))];
    }

    public function borrar(Request $req): array
    {
        return ['mensaje' => $this->chat->borrar(Acceso::actual(), $req->param('id'))];
    }

    public function reaccionar(Request $req): array
    {
        return ['mensaje' => $this->chat->reaccionar(Acceso::actual(), $req->param('id'), (string)($req->json()['emoji'] ?? ''))];
    }

    public function escribiendo(Request $req): array
    {
        $this->chat->escribiendo(Acceso::actual(), $req->param('id'));
        return [];
    }

    public function leido(Request $req): array
    {
        $this->chat->marcarLeido(Acceso::actual(), $req->param('id'), (int)($req->json()['hasta'] ?? 0));
        return [];
    }

    public function avisos(Request $req): array
    {
        /* Sin ?despues= es la primera consulta: solo la línea base. */
        return $this->chat->avisos(Acceso::actual(), $req->entero('despues', -1), $req->texto('activo', '1') !== '0');
    }

    /* Descarga de un adjunto (no es JSON: termina la petición aquí). */
    public function adjunto(Request $req): never
    {
        [$ruta, $mime, $nombre, $enLinea] = $this->chat->adjunto(Acceso::actual(), $req->param('id'), $req->param('indice'));
        Descarga::fichero($ruta, $mime, $nombre, $enLinea && $req->texto('dl') !== '1');
    }

    /* Presencia de todo el equipo (para los puntos de los avatares). */
    public function presencia(Request $req): array
    {
        return ['presencia' => $this->chat->presenciaEquipo(Acceso::actual(), $req->texto('activo', '1') !== '0')];
    }
}
