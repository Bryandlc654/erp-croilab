<?php
namespace Croilab\Tests\Integracion;

use Croilab\Correo\CorreoArchivo;
use Croilab\Http\Diferidas;
use Croilab\Http\HttpError;
use Croilab\Modulos\Auth\RecuperacionServicio;

/* «He olvidado mi contraseña», de punta a punta: el correo se deja como .eml
   en una carpeta temporal y de ahí se saca el enlace. */
class RecuperacionTest extends BaseDatosTestCase
{
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('nueva');
        $h = password_hash('Antigua-123', PASSWORD_DEFAULT);
        self::pdo()->prepare("INSERT INTO admins (id, username, password_hash, email, role, activo) VALUES
                              (1, 'ana', ?, 'ana@ejemplo.com', 'owner', 1), (2, 'sin_correo', ?, NULL, 'editor', 1),
                              (3, 'baja', ?, 'baja@ejemplo.com', 'editor', 0)")->execute([$h, $h, $h]);
        self::$dir = sys_get_temp_dir() . '/croilab-correo-test-' . bin2hex(random_bytes(4));
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$dir . '/*.eml') ?: [] as $f) unlink($f);
        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        foreach (glob(self::$dir . '/*.eml') ?: [] as $f) unlink($f);
        self::pdo()->exec('DELETE FROM password_resets');
        Diferidas::descartar();
    }

    private function servicio(): RecuperacionServicio
    {
        return new RecuperacionServicio(self::pdo(), new CorreoArchivo(self::$dir), 'https://app.ejemplo.com/admin', 'Croilab');
    }

    /** Pide el enlace, envía los correos pendientes y devuelve [correos, token]. */
    private function pedir(string $ident): array
    {
        foreach (glob(self::$dir . '/*.eml') ?: [] as $f) unlink($f);   // solo los correos de esta petición
        $this->servicio()->pedir($ident, '127.0.0.1');
        Diferidas::ejecutar();
        $correos = glob(self::$dir . '/*.eml') ?: [];
        $token = null;
        foreach ($correos as $f) {
            $eml = (string)file_get_contents($f);
            /* El cuerpo va en base64: se decodifica cada parte para buscar el enlace. */
            preg_match_all('/\r\n\r\n([A-Za-z0-9+\/=\r\n]+?)\r\n(?:--|$)/', $eml, $m);
            foreach ($m[1] as $b64) {
                if (preg_match('#/restablecer\?token=([0-9a-f]{64})#', (string)base64_decode(str_replace("\r\n", '', $b64)), $t)) $token = $t[1];
            }
        }
        return [$correos, $token];
    }

    public function testEnviaUnEnlaceAlCorreoDeLaCuenta(): void
    {
        [$correos, $token] = $this->pedir('ana');
        $this->assertCount(1, $correos);
        $this->assertStringContainsString('To: <ana@ejemplo.com>', (string)file_get_contents($correos[0]));
        $this->assertNotNull($token, 'El correo lleva el enlace con el token');
        $guardado = self::pdo()->query('SELECT token_hash FROM password_resets')->fetchColumn();
        $this->assertSame(hash('sha256', $token), $guardado, 'Solo se guarda el hash del token');
        $this->assertSame('ana', $this->servicio()->comprobar($token));
    }

    public function testTambienPorCorreoSinDistinguirMayusculas(): void
    {
        [, $token] = $this->pedir('ANA@ejemplo.com');
        $this->assertNotNull($token);
    }

    public function testNoDelataSiLaCuentaExiste(): void
    {
        foreach (['nadie', 'sin_correo', 'baja'] as $ident) {
            [$correos] = $this->pedir($ident);   // no lanza nada: misma respuesta que con una cuenta real
            $this->assertCount(0, $correos, "No se envía nada a «{$ident}»");
        }
        $this->assertSame(0, (int)self::pdo()->query('SELECT COUNT(*) FROM password_resets')->fetchColumn());
    }

    public function testCambiaLaContrasenaYCierraLasSesiones(): void
    {
        $verAntes = (int)self::pdo()->query('SELECT cred_ver FROM admins WHERE id = 1')->fetchColumn();
        [, $token] = $this->pedir('ana');
        $this->servicio()->restablecer($token, 'Nueva-clave-456');
        Diferidas::ejecutar();

        $a = self::pdo()->query('SELECT password_hash, cred_ver FROM admins WHERE id = 1')->fetch();
        $this->assertTrue(password_verify('Nueva-clave-456', $a['password_hash']));
        $this->assertSame($verAntes + 1, (int)$a['cred_ver'], 'Sube cred_ver: las sesiones abiertas caen');
        $avisos = glob(self::$dir . '/*.eml') ?: [];
        $this->assertCount(2, $avisos, 'Además del enlace, llega el aviso de que ha cambiado');

        $this->expectExceptionObject(HttpError::noEncontrado('El enlace no es válido o ha caducado. Pide uno nuevo.'));
        $this->servicio()->restablecer($token, 'Otra-clave-789');   // de un solo uso
    }

    public function testUnaContrasenaCortaNoGastaElEnlace(): void
    {
        [, $token] = $this->pedir('ana');
        try {
            $this->servicio()->restablecer($token, '123');
            $this->fail('Debía rechazarse');
        } catch (HttpError $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('password', $e->extra['campo']);
        }
        $this->assertSame('ana', $this->servicio()->comprobar($token), 'El enlace sigue valiendo');
    }

    public function testPedirOtroInvalidaElAnterior(): void
    {
        [, $primero] = $this->pedir('ana');
        [, $segundo] = $this->pedir('ana');
        $this->assertNotSame($primero, $segundo);
        $this->assertSame('ana', $this->servicio()->comprobar($segundo));
        $this->expectException(HttpError::class);
        $this->servicio()->comprobar($primero);
    }

    public function testElEnlaceCaduca(): void
    {
        [, $token] = $this->pedir('ana');
        self::pdo()->exec('UPDATE password_resets SET expira_en = NOW() - INTERVAL 1 MINUTE');
        $this->expectException(HttpError::class);
        $this->servicio()->comprobar($token);
    }

    public function testTokensInventados(): void
    {
        foreach (['', 'abc', str_repeat('0', 64), str_repeat('z', 64)] as $t) {
            try {
                $this->servicio()->comprobar($t);
                $this->fail("«{$t}» no debía valer");
            } catch (HttpError $e) {
                $this->assertSame(404, $e->status);
            }
        }
    }
}
