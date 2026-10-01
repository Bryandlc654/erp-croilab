<?php
require_once __DIR__ . '/config.php';

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            /* MySQL guardaba en UTC mientras PHP usa Europe/Madrid, así que todas las
               horas del ERP salían desfasadas (p.ej. 2 h en verano). Fijamos el huso de
               la sesión de MySQL al mismo desfase que PHP para que NOW()/created_at y las
               fechas mostradas coincidan. date('P') da el offset actual ('+02:00'…). */
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        } catch (PDOException $e) {
            /* En dev (Docker) se muestra el detalle para poder depurar. En prod NO:
               el mensaje de MySQL puede llevar usuario/host/nombre de la base, así que
               se registra en el log y al visitante le sale un mensaje genérico. */
            if (defined('APP_ENV') && APP_ENV === 'dev') {
                die('Error de conexión con la base de datos. Revisa config.php. (' . htmlspecialchars($e->getMessage()) . ')');
            }
            error_log('DB connect: ' . $e->getMessage());
            die('No se puede conectar con la base de datos en este momento. Vuelve a intentarlo en un momento.');
        }
    }
    return $pdo;
}

/* ===========================================================
   Auto-reparación del esquema: crea lo nuevo (role, client_types,
   tipo_id) si falta. Así el panel funciona aunque no se haya
   ejecutado migrate.php. Es rápido e idempotente.
   =========================================================== */
function ensure_schema() {
    $pdo = db();
    try {
        $q = function($sql) use ($pdo){ return (int)$pdo->query($sql)->fetchColumn(); };
        $hasTable = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='client_types'");
        $hasRole  = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='role'");
        $hasEmail = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='email'");
        $hasTipo  = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='tipo_id'");
        $hasInf   = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='informes_json'");
        $hasSvc   = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='servicios_json'");
        $hasSet   = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='settings'");
        $hasLook  = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='looker_url'");
        $hasTok   = $hasSet ? $q("SELECT COUNT(*) FROM settings WHERE clave='api_token'") : 0;
        $hasTasks = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tasks'");
        $hasEsCli = $hasTasks ? $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_lists' AND COLUMN_NAME='es_cliente'") : 0;
        $hasComments = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_comments'");
        $hasCreds = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='client_credentials'");
        $hasCrm = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_leads'");
        $hasCrmTypes = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_types'");
        $hasChk = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_checklist'");
        $hasAtt = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_attachments'");
        $hasReact = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_comment_reactions'");
        $hasCliOrden = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='orden'");
        $hasCmChk = $hasComments ? $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_comments' AND COLUMN_NAME='checklist_json'") : 0;
        $hasListTipo = $hasTasks ? $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_lists' AND COLUMN_NAME='tipo'") : 0;
        $hasFini = $hasTasks ? $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tasks' AND COLUMN_NAME='fecha_inicio'") : 0;
        $hasEtq  = $hasTasks ? $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tasks' AND COLUMN_NAME='etiquetas'") : 0;
        $hasFact = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='fact_nombre'");
        $hasActivo = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='activo'");
        $hasFactTel = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='fact_tel'");
        $hasLoginEmail = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='login_email'");
        if ($hasTable && $hasRole && $hasEmail && $hasTipo && $hasInf && $hasSvc && $hasSet && $hasLook && $hasTok && $hasTasks && $hasEsCli && $hasComments && $hasCreds && $hasCrm && $hasCrmTypes && $hasChk && $hasAtt && $hasReact && $hasCliOrden && $hasCmChk && $hasListTipo && $hasFini && $hasEtq && $hasFact && $hasActivo && $hasFactTel && $hasLoginEmail) return;   // ya está todo

        if ($hasTasks && !$hasEsCli) {
            $pdo->exec("ALTER TABLE task_lists ADD COLUMN es_cliente TINYINT NOT NULL DEFAULT 0");
        }
        if ($hasTasks && !$hasListTipo) { $pdo->exec("ALTER TABLE task_lists ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'tareas'"); }
        if (!$hasCrm) {
            $pdo->exec("CREATE TABLE crm_leads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                empresa VARCHAR(200) NOT NULL,
                segmento VARCHAR(10) NOT NULL DEFAULT 'b2b',
                estado VARCHAR(20) NOT NULL DEFAULT 'lead_nuevo',
                localizacion VARCHAR(120) NULL,
                origen VARCHAR(60) NULL,
                seguimiento VARCHAR(200) NULL,
                paso_flujo VARCHAR(80) NULL,
                proxima_accion VARCHAR(255) NULL,
                fecha_prox DATE NULL,
                intentos INT NOT NULL DEFAULT 0,
                telefono VARCHAR(60) NULL,
                correo VARCHAR(160) NULL,
                valor DECIMAL(10,2) NULL,
                notas MEDIUMTEXT NULL,
                responsable_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (segmento), INDEX (estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!$hasCrmTypes) {
            $pdo->exec("CREATE TABLE crm_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(40) NOT NULL UNIQUE,
                nombre VARCHAR(80) NOT NULL,
                orden INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $it=$pdo->prepare("INSERT INTO crm_types (slug,nombre,orden) VALUES (?,?,?)");
            $it->execute(['b2b','B2B · Agencias',0]);
            $it->execute(['b2c','B2C · Clientes',1]);
        }
        if (!$hasChk) {
            $pdo->exec("CREATE TABLE task_checklist (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                texto VARCHAR(500) NOT NULL,
                done TINYINT NOT NULL DEFAULT 0,
                responsable_id INT NULL,
                orden INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!$hasAtt) {
            $pdo->exec("CREATE TABLE task_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                comment_id INT NULL,
                filename VARCHAR(255) NOT NULL,
                orig_name VARCHAR(255) NULL,
                mime VARCHAR(120) NULL,
                admin_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (task_id), INDEX (comment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!$hasReact) {
            $pdo->exec("CREATE TABLE task_comment_reactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                comment_id INT NOT NULL,
                admin_id INT NOT NULL,
                emoji VARCHAR(16) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq (comment_id, admin_id, emoji),
                INDEX (comment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!$hasCliOrden) { $pdo->exec("ALTER TABLE clients ADD COLUMN orden INT NOT NULL DEFAULT 0"); }
        /* Datos de facturación guardados en el cliente (auto-relleno de facturas).
           login_email = el correo de Google con el que ese cliente puede entrar al
           portal pulsando «Entrar con Google» (lo pone el equipo en la ficha). */
        foreach (['fact_nombre'=>"VARCHAR(200) NOT NULL DEFAULT ''",'fact_nif'=>"VARCHAR(40) NOT NULL DEFAULT ''",'fact_dir'=>"VARCHAR(300) NOT NULL DEFAULT ''",'fact_email'=>"VARCHAR(160) NOT NULL DEFAULT ''",'fact_tel'=>"VARCHAR(40) NOT NULL DEFAULT ''",'activo'=>"TINYINT NOT NULL DEFAULT 1",'login_email'=>"VARCHAR(160) NOT NULL DEFAULT ''"] as $col=>$def) {
            if (!$q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='$col'")) $pdo->exec("ALTER TABLE clients ADD COLUMN $col $def");
        }
        /* Bóveda: flag «visible para el cliente» (para enseñar en el portal solo lo marcado). */
        if ($hasCreds && !$q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='client_credentials' AND COLUMN_NAME='visible_cliente'")) { try{ $pdo->exec("ALTER TABLE client_credentials ADD COLUMN visible_cliente TINYINT NOT NULL DEFAULT 0"); }catch(Exception $e){} }
        if ($hasComments && !$hasCmChk) { $pdo->exec("ALTER TABLE task_comments ADD COLUMN checklist_json MEDIUMTEXT NULL"); }
        if ($hasTasks && !$hasFini) { $pdo->exec("ALTER TABLE tasks ADD COLUMN fecha_inicio DATE NULL"); }
        if ($hasTasks && !$hasEtq)  { $pdo->exec("ALTER TABLE tasks ADD COLUMN etiquetas VARCHAR(255) NULL"); }
        if (!$hasComments) {
            $pdo->exec("CREATE TABLE task_comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                admin_id INT NULL,
                cuerpo MEDIUMTEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!$hasCreds) {
            $pdo->exec("CREATE TABLE client_credentials (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!$hasTasks) {
            $pdo->exec("CREATE TABLE task_lists (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                nombre VARCHAR(120) NOT NULL,
                es_cliente TINYINT NOT NULL DEFAULT 0,
                orden INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (client_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE tasks (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        if (!$hasLook) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN looker_url TEXT NULL");
        }
        if ($hasSet && !$hasTok) {
            $pdo->prepare("INSERT INTO settings (clave, valor) VALUES ('api_token', ?)")->execute([bin2hex(random_bytes(16))]);
        }

        if (!$hasSet) {
            $pdo->exec("CREATE TABLE settings (clave VARCHAR(60) PRIMARY KEY, valor TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $ins = $pdo->prepare("INSERT INTO settings (clave, valor) VALUES (?, ?)");
            foreach ([
                'meeting_url' => '',
                'video_id'    => 'J9-aEZ523bA',
                'whatsapp'    => '34600000000',
                'email'       => 'hola@croilab.com',
                'api_token'   => bin2hex(random_bytes(16)),
            ] as $k => $v) { $ins->execute([$k, $v]); }
        }

        if (!$hasInf) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN informes_json MEDIUMTEXT NULL");
        }
        if (!$hasSvc) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN servicios_json MEDIUMTEXT NULL");
        }
        if (!$hasRole) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'editor'");
        }
        if (!$hasEmail) {
            /* Correo de Google del miembro: sirve para «Entrar con Google» (solo entra quien
               tenga aquí su correo; no hay registro abierto). */
            $pdo->exec("ALTER TABLE admins ADD COLUMN email VARCHAR(190) NULL");
        }
        if ($q("SELECT COUNT(*) FROM admins WHERE role='owner'") === 0) {
            $pdo->exec("UPDATE admins SET role='owner' ORDER BY id ASC LIMIT 1");
        }
        if (!$hasTable) {
            $pdo->exec("CREATE TABLE client_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(120) NOT NULL,
                secciones_json MEDIUMTEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!$hasTipo) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN tipo_id INT NULL");
        }
        if ($q("SELECT COUNT(*) FROM client_types") === 0) {
            $todas   = ['metricas'=>1,'progreso'=>1,'informes'=>1,'como'=>1,'accesos'=>1,'plan'=>1];
            $soloWeb = ['metricas'=>0,'progreso'=>1,'informes'=>0,'como'=>1,'accesos'=>1,'plan'=>1];
            $ins = $pdo->prepare("INSERT INTO client_types (nombre, secciones_json) VALUES (?, ?)");
            $ins->execute(['SEO completo', json_encode($todas, JSON_UNESCAPED_UNICODE)]);
            $idSEO = (int)$pdo->lastInsertId();
            $ins->execute(['Solo web', json_encode($soloWeb, JSON_UNESCAPED_UNICODE)]);
            $idWeb = (int)$pdo->lastInsertId();
            $ins->execute(['SEM (campañas)', json_encode($todas, JSON_UNESCAPED_UNICODE)]);
            $pdo->prepare("UPDATE clients SET tipo_id=? WHERE tipo_id IS NULL AND conversiones=1")->execute([$idSEO]);
            $pdo->prepare("UPDATE clients SET tipo_id=? WHERE tipo_id IS NULL AND conversiones=0")->execute([$idWeb]);
        }
    } catch (Exception $e) {
        /* si algo falla, no bloquear la app */
    }
}

/* Lee un ajuste global (tabla settings). Devuelve $def si no existe. */
function get_setting($k, $def = '') {
    try {
        $st = db()->prepare('SELECT valor FROM settings WHERE clave = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? $def : $v;
    } catch (Exception $e) { return $def; }
}
