<?php
/* Ficha de perfil del equipo (red social interna) + centro de cuenta personal.
   Diseño minimal estilo Apple. Cada uno gestiona su perfil, su cuenta y sus
   notificaciones; el Dueño puede editar la ficha pública de cualquiera. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/imagen.php';   // reduce las fotos subidas (ahorro de espacio)

$me = current_admin(); $meId = (int)$me['id'];
admin_profile_ensure();
function pf_set($k,$v){ db()->prepare('INSERT INTO settings (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')->execute([$k,$v]); }
function pf_store_photo($id){
  if (empty($_FILES['foto']) || ($_FILES['foto']['error'] ?? 1)!==UPLOAD_ERR_OK) return null;
  $ext=strtolower(pathinfo((string)$_FILES['foto']['name'],PATHINFO_EXTENSION));
  if (!in_array($ext,['jpg','jpeg','png','gif','webp'],true)) return false;
  if ((int)($_FILES['foto']['size']??0) > 8*1024*1024) return false;
  /* No basta con la extensión: comprobamos que el archivo ES una imagen real y que
     su tipo está permitido, para que no cuele un ejecutable renombrado a .png. */
  $info = @getimagesize($_FILES['foto']['tmp_name']);
  if ($info===false) return false;
  $okTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
  if (!in_array($info[2], $okTypes, true)) return false;
  /* La extensión guardada la fija el tipo REAL detectado, no la del nombre subido. */
  $extReal = image_type_to_extension($info[2], false);   // jpeg|png|gif|webp
  $ext = ($extReal==='jpeg') ? 'jpg' : $extReal;
  $dir=__DIR__.'/../uploads/avatars'; if(!is_dir($dir)) @mkdir($dir,0775,true);
  $fn='a'.(int)$id.'_'.bin2hex(random_bytes(4)).'.'.$ext;
  /* Un avatar se ve pequeño: con 800 px de lado sobra. */
  if (@move_uploaded_file($_FILES['foto']['tmp_name'], $dir.'/'.$fn)) { img_optimizar($dir.'/'.$fn, 800); return $fn; }
  return false;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : $meId;
$uq = db()->prepare('SELECT id,username,email,role FROM admins WHERE id=?'); $uq->execute([$id]); $u=$uq->fetch();
if (!$u) { erp_head('', 'Perfil'); echo '<div style="padding:60px;text-align:center;color:var(--muted)">Ese miembro no existe.</div>'; erp_foot(); exit; }

$isMe = ($id===$meId);
/* Puede editar la ficha pública de otra persona quien gestiona el equipo (y el
   Dueño, que puede todo). Antes era solo is_owner(), que se salta a los roles
   nuevos con «Gestionar el equipo»: los mismos que editan al miembro en
   team-edit.php deben poder retocar su ficha. */
$gestionaEquipo = function_exists('can') && (can('equipo.gestionar') || can('admin.total'));
$canEditProfile = $isMe || is_owner() || $gestionaEquipo;
$flash='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $sec = $_POST['sec'] ?? '';
  if ($sec==='profile' && $canEditProfile) {
    $cumple = trim($_POST['cumple']??''); if($cumple==='') $cumple=null;
    db()->prepare("INSERT INTO admin_profiles (admin_id,cargo,departamento,telefono,ubicacion,web,skills,cumple,bio) VALUES (?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE cargo=VALUES(cargo),departamento=VALUES(departamento),telefono=VALUES(telefono),ubicacion=VALUES(ubicacion),web=VALUES(web),skills=VALUES(skills),cumple=VALUES(cumple),bio=VALUES(bio)")
      ->execute([$id, mb_substr(trim($_POST['cargo']??''),0,120), mb_substr(trim($_POST['departamento']??''),0,80), mb_substr(trim($_POST['telefono']??''),0,60),
                 mb_substr(trim($_POST['ubicacion']??''),0,120), mb_substr(trim($_POST['web']??''),0,160), mb_substr(trim($_POST['skills']??''),0,300), $cumple, mb_substr(trim($_POST['bio']??''),0,2000)]);
    $newFn=pf_store_photo($id);
    if ($newFn){ $old=db()->query("SELECT foto FROM admin_profiles WHERE admin_id=".$id)->fetchColumn(); if($old && is_file(__DIR__.'/../uploads/avatars/'.$old)) @unlink(__DIR__.'/../uploads/avatars/'.$old); db()->prepare("UPDATE admin_profiles SET foto=? WHERE admin_id=?")->execute([$newFn,$id]); }
    header('Location: perfil.php?id='.$id.'&f=perfil'); exit;
  }
  if ($sec==='photo_del' && $canEditProfile) {
    $old=db()->query("SELECT foto FROM admin_profiles WHERE admin_id=".$id)->fetchColumn(); if($old && is_file(__DIR__.'/../uploads/avatars/'.$old)) @unlink(__DIR__.'/../uploads/avatars/'.$old);
    db()->prepare("UPDATE admin_profiles SET foto='' WHERE admin_id=?")->execute([$id]);
    header('Location: perfil.php?id='.$id.'&edit=1'); exit;
  }
  if ($sec==='account' && $isMe) {
    $nu=trim($_POST['username']??''); $em=strtolower(trim($_POST['email']??''));
    /* Este es el ÚNICO sitio donde cada quien edita su usuario y su correo. El correo
       es la identidad de «Entrar con Google», así que se valida formato Y unicidad
       (igual que el alta de team-edit.php) para que dos miembros no compartan correo. */
    if ($nu===''){ $flash='err:El nombre no puede quedar vacío.'; }
    elseif ($em!=='' && !filter_var($em, FILTER_VALIDATE_EMAIL)){ $flash='err:El correo no tiene un formato válido.'; }
    else {
      $ex=db()->prepare('SELECT id FROM admins WHERE username=? AND id<>?'); $ex->execute([$nu,$meId]); $dupU=$ex->fetchColumn();
      $dupE=false; if($em!==''){ $qe=db()->prepare('SELECT id FROM admins WHERE LOWER(email)=? AND id<>?'); $qe->execute([$em,$meId]); $dupE=(bool)$qe->fetchColumn(); }
      if($dupU){ $flash='err:Ese nombre de usuario ya existe.'; }
      elseif($dupE){ $flash='err:Ese correo ya está asignado a otro miembro.'; }
      else { db()->prepare('UPDATE admins SET username=?, email=? WHERE id=?')->execute([mb_substr($nu,0,80),mb_substr($em,0,160),$meId]); header('Location: perfil.php?id='.$id.'&modo=cuenta&tab=cuenta&f=cuenta'); exit; }
    }
  }
  if ($sec==='password' && $isMe) {
    $cur=$_POST['current']??''; $n1=$_POST['new']??''; $n2=$_POST['new2']??'';
    if(!password_verify($cur, $me['password_hash'])) $flash='err:La contraseña actual no es correcta.';
    elseif(password_valida($n1)!=='') $flash='err:'.password_valida($n1);
    elseif($n1!==$n2) $flash='err:Las dos contraseñas no coinciden.';
    else {
      $r = credenciales_cambiar('admins', $meId, $n1, ['propia'=>true]);
      if(!$r['ok']) $flash='err:'.$r['msg'];
      else {
        /* Un enlace de restablecimiento pendiente dejaría de valer: se generó
           antes de este cambio, con la clave que acabas de dejar atrás. */
        require_once __DIR__.'/lib/pwreset.php';
        pwreset_limpiar($meId);
        /* La sesión propia se renueva con la versión nueva, para que cambiar la
           contraseña no cierre la sesión de quien la acaba de cambiar. Las de
           otros dispositivos se caen, que es lo que se busca. */
        credenciales_renovar_sesion('admins', $meId);
        header('Location: perfil.php?id='.$id.'&modo=cuenta&tab=cuenta&f=pass'); exit;
      }
    }
  }
  if ($sec==='notif' && $isMe) {
    $mute=[]; if(!empty($_POST['mute_chat']))$mute[]='chat'; if(!empty($_POST['mute_avisos']))$mute[]='avisos';
    pf_set('notifmute_'.$meId, implode(',',$mute));
    header('Location: perfil.php?id='.$id.'&modo=cuenta&tab=notif&f=notif'); exit;
  }
}

$p = db()->prepare('SELECT * FROM admin_profiles WHERE admin_id=?'); $p->execute([$id]); $p = $p->fetch() ?: [];
$ps = chat_presence_state($id); $pcol = chat_presence_color($ps['state']);
$roles = ['owner'=>'Dueño','editor'=>'Editor','viewer'=>'Solo lectura'];
$editing = $canEditProfile && (($_GET['edit']??'')==='1');
/* Dos formas de llegar aquí:
   · modo=cuenta  → entré desde «mi cuenta» (pie de la barra lateral): es MI perfil y
                    se muestran los Ajustes (Cuenta y Avisos), no el lado social.
   · sin modo     → perfil social (el mío por el popup del avatar, o el de otra
                    persona): contacto, sobre y tareas, SIN menú de ajustes. */
$cuentaMode = $isMe && (($_GET['modo']??'')==='cuenta');
/* Siempre se aterriza en la vista de Perfil. En modo cuenta, además, aparece el
   menú de ajustes (Perfil · Cuenta · Avisos) para poder saltar a la configuración. */
$tab = $_GET['tab'] ?? 'perfil';
if(!$cuentaMode) $tab='perfil';
/* Sección del ERP con la que se pinta el menú lateral:
   · desde «mi cuenta» (pie o engranaje) → «ajustes», el menú de Gestión y ajustes…
     pero SOLO si esa persona administra. Quien no tiene ese módulo vería el menú
     de Ajustes vacío (aj_menu() se filtra por permiso), o sea una columna en
     blanco; para esa gente esto es «cuenta» y sale con el menú de trabajo.
   · desde el popup de un avatar   → la sección desde la que se abrió (?from=chat…),
                                      así el perfil conserva el menú de ese apartado. */
$fromSec = preg_replace('/[^a-z]/','', strtolower((string)($_GET['from'] ?? '')));
$verAjustes = !function_exists('can') || can('ver.ajustes');
$navActive = $cuentaMode ? ($verAjustes ? 'ajustes' : 'cuenta') : $fromSec;
$col = avatar_color($u['username']);
$photo = !empty($p['foto']) ? '../archivo.php?d=avatars&f='.rawurlencode($p['foto']) : '';
$muteNow = array_map('trim', explode(',', (string)get_setting('notifmute_'.$id,'')));
$DEPTS = ['Dirección','Cuentas','SEO','Contenidos','Diseño','Desarrollo','Publicidad','Administración','Soporte'];
$f = $_GET['f'] ?? '';
$MESES=['','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$presTxt = ['online'=>'En línea','idle'=>'Ausente','offline'=>$ps['text']][$ps['state']] ?? $ps['text'];
erp_head($navActive, 'Perfil · '.$u['username'], 'fin-canvas');
?>
<style>
body.fin-canvas .main{background:#f5f5f7}
body.fin-canvas .erp-wrap{animation:none;padding:30px 24px 90px}
.pf{max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,'Inter',sans-serif;-webkit-font-smoothing:antialiased}
.pf *{box-sizing:border-box}
.pf-back{display:inline-flex;align-items:center;gap:6px;color:var(--label);font-size:14px;font-weight:500;text-decoration:none;margin-bottom:20px;transition:color .15s}
.pf-back:hover{color:#1d1d1f}
.pf-back svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2.2}
.pf-note{border-radius:14px;padding:14px 18px;font-size:14px;font-weight:500;line-height:1.5;margin-bottom:20px}
.pf-note.ok{background:rgba(52,199,89,.12);color:#248a3d}
.pf-note.err{background:rgba(255,59,48,.12);color:#c9342a}
/* ---- Cabecera ---- */
.pf-hero{text-align:center;padding:10px 0 32px}
.pf-avwrap{position:relative;display:inline-block}
.pf-av{width:112px;height:112px;border-radius:50%;background:<?= $col ?>;color:#fff;font-size:42px;font-weight:600;display:flex;align-items:center;justify-content:center;overflow:hidden;box-shadow:0 8px 28px -10px rgba(0,0,0,.35);letter-spacing:-1px}
.pf-av img{width:100%;height:100%;object-fit:cover}
.pf-dot{position:absolute;right:7px;bottom:9px;width:22px;height:22px;border-radius:50%;background:<?= $pcol ?>;border:4px solid #f5f5f7}
.pf-name{font-size:30px;font-weight:600;color:#1d1d1f;letter-spacing:-.5px;margin:18px 0 0}
.pf-name .me{color:var(--label);font-weight:500;font-size:16px}
.pf-meta{font-size:15px;color:var(--label);margin-top:4px;display:flex;align-items:center;justify-content:center;gap:7px;flex-wrap:wrap}
.pf-meta .dotc{width:8px;height:8px;border-radius:50%;background:<?= $pcol ?>;display:inline-block}
.pf-meta .pres{color:<?= $ps['state']==='online'?'#248a3d':($ps['state']==='idle'?'#c07d18':'#86868b') ?>;font-weight:500}
.pf-tagline{font-size:16px;color:#1d1d1f;margin-top:8px;font-weight:500}
.pf-tagline .sep{color:#c7c7cc;margin:0 8px}
/* ---- Botonera ---- */
.pf-cta{display:flex;gap:10px;justify-content:center;margin-top:20px;flex-wrap:wrap}
.pf-btn{display:inline-flex;align-items:center;gap:7px;border:none;border-radius:999px;padding:10px 20px;font-size:15px;font-weight:500;cursor:pointer;text-decoration:none;font-family:inherit;transition:transform .1s,background .15s,opacity .15s}
.pf-btn:active{transform:scale(.97)}
.pf-btn svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2}
.pf-btn.pri{background:#1d1d1f;color:#fff}
.pf-btn.pri:hover{background:#000}
.pf-btn.sec{background:#e8e8ed;color:#1d1d1f}
.pf-btn.sec:hover{background:#deded5}
.pf-btn.blue{background:#0071e3;color:#fff}
.pf-btn.blue:hover{background:#0077ed}
.pf-btn.ghost{background:none;color:#0071e3;padding:10px 12px}
.pf-btn.ghost:hover{background:rgba(0,113,227,.08)}
/* ---- Segmented control ---- */
.pf-seg{display:flex;background:#e9e9ec;border-radius:12px;padding:3px;gap:3px;margin:28px auto 22px;max-width:420px}
.pf-seg button{flex:1;border:none;background:none;padding:8px 10px;border-radius:9px;font-size:14px;font-weight:550;color:#4b4b50;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;justify-content:center;gap:6px;transition:background .18s,box-shadow .18s;letter-spacing:-.1px}
.pf-seg button svg{width:15px;height:15px}
.pf-seg button.on{background:#fff;color:#1d1d1f;box-shadow:0 1px 4px rgba(0,0,0,.12),0 0 1px rgba(0,0,0,.06)}
/* ---- Listas agrupadas (estilo Ajustes de iOS) ---- */
.pf-panel{display:none;animation:pfIn .3s ease}
.pf-panel.on{display:block}
@keyframes pfIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.pf-glabel{font-size:12.5px;color:var(--label);font-weight:600;letter-spacing:.2px;margin:26px 4px 8px;text-transform:uppercase}
.pf-glabel:first-child{margin-top:4px}
.pf-group{background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.pf-li{display:flex;align-items:center;gap:14px;padding:17px 20px;position:relative;min-height:56px}
.pf-li+.pf-li::before{content:'';position:absolute;left:54px;right:0;top:0;height:1px;background:#ededf0}
.pf-li.ic-li+.pf-li.ic-li::before{left:66px}
.pf-li .lic{width:30px;height:30px;border-radius:8px;flex:none;display:flex;align-items:center;justify-content:center;color:#fff}
.pf-li .lic svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2}
.pf-li .k{font-size:15px;color:#1d1d1f;flex:none}
.pf-li .body{flex:1;min-width:0}
.pf-li .rl{font-size:12px;color:var(--label);font-weight:500}
.pf-li .rv{font-size:15px;color:#1d1d1f;word-break:break-word;margin-top:1px}
.pf-li a.rv,.pf-li .rv a{color:#0071e3;text-decoration:none}
.pf-li .rv.mut{color:var(--label)}
.pf-bio{white-space:pre-wrap;line-height:1.6}
.pf-skills{display:flex;gap:8px;flex-wrap:wrap;margin-top:3px}
.pf-skill{font-size:13px;font-weight:500;color:#1d1d1f;background:#f0f0f2;border-radius:8px;padding:5px 12px}
/* inputs dentro de lista (Cuenta) */
.pf-li .fin{flex:1;border:none;background:none;outline:none;font-size:15px;font-family:inherit;color:#1d1d1f;text-align:right;min-width:0}
.pf-li .fin::placeholder{color:#c7c7cc}
.pf-li .k.fixw{width:140px}
/* toggles iOS */
.pf-tg{position:relative;width:51px;height:31px;flex:none}
.pf-tg input{opacity:0;width:0;height:0;position:absolute}
.pf-tg .sl{position:absolute;inset:0;background:#e9e9ea;border-radius:999px;transition:background .22s}
.pf-tg .sl::before{content:'';position:absolute;width:27px;height:27px;left:2px;top:2px;background:#fff;border-radius:50%;box-shadow:0 2px 5px rgba(0,0,0,.28);transition:transform .22s}
.pf-tg input:checked+.sl{background:#34c759}
.pf-tg input:checked+.sl::before{transform:translateX(20px)}
.pf-gfoot{font-size:12.5px;color:var(--label);margin:10px 4px 0;line-height:1.5}
.pf-save{display:flex;justify-content:flex-end;gap:12px;margin-top:28px}
/* edición: cambiar foto desde el propio avatar */
.pf-avwrap.editing{cursor:pointer}
.pf-avwrap.editing .pf-av{transition:filter .15s}
.pf-avwrap.editing:hover .pf-av{filter:brightness(.9)}
.pf-cam{position:absolute;right:4px;bottom:6px;width:32px;height:32px;border-radius:50%;background:#0071e3;color:#fff;display:flex;align-items:center;justify-content:center;border:3px solid #f5f5f7;box-shadow:0 2px 6px rgba(0,0,0,.2)}
.pf-cam svg{width:16px;height:16px}
.pf-photolinks{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:12px}
.pf-photolinks .ph-lnk{color:#0071e3;font-size:15px;font-weight:500;cursor:pointer;background:none;border:none;font-family:inherit;padding:4px}
.pf-photolinks .ph-lnk:hover{text-decoration:underline}
.pf-photolinks .ph-lnk.del{color:#ff3b30}
.pf-photolinks .ph-dot{color:#c7c7cc}
/* ---- Formulario de edición: campos modernos y espaciados ---- */
.pf-slabel{font-size:13px;color:var(--label);font-weight:600;letter-spacing:.2px;margin:32px 2px 14px;text-transform:uppercase}
.pf-fields{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.pf-fields.one{grid-template-columns:1fr}
@media(max-width:560px){.pf-fields{grid-template-columns:1fr}}
.pf-field{display:flex;flex-direction:column;gap:8px;min-width:0}
.pf-field label{font-size:13.5px;font-weight:600;color:#3c3c43;letter-spacing:-.1px}
.pf-input{width:100%;border:1px solid #e2e2e6;background:#fff;border-radius:13px;padding:14px 16px;font-size:15px;color:#1d1d1f;font-family:inherit;outline:none;transition:border-color .16s ease,box-shadow .16s ease;-webkit-appearance:none;appearance:none}
.pf-input::placeholder{color:var(--label)}
.pf-input:hover{border-color:#d3d3d9}
.pf-input:focus{border-color:#0071e3;box-shadow:0 0 0 4px rgba(0,113,227,.12)}
textarea.pf-input{min-height:120px;resize:vertical;line-height:1.55}
.pf-selwrap{position:relative}
.pf-selwrap::after{content:"";position:absolute;right:15px;top:50%;width:11px;height:11px;transform:translateY(-60%) rotate(45deg);border-right:2px solid #86868b;border-bottom:2px solid #86868b;pointer-events:none}
select.pf-input{padding-right:40px;cursor:pointer}
input[type=date].pf-input{cursor:pointer}
.pf-fhint{font-size:12.5px;color:var(--label);margin-top:4px;line-height:1.5;padding-left:2px}
/* ---- Tareas del miembro (misma lógica que «Mis tareas» del workspace) ---- */
.pf-glabel.pf-rowlbl{display:flex;align-items:center;justify-content:space-between}
.pf-seeall{font-size:13px;font-weight:600;color:#0071e3;text-transform:none;letter-spacing:0;text-decoration:none}
.pf-seeall:hover{text-decoration:underline}
.pf-task{display:flex;align-items:center;gap:14px;padding:17px 20px;text-decoration:none;color:inherit;cursor:pointer;transition:background .12s;position:relative;min-height:56px}
.pf-task:hover{background:#f7f7f9}
.pf-task+.pf-task::before{content:'';position:absolute;left:56px;right:0;top:0;height:1px;background:#ededf0}
.pf-task .pf-tstate{width:22px;flex:none;display:flex;align-items:center;justify-content:center}
.pf-task .pf-tstate svg{width:18px;height:18px}
.pf-task .body{flex:1;min-width:0}
.pf-task .tt{font-size:15px;color:#1d1d1f;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pf-task .sub{font-size:12.5px;color:var(--label);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pf-task .pf-tdate{font-size:13px;color:var(--label);flex:none;font-variant-numeric:tabular-nums}
.pf-task .pf-tdate.late{color:#ff3b30;font-weight:600}

/* ===== Modo oscuro (aditivo): remapea solo superficies y textos propios) ===== */
[data-theme=dark] body.fin-canvas .main{background:var(--bg)}
[data-theme=dark] .pf-back{color:var(--muted)}
[data-theme=dark] .pf-back:hover{color:var(--ink-strong)}
[data-theme=dark] .pf-note.ok{background-color:var(--ok-bg);color:var(--ok)}
[data-theme=dark] .pf-note.err{background-color:var(--danger-bg);color:var(--danger)}
[data-theme=dark] .pf-dot{border-color:var(--bg)}
[data-theme=dark] .pf-name{color:var(--ink-strong)}
[data-theme=dark] .pf-name .me{color:var(--muted)}
[data-theme=dark] .pf-meta{color:var(--muted)}
[data-theme=dark] .pf-tagline{color:var(--ink)}
[data-theme=dark] .pf-tagline .sep{color:var(--muted)}
[data-theme=dark] .pf-btn.pri{background-color:var(--rev);color:var(--rev-fg)}
[data-theme=dark] .pf-btn.pri:hover{background-color:var(--rev)}
[data-theme=dark] .pf-btn.sec{background-color:var(--soft);color:var(--ink)}
[data-theme=dark] .pf-btn.sec:hover{background-color:var(--line-strong)}
[data-theme=dark] .pf-seg{background-color:var(--soft)}
[data-theme=dark] .pf-seg button{color:var(--muted)}
[data-theme=dark] .pf-seg button.on{background-color:var(--card);color:var(--ink-strong)}
[data-theme=dark] .pf-glabel{color:var(--muted)}
[data-theme=dark] .pf-group{background-color:var(--card)}
[data-theme=dark] .pf-li+.pf-li::before{background-color:var(--line)}
[data-theme=dark] .pf-li .k{color:var(--ink)}
[data-theme=dark] .pf-li .rl{color:var(--muted)}
[data-theme=dark] .pf-li .rv{color:var(--ink)}
[data-theme=dark] .pf-li .rv.mut{color:var(--muted)}
[data-theme=dark] .pf-skill{color:var(--ink);background-color:var(--soft)}
[data-theme=dark] .pf-li .fin{color:var(--ink)}
[data-theme=dark] .pf-li .fin::placeholder{color:var(--muted)}
[data-theme=dark] .pf-tg .sl{background-color:var(--line-strong)}
[data-theme=dark] .pf-gfoot{color:var(--muted)}
[data-theme=dark] .pf-cam{border-color:var(--bg)}
[data-theme=dark] .pf-photolinks .ph-dot{color:var(--muted)}
[data-theme=dark] .pf-slabel{color:var(--muted)}
[data-theme=dark] .pf-field label{color:var(--ink)}
[data-theme=dark] .pf-input{border-color:var(--line);background-color:var(--field);color:var(--ink)}
[data-theme=dark] .pf-input::placeholder{color:var(--muted)}
[data-theme=dark] .pf-input:hover{border-color:var(--line-strong)}
[data-theme=dark] .pf-selwrap::after{border-color:var(--muted)}
[data-theme=dark] .pf-fhint{color:var(--muted)}
[data-theme=dark] .pf-task:hover{background-color:var(--soft)}
[data-theme=dark] .pf-task+.pf-task::before{background-color:var(--line)}
[data-theme=dark] .pf-task .tt{color:var(--ink)}
[data-theme=dark] .pf-task .sub{color:var(--muted)}
[data-theme=dark] .pf-task .pf-tdate{color:var(--muted)}
[data-theme=dark] .pf-task .pf-tdate.late{color:var(--danger)}

/* ===== Móvil (≤640px): columnas apiladas, segmentado y listas cómodas al pulgar ===== */
@media(max-width:640px){
  body.fin-canvas .erp-wrap{padding:20px 14px 80px}
  .pf-hero{padding:6px 0 24px}
  .pf-name{font-size:26px}
  /* El segmentado ocupa todo el ancho para que las tres pestañas quepan sin apretarse. */
  .pf-seg{max-width:none}
  .pf-seg button{padding:8px 6px;font-size:13px;gap:5px}
  /* Filas de contacto, cuenta y tareas: compactas y planas, sin la altura de
     56px de escritorio, para que la lista sea corta en el móvil. */
  .pf-li{padding:12px 16px;min-height:0}
  .pf-task{padding:12px 16px;min-height:0}
  .pf-glabel{margin:18px 4px 7px}
  .pf-li .k.fixw{width:112px}
  /* Botones de guardar a lo ancho, repartidos, para tocarlos con el pulgar. */
  .pf-save{flex-wrap:wrap}
  .pf-save .pf-btn{flex:1;justify-content:center}
}
@media(max-width:400px){
  .pf-li .k.fixw{width:92px}
  .pf-seg button svg{display:none}
}
</style>

<div class="pf">
  <?php /* «Mi equipo» solo existe para el Dueño (team.php es owner-only); un Editor o
           Lector que mire su propio perfil rebotaría, así que a los no-dueños se les
           devuelve al panel. */ ?>
  <?php $backHref = $isMe ? (is_owner()?'team.php':'dashboard.php') : 'chat.php'; $backTxt = $isMe ? (is_owner()?'Mi equipo':'Inicio') : 'Volver'; ?>
  <a class="pf-back" href="<?= $backHref ?>"><svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg> <?= $backTxt ?></a>
  <?php if($f): ?><div class="pf-note ok">✓ <?= $f==='pass'?'Contraseña actualizada.':($f==='cuenta'?'Cuenta actualizada.':($f==='notif'?'Preferencias guardadas.':'Perfil guardado.')) ?></div><?php endif; ?>
  <?php if(strpos($flash,'err:')===0): ?><div class="pf-note err"><?= e(substr($flash,4)) ?></div><?php endif; ?>

  <!-- Cabecera -->
  <div class="pf-hero">
    <div class="pf-avwrap<?= $editing?' editing':'' ?>"<?= $editing?' onclick="document.getElementById(\'pfFoto\').click()"':'' ?>>
      <div class="pf-av"><?php if($photo): ?><img src="<?= e($photo) ?>" alt=""><?php else: ?><?= e(mb_strtoupper(mb_substr($u['username'],0,2))) ?><?php endif; ?></div>
      <?php if($editing): ?><span class="pf-cam"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg></span><?php else: ?><div class="pf-dot" title="<?= e($presTxt) ?>"></div><?php endif; ?>
    </div>
    <div class="pf-name"><?= e($u['username']) ?><?= $isMe?' <span class="me">· Tú</span>':'' ?></div>
    <?php if(!$editing): ?>
    <div class="pf-meta"><span class="dotc"></span><span class="pres"><?= e($presTxt) ?></span><span style="color:#c7c7cc">·</span><span><?= e($roles[$u['role']??'']??'Miembro') ?></span></div>
    <?php if(!empty($p['cargo']) || !empty($p['departamento'])): ?><div class="pf-tagline"><?= e($p['cargo']??'') ?><?php if(!empty($p['cargo'])&&!empty($p['departamento'])): ?><span class="sep">·</span><?php endif; ?><?php if(!empty($p['departamento'])): ?><span style="color:var(--label)"><?= e($p['departamento']) ?></span><?php endif; ?></div><?php endif; ?>
    <div class="pf-cta">
      <?php if(!$isMe): ?><a class="pf-btn pri" href="chat.php?dm=<?= (int)$id ?>"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Mensaje</a><?php endif; ?>
      <?php if(!empty($u['email'])): ?><a class="pf-btn sec" href="mailto:<?= e($u['email']) ?>"><svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/></svg> Email</a><?php endif; ?>
      <?php if($isMe): ?>
        <?php if($cuentaMode): ?><a class="pf-btn sec" href="perfil.php?id=<?= (int)$id ?>" title="Ver tu perfil público"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 12 0v1"/></svg> Ver perfil</a>
        <?php else: ?><a class="pf-btn sec" href="perfil.php?id=<?= (int)$id ?>&modo=cuenta" title="Cuenta y avisos"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg> Ajustes</a><?php endif; ?>
        <a class="pf-btn sec" href="perfil.php?id=<?= (int)$id ?>&edit=1"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg> Editar</a>
      <?php elseif($canEditProfile): ?><a class="pf-btn ghost" href="perfil.php?id=<?= (int)$id ?>&edit=1" title="Como administrador puedes editar esta ficha"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg> Editar · admin</a><?php endif; ?>
    </div>
    <?php else: ?>
    <div class="pf-photolinks"><button type="button" class="ph-lnk" onclick="document.getElementById('pfFoto').click()">Cambiar foto</button><?php if($photo): ?><span class="ph-dot">·</span><button type="button" class="ph-lnk del" onclick="document.getElementById('pfPhotoDel').submit()">Quitar</button><?php endif; ?></div>
    <?php endif; ?>
  </div>

  <?php if($editing): ?>
  <!-- ===== EDICIÓN ===== -->
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="sec" value="profile">
    <input type="file" id="pfFoto" name="foto" accept="image/*" style="display:none" onchange="pfPhotoPick(this)">

    <div class="pf-slabel">Información</div>
    <div class="pf-fields">
      <div class="pf-field"><label>Cargo / puesto</label><input class="pf-input" type="text" name="cargo" value="<?= e($p['cargo']??'') ?>" placeholder="Ej: Especialista SEO" autocomplete="off"></div>
      <div class="pf-field"><label>Departamento</label>
        <div class="pf-selwrap"><select class="pf-input" name="departamento">
          <option value="">Sin departamento</option>
          <?php $curd=$p['departamento']??''; $found=false; foreach($DEPTS as $d): $sel=($curd===$d); if($sel)$found=true; ?><option<?= $sel?' selected':'' ?>><?= e($d) ?></option><?php endforeach; if($curd!==''&&!$found): ?><option selected><?= e($curd) ?></option><?php endif; ?>
        </select></div>
      </div>
      <div class="pf-field"><label>Teléfono</label><input class="pf-input" type="text" name="telefono" value="<?= e($p['telefono']??'') ?>" placeholder="Ej: 600 000 000" autocomplete="off"></div>
      <div class="pf-field"><label>Ubicación</label><input class="pf-input" type="text" name="ubicacion" value="<?= e($p['ubicacion']??'') ?>" placeholder="Ej: Madrid" autocomplete="off"></div>
      <div class="pf-field"><label>Web / enlace</label><input class="pf-input" type="text" name="web" value="<?= e($p['web']??'') ?>" placeholder="https://…" autocomplete="off"></div>
      <div class="pf-field"><label>Cumpleaños</label><input class="pf-input" type="date" name="cumple" value="<?= e($p['cumple']??'') ?>"></div>
    </div>

    <div class="pf-slabel">Habilidades</div>
    <div class="pf-fields one">
      <div class="pf-field"><input class="pf-input" type="text" name="skills" value="<?= e($p['skills']??'') ?>" placeholder="SEO técnico, WordPress, Analítica…" autocomplete="off"><div class="pf-fhint">Sepáralas por comas. Se muestran como etiquetas.</div></div>
    </div>

    <div class="pf-slabel">Sobre mí</div>
    <div class="pf-fields one">
      <div class="pf-field"><textarea class="pf-input" name="bio" placeholder="Cuéntale al equipo a qué te dedicas, en qué puedes ayudar, tu horario…"><?= e($p['bio']??'') ?></textarea></div>
    </div>

    <div class="pf-save"><a class="pf-btn sec" href="perfil.php?id=<?= (int)$id ?>">Cancelar</a><button class="pf-btn pri" type="submit">Guardar cambios</button></div>
  </form>
  <?php if($photo): ?><form id="pfPhotoDel" method="post" style="display:none"><input type="hidden" name="sec" value="photo_del"></form><?php endif; ?>

  <?php else: ?>
  <!-- Menú de Ajustes: solo cuando entras a TU cuenta desde el pie de la barra.
       Se aterriza en «Perfil» y desde aquí se salta a Cuenta o Avisos. -->
  <?php if($cuentaMode): ?>
  <div class="pf-seg">
    <button class="<?= $tab==='perfil'?'on':'' ?>" data-t="perfil" onclick="pfTab('perfil')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 12 0v1"/></svg> Perfil</button>
    <button class="<?= $tab==='cuenta'?'on':'' ?>" data-t="cuenta" onclick="pfTab('cuenta')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg> Cuenta</button>
    <button class="<?= $tab==='notif'?'on':'' ?>" data-t="notif" onclick="pfTab('notif')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg> Avisos</button>
  </div>
  <?php endif; ?>

  <!-- Panel Perfil -->
  <div class="pf-panel <?= $tab==='perfil'?'on':'' ?>" id="pf-perfil">
    <?php
      $tel=$p['telefono']??''; $ubic=$p['ubicacion']??''; $web=$p['web']??''; $bio=$p['bio']??'';
      $skills=array_filter(array_map('trim',explode(',',(string)($p['skills']??'')))); $cumple=$p['cumple']??'';
      $hasContact = !empty($u['email'])||$tel!==''||$ubic!==''||$web!==''||$cumple;
    ?>
    <div class="pf-glabel">Contacto</div>
    <div class="pf-group">
      <div class="pf-li ic-li"><span class="lic" style="background:#8e8e93"><svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/></svg></span><div class="body"><div class="rl">Email</div><?php if(!empty($u['email'])): ?><div class="rv"><a href="mailto:<?= e($u['email']) ?>"><?= e($u['email']) ?></a></div><?php else: ?><div class="rv mut">—</div><?php endif; ?></div></div>
      <?php if($tel!==''): ?><div class="pf-li ic-li"><span class="lic" style="background:#34c759"><svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span><div class="body"><div class="rl">Teléfono</div><div class="rv"><a href="tel:<?= e(preg_replace('/[^0-9+]/','',$tel)) ?>"><?= e($tel) ?></a></div></div></div><?php endif; ?>
      <?php if($ubic!==''): ?><div class="pf-li ic-li"><span class="lic" style="background:#ff9500"><svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></span><div class="body"><div class="rl">Ubicación</div><div class="rv"><?= e($ubic) ?></div></div></div><?php endif; ?>
      <?php if($web!==''): $wh=preg_match('#^https?://#',$web)?$web:('https://'.$web); ?><div class="pf-li ic-li"><span class="lic" style="background:#0071e3"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></span><div class="body"><div class="rl">Web</div><div class="rv"><a href="<?= e($wh) ?>" target="_blank" rel="noopener"><?= e($web) ?></a></div></div></div><?php endif; ?>
      <?php if($cumple): $ct=strtotime($cumple); ?><div class="pf-li ic-li"><span class="lic" style="background:#ff2d55"><svg viewBox="0 0 24 24"><path d="M20 21v-8H4v8"/><path d="M4 13V9a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v4"/><path d="M12 7V4"/><path d="M8 7V5"/><path d="M16 7V5"/></svg></span><div class="body"><div class="rl">Cumpleaños</div><div class="rv"><?= (int)date('j',$ct).' de '.$MESES[(int)date('n',$ct)] ?></div></div></div><?php endif; ?>
    </div>

    <?php if($skills): ?>
    <div class="pf-glabel">Habilidades</div>
    <div class="pf-group"><div class="pf-li"><div class="pf-skills" style="margin:0"><?php foreach($skills as $sk): ?><span class="pf-skill"><?= e($sk) ?></span><?php endforeach; ?></div></div></div>
    <?php endif; ?>

    <div class="pf-glabel">Sobre <?= $isMe?'mí':'esta persona' ?></div>
    <div class="pf-group"><div class="pf-li"><div class="body"><?php if($bio!==''): ?><div class="rv pf-bio"><?= nl2br(e($bio)) ?></div><?php else: ?><div class="rv mut"><?= $isMe?'Aún no has escrito nada. Pulsa «Editar».':'Todavía no ha rellenado su perfil.' ?></div><?php endif; ?></div></div></div>

    <?php
      /* Tareas de este miembro. Misma lógica que «Mis tareas» / «Tareas de un
         empleado» del workspace: se filtra por responsable_id y se ocultan las
         completadas. Se envuelve en try/catch por si en una instalación nueva aún
         no se han creado las tablas tasks/task_lists (se crean al abrir el workspace). */
      $pfTasks = [];
      try {
        $tq = db()->prepare("SELECT t.id,t.titulo,t.estado,t.due_date,c.name AS clientname,l.nombre AS listname
          FROM tasks t JOIN clients c ON c.id=t.client_id JOIN task_lists l ON l.id=t.list_id
          WHERE t.responsable_id=? AND t.estado<>'completada'
          ORDER BY FIELD(t.estado,'en proceso','pendiente','atemporal','completada'), c.name, t.id");
        $tq->execute([$id]);
        $pfTasks = $tq->fetchAll();
      } catch (Exception $e) { $pfTasks = []; }
      $pfRet = $isMe ? 'view=mine' : ('view=emp&emp='.$id);
      $pfHoy = date('Y-m-d');
    ?>
    <div class="pf-glabel pf-rowlbl">
      <span>Tareas <?= $isMe?'que tienes':'asignadas' ?><?php if($pfTasks): ?> · <?= count($pfTasks) ?><?php endif; ?></span>
      <?php if($pfTasks): ?><a class="pf-seeall" href="workspace.php?<?= e($pfRet) ?>">Ver todas</a><?php endif; ?>
    </div>
    <div class="pf-group">
      <?php if(!$pfTasks): ?>
        <div class="pf-li"><div class="body"><div class="rv mut"><?= $isMe?'No tienes tareas pendientes. Todo al día.':'No tiene tareas pendientes.' ?></div></div></div>
      <?php else: foreach($pfTasks as $t): $turl='task.php?id='.(int)$t['id'].'&ret='.rawurlencode($pfRet); $late=($t['due_date'] && $t['due_date']<$pfHoy); ?>
        <a class="pf-task" href="<?= e($turl) ?>">
          <span class="pf-tstate" title="<?= e($t['estado']) ?>"><?= estado_circle($t['estado']) ?></span>
          <div class="body">
            <div class="tt"><?= e($t['titulo']) ?></div>
            <div class="sub"><?= e($t['clientname']) ?><?php if($t['listname']!==''): ?> · <?= e($t['listname']) ?><?php endif; ?></div>
          </div>
          <?php if($t['due_date']): ?><span class="pf-tdate<?= $late?' late':'' ?>"><?= e(date('d/m/y',strtotime($t['due_date']))) ?></span><?php endif; ?>
        </a>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <?php if($isMe): ?>
  <!-- Panel Cuenta -->
  <div class="pf-panel <?= $tab==='cuenta'?'on':'' ?>" id="pf-cuenta">
    <form method="post">
      <input type="hidden" name="sec" value="account">
      <div class="pf-glabel">Datos de acceso</div>
      <div class="pf-group">
        <div class="pf-li"><span class="k fixw">Nombre</span><input class="fin" type="text" name="username" value="<?= e($u['username']) ?>" required autocomplete="off"></div>
        <div class="pf-li"><span class="k fixw">Email</span><input class="fin" type="text" name="email" value="<?= e($u['email']??'') ?>" placeholder="tucorreo@…" autocomplete="off"></div>
      </div>
      <div class="pf-save"><button class="pf-btn pri" type="submit">Guardar</button></div>
    </form>
    <form method="post">
      <input type="hidden" name="sec" value="password">
      <div class="pf-glabel">Contraseña</div>
      <div class="pf-group">
        <div class="pf-li"><span class="k fixw">Actual</span><input class="fin" type="password" name="current" required autocomplete="current-password" placeholder="Requerida"></div>
        <div class="pf-li"><span class="k fixw">Nueva</span><input class="fin" type="password" name="new" required autocomplete="new-password" placeholder="Mín. 6 caracteres"></div>
        <div class="pf-li"><span class="k fixw">Repetir</span><input class="fin" type="password" name="new2" required autocomplete="new-password" placeholder="Repite la nueva"></div>
      </div>
      <div class="pf-save"><button class="pf-btn pri" type="submit">Cambiar contraseña</button></div>
    </form>
  </div>

  <!-- Panel Notificaciones -->
  <div class="pf-panel <?= $tab==='notif'?'on':'' ?>" id="pf-notif">
    <form method="post" id="pfNotifForm">
      <input type="hidden" name="sec" value="notif">
      <div class="pf-glabel">Silenciar</div>
      <div class="pf-group">
        <div class="pf-li"><div class="body"><div class="rv" style="font-size:15px">Chat de equipo</div><div class="rl">Sonido y notificación del navegador</div></div><label class="pf-tg"><input type="checkbox" name="mute_chat" <?= in_array('chat',$muteNow,true)?'checked':'' ?> onchange="document.getElementById('pfNotifForm').submit()"><span class="sl"></span></label></div>
        <div class="pf-li"><div class="body"><div class="rv" style="font-size:15px">Avisos y resúmenes</div><div class="rl">Facturas, informes, resumen diario</div></div><label class="pf-tg"><input type="checkbox" name="mute_avisos" <?= in_array('avisos',$muteNow,true)?'checked':'' ?> onchange="document.getElementById('pfNotifForm').submit()"><span class="sl"></span></label></div>
      </div>
      <div class="pf-gfoot">Las asignaciones y menciones de tareas llegan siempre.</div>
    </form>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
function pfTab(t){var u=new URL(location);u.searchParams.set('tab',t);history.replaceState({},'',u);
  document.querySelectorAll('.pf-panel').forEach(function(p){p.classList.toggle('on',p.id==='pf-'+t);});
  document.querySelectorAll('.pf-seg button').forEach(function(b){b.classList.toggle('on',b.dataset.t===t);});}
function pfPhotoPick(inp){ if(!inp.files||!inp.files[0])return; var u=URL.createObjectURL(inp.files[0]); var pv=document.querySelector('.pf-av'); if(pv)pv.innerHTML='<img src="'+u+'" alt="">'; }
</script>
<?php erp_foot(); ?>
