<?php
namespace Croilab\Tests\Integracion\Crm;

use Croilab\Correo\Correo;
use Croilab\Correo\Mensaje;
use Croilab\Http\HttpError;
use Croilab\Modulos\Clientes\ClientesRepositorio;
use Croilab\Modulos\Clientes\ClientesServicio;
use Croilab\Modulos\Clientes\FichaRepositorio;
use Croilab\Modulos\Crm\ConfigServicio;
use Croilab\Modulos\Crm\ContactosRepositorio;
use Croilab\Modulos\Crm\ContactosServicio;
use Croilab\Modulos\Crm\ConversionServicio;
use Croilab\Modulos\Crm\Cron;
use Croilab\Modulos\Crm\DashboardServicio;
use Croilab\Modulos\Crm\FichaServicio;
use Croilab\Modulos\Crm\Historial;
use Croilab\Modulos\Crm\ImportarServicio;
use Croilab\Modulos\Crm\ListasServicio;
use Croilab\Modulos\Crm\NegociosServicio;
use Croilab\Modulos\Crm\SeguimientosServicio;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;
use Croilab\Tests\Integracion\BaseDatosTestCase;

/* CRM de punta a punta contra la base: permisos (403), alcance por
   propietario (lo que no se ve es 404), contactos, papelera completa y vuelta,
   negocios y su embudo, fases, etiquetas, listas, seguimientos, resumen
   diario, importación CSV y conversión en cliente.
   Personas: 1 dueña (acceso total) · 2 ana (comercial sin alcance total ni
   borrar) · 3 lector (ve todo, no escribe) · 4 sin CRM.
   Contactos: 1 de ana · 2 de la dueña · 3 sin propietario. */
class CrmTest extends BaseDatosTestCase
{
    private const DUENA = ['admin.total'];
    private const ANA = ['ver.crm', 'general.editar', 'crm.crear', 'crm.editar'];
    private const LECTOR = ['ver.crm', 'alcance.todos'];
    private static string $subidas;
    /** @var Mensaje[] */
    public static array $correos = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach (['marca', 'credenciales'] as $lib) require_once __DIR__ . "/../../../admin/lib/$lib.php";
        self::preparar('nueva');
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO admins (id, username, password_hash, role, email) VALUES (1,'duena','x','owner','duena@ejemplo.test'),
                    (2,'ana','x','editor',NULL), (3,'lector','x','viewer',NULL), (4,'nadie','x','viewer',NULL)");
        $pdo->exec("INSERT INTO contacts (id, nombre, empresa, sector, email, telefono, propietario_id, fase) VALUES
                    (1,'Ana Cliente','Alfa SL','Salud','alfa@ejemplo.test','600000001',2,'lead_nuevo'),
                    (2,'Beto','Beta SL','Restauración','beta@ejemplo.test','600000002',1,'lead_nuevo'),
                    (3,'Carla','','Restauración',NULL,NULL,NULL,'lead_nuevo')");
        self::$subidas = sys_get_temp_dir() . '/crm-test-' . bin2hex(random_bytes(4));
        $_SESSION = [];
    }

    private function acc(int $id, array $p): Acceso
    {
        return new Acceso(self::pdo(), $id, $p);
    }

    private function duena(): Acceso
    {
        return $this->acc(1, self::DUENA);
    }

    private function ana(): Acceso
    {
        return $this->acc(2, self::ANA);
    }

    private function lector(): Acceso
    {
        return $this->acc(3, self::LECTOR);
    }

    private function contactos(): ContactosServicio
    {
        $pdo = self::pdo();
        return new ContactosServicio($pdo, new ContactosRepositorio($pdo), new EquipoRepositorio($pdo), new Historial($pdo));
    }

    private function negocios(): NegociosServicio
    {
        return new NegociosServicio(self::pdo(), $this->contactos(), new Historial(self::pdo()));
    }

    private function ficha(): FichaServicio
    {
        $pdo = self::pdo();
        return new FichaServicio($pdo, $this->contactos(), new ContactosRepositorio($pdo), new EquipoRepositorio($pdo), new Historial($pdo), self::$subidas);
    }

    private function seguimientos(): SeguimientosServicio
    {
        $pdo = self::pdo();
        $falso = new class implements Correo {
            public function enviar(Mensaje $m): void
            {
                CrmTest::$correos[] = $m;
            }
        };
        return new SeguimientosServicio($pdo, new EquipoRepositorio($pdo), new Historial($pdo), fn() => $falso);
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

    private function valor(string $sql): mixed
    {
        return self::pdo()->query($sql)->fetchColumn();
    }

    public function testAlcanceYPermisosDeLectura(): void
    {
        $ids = fn(Acceso $a) => array_column($this->contactos()->listar($a, [], 100, 0)['items'], 'id');
        $this->assertEqualsCanonicalizing([1, 3], $ids($this->ana()));            // lo suyo y lo sin propietario
        $this->assertEqualsCanonicalizing([1, 2, 3], $ids($this->lector()));
        $this->assertSame(404, $this->estado(fn() => $this->ficha()->ficha($this->ana(), 2)));
        $this->assertSame(403, $this->estado(fn() => $this->contactos()->listar($this->acc(4, ['general.editar']), [], 10, 0)));
        $this->assertSame(200, $this->estado(fn() => $this->ficha()->ficha($this->ana(), 3)));
        $r = $this->contactos()->listar($this->lector(), ['sector' => 'Restauración', 'sort' => 'nombre', 'dir' => 'asc'], 10, 0);
        $this->assertSame([2, 3], array_column($r['items'], 'id'));
        $this->assertSame(1, $r['n_filtros']);
        $this->assertSame(3, $r['total_sin_filtros']);
    }

    public function testCrearYEditarContacto(): void
    {
        $this->assertSame(403, $this->estado(fn() => $this->contactos()->crear($this->lector(), ['nombre' => 'X'])));
        $this->assertSame(422, $this->estado(fn() => $this->contactos()->crear($this->ana(), ['nombre' => '  '])));
        /* Sin alcance total no se puede crear un contacto para otra persona (dejaría de verlo). */
        $this->assertSame(422, $this->estado(fn() => $this->contactos()->crear($this->ana(), ['nombre' => 'X', 'propietario_id' => 1])));
        $c = $this->contactos()->crear($this->ana(), ['nombre' => 'Nuevo', 'empresa' => 'Gamma', 'propietario_id' => 2]);
        $this->assertSame('lead_nuevo', $c['fase']);
        $this->assertSame('creado', $this->valor("SELECT tipo FROM activities WHERE contact_id = {$c['id']}"));

        $e = $this->contactos()->actualizar($this->ana(), $c['id'], ['valor' => '1.234,56', 'servicios' => ['Web', 'SEO', 'Web'], 'fase' => 'onboarding']);
        $this->assertSame(1234.56, $e['valor']);
        $this->assertSame(['Web', 'SEO'], $e['servicios']);
        $this->assertSame('Fase cambiada a Onboarding', $this->valor("SELECT descripcion FROM activities WHERE contact_id = {$c['id']} AND tipo = 'fase'"));
        $this->assertSame(422, $this->estado(fn() => $this->contactos()->actualizar($this->ana(), $c['id'], ['email' => 'no-es-email'])));
        $this->assertSame(422, $this->estado(fn() => $this->contactos()->actualizar($this->ana(), $c['id'], ['fase' => 'inventada'])));
        $this->assertSame(404, $this->estado(fn() => $this->contactos()->actualizar($this->ana(), 2, ['nombre' => 'Hack'])));
    }

    public function testComentariosMencionesYBorrado(): void
    {
        $f = $this->ficha()->comentar($this->ana(), 1, ['tipo' => 'llamada', 'contenido' => "Llamada hecha.\n@duena mira esto"]);
        $this->assertSame('llamada', $f['comentarios'][0]['tipo']);
        $this->assertSame(date('Y-m-d'), $f['contacto']['fecha_ultimo_contacto']);
        $this->assertSame('Llamada hecha. @duena mira esto', $f['contacto']['ultima_actualizacion']);
        $this->assertSame('/crm/contactos/1', $this->valor("SELECT url FROM notifications WHERE admin_id = 1 AND tipo = 'lead' ORDER BY id DESC LIMIT 1"));
        $cid = $f['comentarios'][0]['id'];
        /* Solo el autor o el dueño lo borran. */
        $otro = $this->acc(5, ['ver.crm', 'general.editar', 'crm.crear', 'alcance.todos']);
        $this->assertSame(403, $this->estado(fn() => $this->ficha()->borrarComentario($otro, $cid)));
        $this->assertSame([], $this->ficha()->borrarComentario($this->duena(), $cid)['comentarios']);
    }

    public function testPapeleraCompletaYDeshacer(): void
    {
        $pdo = self::pdo();
        $n = $this->negocios()->crear($this->duena(), ['contact_id' => 2, 'valor' => '1000']);
        $pdo->exec("INSERT INTO crm_tags (id, nombre, color) VALUES (90, 'Temporal', '#000000')");
        $this->negocios()->etiqueta($this->duena(), $n['id'], 90, true);
        $this->ficha()->comentar($this->duena(), 2, ['contenido' => 'Hola']);
        $this->ficha()->facturacion($this->duena(), 2, ['razon_social' => 'Beta Sociedad Limitada', 'iban' => 'es91 2100 0418 4502 0005 1332']);
        $this->assertSame('ES9121000418450200051332', $this->valor('SELECT iban FROM billing_data WHERE contact_id = 2'));

        $this->assertSame(403, $this->estado(fn() => $this->contactos()->borrar($this->ana(), 1)));   // sin crm.borrar
        $tid = $this->contactos()->borrar($this->duena(), 2);
        foreach (['deals', 'comments', 'billing_data', 'activities'] as $t) $this->assertSame(0, (int)$this->valor("SELECT COUNT(*) FROM $t WHERE contact_id = 2"), $t);
        $this->assertSame(0, (int)$this->valor("SELECT COUNT(*) FROM deal_tags WHERE deal_id = {$n['id']}"));

        /* Otra persona sin permiso de papelera no puede deshacerlo. */
        $this->assertSame(403, $this->estado(fn() => $this->contactos()->restaurar($this->ana(), $tid)));
        $this->contactos()->restaurar($this->duena(), $tid);
        $this->assertSame(1, (int)$this->valor('SELECT COUNT(*) FROM deals WHERE contact_id = 2'));
        $this->assertSame(1, (int)$this->valor("SELECT COUNT(*) FROM deal_tags WHERE deal_id = {$n['id']}"));
        $this->assertSame('Beta Sociedad Limitada', $this->valor('SELECT razon_social FROM billing_data WHERE contact_id = 2'));
    }

    public function testEmbudoDeNegocios(): void
    {
        $neg = $this->negocios();
        $this->assertSame(422, $this->estado(fn() => $neg->crear($this->ana(), ['contact_id' => 2])));   // no lo ve
        $d = $neg->crear($this->ana(), ['contact_id' => 3, 'nombre' => 'Web Carla', 'valor' => '2.500', 'fecha_cierre_prevista' => '2026-12-01']);
        $this->assertSame('lead_nuevo', $d['fase']);
        $d = $neg->mover($this->ana(), $d['id'], ['fase' => 'negociacion', 'indice' => 0]);
        $this->assertSame(70, $d['probabilidad']);
        $this->assertSame('negociacion', $this->valor('SELECT fase FROM contacts WHERE id = 3'));   // el contacto sigue al negocio

        /* A una fase perdida solo se llega con motivo. */
        $this->assertSame(422, $this->estado(fn() => $neg->mover($this->ana(), $d['id'], ['fase' => 'perdido'])));
        $d = $neg->mover($this->ana(), $d['id'], ['fase' => 'perdido', 'motivo' => 'precio', 'comentario' => 'Caro']);
        $this->assertSame('perdido', $d['fase']);
        $this->assertSame((new \DateTimeImmutable('today'))->modify('+3 months')->format('Y-m-d'), $d['fecha_reactivacion']);
        $this->assertSame(date('Y-m-d'), $d['fecha_cierre_real']);
        $this->assertSame('perdido', $this->valor('SELECT fase FROM contacts WHERE id = 3'));

        $d = $neg->mover($this->ana(), $d['id'], ['fase' => 'ganado']);
        $this->assertNull($d['fecha_reactivacion']);
        $m = $neg->listar($this->ana(), false)['metricas'];
        $this->assertSame(1, $m['n_ganado_mes']);
        $this->assertSame(2500.0, $m['ganado_mes']);

        $d = $neg->actualizar($this->ana(), $d['id'], ['archivado' => true]);
        $this->assertTrue($d['archivado']);
        $this->assertSame([$d['id']], array_column($neg->listar($this->ana(), true)['items'], 'id'));
        $this->assertSame(403, $this->estado(fn() => $neg->borrar($this->ana(), $d['id'])));
        $this->assertGreaterThan(0, $neg->borrar($this->duena(), $d['id']));
    }

    public function testFasesEtiquetasYVistas(): void
    {
        $pdo = self::pdo();
        $cfg = new ConfigServicio($pdo, new Historial($pdo));
        $this->assertSame(403, $this->estado(fn() => $cfg->crearFase($this->ana(), ['nombre' => 'Demo'])));
        $fases = $cfg->crearFase($this->duena(), ['nombre' => 'Demo agendada', 'probabilidad' => 40, 'color' => '#123456']);
        $slugs = array_column($fases, 'slug');
        /* Va detrás de la última abierta y antes de las de cierre. */
        $this->assertSame(array_search('contrato', $slugs, true) + 1, array_search('demo_agendada', $slugs, true));
        $demo = $fases[array_search('demo_agendada', $slugs, true)]['id'];
        $this->assertSame(409, $this->estado(fn() => $cfg->borrarFase($this->duena(), $fases[0]['id'])));   // lead_nuevo es estructural

        $pdo->exec("UPDATE contacts SET fase = 'demo_agendada' WHERE id = 1");
        $r = $cfg->borrarFase($this->duena(), $demo);
        $this->assertSame('Lead nuevo', $r['destino']);
        $this->assertSame('lead_nuevo', $this->valor('SELECT fase FROM contacts WHERE id = 1'));   // no queda con una fase que no existe

        /* Reordenar no puede poner fases de cierre delante de las abiertas. */
        $ids = array_reverse(array_column($r['fases'], 'id'));
        $orden = $cfg->ordenFases($this->duena(), ['ids' => $ids]);
        $this->assertSame('abierta', $orden[0]['tipo']);
        $this->assertNotSame('abierta', end($orden)['tipo']);

        $cfg->crearEtiqueta($this->ana(), ['nombre' => 'VIP']);
        $this->assertSame(409, $this->estado(fn() => $cfg->crearEtiqueta($this->ana(), ['nombre' => 'VIP'])));
        $this->assertSame(403, $this->estado(fn() => $cfg->crearEtiqueta($this->lector(), ['nombre' => 'Otra'])));

        $v = $cfg->crearVista($this->ana(), ['nombre' => 'Mía', 'filtros' => ['sector' => 'Salud', 'sort' => 'valor', 'basura' => 1]]);
        $this->assertSame(['sector' => 'Salud', 'sort' => 'valor'], (array)$v[0]['filtros']);
        $this->assertSame(403, $this->estado(fn() => $cfg->crearVista($this->ana(), ['nombre' => 'Global', 'global' => true])));
        $this->assertSame(404, $this->estado(fn() => $cfg->borrarVista($this->lector(), $v[0]['id'])));   // no es suya
    }

    public function testListas(): void
    {
        $pdo = self::pdo();
        $l = new ListasServicio($pdo, new ContactosRepositorio($pdo), $this->contactos());
        $this->assertSame(422, $this->estado(fn() => $l->crear($this->ana(), ['nombre' => 'Vacía', 'tipo' => 'manual', 'ids' => []])));
        $act = $l->crear($this->duena(), ['nombre' => 'Restauración', 'tipo' => 'activa', 'condiciones' => ['sector' => 'Restauración']]);
        $this->assertEqualsCanonicalizing([2, 3], array_column($act['miembros'], 'id'));
        /* Ana solo ve los miembros que puede ver. */
        $this->assertSame([3], array_column($l->ver($this->ana(), $act['lista']['id'])['miembros'], 'id'));
        $act = $l->miembro($this->duena(), $act['lista']['id'], 1, true);
        $forzados = array_column(array_filter($act['miembros'], fn($m) => $m['forzado']), 'id');
        $this->assertSame([1], $forzados);
        $cong = $l->congelar($this->duena(), $act['lista']['id']);
        $this->assertSame('estatica', $cong['lista']['tipo']);
        $this->assertSame(3, (int)$this->valor("SELECT COUNT(*) FROM list_members WHERE list_id = {$act['lista']['id']}"));
        $this->assertStringContainsString('Nombre,Empresa', $l->exportar($this->duena(), $act['lista']['id'])['csv']);
        $this->assertSame(403, $this->estado(fn() => $l->borrar($this->ana(), $act['lista']['id'])));
    }

    public function testSeguimientosYResumenDiario(): void
    {
        $pdo = self::pdo();
        $pdo->exec('DELETE FROM follow_up_tasks');
        $pdo->exec("UPDATE contacts SET fecha_ultimo_contacto = NULL, fase = 'lead_nuevo' WHERE id IN (1,3)");
        $s = $this->seguimientos();
        $creados = $s->generar();
        $this->assertGreaterThanOrEqual(2, $creados);
        $this->assertSame(0, $s->generar());   // idempotente

        $b = $s->bandeja($this->ana());
        $llamar = array_values(array_filter($b['grupos'], fn($g) => $g['canal'] === 'llamar'))[0]['items'];
        $deAna = array_column($llamar, 'contact_id');
        $this->assertNotContains(2, $deAna);   // alcance

        $uno = $llamar[0]['id'];
        $s->omitir($this->ana(), $uno);
        $this->assertSame(0, $s->generar());   // omitir no lo vuelve a crear (fallo del antiguo)
        $this->assertSame(409, $this->estado(fn() => $s->hecho($this->ana(), $uno)));
        $otro = $llamar[1]['id'] ?? null;
        if ($otro) {
            $s->hecho($this->ana(), $otro);
            $this->assertSame(1, $s->bandeja($this->ana())['hechos_hoy']);
        }
        $this->assertSame(403, $this->estado(fn() => $s->posponer($this->lector(), $uno, 1)));

        self::$correos = [];
        $r = $s->ejecutarResumen(true, false);
        $this->assertSame('ok', $r['reason']);
        $this->assertCount(1, self::$correos);   // solo la dueña tiene correo
        $this->assertSame('duena@ejemplo.test', self::$correos[0]->para);
        $this->assertContains($s->ejecutarResumen(false, false)['reason'], ['ya_enviado', 'fin_de_semana']);   // una vez al día laborable
        $this->assertSame('/crm/reporting', $this->valor("SELECT url FROM notifications WHERE admin_id = 1 AND tipo = 'bell' ORDER BY id DESC LIMIT 1"));

        $out = Cron::ejecutar($pdo);
        $this->assertTrue($out['followups']['ok']);
        $this->assertArrayHasKey('daily_digest', $out);
        $pdo->exec("INSERT INTO settings (clave, valor) VALUES ('auto_followups', '0') ON DUPLICATE KEY UPDATE valor = '0'");
        $this->assertArrayNotHasKey('followups', Cron::ejecutar($pdo));
    }

    public function testImportarCsv(): void
    {
        $pdo = self::pdo();
        $imp = new ImportarServicio($pdo, new EquipoRepositorio($pdo), new Historial($pdo));
        $csv = "Nombre,Empresa,Email,Teléfono,Valor,Fase,Propietario\nRosa,Flores,rosa@ejemplo.test,611,\"1.250,5\",Negociación,ana\n,Sin nombre,,,,,\nRepe,X,alfa@ejemplo.test,,12.5,inventada,fantasma\n";
        $this->assertSame(403, $this->estado(fn() => $imp->importar($this->lector(), $csv, null, true, false)));
        $p = $imp->importar($this->duena(), $csv, null, true, false);
        $this->assertSame(0, $p['insertados']);
        $this->assertSame(1, $p['n_omitidas']);
        $this->assertSame(1, $p['n_duplicados']);
        $this->assertSame(2, $p['n_avisos']);
        $this->assertSame(1250.5, $p['muestra'][0]['valor']);
        $this->assertSame(422, $this->estado(fn() => $imp->importar($this->duena(), "Empresa\nX\n", null, true, false)));

        $antes = (int)$this->valor('SELECT COUNT(*) FROM contacts');
        $r = $imp->importar($this->duena(), $csv, null, false, true);
        $this->assertSame(1, $r['insertados']);
        $this->assertSame($antes + 1, (int)$this->valor('SELECT COUNT(*) FROM contacts'));
        $this->assertSame('negociacion', $this->valor("SELECT fase FROM contacts WHERE email = 'rosa@ejemplo.test'"));
        $this->assertSame('2', (string)$this->valor("SELECT propietario_id FROM contacts WHERE email = 'rosa@ejemplo.test'"));
    }

    public function testDashboard(): void
    {
        $pdo = self::pdo();
        $d = (new DashboardServicio($pdo, new EquipoRepositorio($pdo)))->datos($this->lector(), '', '');
        $this->assertCount(6, $d['meses']);
        $this->assertArrayHasKey('embudo', $d['series']);
        $this->assertSame(422, $this->estado(fn() => (new DashboardServicio($pdo, new EquipoRepositorio($pdo)))->datos($this->lector(), '2026-05-01', '2026-01-01')));
    }

    public function testConvertirEnCliente(): void
    {
        $pdo = self::pdo();
        $clientes = new ClientesServicio($pdo, new ClientesRepositorio($pdo), new FichaRepositorio($pdo), new EquipoRepositorio($pdo));
        $conv = new ConversionServicio($pdo, $this->contactos(), $clientes, new Historial($pdo));
        $this->assertSame(403, $this->estado(fn() => $conv->convertir($this->ana(), 1)));   // sin crm.convertir
        $r = $conv->convertir($this->duena(), 1);
        $this->assertFalse($r['ya']);
        $this->assertSame('alfasl', $r['usuario']);
        $this->assertNotEmpty($r['password']);
        $id = $r['cliente_id'];
        $this->assertSame('1', (string)$this->valor("SELECT contact_id FROM clients WHERE id = $id"));
        $this->assertSame((string)$id, (string)$this->valor('SELECT client_id FROM contacts WHERE id = 1'));
        $this->assertSame(4, (int)$this->valor("SELECT COUNT(*) FROM task_lists WHERE client_id = $id"));
        $this->assertTrue($conv->convertir($this->duena(), 1)['ya']);
    }

    public function testAdjuntos(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'crm');
        file_put_contents($f, 'hola');
        $adj = $this->ficha()->guardarAdjunto($this->ana(), 3, $f, 'nota.txt');
        $this->assertSame('nota.txt', $adj[0]['nombre']);
        $this->assertStringStartsWith('archivo.php?d=crm&f=crm_', $adj[0]['url']);
        $this->assertSame(422, $this->estado(fn() => $this->ficha()->guardarAdjunto($this->ana(), 3, $f, 'malo.php')));
        $this->assertSame(403, $this->estado(fn() => $this->ficha()->borrarAdjunto($this->ana(), $adj[0]['id'])));
        $this->assertSame([], $this->ficha()->borrarAdjunto($this->duena(), $adj[0]['id']));
        @unlink($f);
    }
}
