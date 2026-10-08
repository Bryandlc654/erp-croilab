<?php
namespace Croilab\Tests\Unit\Crm;

use Croilab\Http\HttpError;
use Croilab\Modulos\Crm\Catalogos;
use Croilab\Modulos\Crm\Csv;
use Croilab\Modulos\Crm\Dinero;
use Croilab\Modulos\Crm\Filtros;
use PHPUnit\Framework\TestCase;

/* Piezas puras del CRM: importes «a la española», filtros → SQL y CSV. */
class CrmUnitTest extends TestCase
{
    public function testImportesALaEspanola(): void
    {
        $this->assertSame('1234.56', Dinero::leer('1.234,56'));
        $this->assertSame('12.50', Dinero::leer('12,5'));
        $this->assertSame('12.50', Dinero::leer('12.5'));      // el CSV antiguo lo leía como 125
        $this->assertSame('1234.00', Dinero::leer('1.234'));    // tres cifras tras el punto = miles
        $this->assertSame('1200.00', Dinero::leer('1.200 €'));
        $this->assertSame('0.13', Dinero::leer('0,125'));       // redondeo a céntimos, sin pasar por float
        $this->assertSame('2500.00', Dinero::leer(2500));
        $this->assertNull(Dinero::leer(''));
        $this->assertNull(Dinero::leer(null));
        $this->assertNull(Dinero::intentar('abc'));
        $this->assertSame(1234.56, Dinero::num('1234.56'));
    }

    public function testImporteNoValido(): void
    {
        foreach (['abc', '-5', '99999999999999'] as $v) {
            try {
                Dinero::leer($v, 'valor');
                $this->fail("Aceptó «$v»");
            } catch (HttpError $e) {
                $this->assertSame(422, $e->status);
                $this->assertSame('valor', $e->extra['campo']);
            }
        }
    }

    public function testFiltrosNormalizaYGeneraSql(): void
    {
        $f = Filtros::normalizar(['q' => ' ribera ', 'sector' => '', 'prop' => 'sin', 'vmin' => '1.000', 'quick' => 'act7', 'tag' => '3', 'otra' => 'x']);
        $this->assertSame(['q' => 'ribera', 'prop' => 'sin', 'tag' => 3, 'vmin' => '1000.00', 'quick' => 'act7'], $f);
        $this->assertSame(3, Filtros::avanzados($f));   // prop, tag y vmin cuentan; q y quick no
        [$sql, $p] = Filtros::sql($f);
        $this->assertStringContainsString('c.nombre LIKE ?', $sql);
        $this->assertStringContainsString('c.propietario_id IS NULL', $sql);
        $this->assertStringContainsString('INTERVAL 7 DAY', $sql);
        $this->assertSame(['%ribera%', '%ribera%', '%ribera%', '%ribera%', 3, '1000.00'], $p);
    }

    public function testFiltrosEscapanComodines(): void
    {
        [, $p] = Filtros::sql(Filtros::normalizar(['q' => '50%_off']));
        $this->assertSame('%50\\%\\_off%', $p[0]);
    }

    public function testFiltroRapidoDesconocido(): void
    {
        $this->expectException(HttpError::class);
        Filtros::normalizar(['quick' => 'DROP TABLE']);
    }

    public function testFechaNoValida(): void
    {
        $this->expectException(HttpError::class);
        Filtros::normalizar(['fdesde' => '2026-02-30']);
    }

    public function testCsvNeutralizaFormulas(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", Csv::celda('=HYPERLINK("x")'));
        $this->assertSame("'+34 600", Csv::celda('+34 600'));
        $this->assertSame("'@SUM(A1)", Csv::celda('@SUM(A1)'));
        $this->assertSame('Ana', Csv::celda('Ana'));
        $csv = Csv::generar(['Nombre', 'Valor'], [['=1+1', 12.5]]);
        $this->assertStringStartsWith(Csv::BOM, $csv);
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString('12.5', $csv);   // las cifras nuestras van tal cual
    }

    public function testCsvLeeSeparadorYBom(): void
    {
        $r = Csv::leer(Csv::BOM . "Nombre;Empresa\nAna;Ribera\n\n;\nLuis;\"Sonrisa; SL\"\n", 100);
        $this->assertSame(';', $r['separador']);
        $this->assertSame(['Nombre', 'Empresa'], $r['cabecera']);
        $this->assertSame([['Ana', 'Ribera'], ['Luis', 'Sonrisa; SL']], $r['filas']);
        $this->assertSame(',', Csv::leer("a,b\n1,2", 10)['separador']);
    }

    public function testAsciiParaSlugs(): void
    {
        $this->assertSame('demo_agendada', Catalogos::ascii('Demo agendada'));
        $this->assertSame('cafeteria-nono', Catalogos::ascii('Cafetería Ñoño', '-'));
        $this->assertSame('bodegasribera', Catalogos::ascii('Bodegas Ribera', ''));
    }
}
