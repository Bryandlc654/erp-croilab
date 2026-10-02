<?php
namespace Croilab\Correo;

/* Un correo listo para enviar: destinatario, asunto y cuerpo en texto y HTML. */
final class Mensaje
{
    public function __construct(
        public readonly string $para,
        public readonly string $asunto,
        public readonly string $texto,
        public readonly string $html = ''
    ) {
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Destinatario no válido');
    }

    /**
     * El mensaje en formato RFC 5322 (cabeceras + MIME), con saltos CRLF.
     * Los cuerpos van en base64: así ninguna línea pasa de 76 caracteres y no
     * hay que preocuparse de acentos ni de puntos al principio de línea.
     */
    public function rfc822(string $de, string $deNombre): string
    {
        $limpio = fn(string $s) => trim(str_replace(["\r", "\n"], ' ', $s));   // sin inyección de cabeceras
        $cod = fn(string $s) => '=?UTF-8?B?' . base64_encode($limpio($s)) . '?=';
        $dominio = substr(strrchr($de, '@') ?: '@localhost', 1);
        $limite = 'b' . bin2hex(random_bytes(12));

        $cab = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . ($deNombre !== '' ? $cod($deNombre) . ' ' : '') . "<$de>",
            "To: <{$this->para}>",
            'Subject: ' . $cod($this->asunto),
            'Message-ID: <' . bin2hex(random_bytes(16)) . "@$dominio>",
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
        ];
        $parte = fn(string $tipo, string $cuerpo) => "Content-Type: $tipo; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($cuerpo), 76, "\r\n"));

        if ($this->html === '') {
            return implode("\r\n", $cab) . "\r\n" . $parte('text/plain', $this->texto) . "\r\n";
        }
        $cab[] = "Content-Type: multipart/alternative; boundary=\"$limite\"";
        return implode("\r\n", $cab) . "\r\n\r\n"
            . "--$limite\r\n" . $parte('text/plain', $this->texto) . "\r\n"
            . "--$limite\r\n" . $parte('text/html', $this->html) . "\r\n"
            . "--$limite--\r\n";
    }
}
