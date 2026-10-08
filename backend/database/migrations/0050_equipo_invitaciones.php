<?php
/* Invitaciones para darse de alta en el equipo (registro por enlace).

   Antes vivían en `settings` como `signup_<token>` = `<rol>|<caduca>`, con el
   token en claro: quien leyera una copia de la base podía darse de alta. Aquí
   el token se busca por su SHA-256 (como password_resets). Además se guarda
   cifrado con la bóveda para que quien gestiona el equipo pueda volver a
   copiar un enlace vivo: sin la clave de la bóveda, el cifrado no sirve de nada.

   Los enlaces vivos del sistema antiguo se pasan a la tabla (siguen valiendo
   hasta su caducidad) y se borran de `settings`. */

return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_invitations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL,
        token_cifrado TEXT NULL,
        rol VARCHAR(30) NOT NULL DEFAULT 'viewer',
        email VARCHAR(190) NULL DEFAULT NULL,
        creado_por INT NULL DEFAULT NULL,
        creado_en DATETIME NOT NULL,
        expira_en DATETIME NOT NULL,
        usado_en DATETIME NULL DEFAULT NULL,
        usado_por INT NULL DEFAULT NULL,
        UNIQUE KEY uq_ti_token (token_hash),
        KEY ix_ti_vivas (usado_en, expira_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ins = $pdo->prepare('INSERT IGNORE INTO team_invitations (token_hash, rol, creado_en, expira_en) VALUES (?, ?, NOW(), FROM_UNIXTIME(?))');
    foreach ($pdo->query("SELECT clave, valor FROM settings WHERE clave LIKE 'signup\_%'")->fetchAll() as $r) {
        $token = substr((string)$r['clave'], 7);
        [$rol, $caduca] = array_pad(explode('|', (string)$r['valor'], 2), 2, '0');
        $rol = preg_replace('/[^a-z0-9_\-]/', '', strtolower($rol)) ?: 'viewer';
        if ($token !== '' && (int)$caduca > time()) $ins->execute([hash('sha256', $token), substr($rol, 0, 30), (int)$caduca]);
    }
    $pdo->exec("DELETE FROM settings WHERE clave LIKE 'signup\_%'");
};
