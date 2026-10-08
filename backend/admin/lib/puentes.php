<?php
/* ============================================================================
   PUENTES ENTRE MÓDULOS  (Fase 5 — Producto)
   ----------------------------------------------------------------------------
   Por qué existe este archivo:
   el ERP tenía todos los módulos hechos pero ninguno se hablaba con el de al
   lado. Ganabas un negocio en el CRM y tenías que volver a teclear el cliente
   entero en Clientes, aunque sus datos fiscales ya estaban en billing_data.
   Facturabas ese negocio a mano. Y las horas que te pasa un autónomo del equipo
   no aparecían nunca como gasto en Contabilidad.

   Aquí viven esos tres pasos («puentes»), en un solo sitio, para que la lógica
   sea idéntica se llame desde donde se llame (CRM, Negocio, Horas o Cliente).
   Ninguna función imprime nada ni redirige: devuelven datos y un mensaje.

   Convención: todas empiezan por pu_ (puente).
   ============================================================================ */

require_once __DIR__ . '/crm_lib.php';
require_once __DIR__ . '/fin_prog.php';

/* Añade una columna solo si no está. Idéntico patrón al resto del ERP:
   el esquema se va montando solo al abrir las páginas, sin migraciones. */
function pu_col($tabla, $col, $def) {
  try {
    $hayTabla = (int)db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$tabla'")->fetchColumn();
    if (!$hayTabla) return;
    $hay = (int)db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$tabla' AND column_name='$col'")->fetchColumn();
    if (!$hay) db()->exec("ALTER TABLE $tabla ADD COLUMN $col $def");
  } catch (Exception $e) {}
}

/* Las columnas que cosen unos módulos con otros. Se llama al principio de
   cualquier página que use un puente; es idempotente y solo mira una vez. */
function ensure_puentes_schema() { if (croilab_esquema_gestionado()) return;   /* el esquema lo crean las migraciones */
  static $done = false; if ($done) return; $done = true;
  pu_col('contacts',    'client_id',  'INT DEFAULT NULL');   // lead ya convertido -> qué cliente es
  pu_col('clients',     'contact_id', 'INT DEFAULT NULL');   // cliente -> de qué lead salió
  pu_col('deals',       'client_id',  'INT DEFAULT NULL');   // negocio -> cliente creado
  pu_col('deals',       'invoice_id', 'INT DEFAULT NULL');   // negocio -> factura generada
  pu_col('time_entries','acc_id',     'INT DEFAULT NULL');   // horas ya volcadas a un gasto
  pu_col('accounting',  'admin_id',   'INT DEFAULT NULL');   // gasto de equipo -> de quién
  /* partner_id (marca blanca) lo creaba SOLO agencias.php, pero el portal lo lee y el
     puente lead->cliente lo inserta: se garantiza aquí para que exista en todos los
     caminos de creación de cliente, con NULL por defecto (= marca propia) (P2-11). */
  pu_col('clients',     'partner_id', 'INT DEFAULT NULL');
}

/* ---------------------------------------------------------------------------
   1) LEAD / NEGOCIO GANADO  ->  CLIENTE
   --------------------------------------------------------------------------- */

/* Un usuario de portal que se pueda teclear: sin acentos, sin espacios y único.
   Si «cabana» ya existe prueba cabana2, cabana3… en vez de fallar. */
function pu_username($base) {
  $u = strtolower(trim((string)$base));
  $u = strtr($u, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c','à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
  $u = preg_replace('/[^a-z0-9]+/', '', $u);
  if ($u === '') $u = 'cliente';
  $u = substr($u, 0, 40);
  $try = $u; $n = 1;
  try {
    $q = db()->prepare('SELECT COUNT(*) FROM clients WHERE username=?');
    while (true) { $q->execute([$try]); if (!(int)$q->fetchColumn()) break; $n++; $try = $u . $n; if ($n > 200) break; }
  } catch (Exception $e) {}
  return $try;
}

/* Contraseña inicial legible: se enseña UNA vez al convertir y ya no se guarda
   en claro en ningún sitio. El propio ERP la vuelve a generar desde la ficha. */
function pu_password() {
  $letras = 'abcdefghjkmnpqrstuvwxyz';   // sin l/i/o para que no se confundan
  $out = '';
  for ($i = 0; $i < 6; $i++) $out .= $letras[random_int(0, strlen($letras) - 1)];
  return $out . random_int(10, 99);
}

/* Junta las piezas de una dirección en la línea que espera la factura. */
function pu_dir($b) {
  $p = array_filter([$b['direccion'] ?? '', trim(($b['cp'] ?? '') . ' ' . ($b['ciudad'] ?? '')), $b['provincia'] ?? '', $b['pais'] ?? '']);
  return implode(', ', array_map('trim', $p));
}

/* Convierte un contacto del CRM en cliente real del portal.
   Devuelve ['ok'=>bool,'id'=>int,'user'=>string,'pass'=>string,'msg'=>string].
   Si el contacto ya estaba convertido NO duplica: devuelve el cliente existente. */
function pu_lead_a_cliente($contactId, $dealId = 0) {
  ensure_puentes_schema();
  $contactId = (int)$contactId;
  if (!$contactId) return ['ok'=>false, 'msg'=>'Contacto no válido.'];

  try {
    $q = db()->prepare('SELECT * FROM contacts WHERE id=?'); $q->execute([$contactId]); $c = $q->fetch();
  } catch (Exception $e) { $c = null; }
  if (!$c) return ['ok'=>false, 'msg'=>'Ese contacto ya no existe.'];

  /* Ya convertido: no se crea otra ficha, se devuelve la que hay. */
  $yaId = (int)($c['client_id'] ?? 0);
  if ($yaId) {
    $ex = db()->prepare('SELECT id, name FROM clients WHERE id=?'); $ex->execute([$yaId]); $ex = $ex->fetch();
    if ($ex) return ['ok'=>true, 'id'=>(int)$ex['id'], 'user'=>'', 'pass'=>'', 'ya'=>true,
                     'msg'=>'Este contacto ya era el cliente «' . $ex['name'] . '».'];
  }

  $bq = db()->prepare('SELECT * FROM billing_data WHERE contact_id=?'); $bq->execute([$contactId]);
  $b = $bq->fetch() ?: [];

  /* El nombre del cliente es el de la empresa; el del contacto es la persona. */
  $nombre = trim((string)($c['empresa'] ?? '')) ?: trim((string)$c['nombre']);
  $razon  = trim((string)($b['razon_social'] ?? '')) ?: $nombre;
  $saludo = trim(explode(' ', trim((string)$c['nombre']))[0] ?? '');
  $ini    = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}]/u', '', $nombre), 0, 2)) ?: 'CL';

  $user = pu_username($nombre);
  $pass = pu_password();

  /* Los servicios que ya tenía apuntados el lead pasan tal cual a la ficha. */
  $svc = json_decode((string)($c['servicio_json'] ?? ''), true);
  if (!is_array($svc)) $svc = [];

  $campos = [
    'username'      => $user,
    'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
    'name'          => $nombre,
    'iniciales'     => $ini,
    'saludo'        => $saludo,
    'conversiones'  => 1,
    'actual'        => '',
    'contact_id'    => $contactId,
    /* Un lead recién convertido aún no tiene tipo de cliente ni agencia de marca
       blanca: se dejan explícitos en NULL (= «sin tipo» / marca propia). Se
       asignan luego desde Tipos de cliente y Agencias. (P1-08 / relacionado P2-11) */
    'tipo_id'       => null,
    'partner_id'    => null,
    'fact_nombre'   => $razon,
    'fact_nif'      => trim((string)($b['cif'] ?? '')),
    'fact_dir'      => pu_dir($b),
    'fact_email'    => trim((string)($b['email_facturacion'] ?? '')) ?: trim((string)($c['email'] ?? '')),
    'fact_tel'      => trim((string)($c['telefono'] ?? '')),
    'activo'        => 1,
    'servicios_json'=> json_encode($svc, JSON_UNESCAPED_UNICODE),
  ];

  /* Todo el puente va en UNA transacción: crear el cliente, sus cuatro listas y
     coser las fichas es un solo acto. Antes iba suelto y un fallo a mitad dejaba
     un cliente sin listas o un contacto medio enlazado (P1-08). */
  $pdo = db();
  try {
    /* db_tx_* y no beginTransaction() a pelo: este puente puede acabar llamado
       desde un flujo que ya tenga una transacción abierta (por ejemplo el
       alta desde la web del cliente), y en ese caso beginTransaction()
       reventaría con «There is already an active transaction». Los helpers
       anidan con savepoints, así que en ambos casos se confirma o se
       deshace lo que toca. */
    db_tx_begin($pdo);
    $cols = implode(', ', array_keys($campos));
    $ph   = implode(', ', array_map(fn($k) => ":$k", array_keys($campos)));
    $pdo->prepare("INSERT INTO clients ($cols) VALUES ($ph)")->execute($campos);
    $newId = (int)$pdo->lastInsertId();

    /* Mismas listas por defecto que al dar de alta a mano en Clientes › Nuevo.
       INFORMES CLIENTE es la que alimenta el informe del portal. */
    $il = $pdo->prepare('INSERT INTO task_lists (client_id, nombre, es_cliente, tipo, orden) VALUES (?,?,?,?,?)');
    $il->execute([$newId, 'TAREAS', 0, 'tareas', 0]);
    $il->execute([$newId, 'ESTRATEGIA', 0, 'tareas', 1]);
    $il->execute([$newId, 'TAREA CLIENTE', 0, 'tareas', 2]);
    $il->execute([$newId, 'INFORMES CLIENTE', 0, 'informe', 3]);

    /* Cose las dos fichas en los dos sentidos + marca los negocios del contacto. */
    $pdo->prepare('UPDATE contacts SET client_id=? WHERE id=?')->execute([$newId, $contactId]);
    $pdo->prepare('UPDATE deals SET client_id=? WHERE contact_id=?')->execute([$newId, $contactId]);

    db_tx_commit($pdo);
  } catch (Exception $e) {
    db_tx_rollback($pdo);
    error_log('pu_lead_a_cliente: '.$e->getMessage());
    return ['ok'=>false, 'msg'=>'No se ha podido crear el cliente: ' . $e->getMessage()];
  }

  if (function_exists('crm_activity')) crm_activity($contactId, (int)$dealId ?: null, 'cliente', 'Convertido en cliente: ' . $nombre);

  return ['ok'=>true, 'id'=>$newId, 'user'=>$user, 'pass'=>$pass, 'ya'=>false,
          'msg'=>'Cliente «' . $nombre . '» creado.'];
}

/* ---------------------------------------------------------------------------
   2) NEGOCIO GANADO  ->  FACTURA
   ---------------------------------------------------------------------------
   Aquí ya NO se crea ninguna factura. Antes se numeraba un borrador con
   fin_next_numero() desde el CRM (consumía numeración de la serie). Ahora la
   hace Finanzas: el CRM navega a /finanzas/facturas/nueva?negocio=<id>,
   Finanzas prellena el borrador (GET /v1/finanzas/facturas/desde-negocio/{id})
   y guarda el vínculo en invoices.deal_id. Esta función queda solo por si algún
   código antiguo la llamara: devuelve la factura ya vinculada, si la hay, y
   nunca escribe. El código nuevo no la usa. */
function pu_negocio_a_factura($dealId) {
  $dealId = (int)$dealId;
  if (!$dealId) return ['ok'=>false, 'msg'=>'Negocio no válido.'];
  try {
    $q = db()->prepare('SELECT id, numero FROM invoices WHERE deal_id=? ORDER BY id DESC LIMIT 1');
    $q->execute([$dealId]);
    if ($f = $q->fetch()) return ['ok'=>true, 'id'=>(int)$f['id'], 'ya'=>true, 'msg'=>'Este negocio ya tiene la factura ' . $f['numero'] . '.'];
  } catch (Exception $e) {}
  return ['ok'=>false, 'msg'=>'Las facturas de un negocio se crean desde Finanzas (/finanzas/facturas/nueva?negocio=' . $dealId . ').'];
}

/* ---------------------------------------------------------------------------
   3) HORAS DEL EQUIPO  ->  GASTO EN CONTABILIDAD
   --------------------------------------------------------------------------- */

/* Lo que un autónomo del equipo factura por sus horas es un gasto de la agencia,
   pero hasta ahora se quedaba solo en la pantalla de Horas. Esta función coge
   las horas de una persona en un rango de fechas que aún no se hayan volcado y
   apunta UN gasto con el total. Las horas quedan marcadas para no repetirlo. */
function pu_horas_a_gasto($adminId, $ini, $fin) {
  ensure_puentes_schema();
  if (function_exists('ensure_time_schema')) ensure_time_schema();
  $adminId = (int)$adminId;
  if (!$adminId) return ['ok'=>false, 'msg'=>'Persona no válida.'];
  $ini = $ini ?: date('Y-m-01');
  $fin = $fin ?: date('Y-m-t');

  try {
    $a = db()->prepare('SELECT id,username,es_autonomo,tarifa_hora FROM admins WHERE id=?'); $a->execute([$adminId]); $a = $a->fetch();
  } catch (Exception $e) { $a = null; }
  if (!$a) return ['ok'=>false, 'msg'=>'Esa persona ya no está en el equipo.'];

  try {
    $q = db()->prepare('SELECT id, minutos, importe, client_id FROM time_entries WHERE admin_id=? AND fecha BETWEEN ? AND ? AND (acc_id IS NULL OR acc_id=0)');
    $q->execute([$adminId, $ini, $fin]);
    $filas = $q->fetchAll();
  } catch (Exception $e) { $filas = []; }
  if (!$filas) return ['ok'=>false, 'msg'=>'No hay horas pendientes de volcar en ese periodo.'];

  /* Si la hora no traía importe se calcula con la tarifa de la persona. */
  $tarifa = (float)($a['tarifa_hora'] ?? 0);
  $total = 0; $minutos = 0; $ids = []; $clientes = [];
  foreach ($filas as $r) {
    $minutos += (int)$r['minutos'];
    $imp = $r['importe'] !== null ? (float)$r['importe'] : ((int)$r['minutos'] / 60) * $tarifa;
    $total += $imp;
    $ids[] = (int)$r['id'];
    if (!empty($r['client_id'])) $clientes[(int)$r['client_id']] = true;
  }
  /* Si TODAS las horas eran del mismo cliente, el gasto se imputa a ese cliente para
     que la rentabilidad por cliente cuadre; si van mezcladas, queda sin imputar
     (NULL) porque un gasto no puede repartirse entre varios (P2-12). Las horas
     quedan enlazadas por `acc_id` y el apunte lleva `admin_id`, así que el origen
     siempre es localizable. */
  $clienteGasto = (count($clientes) === 1) ? (int)array_key_first($clientes) : null;
  if ($total <= 0) return ['ok'=>false, 'msg'=>'Esas horas suman 0 €: revisa la tarifa por hora de ' . $a['username'] . ' en Equipo.'];

  $horasTxt = number_format($minutos / 60, 2, ',', '.');
  $concepto = 'Horas ' . $a['username'] . ' · ' . date('d/m/Y', strtotime($ini)) . ' – ' . date('d/m/Y', strtotime($fin));

  try {
    db()->prepare('INSERT INTO accounting (fecha,tipo,concepto,categoria,importe,metodo,legal,ambito,deducible,client_id,notas,admin_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$fin, 'gasto', $concepto, 'Equipo', round($total, 2), 'transferencia', 1, 'empresa', 1, $clienteGasto,
                 $horasTxt . ' h del equipo', $adminId]);
    $accId = (int)db()->lastInsertId();
  } catch (Exception $e) {
    return ['ok'=>false, 'msg'=>'No se ha podido apuntar el gasto: ' . $e->getMessage()];
  }

  try {
    $in = implode(',', array_map('intval', $ids));
    db()->exec("UPDATE time_entries SET acc_id=$accId WHERE id IN ($in)");
  } catch (Exception $e) {}

  return ['ok'=>true, 'id'=>$accId, 'importe'=>round($total, 2), 'horas'=>$horasTxt,
          'msg'=>'Gasto de ' . number_format($total, 2, ',', '.') . ' € apuntado (' . $horasTxt . ' h).'];
}
