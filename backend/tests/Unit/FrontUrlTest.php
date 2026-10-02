<?php
namespace Croilab\Tests\Unit;

use Croilab\Modulos\Auth\RecuperacionController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/* FRONT_URL decide adónde apuntan los enlaces de los correos: tiene que ser
   una URL http(s) sin query ni fragmento. */
class FrontUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('FRONT_URL');
    }

    public static function validas(): array
    {
        return [
            ['https://app.ejemplo.com/admin', 'https://app.ejemplo.com/admin'],
            ['https://app.ejemplo.com/admin/', 'https://app.ejemplo.com/admin'],
            ['http://localhost:5173/admin', 'http://localhost:5173/admin'],
            ['https://app.ejemplo.com', 'https://app.ejemplo.com'],
        ];
    }

    #[DataProvider('validas')]
    public function testAceptaYQuitaLaBarraFinal(string $valor, string $esperada): void
    {
        putenv("FRONT_URL=$valor");
        $this->assertSame($esperada, RecuperacionController::urlFront());
    }

    public static function invalidas(): array
    {
        return [[''], ['app.ejemplo.com'], ['javascript:alert(1)'], ['https://app.ejemplo.com/admin?x=1'], ['https://app.ejemplo.com/#a'], ['ftp://x.com']];
    }

    #[DataProvider('invalidas')]
    public function testRechaza(string $valor): void
    {
        putenv("FRONT_URL=$valor");
        $this->expectException(\RuntimeException::class);
        RecuperacionController::urlFront();
    }
}
