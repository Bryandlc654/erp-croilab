<?php
/* Layout común del admin — ahora usa el armazón único del ERP
   (rail negro + barra clara). ahead()/afoot() se mantienen para no
   tocar el resto de páginas. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';

function ahead($title) { erp_head(erp_active_for(), $title); }
function afoot() { erp_foot(); }
