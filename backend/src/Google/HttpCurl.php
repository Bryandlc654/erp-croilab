<?php
namespace Croilab\Google;

/* Transporte real con cURL. Tiempos cortos: una petición de pantalla no puede
   quedarse colgada esperando a Google. */
final class HttpCurl implements Http
{
    public function __construct(private readonly int $timeout = 20) {}

    public function enviar(string $metodo, string $url, array $cabeceras = [], ?string $cuerpo = null): RespuestaHttp
    {
        if (!function_exists('curl_init')) return new RespuestaHttp(0, '', 'Falta la extensión cURL de PHP.');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $cabeceras,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        $out = curl_exec($ch);
        $estado = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return new RespuestaHttp($estado, is_string($out) ? $out : '', $err);
    }
}
