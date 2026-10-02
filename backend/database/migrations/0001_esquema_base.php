<?php
/* Esquema base del ERP: núcleo (equipo, clientes, ajustes) y tareas.

   Hasta ahora estas tablas las creaba ensure_schema() en cada petición, y
   `admins` y `clients` no las creaba nadie: venían de un install.php que no
   estaba en el repositorio, así que una instalación limpia era imposible.
   Aquí está todo explícito. Sobre una base que ya existe no rompe nada: crea lo
   que falte y añade las columnas que no estén. */

use Croilab\Database\Esquema;

return function (PDO $pdo): void {
    $motor = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    /* ---------- Núcleo ---------- */
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (clave VARCHAR(60) PRIMARY KEY, valor TEXT) $motor");
    $ins = $pdo->prepare('INSERT IGNORE INTO settings (clave, valor) VALUES (?, ?)');
    foreach (['meeting_url' => '', 'video_id' => '', 'whatsapp' => '', 'email' => '', 'api_token' => bin2hex(random_bytes(16))] as $k => $v) {
        $ins->execute([$k, $v]);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        email VARCHAR(190) NULL,
        role VARCHAR(30) NOT NULL DEFAULT 'editor',
        activo TINYINT NOT NULL DEFAULT 1,
        cred_ver INT NOT NULL DEFAULT 1,
        password_changed_at DATETIME NULL DEFAULT NULL,
        es_autonomo TINYINT NOT NULL DEFAULT 0,
        tarifa_hora DECIMAL(10,2) NOT NULL DEFAULT 0,
        iva_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
        irpf_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_admins_username (username)
    ) $motor");
    Esquema::columnas($pdo, 'admins', [
        'email' => 'VARCHAR(190) NULL',
        'role' => "VARCHAR(30) NOT NULL DEFAULT 'editor'",
        'activo' => 'TINYINT NOT NULL DEFAULT 1',
        'cred_ver' => 'INT NOT NULL DEFAULT 1',
        'password_changed_at' => 'DATETIME NULL DEFAULT NULL',
        'es_autonomo' => 'TINYINT NOT NULL DEFAULT 0',
        'tarifa_hora' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
        'iva_pct' => 'DECIMAL(5,2) NOT NULL DEFAULT 0',
        'irpf_pct' => 'DECIMAL(5,2) NOT NULL DEFAULT 0',
    ]);
    /* Siempre hay un dueño: si no lo hay, lo es la cuenta más antigua. */
    if ((int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn() > 0
        && (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role='owner'")->fetchColumn() === 0) {
        $pdo->exec("UPDATE admins SET role='owner' ORDER BY id ASC LIMIT 1");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(120) NOT NULL,
        secciones_json MEDIUMTEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) $motor");

    $pdo->exec("CREATE TABLE IF NOT EXISTS clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        username VARCHAR(80) NULL,
        password_hash VARCHAR(255) NULL,
        cred_ver INT NOT NULL DEFAULT 1,
        password_changed_at DATETIME NULL DEFAULT NULL,
        iniciales VARCHAR(4) NOT NULL DEFAULT '',
        saludo VARCHAR(160) NOT NULL DEFAULT '',
        conversiones TINYINT NOT NULL DEFAULT 1,
        actual VARCHAR(40) NOT NULL DEFAULT '',
        tipo_id INT NULL,
        estado_json MEDIUMTEXT NULL, plan_json MEDIUMTEXT NULL, accesos_json MEDIUMTEXT NULL,
        tareas_json MEDIUMTEXT NULL, met_json MEDIUMTEXT NULL, informes_json MEDIUMTEXT NULL, servicios_json MEDIUMTEXT NULL,
        looker_url TEXT NULL,
        orden INT NOT NULL DEFAULT 0,
        activo TINYINT NOT NULL DEFAULT 1,
        fact_nombre VARCHAR(200) NOT NULL DEFAULT '', fact_nif VARCHAR(40) NOT NULL DEFAULT '',
        fact_dir VARCHAR(300) NOT NULL DEFAULT '', fact_email VARCHAR(160) NOT NULL DEFAULT '',
        fact_tel VARCHAR(40) NOT NULL DEFAULT '',
        login_email VARCHAR(160) NOT NULL DEFAULT '',
        partner_id INT NULL,
        contact_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY ix_clients_username (username)
    ) $motor");
    Esquema::columnas($pdo, 'clients', [
        'password_hash' => 'VARCHAR(255) NULL',
        'cred_ver' => 'INT NOT NULL DEFAULT 1',
        'password_changed_at' => 'DATETIME NULL DEFAULT NULL',
        'iniciales' => "VARCHAR(4) NOT NULL DEFAULT ''",
        'saludo' => "VARCHAR(160) NOT NULL DEFAULT ''",
        'conversiones' => 'TINYINT NOT NULL DEFAULT 1',
        'actual' => "VARCHAR(40) NOT NULL DEFAULT ''",
        'tipo_id' => 'INT NULL',
        'estado_json' => 'MEDIUMTEXT NULL', 'plan_json' => 'MEDIUMTEXT NULL', 'accesos_json' => 'MEDIUMTEXT NULL',
        'tareas_json' => 'MEDIUMTEXT NULL', 'met_json' => 'MEDIUMTEXT NULL', 'informes_json' => 'MEDIUMTEXT NULL',
        'servicios_json' => 'MEDIUMTEXT NULL',
        'looker_url' => 'TEXT NULL',
        'orden' => 'INT NOT NULL DEFAULT 0',
        'activo' => 'TINYINT NOT NULL DEFAULT 1',
        'fact_nombre' => "VARCHAR(200) NOT NULL DEFAULT ''", 'fact_nif' => "VARCHAR(40) NOT NULL DEFAULT ''",
        'fact_dir' => "VARCHAR(300) NOT NULL DEFAULT ''", 'fact_email' => "VARCHAR(160) NOT NULL DEFAULT ''",
        'fact_tel' => "VARCHAR(40) NOT NULL DEFAULT ''",
        'login_email' => "VARCHAR(160) NOT NULL DEFAULT ''",
        'partner_id' => 'INT NULL',
        'contact_id' => 'INT NULL',
    ]);
    if ((int)$pdo->query('SELECT COUNT(*) FROM client_types')->fetchColumn() === 0) {
        $todas = ['metricas' => 1, 'progreso' => 1, 'informes' => 1, 'como' => 1, 'accesos' => 1, 'plan' => 1];
        $soloWeb = ['metricas' => 0, 'progreso' => 1, 'informes' => 0, 'como' => 1, 'accesos' => 1, 'plan' => 1];
        $it = $pdo->prepare('INSERT INTO client_types (nombre, secciones_json) VALUES (?, ?)');
        $it->execute(['SEO completo', json_encode($todas)]);
        $seo = (int)$pdo->lastInsertId();
        $it->execute(['Solo web', json_encode($soloWeb)]);
        $web = (int)$pdo->lastInsertId();
        $it->execute(['SEM (campañas)', json_encode($todas)]);
        $pdo->prepare('UPDATE clients SET tipo_id=? WHERE tipo_id IS NULL AND conversiones=1')->execute([$seo]);
        $pdo->prepare('UPDATE clients SET tipo_id=? WHERE tipo_id IS NULL AND conversiones=0')->execute([$web]);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_credentials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        titulo VARCHAR(160) NOT NULL,
        categoria VARCHAR(30) NOT NULL DEFAULT 'other',
        usuario VARCHAR(255) NULL,
        secreto TEXT NULL,
        url VARCHAR(500) NULL,
        nota VARCHAR(500) NULL,
        visible_cliente TINYINT NOT NULL DEFAULT 0,
        orden INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (client_id)
    ) $motor");
    Esquema::columnas($pdo, 'client_credentials', ['visible_cliente' => 'TINYINT NOT NULL DEFAULT 0']);

    /* ---------- Tareas ---------- */
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_lists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        nombre VARCHAR(120) NOT NULL,
        es_cliente TINYINT NOT NULL DEFAULT 0,
        tipo VARCHAR(20) NOT NULL DEFAULT 'tareas',
        orden INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (client_id)
    ) $motor");
    Esquema::columnas($pdo, 'task_lists', [
        'es_cliente' => 'TINYINT NOT NULL DEFAULT 0',
        'tipo' => "VARCHAR(20) NOT NULL DEFAULT 'tareas'",
    ]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        list_id INT NOT NULL,
        titulo VARCHAR(255) NOT NULL,
        descripcion MEDIUMTEXT,
        estado VARCHAR(30) NOT NULL DEFAULT 'pendiente',
        responsable_id INT NULL,
        prioridad TINYINT NOT NULL DEFAULT 0,
        due_date DATE NULL,
        visible_cliente TINYINT NOT NULL DEFAULT 0,
        titulo_cliente VARCHAR(255) NULL,
        explicacion_cliente MEDIUMTEXT,
        mes VARCHAR(40) NULL,
        fecha_inicio DATE NULL,
        etiquetas VARCHAR(255) NULL,
        orden INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (client_id), INDEX (list_id)
    ) $motor");
    Esquema::columnas($pdo, 'tasks', ['fecha_inicio' => 'DATE NULL', 'etiquetas' => 'VARCHAR(255) NULL']);

    $pdo->exec("CREATE TABLE IF NOT EXISTS task_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        admin_id INT NULL,
        cuerpo MEDIUMTEXT NOT NULL,
        checklist_json MEDIUMTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (task_id)
    ) $motor");
    Esquema::columnas($pdo, 'task_comments', ['checklist_json' => 'MEDIUMTEXT NULL']);

    $pdo->exec("CREATE TABLE IF NOT EXISTS task_checklist (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        texto VARCHAR(500) NOT NULL,
        done TINYINT NOT NULL DEFAULT 0,
        responsable_id INT NULL,
        orden INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (task_id)
    ) $motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        comment_id INT NULL,
        filename VARCHAR(255) NOT NULL,
        orig_name VARCHAR(255) NULL,
        mime VARCHAR(120) NULL,
        admin_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (task_id), INDEX (comment_id)
    ) $motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_comment_reactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        comment_id INT NOT NULL,
        admin_id INT NOT NULL,
        emoji VARCHAR(16) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq (comment_id, admin_id, emoji),
        INDEX (comment_id)
    ) $motor");
    /* Asignados múltiples (tarea y punto de checklist). Los creaba el panel
       antiguo (erp_nav.php); ensure_schema() creaba otras con nombre equivocado. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_assignees (
        task_id INT NOT NULL, admin_id INT NOT NULL, orden INT NOT NULL DEFAULT 0,
        PRIMARY KEY (task_id, admin_id), KEY (admin_id)
    ) $motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chk_assignees (
        chk_id INT NOT NULL, admin_id INT NOT NULL, orden INT NOT NULL DEFAULT 0,
        PRIMARY KEY (chk_id, admin_id), KEY (admin_id)
    ) $motor");
    foreach (['task_assigned', 'task_check_assigned'] as $t) {
        if (Esquema::tablaExiste($pdo, $t) && (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() === 0) {
            $pdo->exec("DROP TABLE `$t`");   // vacías y sin uso: las dejó el error de nombre
        }
    }

    /* ---------- CRM básico (lo creaba ensure_schema) ---------- */
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        empresa VARCHAR(200) NOT NULL,
        segmento VARCHAR(10) NOT NULL DEFAULT 'b2b',
        estado VARCHAR(20) NOT NULL DEFAULT 'lead_nuevo',
        localizacion VARCHAR(120) NULL, origen VARCHAR(60) NULL, seguimiento VARCHAR(200) NULL,
        paso_flujo VARCHAR(80) NULL, proxima_accion VARCHAR(255) NULL, fecha_prox DATE NULL,
        intentos INT NOT NULL DEFAULT 0, telefono VARCHAR(60) NULL, correo VARCHAR(160) NULL,
        valor DECIMAL(10,2) NULL, notas MEDIUMTEXT NULL, responsable_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (segmento), INDEX (estado)
    ) $motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(40) NOT NULL UNIQUE,
        nombre VARCHAR(80) NOT NULL,
        orden INT NOT NULL DEFAULT 0
    ) $motor");
    $pdo->exec("INSERT IGNORE INTO crm_types (slug, nombre, orden) VALUES ('b2b','B2B · Agencias',0), ('b2c','B2C · Clientes',1)");

    /* ---------- Equipo: perfil, avisos y presencia (antes en erp_nav.php) ---------- */
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_profiles (
        admin_id INT NOT NULL PRIMARY KEY,
        cargo VARCHAR(120) DEFAULT '', departamento VARCHAR(80) DEFAULT '', telefono VARCHAR(60) DEFAULT '',
        ubicacion VARCHAR(120) DEFAULT '', web VARCHAR(160) DEFAULT '', skills VARCHAR(300) DEFAULT '',
        cumple DATE DEFAULT NULL, bio TEXT, foto VARCHAR(160) DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $motor");
    Esquema::columnas($pdo, 'admin_profiles', [
        'departamento' => "VARCHAR(80) DEFAULT ''", 'web' => "VARCHAR(160) DEFAULT ''",
        'skills' => "VARCHAR(300) DEFAULT ''", 'cumple' => 'DATE DEFAULT NULL', 'foto' => "VARCHAR(160) DEFAULT ''",
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        tipo VARCHAR(20) DEFAULT 'info',
        titulo VARCHAR(200), cuerpo VARCHAR(400), url VARCHAR(200) DEFAULT '',
        ref VARCHAR(140) DEFAULT NULL, tarea VARCHAR(200) DEFAULT '', actor VARCHAR(120) DEFAULT '',
        leido TINYINT DEFAULT 0,
        snooze_until DATETIME DEFAULT NULL,
        borrado TINYINT NOT NULL DEFAULT 0,
        bandeja VARCHAR(12) NOT NULL DEFAULT 'principal',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq (admin_id, ref)
    ) $motor");
    Esquema::columnas($pdo, 'notifications', [
        'tarea' => "VARCHAR(200) DEFAULT ''", 'actor' => "VARCHAR(120) DEFAULT ''",
        'snooze_until' => 'DATETIME DEFAULT NULL', 'borrado' => 'TINYINT NOT NULL DEFAULT 0',
        'bandeja' => "VARCHAR(12) NOT NULL DEFAULT 'principal'",
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_presence (
        admin_id INT NOT NULL PRIMARY KEY, last_seen DATETIME DEFAULT NULL, last_active DATETIME DEFAULT NULL
    ) $motor");

    /* ---------- Índices ---------- */
    croilab_migrar_indices($pdo);
    foreach (CROILAB_INDICES_TAREAS as [$tabla, $nombre, $cols, $unico]) {
        Esquema::indice($pdo, $tabla, $nombre, $cols, $unico);
    }
    Esquema::indice($pdo, 'task_lists', 'ix_tl_cli_ord', ['client_id', 'orden', 'id']);
    Esquema::indice($pdo, 'clients', 'ix_cli_activo_ord', ['activo', 'orden', 'name']);
};
