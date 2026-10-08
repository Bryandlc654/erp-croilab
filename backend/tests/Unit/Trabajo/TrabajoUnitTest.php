<?php
namespace Croilab\Tests\Unit\Trabajo;

use Croilab\Http\HttpError;
use Croilab\Modulos\Tareas\FichaServicio;
use Croilab\Modulos\Tareas\TextoRico;
use Croilab\Modulos\Trabajo\NotificacionesServicio;
use PHPUnit\Framework\TestCase;

/* Lógica pura de Trabajo: texto rico → plano/extracto, horas, posponer y
   el id de tarea de la URL de un aviso. */
class TrabajoUnitTest extends TestCase
{
    public function testTextoPlanoParaElPortal(): void
    {
        $rico = "# Título\n## Sub\n- uno **negrita**\n1. dos *cursiva*\n> cita\n---\n[[chk:1]] hecho\n| a | b |\n| --- | --- |\n| 1 | 2 |\n```\ncódigo\n```\n[[img:3_ab.png]] [[file:3_cd.pdf|Contrato.pdf]] [web](https://x.test) `x`";
        $this->assertSame("Título\nSub\n• uno negrita\n• dos cursiva\ncita\n—\n• hecho\na b\n1 2\ncódigo\n Contrato.pdf web x", TextoRico::aPlano($rico));
    }

    public function testExtractoCortoYSinMarcadores(): void
    {
        $this->assertSame('Hola mundo y más', TextoRico::extracto("# Hola\n- **mundo**\n[[chk:0]] y más"));
        $this->assertSame('abcd…', TextoRico::extracto('abcdefghij', 5));
        $this->assertTrue(TextoRico::vacio("[[chk:0]]  \n "));
        $this->assertSame(['3_ab.png', '3_cd.pdf'], TextoRico::ficheros('[[img:3_ab.png]] y [[file:3_cd.pdf|x]] [[img]]'));
    }

    public function testHorasEnMinutos(): void
    {
        $this->assertSame(90, FichaServicio::minutos('1,5'));
        $this->assertSame(90, FichaServicio::minutos('1.5'));
        $this->assertSame(120, FichaServicio::minutos(2));
        $this->assertSame(0, FichaServicio::minutos(''));
        $this->assertNull(FichaServicio::minutos('-1'));
        $this->assertNull(FichaServicio::minutos('dos'));
        $this->assertSame('1,5 h', FichaServicio::horasTexto(90));
        $this->assertSame('2 h', FichaServicio::horasTexto(120));
    }

    public function testPosponer(): void
    {
        $ahora = new \DateTimeImmutable('2026-10-08 17:20:00');
        $this->assertSame('2026-10-09 09:00:00', NotificacionesServicio::hastaPosponer(null, $ahora));
        $this->assertSame('2026-10-08 20:20:00', NotificacionesServicio::hastaPosponer(3, $ahora));
        $this->expectException(HttpError::class);
        NotificacionesServicio::hastaPosponer('mucho', $ahora);
    }

    public function testTareaDeLaUrlDeUnAviso(): void
    {
        $this->assertSame(12, NotificacionesServicio::tareaDeUrl('/tareas/12#c3'));
        $this->assertSame(12, NotificacionesServicio::tareaDeUrl('/tareas/12'));
        $this->assertSame(7, NotificacionesServicio::tareaDeUrl('task.php?id=7#chk'));
        $this->assertSame(7, NotificacionesServicio::tareaDeUrl('../admin/task.php?ret=x&id=7'));
        $this->assertNull(NotificacionesServicio::tareaDeUrl('/tareas?view=all'));
        $this->assertNull(NotificacionesServicio::tareaDeUrl('/finanzas/facturas/3'));
    }
}
