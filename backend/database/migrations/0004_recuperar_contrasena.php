<?php
/* Recuperación de contraseña por correo.

   Del token solo se guarda su SHA-256: quien lea una copia de la base no puede
   usar los enlaces pendientes. El mecanismo anterior (admin/lib/pwreset.php)
   los guardaba en claro en `settings`, con clave `pwreset_<token>`: se borran. */

return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        creado_en DATETIME NOT NULL,
        expira_en DATETIME NOT NULL,
        usado_en DATETIME NULL DEFAULT NULL,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        UNIQUE KEY uq_pr_token (token_hash),
        KEY ix_pr_admin (admin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("DELETE FROM settings WHERE clave LIKE 'pwreset\\_%'");
};
