<?php
/* ACTAS — espacio interno del equipo para escribir notas y actas de reunión.
   Cada acta usa el MISMO editor enriquecido que la descripción/comentarios de una
   tarea (lib/rt_editor.php): se guarda como TEXTO con marcadores y se repinta con
   rt_blocks(), así que es idéntico de dinámico (títulos, listas, cita, código,
   tabla y pegar de la web conservando el formato).

   Tres vistas: LISTA (tarjetas con buscador y filtro por autor) · LECTURA (el acta
   como documento maquetado, estilo ficha de task.php) · EDICIÓN (editor de documento
   con aire). No sale al portal del cliente: es cosa interna del equipo. */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_admin();
ensure_schema();

require_once __DIR__ . '/lib/rt_editor.php';
require_once __DIR__ . '/lib/papelera.php';

/* Tabla creada en caliente, como el resto del ERP (no hay migraciones versionadas). */
function ensure_actas_schema(){
    static $done=false; if($done) return; $done=true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS actas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(220) NOT NULL DEFAULT '',
            contenido MEDIUMTEXT,
            admin_id INT DEFAULT NULL,
            pinned TINYINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (created_at), INDEX (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { error_log('ensure_actas_schema: '.$e->getMessage()); }
}
ensure_actas_schema();

/* Columna «fijar arriba», añadida en caliente (patrón «columna si no existe»). */
function ensure_actas_pin(){
    static $done=false; if($done) return; $done=true;
    try {
        $c = db()->query("SHOW COLUMNS FROM actas LIKE 'pinned'")->fetch();
        if(!$c) db()->exec("ALTER TABLE actas ADD COLUMN pinned TINYINT NOT NULL DEFAULT 0");
    } catch (Exception $e) { error_log('ensure_actas_pin: '.$e->getMessage()); }
}
ensure_actas_pin();

$me = current_admin(); $meId = (int)($me['id'] ?? 0);

/* ---------------- Manejadores POST (antes de emitir HTML, con PRG) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';

    if ($a === 'guardar') {
        require_can_edit();
        $aid   = (int)($_POST['id'] ?? 0);
        $titulo= trim((string)($_POST['titulo'] ?? ''));
        $cont  = (string)($_POST['contenido'] ?? '');
        if (mb_strlen($titulo) > 220) $titulo = mb_substr($titulo, 0, 220);
        if ($aid > 0) {
            db()->prepare('UPDATE actas SET titulo=?, contenido=? WHERE id=?')->execute([$titulo, $cont, $aid]);
        } else {
            db()->prepare('INSERT INTO actas (titulo, contenido, admin_id) VALUES (?,?,?)')
                ->execute([$titulo, $cont, $meId ?: null]);
            $aid = (int)db()->lastInsertId();
        }
        /* Tras guardar se abre el acta en modo LECTURA (documento), no el editor en bruto. */
        header('Location: actas.php?id='.$aid.'&guardada=1'); exit;
    }

    if ($a === 'pin') {
        require_can_edit();
        $aid = (int)($_POST['id'] ?? 0);
        if ($aid > 0) {
            /* updated_at=updated_at evita que «fijar» cuente como una edición. */
            db()->prepare('UPDATE actas SET pinned=1-pinned, updated_at=updated_at WHERE id=?')->execute([$aid]);
        }
        $volver = (($_POST['ret'] ?? '') === 'ver' && $aid>0) ? ('actas.php?id='.$aid) : 'actas.php';
        header('Location: '.$volver); exit;
    }

    if ($a === 'borrar') {
        require_can_edit();
        $aid = (int)($_POST['id'] ?? 0);
        if ($aid > 0) {
            $ti = (string)(db()->query('SELECT titulo FROM actas WHERE id='.$aid)->fetchColumn() ?: '');
            pap_borrar_flash('actas', $aid, 'acta', $ti ?: 'Acta', [], 'Acta eliminada');
        }
        header('Location: actas.php'); exit;
    }
}

/* ---------------- Datos para la vista ---------------- */
$verId   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$nueva   = isset($_GET['nueva']);
$editando= isset($_GET['edit']);
/* Un acta nueva o el modo edición solo tienen sentido para quien puede escribir. */
if (($nueva || $editando) && !can_edit()) {
    header('Location: '.($verId>0 ? 'actas.php?id='.$verId : 'actas.php')); exit;
}

/* Fecha exacta: «23 sep 2026, 18:40» (se muestra al pasar el ratón sobre la relativa). */
function acta_fecha($ts){ if(!$ts) return '';
    $d=strtotime($ts); if(!$d) return '';
    $meses=[1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
    return (int)date('j',$d).' '.$meses[(int)date('n',$d)].' '.date('Y',$d).', '.date('H:i',$d);
}
/* Fecha relativa: «hace 2 días», «ayer», «hoy», «hace 3 semanas»… */
function acta_fecha_rel($ts){ if(!$ts) return '';
    $d=strtotime($ts); if(!$d) return '';
    $diff=time()-$d; if($diff<0) $diff=0;
    if($diff<60)  return 'hace un momento';
    $min=intdiv($diff,60); if($min<60) return 'hace '.$min.($min==1?' minuto':' minutos');
    $h=intdiv($min,60);    if($h<24)   return 'hace '.$h.($h==1?' hora':' horas');
    $days=intdiv(strtotime('today')-strtotime(date('Y-m-d',$d)),86400);
    if($days<=0) return 'hoy';
    if($days==1) return 'ayer';
    if($days<7)  return 'hace '.$days.' días';
    if($days<30){ $w=intdiv($days,7);  return 'hace '.$w.($w==1?' semana':' semanas'); }
    if($days<365){$mo=intdiv($days,30); return 'hace '.$mo.($mo==1?' mes':' meses'); }
    $y=intdiv($days,365); return 'hace '.$y.($y==1?' año':' años');
}
function acta_ini($s){ return e(mb_strtoupper(mb_substr((string)$s,0,2))); }

$acta = null;
if ($verId > 0) {
    $st = db()->prepare('SELECT a.*, ad.username FROM actas a LEFT JOIN admins ad ON ad.id=a.admin_id WHERE a.id=?');
    $st->execute([$verId]); $acta = $st->fetch();
    if (!$acta) { header('Location: actas.php'); exit; }
}
/* ¿Qué vista toca? */
$modoEditar = ($nueva || ($verId>0 && $editando && can_edit()));
$modoLeer   = ($verId>0 && !$modoEditar);

require_once __DIR__ . '/erp_nav.php';
erp_head('actas', 'Actas');
rt_editor_assets();
?>
<style>
/* ===== ACTAS ===== escala de espaciado propia, coherente en toda la pantalla.
   --s1 6 · --s2 10 · --s3 14 · --s4 20 · --s5 28 · --s6 40 · --s7 56 */
.ac-wrap{--s1:6px;--s2:10px;--s3:14px;--s4:20px;--s5:28px;--s6:40px;--s7:56px;
  max-width:812px;margin:0 auto;padding:var(--s2) 0 var(--s7)}

/* ---- Cabecera de la lista ---- */
.ac-top{display:flex;align-items:flex-end;gap:var(--s3);margin:var(--s1) 0 var(--s2)}
.ac-top h1{font-size:26px;font-weight:600;letter-spacing:-.5px;margin:0;color:var(--ink-strong);line-height:1.1}
.ac-top .sp{flex:1}
.ac-sub{color:var(--muted);font-size:13.5px;line-height:1.55;margin:0 0 var(--s5)}
.ac-newbtn{display:inline-flex;align-items:center;gap:7px;background:var(--accent);color:var(--accent-fg,#fff);border:none;border-radius:10px;padding:9px 16px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:filter .15s ease,transform .15s ease;white-space:nowrap}
.ac-newbtn:hover{filter:brightness(1.08)}.ac-newbtn:active{transform:translateY(1px)}

/* ---- Barra de utilidades: buscador + filtro por autor ---- */
.ac-tools{display:flex;align-items:center;gap:var(--s3);flex-wrap:wrap;margin:0 0 var(--s4)}
.ac-search{position:relative;flex:1;min-width:220px}
.ac-search svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--label);pointer-events:none}
.ac-search input{width:100%;border:1px solid var(--line);background:var(--card);border-radius:10px;padding:10px 12px 10px 36px;font:inherit;font-size:14px;color:var(--ink);outline:none;transition:border-color .15s ease,box-shadow .15s ease}
.ac-search input::placeholder{color:var(--label)}
.ac-search input:focus{border-color:var(--ring,#c4c4c7);box-shadow:0 0 0 3px var(--ring-soft,rgba(17,19,24,.07))}
.ac-authors{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.ac-auth{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:var(--card);color:var(--muted);border-radius:999px;padding:6px 12px;font-size:12.5px;font-weight:600;cursor:pointer;transition:.15s;line-height:1}
.ac-auth:hover{border-color:var(--ring,#c4c4c7);color:var(--ink)}
.ac-auth.on{background:var(--accent);border-color:var(--accent);color:var(--accent-fg,#fff)}
.ac-auth .ac-dot{width:16px;height:16px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:8px;font-weight:700;flex:none}
.ac-auth.on .ac-dot{outline:2px solid var(--accent-fg,#fff)}

/* ---- Lista de tarjetas ---- */
.ac-list{display:flex;flex-direction:column;gap:var(--s3)}
.ac-card{position:relative;background:var(--card);border:1px solid var(--line);border-radius:16px;transition:border-color .16s ease,box-shadow .16s ease,transform .16s ease}
.ac-card:hover{border-color:var(--line-strong,#dcdde1);box-shadow:0 8px 26px rgba(16,19,24,.07)}
.ac-card.pin{border-color:var(--line-strong,#e2e3e6)}
.ac-card.pin:before{content:"";position:absolute;left:0;top:16px;bottom:16px;width:3px;border-radius:3px;background:var(--accent);opacity:.55}
.ac-link{display:block;padding:var(--s4) calc(var(--s4) + 4px);text-decoration:none;color:inherit}
.ac-badge{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--label);margin:0 0 8px}
.ac-badge svg{color:var(--accent)}
.ac-t{font-size:17px;font-weight:650;line-height:1.35;color:var(--ink-strong);margin:0 88px 6px 0;word-break:break-word}
.ac-ex{color:var(--muted);font-size:13.5px;line-height:1.55;margin:0 0 var(--s3);word-break:break-word;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.ac-meta{display:flex;align-items:center;gap:9px;font-size:12.5px;color:var(--label)}
.ac-av{width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:10px;font-weight:700;flex:none}
.ac-meta .who{color:var(--ink);font-weight:600}
.ac-meta .sep{color:var(--line-strong,#d4d7dd)}
.ac-meta time{cursor:default}
/* Acciones de la tarjeta (fijar / borrar), aparecen al pasar el ratón */
.ac-acts{position:absolute;top:14px;right:14px;display:flex;gap:2px;opacity:0;transition:opacity .15s ease}
.ac-card:hover .ac-acts,.ac-card:focus-within .ac-acts{opacity:1}
.ac-acts form{margin:0}
.ac-acts button{border:none;background:none;color:var(--label);cursor:pointer;padding:7px;border-radius:8px;display:inline-flex;line-height:0;transition:.15s}
.ac-acts button:hover{background:var(--soft);color:var(--ink)}
.ac-acts .del:hover{background:var(--danger-bg,#feecec);color:var(--danger,#c0343a)}
.ac-card.pin .ac-acts .pinbtn{color:var(--accent);opacity:1}
/* Estado vacío / sin resultados */
.ac-empty{text-align:center;color:var(--muted);padding:var(--s7) var(--s4);border:1px dashed var(--line);border-radius:18px}
.ac-empty .ei{width:56px;height:56px;border-radius:16px;background:var(--soft);color:var(--label);display:inline-flex;align-items:center;justify-content:center;margin:0 auto var(--s3)}
.ac-empty b{color:var(--ink-strong);display:block;margin-bottom:6px;font-size:16px;font-weight:600}
.ac-empty p{margin:0 auto;max-width:360px;font-size:13.5px;line-height:1.6}
.ac-empty .ac-newbtn{margin-top:var(--s4)}
.ac-noresult{display:none;text-align:center;color:var(--muted);padding:var(--s6) var(--s4);font-size:14px}

/* ---- Volver (miga) ---- */
.ac-crumb{display:flex;align-items:center;gap:7px;font-size:12.5px;color:var(--muted);margin-bottom:var(--s4)}
.ac-crumb a{color:var(--muted);display:inline-flex;align-items:center;gap:5px;text-decoration:none;transition:color .15s ease}
.ac-crumb a:hover{color:var(--ink)}
.ac-crumb a svg{flex:none}

/* ---- Documento: cabecera (lectura y edición comparten anatomía) ---- */
.ac-doc{max-width:728px;margin:0 auto}
.ac-doc h1.ac-h{font-size:29px;font-weight:600;letter-spacing:-.6px;line-height:1.2;color:var(--ink-strong);margin:0 0 var(--s4);word-break:break-word}
.ac-title{width:100%;border:none;outline:none;background:transparent;font-size:29px;font-weight:600;letter-spacing:-.6px;line-height:1.2;color:var(--ink-strong);padding:0;margin:0 0 var(--s4);word-break:break-word}
.ac-title::placeholder{color:var(--label)}
/* Solo al enfocar por teclado (no al clicar con el ratón): así no sale la línea al escribir. */
.ac-title:focus-visible{box-shadow:0 2px 0 -1px var(--accent)}
/* Metadatos en filas etiqueta·valor (estilo ficha task.php, sin cajas) */
.ac-metarows{display:flex;flex-direction:column;gap:2px;padding:var(--s3) 0;border-top:1px solid var(--line);border-bottom:1px solid var(--line);margin:0 0 var(--s5)}
.ac-mr{display:flex;align-items:center;gap:12px;min-height:34px}
.ac-mr .lbl{display:inline-flex;align-items:center;gap:7px;width:132px;flex:none;color:var(--label);font-size:12.5px}
.ac-mr .lbl svg{color:var(--label);flex:none}
.ac-mr .val{display:flex;align-items:center;gap:8px;color:var(--ink);font-size:13.5px}
.ac-mr .val .ac-av{width:22px;height:22px;font-size:9.5px}
.ac-mr .val b{font-weight:600}
.ac-mr .val .exact{color:var(--label);font-size:12.5px}

/* ---- Acciones del documento ---- */
.ac-docacts{display:flex;align-items:center;gap:var(--s2);flex-wrap:wrap;margin:0 0 var(--s5)}
.ac-docacts form{margin:0}
.ac-abtn{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:9px;padding:8px 14px;font:inherit;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:.15s}
.ac-abtn svg{color:var(--label)}
.ac-abtn:hover{border-color:var(--line-strong,#dcdde1);background:var(--soft)}
.ac-abtn.prim{background:var(--accent);border-color:var(--accent);color:var(--accent-fg,#fff)}
.ac-abtn.prim svg{color:currentColor}
.ac-abtn.prim:hover{filter:brightness(1.08);background:var(--accent)}
.ac-abtn.on{border-color:var(--accent);color:var(--ink-strong)}
.ac-abtn.on svg{color:var(--accent)}
.ac-abtn.del:hover{border-color:var(--danger-line,#f2c9c9);background:var(--danger-bg,#feecec);color:var(--danger,#c0343a)}
.ac-abtn.del:hover svg{color:currentColor}

/* ---- Cuerpo del documento (lectura) ---- */
.ac-body{font-size:15px;line-height:1.75}
.ac-body .rt-view{font-size:15px;line-height:1.75}
.ac-body .rt-view>*:first-child{margin-top:0}
.ac-emptybody{color:var(--muted);font-size:14px;padding:var(--s4) 0}

/* ---- Editor (edición) ---- */
.ac-editorcard{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:var(--s4) calc(var(--s4) + 2px) var(--s2)}
.ac-editorcard .rt-editor{min-height:min(56vh,420px);padding-top:var(--s1)}
.ac-savebar{display:flex;align-items:center;gap:var(--s3);flex-wrap:wrap;margin-top:var(--s4)}
.ac-savebar .sp{flex:1}
.ac-editmeta{font-size:12.5px;color:var(--label)}

/* ---- Responsive (móvil tipo app) ---- */
@media(max-width:640px){
  .ac-wrap{padding:var(--s1) 0 var(--s6)}
  .ac-top h1{font-size:23px}
  .ac-tools{flex-direction:column;align-items:stretch;gap:var(--s2)}
  .ac-search{min-width:0}
  .ac-authors{overflow-x:auto;flex-wrap:nowrap;-webkit-overflow-scrolling:touch;padding-bottom:2px}
  .ac-auth{flex:none}
  .ac-link{padding:var(--s3) var(--s4)}
  .ac-t{font-size:16px;margin-right:64px}
  .ac-acts{opacity:1}   /* en móvil no hay hover: fijar/borrar siempre accesibles */
  .ac-doc h1.ac-h,.ac-title{font-size:24px}
  .ac-mr{flex-wrap:wrap;gap:6px 12px;align-items:flex-start;padding:4px 0}
  .ac-mr .lbl{width:auto}
  .ac-editorcard{border-radius:13px;padding:var(--s3) var(--s3) var(--s1)}
}
</style>

<div class="ac-wrap">
<?php if ($modoEditar):
    $esNueva   = ($verId === 0);
    $tituloVal = $esNueva ? '' : (string)$acta['titulo'];
    $contSrc   = $esNueva ? '' : (string)$acta['contenido'];
    $cancelHref= $esNueva ? 'actas.php' : ('actas.php?id='.(int)$verId);
?>
  <div class="ac-doc">
    <div class="ac-crumb"><a href="<?= e($cancelHref) ?>"><?= ic('back',14) ?> <?= $esNueva ? 'Todas las actas' : 'Volver al acta' ?></a></div>

    <form method="post" id="actaForm" onsubmit="return actaGuardar()">
      <input type="hidden" name="action" value="guardar">
      <input type="hidden" name="id" value="<?= (int)$verId ?>">
      <input type="hidden" name="contenido" id="actaContenido">
      <?= csrf_field() ?>
      <input class="ac-title" name="titulo" id="actaTitulo" value="<?= e($tituloVal) ?>" placeholder="Título del acta…" autocomplete="off" maxlength="220">
      <div class="ac-editorcard rt-wrap">
        <div id="actaBody" class="rt-editor no-emoji" data-emoji-live contenteditable="true" data-ph="Escribe aquí… títulos, listas, lista de control, cita, código, tablas y pega de la web conservando el formato."><?= rt_blocks($contSrc, 'rt_format', true, true) ?></div>
        <?php rt_editor_toolbar('actaBody'); ?>
      </div>
    </form>

    <div class="ac-savebar">
      <button type="submit" form="actaForm" class="ac-abtn prim"><?= ic('check',15) ?> Guardar acta</button>
      <a class="ac-abtn" href="<?= e($cancelHref) ?>"><?= ic('back',15) ?> <?= $esNueva ? 'Cancelar' : 'Volver' ?></a>
      <span class="sp"></span>
      <?php if(!$esNueva): ?><span class="ac-editmeta">Última edición <?= e(acta_fecha_rel($acta['updated_at'])) ?></span><?php endif; ?>
    </div>
  </div>

<?php elseif ($modoLeer):
    $contSrc   = (string)$acta['contenido'];
    $autor     = $acta['username'] ?: 'Equipo';
    $bodyHtml  = rt_blocks($contSrc, 'rt_format', true, false);
    $puedeEditar = can_edit();
    $estaFijada  = !empty($acta['pinned']);
?>
  <div class="ac-doc">
    <div class="ac-crumb"><a href="actas.php"><?= ic('back',14) ?> Todas las actas</a></div>

    <h1 class="ac-h"><?= e($acta['titulo']!==''?$acta['titulo']:'(Sin título)') ?></h1>

    <div class="ac-metarows">
      <div class="ac-mr">
        <span class="lbl"><?= ic('user',15) ?> Autor</span>
        <span class="val"><span class="ac-av" style="background:<?= avatar_color($autor) ?>"><?= acta_ini($autor==='Equipo'?'EQ':$autor) ?></span><b><?= e($autor) ?></b></span>
      </div>
      <div class="ac-mr">
        <span class="lbl"><?= ic('clock',15) ?> Última edición</span>
        <span class="val"><?= e(acta_fecha_rel($acta['updated_at'])) ?> <span class="exact">· <?= e(acta_fecha($acta['updated_at'])) ?></span></span>
      </div>
      <div class="ac-mr">
        <span class="lbl"><?= ic('cal',15) ?> Creada</span>
        <span class="val"><?= e(acta_fecha($acta['created_at'] ?? '')) ?></span>
      </div>
    </div>

    <?php if($puedeEditar): ?>
    <div class="ac-docacts">
      <a class="ac-abtn" href="actas.php?id=<?= (int)$verId ?>&edit=1"><?= ic('pencil',15) ?> Editar</a>
      <form method="post">
        <input type="hidden" name="action" value="pin"><input type="hidden" name="id" value="<?= (int)$verId ?>"><input type="hidden" name="ret" value="ver"><?= csrf_field() ?>
        <button type="submit" class="ac-abtn<?= $estaFijada?' on':'' ?>" title="<?= $estaFijada?'Quitar de arriba':'Fijar arriba' ?>"><?= ic('flag',15) ?> <?= $estaFijada?'Fijada':'Fijar' ?></button>
      </form>
      <form method="post" onsubmit="return erpSubmitAsk(this,'¿Borrar esta acta? Se puede recuperar desde la papelera.')">
        <input type="hidden" name="action" value="borrar"><input type="hidden" name="id" value="<?= (int)$verId ?>"><?= csrf_field() ?>
        <button type="submit" class="ac-abtn del"><?= ic('trash',15) ?> Borrar</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="ac-body"><div class="rt-view"><?= $bodyHtml ?: '<div class="ac-emptybody">Esta acta está vacía.</div>' ?></div></div>
  </div>

<?php else: /* ---------------- LISTA ---------------- */
    $actas = db()->query('SELECT a.id, a.titulo, a.contenido, a.pinned, a.updated_at, ad.username
                          FROM actas a LEFT JOIN admins ad ON ad.id=a.admin_id
                          ORDER BY a.pinned DESC, a.updated_at DESC, a.id DESC')->fetchAll();
    /* Autores presentes, para el filtro por chips (solo si hay más de uno). */
    $autores=[]; foreach($actas as $ac){ $u=$ac['username'] ?: 'Equipo'; $autores[$u]=true; }
    $autores=array_keys($autores); sort($autores, SORT_NATURAL|SORT_FLAG_CASE);
?>
  <div class="ac-top">
    <h1>Actas</h1>
    <span class="sp"></span>
    <?php if(can_edit()): ?><a class="ac-newbtn" href="actas.php?nueva=1"><?= ic('plus',15) ?> Nueva acta</a><?php endif; ?>
  </div>
  <p class="ac-sub">Notas y actas internas del equipo. No se ven en el portal del cliente.</p>

  <?php if(!$actas): ?>
    <div class="ac-empty">
      <span class="ei"><?= ic('file',26) ?></span>
      <b>Aún no hay actas</b>
      <p>Crea la primera y escríbela con el mismo editor que las tareas: títulos, listas, tablas y pegar de la web conservando el formato.</p>
      <?php if(can_edit()): ?><a class="ac-newbtn" href="actas.php?nueva=1"><?= ic('plus',15) ?> Nueva acta</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="ac-tools">
      <div class="ac-search">
        <?= ic('search',16) ?>
        <input type="text" id="acBuscar" placeholder="Buscar en las actas…" autocomplete="off" aria-label="Buscar actas">
      </div>
      <?php if(count($autores)>1): ?>
      <div class="ac-authors" id="acAutores">
        <button type="button" class="ac-auth on" data-author="" onclick="acFiltroAutor(this)">Todos</button>
        <?php foreach($autores as $au): ?>
          <button type="button" class="ac-auth" data-author="<?= e($au) ?>" onclick="acFiltroAutor(this)">
            <span class="ac-dot" style="background:<?= avatar_color($au) ?>"><?= acta_ini($au==='Equipo'?'EQ':$au) ?></span><?= e($au) ?>
          </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="ac-list" id="acLista">
      <?php foreach($actas as $ac):
        $ex=rt_excerpt($ac['contenido'],200);
        $autor=$ac['username'] ?: 'Equipo';
        $fijada=!empty($ac['pinned']);
        $busca=e(mb_strtolower(($ac['titulo']!==''?$ac['titulo']:'sin título').' '.$ex));
      ?>
        <div class="ac-card<?= $fijada?' pin':'' ?>" data-s="<?= $busca ?>" data-author="<?= e($autor) ?>">
          <a class="ac-link" href="actas.php?id=<?= (int)$ac['id'] ?>">
            <?php if($fijada): ?><div class="ac-badge"><?= ic('flag',12) ?> Fijada</div><?php endif; ?>
            <div class="ac-t"><?= e($ac['titulo']!==''?$ac['titulo']:'(Sin título)') ?></div>
            <?php if($ex!==''): ?><div class="ac-ex"><?= e($ex) ?></div><?php endif; ?>
            <div class="ac-meta">
              <span class="ac-av" style="background:<?= avatar_color($autor) ?>"><?= acta_ini($autor==='Equipo'?'EQ':$autor) ?></span>
              <span class="who"><?= e($autor) ?></span>
              <span class="sep">·</span>
              <time title="<?= e(acta_fecha($ac['updated_at'])) ?>"><?= e(acta_fecha_rel($ac['updated_at'])) ?></time>
            </div>
          </a>
          <?php if(can_edit()): ?>
          <div class="ac-acts">
            <form method="post">
              <input type="hidden" name="action" value="pin"><input type="hidden" name="id" value="<?= (int)$ac['id'] ?>"><?= csrf_field() ?>
              <button type="submit" class="pinbtn" aria-label="<?= $fijada?'Quitar de arriba':'Fijar arriba' ?>" title="<?= $fijada?'Quitar de arriba':'Fijar arriba' ?>"><?= ic('flag',15) ?></button>
            </form>
            <form method="post" onsubmit="return erpSubmitAsk(this,'¿Borrar esta acta? Se puede recuperar desde la papelera.')">
              <input type="hidden" name="action" value="borrar"><input type="hidden" name="id" value="<?= (int)$ac['id'] ?>"><?= csrf_field() ?>
              <button type="submit" class="del" aria-label="Borrar acta" title="Borrar acta"><?= ic('trash',15) ?></button>
            </form>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="ac-noresult" id="acVacio">No hay actas que coincidan con la búsqueda.</div>
  <?php endif; ?>
<?php endif; ?>
</div>

<script>
/* Al enviar, serializa el editor a los marcadores (igual que una tarea) y valida
   que el acta tenga algo (título o contenido). */
function actaGuardar(){
  var cont = (typeof rtSerialize==='function') ? rtSerialize('actaBody') : '';
  document.getElementById('actaContenido').value = cont;
  var tit = (document.getElementById('actaTitulo').value||'').trim();
  if(tit==='' && cont===''){ if(window.toast)toast('Escribe un título o algo de contenido','err'); var b=document.getElementById('actaBody'); if(b)b.focus(); return false; }
  return true;
}

/* ---- Lista: buscador en vivo + filtro por autor ---- */
(function(){
  var lista=document.getElementById('acLista'); if(!lista) return;
  var input=document.getElementById('acBuscar');
  var vacio=document.getElementById('acVacio');
  var cards=Array.prototype.slice.call(lista.querySelectorAll('.ac-card'));
  var autorSel='';

  function aplicar(){
    var q=(input && input.value ? input.value : '').trim().toLowerCase();
    var visibles=0;
    cards.forEach(function(c){
      var okTexto = q==='' || (c.getAttribute('data-s')||'').indexOf(q)>-1;
      var okAutor = autorSel==='' || (c.getAttribute('data-author')||'')===autorSel;
      var ver = okTexto && okAutor;
      c.style.display = ver ? '' : 'none';
      if(ver) visibles++;
    });
    if(vacio) vacio.style.display = visibles===0 ? 'block' : 'none';
  }
  if(input){
    input.addEventListener('input', aplicar);
    /* Enter no debe recargar nada; solo se filtra. */
    input.addEventListener('keydown', function(e){ if(e.key==='Enter') e.preventDefault(); });
  }
  window.acFiltroAutor=function(btn){
    autorSel = btn.getAttribute('data-author')||'';
    var cont=document.getElementById('acAutores');
    if(cont) cont.querySelectorAll('.ac-auth').forEach(function(b){ b.classList.toggle('on', b===btn); });
    aplicar();
  };
})();

<?php if(isset($_GET['guardada'])): ?>
if(window.toast) toast('Acta guardada');
<?php endif; ?>
</script>
<?php erp_foot(); ?>
