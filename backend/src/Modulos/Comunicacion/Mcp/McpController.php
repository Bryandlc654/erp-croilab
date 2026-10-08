<?php
namespace Croilab\Modulos\Comunicacion\Mcp;

use Croilab\Http\Request;
use Croilab\Modulos\Comunicacion\Descarga;

/* POST /v1/mcp (ruta pública: se entra con el token, sin sesión).
   La respuesta es JSON-RPC tal cual, no el sobre {ok:…} del resto de la API. */
class McpController
{
    public function __construct(private readonly McpServidor $mcp) {}

    public function atender(Request $req): never
    {
        $auth = $req->cabecera('authorization') ?: (string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        [$status, $cuerpo] = $this->mcp->atender((string)file_get_contents('php://input'), McpServidor::tokenDe($auth, $req->query));
        Descarga::json($status, $cuerpo);
    }

    /* Sin canal SSE: los clientes MCP lo entienden con un 405. */
    public function sinFlujo(Request $req): never
    {
        Descarga::json(405, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32601, 'message' => 'Usa POST.']], ['Allow' => 'POST']);
    }
}
