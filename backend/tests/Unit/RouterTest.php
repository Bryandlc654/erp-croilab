<?php
namespace Croilab\Tests\Unit;

use Croilab\Http\HttpError;
use Croilab\Http\Request;
use Croilab\Http\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $r->get('/v1/tareas', fn() => 'listar');
        $r->post('/v1/tareas', fn() => 'crear');
        $r->get('/v1/tareas/{id}', fn() => 'ver');
        $r->patch('/v1/tareas/{id}', fn() => 'actualizar');
        $r->post('/v1/auth/login', fn() => 'login', true);
        return $r;
    }

    public function testResuelveRutaConParametro(): void
    {
        $req = new Request('PATCH', '/v1/tareas/42');
        [$accion, $publica] = $this->router()->resolver($req);
        $this->assertSame('actualizar', $accion());
        $this->assertFalse($publica);
        $this->assertSame(42, $req->param('id'));
    }

    public function testMarcaLasRutasPublicas(): void
    {
        [, $publica] = $this->router()->resolver(new Request('POST', '/v1/auth/login'));
        $this->assertTrue($publica);
    }

    public function testIgnoraLaBarraFinal(): void
    {
        [$accion] = $this->router()->resolver(new Request('GET', '/v1/tareas/'));
        $this->assertSame('listar', $accion());
    }

    public function testParametroNoNumericoEs404(): void
    {
        $this->expectExceptionObject(new HttpError(404, 'Esa ruta no existe.'));
        $this->router()->resolver(new Request('GET', '/v1/tareas/abc'));
    }

    public function testMetodoNoPermitidoEs405ConLosPermitidos(): void
    {
        try {
            $this->router()->resolver(new Request('DELETE', '/v1/tareas'));
            $this->fail('Debía lanzar 405');
        } catch (HttpError $e) {
            $this->assertSame(405, $e->status);
            $this->assertSame(['GET', 'POST'], $e->extra['permitidos']);
        }
    }
}
