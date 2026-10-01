<?php
require_once __DIR__ . '/_layout.php';

/* Listado base: clientes + su tipo. Se lee `activo` (con COALESCE, como el nav)
   para poder separar «en alta» de los dados de baja. `created_at` ya no se pide:
   no se mostraba en ningún sitio. */
/* alcance_sql() no filtra nada para quien tiene «Ve todos los clientes» (el caso
   normal). A quien no lo tenga le deja solo los suyos: aquellos donde tiene
   tareas asignadas o contactos a su nombre. Ver lib/permisos.php. */
$clients = db()->query(
  'SELECT c.id, c.name, c.username, c.iniciales, c.conversiones, COALESCE(c.activo,1) AS activo, c.tipo_id, t.nombre AS tipo_nombre
   FROM clients c LEFT JOIN client_types t ON t.id = c.tipo_id
   WHERE 1 ' . alcance_sql('c.id') . '
   ORDER BY c.name')->fetchAll();
$tipos = db()->query('SELECT id, nombre FROM client_types ORDER BY nombre')->fetchAll();

/* Contadores por cliente en UNA consulta agregada por métrica (nada de N+1).
   Cada una va en su try/catch: si una tabla aún no existe en esta instalación,
   simplemente no hay contadores de esa métrica y el listado no se rompe. */
$openTasks = [];
try { foreach (db()->query("SELECT client_id cid, COUNT(*) n FROM tasks WHERE estado<>'completada' AND client_id IS NOT NULL GROUP BY client_id") as $r) $openTasks[(int)$r['cid']] = (int)$r['n']; } catch (Exception $e) {}
$openTk = [];
try { foreach (db()->query("SELECT client_id cid, COUNT(*) n FROM support_tickets WHERE estado IN ('abierto','en_curso','esperando') AND client_id IS NOT NULL GROUP BY client_id") as $r) $openTk[(int)$r['cid']] = (int)$r['n']; } catch (Exception $e) {}
$pendCob = [];
try {
  foreach (db()->query("SELECT i.client_id cid, COALESCE(SUM(it.base*(1+i.iva_pct/100-i.irpf_pct/100)),0) tot
                        FROM invoices i
                        JOIN (SELECT invoice_id, COALESCE(SUM(cantidad*precio),0) base FROM invoice_items GROUP BY invoice_id) it ON it.invoice_id=i.id
                        WHERE i.client_id IS NOT NULL AND i.estado NOT IN ('borrador','pagada')
                        GROUP BY i.client_id") as $r) $pendCob[(int)$r['cid']] = (float)$r['tot'];
} catch (Exception $e) {}

$nAct = 0; foreach ($clients as $c) { if ((int)$c['activo'] === 1) $nAct++; }
$nNo  = count($clients) - $nAct;

ahead('Clientes en alta');
?>
<style>
/* Solo maquetación local (avatar + colocación). Etiquetas y contadores usan los
   componentes estándar del ERP: .tag, .chip, .seg y .icon-btn. */
/* La tabla global (td) centra por línea base: con el nombre + usuario + etiquetas
   apilados en una celda y una sola etiqueta en la de al lado, las columnas se
   veían a distinta altura. Aquí se centran todas y se les da algo más de aire,
   que era lo que faltaba: las filas iban apretadas. */
#cliTable td{vertical-align:middle;padding-top:18px;padding-bottom:18px}
#cliTable th{padding-top:6px;padding-bottom:12px}
.cli-bar{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:22px}
.cli-buscar{flex:1;min-width:210px;position:relative;display:flex;align-items:center}
.cli-buscar .lupa{position:absolute;left:12px;z-index:1;display:inline-flex;color:var(--muted);pointer-events:none}
.cli-buscar input{padding-left:36px;width:100%}
.cli-av{width:34px;height:34px;border-radius:9px;color:#fff;display:flex;align-items:center;justify-content:center;
  font-weight:700;font-size:13px;flex:none;transition:transform .16s cubic-bezier(.2,.7,.3,1)}
tr.clirow:hover .cli-av{transform:scale(1.06)}
/* Avatar · nombre · etiquetas, todo en una fila y centrado. */
.cli-name{display:flex;align-items:center;gap:13px;flex-wrap:wrap}
.cli-name .nm{color:var(--accent);font-weight:600;text-decoration:none;font-size:14.5px}
.cli-name .nm:hover{text-decoration:underline}
/* El filtro de tipo se sale a lo ancho (el select global es width:100%) y quedaba
   como una barra enorme en su propia línea. Aquí se ajusta a su contenido y se
   alinea con el buscador y el segmentado, en la misma fila. */
#cliFilter{flex:0 0 auto;width:auto;min-width:186px;max-width:240px}
.cli-act{display:flex;gap:6px;flex-wrap:wrap}
tr.clirow{transition:background .14s ease}
tr.clirow.inact td{background:var(--line2)}
tr.clirow.inact .cli-av{opacity:.55}
/* Las acciones aparecen al pasar por la fila, como en Tareas y en Mi equipo.
   Ocupan su sitio siempre para que la fila no dé un salto al aparecer. */
.cli-btns{display:flex;gap:2px;justify-content:flex-end;opacity:0;transition:opacity .16s ease}
tr.clirow:hover .cli-btns,tr.clirow:focus-within .cli-btns{opacity:1}
.cli-btns .icon-btn{transition:background .12s ease,color .12s ease,transform .14s ease}
.cli-btns .icon-btn:hover{background:var(--soft);color:var(--ink);transform:translateY(-1px)}
@media(max-width:760px){ .cli-btns{opacity:1} }
/* ---- Móvil (teléfono): cada cliente en una FILA PLANA y compacta (nombre +
   tipo/actividad en una línea de apoyo), sin tarjeta con borde ni scroll
   horizontal. Toca el nombre para abrir su ficha. ---- */
@media(max-width:640px){
  .cli-bar{gap:8px}
  #cliSeg{width:100%}
  .cli-buscar{flex:1 0 100%;min-width:0;order:3}
  #cliFilter{flex:1;min-width:0;max-width:none}
  #cliTable thead{display:none}
  #cliTable,#cliTable tbody,#cliTable tr,#cliTable td{display:block}
  #cliTable td{padding:0!important;border:none}
  /* Cada cliente = una FILA PLANA y compacta (~11px), sin borde-caja por elemento.
     Nombre en la primera línea; tipo y actividad, pequeños, en la línea de apoyo. */
  #cliTable tr.clirow{display:flex;flex-wrap:wrap;align-items:center;gap:3px 10px;
    padding:11px 2px;margin:0;border:none;border-bottom:1px solid var(--line);background:none}
  tr.clirow.inact,tr.clirow.inact td{background:none}
  #cliTable td:first-child{flex:1 0 100%}
  #cliTable td:nth-child(2),#cliTable td:nth-child(3){flex:0 0 auto}
  .cli-name{gap:9px}
  .cli-av{width:28px;height:28px;border-radius:8px;font-size:11px}
  .cli-name .nm{font-size:14px}
  .cli-act{gap:5px}
  .cli-act .chip{font-size:11px}
  /* Las acciones se abren desde la ficha (toca el nombre) o el clic largo. */
  #cliTable td:last-child{display:none}
  #cliEmpty td{display:block!important;padding:16px 2px!important}
}
</style>

<div class="flex" style="margin-bottom:16px">
  <div class="sp"><h1>Clientes en alta</h1><div class="lead"><span id="cliCount"><?= (int)$nAct ?></span> cliente<span id="cliPl">s</span> a la vista.</div></div>
  <?php if (can_edit()): ?><a class="btn" href="edit.php"><?= ic('plus',15) ?> Nuevo cliente</a><?php endif; ?>
</div>

<?php if ($clients): ?>
<?php /* El estado pasa a ser un segmentado con contadores, el mismo control que
         elige de quién es la ficha en Ajustes › Facturación. Antes era un
         desplegable: había que abrirlo para saber en qué filtro estabas y no
         decía cuántos había en cada uno. El tipo se queda en desplegable porque
         pueden ser muchos. */ ?>
<div class="cli-bar">
  <div class="seg" id="cliSeg">
    <button type="button" class="on" data-est="act">En alta <span class="cnt"><?= (int)$nAct ?></span></button>
    <button type="button" data-est="no">No activos <span class="cnt"><?= (int)$nNo ?></span></button>
    <button type="button" data-est="all">Todos <span class="cnt"><?= count($clients) ?></span></button>
  </div>
  <div class="cli-buscar">
    <span class="lupa"><?= ic('search',16) ?></span>
    <input id="cliSearch" type="text" placeholder="Buscar por nombre o usuario…">
  </div>
  <select id="cliFilter" title="Filtrar por tipo de cliente">
    <option value="">Todos los tipos</option>
    <?php foreach ($tipos as $t): ?><option value="t<?= (int)$t['id'] ?>"><?= e($t['nombre']) ?></option><?php endforeach; ?>
    <option value="none">— Sin tipo —</option>
  </select>
</div>
<input type="hidden" id="cliEstado" value="act">
<?php endif; ?>

<div class="card">
<?php if (!$clients): ?>
  <div class="muted" style="text-align:center;padding:40px 20px">
    <div style="margin-bottom:12px;color:var(--muted)"><?= ic('clients',30) ?></div>
    <div style="font-weight:600;color:var(--ink-strong);font-size:15px;margin-bottom:6px">Aún no hay clientes</div>
    <?php if (can_edit()): ?>
      <p style="margin:0 0 14px">Crea el primero para montar su portal, sus tareas y su facturación.</p>
      <a class="btn" href="edit.php"><?= ic('plus',15) ?> Nuevo cliente</a>
    <?php else: ?>
      <p style="margin:0">Cuando el equipo dé de alta un cliente, aparecerá aquí.</p>
    <?php endif; ?>
  </div>
<?php else: ?>
  <table id="cliTable">
    <thead><tr><th>Cliente</th><th>Tipo</th><th>Actividad</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($clients as $c): ?>
      <?php
        $activo = (int)$c['activo'] === 1;
        $ini = trim((string)$c['iniciales']) !== '' ? $c['iniciales'] : mb_substr((string)$c['name'], 0, 2);
        $nT = $openTasks[(int)$c['id']] ?? 0;
        $nK = $openTk[(int)$c['id']] ?? 0;
        $pc = $pendCob[(int)$c['id']] ?? 0;
      ?>
      <?php /* Clic derecho sobre la fila, igual que en Tareas y en el CRM. */ ?>
      <tr class="clirow<?= $activo?'':' inact' ?>"
          data-name="<?= e(mb_strtolower($c['name'].' '.$c['username'])) ?>"
          data-tipo="<?= $c['tipo_id'] ? 't'.(int)$c['tipo_id'] : 'none' ?>"
          data-activo="<?= $activo?'1':'0' ?>"
          oncontextmenu="return cliMenu(event,<?= (int)$c['id'] ?>,<?= htmlspecialchars(json_encode($c['name']), ENT_QUOTES) ?>)">
        <td>
          <?php /* Una sola línea: avatar · nombre · etiquetas. El usuario ya no se
                   pinta aquí —era casi siempre el nombre en minúsculas y parecía
                   duplicado; se ve y se edita en la ficha del cliente. Y las
                   etiquetas van EN LÍNEA, no apiladas en una columna que descuadraba
                   la fila. */ ?>
          <div class="cli-name">
            <span class="cli-av" style="background:<?= avatar_color($c['name']) ?>"><?= e(mb_strtoupper(mb_substr($ini,0,2))) ?></span>
            <a class="nm" href="client.php?id=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a>
            <?php if (!$activo): ?><span class="tag" style="background:#feecec;color:#c0343a">No activo</span><?php endif; ?>
            <?php if ($c['conversiones']): ?><span class="tag">Conversiones</span><?php endif; ?>
          </div>
        </td>
        <td>
          <?php if (!empty($c['tipo_nombre'])): ?><span class="tag on"><?= e($c['tipo_nombre']) ?></span>
          <?php else: ?><span class="tag">Sin tipo</span><?php endif; ?>
        </td>
        <td>
          <?php if ($nT || $nK || $pc): ?>
          <div class="cli-act">
            <?php if ($nT): ?><span class="chip"><?= ic('check',13) ?><?= (int)$nT ?> tarea<?= $nT==1?'':'s' ?></span><?php endif; ?>
            <?php if ($nK): ?><span class="chip"><?= ic('ticket',13) ?><?= (int)$nK ?> ticket<?= $nK==1?'':'s' ?></span><?php endif; ?>
            <?php if ($pc && puede_importes()): ?><span class="chip"><?= ic('euro',13) ?><?= eur($pc) ?></span><?php endif; ?>
          </div>
          <?php else: ?><span class="muted" style="font-size:12px">Sin actividad</span><?php endif; ?>
        </td>
        <td style="text-align:right;white-space:nowrap">
          <div class="cli-btns">
            <a class="icon-btn" href="client.php?id=<?= (int)$c['id'] ?>" title="Abrir su ficha"><?= ic('eye',16) ?></a>
            <a class="icon-btn" href="workspace.php?view=cliente&cli=<?= (int)$c['id'] ?>" title="Sus tareas"><?= ic('tasks',16) ?></a>
            <a class="icon-btn" href="facturas.php?cli=<?= (int)$c['id'] ?>" title="Sus facturas"><?= ic('euro',16) ?></a>
            <?php if (can_edit()): ?>
              <a class="icon-btn" href="edit.php?id=<?= (int)$c['id'] ?>" title="Editar sus datos"><?= ic('pencil',16) ?></a>
              <a class="icon-btn" href="#" title="Duplicar" onclick="return erpAsk('¿Crear una copia de <?= e(addslashes($c['name'])) ?>? Podrás editarla después.',{post:'duplicate.php',data:{id:<?= (int)$c['id'] ?>},ok:'Duplicar'})"><?= ic('layers',16) ?></a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tbody id="cliEmpty" style="display:none"><tr><td colspan="4" class="muted" style="padding:18px">No hay clientes que coincidan con la búsqueda.</td></tr></tbody>
  </table>
  <div class="ctxmenu" id="cliCtx"></div>
<?php endif; ?>
</div>

<script>
/* Menú de clic derecho de la fila de cliente. Atajos a su ficha, tareas, facturas.
   Duplicar y borrar solo aparecen si la cuenta puede editar; borrar avisa antes. */
var CLI_PUEDE_EDITAR = <?= can_edit()?'true':'false' ?>;
function cliMenu(e,id,nombre){
  if(e.target.closest('a,button')) return true;   // clic derecho sobre un enlace: menú del navegador
  e.preventDefault();
  var m=document.getElementById('cliCtx'); if(!m) return false;
  m.innerHTML='';
  function it(txt,href,danger,fn){
    var a=document.createElement('a'); a.textContent=txt; a.href=href||'#';
    if(danger) a.className='danger';
    a.onclick=function(ev){ if(fn){ ev.preventDefault(); m.classList.remove('on'); fn(); } };
    m.appendChild(a); return a;
  }
  function sep(){ var s=document.createElement('div'); s.className='sep'; m.appendChild(s); }
  it('Abrir ficha','client.php?id='+id);
  it('Tareas del cliente','workspace.php?view=cliente&cli='+id);
  it('Facturas','facturas.php?cli='+id);
  if(CLI_PUEDE_EDITAR){
    sep();
    it('Editar datos','edit.php?id='+id);
    it('Duplicar','#',false,function(){
      erpAsk('¿Crear una copia de «'+nombre+'»? Podrás editarla después.',{post:'duplicate.php',data:{id:id},ok:'Duplicar'});
    });
    sep();
    it('Borrar cliente','#',true,function(){
      erpConfirm('Se borran también sus tareas, listas, tickets y accesos. Queda 30 días en la papelera por si acaso.',
        {titulo:'¿Borrar «'+nombre+'»?',ok:'Borrar',danger:true}).then(function(ok){
          if(ok) erpPost('delete.php',{id:id});
        });
    });
  }
  m.style.left=Math.min(e.clientX,window.innerWidth-200)+'px';
  m.style.top=Math.min(e.clientY,window.innerHeight-240)+'px';
  m.classList.add('on');
  return false;
}
document.addEventListener('click',function(e){
  if(!e.target.closest('#cliCtx')){ var m=document.getElementById('cliCtx'); if(m) m.classList.remove('on'); }
});
</script>

<script>
(function(){
  var s=document.getElementById('cliSearch'), f=document.getElementById('cliFilter'), es=document.getElementById('cliEstado');
  if(!s) return;
  var rows=[].slice.call(document.querySelectorAll('.clirow'));
  var count=document.getElementById('cliCount'), plural=document.getElementById('cliPl'), empty=document.getElementById('cliEmpty');
  function norm(x){ return (x||'').toLowerCase(); }
  function apply(){
    var q=norm(s.value).trim(), tf=f.value, ef=es.value, vis=0;
    rows.forEach(function(r){
      var okText = q==='' || r.getAttribute('data-name').indexOf(q)>=0;
      var okTipo = tf==='' || r.getAttribute('data-tipo')===tf;
      var act = r.getAttribute('data-activo')==='1';
      var okEst = ef==='all' || (ef==='act' && act) || (ef==='no' && !act);
      var show = okText && okTipo && okEst;
      r.style.display = show ? '' : 'none';
      if(show) vis++;
    });
    if(count) count.textContent=vis;
    if(plural) plural.textContent = vis===1 ? '' : 's';
    if(empty) empty.style.display = vis===0 ? '' : 'none';
  }
  /* El estado viene del segmentado; su valor se guarda en un campo oculto para
     no cambiar la lógica del filtro, que ya funcionaba. */
  document.querySelectorAll('#cliSeg [data-est]').forEach(function(b){
    b.addEventListener('click', function(){
      document.querySelectorAll('#cliSeg [data-est]').forEach(function(o){ o.classList.toggle('on', o===b); });
      es.value = b.dataset.est;
      apply();
    });
  });
  s.addEventListener('input', apply); f.addEventListener('change', apply);
  apply();   // por defecto muestra solo los activos («en alta»)
})();
</script>
<?php afoot(); ?>
