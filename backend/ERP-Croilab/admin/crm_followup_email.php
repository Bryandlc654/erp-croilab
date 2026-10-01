<?php
/* CRM v1.3 — Fase 6: endpoint del resumen diario de seguimientos.
   - ?preview=1  → muestra el HTML (para revisar en el navegador)
   - ?send=1&key=CLAVE → genera + registra + intenta enviar (para cron)
   - sin params  → como preview (requiere sesión de admin) */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/lib/crm_followup.php';
ensure_schema();
ensure_crm_schema();

$isCron = (($_GET['send']??'')==='1');

if ($isCron) {
  // Modo cron: protegido por clave en settings (crm_digest_key). Si no hay clave, se rechaza.
  $key = crm_set('crm_digest_key','');
  if ($key==='' || ($_GET['key']??'')!==$key) { http_response_code(403); echo 'forbidden'; exit; }
  $res = crm_fu_send_daily(false);
  header('Content-Type: application/json');
  echo json_encode($res); exit;
}

// Modo vista: requiere sesión de admin.
require_admin();
crm_fu_generate();
$grouped = crm_fu_today(true);
$html = crm_fu_digest_html($grouped);
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Resumen diario · <?= e(marca_agencia()['name']) ?> CRM</title>
<style>body{margin:0;background:#f5f5f7;font-family:Inter,-apple-system,Segoe UI,Arial,sans-serif;padding:34px 16px}
.wrap{background:#fff;border:1px solid rgba(16,19,24,.06);border-radius:18px;padding:32px;max-width:660px;margin:0 auto;box-shadow:0 1px 2px rgba(16,19,24,.03),0 18px 44px -26px rgba(16,19,24,.28)}
.bar{max-width:660px;margin:0 auto 16px;display:flex;align-items:center;gap:10px}
.bar a{font-size:13px;color:#6b7079;text-decoration:none;font-weight:600}.bar a:hover{color:#22262c}.bar .sp{flex:1}
.bar b{font-size:12px;color:var(--label);font-weight:700;letter-spacing:.4px;text-transform:uppercase}</style></head>
<body>
<div class="bar"><a href="automatizaciones.php">← Reporting</a><span class="sp"></span><b>Vista previa del email diario</b></div>
<div class="wrap"><?= $html ?></div>
</body></html>
