<?php
/* Cron de verdad.

   Hasta ahora todo lo automático del ERP dependía de que alguien abriera una
   página: los seguimientos del CRM se generaban al entrar en Automatizaciones,
   los avisos de leads y facturas se refrescaban cada 2 minutos por sesión, y
   las facturas recurrentes se emitían cuando alguien pasaba por Programadas.
   Si nadie entraba un lunes, el lunes no pasaba nada.

   Este archivo es el único punto de entrada para que el servidor lo haga solo.
   Se puede llamar de dos maneras:

     1. Por línea de comandos (lo recomendable en Hostinger):
            php /ruta/al/portal-cliente/admin/cron.php
     2. Por URL, con la clave secreta que se ve en Automatizaciones:
            https://tu-dominio/portal-cliente/admin/cron.php?key=XXXX

   La clave existe para que la URL no la pueda llamar cualquiera. Se genera sola
   la primera vez y se puede regenerar desde la pantalla de Automatizaciones.

   Cada tarea se ejecuta dentro de su propio try: si una falla, las demás siguen.
   Todo queda apuntado en la tabla `cron_log`, que es lo que lee la interfaz para
   decir «última ejecución: hace 20 minutos». */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/cron_lib.php';
require_once __DIR__ . '/lib/crm_followup.php';
require_once __DIR__ . '/lib/fin_prog.php';
require_once __DIR__ . '/lib/papelera.php';

$esCli = (PHP_SAPI === 'cli');

/* ---------- quién puede lanzarlo ---------- */
if (!$esCli) {
    $dada = (string)($_GET['key'] ?? '');
    $buena = cron_key();
    /* hash_equals para que no se pueda adivinar la clave midiendo tiempos. */
    if ($dada === '' || !hash_equals($buena, $dada)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Clave de cron incorrecta.\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

/* ---------- el ciclo ---------- */
$origen = $esCli ? 'cli' : 'url';
$t0 = microtime(true);
$lineas = [];

/* Cada entrada: [clave del interruptor en Ajustes, nombre, función que devuelve
   un texto corto con lo que ha hecho]. auto_on() devuelve true por defecto, así
   que una automatización nueva funciona sin tener que activarla a mano. */
$tareas = [
    ['invoice_recurring', 'Facturas recurrentes', function () {
        if (!function_exists('prog_run')) return 'sin módulo';
        $n = (int)prog_run();   /* prog_run() devuelve cuántas ha emitido */
        return $n ? $n . ' factura(s) emitida(s)' : 'nada que emitir';
    }],
    ['followups', 'Seguimientos del CRM', function () {
        crm_fu_generate();
        $g = crm_fu_today(true); $n = 0; foreach ($g as $x) $n += count($x);
        return $n . ' acción(es) para hoy';
    }],
    ['daily_digest', 'Resumen diario', function () {
        $r = crm_fu_send_daily(false);
        return ($r['reason'] ?? '?') . (isset($r['acciones']) ? ' · ' . (int)$r['acciones'] . ' acciones' : '');
    }],
    ['lead_reminder', 'Avisos de leads', function () {
        notif_sync_leads(); return 'revisado';
    }],
    ['invoice_due', 'Avisos de facturas', function () {
        notif_sync_invoices(); return 'revisado';
    }],
    ['monthly_report', 'Aviso de informe mensual', function () {
        /* Solo los cinco primeros días del mes: pasado eso el aviso ya no sirve
           de recordatorio, solo molesta. El `ref` con el mes dentro hace que
           sea uno por persona y mes, no uno por ejecución del cron. */
        if ((int)date('d') > 5) return 'fuera de fecha';
        $n = 0;
        foreach (db()->query('SELECT id FROM admins')->fetchAll(PDO::FETCH_COLUMN) as $ad) {
            notif_add((int)$ad, 'info', 'Prepara los informes del mes',
                'Es principio de mes: revisa y envía los informes de tus clientes.',
                'workspace.php?view=all', 'report:' . date('Y-m'));
            $n++;
        }
        return $n . ' persona(s) avisada(s)';
    }],
    ['trash_purge', 'Limpieza de la papelera', function () {
        pap_purga(); return 'papelera al día';
    }],
    ['uploads_sweep', 'Archivos sin uso', function () {
        /* Como mucho una vez al día: recorre toda la base y no hace falta más. */
        $ult = (int)get_setting('huerfanos_ultima', '0');
        if ($ult && (time() - $ult) < 86400) return 'ya se revisó hoy';
        $r = pap_limpiar_archivos_huerfanos();
        /* Si no se pudo comprobar, queda en rojo en el registro y se reintenta en el siguiente ciclo. */
        if (!empty($r['error'])) throw new RuntimeException(pap_huerfanos_resumen($r));
        db()->prepare('INSERT INTO settings (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')
            ->execute(['huerfanos_ultima', (string)time()]);
        return pap_huerfanos_resumen($r);
    }],
];

foreach ($tareas as [$clave, $nombre, $fn]) {
    if (!auto_on($clave)) { $lineas[] = '– ' . $nombre . ': desactivada en Ajustes'; continue; }
    $t = microtime(true);
    try {
        $det = (string)$fn();
        $ms = (int)round((microtime(true) - $t) * 1000);
        cron_apunta($clave, true, $det, $ms, $origen);
        $lineas[] = '✓ ' . $nombre . ': ' . $det . ' (' . $ms . ' ms)';
    } catch (Throwable $e) {
        $ms = (int)round((microtime(true) - $t) * 1000);
        cron_apunta($clave, false, $e->getMessage(), $ms, $origen);
        $lineas[] = '✗ ' . $nombre . ': ' . $e->getMessage();
    }
}

$msTot = (int)round((microtime(true) - $t0) * 1000);
cron_apunta('ciclo', true, count($tareas) . ' tareas', $msTot, $origen);

echo marca_agencia()['name'] . " ERP · cron " . date('d/m/Y H:i:s') . " (" . $origen . ")\n";
echo implode("\n", $lineas) . "\n";
echo "Total: {$msTot} ms\n";
