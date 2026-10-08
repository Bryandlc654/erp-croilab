<?php
/* Portal del cliente: «he olvidado mi contraseña».

   Como la del equipo (password_resets), solo se guarda el SHA-256 del token,
   que caduca y es de un solo uso. Tabla aparte porque la del equipo apunta a
   admins. (Los índices que lee el portal ya los ponen 0001 y 0010.) */

return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        creado_en DATETIME NOT NULL,
        expira_en DATETIME NOT NULL,
        usado_en DATETIME NULL DEFAULT NULL,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        UNIQUE KEY uq_ppr_token (token_hash),
        KEY ix_ppr_cliente (client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
