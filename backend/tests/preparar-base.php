<?php
/* Prepara la base de pruebas en un proceso PHP limpio (lo llaman los tests de
   integración). Hace falta un proceso nuevo porque las funciones *_ensure()
   antiguas recuerdan en variables estáticas que ya corrieron: una segunda
   migración en el mismo proceso no crearía sus tablas.

     php tests/preparar-base.php nueva|existente   */

require __DIR__ . '/bootstrap.php';

use Croilab\Database\Migrador;

$escenario = $argv[1] ?? 'nueva';
$pdo = db();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

if ($escenario === 'existente') {
    /* Tablas como las dejaba el ERP antiguo: menos columnas y con datos. */
    $pdo->exec('CREATE TABLE admins (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(80), password_hash VARCHAR(255))');
    $pdo->exec("INSERT INTO admins (username, password_hash) VALUES ('antigua', 'x'), ('segunda', 'y')");
    $pdo->exec('CREATE TABLE clients (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120), username VARCHAR(80), conversiones TINYINT DEFAULT 1)');
    $pdo->exec("INSERT INTO clients (name, username) VALUES ('Cliente viejo', 'cv')");
    $pdo->exec('CREATE TABLE task_lists (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, nombre VARCHAR(120), orden INT DEFAULT 0)');
    $pdo->exec("INSERT INTO task_lists (client_id, nombre) VALUES (1, 'Tareas')");
    $pdo->exec("CREATE TABLE tasks (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, list_id INT, titulo VARCHAR(255),
                descripcion MEDIUMTEXT, estado VARCHAR(30) DEFAULT 'pendiente', responsable_id INT NULL, prioridad TINYINT DEFAULT 0,
                due_date DATE NULL, visible_cliente TINYINT DEFAULT 0, titulo_cliente VARCHAR(255), explicacion_cliente MEDIUMTEXT,
                mes VARCHAR(40), orden INT DEFAULT 0)");
    $pdo->exec("INSERT INTO tasks (client_id, list_id, titulo, responsable_id) VALUES (1, 1, 'Tarea vieja', 1)");
    /* La tabla vacía con el nombre equivocado que dejaba ensure_schema(). */
    $pdo->exec('CREATE TABLE task_assigned (task_id INT, admin_id INT)');
}

try {
    echo json_encode(['aplicadas' => Migrador::migrar($pdo)]);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
