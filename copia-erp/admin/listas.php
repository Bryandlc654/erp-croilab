<?php
/* CRM v1.3 — Fase 5: Listas (generador). Activas (dinámicas) y estáticas (congeladas). */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/crm_lib.php';
ensure_crm_schema();

$STAGES   = crm_stages();
$ORIGENES = crm_origenes();
$SERVICIOS= crm_servicios();
$SECTORS  = crm_sectors();
$responsables = db()->query('SELECT id, username FROM admins ORDER BY username')->fetchAll();
$respMap=[]; foreach($responsables as $r) $respMap[(int)$r['id']]=$r['username'];
/* Todos los contactos, para el selector manual de listas (#5). */
$allContacts = db()->query('SELECT id,nombre,empresa,email FROM contacts ORDER BY nombre')->fetchAll();

/* Construye WHERE + params desde condiciones JSON (mismo motor que CRM) */
function lst_build($cond){
  $w=[]; $p=[];
  if(!empty($cond['q'])){ $w[]='(nombre LIKE ? OR empresa LIKE ? OR email LIKE ?)'; $l='%'.$cond['q'].'%'; array_push($p,$l,$l,$l); }
  if(!empty($cond['sector'])){ $w[]='sector=?'; $p[]=$cond['sector']; }
  if(!empty($cond['origen'])){ $w[]='origen_lead=?'; $p[]=$cond['origen']; }
  if(!empty($cond['fase'])){ $w[]='fase=?'; $p[]=$cond['fase']; }
  if(!empty($cond['servicio'])){ $w[]='servicio_json LIKE ?'; $p[]='%"'.$cond['servicio'].'"%'; }
  if(($cond['prop']??'')!==''){ $w[]='propietario_id=?'; $p[]=(int)$cond['prop']; }
  if(($cond['vmin']??'')!==''){ $w[]='valor>=?'; $p[]=(float)$cond['vmin']; }
  if(($cond['vmax']??'')!==''){ $w[]='valor<=?'; $p[]=(float)$cond['vmax']; }
  if(($cond['quick']??'')==='sin_contactar'){ $w[]='fecha_ultimo_contacto IS NULL'; }
  elseif(($cond['quick']??'')==='act30'){ $w[]='(fecha_ultimo_contacto IS NULL OR fecha_ultimo_contacto <= (CURDATE() - INTERVAL 30 DAY))'; }
  return [$w?(' WHERE '.implode(' AND ',$w)):'', $p];
}
function lst_members($list){
  if($list['tipo']==='estatica'){
    $st=db()->prepare('SELECT c.* FROM list_members lm JOIN contacts c ON c.id=lm.contact_id WHERE lm.list_id=? ORDER BY c.nombre'); $st->execute([$list['id']]); return $st->fetchAll();
  }
  /* Lista activa: los que cumplen las condiciones MÁS los añadidos a mano (que se
     guardan en list_members), sin duplicados. Así se puede meter un contacto en una
     lista dinámica aunque no cumpla el filtro, y sigue dentro al recargar. */
  $cond=json_decode((string)$list['condiciones'],true)?:[];
  [$where,$p]=lst_build($cond);
  $sql='SELECT * FROM contacts'.$where
      .' UNION SELECT * FROM contacts WHERE id IN (SELECT contact_id FROM list_members WHERE list_id=?)'
      .' ORDER BY nombre';
  $p2=$p; $p2[]=(int)$list['id'];
  $st=db()->prepare($sql); $st->execute($p2); return $st->fetchAll();
}

/* ---------------- POST ---------------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && can_edit()) {
  $a=$_POST['action']??'';
  if ($a==='new_list') {
    $nombre=trim($_POST['nombre']??''); $tipoIn=$_POST['tipo']??'activa';
    $manual = ($tipoIn==='manual');                          // #5 — selección manual de contactos
    $tipo = ($tipoIn==='estatica' || $manual) ? 'estatica' : 'activa';
    $cond=['q'=>trim($_POST['q']??''),'sector'=>$_POST['sector']??'','origen'=>$_POST['origen']??'','fase'=>$_POST['fase']??'',
           'servicio'=>$_POST['servicio']??'','prop'=>$_POST['prop']??'','vmin'=>$_POST['vmin']??'','vmax'=>$_POST['vmax']??'','quick'=>$_POST['quick']??''];
    if($nombre!==''){
      db()->prepare('INSERT INTO lists (nombre,descripcion,tipo,condiciones) VALUES (?,?,?,?)')
        ->execute([$nombre, trim($_POST['descripcion']??'')?:null, $tipo, json_encode($manual?[]:$cond,JSON_UNESCAPED_UNICODE)]);
      $lid=(int)db()->lastInsertId();
      if($manual){
        $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['ids']??[])))));
        $ins=db()->prepare('INSERT IGNORE INTO list_members (list_id,contact_id) VALUES (?,?)');
        foreach($ids as $cid) $ins->execute([$lid,$cid]);
        db()->prepare('UPDATE lists SET fecha_congelado=NOW() WHERE id=?')->execute([$lid]);
      } elseif($tipo==='estatica'){
        [$where,$p]=lst_build($cond); $rows=db()->prepare('SELECT id FROM contacts'.$where); $rows->execute($p);
        $ins=db()->prepare('INSERT IGNORE INTO list_members (list_id,contact_id) VALUES (?,?)');
        foreach($rows->fetchAll(PDO::FETCH_COLUMN) as $cid) $ins->execute([$lid,(int)$cid]);
        db()->prepare('UPDATE lists SET fecha_congelado=NOW() WHERE id=?')->execute([$lid]);
      }
    }
    header('Location: listas.php?id='.($lid??'')); exit;
  }
  if ($a==='freeze') {
    $lid=(int)($_POST['id']??0); $st=db()->prepare('SELECT * FROM lists WHERE id=?'); $st->execute([$lid]); $l=$st->fetch();
    if($l && $l['tipo']==='activa'){
      $cond=json_decode((string)$l['condiciones'],true)?:[]; [$where,$p]=lst_build($cond);
      $rows=db()->prepare('SELECT id FROM contacts'.$where); $rows->execute($p);
      $ins=db()->prepare('INSERT IGNORE INTO list_members (list_id,contact_id) VALUES (?,?)');
      foreach($rows->fetchAll(PDO::FETCH_COLUMN) as $cid) $ins->execute([$lid,(int)$cid]);
      db()->prepare("UPDATE lists SET tipo='estatica', fecha_congelado=NOW() WHERE id=?")->execute([$lid]);
    }
    header('Location: listas.php?id='.$lid); exit;
  }
  if ($a==='del_list') { $lid=(int)($_POST['id']??0); db()->prepare('DELETE FROM lists WHERE id=?')->execute([$lid]); db()->prepare('DELETE FROM list_members WHERE list_id=?')->execute([$lid]); header('Location: listas.php'); exit; }
  if ($a==='rename_list') { $lid=(int)($_POST['id']??0); $nom=trim($_POST['nombre']??''); if($lid && $nom!=='') db()->prepare('UPDATE lists SET nombre=? WHERE id=?')->execute([$nom,$lid]); header('Location: listas.php?id='.$lid); exit; }
  /* Añadir un contacto a una lista SALTÁNDOSE sus condiciones. Se persiste en
     list_members: en las estáticas ya es su fuente de miembros; en las activas se
     une a los que cumplen el filtro (ver lst_members), así que queda dentro fijo. */
  if ($a==='add_member') {
    $lid=(int)($_POST['id']??0); $cid=(int)($_POST['contact_id']??0);
    if($lid && $cid) db()->prepare('INSERT IGNORE INTO list_members (list_id,contact_id) VALUES (?,?)')->execute([$lid,$cid]);
    header('Location: listas.php?id='.$lid); exit;
  }
  /* Quitar de la lista un contacto añadido a mano (o cualquiera de una estática). */
  if ($a==='del_member') {
    $lid=(int)($_POST['id']??0); $cid=(int)($_POST['contact_id']??0);
    if($lid && $cid) db()->prepare('DELETE FROM list_members WHERE list_id=? AND contact_id=?')->execute([$lid,$cid]);
    header('Location: listas.php?id='.$lid); exit;
  }
}

/* ---------------- Export CSV ---------------- */
if (($_GET['export']??'')!=='') {
  $lid=(int)$_GET['export']; $st=db()->prepare('SELECT * FROM lists WHERE id=?'); $st->execute([$lid]); $l=$st->fetch();
  if($l){
    $rows=lst_members($l);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="lista-'.preg_replace('/[^a-z0-9]+/i','-',$l['nombre']).'.csv"');
    $out=fopen('php://output','w'); fprintf($out,chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['Nombre','Empresa','Sector','Email','Teléfono','WhatsApp','Origen','Valor','Embudo','Propietario']);
    foreach($rows as $c){ fputcsv($out,[$c['nombre'],$c['empresa'],$c['sector'],$c['email'],$c['telefono'],$c['whatsapp'],$c['origen_lead'],$c['valor'],$STAGES[$c['fase']]['nombre']??$c['fase'],$respMap[(int)$c['propietario_id']]??'']); }
    fclose($out); exit;
  }
}

$lists = db()->query('SELECT * FROM lists ORDER BY fecha_creacion DESC, id DESC')->fetchAll();
$cur = ($_GET['id']??'')!=='' ? (int)$_GET['id'] : ($lists?(int)$lists[0]['id']:0);
$curList=null; foreach($lists as $l){ if((int)$l['id']===$cur){ $curList=$l; break; } }
$members = $curList ? lst_members($curList) : [];
/* Ids añadidos a mano (list_members) del listado actual: son los que llevan botón
   de «quitar». En una estática son todos; en una activa, solo los forzados. */
$forcedSet=[];
if($curList){ try{ $st=db()->prepare('SELECT contact_id FROM list_members WHERE list_id=?'); $st->execute([$cur]); foreach($st->fetchAll(PDO::FETCH_COLUMN) as $fid) $forcedSet[(int)$fid]=1; }catch(Exception $e){} }

erp_head('crm', 'CRM · Listas');
?>
<style>
.ls-top{display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.ls-top h1{font-size:24px;flex:none}
.ls-new{margin-left:auto;border:none;background:var(--ink-strong);color:#fff;border-radius:10px;padding:10px 16px;font-size:13.5px;font-weight:600;cursor:pointer;display:inline-flex;gap:8px;align-items:center}
.ls-new svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2.2;stroke-linecap:round}
.ls-wrap{display:block}
.ls-side{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
.ls-item{display:block;padding:14px 16px;border-bottom:1px solid var(--line2);cursor:pointer;text-decoration:none}
.ls-item:last-child{border-bottom:none}
.ls-item.on{background:var(--soft)}
.ls-item .nm{font-size:13.5px;font-weight:650;color:var(--ink-strong);display:flex;align-items:center;gap:8px}
.ls-item .mt{font-size:11.5px;color:var(--muted);margin-top:2px}
.ls-badge{font-size:10px;font-weight:700;border-radius:6px;padding:2px 7px;color:#fff}
.ls-badge.act{background:#5b8def}.ls-badge.est{background:#12854a}
.ls-empty{padding:30px;text-align:center;color:var(--muted);font-size:13px}
.ls-main{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
.ls-mh{display:flex;align-items:center;gap:12px;padding:18px 20px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.ls-mh h2{font-size:18px;flex:none}.ls-mh .cnt{color:var(--muted);font-size:12.5px;font-weight:600}
.ls-acts{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}
.ls-acts a,.ls-acts button{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:#fff;border-radius:9px;padding:8px 12px;font-size:12.5px;font-weight:600;color:var(--ink);cursor:pointer;text-decoration:none}
.ls-acts a:hover,.ls-acts button:hover{background:var(--soft)}
.ls-acts .del{color:#e5484d;border-color:#f6d5d7}
.ls-acts svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2}
table.ls{width:100%;border-collapse:collapse;font-size:13px}
table.ls th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:650;padding:12px 14px;border-bottom:1px solid var(--line);background:#fcfcfd}
table.ls td{padding:11px 14px;border-bottom:1px solid var(--line2)}
table.ls tr:last-child td{border-bottom:none}
.ls-av{width:26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:10px;margin-right:8px}
.ls-nm{display:flex;align-items:center}
.ls-cond{font-size:11.5px;color:var(--muted);padding:10px 18px;background:var(--soft);border-bottom:1px solid var(--line)}
.ls-mask{position:fixed;inset:0;background:rgba(16,19,24,.4);display:none;align-items:flex-start;justify-content:center;z-index:500;overflow:auto;padding:4vh 0}
.ls-mask.on{display:flex}
.ls-modal{background:#fff;border-radius:18px;width:520px;max-width:94vw;padding:26px 28px;box-shadow:0 30px 70px rgba(0,0,0,.3)}
.ls-modal h3{font-size:18px;font-weight:650;margin:0 0 5px}.ls-modal p.d{font-size:12.5px;color:var(--muted);margin:0 0 16px}
.ls-modal label{display:block;font-size:12px;color:var(--muted);font-weight:600;margin:10px 0 5px}
.ls-modal input,.ls-modal select{width:100%;border:1px solid var(--line);border-radius:10px;padding:9px 11px;font-size:13.5px;font-family:inherit;color:var(--ink)}
.ls-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.ls-seg{display:flex;gap:8px;margin-top:6px}
.ls-seg label{flex:1;border:1px solid var(--line);border-radius:10px;padding:10px;text-align:center;cursor:pointer;margin:0;font-weight:600;color:var(--ink);font-size:13px}
.ls-seg input{display:none}
.ls-seg input:checked+span{color:var(--accent)}
.ls-seg label:has(input:checked){border-color:var(--ink-strong);background:var(--soft)}
.ls-fil{border:1px solid var(--line2);border-radius:12px;padding:12px;margin-top:8px}
.ls-fil .t{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:700;margin-bottom:8px}
.ls-pick{border:1px solid var(--line2);border-radius:12px;padding:12px;margin-top:8px}
.ls-pick .t{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:700;margin-bottom:8px}
.ls-pksearch{width:100%}
.ls-pkbar{display:flex;align-items:center;gap:8px;margin:9px 0 6px;font-size:12px;color:var(--muted)}
.ls-pkbar span{margin-right:auto;font-weight:600;color:var(--ink)}
.ls-pkbar button{border:1px solid var(--line);background:#fff;border-radius:8px;padding:5px 10px;font-size:12px;font-weight:600;color:var(--ink);cursor:pointer}
.ls-pkbar button:hover{background:var(--soft)}
.ls-pklist{max-height:260px;overflow:auto;border:1px solid var(--line2);border-radius:10px}
.ls-pkrow{display:flex;align-items:center;gap:10px;padding:8px 11px;border-bottom:1px solid var(--line2);cursor:pointer;margin:0}
.ls-pkrow:last-child{border-bottom:none}
.ls-pkrow:hover{background:var(--soft)}
.ls-pkrow input{width:16px;height:16px;flex:none;accent-color:var(--ink-strong);padding:0;border:none;border-radius:0}
.ls-pksearch{margin:0}
.ls-pkrow .nm{font-size:13px;color:var(--ink);font-weight:500;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ls-pkrow .nm small{color:var(--muted);font-weight:400;margin-left:5px}
.ls-pkempty{padding:20px;text-align:center;color:var(--muted);font-size:13px}
.ls-modal .acts{display:flex;justify-content:flex-end;gap:9px;margin-top:20px}
.ls-modal .acts button{border-radius:10px;padding:10px 16px;font-size:13.5px;font-weight:600;cursor:pointer;border:1px solid var(--line);background:#fff;color:var(--ink)}
.ls-modal .acts button.pri{background:var(--ink-strong);color:#fff;border-color:var(--ink-strong)}
.lm-x{border:none;background:none;color:var(--label);cursor:pointer;font-size:13px;padding:2px 7px;border-radius:7px}
.lm-x:hover{background:#fdeaec;color:#e5484d}
/* ---- Modo oscuro (capa aditiva: solo remapea superficies y textos propios) ---- */
[data-theme=dark] .ls-side,[data-theme=dark] .ls-main,
[data-theme=dark] .ls-acts a,[data-theme=dark] .ls-acts button,
[data-theme=dark] .ls-modal,[data-theme=dark] .ls-modal .acts button,
[data-theme=dark] .ls-pkbar button,[data-theme=dark] table.ls th{background-color:var(--card)}
[data-theme=dark] .ls-new,[data-theme=dark] .ls-modal .acts button.pri{background-color:var(--rev);color:var(--rev-fg);border-color:var(--rev)}
[data-theme=dark] .ls-acts .del{color:var(--danger);border-color:var(--danger-line)}
[data-theme=dark] .lm-x:hover{background-color:var(--danger-bg);color:var(--danger)}
/* ============ MÓVIL (≤640px) ============ */
@media(max-width:640px){
  .ls-top h1{font-size:21px}
  .ls-mh{padding:16px}
  .ls-mh h2{font-size:16px}
  .ls-acts{margin-left:0;width:100%;flex-wrap:wrap}
  .ls-acts a,.ls-acts button{flex:1 1 auto;justify-content:center}
  /* Miembros: filas planas y compactas (nombre + empresa debajo), sin cajas. */
  table.ls thead{display:none}
  table.ls,table.ls tbody,table.ls tr{display:block}
  table.ls tr{border:none;border-bottom:1px solid var(--line2);margin:0;padding:9px 2px;position:relative}
  table.ls td{display:none;padding:0;border:none}
  table.ls td:nth-child(1){display:block}
  table.ls td:nth-child(1) .ls-nm b{font-size:14px}
  table.ls td:nth-child(2){display:block;padding:2px 40px 0 34px;font-size:12px;color:var(--muted)}
  table.ls td:has(.lm-x){display:block;position:absolute;top:7px;right:2px;padding:0}
  /* Modales a 1 columna y casi a pantalla completa. */
  .ls-modal{width:100%!important;max-width:100%;border-radius:16px;padding:22px 18px}
  .ls-row{grid-template-columns:1fr}
  .ls-seg{flex-direction:column;gap:6px}
}
</style>

<div class="ls-top">
  <h1>Listas</h1>
  <?php if(can_edit()): ?><button class="ls-new" onclick="lsNew()"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Nueva lista</button><?php endif; ?>
</div>

<?php if(!$lists): ?>
  <div class="ls-main"><div class="ls-empty">Aún no hay listas. Crea la primera con «Nueva lista».<br><span style="font-size:12px">Una lista <b>activa</b> se actualiza sola según sus condiciones; una <b>estática</b> congela los contactos actuales.</span></div></div>
<?php else: ?>
<div class="ls-wrap">
  <?php if($curList): $cond=json_decode((string)$curList['condiciones'],true)?:[]; $bits=[];
    if(!empty($cond['sector']))$bits[]='Sector: '.$cond['sector']; if(!empty($cond['origen']))$bits[]='Origen: '.$cond['origen'];
    if(!empty($cond['fase']))$bits[]='Embudo: '.($STAGES[$cond['fase']]['nombre']??$cond['fase']); if(!empty($cond['servicio']))$bits[]='Servicio: '.$cond['servicio'];
    if(($cond['prop']??'')!=='')$bits[]='Propietario: '.($respMap[(int)$cond['prop']]??''); if(($cond['vmin']??'')!=='')$bits[]='≥ '.$cond['vmin'].'€'; if(($cond['vmax']??'')!=='')$bits[]='≤ '.$cond['vmax'].'€';
    if(!empty($cond['q']))$bits[]='«'.$cond['q'].'»'; if(!empty($cond['quick']))$bits[]=$cond['quick']; ?>
  <div class="ls-main">
    <div class="ls-mh">
      <h2><?= e($curList['nombre']) ?></h2><span class="cnt"><?= count($members) ?> contactos</span>
      <div class="ls-acts">
        <?php if(can_edit()): ?><button type="button" onclick="lmOpen()"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Añadir contacto</button><?php endif; ?>
        <a href="listas.php?export=<?= (int)$curList['id'] ?>"><svg viewBox="0 0 24 24"><path d="M12 3v12m0 0l-4-4m4 4l4-4M4 21h16"/></svg> Exportar CSV</a>
        <?php if(can_edit() && $curList['tipo']==='activa'): ?>
        <form method="post" style="display:inline" onsubmit="return erpSubmitAsk(this,'¿Congelar esta lista? Se guardarán los contactos actuales y dejará de actualizarse.')"><input type="hidden" name="action" value="freeze"><input type="hidden" name="id" value="<?= (int)$curList['id'] ?>"><button type="submit"><svg viewBox="0 0 24 24"><path d="M12 2v20M2 12h20M5 5l14 14M19 5L5 19"/></svg> Convertir a estática</button></form>
        <?php endif; ?>
        <?php if(can_edit()): ?><form method="post" style="display:inline" onsubmit="return erpSubmitAsk(this,'¿Eliminar la lista?')"><input type="hidden" name="action" value="del_list"><input type="hidden" name="id" value="<?= (int)$curList['id'] ?>"><button type="submit" class="del" aria-label="Eliminar la lista"><svg viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2m-9 0v14a2 2 0 002 2h6a2 2 0 002-2V6"/></svg></button></form><?php endif; ?>
      </div>
    </div>
    <?php if($bits): ?><div class="ls-cond"><b>Condiciones:</b> <?= e(implode('  ·  ',$bits)) ?></div><?php endif; ?>
    <?php $nc = can_edit()?7:6; ?>
    <table class="ls">
      <thead><tr><th>Nombre</th><th>Empresa</th><th>Sector</th><th>Email</th><th>Teléfono</th><th>Embudo</th><?php if(can_edit()): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
        <?php if(!$members): ?><tr><td colspan="<?= $nc ?>" style="text-align:center;color:var(--muted);padding:24px">Ningún contacto coincide.</td></tr><?php endif; ?>
        <?php foreach($members as $c): $stg=$STAGES[$c['fase']]??['nombre'=>$c['fase'],'color'=>'#98a2b3']; ?>
        <tr>
          <td><div class="ls-nm"><span class="ls-av" style="background:<?= avatar_color($c['nombre']) ?>"><?= e(mb_strtoupper(mb_substr($c['nombre'],0,2))) ?></span><b><?= e($c['nombre']) ?></b></div></td>
          <td><?= e($c['empresa']?:'—') ?></td><td><?= e($c['sector']?:'—') ?></td>
          <td><?= e($c['email']?:'—') ?></td><td><?= e($c['telefono']?:'—') ?></td>
          <td><span class="ls-badge" style="background:<?= e($stg['color']) ?>"><?= e($stg['nombre']) ?></span></td>
          <?php if(can_edit()): ?><td style="text-align:right"><?php if(isset($forcedSet[(int)$c['id']])): ?><form method="post" style="display:inline" onsubmit="return erpSubmitAsk(this,'¿Quitar este contacto de la lista?',{ok:'Quitar'})"><input type="hidden" name="action" value="del_member"><input type="hidden" name="id" value="<?= (int)$curList['id'] ?>"><input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>"><button type="submit" class="lm-x" title="Quitar de la lista">✕</button></form><?php endif; ?></td><?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if(can_edit()): ?>
<div class="ls-mask" id="lsMask">
  <form class="ls-modal" method="post" onsubmit="return lsSubmit(this)">
    <input type="hidden" name="action" value="new_list">
    <h3>Nueva lista</h3><p class="d">Filtra por condiciones o elige los contactos a mano. Una lista activa se recalcula sola; una estática o manual congela los contactos.</p>
    <label>Nombre *</label><input name="nombre" required placeholder="Ej: Restaurantes sin contactar">
    <label>Descripción</label><input name="descripcion" placeholder="Opcional">
    <label>Tipo</label>
    <div class="ls-seg">
      <label><input type="radio" name="tipo" value="activa" checked><span>Activa (dinámica)</span></label>
      <label><input type="radio" name="tipo" value="estatica"><span>Estática (congelada)</span></label>
      <label><input type="radio" name="tipo" value="manual"><span>Selección manual</span></label>
    </div>
    <div class="ls-fil">
      <div class="t">Condiciones</div>
      <div class="ls-row">
        <div><label style="margin-top:0">Sector</label><select name="sector"><option value="">Cualquiera</option><?php foreach($SECTORS as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?></select></div>
        <div><label style="margin-top:0">Origen</label><select name="origen"><option value="">Cualquiera</option><?php foreach($ORIGENES as $o): ?><option value="<?= e($o) ?>"><?= e($o) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="ls-row">
        <div><label>Embudo</label><select name="fase"><option value="">Cualquiera</option><?php foreach($STAGES as $sl=>$sg): ?><option value="<?= e($sl) ?>"><?= e($sg['nombre']) ?></option><?php endforeach; ?></select></div>
        <div><label>Servicio</label><select name="servicio"><option value="">Cualquiera</option><?php foreach($SERVICIOS as $sv): ?><option value="<?= e($sv) ?>"><?= e($sv) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="ls-row">
        <div><label>Propietario</label><select name="prop"><option value="">Cualquiera</option><?php foreach($responsables as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['username']) ?></option><?php endforeach; ?></select></div>
        <div><label>Estado contacto</label><select name="quick"><option value="">Cualquiera</option><option value="sin_contactar">Sin contactar</option><option value="act30">Sin actividad +30 días</option></select></div>
      </div>
      <div class="ls-row">
        <div><label>Valor mínimo (€)</label><input name="vmin" placeholder="€ mín"></div>
        <div><label>Valor máximo (€)</label><input name="vmax" placeholder="€ máx"></div>
      </div>
      <label>Búsqueda de texto</label><input name="q" placeholder="Nombre, empresa o email…">
    </div>
    <div class="ls-pick" style="display:none">
      <div class="t">Elige los contactos</div>
      <input type="text" class="ls-pksearch" placeholder="Buscar contacto…" oninput="lsPickFilter(this.value)" autocomplete="off">
      <div class="ls-pkbar"><span id="lsPkCount">0 seleccionados</span><button type="button" onclick="lsPickAll(true)">Todos</button><button type="button" onclick="lsPickAll(false)">Ninguno</button></div>
      <div class="ls-pklist" id="lsPkList">
        <?php foreach($allContacts as $c): ?>
        <label class="ls-pkrow" data-s="<?= e(mb_strtolower($c['nombre'].' '.($c['empresa']??'').' '.($c['email']??''))) ?>">
          <input type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>" onchange="lsPickCount()">
          <span class="nm"><?= e($c['nombre']) ?><?php if(!empty($c['empresa'])): ?> <small><?= e($c['empresa']) ?></small><?php endif; ?></span>
        </label>
        <?php endforeach; ?>
        <?php if(!$allContacts): ?><div class="ls-pkempty">No hay contactos en el CRM todavía.</div><?php endif; ?>
      </div>
    </div>
    <div class="acts"><button type="button" onclick="lsClose()">Cancelar</button><button type="submit" class="pri">Crear lista</button></div>
  </form>
</div>
<script>
function lsNew(){document.getElementById('lsMask').classList.add('on');}
function lsClose(){document.getElementById('lsMask').classList.remove('on');}
document.getElementById('lsMask').addEventListener('click',function(e){if(e.target===this)lsClose();});
function lsMode(){var t=document.querySelector('input[name=tipo]:checked');var m=t&&t.value==='manual';
  var fil=document.querySelector('.ls-fil'),pick=document.querySelector('.ls-pick');
  if(fil)fil.style.display=m?'none':'';if(pick)pick.style.display=m?'':'none';}
[].forEach.call(document.querySelectorAll('input[name=tipo]'),function(r){r.addEventListener('change',lsMode);});
function lsPickFilter(q){q=(q||'').toLowerCase();[].forEach.call(document.querySelectorAll('#lsPkList .ls-pkrow'),function(r){r.style.display=r.getAttribute('data-s').indexOf(q)>=0?'':'none';});}
function lsPickAll(on){[].forEach.call(document.querySelectorAll('#lsPkList .ls-pkrow'),function(r){if(r.style.display!=='none')r.querySelector('input').checked=on;});lsPickCount();}
function lsPickCount(){var n=document.querySelectorAll('#lsPkList input:checked').length;var el=document.getElementById('lsPkCount');if(el)el.textContent=n+(n===1?' seleccionado':' seleccionados');}
function lsSubmit(f){var t=f.querySelector('input[name=tipo]:checked');if(t&&t.value==='manual'&&!f.querySelectorAll('#lsPkList input:checked').length){if(window.toast)toast('Elige al menos un contacto para la lista','err');return false;}return true;}
<?php if(($_GET['new']??'')==='1'): ?>lsNew();<?php endif; ?>
</script>

<?php if($curList): ?>
<!-- modal: añadir un contacto a mano (se salta las condiciones) -->
<div class="ls-mask" id="lmMask">
  <div class="ls-modal">
    <h3>Añadir contacto a la lista</h3><p class="d">Entra en «<?= e($curList['nombre']) ?>» aunque no cumpla las condiciones. Quedará fijo hasta que lo quites.</p>
    <input type="text" class="ls-pksearch" placeholder="Buscar contacto…" oninput="lmFilter(this.value)" autocomplete="off">
    <div class="ls-pklist" id="lmList" style="margin-top:9px">
      <?php foreach($allContacts as $c): ?>
      <label class="ls-pkrow" data-s="<?= e(mb_strtolower($c['nombre'].' '.($c['empresa']??'').' '.($c['email']??''))) ?>" onclick="lmAdd(<?= (int)$c['id'] ?>)">
        <span class="nm"><?= e($c['nombre']) ?><?php if(!empty($c['empresa'])): ?> <small><?= e($c['empresa']) ?></small><?php endif; ?></span>
      </label>
      <?php endforeach; ?>
      <?php if(!$allContacts): ?><div class="ls-pkempty">No hay contactos en el CRM todavía.</div><?php endif; ?>
    </div>
    <div class="acts"><button type="button" onclick="lmClose()">Cerrar</button></div>
  </div>
</div>
<form id="lmForm" method="post" style="display:none"><input type="hidden" name="action" value="add_member"><input type="hidden" name="id" value="<?= (int)$curList['id'] ?>"><input type="hidden" name="contact_id" id="lmCid"></form>
<script>
function lmOpen(){document.getElementById('lmMask').classList.add('on');}
function lmClose(){document.getElementById('lmMask').classList.remove('on');}
document.getElementById('lmMask').addEventListener('click',function(e){if(e.target===this)lmClose();});
function lmFilter(q){q=(q||'').toLowerCase();[].forEach.call(document.querySelectorAll('#lmList .ls-pkrow'),function(r){r.style.display=r.getAttribute('data-s').indexOf(q)>=0?'':'none';});}
function lmAdd(id){document.getElementById('lmCid').value=id;document.getElementById('lmForm').submit();}
</script>
<?php endif; ?>
<?php endif; ?>

<?php erp_foot(); ?>
