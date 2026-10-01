<?php
/* Datos fiscales de los clientes: razón social, NIF, dirección, email y teléfono
   que se copian solos a sus facturas y programaciones.

   Antes esta página tenía dos pestañas y la primera editaba los datos de los dos
   autónomos emisores… que también se editaban en Ajustes, en la pestaña de
   «Autónomos», con cuatro campos menos. Dos formularios para las mismas claves de
   la tabla `settings`, y según por cuál entrases veías unos campos u otros. Los
   emisores viven ahora solo en Ajustes › Facturación, con el juego completo de
   campos, y aquí se queda lo que de verdad es de aquí: los clientes. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';

$me = current_admin();

/* Un solo cliente por envío (el modal). Antes llegaban arrays paralelos
   (`cid[]`, `fn[]`…) sin `action`: si los índices se desalineaban se escribían
   los datos fiscales de un cliente en la ficha de otro (P1-02). Ahora es un
   `action` explícito, con el id en un campo único y validando que existe. */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_cliente' && can_edit()) {
  $cid = (int)($_POST['cid'] ?? 0);
  $existe = false;
  if ($cid) { $ex=db()->prepare('SELECT COUNT(*) FROM clients WHERE id=?'); $ex->execute([$cid]); $existe=(int)$ex->fetchColumn()>0; }
  if ($existe) {
    db()->prepare('UPDATE clients SET fact_nombre=?,fact_nif=?,fact_dir=?,fact_email=?,fact_tel=? WHERE id=?')
      ->execute([trim($_POST['fn']??''),trim($_POST['fi']??''),trim($_POST['fd']??''),trim($_POST['fe']??''),trim($_POST['ft']??''),$cid]);
  }
  header('Location: fin-ajustes.php?ok=cli'); exit;
}
$okC = ($_GET['ok']??'')==='cli';

$clientes = [];
try { $clientes = db()->query('SELECT id,name,fact_nombre,fact_nif,fact_dir,fact_email,fact_tel FROM clients ORDER BY name')->fetchAll(); } catch(Exception $e){}
$sinDatos = 0; foreach($clientes as $c){ if(trim($c['fact_nombre']??'')==='') $sinDatos++; }

erp_head('finaj', 'Facturación de clientes');
?>
<style>
.fa-wrap{max-width:1040px}
.fa-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media(max-width:820px){.fa-grid{grid-template-columns:1fr}}
.fa-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:26px 28px}
.fa-card h3{font-size:16px;font-weight:600;color:var(--ink-strong);margin-bottom:5px}
.fa-card .sub{font-size:12px;color:var(--muted);margin-bottom:18px;line-height:1.5}
.fa-f{margin-bottom:14px}
.fa-f label{font-size:12px;color:var(--muted);font-weight:600;display:block;margin-bottom:6px}
.fa-f input{width:100%;border:1px solid var(--line);border-radius:9px;padding:9px 11px;font-size:13px;font-family:inherit;outline:none;box-sizing:border-box}
.fa-f input:focus{border-color:var(--accent)}
.fa-row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
/* .ok-note está en erp_nav.php. */
/* clientes */
.cf-head{display:flex;align-items:center;gap:12px;margin:6px 0 14px}.cf-head h2{font-size:19px;flex:1}
.cf-badge{background:#fff4e5;border:1px solid #f3dcbf;color:#b7791f;border-radius:99px;padding:4px 12px;font-size:12px;font-weight:600}
.cf-wrap{background:#fff;border:1px solid var(--line);border-radius:16px;overflow-x:auto}
.cf-tbl{border-collapse:collapse;width:100%;min-width:960px}
.cf-tbl thead th{position:sticky;top:0;background:#fbfbfc;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:650;padding:13px 12px;border-bottom:1px solid var(--line);white-space:nowrap}
.cf-tbl td{border-bottom:1px solid var(--line2);padding:9px 10px;vertical-align:middle}
.cf-tbl tr:last-child td{border-bottom:none}
.cf-tbl tr:hover td{background:#fafbfc}
.cf-cli{font-weight:600;color:var(--ink-strong);font-size:13px;white-space:nowrap;display:flex;align-items:center;gap:8px}
.cf-dot{width:8px;height:8px;border-radius:50%;flex:none}
.cf-tbl input{width:100%;border:1px solid var(--line);border-radius:8px;padding:8px 9px;font-size:12.5px;font-family:inherit;outline:none;box-sizing:border-box;min-width:120px}
.cf-tbl input:focus{border-color:var(--accent)}
.cf-foot{display:flex;justify-content:flex-end;padding:14px 16px;border-top:1px solid var(--line);background:#fbfbfc}
.cl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
.cl-card{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid var(--line);border-radius:12px;padding:17px 19px;cursor:pointer;text-align:left;font-family:inherit;transition:.12s}
.cl-card:hover{border-color:var(--accent);box-shadow:0 8px 22px rgba(0,0,0,.06)}
.cl-dot{width:9px;height:9px;border-radius:50%;flex:none}
.cl-nm{font-weight:600;font-size:14px;color:var(--ink-strong);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cl-st{font-size:10.5px;color:var(--muted);font-weight:600;white-space:nowrap}
#cliModal{position:fixed;inset:0;z-index:720;background:rgba(17,19,24,.6);display:none;align-items:center;justify-content:center;padding:20px}
#cliModal.on{display:flex}
#cliModal .clm-in{background:#fff;border-radius:18px;width:100%;max-width:480px;box-shadow:0 30px 80px rgba(0,0,0,.4);overflow:hidden;animation:clmIn .3s cubic-bezier(.22,1,.36,1)}
@keyframes clmIn{from{opacity:0;transform:translateY(14px) scale(.98)}to{opacity:1;transform:none}}
#cliModal .clm-h{display:flex;align-items:center;padding:18px 22px;border-bottom:1px solid var(--line);font-weight:600;font-size:16px}
#cliModal .clm-h span{flex:1}
#cliModal .clm-x{border:none;background:none;font-size:15px;color:var(--muted);cursor:pointer}
#cliModal .clm-body{padding:20px 22px}
#cliModal .clm-foot{display:flex;justify-content:flex-end;gap:10px;margin-top:10px}
[data-theme=dark] .fa-card{background-color:var(--card)}
[data-theme=dark] .fa-f input,[data-theme=dark] .cf-tbl input{background-color:var(--field);color:var(--ink)}
[data-theme=dark] .cf-badge{background-color:var(--soft);border-color:var(--line);color:var(--warn)}
[data-theme=dark] .cf-wrap{background-color:var(--card)}
[data-theme=dark] .cf-tbl thead th{background-color:var(--soft)}
[data-theme=dark] .cf-tbl tr:hover td{background-color:var(--soft)}
[data-theme=dark] .cf-foot{background-color:var(--soft)}
[data-theme=dark] .cl-card{background-color:var(--card)}
[data-theme=dark] #cliModal .clm-in{background-color:var(--card)}
/* ---- Móvil (teléfono) ---- */
@media(max-width:640px){
  .fa-grid{grid-template-columns:1fr}
  .fa-card{padding:20px 18px}
  /* Clientes = filas planas compactas, no una caja por cliente */
  .cl-grid{grid-template-columns:1fr;gap:0;border-top:1px solid var(--line)}
  .cl-card{border:0;border-bottom:1px solid var(--line);border-radius:0;padding:12px 4px;background:transparent}
  .cl-card:hover{border-color:var(--line);box-shadow:none}
  #cliModal{padding:12px;align-items:flex-end}
  #cliModal .clm-in{max-width:100%;max-height:92vh;display:flex;flex-direction:column}
  #cliModal .clm-body{overflow-y:auto;-webkit-overflow-scrolling:touch}
  .fa-row2{grid-template-columns:1fr}
}
</style>
<div class="fa-wrap">
  <h1>Facturación de clientes</h1>
  <div class="lead" style="color:var(--muted);margin:6px 0 16px">Los datos fiscales de cada cliente. Se rellenan solos al crear una factura o una programación. ¿Buscas los datos de los autónomos que emiten (IBAN, IVA, IRPF)? Están en <a href="settings.php?tab=facturacion" style="color:var(--accent);font-weight:600">Ajustes › Facturación</a>.</div>

  <!-- ============ CLIENTES ============ -->
  <div id="sec-clientes">
    <?php if($sinDatos): ?><div class="cf-head"><span class="cf-badge"><?= $sinDatos ?> sin datos</span></div><?php endif; ?>
    <div class="lead" style="color:var(--muted);margin:-6px 0 14px;font-size:13px">Haz clic en un cliente para configurar sus datos de facturación. Se rellenan solos al facturar o programar. El punto verde indica que ya tiene razón social.</div>
    <?php if($okC): ?><div class="ok-note">Datos del cliente guardados.</div><?php endif; ?>
    <?php if(!$clientes): ?>
      <div class="fa-card">Aún no tienes clientes. <a href="edit.php" style="color:var(--accent);font-weight:600">Crear el primero →</a></div>
    <?php else: ?>
    <div class="cl-grid">
      <?php foreach($clientes as $c): $done=trim($c['fact_nombre']??'')!==''; ?>
      <button type="button" class="cl-card" onclick="cliOpen(<?= (int)$c['id'] ?>)">
        <span class="cl-dot" style="background:<?= $done?'#12a150':'#e0b341' ?>"></span>
        <span class="cl-nm"><?= e($c['name']) ?></span>
        <span class="cl-st"><?= $done?'Completo':'Faltan datos' ?></span>
      </button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div id="cliModal" onclick="if(event.target===this)this.classList.remove('on')"><div class="clm-in">
  <div class="clm-h"><span id="clmTitle">Cliente</span><button type="button" class="clm-x" onclick="document.getElementById('cliModal').classList.remove('on')">✕</button></div>
  <form method="post" class="clm-body">
    <input type="hidden" name="action" value="save_cliente">
    <input type="hidden" name="cid" id="clmId">
    <div class="fa-f"><label>Razón social / Nombre fiscal</label><input type="text" name="fn" id="clmFn" <?= can_edit()?'':'disabled' ?>></div>
    <div class="fa-row2">
      <div class="fa-f"><label>NIF / CIF</label><input type="text" name="fi" id="clmFi" placeholder="B12345678" <?= can_edit()?'':'disabled' ?>></div>
      <div class="fa-f"><label>Teléfono</label><input type="text" name="ft" id="clmFt" placeholder="600 000 000" <?= can_edit()?'':'disabled' ?>></div>
    </div>
    <div class="fa-f"><label>Email</label><input type="text" name="fe" id="clmFe" placeholder="correo@cliente.com" <?= can_edit()?'':'disabled' ?>></div>
    <div class="fa-f"><label>Dirección fiscal</label><input type="text" name="fd" id="clmFd" placeholder="C/ …, CP, ciudad" <?= can_edit()?'':'disabled' ?>></div>
    <div class="clm-foot"><button type="button" class="btn ghost" onclick="document.getElementById('cliModal').classList.remove('on')">Cerrar</button><?php if(can_edit()): ?><button type="submit" class="btn">Guardar</button><?php endif; ?></div>
  </form>
</div></div>
<script>
var CLIENTS_JS=<?= json_encode(array_map(function($c){return ['id'=>(int)$c['id'],'name'=>$c['name'],'fn'=>$c['fact_nombre'],'fi'=>$c['fact_nif'],'fd'=>$c['fact_dir'],'fe'=>$c['fact_email'],'ft'=>$c['fact_tel']];},$clientes), JSON_UNESCAPED_UNICODE) ?>;
function cliOpen(id){var c=CLIENTS_JS.find(function(x){return x.id===id;});if(!c)return;
  document.getElementById('clmTitle').textContent=c.name;
  document.getElementById('clmId').value=c.id;
  var F={clmFn:'fn',clmFi:'fi',clmFe:'fe',clmFt:'ft',clmFd:'fd'};
  for(var k in F){var el=document.getElementById(k);if(el)el.value=c[F[k]]||'';}
  document.getElementById('clmFn').placeholder=c.name;
  document.getElementById('cliModal').classList.add('on');}
</script>
<?php erp_foot(); ?>
