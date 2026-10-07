<?php
/* Ficha completa de una persona del equipo: sus datos de acceso, su rol, sus
   datos de autónomo y su contraseña.

   Sobre la contraseña, que es lo que más confusión genera: en `admins.password_hash`
   **no se guarda la contraseña**, se guarda un hash irreversible (password_hash de
   PHP). Ni el Dueño ni nadie puede leer la que cada uno se pone; no es una
   limitación del ERP, es cómo funciona guardar contraseñas de forma segura. Lo
   que sí tiene el Dueño es el control equivalente: puede cambiársela cuando
   quiera, por cualquiera de las tres vías de abajo, así que nunca se queda fuera
   de una cuenta.

   Tres vías, por si acaso:
     1. Ponerla él directamente  → la escribe y la sabe, porque la elige él.
     2. Generar una             → sale en pantalla una vez, para copiarla y pasarla.
     3. Enlace para que la ponga esa persona → 48 h, un solo uso (lib/pwreset.php).
        No se envía por correo porque este ERP no manda correos: se copia el
        enlace y se le pasa por donde sea. */
require_once __DIR__ . '/_layout.php';
/* Permiso equipo.gestionar, exigido por require_admin() (ver team.php). */
require_once __DIR__ . '/lib/pwreset.php';
/* svc_logo() — el logo de Google de verdad, el mismo que usa Integraciones.
   Hay que pedirlo aquí: erp_nav.php solo carga esta librería dentro de
   erp_foot(), o sea DESPUÉS de pintar la página. */
require_once __DIR__ . '/lib/logos.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];
$m = ['username'=>'', 'role'=>'editor', 'email'=>'', 'es_autonomo'=>0, 'tarifa_hora'=>'', 'iva_pct'=>'', 'irpf_pct'=>''];

if ($id) {
    $st = db()->prepare('SELECT * FROM admins WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { header('Location: team.php'); exit; }
    foreach (array_keys($m) as $k) if (array_key_exists($k, $row)) $m[$k] = $row[$k];
    $m['email'] = $row['email'] ?? '';
}

$rolesT = roles_todos();
$yo     = current_admin();

/* ---------- Acciones sobre la contraseña ---------- */
if ($id && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $acc = (string)($_POST['action'] ?? '');

  /* 2) Generar una legible y enseñarla una vez. */
  if ($acc === 'gen_pass') {
    require_once __DIR__ . '/lib/puentes.php';   // pu_password()
    $nueva = function_exists('pu_password') ? pu_password() : bin2hex(random_bytes(4));
    db()->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($nueva, PASSWORD_DEFAULT), $id]);
    pwreset_limpiar($id);                        // un enlace pendiente ya no vale
    $_SESSION['team_newpass'] = $nueva;
    header('Location: team-edit.php?id='.$id.'&ok=pass'); exit;
  }

  /* 1) Ponerla el Dueño a mano. */
  if ($acc === 'set_pass') {
    $p = (string)($_POST['nueva'] ?? '');
    if (strlen($p) < 6) { $errors[] = 'La contraseña debe tener al menos 6 caracteres.'; }
    else {
      db()->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($p, PASSWORD_DEFAULT), $id]);
      pwreset_limpiar($id);
      header('Location: team-edit.php?id='.$id.'&ok=setpass'); exit;
    }
  }

  /* 3) Enlace para que se la ponga esa persona. */
  if ($acc === 'link_pass') {
    $t = pwreset_crear($id);
    header('Location: team-edit.php?id='.$id.($t ? '&ok=link' : '&ok=err')); exit;
  }
  if ($acc === 'quitar_link') { pwreset_limpiar($id); header('Location: team-edit.php?id='.$id.'&ok=nolink'); exit; }

  /* ---------- Guardar sus datos ---------- */
  if ($acc === 'guardar') {
    $username = trim($_POST['username'] ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $role     = isset($rolesT[$_POST['role'] ?? '']) ? (string)$_POST['role'] : (string)$m['role'];

    if ($username === '') $errors[] = 'El usuario es obligatorio.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo de Google no tiene un formato válido.';

    $chk = db()->prepare('SELECT id FROM admins WHERE username=? AND id<>?'); $chk->execute([$username,$id]);
    if ($chk->fetch()) $errors[] = 'Ese usuario ya lo tiene otra persona.';
    if ($email !== '') {
      $ce = db()->prepare('SELECT id FROM admins WHERE LOWER(email)=? AND id<>?'); $ce->execute([$email,$id]);
      if ($ce->fetch()) $errors[] = 'Ese correo de Google ya está asignado a otra persona.';
    }
    /* Tiene que quedar alguien con acceso total (misma regla que rol_asignar). */
    if (!$errors && $role !== $m['role']) {
      $r = rol_asignar($id, $role);
      if (!$r['ok']) $errors[] = $r['msg']; else $m['role'] = $role;
    }

    if (!$errors) {
      db()->prepare('UPDATE admins SET username=?, email=? WHERE id=?')
          ->execute([$username, ($email !== '' ? $email : null), $id]);
      header('Location: team-edit.php?id='.$id.'&ok=1'); exit;
    }
    /* Con errores se conserva lo escrito. */
    $m['username']=$username; $m['email']=$email;
  }

  /* Las horas van en su propio bloque, al final de la ficha: es lo último que se
     mira y solo aplica a quien factura. Se guardan aparte para que cada bloque
     tenga su botón — dos bloques dentro de un mismo <form> obligarían a poner las
     tarjetas de contraseña dentro del formulario, y un <form> no se puede anidar. */
  if ($acc === 'guardar_horas') {
    db()->prepare('UPDATE admins SET es_autonomo=?, tarifa_hora=?, iva_pct=?, irpf_pct=? WHERE id=?')
        ->execute([
          isset($_POST['es_autonomo']) ? 1 : 0,
          num_es($_POST['tarifa_hora'] ?? '', false),
          num_es($_POST['iva_pct'] ?? '', false),
          num_es($_POST['irpf_pct'] ?? '', false),
          $id,
        ]);
    header('Location: team-edit.php?id='.$id.'&ok=horas'); exit;
  }
}

/* ---------- Alta de alguien nuevo ---------- */
if (!$id && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $role     = isset($rolesT[$_POST['role'] ?? '']) ? (string)$_POST['role'] : (isset($rolesT['editor'])?'editor':(string)array_key_first($rolesT));
    $conLink  = isset($_POST['con_link']);

    if ($username === '') $errors[] = 'El usuario es obligatorio.';
    if (!$conLink) {
      if ($password === '')            $errors[] = 'Pon una contraseña, o marca que se la ponga esa persona.';
      elseif (strlen($password) < 6)   $errors[] = 'La contraseña debe tener al menos 6 caracteres.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo de Google no tiene un formato válido.';

    $chk = db()->prepare('SELECT id FROM admins WHERE username = ?'); $chk->execute([$username]);
    if ($chk->fetch()) $errors[] = 'Ese usuario ya existe.';
    if ($email !== '') {
      $chkE = db()->prepare('SELECT id FROM admins WHERE LOWER(email) = ?'); $chkE->execute([$email]);
      if ($chkE->fetch()) $errors[] = 'Ese correo de Google ya está asignado a otro miembro.';
    }

    if (!$errors) {
        /* Si se la va a poner esa persona, se crea con una contraseña larga al
           azar que nadie conoce: la cuenta no queda abierta mientras tanto. */
        $inicial = $conLink ? bin2hex(random_bytes(16)) : $password;
        db()->prepare('INSERT INTO admins (username, password_hash, role, email) VALUES (?,?,?,?)')
            ->execute([$username, password_hash($inicial, PASSWORD_DEFAULT), $role, ($email!==''?$email:null)]);
        $nuevoId = (int)db()->lastInsertId();
        if ($conLink) { pwreset_crear($nuevoId); header('Location: team-edit.php?id='.$nuevoId.'&ok=link'); exit; }
        header('Location: team.php'); exit;
    }
    $m['username']=$username; $m['role']=$role; $m['email']=$email;
}

$nuevaPass = $_SESSION['team_newpass'] ?? null; if ($nuevaPass !== null) unset($_SESSION['team_newpass']);
$linkVivo  = $id ? pwreset_activo($id) : null;
$ok        = (string)($_GET['ok'] ?? '');

$roles = []; foreach ($rolesT as $k=>$r) $roles[$k] = $r['nombre'];
$rolesDesc = []; foreach ($rolesT as $k=>$r) $rolesDesc[$k] = $r['descripcion'];
$esDueno = $id ? in_array('admin.total', $rolesT[$m['role']]['permisos'] ?? [], true) : false;

ahead($id ? $m['username'] : 'Nuevo miembro');
?>
<style>
/* Ficha de una persona del equipo.

   Es un FORMULARIO, no una ficha de solo lectura: cada campo lleva su etiqueta
   encima y su caja, con el aire suficiente para leerlo de un vistazo. Se usa la
   rejilla `.set-grid` de erp_nav.php, la misma que todo Ajustes, para que no
   haya dos maneras distintas de pintar un formulario en el ERP.

   Orden de la página: quién es → cómo entra → su contraseña → si factura horas →
   dar de baja. Las horas van abajo del todo porque solo aplican a quien es
   autónomo, y no es lo que se viene a mirar aquí. */
.erp-wrap{max-width:none}
.te{max-width:980px}

/* --- Cabecera --- */
.te-top{display:flex;align-items:center;gap:18px;margin-bottom:26px}
.te-av{width:62px;height:62px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;
  color:#fff;font-weight:700;font-size:23px;position:relative;letter-spacing:.5px;
  animation:teAv .45s cubic-bezier(.2,.8,.3,1) both}
@keyframes teAv{from{opacity:0;transform:scale(.82)}to{opacity:1;transform:none}}
.te-av .cr{position:absolute;right:-2px;bottom:-2px;width:22px;height:22px;border-radius:50%;background:#e8a33d;color:#fff;
  border:3px solid var(--bg);display:flex;align-items:center;justify-content:center}
.te-av .cr svg{width:11px;height:11px}
.te-nom{font-size:27px;font-weight:600;letter-spacing:-.5px;color:var(--ink-strong);line-height:1.2;margin:0 0 8px}
.te-meta{display:flex;align-items:center;gap:9px;flex-wrap:wrap;font-size:13px;color:var(--muted)}
.te-rol{display:inline-flex;align-items:center;gap:6px;background:var(--accent-soft);color:var(--ink-strong);
  font-size:12px;font-weight:600;padding:4px 11px;border-radius:99px}
.te-rol.duenyo{background:#fdf3e3;color:#96631a}

/* --- Bloques del formulario --- */
.te-bl{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:26px 28px;margin-bottom:18px}
.te-bl > h3{margin:0 0 4px;font-size:15.5px;font-weight:650;color:var(--ink-strong);letter-spacing:-.1px}
.te-bl > .des{font-size:12.5px;color:var(--muted);line-height:1.55;margin:0 0 22px;max-width:64ch}
.te .set-grid{gap:20px 22px}
/* Campos algo más altos que los de Ajustes: aquí hay pocos y se agradece el aire. */
.te .set-f input,.te .set-f select{padding:11px 13px;font-size:14px}
.te .set-f label{margin-bottom:6px}
.te-pie{display:flex;align-items:center;gap:12px;margin-top:24px}
.te-pie .aviso{font-size:12.5px;color:var(--muted);opacity:0;transition:opacity .2s ease}
.te-pie.sucia .aviso{opacity:1}

/* --- Interruptor de autónomo ---
   Interruptor y no casilla: es un modo que se enciende o se apaga («factura sus
   horas» / «no las factura»), no un dato que se marca. Mismo criterio que las
   reglas automáticas de Ajustes. El .sw viene de erp_nav.php. */
.te-chk{display:flex;align-items:center;gap:14px;cursor:pointer;padding:16px 18px;border:1px solid var(--line);
  border-radius:13px;background:var(--bg);transition:border-color .16s ease,background .16s ease}
.te-chk:hover{border-color:#dcdde0;background:var(--soft)}
.te-chk.on{border-color:#d6d7db;background:var(--accent-soft)}
.te-chk .tx{flex:1;min-width:0}
.te-chk b{display:block;font-size:13.5px;font-weight:600;color:var(--ink-strong)}
.te-chk em{display:block;font-style:normal;font-size:12.5px;color:var(--muted);line-height:1.5;margin-top:3px}
/* Si no factura sus horas, la tarifa no se usa. Antes se atenuaba a opacidad .35
   y parecía media pantalla rota; ahora simplemente NO SE ENSEÑA, y aparece al
   encender el interruptor. Un campo que no hace nada es mejor que no esté. */
.te-fac{display:none}
.te-fac.on{display:grid;animation:teAbre .28s cubic-bezier(.2,.8,.3,1) both}
@keyframes teAbre{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}

/* --- Iconos de las etiquetas --- */
.te .set-f label{display:flex;align-items:center;gap:7px}
.te .set-f label svg{width:15px;height:15px;color:var(--label);flex:none}
/* El logo de Google va a color y algo mayor que un icono normal: es una marca,
   no un pictograma. Igual que en Integraciones. */
.te .set-f label .glogo{width:18px;height:18px;flex:none;display:inline-flex;align-items:center}
.te .set-f label .glogo svg{width:18px;height:18px}

/* --- Las tres formas de dejarle la contraseña (esto se queda como está) --- */
.te-sec{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);font-weight:650;
  margin:38px 0 8px;display:flex;align-items:center;gap:10px}
.te-sec .sp{flex:1;height:1px;background:var(--line2)}
.te-sub{font-size:12.5px;color:var(--muted);line-height:1.55;margin:0}
.te-ops{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;margin-top:16px}
.te-op{position:relative;border:1px solid var(--line);border-radius:16px;padding:22px;display:flex;flex-direction:column;
  background:var(--card);transition:border-color .18s ease,box-shadow .22s ease,transform .18s ease}
.te-op:hover{border-color:#dfe0e3;box-shadow:0 16px 34px -24px rgba(16,19,24,.55);transform:translateY(-2px)}
.te-op .ic{width:42px;height:42px;border-radius:13px;display:flex;align-items:center;justify-content:center;
  background:var(--soft);color:#6f757e;margin-bottom:15px;transition:background .2s ease,color .2s ease}
.te-op:hover .ic{background:var(--accent);color:#fff}
.te-op b{font-size:14.5px;font-weight:650;color:var(--ink-strong);display:block;margin-bottom:6px;letter-spacing:-.1px}
.te-op p{font-size:12.5px;color:var(--muted);line-height:1.6;margin:0 0 20px;flex:1}
.te-op .btn{width:100%;justify-content:center;padding:11px 16px}
.te-op input{width:100%;margin-bottom:10px}

/* --- Avisos --- */
.te-clave{background:#eef8f1;border:1px solid #cde8d5;border-radius:16px;padding:20px 22px;margin-bottom:20px;
  animation:teEntra .4s cubic-bezier(.2,.8,.3,1) both}
@keyframes teEntra{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.te-clave .l{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#3c7a56;font-weight:700}
.te-clave code{display:inline-block;background:#fff;border:1px solid #cde8d5;border-radius:10px;padding:9px 16px;
  font-size:18px;font-weight:700;letter-spacing:1.5px;user-select:all;color:#1d1d1f;margin:10px 0 7px}
.te-clave p{margin:0;font-size:12.5px;color:#3c7a56;line-height:1.55}
.te-link{background:var(--soft);border:1px solid var(--line);border-radius:16px;padding:20px 22px;margin-bottom:20px;
  animation:teEntra .4s cubic-bezier(.2,.8,.3,1) both}
.te-link .t{display:flex;align-items:center;gap:8px;font-size:13.5px;font-weight:600;color:var(--ink-strong);margin-bottom:4px}
.te-link .t svg{color:#6f757e;flex:none}
.te-link p{margin:0;font-size:12.5px;color:var(--muted);line-height:1.55}
.te-link code{display:block;background:#fff;border:1px solid var(--line);border-radius:11px;padding:13px 15px;
  font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;word-break:break-all;cursor:pointer;margin:13px 0 11px;
  transition:border-color .16s ease,box-shadow .2s ease}
.te-link code:hover{border-color:#c9ccd1;box-shadow:0 10px 24px -18px rgba(16,19,24,.6)}
.te-nota{display:flex;gap:12px;align-items:flex-start;margin-top:22px;font-size:12.5px;color:var(--muted);line-height:1.65}
.te-nota svg{flex:none;margin-top:2px;color:var(--label)}
.te-nota b{color:var(--ink);font-weight:600}

/* --- Baja --- */
.te-baja{display:flex;align-items:center;gap:16px;border:1px solid #f2dede;background:#fffafa;border-radius:16px;
  padding:18px 22px}
.te-baja .tx{flex:1;min-width:0}
.te-baja b{display:block;font-size:13.5px;font-weight:600;color:#8f3034;margin-bottom:2px}
.te-baja span{display:block;font-size:12.5px;color:#a86a6c;line-height:1.5}

@media(max-width:860px){
  .te-bl{padding:22px 20px}
  .te-nom{font-size:22px}
  .te-baja{flex-wrap:wrap}
}

/* ===== Modo oscuro (aditivo) ===== */
[data-theme=dark] .te-chk:hover{border-color:var(--line-strong)}
[data-theme=dark] .te-chk.on{border-color:var(--line-strong)}
[data-theme=dark] .te .set-f label svg{color:var(--muted)}
[data-theme=dark] .te-op:hover{border-color:var(--line-strong)}
[data-theme=dark] .te-op .ic{color:var(--muted)}
[data-theme=dark] .te-op:hover .ic{color:var(--accent-fg)}
[data-theme=dark] .te-clave{background-color:var(--ok-bg);border-color:var(--ok-line)}
[data-theme=dark] .te-clave .l{color:var(--ok)}
[data-theme=dark] .te-clave code{background-color:var(--card);border-color:var(--ok-line);color:var(--ink-strong)}
[data-theme=dark] .te-clave p{color:var(--ok)}
[data-theme=dark] .te-link code{background-color:var(--card)}
[data-theme=dark] .te-link code:hover{border-color:var(--line-strong)}
[data-theme=dark] .te-link .t svg{color:var(--muted)}
[data-theme=dark] .te-nota svg{color:var(--muted)}
[data-theme=dark] .te-baja{border-color:var(--danger-line);background-color:var(--danger-bg)}
[data-theme=dark] .te-baja b{color:var(--danger)}
[data-theme=dark] .te-baja span{color:var(--muted)}

/* ===== Móvil (≤640px): cabecera, bloques y tarjetas de contraseña a 1 columna ===== */
/* La rejilla .set-grid ya baja a una columna con los cimientos globales; aquí
   damos aire a los bloques y dejamos que la cabecera envuelva el botón «Ver su
   perfil» debajo del nombre en vez de apretarlo o cortarlo. */
@media(max-width:640px){
  .te-top{flex-wrap:wrap;gap:14px}
  .te-bl{padding:20px 16px}
  .te-op{padding:20px 18px}
  .te-baja{padding:16px 18px}
  .te-sec{margin-top:30px}
}
</style>

<div class="te">
<?php if ($id): ?>
  <div class="tk-crumb"><a href="team.php"><?= ic('back',14) ?> Mi equipo</a><span class="sep">/</span><span><?= e($m['username']) ?></span></div>
  <div class="te-top">
    <span class="te-av" style="background:<?= avatar_color((string)$m['username']) ?>">
      <?= e(mb_strtoupper(mb_substr((string)$m['username'],0,1))) ?>
      <?php if($esDueno): ?><span class="cr" title="Tiene acceso total"><?= ic('usercheck',11) ?></span><?php endif; ?>
    </span>
    <div style="flex:1;min-width:0">
      <div class="te-nom"><?= e($m['username']) ?></div>
      <div class="te-meta">
        <span class="te-rol <?= $esDueno?'duenyo':'' ?>"><?= $esDueno ? ic('usercheck',13) : '' ?><?= e($roles[$m['role']] ?? $m['role']) ?></span>
        <span><?= $m['email']!=='' ? e($m['email']) : 'Sin correo de Google' ?></span>
      </div>
    </div>
    <?php /* Aquí se editan sus DATOS DE ACCESO. Su ficha pública —foto, cargo,
             teléfono, sobre mí, cumpleaños— vive en el perfil, que es donde tiene
             sentido tenerla (la ve el resto del equipo). Este botón la deja a un
             clic; como gestionas el equipo, puedes editarla tú desde ahí. */ ?>
    <a class="btn ghost" href="perfil.php?id=<?= (int)$id ?>&from=roles" title="Foto, cargo, teléfono, sobre mí…"><?= ic('user',15) ?> Ver su perfil</a>
  </div>
<?php else: ?>
  <div class="tk-crumb"><a href="team.php"><?= ic('back',14) ?> Mi equipo</a><span class="sep">/</span><span>Nuevo miembro</span></div>
  <div class="te-nom" style="margin-bottom:26px">Nuevo miembro del equipo</div>
<?php endif; ?>

<?php if ($errors): ?><div class="err-note"><?php foreach($errors as $er) echo '<div>• '.e($er).'</div>'; ?></div><?php endif; ?>
<?php if ($ok==='1'):       ?><div class="ok-note">Datos guardados.</div><?php endif; ?>
<?php if ($ok==='horas'):   ?><div class="ok-note">Datos de facturación guardados.</div><?php endif; ?>
<?php if ($ok==='setpass'): ?><div class="ok-note">Contraseña cambiada. Es la que acabas de escribir.</div><?php endif; ?>
<?php if ($ok==='nolink'):  ?><div class="ok-note">Enlace anulado.</div><?php endif; ?>
<?php if ($ok==='err'):     ?><div class="err-note">No se ha podido crear el enlace.</div><?php endif; ?>

<?php if ($nuevaPass !== null): ?>
  <div class="te-clave">
    <div class="l">Contraseña nueva de <?= e($m['username']) ?></div>
    <code><?= e($nuevaPass) ?></code>
    <p>Cópiala y pásasela. <b>No se vuelve a mostrar</b>: a partir de ahora solo está guardada cifrada.</p>
  </div>
<?php endif; ?>

<?php if ($linkVivo): ?>
  <div class="te-link">
    <div class="t"><?= ic('link',15) ?> Enlace activo para que <?= e($m['username']) ?> elija su contraseña</div>
    <p>Pásaselo por chat o WhatsApp. Caduca el <?= e(date('d/m/Y \a \l\a\s H:i', $linkVivo['caduca'])) ?> y solo sirve una vez.</p>
    <code onclick="navigator.clipboard&&navigator.clipboard.writeText(this.textContent.trim());toast('Enlace copiado')" title="Clic para copiar"><?= e(pwreset_url($linkVivo['token'])) ?></code>
    <form method="post" style="display:inline"><input type="hidden" name="action" value="quitar_link">
      <button class="btn ghost sm" type="submit">Anular el enlace</button></form>
  </div>
<?php endif; ?>

<?php if ($id): ?>
  <?php /* ---------- 1. ACCESO ---------- */ ?>
  <form method="post" class="te-bl" id="teForm"><input type="hidden" name="action" value="guardar">
    <h3>Datos de acceso</h3>
    <p class="des">Con esto entra al panel. Puedes cambiárselo cuando quieras, sin que tenga que hacer nada.</p>
    <div class="set-grid">
      <div class="set-f c6"><label><?= ic('user',15) ?> Usuario</label>
        <input type="text" name="username" value="<?= e($m['username']) ?>" required></div>
      <div class="set-f c6"><label><span class="glogo"><?= svc_logo('google',18) ?></span> Correo de Google</label>
        <input type="email" name="email" value="<?= e($m['email']) ?>" placeholder="nombre@gmail.com" autocomplete="off">
        <div class="hint">Con él puede entrar con «Entrar con Google», sin escribir contraseña.</div></div>
      <div class="set-f c6"><label><?= ic('usercheck',15) ?> Rol</label>
        <select name="role" onchange="teRolDesc(this)" data-desc='<?= e(json_encode($rolesDesc, JSON_UNESCAPED_UNICODE)) ?>'>
          <?php foreach ($roles as $k=>$v): ?><option value="<?= e($k) ?>" <?= $m['role']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
        <div class="hint"><span id="teRolD"><?= e($rolesDesc[$m['role']] ?? '') ?></span> Lo que hace cada rol se decide en <a href="permisos.php">Roles y permisos</a>.</div></div>
    </div>
    <div class="te-pie" id="tePie">
      <button class="btn" type="submit"><?= ic('check',15) ?> Guardar cambios</button>
      <span class="aviso">Hay cambios sin guardar</span>
    </div>
  </form>

  <?php /* ---------- 2. CONTRASEÑA ---------- */ ?>
  <div class="te-sec">Su contraseña<span class="sp"></span></div>
  <p class="te-sub">Tres formas de dejarle el acceso listo. Cualquiera de las tres anula un enlace pendiente, si lo hubiera.</p>

  <div class="te-ops">
    <form method="post" class="te-op">
      <input type="hidden" name="action" value="set_pass">
      <span class="ic"><?= ic('pencil',19) ?></span>
      <b>Ponérsela tú</b>
      <p>Escribes la que quieras y se la dices. Es la vía más directa, y la única en la que sabes cuál es.</p>
      <input type="text" name="nueva" placeholder="Mínimo 6 caracteres" autocomplete="off">
      <button class="btn" type="submit"><?= ic('check',15) ?> Ponerla</button>
    </form>

    <form method="post" class="te-op" onsubmit="return erpSubmitAsk(this,'Se genera una contraseña nueva y la actual deja de valer. Te la enseñamos una vez para que se la pases.',{titulo:'¿Generar una contraseña?',ok:'Generar',danger:false})">
      <input type="hidden" name="action" value="gen_pass">
      <span class="ic"><?= ic('bolt',19) ?></span>
      <b>Generar una</b>
      <p>El ERP inventa una corta y fácil de dictar, y te la enseña una sola vez para que se la pases.</p>
      <button class="btn ghost" type="submit"><?= ic('bolt',15) ?> Generar</button>
    </form>

    <form method="post" class="te-op" onsubmit="return erpSubmitAsk(this,'Se crea un enlace para que <?= e(addslashes($m['username'])) ?> elija su propia contraseña. Caduca en 48 horas y solo sirve una vez.',{titulo:'¿Crear el enlace?',ok:'Crear enlace',danger:false})">
      <input type="hidden" name="action" value="link_pass">
      <span class="ic"><?= ic('link',19) ?></span>
      <b>Que la elija <?= e($m['username']) ?></b>
      <p>Le pasas un enlace y se la pone <?= e($m['username']) ?>. Caduca en 48 h y solo vale una vez.</p>
      <button class="btn ghost" type="submit"><?= ic('link',15) ?><?= $linkVivo ? ' Rehacer enlace' : ' Crear enlace' ?></button>
    </form>
  </div>

  <?php /* Esto hay que decirlo, porque es la duda que sale siempre. */ ?>
  <div class="te-nota">
    <?= ic('alert',16) ?>
    <div><b>¿Y ver la contraseña que tiene ahora?</b> No se puede, y no es cosa del ERP: las contraseñas se
    guardan cifradas de una forma que no tiene vuelta atrás, ni siquiera para ti. Lo que sí tienes siempre es
    el control — con cualquiera de las tres opciones le cambias el acceso al momento, así que nunca te quedas
    fuera de una cuenta.</div>
  </div>

  <?php /* ---------- 3. HORAS (abajo: solo aplica a quien factura) ---------- */ ?>
  <div class="te-sec">Si factura sus horas<span class="sp"></span></div>
  <form method="post" class="te-bl" id="teHoras" style="margin-top:12px"><input type="hidden" name="action" value="guardar_horas">
    <h3>Facturación de <?= e($m['username']) ?></h3>
    <p class="des">Solo hace falta si es autónomo y te pasa factura por sus horas. Si no lo es, no toques nada de aquí.</p>

    <label class="te-chk <?= !empty($m['es_autonomo'])?'on':'' ?>" id="teChk">
      <span class="sw"><input type="checkbox" name="es_autonomo" id="teAut" onchange="teFactura()" <?= !empty($m['es_autonomo'])?'checked':'' ?>><span class="tr"></span></span>
      <span class="tx"><b>Es autónomo y factura sus horas</b><em>Las horas que se apunte pasan a la contabilidad con esta tarifa.</em></span>
    </label>

    <div class="set-grid te-fac <?= !empty($m['es_autonomo'])?'on':'' ?>" style="margin-top:20px">
      <div class="set-f c4 u" data-u="€"><label><?= ic('euro',15) ?> Tarifa por hora</label>
        <input type="text" name="tarifa_hora" value="<?= e($m['tarifa_hora']) ?>" placeholder="35" inputmode="decimal"></div>
      <div class="set-f c4 u" data-u="%"><label><?= ic('calc',15) ?> IVA</label>
        <input type="text" name="iva_pct" value="<?= e($m['iva_pct']) ?>" placeholder="21" inputmode="decimal"></div>
      <div class="set-f c4 u" data-u="%"><label><?= ic('calc',15) ?> IRPF · retención</label>
        <input type="text" name="irpf_pct" value="<?= e($m['irpf_pct']) ?>" placeholder="7" inputmode="decimal"></div>
    </div>

    <div class="te-pie" id="teHPie">
      <button class="btn" type="submit"><?= ic('check',15) ?> Guardar facturación</button>
      <span class="aviso">Hay cambios sin guardar</span>
    </div>
  </form>

  <?php /* ---------- 4. BAJA ---------- */ ?>
  <?php if ((int)$id !== (int)$yo['id']): ?>
    <div class="te-sec">Dar de baja<span class="sp"></span></div>
    <div class="te-baja" style="margin-top:12px">
      <div class="tx">
        <b>Quitarle el acceso a <?= e($m['username']) ?></b>
        <span>Deja de poder entrar al panel. Sus tareas y todo lo que haya hecho se queda donde está.</span>
      </div>
      <a class="btn danger" href="#" onclick="return erpAsk('¿Eliminar a <?= e(addslashes($m['username'])) ?> del equipo? Perderá el acceso al panel.',{titulo:'Dar de baja',ok:'Eliminar',post:'team-delete.php',data:{id:<?= (int)$id ?>},danger:true})"><?= ic('trash',15) ?> Dar de baja</a>
    </div>
  <?php endif; ?>

<?php else: ?>
  <?php /* ---------- ALTA ---------- */ ?>
  <form method="post" class="te-bl">
    <h3>Datos de acceso</h3>
    <p class="des">Con esto entra al panel. Sus datos de facturación y su contraseña se los cambias después desde su ficha.</p>
    <div class="set-grid">
      <div class="set-f c6"><label><?= ic('user',15) ?> Usuario</label>
        <input type="text" name="username" value="<?= e($m['username']) ?>" placeholder="Cómo va a entrar" required autofocus></div>
      <div class="set-f c6"><label><span class="glogo"><?= svc_logo('google',18) ?></span> Correo de Google <span style="font-weight:400;text-transform:none">· opcional</span></label>
        <input type="email" name="email" value="<?= e($m['email']) ?>" placeholder="nombre@gmail.com" autocomplete="off">
        <div class="hint">Le permite entrar con «Entrar con Google», sin escribir contraseña.</div></div>
      <div class="set-f c6"><label><?= ic('usercheck',15) ?> Rol</label>
        <select name="role" onchange="teRolDesc(this)" data-desc='<?= e(json_encode($rolesDesc, JSON_UNESCAPED_UNICODE)) ?>'>
          <?php foreach ($roles as $k=>$v): ?><option value="<?= e($k) ?>" <?= $m['role']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
        <div class="hint" id="teRolD"><?= e($rolesDesc[$m['role']] ?? '') ?></div></div>
      <div class="set-f c6"><label><?= ic('vault',15) ?> Contraseña</label>
        <input type="text" name="password" id="tePass" autocomplete="off" placeholder="Mínimo 6 caracteres"></div>
      <div class="c12">
        <label class="te-chk" id="teChkL">
          <span class="sw"><input type="checkbox" name="con_link" id="teLink" onchange="teAlta()"><span class="tr"></span></span>
          <span class="tx"><b>Que se la ponga esta persona</b><em>Se crea un enlace de un solo uso para pasárselo. La cuenta no queda abierta mientras tanto.</em></span>
        </label>
      </div>
    </div>
    <div class="te-pie">
      <button class="btn" type="submit"><?= ic('plus',15) ?> Crear acceso</button>
      <a class="btn ghost" href="team.php">Cancelar</a>
    </div>
  </form>
<?php endif; ?>
</div>

<script>
/* Apaga la tarifa cuando no es autónomo. No se deshabilita el campo: si estuviera
   deshabilitado el navegador no lo enviaría y al guardar se perdería el valor. */
/* Se llama teFactura y no teAut A PROPÓSITO: el campo lleva id="teAut", y un
   manejador escrito en el propio HTML (onchange="…") resuelve los nombres
   mirando primero el formulario, donde `teAut` ES EL CAMPO. La función quedaba
   tapada por su propio input y el navegador decía «teAut is not a function».
   Regla: el id de un campo y el nombre de la función que lo atiende nunca se
   llaman igual. */
function teFactura(){
  var c=document.getElementById('teAut'); if(!c) return;
  document.querySelectorAll('.te-fac').forEach(function(f){ f.classList.toggle('on', c.checked); });
  var l=document.getElementById('teChk'); if(l) l.classList.toggle('on', c.checked);
}
teFactura();

function teAlta(){
  var c=document.getElementById('teLink'); if(!c) return;
  document.getElementById('tePass').disabled=c.checked;
  var l=document.getElementById('teChkL'); if(l) l.classList.toggle('on', c.checked);
}

function teRolDesc(sel){
  var d={}; try{ d=JSON.parse(sel.getAttribute('data-desc')||'{}'); }catch(e){}
  var c=document.getElementById('teRolD'); if(c) c.textContent=d[sel.value]||'';
}

/* «Hay cambios sin guardar» en cada bloque, por separado.
   Los `hidden` se excluyen porque erp_nav.php inyecta el _csrf DESPUÉS de esta
   foto, y con él dentro el formulario nacería siempre «sucio». */
function teVigilar(idForm, idPie){
  var f=document.getElementById(idForm), pie=document.getElementById(idPie);
  if(!f || !pie) return;
  function foto(){
    return [].slice.call(f.querySelectorAll('input,select'))
      .filter(function(i){ return i.type!=='hidden'; })
      .map(function(i){ return i.type==='checkbox' ? (i.checked?1:0) : i.value; }).join(' ');
  }
  var inicial=foto();
  function mirar(){ pie.classList.toggle('sucia', foto()!==inicial); }
  f.addEventListener('input', mirar); f.addEventListener('change', mirar);
}
teVigilar('teForm','tePie');
teVigilar('teHoras','teHPie');
</script>
<?php afoot(); ?>
