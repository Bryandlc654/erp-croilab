<?php
/* Papelera y deshacer.
   Hasta ahora cualquier «Eliminar» del ERP era definitivo: un clic mal dado y
   la tarea, el contacto o la factura no volvían. Aquí se centraliza el borrado
   para que todo pase por el mismo sitio:

     1. Se hace una foto de la fila (y de sus filas hijas) antes de borrarla.
     2. Se borra de verdad de su tabla, así ninguna consulta del ERP cambia.
     3. La foto queda en `trash`, con quién la borró y cuándo.
     4. Restaurar = volver a insertar la fila con su id original y sus hijas.

   Trabajar con una foto en vez de con una columna «borrado» es lo que permite
   no tocar ni una de las docenas de consultas que ya existen en el proyecto.

   Uso típico desde una página:
     require_once __DIR__.'/lib/papelera.php';
     $tid = pap_borrar('tasks', $id, 'tarea', $titulo, [['tabla'=>'task_comments','fk'=>'task_id']]);
   y después se ofrece el «Deshacer» con ese $tid. */

require_once __DIR__ . '/../../auth.php';
/* Para republicar el portal del cliente al restaurar una tarea/lista/cliente (P2-10).
   publicar_lib.php se autoprotege con if(!function_exists), así que incluirlo es seguro. */
require_once __DIR__ . '/publicar_lib.php';

/* Días que se guarda lo borrado antes de limpiarlo solo. */
if (!defined('PAP_DIAS')) define('PAP_DIAS', 30);

function ensure_papelera_schema() { if (croilab_esquema_gestionado()) return;   /* el esquema lo crean las migraciones */
    static $done = false; if ($done) return; $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS trash (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tabla VARCHAR(64) NOT NULL,
            ref_id INT NOT NULL,
            tipo VARCHAR(40) DEFAULT '',
            titulo VARCHAR(220) DEFAULT '',
            datos MEDIUMTEXT,
            admin_id INT DEFAULT NULL,
            autor VARCHAR(120) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (created_at), INDEX (tabla)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}

/* Nombre bonito de cada tipo, para la lista de la papelera: [etiqueta, icono,
   ruta del front a la que se vuelve tras restaurar (+ id), permiso del módulo].
   Las rutas son las del front nuevo (docs/migracion/RUTAS.md). Una lista
   borrada es de TAREAS (antes enlazaba a listas.php, que es del CRM). */
function pap_tipos() {
    return [
        'tarea'      => ['Tarea',            'check',   '/tareas/',               'ver.tareas'],
        'cliente'    => ['Cliente',          'clients', '/clientes/',             'ver.clientes'],
        'contacto'   => ['Contacto del CRM', 'crm',     '/crm/contactos/',        'ver.crm'],
        'negocio'    => ['Negocio',          'trend',   '/crm/negocio?open=',     'ver.crm'],
        'factura'    => ['Factura',          'file',    '/finanzas/facturas/',    'ver.finanzas'],
        'ticket'     => ['Ticket',           'ticket',  '/soporte/',              'ver.soporte'],
        'lista'      => ['Lista',            'list',    '',                       'ver.tareas'],
        'acta'       => ['Acta',             'pencil',  '/actas/',                'ver.actas'],
        'credencial' => ['Credencial',       'lock',    '',                       'ver.credenciales'],
        'documento'  => ['Documento',        'file',    '',                       'ver.finanzas'],
        /* 'apunte', 'proyecto' y 'servicio' estaban declarados pero nunca se usaban
           (ninguna página los pasa a pap_borrar): eliminados (P3-06). */
    ];
}
function pap_tipo_permiso($t) { $m = pap_tipos(); return $m[$t][3] ?? 'ver.ajustes'; }
function pap_tipo_label($t) { $m = pap_tipos(); return $m[$t][0] ?? ucfirst((string)$t); }
function pap_tipo_icono($t) { $m = pap_tipos(); return $m[$t][1] ?? 'trash'; }
function pap_tipo_url($t, $id) { $m = pap_tipos(); $b = $m[$t][2] ?? ''; return $b ? $b . (int)$id : ''; }

/* Condición SQL de una tabla hija respecto al id de la madre (un solo `?`).
   Sin `en`, la hija apunta a la madre: `fk = ?`. Con `en`, la hija cuelga de
   otra hija: `en` es la cadena de tablas intermedias de la más cercana a la
   más lejana, cada una [tabla, columna que apunta a la siguiente]. Ejemplo,
   los asignados de los puntos de checklist de las tareas de una lista:
     ['tabla'=>'chk_assignees', 'fk'=>'chk_id', 'en'=>[['task_checklist','task_id'], ['tasks','list_id']]]
     → chk_id IN (SELECT id FROM task_checklist WHERE task_id IN (SELECT id FROM tasks WHERE list_id = ?))
   null si algún nombre no es válido o alguna tabla intermedia no existe. */
function pap_condicion_hija($fk, $en = [], $pdo = null) {
    if (!preg_match('/^[a-z_]+$/i', (string)$fk)) return null;
    $sub = '?';
    foreach (array_reverse((array)$en) as $paso) {
        [$t, $col] = array_values((array)$paso) + [null, null];
        if (!preg_match('/^[a-z_]+$/i', (string)$t) || !preg_match('/^[a-z_]+$/i', (string)$col)) return null;
        if (!db_tabla_existe($t, $pdo)) return null;
        $sub = "(SELECT id FROM `$t` WHERE `$col` " . ($sub === '?' ? '= ?' : "IN $sub") . ')';
    }
    return $sub === '?' ? "`$fk` = ?" : "`$fk` IN $sub";
}

/* Borra de verdad, pero guardando antes la foto. Devuelve el id de papelera
   (para ofrecer «Deshacer») o 0 si no se pudo. Cada hija es
   ['tabla'=>…, 'fk'=>…] y opcionalmente 'en' (ver pap_condicion_hija). */
function pap_borrar($tabla, $id, $tipo, $titulo = '', $hijos = []) {
    ensure_papelera_schema();
    $id = (int)$id;
    if (!$id || !preg_match('/^[a-z_]+$/i', (string)$tabla)) return 0;

    try {
        $st = db()->prepare("SELECT * FROM `$tabla` WHERE id=?"); $st->execute([$id]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $fila = null; }
    if (!$fila) return 0;

    /* A partir de aquí todo es «o pasa entero, o no pasa nada».
       Antes cada paso iba por su cuenta con un catch vacío al lado, y el
       resultado era el peor posible para una papelera: si el INSERT en `trash`
       fallaba (disco lleno, conexión cortada, tabla sin espacio) el DELETE de
       las filas ya estaba hecho y confirmado, así que la factura o la tarea
       desaparecían del ERP sin dejar rastro en `trash`. El usuario veía «se ha
       eliminado» y no había nada que deshacer. Con la transacción, ese fallo
       devuelve la factura entera, con sus líneas y su apunte contable. */
    $pdo = db();
    db_tx_begin($pdo);
    try {
        /* Foto de las filas hijas, para que restaurar devuelva la cosa entera y no
           una cáscara vacía (una factura sin sus líneas no sirve de nada). */
        $snapHijos = [];
        foreach ($hijos as $h) {
            $ht = $h['tabla'] ?? ''; $fk = $h['fk'] ?? '';
            if (!preg_match('/^[a-z_]+$/i', (string)$ht) || !preg_match('/^[a-z_]+$/i', (string)$fk)) continue;
            /* Una tabla que no existe en esta instalación no es un fallo: no hay
               hijas que fotografiar ni que borrar. Una que sí existe y falla, sí:
               ese error sube y manda deshacer todo. */
            if (!db_tabla_existe($ht, $pdo)) continue;
            $donde = pap_condicion_hija($fk, $h['en'] ?? [], $pdo);
            if ($donde === null) continue;
            $q = $pdo->prepare("SELECT * FROM `$ht` WHERE $donde"); $q->execute([$id]);
            $snapHijos[] = ['tabla'=>$ht, 'fk'=>$fk, 'donde'=>$donde, 'filas'=>$q->fetchAll(PDO::FETCH_ASSOC)];
        }

        /* Primero las hijas (en el orden pedido: las nietas antes que sus madres,
           porque su condición pasa por ellas), luego la madre. */
        foreach ($snapHijos as &$h) {
            $pdo->prepare("DELETE FROM `{$h['tabla']}` WHERE {$h['donde']}")->execute([$id]);
            unset($h['donde']);
        }
        unset($h);
        $pdo->prepare("DELETE FROM `$tabla` WHERE id=?")->execute([$id]);

        $me = function_exists('current_admin') ? current_admin() : null;
        $datos = json_encode(['fila'=>$fila, 'hijos'=>$snapHijos], JSON_UNESCAPED_UNICODE);
        $pdo->prepare('INSERT INTO trash (tabla,ref_id,tipo,titulo,datos,admin_id,autor) VALUES (?,?,?,?,?,?,?)')
            ->execute([$tabla, $id, (string)$tipo, mb_substr(trim((string)$titulo), 0, 200), $datos,
                       (int)($me['id'] ?? 0) ?: null, (string)($me['username'] ?? '')]);
        $tid = (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        /* Sin tragarse nada: esto es lo que antes se confirmaba a medias. */
        db_tx_rollback($pdo);
        error_log('pap_borrar ' . $tabla . '#' . $id . ': ' . $e->getMessage());
        return 0;
    }
    db_tx_commit($pdo);

    pap_purga();
    return $tid;
}

/* Borra los hijos de una o varias tareas de sus CUATRO tablas reales
   (task_comments, task_checklist, task_attachments y task_comment_reactions).
   Se usa en los borrados en cascada (borrar una lista o un cliente) donde las
   tareas se van sin que sus hijos se guarden para restaurar: sin esto quedaban
   filas huérfanas para siempre (defecto P1-01).
   Las reacciones cuelgan del comentario (comment_id), no de la tarea, así que se
   borran por subconsulta antes que los comentarios. */
function pap_borrar_hijos_tareas($taskIds) {
    $ids = array_values(array_filter(array_map('intval', (array)$taskIds)));
    if (!$ids) return;
    $in = implode(',', $ids);
    $pdo = db();

    /* Cada tabla se comprueba antes de tocarla: una que no exista en esta
       instalación no es un fallo. Y si el fallo es de verdad, el error SUBE
       cuando hay una transacción que lo pueda deshacer (delete.php borra
       después al cliente entero, y confirmar sin comentarios ni adjuntos
       dejaría tareas con hijos huérfanos para siempre). Sin transacción de
       alrededor —workspace.php borra una lista suelta— se registra y se sigue,
       que era lo de antes. */
    $fallar = function ($msg) use ($pdo) {
        error_log($msg);
        if (db_tx_dentro()) throw new Exception($msg);
    };

    if (db_tabla_existe('task_comment_reactions', $pdo)) {
        try { $pdo->exec("DELETE r FROM task_comment_reactions r JOIN task_comments c ON c.id=r.comment_id WHERE c.task_id IN ($in)"); }
        catch (Exception $e) { $fallar('pap_borrar_hijos_tareas reactions: '.$e->getMessage()); }
    }
    foreach (['task_comments','task_checklist','task_attachments'] as $t) {
        if (!db_tabla_existe($t, $pdo)) continue;
        try { $pdo->exec("DELETE FROM `$t` WHERE task_id IN ($in)"); }
        catch (Exception $e) { $fallar("pap_borrar_hijos_tareas $t: ".$e->getMessage()); }
    }
}

/* Deja preparado el aviso con «Deshacer» para la página a la que se redirige.
   Lo pinta erp_foot(), así que quien borra solo tiene que llamar aquí y hacer su
   header('Location: ...') de siempre. */
function pap_undo_flash($tid, $msg = 'Elemento eliminado') {
    if (!(int)$tid) return;
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $_SESSION['erp_undo'] = ['tid' => (int)$tid, 'msg' => (string)$msg];
}

/* Atajo: borra y deja el aviso de una sola llamada. Devuelve el id de papelera. */
function pap_borrar_flash($tabla, $id, $tipo, $titulo = '', $hijos = [], $msg = '') {
    $tid = pap_borrar($tabla, $id, $tipo, $titulo, $hijos);
    if ($tid) pap_undo_flash($tid, $msg ?: (pap_tipo_label($tipo) . ' eliminad' . (in_array($tipo,['tarea','factura','lista','acta','credencial'],true)?'a':'o')));
    return $tid;
}

/* Tipo y autor de un elemento de la papelera, o null si ya no está. */
function pap_elemento($tid) {
    ensure_papelera_schema();
    $st = db()->prepare('SELECT id, tipo, admin_id FROM trash WHERE id = ?');
    $st->execute([(int)$tid]);
    return $st->fetch() ?: null;
}

/* Vuelve a meter la fila (y sus hijas) donde estaban. */
function pap_restaurar($tid) {
    ensure_papelera_schema();
    $tid = (int)$tid; if (!$tid) return ['ok'=>false, 'msg'=>'No encuentro eso en la papelera.'];

    try { $st = db()->prepare('SELECT * FROM trash WHERE id=?'); $st->execute([$tid]); $t = $st->fetch(); }
    catch (Exception $e) { $t = null; }
    if (!$t) return ['ok'=>false, 'msg'=>'Eso ya no está en la papelera.'];

    $d = json_decode((string)$t['datos'], true);
    if (!is_array($d) || empty($d['fila'])) return ['ok'=>false, 'msg'=>'La copia guardada no se puede leer.'];

    $tabla = (string)$t['tabla'];
    /* Si alguien ha vuelto a crear algo con ese id no lo pisamos. */
    try {
        $c = db()->prepare("SELECT COUNT(*) FROM `$tabla` WHERE id=?"); $c->execute([(int)$t['ref_id']]);
        if ((int)$c->fetchColumn() > 0) {
            db()->prepare('DELETE FROM trash WHERE id=?')->execute([$tid]);
            return ['ok'=>true, 'id'=>(int)$t['ref_id'], 'tipo'=>$t['tipo'], 'msg'=>'Eso ya existía otra vez, no hacía falta restaurarlo.'];
        }
    } catch (Exception $e) { return ['ok'=>false, 'msg'=>'Esa tabla ya no existe.']; }

    if (!pap_insert($tabla, $d['fila'])) return ['ok'=>false, 'msg'=>'No se ha podido devolver el registro.'];

    /* La vuelta también es «todo o nada». Antes se inserta la madre, luego las
       hijas una a una sin mirar el resultado, y al final se borra la foto. Con
       una hija que fallara, el elemento volvía a medias (por ejemplo, una factura
       sin sus líneas) y la foto desaparecía igualmente: ya no había forma de
       deshacer el deshacer. */
    $pdo = db();
    db_tx_begin($pdo);
    try {
        foreach (($d['hijos'] ?? []) as $h) {
            if (!preg_match('/^[a-z_]+$/i', (string)($h['tabla'] ?? ''))) continue;
            /* Tabla que ya no existe en la instalación: sus hijas no tienen a
               dónde volver, y eso no es motivo para tumbar la restauración del
               elemento principal. */
            if (!db_tabla_existe((string)$h['tabla'], $pdo)) continue;
            foreach (($h['filas'] ?? []) as $f) {
                if (!pap_insert((string)$h['tabla'], $f)) {
                    throw new Exception('No se ha podido devolver una fila de ' . $h['tabla']);
                }
            }
        }
        $pdo->prepare('DELETE FROM trash WHERE id=?')->execute([$tid]);
    } catch (Exception $e) {
        db_tx_rollback($pdo);
        error_log('pap_restaurar trash#' . $tid . ': ' . $e->getMessage());
        return ['ok'=>false, 'msg'=>'No se ha podido devolver el registro entero. No se ha tocado nada.'];
    }
    db_tx_commit($pdo);

    /* Lo que al borrar solo soltó la referencia (horas, facturas, apuntes,
       contactos, negocios de un cliente) vuelve a apuntar a lo restaurado. */
    if (!empty($d['refs']) && is_array($d['refs'])) {
        try { pap_reenlazar_refs((int)$t['ref_id'], $d['refs']); }
        catch (Throwable $e) { error_log('pap_restaurar refs trash#' . $tid . ': ' . $e->getMessage()); }
    }

    /* Republica el portal del cliente afectado: antes, restaurar una tarea o una
       lista no volvía a publicar y el cliente seguía viendo la versión vieja (P2-10). */
    if (function_exists('publicar_progreso')) {
        $cliAfectado = 0;
        if ($tabla === 'tasks' || $tabla === 'task_lists') $cliAfectado = (int)($d['fila']['client_id'] ?? 0);
        elseif ($tabla === 'clients') $cliAfectado = (int)$t['ref_id'];
        if ($cliAfectado) { try { publicar_progreso($cliAfectado); } catch (Exception $e) {} }
    }

    return ['ok'=>true, 'id'=>(int)$t['ref_id'], 'tipo'=>(string)$t['tipo'],
            'msg'=>pap_tipo_label($t['tipo']) . ' restaurad' . (in_array($t['tipo'],['tarea','factura','lista','acta','credencial'],true)?'a':'o') . '.'];
}

/* Tablas que al borrar un cliente solo pierden la referencia (no se borran).
   La foto de la papelera guarda en `refs` qué filas eran suyas. */
if (!defined('PAP_REFS_CLIENTE')) define('PAP_REFS_CLIENTE', ['accounting', 'invoices', 'contacts', 'deals']);

/* Al restaurar un cliente, sus horas, facturas, apuntes, contactos y negocios
   vuelven a ser suyos, pero solo los que nadie haya enlazado a otro cliente
   mientras tanto (client_id sigue a NULL). Las horas recuperan además su
   tarea si esa tarea ha vuelto con el cliente.
   $refs = ['time_entries' => [[id, task_id], …], 'invoices' => [ids], …]
   Compartida: la usa pap_restaurar() y la puede usar quien restaure a mano. */
function pap_reenlazar_refs($clientId, $refs) {
    $clientId = (int)$clientId;
    if (!$clientId || !is_array($refs)) return;
    $pdo = db();
    foreach (PAP_REFS_CLIENTE as $t) {
        $ids = array_values(array_filter(array_map('intval', (array)($refs[$t] ?? []))));
        if (!$ids || !db_tabla_existe($t, $pdo)) continue;
        $pdo->prepare("UPDATE `$t` SET client_id = ? WHERE client_id IS NULL AND id IN (" . implode(',', $ids) . ')')->execute([$clientId]);
    }
    if (empty($refs['time_entries']) || !db_tabla_existe('time_entries', $pdo)) return;
    $up = $pdo->prepare('UPDATE time_entries SET client_id = ?, task_id = ? WHERE id = ? AND client_id IS NULL AND task_id IS NULL');
    $q = $pdo->prepare('SELECT 1 FROM tasks WHERE id = ? AND client_id = ?');
    foreach ((array)$refs['time_entries'] as $te) {
        if (!is_array($te) || count($te) < 2) continue;
        $te = array_values($te);
        $tarea = $te[1] !== null ? (int)$te[1] : null;
        if ($tarea) {
            $q->execute([$tarea, $clientId]);
            if (!$q->fetchColumn()) $tarea = null;
        }
        $up->execute([$clientId, $tarea, (int)$te[0]]);
    }
}

/* Lo que se va para siempre de la papelera puede dejar filas que solo tenían
   sentido mientras se podía restaurar: las respuestas de los tickets de un
   cliente borrado se quedan en support_replies (el ticket vuelve con su id al
   restaurar). Si el cliente caduca o se purga, esas respuestas sobran. Recibe
   las filas de `trash` que se van a borrar (con su `datos`). */
function pap_limpiar_tras_purga(array $filas) {
    $tickets = [];
    foreach ($filas as $f) {
        if (($f['tabla'] ?? '') !== 'clients') continue;
        $d = json_decode((string)($f['datos'] ?? ''), true);
        foreach ((array)($d['hijos'] ?? []) as $h) {
            if (($h['tabla'] ?? '') !== 'support_tickets') continue;
            foreach ((array)($h['filas'] ?? []) as $r) if (!empty($r['id'])) $tickets[] = (int)$r['id'];
        }
    }
    $tickets = array_values(array_unique(array_filter($tickets)));
    if (!$tickets || !db_tabla_existe('support_replies')) return 0;
    /* Solo las de tickets que no hayan vuelto a existir (restaurados por otro camino). */
    $in = implode(',', $tickets);
    $vivos = db_tabla_existe('support_tickets') ? db()->query("SELECT id FROM support_tickets WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) : [];
    $muertos = array_diff($tickets, array_map('intval', $vivos));
    if (!$muertos) return 0;
    return (int)db()->exec('DELETE FROM support_replies WHERE ticket_id IN (' . implode(',', $muertos) . ')');
}

/* INSERT genérico a partir de una fila fotografiada. Salta columnas que ya no
   existan, porque el esquema de este ERP crece sobre la marcha. */
function pap_insert($tabla, $fila) {
    if (!is_array($fila) || !$fila || !preg_match('/^[a-z_]+$/i', (string)$tabla)) return false;
    try {
        $cols = [];
        foreach (db()->query("SHOW COLUMNS FROM `$tabla`") as $c) $cols[$c['Field']] = true;
    } catch (Exception $e) { return false; }

    $campos = []; $vals = [];
    foreach ($fila as $k => $v) { if (isset($cols[$k])) { $campos[] = "`$k`"; $vals[] = $v; } }
    if (!$campos) return false;
    $ph = implode(',', array_fill(0, count($campos), '?'));
    try {
        db()->prepare("INSERT INTO `$tabla` (" . implode(',', $campos) . ") VALUES ($ph)")->execute($vals);
        return true;
    } catch (Exception $e) { return false; }
}

/* Lo que hay en la papelera, lo más reciente arriba. */
function pap_lista($limite = 300) {
    ensure_papelera_schema();
    $limite = max(1, (int)$limite);
    try { return db()->query("SELECT * FROM trash ORDER BY created_at DESC, id DESC LIMIT $limite")->fetchAll(); }
    catch (Exception $e) { return []; }
}

function pap_contar() {
    ensure_papelera_schema();
    try { return (int)db()->query('SELECT COUNT(*) FROM trash')->fetchColumn(); } catch (Exception $e) { return 0; }
}

/* Tirar definitivamente: un elemento, o toda la papelera si no se pasa id.
   Después se barren los archivos que se han quedado sin dueño: lo que estaba en
   la papelera ya no se puede restaurar, así que sus adjuntos sobran. */
function pap_vaciar($tid = 0) {
    ensure_papelera_schema();
    try {
        if ((int)$tid) {
            $st = db()->prepare("SELECT id, tabla, datos FROM trash WHERE id=? AND tabla='clients'"); $st->execute([(int)$tid]);
            $filas = $st->fetchAll();
            db()->prepare('DELETE FROM trash WHERE id=?')->execute([(int)$tid]);
        } else {
            $filas = db()->query("SELECT id, tabla, datos FROM trash WHERE tabla='clients'")->fetchAll();
            db()->exec('DELETE FROM trash');
        }
    } catch (Exception $e) { return false; }
    try { pap_limpiar_tras_purga($filas); } catch (Throwable $e) { error_log('pap_vaciar respuestas: '.$e->getMessage()); }
    try { pap_limpiar_archivos_huerfanos(); } catch (Throwable $e) { error_log('pap_vaciar huérfanos: '.$e->getMessage()); }
    return true;
}

/* Limpieza automática de lo que lleva más de PAP_DIAS días. Si ha caducado algo,
   también se barren los archivos que se hayan quedado sin dueño. Devuelve
   cuántos elementos han caducado (0 si ya se hizo en esta petición, salvo
   con $forzar: el cron la llama así). */
function pap_purga($forzar = false) {
    static $done = false; if ($done && !$forzar) return 0; $done = true;
    $n = 0;
    try {
        $st = db()->prepare("SELECT id, tabla, datos FROM trash WHERE tabla='clients' AND created_at < (NOW() - INTERVAL ? DAY)");
        $st->execute([(int)PAP_DIAS]);
        $clientes = $st->fetchAll();
        $st = db()->prepare('DELETE FROM trash WHERE created_at < (NOW() - INTERVAL ? DAY)');
        $st->execute([(int)PAP_DIAS]);
        $n = (int)$st->rowCount();
        if ($clientes) pap_limpiar_tras_purga($clientes);
    } catch (Exception $e) { error_log('pap_purga: '.$e->getMessage()); }
    if ($n > 0) {
        try { pap_limpiar_archivos_huerfanos(); } catch (Throwable $e) { error_log('pap_purga huérfanos: '.$e->getMessage()); }
    }
    return $n;
}

/* ---------- Archivos huérfanos de uploads/ ----------
   Borrar una tarea, una lista o un cliente manda las filas a la papelera, pero
   los archivos físicos se quedaban en uploads/ para siempre aunque la papelera
   se vaciara o caducara. En un hosting compartido eso acaba llenando el disco.

   Este barrido borra SOLO los archivos que nadie nombra ya:
     · ni una fila de ninguna tabla (adjuntos, chat, facturas, avatares…),
     · ni un texto enriquecido que lo incruste (descripciones, comentarios…),
     · ni una foto de la papelera (lo borrado tiene que poder volver con su archivo).
   Para no tener que acertar con cada columna, se miran TODAS las columnas de
   texto de TODAS las tablas de la base: si el nombre aparece en cualquier sitio,
   el archivo se queda. Ante la duda no se borra: si cualquier consulta falla se
   cancela la pasada entera, y un archivo con menos de PAP_HUERFANOS_HORAS horas
   nunca se toca (puede ser una subida a medias o una descripción sin guardar). */
if (!defined('PAP_HUERFANOS_HORAS')) define('PAP_HUERFANOS_HORAS', 48);

/* Carpetas de uploads/ y la tabla principal que guarda sus nombres. Si esa tabla
   todavía no existe (su página no se ha abierto nunca), la carpeta no se puede
   comprobar y en esa pasada no se toca. */
function pap_carpetas_uploads() {
    return [
        'tasks'    => ['task_attachments'],   // adjuntos + imágenes incrustadas en descripciones y comentarios
        'crm'      => ['attachments'],        // url = ../archivo.php?d=crm&f=<nombre>
        'chat'     => ['chat_messages'],      // attach = JSON [{fn,orig,img}]
        'facturas' => ['invoice_uploads'],    // filename
        'avatars'  => ['admin_profiles'],     // foto
    ];
}

/* Formas en que un nombre de archivo puede aparecer escrito en la base. */
function pap_variantes_nombre($fn) {
    $v = [$fn, rawurlencode($fn), urlencode($fn), htmlspecialchars($fn, ENT_QUOTES, 'UTF-8'),
          substr((string)json_encode($fn), 1, -1)];
    return array_values(array_unique(array_filter($v, 'strlen')));
}

/* Devuelve ['archivos'=>n, 'bytes'=>n, 'omitidas'=>[carpetas], 'error'=>'', 'lista'=>[carpeta/nombre]].
   Con $simular = true no borra nada: solo dice qué borraría (para revisarlo a mano).
   El barrido real se ejecuta como mucho una vez por petición. */
function pap_limpiar_archivos_huerfanos($simular = false) {
    static $cache = null;
    if ($simular) return pap_barrer_huerfanos(true);
    if ($cache === null) $cache = pap_barrer_huerfanos(false);
    return $cache;
}

/* El barrido en sí (usa pap_limpiar_archivos_huerfanos(), que evita repetirlo). */
function pap_barrer_huerfanos($simular) {
    $res = ['archivos'=>0, 'bytes'=>0, 'omitidas'=>[], 'error'=>'', 'lista'=>[]];
    ensure_papelera_schema();

    $base = realpath(__DIR__ . '/../../uploads');
    if (!$base || !is_dir($base)) { $res['error'] = 'no existe la carpeta uploads'; return $res; }

    /* Sin poder leer la papelera no se puede garantizar que se restaure con su archivo. */
    try { db()->query('SELECT 1 FROM trash LIMIT 1')->fetchAll(); }
    catch (Exception $e) { $res['error'] = 'no se puede leer la papelera'; error_log('Huérfanos: '.$res['error']); return $res; }

    $protegidos = ['.htaccess', 'index.html', 'index.htm', 'index.php', 'web.config'];
    $limite = time() - (int)PAP_HUERFANOS_HORAS * 3600;
    $cand = [];   // clave "carpeta/nombre" => ['ruta'=>..., 'fn'=>..., 'dir'=>...]

    foreach (pap_carpetas_uploads() as $carpeta => $tablas) {
        $dir = realpath($base . DIRECTORY_SEPARATOR . $carpeta);
        if (!$dir || !is_dir($dir) || strpos($dir, $base . DIRECTORY_SEPARATOR) !== 0) continue;

        /* La tabla principal de la carpeta tiene que responder; si no, se salta entera. */
        $ok = true;
        foreach ($tablas as $t) {
            try { db()->query("SELECT 1 FROM `$t` LIMIT 1")->fetchAll(); }
            catch (Exception $e) { $ok = false; break; }
        }
        if (!$ok) { $res['omitidas'][] = $carpeta; continue; }

        $lista = @scandir($dir);
        if ($lista === false) { $res['omitidas'][] = $carpeta; continue; }
        foreach ($lista as $fn) {
            if ($fn === '' || $fn[0] === '.' || in_array(strtolower($fn), $protegidos, true)) continue;
            $ruta = $dir . DIRECTORY_SEPARATOR . $fn;
            if (is_link($ruta) || !is_file($ruta)) continue;
            $mt = @filemtime($ruta);
            if ($mt === false || $mt > $limite) continue;   // reciente o ilegible: se queda
            $cand[$carpeta . '/' . $fn] = ['ruta'=>$ruta, 'fn'=>$fn, 'dir'=>$dir];
        }
    }
    if (!$cand) return $res;

    /* Todas las columnas de texto de todas las tablas de la base. */
    try {
        $cols = [];
        $q = db()->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','json')");
        foreach ($q as $r) $cols[(string)$r['TABLE_NAME']][] = (string)$r['COLUMN_NAME'];
    } catch (Exception $e) {
        $res['error'] = 'no se pueden listar las columnas'; error_log('Huérfanos: '.$res['error'].': '.$e->getMessage());
        return $res;
    }
    if (!$cols) { $res['error'] = 'la base no devuelve columnas'; return $res; }

    /* Pasada 1 (rápida): trocea cada texto en palabras que puedan ser un nombre
       de archivo y tacha los candidatos que aparezcan. Casi todos los archivos
       en uso se descartan aquí. */
    $porNombre = [];   // nombre en minúsculas => [claves de candidato]
    foreach ($cand as $k => $c) $porNombre[strtolower($c['fn'])][] = $k;
    $recorrer = function ($alVer) use ($cols) {
        foreach ($cols as $tabla => $cs) {
            $sel = implode(',', array_map(function ($c) { return '`' . str_replace('`', '``', $c) . '`'; }, $cs));
            $st = db()->query('SELECT ' . $sel . ' FROM `' . str_replace('`', '``', $tabla) . '`');
            while ($fila = $st->fetch(PDO::FETCH_NUM)) {
                foreach ($fila as $v) { if ($v !== null && $v !== '' && strlen($v) > 3) $alVer((string)$v); }
            }
            $st->closeCursor();
        }
    };
    try {
        $recorrer(function ($v) use (&$porNombre, &$cand) {
            if (!$porNombre) return;
            if (!preg_match_all('/[A-Za-z0-9._%\-]+/', $v, $m)) return;
            foreach ($m[0] as $tok) {
                foreach ([$tok, rawurldecode($tok)] as $t) {
                    $t = strtolower($t);
                    if (isset($porNombre[$t])) { foreach ($porNombre[$t] as $k) unset($cand[$k]); unset($porNombre[$t]); }
                }
            }
        });
        /* Pasada 2 (a fondo, solo con lo que queda): busca el nombre como trozo de
           cualquier texto, en todas sus formas (tal cual, codificado en URL, en
           HTML o en JSON) y sin distinguir mayúsculas. */
        if ($cand) {
            $vars = [];
            foreach ($cand as $k => $c) $vars[$k] = pap_variantes_nombre($c['fn']);
            $recorrer(function ($v) use (&$cand, &$vars) {
                foreach ($vars as $k => $vs) {
                    foreach ($vs as $x) { if (stripos($v, $x) !== false) { unset($cand[$k], $vars[$k]); break; } }
                }
            });
        }
    } catch (Exception $e) {
        /* Una tabla que no se deja leer = no sabemos si nombra algún archivo: no se borra nada. */
        $res['error'] = 'consulta fallida, pasada cancelada'; error_log('Huérfanos: '.$res['error'].': '.$e->getMessage());
        return $res;
    }

    /* Lo que queda no lo nombra nadie: fuera. Se vuelve a comprobar todo justo antes. */
    foreach ($cand as $k => $c) {
        $real = realpath($c['ruta']);
        if (!$real || strpos($real, $c['dir'] . DIRECTORY_SEPARATOR) !== 0 || is_link($c['ruta']) || !is_file($real)) continue;
        clearstatcache(true, $real);
        $mt = @filemtime($real);
        if ($mt === false || $mt > $limite) continue;
        $tam = (int)@filesize($real);
        if ($simular || @unlink($real)) { $res['archivos']++; $res['bytes'] += $tam; $res['lista'][] = $k; }
    }

    if (!$simular && ($res['archivos'] || $res['omitidas'])) {
        error_log('Huérfanos de uploads: ' . $res['archivos'] . ' archivo(s), ' . $res['bytes'] . ' bytes liberados'
                . ($res['omitidas'] ? ' · sin comprobar: ' . implode(', ', $res['omitidas']) : ''));
    }
    return $res;
}

/* Texto corto para el registro del cron: «3 archivo(s), 1,2 MB liberados». */
function pap_huerfanos_resumen($r) {
    if (!empty($r['error'])) return 'sin borrar nada: ' . $r['error'];
    $b = (int)$r['bytes'];
    $tam = $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB'
         : ($b >= 1024 ? number_format($b / 1024, 1, ',', '.') . ' KB' : $b . ' bytes');
    return (int)$r['archivos'] . ' archivo(s), ' . $tam . ' liberados'
         . (!empty($r['omitidas']) ? ' · sin comprobar: ' . implode(', ', $r['omitidas']) : '');
}
