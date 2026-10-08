<?php
namespace Croilab\Google;

/* Fallo al hablar con Google. `codigo` es estable para decidir qué enseñar:
     sin_configurar  faltan el client id / secreto del proyecto
     sin_conectar    esa cuenta no ha dado permiso todavía
     revocado        Google ha retirado el permiso (hay que reconectar)
     google          Google ha respondido con un error
     red             no se ha podido contactar con Google */
class ErrorGoogle extends \RuntimeException
{
    public function __construct(public readonly string $codigo, string $msg, public readonly int $estadoHttp = 0)
    {
        parent::__construct($msg);
    }
}
