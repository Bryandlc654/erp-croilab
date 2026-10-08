<?php
namespace Croilab\Tests\Integracion\Trabajo;

use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Modulos\Trabajo\ActasRepositorio;
use Croilab\Modulos\Trabajo\ActasServicio;
use Croilab\Modulos\Trabajo\BuscarServicio;
use Croilab\Modulos\Trabajo\Cron;
use Croilab\Modulos\Trabajo\InicioServicio;
use Croilab\Modulos\Trabajo\NotificacionesRepositorio;
use Croilab\Modulos\Trabajo\NotificacionesServicio;
use Croilab\Modulos\Trabajo\PapeleraServicio;
use Croilab\Seguridad\Acceso;
use Croilab\Tests\Integracion\BaseDatosTestCase;

/* Inicio, avisos, búsqueda, papelera y actas, de punta a punta contra la base.
   Personas: 1 duena (todo) · 2 ana (alcance limitado: solo Alfa) · 3 luis (lector). */
class TrabajoTest extends BaseDatosTestCase
{
    private const DUENA = ['admin.total'];
    private const ANA = ['ver.tareas', 'ver.clientes', 'ver.crm', 'ver.finanzas', 'ver.importes', 'ver.actas', 'general.editar', 'tareas.editar'];
    private const LUIS = ['ver.tareas', 'ver.clientes', 'alcance.todos', 'ver.actas', 'ver.ajustes'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('nueva');
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO admins (id, username, password_hash, role) VALUES (1,'duena','x','owner'), (2,'ana','x','editor'), (3,'luis','x','viewer')");
        $pdo->exec("INSERT INTO clients (id, name, username, activo) VALUES (1,'Alfa','alfa',1), (2,'Beta','beta',1), (3,'Gamma','gamma',0)");
        $pdo->exec("INSERT INTO task_lists (id, client_id, nombre) VALUES (1,1,'Tareas'), (2,2,'Tareas')");
        $hoy = date('Y-m-d');
        $ayer = date('Y-m-d', strtotime('-1 day'));
        $pdo->exec("INSERT INTO tasks (id, client_id, list_id, titulo, responsable_id, estado, due_date, prioridad) VALUES
                    (1,1,1,'Revisar web Alfa',2,'en proceso','$hoy',4), (2,2,2,'Revisar web Beta',1,'pendiente','$ayer',0),
                    (3,2,2,'Hecha',1,'completada',NULL,0)");
        $pdo->exec("INSERT INTO invoices (id, numero, client_id, cliente_nombre, fecha, estado, iva_pct, irpf_pct, fecha_pago) VALUES
                    (1,'F-1',1,'Alfa','2020-01-01','pagada',21,15,'" . date('Y-m-03') . "'),
                    (2,'F-2',2,'Beta','" . date('Y-m-02') . "','pagada',21,0,'2020-01-01'),
                    (3,'F-3',2,'Beta','" . date('Y-m-02') . "','enviada',21,0,NULL)");
        $pdo->exec("INSERT INTO invoice_items (invoice_id, concepto, cantidad, precio) VALUES (1,'Web',3,33.33), (2,'SEO',1,100), (3,'SEO',1,100)");
        $pdo->exec("INSERT INTO contacts (id, nombre, empresa, propietario_id) VALUES (1,'Rosa Web','Óptica',2), (2,'Pedro Web','Taller',1)");
        $pdo->exec("INSERT INTO actas (id, titulo, contenido, admin_id) VALUES (1,'Reunión web','Hablar de la **web**',1)");
    }

    private function acc(int $id, array $p): Acceso
    {
        return new Acceso(self::pdo(), $id, $p);
    }

    private function codigo(callable $f): int
    {
        try { $f(); } catch (HttpError $e) { return $e->status; }
        return 200;
    }

    /* ---------- Inicio ---------- */

    public function testInicioConAlcanceYCobradoPorFechaDeCobro(): void
    {
        $i = new InicioServicio(self::pdo(), new EquipoRepositorio(self::pdo()));
        $ana = $i->resumen($this->acc(2, self::ANA));
        $this->assertSame(1, $ana['kpis']['clientes_activos']);
        $this->assertSame(['Revisar web Alfa'], array_column($ana['tareas']['en_proceso'], 'titulo'));
        $this->assertSame([], $ana['tareas']['atrasadas'], 'La atrasada es de Beta: fuera de su alcance');
        $this->assertSame(['Revisar web Alfa'], array_column($ana['hoy_tareas'], 'titulo'));
        /* F-1: base 99,99 → +21% IVA 21,00 −15% IRPF 15,00 = 105,99 €. F-2 se cobró en 2020. */
        $this->assertSame(10599, $ana['kpis']['cobrado_mes']);

        $duena = $i->resumen($this->acc(1, self::DUENA));
        $this->assertSame(2, $duena['kpis']['clientes_activos']);
        $this->assertSame(1, $duena['tareas']['atrasadas_total']);
        $this->assertSame(['Hecha'], array_column($duena['tareas']['completadas'], 'titulo'));
        $this->assertNull($i->resumen($this->acc(3, self::LUIS))['kpis']['cobrado_mes'], 'Sin Finanzas no ve importes');
    }

    /* ---------- Avisos ---------- */

    public function testBandejasAccionesYSondeo(): void
    {
        $pdo = self::pdo();
        $pdo->exec('DELETE FROM notifications');
        foreach ([[1, 'tarea', 'principal'], [1, 'tarea', 'otras'], [1, 'chat', 'principal'], [1, 'factura', 'principal'], [2, 'tarea', 'principal']] as [$a, $t, $b]) {
            notif_add($a, $t, "aviso $t $b", '', '/tareas/2', null, 'Revisar web Beta', 'ana', $b);
        }
        $s = new NotificacionesServicio(new NotificacionesRepositorio($pdo));
        $duena = $this->acc(1, self::DUENA);
        $l = $s->listar($duena, 'principal', 100, 0);
        $this->assertCount(2, $l['items']);
        $this->assertSame('pendiente', $l['items'][0]['tarea_estado']);
        $this->assertSame(1, $l['contadores']['otras']['total']);
        $this->assertSame(1, $l['contadores']['chat']['total']);

        $ids = array_column($l['items'], 'id');
        $s->accion($duena, ['accion' => 'posponer', 'ids' => [$ids[0]], 'horas' => 3]);
        $s->accion($duena, ['accion' => 'borrar', 'ids' => [$ids[1]]]);
        $c = $s->listar($duena, 'principal', 100, 0)['contadores'];
        $this->assertSame([0, 1, 1], [$c['principal']['total'], $c['tarde']['total'], $c['papelera']['total']]);

        /* Los avisos de otra persona no se tocan; purgar solo lo que está en la papelera. */
        $deAna = (int)$pdo->query('SELECT id FROM notifications WHERE admin_id = 2')->fetchColumn();
        $this->assertSame(0, $s->accion($duena, ['accion' => 'borrar', 'ids' => [$deAna]])['cambiadas']);
        $this->assertSame(0, $s->accion($duena, ['accion' => 'purgar', 'ids' => [$ids[0]]])['cambiadas']);
        $this->assertSame(1, $s->accion($duena, ['accion' => 'purgar', 'ids' => [$ids[1]]])['cambiadas']);

        /* Ana no ve el estado de una tarea que no es suya. */
        $this->assertNull($s->listar($this->acc(2, self::ANA), 'principal', 100, 0)['items'][0]['tarea_estado']);

        /* Sondeo: el primero solo da la línea base; después, lo nuevo (sin chat). */
        $base = $s->avisos($duena, 0);
        $this->assertSame([], $base['nuevos']);
        notif_add(1, 'tarea', 'nuevo de verdad', '', '/tareas/1');
        notif_add(1, 'chat', 'mensaje', '');
        $r = $s->avisos($duena, $base['ultimo_id']);
        $this->assertSame(['nuevo de verdad'], array_column($r['nuevos'], 'titulo'));
        $this->assertSame(422, $this->codigo(fn() => $s->accion($duena, ['accion' => 'volar', 'ids' => [1]])));
    }

    public function testFacturasVencidasSoloEmitidasYAQuienVeFinanzas(): void
    {
        $pdo = self::pdo();
        $pdo->exec('DELETE FROM notifications');
        $pdo->exec("INSERT INTO roles (clave, nombre, permisos) VALUES ('sinfin', 'Sin finanzas', '[\"ver.tareas\"]')");
        $pdo->exec("INSERT INTO admins (id, username, password_hash, role) VALUES (7, 'sinfin', 'x', 'sinfin')");
        $pdo->exec("INSERT INTO invoices (id, numero, client_id, fecha, fecha_venc, estado) VALUES
                    (70, 'V-70', 3, '2026-01-01', '2026-01-31', 'enviada'), (71, NULL, 3, '2026-01-01', '2026-01-31', 'enviada'),
                    (72, 'V-72', 3, '2026-01-01', '2026-01-31', 'anulada')");
        notif_sync_invoices();
        $this->assertSame('vencida', $pdo->query('SELECT estado FROM invoices WHERE id = 70')->fetchColumn());
        $this->assertSame('enviada', $pdo->query('SELECT estado FROM invoices WHERE id = 71')->fetchColumn(), 'Un borrador no vence');
        $refs = $pdo->query("SELECT DISTINCT ref FROM notifications WHERE tipo = 'factura'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['inv:70'], $refs);
        $this->assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE admin_id = 7')->fetchColumn(), 'Sin ver.finanzas no se entera');
        $this->assertGreaterThan(0, (int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE admin_id = 1')->fetchColumn());
    }

    /* ---------- Búsqueda ---------- */

    public function testBuscarRespetaPermisosYAlcance(): void
    {
        $b = new BuscarServicio(self::pdo());
        $grupos = fn(array $r) => array_column($r['grupos'], 'r', 'g');

        $ana = $grupos($b->buscar($this->acc(2, self::ANA), 'web'));
        $this->assertSame(['Revisar web Alfa'], array_column($ana['Tareas'], 't'));
        $this->assertSame(['Rosa Web'], array_column($ana['Contactos'], 't'), 'Solo sus contactos');
        $this->assertSame('/actas/1', $ana['Actas'][0]['u']);

        $f = $grupos($b->buscar($this->acc(2, self::ANA), 'F-'));
        $this->assertSame(['F-1 · Alfa'], array_column($f['Facturas'] ?? [], 't'), 'Solo facturas de sus clientes');

        $luis = $grupos($b->buscar($this->acc(3, self::LUIS), 'web'));
        $this->assertArrayNotHasKey('Contactos', $luis, 'Sin ver.crm no hay contactos');
        $this->assertCount(2, $luis['Tareas']);
        $this->assertSame([], $b->buscar($this->acc(3, self::LUIS), 'w')['grupos'], 'Mínimo dos letras');

        $pag = $grupos($b->buscar($this->acc(3, self::LUIS), 'papel'));
        $this->assertSame('/ajustes/papelera', $pag['Ir a'][0]['u']);
        $this->assertArrayNotHasKey('Ir a', $grupos($b->buscar($this->acc(2, self::ANA), 'papel')), 'Sin ver.ajustes no sale');
    }

    /* ---------- Papelera ---------- */

    public function testPapeleraPermisosYRestaurarCualquierTipo(): void
    {
        $p = new PapeleraServicio(self::pdo());
        $actas = new ActasServicio(new ActasRepositorio(self::pdo()), new EquipoRepositorio(self::pdo()));
        $duena = $this->acc(1, self::DUENA);
        $tid = $actas->borrar($duena, 1);

        $this->assertSame(403, $this->codigo(fn() => $p->listar($this->acc(2, self::ANA), 100, 0)), 'Sin ver.ajustes');
        $this->assertSame(['acta'], array_column($p->listar($this->acc(3, self::LUIS), 100, 0)['items'], 'tipo'));
        /* Ana puede editar, pero no lo borró ella ni tiene papelera.restaurar. */
        $this->assertSame(403, $this->codigo(fn() => $p->restaurar($this->acc(2, self::ANA), $tid)));
        $this->assertSame(403, $this->codigo(fn() => $p->purgar($this->acc(2, self::ANA), $tid)));
        $r = $p->restaurar($duena, $tid);
        $this->assertSame(['id' => 1, 'tipo' => 'acta', 'msg' => 'Acta restaurada.', 'url' => '/actas/1'], $r);
        $this->assertSame(404, $this->codigo(fn() => $p->restaurar($duena, $tid)));
    }

    public function testRestaurarClienteReenlazaYCaducarLimpiaRespuestas(): void
    {
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO clients (id, name, activo) VALUES (40,'Borrable',1)");
        $pdo->exec("INSERT INTO support_tickets (id, asunto, client_id) VALUES (40,'Ticket',40)");
        $pdo->exec("INSERT INTO support_replies (ticket_id, cuerpo) VALUES (40,'respuesta')");
        $pdo->exec("INSERT INTO invoices (id, numero, client_id, fecha, estado) VALUES (40,'F-40',NULL,'2026-01-01','pagada')");
        $pdo->exec("INSERT INTO time_entries (id, admin_id, task_id, client_id, fecha, minutos) VALUES (40, 1, NULL, NULL, '2026-01-01', 30)");
        $tid = pap_borrar('clients', 40, 'cliente', 'Borrable', [['tabla' => 'support_tickets', 'fk' => 'client_id']]);
        $d = json_decode((string)$pdo->query("SELECT datos FROM trash WHERE id = $tid")->fetchColumn(), true);
        $d['refs'] = ['invoices' => [40], 'time_entries' => [[40, null]]];
        $pdo->prepare('UPDATE trash SET datos = ? WHERE id = ?')->execute([json_encode($d), $tid]);

        $this->assertTrue(pap_restaurar($tid)['ok']);
        $this->assertSame(40, (int)$pdo->query('SELECT client_id FROM invoices WHERE id = 40')->fetchColumn(), 'La factura vuelve a su cliente');
        $this->assertSame(40, (int)$pdo->query('SELECT client_id FROM time_entries WHERE id = 40')->fetchColumn());

        /* Otra vez a la papelera, y esta vez caduca: sus respuestas de tickets sobran. */
        $tid = pap_borrar('clients', 40, 'cliente', 'Borrable', [['tabla' => 'support_tickets', 'fk' => 'client_id']]);
        $pdo->exec("UPDATE trash SET created_at = NOW() - INTERVAL 40 DAY WHERE id = $tid");
        Cron::ejecutar($pdo);
        $this->assertFalse($pdo->query("SELECT 1 FROM trash WHERE id = $tid")->fetchColumn());
        $this->assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM support_replies WHERE ticket_id = 40')->fetchColumn());
    }

    /* ---------- Actas ---------- */

    public function testActasPermisosYConflicto(): void
    {
        $s = new ActasServicio(new ActasRepositorio(self::pdo()), new EquipoRepositorio(self::pdo()));
        $duena = $this->acc(1, self::DUENA);
        $this->assertSame(403, $this->codigo(fn() => $s->crear($this->acc(3, self::LUIS), ['titulo' => 'x'])), 'El lector no escribe');
        $this->assertSame(403, $this->codigo(fn() => $s->listar($this->acc(9, ['ver.tareas']), '', 0, 100, 0)));
        $this->assertSame(422, $this->codigo(fn() => $s->crear($duena, ['titulo' => ' ', 'contenido' => "[[chk:0]]\n"])));
        $a = $s->crear($duena, ['titulo' => 'Nueva', 'contenido' => '- **uno**']);
        $this->assertSame('uno', $a['extracto']);
        $antes = $a['updated_at'];
        $s->fijar($duena, $a['id'], true);
        $this->assertSame($antes, $s->ver($duena, $a['id'])['updated_at'], 'Fijar no cuenta como edición');
        $this->assertSame('Nueva', $s->listar($duena, '', 0, 100, 0)['items'][0]['titulo'], 'Las fijadas primero');
        $this->assertSame(409, $this->codigo(fn() => $s->guardar($duena, $a['id'], ['contenido' => 'x', 'version' => '2000-01-01 00:00:00'])));
        $g = $s->guardar($duena, $a['id'], ['contenido' => 'otro', 'version' => $antes]);
        $this->assertSame('otro', $g['contenido']);
        $this->assertSame('Nueva', $g['titulo'], 'Lo que no se manda se conserva');
    }
}
