<?php
/* ============================================================================
   proyectos_lib.php — Modelo de vinculación PROYECTO ↔ documentos financieros.

   Regla del modelo (sin duplicar lógica): la CAJA (tabla `accounting`) es la
   ÚNICA fuente de verdad del balance de un proyecto. Todo lo que computa como
   ingreso o coste de un proyecto es una fila de `accounting` con `project_id`.

   Los documentos se enganchan a esa caja:
     · Factura de venta emitida  →  invoices.project_id  (al cobrarse, su apunte
       de caja hereda el proyecto vía fin_sync_accounting()).
     · Gasto/ingreso con factura →  invoice_uploads.acc_id  →  accounting (el
       documento subido en Facturas crea su apunte; se asocia por project_id).
     · Gasto/ingreso sin factura →  apunte de `accounting` a secas.

   Así un proyecto no cuenta nada dos veces: los movimientos salen de la caja;
   las facturas sin cobrar se muestran aparte como "pendiente".
   ============================================================================ */

if (!function_exists('proj_like_escape')) {
  function proj_like_escape($s){ return str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], (string)$s); }
}

/* Ficha del proyecto (o null). */
function proj_get($id){
  $id=(int)$id; if(!$id) return null;
  try{ $q=db()->prepare('SELECT * FROM projects WHERE id=?'); $q->execute([$id]); return $q->fetch() ?: null; }
  catch(Exception $e){ return null; }
}

/* Balance del proyecto a partir de la caja. $year=0 → histórico completo. */
function proj_balance($id, $year=0){
  $id=(int)$id; $out=['ing'=>0.0,'gas'=>0.0,'ben'=>0.0,'nmov'=>0];
  if(!$id) return $out;
  try{
    $cond = $year ? ' AND YEAR(fecha)='.(int)$year : '';
    $q=db()->prepare("SELECT
        COALESCE(SUM(CASE WHEN tipo='ingreso' THEN importe END),0) ing,
        COALESCE(SUM(CASE WHEN tipo='gasto'   THEN importe END),0) gas,
        COUNT(*) nmov
      FROM accounting WHERE project_id=?$cond");
    $q->execute([$id]); $r=$q->fetch();
    $out['ing']=(float)$r['ing']; $out['gas']=(float)$r['gas'];
    $out['ben']=$out['ing']-$out['gas']; $out['nmov']=(int)$r['nmov'];
  }catch(Exception $e){}
  return $out;
}

/* Movimientos de caja vinculados al proyecto (fuente de verdad), enriquecidos:
   - factura_numero / factura_estado  si el apunte viene de una factura de venta
   - doc_file / doc_orig               si el apunte tiene un documento subido
   $year=0 → histórico. */
function proj_movimientos($id, $year=0){
  $id=(int)$id; if(!$id) return [];
  try{
    $cond = $year ? ' AND YEAR(a.fecha)='.(int)$year : '';
    $q=db()->prepare("SELECT a.*, i.numero factura_numero, i.estado factura_estado,
        u.id upload_id, u.filename doc_file, u.orig_name doc_orig, u.proveedor doc_prov
      FROM accounting a
      LEFT JOIN invoices i ON i.id=a.invoice_id
      LEFT JOIN invoice_uploads u ON u.acc_id=a.id
      WHERE a.project_id=?$cond
      ORDER BY a.fecha DESC, a.id DESC");
    $q->execute([$id]); return $q->fetchAll();
  }catch(Exception $e){ return []; }
}

/* Total de una factura (base + IVA − IRPF), calculado desde sus líneas. */
function proj_invoice_total($inv){
  try{
    $b=db()->prepare('SELECT COALESCE(SUM(cantidad*precio),0) FROM invoice_items WHERE invoice_id=?');
    $b->execute([(int)$inv['id']]); $sub=(float)$b->fetchColumn();
    return $sub*(1+((float)($inv['iva_pct']??0))/100-((float)($inv['irpf_pct']??0))/100);
  }catch(Exception $e){ return 0.0; }
}

/* Facturas de venta vinculadas al proyecto (invoices.project_id), con total y
   si ya están reflejadas en la caja (estado=pagada). */
function proj_facturas($id){
  $id=(int)$id; if(!$id) return [];
  try{
    $q=db()->prepare('SELECT * FROM invoices WHERE project_id=? ORDER BY fecha DESC, id DESC');
    $q->execute([$id]); $rows=$q->fetchAll();
    foreach($rows as &$r){ $r['total']=proj_invoice_total($r); $r['en_caja']=(($r['estado']??'')==='pagada'); }
    return $rows;
  }catch(Exception $e){ return []; }
}

/* Suma de facturas vinculadas aún NO cobradas (ingreso pendiente). */
function proj_pendiente($id){
  $t=0.0; foreach(proj_facturas($id) as $f){ if(!$f['en_caja']) $t+=(float)$f['total']; } return $t;
}

/* ---------- Selector escalable (búsqueda en servidor) -------------------- */

/* Búsqueda de proyectos por texto. Ordena: primero los del cliente indicado,
   luego activos, luego coincidencia exacta/por prefijo, luego alfabético.
   Nunca devuelve la lista entera: siempre acotada por $limit. */
function proj_search($q='', $clientId=null, $limit=12){
  $q=trim((string)$q); $limit=max(1,min(40,(int)$limit));
  $cli=($clientId!==null && $clientId!=='')?(int)$clientId:0;
  try{
    $where=''; $args=[];
    if($q!==''){ $where=' WHERE p.nombre LIKE ?'; $args[]='%'.proj_like_escape($q).'%'; }
    $sql="SELECT p.id,p.nombre,p.color,p.activo,p.client_id,
        (p.client_id IS NOT NULL AND p.client_id=?) is_client,
        (SELECT COUNT(*) FROM accounting a WHERE a.project_id=p.id) nmov
      FROM projects p$where
      ORDER BY is_client DESC, p.activo DESC, "
      .($q!=='' ? "(p.nombre=?) DESC, (p.nombre LIKE ?) DESC, " : "")
      ."p.nombre ASC LIMIT $limit";
    $bind=array_merge([$cli], $args);
    if($q!==''){ $bind[]=$q; $bind[]=proj_like_escape($q).'%'; }
    $st=db()->prepare($sql); $st->execute($bind); return $st->fetchAll();
  }catch(Exception $e){ return []; }
}

/* Sugerencias contextuales cuando el buscador está vacío: del cliente + activos,
   por actividad reciente. Acotado. */
function proj_suggest($clientId=null, $limit=8){
  $limit=max(1,min(20,(int)$limit));
  $cli=($clientId!==null && $clientId!=='')?(int)$clientId:0;
  try{
    $st=db()->prepare("SELECT p.id,p.nombre,p.color,p.activo,p.client_id,
        (p.client_id IS NOT NULL AND p.client_id=?) is_client,
        (SELECT COUNT(*) FROM accounting a WHERE a.project_id=p.id) nmov,
        (SELECT MAX(a.fecha) FROM accounting a WHERE a.project_id=p.id) last_mov
      FROM projects p
      ORDER BY is_client DESC, p.activo DESC, (last_mov IS NULL) ASC, last_mov DESC, p.created_at DESC
      LIMIT $limit");
    $st->execute([$cli]); return $st->fetchAll();
  }catch(Exception $e){ return []; }
}

/* Encuentra por nombre exacto o crea. Devuelve id (o null si nombre vacío). */
function proj_get_or_create($nombre, $clientId=null){
  $nombre=trim((string)$nombre); if($nombre==='') return null;
  try{
    $q=db()->prepare('SELECT id FROM projects WHERE nombre=? LIMIT 1'); $q->execute([$nombre]);
    $pid=$q->fetchColumn();
    if($pid) return (int)$pid;
    $cli=($clientId!==null && $clientId!=='')?(int)$clientId:null;
    db()->prepare('INSERT INTO projects (nombre,client_id) VALUES (?,?)')->execute([$nombre,$cli]);
    return (int)db()->lastInsertId();
  }catch(Exception $e){ return null; }
}

/* ---------- Vincular / desvincular -------------------------------------- */

function proj_link_invoice($projId, $invoiceId){
  $projId=(int)$projId; $invoiceId=(int)$invoiceId; if(!$invoiceId) return;
  try{
    db()->prepare('UPDATE invoices SET project_id=? WHERE id=?')->execute([$projId?:null,$invoiceId]);
    db()->prepare('UPDATE accounting SET project_id=? WHERE invoice_id=?')->execute([$projId?:null,$invoiceId]);
  }catch(Exception $e){}
}
function proj_unlink_invoice($invoiceId){ proj_link_invoice(0,$invoiceId); }

function proj_link_acc($projId, $accId){
  $projId=(int)$projId; $accId=(int)$accId; if(!$accId) return;
  try{ db()->prepare('UPDATE accounting SET project_id=? WHERE id=?')->execute([$projId?:null,$accId]); }catch(Exception $e){}
}
function proj_unlink_acc($accId){ proj_link_acc(0,$accId); }

/* Añade un apunte de caja NUEVO ya vinculado al proyecto. Devuelve acc id. */
function proj_add_mov($projId, $tipo, $concepto, $importe, $fecha=null, $ambito='empresa', $metodo='transferencia'){
  $projId=(int)$projId; if(!$projId) return 0;
  $tipo=($tipo==='ingreso')?'ingreso':'gasto';
  $concepto=trim((string)$concepto); if($concepto==='') $concepto=($tipo==='ingreso'?'Ingreso':'Gasto');
  $importe=(float)$importe;
  $fecha=($fecha!==null && $fecha!=='')?$fecha:date('Y-m-d');
  $ambito=(function_exists('fin_emisor_ok') && $ambito!=='empresa')?fin_emisor_ok($ambito):($ambito?:'empresa');
  $cat=($tipo==='ingreso')?'Cliente':'Gasto';
  try{
    db()->prepare('INSERT INTO accounting (fecha,tipo,concepto,categoria,importe,metodo,legal,ambito,deducible,personal,project_id,notas) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$fecha,$tipo,$concepto,$cat,$importe,($metodo?:'transferencia'),1,$ambito,0,0,$projId,'Añadido desde el proyecto']);
    return (int)db()->lastInsertId();
  }catch(Exception $e){ return 0; }
}

/* ---------- Combobox de proyecto reutilizable (búsqueda en servidor) -----
   Un único componente para todo el ERP. Multi-instancia: cada `.pcb[data-pcb]`
   se auto-inicializa. Envía dos hidden: `project_name` (nombre, respaldo por
   nombre) y `project_id_sel` (id preferido en el guardado). El client_id lo toma
   del `<select name=client_id>` del MISMO formulario (si lo hay). Consume el
   endpoint `facturas.php?proj_search=1&client=&q=`. */
function proj_combobox($curName='', $curId='', $placeholder='Buscar o crear proyecto…'){
  $ph=htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8');
  $n =htmlspecialchars((string)$curName, ENT_QUOTES, 'UTF-8');
  $i =htmlspecialchars((string)$curId, ENT_QUOTES, 'UTF-8');
  echo '<div class="pcb" data-pcb>'
     .'<input type="hidden" name="project_name" class="pcb-name" value="'.$n.'">'
     .'<input type="hidden" name="project_id_sel" class="pcb-id" value="'.$i.'">'
     .'<div class="pcb-field"><input type="text" class="pcb-input" placeholder="'.$ph.'" autocomplete="off">'
     .'<button type="button" class="pcb-x" title="Quitar proyecto">✕</button></div>'
     .'<div class="pcb-drop"></div></div>';
}

/* CSS + JS del combobox. Se emite UNA sola vez por página. */
function proj_combobox_assets(){
  static $done=false; if($done) return; $done=true;
  ?>
  <style>
  .pcb{position:relative}
  .pcb-field{display:flex;align-items:center;gap:6px;border:1px solid var(--line);border-radius:10px;padding:1px 5px 1px 3px;background:#fff;transition:border-color .12s,box-shadow .12s}
  .pcb-field:focus-within{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
  .pcb-input{flex:1;min-width:0;border:none;outline:none;background:transparent;padding:9px 9px;font-size:13px;font-family:inherit;color:var(--ink-strong)}
  .pcb-x{border:none;background:none;color:#c2c6ce;cursor:pointer;font-size:13px;line-height:1;border-radius:6px;padding:5px 8px;display:none}
  .pcb-field.has .pcb-x{display:inline-flex}
  .pcb-x:hover{background:var(--soft);color:var(--ink)}
  .pcb-drop{position:fixed;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 14px 40px rgba(0,0,0,.14);padding:6px;z-index:2500;max-height:290px;overflow:auto;display:none}
  .pcb-drop.on{display:block}
  .pcb-grp{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:800;padding:8px 10px 4px}
  .pcb-item{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:8px;cursor:pointer;font-size:13px;color:var(--ink-strong)}
  .pcb-item:hover{background:var(--accent-soft)}
  .pcb-dot{width:9px;height:9px;border-radius:50%;flex:none}
  .pcb-nm{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .pcb-mov{font-size:11px;color:var(--muted);font-weight:600;flex:none}
  .pcb-arch{font-size:9.5px;font-weight:800;letter-spacing:.3px;text-transform:uppercase;color:#9aa0a8;background:var(--soft);padding:2px 8px;border-radius:99px;flex:none}
  .pcb-create{color:var(--accent);font-weight:600}
  .pcb-plus{width:9px;text-align:center;flex:none;font-weight:700}
  .pcb-none{padding:14px;text-align:center;color:var(--muted);font-size:12.5px}
  </style>
  <script>
  (function(){
    if(window.__pcbReady)return; window.__pcbReady=1;
    function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
    function initOne(root){
      if(root.__pcb)return; root.__pcb=1;
      var input=root.querySelector('.pcb-input'), drop=root.querySelector('.pcb-drop'),
          field=root.querySelector('.pcb-field'), nameH=root.querySelector('.pcb-name'),
          idH=root.querySelector('.pcb-id'), clearB=root.querySelector('.pcb-x');
      if(!input||!drop||!field||!nameH||!idH)return;
      /* El desplegable se mueve al <body>: si algún ancestro tiene transform (p.ej.
         una animación), position:fixed se posicionaría respecto a él y saldría mal. */
      document.body.appendChild(drop);
      var items=[], t=null;
      /* El desplegable va en position:fixed y se coloca bajo el campo por JS, así
         ningún contenedor con overflow lo recorta (bug del desplegable cortado). */
      function place(){ var r=field.getBoundingClientRect();
        drop.style.position='fixed'; drop.style.left=r.left+'px'; drop.style.width=r.width+'px'; drop.style.right='auto';
        var dh=drop.offsetHeight||0;
        if(r.bottom+6+dh>window.innerHeight-8 && r.top-6-dh>8){ drop.style.top=(r.top-6-dh)+'px'; }
        else { drop.style.top=(r.bottom+6)+'px'; }
      }
      var winH=function(){ if(drop.classList.contains('on'))place(); };
      function hide(){drop.classList.remove('on'); window.removeEventListener('scroll',winH,true); window.removeEventListener('resize',winH); }
      function clientId(){ var f=root.closest('form'); var cs=f?f.querySelector('select[name=client_id]'):null; return cs?cs.value:''; }
      function itemHtml(it){ var meta=(it.activo==0)?'<span class="pcb-arch">archivado</span>':'<span class="pcb-mov">'+(it.nmov||0)+' mov.</span>';
        return '<div class="pcb-item" data-idx="'+it._idx+'"><span class="pcb-dot" style="background:'+esc(it.color||'#2f6df6')+'"></span><span class="pcb-nm">'+esc(it.nombre)+'</span>'+meta+'</div>'; }
      function render(list,q){ items=list||[]; var mine=[],rest=[];
        items.forEach(function(it,i){it._idx=i;(it.is_client==1?mine:rest).push(it);});
        var html='';
        function grp(l,a){if(!a.length)return'';var h='<div class="pcb-grp">'+esc(l)+'</div>';a.forEach(function(it){h+=itemHtml(it);});return h;}
        html+=grp('Del cliente',mine); html+=grp(mine.length?'Otros':'Activos',rest);
        var qq=(q||'').trim();
        if(qq!==''){ var ex=items.some(function(it){return (it.nombre||'').toLowerCase()===qq.toLowerCase();});
          if(!ex) html+='<div class="pcb-item pcb-create" data-idx="-1"><span class="pcb-plus">＋</span><span class="pcb-nm">Crear «'+esc(qq)+'»</span></div>'; }
        if(html==='')html='<div class="pcb-none">Sin proyectos</div>';
        drop.innerHTML=html; drop.classList.add('on'); place();
        window.addEventListener('scroll',winH,true); window.addEventListener('resize',winH);
      }
      function fetchP(q){ var url='facturas.php?proj_search=1&client='+encodeURIComponent(clientId())+'&q='+encodeURIComponent(q||'');
        fetch(url,{headers:{'X-Requested-With':'fetch'}}).then(function(r){return r.json();}).then(function(j){ if(j&&j.ok)render(j.items||[],q); }).catch(function(){}); }
      function choose(name,id){ nameH.value=name; idH.value=(id===''||id==null?'':id); input.value=name; field.classList.add('has'); hide(); }
      if(nameH.value){ input.value=nameH.value; field.classList.add('has'); }
      input.addEventListener('focus',function(){fetchP(input.value.trim());});
      input.addEventListener('input',function(){clearTimeout(t);var v=input.value;t=setTimeout(function(){fetchP(v.trim());},180);});
      input.addEventListener('blur',function(){ setTimeout(function(){ hide(); var v=input.value.trim();
        if(v===''){nameH.value='';idH.value='';field.classList.remove('has');}
        else if(v!==nameH.value){nameH.value=v;idH.value='';} },150); });
      drop.addEventListener('mousedown',function(ev){ var el=ev.target.closest('.pcb-item'); if(!el)return; ev.preventDefault();
        var idx=parseInt(el.getAttribute('data-idx'),10);
        if(idx===-1)choose(input.value.trim(),''); else{var it=items[idx];if(it)choose(it.nombre,it.id);} });
      if(clearB)clearB.addEventListener('click',function(){nameH.value='';idH.value='';input.value='';field.classList.remove('has');input.focus();});
      var f=root.closest('form'); var cs=f?f.querySelector('select[name=client_id]'):null;
      if(cs)cs.addEventListener('change',function(){ if(drop.classList.contains('on'))fetchP(input.value.trim()); });
    }
    function scan(){ document.querySelectorAll('.pcb[data-pcb]').forEach(initOne); }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',scan); else scan();
    window.pcbScan=scan;
  })();
  </script>
  <?php
}

/* Facturas emitidas candidatas a vincular (sin proyecto o de este cliente),
   para el diálogo "Vincular factura" de la ficha. Acotado. */
function proj_invoices_vinculables($clientId=null, $q='', $limit=20){
  $limit=max(1,min(40,(int)$limit)); $q=trim((string)$q);
  try{
    $where='WHERE (project_id IS NULL'; $args=[];
    if($clientId){ $where.=' OR client_id=?'; $args[]=(int)$clientId; }
    $where.=')';
    if($q!==''){ $where.=' AND (numero LIKE ? OR cliente_nombre LIKE ?)'; $like='%'.proj_like_escape($q).'%'; $args[]=$like; $args[]=$like; }
    $st=db()->prepare("SELECT id,numero,cliente_nombre,fecha,estado,iva_pct,irpf_pct,project_id FROM invoices $where ORDER BY fecha DESC, id DESC LIMIT $limit");
    $st->execute($args); $rows=$st->fetchAll();
    foreach($rows as &$r){ $r['total']=proj_invoice_total($r); }
    return $rows;
  }catch(Exception $e){ return []; }
}
