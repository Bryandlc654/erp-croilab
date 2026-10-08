<?php
/* Índices para lo que hace Clientes: buscar por correo de Google (login del
   portal), contar clientes por tipo y por agencia, y sobre todo el borrado de
   un cliente y su ficha, que van tabla por tabla buscando por client_id.

   Solo se crea un índice si no hay ya otro que empiece por esas columnas (lo
   puede haber creado otro módulo con otro nombre): así no se duplican. */

use Croilab\Database\Esquema;

return function (PDO $pdo): void {
    $hayPrefijo = function (string $tabla, array $cols) use ($pdo): bool {
        $st = $pdo->prepare('SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
                               FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? GROUP BY INDEX_NAME');
        $st->execute([$tabla]);
        $buscado = implode(',', $cols);
        foreach ($st->fetchAll() as $r) {
            if ($r['cols'] === $buscado || str_starts_with($r['cols'], $buscado . ',')) return true;
        }
        return false;
    };
    $indices = [
        ['clients', 'ix_cli_login_email', ['login_email']],
        ['clients', 'ix_cli_tipo', ['tipo_id']],
        ['clients', 'ix_cli_partner', ['partner_id']],
        ['invoices', 'ix_inv_cli_estado', ['client_id', 'estado']],
        ['contacts', 'ix_cont_cli', ['client_id']],
        ['deals', 'ix_deal_cli', ['client_id']],
        ['accounting', 'ix_acc_cli', ['client_id']],
        ['projects', 'ix_proj_cli', ['client_id']],
        ['invoice_schedules', 'ix_isch_cli', ['client_id']],
        ['time_entries', 'ix_te_cli', ['client_id']],
    ];
    foreach ($indices as [$tabla, $nombre, $cols]) {
        if (!Esquema::tablaExiste($pdo, $tabla)) continue;
        foreach ($cols as $c) if (!Esquema::columnaExiste($pdo, $tabla, $c)) continue 2;
        if (!$hayPrefijo($tabla, $cols)) Esquema::indice($pdo, $tabla, $nombre, $cols);
    }
};
