<?php
/* CRM v1.3 — #8 Importar contactos desde CSV. */
require_once __DIR__ . '/../auth.php';
require_admin();
if(!can_edit()){ http_response_code(403); exit('Sin permisos.'); }
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/crm_lib.php';
ensure_crm_schema();

$STAGES=crm_stages();
$responsables = db()->query('SELECT id, username FROM admins')->fetchAll();
$ownerByName=[]; foreach($responsables as $r) $ownerByName[mb_strtolower($r['username'])]=(int)$r['id'];
$stageBySlug=$STAGES; $stageByName=[]; foreach($STAGES as $sl=>$sg) $stageByName[mb_strtolower($sg['nombre'])]=$sl;

/* plantilla */
if(($_GET['template']??'')==='1'){
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="plantilla-contactos.csv"');
  $out=fopen('php://output','w'); fprintf($out,chr(0xEF).chr(0xBB).chr(0xBF));
  fputcsv($out,['Nombre','Empresa','Sector','Email','Telefono','WhatsApp','Origen','Servicios','Valor','Fase','Propietario']);
  fputcsv($out,['Ejemplo SL','Ejemplo SL','Restauración','correo@ejemplo.es','600111222','600111222','Referido','Web;SEO','2500','lead_nuevo','']);
  fclose($out); exit;
}

$done=null; $errors=[];
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['csv']) && $_FILES['csv']['error']===UPLOAD_ERR_OK){
  $fh=fopen($_FILES['csv']['tmp_name'],'r');
  if($fh){
    $delim=',';
    $first=fgets($fh); if(strpos($first,';')!==false && strpos($first,',')===false) $delim=';'; rewind($fh);
    $header=fgetcsv($fh,0,$delim); if($header){ $header[0]=preg_replace('/^\xEF\xBB\xBF/','',$header[0]); }
    $map=[]; foreach($header as $i=>$h){ $k=mb_strtolower(trim($h));
      if(in_array($k,['nombre','nombre completo','contacto','name'])) $map['nombre']=$i;
      elseif(in_array($k,['empresa','company','negocio'])) $map['empresa']=$i;
      elseif(in_array($k,['sector','industria'])) $map['sector']=$i;
      elseif(in_array($k,['email','correo','e-mail','mail'])) $map['email']=$i;
      elseif(in_array($k,['telefono','teléfono','tel','phone','móvil','movil'])) $map['telefono']=$i;
      elseif(in_array($k,['whatsapp','wsp','wa'])) $map['whatsapp']=$i;
      elseif(in_array($k,['origen','origen lead','origen_lead','fuente','source'])) $map['origen_lead']=$i;
      elseif(in_array($k,['servicios','servicio','services'])) $map['servicios']=$i;
      elseif(in_array($k,['valor','importe','value','presupuesto'])) $map['valor']=$i;
      elseif(in_array($k,['fase','embudo','estado','etapa','stage'])) $map['fase']=$i;
      elseif(in_array($k,['propietario','owner','responsable','asignado'])) $map['propietario']=$i;
    }
    if(!isset($map['nombre'])){ $errors[]='El CSV debe tener al menos una columna «Nombre».'; }
    else {
      $ins=db()->prepare('INSERT INTO contacts (nombre,empresa,sector,email,telefono,whatsapp,origen_lead,servicio_json,valor,fase,propietario_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
      $n=0; $skip=0;
      while(($row=fgetcsv($fh,0,$delim))!==false){
        $get=function($k)use($row,$map){ return isset($map[$k])&&isset($row[$map[$k]])?trim((string)$row[$map[$k]]):''; };
        $nombre=$get('nombre'); if($nombre==='') { $skip++; continue; }
        $svcRaw=$get('servicios'); $svc=$svcRaw!==''?array_values(array_filter(array_map('trim',preg_split('/[;,|]/',$svcRaw)))):[];
        $valRaw=$get('valor'); $val=$valRaw!==''?(float)str_replace(['.',',','€',' '],['','.','',''],$valRaw):null;
        $faseRaw=mb_strtolower($get('fase')); $fase='lead_nuevo';
        if($faseRaw!==''){ if(isset($stageBySlug[$faseRaw])) $fase=$faseRaw; elseif(isset($stageByName[$faseRaw])) $fase=$stageByName[$faseRaw]; }
        $ownRaw=mb_strtolower($get('propietario')); $own=$ownRaw!==''&&isset($ownerByName[$ownRaw])?$ownerByName[$ownRaw]:null;
        $ins->execute([$nombre, $get('empresa')?:null, $get('sector')?:null, $get('email')?:null, $get('telefono')?:null, $get('whatsapp')?:null,
                       $get('origen_lead')?:null, $svc?json_encode($svc,JSON_UNESCAPED_UNICODE):null, $val, $fase, $own]);
        crm_activity((int)db()->lastInsertId(),null,'creado','Importado desde CSV'); $n++;
      }
      $done=['insertados'=>$n,'omitidos'=>$skip];
    }
    fclose($fh);
  }
}

erp_head('crm','CRM · Importar contactos');
?>
<style>
.im-wrap{max-width:640px}
.im-wrap h1{font-size:24px;margin-bottom:7px}
.im-sub{color:var(--muted);font-size:13.5px;margin-bottom:22px}
.im-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px 26px;margin-bottom:18px}
.im-steps{font-size:13.5px;color:var(--ink);line-height:1.7;margin:0 0 4px;padding-left:18px}
.im-drop{border:2px dashed var(--line);border-radius:14px;padding:30px;text-align:center;background:var(--soft);cursor:pointer;transition:border-color .15s}
.im-drop:hover{border-color:var(--ink-strong)}
.im-drop svg{width:34px;height:34px;stroke:var(--muted);fill:none;stroke-width:1.6;margin-bottom:8px}
.im-drop .t{font-size:14px;font-weight:600;color:var(--ink)}
.im-drop .s{font-size:12.5px;color:var(--muted);margin-top:3px}
.im-fname{font-size:13px;color:var(--accent);font-weight:600;margin-top:10px}
.im-actions{display:flex;gap:10px;margin-top:16px;align-items:center}
.im-btn{border:none;background:var(--ink-strong);color:#fff;border-radius:10px;padding:11px 18px;font-size:14px;font-weight:600;cursor:pointer}
.im-btn[disabled]{opacity:.5;cursor:default}
.im-tmpl{color:var(--accent);font-size:13px;text-decoration:none;font-weight:600}
.im-ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#12854a;border-radius:12px;padding:16px 18px;font-size:14px}
.im-err{background:#fdeaec;border:1px solid #f6d5d7;color:#c0343a;border-radius:12px;padding:14px 16px;font-size:13.5px;margin-bottom:14px}
.im-cols{font-size:12.5px;color:var(--muted);margin-top:12px;line-height:1.6}
.im-cols code{background:var(--soft);border:1px solid var(--line2);border-radius:5px;padding:1px 6px;font-size:11.5px}
/* ---- Modo oscuro (capa aditiva: solo remapea superficies y textos propios) ---- */
[data-theme=dark] .im-card{background-color:var(--card)}
[data-theme=dark] .im-btn{background-color:var(--rev);color:var(--rev-fg)}
[data-theme=dark] .im-ok{background-color:var(--ok-bg);border-color:var(--ok-line);color:var(--ok)}
[data-theme=dark] .im-err{background-color:var(--danger-bg);border-color:var(--danger-line);color:var(--danger)}
/* ============ MÓVIL (≤640px) ============ */
@media(max-width:640px){
  .im-wrap h1{font-size:21px}
  .im-card{padding:20px 16px}
  .im-drop{padding:24px 14px}
  .im-actions{flex-wrap:wrap;gap:12px}
  .im-btn{width:100%}
}
</style>

<div class="im-wrap">
  <h1>Importar contactos</h1>
  <div class="im-sub">Sube un CSV y se crean los contactos en el CRM.</div>

  <?php if($done): ?>
    <div class="im-ok">✓ Importación completada: <b><?= (int)$done['insertados'] ?></b> contacto(s) creados<?= $done['omitidos']?', '.(int)$done['omitidos'].' fila(s) omitidas (sin nombre)':'' ?>.
      <div style="margin-top:10px"><a class="im-tmpl" href="crm.php">→ Ver contactos</a> &nbsp; <a class="im-tmpl" href="crm_import.php">Importar otro archivo</a></div>
    </div>
  <?php else: ?>
    <?php foreach($errors as $er): ?><div class="im-err"><?= e($er) ?></div><?php endforeach; ?>
    <div class="im-card">
      <form method="post" enctype="multipart/form-data" id="imForm">
        <label class="im-drop" id="imDrop">
          <svg viewBox="0 0 24 24"><path d="M12 16V4m0 0L8 8m4-4l4 4M4 20h16"/></svg>
          <div class="t">Haz clic para elegir tu archivo CSV</div>
          <div class="s">o arrástralo aquí</div>
          <input type="file" name="csv" accept=".csv,text/csv" style="display:none" id="imFile" onchange="document.getElementById('imName').textContent=this.files[0]?this.files[0].name:'';document.getElementById('imGo').disabled=!this.files[0];">
          <div class="im-fname" id="imName"></div>
        </label>
        <div class="im-actions">
          <button type="submit" class="im-btn" id="imGo" disabled>Importar contactos</button>
          <a class="im-tmpl" href="crm_import.php?template=1">Descargar plantilla CSV</a>
        </div>
      </form>
      <div class="im-cols">Columnas reconocidas (por su cabecera, en cualquier orden): <code>Nombre</code> (obligatoria), <code>Empresa</code>, <code>Sector</code>, <code>Email</code>, <code>Telefono</code>, <code>WhatsApp</code>, <code>Origen</code>, <code>Servicios</code> (separados por ; ), <code>Valor</code>, <code>Fase</code>, <code>Propietario</code>.</div>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){var d=document.getElementById('imDrop');if(!d)return;var f=document.getElementById('imFile');
  d.addEventListener('dragover',function(e){e.preventDefault();d.style.borderColor='#111318';});
  d.addEventListener('dragleave',function(){d.style.borderColor='';});
  d.addEventListener('drop',function(e){e.preventDefault();d.style.borderColor='';if(e.dataTransfer.files[0]){f.files=e.dataTransfer.files;document.getElementById('imName').textContent=f.files[0].name;document.getElementById('imGo').disabled=false;}});
})();
</script>

<?php erp_foot(); ?>
