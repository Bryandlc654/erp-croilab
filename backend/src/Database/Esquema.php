<?php
namespace Croilab\Database;

use PDO;

/* Ayudas para escribir migraciones idempotentes. */
final class Esquema
{
    public static function tablaExiste(PDO $pdo, string $tabla): bool
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $st->execute([$tabla]);
        return (int)$st->fetchColumn() > 0;
    }

    public static function columnaExiste(PDO $pdo, string $tabla, string $columna): bool
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$tabla, $columna]);
        return (int)$st->fetchColumn() > 0;
    }

    /** Añade las columnas que falten: [nombre => definición SQL]. */
    public static function columnas(PDO $pdo, string $tabla, array $columnas): void
    {
        foreach ($columnas as $col => $def) {
            if (!self::columnaExiste($pdo, $tabla, $col)) $pdo->exec("ALTER TABLE `$tabla` ADD COLUMN `$col` $def");
        }
    }

    /** Crea el índice si no existe uno con ese nombre. */
    public static function indice(PDO $pdo, string $tabla, string $nombre, array $columnas, bool $unico = false): void
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $st->execute([$tabla, $nombre]);
        if ((int)$st->fetchColumn() > 0) return;
        $pdo->exec("ALTER TABLE `$tabla` ADD " . ($unico ? 'UNIQUE KEY' : 'INDEX') . " `$nombre` (`" . implode('`,`', $columnas) . '`)');
    }

    /** Falla si falta alguna tabla: las *_ensure() antiguas se tragan sus errores. */
    public static function exigirTablas(PDO $pdo, array $tablas): void
    {
        $faltan = array_values(array_filter($tablas, fn($t) => !self::tablaExiste($pdo, $t)));
        if ($faltan) throw new \RuntimeException('No se han podido crear las tablas: ' . implode(', ', $faltan));
    }
}
