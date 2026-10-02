<?php
namespace Croilab\Tests\Integracion;

use Croilab\Database\Esquema;
use Croilab\Database\Migrador;

/* Una base vacía queda lista para usar solo con las migraciones. */
class MigracionInstalacionNuevaTest extends BaseDatosTestCase
{
    private static array $aplicadas = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$aplicadas = self::preparar('nueva');
    }

    public function testAplicaTodasEnOrden(): void
    {
        $this->assertSame(array_keys(Migrador::archivos()), self::$aplicadas);
        $this->assertFalse(Migrador::hayPendientes(self::pdo()));
    }

    public function testCreaLasTablasQueAntesNoCreabaNadie(): void
    {
        foreach (['admins', 'clients', 'settings', 'roles', 'tasks', 'task_lists', 'task_assignees', 'chk_assignees',
                  'trash', 'notifications', 'admin_profiles', 'login_attempts', 'sessions', 'schema_migrations'] as $t) {
            $this->assertContains($t, self::tablas(), "Falta la tabla $t");
        }
        $this->assertNotContains('task_assigned', self::tablas(), 'La tabla con el nombre equivocado no debe existir');
        $this->assertTrue(Esquema::columnaExiste(self::pdo(), 'task_lists', 'tipo'));
        $this->assertTrue(Esquema::columnaExiste(self::pdo(), 'admins', 'cred_ver'));
    }

    public function testSiembraLosRolesDelSistema(): void
    {
        $roles = self::pdo()->query('SELECT clave FROM roles ORDER BY clave')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['editor', 'owner', 'viewer'], $roles);
    }

    public function testRepetirNoHaceNada(): void
    {
        $this->assertSame([], self::migrar());
    }

    public function testDetectaMigracionesNuevas(): void
    {
        $dir = sys_get_temp_dir() . '/croilab_mig_' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach (Migrador::archivos() as $f) copy($f, $dir . '/' . basename($f));
        file_put_contents("$dir/9999_prueba.php", '<?php return function (PDO $pdo): void {};');
        try {
            $this->assertTrue(Migrador::hayPendientes(self::pdo(), $dir));
        } finally {
            array_map('unlink', glob("$dir/*.php"));
            rmdir($dir);
        }
    }
}
