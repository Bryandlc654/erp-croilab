<?php
/* Alta y edición de un tipo de cliente: qué secciones ve en su portal.

   Antes era una tarjeta estrecha, fuera del marco de Ajustes (o sea, sin el menú
   de la izquierda) y con seis casillas sueltas en columna que solo decían el
   nombre de la sección. Ninguna explicaba qué es «Método» o qué se pierde el
   cliente al quitarle «Progreso», que es justo lo que hay que saber para marcar
   o desmarcar con criterio. */
require_once __DIR__ . '/../auth.php';
require_admin();
require_can_edit();

/* Alta rápida por AJAX desde OTROS sitios (p. ej. la ficha del cliente en edit.php):
   crea un tipo con todas las secciones activadas y devuelve su id+nombre. El CSRF y el
   permiso (tipos.editar) ya los ha exigido auth.php + require_admin arriba. Se puede
   afinar luego en la pantalla completa. */
if (($_GET['ajax'] ?? '') === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $nombre = trim($_POST['nombre'] ?? '');
    if ($nombre === '') { echo json_encode(['ok'=>false,'msg'=>'Pon un nombre al tipo.']); exit; }
    /* No repetir un nombre que ya existe: se devuelve el existente. */
    $q = db()->prepare('SELECT id FROM client_types WHERE nombre = ? LIMIT 1'); $q->execute([$nombre]);
    if ($ya = $q->fetchColumn()) { echo json_encode(['ok'=>true,'id'=>(int)$ya,'nombre'=>$nombre,'dup'=>true]); exit; }
    $sec = ['metricas'=>1,'progreso'=>1,'informes'=>1,'como'=>1,'accesos'=>1,'plan'=>1];
    db()->prepare('INSERT INTO client_types (nombre, secciones_json) VALUES (?, ?)')
        ->execute([$nombre, json_encode($sec, JSON_UNESCAPED_UNICODE)]);
    echo json_encode(['ok'=>true,'id'=>(int)db()->lastInsertId(),'nombre'=>$nombre]); exit;
}

/* Cada sección con lo que el cliente ve dentro. La clave es la que se guarda en
   client_types.secciones_json y la lee portal-cliente/index.php. */
$SECCIONES = [
  'metricas' => ['Métricas',  'chart',  'Llamadas, WhatsApps, formularios y visitas, mes a mes.'],
  'progreso' => ['Progreso',  'trend',  'Las fases del trabajo y en cuál va ahora mismo.'],
  'informes' => ['Informes',  'file',   'Los informes mensuales que le subes.'],
  'como'     => ['Método',    'layers', 'Cómo trabajáis, con el vídeo de presentación.'],
  'accesos'  => ['Accesos',   'vault',  'Las claves y enlaces que le has dejado preparados.'],
  'plan'     => ['Plan',      'list',   'Qué incluye lo que tiene contratado.'],
];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];
$t = ['nombre'=>'', 'sec'=>['metricas'=>1,'progreso'=>1,'informes'=>1,'como'=>1,'accesos'=>1,'plan'=>1]];

if ($id) {
    $st = db()->prepare('SELECT * FROM client_types WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { header('Location: types.php'); exit; }
    $t['nombre'] = $row['nombre'];
    $t['sec'] = jdecode($row['secciones_json'], $t['sec']);
}

/* Todo el POST antes de erp_nav.php y terminando en redirect (docs/05 §12). */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    if ($nombre === '') $errors[] = 'Pon un nombre al tipo.';
    $sec = [];
    foreach ($SECCIONES as $k=>$d) $sec[$k] = isset($_POST['sec'][$k]) ? 1 : 0;

    if (!$errors) {
        if ($id) {
            db()->prepare('UPDATE client_types SET nombre = ?, secciones_json = ? WHERE id = ?')
                ->execute([$nombre, json_encode($sec, JSON_UNESCAPED_UNICODE), $id]);
        } else {
            db()->prepare('INSERT INTO client_types (nombre, secciones_json) VALUES (?, ?)')
                ->execute([$nombre, json_encode($sec, JSON_UNESCAPED_UNICODE)]);
        }
        header('Location: types.php?ok=1');
        exit;
    }
    $t = ['nombre'=>$nombre, 'sec'=>$sec];
}

require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/ajustes_nav.php';

/* A cuánta gente afecta lo que se toque aquí. */
$nUso = 0;
if ($id) { try { $q=db()->prepare('SELECT COUNT(*) FROM clients WHERE tipo_id=?'); $q->execute([$id]); $nUso=(int)$q->fetchColumn(); } catch(Exception $e){} }

aj_head('tipos', $id ? 'Editar «'.$t['nombre'].'»' : 'Nuevo tipo de cliente',
  $id
    ? 'Lo que marques aquí cambia el portal de <b>'.$nUso.' cliente'.($nUso==1?'':'s').'</b> al momento.'
    : 'Un tipo agrupa a los clientes que ven lo mismo en su portal.',
  '<a class="btn ghost" href="types.php">'.ic('back',15).' Volver</a>');
?>
<style>
/* Lista de secciones con interruptor, igual que las reglas automáticas. Antes
   eran seis casillas en columna con solo el nombre al lado. */
.ty-sec{display:flex;align-items:center;gap:16px;padding:16px 0;border-top:1px solid var(--line2)}
.ty-sec:first-of-type{border-top:none}
.ty-ic{width:38px;height:38px;border-radius:11px;background:var(--accent-soft);color:var(--accent);
  display:flex;align-items:center;justify-content:center;flex:none;transition:background .16s ease,color .16s ease}
.ty-sec.off .ty-ic{background:var(--line2);color:var(--label)}
.ty-bd{flex:1;min-width:0}
.ty-bd b{font-size:14.5px;font-weight:600;color:var(--ink-strong);display:block;transition:color .16s ease}
.ty-sec.off .ty-bd b{color:var(--muted)}
.ty-bd p{font-size:13px;color:var(--muted);margin-top:4px;line-height:1.55}
/* «Inicio» no es opcional: se enseña igual pero sin interruptor, para que se vea
   que está y no se busque el modo de activarlo. */
.ty-fija{opacity:.72}
.ty-fija .tag{flex:none}
/* Modo oscuro: el icono de sección apagada usa un gris que hay que aclarar. */
[data-theme=dark] .ty-sec.off .ty-ic{color:var(--muted)}
</style>

<?php if ($errors): ?><div class="err-note"><?php foreach($errors as $er) echo '<div>• '.e($er).'</div>'; ?></div><?php endif; ?>

<form method="post" class="aj-save">
  <div class="set-card">
    <div class="set-grid">
      <div class="set-f c6">
        <label><?= ic('pencil',15) ?> Nombre del tipo</label>
        <input type="text" name="nombre" value="<?= e($t['nombre']) ?>" placeholder="Ej: SEO completo, Solo web, Mantenimiento" required autofocus>
        <div class="hint">Solo lo ves tú, en la ficha de cada cliente.</div>
      </div>
    </div>
  </div>

  <div class="set-card">
    <h3>Qué ve el cliente en su portal</h3>
    <div class="h-sub">Apaga lo que no quieras que vea. Se le oculta la sección entera del menú de su portal.</div>

    <div class="ty-sec ty-fija">
      <div class="ty-ic"><?= ic('home',18) ?></div>
      <div class="ty-bd"><b>Inicio</b><p>El resumen del mes y los avisos. Se ve siempre.</p></div>
      <span class="tag on">Fija</span>
    </div>

    <?php foreach ($SECCIONES as $k=>$d): $on = !empty($t['sec'][$k]); ?>
      <div class="ty-sec <?= $on?'':'off' ?>">
        <div class="ty-ic"><?= ic($d[1],18) ?></div>
        <div class="ty-bd"><b><?= e($d[0]) ?></b><p><?= e($d[2]) ?></p></div>
        <label class="sw">
          <input type="checkbox" name="sec[<?= e($k) ?>]" <?= $on?'checked':'' ?> onchange="tySec(this)">
          <span class="tr"></span>
        </label>
      </div>
    <?php endforeach; ?>

    <div class="hint" style="margin-top:14px">Si apagas <b>Métricas</b>, también desaparecen las oportunidades del Inicio: salen de ahí.</div>
  </div>

  <div class="flex" style="gap:10px">
    <button class="btn" type="submit"><?= ic('check',15) ?> <?= $id ? 'Guardar cambios' : 'Crear tipo' ?></button>
    <a class="btn ghost" href="types.php">Cancelar</a>
  </div>
</form>

<script>
/* La fila se apaga en gris al desactivarla: se ve de un vistazo qué queda dentro
   y qué fuera, sin leer el estado de cada interruptor. */
function tySec(inp){ inp.closest('.ty-sec').classList.toggle('off', !inp.checked); }
</script>
<?php aj_foot(); ?>
