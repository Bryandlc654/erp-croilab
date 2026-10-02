<?php
namespace Croilab\Correo;

/* Cualquier forma de enviar un correo (SMTP real, fichero en desarrollo). */
interface Correo
{
    /** @throws \RuntimeException si no se ha podido entregar */
    public function enviar(Mensaje $m): void;
}
