<?php
/* Roles y permisos: qué puede hacer cada rol.

   Una rejilla: filas = lo que se puede hacer, columnas = roles, y cada cruce una
   casilla que se marca y se guarda sola. A quién se le pone cada rol se decide en
   «Mi equipo», donde está la lista de personas.

   Construida con el vocabulario del ERP (docs/05 §11), no con uno propio:
   `.card` para los bloques, `.sec-t` para los títulos de sección, `.tag` para las
   etiquetas de rol, `.btn`/`.icon-btn` para las acciones y casillas nativas con
   `accent-color`, que es como se pintan las casillas en el resto del panel
   (`.nf-chk` en Ajustes). Antes esta pantalla tenía su propio juego de clases
   `.pm-*` con cajas de otro radio y casillas verdes: el ERP es blanco, negro y
   grises, y el color se reserva para señales de estado (docs/05 §2). */
require_once __DIR__ . '/../auth.php';
require_admin();                       // pide roles.gestionar (perm_de_pagina)

/* Guarda la lista de permisos de un rol y **devuelve la lista que ha quedado**.

   Eso último importa: rol_guardar() completa los requisitos por su cuenta, así
   que lo guardado casi nunca es exactamente lo enviado. Devolviéndolo, la matriz
   se repinta con la verdad del servidor en vez de intentar adivinar qué casillas
   se han movido por dependencia — que es justo donde antes se descuadraba. */
function pm_aplicar($clave, array $permisos, array $roles) {
  $r = rol_guardar($clave, $roles[$clave]['nombre'], $roles[$clave]['descripcion'], $permisos);
  if (!$r['ok']) { http_response_code(400); return ['ok'=>0,'msg'=>$r['msg']]; }
  roles_todos(true);
  $ahora = roles_todos()[$clave]['permisos'] ?? [];
  return [
    'ok'       => 1,
    'permisos' => array_values($ahora),
    /* Si te acabas de tocar tu propio rol, lo que ves ya no es lo que puedes. */
    'recargar' => ($clave === (string)(current_admin()['role'] ?? '')) ? 1 : 0,
  ];
}

/* Todo el POST antes de erp_nav.php y terminando en exit (docs/05 §12). */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');
  $a     = $_POST['action'] ?? '';
  $roles = roles_todos();

  if ($a === 'toggle') {
    $clave = (string)($_POST['rol'] ?? ''); $perm = (string)($_POST['perm'] ?? '');
    $on    = ($_POST['on'] ?? '') === '1';
    if (!isset($roles[$clave]))               { http_response_code(400); echo json_encode(['ok'=>0,'msg'=>'Ese rol ya no existe.']); exit; }
    if (!in_array($perm, perm_todas(), true)) { http_response_code(400); echo json_encode(['ok'=>0,'msg'=>'Ese permiso no existe.']); exit; }
    /* rol_guardar() se lo devolvería puesto igualmente, pero entonces respondería
       «guardado» sin haber guardado nada, que engaña más que un error. */
    if ($clave === 'owner' && $perm === 'admin.total' && !$on) {
      http_response_code(400);
      echo json_encode(['ok'=>0,'msg'=>'Al rol Dueño no se le puede quitar el acceso total: es lo que impide que el ERP se quede sin nadie que pueda gestionar roles.']);
      exit;
    }
    $p = $roles[$clave]['permisos'];
    if ($on) {
      /* Al encender, rol_guardar() completa los requisitos por su cuenta. */
      $p = array_values(array_unique(array_merge($p, [$perm])));
    } else {
      /* Al apagar hay que quitar también lo que dependía de él, o quedarían
         permisos huérfanos que rol_guardar() volvería a rellenar en el acto y
         daría la sensación de que la casilla «no se apaga». */
      $quitar = array_merge([$perm], perm_dependientes($perm));
      $p = array_values(array_diff($p, $quitar));
    }
    echo json_encode(pm_aplicar($clave, $p, $roles)); exit;
  }

  /* Marcar o quitar una sección entera de golpe para un rol.

     Es lo que evita el clic a clic: «Qué módulos ve» son trece casillas, y montar
     un rol nuevo a mano eran cuarenta y tantos clics repartidos por ocho bloques.

     `admin.total` queda FUERA de los cambios en bloque a propósito: es la llave
     maestra —quien la tiene puede todo, ahora y lo que se invente después— y no
     se debe dar de refilón por pulsar «Todo» en su sección. Esa se marca a mano. */
  if ($a === 'grupo') {
    $clave = (string)($_POST['rol'] ?? ''); $grupo = (string)($_POST['grupo'] ?? '');
    $on    = ($_POST['on'] ?? '') === '1';
    $cat   = perm_catalogo();
    if (!isset($roles[$clave])) { http_response_code(400); echo json_encode(['ok'=>0,'msg'=>'Ese rol ya no existe.']); exit; }
    if (!isset($cat[$grupo]))   { http_response_code(400); echo json_encode(['ok'=>0,'msg'=>'Esa sección no existe.']); exit; }

    $claves = array_values(array_diff(array_keys($cat[$grupo]), ['admin.total']));
    $p = $roles[$clave]['permisos'];
    if ($on) {
      /* rol_guardar() añade por su cuenta lo que haga falta de otras secciones. */
      $p = array_merge($p, $claves);
    } else {
      /* Al quitar hay que llevarse también lo que dependía de estos permisos, o
         rol_guardar() los repondría en el acto y parecería que no se ha apagado. */
      $quitar = $claves;
      foreach ($claves as $k) $quitar = array_merge($quitar, perm_dependientes($k));
      $p = array_values(array_diff($p, array_unique($quitar)));
    }
    echo json_encode(pm_aplicar($clave, $p, $roles)); exit;
  }

  if ($a === 'crear') {
    /* Nace sin permisos a propósito: es más seguro darlos de uno en uno que
       quitarlos de una lista que ya lo permitía todo. */
    $r = rol_guardar('', trim((string)($_POST['nombre'] ?? '')), '', []);
    if (!$r['ok']) http_response_code(400);
    echo json_encode($r['ok'] ? ['ok'=>1,'clave'=>$r['clave']] : ['ok'=>0,'msg'=>$r['msg']]); exit;
  }

  if ($a === 'renombrar') {
    $clave = (string)($_POST['rol'] ?? '');
    if (!isset($roles[$clave])) { http_response_code(400); echo json_encode(['ok'=>0,'msg'=>'Ese rol ya no existe.']); exit; }
    $r = rol_guardar($clave, trim((string)($_POST['nombre'] ?? '')), $roles[$clave]['descripcion'], $roles[$clave]['permisos']);
    if (!$r['ok']) http_response_code(400);
    echo json_encode($r['ok'] ? ['ok'=>1] : ['ok'=>0,'msg'=>$r['msg']]); exit;
  }

  if ($a === 'borrar') {
    $r = rol_borrar((string)($_POST['rol'] ?? ''));
    if (!$r['ok']) http_response_code(400);
    echo json_encode($r['ok'] ? ['ok'=>1] : ['ok'=>0,'msg'=>$r['msg']]); exit;
  }

  http_response_code(400); echo json_encode(['ok'=>0,'msg'=>'Acción desconocida.']); exit;
}

require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/ajustes_nav.php';
roles_ensure();

$roles = roles_todos();
$uso   = roles_uso();
$cat   = perm_catalogo();
$nEquipo = 0;
try { $nEquipo = (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn(); } catch (Exception $e) {}

aj_head('permisos', '', 'Marca lo que puede hacer cada rol. Se guarda solo, al momento.',
  '<button class="btn" onclick="pmNuevo()">'.ic('plus',15).' Nuevo rol</button>');
?>
<style>
/* Las columnas se declaran una sola vez y las heredan la cabecera y todas las
   filas de todas las tarjetas: así quedan alineadas aunque cada sección sea una
   .card independiente. */
.pm{--pm-cols:minmax(230px,1fr) repeat(var(--pm-n),minmax(104px,124px));overflow-x:auto}
.pm-in{min-width:min-content}
.pm .card{padding:0;overflow:hidden}

/* Cabecera de roles. Se queda arriba al recorrer los permisos. */
.pm-h{display:grid;grid-template-columns:var(--pm-cols);align-items:end;position:sticky;top:0;z-index:5;
  background:var(--bg);padding:2px 0 10px;margin:0 1px}
.pm-h .q{font-size:12.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);font-weight:600;padding:0 2px 7px}
.pm-rol{text-align:center;padding:0 6px}
.pm-rn{border:1px solid transparent;background:none;font:inherit;font-size:13px;font-weight:600;color:var(--ink-strong);
  text-align:center;width:100%;padding:5px 4px;border-radius:8px;cursor:text}
.pm-rn:hover{background:var(--soft)}
.pm-rn:focus{outline:none;background:#fff;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.pm-meta{display:flex;align-items:center;justify-content:center;gap:5px;margin-top:5px;min-height:22px}
.pm-rol .icon-btn{opacity:0;transition:opacity .14s ease}
.pm-rol:hover .icon-btn{opacity:1}
.pm-rol .icon-btn:hover{background:#feecec;color:#c0343a}

/* Cabecera de cada sección, con las mismas columnas que las filas. */
.pm-g{display:grid;grid-template-columns:var(--pm-cols);align-items:end;margin:24px 1px 6px}
.pm-g:first-of-type{margin-top:14px}
.pm-gq{display:flex;align-items:center;gap:8px;padding:0 2px 5px;min-width:0}
.pm-gq .tag{font-size:10px;letter-spacing:.3px}
.pm-gc{display:flex;justify-content:center;padding:0 6px 4px}
.pm-todo{border:1px solid var(--line);background:var(--bg);border-radius:7px;font:inherit;font-size:11px;font-weight:600;
  color:var(--muted);padding:3px 10px;cursor:pointer;letter-spacing:.2px;
  transition:color .14s ease,border-color .14s ease,background .14s ease,transform .12s ease}
.pm-todo:hover{color:var(--ink-strong);border-color:#c9ccd1;background:var(--soft)}
.pm-todo:active{transform:scale(.94)}
.pm-todo.quitar:hover{color:#c0343a;border-color:#f0c9c9;background:#feecec}

/* Filas */
.pm-r{display:grid;grid-template-columns:var(--pm-cols);align-items:center;border-bottom:1px solid var(--line2)}
.pm .card .pm-r:last-child{border-bottom:none}
.pm-r:hover{background:#fafbfc}
.pm-q{padding:14px 18px;min-width:0}
.pm-q b{font-size:14px;font-weight:600;color:var(--ink-strong);display:block}
.pm-q span{font-size:12px;color:var(--muted);line-height:1.5;display:block;margin-top:4px}
.pm-c{display:flex;align-items:center;justify-content:center;align-self:stretch;padding:7px;transition:background .14s ease}
.pm-c.total{background:var(--soft)}
.pm-r:hover .pm-c:hover{background:var(--accent-soft)}
/* El interruptor es .sw, de erp_nav.php. Aquí va en negro (el acento) y no en
   verde: verde en este ERP significa «está funcionando», y un permiso no es un
   estado, es una decisión. */

.pm-pie{font-size:12.5px;color:var(--muted);margin-top:2px;display:flex;align-items:center;gap:7px}
.pm-pie svg{width:14px;height:14px;color:var(--label)}
@media(max-width:700px){ .pm{--pm-cols:minmax(170px,1fr) repeat(var(--pm-n),92px)} .pm-q{padding:10px 12px} .pm-q span{display:none} }
/* Móvil (≤640px): la matriz se desplaza en horizontal (ya hay overflow-x en .pm)
   y la primera columna —el nombre del permiso— queda fija para no perder la
   referencia de a qué corresponde cada columna de rol al desplazarse. Los fondos
   usan tokens que ya cambian solos en modo oscuro. */
@media(max-width:640px){
  .pm-q,.pm-gq{position:sticky;left:0;z-index:3;background:var(--card)}
  .pm-gq{background:var(--bg)}
  .pm-h .q{position:sticky;left:0;z-index:6;background:var(--bg)}
}
/* Modo oscuro: foco del nombre de rol, botones "todo/quitar" y hover de fila. */
[data-theme=dark] .pm-rn:focus{background-color:var(--field)}
[data-theme=dark] .pm-rol .icon-btn:hover{background-color:var(--danger-bg);color:var(--danger)}
[data-theme=dark] .pm-todo:hover{border-color:var(--line-strong)}
[data-theme=dark] .pm-todo.quitar:hover{color:var(--danger);border-color:var(--danger-line);background-color:var(--danger-bg)}
[data-theme=dark] .pm-r:hover{background-color:var(--soft)}
[data-theme=dark] .pm-pie svg{color:var(--muted)}
</style>

<div class="pm" style="--pm-n:<?= count($roles) ?>">
  <div class="pm-in">

    <div class="pm-h">
      <div class="q">Permiso</div>
      <?php foreach($roles as $k=>$r): $n=(int)($uso[$k] ?? 0); $total=in_array('admin.total',$r['permisos'],true); ?>
        <div class="pm-rol">
          <input class="pm-rn" value="<?= e($r['nombre']) ?>" data-rol="<?= e($k) ?>" data-prev="<?= e($r['nombre']) ?>"
                 onchange="pmRenombrar(this)" onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}"
                 title="Escribe encima para cambiarle el nombre">
          <div class="pm-meta">
            <span class="tag <?= $total?'on':'' ?>"><?= $total ? 'Todo' : $n.' pers.' ?></span>
            <?php /* El aspa solo en los roles que se pueden borrar, y solo al pasar
                     por encima: los tres del sistema y los que tenga alguien puesto
                     no la llevan. */
                  if(!$r['sistema'] && !$n): ?>
              <button class="icon-btn" onclick="pmBorrar('<?= e(addslashes($k)) ?>','<?= e(addslashes($r['nombre'])) ?>')" title="Eliminar este rol"><?= ic('trash',14) ?></button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php foreach($cat as $grupo=>$items):
          /* La sección se marca como configuración si la mayoría de lo que hay
             dentro lo es. «Administración» tiene un permiso de trabajo suelto
             (Guardar cambios) y aun así es configuración; «Dinero» tiene uno de
             configuración (los datos fiscales) y aun así es trabajo. */
          $nConf = count(array_intersect(array_keys($items), perm_config()));
          $esConfig = $nConf * 2 > count($items); ?>
      <?php /* La cabecera de sección usa las MISMAS columnas que las filas, así que
               el botón de «Todo / Nada» cae justo encima de la columna de su rol.
               Sin esto había que marcar trece casillas para dar «Qué módulos ve». */ ?>
      <div class="pm-g" data-g="<?= e($grupo) ?>">
        <div class="pm-gq">
          <span class="sec-t" style="margin:0"><?= e($grupo) ?></span>
          <?php if($esConfig): ?><span class="tag" title="Cambia cómo funciona el ERP para todos, o quién entra en él">Configuración</span><?php endif; ?>
        </div>
        <?php foreach($roles as $k=>$r): $total = in_array('admin.total',$r['permisos'],true); ?>
          <div class="pm-gc">
            <?php /* Al rol con acceso total no se le marca nada: ya lo tiene todo. */
                  if(!$total): ?>
              <button class="pm-todo" data-rol="<?= e($k) ?>" data-grupo="<?= e($grupo) ?>" onclick="pmGrupo(this)"></button>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="card">
        <?php foreach($items as $clave=>$d): ?>
          <div class="pm-r">
            <div class="pm-q"><b><?= e($d[0]) ?></b><span><?= e($d[1]) ?></span></div>
            <?php foreach($roles as $k=>$r):
                  $total = in_array('admin.total',$r['permisos'],true);
                  /* Quien tiene acceso total sale con todo marcado y bloqueado: es
                     lo que pasa de verdad, y desmarcarle una casilla no cambiaría
                     nada. Al rol Dueño tampoco se le quita su propio acceso total. */
                  $fijo = ($total && $clave!=='admin.total') || ($clave==='admin.total' && $k==='owner');
                  $on   = $fijo ? true : in_array($clave,$r['permisos'],true); ?>
              <div class="pm-c <?= $total?'total':'' ?>">
                <label class="sw" title="<?= e($fijo ? $r['nombre'].' tiene acceso total: puede esto y todo lo demás' : $r['nombre'].' · '.$d[0]) ?>">
                  <input type="checkbox" <?= $on?'checked':'' ?> <?= $fijo?'disabled':'' ?>
                         data-rol="<?= e($k) ?>" data-perm="<?= e($clave) ?>"
                         aria-label="<?= e($d[0].' · '.$r['nombre']) ?>"
                         <?= $fijo?'':'onchange="pmToggle(this)"' ?>>
                  <span class="tr"></span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

  </div>
</div>

<div class="pm-pie">
  <?= ic('user',14) ?>
  <span>Para darle un rol a alguien, ve a <a href="team.php">Mi equipo</a><?php if($nEquipo): ?> · <?= (int)$nEquipo ?> persona<?= $nEquipo==1?'':'s' ?> con acceso<?php endif; ?>.</span>
</div>

<script>
/* Con URLSearchParams el envoltorio de fetch de erp_nav.php añade el _csrf
   además de la cabecera (erpPost() no vale: ese manda un formulario y recarga). */
function pmPost(datos){
  var b=new URLSearchParams();
  Object.keys(datos).forEach(function(k){ b.append(k,datos[k]); });
  return fetch('permisos.php',{method:'POST',body:b})
    .then(function(r){ return r.json().catch(function(){ return {ok:0,msg:'Respuesta inesperada del servidor'}; }); });
}

/* Requisitos entre permisos: qué necesita cada uno y qué se cae con él. */
var PM_REQ  = <?= json_encode(perm_requisitos(), JSON_UNESCAPED_UNICODE) ?>;
var PM_ETIQ = <?php $et=[]; foreach(perm_catalogo() as $g) foreach($g as $k=>$d) $et[$k]=$d[0]; echo json_encode($et, JSON_UNESCAPED_UNICODE); ?>;

function pmEtiq(k){ return PM_ETIQ[k] || k; }
function pmLista(arr){
  var n = arr.map(pmEtiq);
  return n.length===1 ? '«'+n[0]+'»' : '«'+n.slice(0,-1).join('», «')+'» y «'+n[n.length-1]+'»';
}
/* Qué tiene marcado ahora mismo un rol, leído de la propia matriz. */
function pmDeRol(rol){
  var out=[];
  document.querySelectorAll('.pm-c .sw input[data-rol="'+rol+'"]').forEach(function(i){ if(i.checked) out.push(i.dataset.perm); });
  return out;
}

/* La casilla cambia ya y se revierte si el servidor dice que no: esperar a la
   respuesta para pintar hace que marcar diez seguidas se sienta lento.
   Antes de eso se avisa si el cambio arrastra a otros permisos. */
function pmToggle(inp){
  var on = inp.checked, rol = inp.dataset.rol, perm = inp.dataset.perm;
  var tengo = pmDeRol(rol);

  /* El acceso total se pregunta SIEMPRE. Es la única casilla que no se puede
     deshacer sin consecuencias: quien la tiene puede todo —incluido cambiar
     roles, vaciar la papelera y tocar la base de datos en bruto— y además se
     lleva de regalo cualquier permiso que se invente en el futuro. Un clic de
     más en la fila equivocada no debería repartir el ERP entero. */
  if (on && perm === 'admin.total') {
    inp.checked = false;
    erpConfirm('Todo el que tenga el rol «'+pmNombreRol(rol)+'» podrá hacer CUALQUIER cosa en el ERP: cambiar roles, ver y tocar el dinero, vaciar la papelera y editar la base de datos. También tendrá los permisos que se añadan en el futuro.',
      {titulo:'¿Darle acceso total?', ok:'Sí, acceso total', danger:true})
      .then(function(si){ if(si){ inp.checked = true; pmGuardar(inp, true, []); } });
    return;
  }

  /* Al ENCENDER: ¿le falta algún requisito? */
  if (on) {
    var faltan = (PM_REQ[perm]||[]).filter(function(r){ return tengo.indexOf(r)<0; });
    if (faltan.length) {
      inp.checked = false;   // no se marca hasta que confirme
      erpConfirm('«'+pmEtiq(perm)+'» no sirve de nada sin '+pmLista(faltan)+'. Se activarán también.',
        {titulo:'Hacen falta otros permisos', ok:'Activar los '+(faltan.length+1)})
        .then(function(si){ if(si){ inp.checked = true; pmGuardar(inp, true, faltan); } });
      return;
    }
  }
  /* Al APAGAR: ¿hay permisos que dependían de este y los tiene marcados? */
  else {
    var caen = Object.keys(PM_REQ).filter(function(p){
      return (PM_REQ[p]||[]).indexOf(perm)>=0 && tengo.indexOf(p)>=0;
    });
    if (caen.length) {
      inp.checked = true;    // no se desmarca hasta que confirme
      erpConfirm('Sin «'+pmEtiq(perm)+'» no se puede usar '+pmLista(caen)+'. Se apagarán también.',
        {titulo:'Se apagarán otros permisos', ok:'Apagar los '+(caen.length+1), danger:true})
        .then(function(si){ if(si){ inp.checked = false; pmGuardar(inp, false, caen); } });
      return;
    }
  }
  pmGuardar(inp, on, []);
}

/* Pone la columna de un rol exactamente como dice el servidor.
   Es la única fuente de verdad: rol_guardar() completa requisitos por su cuenta,
   así que lo guardado no tiene por qué ser lo enviado. */
function pmSync(rol, permisos){
  var tiene = {}; (permisos||[]).forEach(function(p){ tiene[p]=1; });
  var cambiadas = 0;
  document.querySelectorAll('.pm-c .sw input[data-rol="'+rol+'"]').forEach(function(i){
    if (i.disabled) return;
    var deb = !!tiene[i.dataset.perm];
    if (i.checked !== deb) { i.checked = deb; cambiadas++; }
  });
  pmBotones(rol);
  return cambiadas;
}

/* Repinta los botones «Todo / Nada» de un rol según lo que tenga marcado. */
function pmBotones(rol){
  document.querySelectorAll('.pm-todo[data-rol="'+rol+'"]').forEach(function(b){
    var caj = pmCajas(rol, b.dataset.grupo);
    if (!caj.length) { b.style.display='none'; return; }
    var todas = caj.every(function(i){ return i.checked; });
    b.textContent = todas ? 'Quitar' : 'Todo';
    b.classList.toggle('quitar', todas);
    b.title = (todas ? 'Quitarle a este rol las ' : 'Darle a este rol las ') + caj.length + ' de «'+b.dataset.grupo+'»';
  });
}

/* Las casillas de un rol dentro de una sección, sin contar las fijas. */
function pmCajas(rol, grupo){
  var card = document.querySelector('.pm-g[data-g="'+CSS.escape(grupo)+'"]');
  card = card && card.nextElementSibling;
  if (!card) return [];
  return [].slice.call(card.querySelectorAll('.pm-c .sw input[data-rol="'+rol+'"]'))
           .filter(function(i){ return !i.disabled; });
}

/* Marcar o quitar una sección entera. Se avisa antes: son muchos permisos de
   golpe y algunos arrastran a otras secciones. */
function pmGrupo(btn){
  var rol = btn.dataset.rol, grupo = btn.dataset.grupo;
  var quitar = btn.classList.contains('quitar');
  var n = pmCajas(rol, grupo).length;
  var nombre = pmNombreRol(rol);
  var msg = quitar
    ? 'Se le quitan a «'+nombre+'» los '+n+' permisos de «'+grupo+'», y lo que dependa de ellos.'
    : 'Se le dan a «'+nombre+'» los '+n+' permisos de «'+grupo+'». Si alguno necesita otro de otra sección, se activa también.';
  erpConfirm(msg, {titulo: (quitar?'Quitar «':'Dar «')+grupo+'»', ok: quitar?'Quitar los '+n:'Dar los '+n, danger: quitar})
    .then(function(si){
      if (!si) return;
      btn.disabled = true;
      pmPost({action:'grupo', rol:rol, grupo:grupo, on: quitar?'0':'1'}).then(function(j){
        btn.disabled = false;
        if (j && j.ok) {
          pmSync(rol, j.permisos);
          toast(quitar ? 'Quitados los permisos de «'+grupo+'»' : 'Activados los permisos de «'+grupo+'»');
          if (j.recargar) location.reload();
        } else toast((j&&j.msg)||'No se ha podido guardar','err');
      }).catch(function(){ btn.disabled=false; toast('No se ha podido guardar','err'); });
    });
}

function pmNombreRol(rol){
  var i = document.querySelector('.pm-rn[data-rol="'+rol+'"]');
  return i ? i.value : rol;
}

/* Guarda el cambio y deja la columna como haya quedado en el servidor. */
function pmGuardar(inp, on, arrastrados){
  var sw = inp.closest('.sw'), rol = inp.dataset.rol;
  if (sw) sw.classList.add('sw-guardando');
  pmPost({action:'toggle', rol:rol, perm:inp.dataset.perm, on:on?'1':'0'})
    .then(function(j){
      if (sw) sw.classList.remove('sw-guardando');
      if (j && j.ok) {
        var movidas = pmSync(rol, j.permisos);
        if (arrastrados.length) toast(on ? 'Activados '+(arrastrados.length+1)+' permisos' : 'Apagados '+(arrastrados.length+1)+' permisos');
        if (j.recargar) location.reload();
      } else {
        inp.checked = !on; toast((j&&j.msg)||'No se ha podido guardar','err');
      }
    })
    .catch(function(){ if(sw) sw.classList.remove('sw-guardando'); inp.checked=!on; toast('No se ha podido guardar','err'); });
}

function pmRenombrar(inp){
  var nombre=inp.value.trim();
  if(nombre==='' || nombre===inp.dataset.prev){ inp.value=inp.dataset.prev; return; }
  pmPost({action:'renombrar', rol:inp.dataset.rol, nombre:nombre}).then(function(j){
    if(j && j.ok){ inp.dataset.prev=nombre; toast('Rol renombrado'); }
    else { inp.value=inp.dataset.prev; toast((j&&j.msg)||'No se ha podido renombrar','err'); }
  });
}

function pmNuevo(){
  erpPrompt('¿Cómo se llama el rol nuevo?','',{msg:'Nacerá sin permisos: se los marcas en su columna.',placeholder:'Ej: Comercial, Contable, Becario',ok:'Crear rol'})
    .then(function(nombre){
      if(!nombre) return;
      return pmPost({action:'crear', nombre:nombre}).then(function(j){
        if(j && j.ok) location.reload(); else toast((j&&j.msg)||'No se ha podido crear','err');
      });
    });
}

/* Al cargar, cada botón dice ya si esa sección está entera o no. */
document.querySelectorAll('.pm-rn').forEach(function(i){ pmBotones(i.dataset.rol); });

function pmBorrar(clave,nombre){
  erpConfirm('¿Eliminar el rol «'+nombre+'»?',{titulo:'Eliminar rol',ok:'Eliminar',danger:true}).then(function(si){
    if(!si) return;
    pmPost({action:'borrar', rol:clave}).then(function(j){
      if(j && j.ok) location.reload(); else toast((j&&j.msg)||'No se ha podido eliminar','err');
    });
  });
}
</script>
<?php aj_foot(); ?>
