<?php
/* Borra un cliente y TODO lo que cuelga de él.
   Solo por POST y con token CSRF (auth.php lo comprueba solo).
   Antes esto era un enlace GET: cualquier <img src="delete.php?id=1"> borraba. */
require_once __DIR__ . '/../auth.php';
require_can_edit();
/* Borrar un cliente es lo más caro que se puede hacer aquí sin querer, así que
   pasa por la papelera igual que todo lo demás. */
require_once __DIR__ . '/lib/papelera.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: index.php'); exit; }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

/* Alcance: un miembro con rol limitado no puede borrar clientes fuera de su alcance. */
if ($id && function_exists('alcance_ve_cliente') && !alcance_ve_cliente($id)) { header('Location: index.php'); exit; }

if ($id) {
    $pdo = db();
    /* db_tx_* y no beginTransaction() a pelo: dentro de este bloque se llama a
       pap_borrar(), que a su vez abre su propio nivel de transacción para hacer
       atómico el borrado de las hijas. Con los dos a pelo, el beginTransaction()
       de pap_borrar reventaría con «There is already an active transaction», y
       si se mezclaran mal, el commit de dentro se llevaría por delante todo lo
       hecho aquí fuera. Los helpers llevan la cuenta de los niveles. */
    db_tx_begin($pdo);
    try {
        /* Tareas del cliente: primero lo que cuelga de cada tarea. */
        $taskIds = [];
        /* Esto antes iba dentro de un «catch (Exception $e) {}». En MySQL una
           sentencia que falla NO aborta la transacción, así que ese catch no
           protegía nada: si la consulta fallaba, $taskIds se quedaba vacío, el
           código seguía, y el commit de abajo confirmaba el cliente borrado
           con sus tareas, comentarios, checklists, adjuntos y reacciones
           huérfanos, y sin devolvernos nada. Si el fallo es real, que suba. */
        $q = $pdo->prepare('SELECT id FROM tasks WHERE client_id = ?');
        $q->execute([$id]);
        $taskIds = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));

        /* Hijos de cada tarea del cliente: sus CUATRO tablas reales. Antes esto
           borraba de `comments`/`attachments` (tablas del CRM) por task_id, que
           no existen para tareas, así que dejaba huérfanos indefinidamente los
           comentarios, checklists, adjuntos y reacciones de las tareas (P1-01).
           Ahora lo centraliza el helper de la papelera. */
        if ($taskIds) {
            pap_borrar_hijos_tareas($taskIds);
        }

        /* Horas fichadas: NO se borran. Son la nómina de quien las trabajó, y
           borrar un cliente no puede hacer desaparecer lo que alguien cobró.
           Como la tarea y el cliente sí desaparecen, primero se deja escrito en
           el concepto a qué correspondían, y después se sueltan las referencias.
           fin-horas.php las lee con LEFT JOIN, así que sueltas se siguen viendo. */
        /* Esta página no carga erp_nav.php, así que el concepto genérico se
           nombra aquí con el mismo texto que usa TIME_CONCEPTO_TAREA. */
        $conceptoGenerico = defined('TIME_CONCEPTO_TAREA') ? TIME_CONCEPTO_TAREA : 'Horas de la tarea';

        $nomCli = $pdo->prepare('SELECT name FROM clients WHERE id = ?');
        $nomCli->execute([$id]);
        $cname = (string)$nomCli->fetchColumn();
        if ($cname === '') $cname = 'cliente eliminado';

        if (db_tabla_existe('time_entries', $pdo)) {
            /* Las que cuelgan de una tarea heredan el título de la tarea. */
            if ($taskIds) {
                $in = implode(',', $taskIds);
                $pdo->prepare(
                    "UPDATE time_entries te JOIN tasks t ON t.id = te.task_id
                        SET te.concepto = CONCAT(t.titulo, ' · ', ?)
                      WHERE te.task_id IN ($in)
                        AND (te.concepto IS NULL OR te.concepto = '' OR te.concepto = ?)"
                )->execute([$cname, $conceptoGenerico]);
            }
            /* Las que solo apuntaban al cliente, al menos con el nombre. */
            $pdo->prepare(
                "UPDATE time_entries SET concepto = ?
                  WHERE client_id = ? AND task_id IS NULL
                    AND (concepto IS NULL OR concepto = '' OR concepto = ?)"
            )->execute(['Horas de '.$cname, $id, $conceptoGenerico]);

            $pdo->prepare('UPDATE time_entries SET client_id = NULL WHERE client_id = ?')->execute([$id]);
            if ($taskIds) {
                $in = implode(',', $taskIds);
                $pdo->exec("UPDATE time_entries SET task_id = NULL WHERE task_id IN ($in)");
            }
        }

        /* Foto para la papelera: el cliente y lo que se borra con él. Las horas,
           facturas, apuntes y CRM no entran aquí porque no se borran, solo se
           sueltan; por eso el aviso avisa de que la vuelta no es completa. */
        $cn = $pdo->prepare('SELECT name FROM clients WHERE id=?'); $cn->execute([$id]);
        $cNom = (string)($cn->fetchColumn() ?: '');
        $tid = pap_borrar_flash('clients', $id, 'cliente', $cNom, [
            ['tabla'=>'tasks','fk'=>'client_id'],
            ['tabla'=>'task_lists','fk'=>'client_id'],
            ['tabla'=>'client_credentials','fk'=>'client_id'],
            ['tabla'=>'support_tickets','fk'=>'client_id'],
            ['tabla'=>'invoice_schedules','fk'=>'client_id'],
            ['tabla'=>'projects','fk'=>'client_id'],
        ], $cNom!=='' ? 'Cliente «'.$cNom.'» eliminado' : 'Cliente eliminado');
        /* Si no hay foto, no se borra. pap_borrar() avisa devolviendo 0 en vez de
           lanzar, y seguir adelante aquí habría dejado al cliente y a sus
           tareas fuera del ERP sin nada en la papelera: un borrado sin vuelta
           atrás, que es justo lo que la papelera existe para evitar. */
        if (!$tid) throw new Exception('no se ha podido guardar la foto del cliente en la papelera');

        /* Tablas que referencian al cliente directamente. Antes cada DELETE iba
           con su «catch (Exception $e) {}» (línea 97): si una de ellas fallaba,
           el error se lo comía, el loop seguía con las demás y el commit
           confirmaba el borrado del cliente con esas filas todavía colgando de él.
           Ahora se comprueba antes que la tabla exista —una instalación
           antigua puede no tener invoice_schedules— y si existe, que su fallo
           sea un fallo de verdad. */
        foreach ([
            'tasks', 'task_lists', 'client_credentials',
            'support_tickets', 'invoice_schedules', 'projects',
        ] as $t) {
            if (!db_tabla_existe($t, $pdo)) continue;
            $pdo->prepare("DELETE FROM $t WHERE client_id = ?")->execute([$id]);
        }

        /* Respuestas de tickets que se han quedado huérfanas. */
        if (db_tabla_existe('support_replies', $pdo)) {
            $pdo->exec("DELETE r FROM support_replies r LEFT JOIN support_tickets t ON t.id = r.ticket_id WHERE t.id IS NULL");
        }

        /* Contabilidad, facturas y CRM NO se borran: son datos fiscales e
           histórico comercial. Solo se desvinculan para que no apunten a un
           cliente que ya no existe. Antes de soltar la factura, se garantiza que
           conserva el nombre del cliente en su snapshot `cliente_nombre`, para que
           nunca aparezca sin nombre en los listados (P2-14). */
        if ($cNom !== '' && db_tabla_existe('invoices', $pdo)) {
            /* Esto sí llevaba error_log(), pero igualmente: el log no deshace. Un
               UPDATE que falla aquí dejaba facturas sin nombre de cliente, que es
               justo lo que este bloque existe para evitar. */
            $pdo->prepare("UPDATE invoices SET cliente_nombre=? WHERE client_id=? AND (cliente_nombre IS NULL OR cliente_nombre='')")->execute([$cNom, $id]);
        }
        /* Igual que el borrado de arriba: un «catch» aquí dejaba asientos
           contables apuntando a un cliente que ya no existe, y eso no se
           detecta solo: aparece un meses después al revisar la caja. */
        foreach (['accounting', 'invoices', 'contacts', 'deals'] as $t) {
            if (!db_tabla_existe($t, $pdo)) continue;
            $pdo->prepare("UPDATE $t SET client_id = NULL WHERE client_id = ?")->execute([$id]);
        }

        $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        db_tx_commit($pdo);
    } catch (Exception $e) {
        /* Todo lo anterior va en una sola transacción a propósito: o se borra el
           cliente con todo su entorno, o no se borra nada. */
        db_tx_rollback($pdo);
        error_log('delete.php cliente ' . $id . ': ' . $e->getMessage());
        header('Location: client.php?id=' . $id . '&msg=error-borrado');
        exit;
    }
}

header('Location: index.php?msg=cliente-eliminado');
exit;
