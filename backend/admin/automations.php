<?php
/* Redirección: las reglas automáticas del ERP ahora viven dentro de Ajustes.
   Esta página era una entrada más de Herramientas llamada «Reglas automáticas»,
   a un clic de la de «Automatizaciones» del CRM y sin ninguna relación con ella.
   Se queda solo el reenvío para que no se rompa ningún enlace guardado. */
require_once __DIR__ . '/../auth.php';
require_admin();
header('Location: settings.php?tab=reglas');
exit;
