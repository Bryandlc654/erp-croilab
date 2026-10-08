<?php
namespace Croilab\Tests\Integracion\Clientes;

use Croilab\Http\HttpError;
use Croilab\Google\GoogleOAuth;
use Croilab\Google\Metricas;
use Croilab\Modulos\Clientes\AgenciasServicio;
use Croilab\Modulos\Clientes\AvanzadoServicio;
use Croilab\Modulos\Clientes\ClientesRepositorio;
use Croilab\Modulos\Clientes\ClientesServicio;
use Croilab\Modulos\Clientes\FichaRepositorio;
use Croilab\Modulos\Clientes\GoogleServicio;
use Croilab\Modulos\Clientes\ServiciosServicio;
use Croilab\Modulos\Clientes\TiposServicio;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Modulos\Equipo\Reautenticacion;
use Croilab\Seguridad\Acceso;
use Croilab\Tests\Integracion\BaseDatosTestCase;

/* Clientes de punta a punta contra la base: alta con sus listas y su aviso,
   validación, alcance (lo que no se ve es 404), permisos (403), borrado a la
   papelera y vuelta, duplicar, contraseña del portal, tipos, servicios,
   marca blanca, Google y datos avanzados.
   Personas: 1 dueña y 4 socia (acceso total, dueñas) · 2 ana (solo lo suyo) ·
   3 lector (ve todo, no escribe).
   Clientes: 1 Alfa (tarea de ana) · 2 Beta (nada de ana). */
class ClientesTest extends BaseDatosTestCase
{
    private const DUENA = ['admin.total'];
    private const ANA = ['ver.clientes', 'general.editar', 'clientes.editar', 'clientes.crear', 'clientes.borrar', 'ver.ajustes', 'marca.editar'];
    private const LECTOR = ['ver.clientes', 'alcance.todos', 'ver.ajustes'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach (['marca', 'servicios_cat', 'boveda'] as $lib) require_once __DIR__ . "/../../../admin/lib/$lib.php";
        self::preparar('nueva');
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO admins (id, username, password_hash, role) VALUES (1,'duena','" . password_hash('clave-duena', PASSWORD_DEFAULT) . "','owner'),
                    (2,'ana','x','editor'), (3,'lector','x','viewer'), (4,'socia','x','owner')");
        $pdo->exec("INSERT INTO clients (id, name, username, activo, conversiones) VALUES (1,'Alfa','alfa',1,1), (2,'Beta','beta',0,0)");
        $pdo->exec("INSERT INTO task_lists (id, client_id, nombre) VALUES (1,1,'Tareas'), (2,2,'Tareas')");
        $pdo->exec("INSERT INTO tasks (id, client_id, list_id, titulo, responsable_id, estado) VALUES (1,1,1,'De ana',2,'pendiente'), (2,2,2,'De la dueña',1,'en proceso')");
        $_SESSION = [];
    }

    private function acc(int $id, array $permisos): Acceso
    {
        return new Acceso(self::pdo(), $id, $permisos);
    }

    private function duena(): Acceso
    {
        return $this->acc(1, self::DUENA);
    }

    private function s(): ClientesServicio
    {
        $pdo = self::pdo();
        return new ClientesServicio($pdo, new ClientesRepositorio($pdo), new FichaRepositorio($pdo), new EquipoRepositorio($pdo));
    }

    private function estado(callable $f): int
    {
        try {
            $f();
        } catch (HttpError $e) {
            return $e->status;
        }
        return 200;
    }

    private function campo(callable $f): string
    {
        try {
            $f();
        } catch (HttpError $e) {
            return (string)($e->extra['campo'] ?? '');
        }
        return '';
    }

    private function alta(array $extra = []): int
    {
        static $n = 0;
        $n++;
        return $this->s()->crear($this->duena(), $extra + ['name' => "Nuevo $n", 'username' => "nuevo$n", 'password' => 'secreta1']);
    }

    /* ---------- Alta y validación ---------- */

    public function testAltaCreaListasHashYAvisaALasDemasDuenas(): void
    {
        $id = $this->alta(['login_email' => 'Cliente@Gmail.com', 'iniciales' => 'ab']);
        $pdo = self::pdo();
        $this->assertSame(['TAREAS', 'ESTRATEGIA', 'TAREA CLIENTE', 'INFORMES CLIENTE'], $pdo->query("SELECT nombre FROM task_lists WHERE client_id = $id ORDER BY orden")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame('informe', $pdo->query("SELECT tipo FROM task_lists WHERE client_id = $id AND orden = 3")->fetchColumn());
        $c = $pdo->query("SELECT password_hash, login_email, iniciales FROM clients WHERE id = $id")->fetch();
        $this->assertTrue(password_verify('secreta1', $c['password_hash']), 'La contraseña se guarda con password_hash');
        $this->assertSame('cliente@gmail.com', $c['login_email']);
        $this->assertSame('AB', $c['iniciales']);
        // notif_client_new avisa a quien tiene acceso total (las dos dueñas, sin sesión no hay «yo»).
        $avisos = $pdo->query("SELECT admin_id, url FROM notifications WHERE ref = 'clinew:$id' ORDER BY admin_id")->fetchAll();
        $this->assertSame([1, 4], array_map('intval', array_column($avisos, 'admin_id')));
        $this->assertSame("/clientes/$id", $avisos[0]['url']);
    }

    public function testValidacionDeLaFicha(): void
    {
        $s = $this->s();
        $d = $this->duena();
        $this->assertSame('name', $this->campo(fn() => $s->crear($d, ['username' => 'x1', 'password' => 'secreta1'])));
        $this->assertSame('username', $this->campo(fn() => $s->crear($d, ['name' => 'X', 'username' => 'ALFA', 'password' => 'secreta1'])), 'Usuario repetido (sin distinguir mayúsculas)');
        $this->assertSame('password', $this->campo(fn() => $s->crear($d, ['name' => 'X', 'username' => 'x2'])), 'Al crear hace falta contraseña');
        $this->assertSame('password', $this->campo(fn() => $s->crear($d, ['name' => 'X', 'username' => 'x2', 'password' => '123'])));
        $this->assertSame('tipo_id', $this->campo(fn() => $s->crear($d, ['name' => 'X', 'username' => 'x2', 'password' => 'secreta1', 'tipo_id' => 999])));
        $this->assertSame('fact_email', $this->campo(fn() => $s->actualizar($d, 1, ['fact_email' => 'no-es-un-email'])));
        $s->actualizar($d, 1, ['login_email' => 'alfa@gmail.com']);
        $this->assertSame('login_email', $this->campo(fn() => $s->actualizar($d, 2, ['login_email' => 'ALFA@gmail.com'])), 'El correo de Google es único');
    }

    public function testEditarNuncaTocaLasMetricasYCambiarLaContrasenaCierraSesiones(): void
    {
        $pdo = self::pdo();
        $pdo->exec("UPDATE clients SET met_json = '{\"Mayo\":{\"ll\":1}}', servicios_json = '[\"SEO\"]' WHERE id = 1");
        $ver = (int)$pdo->query('SELECT cred_ver FROM clients WHERE id = 1')->fetchColumn();
        $this->s()->actualizar($this->duena(), 1, ['saludo' => 'Hola', 'password' => 'nueva-clave']);
        $c = $pdo->query('SELECT met_json, servicios_json, saludo, cred_ver, password_hash FROM clients WHERE id = 1')->fetch();
        $this->assertSame('{"Mayo":{"ll":1}}', $c['met_json']);
        $this->assertSame('["SEO"]', $c['servicios_json']);
        $this->assertSame('Hola', $c['saludo']);
        $this->assertSame($ver + 1, (int)$c['cred_ver']);
        $this->assertTrue(password_verify('nueva-clave', $c['password_hash']));
    }

    /* ---------- Permisos y alcance ---------- */

    public function testElAlcanceLimitadoNoVeNiTocaClientesAjenos(): void
    {
        $ana = $this->acc(2, self::ANA);
        $s = $this->s();
        [$items] = $s->listar($ana, [], 50, 0, true);
        $this->assertSame(['Alfa'], array_column($items, 'name'));
        $this->assertSame(1, $items[0]['tareas_abiertas']);
        $this->assertNull($items[0]['pendiente_cobro'], 'Sin ver.importes los euros no salen');
        foreach ([fn() => $s->ficha($ana, 2), fn() => $s->datos($ana, 2), fn() => $s->actualizar($ana, 2, ['saludo' => 'x']),
                  fn() => $s->borrar($ana, 2), fn() => $s->duplicar($ana, 2), fn() => $s->restablecerPassword($ana, 2)] as $f) {
            $this->assertSame(404, $this->estado($f));
        }
        $this->assertSame(404, $this->estado(fn() => (new AgenciasServicio(self::pdo()))->asignar($ana, 2, null)));
        $this->assertSame(['Alfa'], array_column((new AgenciasServicio(self::pdo()))->listar($ana)['clientes'], 'name'));
    }

    public function testSinPermisoNoEscribe(): void
    {
        $lector = $this->acc(3, self::LECTOR);
        $s = $this->s();
        $this->assertSame(200, $this->estado(fn() => $s->ficha($lector, 2)));
        foreach ([fn() => $s->crear($lector, ['name' => 'X', 'username' => 'x9', 'password' => 'secreta1']), fn() => $s->actualizar($lector, 1, ['saludo' => 'x']),
                  fn() => $s->borrar($lector, 1), fn() => $s->duplicar($lector, 1), fn() => $s->restablecerPassword($lector, 1),
                  fn() => $s->secreto($lector, 1, 1)] as $f) {
            $this->assertSame(403, $this->estado($f));
        }
        // Crear exige clientes.crear (el antiguo pedía clientes.editar).
        $soloEditar = $this->acc(2, ['ver.clientes', 'general.editar', 'clientes.editar', 'alcance.todos']);
        $this->assertSame(403, $this->estado(fn() => $s->crear($soloEditar, ['name' => 'X', 'username' => 'x9', 'password' => 'secreta1'])));
        // Lo que ve el cliente en su portal pide clientes.portal, pero solo si cambia.
        $this->assertSame(403, $this->estado(fn() => $s->actualizar($soloEditar, 1, ['estado' => ['nombre' => 'Otra etapa']])));
        $actual = $s->datos($this->duena(), 1);
        $this->assertSame(200, $this->estado(fn() => $s->actualizar($soloEditar, 1, ['estado' => $actual['estado'], 'saludo' => 'Sin cambiar el portal'])));
    }

    /* ---------- Papelera ---------- */

    public function testBorrarVaALaPapeleraYRestaurarLoDevuelveTodo(): void
    {
        $pdo = self::pdo();
        $id = $this->alta(['fact_nombre' => 'Gamma S.L.']);
        $lista = (int)$pdo->query("SELECT id FROM task_lists WHERE client_id = $id ORDER BY orden LIMIT 1")->fetchColumn();
        $pdo->exec("INSERT INTO tasks (client_id, list_id, titulo, estado) VALUES ($id, $lista, 'Tarea G', 'pendiente')");
        $tarea = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO task_comments (task_id, cuerpo) VALUES ($tarea, 'hola')");
        $pdo->exec("INSERT INTO time_entries (admin_id, task_id, client_id, fecha, minutos, concepto) VALUES (1, $tarea, $id, '2026-06-01', 30, '')");
        $horas = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO invoices (numero, client_id, cliente_nombre, estado) VALUES ('T-1', $id, '', 'enviada')");
        $factura = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO support_tickets (asunto, client_id) VALUES ('Ayuda', $id)");
        $ticket = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO support_replies (ticket_id, cuerpo) VALUES ($ticket, 'respuesta')");
        $pdo->exec("INSERT INTO client_credentials (client_id, titulo, secreto) VALUES ($id, 'Web', 's3creto')");
        $pdo->exec("INSERT INTO portal_meeting_requests (client_id, motivo) VALUES ($id, 'Hablar')");

        $nombre = (string)$pdo->query("SELECT name FROM clients WHERE id = $id")->fetchColumn();
        $tid = $this->s()->borrar($this->duena(), $id);
        $this->assertFalse($pdo->query("SELECT 1 FROM clients WHERE id = $id")->fetchColumn());
        foreach (['tasks', 'task_lists', 'client_credentials', 'support_tickets', 'portal_meeting_requests'] as $t) {
            $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM $t WHERE client_id = $id")->fetchColumn(), $t);
        }
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM task_comments WHERE task_id = $tarea")->fetchColumn());
        $h = $pdo->query("SELECT client_id, task_id, concepto FROM time_entries WHERE id = $horas")->fetch();
        $this->assertNull($h['client_id']);
        $this->assertSame("Tarea G · $nombre", $h['concepto'], 'Las horas se quedan con un concepto que se entiende sin el cliente');
        $f = $pdo->query("SELECT client_id, cliente_nombre FROM invoices WHERE id = $factura")->fetch();
        $this->assertNull($f['client_id'], 'La factura no se borra: suelta la referencia');
        $this->assertNotSame('', $f['cliente_nombre'], 'y conserva a quién se hizo');
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM support_replies WHERE ticket_id = $ticket")->fetchColumn());

        // Ana no lo borró y no tiene permiso de papelera.
        $this->assertSame(403, $this->estado(fn() => $this->s()->restaurar($this->acc(2, self::ANA + ['x' => 'alcance.todos']), $tid)));
        $this->assertSame($id, $this->s()->restaurar($this->duena(), $tid));
        foreach (['tasks' => 1, 'task_lists' => 4, 'client_credentials' => 1, 'support_tickets' => 1, 'portal_meeting_requests' => 1, 'invoices' => 1] as $t => $n) {
            $this->assertSame($n, (int)$pdo->query("SELECT COUNT(*) FROM $t WHERE client_id = $id")->fetchColumn(), $t);
        }
        $this->assertSame([$id, $tarea], array_map('intval', array_values($pdo->query("SELECT client_id, task_id FROM time_entries WHERE id = $horas")->fetch())));
        $this->assertSame(404, $this->estado(fn() => $this->s()->restaurar($this->duena(), $tid)), 'Ya no está en la papelera');
    }

    /* ---------- Duplicar y contraseña ---------- */

    public function testDuplicarCopiaLoDeTrabajoPeroNoLoFiscalNiLasMetricasNiLaContrasena(): void
    {
        $pdo = self::pdo();
        $pdo->exec("UPDATE clients SET fact_nif = 'B1', met_json = '{\"Mayo\":{}}', plan_json = '{\"resumen\":\"P\"}', servicios_json = '[\"SEO\"]', tipo_id = 1 WHERE id = 1");
        [$nuevo, $pass] = $this->s()->duplicar($this->duena(), 1);
        $c = $pdo->query("SELECT * FROM clients WHERE id = $nuevo")->fetch();
        $this->assertSame('Alfa (copia)', $c['name']);
        $this->assertSame('alfa_copia', $c['username']);
        $this->assertSame('', $c['fact_nif']);
        $this->assertNull($c['met_json']);
        $this->assertSame('{"resumen":"P"}', $c['plan_json']);
        $this->assertSame('["SEO"]', $c['servicios_json']);
        $this->assertSame(1, (int)$c['tipo_id']);
        $this->assertTrue(password_verify($pass, $c['password_hash']));
        $this->assertSame(4, (int)$pdo->query("SELECT COUNT(*) FROM task_lists WHERE client_id = $nuevo")->fetchColumn());
        [$otro] = $this->s()->duplicar($this->duena(), 1);
        $this->assertSame('alfa_copia2', $pdo->query("SELECT username FROM clients WHERE id = $otro")->fetchColumn());
    }

    public function testRestablecerContrasenaDevuelveUnaNuevaUnaVez(): void
    {
        $pass = $this->s()->restablecerPassword($this->duena(), 2);
        $this->assertGreaterThanOrEqual(12, strlen($pass));
        $this->assertTrue(password_verify($pass, (string)self::pdo()->query('SELECT password_hash FROM clients WHERE id = 2')->fetchColumn()));
    }

    /* ---------- Ficha ---------- */

    public function testFichaConImportesYCredencialesSegunPermiso(): void
    {
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO invoices (id, numero, client_id, estado, iva_pct, irpf_pct) VALUES (900,'F-900',2,'pagada',21,0), (901,'F-901',2,'enviada',21,15)");
        $pdo->exec("INSERT INTO invoice_items (invoice_id, cantidad, precio) VALUES (900, 1, 100), (900, 1.5, 0.67), (901, 1, 200)");
        $pdo->exec("INSERT INTO client_credentials (id, client_id, titulo, secreto) VALUES (900, 2, 'Hosting', 'abc')");
        $f = $this->s()->ficha($this->duena(), 2);
        // 100 + round(1,5·0,67 = 1,005 → 1,01) = 101,01 · +21 % (21,2121 → 21,21) = 122,22
        $this->assertSame(12222, $f['resumen']['cobrado']);
        $this->assertSame(21200, $f['facturas']['pendiente']);   // 200 + 42 − 30
        $this->assertFalse(isset($f['credenciales']['items'][0]['secreto']), 'El secreto nunca va en la ficha');
        $this->assertSame('abc', $this->s()->secreto($this->duena(), 2, 900));
        $this->assertSame(404, $this->estado(fn() => $this->s()->secreto($this->duena(), 1, 900)), 'La credencial tiene que ser de ese cliente');

        $sinDinero = $this->s()->ficha($this->lector(), 2);
        $this->assertNull($sinDinero['resumen']['cobrado']);
        $this->assertNull($sinDinero['facturas']['ultimas'][0]['total']);
        $this->assertNull($sinDinero['credenciales']);
    }

    private function lector(): Acceso
    {
        return $this->acc(3, self::LECTOR);
    }

    /* ---------- Tipos ---------- */

    public function testTiposAltaRapidaEditarYBorrar(): void
    {
        $t = new TiposServicio(self::pdo());
        [$a, $dup] = $t->crear($this->duena(), ['nombre' => 'Mantenimiento', 'rapido' => true]);
        $this->assertFalse($dup);
        $this->assertSame(array_fill_keys(TiposServicio::SECCIONES, true), $a['secciones']);
        [$b, $dup] = $t->crear($this->duena(), ['nombre' => 'Mantenimiento', 'rapido' => true]);
        $this->assertTrue($dup);
        $this->assertSame($a['id'], $b['id']);
        $e = $t->actualizar($this->duena(), $a['id'], ['secciones' => ['metricas' => false, 'plan' => true]]);
        $this->assertFalse($e['secciones']['metricas']);
        $this->assertTrue($e['secciones']['plan']);
        $this->assertFalse($e['secciones']['progreso'], 'Lo que no llega se apaga');
        self::pdo()->exec("UPDATE clients SET tipo_id = {$a['id']} WHERE id = 2");
        $this->assertSame(1, $t->borrar($this->duena(), $a['id']));
        $this->assertNull(self::pdo()->query('SELECT tipo_id FROM clients WHERE id = 2')->fetchColumn() ?: null);
        $this->assertSame(403, $this->estado(fn() => $t->crear($this->lector(), ['nombre' => 'X'])));
        $this->assertSame('nombre', $this->campo(fn() => $t->crear($this->duena(), ['nombre' => ' '])));
    }

    /* ---------- Servicios ---------- */

    public function testRenombrarUnServicioLoArrastraALosClientesYConservaSuVideo(): void
    {
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO settings (clave, valor) VALUES ('servicios_catalogo', '[{\"nombre\":\"SEO\",\"desc\":\"a\",\"video\":\"J9-aEZ523bA\"},{\"nombre\":\"SEM\",\"desc\":\"b\",\"video\":\"\"},{\"nombre\":\"CRO\",\"desc\":\"c\",\"video\":\"\"}]')
                    ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        $pdo->exec("UPDATE clients SET servicios_json = '[\"SEO\",\"CRO\"]' WHERE id = 2");
        $s = new ServiciosServicio();
        $r = $s->guardar($this->duena(), [
            ['nombre' => 'Posicionamiento SEO', 'desc' => 'a', 'orig' => 'SEO'],
            ['nombre' => 'SEM', 'desc' => 'b', 'orig' => 'SEM'],
            ['nombre' => 'Email', 'desc' => 'nuevo', 'orig' => ''],
        ]);
        $this->assertSame(1, $r['renombrados']);
        $this->assertSame('["Posicionamiento SEO"]', $pdo->query('SELECT servicios_json FROM clients WHERE id = 2')->fetchColumn(), 'Renombrado y CRO quitado');
        $cat = $s->listar($this->duena())['servicios'];
        $this->assertSame(['Posicionamiento SEO', 'SEM', 'Email'], array_column($cat, 'nombre'));
        $this->assertTrue($cat[0]['video'], 'El vídeo sigue al servicio renombrado');
        $this->assertSame('servicios', $this->campo(fn() => $s->guardar($this->duena(), [['nombre' => 'A'], ['nombre' => 'a']])));
        $this->assertSame(403, $this->estado(fn() => $s->guardar($this->lector(), [['nombre' => 'A']])));
    }

    /* ---------- Marca blanca ---------- */

    public function testAgenciasValidanYAlBorrarLosClientesVuelvenALaCasa(): void
    {
        $a = new AgenciasServicio(self::pdo());
        $this->assertSame('meeting_url', $this->campo(fn() => $a->crear($this->duena(), ['nombre' => 'X', 'meeting_url' => 'javascript:alert(1)'])));
        $this->assertSame('color', $this->campo(fn() => $a->crear($this->duena(), ['nombre' => 'X', 'color' => 'red;}'])));
        $ag = $a->crear($this->duena(), ['nombre' => 'Faro', 'whatsapp' => '+34 600 11 22 33', 'color' => '#0EA5E9']);
        $this->assertSame('34600112233', $ag['whatsapp']);
        $a->asignar($this->duena(), 2, $ag['id']);
        $this->assertSame(1, $a->listar($this->duena())['agencias'][0]['uso']);
        $a->borrar($this->duena(), $ag['id']);
        $this->assertNull(self::pdo()->query('SELECT partner_id FROM clients WHERE id = 2')->fetchColumn() ?: null);
    }

    /* ---------- Google y datos avanzados ---------- */

    public function testGoogleGuardaConfiguracionYUnEventoNoPuedeEstarEnDosTipos(): void
    {
        $g = new GoogleServicio(self::pdo(), $this->s(), new Metricas(new GoogleOAuth(), self::pdo()));
        $this->assertSame('eventos', $this->campo(fn() => $g->guardar($this->duena(), 1, ['eventos' => ['ll' => ['click'], 'wa' => ['click'], 'fo' => []]])));
        $this->assertSame('prop', $this->campo(fn() => $g->guardar($this->duena(), 1, ['prop' => 'properties/12'])));
        $this->assertSame('site', $this->campo(fn() => $g->guardar($this->duena(), 1, ['site' => 'ftp://x'])));
        $g->guardar($this->duena(), 1, ['site' => 'sc-domain:alfa.test', 'prop' => '313888031', 'eventos' => ['ll' => ['phone_call'], 'wa' => [], 'fo' => ['form_ok', 'lead']]]);
        $v = $g->ver($this->duena(), 1);
        $this->assertSame(['phone_call'], $v['eventos']['ll']);
        $this->assertSame(['form_ok', 'lead'], $v['eventos']['fo']);
        $this->assertFalse($v['conectado']);
        // Sin conexión con Google, pedir eventos o sincronizar es un 409 con su explicación.
        $this->assertSame(409, $this->estado(fn() => $g->eventos($this->duena(), 1, '')));
        $this->assertSame(409, $this->estado(fn() => $g->sincronizar($this->duena(), 1)));
    }

    public function testDatosAvanzadosPidenLaContrasenaOtraVez(): void
    {
        $_SESSION = [];
        $av = new AvanzadoServicio(self::pdo(), $this->s());
        // Sin confirmar: el 403 «reauth» común de Equipo, con su zona.
        try {
            $av->ver($this->duena(), 1);
            $this->fail('Debía pedir la contraseña');
        } catch (HttpError $e) {
            $this->assertSame([403, 'reauth', 'datos'], [$e->status, $e->codigo, $e->extra['zona'] ?? null]);
        }
        $this->assertSame(403, $this->estado(fn() => $av->ver($this->acc(2, self::ANA), 1)), 'Sin datos.avanzado, ni con contraseña');
        $duena = self::pdo()->query('SELECT id, password_hash FROM admins WHERE id = 1')->fetch();
        $this->assertSame('password', $this->campo(fn() => Reautenticacion::confirmar($duena, 'mala', 'datos')));
        Reautenticacion::confirmar($duena, 'clave-duena', 'datos');
        $av->guardar($this->duena(), 1, ['servicios_json' => '', 'informes_json' => '[{"mes":"Junio","titulo":"I","texto":"t","url":""}]']);
        $c = self::pdo()->query('SELECT servicios_json, informes_json FROM clients WHERE id = 1')->fetch();
        $this->assertNull($c['servicios_json'], 'Vacío = ve todos los servicios');
        $this->assertStringContainsString('"Junio"', $c['informes_json']);
        $av->bloquear();
        $this->assertSame(403, $this->estado(fn() => $av->ver($this->duena(), 1)));
    }
}
