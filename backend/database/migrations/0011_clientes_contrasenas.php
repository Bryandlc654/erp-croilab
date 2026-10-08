<?php
/* Contraseñas del portal guardadas en claro.

   El ERP antiguo siempre guardaba el hash (password_hash), pero las cuentas que
   se dieron de alta a mano o con el instalador que no está en el repositorio
   pueden tener en `clients.password_hash` el texto tal cual. Esas se cifran
   aquí con password_hash(): el cliente sigue entrando con la misma contraseña
   y en la base ya no queda legible. Lo que ya es un hash no se toca. */

return function (PDO $pdo): void {
    $sel = $pdo->query("SELECT id, password_hash FROM clients WHERE password_hash IS NOT NULL AND password_hash <> ''");
    $upd = $pdo->prepare('UPDATE clients SET password_hash = ?, cred_ver = cred_ver + 1 WHERE id = ?');
    $n = 0;
    foreach ($sel->fetchAll() as $r) {
        $v = (string)$r['password_hash'];
        if (password_get_info($v)['algo'] !== null && password_get_info($v)['algo'] !== 0) continue;   // ya es un hash
        $upd->execute([password_hash($v, PASSWORD_DEFAULT), (int)$r['id']]);
        $n++;
    }
    if ($n) error_log("0011: $n contraseña(s) de cliente en claro pasadas a hash.");
};
