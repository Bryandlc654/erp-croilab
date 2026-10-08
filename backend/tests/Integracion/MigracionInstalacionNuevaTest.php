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

    /* Producción (Hostinger): se despliega una migración nueva, la web ve
       «faltan migraciones» y responde 503; después se migra desde la consola,
       que guarda su caché en OTRA carpeta temporal. La web no puede seguir
       creyendo que faltan: el 503 no se iría nunca. El despliegue se simula
       cambiando la fecha del fichero (cambia la huella) y la migración del
       otro proceso, anotando la versión a mano. */
    public function testTrasMigrarDesdeOtroProcesoLaApiDejaDeResponder503(): void
    {
        $pdo = self::pdo();
        $archivos = Migrador::archivos();
        $ultima = (string)array_key_last($archivos);
        $ruta = $archivos[$ultima];
        $mtime = filemtime($ruta);
        $fila = $pdo->query("SELECT version, nombre FROM schema_migrations WHERE version = '$ultima'")->fetch(\PDO::FETCH_ASSOC);
        $pdo->exec("DELETE FROM schema_migrations WHERE version = '$ultima'");
        try {
            touch($ruta, $mtime + 60);   // «se ha subido» la migración
            Migrador::olvidar();
            $this->assertTrue(Migrador::hayPendientes($pdo), 'recién desplegada, falta esa migración');
        } finally {
            $pdo->prepare('INSERT INTO schema_migrations (version, nombre) VALUES (?, ?)')->execute([$fila['version'], $fila['nombre']]);
        }
        try {
            Migrador::olvidar();   // la siguiente petición, en otro proceso
            $this->assertFalse(Migrador::hayPendientes($pdo), 'ya migrada desde la consola: la API vuelve a responder');
        } finally {
            touch($ruta, $mtime);
            clearstatcache(true, $ruta);
        }
    }
}
