<?php
namespace Croilab\Tests\Unit\Equipo;

use Croilab\Google\Cuenta;
use Croilab\Google\GoogleOAuth;
use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\Validar;
use PHPUnit\Framework\TestCase;

class ValidarTest extends TestCase
{
    public function testDecimalesConComaYSinFloat(): void
    {
        $this->assertSame('25.50', Validar::decimal('25,5', 'x', 0, 9999, 'La tarifa'));
        $this->assertSame('1234.56', Validar::decimal('1.234,56', 'x', 0, 9999, 'La tarifa'));
        $this->assertSame('0.50', Validar::decimal('0.5', 'x', 0, 100, 'El IVA'));
        $this->assertSame('21.00', Validar::decimal(21, 'x', 0, 100, 'El IVA'));
        $this->assertSame('0.00', Validar::decimal('', 'x', 0, 100, 'El IVA'));
        foreach (['abc', '1.234', '101', '-3', '1,234'] as $malo) {
            try {
                Validar::decimal($malo, 'iva', 0, 100, 'El IVA');
                $this->fail("Aceptó $malo");
            } catch (HttpError $e) {
                $this->assertSame('iva', $e->extra['campo']);
            }
        }
    }

    public function testUsuarioCorreoYFecha(): void
    {
        $this->assertSame('ana', Validar::username('  ana '));
        $this->assertNull(Validar::email(''));
        $this->assertSame('ana@x.com', Validar::email('ANA@x.com'));
        $this->assertSame('2024-02-29', Validar::fecha('2024-02-29', 'f'));
        $this->expectException(HttpError::class);
        Validar::fecha('2023-02-29', 'f');
    }

    public function testUrlSoloHttp(): void
    {
        $this->assertSame('https://a.com/x', Validar::url('https://a.com/x', 100, 'u'));
        $this->assertSame('', Validar::url('', 100, 'u'));
        $this->expectException(HttpError::class);
        Validar::url('javascript:alert(1)', 100, 'u');
    }

    public function testRutaDeVueltaSoloInterna(): void
    {
        $this->assertSame('/calendario?x=1', GoogleOAuth::rutaSegura('/calendario?x=1'));
        foreach (['//evil.com', 'https://evil.com', '/\evil.com', '', "/a\nb"] as $mala) $this->assertSame('/', GoogleOAuth::rutaSegura($mala));
    }

    public function testCuentas(): void
    {
        $this->assertSame('calendar', Cuenta::desde('calendar', 4)->integracion);
        $this->assertSame(0, Cuenta::metricas()->adminId);
        $this->expectException(\InvalidArgumentException::class);
        Cuenta::calendario(0);
    }
}
