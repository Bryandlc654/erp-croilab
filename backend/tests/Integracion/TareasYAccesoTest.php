<?php
namespace Croilab\Tests\Integracion;

use Croilab\Http\HttpError;
use Croilab\Modulos\Clientes\ClientesRepositorio;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Modulos\Tareas\TareasRepositorio;
use Croilab\Modulos\Tareas\TareasServicio;
use Croilab\Seguridad\Acceso;

/* Permisos y alcance, de punta a punta contra la base: quién ve qué y quién
   puede cambiar qué. Personas:
     1 dueña   (admin.total)
     2 ana     (alcance limitado: solo lo suyo; puede editar)
     3 lector  (lo ve todo; no edita)
   Clientes: 1 Alfa (tarea 1 de ana) · 2 Beta (tarea 2 de la dueña, tarea 3
   asignada a ana por task_assignees) · 3 Gamma (tarea 4 de la dueña). */
class TareasYAccesoTest extends BaseDatosTestCase
{
    private const DUENA = ['admin.total'];
    private const ANA = ['ver.tareas', 'general.editar', 'tareas.editar'];
    private const LECTOR = ['ver.tareas', 'alcance.todos'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('nueva');
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO admins (id, username, password_hash, role) VALUES (1,'duena','x','owner'), (2,'ana','x','editor'), (3,'lector','x','viewer')");
        $pdo->exec("INSERT INTO clients (id, name, activo) VALUES (1,'Alfa',1), (2,'Beta',1), (3,'Gamma',0)");
        $pdo->exec("INSERT INTO task_lists (id, client_id, nombre) VALUES (1,1,'Tareas'), (2,2,'Tareas'), (3,3,'Tareas')");
        $pdo->exec("INSERT INTO tasks (id, client_id, list_id, titulo, responsable_id, estado) VALUES
                    (1,1,1,'De ana',2,'pendiente'), (2,2,2,'De la dueña',1,'en proceso'),
                    (3,2,2,'Asignada a ana',1,'pendiente'), (4,3,3,'Otra',1,'completada')");
        $pdo->exec('INSERT INTO task_assignees (task_id, admin_id) VALUES (3, 2)');
    }

    private function acceso(int $id, array $permisos): Acceso
    {
        return new Acceso(self::pdo(), $id, $permisos);
    }

    private function servicio(): TareasServicio
    {
        $equipo = new EquipoRepositorio(self::pdo());
        return new TareasServicio(new TareasRepositorio(self::pdo(), $equipo), new ClientesRepositorio(self::pdo()), $equipo);
    }

    private function titulos(Acceso $a, array $f = []): array
    {
        [$items] = $this->servicio()->listar($a, $f + ['view' => 'all'], 100, 0);
        return array_column($items, 'titulo');
    }

    /* ---------- Alcance ---------- */

    public function testConAlcanceLimitadoSoloVeLoSuyo(): void
    {
        $ana = $this->acceso(2, self::ANA);
        $this->assertSame(['De ana', 'Asignada a ana'], $this->titulos($ana));
        $this->assertSame([1, 2], $ana->clientesVisibles());
        $this->assertTrue($ana->veTarea(3), 'La asignación por task_assignees cuenta');
        $this->assertFalse($ana->veTarea(2));
        $this->assertFalse($ana->veCliente(3));
    }

    public function testConAlcanceTotalVeTodoSinCompletadas(): void
    {
        $this->assertSame(['De ana', 'De la dueña', 'Asignada a ana'], $this->titulos($this->acceso(3, self::LECTOR)));
        $this->assertSame(['Otra'], $this->titulos($this->acceso(3, self::LECTOR), ['fe' => 'completada']));
        $this->assertNull($this->acceso(1, self::DUENA)->clientesVisibles());
    }

    public function testLosClientesSeFiltranYSePaginan(): void
    {
        $repo = new ClientesRepositorio(self::pdo());
        [$items, $total] = $repo->listar($this->acceso(2, self::ANA), [], 50, 0);
        $this->assertSame(['Alfa', 'Beta'], array_column($items, 'name'));
        $this->assertSame(2, $total);

        [$items, $total] = $repo->listar($this->acceso(1, self::DUENA), ['activo' => true], 1, 1);
        $this->assertSame(['Beta'], array_column($items, 'name'));
        $this->assertSame(2, $total);
        $this->assertSame(['total' => 3, 'activos' => 2, 'inactivos' => 1], $repo->contadores($this->acceso(1, self::DUENA)));
    }

    public function testLaVistaDeClienteAjenoEs404(): void
    {
        $this->expectExceptionObject(HttpError::noEncontrado('Cliente no encontrado.'));
        $this->servicio()->listar($this->acceso(2, self::ANA), ['view' => 'cliente', 'cli' => 3], 100, 0);
    }

    /* ---------- Escrituras ---------- */

    public function testNoPuedeEditarLoQueNoVe(): void
    {
        try {
            $this->servicio()->actualizar($this->acceso(2, self::ANA), 2, ['prioridad' => 1]);
            $this->fail('Debía ser 404');
        } catch (HttpError $e) {
            $this->assertSame(404, $e->status);
        }
        $this->assertSame(0, (int)self::pdo()->query('SELECT prioridad FROM tasks WHERE id = 2')->fetchColumn());
    }

    public function testElLectorNoEdita(): void
    {
        $this->expectExceptionObject(HttpError::permiso());
        $this->servicio()->actualizar($this->acceso(3, self::LECTOR), 1, ['prioridad' => 1]);
    }

    public function testSinPermisoDeCrearNiDeBorrar(): void
    {
        $ana = $this->acceso(2, self::ANA);
        foreach ([fn() => $this->servicio()->crear($ana, ['client_id' => 1, 'list_id' => 1, 'titulo' => 'x']),
                  fn() => $this->servicio()->borrar($ana, 1)] as $f) {
            try { $f(); $this->fail('Debía ser 403'); } catch (HttpError $e) { $this->assertSame(403, $e->status); }
        }
    }

    public function testCambiarResponsableActualizaLosAsignados(): void
    {
        $t = $this->servicio()->actualizar($this->acceso(1, self::DUENA), 4, ['responsable_id' => 2]);
        $this->assertSame(2, $t['responsable_id']);
        $this->assertSame(['ana'], array_column($t['asignados'], 'username'));
        $t = $this->servicio()->actualizar($this->acceso(1, self::DUENA), 4, ['responsable_id' => null]);
        $this->assertNull($t['responsable_id']);
        $this->assertSame([], $t['asignados']);
    }

    public function testPatchNoTocaLoQueNoSeEnvia(): void
    {
        self::pdo()->exec('UPDATE tasks SET visible_cliente = 1 WHERE id = 2');
        $t = $this->servicio()->actualizar($this->acceso(1, self::DUENA), 2, ['titulo' => 'De la dueña']);
        $this->assertTrue($t['visible_cliente'], 'Cambiar el título no puede ocultar la tarea del portal');
    }

    public function testCrearExigeUnaListaDelCliente(): void
    {
        try {
            $this->servicio()->crear($this->acceso(1, self::DUENA), ['client_id' => 1, 'list_id' => 2, 'titulo' => 'x']);
            $this->fail('Debía rechazarse');
        } catch (HttpError $e) {
            $this->assertSame('list_id', $e->extra['campo']);
        }
        $t = $this->servicio()->crear($this->acceso(1, self::DUENA), ['client_id' => 1, 'list_id' => 1, 'titulo' => 'Nueva', 'responsable_id' => 3]);
        $this->assertSame('Nueva', $t['titulo']);
        $this->assertSame(['lector'], array_column($t['asignados'], 'username'));
    }

    public function testBorrarVaALaPapeleraYSePuedeRestaurar(): void
    {
        $duena = $this->acceso(1, self::DUENA);
        self::pdo()->exec("INSERT INTO task_comments (task_id, admin_id, cuerpo) VALUES (1, 2, 'hola')");
        $tid = $this->servicio()->borrar($duena, 1);
        $this->assertGreaterThan(0, $tid);
        $this->assertFalse(self::pdo()->query('SELECT 1 FROM tasks WHERE id = 1')->fetchColumn());
        $this->assertFalse(self::pdo()->query('SELECT 1 FROM task_comments WHERE task_id = 1')->fetchColumn());

        $this->assertSame(1, $this->servicio()->restaurar($duena, $tid));
        $this->assertSame('De ana', self::pdo()->query('SELECT titulo FROM tasks WHERE id = 1')->fetchColumn());
        $this->assertSame('hola', self::pdo()->query('SELECT cuerpo FROM task_comments WHERE task_id = 1')->fetchColumn());
    }

    public function testRestaurarLoDeOtroExigePermisoDePapelera(): void
    {
        $tid = $this->servicio()->borrar($this->acceso(1, self::DUENA), 4);
        $this->expectExceptionObject(HttpError::permiso());
        /* Puede editar, pero no borró ella y no tiene papelera.restaurar. */
        $this->servicio()->restaurar($this->acceso(2, [...self::ANA, 'tareas.borrar']), $tid);
    }
}
