<?php
namespace Croilab\Tests\Unit;

use Croilab\Http\HttpError;
use Croilab\Modulos\Clientes\ClientesRepositorio;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Modulos\Tareas\TareasRepositorio;
use Croilab\Modulos\Tareas\TareasServicio;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/* Validación de los campos de una tarea (sin MySQL: SQLite en memoria para
   la única consulta que hace, la de si existe la persona). */
class ValidacionTareasTest extends TestCase
{
    private TareasServicio $s;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('CREATE TABLE admins (id INTEGER PRIMARY KEY, username TEXT)');
        $pdo->exec("INSERT INTO admins VALUES (1, 'ana'), (2, 'luis')");
        $equipo = new EquipoRepositorio($pdo);
        $this->s = new TareasServicio(new TareasRepositorio($pdo, $equipo), new ClientesRepositorio($pdo), $equipo);
    }

    public function testSoloDevuelveLoQueViene(): void
    {
        $this->assertSame(['prioridad' => 3], $this->s->validar(['prioridad' => '3']));
        $this->assertSame([], $this->s->validar(['desconocido' => 'x']));
    }

    public function testNormaliza(): void
    {
        $c = $this->s->validar([
            'titulo' => '  Hola  ', 'estado' => 'en proceso', 'due_date' => '', 'fecha_inicio' => '2026-02-28',
            'responsable_id' => 2, 'visible_cliente' => 'true', 'etiquetas' => ' seo ',
        ]);
        $this->assertSame([
            'titulo' => 'Hola', 'estado' => 'en proceso', 'due_date' => null, 'fecha_inicio' => '2026-02-28',
            'responsable_id' => 2, 'visible_cliente' => 1, 'etiquetas' => 'seo',
        ], $c);
    }

    public function testQuitarResponsable(): void
    {
        $this->assertSame(['responsable_id' => null], $this->s->validar(['responsable_id' => null]));
        $this->assertSame(['responsable_id' => null], $this->s->validar(['responsable_id' => '']));
    }

    public static function invalidos(): array
    {
        return [
            'título vacío' => [['titulo' => '   '], 'titulo'],
            'título largo' => [['titulo' => str_repeat('a', 256)], 'titulo'],
            'estado' => [['estado' => 'hecho'], 'estado'],
            'prioridad alta' => [['prioridad' => 5], 'prioridad'],
            'prioridad negativa' => [['prioridad' => -1], 'prioridad'],
            'prioridad texto' => [['prioridad' => 'alta'], 'prioridad'],
            'fecha imposible' => [['due_date' => '2026-02-30'], 'due_date'],
            'fecha con formato' => [['fecha_inicio' => '30/01/2026'], 'fecha_inicio'],
            'persona inexistente' => [['responsable_id' => 99], 'responsable_id'],
            'texto no escalar' => [['mes' => ['x']], 'mes'],
        ];
    }

    #[DataProvider('invalidos')]
    public function testRechazaConCampo(array $datos, string $campo): void
    {
        try {
            $this->s->validar($datos);
            $this->fail('Debía rechazarse');
        } catch (HttpError $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame($campo, $e->extra['campo'] ?? null);
        }
    }
}
