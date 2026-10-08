<?php
namespace Croilab\Tests\Unit\Clientes;

use Croilab\Http\HttpError;
use Croilab\Modulos\Clientes\AvanzadoServicio;
use Croilab\Modulos\Clientes\ContenidoPortal;
use Croilab\Modulos\Clientes\Importes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/* Los bloques JSON del portal, los totales de factura y el editor JSON de
   «Datos avanzados»: lógica pura, sin base de datos. */
class ContenidoPortalTest extends TestCase
{
    private function falla(callable $f, string $campo): void
    {
        try {
            $f();
            $this->fail('Debía rechazarse');
        } catch (HttpError $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame($campo, $e->extra['campo'] ?? null);
        }
    }

    public function testEstadoDescartaFasesSinNombreYValidaElEstado(): void
    {
        $j = ContenidoPortal::estado(['nombre' => ' Etapa ', 'fases' => [['t' => 'Auditoría', 's' => 'y arranque', 'estado' => 'done'], ['t' => '  ', 'estado' => 'now']]]);
        $this->assertSame(['nombre' => 'Etapa', 'etiqueta' => '', 'siguiente' => '', 'fases' => [['t' => 'Auditoría', 's' => 'y arranque', 'estado' => 'done']]], json_decode($j, true));
        $this->falla(fn() => ContenidoPortal::estado(['fases' => [['t' => 'X', 'estado' => 'hecho']]]), 'estado');
        $this->falla(fn() => ContenidoPortal::estado(['fases' => 'no']), 'estado');
    }

    public function testPlanDescartaFilasVacias(): void
    {
        $d = json_decode(ContenidoPortal::plan(['resumen' => 'R', 'items' => [['n' => '4', 't' => 'Artículos'], ['n' => '', 't' => '']], 'detalle' => [['h' => '', 'p' => '']]]), true);
        $this->assertSame([['n' => '4', 't' => 'Artículos']], $d['items']);
        $this->assertSame([], $d['detalle']);
    }

    public function testAccesosSoloEnlacesHttpYSinEnlaceEsAlmohadilla(): void
    {
        $d = json_decode(ContenidoPortal::accesos([['b' => 'Figma', 'u' => 'https://figma.com/x', 'tipo' => 'figma'], ['b' => 'Drive', 'u' => '', 'tipo' => 'drive'], ['b' => '', 'u' => 'https://x.test']]), true);
        $this->assertSame(['https://figma.com/x', '#'], array_column($d, 'u'));
        $this->falla(fn() => ContenidoPortal::accesos([['b' => 'Malo', 'u' => 'javascript:alert(1)', 'tipo' => 'web']]), 'accesos');
        $this->falla(fn() => ContenidoPortal::accesos([['b' => 'Malo', 'u' => 'https://x.test', 'tipo' => 'otro']]), 'accesos');
        // Al leer, el '#' del antiguo vuelve a ser «sin enlace».
        $this->assertSame('', ContenidoPortal::leerAccesos('[{"b":"Drive","u":"#","tipo":"drive"}]')[0]['u']);
    }

    public function testProgresoAgrupaPorMesYJuntaRepetidos(): void
    {
        $j = ContenidoPortal::progreso([
            ['mes' => 'Junio', 'completado' => [['t' => 'A', 'd' => 'x']], 'pendiente' => []],
            ['mes' => 'Junio', 'completado' => [], 'pendiente' => [['t' => 'B']]],
            ['mes' => 'Julio', 'completado' => [['t' => '']], 'pendiente' => []],
        ]);
        $this->assertSame(['Junio' => ['completado' => [['t' => 'A', 'd' => 'x']], 'pendiente' => [['t' => 'B', 'd' => '']]]], json_decode($j, true));
        $this->assertSame('{}', ContenidoPortal::progreso([]));
        $this->falla(fn() => ContenidoPortal::progreso([['mes' => '', 'completado' => [['t' => 'A']]]]), 'tareas');
        // Ida y vuelta: lo leído se puede volver a guardar igual.
        $this->assertSame($j, ContenidoPortal::progreso(ContenidoPortal::leerProgreso($j)));
    }

    public function testLeerToleraBasura(): void
    {
        $this->assertSame([], ContenidoPortal::leerEstado('no es json')['fases']);
        $this->assertSame([], ContenidoPortal::leerAccesos('{"a":1}'));
        $this->assertNull(ContenidoPortal::leerServicios(''));
        $this->assertSame([], ContenidoPortal::leerServicios('[]'));
        $this->assertSame(['SEO'], ContenidoPortal::leerServicios('["SEO", ""]'));
        $m = ContenidoPortal::leerMetricas('{"Mayo":{"ll":"3","wa":2,"fo":null,"ctr":"4.256"},"x":5}');
        $this->assertSame([['mes' => 'Mayo', 'll' => 3, 'wa' => 2, 'fo' => 0, 'vi' => 0, 'ap' => 0, 'ctr' => 4.26, 'total' => 5]], $m);
    }

    public function testTextosLargosSeRechazan(): void
    {
        $this->falla(fn() => ContenidoPortal::estado(['nombre' => str_repeat('a', 201)]), 'estado');
    }

    public static function centimos(): array
    {
        return [['1234.56', 123456], ['0.5', 50], ['-3.5', -350], ['10', 1000], ['1.005', 101], [null, 0], ['', 0], ['12.344', 1234]];
    }

    #[DataProvider('centimos')]
    public function testCentimos(?string $v, int $esperado): void
    {
        $this->assertSame($esperado, Importes::centimos($v));
    }

    public function testTotalesDeFacturaSinFloats(): void
    {
        // 900,25 € + 21 % (189,0525 → 189,05) = 1089,30 €
        $this->assertSame(108930, Importes::total(90025, '21.00', '0.00'));
        // 450 € + 21 % − 15 % = 477 €
        $this->assertSame(47700, Importes::total(45000, '21.00', '15.00'));
        // 0,10 € al 21 % → 0,021 → 0,02
        $this->assertSame(12, Importes::total(10, '21', null));
        $this->assertSame(-121, Importes::total(-100, '21', '0'));
    }

    public function testJsonAvanzadoValidaLaRaiz(): void
    {
        $this->assertNull(AvanzadoServicio::json('  ', 'servicios_json', 'lista'));
        $this->assertSame('["SEO"]', AvanzadoServicio::json('[ "SEO" ]', 'servicios_json', 'lista'));
        $this->assertSame('{}', AvanzadoServicio::json('{}', 'met_json', 'objeto'));
        $this->falla(fn() => AvanzadoServicio::json('{"a":1}', 'servicios_json', 'lista'), 'servicios_json');
        $this->falla(fn() => AvanzadoServicio::json('[1]', 'met_json', 'objeto'), 'met_json');
        $this->falla(fn() => AvanzadoServicio::json('{roto', 'met_json', 'objeto'), 'met_json');
        // Los bloques con pantalla propia pasan por su validador.
        $this->falla(fn() => AvanzadoServicio::json('[{"b":"x","u":"javascript:1","tipo":"web"}]', 'accesos_json', 'lista'), 'accesos');
    }
}
