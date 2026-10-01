<?php
/* Rastro de cambios en los ajustes del ERP.

   Lo que resuelve: varias pantallas de Ajustes escriben en la misma tabla
   `settings`, y hasta ahora no había forma de saber quién cambió qué ni
   cuándo. Para el contenido del portal eso importa: un identificador de vídeo
   o un enlace de contacto mal puesto sale a todos los clientes, y sin traza no
   hay manera de saber cuándo entró ni quién lo puso.

   Sigue el mismo patrón que cron_log y email_log: tabla creada al vuelo e
   idempotente, y un helper que nunca debe romper la pantalla que audita. Por
   eso el INSERT va envuelto en un try: si la tabla no se puede crear (permisos
   de MySQL, por ejemplo), el guardado del ajuste sigue adelante. */

function audit_log($accion, $detalle = '') {
    static $done = false;
    if (!$done) {
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS audit_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_id INT DEFAULT NULL,
                usuario VARCHAR(100) DEFAULT '',
                accion VARCHAR(60) NOT NULL,
                detalle VARCHAR(255) DEFAULT '',
                ip VARCHAR(45) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (accion), INDEX (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }
    try {
        $me = function_exists('current_admin') ? current_admin() : null;
        db()->prepare('INSERT INTO audit_log (admin_id,usuario,accion,detalle,ip) VALUES (?,?,?,?,?)')
            ->execute([
                $me['id'] ?? null,
                (string)($me['username'] ?? ''),
                (string)$accion,
                mb_substr((string)$detalle, 0, 255),
                mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ]);
    } catch (Exception $e) {}
}

/* Atajo para los ajustes: registra la clave, el valor anterior y el nuevo.
   Es lo que permite reconstruir qué se cambió y desde cuándo. */
function audit_setting($clave, $antes, $despues) {
    if ((string)$antes === (string)$despues) return;   /* no se registró ningún cambio */
    audit_log('ajuste.' . $clave, 'de "' . $antes . '" a "' . $despues . '"');
}
