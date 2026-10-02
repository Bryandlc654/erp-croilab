<?php
namespace Croilab\Correo;

/* Desarrollo y tests (MAIL_DRIVER=archivo): en vez de enviar, deja cada correo
   como un .eml en una carpeta. Se abre con cualquier cliente de correo. */
final class CorreoArchivo implements Correo
{
    public function __construct(private readonly string $dir, private readonly string $de = 'erp@localhost') {}

    public function enviar(Mensaje $m): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true)) throw new \RuntimeException("No se puede crear {$this->dir}");
        $f = $this->dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
        if (file_put_contents($f, $m->rfc822($this->de, 'ERP (desarrollo)')) === false) throw new \RuntimeException("No se puede escribir $f");
    }
}
