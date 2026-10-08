<?php
namespace Croilab\Tests\Integracion\Equipo;

use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\AjustesServicio;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Modulos\Equipo\PerfilServicio;
use Croilab\Modulos\Equipo\RolesServicio;
use Croilab\Modulos\Equipo\Subidas;
use Croilab\Seguridad\Acceso;
use Croilab\Tests\Integracion\BaseDatosTestCase;

/* Perfil y cuenta propia, matriz de roles y ajustes de la agencia/portal. */
class PerfilRolesAjustesTest extends BaseDatosTestCase
{
    private const DUENA = ['admin.total'];
    private const ANA = ['ver.tareas', 'general.editar'];
    private const ROLES = ['ver.ajustes', 'roles.gestionar'];
    private static string $uploads;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('nueva');
        roles_todos(true);
        $h = password_hash('Prueba-123', PASSWORD_DEFAULT);
        self::pdo()->prepare("INSERT INTO admins (id, username, password_hash, email, role) VALUES (1,'duena',?,'duena@ejemplo.com','owner'), (2,'ana',?,NULL,'editor'), (3,'beto',?,NULL,'viewer')")->execute([$h, $h, $h]);
        self::pdo()->exec("INSERT INTO clients (id, name, activo) VALUES (1,'Alfa',1), (2,'Beta',1)");
        self::pdo()->exec("INSERT INTO task_lists (id, client_id, nombre) VALUES (1,1,'Tareas'), (2,2,'Tareas')");
        self::pdo()->exec("INSERT INTO tasks (id, client_id, list_id, titulo, responsable_id, estado) VALUES (1,1,1,'Responsable ana',2,'pendiente'), (2,2,2,'Asignada a ana',1,'en proceso'), (3,1,1,'Hecha',2,'completada')");
        self::pdo()->exec('INSERT INTO task_assignees (task_id, admin_id) VALUES (2, 2)');
        self::$uploads = sys_get_temp_dir() . '/croilab-uploads-test-' . bin2hex(random_bytes(4));
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$uploads . '/*/*') ?: [] as $f) unlink($f);
        foreach (glob(self::$uploads . '/*') ?: [] as $d) @rmdir($d);
        @rmdir(self::$uploads);
    }

    private function acc(int $id, array $p): Acceso
    {
        return new Acceso(self::pdo(), $id, $p);
    }

    private function perfil(): PerfilServicio
    {
        return new PerfilServicio(self::pdo(), new EquipoRepositorio(self::pdo()), new Subidas(self::$uploads));
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

    private function png(): string
    {
        $f = tempnam(sys_get_temp_dir(), 'img');
        $im = imagecreatetruecolor(20, 20);
        imagepng($im, $f);
        imagedestroy($im);
        return $f;
    }

    /* ---------- Perfil ---------- */

    public function testPerfilConTareasAsignadasYRolReal(): void
    {
        $p = $this->perfil()->ver($this->acc(1, self::DUENA), 2)['perfil'];
        $this->assertSame('Editor', $p['role_nombre']);
        /* En proceso antes que pendiente, como el antiguo; cuenta también task_assignees. */
        $this->assertSame(['Asignada a ana', 'Responsable ana'], array_column($p['tareas']['items'], 'titulo'));
        $this->assertFalse($p['yo']);
        $this->assertSame('offline', $p['presencia']['estado']);
    }

    public function testSoloUnoMismoOQuienGestionaEditaElPerfil(): void
    {
        $s = $this->perfil();
        $this->falla(fn() => $s->actualizar($this->acc(3, ['ver.tareas']), 2, ['cargo' => 'x']), 403);
        $r = $s->actualizar($this->acc(2, self::ANA), 2, ['cargo' => 'Especialista SEO', 'web' => 'ana.dev', 'skills' => 'SEO, Ads, SEO, ', 'cumple' => '1990-05-04', 'bio' => "Hola\nqué tal"]);
        $this->assertSame('https://ana.dev', $r['perfil']['web']);
        $this->assertSame(['SEO', 'Ads'], $r['perfil']['skills']);
        $this->assertSame('1990-05-04', $r['perfil']['cumple']);
        $this->falla(fn() => $s->actualizar($this->acc(2, self::ANA), 2, ['cumple' => '1990-02-31']), 422, 'cumple');
        $this->falla(fn() => $s->actualizar($this->acc(2, self::ANA), 2, ['cargo' => str_repeat('x', 121)]), 422, 'cargo');
        /* Un gestor sin acceso total no edita a la dueña. */
        $this->falla(fn() => $s->actualizar($this->acc(2, ['equipo.gestionar']), 1, ['cargo' => 'x']), 403);
    }

    public function testFotoValidaElTipoRealYBorraLaAnterior(): void
    {
        $s = $this->perfil();
        $ana = $this->acc(2, self::ANA);
        $falsa = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($falsa, '<?php echo 1; ?>');
        $this->falla(fn() => $s->subirFotoDesdeRuta($ana, 2, $falsa), 422, 'archivo');
        $r1 = $s->subirFotoDesdeRuta($ana, 2, $this->png());
        $this->assertMatchesRegularExpression('#^archivo\.php\?d=avatars&f=a2_[0-9a-f]{16}\.png$#', $r1['foto']);
        $primera = basename(urldecode(substr($r1['foto'], strpos($r1['foto'], 'f=') + 2)));
        $this->assertFileExists(self::$uploads . '/avatars/' . $primera);
        $s->subirFotoDesdeRuta($ana, 2, $this->png());
        $this->assertFileDoesNotExist(self::$uploads . '/avatars/' . $primera);
        $this->assertSame(['foto' => null], $s->quitarFoto($ana, 2));
        $this->assertSame([], glob(self::$uploads . '/avatars/*') ?: []);
    }

    public function testCambiarMiContrasenaYMiCuenta(): void
    {
        $s = $this->perfil();
        $beto = $this->acc(3, ['ver.tareas']);
        $this->falla(fn() => $s->cambiarPassword($beto, ['actual' => 'mala', 'nueva' => 'Nueva-123']), 422, 'actual');
        $this->falla(fn() => $s->cambiarPassword($beto, ['actual' => 'Prueba-123', 'nueva' => 'Prueba-123']), 422, 'nueva');
        $antes = (int)self::pdo()->query('SELECT cred_ver FROM admins WHERE id = 3')->fetchColumn();
        $s->cambiarPassword($beto, ['actual' => 'Prueba-123', 'nueva' => 'Nueva-123']);
        $this->assertSame($antes + 1, (int)self::pdo()->query('SELECT cred_ver FROM admins WHERE id = 3')->fetchColumn());
        $this->falla(fn() => $s->actualizarCuenta($beto, ['username' => 'ana']), 422, 'username');
        $this->falla(fn() => $s->actualizarCuenta($beto, ['username' => '']), 422, 'username');
        $this->assertSame('beto2', $s->actualizarCuenta($beto, ['username' => 'beto2', 'email' => 'B@x.com'])['cuenta']['username']);
        $this->assertSame(['silenciar' => ['chat']], $s->guardarAvisos($beto, ['silenciar' => ['chat', 'otra']]));
        $this->assertSame(['silenciar' => ['chat']], $s->avisos($beto));
    }

    /* ---------- Roles ---------- */

    public function testMatrizDeRoles(): void
    {
        $s = new RolesServicio();
        $gestor = $this->acc(2, self::ROLES);
        $this->falla(fn() => $s->listar($this->acc(2, self::ANA)), 403);
        $l = $s->listar($gestor);
        $this->assertSame(['owner', 'editor', 'viewer'], array_column($l['roles'], 'clave'));
        $this->assertSame(1, $l['roles'][0]['uso']);

        $clave = $s->crear($gestor, ['nombre' => 'Comercial'])['clave'];
        $this->assertSame('comercial', $clave);
        $this->falla(fn() => $s->crear($gestor, ['nombre' => 'comercial']), 422);
        /* Encender un permiso trae sus requisitos; apagar el requisito se lleva lo que depende de él. */
        $r = $s->permiso($gestor, ['rol' => $clave, 'perm' => 'crm.crear', 'on' => true]);
        $this->assertEqualsCanonicalizing(['crm.crear', 'ver.crm', 'general.editar'], $r['permisos']);
        $r = $s->permiso($gestor, ['rol' => $clave, 'perm' => 'ver.crm', 'on' => false]);
        $this->assertSame(['general.editar'], $r['permisos']);
        $r = $s->grupo($gestor, ['rol' => $clave, 'grupo' => 'Clientes', 'on' => true]);
        $this->assertContains('clientes.borrar', $r['permisos']);
        $this->assertContains('ver.clientes', $r['permisos']);

        /* El acceso total es cosa de quien lo tiene, y el Dueño no lo pierde. */
        $this->falla(fn() => $s->permiso($gestor, ['rol' => $clave, 'perm' => 'admin.total', 'on' => true]), 403);
        $this->falla(fn() => $s->permiso($this->acc(1, self::DUENA), ['rol' => 'owner', 'perm' => 'admin.total', 'on' => false]), 409);
        $this->falla(fn() => $s->permiso($gestor, ['rol' => $clave, 'perm' => 'no.existe', 'on' => true]), 422);

        $s->renombrar($gestor, ['rol' => $clave, 'nombre' => 'Ventas']);
        $this->assertSame('Ventas', roles_todos()[$clave]['nombre']);
        $this->falla(fn() => $s->borrar($gestor, 'editor'), 409);
        self::pdo()->exec("UPDATE admins SET role = '$clave' WHERE id = 3");
        roles_todos(true);
        $this->falla(fn() => $s->borrar($gestor, $clave), 409);
        self::pdo()->exec("UPDATE admins SET role = 'viewer' WHERE id = 3");
        $s->borrar($gestor, $clave);
        $this->assertArrayNotHasKey($clave, roles_todos());
    }

    /* ---------- Ajustes ---------- */

    public function testAgenciaContactoYVideos(): void
    {
        $s = new AjustesServicio(self::pdo(), new Subidas(self::$uploads));
        $duena = $this->acc(1, self::DUENA);
        $this->falla(fn() => $s->guardarAgencia($this->acc(2, ['ver.ajustes']), ['nombre' => 'X']), 403);
        $this->falla(fn() => $s->guardarAgencia($duena, ['nombre' => '']), 422, 'nombre');
        $this->falla(fn() => $s->guardarAgencia($duena, ['color' => 'rojo']), 422, 'color');
        $this->falla(fn() => $s->guardarAgencia($duena, ['logo' => 'javascript:alert(1)']), 422, 'logo');
        $a = $s->guardarAgencia($duena, ['nombre' => 'Mi Agencia', 'color' => '1F232A', 'web' => 'miagencia.com', 'email' => 'Hola@MiAgencia.com'])['agencia'];
        $this->assertSame(['Mi Agencia', '#1f232a', 'https://miagencia.com', 'hola@miagencia.com'], [$a['nombre'], $a['color'], $a['web'], $a['email']]);
        $this->assertSame(1, (int)self::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE accion = 'ajuste.agency_name'")->fetchColumn());

        $logo = $s->subirLogoDesdeRuta($duena, $this->png())['agencia']['logo'];
        $this->assertMatchesRegularExpression('#/uploads/marca/logo_[0-9a-f]{16}\.png$#', $logo);
        $this->assertCount(1, glob(self::$uploads . '/marca/*') ?: []);
        $s->quitarLogo($duena);
        $this->assertSame([], glob(self::$uploads . '/marca/*') ?: []);

        $c = $s->guardarContacto($duena, ['whatsapp' => '+34 600 00 00 00', 'meeting_url' => 'https://calendar.app.google/abc', 'email' => ''])['contacto'];
        $this->assertSame('34600000000', $c['whatsapp']);
        $this->falla(fn() => $s->guardarContacto($duena, ['whatsapp' => '12']), 422, 'whatsapp');

        $v = $s->guardarVideos($duena, ['video_id' => 'https://youtu.be/J9-aEZ523bA', 'servicios' => [['nombre' => 'SEO', 'video' => 'https://www.youtube.com/watch?v=abcdefghijk']]]);
        $this->assertSame('J9-aEZ523bA', $v['video_id']);
        $seo = array_values(array_filter($v['servicios'], fn($x) => $x['nombre'] === 'SEO'))[0];
        $this->assertSame('abcdefghijk', $seo['video']);
        $this->assertCount(6, $v['servicios'], 'No se pierde ningún servicio del catálogo');
        $this->falla(fn() => $s->guardarVideos($duena, ['video_id' => '<script>']), 422, 'video_id');

        $reglas = $s->regla($duena, ['clave' => 'daily_digest', 'on' => false])['reglas'];
        $this->assertFalse(array_values(array_filter($reglas, fn($r) => $r['clave'] === 'daily_digest'))[0]['on']);
        $this->falla(fn() => $s->regla($duena, ['clave' => 'inventada', 'on' => true]), 422);
    }
}
