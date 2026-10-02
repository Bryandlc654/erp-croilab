<?php
namespace Croilab\Tests\Integracion;

use Croilab\Database\Migrador;
use PDO;
use PHPUnit\Framework\TestCase;

/* Base de los tests contra MySQL/MariaDB. Cada clase prepara la base de
   pruebas desde cero con tests/preparar-base.php, en un proceso PHP aparte:
   las funciones *_ensure() antiguas recuerdan en variables estáticas que ya
   corrieron, y en este mismo proceso no volverían a crear sus tablas. */
abstract class BaseDatosTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!CROILAB_TEST_DB) self::markTestSkipped('Sin TEST_DB_*: se omiten los tests de integración.');
    }

    protected static function pdo(): PDO
    {
        return db();
    }

    /** Vacía la base, monta el escenario y migra. Devuelve las versiones aplicadas. */
    protected static function preparar(string $escenario = 'nueva'): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../preparar-base.php') . ' ' . escapeshellarg($escenario);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos);
        $salida = stream_get_contents($tubos[1]);
        $error = stream_get_contents($tubos[2]);
        if (proc_close($proc) !== 0) throw new \RuntimeException("No se pudo preparar la base ($escenario): $error");
        Migrador::olvidar();
        return json_decode($salida, true)['aplicadas'] ?? [];
    }

    /** Migra en este proceso (solo para comprobar que repetir no hace nada). */
    protected static function migrar(): array
    {
        Migrador::olvidar();
        return Migrador::migrar(self::pdo());
    }

    protected static function tablas(): array
    {
        return self::pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    }
}
