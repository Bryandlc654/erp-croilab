<?php
namespace Croilab\Http;

/* Error que acaba en una respuesta JSON con su código HTTP. `msg` es el texto
   que ve el usuario; `codigo` es estable para que el front decida qué hacer. */
class HttpError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $msg,
        public readonly string $codigo = '',
        public readonly array $extra = []
    ) {
        parent::__construct($msg, $status);
    }

    public static function datos(string $msg): self { return new self(400, $msg, 'datos'); }
    public static function sesion(): self { return new self(401, 'Tu sesión ha terminado. Vuelve a entrar.', 'sesion'); }
    public static function permiso(): self { return new self(403, 'No tienes permiso para esto.', 'permiso'); }
    /** $msg completo («Tarea no encontrada»): el género cambia según lo que falte. */
    public static function noEncontrado(string $msg = 'No encontrado.'): self { return new self(404, $msg, 'no_encontrado'); }
    public static function csrf(): self { return new self(419, 'La sesión ha caducado. Recarga la página.', 'csrf'); }
    public static function validacion(string $msg, string $campo = ''): self
    {
        return new self(422, $msg, 'validacion', $campo !== '' ? ['campo' => $campo] : []);
    }
}
