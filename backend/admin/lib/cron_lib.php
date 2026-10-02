<?php
/* Piezas del cron que también necesita la interfaz.

   Están aquí y no dentro de cron.php porque cron.php *ejecuta* el ciclo nada
   más incluirlo: si Automatizaciones lo requiriera para leer la última
   ejecución, abrir la página lanzaría el cron entero sin querer. Así el
   archivo de arriba se queda con el ciclo y aquí viven los datos. */

require_once __DIR__ . '/../../auth.php';

/* Días que se guarda el registro del cron antes de purgarlo (antes era un 60
   mágico repetido en la consulta — P3-07). */
if (!defined('CRON_LOG_DIAS')) define('CRON_LOG_DIAS', 60);

/* Registro de ejecuciones. Sin esto no hay forma de saber si el servidor está
   haciendo su trabajo o lleva tres semanas parado. */
function cron_ensure() { if (croilab_esquema_gestionado()) return;   /* el esquema lo crean las migraciones */
    static $done = false; if ($done) return; $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS cron_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tarea VARCHAR(60) NOT NULL,
            ok TINYINT DEFAULT 1,
            detalle VARCHAR(255) DEFAULT '',
            ms INT DEFAULT 0,
            origen VARCHAR(10) DEFAULT 'cli',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (tarea), INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}

function cron_apunta($tarea, $ok, $detalle, $ms, $origen = 'cli') {
    cron_ensure();
    try {
        db()->prepare('INSERT INTO cron_log (tarea,ok,detalle,ms,origen) VALUES (?,?,?,?,?)')
            ->execute([$tarea, $ok ? 1 : 0, mb_substr((string)$detalle, 0, 250), (int)$ms, $origen]);
    } catch (Exception $e) {}
    /* El log no puede crecer para siempre en una tabla que nadie mira. */
    try { db()->prepare('DELETE FROM cron_log WHERE created_at < (NOW() - INTERVAL ? DAY)')->execute([(int)CRON_LOG_DIAS]); } catch (Exception $e) {}
}

/* Última vez que se ejecutó el ciclo completo, para pintarlo en la interfaz. */
function cron_ultima() {
    cron_ensure();
    try { return db()->query("SELECT * FROM cron_log WHERE tarea='ciclo' ORDER BY id DESC LIMIT 1")->fetch() ?: null; }
    catch (Exception $e) { return null; }
}

/* Últimas ejecuciones por tarea, para la tabla de estado. */
function cron_por_tarea($limite = 12) {
    cron_ensure();
    try {
        $q = db()->query("SELECT c.* FROM cron_log c
                          JOIN (SELECT tarea, MAX(id) mid FROM cron_log WHERE tarea<>'ciclo' GROUP BY tarea) u
                            ON u.mid = c.id
                          ORDER BY c.created_at DESC LIMIT " . (int)$limite);
        return $q->fetchAll() ?: [];
    } catch (Exception $e) { return []; }
}

/* Clave secreta para poder llamarlo por URL. Se crea sola la primera vez. */
function cron_key($regenerar = false) {
    $k = '';
    try { $k = (string)get_setting('cron_key', ''); } catch (Exception $e) {}
    if ($k === '' || $regenerar) {
        $k = bin2hex(random_bytes(16));
        try {
            db()->prepare('INSERT INTO settings (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')
                ->execute(['cron_key', $k]);
        } catch (Exception $e) {}
    }
    return $k;
}

/* «hace 20 minutos», que es como lo lee una persona. */
function cron_hace($fecha) {
    $t = strtotime((string)$fecha); if (!$t) return '—';
    $s = time() - $t;
    if ($s < 60)    return 'hace menos de un minuto';
    if ($s < 3600)  return 'hace ' . (int)($s / 60) . ' min';
    if ($s < 86400) { $h = (int)($s / 3600); return 'hace ' . $h . ' hora' . ($h == 1 ? '' : 's'); }
    $d = (int)($s / 86400);
    return 'hace ' . $d . ' día' . ($d == 1 ? '' : 's');
}

/* La ruta absoluta del cron en el servidor, para poder copiar y pegar la línea
   del crontab sin tener que averiguarla a mano. */
function cron_ruta() { return realpath(__DIR__ . '/../cron.php') ?: (__DIR__ . '/../cron.php'); }

/* La URL pública con su clave. Se calcula desde la petición actual porque en
   local es localhost y en Hostinger es el dominio de verdad. */
function cron_url() {
    $esq  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir  = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php'))), '/');
    return $esq . '://' . $host . $dir . '/cron.php?key=' . cron_key();
}
