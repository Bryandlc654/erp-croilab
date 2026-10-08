<?php
/* Finanzas: lo que faltaba para numerar bien, rectificar, programar sin
   duplicar y llevar la caja coherente (docs/migracion/04-finanzas.md §17).

   · invoices.numero admite NULL: un borrador ya NO consume número; se le da al
     emitir (Numeracion::asignar, en transacción y con la fila del contador
     bloqueada). Las facturas antiguas conservan el suyo.
   · serie + correlativo: de qué serie es cada número, para validar el orden de
     fechas y no depender de trocear el texto del número.
   · Rectificativas (tipo, rectifica_id, rect_motivo) y anulación.
   · schedule_id + schedule_ym con índice único: una programación no puede
     generar dos facturas del mismo mes aunque el cron y «Generar ahora» corran
     a la vez.
   · invoice_counters.emisor: una serie es de un solo emisor (si no, cada
     autónomo tendría huecos en su numeración).
   · accounting.upload_id y time_entries.acc_id: de qué documento u horas sale
     cada apunte (para llevarlos a la papelera juntos y no volcar horas dos veces).
   · Limpiezas de datos que el ERP antiguo hacía en un GET (§17.17). */

use Croilab\Database\Esquema;

return function (PDO $pdo): void {
    /* ---------- invoices ---------- */
    $pdo->exec("ALTER TABLE invoices MODIFY numero VARCHAR(40) NULL DEFAULT NULL");
    Esquema::columnas($pdo, 'invoices', [
        'project_id' => 'INT NULL DEFAULT NULL',
        'serie' => "VARCHAR(60) NOT NULL DEFAULT ''",
        'correlativo' => 'INT NULL DEFAULT NULL',
        'tipo' => "VARCHAR(15) NOT NULL DEFAULT 'normal'",
        'rectifica_id' => 'INT NULL DEFAULT NULL',
        'rect_motivo' => "VARCHAR(300) NOT NULL DEFAULT ''",
        'mencion_iva' => "VARCHAR(250) NOT NULL DEFAULT ''",
        'emitida_at' => 'DATETIME NULL DEFAULT NULL',
        'emitida_por' => 'INT NULL DEFAULT NULL',
        'anulada_at' => 'DATETIME NULL DEFAULT NULL',
        'anulada_motivo' => "VARCHAR(300) NOT NULL DEFAULT ''",
        'schedule_id' => 'INT NULL DEFAULT NULL',
        'schedule_ym' => 'CHAR(7) NULL DEFAULT NULL',
        'deal_id' => 'INT NULL DEFAULT NULL',
        'hash' => 'CHAR(64) NULL DEFAULT NULL',
        'updated_at' => 'TIMESTAMP NULL DEFAULT NULL',
    ]);
    Esquema::indice($pdo, 'invoices', 'ix_inv_emisor_fecha', ['emisor', 'fecha']);
    Esquema::indice($pdo, 'invoices', 'ix_inv_fecha', ['fecha']);
    Esquema::indice($pdo, 'invoices', 'ix_inv_serie', ['serie', 'correlativo']);
    Esquema::indice($pdo, 'invoices', 'ix_inv_proj', ['project_id']);
    Esquema::indice($pdo, 'invoices', 'ix_inv_deal', ['deal_id']);
    Esquema::indice($pdo, 'invoices', 'ix_inv_rect', ['rectifica_id']);
    Esquema::indice($pdo, 'invoices', 'uq_inv_prog_mes', ['schedule_id', 'schedule_ym'], true);

    /* Facturas antiguas: su serie es el número sin las cifras finales
       (V-2026-014 → V-2026-) y el correlativo, esas cifras. Las que ya tenían
       número cuentan como emitidas aunque estén en borrador: ese número ya
       existe y no se puede reutilizar. */
    $st = $pdo->query("SELECT id, numero, created_at FROM invoices WHERE numero IS NOT NULL AND numero <> '' AND serie = ''");
    $up = $pdo->prepare('UPDATE invoices SET serie = ?, correlativo = ?, emitida_at = COALESCE(emitida_at, ?) WHERE id = ?');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $num = trim((string)$r['numero']);
        $serie = $num;
        $n = null;
        if (preg_match('/^(.*?)(\d+)\s*$/', $num, $m)) {
            $serie = $m[1];
            $n = (int)$m[2];
        }
        $up->execute([mb_substr($serie, 0, 60), $n, $r['created_at'] ?: date('Y-m-d H:i:s'), (int)$r['id']]);
    }
    /* Números vacíos de verdad ('' en vez de NULL) chocarían con el índice único. */
    $pdo->exec("UPDATE invoices SET numero = NULL WHERE numero = ''");

    /* ---------- invoice_items ---------- */
    Esquema::columnas($pdo, 'invoice_items', ['orden' => 'INT NOT NULL DEFAULT 0']);

    /* ---------- invoice_counters ---------- */
    Esquema::columnas($pdo, 'invoice_counters', ['emisor' => "VARCHAR(15) NOT NULL DEFAULT ''"]);

    /* ---------- invoice_schedules ---------- */
    $pdo->exec("ALTER TABLE invoice_schedules MODIFY serie VARCHAR(40) NOT NULL DEFAULT ''");
    Esquema::columnas($pdo, 'invoice_schedules', [
        'venc_dias' => 'INT NULL DEFAULT NULL',
        'project_id' => 'INT NULL DEFAULT NULL',
    ]);

    /* ---------- accounting ---------- */
    Esquema::columnas($pdo, 'accounting', ['upload_id' => 'INT NULL DEFAULT NULL', 'admin_id' => 'INT NULL DEFAULT NULL']);
    $pdo->exec('UPDATE accounting a JOIN invoice_uploads u ON u.acc_id = a.id SET a.upload_id = u.id WHERE a.upload_id IS NULL');
    /* Un solo apunte por factura (el índice único lo garantiza ante dos «cobrar» a la vez). */
    $pdo->exec('DELETE a FROM accounting a JOIN accounting b ON a.invoice_id = b.invoice_id AND a.id < b.id WHERE a.invoice_id IS NOT NULL');
    Esquema::indice($pdo, 'accounting', 'uq_acc_invoice', ['invoice_id'], true);
    Esquema::indice($pdo, 'accounting', 'ix_acc_fecha', ['fecha']);
    Esquema::indice($pdo, 'accounting', 'ix_acc_ambito_fecha', ['ambito', 'fecha']);
    Esquema::indice($pdo, 'accounting', 'ix_acc_proj', ['project_id']);
    Esquema::indice($pdo, 'accounting', 'ix_acc_upload', ['upload_id']);

    /* ---------- invoice_uploads / time_entries / projects ---------- */
    Esquema::indice($pdo, 'invoice_uploads', 'ix_iu_emisor_tipo_fecha', ['emisor', 'tipo', 'fecha']);
    Esquema::columnas($pdo, 'time_entries', ['acc_id' => 'INT NULL DEFAULT NULL']);
    Esquema::indice($pdo, 'time_entries', 'ix_te_acc', ['acc_id']);
    Esquema::indice($pdo, 'time_entries', 'ix_te_admin_fecha', ['admin_id', 'fecha']);

    /* ---------- Limpiezas únicas del ERP antiguo ---------- */
    $flag = $pdo->prepare('SELECT valor FROM settings WHERE clave = ?');
    $marcar = $pdo->prepare("INSERT INTO settings (clave, valor) VALUES (?, '1') ON DUPLICATE KEY UPDATE valor = '1'");
    /* Caja: fuera los ingresos de facturas que nunca se cobraron (fin_limpiar_ingresos_no_cobrados). */
    $flag->execute(['fin_limpieza_ingresos']);
    if (!$flag->fetchColumn()) {
        $pdo->exec("DELETE a FROM accounting a JOIN invoices i ON i.id = a.invoice_id WHERE a.tipo = 'ingreso' AND i.estado <> 'pagada'");
        $marcar->execute(['fin_limpieza_ingresos']);
    }
    /* «Deducible» de los gastos subidos significaba también «personal». */
    $flag->execute(['acc_personal_migrated']);
    if (!$flag->fetchColumn()) {
        $pdo->exec('UPDATE accounting SET personal = 1 WHERE deducible = 1 AND upload_id IS NOT NULL');
        $marcar->execute(['acc_personal_migrated']);
    }
};
