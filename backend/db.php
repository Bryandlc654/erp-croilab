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
   EL SELLO DEL ESQUEMA

   `ensure_schema()` se llama en la línea ~5 de casi todas las pantallas. Antes
   comprobaba el esquema con 27 consultas a `information_schema` en cada
   petición: aunque no faltara nada, cada clic de menú pagaba 27
   viajes de ida y vuelta a MySQL antes de que la página empezara a trabajar.

   Ahora se guarda en `settings.schema_version` qué versión del esquema sabe
   dejar este código al día. Si esa versión coincide con CROILAB_SCHEMA_VERSION,
   `ensure_schema()` sale tras UNA consulta —una clave primaria, la más barata
   que hay— y ni vuelve a mirar `information_schema`.

   PARA SUBIR LA VERSIÓN: cambia CROILAB_SCHEMA_VERSION por un número mayor, y
   solo cuando el bloque de migración de más abajo ya sepa arreglar lo que
   falta. El número es el aviso, no el trabajo: el trabajo sigue siendo el
   bloque de abajo, que es idempotente y se puede repetir sin miedo.

   LO QUE ESTO CAMBIA A PROPÓSITO: restaurar una copia antigua de la base ya
   no se repara sola, porque el sello dirá que lo está. Se fuerza con
   `ensure_schema(true)`, que hace la comprobación entera ignorando el sello.
   =========================================================== */
const CROILAB_SCHEMA_VERSION = 2;

/* Una sola consulta de clave primaria. Devuelve true si el esquema está al día.
   Si la tabla `settings` no existe todavía (instalación nueva) MySQL avisa con
   excepción, y aquí no hay nada que comprobar: es trabajo del bloque de abajo. */
function croilab_esquema_al_dia($pdo) {
    try {
        $st = $pdo->prepare('SELECT valor FROM settings WHERE clave = ?');
        $st->execute(['schema_version']);
        return ((int)$st->fetchColumn()) === CROILAB_SCHEMA_VERSION;
    } catch (Exception $e) {
        return false;
    }
}

/* Sella el esquema como actualizado. Se llama SOLO cuando la migración de abajo
   ha terminado bien: si una sentencia peta a mitad, el sello no se escribe y la
   siguiente petición vuelve a intentarlo, igual que antes de este cambio. */
function croilab_sellar_esquema($pdo) {
    try {
        $pdo->prepare('INSERT INTO settings (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
            ->execute(['schema_version', (string)CROILAB_SCHEMA_VERSION]);
    } catch (Exception $e) { }
}

/* ===========================================================
   ÍNDICES QUE FALTABAN

   La mitad de las tablas del ERP se nacen en el fichero de la página que las usa
   y, casi siempre, solo con PRIMARY KEY. Eso no estorba en desarrollo, pero en
   cuanto una tabla crece, cada listado la recorre entera: así `notifications`
   ordenaba toda la bandeja de un usuario en cada carga, o `invoices` no tenía
   nada sobre `client_id` ni `fecha`.

   Aquí se crea lo que falta, una vez, al subir el sello a la versión 2.

   TRES SALVEDADES IMPORTANTES:

   1. No se inventan índices «por si acaso»: solo los que tienen una consulta
      detrás. `login_attempts`, por ejemplo, ya está bien cubierta por su
      PRIMARY KEY (ident, ip), que es exactamente lo que consulta el login.
   2. Antes de tocar nada se comprueba que la tabla existe, que TODAS las
      columnas del índice existen y que el índice no existe ya. Sin esas tres
      comprobaciones, un ALTER sobre una columna que esa instalación no tiene
      revienta la migración entera.
   3. Un fallo puntual no debe dejar el esquema a medias ni tumbar la web: cada
      índice va en su propio try/catch y se registra en el log. La web sigue
      funcionando igual de rápido que antes, solo que sin ese índice.
   =========================================================== */
/* La lista de índices que este código garantiza. Es la fuente única: la usan
   tanto la comprobación del sello como la migración, para que no puedan
   divergir (si divergieran, el sello se escribiría sin un índice y nadie lo
   volvería a intentar).
   Formato: tabla, nombre del índice, columnas, y si es único.
   Solo se incluyen los que tienen una consulta detrás; los que ya cubren una
   PRIMARY KEY (login_attempts, chat_members, contact_tags…) no aparecen. */
const CROILAB_INDICES = [
    /* `notifications` tenía UNIQUE (admin_id, ref): el filtro por admin_id ya
       funcionaba, pero faltaba created_at, y «SELECT * FROM notifications WHERE
       admin_id=? ORDER BY created_at DESC» (notifications.php) ordenaba en
       memoria toda la bandeja del usuario en cada visita. El segundo índice es
       el del contador y la lista de sin leer, que filtran por leido. */
    ['notifications', 'ix_notif_admin_fecha', ['admin_id', 'created_at'], false],
    ['notifications', 'ix_notif_admin_leido',  ['admin_id', 'leido'], false],

    /* `invoices` no tenía nada salvo la clave primaria: cada factura de un
       cliente, cada listado por fecha y cada filtro por estado (facturas.php,
       contabilidad.php, buscar.php) recorrían la tabla entera. `numero` ya lo
       cubre el único condicional de lib/fin_prog.php, con la aplicación
       comprobando antes que no haya repetidos. */
    ['invoices', 'ix_inv_client_fecha', ['client_id', 'fecha'], false],
    ['invoices', 'ix_inv_fecha',        ['fecha'], false],
    ['invoices', 'ix_inv_estado_fecha',  ['estado', 'fecha'], false],

    /* `accounting` se recorta por fecha y por cliente, y se cruza contra invoices. */
    ['accounting', 'ix_acc_fecha',  ['fecha'], false],
    ['accounting', 'ix_acc_client', ['client_id'], false],

    /* Listados por cliente: proyectos, tickets, agendas y vencimientos. */
    ['projects', 'ix_proj_client', ['client_id'], false],
    ['support_tickets', 'ix_ticket_client_estado', ['client_id', 'estado'], false],
    ['portal_meeting_requests', 'ix_pmr_estado_fecha', ['estado', 'created_at'], false],
    ['invoice_schedules', 'ix_isch_client_activo', ['client_id', 'activo'], false],

    /* Presencia en el chat y perfil: se piden siempre por admin_id y solo
       tenían la clave primaria por id. */
    ['chat_presence', 'ix_pres_admin', ['admin_id'], false],
    ['admin_profiles', 'ix_perfil_admin', ['admin_id'], false],

/* Búsqueda por prefijo en admin/buscar.php. Ojo con lo que esto NO arregla:
       un LIKE '%x%' no lo puede usar ningún índice, porque el comodín inicial
       deja el orden de las claves indeterminado. Lo que sí se resuelve es la
       búsqueda por «x%», que es la primera pasada de la general y la única del
       buscador de clientes: lo que se está tecleando, se teclea por delante. */
    ['clients', 'ix_cli_name',     ['name'], false],
    ['clients', 'ix_cli_username', ['username'], false],
    ['admins',  'ix_adm_username', ['username'], false],
    ['admins',  'ix_adm_email',    ['email'], false],
    ['tasks',   'ix_task_titulo',  ['titulo'], false],
    ['contacts', 'ix_cont_nombre', ['nombre'], false],
    ['contacts', 'ix_cont_empresa',['empresa'], false],
    ['deals',   'ix_deal_nombre',  ['nombre'], false],
    ['support_tickets', 'ix_ticket_asunto', ['asunto'], false],
    ['projects', 'ix_proj_nombre', ['nombre'], false],
    ['invoices', 'ix_inv_cliente_nombre', ['cliente_nombre'], false],

    /* Tareas: casi siempre se filtran por estado y se ordenan por fecha. */
    ['tasks', 'ix_task_estado_fini', ['estado', 'fecha_inicio'], false],

    /* CRM: listados por propietario. */
    ['contacts', 'ix_cont_propietario', ['propietario_id'], false],
];

function croilab_indice_asegurar($pdo, $tabla, $indice, array $columnas, $unico = false) {
    static $cacheTabla = [];
    try {
        $cacheTabla[$tabla] ??= ((int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $pdo->quote($tabla)
        )->fetchColumn()) > 0;
        if (!$cacheTabla[$tabla]) return false;   /* la tabla no existe en esta instalación: no hay nada que indexar */

        /* ¿Existe ya el índice con ese nombre? Por nombre, no por columnas: si el
           nombre está ocupado, el ALTER rebotaría con «duplicate key name». */
        $existe = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" .
            $pdo->quote($tabla) . " AND INDEX_NAME=" . $pdo->quote($indice)
        )->fetchColumn();
        if ($existe > 0) return false;

        /* Todas las columnas tienen que existir. information_schema se consulta
           una vez por columna y solo dentro de este bloque, que corre una vez
           por instalación: es la opción lenta, y aquí slow es lo que se quiere. */
        foreach ($columnas as $col) {
            $hay = (int)$pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" .
                $pdo->quote($tabla) . " AND COLUMN_NAME=" . $pdo->quote($col)
            )->fetchColumn();
            if (!$hay) return false;
        }

        $pdo->exec("ALTER TABLE `$tabla` ADD " . ($unico ? 'UNIQUE KEY' : 'INDEX') . " `$indice` (`" . implode('`,`', $columnas) . "`)");
        error_log('croilab: indice creado ' . $tabla . '.' . $indice);
        return true;
    } catch (Exception $e) {
        error_log('croilab: indice ' . $tabla . '.' . $indice . ' -> ' . $e->getMessage());
        return false;
    }
}

function croilab_migrar_indices($pdo) {
    foreach (CROILAB_INDICES as $d) {
        croilab_indice_asegurar($pdo, $d[0], $d[1], $d[2], $d[3]);
    }
}

/* Asegura los índices de UNA sola tabla, y solo si falta alguno.
 *
 * Hace falta para las tablas que crea el fichero de su página y no ensure_schema()
 * (notifications en erp_nav.php, el chat en chat.php…): cuando la migración se
 * ejecutó, esas tablas aún no existían, así que sus índices se quedaron sin
 * crear y no hay forma de recuperarlos salvo al subir otra vez el sello.
 *
 * OJO con lo que NO es la solución: llamar aquí a ensure_schema(true). El
 * parámetro true salta el sello a propósito, así que eso reventaría la
 * migración entera —28 consultas a information_schema— en cada petición que
 * pase por aquí. Una consulta y, solo si algo falta, los ALTER. */
function croilab_indices_tabla_asegurar($pdo, $tabla) {
    try {
        $nombres = [];
        foreach (CROILAB_INDICES as $d) { if ($d[0] === $tabla) $nombres[] = $d[1]; }
        if (!$nombres) return;
        $in = implode(',', array_map([$pdo, 'quote'], $nombres));
        $hay = (int)$pdo->query(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $pdo->quote($tabla) . " AND INDEX_NAME IN ($in)"
        )->fetchColumn();
        if ($hay >= count($nombres)) return;   /* ya están: no se toca nada */
        foreach (CROILAB_INDICES as $d) {
            if ($d[0] === $tabla) croilab_indice_asegurar($pdo, $d[0], $d[1], $d[2], $d[3]);
        }
    } catch (Exception $e) { }
}

/* ¿Falta algún índice de los que este código sabe crear?
   Dos consultas, y solo se ejecuta en el camino lento (cuando el sello no cuadra):
   en el camino rápido el sello ya respondió y esto ni se mira. */
function croilab_indices_al_dia($pdo) {
    try {
        $tablas = [];
        foreach (CROILAB_INDICES as $d) $tablas[$d[0]] = true;
        $in = implode(',', array_map([$pdo, 'quote'], array_keys($tablas)));

        $presentes = [];
        foreach ($pdo->query("SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS
                              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($in)")->fetchAll() as $r) {
            $presentes[$r['TABLE_NAME'] . '|' . $r['INDEX_NAME']] = true;
        }
        /* Las tablas que aún no existen no cuentan como pendientes: se crean
           desde el fichero de su página (notifications en erp_nav.php, chat en
           chat.php…) y no pueden tener índices antes de existir. Su índice se
           añadirá en la próxima pasada, que para entonces ya las verá. */
        $tablasOk = [];
        foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES
                              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($in)")->fetchAll() as $r) {
            $tablasOk[$r['TABLE_NAME']] = true;
        }

        foreach (CROILAB_INDICES as $d) {
            if (!isset($tablasOk[$d[0]])) continue;
            if (!isset($presentes[$d[0] . '|' . $d[1]])) return false;
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/* ===========================================================
   Auto-reparación del esquema: crea lo nuevo (role, client_types,
   tipo_id) si falta. Así el panel funciona aunque no se haya
   ejecutado migrate.php. Es idempotente, y solo entra en la
   comprobación completa cuando el sello no cuadra.
   =========================================================== */
function ensure_schema($forzar = false) {
    /* Una sola pasada por petición aunque la llamen varias pantallas
       (pasa en _layout.php y en los ajustes). */
    static $hecho = false;
    if ($hecho && !$forzar) return;
    $pdo = db();
    if (!$forzar && croilab_esquema_al_dia($pdo)) { $hecho = true; return; }
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
        /* OJO: aquí NO se mira si los índices están, a propósito.
           Si el sello quedara atado a ellos, bastaría UNA columna que no se
           pueda indexar (un TEXT, un nombre demasiado largo, un tipo raro de
           esa instalación) para que el sello nunca se escribiera y las 27
           consultas a information_schema volvieran a caer en cada petición: el
           arreglo del punto 1 deshaciéndose solo. Que un índice no se pueda
           crear es un aviso en el log, no un motivo para no sellar. */
        if ($hasTable && $hasRole && $hasEmail && $hasTipo && $hasInf && $hasSvc && $hasSet && $hasLook && $hasTok && $hasTasks && $hasEsCli && $hasComments && $hasCreds && $hasCrm && $hasCrmTypes && $hasChk && $hasAtt && $hasReact && $hasCliOrden && $hasCmChk && $hasListTipo && $hasFini && $hasEtq && $hasFact && $hasActivo && $hasFactTel && $hasLoginEmail) { croilab_sellar_esquema($pdo); $hecho = true; return; }   // ya está todo

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
        /* Índices que faltaban. Va después de crear las columnas nuevas, porque un
           índice sobre una columna que este mismo bloque acaba de añadir sí se
           puede crear, y al revés no.
           La comprobación previa es solo para no recorrer los 26 índices
           cuando ya están todos: si falta alguno, se intenta la migración
           entera. Y aunque algún ALTER no llegue a salir, el sello se escribe
           igual (más abajo). */
        if (!croilab_indices_al_dia($pdo)) croilab_migrar_indices($pdo);

        /* Tablas puente para asignaciones múltiples en tareas (comentarios/checklist).
           El código ya las usa con helpers (task_assigned, task_check_assigned), pero
           en algunas instalaciones pueden no existir todavía. Se crean aquí de forma
           idempotente, sin bloquear el arranque. */
        if (!db_tabla_existe('task_assigned', $pdo)) {
            $pdo->exec("CREATE TABLE task_assigned (
                task_id INT NOT NULL,
                admin_id INT NOT NULL,
                PRIMARY KEY (task_id, admin_id),
                KEY (admin_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!db_tabla_existe('task_check_assigned', $pdo)) {
            $pdo->exec("CREATE TABLE task_check_assigned (
                check_id INT NOT NULL,
                admin_id INT NOT NULL,
                PRIMARY KEY (check_id, admin_id),
                KEY (admin_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        /* Índices secundarios mínimos para el flujo de tareas. Idempotentes y
           tolerantes: si alguna tabla no existe, croilab_indice_asegurar() lo ignora. */
        $ix_tareas = [
            ['tasks', 'ix_t_list_ord', '(list_id, orden, id)'],
            ['tasks', 'ix_t_cli_list', '(client_id, list_id)'],
            ['tasks', 'ix_t_cli_estado', '(client_id, estado)'],
            ['tasks', 'ix_t_resp_estado', '(responsable_id, estado)'],
            ['task_comments', 'ix_tc_task_crea', '(task_id, created_at, id)'],
            ['task_checklist', 'ix_tk_task_done_ord', '(task_id, done DESC, orden, id)'],
            ['task_attachments', 'ix_ta_task', '(task_id)'],
            ['task_attachments', 'ix_ta_comment', '(comment_id)'],
            ['task_comment_reactions', 'ix_tcr_comment_emoji', '(comment_id, emoji)'],
            ['task_comment_reactions', 'uq_tcr_c_a_e', 'UNIQUE (comment_id, admin_id, emoji)'],
        ];
        foreach ($ix_tareas as $it) {
            @croilab_indice_asegurar($pdo, $it[0], $it[1], $it[2]);
        }

        /* Solo se sella si se ha llegado aquí entero. */
        croilab_sellar_esquema($pdo);
        $hecho = true;
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

/* ===========================================================
   TRANSACCIONES QUE SE PUEDEN ANIDAR

   Por qué esto existe: PDO no admite transacciones anidadas. Si un código ya
   está dentro de una transacción y llama a beginTransaction(), revienta con
   «There is already an active transaction». Y en este ERP hace falta anidar:
   por ejemplo admin/delete.php abre su transacción para borrar un cliente y,
   en medio, llama a pap_borrar(), que borra las filas hijas del cliente. Si la
   papelera abriera su propia transacción, el borrado entero reventaría.

   Por eso el nivel 0 usa BEGIN/COMMIT/ROLLBACK de verdad, y los niveles
   siguientes usan SAVEPOINT: MySQL permite anidar así, y un fallo en la parte
   interior deshace solo lo interior, sin tirar lo que hizo la exterior.

   LO QUE ESTAS FUNCIONES NO PUEDEN ARREGLAR, y es importante saberlo: en MySQL
   una sentencia que falla NO aborta la transacción (al revés que en PostgreSQL).
   Por eso un «catch (Exception $e) {}» metido entre un BEGIN y su COMMIT deja
   pasar el fallo y confirma el resto: la transacción parece correcta y el
   resultado es un estado a medias. Por eso el patrón que se usa en el ERP es
   dejar que la excepción suba hasta el catch exterior, y que ese sea el que
   haga el rollback. */
function &db_tx_profundidad() {
    static $n = 0;
    return $n;
}

/* Abre un nivel de transacción. Devuelve false si ya había una rota. */
function db_tx_begin($pdo) {
    $n = &db_tx_profundidad();
    if ($n === 0) {
        /* Hay una transacción abierta que no es nuestra: alguien llamó a
           beginTransaction() a pelo por una ruta que no usa estos helpers.
           No se puede continuar sobre ella (el contador no la conoce y su
           commit se llevaría por delante lo que hagamos aquí). Se deshace para
           poder empezar limpio, pero se avisa: perder un commit sin que nadie
           entienda por qué es peor que el fallo que lo provocando. */
        if ($pdo->inTransaction()) {
            error_log('db_tx_begin: había una transacción abierta sin db_tx_begin(); se deshace');
            $pdo->rollBack();
        }
        $pdo->beginTransaction();
        $n = 1;
        return true;
    }
    $pdo->exec('SAVEPOINT croilab_sp' . $n);
    $n++;
    return true;
}

/* Cierra un nivel. En el 0 confirma de verdad; en los demás, suelta el savepoint. */
function db_tx_commit($pdo) {
    $n = &db_tx_profundidad();
    if ($n === 0) return false;
    $n--;
    if ($n === 0) { $pdo->commit(); return true; }
    $pdo->exec('RELEASE SAVEPOINT croilab_sp' . $n);
    return true;
}

/* Deshace hasta este nivel. Si MySQL ya ha dejado la transacción entera rota
   (un deadlock, o un lock wait timeout), no hay nada que deshacer y el nivel se
   pone a 0 para no dejar el contador desfasado. */
function db_tx_rollback($pdo) {
    $n = &db_tx_profundidad();
    if ($n === 0) return false;
    if (!$pdo->inTransaction()) { $n = 0; return false; }
    $n--;
    if ($n === 0) { $pdo->rollBack(); return true; }
    $pdo->exec('ROLLBACK TO SAVEPOINT croilab_sp' . $n);
    return true;
}

/* ¿Existe esta tabla en esta instalación?
   Este ERP crea media base de datos sobre la marcha, y cada tabla tiene su
   página: una instalación puede no tener todavía `invoice_schedules` o
   `task_checklist`. Al borrar o migrar hay que tolerar esa ausencia… pero no
   cualquier fallo.
   La diferencia es la que motivó esta función: con un catch vacío no se
   distingue «no hay nada que limpiar» de «la limpieza ha fallado», y en una
   transacción MySQL el segundo caso se confirma igualmente, dejando el borrado
   a medias. Comprobando antes de actuar, lo que no se puede hacer se ni intenta,
   y lo que falla de verdad sube y deshace. */
function db_tabla_existe($tabla, $pdo = null) {
    static $cache = [];
    $tabla = (string)$tabla;
    if (isset($cache[$tabla])) return $cache[$tabla];
    if (!preg_match('/^[a-z_]+$/i', $tabla)) return false;
    try {
        $pdo = $pdo ?: db();
        $n = (int)$pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $pdo->quote($tabla)
        )->fetchColumn();
        $cache[$tabla] = $n > 0;
    } catch (Exception $e) {
        $cache[$tabla] = false;
    }
    return $cache[$tabla];
}

/* ¿Existe esta columna? Mismo motivo que db_tabla_existe(), para cuando lo que
   falta en una instalación antigua es la columna y no la tabla entera. */
function db_columna_existe($tabla, $columna, $pdo = null) {
    static $cache = [];
    $clave = $tabla . '.' . $columna;
    if (isset($cache[$clave])) return $cache[$clave];
    if (!preg_match('/^[a-z_]+$/i', (string)$tabla) || !preg_match('/^[a-z_]+$/i', (string)$columna)) return false;
    try {
        $pdo = $pdo ?: db();
        $n = (int)$pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' .
            $pdo->quote($tabla) . ' AND COLUMN_NAME=' . $pdo->quote($columna)
        )->fetchColumn();
        $cache[$clave] = $n > 0;
    } catch (Exception $e) {
        $cache[$clave] = false;
    }
    return $cache[$clave];
}

/* ¿Hay una transacción abierta? Para saber si lo que viene se va a
   confirmar solo o no. */
function db_tx_dentro() {
    $n = &db_tx_profundidad();
    return $n > 0;
}
