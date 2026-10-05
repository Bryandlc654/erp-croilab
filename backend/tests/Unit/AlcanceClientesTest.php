<?php
namespace Croilab\Tests\Unit;

use Croilab\Seguridad\Acceso;
use PDO;
use PHPUnit\Framework\TestCase;

/* El alcance de una persona sin permiso 'alcance.todos': qué clientes ve. */
class AlcanceClientesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        /* SQLite en memoria: mismo SQL que MySQL para estas consultas. */
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, client_id INTEGER, responsable_id INTEGER)');
        $pdo->exec('CREATE TABLE task_assignees (task_id INTEGER, admin_id INTEGER)');
        $pdo->exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, client_id INTEGER, propietario_id INTEGER)');

        /* Ana (1) es responsable de las tareas 1 y 2; el 3 es suyo solo por
           asignación. El 4 es de otro. El 5 no tiene ni una. */
        $pdo->exec('INSERT INTO tasks VALUES (1, 10, 1), (2, 11, 1), (3, 12, 9), (4, 13, 9), (5, 14, 9)');
        $pdo->exec('INSERT INTO task_assignees VALUES (3, 1)');
        /* Ana es además propietaria de un contacto del cliente 15. El 16 es de otro. */
        $pdo->exec('INSERT INTO contacts VALUES (1, 15, 1), (2, 16, 9)');
        /* Tareas sin cliente: no pueden aparecer en el alcance. */
        $pdo->exec('INSERT INTO tasks VALUES (6, NULL, 1)');
        $pdo->exec('INSERT INTO tasks VALUES (7, NULL, 1)');
        $this->pdo = $pdo;
    }

    private function ana(): Acceso
    {
        return new Acceso($this->pdo, 1, ['tareas.ver']);
    }

    public function testSumaResponsableAsignadoYContacto(): void
    {
        $ids = $this->ana()->clientesVisibles();
        sort($ids);
        $this->assertSame([10, 11, 12, 15], $ids);
    }

    public function testNoSeRepiteUnClienteQueAparecePorLasDosVias(): void
    {
        $pdo = $this->pdo;
        $pdo->exec('INSERT INTO tasks VALUES (8, 15, 1)');   /* 15 ya salía por contacto */
        $ids = $this->ana()->clientesVisibles();
        $this->assertSame(1, count(array_keys($ids, 15, true)), 'el cliente 15 sale dos veces');
    }

    public function testSinNadaNoVeNingunCliente(): void
    {
        $this->assertSame([], (new Acceso($this->pdo, 5, ['tareas.ver']))->clientesVisibles());
    }

    public function testConAlcanceTotalNoFiltra(): void
    {
        $this->assertNull((new Acceso($this->pdo, 1, ['alcance.todos']))->clientesVisibles());
    }

    public function testSoloCalculaUnaVezPorPeticion(): void
    {
        $a = $this->ana();
        $this->assertSame($a->clientesVisibles(), $a->clientesVisibles());
    }

    public function testVeCliente(): void
    {
        $a = $this->ana();
        $this->assertTrue($a->veCliente(10));
        $this->assertTrue($a->veCliente(15));
        $this->assertFalse($a->veCliente(13), 'el 13 es de otro responsable');
        $this->assertFalse($a->veCliente(999));
    }

    public function testSqlClientes(): void
    {
        $sql = trim($this->ana()->sqlClientes('c.id'));
        $this->assertStringStartsWith('AND c.id IN (', $sql);
        $this->assertStringContainsString('10', $sql);
        $this->assertStringNotContainsString('13', $sql);
    }

    public function testSqlClientesSinNadaNoDejaPasarNada(): void
    {
        $a = new Acceso($this->pdo, 5, ['tareas.ver']);
        $this->assertStringContainsString('1=0', $a->sqlClientes('c.id'));
    }
}
