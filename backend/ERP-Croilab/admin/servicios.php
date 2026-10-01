<?php
/* Catálogo de SERVICIOS de la agencia: lo que ofreces y lo que le puedes asignar
   a cada cliente. Se guarda en settings -> servicios_catalogo (JSON).

   Antes era una lista de inputs planos, todos iguales, sin decir nada más. No se
   veía a cuántos clientes afectaba cada servicio, ni cuáles tenían vídeo, y sobre
   todo: renombrar uno **desenganchaba en silencio a todos los clientes que lo
   tenían**, porque cada cliente guarda los suyos por nombre en
   clients.servicios_json. El portal les bloqueaba la sección y nadie se enteraba
   hasta que el cliente lo decía.

   Ahora cada fila enseña a cuánta gente afecta, si tiene vídeo, y al renombrar se
   arrastra el cambio a las fichas de los clientes. Ver la cabecera de
   lib/servicios_cat.php. */
require_once __DIR__ . '/../auth.php';
require_admin();
require_once __DIR__ . '/lib/servicios_cat.php';

/* Todo el POST antes de erp_nav.php y terminando en redirect (docs/05 §12). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can_edit()) {
  $noms  = (array)($_POST['nombre'] ?? []);
  $descs = (array)($_POST['desc']   ?? []);
  /* El nombre con el que se cargó cada fila. Comparándolo con el actual se sabe
     qué se ha renombrado, que es lo que hay que arrastrar a los clientes. */
  $origs = (array)($_POST['orig']   ?? []);

  $out = []; $movidos = 0; $renombrados = 0;
  foreach ($noms as $i => $n) {
    $n = trim((string)$n);
    if ($n === '') continue;
    $orig = trim((string)($origs[$i] ?? ''));
    if ($orig !== '' && $orig !== $n) { $movidos += svc_renombrar_en_clientes($orig, $n); $renombrados++; }
    $out[] = ['nombre'=>$n, 'desc'=>(string)($descs[$i] ?? '')];
  }

  /* Un servicio que desaparece de la lista se quita también de las fichas: si no,
     el cliente se queda con un servicio que ya no existe en ningún sitio. */
  $quedan = array_column($out, 'nombre');
  $quitados = 0;
  foreach (svc_catalogo() as $s)
    if (!in_array($s['nombre'], $quedan, true)) { $quitados += svc_renombrar_en_clientes($s['nombre'], ''); }

  svc_guardar($out);
  header('Location: servicios.php?ok=1&r='.(int)$renombrados.'&c='.(int)($movidos+$quitados)); exit;
}

require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/ajustes_nav.php';

$svcs = svc_catalogo();
$uso  = svc_uso();
$rw   = can_edit();

aj_head('servicios', '',
  'Lo que ofreces y lo que le puedes asignar a cada cliente. Está disponible al crear presupuestos y programaciones, y decide qué secciones ve cada cliente en su portal.');
?>
<style>
/* Filas de lista: mismo patrón que el resto del ERP (fila con hover, separador
   suave, acciones a la derecha que aparecen al pasar). */
.sv-r{display:grid;grid-template-columns:1fr 1.5fr auto;gap:16px;align-items:center;
  padding:15px 20px;border-bottom:1px solid var(--line2)}
.sv-r:last-of-type{border-bottom:none}
.sv-r:hover{background:#fafbfc}
.sv-r input{width:100%}
.sv-meta{display:flex;align-items:center;gap:7px;justify-content:flex-end;min-width:190px}
.sv-r .icon-btn{opacity:0;transition:opacity .14s ease}
.sv-r:hover .icon-btn{opacity:1}
.sv-r .icon-btn:hover{background:#feecec;color:#c0343a}
.sv-h{display:grid;grid-template-columns:1fr 1.5fr auto;gap:16px;padding:0 20px 10px;
  font-size:12.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);font-weight:600}
.sv-h span:last-child{text-align:right;min-width:190px}
.inline-add{border-top:1px solid var(--line2)}
/* Una fila nueva entra deslizándose en vez de aparecer de golpe. */
.sv-r.nueva{animation:erpIn .26s cubic-bezier(.2,.7,.3,1) both}
.sv-r.saliendo{animation:erpOut .18s ease forwards}
@media(max-width:760px){ .sv-r,.sv-h{grid-template-columns:1fr} .sv-h{display:none} .sv-meta{justify-content:flex-start;min-width:0} }
/* Modo oscuro: hover de fila y borrado. */
[data-theme=dark] .sv-r:hover{background-color:var(--soft)}
[data-theme=dark] .sv-r .icon-btn:hover{background-color:var(--danger-bg);color:var(--danger)}
</style>

<?php if(isset($_GET['ok'])):
  $nR=(int)($_GET['r']??0); $nC=(int)($_GET['c']??0); ?>
  <div class="ok-note">Catálogo guardado.<?php if($nR && $nC): ?> Se ha cambiado el nombre en la ficha de <?= $nC ?> cliente<?= $nC==1?'':'s' ?>.<?php endif; ?></div>
<?php endif; ?>

<form method="post" class="aj-save">
  <div class="card" style="padding:0;overflow:hidden">
    <div style="padding:18px 18px 0">
      <div class="sec-t" style="margin:0 0 12px"><?= count($svcs) ?> servicio<?= count($svcs)==1?'':'s' ?> en el catálogo</div>
      <?php /* Sin esta frase el contador de cada fila se lee mal: si nadie tiene la
               lista personalizada, todos los servicios los ven todos los clientes, y
               el número de al lado parece un error. */
            if ($uso['abiertos']): ?>
        <div class="muted" style="font-size:12.5px;margin:-6px 0 14px;line-height:1.5">
          <?= (int)$uso['abiertos'] ?> de tus <?= (int)$uso['total'] ?> clientes no tienen la lista de servicios personalizada,
          así que <b>ven todos</b>. Se elige cliente a cliente desde su portal.
        </div>
      <?php endif; ?>
    </div>
    <div class="sv-h"><span>Servicio</span><span>Descripción corta</span><span>Estado</span></div>

    <div id="svcRows">
      <?php foreach ($svcs as $s): $n = svc_uso_de($uso, $s['nombre']); ?>
        <div class="sv-r">
          <div>
            <input type="text" name="nombre[]" value="<?= e($s['nombre']) ?>" placeholder="Nombre del servicio" <?= $rw?'':'disabled' ?>>
            <input type="hidden" name="orig[]" value="<?= e($s['nombre']) ?>">
          </div>
          <input type="text" name="desc[]" value="<?= e($s['desc']) ?>" placeholder="Para qué es, en una línea" <?= $rw?'':'disabled' ?>>
          <div class="sv-meta">
            <?php /* Los dos datos que antes no se veían y que cambian la decisión
                     de tocar un servicio: a cuánta gente afecta y si tiene vídeo. */ ?>
            <span class="tag <?= $n?'on':'' ?>" title="Clientes que ven este servicio en su portal"><?= $n ?> cliente<?= $n==1?'':'s' ?></span>
            <?php if($s['video']!==''): ?>
              <span class="tag" title="Tiene su propio vídeo en el portal"><?= ic('eye',12) ?> Vídeo</span>
            <?php endif; ?>
            <?php if($rw): ?>
              <button type="button" class="icon-btn" title="Quitar del catálogo"
                onclick="svcQuitar(this,<?= $n ?>,'<?= e(addslashes($s['nombre'])) ?>')"><?= ic('trash',15) ?></button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php /* Antes esto era un .addbtn: un rectángulo de borde discontinuo que
             parecía un hueco vacío al final de la lista. Ahora es la fila de
             añadir del tablero de Tareas (.inline-add, en erp_nav.php): una fila
             más, con su «+» que gira al pasar por encima. */
          if($rw): ?>
      <button type="button" class="inline-add" onclick="svcAnadir()">
        <span class="plus"><?= ic('plus',16) ?></span> Añadir servicio
      </button>
    <?php endif; ?>
  </div>

  <?php if($rw): ?>
    <div class="flex" style="gap:12px;align-items:center;flex-wrap:wrap">
      <button class="btn" type="submit"><?= ic('check',15) ?> Guardar catálogo</button>
      <span class="muted" style="font-size:12.5px">Al cambiarle el nombre a un servicio, se cambia también en la ficha de los clientes que lo tengan.
        El vídeo de cada uno se pone en <a href="settings.php?tab=videos">Vídeos</a>.</span>
    </div>
  <?php else: ?>
    <div class="muted" style="font-size:13px">Solo lectura: no puedes cambiar el catálogo con tu permiso actual.</div>
  <?php endif; ?>
</form>

<template id="svcTpl">
  <div class="sv-r">
    <div>
      <input type="text" name="nombre[]" placeholder="Nombre del servicio">
      <input type="hidden" name="orig[]" value="">
    </div>
    <input type="text" name="desc[]" placeholder="Para qué es, en una línea">
    <div class="sv-meta">
      <span class="tag">Nuevo</span>
      <button type="button" class="icon-btn" title="Quitar" onclick="svcQuitar(this,0,'')"></button>
    </div>
  </div>
</template>

<script>
/* Añadir o quitar filas no dispara ningún «input», así que hay que avisar al
   marco o el aviso de «Sin guardar» no se entera (ver lib/ajustes_nav.php). */
function svcTocado(el){
  var f = el && el.closest ? el.closest('form') : document.querySelector('form.aj-save');
  if (f) f.dispatchEvent(new Event('input',{bubbles:true}));
}

function svcAnadir(){
  var c = document.getElementById('svcRows');
  c.appendChild(document.getElementById('svcTpl').content.cloneNode(true));
  var filas = c.querySelectorAll('.sv-r');
  var ultima = filas[filas.length-1];
  ultima.classList.add('nueva');
  /* El icono de la papelera se copia del que ya está pintado: así no hay un SVG
     escrito a mano aquí (docs/05 §3 — los iconos salen de ic()). */
  var modelo = document.querySelector('#svcRows .sv-r .icon-btn svg');
  var btn = ultima.querySelector('.icon-btn');
  if (modelo && btn && !btn.querySelector('svg')) btn.appendChild(modelo.cloneNode(true));
  ultima.querySelector('input').focus();
  svcTocado(c);
}

/* Quitar uno que está contratado desengancha a esos clientes: se avisa con el
   número antes de hacerlo, no después. */
function svcQuitar(btn, nClientes, nombre){
  var fila = btn.closest('.sv-r');
  var fuera = function(){
    fila.classList.add('saliendo');
    setTimeout(function(){ fila.remove(); svcTocado(document.getElementById('svcRows')); }, 170);
  };
  if (!nClientes) { fuera(); return; }
  erpConfirm('«'+nombre+'» lo tienen '+nClientes+' cliente'+(nClientes==1?'':'s')+' contratado. Si lo quitas del catálogo, dejará de aparecer en su portal.',
    {titulo:'Quitar del catálogo', ok:'Quitar igualmente', danger:true})
    .then(function(si){ if(si) fuera(); });
}
</script>
<?php aj_foot(); ?>
