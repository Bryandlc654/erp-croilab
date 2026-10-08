<?php
namespace Croilab\Modulos\Comunicacion;

/* Respuestas que no son JSON: un fichero (adjuntos del chat) o el JSON-RPC del
   servidor MCP, que tiene su propio formato.

   El Kernel siempre responde `{ok:true, …}` en JSON, así que estas acciones
   escriben la respuesta ellas mismas y terminan la petición. Es lo mismo que
   hacía archivo.php, con las mismas cabeceras de seguridad. Pendiente en el
   Kernel (ver el informe del módulo): admitir una respuesta «cruda» para no
   tener que cortar aquí. En los tests no se llega a esto: se prueban las
   clases que deciden qué servir. */
final class Descarga
{
    /** Envía un fichero del disco y termina. */
    public static function fichero(string $ruta, string $mime, string $nombre, bool $enLinea): never
    {
        self::limpiar();
        $nombreAscii = preg_replace('/[^\x20-\x7e]/', '_', $nombre) ?: 'archivo';
        $nombreAscii = str_replace(['"', '\\'], '_', $nombreAscii);
        http_response_code(200);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($ruta));
        header('Content-Disposition: ' . ($enLinea ? 'inline' : 'attachment') . '; filename="' . $nombreAscii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
        header('Cache-Control: private, max-age=600');
        header('Cross-Origin-Resource-Policy: same-site');
        readfile($ruta);
        exit;
    }

    /** Envía un JSON tal cual (sin el sobre {ok:…}) con su código y termina. */
    public static function json(int $status, ?array $cuerpo, array $cabeceras = []): never
    {
        self::limpiar();
        http_response_code($status);
        header('Cache-Control: no-store');
        foreach ($cabeceras as $k => $v) header("$k: $v");
        if ($cuerpo !== null) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        exit;
    }

    /* Lo que el Kernel tuviera en el búfer de salida no puede colarse delante. */
    private static function limpiar(): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    }
}
