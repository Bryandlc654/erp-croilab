<?php
/* Agencias colaboradoras (white-label): el portal/informe del cliente sale con su logo. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/marca.php';
/* Apartado de Ajustes: con qué marca ve el portal cada cliente es una decisión
   de configuración, así que se pinta dentro del marco común. */
require_once __DIR__ . '/lib/ajustes_nav.php';

/* marca_ensure() añade las columnas de contacto (whatsapp, meeting_url,
   telefono) a las instalaciones que crearon la tabla antes de que existieran. */
marca_ensure();
$CASA = marca_agencia();

db()->exec("CREATE TABLE IF NOT EXISTS partner_agencies (
  id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(160) NOT NULL, logo_url VARCHAR(400) DEFAULT '',
  color VARCHAR(20) DEFAULT '', web VARCHAR(200) DEFAULT '', email VARCHAR(160) DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try { $has=db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='partner_id'")->fetchColumn(); if(!$has) db()->exec("ALTER TABLE clients ADD COLUMN partner_id INT DEFAULT NULL"); } catch(Exception $e){}

if ($_SERVER['REQUEST_METHOD']==='POST' && can_edit()) {
  $a=$_POST['action']??'';
  if ($a==='save') {
    $id=(int)($_POST['id']??0); $nom=trim($_POST['nombre']??'');
    if ($nom!=='') {
      /* whatsapp / meeting_url / telefono son la parte que faltaba de la marca
         blanca: sin ellos el cliente de una agencia colaboradora veía el portal
         con el logo de su agencia pero escribía al WhatsApp de la casa. */
      $f=['nombre'=>$nom,'logo_url'=>trim($_POST['logo_url']??''),'color'=>trim($_POST['color']??''),'web'=>trim($_POST['web']??''),'email'=>trim($_POST['email']??''),
          'whatsapp'=>preg_replace('/[^0-9]/','',(string)($_POST['whatsapp']??'')),'meeting_url'=>trim($_POST['meeting_url']??''),'telefono'=>trim($_POST['telefono']??'')];
      if ($id) { $set=implode(', ',array_map(fn($k)=>"$k=:$k",array_keys($f))); $p=$f;$p['id']=$id; db()->prepare("UPDATE partner_agencies SET $set WHERE id=:id")->execute($p); }
      else { $cols=implode(',',array_keys($f)); $ph=implode(',',array_map(fn($k)=>":$k",array_keys($f))); db()->prepare("INSERT INTO partner_agencies ($cols) VALUES ($ph)")->execute($f); }
    }
    header('Location: agencias.php'); exit;
  } elseif ($a==='del') {
    $id=(int)($_POST['id']??0); db()->prepare('UPDATE clients SET partner_id=NULL WHERE partner_id=?')->execute([$id]); db()->prepare('DELETE FROM partner_agencies WHERE id=?')->execute([$id]);
    header('Location: agencias.php'); exit;
  } elseif ($a==='assign') {
    $cid=(int)($_POST['client_id']??0); $pid=($_POST['partner_id']??'')!==''?(int)$_POST['partner_id']:null;
    db()->prepare('UPDATE clients SET partner_id=? WHERE id=?')->execute([$pid,$cid]);
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
  }
}

$ags = db()->query('SELECT * FROM partner_agencies ORDER BY nombre')->fetchAll();
$agName=[]; foreach($ags as $a) $agName[$a['id']]=$a['nombre'];
$clients = db()->query('SELECT id, name, partner_id FROM clients ORDER BY name')->fetchAll();
/* Raíz del portal del cliente (un directorio por encima de /admin), para poder
   enseñar el enlace de acceso con la marca de cada agencia ya montado. */
$BASE = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
      . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost')
      . rtrim(str_replace('\\','/',dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php')))),'/');
aj_head('agencias', 'Marca blanca',
  'Los clientes asignados a una agencia ven su portal e informes con el logo de esa agencia y escriben a su WhatsApp y su enlace de reuniones, no a los tuyos. Lo que no se rellene aquí cae a los datos de <a href="settings.php?tab=agency">'.e($CASA['name']).'</a>.',
  can_edit() ? '<button class="btn" onclick="agOpen()">'.ic('plus',16).' Nueva agencia</button>' : '');
?>
<style>
/* Dos columnas, no tres: dentro del marco de Ajustes la columna de contenido es
   más estrecha y a tres las tarjetas se quedaban sin sitio para el enlace. */
/* auto-fit + minmax para que las tarjetas reflowen solas sin desbordar cuando la
   columna de contenido es estrecha (a 1024px con dos columnas se salía +20px);
   min-width:0 deja que el enlace de acceso (nowrap) encoja en vez de empujar. */
.ag-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;margin:0 0 30px}
@media(max-width:820px){.ag-grid{grid-template-columns:1fr}}
.ag-card{background:#fff;border:1px solid var(--line);border-radius:15px;padding:20px 22px;position:relative;min-width:0}
.ag-logo{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:18px;overflow:hidden;margin-bottom:12px}
.ag-logo img{width:100%;height:100%;object-fit:contain}
.ag-card b{font-size:15.5px;font-weight:600;color:var(--ink-strong);display:block}
.ag-card .m{font-size:12.5px;color:var(--muted);margin-top:4px}
.ag-card .acts{position:absolute;top:12px;right:12px;display:flex;gap:4px}
.ag-card .acts button{border:none;background:none;color:var(--label);cursor:pointer;padding:5px;border-radius:7px}
.ag-card .acts button:hover{background:var(--soft);color:var(--ink)}
.ag-empty{border:1px dashed #d4d8de;border-radius:15px;padding:38px;text-align:center;color:var(--muted)}
.ag-tbl{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden}
.ag-row{display:grid;grid-template-columns:1fr 260px;gap:12px;align-items:center;padding:14px 20px;border-bottom:1px solid var(--line)}
.ag-row:last-child{border-bottom:none}.ag-row.h{background:#fbfbfc;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600}
.ag-ov{position:fixed;inset:0;background:rgba(20,22,28,.5);z-index:400;display:none;align-items:center;justify-content:center;padding:20px}
.ag-ov.on{display:flex;animation:fadeIn .15s ease}
.ag-modal{background:#fff;border-radius:18px;width:620px;max-width:100%;box-shadow:0 30px 80px rgba(0,0,0,.28)}
.ag-modal .mh{padding:20px 24px;border-bottom:1px solid var(--line);font-size:17px;font-weight:600}
.ag-modal .mb{padding:22px 24px;max-height:70vh;overflow:auto}
.ag-modal .mf{padding:14px 22px;border-top:1px solid var(--line);display:flex;justify-content:flex-end;gap:8px}
.ag-modal label{font-size:12px;color:var(--muted);font-weight:600;display:flex;align-items:center;gap:6px;margin:0 0 5px}
/* Icono de la etiqueta: gris tenue y del tamaño del texto, igual que en el resto
   de Ajustes (.set-f label svg del marco común). */
.ag-modal label svg{width:14px;height:14px;color:var(--label);flex:none}
.ag-modal input{width:100%;border:1px solid var(--line);border-radius:9px;padding:9px 12px;font-size:13.5px;font-family:inherit}
/* Rejilla de 12 columnas dentro de la ventana, igual que en el resto de Ajustes. */
.ag-g{display:grid;grid-template-columns:repeat(12,1fr);gap:13px 14px}
.ag-g > span{grid-column:span 12;display:block}
.ag-g .c4{grid-column:span 4}.ag-g .c5{grid-column:span 5}
.ag-g .c7{grid-column:span 7}.ag-g .c8{grid-column:span 8}.ag-g .c12{grid-column:span 12}
@media(max-width:560px){ .ag-g > span{grid-column:span 12 !important} }
/* Móvil (≤640px): la tabla «Asignar clientes» apila cliente y selector, y el
   selector ocupa todo el ancho para tocarlo con el pulgar sin que se corte. */
@media(max-width:640px){
  .ag-row{grid-template-columns:1fr;gap:6px;padding:14px 16px}
  .ag-row.h{display:none}
  .ag-row select{width:100%}
}
/* Separador dentro del formulario: los datos fiscales de la agencia y el
   contacto que ve el cliente son dos cosas distintas y se leen mejor aparte. */
.ag-sep{margin:24px 0 6px;padding-top:18px;border-top:1px solid var(--line);font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600}
.ag-hint{margin-top:14px;font-size:12.5px;color:var(--muted);line-height:1.55}
.ag-link{display:flex;align-items:center;gap:6px;margin-top:8px;font-size:11.5px;color:var(--muted)}
.ag-link code{background:var(--soft);border:1px solid var(--line);border-radius:6px;padding:2px 6px;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0}
.ag-link button{border:1px solid var(--line);background:#fff;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:600;cursor:pointer;color:var(--ink);flex:none}
.ag-link button:hover{background:var(--soft)}
/* Modo oscuro: tarjetas de agencia, tabla, ventana y campos propios. Los cuadros
   de logo se dejan como están (color de marca). */
[data-theme=dark] .ag-card{background-color:var(--card)}
[data-theme=dark] .ag-card .acts button{color:var(--muted)}
[data-theme=dark] .ag-empty{border-color:var(--line)}
[data-theme=dark] .ag-tbl{background-color:var(--card)}
[data-theme=dark] .ag-row.h{background-color:var(--soft)}
[data-theme=dark] .ag-modal{background-color:var(--card)}
[data-theme=dark] .ag-modal label svg{color:var(--muted)}
[data-theme=dark] .ag-modal input{background-color:var(--field)}
[data-theme=dark] .ag-link button{background-color:var(--field)}
</style>

<?php if(!$ags): ?>
  <div class="ag-empty">Aún no hay agencias colaboradoras. <?php if(can_edit()): ?><a href="#" onclick="agOpen();return false" style="color:var(--accent);font-weight:600">Añade la primera →</a><?php endif; ?></div>
<?php else: ?>
  <div class="ag-grid">
    <?php foreach($ags as $a): $col=$a['color']?:avatar_color($a['nombre']); ?>
      <div class="ag-card">
        <?php if(can_edit()): ?><div class="acts"><button title="Editar" onclick='agOpen(<?= json_encode($a, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG) ?>)'><?= ic('settings',15) ?></button><form method="post" style="margin:0" onsubmit="return erpSubmitAsk(this,'¿Eliminar agencia? Los clientes volverán a tu marca.')"><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button title="Eliminar"><?= ic('trash',15) ?></button></form></div><?php endif; ?>
        <div class="ag-logo" style="background:<?= e($col) ?>"><?php if($a['logo_url']): ?><img src="<?= e($a['logo_url']) ?>" alt="" onerror="this.style.display='none'"><?php else: ?><?= e(mb_strtoupper(mb_substr($a['nombre'],0,1))) ?><?php endif; ?></div>
        <b><?= e($a['nombre']) ?></b>
        <div class="m"><?php $mm=array_filter([$a['web'],$a['email']]); echo e(implode(' · ',$mm) ?: 'Sin datos de contacto'); ?></div>
        <div class="m" style="margin-top:6px"><?= (int)array_sum(array_map(function($c)use($a){return (int)$c['partner_id']===(int)$a['id']?1:0;},$clients)) ?> cliente(s)</div>
        <?php /* El acceso con la marca de la agencia: es el enlace que ella le
                 pasa a sus clientes para que el login salga con su logo. */ ?>
        <div class="ag-link"><code id="agl<?= (int)$a['id'] ?>"><?= e($BASE) ?>/login.php?m=<?= (int)$a['id'] ?></code><button type="button" onclick="agCopiar(<?= (int)$a['id'] ?>,this)">Copiar</button></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h3 style="font-size:15px;margin-bottom:10px;color:var(--ink-strong)">Asignar clientes</h3>
<div class="ag-tbl">
  <div class="ag-row h"><span>Cliente</span><span>Agencia (white-label)</span></div>
  <?php if(!$clients): ?><div style="padding:30px;text-align:center;color:var(--muted)">No hay clientes.</div><?php endif; ?>
  <?php foreach($clients as $c): ?>
    <div class="ag-row">
      <span style="font-weight:600"><?= e($c['name']) ?></span>
      <span><select aria-label="Agencia de marca blanca del cliente" onchange="agAssign(<?= (int)$c['id'] ?>,this.value)" <?= can_edit()?'':'disabled' ?>><option value="">— Tu marca (<?= e($CASA['name']) ?>) —</option><?php foreach($ags as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$c['partner_id']===(int)$a['id']?'selected':'' ?>><?= e($a['nombre']) ?></option><?php endforeach; ?></select></span>
    </div>
  <?php endforeach; ?>
</div>

<script>
/* Copiar el enlace de acceso con la marca de la agencia. Está fuera del bloque
   de edición porque la tarjeta la ve también quien solo puede mirar. */
function agCopiar(id,btn){
  var t=(document.getElementById('agl'+id)||{}).textContent||'';
  var fin=function(){var o=btn.textContent;btn.textContent='Copiado';setTimeout(function(){btn.textContent=o;},1400);};
  if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(fin,function(){if(window.toast)toast('No se ha podido copiar','err');});return;}
  var a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();
  try{document.execCommand('copy');fin();}catch(e){if(window.toast)toast('No se ha podido copiar','err');}
  document.body.removeChild(a);
}
</script>

<?php if(can_edit()): ?>
<div class="ag-ov" id="agOv"><div class="ag-modal">
  <div class="mh" id="agTitle">Nueva agencia</div>
  <form method="post"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="ag_id">
    <?php /* Ocho campos en una sola columna hacían una ventana altísima en la que
             había que bajar para llegar al botón de guardar, y donde el WhatsApp
             (doce cifras) ocupaba lo mismo que un enlace de Calendly. Ahora van en
             dos columnas, cada uno con su ancho, y la ventana cabe entera. */ ?>
    <div class="mb">
      <div class="ag-sep" style="margin-top:0;padding-top:0;border:none">La agencia</div>
      <div class="ag-g">
        <span class="c8"><label><?= ic('building',15) ?> Nombre de la agencia</label><input type="text" name="nombre" id="ag_nombre" required></span>
        <span class="c4"><label><?= ic('eye',15) ?> Color de marca</label><input type="text" name="color" id="ag_color" placeholder="#7b68ee"></span>
        <span class="c12"><label><?= ic('eye',15) ?> Logo (dirección de la imagen)</label><input type="text" name="logo_url" id="ag_logo" placeholder="https://…/logo.png"></span>
      </div>
      <div class="ag-sep">Contacto que ve el cliente en su portal</div>
      <div class="ag-g">
        <span class="c7"><label><?= ic('inbox',15) ?> Email</label><input type="email" name="email" id="ag_email" placeholder="hola@agencia.com"></span>
        <span class="c5"><label>Teléfono</label><input type="tel" name="telefono" id="ag_telefono"></span>
        <span class="c7"><label><?= ic('link',15) ?> Web</label><input type="text" name="web" id="ag_web" placeholder="https://agencia.com"></span>
        <span class="c5"><label><?= ic('chat',15) ?> WhatsApp</label><input type="tel" name="whatsapp" id="ag_whatsapp" placeholder="34600000000"></span>
        <span class="c12"><label><?= ic('cal',15) ?> Enlace para pedir reunión</label><input type="text" name="meeting_url" id="ag_meeting_url" placeholder="https://calendly.com/agencia/reunion"></span>
      </div>
      <div class="ag-hint">Lo que se deje vacío cae a los datos de <?= e($CASA['name']) ?> (apartado Agencia).</div>
    </div>
    <div class="mf"><button class="btn ghost sm" type="button" onclick="document.getElementById('agOv').classList.remove('on')">Cancelar</button><button class="btn sm" type="submit">Guardar</button></div>
  </form>
</div></div>
<script>
function agOpen(d){d=d||{};document.getElementById('agTitle').textContent=d.id?'Editar agencia':'Nueva agencia';
  /* Los ids del formulario coinciden ya con el nombre de la columna salvo el
     logo, así que la tabla de equivalencias solo hace falta para ese. */
  ['id','nombre','logo','color','web','email','whatsapp','meeting_url','telefono'].forEach(function(k){var map={logo:'logo_url'};var col=map[k]||k;var el=document.getElementById('ag_'+k);if(el)el.value=(d[col]!=null?d[col]:'');});
  document.getElementById('agOv').classList.add('on');setTimeout(function(){document.getElementById('ag_nombre').focus();},50);}
document.getElementById('agOv').addEventListener('click',function(e){if(e.target===this)this.classList.remove('on');});
function agAssign(cid,pid){fetch('agencias.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=assign&client_id='+cid+'&partner_id='+pid})
  .then(function(r){if(!r.ok)throw 0;if(window.toast)toast('Cliente asignado');})
  .catch(function(){if(window.toast)toast('No se pudo asignar','err');});}
</script>
<?php endif; ?>
<?php aj_foot(); ?>
