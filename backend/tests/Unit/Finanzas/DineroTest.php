<?php
namespace Croilab\Tests\Unit\Finanzas;

use Croilab\Modulos\Finanzas\Dinero;
use Croilab\Modulos\Finanzas\EmisoresServicio;
use Croilab\Modulos\Finanzas\HorasServicio;
use Croilab\Modulos\Finanzas\Numeracion;
use Croilab\Modulos\Finanzas\PdfFactura;
use PHPUnit\Framework\TestCase;

/* La regla única de importes (céntimos, redondeo por línea) y las piezas puras
   de Finanzas, sin base de datos. */
class DineroTest extends TestCase
{
    public function testLeeNumerosComoLosEscribeUnaPersona(): void
    {
        $casos = ['1.234,56' => '1234.56', '1,234.56' => '1234.56', '12,5' => '12.50', '12.5' => '12.50', '1.234' => '1234.00',
                  '1234' => '1234.00', '1.200 €' => '1200.00', '-3,5' => '-3.50', '0,005' => '0.01', 'abc' => null, '' => null];
        foreach ($casos as $in => $out) $this->assertSame($out, Dinero::leer($in), "leer('$in')");
        $this->assertSame('12.30', Dinero::leer(12.3));
        $this->assertSame('7.00', Dinero::leer(7));
    }

    public function testRedondeoPorLineaYCuotasSobreLaBase(): void
    {
        $this->assertSame(8333, Dinero::linea('2.50', '33.33'), '83,325 → 83,33 (mitad hacia fuera)');
        $this->assertSame(-8333, Dinero::linea('-2.50', '33.33'));
        $this->assertSame(1, Dinero::linea('0.10', '0.05'), '0,005 → 0,01');
        $t = Dinero::totales([['cantidad' => '3', 'precio' => '0.33'], ['cantidad' => '1', 'precio' => '0.01']], '21', '15');
        $this->assertSame(['base' => 100, 'iva' => 21, 'irpf' => 15, 'total' => 106], $t);
        /* Sumar floats daría 0.30000000000000004: aquí no hay floats. */
        $this->assertSame(30, Dinero::totales([['cantidad' => '1', 'precio' => '0.1'], ['cantidad' => '1', 'precio' => '0.2']], 0, 0)['total']);
    }

    public function testFormatos(): void
    {
        $this->assertSame('1.234,56', Dinero::texto(123456));
        $this->assertSame('-0,05', Dinero::texto(-5));
        $this->assertSame('1234.56', Dinero::decimal(123456));
        $this->assertSame('-0.05', Dinero::decimal(-5));
        $this->assertSame(['21', '7,5', '0'], [Dinero::pct('21.00'), Dinero::pct('7.50'), Dinero::pct('0.00')]);
    }

    public function testIbanYClavesDeEmisor(): void
    {
        $this->assertTrue(EmisoresServicio::ibanValido('ES9121000418450200051332'));
        $this->assertFalse(EmisoresServicio::ibanValido('ES9121000418450200051333'));
        $this->assertSame('victor2', EmisoresServicio::slug('Víctor', ['victor']));
        $this->assertSame('V', EmisoresServicio::inicial('Víctor'));
    }

    public function testFormatoDeNumeroYSerie(): void
    {
        $this->assertSame('V-2026-001', Numeracion::formato('V-2026-', 1));
        $this->assertSame('V-2026-1000', Numeracion::formato('V-2026-', 1000));
        $this->assertSame('SE-', Numeracion::validarSerie(' se- '));
        $this->expectException(\Croilab\Http\HttpError::class);
        Numeracion::validarSerie('F 1');
    }

    public function testHorasAEuros(): void
    {
        $this->assertSame(3000, HorasServicio::aEuros(90, 2000));
        $this->assertSame(1, HorasServicio::aEuros(1, 30), '0,5 céntimos → 1');
    }

    public function testElPdfEsUnPdf(): void
    {
        $pdf = PdfFactura::generar([
            'titulo' => 'FACTURA', 'numero' => 'V-2026-001', 'tipo' => 'normal', 'borrador' => false, 'fecha' => '2026-10-08',
            'cliente' => ['nombre' => 'Cliente (con paréntesis) \\ raro', 'nif' => 'B1', 'tel' => '', 'dir' => '', 'email' => ''],
            'emisor' => ['name' => 'Víctor', 'nif' => '1Z', 'dir' => '', 'email' => '', 'phone' => '', 'iban' => '', 'banco' => ''],
            'lineas' => array_fill(0, 60, ['concepto' => 'Línea con un concepto bastante largo que debería partirse en varias líneas para caber', 'cantidad' => '1.00', 'precio' => '10.00', 'importe' => 1000]),
            'iva_pct' => '21', 'irpf_pct' => '0', 'totales' => ['base' => 60000, 'iva' => 12600, 'irpf' => 0, 'total' => 72600],
            'pago' => ['banco' => '', 'titular' => 'Víctor', 'forma' => 'Transferencia', 'condiciones' => 'Contado', 'fecha_venc' => null, 'iban' => ''],
            'notas_legales' => [], 'notas' => "Gracias\nOtra línea",
        ]);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertGreaterThan(1, substr_count($pdf, '/Type /Page '), 'Muchas líneas → varias páginas');
        $this->assertStringContainsString('\\(con par', $pdf, 'Los paréntesis se escapan');
    }
}
