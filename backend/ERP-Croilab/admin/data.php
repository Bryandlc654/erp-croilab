<?php
require_once __DIR__ . '/_layout.php';
/* Primera puerta: el permiso datos.avanzado, que exige require_admin(). No se
   pide además require_role('owner') porque eso mira el nombre del rol y no el
   permiso: un rol propio con «Datos avanzados» marcado no podría entrar. */
require_once __DIR__ . '/lib/ajustes_nav.php';
/* Segunda puerta: aunque la sesión sea de un administrador, aquí se vuelve a
   pedir la contraseña. Esta pantalla escribe en la base sin validar nada, y una
   sesión abierta en un ordenador compartido no prueba quién está delante ahora.
   reauth() corta la ejecución si no se confirma: lo de abajo ni se genera. */
require_once __DIR__ . '/lib/reauth.php';
/* Para 0.7.14: el cambio de contraseña de esta pantalla pasa por aquí, que es
   el único sitio del ERP que sabe aplicar la política, el historial y el
   incremento de versión de credenciales. */
require_once __DIR__ . '/lib/credenciales.php';
/* «Bloquear»: cierra la confirmación a mano, para cuando te levantas del sitio.
   Va antes de reauth() para que el siguiente paso ya la pida. */
if (isset($_GET['bloquear'])) { reauth_cerrar('datos'); header('Location: data.php'); exit; }
reauth('datos', 'Datos avanzados');

/* Tablas que se pueden ver/editar desde aquí. 0.7.12: los ajustes y las
   credenciales de cliente NO están, y no por casualidad. Añadirlas aquí metdría
   el api_token de integraciones y la contraseña de acceso del cliente en una
   pantalla de edición en crudo, así que la lista se comprueba abajo y el
   programa se niega a arrancar si alguien las mete. */
$TABLAS = ['clients' => 'Clientes', 'client_types' => 'Tipos de cliente', 'admins' => 'Equipo'];
$TABLAS_NUNCA = ['settings', 'client_credentials', 'password_history', 'audit_log', 'roles', 'admin_sesiones'];
foreach ($TABLAS_NUNCA as $t) {
    if (isset($TABLAS[$t])) {
        http_response_code(500);
        die('data.php: la tabla «' . $t . '» no se puede editar aquí. Quita esa línea de $TABLAS.');
    }
}

$tabla = $_GET['table'] ?? ($_POST['table'] ?? 'clients');
if (!isset($TABLAS[$tabla])) $tabla = 'clients';

/* ---------- QUÉ COLUMNAS SE PUEDEN TOCAR (0.7.1, 0.7.15) ----------

   Antes el bucle de guardado saltaba lo que estuviera en una lista de dos
   elementos (`id` y `created_at`) y aceptaba todo lo demás que llegara en el
   POST. Eso dejaba la pantalla con las puertas abiertas de par en par: el rol
   de un administrador, el hash de contraseña de cualquier cuenta, y el
   api_token si alguien añadía la tabla de ajustes. Una marca de «solo
   lectura» en el formulario no protege nada, porque el campo se puede mandar
   igualmente a mano.

   Ahora la lista es al revés: se declara qué columnas se pueden editar, una por
   tabla, y todo lo demás queda fuera. Lo que no está en la lista no se escribe
   aunque llegue en la petición (0.7.4). Añadir una columna nueva al ERP la deja
   fuera de la edición aquí, que es el fallo que no rompe nada. */
$EDITABLES = [
    'clients' => [
        'username', 'nombre', 'email', 'telefono', 'direccion', 'poblacion', 'cp',
        'provincia', 'pais', 'notas', 'activo', 'tipo_id', 'orden', 'partner_id',
        'login_email', 'fact_nombre', 'fact_nif', 'fact_dir', 'fact_email', 'fact_tel',
        'looker_url', 'informes_json', 'servicios_json',
    ],
    'client_types' => ['nombre', 'descripcion', 'color', 'activo', 'orden'],
    /* El rol NO está: cambiarlo es ascensión de privilegios y se hace en Equipo,
       que valida contra la lista de roles reales. password_hash tampoco: se
       cambia con la casilla de contraseña nueva de más abajo, por el camino
       seguro. 0.7.2 y 0.7.5. */
    'admins' => ['username', 'nombre', 'email', 'activo', 'es_autonomo', 'tarifa_hora', 'iva_pct', 'irpf_pct'],
];

/* Aunque una columna se colara en la lista de editable, esto la para. Es la
   última línea de defensa y no depende de que nadie se acuerde de updating la
   lista de arriba. */
$PROTEGIDAS = [
    'id', 'created_at', 'updated_at', 'password_hash', 'password_history', 'cred_ver',
    'password_changed_at', 'role', 'api_token', 'client_id', 'usuario_id', 'user_id',
];

/* columnas reales de la tabla (de información del sistema = seguro) */
function columnas(PDO $pdo, $tabla) {
    $st = $pdo->prepare(
      "SELECT COLUMN_NAME FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
    $st->execute([$tabla]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}
$cols = columnas(db(), $tabla);

/* Intersección con lo que existe de verdad: una columna del listado que la tabla
   ya no tiene se ignora en silencio en vez de romper el guardado. */
$editables = array_values(array_diff(array_intersect($EDITABLES[$tabla], $cols), $PROTEGIDAS));

/* 0.7.10: el listado no enseña el hash ni nada más que sea credencial. */
$OCULTAS_LISTADO = ['password_hash', 'password_history', 'cred_ver', 'password_changed_at'];

/* Columnas que existen en la tabla pero que esta pantalla no toca, para poder
   decirlo en pantalla en vez de que el usuario descubra que un campo no se
   guarda y piense que es un fallo. */
$noEditables = array_values(array_diff($cols, $editables));

/* ---------- AVISOS QUE CRUZAN UNA REDIRECCIÓN ---------- */
function data_msg($texto, $tipo = 'err') {
    $_SESSION['data_msg'][] = ['t' => $tipo, 'm' => $texto];
}
function data_msgs() {
    $m = $_SESSION['data_msg'] ?? [];
    unset($_SESSION['data_msg']);
    return is_array($m) ? $m : [];
}

/* ---------- 0.7.18: LA CONTRASEÑA, EN EL MOMENTO DE ESCRIBIR ----------

   La ventana de 30 minutos de reauth() sirve para LECTURA: mientras está
   abierta, esta pantalla puede estar abierta en una pestaña que dejaste a medias.
   Si el guardado no volviera a pedir nada, bastaba con que alguien preparara el
   cambio, te lo dejara en pantalla y volviera a pulsar Guardar media hora
   después. Por eso toda escritura vuelve a exigir la contraseña aquí, aunque la
   ventana siga abierta. */
function data_exigir_clave() {
    $pass = (string)($_POST['__reauth'] ?? '');
    if ($pass !== '') {
        $me = current_admin();
        if (password_verify($pass, (string)($me['password_hash'] ?? ''))) return;
        usleep(400000);
        data_msg('La contraseña no es correcta. No se ha guardado nada.');
    } else {
        data_msg('Hay que confirmar la contraseña para escribir aquí.');
    }
    data_pedir_clave();
    exit;
}

/* Pantalla intermedia: pide la contraseña y reenvía la acción tal cual, para no
   tener que volver a escribir el cambio a mano. Lo que NO se copia es la
   contraseña nueva del campo de texto, que volvería a salir por pantalla. */
function data_pedir_clave() {
    $repetir = '';
    foreach ($_POST as $k => $v) {
        if ($k === '__reauth' || $k === '__newpass' || !is_scalar($v)) continue;
        $repetir .= '<input type="hidden" name="' . e((string)$k) . '" value="' . e((string)$v) . '">';
    }
    $huboPass = (string)($_POST['__newpass'] ?? '') !== '';
    aj_head('datos', 'Confirma que eres tú',
        'Volver a escribir en la base de datos pide la contraseña aunque el bloqueo de 30 minutos siga abierto.',
        '<a class="btn ghost sm" href="data.php?bloquear=1">'.ic('vault',15).' Bloquear</a>');
    ?>
    <div class="card" style="max-width:440px;margin:0 auto">
      <div class="sec-t">Confirma tu contraseña para continuar</div>
      <p class="muted" style="margin-bottom:14px">No se ha guardado nada todavía.</p>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <?= $repetir ?>
        <label>Tu contraseña</label>
        <input type="password" name="__reauth" required autocomplete="current-password">
        <?php if ($huboPass): ?>
          <p class="muted" style="margin-top:8px">Tendrás que escribir otra vez la contraseña nueva de la cuenta: no se ha guardado en el formulario a propósito.</p>
        <?php endif; ?>
        <div class="flex" style="margin-top:16px">
          <button class="btn" type="submit"><?= ic('check',15) ?> Continuar</button>
          <a class="btn ghost" href="data.php?table=<?= e($_GET['table'] ?? $_POST['table'] ?? 'clients') ?>">Cancelar</a>
        </div>
      </form>
    </div>
    <?php
    aj_foot();
    exit;
}

/* ---------- 0.7.7: ESCRIBIR EN «admins» ES COSA DEL DUEÑO ----------
   Cualquiera con «datos avanzados» podía tocar la tabla del equipo. Como el rol
   quedaba fuera de la lista editable, no podía ascenderse a sí mismo, pero sí
   podía desactivar cuentas, cambiar correos o mover datos de facturación. Se
   exige acceso total, que es lo mismo que exige el resto de gestión de roles. */
function data_exigir_dueno($tabla) {
    if ($tabla !== 'admins') return;
    if (is_owner()) return;
    if (function_exists('perm_pantalla_denegado')) perm_pantalla_denegado('editar la tabla del equipo');
    http_response_code(403);
    data_msg('Editar el equipo es solo para el dueño de la instalación.');
    header('Location: data.php?table=clients');
    exit;
}

/* ---------- 0.7.9: LA FILA NO HA CAMBIADO DESDE QUE LA ABRISTE ----------
   Una huella de los valores que se están editando. Al guardar se vuelve a
   calcular sobre lo que hay ahora en la base: si no coincide, alguien lo cambió
   por otra vía mientras esta pantalla estaba abierta, y el guardado se para en
   vez de pisarlo en silencio. */
function data_huella(array $fila, array $cols) {
    $p = [];
    foreach ($cols as $c) if (array_key_exists($c, $fila)) $p[$c] = (string)$fila[$c];
    return substr(hash('sha256', (string)json_encode($p)), 0, 32);
}

function data_fila($tabla, $id) {
    $st = db()->prepare("SELECT * FROM `$tabla` WHERE id = ?");
    $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}

/* =====================================================================
   BORRAR FILA —  GET pide confirmación, POST borra
   ===================================================================== */
$confirmarBorrado = (int)($_GET['del'] ?? 0);
if ($confirmarBorrado && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $filaDel = data_fila($tabla, $confirmarBorrado);
    if (!$filaDel) { header("Location: data.php?table=$tabla"); exit; }
    data_exigir_dueno($tabla);
    aj_head('datos', 'Borrar fila',
        'Una fila borrada no se puede recuperar desde aquí.', '');
    ?>
    <div class="card" style="max-width:520px;margin:0 auto">
      <div class="sec-t">¿Borrar la fila #<?= (int)$confirmarBorrado ?> de <?= e($TABLAS[$tabla]) ?>?</div>
      <p class="muted" style="margin-bottom:14px">No se puede deshacer. Si es un cliente, antes se borran sus tareas, credenciales, tickets y proyectos, y se sueltan sus facturas y contactos.</p>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="table" value="<?= e($tabla) ?>">
        <input type="hidden" name="del" value="<?= (int)$confirmarBorrado ?>">
        <label>Tu contraseña, para confirmar</label>
        <input type="password" name="__reauth" required autocomplete="current-password">
        <div class="flex" style="margin-top:16px">
          <button class="btn danger" type="submit"><?= ic('trash',15) ?> Sí, borrar</button>
          <a class="btn ghost" href="data.php?table=<?= e($tabla) ?>">Cancelar</a>
        </div>
      </form>
    </div>
    <?php
    aj_foot();
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['del'])) {
    data_exigir_clave();
    data_exigir_dueno($tabla);
    $delId = (int)$_POST['del'];
    $filaDel = data_fila($tabla, $delId);
    if (!$filaDel) { data_msg('Esa fila ya no está.'); header("Location: data.php?table=$tabla"); exit; }

    /* 0.7.6: ni la propia fila ni la del dueño. Borrarte a ti mismo te deja
       fuera de tu propia sesión y, si eres el único dueño, sin nadie que
       pueda devolver el acceso. Si quedan varios dueños, el dueño puede
       retirar a otro dueño; lo que no puede es quedarse sin ninguno. */
    if ($tabla === 'admins') {
        $meId = (int)(current_admin()['id'] ?? 0);
        if ($delId === $meId) {
            data_msg('No puedes borrar tu propia cuenta desde aquí.');
            header("Location: data.php?table=$tabla"); exit;
        }
        if ((string)($filaDel['role'] ?? '') === 'owner') {
            $otros = (int)db()->query("SELECT COUNT(*) FROM admins WHERE role='owner' AND id <> " . (int)$delId)->fetchColumn();
            if (!is_owner() || $otros < 1) {
                data_msg('No se puede borrar la cuenta del dueño' . ($otros < 1 ? ' porque es el único: el ERP se quedaría sin nadie que lo administre.' : '.'));
                header("Location: data.php?table=$tabla"); exit;
            }
        }
    }

    /* si se borra un cliente desde aquí, se hace la misma limpieza que en delete.php */
    if ($tabla === 'clients') {
        foreach (['tasks','task_lists','client_credentials','time_entries','support_tickets','invoice_schedules','projects'] as $t) {
            try { db()->prepare("DELETE FROM $t WHERE client_id = ?")->execute([$delId]); } catch (Exception $e) { error_log("data.php borrar cliente $delId: fallo al borrar de $t: ".$e->getMessage()); }
        }
        foreach (['accounting','invoices','contacts','deals'] as $t) {
            try { db()->prepare("UPDATE $t SET client_id = NULL WHERE client_id = ?")->execute([$delId]); } catch (Exception $e) { error_log("data.php borrar cliente $delId: fallo al desvincular de $t: ".$e->getMessage()); }
        }
    }
    db()->prepare("DELETE FROM `$tabla` WHERE id = ?")->execute([$delId]);
    audit_log('data.borrar', 'tabla ' . $tabla . ', fila #' . $delId
        . ' (' . e((string)($filaDel['username'] ?? $filaDel['nombre'] ?? $filaDel['id'])) . ')');
    header("Location: data.php?table=$tabla&ok=del");
    exit;
}

/* =====================================================================
   GUARDAR EDICIÓN
   ===================================================================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['__id'])) {
    data_exigir_clave();
    data_exigir_dueno($tabla);
    $rid = (int)$_POST['__id'];

    $fila = data_fila($tabla, $rid);
    if (!$fila) { data_msg('Esa fila ya no está.'); header("Location: data.php?table=$tabla"); exit; }

    /* 0.7.9 */
    $esperado = (string)($_POST['__ver'] ?? '');
    $ahora    = data_huella($fila, $editables);
    if ($esperado === '' || !hash_equals($ahora, $esperado)) {
        data_msg('Esta fila se ha cambiado por otra parte mientras la tenías abierta, así que no se ha guardado nada. Ábrela de nuevo para ver el valor actual.');
        header("Location: data.php?table=$tabla&edit=$rid");
        exit;
    }

    $np = (string)($_POST['__newpass'] ?? '');
    if ($np !== '') {
        if (!in_array('password_hash', $cols, true)) {
            data_msg('Esa tabla no tiene contraseña.'); header("Location: data.php?table=$tabla&edit=$rid"); exit;
        }
        if (!in_array($tabla, credenciales_tablas(), true)) {
            data_msg('Esa tabla no admite contraseñas.'); header("Location: data.php?table=$tabla&edit=$rid"); exit;
        }
        /* 0.7.14: se comprueba ANTES de escribir nada, con las mismas funciones
           que usa credenciales_cambiar(). Si la contraseña fuera inválida, la
           fila no se habría guardado a medias con un error a la mitad. */
        $err = password_valida($np);
        if ($err === '') $err = password_uso_reciente($tabla, $rid, $np);
        if ($err !== '') {
            data_msg($err); header("Location: data.php?table=$tabla&edit=$rid"); exit;
        }
    }

    $sets = []; $params = []; $tocadas = []; $antes = [];
    foreach ($editables as $col) {
        if (!array_key_exists($col, $_POST)) continue;
        $val = $_POST[$col];
        /* tipo_id vacío = NULL */
        if ($col === 'tipo_id' && $val === '') $val = null;
        /* 0.7.13: las columnas con JSON se validan. Antes se escribían como
           texto libre y un error de coma se guardaba tan tranquilo, dejando la
           columna rota para quien la leyera después. */
        if (strpos($col, 'json') !== false && is_string($val) && trim($val) !== '') {
            json_decode($val, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                data_msg('El campo «' . $col . '» no es un JSON válido: ' . json_last_error_msg() . '. No se ha guardado nada.');
                header("Location: data.php?table=$tabla&edit=$rid"); exit;
            }
        }
        /* Lo que no cambia no se escribe: así la auditoría dice la verdad y no
           se generan escrituras que no cambian nada. */
        if ((string)$fila[$col] === (string)$val) continue;
        $sets[] = "`$col` = :$col";
        $params[$col] = $val;
        $tocadas[] = $col;
        $antes[$col] = (string)$fila[$col];
    }

    if ($sets) {
        $params['__rid'] = $rid;
        db()->prepare("UPDATE `$tabla` SET ".implode(', ', $sets)." WHERE id = :__rid")->execute($params);
    }

    if ($np !== '') {
        $res = credenciales_cambiar($tabla, $rid, $np);
        if (!$res['ok']) {
            data_msg('La fila se guardó, pero la contraseña no: ' . $res['msg']);
            header("Location: data.php?table=$tabla&edit=$rid"); exit;
        }
        /* Si te has cambiado la tuya a ti mismo, tu sesión sigue viva con la
           versión nueva; a los demás les cae. */
        if ((int)(current_admin()['id'] ?? 0) === $rid) credenciales_renovar_sesion($tabla, $rid);
        $tocadas[] = 'contraseña';
    }

    /* 0.7.11: qué se tocó y lo que había antes. Sin esto, un cambio de estas
       características no deja rastro de nada más que de que la fila se movió. */
    if ($tocadas) {
        $detalle = 'tabla ' . $tabla . ', fila #' . $rid . ', columnas: ' . implode(', ', $tocadas);
        if ($antes) {
            $viejo = [];
            foreach ($antes as $c => $v) $viejo[] = $c . '=' . (mb_strlen($v) > 60 ? mb_substr($v, 0, 60) . '…' : $v);
            $detalle .= ' | antes: ' . implode('; ', $viejo);
        }
        audit_log('data.editar', $detalle);
    }
    header("Location: data.php?table=$tabla&ok=save");
    exit;
}

/* ---------- ESTADO PARA PINTAR ---------- */
$avisos = data_msgs();
if (($_GET['ok'] ?? '') === 'save') $avisos[] = ['t' => 'ok', 'm' => 'Fila guardada.'];
if (($_GET['ok'] ?? '') === 'del')  $avisos[] = ['t' => 'ok', 'm' => 'Fila eliminada.'];

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$fila = null;
if ($editId) $fila = data_fila($tabla, $editId);

$rows = db()->query("SELECT * FROM `$tabla` ORDER BY id")->fetchAll();
$colsListado = array_values(array_diff($cols, $OCULTAS_LISTADO));
/* Sin título: lo coge de su entrada del menú, para que no digan cosas distintas. */
aj_head('datos', '',
  'Edición directa de las tablas, tipo phpMyAdmin. Solo se pueden tocar las columnas marcadas como editables; el resto se gestiona en su pantalla propia.',
  '<a class="btn ghost sm" href="data.php?bloquear=1" title="Volver a pedir la contraseña">'.ic('vault',15).' Bloquear</a>');
?>
<div class="flex" style="gap:8px;margin-bottom:16px">
  <?php foreach ($TABLAS as $k=>$lbl): ?>
    <a class="btn <?= $k===$tabla?'':'ghost' ?> sm" href="data.php?table=<?= $k ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>
</div>

<?php foreach ($avisos as $a): ?>
  <?php if ($a['t'] === 'ok'): ?><div class="ok-note"><?= e($a['m']) ?></div>
  <?php else: ?><div class="err-note"><?= e($a['m']) ?></div><?php endif; ?>
<?php endforeach; ?>

<?php if (!$fila): ?>
<div class="err-note"><b>Cuidado:</b> esta vista escribe en la base de datos saltándose las validaciones de las pantallas normales, y por eso pide la contraseña en cada guardado. Para el día a día usa <a href="index.php" style="color:inherit;font-weight:700">Clientes</a>, <a href="types.php" style="color:inherit;font-weight:700">Tipos de cliente</a> y <a href="team.php" style="color:inherit;font-weight:700">Equipo</a>, que validan los datos por ti.</div>
<?php endif; ?>

<?php if ($fila): ?>
  <!-- ===== FORMULARIO DE EDICIÓN DE UNA FILA ===== -->
  <div class="card">
    <div class="sec-t">Editar fila #<?= (int)$editId ?> de <?= e($TABLAS[$tabla]) ?></div>
    <form method="post">
      <input type="hidden" name="__id" value="<?= (int)$editId ?>">
      <input type="hidden" name="table" value="<?= e($tabla) ?>">
      <?php /* 0.7.9: la huella de lo que se ve ahora. Sin este campo, un
               guardado sobre una fila que ha cambiado por otra parte la
               pisaría sin avisar. */ ?>
      <input type="hidden" name="__ver" value="<?= e(data_huella($fila, $editables)) ?>">
      <?php foreach ($cols as $col): $editable = in_array($col, $editables, true); ?>
        <label><?= e($col) ?><?= $editable ? '' : ' (no editable aquí)' ?></label>
        <?php if (!$editable): ?>
          <?php /* 0.7.3: el hash ya no viaja en el formulario. Antes era un
                   <input readonly> con name=password_hash: la marca de solo
                   lectura no impedía mandar el campo, y el bucle lo escribía tal
                   cual. Aquí se muestra como texto, sin name, y para cambiarlo
                   está la casilla de más abajo. */ ?>
          <input type="text" value="<?= in_array($col, $OCULTAS_LISTADO, true) ? '————' : e($fila[$col]) ?>" disabled>
        <?php elseif (strpos($col,'json') !== false): ?>
          <textarea name="<?= e($col) ?>" style="font-family:monospace;font-size:12.5px;min-height:90px"><?= e($fila[$col]) ?></textarea>
        <?php else: ?>
          <input type="text" name="<?= e($col) ?>" value="<?= e($fila[$col]) ?>">
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if (in_array('password_hash', $cols, true) && in_array($tabla, credenciales_tablas(), true)): ?>
        <label style="margin-top:14px">Contraseña de esta cuenta</label>
        <input type="text" name="__newpass" value="" placeholder="Déjala vacía para no cambiarla" autocomplete="off">
        <p class="muted" style="margin-top:4px">Se escribe en claro aquí y se cifra al guardar, por el mismo camino que el resto del ERP: con la política de contraseñas, sin poder repetir las últimas y cerrando las sesiones abiertas. Para cambiarla por su cuenta, en Equipo o en la ficha del cliente.</p>
      <?php endif; ?>

      <?php /* La casilla va en TODAS las tablas, no solo en «Equipo»: la pantalla
               deja de escribir sin la contraseña durante los 30 minutos, y ponerla
               solo en una haría que en las otras saliera el aviso de reautenticar
               en cada guardado. El atributo required es solo comodidad; la
               comprobación de verdad está en data_exigir_clave(), que no se fía
               del navegador. */ ?>
      <label style="margin-top:14px">Tu contraseña, para confirmar el cambio</label>
      <input type="password" name="__reauth" autocomplete="current-password" required>

      <div class="flex" style="margin-top:18px">
        <button class="btn" type="submit">Guardar fila</button>
        <a class="btn ghost" href="data.php?table=<?= e($tabla) ?>">Cancelar</a>
      </div>
    </form>
  </div>
<?php else: ?>
  <?php /* 0.7.15: se enseña de qué columnas se trata, para que la lista blanca
           no parezca un fallo. */ ?>
  <?php if ($noEditables): ?>
    <p class="muted" style="margin-bottom:10px">Columnas que existen en la tabla y no se editan aquí: <?= e(implode(', ', $noEditables)) ?>. Se cambian en su pantalla propia.</p>
  <?php endif; ?>
  <!-- ===== LISTADO DE FILAS ===== -->
  <div class="card" style="overflow-x:auto">
  <?php if (!$rows): ?>
    <p class="muted">La tabla está vacía.</p>
  <?php else: ?>
    <table>
      <thead><tr>
        <?php foreach ($colsListado as $col): ?><th><?= e($col) ?></th><?php endforeach; ?>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <?php foreach ($colsListado as $col): $v=(string)$r[$col]; ?>
            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($v) ?>">
              <?= e(mb_strlen($v) > 40 ? mb_substr($v,0,40).'…' : $v) ?>
            </td>
          <?php endforeach; ?>
          <td style="text-align:right;white-space:nowrap">
            <a class="btn ghost sm" href="data.php?table=<?= e($tabla) ?>&edit=<?= (int)$r['id'] ?>">Editar</a>
            <?php /* El borrado pide la contraseña en una pantalla propia en vez de
                     el diálogo de JavaScript: es una acción sin vuelta atrás y
                     el diálogo no tenía dónde meter la casilla. */ ?>
            <a class="btn danger sm" href="data.php?table=<?= e($tabla) ?>&del=<?= (int)$r['id'] ?>">Borrar</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
<?php endif; ?>
<?php aj_foot(); ?>
