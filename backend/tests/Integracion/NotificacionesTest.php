<?php
namespace Croilab\Tests\Integracion;

/* Avisos (admin/lib/notificaciones.php) contra la base: no se repiten, se
   pueden silenciar por categoría, las menciones llegan a quien toca y las
   URLs son rutas del front nuevo. Sin sesión (CLI), «yo» es nadie (id 0). */
class NotificacionesTest extends BaseDatosTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('nueva');
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO admins (id, username, password_hash, role, activo) VALUES (1,'duena','x','owner',1), (2,'ana','x','editor',1), (3,'luis','x','viewer',1)");
        $pdo->exec("INSERT INTO clients (id, name, activo) VALUES (1,'Alfa',1)");
        $pdo->exec("INSERT INTO task_lists (id, client_id, nombre) VALUES (1,1,'Tareas')");
        $pdo->exec("INSERT INTO tasks (id, client_id, list_id, titulo, responsable_id, estado) VALUES (1,1,1,'Web nueva',3,'pendiente')");
    }

    protected function setUp(): void
    {
        self::pdo()->exec('DELETE FROM notifications');
        self::pdo()->exec("DELETE FROM settings WHERE clave LIKE 'notifmute_%'");
    }

    private function avisos(int $adminId): array
    {
        $st = self::pdo()->prepare('SELECT tipo, titulo, cuerpo, url, tarea, actor, bandeja FROM notifications WHERE admin_id = ? ORDER BY id');
        $st->execute([$adminId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testMismoRefNoSeRepite(): void
    {
        notif_add(2, 'tarea', 'hola', '', '/tareas/1', 'x:1');
        notif_add(2, 'tarea', 'hola otra vez', '', '/tareas/1', 'x:1');
        notif_add(2, 'tarea', 'sin ref', '', '/tareas/1');
        $this->assertCount(2, $this->avisos(2));
        $this->assertSame(2, notif_unread(2));
    }

    public function testSilenciarAvisosNoSilenciaTareas(): void
    {
        self::pdo()->prepare('INSERT INTO settings (clave, valor) VALUES (?, ?)')->execute(['notifmute_2', 'avisos,chat']);
        notif_add(2, 'info', 'aviso', '');
        notif_add(2, 'chat', 'mensaje', '');
        notif_add(2, 'tarea', 'asignada', '');
        $this->assertSame(['tarea'], array_column($this->avisos(2), 'tipo'));
    }

    public function testAsignarTareaAvisaConRutaNueva(): void
    {
        notif_task_assigned(1, 2, 'duena');
        $a = $this->avisos(2);
        $this->assertCount(1, $a);
        $this->assertSame('/tareas/1', $a[0]['url']);
        $this->assertSame('Web nueva', $a[0]['tarea']);
        $this->assertSame('Alfa', $a[0]['cuerpo']);
        $this->assertSame('duena', $a[0]['actor']);
    }

    public function testComentarioAvisaAMencionadosYAlResponsableUnaVez(): void
    {
        notif_comment_scan(1, 7, 'Mira esto @ana y @luis, y @nadie', 'duena');
        $ana = $this->avisos(2);
        $luis = $this->avisos(3);
        $this->assertCount(1, $ana);
        $this->assertSame('/tareas/1#c7', $ana[0]['url']);
        $this->assertStringStartsWith('te ha mencionado', $ana[0]['titulo']);
        // Luis es el responsable y además está mencionado: un único aviso.
        $this->assertCount(1, $luis);
        notif_comment_scan(1, 7, 'Mira esto @ana y @luis', 'duena');
        $this->assertCount(1, $this->avisos(2));
    }

    public function testDescripcionNoRepiteAlAutoguardar(): void
    {
        notif_desc_scan(1, 'Pendiente de @ana', 'duena');
        notif_desc_scan(1, 'Pendiente de @ana (editado)', 'duena');
        $this->assertCount(1, $this->avisos(2));
    }

    public function testClienteNuevoSoloALosDuenos(): void
    {
        notif_client_new(1, 'ana');
        $this->assertSame('/clientes/1', $this->avisos(1)[0]['url'] ?? null);
        $this->assertSame([], $this->avisos(2));
        $this->assertSame([], $this->avisos(3));
    }
}
