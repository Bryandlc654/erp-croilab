<?php
namespace Croilab\Tests\Integracion\Portal;

use Croilab\Correo\Correo;
use Croilab\Correo\Mensaje;
use Croilab\Google\Cuenta;
use Croilab\Http\Diferidas;
use Croilab\Http\HttpError;
use Croilab\Http\Kernel;
use Croilab\Http\Request;
use Croilab\Http\Router;
use Croilab\Modulos\Clientes\ClientesRepositorio;
use Croilab\Modulos\Clientes\ClientesServicio;
use Croilab\Modulos\Clientes\FichaRepositorio;
use Croilab\Modulos\Comunicacion\Soporte\SoporteRepositorio;
use Croilab\Modulos\Comunicacion\Soporte\SoporteServicio;
use Croilab\Modulos\Equipo\BovedaServicio;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Modulos\Finanzas\Modulo;
use Croilab\Modulos\Portal\EditorServicio;
use Croilab\Modulos\Portal\PortalAuthServicio;
use Croilab\Modulos\Portal\PortalRepositorio;
use Croilab\Modulos\Portal\PortalServicio;
use Croilab\Modulos\Portal\PortalSesion;
use Croilab\Seguridad\Acceso;
use Croilab\Tests\Integracion\BaseDatosTestCase;

/* Portal del cliente de punta a punta contra la base: acceso (contraseña,
   freno, baja, cred_ver, Google, recuperación), aislamiento entre clientes y
   entre sesiones (cliente ↔ equipo), lo que ve el cliente, sus acciones, la
   vista previa y el editor en vivo del equipo.
   Clientes: 1 Alfa (el nuestro) · 2 Beta (otro) · 3 Baja (activo = 0).
   Equipo: 1 dueña (todo) · 2 ana (solo lo suyo: tareas de Beta) · 3 lector (ve, no edita el portal). */
class PortalTest extends BaseDatosTestCase
{
    private const PASS = 'Clave-cliente-1';
    /** @var Mensaje[] */
    private static array $correos = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach (['login_throttle', 'marca', 'servicios_cat', 'boveda'] as $lib) require_once __DIR__ . "/../../../admin/lib/$lib.php";
        self::preparar('nueva');
        $pdo = self::pdo();
        $h = password_hash(self::PASS, PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO admins (id, username, password_hash, role) VALUES (1,'duena','x','owner'), (2,'ana','x','editor'), (3,'lector','x','viewer')");
        $pdo->prepare("INSERT INTO clients (id, name, username, password_hash, activo, conversiones, login_email, fact_email, actual, met_json, tareas_json, informes_json, estado_json)
                       VALUES (1,'Alfa','alfa',?,1,1,'alfa@gmail.test','fact@alfa.test','Septiembre',?,?,?,?),
                              (2,'Beta','beta',?,1,1,'','', '', '{}','{}','[]','{}'),
                              (3,'Baja','baja',?,0,1,'','', '', '{}','{}','[]','{}')")
            ->execute([$h, json_encode(['Agosto' => ['ll' => 1], '2026-09' => ['ll' => 2, 'wa' => 1]]), json_encode(['Septiembre' => ['completado' => [['t' => 'Hecho', 'd' => '']]]]),
                       json_encode([['mes' => 'Septiembre', 'titulo' => 'Inf', 'texto' => 'x', 'url' => '']]), json_encode(['nombre' => 'Etapa', 'fases' => []]), $h, $h]);
        $pdo->exec("INSERT INTO task_lists (id, client_id, nombre, es_cliente, tipo) VALUES (1,1,'TAREAS',0,'tareas'), (2,2,'TAREAS',0,'tareas')");
        $pdo->exec("INSERT INTO tasks (id, client_id, list_id, titulo, titulo_cliente, explicacion_cliente, descripcion, estado, visible_cliente, mes, responsable_id) VALUES
                    (1,1,1,'Interna visible','Para el cliente','Explicación','DESCRIPCIÓN INTERNA','pendiente',1,'Septiembre',1),
                    (2,1,1,'Oculta',NULL,'','secreta','pendiente',0,'Septiembre',1),
                    (3,2,2,'De Beta','De Beta','','','pendiente',1,'Septiembre',2)");
        $em = json_encode(['name' => 'Croilab']);
        $pdo->prepare("INSERT INTO invoices (id, numero, client_id, cliente_nombre, fecha, estado, iva_pct, irpf_pct, emisor, emisor_json, tipo) VALUES
                    (1,'2026-001',1,'Alfa','2026-09-01','pagada',21,0,'principal',?,'normal'),
                    (2,NULL,1,'Alfa','2026-09-02','borrador',21,0,'principal',NULL,'normal'),
                    (3,'2026-002',2,'Beta','2026-09-03','enviada',21,0,'principal',?,'normal')")->execute([$em, $em]);
        $pdo->exec("INSERT INTO invoice_items (invoice_id, concepto, cantidad, precio) VALUES (1,'Plan',1,100.00), (1,'Extra',3,0.35), (3,'Web',1,500)");
        $pdo->exec("INSERT INTO support_tickets (id, asunto, cuerpo, client_id, prioridad, estado) VALUES (1,'De Alfa','x',1,2,'abierto'), (2,'De Beta','y',2,2,'abierto')");
        $pdo->exec("INSERT INTO support_replies (ticket_id, admin_id, cuerpo) VALUES (1, 2, 'Respuesta'), (2, 2, 'Para Beta')");
        $pdo->prepare('INSERT INTO client_credentials (id, client_id, titulo, categoria, usuario, secreto, visible_cliente) VALUES (1,1,?,?,?,?,1), (2,1,?,?,?,?,0), (3,2,?,?,?,?,1)')
            ->execute(['WP', 'cms', 'u', boveda_cifrar('S-alfa', 'cred_cliente'), 'Hosting', 'hosting', 'r', boveda_cifrar('S-oculto', 'cred_cliente'), 'WP', 'cms', 'u', boveda_cifrar('S-beta', 'cred_cliente')]);
        $_SESSION = [];
    }

    protected function setUp(): void
    {
        $_SESSION = [];
        self::$correos = [];
        Diferidas::descartar();
        self::pdo()->exec('DELETE FROM login_attempts');
    }

    /* ---------- Montaje ---------- */

    private function sesion(): PortalSesion
    {
        return new PortalSesion(self::pdo());
    }

    private function auth(): PortalAuthServicio
    {
        $correo = new class implements Correo {
            public function enviar(Mensaje $m): void
            {
                PortalTest::guardarCorreo($m);
            }
        };
        return new PortalAuthServicio(self::pdo(), $this->sesion(), null, fn() => $correo, 'https://app.test');
    }

    public static function guardarCorreo(Mensaje $m): void
    {
        self::$correos[] = $m;
    }

    private function portal(): PortalServicio
    {
        $pdo = self::pdo();
        return new PortalServicio($pdo, new PortalRepositorio($pdo), fn() => (new Modulo($pdo))->hoja(),
            fn() => new SoporteServicio(new SoporteRepositorio($pdo), new EquipoRepositorio($pdo)), fn() => new BovedaServicio($pdo), new \DateTimeImmutable('2026-10-08'));
    }

    private function editor(): EditorServicio
    {
        $pdo = self::pdo();
        $repo = new ClientesRepositorio($pdo);
        return new EditorServicio($pdo, new ClientesServicio($pdo, $repo, new FichaRepositorio($pdo), new EquipoRepositorio($pdo)), $repo, new PortalRepositorio($pdo));
    }

    private function acc(int $id, array $permisos): Acceso
    {
        return new Acceso(self::pdo(), $id, $permisos);
    }

    private function error(callable $f): array
    {
        try {
            $f();
        } catch (HttpError $e) {
            return [$e->status, $e->codigo];
        }
        return [200, ''];
    }

    private function kernel(): Kernel
    {
        $r = new Router();
        (require __DIR__ . '/../../../api/rutas.php')($r, self::pdo());
        return new Kernel($r);
    }

    private function llamar(string $metodo, string $ruta, array $cuerpo = []): array
    {
        if ($metodo !== 'GET') $_SERVER['HTTP_X_CSRF_TOKEN'] = csrf_token();
        [$status, , $txt] = $this->kernel()->manejar(new Request($metodo, $ruta, [], $cuerpo ? json_encode($cuerpo) : '', []));
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        return [$status, json_decode($txt, true)];
    }

    private function entrarAlfa(): void
    {
        $this->auth()->entrar('alfa', self::PASS);
    }

    /* ---------- Acceso ---------- */

    public function testEntrarConUsuarioOCorreoYSalir(): void
    {
        $r = $this->auth()->entrar('ALFA', self::PASS);
        $this->assertSame(1, $r['cliente']['id']);
        $this->assertSame(1, $_SESSION['portal_cli']);
        $this->assertArrayNotHasKey('admin_id', $_SESSION, 'Un cliente nunca tiene sesión de equipo');
        $this->assertSame($r['csrf'], $_SESSION['csrf_token']);
        $this->auth()->salir();
        $this->assertNull($this->sesion()->cliente());
        $this->auth()->entrar('Alfa@Gmail.test', self::PASS);
        $this->assertSame(1, $this->sesion()->id(), 'También con el correo de Google');
    }

    public function testCredencialesMalasFrenoYBaja(): void
    {
        $a = $this->auth();
        $this->assertSame([401, 'credenciales'], $this->error(fn() => $a->entrar('alfa', 'mala')));
        $this->assertSame([401, 'credenciales'], $this->error(fn() => $a->entrar('nadie', 'mala')));
        for ($i = 0; $i < 4; $i++) $this->error(fn() => $a->entrar('alfa', 'mala'));
        $this->assertSame([429, 'bloqueo'], $this->error(fn() => $a->entrar('alfa', self::PASS)), 'Tras 5 fallos, bloqueo aunque la contraseña sea buena');
        self::pdo()->exec('DELETE FROM login_attempts');
        $this->assertSame([403, 'inactivo'], $this->error(fn() => $a->entrar('baja', self::PASS)), 'Un cliente dado de baja ya no entra');
        $this->assertSame(0, $this->sesion()->id());
    }

    public function testCambiarLaContrasenaCierraLaSesion(): void
    {
        $this->entrarAlfa();
        $this->assertNotNull($this->sesion()->cliente());
        credenciales_cambiar('clients', 1, 'Otra-clave-99', ['sin_historial' => true]);
        $this->assertNull($this->sesion()->cliente(), 'cred_ver distinto: sesión fuera');
        $this->assertSame(0, $this->sesion()->id());
        self::pdo()->prepare('UPDATE clients SET password_hash = ? WHERE id = 1')->execute([password_hash(self::PASS, PASSWORD_DEFAULT)]);
    }

    public function testConSesionDeEquipoNoSeEntraComoCliente(): void
    {
        $_SESSION['admin_id'] = 1;
        $this->assertSame([409, 'equipo'], $this->error(fn() => $this->auth()->entrar('alfa', self::PASS)));
        $this->assertSame(0, $this->sesion()->id());
    }

    public function testVueltaDeGoogle(): void
    {
        $a = $this->auth();
        $ok = fn(string $email, string $volver = '/portal/login?m=4') => ['ok' => true, 'cuenta' => Cuenta::login(), 'volver' => $volver, 'codigo' => 'ok', 'msg' => '', 'email' => $email];
        $this->assertNull($a->vueltaGoogle($ok('alfa@gmail.test', '/ajustes')), 'Una vuelta que no es del portal no la toca el portal');
        $this->assertSame('/portal/login?ge=denied&m=4', $a->vueltaGoogle($ok('otro@gmail.test')));
        $this->assertSame(0, $this->sesion()->id());
        $this->assertSame('/portal/login?ge=cancel&m=4', $a->vueltaGoogle(['ok' => false, 'codigo' => 'cancelado'] + $ok('')));
        $this->assertSame('/portal', $a->vueltaGoogle($ok('alfa@gmail.test')));
        $this->assertSame(1, $this->sesion()->id());
    }

    public function testRecuperarContrasena(): void
    {
        $a = $this->auth();
        $a->pedirEnlace('beta', '127.0.0.1');   // sin correo: nada, sin delatarlo
        $a->pedirEnlace('alfa', '127.0.0.1');
        Diferidas::ejecutar();
        $this->assertCount(1, self::$correos);
        $this->assertSame('alfa@gmail.test', self::$correos[0]->para);
        $this->assertSame(1, preg_match('#/portal/restablecer\?token=([0-9a-f]{64})#', self::$correos[0]->texto, $m));
        $this->assertSame('alfa', $a->comprobarEnlace($m[1]));
        $this->assertSame([422, 'validacion'], $this->error(fn() => $a->restablecer($m[1], '123')));
        $this->entrarAlfa();
        $a->restablecer($m[1], 'Nueva-clave-77');
        $this->assertNull($this->sesion()->cliente(), 'Restablecer cierra las sesiones abiertas');
        $this->assertSame([404, 'no_encontrado'], $this->error(fn() => $a->restablecer($m[1], 'Otra-clave-88')), 'Un solo uso');
        $this->assertSame([404, 'no_encontrado'], $this->error(fn() => $a->comprobarEnlace(str_repeat('a', 64))));
        $a->entrar('alfa', 'Nueva-clave-77');
        self::pdo()->prepare('UPDATE clients SET password_hash = ? WHERE id = 1')->execute([password_hash(self::PASS, PASSWORD_DEFAULT)]);
    }

    /* ---------- Lo que ve el cliente ---------- */

    public function testDatosSoloDelClienteYSoloLoVisible(): void
    {
        $d = $this->portal()->datos(1);
        $this->assertSame('2026-09', $d['cliente']['actual']);
        $this->assertSame(['2026-08', '2026-09'], array_column($d['metricas'], 'clave'), 'Meses con año y en orden');
        $this->assertSame(['Para el cliente'], array_column($d['tareas'], 'titulo'), 'Ni tareas ocultas ni de otro cliente');
        $this->assertSame('Explicación', $d['tareas'][0]['texto']);
        $this->assertStringNotContainsString('INTERNA', json_encode($d), 'La descripción interna no sale nunca');
        $this->assertSame([1], array_column($d['facturas'], 'id'), 'Ni borradores ni facturas de otro');
        $this->assertSame(12227, $d['facturas'][0]['total'], 'Base 101,05 + IVA 21,22 (redondeo a céntimo, sin floats)');
    }

    public function testCredencialesSinSecretoYSecretoBajoDemanda(): void
    {
        $d = $this->portal()->datos(1);
        $this->assertSame([1], array_column($d['credenciales'], 'id'));
        $this->assertStringNotContainsString('S-alfa', json_encode($d), 'El secreto no va en el payload');
        $this->assertSame('S-alfa', $this->portal()->secreto(1, 1));
        $this->assertSame([404, 'no_encontrado'], $this->error(fn() => $this->portal()->secreto(1, 2)), 'No visible para el cliente');
        $this->assertSame([404, 'no_encontrado'], $this->error(fn() => $this->portal()->secreto(1, 3)), 'De otro cliente');
    }

    public function testFacturasYTicketsDeOtroClienteSon404(): void
    {
        $p = $this->portal();
        $this->assertSame('2026-001', $p->factura(1, 1)['numero']);
        $this->assertSame([404, 'no_encontrado'], $this->error(fn() => $p->factura(1, 2)), 'Borrador');
        $this->assertSame([404, 'no_encontrado'], $this->error(fn() => $p->factura(1, 3)), 'De Beta');
        $this->assertStringStartsWith('JVBER', $p->pdf(1, 1)['base64']);
        $t = $p->ticket(1, 1);
        $this->assertSame('ana', $t['respuestas'][0]['autor']['nombre']);
        $this->assertSame([404, 'no_encontrado'], $this->error(fn() => $p->ticket(1, 2)));
    }

    public function testEscribirYPedirReunionAvisanAlEquipo(): void
    {
        $p = $this->portal();
        $this->assertSame([422, 'validacion'], $this->error(fn() => $p->escribir(1, ['cuerpo' => '  '])));
        $t = $p->escribir(1, ['asunto' => '', 'cuerpo' => 'Hola']);
        $this->assertSame('Mensaje de Alfa', $t['asunto']);
        $this->assertSame(1, (int)self::pdo()->query("SELECT client_id FROM support_tickets WHERE id = {$t['id']}")->fetchColumn());
        $this->assertSame([422, 'validacion'], $this->error(fn() => $p->solicitarReunion(1, ['motivo' => ''])));
        $this->assertSame([422, 'validacion'], $this->error(fn() => $p->solicitarReunion(1, ['motivo' => 'x', 'fecha' => '2026-02-30'])));
        $this->assertSame([422, 'validacion'], $this->error(fn() => $p->solicitarReunion(1, ['motivo' => 'x', 'franja' => '<script>'])));
        $s = $p->solicitarReunion(1, ['motivo' => 'Revisar', 'fecha' => '2026-10-20', 'franja' => 'Por la tarde']);
        $this->assertSame('pendiente', $s['estado']);
        $this->assertSame(1, (int)self::pdo()->query("SELECT COUNT(*) FROM notifications WHERE admin_id = 1 AND ref = 'meetreq:{$s['id']}'")->fetchColumn());
        $this->assertSame(1, (int)self::pdo()->query("SELECT COUNT(*) FROM notifications WHERE admin_id = 1 AND ref LIKE 'tknew:{$t['id']}:%'")->fetchColumn());
    }

    /* ---------- Aislamiento de sesiones (por el Kernel, como llega de verdad) ---------- */

    public function testSesionDeClienteNoLlegaARutasDelEquipo(): void
    {
        $this->entrarAlfa();
        foreach (['/v1/clientes', '/v1/clientes/1/ficha', '/v1/portal/equipo/clientes', '/v1/portal/equipo/clientes/1', '/v1/me'] as $ruta) {
            [$st, $j] = $this->llamar('GET', $ruta);
            $this->assertSame(401, $st, $ruta);
            $this->assertSame('sesion', $j['error']);
        }
        [$st, $j] = $this->llamar('GET', '/v1/portal');
        $this->assertSame(200, $st);
        $this->assertSame('Alfa', $j['cliente']['name']);
        [$st] = $this->llamar('GET', '/v1/portal/facturas/3');
        $this->assertSame(404, $st, 'La factura de otro cliente no existe para este');
    }

    public function testSesionDeEquipoNoSirveEnElPortal(): void
    {
        $_SESSION['admin_id'] = 1;   // sin pasar por current_admin(): el portal ni lo mira
        foreach (['/v1/portal', '/v1/portal/facturas/1', '/v1/portal/tickets/1'] as $ruta) {
            [$st, $j] = $this->llamar('GET', $ruta);
            $this->assertSame(401, $st, $ruta);
            $this->assertSame('portal_sesion', $j['error']);
        }
        [$st] = $this->llamar('POST', '/v1/portal/tickets', ['cuerpo' => 'x']);
        $this->assertSame(401, $st);
    }

    public function testPostsDelPortalExigenCsrf(): void
    {
        $this->entrarAlfa();
        [$st, $j] = $this->kernel()->manejar(new Request('POST', '/v1/portal/tickets', [], '{"cuerpo":"x"}', []));
        $this->assertSame(419, $st);
        [$st] = $this->llamar('POST', '/v1/portal/tickets', ['cuerpo' => 'Con token']);
        $this->assertSame(201, $st);
    }

    /* ---------- Equipo: vista previa y editor ---------- */

    public function testVistaPreviaConAlcance(): void
    {
        $e = $this->editor();
        $ana = $this->acc(2, ['ver.clientes']);
        $this->assertSame([404, 'no_encontrado'], $this->error(fn() => $e->visible($ana, 1)), 'Fuera de su alcance');
        $e->visible($ana, 2);
        $this->assertSame([403, 'permiso'], $this->error(fn() => $e->visible($this->acc(2, []), 2)));
        $d = $this->portal()->datos(2, true);
        $this->assertTrue($d['vista_previa']);
        $this->assertFalse($d['puede_enviar']);
        $this->assertSame(['Beta'], array_column($e->clientes($ana), 'name'), 'El modo equipo solo lista su alcance');
    }

    public function testEditorPermisosYGuardado(): void
    {
        $e = $this->editor();
        $lector = $this->acc(3, ['ver.clientes', 'alcance.todos', 'general.editar']);
        $this->assertFalse($e->leer($lector, 1)['puede_guardar']);
        $this->assertSame([403, 'permiso'], $this->error(fn() => $e->guardar($lector, 1, ['saludo' => 'x'])), 'Sin clientes.portal');
        $duena = $this->acc(1, ['admin.total']);
        $antes = self::pdo()->query('SELECT met_json, cred_ver FROM clients WHERE id = 1')->fetch();
        $r = $e->guardar($duena, 1, [
            'saludo' => 'Ana', 'informes' => [['mes' => 'Octubre 2026', 'titulo' => 'Oct', 'texto' => 'Bien', 'url' => '']],
            'servicios' => ['SEO'], 'looker' => 'https://lookerstudio.google.com/embed/x', 'estado' => ['nombre' => 'Nueva', 'fases' => [['t' => 'F', 'estado' => 'now']]],
            'met_json' => '{"hack":1}', 'login_email' => 'x@y.z',
        ]);
        $this->assertSame('Ana', $r['contenido']['saludo']);
        $this->assertSame(['SEO'], $r['contenido']['servicios']);
        $this->assertSame('Oct', $r['contenido']['informes'][0]['titulo']);
        $fila = self::pdo()->query('SELECT met_json, login_email, cred_ver FROM clients WHERE id = 1')->fetch();
        $this->assertSame($antes['met_json'], $fila['met_json'], 'Nunca toca met_json');
        $this->assertSame('alfa@gmail.test', $fila['login_email'], 'Ni el correo de Google');
        $this->assertSame((int)$antes['cred_ver'], (int)$fila['cred_ver']);
        $this->assertSame([422, 'validacion'], $this->error(fn() => $e->guardar($duena, 1, ['username' => 'beta'])), 'Usuario repetido');
        $this->assertSame([422, 'validacion'], $this->error(fn() => $e->guardar($duena, 1, ['accesos' => [['b' => 'X', 'u' => 'javascript:x', 'tipo' => 'web']]])));
        $e->guardar($duena, 1, ['password' => 'Cambiada-123']);
        $this->assertSame((int)$antes['cred_ver'] + 1, (int)self::pdo()->query('SELECT cred_ver FROM clients WHERE id = 1')->fetchColumn(), 'Cambiar la contraseña desde el editor cierra sus sesiones');
        self::pdo()->prepare('UPDATE clients SET password_hash = ? WHERE id = 1')->execute([password_hash(self::PASS, PASSWORD_DEFAULT)]);
    }
}
