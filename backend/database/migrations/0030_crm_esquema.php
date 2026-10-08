<?php
/* CRM: lo que la API nueva necesita además de las tablas que ya crea
   ensure_crm_schema() (migración 0002).

   · lists.orden: las listas del menú lateral se reordenan arrastrando.
   · follow_up_tasks.fecha_hecha: «hechos hoy» contaba por la fecha prevista
     (no existía la fecha en que se hizo).
   · Índices para los filtros de Contactos (próxima acción, alta, correo y
     teléfono para avisar de duplicados al importar) y para los seguimientos.
   · Vistas guardadas: el ERP antiguo guardaba el query string tal cual
     («?sector=Salud&quick=act7»); se pasan a objeto JSON.
   · Fases: si la tabla está vacía (la semilla de ensure_crm_schema() falló),
     se siembran las nueve de siempre. */

use Croilab\Database\Esquema;

return function (PDO $pdo): void {
    Esquema::exigirTablas($pdo, ['contacts', 'deals', 'pipeline_stages', 'lists', 'list_members', 'comments', 'activities',
        'proposals', 'attachments', 'crm_tags', 'contact_tags', 'deal_tags', 'saved_views', 'follow_up_tasks', 'email_log', 'billing_data']);

    Esquema::columnas($pdo, 'lists', ['orden' => 'INT NOT NULL DEFAULT 0']);
    Esquema::columnas($pdo, 'follow_up_tasks', ['fecha_hecha' => 'DATE NULL']);

    Esquema::indice($pdo, 'contacts', 'ix_cont_prox', ['fecha_prox']);
    Esquema::indice($pdo, 'contacts', 'ix_cont_alta', ['fecha_creacion']);
    Esquema::indice($pdo, 'contacts', 'ix_cont_email', ['email']);
    Esquema::indice($pdo, 'contacts', 'ix_cont_tel', ['telefono']);
    Esquema::indice($pdo, 'deals', 'ix_deal_cont_arch', ['contact_id', 'archivado']);
    Esquema::indice($pdo, 'follow_up_tasks', 'ix_fu_estado_fecha', ['estado', 'fecha_prevista']);
    Esquema::indice($pdo, 'follow_up_tasks', 'ix_fu_deal', ['deal_id']);
    Esquema::indice($pdo, 'activities', 'ix_act_cont_fecha', ['contact_id', 'fecha']);
    Esquema::indice($pdo, 'saved_views', 'ix_sv_mod_usr', ['modulo', 'usuario_id']);

    /* Vistas guardadas del antiguo: query string → objeto. Solo las que no son ya JSON. */
    $up = $pdo->prepare('UPDATE saved_views SET filtros = ? WHERE id = ?');
    foreach ($pdo->query("SELECT id, filtros FROM saved_views WHERE modulo = 'crm'")->fetchAll() as $v) {
        $f = trim((string)$v['filtros']);
        if ($f === '' || $f[0] === '{') continue;
        parse_str(ltrim($f, '?'), $q);
        $limpio = [];
        foreach (['q', 'sector', 'origen', 'fase', 'servicio', 'prop', 'tag', 'vmin', 'vmax', 'fdesde', 'fhasta', 'quick', 'sort', 'dir'] as $k) {
            if (isset($q[$k]) && is_string($q[$k]) && trim($q[$k]) !== '') $limpio[$k] = trim($q[$k]);
        }
        $up->execute([json_encode((object)$limpio, JSON_UNESCAPED_UNICODE), (int)$v['id']]);
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM pipeline_stages')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO pipeline_stages (nombre, slug, orden, probabilidad, tipo, color) VALUES (?,?,?,?,?,?)');
        foreach ([
            ['Lead nuevo', 'lead_nuevo', 1, 5, 'abierta', '#64748b'],
            ['Onboarding', 'onboarding', 2, 15, 'abierta', '#2563eb'],
            ['Onboarding hecho', 'onboarding_hecho', 3, 30, 'abierta', '#1d4ed8'],
            ['Propuesta enviada', 'propuesta', 4, 50, 'abierta', '#a16207'],
            ['Negociación', 'negociacion', 5, 70, 'abierta', '#c2410c'],
            ['Contrato firmado', 'contrato', 6, 90, 'abierta', '#0f7a3d'],
            ['Cerrado ganado', 'ganado', 7, 100, 'ganada', '#047857'],
            ['Cerrado perdido', 'perdido', 8, 0, 'perdida', '#b91c1c'],
            ['En pausa', 'pausa', 9, 0, 'pausa', '#64748b'],
        ] as $f) $ins->execute($f);
    }

    /* Listas existentes: el orden del menú era «activas primero, por nombre». */
    if ((int)$pdo->query('SELECT COUNT(*) FROM lists WHERE orden <> 0')->fetchColumn() === 0) {
        $n = 0;
        $up = $pdo->prepare('UPDATE lists SET orden = ? WHERE id = ?');
        foreach ($pdo->query("SELECT id FROM lists ORDER BY tipo DESC, nombre")->fetchAll(PDO::FETCH_COLUMN) as $id) $up->execute([++$n, (int)$id]);
    }
};
