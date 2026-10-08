<?php
namespace Croilab\Modulos\Finanzas;

use PDO;

/* Monta los servicios de Finanzas una sola vez (rutas, cron y tests). */
final class Modulo
{
    private array $s = [];

    public function __construct(public readonly PDO $pdo, private readonly ?string $carpetaDocumentos = null) {}

    private function u(string $k, callable $f): mixed
    {
        return $this->s[$k] ??= $f();
    }

    public function ajustes(): Ajustes { return $this->u(__FUNCTION__, fn() => new Ajustes($this->pdo)); }
    public function emisores(): EmisoresServicio { return $this->u(__FUNCTION__, fn() => new EmisoresServicio($this->pdo, $this->ajustes())); }
    public function numeracion(): Numeracion { return $this->u(__FUNCTION__, fn() => new Numeracion($this->pdo, $this->emisores())); }
    public function repo(): FacturasRepositorio { return $this->u(__FUNCTION__, fn() => new FacturasRepositorio($this->pdo)); }
    public function caja(): Caja { return $this->u(__FUNCTION__, fn() => new Caja($this->pdo, $this->repo())); }
    public function proyectos(): ProyectosServicio { return $this->u(__FUNCTION__, fn() => new ProyectosServicio($this->pdo)); }
    public function facturas(): FacturasServicio
    {
        return $this->u(__FUNCTION__, fn() => new FacturasServicio($this->pdo, $this->repo(), $this->emisores(), $this->numeracion(), $this->caja(), $this->proyectos(), $this->ajustes()));
    }
    public function documentos(): DocumentosServicio
    {
        return $this->u(__FUNCTION__, fn() => new DocumentosServicio($this->pdo, $this->emisores(), $this->caja(), $this->proyectos(), $this->carpetaDocumentos ?? DocumentosServicio::carpetaPorDefecto()));
    }
    public function explorador(): ExploradorServicio { return $this->u(__FUNCTION__, fn() => new ExploradorServicio($this->pdo, $this->repo(), $this->documentos(), $this->emisores())); }
    public function programaciones(): ProgramacionesServicio
    {
        return $this->u(__FUNCTION__, fn() => new ProgramacionesServicio($this->pdo, $this->emisores(), $this->repo(), $this->facturas(), $this->numeracion(), $this->proyectos()));
    }
    public function contabilidad(): ContabilidadServicio { return $this->u(__FUNCTION__, fn() => new ContabilidadServicio($this->pdo, $this->emisores())); }
    public function resumen(): ResumenServicio { return $this->u(__FUNCTION__, fn() => new ResumenServicio($this->pdo, $this->repo(), $this->emisores())); }
    public function horas(): HorasServicio { return $this->u(__FUNCTION__, fn() => new HorasServicio($this->pdo, $this->caja())); }
    public function clientes(): ClientesFacturacion { return $this->u(__FUNCTION__, fn() => new ClientesFacturacion($this->pdo)); }
    public function hoja(): HojaFactura { return $this->u(__FUNCTION__, fn() => new HojaFactura($this->repo(), $this->emisores())); }
}
