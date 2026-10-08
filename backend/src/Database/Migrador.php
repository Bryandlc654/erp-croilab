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
    private static ?string $cacheFichero = null;

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
        self::$cacheFichero = null;
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

    /**
     * ¿Falta alguna migración? Cero consultas si nada ha cambiado desde la
     * última comprobación.
     *
     * Antes costaba un SELECT a schema_migrations en cada petición HTTP, y con un
     * MySQL a ~400 ms de latencia eran 400 ms de cada respuesta sólo para
     * confirmar que el esquema estaba al día. El recordatorio estático de la
     * clase no ayudaba: cada petición es un proceso nuevo.
     *
     * El resultado se guarda en un fichero de la carpeta temporal, con la huella
     * de los ficheros de migración (nombre, tamaño y mtime) y el nombre de la
     * base. Si al desplegar se sube una migración nueva, la huella cambia y se
     * vuelve a preguntar. Fichero ilegible o no escribible: se cae a consultar,
     * que es el comportamiento de siempre.
     *
     * Solo se recuerda «al día», nunca «faltan»: las migraciones se aplican
     * desde la consola (bin/migrate.php), que en Hostinger usa otra carpeta
     * temporal que la web y no puede corregir su caché. Si la web recordara
     * «faltan», seguiría respondiendo 503 después de migrar, hasta el siguiente
     * despliegue. Mientras falten, cada petición pregunta a la base: es solo
     * durante el mantenimiento.
     */
    public static function hayPendientes(PDO $pdo, string $dir = self::DIR): bool
    {
        /* Un directorio distinto es un caso de los tests: nunca se cachea (ni
           se mira la memoria del proceso, que es la del directorio real). */
        if ($dir !== self::DIR) {
            return (bool)array_diff(array_keys(self::archivos($dir)), self::aplicadas($pdo));
        }
        if (self::$pendientes !== null) return self::$pendientes;

        $base = self::nombreBase($pdo);
        $fichero = self::ficheroCache($base);
        $huella = $base === null ? null : self::huella($base, $dir);

        if ($huella !== null && is_readable($fichero)) {
            $guardado = @file_get_contents($fichero);
            if (is_string($guardado)) {
                $d = json_decode($guardado, true);
                if (is_array($d) && ($d['huella'] ?? null) === $huella && ($d['pendientes'] ?? null) === false) {
                    return self::$pendientes = false;
                }
            }
        }

        $p = (bool)array_diff(array_keys(self::archivos($dir)), self::aplicadas($pdo));
        self::$pendientes = $p;
        if ($huella !== null && !$p) self::guardarCache($fichero, $huella, false);
        return $p;
    }

    /**
     * Nombre de la base sin gastar una consulta: DB_NAME ya la tiene config.php.
     * Solo se pregunta a MySQL si faltara la constante.
     */
    private static function nombreBase(PDO $pdo): ?string
    {
        if (defined('DB_NAME') && DB_NAME !== '') return (string)DB_NAME;
        try { $b = (string)$pdo->query('SELECT DATABASE()')->fetchColumn(); } catch (\PDOException $e) { return null; }
        return $b !== '' ? $b : null;
    }

    /** Huella de las migraciones + base: si cambia cualquiera de las dos, el valor guardado ya no vale. */
    private static function huella(string $base, string $dir): string
    {
        $f = [];
        foreach (self::archivos($dir) as $v => $ruta) {
            clearstatcache(true, $ruta);
            $f[] = $v . ':' . basename($ruta) . ':' . @filemtime($ruta) . ':' . @filesize($ruta);
        }
        return hash('sha256', $base . '|' . implode('|', $f));
    }

    private static function ficheroCache(?string $base): string
    {
        if (self::$cacheFichero === null) {
            self::$cacheFichero = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
                . 'croilab-migraciones-' . hash('sha256', (string)$base) . '.json';
        }
        return self::$cacheFichero;
    }

    private static function guardarCache(string $fichero, string $huella, bool $pendientes): void
    {
        $tmp = $fichero . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode(['huella' => $huella, 'pendientes' => $pendientes]), LOCK_EX) !== false) {
            @rename($tmp, $fichero);   /* atómico: nunca se lee a medio escribir */
        } else {
            @unlink($tmp);
        }
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
        /* La caché de hayPendientes() acaba de quedarse vieja: hay que
           reescribirla o la API seguiría pensando que faltaban migraciones. */
        self::$cacheFichero = null;
        try {
            $base = self::nombreBase($pdo);
            if ($base !== null) self::guardarCache(self::ficheroCache($base), self::huella($base, self::DIR), false);
        } catch (\Throwable $e) { /* la caché es una optimización, no un requisito */ }
        return $nuevas;
    }
}
