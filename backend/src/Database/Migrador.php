<?php
namespace Croilab\Database;

use PDO;

/* Migraciones versionadas: cada fichero de database/migrations/NNNN_nombre.php
   devuelve una función (PDO $pdo): void. Se aplican en orden, una sola vez, y
   quedan anotadas en `schema_migrations`.

   Sustituye a la comprobación del esquema en cada petición: la API no crea ni
   altera tablas; si faltan migraciones responde 503 hasta que se ejecuta
   `php bin/migrate.php`. Las migraciones son idempotentes (IF NOT EXISTS,
   columnas que se añaden solo si faltan), así que valen tanto para una
   instalación nueva como para una que ya tenía las tablas del ERP antiguo. */
class Migrador
{
    public const DIR = __DIR__ . '/../../database/migrations';
    private static ?bool $pendientes = null;

    /** @return array<string,string> versión => ruta, en orden */
    public static function archivos(string $dir = self::DIR): array
    {
        $out = [];
        foreach (glob(rtrim($dir, '/\\') . '/*.php') ?: [] as $f) {
            if (preg_match('/^(\d{4})_[a-z0-9_]+\.php$/', basename($f), $m)) $out[$m[1]] = $f;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** Olvida lo recordado en esta petición (tests que cambian la base). */
    public static function olvidar(): void
    {
        self::$pendientes = null;
    }

    /** @return string[] versiones aplicadas */
    public static function aplicadas(PDO $pdo): array
    {
        try {
            return $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            return [];   // la tabla aún no existe: no se ha migrado nunca
        }
    }

    /** Una consulta por petición (se recuerda). */
    public static function hayPendientes(PDO $pdo, string $dir = self::DIR): bool
    {
        if (self::$pendientes === null || $dir !== self::DIR) {
            $p = (bool)array_diff(array_keys(self::archivos($dir)), self::aplicadas($pdo));
            if ($dir !== self::DIR) return $p;
            self::$pendientes = $p;
        }
        return self::$pendientes;
    }

    /**
     * Aplica las pendientes. $log recibe una línea por paso.
     * @return string[] versiones aplicadas en esta ejecución
     */
    public static function migrar(PDO $pdo, ?callable $log = null, string $dir = self::DIR): array
    {
        $log ??= fn(string $s) => null;
        if (!defined('CROILAB_MIGRANDO')) define('CROILAB_MIGRANDO', true);
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(4) NOT NULL PRIMARY KEY,
            nombre VARCHAR(120) NOT NULL,
            aplicada_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $hechas = self::aplicadas($pdo);
        $nuevas = [];
        foreach (self::archivos($dir) as $version => $ruta) {
            if (in_array($version, $hechas, true)) continue;
            $nombre = basename($ruta, '.php');
            $log("→ $nombre");
            $t0 = microtime(true);
            $fn = require $ruta;
            if (!is_callable($fn)) throw new \RuntimeException("$nombre no devuelve una función");
            /* MySQL confirma cada CREATE/ALTER por su cuenta, así que no hay
               transacción que valga: si una migración falla a medias, se arregla
               y se vuelve a lanzar (por eso son idempotentes). */
            $fn($pdo);
            $pdo->prepare('INSERT INTO schema_migrations (version, nombre) VALUES (?, ?)')->execute([$version, $nombre]);
            $log(sprintf('  hecha en %d ms', (microtime(true) - $t0) * 1000));
            $nuevas[] = $version;
        }
        self::$pendientes = false;
        return $nuevas;
    }
}
