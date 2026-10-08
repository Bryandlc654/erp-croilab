<?php
namespace Croilab\Tests\Integracion\Equipo;

use Croilab\Correo\CorreoArchivo;
use Croilab\Http\Diferidas;
use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\BovedaServicio;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Modulos\Equipo\InvitacionesServicio;
use Croilab\Modulos\Equipo\Reautenticacion;
use Croilab\Seguridad\Acceso;
use Croilab\Tests\Integracion\BaseDatosTestCase;

/* Registro por enlace (invitaciones de un solo uso, con hash) y bóveda de
   credenciales de clientes (cifrado, revelado con contraseña, alcance). */
class InvitacionesYBovedaTest extends BaseDatosTestCase
{
    private const DUENA = ['admin.total'];
    /* Ana: ve la bóveda pero solo los clientes de sus tareas. */
    private const ANA = ['ver.credenciales', 'general.editar', 'ver.tareas'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('nueva');
        roles_todos(true);
        $pdo = self::pdo();
        $h = password_hash('Prueba-123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO admins (id, username, password_hash, email, role) VALUES (1,'duena',?,'duena@ejemplo.com','owner'), (2,'ana',?,NULL,'editor')")->execute([$h, $h]);
        $pdo->exec("INSERT INTO clients (id, name, activo) VALUES (1,'Alfa',1), (2,'Beta',1)");
        $pdo->exec("INSERT INTO task_lists (id, client_id, nombre) VALUES (1, 1, 'Tareas')");
        $pdo->exec("INSERT INTO tasks (id, client_id, list_id, titulo, responsable_id) VALUES (1, 1, 1, 'De ana', 2)");
    }

    protected function setUp(): void
    {
        Diferidas::descartar();
        $_SESSION = [];
    }

    private function acc(int $id, array $p): Acceso
    {
        return new Acceso(self::pdo(), $id, $p);
    }

    private function inv(): InvitacionesServicio
    {
        return new InvitacionesServicio(self::pdo(), new EquipoRepositorio(self::pdo()), fn() => new CorreoArchivo(sys_get_temp_dir() . '/croilab-inv-test'), 'https://app.ejemplo.com');
    }

    private function token(array $r): string
    {
        preg_match('/t=([0-9a-f]{64})$/', $r['invitacion']['url'], $m);
        return $m[1];
    }

    private function falla(callable $f, int $status): HttpError
    {
        try {
            $f();
        } catch (HttpError $e) {
            $this->assertSame($status, $e->status, $e->getMessage());
            return $e;
        }
        $this->fail("Se esperaba un error $status");
    }

    /* ---------- Invitaciones ---------- */

    public function testNoSeInvitaConAccesoTotalNiSinPermiso(): void
    {
        $this->falla(fn() => $this->inv()->crear($this->acc(1, self::DUENA), ['rol' => 'owner']), 422);
        $this->falla(fn() => $this->inv()->crear($this->acc(2, self::ANA), ['rol' => 'viewer']), 403);
        $roles = array_column(InvitacionesServicio::rolesInvitables(), 'clave');
        $this->assertNotContains('owner', $roles);
    }

    public function testRegistroDeUnSoloUsoConTokenConHash(): void
    {
        $r = $this->inv()->crear($this->acc(1, self::DUENA), ['rol' => 'viewer']);
        $t = $this->token($r);
        $fila = self::pdo()->query('SELECT token_hash, token_cifrado FROM team_invitations WHERE id = ' . $r['invitacion']['id'])->fetch();
        $this->assertSame(hash('sha256', $t), $fila['token_hash']);
        $this->assertStringNotContainsString($t, (string)$fila['token_cifrado'], 'El token no se guarda en claro');
        /* Se puede volver a copiar mientras vive. */
        $this->assertSame($r['invitacion']['url'], $this->inv()->enlace($this->acc(1, self::DUENA), $r['invitacion']['id'])['url']);

        $this->assertSame('Solo lectura', $this->inv()->comprobar($t)['rol_nombre']);
        $this->falla(fn() => $this->inv()->registrar(['token' => $t, 'username' => 'x', 'password' => 'Secreta-1']), 422);
        $this->falla(fn() => $this->inv()->registrar(['token' => $t, 'username' => 'ana', 'password' => 'Secreta-1']), 422);
        $this->falla(fn() => $this->inv()->registrar(['token' => $t, 'username' => 'nuevo', 'email' => 'DUENA@ejemplo.com', 'password' => 'Secreta-1']), 422);
        $this->assertSame(['username' => 'nuevo'], $this->inv()->registrar(['token' => $t, 'username' => 'nuevo', 'password' => 'Secreta-1']));
        $nuevo = self::pdo()->query("SELECT role, activo, password_hash FROM admins WHERE username = 'nuevo'")->fetch();
        $this->assertSame('viewer', $nuevo['role']);
        $this->assertTrue(password_verify('Secreta-1', $nuevo['password_hash']));
        /* Segundo uso: ya no vale. Y se avisa a la dueña. */
        $this->falla(fn() => $this->inv()->registrar(['token' => $t, 'username' => 'otro', 'password' => 'Secreta-1']), 404);
        $this->falla(fn() => $this->inv()->comprobar($t), 404);
        $this->assertSame(1, (int)self::pdo()->query("SELECT COUNT(*) FROM notifications WHERE admin_id = 1 AND ref LIKE 'registro:%'")->fetchColumn());
    }

    public function testCaducadaOAnuladaNoVale(): void
    {
        $r = $this->inv()->crear($this->acc(1, self::DUENA), ['rol' => 'editor']);
        $t = $this->token($r);
        self::pdo()->exec('UPDATE team_invitations SET expira_en = NOW() - INTERVAL 1 MINUTE WHERE id = ' . $r['invitacion']['id']);
        $this->falla(fn() => $this->inv()->comprobar($t), 404);
        $r2 = $this->inv()->crear($this->acc(1, self::DUENA), ['rol' => 'editor']);
        $this->inv()->anular($this->acc(1, self::DUENA), $r2['invitacion']['id']);
        $this->falla(fn() => $this->inv()->comprobar($this->token($r2)), 404);
        $this->falla(fn() => $this->inv()->comprobar('../../etc'), 404);
    }

    public function testLosEnlacesDelAntiguoSePasanALaTabla(): void
    {
        /* Lo que hacía la migración 0050 con settings.signup_<token>. */
        $viejo = bin2hex(random_bytes(24));
        self::pdo()->prepare("INSERT INTO settings (clave, valor) VALUES (?, ?)")->execute(['signup_' . $viejo, 'editor|' . (time() + 3600)]);
        (require __DIR__ . '/../../../database/migrations/0050_equipo_invitaciones.php')(self::pdo());
        $this->assertSame('Editor', $this->inv()->comprobar($viejo)['rol_nombre']);
        $this->assertSame(0, (int)self::pdo()->query("SELECT COUNT(*) FROM settings WHERE clave LIKE 'signup\\_%'")->fetchColumn());
    }

    /* ---------- Bóveda ---------- */

    public function testElSecretoSeGuardaCifradoYSoloSeRevelaConContrasena(): void
    {
        $b = new BovedaServicio(self::pdo());
        $duena = $this->acc(1, self::DUENA);
        $c = $b->crear($duena, 1, ['titulo' => 'WordPress', 'categoria' => 'cms', 'usuario' => 'admin', 'secreto' => 'S3creto!', 'url' => 'alfa.com/wp-admin'])['credencial'];
        $this->assertTrue($c['tiene_secreto']);
        $this->assertSame('https://alfa.com/wp-admin', $c['url']);
        $this->assertArrayNotHasKey('secreto', $c);
        $guardado = (string)self::pdo()->query('SELECT secreto FROM client_credentials WHERE id = ' . $c['id'])->fetchColumn();
        $this->assertStringStartsWith('bx1:', $guardado);
        $this->assertStringNotContainsString('S3creto', $guardado);

        $e = $this->falla(fn() => $b->revelar($duena, $c['id']), 403);
        $this->assertSame('reauth', $e->codigo);
        $this->falla(fn() => Reautenticacion::confirmar(['id' => 1, 'password_hash' => password_hash('Prueba-123', PASSWORD_DEFAULT)], 'mala', 'boveda'), 422);
        Reautenticacion::confirmar(['id' => 1, 'password_hash' => password_hash('Prueba-123', PASSWORD_DEFAULT)], 'Prueba-123', 'boveda');
        $this->assertSame('S3creto!', $b->revelar($duena, $c['id'])['secreto']);
        $this->assertSame(1, (int)self::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE accion = 'boveda.revelar'")->fetchColumn());

        /* Editar sin secreto no lo borra; con quitar_secreto, sí. */
        $b->actualizar($duena, $c['id'], ['titulo' => 'WP', 'secreto' => '']);
        $this->assertSame('S3creto!', $b->revelar($duena, $c['id'])['secreto']);
        $this->falla(fn() => $b->actualizar($duena, $c['id'], ['url' => 'javascript:alert(1)']), 422);
    }

    public function testAlcanceYPapelera(): void
    {
        $b = new BovedaServicio(self::pdo());
        $duena = $this->acc(1, self::DUENA);
        $ana = $this->acc(2, self::ANA);
        $beta = $b->crear($duena, 2, ['titulo' => 'Hosting de Beta', 'categoria' => 'hosting'])['credencial'];
        $this->assertSame([1], array_column($b->clientes($ana)['items'], 'id'), 'Ana solo ve Alfa');
        $this->falla(fn() => $b->listar($ana, 2), 404);
        $this->falla(fn() => $b->actualizar($ana, $beta['id'], ['titulo' => 'x']), 404);
        $this->falla(fn() => $b->listar($this->acc(2, ['ver.tareas']), 1), 403);

        $r = $b->borrar($duena, $beta['id']);
        $this->assertNotNull($r['papelera_id']);
        $this->assertFalse(self::pdo()->query('SELECT 1 FROM client_credentials WHERE id = ' . $beta['id'])->fetchColumn());
    }

    public function testLaMigracionCifraLosSecretosEnClaro(): void
    {
        self::pdo()->exec("INSERT INTO client_credentials (id, client_id, titulo, secreto) VALUES (900, 1, 'Viejo', 'enclaro-1')");
        self::pdo()->exec("INSERT INTO settings (clave, valor) VALUES ('google_oauth_refresh_token', '1//refresco-en-claro') ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        (require __DIR__ . '/../../../database/migrations/0051_equipo_cifrar_secretos.php')(self::pdo());
        $this->assertStringStartsWith('bx1:', (string)self::pdo()->query('SELECT secreto FROM client_credentials WHERE id = 900')->fetchColumn());
        $this->assertStringStartsWith('bx1:', (string)self::pdo()->query("SELECT valor FROM settings WHERE clave = 'google_oauth_refresh_token'")->fetchColumn());
        $this->assertSame('1//refresco-en-claro', boveda_valor('google_oauth_refresh_token', 'gmet'));
        $b = new BovedaServicio(self::pdo());
        $_SESSION['reauth']['boveda'] = time();
        $this->assertSame('enclaro-1', $b->revelar($this->acc(1, self::DUENA), 900)['secreto']);
        /* Repetir la migración no cambia nada. */
        $antes = self::pdo()->query('SELECT secreto FROM client_credentials WHERE id = 900')->fetchColumn();
        (require __DIR__ . '/../../../database/migrations/0051_equipo_cifrar_secretos.php')(self::pdo());
        $this->assertSame($antes, self::pdo()->query('SELECT secreto FROM client_credentials WHERE id = 900')->fetchColumn());
    }
}
