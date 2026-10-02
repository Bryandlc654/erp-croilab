<?php
/* Sesiones en la base de datos (SESSION_DRIVER=db). Con ellas el backend no
   guarda estado en el disco del servidor y puede correr en varias máquinas
   detrás de un balanceador. */

return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
        id VARCHAR(128) NOT NULL PRIMARY KEY,
        datos MEDIUMBLOB NOT NULL,
        actualizada INT UNSIGNED NOT NULL,
        INDEX ix_sessions_actualizada (actualizada)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
