<?php
/* Generador de facturas. Lista + crear/editar + vista imprimible (PDF vía navegador). */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
/* Borrar en el ERP no es definitivo: pasa por la papelera y se puede deshacer. */
require_once __DIR__ . '/lib/papelera.php';
require_once __DIR__ . '/lib/fin_prog.php';
require_once __DIR__ . '/lib/proyectos_lib.php';
require_once __DIR__ . '/lib/imagen.php';   // reduce las fotos de tickets subidas (ahorro de espacio)

db()->exec("CREATE TABLE IF NOT EXISTS invoices (
  id INT AUTO_INCREMENT PRIMARY KEY, numero VARCHAR(30), client_id INT DEFAULT NULL,
  cliente_nombre VARCHAR(200), cliente_nif VARCHAR(40) DEFAULT '', cliente_dir VARCHAR(300) DEFAULT '', cliente_email VARCHAR(160) DEFAULT '',
  fecha DATE, fecha_venc DATE DEFAULT NULL, estado VARCHAR(15) DEFAULT 'borrador',
  iva_pct DECIMAL(5,2) DEFAULT 21, irpf_pct DECIMAL(5,2) DEFAULT 0, notas TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->exec("CREATE TABLE IF NOT EXISTS invoice_items (
  id INT AUTO_INCREMENT PRIMARY KEY, invoice_id INT NOT NULL, concepto VARCHAR(300), cantidad DECIMAL(10,2) DEFAULT 1, precio DECIMAL(12,2) DEFAULT 0, INDEX(invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->exec("CREATE TABLE IF NOT EXISTS accounting (
  id INT AUTO_INCREMENT PRIMARY KEY, fecha DATE, tipo VARCHAR(10) DEFAULT 'gasto', concepto VARCHAR(250) DEFAULT '', categoria VARCHAR(80) DEFAULT '', importe DECIMAL(12,2) DEFAULT 0,
  metodo VARCHAR(20) DEFAULT 'transferencia', legal TINYINT DEFAULT 1, ambito VARCHAR(15) DEFAULT 'empresa', deducible TINYINT DEFAULT 0, client_id INT DEFAULT NULL, notas VARCHAR(300) DEFAULT '', invoice_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try { $h=db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='accounting' AND column_name='invoice_id'")->fetchColumn(); if(!$h) db()->exec("ALTER TABLE accounting ADD COLUMN invoice_id INT DEFAULT NULL"); } catch(Exception $e){}
/* columnas nuevas de facturas: emisor (autónomo), efectivo (B) y personal (no contabiliza) */
foreach (['emisor'=>"VARCHAR(15) NOT NULL DEFAULT 'victor'",'efectivo'=>"TINYINT NOT NULL DEFAULT 0",'personal'=>"TINYINT NOT NULL DEFAULT 0",'periodo_ini'=>"DATE DEFAULT NULL",'periodo_fin'=>"DATE DEFAULT NULL",'cliente_tel'=>"VARCHAR(40) NOT NULL DEFAULT ''",'cond_pago'=>"VARCHAR(60) NOT NULL DEFAULT 'Contado'",'emisor_json'=>"TEXT"] as $col=>$def) {
  try { if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='$col'")->fetchColumn()) db()->exec("ALTER TABLE invoices ADD COLUMN $col $def"); } catch(Exception $e){}
}
/* archivos de factura subidos (ingresos y gastos escaneados/recibidos) */
db()->exec("CREATE TABLE IF NOT EXISTS invoice_uploads (
  id INT AUTO_INCREMENT PRIMARY KEY, emisor VARCHAR(15) NOT NULL DEFAULT 'victor', tipo VARCHAR(10) NOT NULL DEFAULT 'gasto',
  concepto VARCHAR(250) DEFAULT '', proveedor VARCHAR(200) DEFAULT '', importe DECIMAL(12,2) DEFAULT 0, fecha DATE,
  filename VARCHAR(255) DEFAULT NULL, orig_name VARCHAR(255) DEFAULT NULL, mime VARCHAR(120) DEFAULT '', acc_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(emisor), INDEX(tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach(['efectivo'=>"TINYINT NOT NULL DEFAULT 0",'personal'=>"TINYINT NOT NULL DEFAULT 0"] as $col=>$def){ try{ if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoice_uploads' AND column_name='$col'")->fetchColumn()) db()->exec("ALTER TABLE invoice_uploads ADD COLUMN $col $def"); }catch(Exception $e){} }
try{ if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='accounting' AND column_name='personal'")->fetchColumn()) db()->exec("ALTER TABLE accounting ADD COLUMN personal TINYINT NOT NULL DEFAULT 0"); }catch(Exception $e){}
db()->exec("CREATE TABLE IF NOT EXISTS projects (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(160) NOT NULL, color VARCHAR(16) DEFAULT '#2f6df6', client_id INT DEFAULT NULL, activo TINYINT DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach(['invoices'=>'project_id','accounting'=>'project_id'] as $tb=>$col){ try{ if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$tb' AND column_name='$col'")->fetchColumn()) db()->exec("ALTER TABLE $tb ADD COLUMN $col INT DEFAULT NULL"); }catch(Exception $e){} }

/* Buscador de proyectos del combobox del editor (GET → JSON, sin CSRF). Nunca
   devuelve la lista entera: proj_search/proj_suggest van acotados en servidor. */
if(isset($_GET['proj_search'])){
  header('Content-Type: application/json; charset=utf-8');
  $q=trim($_GET['q']??''); $cli=($_GET['client']??'')!==''?(int)$_GET['client']:null;
  $rows = $q!=='' ? proj_search($q,$cli,12) : proj_suggest($cli,8);
  echo json_encode(['ok'=>1,'items'=>array_map(function($r){return ['id'=>(int)$r['id'],'nombre'=>$r['nombre'],'color'=>$r['color'],'activo'=>(int)$r['activo'],'is_client'=>(int)$r['is_client'],'nmov'=>(int)$r['nmov']];},$rows)], JSON_UNESCAPED_UNICODE);
  exit;
}

function sset($k){ static $c=null; if($c===null){ $c=[]; try{ foreach(db()->query('SELECT clave,valor FROM settings') as $r) $c[$r['clave']]=$r['valor']; }catch(Exception $e){} } return $c[$k] ?? ''; }
$ESTADOS = ['borrador'=>['Borrador','#9aa0a8'],'enviada'=>['Enviada','#3b82f6'],'pagada'=>['Pagada','#12a150'],'vencida'=>['Vencida','#ef4444']];
/* Quién factura ya no está escrito aquí: se configura en Ajustes › Facturación y
   lo sirve lib/fin_prog.php, que es quien también numera y contabiliza. */
$EMISORES = fin_emisores();
/* emisor_data() era una copia literal de fin_emisor_data() con su propio lector
   de settings. Se queda como nombre corto para no tocar las quince llamadas de
   esta pantalla, pero ya solo delega: una sola definición de qué es un emisor. */
function emisor_data($em){ return fin_emisor_data($em); }
$clients = db()->query('SELECT id, name, fact_nombre, fact_nif, fact_dir, fact_email, fact_tel FROM clients ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD']==='POST' && can_edit()) {
  $a = $_POST['action'] ?? '';
  if ($a==='save') {
    $id=(int)($_POST['id']??0);
    $emisor=fin_emisor_ok($_POST['emisor']??'');
    $efectivo=isset($_POST['efectivo'])?1:0;
    $personal=isset($_POST['personal'])?1:0;
    $numero=trim($_POST['numero']??'');
    $serie=trim($_POST['serie']??'');
    if($serie!=='') db()->prepare('INSERT INTO settings (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')->execute(['serie_'.$emisor,$serie]);
    /* Numeración con contador atómico (lib/fin_prog.php): dos personas guardando a
       la vez ya no pueden recibir el mismo número de factura. */
    if ($numero==='') $numero = fin_next_numero($serie, $emisor, $_POST['fecha'] ?? null);
    /* Efectivo (cobro en B): sin IVA ni IRPF automáticamente */
    $ivaPct=$efectivo?0:(float)($_POST['iva_pct']??21);
    $irpfPct=$efectivo?0:(float)($_POST['irpf_pct']??0);
    /* Proyecto: se prefiere el id elegido en el combobox (project_id_sel). Si no hay
       id pero sí un nombre tecleado, se crea/reutiliza por nombre (respaldo). */
    $projId=null; $psel=($_POST['project_id_sel']??'');
    if($psel!==''){ $projId=(int)$psel; }
    else { $pn=trim($_POST['project_name']??''); if($pn!==''){ $projId=proj_get_or_create($pn, ($_POST['client_id']??'')!==''?(int)$_POST['client_id']:null); } }
    $f = [
      'numero'=>$numero,
      'emisor'=>$emisor,
      'efectivo'=>$efectivo,
      'personal'=>$personal,
      'client_id'=>($_POST['client_id']??'')!==''?(int)$_POST['client_id']:null,
      'cliente_nombre'=>trim($_POST['cliente_nombre']??''),
      'cliente_nif'=>trim($_POST['cliente_nif']??''),
      'cliente_dir'=>trim($_POST['cliente_dir']??''),
      'cliente_email'=>trim($_POST['cliente_email']??''),
      'cliente_tel'=>trim($_POST['cliente_tel']??''),
      'fecha'=>($_POST['fecha']??'')!==''?$_POST['fecha']:date('Y-m-d'),
      'fecha_venc'=>($_POST['fecha_venc']??'')!==''?$_POST['fecha_venc']:null,
      'periodo_ini'=>($_POST['periodo_ini']??'')!==''?$_POST['periodo_ini']:null,
      'periodo_fin'=>($_POST['periodo_fin']??'')!==''?$_POST['periodo_fin']:null,
      'cond_pago'=>trim($_POST['cond_pago']??'Contado'),
      'estado'=>array_key_exists($_POST['estado']??'',$ESTADOS)?$_POST['estado']:'borrador',
      'iva_pct'=>$ivaPct,
      'irpf_pct'=>$irpfPct,
      'notas'=>trim($_POST['notas']??''),
      'project_id'=>$projId,
    ];
    /* snapshot de los datos del autónomo en el momento de emitir (NO retroactivo) */
    if (!$id) { $f['emisor_json']=json_encode(emisor_data($emisor), JSON_UNESCAPED_UNICODE); }
    if ($f['cliente_nombre']==='' && $f['client_id']) { foreach($clients as $c){ if((int)$c['id']===$f['client_id']) $f['cliente_nombre']=$c['name']; } }
    if ($id) { $set=implode(', ',array_map(fn($k)=>"$k=:$k",array_keys($f))); $p=$f;$p['id']=$id; db()->prepare("UPDATE invoices SET $set WHERE id=:id")->execute($p); }
    else { $cols=implode(',',array_keys($f)); $ph=implode(',',array_map(fn($k)=>":$k",array_keys($f))); db()->prepare("INSERT INTO invoices ($cols) VALUES ($ph)")->execute($f); $id=(int)db()->lastInsertId(); }
    /* guardar los datos de facturación en la ficha del cliente (solo los que vengan rellenos) para reutilizarlos */
    if ($f['client_id']) { $upd=[]; $vals=[]; foreach(['fact_nombre'=>$f['cliente_nombre'],'fact_nif'=>$f['cliente_nif'],'fact_dir'=>$f['cliente_dir'],'fact_email'=>$f['cliente_email'],'fact_tel'=>$f['cliente_tel']] as $col=>$val){ if(trim((string)$val)!==''){ $upd[]="$col=?"; $vals[]=$val; } } if($upd){ $vals[]=$f['client_id']; db()->prepare('UPDATE clients SET '.implode(',',$upd).' WHERE id=?')->execute($vals); } }
    db()->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$id]);
    $con=$_POST['it_concepto']??[]; $can=$_POST['it_cant']??[]; $pre=$_POST['it_precio']??[];
    $ins=db()->prepare('INSERT INTO invoice_items (invoice_id,concepto,cantidad,precio) VALUES (?,?,?,?)');
    /* Cantidad y precio pasan por num_es(): escribir «1.234,56» en el precio de
       una línea daba antes 1,00 € porque (float) corta en la primera coma. */
    foreach($con as $i=>$cc){ $cc=trim($cc); if($cc!==''){ $qc=(float)num_es($can[$i]??1,false); $pc=(float)num_es($pre[$i]??0,false); $ins->execute([$id,$cc,$qc,$pc]); } }
    /* La contabilidad es la caja: el ingreso solo se apunta si está pagada. */
    if ($f['estado']==='pagada') db()->prepare('UPDATE invoices SET fecha_pago=COALESCE(fecha_pago,?) WHERE id=?')->execute([date('Y-m-d'),$id]);
    else                        db()->prepare('UPDATE invoices SET fecha_pago=NULL WHERE id=?')->execute([$id]);
    fin_sync_accounting($id);
    header('Location: facturas.php?v='.$id); exit;
  } elseif ($a==='set_project') {
    $iid=(int)($_POST['id']??0); $projId=null; $psel=($_POST['project_id_sel']??'');
    if($psel!==''){ $projId=(int)$psel; }
    elseif(($pn=trim($_POST['project_name']??''))!==''){ $projId=proj_get_or_create($pn,null); }
    if($iid){ db()->prepare('UPDATE invoices SET project_id=? WHERE id=?')->execute([$projId,$iid]); db()->prepare('UPDATE accounting SET project_id=? WHERE invoice_id=?')->execute([$projId,$iid]); }
    header('Location: '.($_POST['ret']??'facturas.php')); exit;
  } elseif ($a==='set_estado') {
    $id=(int)($_POST['id']??0); $e=$_POST['estado']??'';
    if (array_key_exists($e,$ESTADOS)) {
      /* Estado anterior: para avisar SOLO cuando pasa a cobrada de verdad, no si
         ya lo estaba (o si se guarda el mismo estado). */
      $antesEst=''; $qe=db()->prepare('SELECT estado FROM invoices WHERE id=?'); $qe->execute([$id]); $antesEst=(string)$qe->fetchColumn();
      /* Al marcarla pagada se guarda el día del cobro (es la fecha que cuenta
         en la caja) y se apunta el ingreso; al desmarcarla, se retira. */
      if ($e==='pagada') db()->prepare('UPDATE invoices SET estado=?, fecha_pago=COALESCE(fecha_pago,?) WHERE id=?')->execute([$e,date('Y-m-d'),$id]);
      else               db()->prepare('UPDATE invoices SET estado=?, fecha_pago=NULL WHERE id=?')->execute([$e,$id]);
      fin_sync_accounting($id);
      if ($e==='pagada' && $antesEst!=='pagada' && function_exists('notif_invoice_paid')) notif_invoice_paid($id,(string)(current_admin()['username']??''));
    }
    header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit;
  } elseif ($a==='del') {
    $id=(int)($_POST['id']??0);
    $fn=db()->prepare('SELECT numero,cliente_nombre FROM invoices WHERE id=?'); $fn->execute([$id]); $fRow=$fn->fetch();
    /* Una factura sin sus líneas no sirve de nada: se guardan juntas, y también
       su apunte contable, para que deshacer deje la contabilidad como estaba. */
    $fNum=trim((string)($fRow['numero'] ?? ''));
    pap_borrar_flash('invoices', $id, 'factura',
        trim($fNum.' · '.(string)($fRow['cliente_nombre'] ?? ''), ' ·'),
        [['tabla'=>'invoice_items','fk'=>'invoice_id'],['tabla'=>'accounting','fk'=>'invoice_id']],
        $fNum!=='' ? 'Factura '.$fNum.' eliminada' : 'Factura eliminada');
    db()->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$id]); db()->prepare('DELETE FROM accounting WHERE invoice_id=?')->execute([$id]); db()->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
    header('Location: '.($_POST['ret']??'facturas.php')); exit;
  } elseif ($a==='dup') {
    $id=(int)($_POST['id']??0);
    $o=db()->prepare('SELECT * FROM invoices WHERE id=?'); $o->execute([$id]); $src=$o->fetch();
    if($src){ unset($src['id']); $src['created_at']=date('Y-m-d H:i:s'); $src['estado']='borrador'; $src['fecha_pago']=null;
      /* La copia estrena número por el mismo contador atómico que las demás, pero
         CONSERVANDO la serie del original (antes se pasaba '' y la copia caía en la
         serie por defecto, desviándose de la del original — P2-06). La serie va
         embebida como prefijo del número: se recupera quitando los dígitos finales. */
      $emisorD=fin_emisor_ok($src['emisor']??'');
      $serieCopia=preg_replace('/\d+$/','',(string)$src['numero']);
      $src['numero']=fin_next_numero($serieCopia, $emisorD, null);
      $cols=array_keys($src); db()->prepare('INSERT INTO invoices ('.implode(',',$cols).') VALUES ('.implode(',',array_map(fn($k)=>":$k",$cols)).')')->execute($src); $nid=(int)db()->lastInsertId();
      foreach(db()->query('SELECT concepto,cantidad,precio FROM invoice_items WHERE invoice_id='.$id) as $it){ db()->prepare('INSERT INTO invoice_items (invoice_id,concepto,cantidad,precio) VALUES (?,?,?,?)')->execute([$nid,$it['concepto'],$it['cantidad'],$it['precio']]); }
      header('Location: facturas.php?edit='.$nid); exit; }
    header('Location: facturas.php'); exit;
  } elseif ($a==='save_cli_fiscal') {
    $cid=(int)($_POST['cli']??0);
    if($cid) db()->prepare('UPDATE clients SET fact_nombre=?,fact_nif=?,fact_dir=?,fact_email=?,fact_tel=? WHERE id=?')->execute([trim($_POST['fact_nombre']??''),trim($_POST['fact_nif']??''),trim($_POST['fact_dir']??''),trim($_POST['fact_email']??''),trim($_POST['fact_tel']??''),$cid]);
    header('Location: facturas.php?cli='.$cid); exit;
  } elseif ($a==='upload_doc') {
    $emU=fin_emisor_ok($_POST['emisor']??'');
    $tipoU=($_POST['tipo']??'gasto')==='ingreso'?'ingreso':'gasto';
    $concepto=trim($_POST['concepto']??''); $prov=trim($_POST['proveedor']??'');
    $imp=(float)num_es($_POST['importe']??0, false);   // «1.234,56» vale mil doscientos treinta y cuatro con cincuenta y seis
    $fch=($_POST['fecha']??'')!==''?$_POST['fecha']:date('Y-m-d');
    $fn=null;$orig=null;$mime=null;
    if(!empty($_FILES['file']) && ($_FILES['file']['error']??1)===0 && ($_FILES['file']['tmp_name']??'')!==''){
      $dir=__DIR__.'/../uploads/facturas'; if(!is_dir($dir))@mkdir($dir,0775,true);
      $ext=strtolower(preg_replace('/[^a-z0-9]/','',pathinfo($_FILES['file']['name'],PATHINFO_EXTENSION)));
      if(in_array($ext,['pdf','jpg','jpeg','png','webp'],true)){
        $fn=bin2hex(random_bytes(8)).'.'.$ext;
        /* Documento fiscal: si es foto de un ticket se comprime, pero con 3000 px para que siga legible. */
        if(@move_uploaded_file($_FILES['file']['tmp_name'],$dir.'/'.$fn)){ img_optimizar($dir.'/'.$fn, 3000); $orig=mb_substr($_FILES['file']['name'],0,240); $mime=$_FILES['file']['type']??''; } else { $fn=null; }
      }
    }
    $efU=isset($_POST['efectivo'])?1:0; $perU=isset($_POST['personal'])?1:0;
    $concAcc=($concepto!==''?$concepto:($tipoU==='gasto'?'Gasto':'Ingreso')).($prov!==''?(' · '.$prov):'');
    /* Proyecto del gasto/ingreso: se prefiere el id del combobox; si no, se crea por nombre. */
    $projId=null; $psel=($_POST['project_id_sel']??'');
    if($psel!==''){ $projId=(int)$psel; }
    elseif(($pnU=trim($_POST['project_name']??''))!==''){ $projId=proj_get_or_create($pnU,null); }
    $editDoc=(int)($_POST['edit_id']??0);
    if($editDoc){
      $rx=db()->prepare('SELECT * FROM invoice_uploads WHERE id=?'); $rx->execute([$editDoc]); $ex=$rx->fetch();
      if($ex){
        if($fn){ if($ex['filename']){ $op=__DIR__.'/../uploads/facturas/'.$ex['filename']; if(is_file($op))@unlink($op); } } else { $fn=$ex['filename']; $orig=$ex['orig_name']; $mime=$ex['mime']; }
        $dedFlag=($tipoU==='gasto')?$perU:0; $newAcc=$ex['acc_id'];
        if($ex['acc_id']){ db()->prepare('UPDATE accounting SET fecha=?,tipo=?,concepto=?,categoria=?,importe=?,metodo=?,legal=?,ambito=?,deducible=?,personal=?,project_id=? WHERE id=?')
          ->execute([$fch,$tipoU,$concAcc,$tipoU==='gasto'?'Gasto':'Cliente',$imp,$efU?'efectivo':'transferencia',$efU?0:1,$emU,$dedFlag,$perU,$projId,$ex['acc_id']]); }
        else { db()->prepare('INSERT INTO accounting (fecha,tipo,concepto,categoria,importe,metodo,legal,ambito,deducible,personal,project_id,notas) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$fch,$tipoU,$concAcc,$tipoU==='gasto'?'Gasto':'Cliente',$imp,$efU?'efectivo':'transferencia',$efU?0:1,$emU,$dedFlag,$perU,$projId,'Subida en Facturas']); $newAcc=(int)db()->lastInsertId(); }
        db()->prepare('UPDATE invoice_uploads SET concepto=?,proveedor=?,importe=?,fecha=?,filename=?,orig_name=?,mime=?,efectivo=?,personal=?,acc_id=? WHERE id=?')
          ->execute([$concepto,$prov,$imp,$fch,$fn,$orig,$mime,$efU,$perU,$newAcc,$editDoc]);
        header('Location: facturas.php?em='.$emU.'&tipo='.$tipoU); exit;
      }
    }
    $dedFlag=($tipoU==='gasto')?$perU:0;
    db()->prepare('INSERT INTO accounting (fecha,tipo,concepto,categoria,importe,metodo,legal,ambito,deducible,personal,project_id,notas) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$fch,$tipoU,$concAcc,$tipoU==='gasto'?'Gasto':'Cliente',$imp,$efU?'efectivo':'transferencia',$efU?0:1,$emU,$dedFlag,$perU,$projId,'Subida en Facturas']);
    $accId=(int)db()->lastInsertId();
    db()->prepare('INSERT INTO invoice_uploads (emisor,tipo,concepto,proveedor,importe,fecha,filename,orig_name,mime,acc_id,efectivo,personal) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$emU,$tipoU,$concepto,$prov,$imp,$fch,$fn,$orig,$mime,$accId,$efU,$perU]);
    header('Location: facturas.php?em='.$emU.'&tipo='.$tipoU); exit;
  } elseif ($a==='del_doc') {
    $didv=(int)($_POST['id']??0);
    $r=db()->prepare('SELECT * FROM invoice_uploads WHERE id=?'); $r->execute([$didv]); $d=$r->fetch();
    if($d){ if($d['filename']){ $p=__DIR__.'/../uploads/facturas/'.$d['filename']; if(is_file($p))@unlink($p); } if($d['acc_id']) db()->prepare('DELETE FROM accounting WHERE id=?')->execute([$d['acc_id']]); db()->prepare('DELETE FROM invoice_uploads WHERE id=?')->execute([$didv]); }
    header('Location: facturas.php?em='.urlencode(fin_emisor_ok($d['emisor']??'')).'&tipo='.($d['tipo']??'gasto')); exit;
  }
}

$view = isset($_GET['v']) ? (int)$_GET['v'] : 0;
$edit = isset($_GET['edit']) ? (int)$_GET['edit'] : (isset($_GET['new']) ? -1 : 0);

/* ---------- VISTA IMPRIMIBLE ---------- */
if ($view) {
  $inv = db()->prepare('SELECT * FROM invoices WHERE id=?'); $inv->execute([$view]); $inv=$inv->fetch();
  if (!$inv) { header('Location: facturas.php'); exit; }
  $items = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id'); $items->execute([$view]); $items=$items->fetchAll();
  $sub=0; foreach($items as $it) $sub+=$it['cantidad']*$it['precio'];
  $iva=$sub*$inv['iva_pct']/100; $irpf=$sub*$inv['irpf_pct']/100; $tot=$sub+$iva-$irpf;
  $ev=$ESTADOS[$inv['estado']]??$ESTADOS['borrador'];
  $em = (!empty($inv['emisor_json']) && ($snap=json_decode($inv['emisor_json'],true)) && is_array($snap)) ? array_merge(emisor_data($inv['emisor']??''),$snap) : emisor_data($inv['emisor']??'');
  erp_head('fact', 'Factura '.$inv['numero']);
  ?>
  <style>
  .inv-sheet,.inv-sheet *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important}
  @page{margin:0}
  @media print{.no-print{display:none!important}.erp-wrap{padding:0!important;max-width:none}.rail,.side,.erp-top{display:none!important}.main{margin:0!important}body{background:#fff!important}.inv-sheet{box-shadow:none!important;border:none!important;margin:0 auto!important;border-radius:0!important}.inv-pad{padding:16mm 15mm!important}}
  .inv-bar{display:flex;gap:10px;align-items:center;margin-bottom:18px}
  .inv-bar .sp{flex:1}
  /* El "papel" de la factura es SIEMPRE blanco (también en modo oscuro), así que sus tokens de
     texto se redefinen aquí a los valores del tema claro: sin esto, en oscuro salía texto casi
     blanco sobre papel blanco (ilegible). */
  .inv-sheet{--ink:#3c4149;--ink-strong:#22262c;--muted:#656a72;--label:#656a72;--line:#eeeeef;--line2:#f6f6f7;--soft:#f7f7f8;color:var(--ink);max-width:680px;margin:0 auto;background:#fff;border:1px solid #eeeeef;border-radius:18px;box-shadow:0 16px 50px rgba(0,0,0,.08);overflow:hidden}
  .inv-pad{padding:40px 42px}
  .inv-hd{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:26px}
  .inv-fact{font-size:36px;font-weight:850;letter-spacing:-1.3px;color:var(--ink-strong);line-height:.9}
  .inv-numpill{display:inline-block;margin-top:14px;border:1.5px solid var(--ink-strong);border-radius:99px;padding:5px 15px;font-size:12.5px;font-weight:700;color:var(--ink-strong)}
  .inv-chips{display:flex;flex-direction:column;gap:9px;align-items:flex-end}
  .inv-chip{border:1.5px solid var(--line);border-radius:99px;padding:6px 15px;font-size:12px;font-weight:600;color:var(--ink)}
  .inv-chip b{color:var(--ink-strong)}
  .inv-badge{align-self:flex-end;font-size:11px;font-weight:700;padding:4px 12px;border-radius:99px}
  .inv-parts{display:grid;grid-template-columns:1fr 1fr;border:1.5px solid var(--ink-strong);border-radius:16px;overflow:hidden;margin-bottom:28px}
  .inv-block{padding:18px 22px}
  .inv-block+.inv-block{border-left:1.5px solid var(--ink-strong)}
  .inv-block .l{font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);font-weight:800;margin-bottom:8px}
  .inv-block .n{font-size:14px;font-weight:700;color:var(--ink-strong)}
  .inv-block .d{font-size:12px;color:#6b7280;line-height:1.65;margin-top:4px}
  .inv-tbl{width:100%;border-collapse:separate;border-spacing:0 0;margin-bottom:22px}
  .inv-tbl thead th{background:var(--ink-strong);color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:.4px;font-weight:700;padding:12px 16px;text-align:left}
  .inv-tbl thead th:first-child{border-radius:10px 0 0 10px}
  .inv-tbl thead th:last-child{border-radius:0 10px 10px 0}
  .inv-tbl thead th.r{text-align:right}
  .inv-tbl td{padding:13px 16px;border-bottom:1px solid var(--line2);font-size:13px;color:var(--ink)}
  .inv-tbl .r{text-align:right}
  .inv-tbl .cpt{font-weight:600;color:var(--ink-strong)}
  .inv-tot{display:flex;justify-content:flex-end;margin-top:6px}
  .inv-tot .box{width:330px}
  .inv-tot .row{display:flex;justify-content:space-between;font-size:13px;padding:8px 2px}
  .inv-tot .row.sub{color:#6b7280}
  .inv-tot .row.base{color:var(--ink-strong);font-weight:700;border-top:1.5px solid var(--line);border-bottom:1.5px solid var(--line);padding:10px 2px}
  .inv-tot .big{display:flex;justify-content:space-between;align-items:center;background:var(--ink-strong);color:#fff;border-radius:12px;padding:15px 20px;margin-top:12px}
  .inv-tot .big .t{font-weight:700;font-size:14px;letter-spacing:.3px}
  .inv-tot .big .a{font-size:23px;font-weight:850;letter-spacing:-.5px}
  .inv-pay{margin-top:26px;border:1.5px solid var(--line);border-radius:14px;padding:16px 20px}
  .inv-pay .h{font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);font-weight:800;margin-bottom:10px}
  .inv-pay .row{display:flex;justify-content:space-between;gap:14px;font-size:12.5px;padding:3px 0}
  .inv-pay .row span:first-child{color:#6b7280}
  .inv-pay .row span:last-child{color:var(--ink-strong);font-weight:600;text-align:right}
  .inv-notes{margin-top:20px;font-size:12px;color:var(--muted);line-height:1.6}
  .inv-crumbs{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:12.5px;margin-bottom:12px}
  .inv-crumbs a{color:var(--muted);font-weight:600;text-decoration:none;transition:color .12s}
  .inv-crumbs a:hover{color:var(--ink-strong)}
  .inv-crumbs .sep{color:var(--label)}
  .inv-crumbs .cur{color:var(--ink-strong);font-weight:700}
  </style>
  <?php $bcProj = !empty($inv['project_id']) ? proj_get((int)$inv['project_id']) : null; ?>
  <div class="inv-crumbs no-print">
    <a href="facturas.php?em=<?= e($em['key']) ?>&tipo=ingreso">Facturas</a>
    <span class="sep">›</span>
    <?php if($bcProj): ?><a href="proyecto.php?id=<?= (int)$inv['project_id'] ?>"><?= e($bcProj['nombre']) ?></a><span class="sep">›</span><?php endif; ?>
    <span class="cur">Factura <?= e($inv['numero']) ?></span>
  </div>
  <div class="inv-bar no-print">
    <a class="btn ghost sm" href="facturas.php?em=<?= e($em['key']) ?>&tipo=ingreso"><?= ic('back',15) ?> Facturas</a><span class="sp"></span>
    <?php if(can_edit()): ?><a class="btn ghost sm" href="facturas.php?edit=<?= $view ?>"><?= ic('pencil',14) ?> Editar</a><?php endif; ?>
    <button class="btn sm" onclick="window.print()"><?= ic('download',15) ?> Descargar / Imprimir</button>
  </div>
  <div class="inv-sheet"><div class="inv-pad">
    <div class="inv-hd">
      <div>
        <div class="inv-fact">FACTURA</div>
        <div class="inv-numpill">Nº <?= e($inv['numero']) ?></div>
      </div>
      <div class="inv-chips">
        <?php if(!empty($inv['periodo_ini'])||!empty($inv['periodo_fin'])): ?><div class="inv-chip">Período: <b><?= $inv['periodo_ini']?e(date('d/m/Y',strtotime($inv['periodo_ini']))):'—' ?></b> a <b><?= $inv['periodo_fin']?e(date('d/m/Y',strtotime($inv['periodo_fin']))):'—' ?></b></div><?php endif; ?>
        <div class="inv-chip">Fecha: <b><?= e(date('d/m/Y',strtotime($inv['fecha']))) ?></b></div>
      </div>
    </div>
    <div class="inv-parts">
      <div class="inv-block">
        <div class="l">Datos del cliente</div>
        <div class="n"><?= e($inv['cliente_nombre'] ?: '—') ?></div>
        <div class="d"><?php $cd=array_filter([$inv['cliente_nif'],($inv['cliente_tel']??''),$inv['cliente_dir'],$inv['cliente_email']]); echo nl2br(e(implode("\n",$cd))); ?></div>
      </div>
      <div class="inv-block">
        <div class="l">Datos autónomo</div>
        <div class="n"><?= e($em['name']) ?></div>
        <div class="d"><?php $ad=array_filter([$em['nif'],$em['email'],$em['phone'],$em['dir']]); echo nl2br(e(implode("\n",$ad))); ?></div>
      </div>
    </div>
    <table class="inv-tbl">
      <thead><tr><th>Detalle</th><th class="r" style="width:84px">Cantidad</th><th class="r" style="width:120px">Precio</th><th class="r" style="width:120px">Total</th></tr></thead>
      <tbody>
        <?php foreach($items as $it): ?><tr><td class="cpt"><?= e($it['concepto']) ?></td><td class="r"><?= rtrim(rtrim(number_format($it['cantidad'],2,',','.'),'0'),',') ?></td><td class="r"><?= eur($it['precio']) ?></td><td class="r"><?= eur($it['cantidad']*$it['precio']) ?></td></tr><?php endforeach; ?>
        <?php if(!$items): ?><tr><td colspan="4" style="color:var(--muted);text-align:center;padding:20px">Sin líneas.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <div class="inv-tot"><div class="box">
      <div class="row base"><span>Base imponible</span><span><?= eur($sub) ?></span></div>
      <div class="row sub"><span>IVA (+<?= rtrim(rtrim(number_format($inv['iva_pct'],2,',','.'),'0'),',') ?>%)</span><span><?= eur($iva) ?></span></div>
      <div class="row sub"><span>IRPF (−<?= rtrim(rtrim(number_format($inv['irpf_pct'],2,',','.'),'0'),',') ?>%)</span><span>−<?= eur($irpf) ?></span></div>
      <div class="big"><span class="t">TOTAL</span><span class="a"><?= eur($tot) ?></span></div>
    </div></div>
    <?php $forma = !empty($inv['efectivo']) ? 'Efectivo' : 'Transferencia'; ?>
    <div class="inv-pay">
      <div class="h">Información de pago</div>
      <?php if($em['banco']): ?><div class="row"><span>Banco</span><span><?= e($em['banco']) ?></span></div><?php endif; ?>
      <div class="row"><span>Titular</span><span><?= e($em['name']) ?></span></div>
      <div class="row"><span>Forma de pago</span><span><?= e($forma) ?></span></div>
      <div class="row"><span>Vencimiento</span><span><?= e($inv['cond_pago'] ?: 'Contado') ?><?= $inv['fecha_venc']?(' · '.e(date('d/m/Y',strtotime($inv['fecha_venc'])))):'' ?></span></div>
      <?php if(empty($inv['efectivo']) && $em['iban']): ?><div class="row"><span>IBAN</span><span><?= e($em['iban']) ?></span></div><?php endif; ?>
    </div>
    <?php if(!empty($inv['efectivo'])): ?><div class="inv-notes">Operación cobrada en efectivo. Factura sin IVA.</div><?php endif; ?>
    <?php if(trim((string)$inv['notas'])!==''): ?><div class="inv-notes"><?= nl2br(e($inv['notas'])) ?></div><?php endif; ?>
  </div></div>
  <?php erp_foot(); return; }

/* ---------- CREAR / EDITAR (estilo Holded con preview en vivo) ---------- */
if ($edit!==0) {
  /* Un usuario de solo lectura no debe ver el formulario editable con «Guardar
     factura» (antes lo veía y al pulsar no pasaba nada, sin aviso — P2-17). Se le
     manda a la vista imprimible de solo lectura si edita una existente, o a la lista
     si intentaba crear una nueva. */
  if (!can_edit()) { header('Location: facturas.php'.($edit>0 ? ('?v='.$edit) : '')); exit; }
  $inv=['id'=>0,'numero'=>'','emisor'=>fin_emisor_ok($_GET['em']??''),'efectivo'=>0,'personal'=>0,'client_id'=>'','cliente_nombre'=>'','cliente_nif'=>'','cliente_dir'=>'','cliente_email'=>'','cliente_tel'=>'','fecha'=>date('Y-m-d'),'fecha_venc'=>'','periodo_ini'=>'','periodo_fin'=>'','cond_pago'=>'Contado','estado'=>'borrador','iva_pct'=>'21','irpf_pct'=>'0','notas'=>''];
  $items=[];
  if ($edit>0) { $q=db()->prepare('SELECT * FROM invoices WHERE id=?'); $q->execute([$edit]); $inv=$q->fetch(); if(!$inv){header('Location: facturas.php');exit;} $it=db()->prepare('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id'); $it->execute([$edit]); $items=$it->fetchAll(); }
  /* Factura nueva llegando desde la ficha de un cliente (?cli=): ya sale con ese cliente
     marcado y sus datos de facturación rellenos. */
  elseif (isset($_GET['cli'])) { $cliPre=(int)$_GET['cli']; foreach($clients as $cc){ if((int)$cc['id']===$cliPre){ $inv['client_id']=$cliPre; $inv['cliente_nombre']=(trim((string)($cc['fact_nombre']??''))?:$cc['name']); $inv['cliente_nif']=$cc['fact_nif']??''; $inv['cliente_dir']=$cc['fact_dir']??''; $inv['cliente_email']=$cc['fact_email']??''; $inv['cliente_tel']=$cc['fact_tel']??''; break; } } }
  /* Los datos de todos los emisores para que el JS rellene NIF, IBAN, IVA y
     vencimiento al cambiar el desplegable, sin volver al servidor. */
  $emAll=[]; foreach(array_keys($EMISORES) as $ek) $emAll[$ek]=emisor_data($ek);
  /* Prefijo de serie de cada uno, para la sugerencia de número (V-2026-001). */
  $emPfx=[]; foreach(array_keys($EMISORES) as $ek) $emPfx[$ek]=fin_emisor_serie($ek);
  /* El proyecto vinculado se resuelve por id (proj_get). El buscador ya no vuelca
     la lista entera: la sirve el endpoint proj_search bajo demanda. */
  $curProjName=''; if(!empty($inv['project_id'])){ $pr=proj_get((int)$inv['project_id']); if($pr) $curProjName=$pr['nombre']; }
  if ($edit<0) { $ed=$emAll[$inv['emisor']]; $inv['iva_pct']=$ed['iva']; $inv['irpf_pct']=$ed['irpf']; $inv['cond_pago']=$ed['venc']; }
  erp_head('fact', $edit>0?'Editar factura':'Nueva factura');
  ?>
  <style>
  .fe-layout{display:grid;grid-template-columns:minmax(0,1fr) 470px;gap:24px;align-items:start}
  @media(max-width:1150px){.fe-layout{grid-template-columns:1fr}}
  .fe-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px 26px;margin-bottom:18px}
  .fe-card h3{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;font-weight:650;margin-bottom:18px}
  /* Secciones plegables (propuesta 6): pulsar el título contrae la sección. */
  .fe-card>h3{cursor:pointer;user-select:none}
  .fe-card>h3::before{content:'▾';display:inline-block;width:14px;transition:transform .15s ease}
  .fe-card.collapsed>h3{margin-bottom:0}
  .fe-card.collapsed>h3::before{transform:rotate(-90deg)}
  .fe-card.collapsed>*:not(h3){display:none}
  .fe-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .fe-grid.g3{grid-template-columns:1fr 1fr 1fr}
  .fe-grid .full{grid-column:1/-1}
  .pd-seg{display:inline-flex;background:var(--soft);border-radius:12px;padding:4px;gap:4px}
  .pd-opt{border:none;background:transparent;border-radius:8px;padding:9px 18px;font-size:12.5px;font-weight:600;cursor:pointer;color:var(--muted);font-family:inherit;transition:.15s;white-space:nowrap}
  .pd-opt:hover{color:var(--ink-strong)}
  .pd-opt.on{background:var(--ink-strong);color:#fff;box-shadow:0 3px 10px rgba(0,0,0,.16)}
  .pd-row{display:flex;align-items:center;gap:10px}
  .pd-row .dpick{flex:1;min-width:0}
  .pd-arrow{color:var(--muted);font-weight:800;flex:none}
  .fe-f label{font-size:12px;color:var(--muted);font-weight:600;display:block;margin-bottom:6px}
  .fe-f input,.fe-f select,.fe-f textarea{width:100%;border:1px solid var(--line);border-radius:9px;padding:10px 12px;font-size:13px;font-family:inherit;outline:none;box-sizing:border-box;background:#fff}
  .fe-f input:focus,.fe-f select:focus{border-color:var(--accent)}
  .li-head,.li-row{display:grid;grid-template-columns:1fr 72px 100px 100px 30px;gap:8px;align-items:center}
  .li-head{font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:650;padding:0 2px 10px}
  .li-row{margin-bottom:8px}
  .li-row .lt{text-align:right}
  .li-row input{border:1px solid var(--line);border-radius:9px;padding:8px 10px;font-size:13px;font-family:inherit;width:100%;outline:none}
  .li-tot{background:var(--soft);border-radius:9px;padding:8px 10px;font-size:12.5px;text-align:right;color:var(--ink)}
  .li-del{border:none;background:none;color:var(--label);cursor:pointer;font-size:14px;border-radius:7px;padding:6px}
  .li-del:hover{background:#fde8e8;color:#c0392b}
  .fe-add{border:1px dashed #d4d8de;background:#fff;border-radius:10px;padding:9px;width:100%;cursor:pointer;color:var(--accent);font-weight:600;font-size:13px;margin-top:4px}
  .fe-foot{display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:var(--bg);padding:14px 0}
  .fe-tg{display:inline-flex;align-items:center;gap:8px;font-size:13px;color:var(--ink);cursor:pointer;font-weight:500}
  .fe-tg input{width:16px;height:16px;accent-color:var(--accent);cursor:pointer}
  .fe-prevcol{position:sticky;top:14px}
  .fe-prevcol .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:800;margin-bottom:8px;display:flex;align-items:center;gap:6px}
  /* preview */
  .pv-stage{border-radius:18px}
  /* Papel de la vista previa: SIEMPRE blanco (incluido modo oscuro). Se redefinen los tokens de
     texto al tema claro para que no salga texto casi blanco sobre papel blanco. */
  .pv{--ink:#3c4149;--ink-strong:#22262c;--muted:#656a72;--label:#656a72;--line:#eeeeef;--line2:#f6f6f7;--soft:#f7f7f8;--accent-fg:#fff;color:var(--ink);background:#fff;border:1px solid #eeeeef;border-radius:16px;box-shadow:0 14px 44px rgba(0,0,0,.08);padding:24px 24px;transform-origin:top center;animation:pvIn .45s cubic-bezier(.22,1,.36,1)}
  @keyframes pvIn{from{opacity:0;transform:translateY(16px) scale(.975)}to{opacity:1;transform:none}}
  .pv-hd{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px}
  .pv-fact{font-size:27px;font-weight:850;letter-spacing:-1.2px;color:var(--ink-strong);line-height:.9}
  .pv-num{display:inline-block;margin-top:8px;border:1.5px solid var(--ink-strong);border-radius:99px;padding:2px 11px;font-size:10.5px;font-weight:700;color:var(--ink-strong)}
  .pv-chips{display:flex;flex-direction:column;gap:6px;align-items:flex-end}
  .pv-chip{border:1.5px solid var(--line);border-radius:99px;padding:3px 10px;font-size:9.5px;font-weight:600;color:var(--ink)}
  .pv-chip b{color:var(--ink-strong)}
  .pv-parts{display:grid;grid-template-columns:1fr 1fr;border:1.5px solid var(--ink-strong);border-radius:11px;overflow:hidden;margin-bottom:14px}
  .pv-block{padding:10px 12px;min-width:0}
  .pv-block+.pv-block{border-left:1.5px solid var(--ink-strong)}
  .pv-block .l{font-size:8px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:800;margin-bottom:4px}
  .pv-block .n{font-size:11px;font-weight:700;color:var(--ink-strong);word-break:break-word}
  .pv-block .d{font-size:9.5px;color:#6b7280;line-height:1.55;white-space:pre-line;word-break:break-word}
  .pv-tbl{width:100%;border-collapse:separate;margin-bottom:10px}
  .pv-tbl th{background:var(--ink-strong);color:#fff;font-size:8px;text-transform:uppercase;letter-spacing:.3px;padding:6px 9px;text-align:left}
  .pv-tbl th:first-child{border-radius:6px 0 0 6px}.pv-tbl th:last-child{border-radius:0 6px 6px 0}
  .pv-tbl th.r{text-align:right}
  .pv-tbl td{padding:6px 9px;border-bottom:1px solid var(--line2);font-size:10px;color:var(--ink)}
  .pv-tbl .r{text-align:right}
  .pv-tbl .cpt{font-weight:600;color:var(--ink-strong)}
  .pv-tot{margin-left:auto;width:62%}
  .pv-tot .row{display:flex;justify-content:space-between;font-size:10px;padding:3px 1px;color:#6b7280}
  .pv-tot .base{color:var(--ink-strong);font-weight:700;border-top:1.5px solid var(--line);border-bottom:1.5px solid var(--line);padding:5px 1px}
  .pv-big{display:flex;justify-content:space-between;align-items:center;background:var(--ink-strong);color:#fff;border-radius:8px;padding:9px 12px;margin-top:8px}
  .pv-big .a{font-size:15px;font-weight:850}
  .pv-pay{border:1.5px solid var(--line);border-radius:11px;padding:11px 13px;margin-top:14px}
  .pv-pay .h{font-size:8px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:800;margin-bottom:6px}
  .pv-pay .row{display:flex;justify-content:space-between;gap:10px;font-size:9.5px;padding:2px 0}
  .pv-pay .row span:first-child{color:#6b7280}
  .pv-pay .row span:last-child{color:var(--ink-strong);font-weight:600;text-align:right}
  /* Modo oscuro: SOLO la interfaz del editor (.fe-/.li-/.pd-). La vista previa .pv-* queda en blanco (es papel). */
  [data-theme=dark] .fe-card{background-color:var(--card)}
  [data-theme=dark] .pd-opt.on{background-color:var(--rev);color:var(--rev-fg)}
  [data-theme=dark] .fe-f input,[data-theme=dark] .fe-f select,[data-theme=dark] .fe-f textarea,[data-theme=dark] .li-row input{background-color:var(--field);color:var(--ink)}
  [data-theme=dark] .li-del{color:var(--muted)}
  [data-theme=dark] .li-del:hover{background-color:var(--danger-bg);color:var(--danger)}
  [data-theme=dark] .fe-add{background-color:var(--card);border-color:var(--line-strong)}
  /* ---- Móvil (teléfono): solo la INTERFAZ de edición, nunca la vista previa .pv-* ---- */
  @media(max-width:640px){
    .fe-card{padding:18px 16px}
    .fe-grid,.fe-grid.g3{grid-template-columns:1fr}
    .li-head,.li-row{grid-template-columns:1fr 46px 66px 66px 24px;gap:5px}
    .li-head{font-size:9px}
    .li-row input{padding:8px 7px;font-size:12.5px}
    .li-tot{padding:8px 7px;font-size:11.5px}
    .pd-seg{width:100%}
    .pd-opt{flex:1;text-align:center;padding:9px 8px}
    .fe-prevcol{position:static;margin-top:8px}
  }
  </style>
  <a class="btn ghost sm" href="facturas.php<?= ($inv['emisor']??'')?('?em='.$inv['emisor'].'&tipo=ingreso'):'' ?>" style="margin-bottom:14px;display:inline-flex"><?= ic('back',15) ?> Volver</a>
  <h1 style="margin-bottom:16px"><?= $edit>0?'Editar factura':'Nueva factura' ?></h1>
  <form method="post" class="fe-layout" autocomplete="off"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
    <div class="fe-col-form">
      <div class="fe-card">
        <h3>Cliente</h3>
        <div class="fe-grid">
          <div class="fe-f full"><label>Cliente del portal (autorrellena sus datos)</label><select name="client_id" aria-label="Cliente" onchange="feClient(this)"><option value="">— Manual —</option><?php foreach($clients as $c): ?><option value="<?= (int)$c['id'] ?>" data-name="<?= e($c['name']) ?>" data-fnombre="<?= e($c['fact_nombre']) ?>" data-nif="<?= e($c['fact_nif']) ?>" data-dir="<?= e($c['fact_dir']) ?>" data-email="<?= e($c['fact_email']) ?>" data-tel="<?= e($c['fact_tel']) ?>" <?= (int)$inv['client_id']===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
          <div class="fe-f"><label>Nombre / razón social</label><input type="text" name="cliente_nombre" id="feNombre" aria-label="Nombre fiscal del cliente" value="<?= e($inv['cliente_nombre']) ?>" oninput="feRender()" autocomplete="off"></div>
          <div class="fe-f"><label>NIF / CIF</label><input type="text" name="cliente_nif" id="feNif" aria-label="NIF o CIF" value="<?= e($inv['cliente_nif']) ?>" oninput="feRender()" autocomplete="off"></div>
          <div class="fe-f"><label>Teléfono</label><input type="text" name="cliente_tel" id="feTel" aria-label="Teléfono" value="<?= e($inv['cliente_tel']??'') ?>" oninput="feRender()" autocomplete="off"></div>
          <div class="fe-f"><label>Email</label><input type="text" name="cliente_email" id="feEmail" aria-label="Email" value="<?= e($inv['cliente_email']) ?>" oninput="feRender()" autocomplete="off"></div>
          <div class="fe-f full"><label>Dirección</label><input type="text" name="cliente_dir" id="feDir" aria-label="Dirección" value="<?= e($inv['cliente_dir']) ?>" oninput="feRender()" autocomplete="off"></div>
        </div>
      </div>
      <div class="fe-card">
        <h3>Datos de emisión</h3>
        <div class="fe-grid g3">
          <div class="fe-f"><label>Emitida por</label><select name="emisor" id="feEmisor" aria-label="Emisor de la factura" onchange="feCambiaEmisor()"><?php foreach($EMISORES as $k=>$v): ?><option value="<?= e($k) ?>" <?= ($inv['emisor']??'')===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
          <div class="fe-f"><label>Serie (prefijo)</label><input type="text" name="serie" id="feSerie" value="<?= e($inv['id']?'':sset('serie_'.($inv['emisor']??''))) ?>" placeholder="Ej: F, SE-, 2026-" oninput="feSerieChg()"></div>
          <div class="fe-f"><label>Nº (vacío = auto por serie)</label><input type="text" name="numero" id="feNum" value="<?= e($inv['numero']) ?>" placeholder="auto" oninput="feRender()"></div>
          <div class="fe-f"><label>Estado</label><select name="estado" aria-label="Estado de la factura"><?php foreach($ESTADOS as $k=>$v): ?><option value="<?= $k ?>" <?= $inv['estado']===$k?'selected':'' ?>><?= e($v[0]) ?></option><?php endforeach; ?></select></div>
        </div>
      </div>
      <div class="fe-card">
        <h3>Fechas y cobro</h3>
        <div class="fe-grid">
          <div class="fe-f"><label>Fecha</label><input type="text" class="dpick" data-iso="<?= e($inv['fecha']) ?>" data-sync="#feFecha"><input type="hidden" name="fecha" id="feFecha" value="<?= e($inv['fecha']) ?>"></div>
          <div class="fe-f"><label>Fecha vto. (opcional)</label><input type="text" class="dpick" data-iso="<?= e($inv['fecha_venc']) ?>" data-sync="#feVenc"><input type="hidden" name="fecha_venc" id="feVenc" value="<?= e($inv['fecha_venc']) ?>"></div>
          <div class="fe-f full">
            <label style="margin-bottom:8px">Período de facturación</label>
            <div class="pd-seg">
              <button type="button" class="pd-opt" onclick="fePeriodo('vista',this)">Mes vista</button>
              <button type="button" class="pd-opt" onclick="fePeriodo('vencido',this)">Mes vencido</button>
              <button type="button" class="pd-opt" onclick="fePeriodo('clear',this)">Sin período</button>
            </div>
            <div class="pd-row" style="margin-top:11px">
              <input type="text" class="dpick" data-iso="<?= e($inv['periodo_ini']??'') ?>" data-sync="#fePini" placeholder="Desde"><input type="hidden" name="periodo_ini" id="fePini" value="<?= e($inv['periodo_ini']??'') ?>">
              <span class="pd-arrow">→</span>
              <input type="text" class="dpick" data-iso="<?= e($inv['periodo_fin']??'') ?>" data-sync="#fePfin" placeholder="Hasta"><input type="hidden" name="periodo_fin" id="fePfin" value="<?= e($inv['periodo_fin']??'') ?>">
            </div>
          </div>
          <div class="fe-f full"><label>Vencimiento (texto)</label><input type="text" name="cond_pago" id="feCond" value="<?= e($inv['cond_pago']??'Contado') ?>" placeholder="Contado" oninput="feRender()"></div>
        </div>
      </div>
      <div class="fe-card">
        <h3>Líneas</h3>
        <div class="li-head"><span>Detalle</span><span class="lt">Cant.</span><span class="lt">Precio</span><span class="lt">Total</span><span></span></div>
        <div id="feLines"></div>
        <button type="button" class="fe-add" onclick="feAdd()">＋ Añadir línea</button>
      </div>
      <div class="fe-card">
        <h3>Impuestos</h3>
        <div class="fe-grid">
          <div class="fe-f"><label>IVA %</label><input type="number" name="iva_pct" id="feIvaPct" aria-label="IVA en porcentaje" value="<?= e($inv['iva_pct']) ?>" step="0.01" oninput="feCalc()"></div>
          <div class="fe-f"><label>IRPF %</label><input type="number" name="irpf_pct" id="feIrpfPct" aria-label="IRPF en porcentaje" value="<?= e($inv['irpf_pct']) ?>" step="0.01" oninput="feCalc()"></div>
        </div>
        <div style="display:flex;gap:22px;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid var(--line)">
          <label class="fe-tg"><input type="checkbox" name="efectivo" id="feEfectivo" <?= !empty($inv['efectivo'])?'checked':'' ?> onchange="feModoEfectivo()"> Efectivo (en B) · sin IVA ni IRPF</label>
          <label class="fe-tg"><input type="checkbox" name="personal" id="fePersonal" <?= !empty($inv['personal'])?'checked':'' ?>> Ingreso personal (mío · fuera de la contabilidad de empresa)</label>
        </div>
      </div>
      <div class="fe-card">
        <h3>Notas</h3>
        <textarea name="notas" oninput="feRender()" style="width:100%;min-height:64px;border:1px solid var(--line);border-radius:10px;padding:10px 13px;font-family:inherit;font-size:13.5px;box-sizing:border-box" placeholder="Condiciones, comentarios…"><?= e($inv['notas']) ?></textarea>
      </div>
      <div class="fe-card">
        <h3>Proyecto <span style="color:var(--muted);font-weight:400;text-transform:none;letter-spacing:0">· uso interno, no sale en la factura</span></h3>
        <div class="fe-f full">
          <?php proj_combobox($curProjName, (string)($inv['project_id']??'')); ?>
        </div>
      </div>
      <div class="fe-foot"><a class="btn ghost" href="facturas.php">Cancelar</a><button class="btn" type="submit"><?= ic('file',15) ?> Guardar factura</button></div>
    </div>
    <div class="fe-prevcol">
      <div class="lbl"><?= ic('eye',13) ?> Vista previa</div>
      <div class="pv-stage"><div class="pv" id="fePreview"></div></div>
    </div>
  </form>
  <script>
  var EMDATA=<?= json_encode($emAll, JSON_UNESCAPED_UNICODE) ?>;
  var EMPFX=<?= json_encode($emPfx, JSON_UNESCAPED_UNICODE) ?>;
  var FE_ITEMS=<?= json_encode(array_map(function($x){return ['c'=>$x['concepto'],'q'=>(float)$x['cantidad'],'p'=>(float)$x['precio']];},$items), JSON_UNESCAPED_UNICODE) ?>;
  var FE_ISNEW=<?= $edit<0?'true':'false' ?>;
  function eurf(n){return (Math.round(n*100)/100).toLocaleString('es-ES',{minimumFractionDigits:2,maximumFractionDigits:2})+' €';}
  function esc(s){s=(s==null?'':''+s);return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
  function fmtD(iso){if(!iso)return'';var p=(''+iso).split('-');if(p.length!==3)return iso;return p[2]+'/'+p[1]+'/'+p[0];}
  function feDp(){feRender();}
  function feClient(sel){var o=sel.options[sel.selectedIndex];if(!o)return;
    var nm=o.dataset.fnombre||o.dataset.name;if(nm)document.getElementById('feNombre').value=nm;
    function set(id,val){var el=document.getElementById(id);if(el&&val)el.value=val;}
    set('feNif',o.dataset.nif);set('feDir',o.dataset.dir);set('feEmail',o.dataset.email);set('feTel',o.dataset.tel);
    if(typeof pcbRefreshForClient==='function')pcbRefreshForClient();feRender();}
  function feSerieChg(){var sf=document.getElementById('feSerie');var n=document.getElementById('feNum');if(sf&&n)n.placeholder=(sf.value||'')+'001';feRender();}
  function feSetDp(hiddenId,dt){var iso=dt.getFullYear()+'-'+String(dt.getMonth()+1).padStart(2,'0')+'-'+String(dt.getDate()).padStart(2,'0');var h=document.getElementById(hiddenId);if(h)h.value=iso;var vis=document.querySelector('[data-sync=\"#'+hiddenId+'\"]');if(vis){vis.setAttribute('data-iso',iso);var pp=iso.split('-');vis.value=pp[2]+'/'+pp[1]+'/'+pp[0].slice(2);}}
  function feClrDp(hiddenId){var h=document.getElementById(hiddenId);if(h)h.value='';var vis=document.querySelector('[data-sync=\"#'+hiddenId+'\"]');if(vis){vis.setAttribute('data-iso','');vis.value='';}}
  function fePeriodo(tipo,el){if(el){var seg=el.parentNode;var bs=seg.querySelectorAll('.pd-opt');for(var i=0;i<bs.length;i++)bs[i].classList.remove('on');el.classList.add('on');}
    if(tipo==='clear'){feClrDp('fePini');feClrDp('fePfin');feRender();return;}
    var t=new Date();var y=t.getFullYear();var m=t.getMonth();var ini,fin,fch;
    if(tipo==='vencido'){ini=new Date(y,m-1,1);fin=new Date(y,m,1);fch=new Date(y,m,1);}
    else{ini=new Date(y,m,1);fin=new Date(y,m+1,1);fch=new Date(y,m,1);}
    feSetDp('fePini',ini);feSetDp('fePfin',fin);feSetDp('feFecha',fch);feRender();}
  /* El prefijo ya no se adivina de la clave («gavi» → G): viene de EMPFX, que es
     el que cada emisor tiene guardado en Ajustes. Así un emisor nuevo enseña su
     propio prefijo y renombrar a alguien no le cambia la numeración. */
  /* Renombrada: el campo se llama id="feEmisor" aria-label="Emisor de la factura" y un manejador escrito en el HTML
     (onchange="...") busca ese nombre en el formulario antes que en el resto,
     asi que encontraba el CAMPO y no esta funcion. El id no se toca: lo usa
     getElementById. Regla: id de campo y nombre de funcion nunca iguales. */
    function feCambiaEmisor(){var em=document.getElementById('feEmisor').value;var pfx=EMPFX[em]||'F';var n=document.getElementById('feNum');if(n)n.placeholder=pfx+'-'+(new Date().getFullYear())+'-001';
    if(FE_ISNEW && EMDATA[em]){document.getElementById('feIvaPct').value=EMDATA[em].iva;document.getElementById('feIrpfPct').value=EMDATA[em].irpf;document.getElementById('feCond').value=EMDATA[em].venc;}
    feCalc();}
  /* Renombrada: el campo se llama id="feEfectivo" y un manejador escrito en el HTML
     (onchange="...") busca ese nombre en el formulario antes que en el resto,
     asi que encontraba el CAMPO y no esta funcion. El id no se toca: lo usa
     getElementById. Regla: id de campo y nombre de funcion nunca iguales. */
    function feModoEfectivo(){var on=document.getElementById('feEfectivo').checked;var iva=document.getElementById('feIvaPct');var irpf=document.getElementById('feIrpfPct');
    if(on){iva.dataset.prev=iva.value;irpf.dataset.prev=irpf.value;iva.value=0;irpf.value=0;iva.disabled=true;irpf.disabled=true;iva.style.opacity=.5;irpf.style.opacity=.5;}
    else{iva.disabled=false;irpf.disabled=false;iva.style.opacity=1;irpf.style.opacity=1;if(iva.dataset.prev!==undefined)iva.value=iva.dataset.prev;if(irpf.dataset.prev!==undefined)irpf.value=irpf.dataset.prev;}
    feCalc();}
  function feAdd(c,q,p){var box=document.getElementById('feLines');var row=document.createElement('div');row.className='li-row';
    var ci=document.createElement('input');ci.type='text';ci.name='it_concepto[]';ci.placeholder='Concepto…';ci.value=c||'';ci.oninput=feCalc;
    var qi=document.createElement('input');qi.type='number';qi.name='it_cant[]';qi.step='0.01';qi.min='0';qi.className='lt';qi.setAttribute('aria-label','Cantidad');qi.value=(q!=null?q:1);qi.oninput=feCalc;
    var pi=document.createElement('input');pi.type='number';pi.name='it_precio[]';pi.step='0.01';pi.min='0';pi.className='lt';pi.setAttribute('aria-label','Precio');pi.value=(p!=null?p:0);pi.oninput=feCalc;
    var ti=document.createElement('div');ti.className='li-tot';ti.textContent='0,00 €';
    var x=document.createElement('button');x.type='button';x.className='li-del';x.textContent='✕';x.onclick=function(){row.remove();feCalc();};
    row.appendChild(ci);row.appendChild(qi);row.appendChild(pi);row.appendChild(ti);row.appendChild(x);box.appendChild(row);feCalc();}
  function feLines(){var rows=document.querySelectorAll('#feLines .li-row');var out=[];rows.forEach(function(r){var el=r.querySelectorAll('input');out.push({c:el[0].value,q:parseFloat(el[1].value)||0,p:parseFloat(el[2].value)||0});});return out;}
  function feCalc(){var rows=document.querySelectorAll('#feLines .li-row');
    rows.forEach(function(r){var el=r.querySelectorAll('input');var q=parseFloat(el[1].value)||0;var p=parseFloat(el[2].value)||0;r.querySelector('.li-tot').textContent=eurf(q*p);});
    feRender();}
  function feRender(){
    var em=EMDATA[document.getElementById('feEmisor').value]||{};
    var lines=feLines();var sub=0;lines.forEach(function(l){sub+=l.q*l.p;});
    var ivaP=parseFloat(document.getElementById('feIvaPct').value)||0;var irpfP=parseFloat(document.getElementById('feIrpfPct').value)||0;
    var iva=sub*ivaP/100;var irpf=sub*irpfP/100;var tot=sub+iva-irpf;
    var num=document.getElementById('feNum').value||document.getElementById('feNum').placeholder;
    var fecha=document.getElementById('feFecha').value;var pini=document.getElementById('fePini').value;var pfin=document.getElementById('fePfin').value;
    var venc=document.getElementById('feVenc').value;var cond=document.getElementById('feCond').value||'Contado';
    var efect=document.getElementById('feEfectivo').checked;
    var cliN=document.getElementById('feNombre').value||'—';
    var cliD=[document.getElementById('feNif').value,document.getElementById('feTel').value,document.getElementById('feDir').value,document.getElementById('feEmail').value].filter(Boolean).join('\n');
    var emD=[em.nif,em.email,em.phone,em.dir].filter(Boolean).join('\n');
    var rowsHtml='';lines.forEach(function(l){if(!l.c&&!l.p)return;rowsHtml+='<tr><td class="cpt">'+esc(l.c||'—')+'</td><td class="r">'+(l.q||0)+'</td><td class="r">'+eurf(l.p)+'</td><td class="r">'+eurf(l.q*l.p)+'</td></tr>';});
    if(!rowsHtml)rowsHtml='<tr><td colspan="4" style="text-align:center;color:var(--label);padding:12px">Sin líneas</td></tr>';
    var chips='';if(pini||pfin)chips+='<div class="pv-chip">Período: <b>'+(fmtD(pini)||'—')+'</b> a <b>'+(fmtD(pfin)||'—')+'</b></div>';
    chips+='<div class="pv-chip">Fecha: <b>'+(fmtD(fecha)||'—')+'</b></div>';
    var irpfRow='<div class="row"><span>IRPF (−'+irpfP+'%)</span><span>−'+eurf(irpf)+'</span></div>';
    var pay='<div class="pv-pay"><div class="h">Información de pago</div>'+(em.banco?'<div class="row"><span>Banco</span><span>'+esc(em.banco)+'</span></div>':'')+'<div class="row"><span>Titular</span><span>'+esc(em.name||'')+'</span></div><div class="row"><span>Forma de pago</span><span>'+(efect?'Efectivo':'Transferencia')+'</span></div><div class="row"><span>Vencimiento</span><span>'+esc(cond)+(venc?(' · '+fmtD(venc)):'')+'</span></div>'+((!efect&&em.iban)?'<div class="row"><span>IBAN</span><span>'+esc(em.iban)+'</span></div>':'')+'</div>';
    document.getElementById('fePreview').innerHTML=
      '<div class="pv-hd"><div><div class="pv-fact">FACTURA</div><div class="pv-num">Nº '+esc(num)+'</div></div><div class="pv-chips">'+chips+'</div></div>'+
      '<div class="pv-parts"><div class="pv-block"><div class="l">Datos del cliente</div><div class="n">'+esc(cliN)+'</div><div class="d">'+esc(cliD)+'</div></div><div class="pv-block"><div class="l">Datos autónomo</div><div class="n">'+esc(em.name||'')+'</div><div class="d">'+esc(emD)+'</div></div></div>'+
      '<table class="pv-tbl"><thead><tr><th>Detalle</th><th class="r">Cant.</th><th class="r">Precio</th><th class="r">Total</th></tr></thead><tbody>'+rowsHtml+'</tbody></table>'+
      '<div class="pv-tot"><div class="row base"><span>Base imponible</span><span>'+eurf(sub)+'</span></div><div class="row"><span>IVA (+'+ivaP+'%)</span><span>'+eurf(iva)+'</span></div>'+irpfRow+'<div class="pv-big"><span>TOTAL</span><span class="a">'+eurf(tot)+'</span></div></div>'+
      pay;
  }
  if(FE_ITEMS.length)FE_ITEMS.forEach(function(x){feAdd(x.c,x.q,x.p);});else feAdd();
  if(document.getElementById('feEfectivo').checked)feModoEfectivo();else feRender();
  setInterval(feRender,600); /* mantiene el preview al día (incluidas fechas del datepicker) */
  /* El combobox de proyecto se auto-inicializa desde proj_combobox_assets() (lib). */
  /* Secciones plegables del formulario (propuesta 6). */
  document.querySelectorAll('.fe-card>h3').forEach(function(h){ h.addEventListener('click',function(){ h.parentElement.classList.toggle('collapsed'); }); });
  </script>
  <?php proj_combobox_assets(); erp_foot(); return; }

/* ---------- HUBS · INGRESOS/GASTOS · MESES · LISTA ---------- */
/* Antes se llamaba aquí a prog_run(), así que abrir la lista de Facturas (un GET)
   emitía facturas recurrentes: un F5 o una precarga podían duplicarlas (P1-06).
   La emisión la hacen ahora el cron y el botón «Generar ahora» de Programaciones. */
/* Una sola vez: el sistema antiguo apuntaba el ingreso al emitir la factura, no
   al cobrarla. Esto retira de la caja las que nunca llegaron a pagarse. */
fin_limpiar_ingresos_no_cobrados();

/* ===== HUB POR CLIENTE (tipo bóveda) · cliente → meses → facturas =====
   Los nombres de los meses ya no se listan aquí: mes_nom() y mes_label() viven
   en erp_nav.php y los usa todo el ERP. */
$clientesHub = isset($_GET['clientes']);
$cliW = isset($_GET['cli']) ? (int)$_GET['cli'] : 0;
$cliMes = (isset($_GET['mes']) && preg_match('/^\d{4}-\d{2}$/',$_GET['mes'])) ? $_GET['mes'] : '';
if ($clientesHub || $cliW) {
  erp_head('fact','Facturas por cliente');
  ?>
  <style>
  .cb-head{display:flex;align-items:center;gap:12px;margin-bottom:18px}.cb-head h1{flex:1}
  .cb-head h1 .crumb{color:var(--muted);font-weight:600;text-decoration:none}.cb-head h1 .crumb:hover{color:var(--ink-strong)}.cb-head h1 .sep{color:var(--label);margin:0 4px}
  .cb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:18px}
  .cb-card{display:block;background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px;transition:transform .12s,box-shadow .12s,border-color .12s}
  .cb-card:hover{transform:translateY(-3px);box-shadow:0 14px 40px rgba(0,0,0,.08);border-color:#d7d7db}
  .cb-av{width:46px;height:46px;border-radius:13px;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;margin-bottom:14px}
  .cb-nm{font-size:15px;font-weight:750;color:var(--ink-strong);letter-spacing:-.3px}
  .cb-sub{font-size:12px;color:var(--muted);margin-top:4px}
  .cb-amt{font-size:17px;font-weight:750;color:var(--ink-strong);letter-spacing:-.3px;margin-top:12px}
  .cb-net{display:block;font-size:12px;color:var(--muted);font-weight:500;margin-top:5px}
  .cb-mon{display:block;background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px;transition:transform .12s,box-shadow .12s,border-color .12s}
  .cb-mon:hover{transform:translateY(-3px);box-shadow:0 12px 34px rgba(0,0,0,.08);border-color:#d7d7db}
  .cb-mon .fold{display:flex;color:var(--accent);margin-bottom:12px}.cb-mnm{font-size:15px;font-weight:700;color:var(--ink-strong)}.cb-msub{font-size:12px;color:var(--muted);margin-top:3px}
  .cb-tbl{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
  .cb-row{display:grid;grid-template-columns:130px 1fr 110px 120px 110px 22px;gap:10px;align-items:center;padding:15px 18px;border-bottom:1px solid var(--line);cursor:pointer}
  .cb-row:last-child{border-bottom:none}.cb-row:hover{background:#fafbfc}
  .cb-row.h{background:#fbfbfc;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:650;cursor:default}
  .cb-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:99px;width:max-content}
  .cb-empty{grid-column:1/-1;padding:40px;text-align:center;color:var(--muted);background:#fff;border:1px solid var(--line);border-radius:16px}
  .fiscal-box{display:none;margin-bottom:18px}.fiscal-box.on{display:block}
  .fiscal-form{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px 20px}
  .ff-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}.ff-grid .full{grid-column:1/-1}
  .ff-grid label{font-size:11.5px;color:var(--muted);font-weight:600;display:block;margin-bottom:5px}
  .ff-grid input{width:100%;border:1px solid var(--line);border-radius:9px;padding:9px 11px;font-size:13px;font-family:inherit;box-sizing:border-box;outline:none}
  @media(max-width:720px){.ff-grid{grid-template-columns:1fr 1fr}}
  [data-theme=dark] .cb-card,[data-theme=dark] .cb-mon,[data-theme=dark] .cb-tbl,[data-theme=dark] .cb-empty{background-color:var(--card)}
  [data-theme=dark] .cb-card:hover,[data-theme=dark] .cb-mon:hover{border-color:var(--line-strong)}
  [data-theme=dark] .cb-row:hover{background-color:var(--soft)}
  [data-theme=dark] .cb-row.h{background-color:var(--soft)}
  [data-theme=dark] .fiscal-form{background-color:var(--card)}
  [data-theme=dark] .ff-grid input{background-color:var(--field);color:var(--ink)}
  /* ---- Móvil (teléfono) ---- */
  @media(max-width:640px){
    .cb-head{flex-wrap:wrap;gap:10px}
    .cb-head h1{flex:1 1 100%;font-size:20px}
    .cb-head .btn{flex:1 1 auto;justify-content:center}
    /* Clientes del hub = filas compactas planas, no tarjetas grandes */
    .cb-grid{grid-template-columns:1fr;gap:0;border-top:1px solid var(--line)}
    .cb-card{display:grid;grid-template-columns:auto 1fr auto;grid-template-areas:"av nm amt" "av sub amt";column-gap:11px;align-items:center;padding:11px 13px;border:0;border-bottom:1px solid var(--line);border-radius:0;background:transparent}
    .cb-card:hover{transform:none;box-shadow:none;border-color:var(--line)}
    .cb-av{grid-area:av;width:34px;height:34px;border-radius:9px;font-size:13px;margin-bottom:0}
    .cb-nm{grid-area:nm;font-size:14px;align-self:end}
    .cb-sub{grid-area:sub;margin-top:1px;align-self:start}
    .cb-amt{grid-area:amt;font-size:15px;margin-top:0;text-align:right}
    .cb-net{font-size:11px;margin-top:1px}
    .cb-tbl{overflow-x:auto;-webkit-overflow-scrolling:touch}
    .cb-row{min-width:600px}
    .ff-grid{grid-template-columns:1fr}
  }
  </style>
  <?php
  if ($clientesHub) {
    $rowsC=[];
    foreach(db()->query("SELECT i.client_id cid, c.name nm, i.iva_pct, i.irpf_pct, (SELECT COALESCE(SUM(cantidad*precio),0) FROM invoice_items WHERE invoice_id=i.id) base FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.client_id IS NOT NULL".(function_exists('alcance_sql')?alcance_sql('i.client_id'):''))->fetchAll() as $iv){
      $k=(int)$iv['cid']; if(!isset($rowsC[$k]))$rowsC[$k]=['name'=>$iv['nm'],'nf'=>0,'tot'=>0,'neto'=>0]; $rowsC[$k]['nf']++; $rowsC[$k]['tot']+=$iv['base']*(1+$iv['iva_pct']/100-$iv['irpf_pct']/100); $rowsC[$k]['neto']+=(float)$iv['base'];
    }
    uasort($rowsC,fn($a,$b)=>strcasecmp($a['name'],$b['name']));
    ?>
    <div class="cb-head"><h1>Facturas por cliente</h1><?php if(can_edit()): ?><a class="btn" href="facturas.php?new=1"><?= ic('plus',16) ?> Nueva factura</a><?php endif; ?></div>
    <div class="cb-grid">
      <?php if(!$rowsC): ?><?= erp_empty('euro','Aún no hay facturas','Cuando emitas facturas a tus clientes, aparecerán aquí.', can_edit()?'<a class="btn" href="facturas.php?new=1">'.ic('plus',15).' Nueva factura</a>':'') ?><?php endif; ?>
      <?php foreach($rowsC as $cid=>$rc): ?>
      <a class="cb-card" href="facturas.php?cli=<?= (int)$cid ?>">
        <span class="cb-av" style="background:<?= avatar_color($rc['name']) ?>"><?= e(mb_strtoupper(mb_substr($rc['name'],0,2))) ?></span>
        <div class="cb-nm"><?= e($rc['name']) ?></div>
        <div class="cb-sub"><?= (int)$rc['nf'] ?> factura<?= $rc['nf']==1?'':'s' ?></div>
        <div class="cb-amt"><?= eur($rc['tot']) ?><span class="cb-net">Neto <?= eur($rc['neto']??$rc['tot']) ?></span></div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php erp_foot(); return;
  }
  /* cliente concreto */
  $cn=db()->prepare('SELECT * FROM clients WHERE id=?'); $cn->execute([$cliW]); $cliRow=$cn->fetch();
  if(!$cliRow){ header('Location: facturas.php?clientes=1'); exit; }
  $cliName=$cliRow['name'];
  $qi=db()->prepare("SELECT i.*, (SELECT COALESCE(SUM(cantidad*precio),0) FROM invoice_items WHERE invoice_id=i.id) base FROM invoices i WHERE i.client_id=? ORDER BY i.fecha DESC, i.id DESC"); $qi->execute([$cliW]); $cinvs=$qi->fetchAll();
  $cmeses=[]; foreach($cinvs as $iv){ $mk=date('Y-m',strtotime($iv['fecha'])); if(!isset($cmeses[$mk]))$cmeses[$mk]=['n'=>0,'tot'=>0,'neto'=>0]; $cmeses[$mk]['n']++; $cmeses[$mk]['tot']+=$iv['base']*(1+$iv['iva_pct']/100-$iv['irpf_pct']/100); $cmeses[$mk]['neto']+=(float)$iv['base']; }
  ?>
  <div class="cb-head"><h1><a class="crumb" href="facturas.php?clientes=1">Por cliente</a><span class="sep">›</span><?php if($cliMes): ?><a class="crumb" href="facturas.php?cli=<?= $cliW ?>"><?= e($cliName) ?></a><span class="sep">›</span><?= e(mes_label($cliMes)) ?><?php else: ?><?= e($cliName) ?><?php endif; ?></h1>
    <?php if(can_edit()): ?><button class="btn ghost" onclick="document.getElementById('fiscalBox').classList.toggle('on')"><?= ic('settings',15) ?> Datos de facturación</button><a class="btn" href="facturas.php?new=1"><?= ic('plus',16) ?> Nueva factura</a><?php endif; ?>
  </div>
  <?php if(can_edit()): ?>
  <div id="fiscalBox" class="fiscal-box">
    <form method="post" class="fiscal-form"><input type="hidden" name="action" value="save_cli_fiscal"><input type="hidden" name="cli" value="<?= $cliW ?>">
      <div class="ff-grid">
        <div><label>Nombre fiscal / razón social</label><input name="fact_nombre" value="<?= e($cliRow['fact_nombre']??'') ?>" placeholder="<?= e($cliName) ?>"></div>
        <div><label>NIF / CIF</label><input name="fact_nif" value="<?= e($cliRow['fact_nif']??'') ?>"></div>
        <div><label>Teléfono</label><input name="fact_tel" value="<?= e($cliRow['fact_tel']??'') ?>"></div>
        <div><label>Email</label><input name="fact_email" value="<?= e($cliRow['fact_email']??'') ?>"></div>
        <div class="full"><label>Dirección fiscal</label><input name="fact_dir" value="<?= e($cliRow['fact_dir']??'') ?>"></div>
      </div>
      <div style="text-align:right;margin-top:12px"><button class="btn sm" type="submit">Guardar datos de facturación</button></div>
    </form>
  </div>
  <?php endif; ?>
  <?php if($cliMes===''): ?>
    <div class="cb-grid">
      <?php if(!$cmeses): ?><?= erp_empty('euro','Este cliente no tiene facturas','Emite la primera cuando quieras.', can_edit()?'<a class="btn" href="facturas.php?new=1">'.ic('plus',15).' Emitir factura</a>':'') ?><?php endif; ?>
      <?php foreach($cmeses as $mk=>$ms): ?>
      <a class="cb-mon" href="facturas.php?cli=<?= $cliW ?>&mes=<?= $mk ?>">
        <span class="fold"><?= ic('folder',30) ?></span>
        <div class="cb-mnm"><?= e(mes_label($mk)) ?></div>
        <div class="cb-msub"><?= (int)$ms['n'] ?> factura<?= $ms['n']==1?'':'s' ?></div>
        <div class="cb-amt"><?= eur($ms['tot']) ?><span class="cb-net">Neto <?= eur($ms['neto']??$ms['tot']) ?></span></div>
      </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="cb-tbl">
      <div class="cb-row h"><span>Nº</span><span>Emisor</span><span>Fecha</span><span style="text-align:right">Total</span><span>Estado</span><span></span></div>
      <?php $any=false; foreach($cinvs as $iv): if(date('Y-m',strtotime($iv['fecha']))!==$cliMes) continue; $any=true; $ev=$ESTADOS[$iv['estado']]??$ESTADOS['borrador']; $tt=$iv['base']*(1+$iv['iva_pct']/100-$iv['irpf_pct']/100); ?>
        <div class="cb-row" onclick="location.href='facturas.php?v=<?= (int)$iv['id'] ?>'">
          <span style="font-weight:700;color:var(--ink-strong);font-size:13px"><?= e($iv['numero']) ?></span>
          <span class="muted" style="font-size:12.5px"><?= e($EMISORES[$iv['emisor']]??'') ?></span>
          <span class="muted" style="font-size:12.5px"><?= e(date('d/m/Y',strtotime($iv['fecha']))) ?></span>
          <span style="text-align:right;font-weight:600"><?= eur($tt) ?></span>
          <span><span class="cb-badge" style="background:<?= $ev[1] ?>18;color:<?= $ev[1] ?>"><?= e($ev[0]) ?></span></span>
          <span style="text-align:right;color:var(--label)"><?= ic('chevron',15) ?></span>
        </div>
      <?php endforeach; if(!$any): ?><div style="padding:40px;text-align:center;color:var(--muted)">No hay facturas en <?= e(mes_label($cliMes)) ?>.</div><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php erp_foot(); return; }

$TIPONOM = ['ingreso'=>'Ingresos','gasto'=>'Gastos'];
$tipo = in_array($_GET['tipo']??'',['ingreso','gasto'],true) ? $_GET['tipo'] : '';
$mes  = (isset($_GET['mes']) && preg_match('/^\d{4}-\d{2}$/',$_GET['mes'])) ? $_GET['mes'] : '';

/* stats por autónomo (grid principal): facturas emitidas */
$hubStats=[]; foreach(array_keys($EMISORES) as $ek) $hubStats[$ek]=['n'=>0,'cob'=>0,'pen'=>0];
foreach(db()->query("SELECT i.emisor,i.estado,i.iva_pct,i.irpf_pct,(SELECT COALESCE(SUM(cantidad*precio),0) FROM invoice_items WHERE invoice_id=i.id) base FROM invoices i")->fetchAll() as $iv){
  /* Una factura de alguien que ya no está en la lista no se suma a otro: se
     cuenta aparte, para que el total del hub no mienta. */
  $k=(string)$iv['emisor']; if(!isset($hubStats[$k])) $hubStats[$k]=['n'=>0,'cob'=>0,'pen'=>0,'baja'=>1];
  $tt=$iv['base']*(1+$iv['iva_pct']/100-$iv['irpf_pct']/100); $hubStats[$k]['n']++;
  if($iv['estado']==='pagada')$hubStats[$k]['cob']+=$tt; elseif($iv['estado']!=='borrador')$hubStats[$k]['pen']+=$tt; }

/* La cadena vacía significa «el hub, sin filtrar por nadie», así que aquí no
   vale fin_emisor_ok(): solo se acepta una clave que exista. Se aceptan también
   las de emisores dados de baja que aún tienen facturas, o su histórico quedaría
   inaccesible desde el momento en que se les quita de la lista. */
$emGet = (string)($_GET['em'] ?? '');
$em    = (isset($EMISORES[$emGet]) || isset($hubStats[$emGet])) ? $emGet : '';

/* documentos del autónomo: facturas emitidas (ingreso) + subidas (ingreso/gasto) */
$docs=[]; $totTipo=['ingreso'=>0,'gasto'=>0]; $cntTipo=['ingreso'=>0,'gasto'=>0];
if($em!==''){
  $q=db()->prepare("SELECT i.*, (SELECT COALESCE(SUM(cantidad*precio),0) FROM invoice_items WHERE invoice_id=i.id) base FROM invoices i WHERE i.emisor=? ORDER BY i.fecha DESC, i.id DESC");
  $q->execute([$em]);
  foreach($q->fetchAll() as $iv){ $tt=$iv['base']*(1+$iv['iva_pct']/100-$iv['irpf_pct']/100);
    $docs[]=['tipo'=>'ingreso','fecha'=>$iv['fecha'],'importe'=>$tt,'neto'=>(float)$iv['base'],'titulo'=>($iv['cliente_nombre']?:'—'),'sub'=>$iv['numero'],'kind'=>'inv','id'=>(int)$iv['id'],'estado'=>$iv['estado']];
    $totTipo['ingreso']+=$tt; $cntTipo['ingreso']++; }
  $qu=db()->prepare("SELECT u.*, a.project_id, p.nombre proj_name FROM invoice_uploads u LEFT JOIN accounting a ON a.id=u.acc_id LEFT JOIN projects p ON p.id=a.project_id WHERE u.emisor=? ORDER BY u.fecha DESC, u.id DESC"); $qu->execute([$em]);
  foreach($qu->fetchAll() as $u){ $tp=($u['tipo']==='gasto')?'gasto':'ingreso';
    $docs[]=['tipo'=>$tp,'fecha'=>$u['fecha'],'importe'=>(float)$u['importe'],'titulo'=>($u['concepto']!==''?$u['concepto']:($tp==='gasto'?'Gasto':'Ingreso')),'concepto'=>$u['concepto'],'sub'=>$u['proveedor'],'kind'=>'file','id'=>(int)$u['id'],'file'=>$u['filename'],'orig'=>$u['orig_name'],'efectivo'=>(int)($u['efectivo']??0),'personal'=>(int)($u['personal']??0),'proj_id'=>(int)($u['project_id']??0),'proj_name'=>($u['proj_name']??'')];
    $totTipo[$tp]+=(float)$u['importe']; $cntTipo[$tp]++; }
}
$meses=[]; $docsMes=[];
if($em!=='' && $tipo!==''){
  foreach($docs as $d){ if($d['tipo']!==$tipo) continue; $mk=date('Y-m',strtotime($d['fecha'])); if(!isset($meses[$mk]))$meses[$mk]=['n'=>0,'tot'=>0,'neto'=>0]; $meses[$mk]['n']++; $meses[$mk]['tot']+=$d['importe']; $meses[$mk]['neto']+=($d['neto']??$d['importe']); if($mes!=='' && $mk===$mes) $docsMes[]=$d; }
  krsort($meses);
}
erp_head('fact', 'Facturas');
?>
<style>
.fc-head{display:flex;align-items:center;gap:12px;margin-bottom:18px}.fc-head h1{flex:1}
.fc-head .co-yr a{padding:6px 13px;border-radius:8px;font-size:12.5px;font-weight:600;color:var(--muted)}
.fc-head .co-yr a.on{background:var(--accent);color:#fff}.fc-head .co-yr a:hover:not(.on){background:var(--soft)}
.fc-head h1 .crumb{color:var(--muted);font-weight:600;text-decoration:none}
.fc-head h1 .crumb:hover{color:var(--ink-strong)}
.fc-head h1 .sep{color:var(--label);font-weight:400;margin:0 3px}
.fc-hubs{display:grid;grid-template-columns:1fr 1fr;gap:22px}
@media(max-width:720px){.fc-hubs{grid-template-columns:1fr}}
.fc-hub{display:flex;flex-direction:column;justify-content:center;min-height:300px;background:#fff;border:1px solid var(--line);border-radius:22px;padding:44px 40px;position:relative;transition:transform .14s ease,box-shadow .14s ease,border-color .14s ease}
.fc-hub:hover{transform:translateY(-4px);box-shadow:0 18px 48px rgba(0,0,0,.09);border-color:#d7d7db}
.fc-hub .ico{width:70px;height:70px;border-radius:20px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;margin-bottom:26px}
.fc-hub .ico svg{width:34px;height:34px}
.fc-hub .nm{font-size:26px;font-weight:780;color:var(--ink-strong);letter-spacing:-.6px}
.fc-hub .sub{font-size:13px;color:var(--muted);margin-top:4px}
.fc-hub .stats{display:flex;gap:40px;margin-top:28px}
.fc-hub .stats .s .v{font-size:20px;font-weight:750;color:var(--ink-strong)}
.fc-hub .stats .s .k{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:700;margin-top:3px}
.fc-hub .go{position:absolute;top:34px;right:34px;color:var(--label)}
.fc-months{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:18px}
.fc-mon{display:block;background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px 22px 22px;transition:transform .12s ease,box-shadow .12s ease,border-color .12s ease}
.fc-mon:hover{transform:translateY(-3px);box-shadow:0 12px 34px rgba(0,0,0,.08);border-color:#d7d7db}
.fc-mon .fold{display:flex;color:var(--accent);margin-bottom:14px}
.fc-mon .mnm{font-size:16px;font-weight:700;color:var(--ink-strong)}
.fc-mon .msub{font-size:12px;color:var(--muted);margin-top:3px}
.fc-mon .mtot{font-size:16px;font-weight:750;color:var(--ink-strong);letter-spacing:-.3px;margin-top:14px}
.fc-mon .mnet{display:block;font-size:12px;color:var(--muted);font-weight:500;margin-top:5px}
.fc-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
.fc-kpi{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px}
.fc-kpi .n{font-size:23px;font-weight:730;color:var(--ink-strong);letter-spacing:-.5px}
.fc-kpi .l{font-size:11.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;font-weight:700;margin-top:3px}
.fc-tbl{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
.fc-row{display:grid;grid-template-columns:110px 1fr 120px 120px 120px 40px;gap:10px;align-items:center;padding:13px 18px;border-bottom:1px solid var(--line);cursor:pointer}
.fc-row:last-child{border-bottom:none}.fc-row:hover{background:#fafbfc}
.fc-row.h{background:#fbfbfc;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;cursor:default}
.fc-num{font-weight:700;color:var(--ink-strong);font-size:13px}
.fc-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:99px;width:max-content;display:inline-flex;align-items:center;flex:none}
.fc-det{display:flex;align-items:center;gap:9px;min-width:0}
.fc-av{width:26px;height:26px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:700}
.fc-cn{font-weight:500;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fc-bdot{width:7px;height:7px;border-radius:50%;display:inline-block;margin-right:5px}
.fc-empty{padding:50px;text-align:center;color:var(--muted)}
.fc-up .fc-upkind{display:inline-flex;align-items:center;gap:9px;font-weight:600;color:var(--ink-strong);font-size:12.5px;white-space:nowrap}
.fc-upic{width:28px;height:28px;border-radius:8px;background:var(--soft);display:inline-flex;align-items:center;justify-content:center;color:var(--accent);flex:none}
.fc-upic svg{width:14px;height:14px;display:block}
.fc-updet{display:flex;align-items:center;gap:9px;min-width:0;overflow:hidden}
.fc-updet b{font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.fc-updet small{color:var(--muted);font-size:12px;white-space:nowrap;flex:none}
.fc-tag{font-size:9.5px;font-weight:800;letter-spacing:.3px;padding:2px 8px;border-radius:99px;white-space:nowrap;text-transform:uppercase;flex:none}
.fc-tag.ef{background:#eaf7ee;color:var(--ok)}
.fc-tag.pe{background:#eef1fb;color:#3b5bdb}
.fc-ftype{font-size:9.5px;font-weight:800;letter-spacing:.4px;color:var(--muted);background:var(--soft);padding:4px 10px;border-radius:99px}
.fc-kebab{border:none;background:none;color:var(--label);cursor:pointer;font-size:19px;line-height:1;border-radius:8px;padding:1px 7px;font-weight:700;transition:.12s}
.fc-kebab:hover{background:var(--soft);color:var(--ink-strong)}
.ctxmenu{position:fixed;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 46px rgba(0,0,0,.17);padding:5px;min-width:190px;z-index:500;display:none}
.ctxmenu.on{display:block;animation:pop .15s ease}
.ctxmenu a{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;color:#4c515b;font-weight:500;cursor:pointer;font-size:13px}
.ctxmenu a:hover{background:var(--soft);color:var(--ink)}.ctxmenu a.danger{color:#c0392b}.ctxmenu a.danger:hover{background:#fde8e8}
.ctxmenu .sep{height:1px;background:var(--line);margin:4px 6px}.ctxmenu .dot{width:8px;height:8px;border-radius:50%}
.up-wrap{display:none;margin-bottom:18px}
.up-wrap.on{display:block;animation:fadeUp .2s ease}
.up-form{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:0 14px 44px rgba(0,0,0,.07)}
.upx{display:grid;grid-template-columns:minmax(0,1.05fr) 360px}
.upx-prev{border-right:1px solid var(--line);display:flex;flex-direction:column;background:linear-gradient(180deg,#fbfbfc,#eef0f3)}
.upx-prevbar{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--line);background:#fff}
.upx-fname{font-size:13px;font-weight:600;color:var(--ink-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
.upx-badge{font-size:9.5px;font-weight:800;letter-spacing:.5px;padding:3px 9px;border-radius:99px;background:var(--soft);color:var(--accent);text-transform:uppercase}
.upx-stage{flex:1;min-height:380px;display:flex;align-items:center;justify-content:center;padding:18px;overflow:auto}
.upx-stage iframe{width:100%;height:430px;border:none;border-radius:10px;background:#fff;box-shadow:0 10px 34px rgba(0,0,0,.12)}
.upx-stage img{max-width:100%;max-height:450px;border-radius:10px;box-shadow:0 10px 34px rgba(0,0,0,.16);display:block;animation:pvIn .4s cubic-bezier(.22,1,.36,1)}
.upx-empty{display:flex;flex-direction:column;align-items:center;gap:7px;text-align:center;color:var(--muted);cursor:pointer;border:2px dashed #d6d9df;border-radius:14px;padding:44px 34px;width:100%;box-sizing:border-box;transition:.15s;background:#fff}
.upx-empty:hover{border-color:var(--accent);background:rgba(0,0,0,.015);color:var(--ink)}
.upx-empty svg{color:var(--accent);margin-bottom:2px}
.upx-empty b{font-size:14.5px;color:var(--ink-strong);font-weight:750}
.upx-empty span{font-size:12px}
.upx-prevfoot{display:flex;gap:8px;padding:12px 16px;border-top:1px solid var(--line);background:#fff}
.upx-pick,.upx-clear{border:1px solid var(--line);background:#fff;border-radius:10px;padding:8px 14px;font-size:12.5px;font-weight:600;cursor:pointer;color:var(--ink);font-family:inherit;display:inline-flex;align-items:center;gap:6px}
.upx-pick{background:var(--ink-strong);color:#fff;border-color:var(--ink-strong)}
.upx-pick:hover{opacity:.9}
.upx-clear:hover{background:#fde8e8;color:#c0392b;border-color:#f3c7c7}
.upx-form{padding:24px 26px;display:flex;flex-direction:column;gap:16px}
.upx-f label{font-size:11px;color:var(--muted);font-weight:650;display:block;margin-bottom:7px;text-transform:uppercase;letter-spacing:.4px}
.upx-f input[type=text]{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font-size:13.5px;font-family:inherit;outline:none;box-sizing:border-box;transition:.15s}
.upx-f input[type=text]:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,0,0,.045)}
.upx-row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.upx-tgs{display:flex;flex-direction:column;gap:11px;background:var(--soft);border-radius:12px;padding:13px 15px}
.upx-tg{display:inline-flex;align-items:center;gap:10px;font-size:12.5px;color:var(--ink);cursor:pointer;font-weight:500}
.upx-tg input{width:16px;height:16px;accent-color:var(--accent);cursor:pointer;flex:none}
.upx-foot{display:flex;justify-content:flex-end;gap:10px;margin-top:auto;padding-top:4px}
@media(max-width:900px){.upx{grid-template-columns:1fr}.upx-prev{border-right:none;border-bottom:1px solid var(--line)}}
.fc-del{border:none;background:none;color:var(--label);cursor:pointer;font-size:13px;border-radius:7px;padding:5px 8px}
.fc-del:hover{background:#fde8e8;color:#c0392b}
[data-theme=dark] .fc-head .co-yr a.on{color:var(--accent-fg)}
[data-theme=dark] .fc-hub,[data-theme=dark] .fc-mon,[data-theme=dark] .fc-kpi,[data-theme=dark] .fc-tbl{background-color:var(--card)}
[data-theme=dark] .fc-hub:hover,[data-theme=dark] .fc-mon:hover{border-color:var(--line-strong)}
[data-theme=dark] .fc-hub .ico{color:var(--accent-fg)}
[data-theme=dark] .fc-hub .go{color:var(--muted)}
[data-theme=dark] .fc-row:hover{background-color:var(--soft)}
[data-theme=dark] .fc-row.h{background-color:var(--soft)}
[data-theme=dark] .fc-tag.ef{background-color:var(--ok-bg);color:var(--ok)}
[data-theme=dark] .fc-tag.pe{background-color:var(--accent-soft);color:var(--accent)}
[data-theme=dark] .fc-kebab{color:var(--muted)}
[data-theme=dark] .ctxmenu{background-color:var(--pop)}
[data-theme=dark] .ctxmenu a{color:var(--ink)}
[data-theme=dark] .ctxmenu a.danger{color:var(--danger)}
[data-theme=dark] .ctxmenu a.danger:hover{background-color:var(--danger-bg)}
[data-theme=dark] .up-form{background-color:var(--card)}
[data-theme=dark] .upx-prev{background:var(--soft)}
[data-theme=dark] .upx-prevbar,[data-theme=dark] .upx-prevfoot{background-color:var(--card)}
[data-theme=dark] .upx-empty{background-color:var(--card);border-color:var(--line-strong)}
[data-theme=dark] .upx-clear{background-color:var(--card)}
[data-theme=dark] .upx-clear:hover{background-color:var(--danger-bg);color:var(--danger);border-color:var(--danger-line)}
[data-theme=dark] .upx-pick{background-color:var(--rev);color:var(--rev-fg);border-color:var(--rev)}
[data-theme=dark] .upx-f input[type=text]{background-color:var(--field);color:var(--ink)}
[data-theme=dark] .fc-del{color:var(--muted)}
[data-theme=dark] .fc-del:hover{background-color:var(--danger-bg);color:var(--danger)}
/* ---- Móvil (teléfono): listado y navegación; no afecta al documento imprimible ---- */
@media(max-width:640px){
  .fc-head{flex-wrap:wrap;gap:10px}
  .fc-head h1{flex:1 1 100%}
  .fc-head .btn{flex:1 1 auto;justify-content:center}
  /* Hubs de emisor = fila compacta (icono + nombre/stats), no tarjetón alto */
  .fc-hubs{grid-template-columns:1fr;gap:10px}
  .fc-hub{min-height:0;display:grid;grid-template-columns:auto 1fr;grid-template-areas:"ico nm" "ico sub" "ico stats";column-gap:14px;row-gap:2px;align-content:center;padding:14px 40px 14px 16px;border-radius:14px}
  .fc-hub:hover{transform:none;box-shadow:none}
  .fc-hub .ico{grid-area:ico;align-self:center;width:46px;height:46px;border-radius:13px;margin-bottom:0}
  .fc-hub .ico svg{width:24px;height:24px}
  .fc-hub .nm{grid-area:nm;font-size:18px;align-self:end}
  .fc-hub .sub{grid-area:sub}
  .fc-hub .stats{grid-area:stats;gap:24px;margin-top:8px}
  .fc-hub .stats .s .v{font-size:16px}
  .fc-hub .go{top:50%;right:14px;transform:translateY(-50%)}
  .fc-months{grid-template-columns:1fr 1fr;gap:12px}
  .fc-kpis{grid-template-columns:1fr 1fr;gap:10px}
  .fc-kpi{padding:12px 13px}
  .fc-kpi .n{font-size:19px}
  .fc-tbl{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .fc-row{min-width:640px}
  .upx-form{padding:18px 16px}
}
@media(max-width:400px){
  .fc-months{grid-template-columns:1fr}
}
</style>
<div class="fc-head">
  <h1><?php if($em===''): ?>Facturas<?php else: ?><a class="crumb" href="facturas.php">Facturas</a><span class="sep">›</span><?php if($tipo===''): ?><?= e($EMISORES[$em]) ?><?php else: ?><a class="crumb" href="facturas.php?em=<?= $em ?>"><?= e($EMISORES[$em]) ?></a><span class="sep">›</span><?php if($mes===''): ?><?= e($TIPONOM[$tipo]) ?><?php else: ?><a class="crumb" href="facturas.php?em=<?= $em ?>&tipo=<?= $tipo ?>"><?= e($TIPONOM[$tipo]) ?></a><span class="sep">›</span><?= e(mes_label($mes)) ?><?php endif; ?><?php endif; ?><?php endif; ?></h1>
  <?php if(can_edit()): ?>
    <?php if($em!=='' && $tipo!==''): ?><button class="btn ghost" onclick="upNew()"><?= ic('plus',16) ?> Subir factura</button><?php endif; ?>
    <?php if($tipo!=='gasto'): ?><a class="btn" href="facturas.php?new=1<?= $em?'&em='.$em:'' ?>"><?= ic('plus',16) ?> Nueva factura</a><?php endif; ?>
  <?php endif; ?>
</div>

<?php if($em!=='' && $tipo!=='' && can_edit()): ?>
<div id="upWrap" class="up-wrap">
  <form method="post" enctype="multipart/form-data" class="up-form">
    <input type="hidden" name="action" value="upload_doc"><input type="hidden" name="emisor" value="<?= $em ?>"><input type="hidden" name="tipo" value="<?= $tipo ?>"><input type="hidden" name="edit_id" id="upEditId" value="">
    <div class="upx">
      <div class="upx-prev">
        <div class="upx-prevbar"><span class="upx-fname" id="upxName">Sin archivo seleccionado</span><span class="upx-badge" id="upxBadge" style="display:none"></span></div>
        <div class="upx-stage" id="upPreview">
          <label for="upFile" class="upx-empty"><?= ic('link',30) ?><b>Arrastra o haz clic para subir</b><span>PDF o imagen · <?= e($TIPONOM[$tipo]) ?> de <?= e($EMISORES[$em]) ?></span></label>
        </div>
        <input type="file" name="file" id="upFile" accept=".pdf,.jpg,.jpeg,.png,.webp" onchange="upName()" style="display:none">
        <div class="upx-prevfoot"><button type="button" class="upx-pick" onclick="document.getElementById('upFile').click()"><?= ic('plus',14) ?> Elegir archivo</button><button type="button" class="upx-clear" id="upxClear" style="display:none" onclick="upClear()">Quitar</button></div>
      </div>
      <div class="upx-form">
        <div class="upx-f"><label>Concepto</label><input type="text" name="concepto" placeholder="<?= $tipo==='gasto'?'Hosting, gestoría…':'Servicio SEO…' ?>"></div>
        <div class="upx-f"><label><?= $tipo==='gasto'?'Proveedor':'Cliente' ?></label><input type="text" name="proveedor"></div>
        <div class="upx-row2">
          <div class="upx-f"><label>Importe (€)</label><input type="text" name="importe" placeholder="0,00"></div>
          <div class="upx-f"><label>Fecha</label><input type="text" class="dpick" data-iso="<?= date('Y-m-d') ?>" data-sync="#upFecha"><input type="hidden" name="fecha" id="upFecha" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <div class="upx-tgs">
          <label class="upx-tg"><input type="checkbox" name="efectivo"> Efectivo (pagado en B · sin IVA/IRPF)</label>
          <label class="upx-tg"><input type="checkbox" name="personal"> <?= $tipo==='gasto'?'Deducible — me lo desgravo (gasto del socio)':'Personal — no cuenta en contabilidad' ?></label>
        </div>
        <div class="upx-f" style="margin-top:2px"><label>Proyecto <span style="color:var(--muted);font-weight:400">· uso interno, para su rentabilidad</span></label><?php proj_combobox('', '', 'Sin proyecto — buscar o crear…'); ?></div>
        <div class="upx-foot"><button type="button" class="btn ghost sm" onclick="document.getElementById('upWrap').classList.remove('on')">Cancelar</button><button type="submit" class="btn sm"><?= ic('plus',15) ?> Guardar</button></div>
      </div>
    </div>
  </form>
</div>
<div id="upDrop"><div class="dz"><?= ic('link',34) ?><b>Suelta el archivo para subir la factura</b><span>PDF o imagen · <?= e($TIPONOM[$tipo]) ?> de <?= e($EMISORES[$em]) ?></span></div></div>
<style>
#upDrop{position:fixed;inset:0;z-index:600;background:rgba(17,19,24,.55);display:none;align-items:center;justify-content:center}
#upDrop.on{display:flex}
#upDrop .dz{background:#fff;border:2px dashed var(--accent);border-radius:18px;padding:40px 54px;text-align:center;color:var(--ink-strong);box-shadow:0 24px 60px rgba(0,0,0,.25)}
#upDrop .dz svg{width:34px;height:34px;color:var(--accent);margin-bottom:10px}
#upDrop .dz b{display:block;font-size:16px}
#upDrop .dz span{display:block;font-size:12.5px;color:var(--muted);margin-top:6px}
[data-theme=dark] #upDrop .dz{background-color:var(--card)}
</style>
<script>
var UP_EMPTY=(document.getElementById('upPreview')||{}).innerHTML||'';
function upName(){var f=document.getElementById('upFile');var pv=document.getElementById('upPreview');var nm=document.getElementById('upxName');var bd=document.getElementById('upxBadge');var cl=document.getElementById('upxClear');
  if(!f||!f.files||!f.files.length){return upClear();}
  var file=f.files[0];var ext=(file.name.split('.').pop()||'').toLowerCase();
  if(nm)nm.textContent=file.name;
  if(bd){bd.style.display='inline-block';bd.textContent=ext==='pdf'?'PDF':'Imagen';}
  if(cl)cl.style.display='inline-flex';
  var url=URL.createObjectURL(file);pvShow(url,ext,pv);}
function upClear(){var f=document.getElementById('upFile');var pv=document.getElementById('upPreview');var nm=document.getElementById('upxName');var bd=document.getElementById('upxBadge');var cl=document.getElementById('upxClear');
  if(f)f.value='';
  if(nm)nm.textContent='Sin archivo seleccionado';
  if(bd)bd.style.display='none';
  if(cl)cl.style.display='none';
  if(pv)pv.innerHTML=UP_EMPTY;}
(function(){var ov=document.getElementById('upDrop');var cnt=0;
  function hasFiles(e){return e.dataTransfer&&Array.prototype.indexOf.call(e.dataTransfer.types||[],'Files')>-1;}
  window.addEventListener('dragenter',function(e){if(!hasFiles(e))return;cnt++;ov.classList.add('on');});
  window.addEventListener('dragover',function(e){if(!hasFiles(e))return;e.preventDefault();ov.classList.add('on');});
  window.addEventListener('dragleave',function(e){if(!hasFiles(e))return;cnt--;if(cnt<=0){cnt=0;ov.classList.remove('on');}});
  window.addEventListener('drop',function(e){cnt=0;ov.classList.remove('on');if(!hasFiles(e))return;e.preventDefault();
    var files=e.dataTransfer.files;if(!files||!files.length)return;
    var inp=document.getElementById('upFile');try{var dt=new DataTransfer();dt.items.add(files[0]);inp.files=dt.files;}catch(_){}
    document.getElementById('upWrap').classList.add('on');upName();
    var imp=document.querySelector('#upWrap [name=importe]');if(imp)imp.focus();
    document.getElementById('upWrap').scrollIntoView({behavior:'smooth',block:'center'});
  });
})();
</script>
<?php endif; ?>

<?php if($em===''): /* NIVEL 1 · un hub por autónomo que factura */ ?>
<div class="fc-hubs">
  <?php /* La lista sale de Ajustes › Facturación. Al final se añaden, si los hay,
           los emisores dados de baja que aún tienen facturas: su histórico se
           sigue pudiendo consultar aunque ya no se les emita nada nuevo. */
        $hubs = $EMISORES;
        foreach($hubStats as $hk=>$hs) if(!isset($hubs[$hk])) $hubs[$hk] = $hk.' (dado de baja)';
        foreach($hubs as $hk=>$hn): $hs=$hubStats[$hk] ?? ['n'=>0,'cob'=>0,'pen'=>0]; ?>
  <a class="fc-hub" href="facturas.php?em=<?= urlencode($hk) ?>">
    <span class="go"><?= ic('chevron',20) ?></span>
    <span class="ico"><?= ic('file',34) ?></span>
    <div class="nm"><?= e($hn) ?></div>
    <div class="sub"><?= (int)$hs['n'] ?> factura<?= $hs['n']==1?'':'s' ?></div>
    <div class="stats">
      <div class="s"><div class="v"><?= eur($hs['cob']) ?></div><div class="k">Cobrado</div></div>
      <div class="s"><div class="v"><?= eur($hs['pen']) ?></div><div class="k">Pendiente</div></div>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<?php elseif($tipo===''): /* NIVEL 2 · Ingresos / Gastos */ ?>
<div class="fc-hubs">
  <?php foreach(['ingreso'=>'Ingresos','gasto'=>'Gastos'] as $tk=>$tn): ?>
  <a class="fc-hub" href="facturas.php?em=<?= $em ?>&tipo=<?= $tk ?>">
    <span class="go"><?= ic('chevron',20) ?></span>
    <span class="ico" style="background:<?= $tk==='gasto'?'#c0392b':'#12854a' ?>"><?= ic($tk==='gasto'?'euro':'file',30) ?></span>
    <div class="nm"><?= $tn ?></div>
    <div class="sub"><?= (int)$cntTipo[$tk] ?> documento<?= $cntTipo[$tk]==1?'':'s' ?></div>
    <div class="stats"><div class="s"><div class="v"><?= eur($totTipo[$tk]) ?></div><div class="k">Total</div></div></div>
  </a>
  <?php endforeach; ?>
</div>

<?php elseif($mes===''): /* NIVEL 3 · carpetas por mes */ ?>
<div class="fc-months">
  <?php if(!$meses): ?><div class="fc-empty" style="grid-column:1/-1;background:#fff;border:1px solid var(--line);border-radius:16px">Aún no hay <?= mb_strtolower($TIPONOM[$tipo]) ?> de <?= e($EMISORES[$em]) ?>.</div><?php endif; ?>
  <?php foreach($meses as $mk=>$ms): ?>
  <a class="fc-mon" href="facturas.php?em=<?= $em ?>&tipo=<?= $tipo ?>&mes=<?= $mk ?>">
    <span class="fold"><?= ic('folder',34) ?></span>
    <div class="mnm"><?= e(mes_label($mk)) ?></div>
    <div class="msub"><?= (int)$ms['n'] ?> doc<?= $ms['n']==1?'':'s' ?></div>
    <div class="mtot"><?= eur($ms['tot']) ?></div>
    <div class="mnet">Neto <?= eur($ms['neto']??$ms['tot']) ?></div>
  </a>
  <?php endforeach; ?>
</div>

<?php else: /* NIVEL 4 · lista del mes */ ?>
<div class="fc-tbl">
  <div class="fc-row h"><span>Documento</span><span>Detalle</span><span>Fecha</span><span class="r">Importe</span><span></span><span></span></div>
  <?php if(!$docsMes): ?><div class="fc-empty">No hay <?= mb_strtolower($TIPONOM[$tipo]) ?> en <?= e(mes_label($mes)) ?>.</div><?php endif; ?>
  <?php foreach($docsMes as $d): ?>
    <?php if($d['kind']==='inv'): $ev=$ESTADOS[$d['estado']]??$ESTADOS['borrador']; ?>
    <div class="fc-row" onclick="location.href='facturas.php?v=<?= (int)$d['id'] ?>'" <?= can_edit()?'oncontextmenu="return fcMenu(event,'.(int)$d['id'].','.htmlspecialchars(json_encode($d['sub']),ENT_QUOTES).')"':'' ?>>
      <span class="fc-num"><?= e($d['sub']) ?></span>
      <span class="fc-det"><span class="fc-av" style="background:<?= avatar_color($d['titulo']) ?>"><?= e(mb_strtoupper(mb_substr($d['titulo'],0,1))) ?></span><span class="fc-cn"><?= e($d['titulo']) ?></span><span class="fc-badge" style="background:<?= $ev[1] ?>18;color:<?= $ev[1] ?>"><span class="fc-bdot" style="background:<?= $ev[1] ?>"></span><?= e($ev[0]) ?></span></span>
      <span class="muted" style="font-size:12.5px"><?= e(date('d/m/Y',strtotime($d['fecha']))) ?></span>
      <span class="r" style="text-align:right;font-weight:600"><?= eur($d['importe']) ?></span>
      <span></span>
      <span style="text-align:right;color:var(--label)"><?= ic('chevron',15) ?></span>
    </div>
    <?php else: ?>
    <?php $upj=htmlspecialchars(json_encode(['id'=>(int)$d['id'],'concepto'=>($d['concepto']!==''?$d['concepto']:$d['titulo']),'prov'=>$d['sub'],'importe'=>(float)$d['importe'],'fecha'=>$d['fecha'],'efectivo'=>(int)$d['efectivo'],'personal'=>(int)$d['personal'],'file'=>$d['file'],'titulo'=>$d['titulo'],'proj_id'=>(int)($d['proj_id']??0),'proj_name'=>($d['proj_name']??'')]),ENT_QUOTES); $fx=!empty($d['file'])?strtolower(pathinfo($d['file'],PATHINFO_EXTENSION)):''; ?>
    <div class="fc-row fc-up" data-up="<?= $upj ?>"<?php if(!empty($d['file'])): ?> style="cursor:pointer" onclick="fcPreview('<?= e($d['file']) ?>',<?= htmlspecialchars(json_encode($d['titulo']),ENT_QUOTES) ?>)"<?php endif; ?> <?= can_edit()?'oncontextmenu="return fcMenuUp(event,'.(int)$d['id'].')"':'' ?>>
      <span class="fc-upkind"><span class="fc-upic"><?= ic('file',14) ?></span>Subida</span>
      <span class="fc-updet"><b><?= e($d['titulo']) ?></b><?php if(!empty($d['sub'])): ?><small><?= e($d['sub']) ?></small><?php endif; ?><?php if(!empty($d['efectivo'])): ?><span class="fc-tag ef">Efectivo</span><?php endif; ?><?php if(!empty($d['personal'])): ?><span class="fc-tag pe"><?= $tipo==='gasto'?'Deducible':'Personal' ?></span><?php endif; ?></span>
      <span class="muted" style="font-size:12.5px"><?= e(date('d/m/Y',strtotime($d['fecha']))) ?></span>
      <span class="r" style="text-align:right;font-weight:600"><?= eur($d['importe']) ?></span>
      <span><?php if(!empty($d['file'])): ?><span class="fc-ftype"><?= $fx==='pdf'?'PDF':'Imagen' ?></span><?php else: ?><span class="muted" style="font-size:11.5px">sin archivo</span><?php endif; ?></span>
      <span style="text-align:right"><?php if(can_edit()): ?><button type="button" class="fc-kebab" title="Opciones" onclick="event.stopPropagation();fcMenuUp(event,<?= (int)$d['id'] ?>)">⋯</button><?php endif; ?></span>
    </div>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if(can_edit()): ?>
<div class="ctxmenu" id="fcCtx"></div>
<form id="fcDelForm" method="post" style="display:none"><input type="hidden" name="action" value="del"><input type="hidden" name="id" id="fcDelId"></form>
<form id="upDelForm" method="post" style="display:none"><input type="hidden" name="action" value="del_doc"><input type="hidden" name="id"></form>
<script>
function fcSet(id,e){fetch('facturas.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=set_estado&id='+id+'&estado='+e}).then(function(){location.reload();});}
function fcMenu(e,id,num){e.preventDefault();var m=document.getElementById('fcCtx');m.innerHTML='';
  function it(html,fn,danger){var a=document.createElement('a');a.innerHTML=html;if(danger)a.className='danger';a.onclick=function(ev){ev.stopPropagation();m.classList.remove('on');fn();};return a;}
  m.appendChild(it('Ver / Imprimir',function(){location.href='facturas.php?v='+id;}));
  m.appendChild(it('Editar',function(){location.href='facturas.php?edit='+id;}));
  m.appendChild(it('Asignar proyecto…',function(){fcProject(id);}));
  m.appendChild(it('Duplicar',function(){var f=document.getElementById('fcDupForm');f.querySelector('[name=id]').value=id;f.submit();}));
  var s=document.createElement('div');s.className='sep';m.appendChild(s);
  m.appendChild(it('<span class="dot" style="background:#12a150"></span>Marcar pagada',function(){fcSet(id,'pagada');}));
  m.appendChild(it('<span class="dot" style="background:#3b82f6"></span>Marcar enviada',function(){fcSet(id,'enviada');}));
  var s2=document.createElement('div');s2.className='sep';m.appendChild(s2);
  m.appendChild(it('Borrar factura',function(){
    erpConfirm('Se borra también su apunte de contabilidad.',{titulo:'¿Borrar la factura '+num+'?',danger:true}).then(function(ok){if(!ok)return;
      document.getElementById('fcDelId').value=id;document.getElementById('fcDelForm').submit();});},true));
  m.style.left=Math.min(e.clientX,window.innerWidth-210)+'px';m.style.top=Math.min(e.clientY,window.innerHeight-280)+'px';m.classList.add('on');return false;}
document.addEventListener('click',function(e){if(!e.target.closest('#fcCtx')){var m=document.getElementById('fcCtx');if(m)m.classList.remove('on');}});
function fcMenuUp(e,id){e.preventDefault();e.stopPropagation();var row=e.target.closest('.fc-up');var d={};try{d=JSON.parse(row.getAttribute('data-up'));}catch(_){}
  var m=document.getElementById('fcCtx');m.innerHTML='';
  function it(html,fn,danger){var a=document.createElement('a');a.innerHTML=html;if(danger)a.className='danger';a.onclick=function(ev){ev.stopPropagation();m.classList.remove('on');fn();};return a;}
  m.appendChild(it('Editar',function(){upEdit(d);}));
  if(d.file){m.appendChild(it('Vista previa',function(){fcPreview(d.file,d.titulo);}));
    m.appendChild(it('Descargar',function(){var a=document.createElement('a');a.href=fcUrl(d.file)+'&dl=1';a.download='';document.body.appendChild(a);a.click();a.remove();}));}
  var s=document.createElement('div');s.className='sep';m.appendChild(s);
  m.appendChild(it('Borrar documento',function(){
    erpConfirm('También se quita de contabilidad.',{titulo:'¿Borrar este documento?',danger:true}).then(function(ok){if(!ok)return;
      var f=document.getElementById('upDelForm');f.querySelector('[name=id]').value=id;f.submit();});},true));
  m.style.left=Math.min(e.clientX,window.innerWidth-210)+'px';m.style.top=Math.min(e.clientY,window.innerHeight-210)+'px';m.classList.add('on');return false;}
function upEdit(d){var w=document.getElementById('upWrap');if(!w)return;
  var eid=document.getElementById('upEditId');if(eid)eid.value=d.id;
  var f=w.querySelector('[name=concepto]');if(f)f.value=d.concepto||'';
  var pr=w.querySelector('[name=proveedor]');if(pr)pr.value=d.prov||'';
  var im=w.querySelector('[name=importe]');if(im)im.value=(d.importe!=null?String(d.importe).replace('.',','):'');
  var ef=w.querySelector('[name=efectivo]');if(ef)ef.checked=!!d.efectivo;
  var pe=w.querySelector('[name=personal]');if(pe)pe.checked=!!d.personal;
  var hf=document.getElementById('upFecha');if(hf&&d.fecha)hf.value=d.fecha;
  var dp=w.querySelector('.dpick');if(dp&&d.fecha){dp.setAttribute('data-iso',d.fecha);var pp=(''+d.fecha).split('-');if(pp.length===3)dp.value=pp[2]+'/'+pp[1]+'/'+pp[0].slice(2);}
  var nm=document.getElementById('upxName');var bd=document.getElementById('upxBadge');var cl=document.getElementById('upxClear');var pv=document.getElementById('upPreview');
  if(d.file){var ext=((''+d.file).split('.').pop()||'').toLowerCase();if(nm)nm.textContent=d.file;if(bd){bd.style.display='inline-block';bd.textContent=ext==='pdf'?'PDF':'Imagen';}if(cl)cl.style.display='inline-flex';pvShow(fcUrl(d.file),ext,pv);}
  else{upClear();}
  upSetProj(w, d.proj_name||'', d.proj_id||'');
  w.classList.add('on');
  if(im)im.focus();
  w.scrollIntoView({behavior:'smooth',block:'center'});}
function upNew(){var w=document.getElementById('upWrap');if(!w)return;
  if(w.classList.contains('on')){w.classList.remove('on');return;}
  var eid=document.getElementById('upEditId');if(eid)eid.value='';
  var f=w.querySelector('[name=concepto]');if(f)f.value='';
  var pr=w.querySelector('[name=proveedor]');if(pr)pr.value='';
  var im=w.querySelector('[name=importe]');if(im)im.value='';
  var ef=w.querySelector('[name=efectivo]');if(ef)ef.checked=false;
  var pe=w.querySelector('[name=personal]');if(pe)pe.checked=false;
  upSetProj(w,'','');
  upClear();w.classList.add('on');}
/* Rellena/limpia el combobox de proyecto del formulario de subida. */
function upSetProj(w,name,id){var pcb=w.querySelector('.pcb[data-pcb]');if(!pcb)return;
  var pn=pcb.querySelector('.pcb-name'),pi=pcb.querySelector('.pcb-id'),pin=pcb.querySelector('.pcb-input'),pf=pcb.querySelector('.pcb-field');
  if(pn)pn.value=name||'';if(pi)pi.value=id?id:'';if(pin)pin.value=name||'';if(pf)pf.classList.toggle('has',!!name);}
</script>
<?php proj_combobox_assets(); ?>
<?php endif; ?>
<form id="fcDupForm" method="post" style="display:none"><input type="hidden" name="action" value="dup"><input type="hidden" name="id"></form>
<?php /* El bloque «Asignar proyecto» (modal con botón Guardar + formulario) solo se
         muestra a quien puede editar: el rol Solo lectura no debe ver un Guardar que el
         servidor bloquea (P-16). La acción se abre desde el menú contextual, ya vallado. */ ?>
<?php if(can_edit()): ?>
<?php $LP=[]; try{ foreach(db()->query('SELECT id,nombre,color FROM projects WHERE activo=1 ORDER BY nombre') as $rp) $LP[]=['id'=>(int)$rp['id'],'nombre'=>$rp['nombre'],'color'=>$rp['color']]; }catch(Exception $e){} ?>
<div id="projModal" onclick="if(event.target===this)this.classList.remove('on')"><div class="pm-in"><div class="pm-h">Asignar proyecto</div>
  <div class="pm-body">
    <?php proj_combobox('', '', 'Buscar o crear proyecto…'); ?>
    <div class="pm-foot"><button type="button" class="btn ghost sm" onclick="document.getElementById('projModal').classList.remove('on')">Cancelar</button><button type="button" class="btn sm" onclick="projSaveCombo()"><?= ic('check',15) ?> Guardar</button></div>
  </div>
</div></div>
<?php proj_combobox_assets(); ?>
<form id="projForm" method="post" style="display:none"><input type="hidden" name="action" value="set_project"><input type="hidden" name="id" id="pmId"><input type="hidden" name="project_name" id="pmName"><input type="hidden" name="project_id_sel" id="pmIdSel"><input type="hidden" name="ret" id="pmRet"></form>
<style>
#projModal{position:fixed;inset:0;z-index:720;background:rgba(17,19,24,.6);display:none;align-items:center;justify-content:center;padding:20px}
#projModal.on{display:flex}
#projModal .pm-in{background:#fff;border-radius:16px;width:100%;max-width:420px;box-shadow:0 30px 80px rgba(0,0,0,.4);overflow:hidden;animation:pvIn .3s cubic-bezier(.22,1,.36,1)}
#projModal .pm-h{padding:15px 20px;font-weight:700;font-size:15px;border-bottom:1px solid var(--line)}
#projModal .pm-in{overflow:visible}
#projModal .pm-body{padding:18px 20px}
#projModal .pm-foot{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}
#projModal #pmList{max-height:320px;overflow:auto;padding:8px}
#projModal .pm-opt{display:flex;align-items:center;gap:9px;width:100%;text-align:left;border:none;background:none;padding:10px 12px;border-radius:9px;cursor:pointer;font-size:13.5px;font-family:inherit;color:var(--ink)}
#projModal .pm-opt:hover{background:var(--soft)}
#projModal .pm-dot{width:9px;height:9px;border-radius:50%;flex:none}
#projModal .pm-new{display:flex;gap:8px;padding:12px 16px;border-top:1px solid var(--line)}
#projModal .pm-new input{flex:1;border:1px solid var(--line);border-radius:9px;padding:9px 11px;font-size:13px;font-family:inherit;outline:none}
#projModal .pm-new button{border:none;background:var(--ink-strong);color:#fff;border-radius:9px;padding:9px 14px;font-size:12.5px;font-weight:600;cursor:pointer}
[data-theme=dark] #projModal .pm-in{background-color:var(--card)}
[data-theme=dark] #projModal .pm-new input{background-color:var(--field);color:var(--ink)}
[data-theme=dark] #projModal .pm-new button{background-color:var(--rev);color:var(--rev-fg)}
</style>
<script>
/* Asignar proyecto a una factura (clic derecho → "Asignar proyecto…"). Usa el
   mismo combobox de búsqueda en servidor, con "Crear «…»" inline. */
function fcProject(id){document.getElementById('pmId').value=id;document.getElementById('pmRet').value=location.href;
  var modal=document.getElementById('projModal');var pcb=modal.querySelector('.pcb[data-pcb]');
  if(pcb){var n=pcb.querySelector('.pcb-name'),i=pcb.querySelector('.pcb-id'),inp=pcb.querySelector('.pcb-input'),f=pcb.querySelector('.pcb-field');
    if(n)n.value='';if(i)i.value='';if(inp)inp.value='';if(f)f.classList.remove('has');}
  modal.classList.add('on');
  setTimeout(function(){var inp=modal.querySelector('.pcb-input');if(inp)inp.focus();},30);}
function projSaveCombo(){var modal=document.getElementById('projModal');var pcb=modal.querySelector('.pcb[data-pcb]');
  document.getElementById('pmName').value=pcb?pcb.querySelector('.pcb-name').value:'';
  document.getElementById('pmIdSel').value=pcb?pcb.querySelector('.pcb-id').value:'';
  document.getElementById('projForm').submit();}
/* Compat: por si algo llama a projPick(nombre) directamente. */
function projPick(name){document.getElementById('pmName').value=name;document.getElementById('pmIdSel').value='';document.getElementById('projForm').submit();}
</script>
<?php endif; /* can_edit(): fin del bloque «Asignar proyecto» */ ?>
<div id="fcLbox" onclick="if(event.target===this)this.classList.remove('on')"><div class="lb-inner" id="fcLboxInner"></div></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" integrity="sha384-/1qUCSGwTur9vjf/z9lmu/eCUYbpOTgSjmpbMQZ1/CtX2v/WcAIKqRv+U1DUCG6e" crossorigin="anonymous"></script>
<script>/* El worker no admite integrity: lo carga pdf.js con new Worker(), y ahí el
   atributo no existe. Queda fuera de la protección de SRI. */if(window.pdfjsLib){pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';}</script>
<style>
#fcLbox{position:fixed;inset:0;z-index:700;background:rgba(17,19,24,.78);display:none;align-items:center;justify-content:center;padding:30px}
#fcLbox.on{display:flex;animation:lbFade .2s ease}
#fcLbox .lb-inner{max-width:900px;width:100%;max-height:92vh;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.4);display:flex;flex-direction:column}
#fcLbox .lb-bar{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--line);font-size:13px;font-weight:600;color:var(--ink-strong)}
#fcLbox .lb-bar .sp{flex:1}
#fcLbox .lb-bar a,#fcLbox .lb-bar button{border:none;background:var(--soft);border-radius:8px;padding:7px 12px;font-size:12.5px;font-weight:600;cursor:pointer;color:var(--ink);text-decoration:none}
#fcLbox .lb-body{overflow:auto;background:#f4f5f7;text-align:center}
#fcLbox .lb-body img{max-width:100%;display:block;margin:0 auto}
#fcLbox .lb-body iframe{width:100%;height:80vh;border:none;background:#fff}
.pdfcol{display:flex;flex-direction:column;align-items:center;gap:14px;width:100%;padding:6px 0}
.pdfpg{border-radius:8px;box-shadow:0 8px 26px rgba(0,0,0,.14);background:#fff;max-width:100%;animation:pvIn .4s cubic-bezier(.22,1,.36,1)}
.pdf-load{color:var(--muted);font-size:13px;padding:44px 20px;text-align:center;width:100%}
.upx-stage.has-doc{align-items:flex-start}
#fcLbox .lb-body .pdfcol{padding:18px}
#fcLbox .lb-inner{animation:lbPop .3s cubic-bezier(.22,1,.36,1)}
@keyframes lbPop{from{opacity:0;transform:translateY(20px) scale(.96)}to{opacity:1;transform:none}}
@keyframes lbFade{from{opacity:0}to{opacity:1}}
</style>
<script>
function pvShow(url,ext,c){if(!c)return;c.classList.remove('has-doc');if(ext==='pdf'){pdfRender(url,c);}else{c.innerHTML='<img src="'+url+'" alt="">';}}
function pdfRender(url,c){c.innerHTML='<div class="pdf-load">Cargando documento…</div>';
  if(!window.pdfjsLib){c.innerHTML='<iframe src="'+url+'"></iframe>';return;}
  var col=document.createElement('div');col.className='pdfcol';
  pdfjsLib.getDocument(url).promise.then(function(pdf){
    c.innerHTML='';c.classList.add('has-doc');c.appendChild(col);
    var n=Math.min(pdf.numPages,10);var chain=Promise.resolve();
    for(var i=1;i<=n;i++){(function(pg){chain=chain.then(function(){return pdf.getPage(pg).then(function(page){
      var vp=page.getViewport({scale:1});var tw=(c.clientWidth||560)-30;if(tw<220)tw=520;if(tw>960)tw=960;
      var scale=tw/vp.width;var dpr=window.devicePixelRatio||1;var rv=page.getViewport({scale:scale*dpr});
      var cv=document.createElement('canvas');cv.className='pdfpg';cv.width=rv.width;cv.height=rv.height;cv.style.width=tw+'px';cv.style.height=(rv.height/dpr)+'px';
      col.appendChild(cv);return page.render({canvasContext:cv.getContext('2d'),viewport:rv}).promise;
    });});})(i);}
    return chain;
  }).catch(function(){c.classList.remove('has-doc');c.innerHTML='<iframe src="'+url+'"></iframe>';});}
function fcUrl(file){ return '../archivo.php?d=facturas&f='+encodeURIComponent(String(file||'')); }
function fcPreview(file,name){var b=document.getElementById('fcLbox');var inner=document.getElementById('fcLboxInner');var url=fcUrl(file);
  var ext=(String(file).split('.').pop()||'').toLowerCase();
  inner.innerHTML='<div class="lb-bar"><span>'+escHtml(name||'Documento')+'</span><span class="sp"></span><a href="'+escHtml(url)+'" target="_blank">Abrir</a><a href="'+escHtml(url)+'&dl=1" download>Descargar</a><button onclick="document.getElementById(\'fcLbox\').classList.remove(\'on\')">Cerrar</button></div><div class="lb-body" id="lbBody"></div>';
  pvShow(url,ext,document.getElementById('lbBody'));
  b.classList.add('on');}
document.addEventListener('keydown',function(e){if(e.key==='Escape'){var b=document.getElementById('fcLbox');if(b)b.classList.remove('on');}});
</script>
<?php erp_foot(); ?>
