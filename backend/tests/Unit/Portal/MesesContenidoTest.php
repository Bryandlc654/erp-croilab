<?php
namespace Croilab\Tests\Unit\Portal;

use Croilab\Http\HttpError;
use Croilab\Modulos\Portal\Contenido;
use Croilab\Modulos\Portal\Meses;
use PHPUnit\Framework\TestCase;

/* Meses con año (el fallo de los meses sin año del antiguo) y la lectura y
   validación de los bloques del portal que no cubre Clientes. */
class MesesContenidoTest extends TestCase
{
    private function ref(string $d = '2026-10-08'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($d);
    }

    public function testClaveDeEtiquetas(): void
    {
        $r = $this->ref();
        $this->assertSame('2026-06', Meses::clave('2026-06', $r));
        $this->assertSame('2025-06', Meses::clave('Junio 2025', $r));
        $this->assertSame('2024-03', Meses::clave('marzo de 2024', $r));
        $this->assertSame('2026-09', Meses::clave('Sep 2026', $r));
        $this->assertSame('2026-09', Meses::clave('Setiembre', $r));
        $this->assertNull(Meses::clave('General', $r));
        $this->assertNull(Meses::clave('', $r));
        $this->assertNull(Meses::clave('2026-13', $r));
    }

    public function testMesSinAnioEsElUltimoNoPosterior(): void
    {
        $r = $this->ref('2027-01-15');
        $this->assertSame('2027-01', Meses::clave('Enero', $r), 'Enero en enero es este enero');
        $this->assertSame('2026-12', Meses::clave('Diciembre', $r), 'Diciembre en enero es el del año anterior (no el que viene)');
        $this->assertSame('2026-02', Meses::clave('Febrero', $r));
    }

    public function testEtiquetaYAnterior(): void
    {
        $this->assertSame('Junio 2026', Meses::etiqueta('2026-06'));
        $this->assertSame('2025-12', Meses::anterior('2026-01'));
    }

    public function testMetricasOrdenadasPorFechaYConAnio(): void
    {
        /* Como lo dejaba el antiguo: orden de inserción invertido y sin año. */
        $json = json_encode(['Octubre' => ['ll' => 1, 'wa' => 2, 'fo' => 3, 'vi' => 10, 'ap' => 100, 'ctr' => 10, 'src' => ['Direct' => 5, 'X' => 0], 'geo' => ['es' => 7, 'zzz' => 1]],
            'Septiembre' => ['ll' => 'x'], 'Diciembre' => ['ll' => 4], '2026-09' => ['ll' => 9]]);
        $m = Contenido::metricas($json, $this->ref());
        $this->assertSame(['2025-12', '2026-09', '2026-10'], array_column($m, 'clave'));
        $this->assertSame('Diciembre 2025', $m[0]['etiqueta']);
        $this->assertSame(9, $m[1]['ll'], 'La entrada con año gana a la «Septiembre» suelta');
        $this->assertSame(6, $m[2]['total']);
        $this->assertSame(['Direct' => 5], $m[2]['src']);
        $this->assertSame(['ES' => 7], $m[2]['geo']);
        $this->assertSame([], Contenido::metricas('basura', $this->ref()));
    }

    public function testProgresoEInformesDelMasNuevoAlMasViejo(): void
    {
        $p = Contenido::progreso(json_encode(['General' => ['completado' => [['t' => 'A', 'd' => '']]], 'Agosto' => ['pendiente' => [['t' => 'B', 'd' => '']]], 'Septiembre 2026' => ['completado' => [['t' => 'C', 'd' => '']]]]), $this->ref());
        $this->assertSame(['Septiembre 2026', 'Agosto 2026', 'General'], array_column($p, 'etiqueta'));
        $i = Contenido::informes(json_encode([['mes' => 'Agosto', 'titulo' => 'Ag', 'texto' => 'x', 'url' => 'javascript:alert(1)'], ['mes' => '2026-09', 'titulo' => '', 'texto' => ''], ['mes' => 'Septiembre', 'titulo' => 'Sep', 'texto' => 'y', 'url' => 'https://a.test/x.pdf']]), $this->ref());
        $this->assertSame(['Sep', 'Ag'], array_column($i, 'titulo'), 'Vacíos fuera, lo último arriba');
        $this->assertSame('', $i[1]['url'], 'Un enlace que no es http(s) no llega al portal');
    }

    public function testSecciones(): void
    {
        $this->assertFalse(Contenido::secciones(null, false)['metricas'], 'Sin tipo, Métricas según «conversiones»');
        $this->assertTrue(Contenido::secciones(null, false)['plan']);
        $s = Contenido::secciones(['metricas' => 1, 'plan' => 0], false);
        $this->assertTrue($s['metricas']);
        $this->assertFalse($s['plan']);
        $this->assertFalse($s['informes'], 'Lo que el tipo no dice, apagado');
    }

    public function testValidacionDelEditor(): void
    {
        $this->assertSame('[{"mes":"Octubre 2026","titulo":"T","texto":"a","url":""}]', Contenido::validarInformes([['mes' => 'Octubre 2026', 'titulo' => 'T', 'texto' => "a\r\n"], ['titulo' => '', 'texto' => '']]));
        $this->assertSame('["SEO","CRO"]', Contenido::validarServicios(['SEO', ' seo ', 'CRO', '']));
        $this->assertNull(Contenido::validarServicios(null));
        $this->assertSame('', Contenido::validarLooker(''));
        $this->assertSame('https://lookerstudio.google.com/embed/r/1', Contenido::validarLooker('https://lookerstudio.google.com/embed/r/1'));
        foreach ([fn() => Contenido::validarLooker('https://malo.test/embed'), fn() => Contenido::validarLooker('http://lookerstudio.google.com/x'),
                  fn() => Contenido::validarInformes([['titulo' => 'x', 'url' => 'javascript:alert(1)']]), fn() => Contenido::validarInformes('no'),
                  fn() => Contenido::validarServicios([['x']])] as $f) {
            try {
                $f();
                $this->fail('Debía rechazarse');
            } catch (HttpError $e) {
                $this->assertSame(422, $e->status);
            }
        }
        $this->assertSame('', Contenido::lookerSeguro('https://evil.test/'), 'Lo guardado antes de otra forma no se pinta');
    }
}
