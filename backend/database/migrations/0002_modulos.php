<?php
/* Tablas de las librerías de admin/lib (papelera, roles, accesos, marca, CRM,
   facturación, cron…). Cada librería ya sabe crear las suyas con su función
   *_ensure(); aquí se llaman todas una vez, en vez de en cada petición.
   Esas funciones se tragan sus errores, así que al final se comprueba que
   cada tabla exista de verdad. */

use Croilab\Database\Esquema;

return function (PDO $pdo): void {
    $lib = __DIR__ . '/../../admin/lib';
    foreach (['permisos', 'papelera', 'login_throttle', 'credenciales', 'audit', 'marca', 'cron_lib', 'fin_prog', 'crm_lib', 'google_metrics', 'puentes', 'tareas_lib'] as $f) {
        require_once "$lib/$f.php";
    }

    roles_ensure();
    perm_migrar();          // reparte entre los roles los permisos que se han ido añadiendo
    ensure_papelera_schema();
    login_throttle_ensure();
    credenciales_asegurar();
    audit_log('migracion', 'esquema 0002');   // crea audit_log y deja constancia
    marca_ensure();
    cron_ensure();
    prog_ensure();
    fin_counters_ensure();
    ensure_crm_schema();
    crm_meetings_ensure();
    gm_ensure_schema();
    ensure_puentes_schema();

    Esquema::exigirTablas($pdo, [
        'roles', 'trash', 'login_attempts', 'password_history', 'audit_log', 'partner_agencies', 'cron_log',
        'invoices', 'invoice_items', 'invoice_schedules', 'invoice_counters', 'accounting',
        'contacts', 'deals', 'pipeline_stages', 'crm_meetings',
    ]);
};
