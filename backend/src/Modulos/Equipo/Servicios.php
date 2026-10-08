<?php
namespace Croilab\Modulos\Equipo;

use Croilab\Correo\Fabrica;
use Croilab\Google\GoogleOAuth;
use Croilab\Http\Contenedor;

/* Los servicios del módulo, creados al pedirlos (una vez por petición). Así
   registrar las rutas no cuesta ninguna consulta: la marca o el correo solo
   se leen en las peticiones que los usan. */
final class Servicios
{
    public function __construct(private readonly Contenedor $c) {}

    public static function marca(): array
    {
        require_once __DIR__ . '/../../../admin/lib/marca.php';
        return marca_agencia();
    }

    public function google(): GoogleOAuth
    {
        /* Clave compartida: Comunicación y Clientes pueden pedir la misma instancia
           con $c->unico('google', …). */
        return $this->c->unico('google', fn() => new GoogleOAuth());
    }

    public function miembros(): MiembrosServicio
    {
        return $this->c->unico('equipo.miembros', fn($c) => new MiembrosServicio(
            $c->pdo, $c->equipo(), fn() => Fabrica::desdeConfig(), GoogleOAuth::urlFront(), (string)self::marca()['name']));
    }

    public function invitaciones(): InvitacionesServicio
    {
        return $this->c->unico('equipo.invitaciones', fn($c) => new InvitacionesServicio(
            $c->pdo, $c->equipo(), fn() => Fabrica::desdeConfig(), GoogleOAuth::urlFront(), self::marca()));
    }

    public function perfil(): PerfilServicio
    {
        return $this->c->unico('equipo.perfil', fn($c) => new PerfilServicio($c->pdo, $c->equipo(), Subidas::porDefecto()));
    }

    public function roles(): RolesServicio
    {
        return $this->c->unico('equipo.roles', fn() => new RolesServicio());
    }

    public function ajustes(): AjustesServicio
    {
        return $this->c->unico('equipo.ajustes', fn($c) => new AjustesServicio($c->pdo, Subidas::porDefecto()));
    }

    public function integraciones(): IntegracionesServicio
    {
        return $this->c->unico('equipo.integraciones', fn($c) => new IntegracionesServicio($c->pdo, $this->google()));
    }

    public function metricas(): MetricasAjustesServicio
    {
        return $this->c->unico('equipo.metricas', fn($c) => new MetricasAjustesServicio($c->pdo, $this->google()));
    }

    public function boveda(): BovedaServicio
    {
        return $this->c->unico('equipo.boveda', fn($c) => new BovedaServicio($c->pdo));
    }
}
