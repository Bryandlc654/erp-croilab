<?php
namespace Croilab\Modulos\Finanzas;

use PDO;

/* Transacción «todo o nada» que se puede anidar (usa db_tx_* de db.php, que
   también usa la papelera): si algo lanza, se deshace entero y la excepción
   sigue hacia arriba. */
final class Tx
{
    public static function run(PDO $pdo, callable $f): mixed
    {
        $anidable = function_exists('db_tx_begin');
        $anidable ? db_tx_begin($pdo) : $pdo->beginTransaction();
        try {
            $r = $f();
        } catch (\Throwable $e) {
            if ($anidable) db_tx_rollback($pdo);
            elseif ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $anidable ? db_tx_commit($pdo) : $pdo->commit();
        return $r;
    }
}
