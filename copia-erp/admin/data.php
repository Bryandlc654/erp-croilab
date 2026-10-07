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
/* «Bloquear»: cierra la confirmación a mano, para cuando te levantas del sitio.
   Va antes de reauth() para que el siguiente paso ya la pida. */
if (isset($_GET['bloquear'])) { reauth_cerrar('datos'); header('Location: data.php'); exit; }
reauth('datos', 'Datos avanzados');

/* tablas que se pueden ver/editar desde aquí */
$TABLAS = ['clients'=>'Clientes', 'client_types'=>'Tipos de cliente', 'admins'=>'Equipo'];

$tabla = $_GET['table'] ?? 'clients';
if (!isset($TABLAS[$tabla])) $tabla = 'clients';

/* columnas reales de la tabla (de información del sistema = seguro) */
function columnas(PDO $pdo, $tabla) {
    $st = $pdo->prepare(
      "SELECT COLUMN_NAME FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
    $st->execute([$tabla]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}
$cols = columnas(db(), $tabla);
$noEditables = ['id','created_at'];          // no se tocan
$flash = '';

/* ---------- BORRAR FILA (solo POST + CSRF, lo comprueba auth.php) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['del'])) {
    $delId = (int)$_POST['del'];
    /* si se borra un cliente desde aquí, se hace la misma limpieza que en delete.php */
    if ($tabla === 'clients' && $delId) {
        foreach (['tasks','task_lists','client_credentials','time_entries','support_tickets','invoice_schedules','projects'] as $t) {
            try { db()->prepare("DELETE FROM $t WHERE client_id = ?")->execute([$delId]); } catch (Exception $e) { error_log("data.php borrar cliente $delId: fallo al borrar de $t: ".$e->getMessage()); }
        }
        foreach (['accounting','invoices','contacts','deals'] as $t) {
            try { db()->prepare("UPDATE $t SET client_id = NULL WHERE client_id = ?")->execute([$delId]); } catch (Exception $e) { error_log("data.php borrar cliente $delId: fallo al desvincular de $t: ".$e->getMessage()); }
        }
    }
    db()->prepare("DELETE FROM `$tabla` WHERE id = ?")->execute([$delId]);
    header("Location: data.php?table=$tabla&ok=del");
    exit;
}

/* ---------- GUARDAR EDICIÓN ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__id'])) {
    $rid = (int)$_POST['__id'];
    $sets = []; $params = [];
    foreach ($cols as $col) {
        if (in_array($col, $noEditables, true)) continue;
        if (!array_key_exists($col, $_POST)) continue;
        $val = $_POST[$col];
        // tipo_id vacío = NULL
        if ($col === 'tipo_id' && $val === '') $val = null;
        $sets[] = "`$col` = :$col";
        $params[$col] = $val;
    }
    /* Cambiar la contraseña se hacía en una página aparte («Generar clave») que solo
       devolvía el código cifrado para copiarlo y pegarlo a mano en este campo. Era el
       único sitio del ERP donde había que hacer un viaje de ida y vuelta para algo
       que se puede cifrar aquí mismo al guardar. */
    $np = (string)($_POST['__newpass'] ?? '');
    if ($np !== '' && in_array('password_hash', $cols, true) && !in_array('password_hash', $noEditables, true)) {
        if (!array_key_exists('password_hash', $params)) $sets[] = '`password_hash` = :password_hash';
        $params['password_hash'] = password_hash($np, PASSWORD_DEFAULT);
    }
    if ($sets) {
        $params['__rid'] = $rid;
        db()->prepare("UPDATE `$tabla` SET ".implode(', ', $sets)." WHERE id = :__rid")->execute($params);
    }
    header("Location: data.php?table=$tabla&ok=save");
    exit;
}

if (($_GET['ok'] ?? '')==='save') $flash = 'Fila guardada.';
if (($_GET['ok'] ?? '')==='del')  $flash = 'Fila eliminada.';

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$fila = null;
if ($editId) {
    $st = db()->prepare("SELECT * FROM `$tabla` WHERE id = ?");
    $st->execute([$editId]);
    $fila = $st->fetch();
}

$rows = db()->query("SELECT * FROM `$tabla` ORDER BY id")->fetchAll();
/* Sin título: lo coge de su entrada del menú, para que no digan cosas distintas. */
aj_head('datos', '',
  'Edición directa de las tablas, tipo phpMyAdmin. Lo que cambies se guarda tal cual, sin comprobar nada.',
  '<a class="btn ghost sm" href="data.php?bloquear=1" title="Volver a pedir la contraseña">'.ic('vault',15).' Bloquear</a>');
?>
<div class="flex" style="gap:8px;margin-bottom:16px">
  <?php foreach ($TABLAS as $k=>$lbl): ?>
    <a class="btn <?= $k===$tabla?'':'ghost' ?> sm" href="data.php?table=<?= $k ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>
</div>

<?php /* El aviso de guardado y el de peligro eran dos tarjetas con colores escritos
         a mano aquí (#cfe9d6 verde, #fdf3f3 rojo) que no coinciden con los de
         ninguna otra pantalla. Se usan .ok-note y .err-note, que están en
         erp_nav.php y son los mismos en todo el ERP. Y el aviso de peligro
         estaba DEBAJO de la tabla, es decir, después de haber borrado algo:
         ahora se lee antes de tocar nada. */ ?>
<?php if ($flash): ?><div class="ok-note"><?= e($flash) ?></div><?php endif; ?>

<?php if (!$fila): ?>
<div class="err-note"><b>Cuidado:</b> esta vista escribe en la base de datos tal cual, sin comprobar nada. Para el día a día usa <a href="index.php" style="color:inherit;font-weight:700">Clientes</a>, <a href="types.php" style="color:inherit;font-weight:700">Tipos de cliente</a> y <a href="team.php" style="color:inherit;font-weight:700">Equipo</a>, que validan los datos por ti.</div>
<?php endif; ?>

<?php if ($fila): ?>
  <!-- ===== FORMULARIO DE EDICIÓN DE UNA FILA ===== -->
  <div class="card">
    <div class="sec-t">Editar fila #<?= (int)$editId ?> de <?= e($TABLAS[$tabla]) ?></div>
    <form method="post">
      <input type="hidden" name="__id" value="<?= (int)$editId ?>">
      <?php foreach ($cols as $col): ?>
        <label><?= e($col) ?><?= in_array($col,$noEditables,true)?' (no editable)':'' ?></label>
        <?php if (in_array($col,$noEditables,true)): ?>
          <input type="text" value="<?= e($fila[$col]) ?>" disabled>
        <?php elseif (strpos($col,'json') !== false): ?>
          <textarea name="<?= e($col) ?>" style="font-family:monospace;font-size:12.5px;min-height:90px"><?= e($fila[$col]) ?></textarea>
        <?php elseif ($col==='password_hash'): ?>
          <input type="text" name="<?= e($col) ?>" value="<?= e($fila[$col]) ?>" style="font-family:monospace;font-size:12px" readonly>
          <label style="margin-top:10px">Nueva contraseña</label>
          <input type="text" name="__newpass" value="" placeholder="Déjalo vacío para no cambiarla" autocomplete="off">
          <p class="muted" style="margin-top:4px">Escribe la contraseña en claro y se cifra sola al guardar. El código de arriba es el cifrado actual y no se escribe a mano.</p>
        <?php else: ?>
          <input type="text" name="<?= e($col) ?>" value="<?= e($fila[$col]) ?>">
        <?php endif; ?>
      <?php endforeach; ?>
      <div class="flex" style="margin-top:18px">
        <button class="btn" type="submit">Guardar fila</button>
        <a class="btn ghost" href="data.php?table=<?= $tabla ?>">Cancelar</a>
      </div>
    </form>
  </div>
<?php else: ?>
  <!-- ===== LISTADO DE FILAS ===== -->
  <div class="card" style="overflow-x:auto">
  <?php if (!$rows): ?>
    <p class="muted">La tabla está vacía.</p>
  <?php else: ?>
    <table>
      <thead><tr>
        <?php foreach ($cols as $col): ?><th><?= e($col) ?></th><?php endforeach; ?>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <?php foreach ($cols as $col): $v=(string)$r[$col]; ?>
            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($v) ?>">
              <?= e(mb_strlen($v) > 40 ? mb_substr($v,0,40).'…' : $v) ?>
            </td>
          <?php endforeach; ?>
          <td style="text-align:right;white-space:nowrap">
            <a class="btn ghost sm" href="data.php?table=<?= $tabla ?>&edit=<?= (int)$r['id'] ?>">Editar</a>
            <a class="btn danger sm" href="#" onclick="return erpAsk('¿Borrar la fila #<?= (int)$r['id'] ?> de <?= e($TABLAS[$tabla]) ?>? No se puede deshacer.',{post:'data.php?table=<?= e($tabla) ?>',data:{del:<?= (int)$r['id'] ?>},danger:true})">Borrar</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
<?php endif; ?>
<?php aj_foot(); ?>
