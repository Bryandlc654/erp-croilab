<?php
/* Papelera del ERP: todo lo que se ha borrado en los últimos 30 días, con su
   botón para devolverlo a su sitio. También es el endpoint que usa el
   «Deshacer» del toast, en modo JSON. */
require_once __DIR__ . '/../auth.php';
require_admin();
require_once __DIR__ . '/lib/papelera.php';
ensure_papelera_schema();

/* ---------- acciones ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $a = $_POST['action'] ?? '';
    $json = isset($_POST['json']);

    if ($a === 'restore') {
        /* Restaurar reinserta filas de negocio: es una escritura. Antes no
           comprobaba permiso y un usuario de solo lectura podía restaurar (P1-03). */
        if (!can_edit()) {
            $r = ['ok'=>false, 'msg'=>'No tienes permiso para restaurar.'];
            if ($json) { header('Content-Type: application/json'); http_response_code(403); echo json_encode($r); exit; }
            header('Location: papelera.php?flash=' . rawurlencode($r['msg']) . '&fok=0'); exit;
        }
        $r = pap_restaurar((int)($_POST['tid'] ?? 0));
        if ($json) { header('Content-Type: application/json'); echo json_encode($r); exit; }
        header('Location: papelera.php?flash=' . rawurlencode($r['msg']) . '&fok=' . ($r['ok'] ? '1' : '0')); exit;
    }
    if ($a === 'purge' && can_edit()) {
        pap_vaciar((int)($_POST['tid'] ?? 0));
        $msg = (int)($_POST['tid'] ?? 0) ? 'Eliminado definitivamente.' : 'Papelera vaciada.';
        if ($json) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>$msg]); exit; }
        header('Location: papelera.php?flash=' . rawurlencode($msg) . '&fok=1'); exit;
    }
    header('Location: papelera.php'); exit;
}

require_once __DIR__ . '/erp_nav.php';
$items = pap_lista(300);
erp_head('papelera', 'Papelera');
?>
<style>
.pp-head{display:flex;align-items:center;gap:12px;margin-bottom:6px;flex-wrap:wrap}
.pp-head h1{flex:1;font-size:24px;margin:0}
.pp-note{font-size:13.5px;color:var(--muted);margin-bottom:20px;line-height:1.55}
.pp-list{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
.pp-r{display:flex;align-items:center;gap:14px;padding:15px 20px;border-top:1px solid var(--line2)}
.pp-r:first-child{border-top:none}
.pp-r:hover{background:var(--soft)}
.pp-r .ic{width:34px;height:34px;border-radius:10px;background:var(--soft);color:var(--ink-strong);display:flex;align-items:center;justify-content:center;flex:none}
.pp-r:hover .ic{background:#fff}
.pp-r .m{flex:1;min-width:0}
.pp-r .m .t{font-size:14px;font-weight:600;color:var(--ink-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pp-r .m .s{font-size:12.5px;color:var(--muted);margin-top:3px}
.pp-r .kind{font-size:11px;font-weight:700;color:var(--muted);background:var(--accent-soft);border-radius:999px;padding:3px 10px;flex:none}
.pp-r form{display:inline}
.pp-r .undo{border:1px solid var(--line);background:#fff;border-radius:9px;padding:7px 13px;font-size:12.5px;font-weight:600;color:var(--ink);cursor:pointer;font-family:inherit}
.pp-r .undo:hover{background:var(--ink-strong);color:#fff;border-color:var(--ink-strong)}
.pp-r .kill{border:none;background:none;color:#c2c6cd;cursor:pointer;padding:7px;border-radius:8px;flex:none}
.pp-r .kill:hover{background:#fdecec;color:#e5484d}
.pp-empty{padding:48px 20px;text-align:center;color:var(--muted);font-size:14px}
.pp-empty .ic{width:46px;height:46px;border-radius:14px;background:var(--soft);color:var(--muted);display:flex;align-items:center;justify-content:center;margin:0 auto 12px}
/* Móvil (≤640px): la fila no cabe con el icono, el título, la etiqueta y los dos
   botones en línea. El icono y el texto se quedan arriba (el texto ocupa el resto)
   y la etiqueta con los botones bajan a una segunda línea, cómodos de tocar. */
@media(max-width:640px){
  .pp-r{flex-wrap:wrap;padding:14px 16px}
  .pp-r .m{flex:1 1 60%}
  .pp-r .kind{order:3}
  .pp-r form{order:4}
  .pp-r .undo{padding:9px 15px}
}
/* Modo oscuro: lista, botón restaurar (pasa al "negro" invertido) y borrado. */
[data-theme=dark] .pp-list{background-color:var(--card)}
[data-theme=dark] .pp-r:hover .ic{background-color:var(--card)}
[data-theme=dark] .pp-r .undo{background-color:var(--field)}
[data-theme=dark] .pp-r .undo:hover{background-color:var(--rev);color:var(--rev-fg);border-color:var(--rev)}
[data-theme=dark] .pp-r .kill{color:var(--muted)}
[data-theme=dark] .pp-r .kill:hover{background-color:var(--danger-bg);color:var(--danger)}
</style>

<div class="pp-head">
  <h1>Papelera</h1>
  <?php if($items && can_edit()): ?>
  <form method="post" onsubmit="return erpSubmitAsk(this,'Se eliminarán definitivamente los <?= count($items) ?> elementos de la papelera. Esto ya no tiene vuelta atrás.',{titulo:'¿Vaciar la papelera?',ok:'Vaciar',danger:true})">
    <input type="hidden" name="action" value="purge">
    <button class="btn ghost sm" type="submit"><?= ic('trash',14) ?> Vaciar papelera</button>
  </form>
  <?php endif; ?>
</div>
<div class="pp-note">Lo que borras en el ERP pasa por aquí y se puede devolver a su sitio. Pasados <?= (int)PAP_DIAS ?> días se elimina solo.</div>

<div class="pp-list">
  <?php if(!$items): ?>
    <div class="pp-empty"><div class="ic"><?= ic('trash',22) ?></div>La papelera está vacía. Nada que recuperar.</div>
  <?php else: foreach($items as $it): $u = pap_tipo_url($it['tipo'], $it['ref_id']); ?>
    <div class="pp-r">
      <div class="ic"><?= ic(pap_tipo_icono($it['tipo']),16) ?></div>
      <div class="m">
        <div class="t"><?= e($it['titulo'] ?: pap_tipo_label($it['tipo']).' #'.(int)$it['ref_id']) ?></div>
        <div class="s"><?= $it['autor'] ? 'Borrado por '.e($it['autor']).' · ' : '' ?><?= date('d/m/Y H:i', strtotime($it['created_at'])) ?></div>
      </div>
      <span class="kind"><?= e(pap_tipo_label($it['tipo'])) ?></span>
      <?php if(can_edit()): ?>
      <form method="post">
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="tid" value="<?= (int)$it['id'] ?>">
        <button class="undo" type="submit"><?= ic('back',14) ?> Restaurar</button>
      </form>
      <?php endif; ?>
      <?php if(can_edit()): ?>
      <form method="post" onsubmit="return erpSubmitAsk(this,'Se elimina definitivamente. Ya no se podrá recuperar.',{titulo:'¿Eliminar del todo?',ok:'Eliminar',danger:true})">
        <input type="hidden" name="action" value="purge">
        <input type="hidden" name="tid" value="<?= (int)$it['id'] ?>">
        <button class="kill" type="submit" title="Eliminar definitivamente"><?= ic('trash',15) ?></button>
      </form>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</div>

<?php if(isset($_GET['flash']) && $_GET['flash']!==''): ?>
<script>window.addEventListener('load',function(){ if(window.toast)toast(<?= json_encode((string)$_GET['flash']) ?><?= (($_GET['fok']??'1')==='1')?'':", 'err'" ?>); });</script>
<?php endif; ?>
<?php erp_foot(); ?>
