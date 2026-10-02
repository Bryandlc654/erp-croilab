<?php
namespace Croilab\Tests\Integracion;

use Croilab\Database\Esquema;

/* Una base del ERP antiguo (tablas con menos columnas, sin task_assignees,
   con datos) se pone al día sin perder nada. */
class MigracionBaseExistenteTest extends BaseDatosTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('existente');
    }

    public function testConservaLosDatos(): void
    {
        $this->assertSame('Tarea vieja', self::pdo()->query('SELECT titulo FROM tasks WHERE id = 1')->fetchColumn());
        $this->assertSame('Cliente viejo', self::pdo()->query('SELECT name FROM clients WHERE id = 1')->fetchColumn());
    }

    public function testAnadeLasColumnasQueFaltaban(): void
    {
        foreach ([['admins', 'role'], ['admins', 'activo'], ['admins', 'cred_ver'], ['clients', 'activo'], ['clients', 'iniciales'],
                  ['task_lists', 'tipo'], ['tasks', 'fecha_inicio'], ['tasks', 'etiquetas']] as [$t, $c]) {
            $this->assertTrue(Esquema::columnaExiste(self::pdo(), $t, $c), "Falta $t.$c");
        }
        $this->assertSame('tareas', self::pdo()->query('SELECT tipo FROM task_lists WHERE id = 1')->fetchColumn());
        $this->assertSame(1, (int)self::pdo()->query('SELECT activo FROM clients WHERE id = 1')->fetchColumn());
    }

    public function testLaCuentaMasAntiguaQuedaComoDuena(): void
    {
        $this->assertSame('owner', self::pdo()->query('SELECT role FROM admins WHERE id = 1')->fetchColumn());
        $this->assertSame('editor', self::pdo()->query('SELECT role FROM admins WHERE id = 2')->fetchColumn());
    }

    public function testQuitaLaTablaVaciaDeNombreEquivocado(): void
    {
        $this->assertNotContains('task_assigned', self::tablas());
        $this->assertContains('task_assignees', self::tablas());
    }
}
