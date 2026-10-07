<?php
require_once __DIR__ . '/_layout.php';
require_can_edit();   // los de "solo lectura" no pueden crear/editar clientes

/* Deja la ficha con TODAS sus claves, pase lo que pase.

   El bug: un cliente al que aún no se le ha montado el informe tiene
   `estado_json`/`plan_json` = «{}» en la base. jdecode() de «{}» es un array
   vacío, no el array con forma —así que `$c['estado']['fases']`,
   `$c['plan']['items']`, etc. eran claves inexistentes y PHP escupía un
   «Warning: Undefined array key» EN MEDIO de los atributos HTML (dentro de un
   value="…"). Eso rompía la etiqueta y, con ella, el maquetado y las pestañas.

   Aquí se normaliza la estructura entera (cabeceras y filas) antes de pintar, así
   que la plantilla puede leer cualquier clave sin comprobar nada y no hay ni un
   warning. No toca el guardado: eso lee de $_POST aparte. */
function ed_norm(&$c){
  $e = is_array($c['estado'] ?? null) ? $c['estado'] : [];
  $c['estado'] = ['nombre'=>$e['nombre']??'', 'etiqueta'=>$e['etiqueta']??'', 'siguiente'=>$e['siguiente']??'', 'fases'=>[]];
  foreach ((is_array($e['fases']??null)?$e['fases']:[]) as $f) if(is_array($f)) $c['estado']['fases'][] = ['t'=>$f['t']??'', 's'=>$f['s']??'', 'estado'=>$f['estado']??''];

  $p = is_array($c['plan'] ?? null) ? $c['plan'] : [];
  $c['plan'] = ['resumen'=>$p['resumen']??'', 'items'=>[], 'detalle'=>[]];
  foreach ((is_array($p['items']??null)?$p['items']:[]) as $it) if(is_array($it)) $c['plan']['items'][] = ['n'=>$it['n']??'', 't'=>$it['t']??''];
  foreach ((is_array($p['detalle']??null)?$p['detalle']:[]) as $d) if(is_array($d)) $c['plan']['detalle'][] = ['h'=>$d['h']??'', 'p'=>$d['p']??''];

  $ac=[]; foreach ((is_array($c['accesos']??null)?$c['accesos']:[]) as $a) if(is_array($a)) $ac[] = ['b'=>$a['b']??'', 's'=>$a['s']??'', 'u'=>$a['u']??'', 'tipo'=>$a['tipo']??'generic'];
  $c['accesos']=$ac;

  $mm=[]; foreach ((is_array($c['met']??null)?$c['met']:[]) as $mes=>$m) if(is_array($m)) $mm[$mes] = ['ll'=>$m['ll']??'', 'wa'=>$m['wa']??'', 'fo'=>$m['fo']??'', 'vi'=>$m['vi']??'', 'ap'=>$m['ap']??'', 'ctr'=>$m['ctr']??''];
  $c['met']=$mm;

  $tt=[]; foreach ((is_array($c['tareas']??null)?$c['tareas']:[]) as $mes=>$g){ if(!is_array($g)) continue; $tt[$mes]=['completado'=>[], 'pendiente'=>[]];
    foreach (['completado','pendiente'] as $st) foreach ((is_array($g[$st]??null)?$g[$st]:[]) as $t) if(is_array($t)) $tt[$mes][$st][] = ['t'=>$t['t']??'', 'd'=>$t['d']??'']; }
  $c['tareas']=$tt;
}

$tiposCliente = db()->query('SELECT id, nombre FROM client_types ORDER BY nombre')->fetchAll();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
/* Igual que en client.php: editar la ficha de un cliente que no te toca es
   justo lo que impide el alcance. Crear uno nuevo ($id=0) no se filtra. */
if ($id && function_exists('alcance_exigir_cliente')) alcance_exigir_cliente($id);
$errors = [];

// ---------- valores por defecto ----------
$c = [
  'name'=>'', 'iniciales'=>'', 'saludo'=>'', 'username'=>'', 'conversiones'=>1, 'actual'=>'', 'tipo_id'=>'',
  'fact_nombre'=>'', 'fact_nif'=>'', 'fact_dir'=>'', 'fact_email'=>'', 'login_email'=>'', 'activo'=>1,
  'estado'=>['nombre'=>'','etiqueta'=>'','siguiente'=>'','fases'=>[
      ['t'=>'Auditoría','s'=>'y arranque','estado'=>'done'],
      ['t'=>'Base técnica','s'=>'y contenidos','estado'=>'done'],
      ['t'=>'Crecimiento','s'=>'y captación','estado'=>'now'],
      ['t'=>'Consolidación','s'=>'y escala','estado'=>''],
  ]],
  'plan'=>['resumen'=>'','items'=>[],'detalle'=>[]],
  'accesos'=>[],
  'met'=>[],
  'tareas'=>[],
];

// ---------- cargar si edita ----------
if ($id) {
    $st = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { header('Location: index.php'); exit; }
    $c['name']=$row['name']; $c['iniciales']=$row['iniciales']; $c['saludo']=$row['saludo'];
    $c['username']=$row['username']; $c['conversiones']=(int)$row['conversiones']; $c['actual']=$row['actual'];
    $c['tipo_id']=$row['tipo_id'] !== null ? (int)$row['tipo_id'] : '';
    $c['fact_nombre']=$row['fact_nombre']??''; $c['fact_nif']=$row['fact_nif']??''; $c['fact_dir']=$row['fact_dir']??''; $c['fact_email']=$row['fact_email']??''; $c['login_email']=$row['login_email']??''; $c['activo']=isset($row['activo'])?(int)$row['activo']:1;
    $c['estado']=jdecode($row['estado_json'], $c['estado']);
    $c['plan']=jdecode($row['plan_json'], $c['plan']);
    $c['accesos']=jdecode($row['accesos_json'], []);
    $c['met']=jdecode($row['met_json'], []);
    $c['tareas']=jdecode($row['tareas_json'], []);
}

// ---------- guardar ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($name==='') $errors[] = 'El nombre es obligatorio.';
    if ($username==='') $errors[] = 'El usuario es obligatorio.';
    if (!$id && $password==='') $errors[] = 'Pon una contraseña para el cliente.';

    // unicidad de usuario
    $chk = db()->prepare('SELECT id FROM clients WHERE username = ? AND id <> ?');
    $chk->execute([$username, $id]);
    if ($chk->fetch()) $errors[] = 'Ese usuario ya existe, elige otro.';

    // unicidad del correo de Google (si se ha puesto): dos clientes no pueden compartirlo
    $loginEmail = strtolower(trim($_POST['login_email'] ?? ''));
    if ($loginEmail !== '') {
        $chkg = db()->prepare("SELECT id FROM clients WHERE login_email = ? AND id <> ?");
        $chkg->execute([$loginEmail, $id]);
        if ($chkg->fetch()) $errors[] = 'Ese correo de Google ya está en otro cliente.';
    }

    // estado
    $estado = [
      'nombre'=>trim($_POST['est_nombre'] ?? ''),
      'etiqueta'=>trim($_POST['est_etiqueta'] ?? ''),
      'siguiente'=>trim($_POST['est_siguiente'] ?? ''),
      'fases'=>[],
    ];
    foreach (($_POST['fase_t'] ?? []) as $i => $t) {
        $t = trim($t);
        if ($t==='') continue;
        $estado['fases'][] = ['t'=>$t,'s'=>trim($_POST['fase_s'][$i] ?? ''),'estado'=>$_POST['fase_estado'][$i] ?? ''];
    }

    // plan
    $items = [];
    foreach (($_POST['item_n'] ?? []) as $i => $n) {
        $t = trim($_POST['item_t'][$i] ?? '');
        if (trim($n)==='' && $t==='') continue;
        $items[] = ['n'=>trim($n),'t'=>$t];
    }
    $detalle = [];
    foreach (($_POST['det_h'] ?? []) as $i => $h) {
        $h = trim($h); $p = trim($_POST['det_p'][$i] ?? '');
        if ($h==='' && $p==='') continue;
        $detalle[] = ['h'=>$h,'p'=>$p];
    }
    $plan = ['resumen'=>trim($_POST['plan_resumen'] ?? ''),'items'=>$items,'detalle'=>$detalle];

    // accesos
    $accesos = [];
    foreach (($_POST['acc_b'] ?? []) as $i => $b) {
        $b = trim($b);
        if ($b==='') continue;
        $accesos[] = ['b'=>$b,'s'=>trim($_POST['acc_s'][$i] ?? ''),'u'=>trim($_POST['acc_u'][$i] ?? '#'),'tipo'=>$_POST['acc_tipo'][$i] ?? 'generic'];
    }

    /* Las métricas ya NO se editan a mano: las trae Analytics/Search Console solo
       (ver Métricas de Google). Por eso este formulario ya no escribe met_json —
       si lo hiciera, guardar la ficha borraría lo que trajo Google. */

    // tareas
    $tareas = [];
    foreach (($_POST['tar_mes'] ?? []) as $i => $mes) {
        $mes = trim($mes); $t = trim($_POST['tar_t'][$i] ?? '');
        if ($mes==='' || $t==='') continue;
        $estadoT = ($_POST['tar_estado'][$i] ?? 'completado') === 'pendiente' ? 'pendiente' : 'completado';
        if (!isset($tareas[$mes])) $tareas[$mes] = ['completado'=>[],'pendiente'=>[]];
        $tareas[$mes][$estadoT][] = ['t'=>$t,'d'=>trim($_POST['tar_d'][$i] ?? '')];
    }

    if (!$errors) {
        $fields = [
          'username'=>$username,'name'=>$name,'iniciales'=>trim($_POST['iniciales'] ?? 'CL'),
          'saludo'=>trim($_POST['saludo'] ?? ''),'conversiones'=>isset($_POST['conversiones'])?1:0,
          'actual'=>trim($_POST['actual'] ?? ''),
          'tipo_id'=>(isset($_POST['tipo_id']) && $_POST['tipo_id']!=='') ? (int)$_POST['tipo_id'] : null,
          'fact_nombre'=>trim($_POST['fact_nombre'] ?? ''),'fact_nif'=>trim($_POST['fact_nif'] ?? ''),'fact_dir'=>trim($_POST['fact_dir'] ?? ''),'fact_email'=>trim($_POST['fact_email'] ?? ''),'login_email'=>strtolower(trim($_POST['login_email'] ?? '')),
          'activo'=>isset($_POST['activo'])?1:0,
          'estado_json'=>json_encode($estado, JSON_UNESCAPED_UNICODE),
          'plan_json'=>json_encode($plan, JSON_UNESCAPED_UNICODE),
          'accesos_json'=>json_encode($accesos, JSON_UNESCAPED_UNICODE),
          /* met_json NO se toca aquí a propósito: lo gestiona la sincronización con Google. */
          'tareas_json'=>json_encode($tareas, JSON_UNESCAPED_UNICODE),
        ];
        if ($id) {
            $set = implode(', ', array_map(fn($k)=>"$k = :$k", array_keys($fields)));
            $sql = "UPDATE clients SET $set" . ($password!=='' ? ", password_hash = :ph" : "") . " WHERE id = :id";
            $params = $fields; $params['id']=$id;
            if ($password!=='') $params['ph'] = password_hash($password, PASSWORD_DEFAULT);
            db()->prepare($sql)->execute($params);
        } else {
            $fields['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $cols = implode(', ', array_keys($fields));
            $ph = implode(', ', array_map(fn($k)=>":$k", array_keys($fields)));
            db()->prepare("INSERT INTO clients ($cols) VALUES ($ph)")->execute($fields);
            $newCid = (int)db()->lastInsertId();
            /* Al dar de alta: listas por defecto. La lista INFORMES CLIENTE (tipo 'informe')
               es la que se vincula al informe del portal del cliente. */
            try {
              $il = db()->prepare('INSERT INTO task_lists (client_id, nombre, es_cliente, tipo, orden) VALUES (?,?,?,?,?)');
              $il->execute([$newCid,'TAREAS',0,'tareas',0]);
              $il->execute([$newCid,'ESTRATEGIA',0,'tareas',1]);
              $il->execute([$newCid,'TAREA CLIENTE',0,'tareas',2]);
              $il->execute([$newCid,'INFORMES CLIENTE',0,'informe',3]);
            } catch (Exception $e) {}
            /* Aviso a los dueños de que hay un cliente nuevo de alta. */
            if (function_exists('notif_client_new')) notif_client_new($newCid,(string)(current_admin()['username']??''));
        }
        /* Confirmación al volver al listado: el pie común lee ?msg= y pinta el toast
           (claves «guardado»/«creado» ya definidas en erp_foot). */
        header('Location: index.php?msg=' . ($id ? 'guardado' : 'creado'));
        exit;
    }
    // si hubo error, conservar lo escrito
    $c = array_merge($c, ['name'=>$name,'iniciales'=>$_POST['iniciales']??'','saludo'=>$_POST['saludo']??'','username'=>$username,'conversiones'=>isset($_POST['conversiones'])?1:0,'actual'=>$_POST['actual']??'','tipo_id'=>(isset($_POST['tipo_id'])&&$_POST['tipo_id']!=='')?(int)$_POST['tipo_id']:'','fact_nombre'=>$_POST['fact_nombre']??'','fact_nif'=>$_POST['fact_nif']??'','fact_dir'=>$_POST['fact_dir']??'','fact_email'=>$_POST['fact_email']??'','login_email'=>$_POST['login_email']??'','activo'=>isset($_POST['activo'])?1:0,'estado'=>$estado,'plan'=>$plan,'accesos'=>$accesos,'tareas'=>$tareas]);
}

$tipos = ['figma'=>'Figma','drive'=>'Google Drive','web'=>'Sitio web','looker'=>'Looker Studio','generic'=>'Genérico'];
ed_norm($c);   // la ficha queda con todas sus claves: ni un «Undefined array key» que rompa el HTML
ahead($id ? 'Editar cliente' : 'Nuevo cliente');
?>
<?php /* La ficha entera son 151 campos y casi cuatro mil píxeles de alto. Sin
         nada que la divida, corregir un acceso obligaba a bajar por delante de
         las métricas de doce meses, y el único botón de guardar estaba al final
         del todo. Ahora las siete secciones se eligen arriba y solo se ve una;
         el resto sigue en la página (oculto), así que un único Guardar las
         guarda todas. Y el botón va fijo abajo, siempre a mano. */ ?>
<div class="ed-head">
  <div>
    <h1><?= $id ? 'Editar cliente' : 'Nuevo cliente' ?></h1>
    <div class="lead" style="margin:0">Rellena la ficha; el portal del cliente se arma con esto.</div>
  </div>
  <?php if($id): ?><a class="btn ghost" href="client.php?id=<?= (int)$id ?>"><?= ic('back',15) ?> Volver a la ficha</a><?php endif; ?>
</div>

<div class="seg ed-seg" id="edSeg">
  <button type="button" class="on" data-s="datos"><?= ic('user',15) ?> Datos</button>
  <button type="button" data-s="fact"><?= ic('euro',15) ?> Facturación</button>
  <button type="button" data-s="estado"><?= ic('trend',15) ?> Progreso</button>
  <button type="button" data-s="plan"><?= ic('list',15) ?> Plan</button>
  <button type="button" data-s="accesos"><?= ic('link',15) ?> Accesos</button>
  <button type="button" data-s="tareas"><?= ic('tasks',15) ?> Progreso del cliente</button>
</div>
<?php /* Los errores se pintaban con una tarjeta y colores escritos a mano.
         .err-note está en erp_nav.php y es el mismo aviso en todo el ERP. */ ?>
<?php if ($errors): ?><div class="err-note"><?php foreach($errors as $er) echo '<div>• '.e($er).'</div>'; ?></div><?php endif; ?>

<form method="post">
  <div class="card ed-sec on" data-s="datos" style="scroll-margin-top:80px">
    <h3>Datos del cliente</h3>
    <div class="h-sub">Quién es y cómo entra a su portal.</div>

    <div class="set-zone">El negocio</div>
    <div class="set-grid">
      <div class="set-f c6"><label><?= ic('building',15) ?> Nombre del negocio</label><input type="text" name="name" value="<?= e($c['name']) ?>" required aria-label="Nombre del negocio"></div>
      <div class="set-f c4"><label><?= ic('chat',15) ?> Saludo</label><input type="text" name="saludo" value="<?= e($c['saludo']) ?>" placeholder="Ej: María"><div class="hint">Cómo le saluda el portal.</div></div>
      <div class="set-f c2"><label><?= ic('user',15) ?> Iniciales</label><input type="text" name="iniciales" maxlength="4" value="<?= e($c['iniciales']) ?>" placeholder="CA"><div class="hint">Su avatar.</div></div>
    </div>

    <div class="set-zone">Cómo entra a su portal</div>
    <div class="set-grid">
      <div class="set-f c4"><label><?= ic('user',15) ?> Usuario</label><input type="text" name="username" value="<?= e($c['username']) ?>" required aria-label="Usuario"></div>
      <div class="set-f c4"><label><?= ic('vault',15) ?> Contraseña</label><input type="password" name="password" <?= $id?'':'required' ?> placeholder="<?= $id ? 'Déjalo vacío para no cambiarla' : '' ?>" aria-label="Contraseña"></div>
      <div class="set-f c4"><label><?= ic('cal',15) ?> Mes actual</label><input type="text" name="actual" value="<?= e($c['actual']) ?>" placeholder="Junio"><div class="hint">El mes que abre por defecto.</div></div>
      <div class="set-f c12"><label><?= ic('link',15) ?> Correo de Google para entrar</label><input type="email" name="login_email" value="<?= e($c['login_email']) ?>" placeholder="cliente@gmail.com"><div class="hint">Si pones aquí su Gmail, podrá entrar al portal pulsando «Entrar con Google», sin contraseña. Déjalo vacío si no lo usa.</div></div>
    </div>

    <div class="set-zone">Qué ve en su portal</div>
    <div class="set-grid">
      <div class="set-f c12"><label><?= ic('flag',15) ?> Tipo de cliente</label>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <select name="tipo_id" style="width:420px;max-width:100%" aria-label="Tipo de cliente">
            <option value="">— Sin tipo —</option>
            <?php foreach ($tiposCliente as $tc): ?>
              <option value="<?= (int)$tc['id'] ?>" <?= ((string)$c['tipo_id']===(string)$tc['id'])?'selected':'' ?>><?= e($tc['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (can_edit()): ?><button type="button" class="btn ghost sm" style="white-space:nowrap" onclick="edNuevoTipo()"><?= ic('plus',14) ?> Nuevo tipo</button><?php endif; ?>
        </div>
        <div class="hint">El tipo decide qué secciones ve. Puedes crear uno aquí mismo o gestionarlos en <a href="types.php">Tipos de cliente</a>.</div>
      </div>
    </div>
    <?php if (can_edit()): ?>
    <script>
    /* Crear un tipo de cliente sin salir de la ficha. Usa el MISMO alta que la
       pantalla real (type-edit.php?ajax=create): crea el tipo con todas las secciones
       activadas (se afinan luego en Tipos de cliente) y lo deja seleccionado aquí. */
    function edNuevoTipo(){
      erpPrompt('Nuevo tipo de cliente', '', {
        msg:'Se crea al momento con todas las secciones visibles. Podrás afinar qué ve cada tipo en Ajustes › Tipos de cliente.',
        placeholder:'Ej: SEO completo, Solo web…', ok:'Crear tipo'
      }).then(function(nombre){
        if(!nombre) return;   // null si se cancela o se deja vacío
        var body=new URLSearchParams(); body.set('nombre', nombre);
        fetch('type-edit.php?ajax=create',{method:'POST',headers:{'Accept':'application/json'},body:body})
          .then(function(r){return r.json();})
          .then(function(j){
            if(!j || !j.ok){ toast((j&&j.msg)||'No se pudo crear el tipo.','err'); return; }
            var sel=document.querySelector('select[name="tipo_id"]');
            var op=sel.querySelector('option[value="'+j.id+'"]');
            if(!op){ op=document.createElement('option'); op.value=j.id; op.textContent=j.nombre; sel.appendChild(op); }
            sel.value=j.id; if(sel._csSync) sel._csSync();
            toast(j.dup ? 'Ese tipo ya existía: seleccionado.' : 'Tipo «'+j.nombre+'» creado.');
          })
          .catch(function(){ toast('Error de conexión.','err'); });
      });
    }
    </script>
    <?php endif; ?>
    <?php /* Dos interruptores, no dos casillas: son estados que se encienden o se
             apagan (tiene conversiones / está activo), como las reglas de Ajustes.
             El .sw viene de erp_nav.php. */ ?>
    <label class="ed-sw <?= $c['conversiones']?'on':'' ?>">
      <span class="sw"><input type="checkbox" name="conversiones" onchange="edSw(this)" <?= $c['conversiones']?'checked':'' ?>><span class="tr"></span></span>
      <span class="tx"><b>Tiene conversiones</b><em>Enseña Métricas y oportunidades en su portal. Apágalo en proyectos de solo web.</em></span>
    </label>
    <?php if ($id): ?>
    <div class="ed-subact"><a class="btn ghost sm" href="conversiones.php?cli=<?= (int)$id ?>"><?= ic('chart',14) ?> Configurar sus métricas de Google (web, Analytics y conversiones)</a></div>
    <?php endif; ?>
    <label class="ed-sw <?= !empty($c['activo'])?'on':'' ?>">
      <span class="sw"><input type="checkbox" name="activo" onchange="edSw(this)" <?= !empty($c['activo'])?'checked':'' ?>><span class="tr"></span></span>
      <span class="tx"><b>Cliente activo</b><em>Si lo apagas, pasa a «Clientes no activos» y deja de contar como cliente en alta.</em></span>
    </label>
  </div>

  <?php /* El id permite que el «Editar →» de los datos fiscales de la ficha
           caiga directamente aquí en vez de en lo alto de un formulario largo. */ ?>
  <div class="card ed-sec" id="fact" data-s="fact" style="scroll-margin-top:80px">
    <h3>Datos de facturación</h3>
    <div class="h-sub">Se copian solos a cada factura que le hagas. También se rellenan al crear la primera.</div>
    <div class="set-grid">
      <div class="set-f c8"><label><?= ic('building',15) ?> Nombre fiscal / razón social</label><input type="text" name="fact_nombre" value="<?= e($c['fact_nombre']) ?>" placeholder="<?= e($c['name']) ?>"></div>
      <div class="set-f c4"><label><?= ic('file',15) ?> NIF / CIF</label><input type="text" name="fact_nif" value="<?= e($c['fact_nif']) ?>" placeholder="B12345678"></div>
      <div class="set-f c12"><label><?= ic('home',15) ?> Dirección fiscal</label><input type="text" name="fact_dir" value="<?= e($c['fact_dir']) ?>" placeholder="Calle, número, código postal y ciudad"></div>
      <div class="set-f c8"><label><?= ic('inbox',15) ?> Email de facturación</label><input type="email" name="fact_email" value="<?= e($c['fact_email']) ?>"></div>
    </div>
  </div>

  <div class="card ed-sec" data-s="estado" style="scroll-margin-top:80px">
    <h3>Progreso del proyecto</h3>
    <div class="h-sub">La etapa y las fases que ve el cliente en la cabecera de su portal.</div>
    <div class="set-grid"><div class="set-f c12"><label><?= ic('trend',15) ?> Nombre de la etapa actual</label><input type="text" name="est_nombre" value="<?= e($c['estado']['nombre']) ?>" placeholder="Crecimiento y captación de clientes"></div>
      <div class="set-f c4"><label><?= ic('flag',15) ?> Etiqueta</label><input type="text" name="est_etiqueta" value="<?= e($c['estado']['etiqueta']) ?>" placeholder="Etapa 3 de 4"></div>
      <div class="set-f c8"><label><?= ic('chevron',15) ?> Lo siguiente</label><input type="text" name="est_siguiente" value="<?= e($c['estado']['siguiente']) ?>" placeholder="nuevas páginas de servicio…"></div>
    </div>
    <div class="set-zone">Fases · la barra de progreso de su portal</div>
    <div class="ed-lista">
      <div class="ed-cols"><span>Nombre de la fase</span><span>Subtítulo</span><span style="flex:0 0 150px">Estado</span><span class="hueco"></span></div>
      <div id="fase-rows">
      <?php foreach ($c['estado']['fases'] as $f): ?>
      <div class="rep-row">
        <input type="text" name="fase_t[]" value="<?= e($f['t']) ?>" placeholder="Nombre de fase">
        <input type="text" name="fase_s[]" value="<?= e($f['s']) ?>" placeholder="subtítulo">
        <select name="fase_estado[]"><option value="done" <?= $f['estado']==='done'?'selected':'' ?>>Completada</option><option value="now" <?= $f['estado']==='now'?'selected':'' ?>>Actual</option><option value="" <?= $f['estado']===''?'selected':'' ?>>Pendiente</option></select>
        <button type="button" class="del" onclick="delRow(this)" title="Quitar esta fila"><?= ic('trash',15) ?></button>
      </div>
      <?php endforeach; ?>
    </div>
      <button type="button" class="inline-add" onclick="addRow('fase')"><span class="plus"><?= ic('plus',16) ?></span> Añadir fase</button>
    </div>
  </div>

  <div class="card ed-sec" data-s="plan" style="scroll-margin-top:80px">
    <h3>Plan contratado</h3>
    <div class="h-sub">Lo que el cliente ve en la sección «Plan» de su portal.</div>
    <div class="set-zone"><?= ic('pencil',14) ?> Resumen en lenguaje sencillo</div>
    <textarea name="plan_resumen" placeholder="Cada mes trabajamos…"><?= e($c['plan']['resumen']) ?></textarea>
    <div class="set-zone"><?= ic('list',14) ?> Lo que incluye · número + concepto</div>
    <div class="ed-lista">
      <div class="ed-cols"><span style="flex:0 0 90px">Cantidad</span><span>Concepto</span><span class="hueco"></span></div>
      <div id="item-rows">
      <?php foreach ($c['plan']['items'] as $it): ?>
      <div class="rep-row"><input type="text" name="item_n[]" value="<?= e($it['n']) ?>" placeholder="1" style="max-width:90px"><input type="text" name="item_t[]" value="<?= e($it['t']) ?>" placeholder="Página de servicio al mes"><button type="button" class="del" onclick="delRow(this)" title="Quitar esta fila"><?= ic('trash',15) ?></button></div>
      <?php endforeach; ?>
    </div>
      <button type="button" class="inline-add" onclick="addRow('item')"><span class="plus"><?= ic('plus',16) ?></span> Añadir concepto</button>
    </div>
    <div class="set-zone"><?= ic('file',14) ?> Detalle completo · título + texto</div>
    <div class="ed-lista">
      <div class="ed-cols"><span>Apartado y descripción</span><span class="hueco"></span></div>
      <div id="det-rows">
      <?php foreach ($c['plan']['detalle'] as $d): ?>
      <div class="rep-row" style="flex-direction:column"><input type="text" name="det_h[]" value="<?= e($d['h']) ?>" placeholder="Título del apartado"><div style="display:flex;gap:8px;width:100%"><textarea name="det_p[]" placeholder="Descripción…" style="flex:1"><?= e($d['p']) ?></textarea><button type="button" class="del" onclick="delRow(this)" title="Quitar esta fila"><?= ic('trash',15) ?></button></div></div>
      <?php endforeach; ?>
    </div>
      <button type="button" class="inline-add" onclick="addRow('det')"><span class="plus"><?= ic('plus',16) ?></span> Añadir apartado</button>
    </div>
  </div>

  <div class="card ed-sec" data-s="accesos" style="scroll-margin-top:80px">
    <h3>Accesos</h3>
    <div class="h-sub">Los enlaces y herramientas que el cliente abre desde su portal (Figma, Drive, su web…).</div>
    <div class="ed-lista">
      <div class="ed-cols"><span>Título</span><span>Descripción</span><span>Enlace</span><span style="flex:0 0 150px">Tipo</span><span class="hueco"></span></div>
      <div id="acc-rows">
      <?php foreach ($c['accesos'] as $a): ?>
      <div class="rep-row">
        <input type="text" name="acc_b[]" value="<?= e($a['b']) ?>" placeholder="Título">
        <input type="text" name="acc_s[]" value="<?= e($a['s']) ?>" placeholder="Descripción">
        <input type="url" name="acc_u[]" value="<?= e($a['u']) ?>" placeholder="https://…">
        <select name="acc_tipo[]"><?php foreach($tipos as $k=>$v): ?><option value="<?= $k ?>" <?= ($a['tipo']??'')===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select>
        <button type="button" class="del" onclick="delRow(this)" title="Quitar esta fila"><?= ic('trash',15) ?></button>
      </div>
      <?php endforeach; ?>
    </div>
      <button type="button" class="inline-add" onclick="addRow('acc')"><span class="plus"><?= ic('plus',16) ?></span> Añadir acceso</button>
    </div>
  </div>

  <div class="card ed-sec" data-s="tareas" style="scroll-margin-top:80px">
    <h3>Progreso del cliente</h3>
    <div class="h-sub">Lo que ve en su sección de Progreso: qué se ha hecho y qué está en curso cada mes, explicado para él.</div>
    <div class="ed-lista">
      <div class="ed-cols"><span>Cada tarea del cliente · mes, estado, título y su explicación</span><span class="hueco"></span></div>
      <div id="tar-rows">
      <?php foreach ($c['tareas'] as $mes=>$g): foreach (['completado','pendiente'] as $estadoT): foreach ($g[$estadoT] as $t): ?>
      <div class="rep-row" style="flex-direction:column">
        <div style="display:flex;gap:8px;width:100%">
          <input type="text" name="tar_mes[]" value="<?= e($mes) ?>" placeholder="Mes" style="max-width:130px">
          <select name="tar_estado[]" style="max-width:150px"><option value="completado" <?= $estadoT==='completado'?'selected':'' ?>>Completado</option><option value="pendiente" <?= $estadoT==='pendiente'?'selected':'' ?>>En curso</option></select>
          <input type="text" name="tar_t[]" value="<?= e($t['t']) ?>" placeholder="Título de la tarea" style="flex:1">
        </div>
        <div style="display:flex;gap:8px;width:100%"><textarea name="tar_d[]" placeholder="Explicación para el cliente" style="flex:1"><?= e($t['d']) ?></textarea><button type="button" class="del" onclick="delRow(this)" title="Quitar esta fila"><?= ic('trash',15) ?></button></div>
      </div>
      <?php endforeach; endforeach; endforeach; ?>
    </div>
      <button type="button" class="inline-add" onclick="addRow('tar')"><span class="plus"><?= ic('plus',16) ?></span> Añadir tarea</button>
    </div>
  </div>

  <?php /* Barra fija: el botón estaba al final de casi cuatro mil píxeles, así
           que para guardar un cambio de la primera sección había que recorrer
           la ficha entera. */ ?>
  <div class="ed-guardar">
    <button class="btn" type="submit"><?= ic('check',15) ?> Guardar cliente</button>
    <a class="btn ghost" href="<?= $id ? 'client.php?id='.(int)$id : 'index.php' ?>">Cancelar</a>
    <span class="muted" style="font-size:12.5px;margin-left:auto">Se guardan todas las secciones a la vez, no solo la que estés viendo.</span>
  </div>
</form>

<?php /* Antes las secciones se plegaban pulsando su título, con un «▾» escrito a
         mano. Lo sustituye el segmentado de arriba: dos formas de hacer lo mismo
         en la misma pantalla sobran, y el carácter suelto no es del ERP
         (docs/05 §3: los iconos salen de ic()). */ ?>
<style>
.ed-head{display:flex;align-items:flex-start;gap:14px;margin-bottom:14px}
.ed-head h1{margin-bottom:4px}
.ed-seg{margin-bottom:18px}
/* Cada sección respira: más aire dentro de la tarjeta y separación clara entre
   su título y el primer bloque de campos. */
.ed-sec.card{padding:26px 28px 28px;max-width:900px}
.ed-sec > h3{margin:0 0 3px;font-size:16px;font-weight:650;letter-spacing:-.1px}
.ed-sec > .h-sub{font-size:12.5px;color:var(--muted);line-height:1.5;margin-bottom:20px;max-width:66ch}
.ed-sec .set-grid{gap:18px 20px}
.ed-sec .set-f input,.ed-sec .set-f select{padding:11px 13px;font-size:14px}
.ed-sec > textarea{width:100%;min-height:96px;padding:12px 14px;font-size:14px;line-height:1.55;margin-bottom:2px}
/* Interruptores en fila: mismo patrón que team-edit y las reglas de Ajustes.
   Un modo que se enciende o se apaga se pinta con .sw (de erp_nav), no con una
   casilla. */
.ed-sw{display:flex;align-items:center;gap:14px;padding:15px 17px;border:1px solid var(--line);border-radius:13px;
  background:var(--bg);cursor:pointer;margin-top:12px;transition:border-color .16s ease,background .16s ease}
.ed-sw:first-of-type{margin-top:16px}
.ed-sw:hover{border-color:#dcdde0;background:var(--soft)}
.ed-sw.on{border-color:#d6d7db;background:var(--accent-soft)}
.ed-sw .tx{flex:1;min-width:0}
.ed-sw b{display:block;font-size:13.5px;font-weight:600;color:var(--ink-strong)}
.ed-sw em{display:block;font-style:normal;font-size:12.5px;color:var(--muted);line-height:1.5;margin-top:3px}
/* Solo se ve la sección elegida; el resto sigue en el formulario para que un
   único «Guardar» las guarde todas. */
.ed-sec{display:none}
.ed-sec.on{display:block;animation:fadeUp .2s cubic-bezier(.2,.7,.3,1)}
/* Barra de guardado siempre visible al pie del contenido. */
.ed-guardar{position:sticky;bottom:0;z-index:20;display:flex;gap:10px;align-items:center;flex-wrap:wrap;
  background:rgba(255,255,255,.92);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
  border-top:1px solid var(--line);margin:0 -44px;padding:14px 44px}
@media(max-width:700px){ .ed-guardar{margin:0 -18px;padding:12px 18px} }
/* Listas repetibles (fases, accesos, métricas…). Antes eran inputs sueltos uno
   debajo de otro, sin caja ni cabecera, con un botón de borde discontinuo al
   final: parecía un formulario a medio maquetar. Ahora cada lista es una caja
   con su cabecera de columnas y su fila de añadir, como las tablas del ERP. */
.ed-lista{border:1px solid var(--line);border-radius:12px;overflow:hidden;background:#fff;margin-top:4px}
.ed-cols{display:flex;gap:8px;padding:9px 14px 8px;background:#fbfbfc;border-bottom:1px solid var(--line2);
  font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:650}
.ed-cols span{flex:1;min-width:0}
/* El hueco de la derecha tiene que medir exactamente lo que el botón de borrar,
   o cada columna se desplaza un píxel más que la anterior y la cabecera acaba
   descuadrada respecto a los campos. */
.ed-cols span.hueco{flex:0 0 41px}
/* La cabecera solo tiene sentido si las filas van en una línea; en las que
   apilan campos (detalle, tareas) se queda como título de la lista. */
.ed-lista .rep-row{margin:0;padding:10px 12px;border-bottom:1px solid var(--line2);gap:8px;align-items:center}
.ed-lista .rep-row:last-child{border-bottom:none}
.ed-lista .rep-row:hover{background:#fbfbfc}
.ed-lista .inline-add{border-top:1px solid var(--line2)}
.ed-lista .del{opacity:0;transition:opacity .14s ease}
.ed-lista .rep-row:hover .del,.ed-lista .rep-row:focus-within .del{opacity:1}
/* Campos SIN caja hasta que los tocas: se editan en línea, como en la ficha de
   tarea. Cada celda con su recuadro y su borde parecía una hoja de cálculo; así
   queda una tabla limpia y moderna, y el foco se ve claro al escribir. */
.ed-lista .rep-row input,
.ed-lista .rep-row select,
.ed-lista .rep-row textarea{border:1px solid transparent;background:transparent;border-radius:8px;padding:8px 10px;
  transition:background-color .14s ease,border-color .14s ease,box-shadow .14s ease}
.ed-lista .rep-row input:hover,
.ed-lista .rep-row select:hover,
.ed-lista .rep-row textarea:hover{background:#f1f2f4}
.ed-lista .rep-row input:focus,
.ed-lista .rep-row select:focus,
.ed-lista .rep-row textarea:focus{background:#fff;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft);outline:none}
.ed-lista .rep-row input::placeholder,.ed-lista .rep-row textarea::placeholder{color:#c2c6cc}
/* Las filas que apilan campos (detalle, tareas) necesitan un respiro extra. */
.ed-lista .rep-row[style*="column"]{padding:12px 12px;gap:6px}
.ed-lista .rep-row textarea{min-height:60px;line-height:1.5}
/* Cabecera de columnas un pelín más legible. */
.ed-cols{font-size:11px;padding:10px 14px 9px}
/* La primera columna (Mes) es la etiqueta de la fila: va en negrita para que se
   lea de un vistazo de qué mes es cada línea. */
.ed-lista .rep-row input[name="met_mes[]"],
.ed-lista .rep-row input[name="tar_mes[]"]{font-weight:600;color:var(--ink-strong)}
/* Métricas es una tabla de números: centrados y con sus cabeceras centradas,
   como cualquier tabla de datos del ERP. */
.ed-sec[data-s="metricas"] .rep-row input[type=number]{text-align:center}
.ed-sec[data-s="metricas"] .ed-cols span:not(:first-child):not(.hueco){text-align:center}
/* La columna «Mes» necesita más sitio: con flex:1 recortaba «Febrero»/«Septiembre».
   Se fija el ancho en cabecera y celda a la vez para que sigan alineadas. */
.ed-sec[data-s="metricas"] .ed-cols span:first-child,
.ed-sec[data-s="metricas"] .rep-row input[name="met_mes[]"]{flex:0 0 104px}
/* Sub-acción que cuelga de un interruptor (p. ej. «Configurar métricas» bajo
   «Tiene conversiones»): con aire arriba y abajo para que no quede pegada. */
.ed-subact{margin:11px 0 6px;padding-left:2px}
/* Un campo suelto no debe estirarse a todo lo ancho de la tarjeta. */
.ed-sec > input,.ed-sec > select,.ed-sec > textarea{max-width:640px}
/* ---- Modo oscuro: remapea SOLO las superficies y bordes propios de esta
   pantalla. No toca el modo claro ni los colores de marca. ---- */
[data-theme=dark] .ed-sw:hover{border-color:var(--line-strong)}
[data-theme=dark] .ed-sw.on{border-color:var(--line-strong)}
[data-theme=dark] .ed-guardar{background-color:var(--card)}
[data-theme=dark] .ed-lista{background-color:var(--card)}
[data-theme=dark] .ed-cols{background-color:var(--soft)}
[data-theme=dark] .ed-lista .rep-row:hover{background-color:var(--soft)}
[data-theme=dark] .ed-lista .rep-row input:hover,
[data-theme=dark] .ed-lista .rep-row select:hover,
[data-theme=dark] .ed-lista .rep-row textarea:hover{background-color:var(--soft)}
[data-theme=dark] .ed-lista .rep-row input:focus,
[data-theme=dark] .ed-lista .rep-row select:focus,
[data-theme=dark] .ed-lista .rep-row textarea:focus{background-color:var(--field)}
/* ---- Móvil (teléfono): campos a ancho completo y listas apiladas ---- */
@media(max-width:640px){
  .ed-head{flex-direction:column;gap:10px}
  .ed-sec.card{padding:18px 15px 18px}
  .ed-sec > input,.ed-sec > select,.ed-sec > textarea{max-width:none}
  select[name="tipo_id"]{width:100%!important}
  /* Listas repetibles (fases, plan, accesos, progreso): la cabecera de columnas
     no cabe; se ocultan sus etiquetas y cada campo pasa a ancho completo. Los
     placeholders siguen indicando qué es cada campo. */
  .ed-lista .ed-cols{display:none}
  .ed-lista .rep-row{flex-direction:column;align-items:stretch;gap:8px}
  .ed-lista .rep-row > div[style*="flex"]{flex-direction:column!important;align-items:stretch;gap:8px}
  .ed-lista .rep-row input,
  .ed-lista .rep-row select,
  .ed-lista .rep-row textarea,
  .ed-lista .rep-row [style*="max-width"]{width:100%;max-width:none!important}
  .ed-lista .rep-row .del{opacity:1;align-self:flex-end;flex:none}
  /* Barra de guardado: el texto de ayuda a su propia línea, no pegado al botón. */
  .ed-guardar .muted{margin-left:0!important;flex:1 0 100%}
  /* Sus márgenes negativos deben igualar el padding del .erp-wrap en móvil (14px),
     no los 18px del escritorio, o sobresale por la derecha. */
  .ed-guardar{margin-left:-14px;margin-right:-14px;padding-left:14px;padding-right:14px}
}
</style>
<script>
/* Cambiar de sección. Los campos de las demás siguen en el DOM. */
document.querySelectorAll('#edSeg [data-s]').forEach(function(b){
  b.addEventListener('click', function(){
    document.querySelectorAll('#edSeg [data-s]').forEach(function(o){ o.classList.toggle('on', o===b); });
    document.querySelectorAll('.ed-sec').forEach(function(sec){ sec.classList.toggle('on', sec.dataset.s===b.dataset.s); });
    window.scrollTo({top:0, behavior:'smooth'});
  });
});
/* El enlace «Editar →» de los datos fiscales llega con #fact: abre esa sección. */
if (location.hash === '#fact') { var f=document.querySelector('#edSeg [data-s="fact"]'); if(f) f.click(); }
/* Los interruptores encienden/apagan el resaltado de su fila. */
function edSw(inp){ var l=inp.closest('.ed-sw'); if(l) l.classList.toggle('on', inp.checked); }
/* Las filas nuevas necesitan su icono de papelera: se clona de una que ya esté
   pintada, porque la plantilla es una cadena de JS y ahí no se puede llamar a ic(). */
(function(){
  var orig = window.addRow;
  if (typeof orig !== 'function') return;
  window.addRow = function(k){
    orig(k);
    var modelo = document.querySelector('.del svg');
    document.querySelectorAll('.del').forEach(function(b){
      if (modelo && !b.querySelector('svg')) b.appendChild(modelo.cloneNode(true));
    });
  };
})();
</script>

<script>
var TPL={
 fase:'<div class="rep-row"><input type="text" name="fase_t[]" placeholder="Nombre de fase"><input type="text" name="fase_s[]" placeholder="subtítulo"><select name="fase_estado[]"><option value="done">Completada</option><option value="now">Actual</option><option value="" selected>Pendiente</option></select><button type="button" class="del" onclick="delRow(this)" title="Quitar esta fila"><?= ic('trash',15) ?></button></div>',
 item:'<div class="rep-row"><input type="text" name="item_n[]" placeholder="1" style="max-width:90px"><input type="text" name="item_t[]" placeholder="Concepto"><button type="button" class="del" onclick="delRow(this)" title="Quitar esta fila"><?= ic('trash',15) ?></button></div>',
 det:'<div class="rep-row" style="flex-direction:column"><input type="text" name="det_h[]" placeholder="Título del apartado"><div style="display:flex;gap:8px;width:100%"><textarea name="det_p[]" placeholder="Descripción…" style="flex:1"></textarea><button type="button" class="del" onclick="delRow(this)" title="Quitar esta fila"><?= ic('trash',15) ?></button></div></div>',
 acc:'<div class="rep-row"><input type="text" name="acc_b[]" placeholder="Título"><input type="text" name="acc_s[]" placeholder="Descripción"><input type="url" name="acc_u[]" placeholder="https://…"><select name="acc_tipo[]"><option value="figma">Figma</option><option value="drive">Google Drive</option><option value="web">Sitio web</option><option value="looker">Looker Studio</option><option value="generic" selected>Genérico</option></select><button type="button" class="del" onclick="delRow(this)" title="Quitar esta fila"><?= ic('trash',15) ?></button></div>',
 met:'<div class="rep-row"><input type="text" name="met_mes[]" placeholder="Mes"><input type="number" name="met_ll[]" placeholder="Llam."><input type="number" name="met_wa[]" placeholder="WA"><input type="number" name="met_fo[]" placeholder="Form."><input type="number" name="met_vi[]" placeholder="Visitas"><input type="number" name="met_ap[]" placeholder="Aparic."><input type="text" name="met_ctr[]" placeholder="CTR %"><button type="button" class="del" onclick="delRow(this)" title="Quitar esta fila"><?= ic('trash',15) ?></button></div>',
 tar:'<div class="rep-row" style="flex-direction:column"><div style="display:flex;gap:8px;width:100%"><input type="text" name="tar_mes[]" placeholder="Mes" style="max-width:130px"><select name="tar_estado[]" style="max-width:150px"><option value="completado">Completado</option><option value="pendiente">En curso</option></select><input type="text" name="tar_t[]" placeholder="Título de la tarea" style="flex:1"></div><div style="display:flex;gap:8px;width:100%"><textarea name="tar_d[]" placeholder="Explicación para el cliente" style="flex:1"></textarea><button type="button" class="del" onclick="delRow(this)" title="Quitar esta fila"><?= ic('trash',15) ?></button></div></div>'
};
/* La fila nueva se inyecta en crudo, así que sus desplegables y sus fechas no
   han pasado por el arranque de la página: se les aplica aquí el mismo estilo
   que al resto, o la fila recién añadida se ve distinta a las de arriba. */
function addRow(k){var c=document.getElementById(k+'-rows');c.insertAdjacentHTML('beforeend',TPL[k]);
  var fila=c.lastElementChild;
  if(fila){if(window.csEnhance)window.csEnhance(fila);if(window.dpScan)window.dpScan(fila);}}
function delRow(b){b.closest('.rep-row').remove();}
</script>
<?php afoot(); ?>
