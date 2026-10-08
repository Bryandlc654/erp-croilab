<?php
namespace Croilab\Tests\Integracion\Comunicacion;

use Croilab\Http\HttpError;
use Croilab\Modulos\Comunicacion\Cron;
use Croilab\Modulos\Comunicacion\Soporte\SoporteRepositorio;
use Croilab\Modulos\Comunicacion\Soporte\SoporteServicio;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;
use Croilab\Tests\Integracion\BaseDatosTestCase;

/* Tickets: permisos (responder ≠ editar), alcance (incluidos los sin cliente
   que son tuyos), aviso al asignar y al responder, papelera y portal.
   Personas: 1 duena (todo), 2 ana (editora con alcance total), 3 limi (sin
   alcance.todos: solo ve el cliente 2 por su tarea), 4 lector (solo ver). */
class SoporteTest extends BaseDatosTestCase
{
    private const DUENA = ['admin.total'];
    private const ANA = ['ver.soporte', 'general.editar', 'soporte.responder', 'alcance.todos'];
    private const LIMI = ['ver.soporte', 'general.editar', 'soporte.responder'];
    private const LECTOR = ['ver.soporte', 'alcance.todos'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('nueva');
        roles_todos(true);
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO admins (id, username, password_hash, role) VALUES (1,'duena','x','owner'),(2,'ana','x','editor'),(3,'limi','x','editor'),(4,'lector','x','viewer')");
        $pdo->exec("INSERT INTO clients (id, name, activo) VALUES (1, 'Alfa', 1), (2, 'Beta', 1)");
        $pdo->exec("INSERT INTO task_lists (id, client_id, nombre) VALUES (1, 2, 'Tareas')");
        $pdo->exec("INSERT INTO tasks (id, client_id, list_id, titulo, responsable_id) VALUES (1, 2, 1, 'De limi', 3)");
    }

    private function acc(int $id, array $p): Acceso
    {
        return new Acceso(self::pdo(), $id, $p);
    }

    private function s(): SoporteServicio
    {
        return new SoporteServicio(new SoporteRepositorio(self::pdo()), new EquipoRepositorio(self::pdo()));
    }

    private function falla(callable $f, int $status, string $campo = ''): void
    {
        try {
            $f();
        } catch (HttpError $e) {
            $this->assertSame($status, $e->status, $e->getMessage());
            if ($campo !== '') $this->assertSame($campo, $e->extra['campo'] ?? '');
            return;
        }
        $this->fail("Se esperaba un error $status");
    }

    public function testCrearAsignarResponderYAlcance(): void
    {
        $s = $this->s();
        $ana = $this->acc(2, self::ANA);
        $limi = $this->acc(3, self::LIMI);
        $lector = $this->acc(4, self::LECTOR);

        $this->falla(fn() => $s->crear($ana, ['asunto' => '  ']), 422, 'asunto');
        $this->falla(fn() => $s->crear($ana, ['asunto' => 'x', 'prioridad' => 9]), 422, 'prioridad');
        $this->falla(fn() => $s->crear($ana, ['asunto' => 'x', 'assignee_id' => 99]), 422, 'assignee_id');
        $this->falla(fn() => $s->crear($lector, ['asunto' => 'x']), 403);

        $t1 = $s->crear($ana, ['asunto' => 'No va la web', 'cuerpo' => 'Error 500', 'prioridad' => 4, 'assignee_id' => 1, 'client_id' => 1]);
        $this->assertSame(['abierto', 4, 'Alfa', 'duena', 'ana', false], [$t1['estado'], $t1['prioridad'], $t1['cliente'], $t1['asignado']['username'], $t1['creador']['username'], $t1['desde_portal']]);
        $aviso = self::pdo()->query("SELECT tipo, url, ref FROM notifications WHERE admin_id = 1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['tipo' => 'ticket', 'url' => '/soporte/' . $t1['id'], 'ref' => 'tkassign:' . $t1['id'] . ':1'], $aviso);

        $t2 = $s->crear($ana, ['asunto' => 'Del cliente Beta', 'client_id' => 2]);
        $t3 = $s->crear($limi, ['asunto' => 'Sin cliente, mío']);
        $this->falla(fn() => $s->crear($limi, ['asunto' => 'x', 'client_id' => 1]), 422, 'client_id');   // cliente fuera de su alcance

        /* limi ve los de su cliente y los suyos sin cliente, no los de Alfa. */
        $ids = array_column($s->listar($limi, '', 0)['items'], 'id');
        sort($ids);
        $this->assertSame([$t2['id'], $t3['id']], $ids);
        $this->falla(fn() => $s->detalle($limi, $t1['id']), 404);
        $this->falla(fn() => $s->listar($limi, '', 1), 404);
        /* El antiguo los escondía todos; ahora, si se lo asignan, lo ve. */
        $s->actualizar($ana, $t1['id'], ['assignee_id' => 3]);
        $this->assertSame('Alfa', $s->detalle($limi, $t1['id'])['ticket']['cliente']);

        /* Responder y el estado piden soporte.responder; lo demás, general.editar. */
        $this->falla(fn() => $s->responder($lector, $t1['id'], ['cuerpo' => 'hola']), 403);
        $this->falla(fn() => $s->actualizar($lector, $t1['id'], ['estado' => 'resuelto']), 403);
        $this->falla(fn() => $s->responder($ana, $t1['id'], ['cuerpo' => ' ']), 422, 'cuerpo');
        $r = $s->responder($ana, $t1['id'], ['cuerpo' => 'Mirándolo']);
        $this->assertSame(1, $r['ticket']['respuestas']);
        $this->assertSame(1, (int)self::pdo()->query("SELECT COUNT(*) FROM notifications WHERE admin_id = 3 AND ref LIKE 'tkreply:%'")->fetchColumn(), 'Aviso a quien lo lleva');
        $s->actualizar($limi, $t1['id'], ['estado' => 'resuelto']);
        $this->falla(fn() => $s->actualizar($ana, $t1['id'], ['estado' => 'raro']), 422, 'estado');

        $l = $s->listar($ana, '', 0);
        $this->assertSame(['abierto' => 2, 'en_curso' => 0, 'esperando' => 0, 'resuelto' => 1, 'total' => 3], $l['contadores']);
        $this->assertSame($t1['id'], end($l['items'])['id'], 'Los resueltos al final');
        $this->assertSame([$t1['id']], array_column($s->listar($ana, 'resuelto', 0)['items'], 'id'));
        $this->assertSame(['id' => 2, 'nombre' => 'Beta'], $s->listar($ana, '', 2)['cliente']);
    }

    public function testPapeleraPortalYCron(): void
    {
        $s = $this->s();
        $duena = $this->acc(1, self::DUENA);
        $t = $s->crear($duena, ['asunto' => 'Borrable', 'client_id' => 1]);
        $s->responder($duena, $t['id'], ['cuerpo' => 'algo']);
        $pid = $s->borrar($duena, $t['id']);
        $this->falla(fn() => $s->detalle($duena, $t['id']), 404);
        $this->assertSame(0, (int)self::pdo()->query('SELECT COUNT(*) FROM support_replies WHERE ticket_id = ' . $t['id'])->fetchColumn());
        $this->assertSame($t['id'], $s->restaurar($duena, $pid));
        $this->assertSame(1, $s->detalle($duena, $t['id'])['ticket']['respuestas']);

        $id = $s->crearDesdePortal(2, '', 'Necesito ayuda', 'Beta');
        $p = $s->detalle($duena, $id)['ticket'];
        $this->assertSame(['Mensaje de Beta', true, null], [$p['asunto'], $p['desde_portal'], $p['asignado']]);
        $this->assertSame(1, (int)self::pdo()->query("SELECT COUNT(*) FROM notifications WHERE admin_id = 1 AND ref = 'tknew:$id:1'")->fetchColumn());

        self::pdo()->exec("UPDATE support_tickets SET created_at = NOW() - INTERVAL 2 DAY WHERE id = $id");
        $this->assertGreaterThanOrEqual(1, Cron::ejecutar(self::pdo())['tickets_sin_asignar']);
        Cron::ejecutar(self::pdo());
        $this->assertSame(1, (int)self::pdo()->query("SELECT COUNT(*) FROM notifications WHERE admin_id = 1 AND ref = 'tknoasig:$id:1'")->fetchColumn(), 'Una vez por ticket');
    }
}
