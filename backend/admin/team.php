<?php
/* Equipo y permisos: quién entra al panel y qué puede tocar.

   Antes esto era una tabla escueta y, debajo, un recuadro amarillo
   (#fbfbe9 sobre #ece9c2) que no existe en ninguna otra pantalla del ERP,
   explicando los tres permisos en una línea corrida. Ahora los permisos se
   explican donde se leen —junto a la etiqueta de cada miembro— y la página
   usa el avatar de color que ya se usa en el resto del ERP. */
require_once __DIR__ . '/_layout.php';
/* El permiso lo exige ya require_admin() por perm_de_pagina (equipo.gestionar).
   Aquí NO se pide require_role('owner'): eso compara el NOMBRE del rol y dejaba
   fuera a los roles propios que sí tienen el permiso — un «Coordinador» con
   «Gestionar el equipo» marcado se comía una pantalla de «no tienes permiso». */

/* Cambiar el rol de alguien se hace aquí, en su propia fila. Antes había que ir
   a team-edit.php, elegir en un desplegable y darle a guardar —tres pantallas
   para cambiar una palabra— y durante un tiempo la lista volvió a estar
   duplicada en «Roles y permisos». Aquí está la gente: aquí se le pone el rol.
   La comprobación de que siempre quede un dueño vive en rol_asignar(). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_rol') {
  header('Content-Type: application/json; charset=utf-8');
  $r = rol_asignar((int)($_POST['uid'] ?? 0), (string)($_POST['rol'] ?? ''));
  if (!$r['ok']) http_response_code(400);
  echo json_encode(['ok'=>$r['ok']?1:0, 'msg'=>$r['msg'], 'recargar'=>!empty($r['recargar'])?1:0]);
  exit;
}

/* Auto-registro por enlace: generar / anular. Solo llega aquí quien tiene
   `equipo.gestionar` (lo exige require_admin por perm_de_pagina). Nunca se permite
   generar un enlace de un rol con acceso total: nadie se auto-registra como Dueño. */
require_once __DIR__ . '/lib/signup.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gen_signup') {
  $rol   = (string)($_POST['rol'] ?? 'viewer');
  $roles = roles_todos();
  if (!isset($roles[$rol]) || in_array('admin.total', $roles[$rol]['permisos'] ?? [], true)) $rol = 'viewer';
  signup_crear($rol);
  header('Location: team.php#registro'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'del_signup') {
  signup_borrar((string)($_POST['token'] ?? ''));
  header('Location: team.php#registro'); exit;
}

if (function_exists('admin_profile_ensure')) admin_profile_ensure();   // por si la tabla de perfiles aún no existe
$admins = db()->query('SELECT a.id, a.username, a.role, a.email, a.created_at, p.cumple
                       FROM admins a LEFT JOIN admin_profiles p ON p.admin_id=a.id
                       ORDER BY a.role, a.username')->fetchAll();
$ROLES  = []; foreach (roles_todos() as $k=>$r) $ROLES[$k] = $r['nombre'];
$MES_ABR = ['','ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
$nClientes = (int)db()->query('SELECT COUNT(*) FROM clients')->fetchColumn();
$yo = current_admin();

/* Qué puede hacer cada permiso, en una frase. Se enseña bajo el nombre del rol
   en vez de en un cartel aparte al final de la página. */
/* Las descripciones salen de la tabla de roles: si el dueño crea «Comercial»,
   aquí aparece con la suya sin tocar este archivo. Antes estaban escritas para
   los tres roles fijos y un rol nuevo se quedaba sin explicación. */
$ROL_DESC = []; foreach (roles_todos() as $k=>$r) $ROL_DESC[$k] = $r['descripcion'];
ahead('Mi equipo');
?>
<style>
/* Rejilla y no flex: con flex, la descripción del rol hacía la fila más alta o
   más baja según ocupara una línea o dos, y la lista se veía a saltos (117px una
   fila, 101px la siguiente). Aquí todas las filas miden lo mismo. */
.tm-list{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
.tm-row{display:grid;grid-template-columns:40px 1fr 220px 132px;gap:16px;align-items:center;
  padding:15px 20px;border-bottom:1px solid var(--line2);transition:background .14s ease}
.tm-row:last-child{border-bottom:none}
.tm-row:hover{background:#fafbfc}

/* Avatar con distintivo de administrador. Antes era un círculo negro con un
   check: se confundía con el resto del panel, que es todo blanco y negro. Ahora
   lleva el icono de «usuario con permiso» y el mismo ámbar con el que se marca
   el acceso total en Roles y permisos, para que las dos pantallas hablen igual. */
.tm-avw{position:relative;width:38px;height:38px;flex:none}
.tm-av{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  color:#fff;font-weight:700;font-size:13.5px;text-decoration:none;transition:transform .16s cubic-bezier(.2,.7,.3,1)}
.tm-row:hover .tm-av{transform:scale(1.06)}
.tm-crown{position:absolute;right:-4px;bottom:-4px;width:19px;height:19px;border-radius:50%;
  background:#e8a33d;color:#fff;border:2px solid #fff;display:flex;align-items:center;justify-content:center;
  box-shadow:0 2px 5px rgba(184,122,31,.35);transition:transform .16s cubic-bezier(.2,.7,.3,1)}
.tm-row:hover .tm-crown{transform:scale(1.1)}
.tm-crown svg{width:11px;height:11px}
/* Y una etiqueta junto al nombre, porque el distintivo del avatar es pequeño y
   quien no sepa qué significa necesita leerlo una vez. */
.tm-admin{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;text-transform:uppercase;
  letter-spacing:.4px;background:#fff4e5;border:1px solid #f3dcbf;color:#b7791f;border-radius:99px;
  padding:1px 8px;margin-left:7px;vertical-align:2px}
.tm-admin svg{width:10px;height:10px}

.tm-id{min-width:0}
.tm-nm{font-weight:600;font-size:14.5px;color:var(--ink-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tm-nm .yo{font-weight:500;color:var(--muted);font-size:12.5px;margin-left:5px}
.tm-sub{font-size:12px;color:var(--muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* El selector: csEnhance() lo envuelve en un .cs-wrap, así que el estilo tiene
   que ir al envoltorio y no al <select>, que ya no se ve. Ese era el descuadre. */
.tm-rol{min-width:0}
.tm-rol .cs-wrap{width:100%}
.tm-rol .cs-trig{width:100%;border-color:transparent;background:transparent;font-weight:600;
  transition:background .14s ease,border-color .14s ease,box-shadow .14s ease}
.tm-row:hover .tm-rol .cs-trig{background:#fff;border-color:var(--line)}
.tm-rol select{width:100%}

/* Acciones: aparecen al pasar por encima, como en el tablero de Tareas. Ocupan
   su sitio siempre para que la fila no dé un salto al aparecer. */
.tm-act{display:flex;gap:2px;justify-content:flex-end;opacity:0;transition:opacity .16s ease}
.tm-row:hover .tm-act,.tm-row:focus-within .tm-act{opacity:1}
.tm-act .icon-btn{transition:background .12s ease,color .12s ease,transform .14s ease}
.tm-act .icon-btn:hover{background:var(--soft);color:var(--ink);transform:translateY(-1px)}
.tm-act .icon-btn.del:hover{background:#feecec;color:#c0343a}
@media(max-width:860px){
  .tm-row{grid-template-columns:40px 1fr;grid-auto-rows:auto;row-gap:10px}
  .tm-rol,.tm-act{grid-column:2}
  .tm-act{opacity:1;justify-content:flex-start}
}

/* ===== Modo oscuro (aditivo) ===== */
[data-theme=dark] .tm-list{background-color:var(--card)}
[data-theme=dark] .tm-row:hover{background-color:var(--soft)}
[data-theme=dark] .tm-crown{border-color:var(--card)}
[data-theme=dark] .tm-row:hover .tm-rol .cs-trig{background-color:var(--card)}
[data-theme=dark] .tm-act .icon-btn.del:hover{background-color:var(--danger-bg);color:var(--danger)}

/* ===== Móvil (≤640px): fila compacta y plana, no tarjeta ===== */
/* A partir de 860px la rejilla ya se apila. Aquí, en teléfono, la comprimimos:
   avatar + nombre en la línea 1, el correo pequeño en la 2, y el rol y las
   acciones en una fila corta debajo. Sin cajas, con poco padding y poco hueco
   entre filas, para que la lista sea corta. El selector de rol lleva su fondo
   siempre, ya que en táctil no hay «hover» que lo revele. */
@media(max-width:640px){
  .tm-row{padding:10px 14px;gap:3px 12px;grid-template-columns:34px 1fr}
  .tm-avw,.tm-av{width:34px;height:34px}
  .tm-nm{font-size:14px;white-space:normal;overflow:visible;text-overflow:clip}
  .tm-sub{font-size:11.5px;white-space:normal;overflow:visible;text-overflow:clip}
  /* Rol y acciones comparten UNA línea (rol izquierda, acciones derecha) para
     que cada miembro no ocupe tres filas. */
  .tm-rol{grid-column:2;grid-row:2;margin-top:5px;justify-self:start;align-self:center}
  .tm-rol .cs-wrap{width:auto}
  .tm-rol .cs-trig{width:auto;min-width:0}
  .tm-rol .cs-trig,.tm-row:hover .tm-rol .cs-trig{background:#fff;border-color:var(--line)}
  .tm-act{grid-column:2;grid-row:2;opacity:1;justify-content:flex-end;justify-self:end;align-self:center;gap:0;margin-top:5px}
}
</style>
<div class="tm-wrap">
  <div class="flex" style="margin-bottom:18px">
    <div class="sp">
      <h1>Mi equipo</h1>
      <div class="lead">Quién entra al panel y qué puede hacer · <?= count($admins) ?> persona<?= count($admins)==1?'':'s' ?> con acceso · <?= $nClientes ?> cliente<?= $nClientes==1?'':'s' ?> de alta.</div>
    </div>
    <a class="btn ghost" href="permisos.php" style="margin-right:8px"><?= ic('usercheck',15) ?> Roles y permisos</a><a class="btn" href="team-edit.php"><?= ic('plus',15) ?> Nuevo miembro</a>
  </div>

  <div class="tm-list">
  <?php foreach ($admins as $a): $r=$a['role']; $u=(string)$a['username']; $yoMismo = ($a['id']==$yo['id']);
        $esDueno = in_array('admin.total', roles_todos()[$r]['permisos'] ?? [], true); ?>
    <div class="tm-row">
      <div class="tm-avw">
        <a class="tm-av" href="perfil.php?id=<?= (int)$a['id'] ?>" data-uid="<?= (int)$a['id'] ?>" style="background:<?= avatar_color($u) ?>" title="Ver el perfil de <?= e($u) ?>"><?= e(mb_strtoupper(mb_substr($u,0,1))) ?></a>
        <?php if($esDueno): ?><span class="tm-crown" title="Administrador · acceso total"><?= ic('usercheck',11) ?></span><?php endif; ?>
      </div>
      <div class="tm-id">
        <div class="tm-nm"><?= e($u) ?><?= $yoMismo ? '<span class="yo">tú</span>' : '' ?><?php
          if($esDueno) echo '<span class="tm-admin" title="Puede todo, también gestionar el equipo y los roles">'.ic('usercheck',10).' Admin</span>'; ?></div>
        <?php /* El correo importa más que la fecha de alta: es con el que entra por
                 Google. Si no lo tiene, se dice, porque explica por qué a esa
                 persona no le funciona «Entrar con Google». */
              $mail = trim((string)($a['email'] ?? '')); ?>
        <?php $cumple = trim((string)($a['cumple'] ?? '')); $cumTxt = '';
              if ($cumple !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $cumple, $mm)) $cumTxt = (int)$mm[3].' '.$MES_ABR[(int)$mm[2]]; ?>
        <div class="tm-sub" title="<?= e($mail ?: 'Sin correo de Google') ?>">
          <?= $mail !== '' ? e($mail) : 'Sin correo · no puede entrar con Google' ?>
          · desde <?= e(substr((string)$a['created_at'],0,7)) ?><?= $cumTxt !== '' ? ' · 🎂 '.e($cumTxt) : '' ?>
        </div>
      </div>
      <div class="tm-rol">
        <?php /* Se elige aquí mismo y se guarda al momento. La descripción del rol
                 va en el title y no debajo: puesta debajo hacía las filas de dos
                 alturas distintas y la lista se veía a saltos. */ ?>
        <select data-uid="<?= (int)$a['id'] ?>" data-prev="<?= e($r) ?>" onchange="tmRol(this)" aria-label="Rol de <?= e($u) ?>" title="<?= e($ROL_DESC[$r] ?? '') ?>">
          <?php foreach ($ROLES as $k=>$nom): ?>
            <option value="<?= e($k) ?>" <?= $r===$k?'selected':'' ?>><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="tm-act">
        <a class="icon-btn" href="perfil.php?id=<?= (int)$a['id'] ?>" title="Ver su perfil"><?= ic('user',16) ?></a>
        <a class="icon-btn" href="chat.php?u=<?= (int)$a['id'] ?>" title="Escribirle por el chat"><?= ic('chat',16) ?></a>
        <a class="icon-btn" href="team-edit.php?id=<?= (int)$a['id'] ?>" title="Editar sus datos y su contraseña"><?= ic('pencil',16) ?></a>
        <?php if (!$yoMismo): ?>
          <a class="icon-btn del" href="#" title="Quitar del equipo" onclick="return erpAsk('¿Eliminar a <?= e(addslashes($u)) ?> del equipo? Perderá el acceso al panel.',{titulo:'Eliminar del equipo',ok:'Eliminar',post:'team-delete.php',data:{id:<?= (int)$a['id'] ?>},danger:true})"><?= ic('trash',16) ?></a>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <div class="lead" style="margin-top:14px;font-size:12.5px">
    ¿Quieres cambiar <b>qué puede hacer</b> un rol, o crear uno nuevo? Se hace en
    <a href="permisos.php">Roles y permisos</a>.
  </div>

  <?php /* Auto-registro por enlace: el Dueño genera un enlace temporal y quien lo
           abre crea su propia cuenta. Sin enlace vivo, nadie puede registrarse. */
        $links = signup_activos(); ?>
  <div class="su" id="registro">
    <div class="su-h">
      <div class="sp">
        <h2>Registro por enlace</h2>
        <div class="lead">Genera un enlace temporal para que alguien cree su propia cuenta. <b>Solo funciona con el enlace que generes tú</b>, es de un solo uso y caduca a las <?= (int)signup_horas() ?> h.</div>
      </div>
      <form method="post" class="su-gen">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="gen_signup">
        <label>Entrará como</label>
        <select name="rol" aria-label="Rol con el que entrará">
          <?php foreach ($ROLES as $k=>$nom): if (in_array('admin.total', roles_todos()[$k]['permisos'] ?? [], true)) continue; ?>
            <option value="<?= e($k) ?>" <?= $k==='viewer'?'selected':'' ?>><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn" type="submit"><?= ic('link',15) ?> Generar enlace</button>
      </form>
    </div>
    <?php if ($links): ?>
      <div class="su-list">
        <?php foreach ($links as $l): $url = signup_url($l['token']); $rn = $ROLES[$l['rol']] ?? $l['rol'];
              $hLeft = max(1, (int)round(($l['caduca']-time())/3600)); ?>
          <div class="su-row">
            <span class="su-ic"><?= ic('link',17) ?></span>
            <div class="su-info">
              <div class="su-t">Enlace de registro <span class="su-tag"><?= e($rn) ?></span></div>
              <div class="su-m">Un solo uso · caduca en ~<?= $hLeft ?> h</div>
            </div>
            <button type="button" class="btn sm su-copy" data-url="<?= e($url) ?>" onclick="suCopy(this)"><?= ic('link',14) ?> Copiar enlace</button>
            <form method="post" style="display:inline;margin:0" onsubmit="return erpSubmitAsk(this,'Quien tenga este enlace ya no podrá registrarse.',{titulo:'¿Anular el enlace?',ok:'Anular'})">
              <?= csrf_field() ?><input type="hidden" name="action" value="del_signup"><input type="hidden" name="token" value="<?= e($l['token']) ?>">
              <button class="icon-btn del" type="submit" title="Anular el enlace"><?= ic('trash',16) ?></button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="su-empty">No hay ningún enlace activo. Genera uno cuando quieras dar de alta a alguien.</div>
    <?php endif; ?>
  </div>
</div>

<style>
.su{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px 26px;margin-top:24px}
.su-h{display:flex;align-items:flex-start;gap:18px;flex-wrap:wrap}
.su h2{font-size:16px;font-weight:600;margin:0 0 4px;color:var(--ink-strong)}
.su-gen{display:flex;align-items:center;gap:8px;flex:none}
.su-gen label{font-size:12.5px;color:var(--muted);font-weight:600}
.su-gen select{min-width:150px}
.su-list{margin-top:18px;display:flex;flex-direction:column;gap:11px}
.su-row{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px 14px 14px 16px}
.su-ic{width:34px;height:34px;flex:none;border-radius:9px;background:var(--soft);color:var(--muted);display:flex;align-items:center;justify-content:center}
.su-info{flex:1;min-width:0}
.su-t{font-size:14px;font-weight:600;color:var(--ink-strong);display:flex;align-items:center;gap:8px}
.su-m{font-size:12px;color:var(--muted);margin-top:3px;line-height:1.5}
.su-tag{flex:none;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;background:var(--accent-soft);border-radius:99px;padding:2px 9px;color:var(--ink)}
.su-copy{flex:none}
.su-empty{margin-top:14px;font-size:12.5px;color:var(--muted)}
@media(max-width:720px){ .su-h{flex-direction:column} .su-gen{width:100%} .su-row{flex-wrap:wrap} .su-copy{flex:1} }

/* ===== Modo oscuro (aditivo) ===== */
[data-theme=dark] .su{background-color:var(--card)}
[data-theme=dark] .su-row{background-color:var(--card)}

/* ===== Móvil (≤640px): bloque de registro por enlace usable ===== */
@media(max-width:640px){
  .su{padding:20px 16px}
  .su-gen{flex-wrap:wrap}
  .su-gen label{width:100%}
  .su-gen select{flex:1;min-width:0}
  .su-row{padding:12px}
}
</style>
<script>
function suCopy(btn){
  var url = btn.getAttribute('data-url') || '';
  try { navigator.clipboard.writeText(url); }
  catch(e){ var t=document.createElement('textarea'); t.value=url; document.body.appendChild(t); t.select(); try{document.execCommand('copy');}catch(_){}; t.remove(); }
  if (window.toast) toast('Enlace copiado');
}
</script>

<script>
/* Las descripciones de todos los roles, para poder cambiar la de debajo del
   desplegable sin ir al servidor. */
var TM_DESC = <?= json_encode($ROL_DESC, JSON_UNESCAPED_UNICODE) ?>;
/* Los roles con acceso total, para poner o quitar la marca del avatar sin
   recargar cuando se le cambia el rol a alguien. */
var TM_DUENOS = <?= json_encode(array_values(array_keys(array_filter(roles_todos(), function($r){ return in_array('admin.total',$r['permisos'],true); }))), JSON_UNESCAPED_UNICODE) ?>;

function tmRol(sel){
  var antes = sel.dataset.prev, fila = sel.closest('.tm-row');
  sel.title = TM_DESC[sel.value] || '';
  var b = new URLSearchParams();
  b.append('action','set_rol'); b.append('uid',sel.dataset.uid); b.append('rol',sel.value);
  fetch('team.php',{method:'POST',body:b})
    .then(function(r){ return r.json().catch(function(){ return {ok:0}; }); })
    .then(function(j){
      if (j && j.ok) {
        sel.dataset.prev = sel.value;
        tmMarca(fila, TM_DUENOS.indexOf(sel.value) >= 0);
        toast('Rol actualizado');
        if (j.recargar) location.reload();
      } else {
        sel.value = antes; sel.title = TM_DESC[antes] || '';
        toast((j && j.msg) || 'No se ha podido cambiar el rol','err');
      }
    })
    .catch(function(){ sel.value = antes; sel.title = TM_DESC[antes] || ''; toast('No se ha podido cambiar el rol','err'); });
}

/* Pone o quita el distintivo de administrador (el del avatar y la etiqueta del
   nombre). Los iconos se clonan de otra fila que ya los tenga, para no escribir
   un SVG a mano aquí (docs/05 §3: los iconos salen de ic()). */
function tmMarca(fila, esDueno){
  if (!fila) return;
  var w = fila.querySelector('.tm-avw'), nm = fila.querySelector('.tm-nm');
  var marca = w ? w.querySelector('.tm-crown') : null;
  var etiq  = nm ? nm.querySelector('.tm-admin') : null;
  if (esDueno === !!marca) return;
  if (!esDueno) { if (marca) marca.remove(); if (etiq) etiq.remove(); return; }
  var mMarca = document.querySelector('.tm-crown'), mEtiq = document.querySelector('.tm-admin');
  if (mMarca && w)  w.appendChild(mMarca.cloneNode(true));
  if (mEtiq  && nm) nm.appendChild(mEtiq.cloneNode(true));
}
</script>
<?php afoot(); ?>
