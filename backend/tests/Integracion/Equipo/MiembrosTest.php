<?php
namespace Croilab\Tests\Integracion\Equipo;

use Croilab\Correo\CorreoArchivo;
use Croilab\Http\Diferidas;
use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Modulos\Equipo\MiembrosServicio;
use Croilab\Seguridad\Acceso;
use Croilab\Tests\Integracion\BaseDatosTestCase;

/* «Mi equipo» de punta a punta: alta, rol, contraseña, baja y permisos.
   Personas: 1 duena (owner), 2 gestor (rol propio con equipo.gestionar, sin
   acceso total), 3 ana (editor). */
class MiembrosTest extends BaseDatosTestCase
{
    private const DUENA = ['admin.total'];
    private const GESTOR = ['ver.ajustes', 'equipo.gestionar', 'general.editar'];
    private const ANA = ['ver.tareas', 'general.editar'];
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('nueva');
        roles_todos(true);
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO roles (clave, nombre, descripcion, permisos, sistema, orden) VALUES ('gestor', 'Gestor', '', '[\"ver.ajustes\",\"equipo.gestionar\",\"general.editar\"]', 0, 20)");
        roles_todos(true);
        $h = password_hash('Prueba-123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO admins (id, username, password_hash, email, role) VALUES (1,'duena',?,'duena@ejemplo.com','owner'), (2,'gestor',?,NULL,'gestor'), (3,'ana',?,'ana@ejemplo.com','editor')")->execute([$h, $h, $h]);
        $pdo->exec("INSERT INTO clients (id, name, activo) VALUES (1, 'Alfa', 1)");
        $pdo->exec("INSERT INTO task_lists (id, client_id, nombre) VALUES (1, 1, 'Tareas')");
        $pdo->exec("INSERT INTO tasks (id, client_id, list_id, titulo, responsable_id, estado) VALUES (1,1,1,'Abierta de ana',3,'pendiente'), (2,1,1,'Hecha de ana',3,'completada')");
        self::$dir = sys_get_temp_dir() . '/croilab-equipo-test-' . bin2hex(random_bytes(4));
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$dir . '/*.eml') ?: [] as $f) unlink($f);
        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        Diferidas::descartar();
    }

    private function acc(int $id, array $p): Acceso
    {
        return new Acceso(self::pdo(), $id, $p);
    }

    private function servicio(): MiembrosServicio
    {
        return new MiembrosServicio(self::pdo(), new EquipoRepositorio(self::pdo()), fn() => new CorreoArchivo(self::$dir), 'https://app.ejemplo.com', 'Croilab');
    }

    private function falla(callable $f, int $status, string $campo = ''): HttpError
    {
        try {
            $f();
        } catch (HttpError $e) {
            $this->assertSame($status, $e->status, $e->getMessage());
            if ($campo !== '') $this->assertSame($campo, $e->extra['campo'] ?? '', $e->getMessage());
            return $e;
        }
        $this->fail("Se esperaba un error $status");
    }

    private function credVer(int $id): int
    {
        return (int)self::pdo()->query("SELECT cred_ver FROM admins WHERE id = $id")->fetchColumn();
    }

    public function testSinPermisoNoSeVeNiSeToca(): void
    {
        $ana = $this->acc(3, self::ANA);
        $this->falla(fn() => $this->servicio()->listar($ana), 403);
        $this->falla(fn() => $this->servicio()->crear($ana, ['username' => 'x', 'password' => 'Secreta-1']), 403);
        $this->falla(fn() => $this->servicio()->baja($ana, 2), 403);
    }

    public function testAltaValidaYNoRepite(): void
    {
        $s = $this->servicio();
        $duena = $this->acc(1, self::DUENA);
        $this->falla(fn() => $s->crear($duena, ['username' => '', 'password' => 'Secreta-1']), 422, 'username');
        $this->falla(fn() => $s->crear($duena, ['username' => 'pepe']), 422, 'password');
        $this->falla(fn() => $s->crear($duena, ['username' => 'pepe', 'password' => '123']), 422, 'password');
        $this->falla(fn() => $s->crear($duena, ['username' => 'pepe', 'email' => 'no-es-correo', 'password' => 'Secreta-1']), 422, 'email');
        $this->falla(fn() => $s->crear($duena, ['username' => 'ana', 'password' => 'Secreta-1']), 422, 'username');
        $this->falla(fn() => $s->crear($duena, ['username' => 'pepe', 'email' => 'ANA@ejemplo.com', 'password' => 'Secreta-1']), 422, 'email');
        $this->falla(fn() => $s->crear($duena, ['username' => 'pepe', 'role' => 'nope', 'password' => 'Secreta-1']), 422, 'role');

        $r = $s->crear($duena, ['username' => 'pepe', 'email' => 'Pepe@Ejemplo.com', 'role' => 'viewer', 'password' => 'Secreta-1']);
        $this->assertSame('pepe@ejemplo.com', $r['miembro']['email']);
        $this->assertSame('Solo lectura', $r['miembro']['role_nombre']);
        $hash = self::pdo()->query('SELECT password_hash FROM admins WHERE id = ' . $r['miembro']['id'])->fetchColumn();
        $this->assertTrue(password_verify('Secreta-1', (string)$hash));
    }

    public function testAltaConEnlaceMandaCorreoYNoDejaLaCuentaAbierta(): void
    {
        foreach (glob(self::$dir . '/*.eml') ?: [] as $f) unlink($f);
        $r = $this->servicio()->crear($this->acc(1, self::DUENA), ['username' => 'luis', 'email' => 'luis@ejemplo.com', 'role' => 'editor', 'enviar_enlace' => true, 'enviar_correo' => true]);
        $this->assertMatchesRegularExpression('#^https://app\.ejemplo\.com/restablecer\?token=[0-9a-f]{64}$#', $r['enlace']['url']);
        $this->assertNotNull($r['miembro']['enlace_password']);
        Diferidas::ejecutar();
        $this->assertCount(1, glob(self::$dir . '/*.eml') ?: []);
        /* Del token solo se guarda el hash. */
        $token = substr($r['enlace']['url'], -64);
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM password_resets WHERE token_hash = ? AND expira_en > NOW() + INTERVAL 47 HOUR');
        $st->execute([hash('sha256', $token)]);
        $this->assertSame(1, (int)$st->fetchColumn());
    }

    public function testSoloQuienTieneAccesoTotalDaAccesoTotal(): void
    {
        $gestor = $this->acc(2, self::GESTOR);
        $e = $this->falla(fn() => $this->servicio()->crear($gestor, ['username' => 'jefa2', 'role' => 'owner', 'password' => 'Secreta-1']), 403);
        $this->assertStringContainsString('acceso total', $e->getMessage());
        $this->falla(fn() => $this->servicio()->cambiarRol($gestor, 3, 'owner'), 403);
        /* Ni tocar a la dueña (su contraseña, sus datos). */
        $this->falla(fn() => $this->servicio()->password($gestor, 1, ['modo' => 'generar']), 403);
        /* Pero sí gestionar a los demás. */
        $r = $this->servicio()->cambiarRol($gestor, 3, 'viewer');
        $this->assertSame('viewer', $r['miembro']['role']);
        $this->servicio()->cambiarRol($gestor, 3, 'editor');
    }

    public function testNoSeQuedaSinDuenoNiPorRolNiPorBaja(): void
    {
        $duena = $this->acc(1, self::DUENA);
        $e = $this->falla(fn() => $this->servicio()->cambiarRol($duena, 1, 'editor'), 409);
        $this->assertStringContainsString('única persona con acceso total', $e->getMessage());
        $this->falla(fn() => $this->servicio()->baja($duena, 1), 409);   // uno mismo
        /* Un dueño dado de baja no cuenta: sigue siendo la única activa. */
        self::pdo()->exec("INSERT INTO admins (id, username, password_hash, role, activo) VALUES (50, 'exdueno', 'x', 'owner', 0)");
        $this->falla(fn() => $this->servicio()->cambiarRol($duena, 1, 'editor'), 409);
    }

    public function testCambiarRolCierraSusSesiones(): void
    {
        $antes = $this->credVer(3);
        $r = $this->servicio()->cambiarRol($this->acc(1, self::DUENA), 3, 'viewer');
        $this->assertFalse($r['recargar']);
        $this->assertSame($antes + 1, $this->credVer(3));
        $this->servicio()->cambiarRol($this->acc(1, self::DUENA), 3, 'editor');
    }

    public function testContrasenaFijarYGenerarSubenCredVerYAnulanEnlaces(): void
    {
        $s = $this->servicio();
        $duena = $this->acc(1, self::DUENA);
        $s->password($duena, 3, ['modo' => 'enlace']);
        $antes = $this->credVer(3);
        $this->falla(fn() => $s->password($duena, 3, ['modo' => 'fijar', 'password' => '12']), 422, 'password');
        $s->password($duena, 3, ['modo' => 'fijar', 'password' => 'Nueva-Clave-9']);
        $this->assertSame($antes + 1, $this->credVer(3));
        $this->assertSame(0, (int)self::pdo()->query('SELECT COUNT(*) FROM password_resets WHERE admin_id = 3 AND usado_en IS NULL')->fetchColumn());
        $g = $s->password($duena, 3, ['modo' => 'generar']);
        $this->assertSame(14, strlen($g['password']));
        $hash = self::pdo()->query('SELECT password_hash FROM admins WHERE id = 3')->fetchColumn();
        $this->assertTrue(password_verify($g['password'], (string)$hash));
        $this->assertSame($antes + 2, $this->credVer(3));
    }

    public function testEnlacePorCorreoExigeCorreo(): void
    {
        $this->falla(fn() => $this->servicio()->password($this->acc(1, self::DUENA), 2, ['modo' => 'enlace', 'enviar_correo' => true]), 422);
    }

    public function testFacturacionGuardaDecimalesSinFloat(): void
    {
        $r = $this->servicio()->facturacion($this->acc(1, self::DUENA), 3, ['es_autonomo' => true, 'tarifa_hora' => '25,5', 'iva_pct' => '21', 'irpf_pct' => '15']);
        $this->assertTrue($r['miembro']['es_autonomo']);
        $this->assertSame('25.50', $r['miembro']['tarifa_hora']);
        $this->falla(fn() => $this->servicio()->facturacion($this->acc(1, self::DUENA), 3, ['iva_pct' => '150']), 422, 'iva_pct');
        $this->falla(fn() => $this->servicio()->facturacion($this->acc(1, self::DUENA), 3, ['tarifa_hora' => 'mucho']), 422, 'tarifa_hora');
    }

    public function testBajaEsLogicaYLiberaSoloLoAbierto(): void
    {
        $s = $this->servicio();
        $duena = $this->acc(1, self::DUENA);
        self::pdo()->exec("INSERT INTO settings (clave, valor) VALUES ('gcal_tok_3', 'bx1:algo')");
        $antes = $this->credVer(3);
        $s->baja($duena, 3);
        $fila = self::pdo()->query('SELECT activo, cred_ver FROM admins WHERE id = 3')->fetch();
        $this->assertSame(0, (int)$fila['activo']);
        $this->assertSame($antes + 1, (int)$fila['cred_ver']);
        $this->assertNull(self::pdo()->query('SELECT responsable_id FROM tasks WHERE id = 1')->fetchColumn());
        $this->assertSame(3, (int)self::pdo()->query('SELECT responsable_id FROM tasks WHERE id = 2')->fetchColumn(), 'Lo hecho conserva a su autor');
        $this->assertFalse(self::pdo()->query("SELECT 1 FROM settings WHERE clave = 'gcal_tok_3'")->fetchColumn());
        $this->assertSame([], array_filter($s->listar($duena)['items'], fn($m) => $m['id'] === 3));
        $this->assertCount(1, array_filter($s->listar($duena, true)['items'], fn($m) => $m['id'] === 3));
        $s->reactivar($duena, 3);
        $this->assertSame(1, (int)self::pdo()->query('SELECT activo FROM admins WHERE id = 3')->fetchColumn());
    }
}
