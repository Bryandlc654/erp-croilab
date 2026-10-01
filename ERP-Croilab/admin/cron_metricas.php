<?php
/* Actualización DIARIA de métricas del portal desde Search Console + GA4.
   Refuerzo del refresco automático que ya hace el ERP solo (gm_auto_daily en cada
   carga). Útil en el servidor para que se actualice aunque nadie abra el panel.
   Programar en el cron del hosting, una vez al día:  php admin/cron_metricas.php
   Solo por línea de comandos (no por web). Sin Claude ni n8n: PHP puro. */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Solo CLI'); }

require_once __DIR__ . '/../db.php';
ensure_schema();
require_once __DIR__ . '/lib/google_metrics.php';

if (!gm_configurada()) { fwrite(STDERR, "Falta la clave de la cuenta de servicio (Ajustes › Métricas de Google).\n"); exit(1); }

$r = gm_sync_all();   // mes en curso + anterior, todos los clientes con web/propiedad
echo date('c') . ' · métricas: ' . $r['ok'] . ' ok, ' . $r['fail'] . " avisos\n";
foreach ($r['detalle'] as $d) echo '  - ' . $d . "\n";
exit(0);
