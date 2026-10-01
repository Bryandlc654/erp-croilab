<?php
/* Tipos de cliente: cada tipo decide qué secciones ve el cliente en su portal.

   Antes las secciones visibles se listaban como texto gris separado por puntos
   («Inicio · Métricas · Informes»), que se lee igual que una descripción y no
   deja ver de un vistazo qué falta. Ahora son etiquetas .tag, y las secciones
   apagadas se enseñan también, en gris, para que se vea lo que ese tipo NO ve.
   El estado vacío tampoco era uno: era un párrafo suelto dentro de la tarjeta. */
require_once __DIR__ . '/_layout.php';
require_admin();   // editores y dueño pueden ver/crear tipos
/* Apartado de Ajustes: mismo marco y mismo menú que el resto de la configuración. */
require_once __DIR__ . '/lib/ajustes_nav.php';

$SECCIONES = ['metricas'=>'Métricas','progreso'=>'Progreso','informes'=>'Informes','como'=>'Método','accesos'=>'Accesos','plan'=>'Plan'];
$tipos = db()->query('SELECT id, nombre, secciones_json FROM client_types ORDER BY nombre')->fetchAll();

/* Cuántos clientes usa cada tipo: sin este dato, borrar un tipo es a ciegas. */
$uso = [];
try {
  foreach (db()->query('SELECT tipo_id, COUNT(*) n FROM clients WHERE tipo_id IS NOT NULL GROUP BY tipo_id') as $r) $uso[(int)$r['tipo_id']] = (int)$r['n'];
} catch(Exception $e){}

aj_head('tipos', 'Tipos de cliente',
  'Cada tipo decide qué secciones ve el cliente en su portal. Inicio se ve siempre.',
  can_edit() ? '<a class="btn" href="type-edit.php">'.ic('plus',15).' Nuevo tipo</a>' : '');
?>
<style>
.ty-list{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
.ty-row{display:flex;align-items:flex-start;gap:20px;padding:19px 22px;border-bottom:1px solid var(--line2)}
.ty-row:last-child{border-bottom:none}
.ty-row:hover{background:#fafbfc}
.ty-id{flex:none;width:190px}
.ty-nm{font-weight:600;font-size:15px;color:var(--ink-strong)}
.ty-us{font-size:12px;color:var(--muted);margin-top:4px}
.ty-secs{flex:1;display:flex;flex-wrap:wrap;gap:6px;padding-top:2px}
.ty-secs .tag.no{background:transparent;border:1px dashed var(--line);color:#c2c6cc}
.ty-act{flex:none;white-space:nowrap;display:flex;gap:6px}
.ty-empty{background:#fff;border:1px dashed #d9dce1;border-radius:16px;padding:48px 28px;text-align:center}
.ty-empty .t{font-weight:600;font-size:16px;color:var(--ink-strong);margin-bottom:8px}
.ty-empty .s{font-size:13.5px;color:var(--muted);margin-bottom:20px;line-height:1.6}
@media(max-width:760px){.ty-row{flex-wrap:wrap}.ty-id{width:auto;flex:1}}
/* Móvil (≤640px): fila plana y compacta. Nombre+uso arriba, etiquetas de
   secciones justo debajo y las acciones en línea; nada de tarjeta por tipo. */
@media(max-width:640px){
  .ty-row{gap:7px;padding:11px 14px}
  .ty-id,.ty-secs,.ty-act{flex:1 1 100%;width:100%}
  .ty-nm{font-size:14.5px}
  .ty-us{margin-top:2px}
  .ty-secs{gap:5px}
  .ty-secs .tag{font-size:11px;padding:3px 8px}
  .ty-act{justify-content:flex-start;gap:6px}
}
/* Modo oscuro: solo superficies y textos propios de la lista de tipos. */
[data-theme=dark] .ty-list{background-color:var(--card)}
[data-theme=dark] .ty-row:hover{background-color:var(--soft)}
[data-theme=dark] .ty-secs .tag.no{color:var(--muted)}
[data-theme=dark] .ty-empty{background-color:var(--card);border-color:var(--line)}
</style>
<?php if (!$tipos): ?>
  <div class="ty-empty">
    <div class="t">Todavía no hay ningún tipo</div>
    <div class="s">Un tipo agrupa a los clientes que ven lo mismo en su portal.<br>Por ejemplo «SEO completo», «Solo web» o «Mantenimiento».</div>
    <?php if (can_edit()): ?><a class="btn" href="type-edit.php"><?= ic('plus',15) ?> Crear el primer tipo</a><?php endif; ?>
  </div>
<?php else: ?>
  <div class="ty-list">
    <?php foreach ($tipos as $t): $sec = jdecode($t['secciones_json'], []); $n = $uso[(int)$t['id']] ?? 0; ?>
      <div class="ty-row">
        <div class="ty-id">
          <div class="ty-nm"><?= e($t['nombre']) ?></div>
          <div class="ty-us"><?= $n ? $n.' cliente'.($n==1?'':'s') : 'Sin clientes asignados' ?></div>
        </div>
        <div class="ty-secs">
          <span class="tag on">Inicio</span>
          <?php foreach ($SECCIONES as $k=>$lbl): $on = !empty($sec[$k]); ?>
            <span class="tag <?= $on?'on':'no' ?>"><?= e($lbl) ?></span>
          <?php endforeach; ?>
        </div>
        <div class="ty-act">
          <?php if (can_edit()): ?>
            <a class="btn ghost sm" href="type-edit.php?id=<?= (int)$t['id'] ?>">Editar</a>
            <a class="btn danger sm" href="#" onclick="return erpAsk('¿Eliminar el tipo <?= e(addslashes($t['nombre'])) ?>?<?= $n ? ' Los '.$n.' cliente'.($n==1?'':'s').' con este tipo se quedarán sin tipo.' : '' ?>',{titulo:'Eliminar tipo',post:'type-delete.php',data:{id:<?= (int)$t['id'] ?>},danger:true})">Eliminar</a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php aj_foot(); ?>
