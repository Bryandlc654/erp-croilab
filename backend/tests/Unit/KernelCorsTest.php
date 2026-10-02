<?php
namespace Croilab\Tests\Unit;

use Croilab\Http\Kernel;
use Croilab\Http\Request;
use Croilab\Http\Router;
use PHPUnit\Framework\TestCase;

/* El preflight se contesta antes de tocar sesión o base de datos. */
class KernelCorsTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('CORS_ORIGINS=https://app.ejemplo.com');
    }

    protected function tearDown(): void
    {
        putenv('CORS_ORIGINS');
    }

    public function testPreflightDeOrigenPermitido(): void
    {
        [$status, $cab, $cuerpo] = (new Kernel(new Router()))->manejar(new Request('OPTIONS', '/v1/tareas/1', [], '', ['origin' => 'https://app.ejemplo.com']));
        $this->assertSame(204, $status);
        $this->assertSame('', $cuerpo);
        $this->assertSame('https://app.ejemplo.com', $cab['Access-Control-Allow-Origin']);
        $this->assertSame('true', $cab['Access-Control-Allow-Credentials']);
        $this->assertStringContainsString('PATCH', $cab['Access-Control-Allow-Methods']);
    }

    public function testOrigenNoPermitidoNoRecibeCabeceras(): void
    {
        [, $cab] = (new Kernel(new Router()))->manejar(new Request('OPTIONS', '/v1/tareas', [], '', ['origin' => 'https://malo.example']));
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $cab);
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $cab);
    }

    public function testViteSiempreEstaPermitido(): void
    {
        $this->assertContains('http://localhost:5173', Kernel::origenesPermitidos());
        $this->assertContains('https://app.ejemplo.com', Kernel::origenesPermitidos());
    }
}
