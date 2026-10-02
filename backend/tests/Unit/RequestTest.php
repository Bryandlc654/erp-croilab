<?php
namespace Croilab\Tests\Unit;

use Croilab\Http\HttpError;
use Croilab\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    public function testJsonDeObjeto(): void
    {
        $r = new Request('POST', '/x', [], '{"titulo":"Hola","prioridad":2}');
        $this->assertSame(['titulo' => 'Hola', 'prioridad' => 2], $r->json());
    }

    public function testCuerpoVacioEsUnObjetoVacio(): void
    {
        $this->assertSame([], (new Request('POST', '/x'))->json());
        $this->assertSame([], (new Request('POST', '/x', [], '{}'))->json());
    }

    public static function cuerposInvalidos(): array
    {
        return [['[1,2]'], ['{"a":'], ['"texto"'], ['123']];
    }

    #[DataProvider('cuerposInvalidos')]
    public function testCuerpoQueNoEsObjetoEs400(string $cuerpo): void
    {
        $this->expectException(HttpError::class);
        $this->expectExceptionCode(400);
        (new Request('POST', '/x', [], $cuerpo))->json();
    }

    public function testPaginacionAcotada(): void
    {
        $this->assertSame([50, 0], (new Request('GET', '/x'))->paginacion());
        $this->assertSame([200, 10], (new Request('GET', '/x', ['limit' => '9999', 'offset' => '10']))->paginacion(50, 200));
        $this->assertSame([1, 0], (new Request('GET', '/x', ['limit' => '-5', 'offset' => '-3']))->paginacion());
        $this->assertSame([50, 0], (new Request('GET', '/x', ['limit' => 'abc']))->paginacion());
    }

    public function testEnteroIgnoraValoresNoNumericos(): void
    {
        $r = new Request('GET', '/x', ['a' => '12', 'b' => '1e3', 'c' => ['x']]);
        $this->assertSame(12, $r->entero('a'));
        $this->assertSame(0, $r->entero('b'));
        $this->assertSame(7, $r->entero('c', 7));
    }
}
