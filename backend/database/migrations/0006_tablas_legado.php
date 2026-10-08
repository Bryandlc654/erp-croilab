<?php
/* Tablas y columnas que el ERP antiguo (copia-erp) creaba al vuelo desde cada
   pantalla —chat.php, support.php, reuniones.php, actas.php, facturas.php…— y
   que no estaban en las migraciones. Las definiciones son las mismas que allí
   (ver docs/migracion/*.md), para que una base que ya las tiene no cambie. */

use Croilab\Database\Esquema;

return function (PDO $pdo): void {
    $m = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    /* ---------- Trabajo ---------- */
    Esquema::columnas($pdo, 'tasks', ['descripcion_rich' => 'MEDIUMTEXT NULL']);
    Esquema::columnas($pdo, 'task_comments', ['reply_to' => 'INT NULL']);
    $pdo->exec("CREATE TABLE IF NOT EXISTS time_entries (
        id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, task_id INT NULL, client_id INT NULL,
        fecha DATE NOT NULL, minutos INT NOT NULL DEFAULT 0, importe DECIMAL(10,2) NULL, concepto VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(admin_id), INDEX(task_id), INDEX(fecha)
    ) $m");
    $pdo->exec("CREATE TABLE IF NOT EXISTS actas (
        id INT AUTO_INCREMENT PRIMARY KEY, titulo VARCHAR(220) NOT NULL DEFAULT '', contenido MEDIUMTEXT,
        admin_id INT DEFAULT NULL, pinned TINYINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (created_at), INDEX (admin_id)
    ) $m");
    Esquema::columnas($pdo, 'actas', ['pinned' => 'TINYINT NOT NULL DEFAULT 0']);

    /* ---------- Finanzas ---------- */
    $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
        id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(160) NOT NULL, color VARCHAR(16) DEFAULT '#2f6df6',
        client_id INT DEFAULT NULL, activo TINYINT DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) $m");
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_uploads (
        id INT AUTO_INCREMENT PRIMARY KEY, emisor VARCHAR(15) NOT NULL DEFAULT 'victor', tipo VARCHAR(10) NOT NULL DEFAULT 'gasto',
        concepto VARCHAR(250) DEFAULT '', proveedor VARCHAR(200) DEFAULT '', importe DECIMAL(12,2) DEFAULT 0, fecha DATE,
        filename VARCHAR(255) DEFAULT NULL, orig_name VARCHAR(255) DEFAULT NULL, mime VARCHAR(120) DEFAULT '', acc_id INT DEFAULT NULL,
        efectivo TINYINT NOT NULL DEFAULT 0, personal TINYINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(emisor), INDEX(tipo)
    ) $m");
    Esquema::columnas($pdo, 'invoice_uploads', ['efectivo' => 'TINYINT NOT NULL DEFAULT 0', 'personal' => 'TINYINT NOT NULL DEFAULT 0']);
    if (Esquema::tablaExiste($pdo, 'invoices')) {
        Esquema::columnas($pdo, 'invoices', [
            'emisor' => "VARCHAR(15) NOT NULL DEFAULT 'victor'", 'efectivo' => 'TINYINT NOT NULL DEFAULT 0',
            'personal' => 'TINYINT NOT NULL DEFAULT 0', 'periodo_ini' => 'DATE DEFAULT NULL', 'periodo_fin' => 'DATE DEFAULT NULL',
            'cliente_tel' => "VARCHAR(40) NOT NULL DEFAULT ''", 'cond_pago' => "VARCHAR(60) NOT NULL DEFAULT 'Contado'",
            'emisor_json' => 'TEXT', 'fecha_pago' => 'DATE DEFAULT NULL',
        ]);
    }
    if (Esquema::tablaExiste($pdo, 'accounting')) {
        Esquema::columnas($pdo, 'accounting', ['invoice_id' => 'INT DEFAULT NULL', 'personal' => 'TINYINT NOT NULL DEFAULT 0', 'project_id' => 'INT DEFAULT NULL']);
    }

    /* ---------- Comunicación ---------- */
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) DEFAULT '', type VARCHAR(10) NOT NULL DEFAULT 'group',
        created_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) $m");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_members (
        room_id INT NOT NULL, admin_id INT NOT NULL, last_read INT NOT NULL DEFAULT 0, PRIMARY KEY(room_id, admin_id), KEY(admin_id)
    ) $m");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY, room_id INT NOT NULL, admin_id INT NOT NULL, body TEXT,
        reply_to INT DEFAULT NULL, edited TINYINT NOT NULL DEFAULT 0, deleted TINYINT NOT NULL DEFAULT 0, attach TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(room_id)
    ) $m");
    Esquema::columnas($pdo, 'chat_messages', ['reply_to' => 'INT DEFAULT NULL', 'edited' => 'TINYINT NOT NULL DEFAULT 0',
                                               'deleted' => 'TINYINT NOT NULL DEFAULT 0', 'attach' => 'TEXT DEFAULT NULL']);
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_reactions (
        message_id INT NOT NULL, admin_id INT NOT NULL, emoji VARCHAR(16) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(message_id, admin_id, emoji)
    ) $m");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_typing (
        room_id INT NOT NULL, admin_id INT NOT NULL, until_ts INT NOT NULL DEFAULT 0, PRIMARY KEY(room_id, admin_id)
    ) $m");
    Esquema::columnas($pdo, 'chat_presence', ['last_active' => 'DATETIME DEFAULT NULL']);

    $pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY, asunto VARCHAR(200) NOT NULL, cuerpo TEXT, client_id INT DEFAULT NULL,
        prioridad INT DEFAULT 2, estado VARCHAR(20) DEFAULT 'abierto', assignee_id INT DEFAULT NULL, created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(client_id), INDEX(estado)
    ) $m");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_replies (
        id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, admin_id INT, cuerpo TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(ticket_id)
    ) $m");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reunion_cliente (
        event_id VARCHAR(255) PRIMARY KEY, contact_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) $m");
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_meeting_requests (
        id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, fecha_deseada DATE NULL, franja VARCHAR(30) DEFAULT '',
        motivo TEXT, estado VARCHAR(20) DEFAULT 'pendiente', meeting_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(client_id)
    ) $m");
    if (Esquema::tablaExiste($pdo, 'crm_meetings')) Esquema::columnas($pdo, 'crm_meetings', ['notas_doc' => 'TEXT']);
};
