<?php
namespace Croilab\Http;

use Croilab\Modulos\Clientes\ClientesRepositorio;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use PDO;

/* Dependencias que comparten varios módulos, creadas al pedirlas y una sola
   vez por petición. Cada fichero de api/rutas/ recibe este contenedor y monta
   sus propios controladores; lo que solo usa un módulo se crea allí mismo. */
final class Contenedor
{
    private array $hechos = [];

    public function __construct(public readonly PDO $pdo) {}

    public function equipo(): EquipoRepositorio
    {
        return $this->hechos[__FUNCTION__] ??= new EquipoRepositorio($this->pdo);
    }

    public function clientes(): ClientesRepositorio
    {
        return $this->hechos[__FUNCTION__] ??= new ClientesRepositorio($this->pdo);
    }

    /** Para lo que un módulo quiera compartir sin tocar esta clase: $c->unico('clave', fn() => …). */
    public function unico(string $clave, callable $crear): mixed
    {
        return $this->hechos['x:' . $clave] ??= $crear($this);
    }
}
