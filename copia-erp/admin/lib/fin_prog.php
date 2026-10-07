<?php
/* Programaciones de facturas: tabla + generación automática mes a mes.
   Se incluye desde programaciones.php y facturas.php. */

function prog_ensure(){
  static $done=false; if($done) return; $done=true;
  /* tablas base por si no se abrió facturas.php antes */
  db()->exec("CREATE TABLE IF NOT EXISTS invoices (id INT AUTO_INCREMENT PRIMARY KEY, numero VARCHAR(30), client_id INT DEFAULT NULL, cliente_nombre VARCHAR(200), cliente_nif VARCHAR(40) DEFAULT '', cliente_dir VARCHAR(300) DEFAULT '', cliente_email VARCHAR(160) DEFAULT '', fecha DATE, fecha_venc DATE DEFAULT NULL, estado VARCHAR(15) DEFAULT 'borrador', iva_pct DECIMAL(5,2) DEFAULT 21, irpf_pct DECIMAL(5,2) DEFAULT 0, notas TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  db()->exec("CREATE TABLE IF NOT EXISTS invoice_items (id INT AUTO_INCREMENT PRIMARY KEY, invoice_id INT NOT NULL, concepto VARCHAR(300), cantidad DECIMAL(10,2) DEFAULT 1, precio DECIMAL(12,2) DEFAULT 0, INDEX(invoice_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  db()->exec("CREATE TABLE IF NOT EXISTS accounting (id INT AUTO_INCREMENT PRIMARY KEY, fecha DATE, tipo VARCHAR(10) DEFAULT 'gasto', concepto VARCHAR(250) DEFAULT '', categoria VARCHAR(80) DEFAULT '', importe DECIMAL(12,2) DEFAULT 0, metodo VARCHAR(20) DEFAULT 'transferencia', legal TINYINT DEFAULT 1, ambito VARCHAR(15) DEFAULT 'empresa', deducible TINYINT DEFAULT 0, client_id INT DEFAULT NULL, notas VARCHAR(300) DEFAULT '', invoice_id INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  /* `personal` y `project_id` los INSERTA fin_sync_accounting(), pero antes solo
     los añadían facturas.php y proyectos.php. En una instalación limpia, si se
     abría Programaciones (o corría el cron) antes que Facturas, la tabla nacía
     sin esas columnas y la sincronización contable fallaba en silencio (P1-07).
     Aquí se garantizan siempre. */
  foreach(['personal'=>"TINYINT NOT NULL DEFAULT 0",'project_id'=>"INT DEFAULT NULL"] as $col=>$def){
    try{ if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='accounting' AND column_name='$col'")->fetchColumn()) db()->exec("ALTER TABLE accounting ADD COLUMN $col $def"); }catch(Exception $e){ error_log('prog_ensure accounting '.$col.': '.$e->getMessage()); }
  }
  foreach (['emisor'=>"VARCHAR(15) NOT NULL DEFAULT 'victor'",'efectivo'=>"TINYINT NOT NULL DEFAULT 0",'personal'=>"TINYINT NOT NULL DEFAULT 0",'periodo_ini'=>"DATE DEFAULT NULL",'periodo_fin'=>"DATE DEFAULT NULL",'cliente_tel'=>"VARCHAR(40) NOT NULL DEFAULT ''",'cond_pago'=>"VARCHAR(60) NOT NULL DEFAULT 'Contado'",'emisor_json'=>"TEXT"] as $col=>$def) {
    try { if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='$col'")->fetchColumn()) db()->exec("ALTER TABLE invoices ADD COLUMN $col $def"); } catch(Exception $e){}
  }
  db()->exec("CREATE TABLE IF NOT EXISTS invoice_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY, emisor VARCHAR(15) NOT NULL DEFAULT 'victor', client_id INT DEFAULT NULL,
    cliente_nombre VARCHAR(200) DEFAULT '', cliente_nif VARCHAR(40) DEFAULT '', cliente_dir VARCHAR(300) DEFAULT '', cliente_email VARCHAR(160) DEFAULT '', cliente_tel VARCHAR(40) DEFAULT '',
    lineas_json MEDIUMTEXT, iva_pct DECIMAL(5,2) DEFAULT 21, irpf_pct DECIMAL(5,2) DEFAULT 0, cond_pago VARCHAR(60) DEFAULT 'Contado',
    dia TINYINT DEFAULT 1, activo TINYINT DEFAULT 1, start_ym VARCHAR(7) DEFAULT '', last_ym VARCHAR(7) DEFAULT '', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  foreach(['serie'=>"VARCHAR(20) NOT NULL DEFAULT ''"] as $col=>$def){ try{ if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoice_schedules' AND column_name='$col'")->fetchColumn()) db()->exec("ALTER TABLE invoice_schedules ADD COLUMN $col $def"); }catch(Exception $e){} }
  try{ if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='fecha_pago'")->fetchColumn()) db()->exec("ALTER TABLE invoices ADD COLUMN fecha_pago DATE DEFAULT NULL"); }catch(Exception $e){}
}

/* ============================================================
   QUIÉN FACTURA (EMISORES)

   Hasta ahora «Víctor» y «Gabi» estaban escritos a mano en nueve archivos:
   facturas, programaciones, contabilidad, análisis, resumen mensual, el menú
   lateral, los ajustes y aquí. Entraba un tercer autónomo y había que tocar
   código. Ahora la lista vive en settings.emisores_lista y todo el ERP la lee
   de aquí.

   Tres cosas que no se pueden romper y por eso se hacen así:

   1. La CLAVE de un emisor (`victor`, `gavi`) es lo que está grabado en
      invoices.emisor, invoice_schedules.emisor y accounting.ambito, que son
      VARCHAR(15). Una clave no se cambia nunca una vez creada: el nombre
      visible sí, la clave no. Renombrar a «Gabriel» no toca ni una factura.
   2. El PREFIJO de serie (V-2026-001, G-2026-001) se guarda aparte, en
      emisor_<clave>_serie, en vez de deducirlo de la inicial del nombre. Si se
      dedujera, renombrar a alguien le cambiaría la numeración de las facturas
      del año, que es justo lo que no puede pasar en un documento legal.
   3. Un emisor con facturas, programaciones o apuntes contables NO se borra
      (fin_emisor_uso lo dice). Se puede renombrar, pero su histórico se queda.
   ============================================================ */

/* Los dos de siempre: es lo que hay antes de que nadie toque la lista, y lo que
   se usa si el JSON guardado estuviera corrupto. */
function fin_emisores_defecto(){ return [['k'=>'victor','nombre'=>'Víctor'],['k'=>'gavi','nombre'=>'Gabi']]; }

/* clave => nombre visible, en el orden en que se hayan puesto.
   Se cachea para no consultar settings en cada una de las cien veces que se
   pinta un nombre de emisor en una página. `$recargar` la vacía: lo usa
   fin_emisores_guardar() para que el resto de la petición vea ya la lista nueva. */
function fin_emisores($recargar=false){
  static $c=null;
  if($recargar){ $c=null; return []; }
  if($c!==null) return $c;
  /* get_setting también cachea por su cuenta, así que se lee en crudo. */
  $raw='';
  try{ $s=db()->prepare("SELECT valor FROM settings WHERE clave='emisores_lista'"); $s->execute(); $raw=(string)$s->fetchColumn(); }catch(Exception $e){}
  $arr = $raw!=='' ? json_decode($raw,true) : null;
  if(!is_array($arr) || !$arr) $arr = fin_emisores_defecto();
  $c=[];
  foreach($arr as $r){
    if(!is_array($r)) continue;
    $k=trim((string)($r['k']??'')); if($k==='') continue;
    $n=trim((string)($r['nombre']??''));
    $c[$k] = ($n!==''?$n:$k);
  }
  if(!$c) foreach(fin_emisores_defecto() as $r) $c[$r['k']]=$r['nombre'];
  return $c;
}

/* Normaliza cualquier valor que llegue de fuera (POST, query string, una fila
   antigua) a una clave que exista de verdad. Sustituye a los
   `($x==='gavi')?'gavi':'victor'` que había repartidos por todo el ERP y que
   convertían en «Víctor» cualquier cosa que no fuera «Gabi». */
function fin_emisor_ok($k, $def=null){
  $e=fin_emisores(); $k=(string)$k;
  if(isset($e[$k])) return $k;
  if($def!==null && isset($e[(string)$def])) return (string)$def;
  $ks=array_keys($e); return (string)($ks[0] ?? 'victor');
}

function fin_emisor_nombre($k){ $e=fin_emisores(); return $e[(string)$k] ?? (string)$k; }

/* Clave nueva a partir del nombre: minúsculas, sin tildes, sin espacios y como
   mucho 15 caracteres, que es lo que aceptan las columnas. Nunca repite una
   clave ya usada, ni siquiera la de un emisor borrado hace tiempo, para que dos
   personas distintas no compartan histórico. */
function fin_emisor_slug($nombre, $usadas=[]){
  $s = (string)$nombre;
  if(function_exists('iconv')){ $t=@iconv('UTF-8','ASCII//TRANSLIT',$s); if($t!==false) $s=$t; }
  $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/','', $s));
  if($s==='') $s='emisor';
  $s = substr($s,0,15);
  $base = substr($s,0,13); $i=2; $out=$s;
  while(in_array($out,$usadas,true)){ $out = substr($base,0,15-strlen((string)$i)).$i; $i++; if($i>99) { $out=substr('e'.substr((string)time(),-8),0,15); break; } }
  return $out;
}

/* Prefijo de serie por defecto de un emisor (la «V» de V-2026-001). Guardado,
   no deducido: ver el punto 2 de la cabecera. */
function fin_emisor_serie($k){
  $k = (string)$k;
  $v = function_exists('get_setting') ? trim((string)get_setting('emisor_'.$k.'_serie','')) : '';
  if($v!=='') return $v;
  /* Sin nada guardado: la inicial del nombre. Para los dos de siempre da
     exactamente la «V» y la «G» que ya llevan sus facturas. */
  $n = fin_emisor_nombre($k);
  if(function_exists('iconv')){ $t=@iconv('UTF-8','ASCII//TRANSLIT',$n); if($t!==false) $n=$t; }
  $n = preg_replace('/[^a-zA-Z0-9]/','',$n);
  return $n!=='' ? strtoupper(substr($n,0,1)) : 'F';
}

/* Cuánto histórico tiene un emisor. Si devuelve algo distinto de cero, no se
   puede borrar: hay facturas emitidas a su nombre. */
function fin_emisor_uso($k){
  $k=(string)$k; $u=['facturas'=>0,'programaciones'=>0,'apuntes'=>0];
  try{ $s=db()->prepare('SELECT COUNT(*) FROM invoices WHERE emisor=?');           $s->execute([$k]); $u['facturas']=(int)$s->fetchColumn(); }catch(Exception $e){}
  try{ $s=db()->prepare('SELECT COUNT(*) FROM invoice_schedules WHERE emisor=?');  $s->execute([$k]); $u['programaciones']=(int)$s->fetchColumn(); }catch(Exception $e){}
  try{ $s=db()->prepare('SELECT COUNT(*) FROM accounting WHERE ambito=?');         $s->execute([$k]); $u['apuntes']=(int)$s->fetchColumn(); }catch(Exception $e){}
  $u['total']=$u['facturas']+$u['programaciones']+$u['apuntes'];
  return $u;
}

/* Guarda la lista. Recibe [['k'=>clave o '' si es nuevo, 'nombre'=>...], …].
   Devuelve ['lista'=>clave=>nombre, 'nuevos'=>[claves creadas]]. No borra los
   datos (emisor_<k>_*) de quien desaparece de la lista: si se vuelve a añadir
   con la misma clave, sus datos siguen ahí. */
function fin_emisores_guardar(array $filas){
  $previas = array_keys(fin_emisores());
  $out=[]; $usadas=$previas; $nuevos=[];
  foreach($filas as $f){
    $nom = trim((string)($f['nombre'] ?? ''));
    if($nom==='') continue;
    $k = trim((string)($f['k'] ?? ''));
    if($k==='' || !in_array($k,$previas,true)){
      $k = fin_emisor_slug($nom,$usadas); $usadas[]=$k; $nuevos[]=$k;
    }
    if(isset($out[$k])) continue;               // clave repetida en el envío
    $out[$k]=$nom;
  }
  if(!$out) return ['lista'=>fin_emisores(),'nuevos'=>[]];   // no dejar el ERP sin nadie que facture
  $json=[]; foreach($out as $k=>$n) $json[]=['k'=>$k,'nombre'=>$n];
  db()->prepare("INSERT INTO settings (clave,valor) VALUES ('emisores_lista',?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")
      ->execute([json_encode($json, JSON_UNESCAPED_UNICODE)]);
  fin_emisores(true);   // tirar la caché: el resto de la petición ya ve la lista nueva
  /* Un emisor nuevo estrena prefijo de serie con la inicial de su nombre, y se
     graba ya: a partir de aquí ese prefijo es suyo aunque luego se le cambie el
     nombre. */
  foreach($nuevos as $k){
    $ini = fin_emisor_slug($out[$k]);
    $ini = strtoupper(substr($ini,0,1)) ?: 'F';
    try{ db()->prepare("INSERT INTO settings (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")->execute(['emisor_'.$k.'_serie',$ini]); }catch(Exception $e){}
  }
  return ['lista'=>$out,'nuevos'=>$nuevos];
}

/* ============================================================
   NUMERACIÓN DE FACTURAS
   Una factura no puede repetir número: es un documento legal. Antes se hacía
   «busca el número más alto y súmale uno», y eso falla en cuanto dos personas
   guardan a la vez — o simplemente cuando la generación automática se dispara
   al abrir Programaciones y Facturas en dos pestañas. Ahora cada serie tiene un
   contador que la base de datos incrementa de forma atómica.
   ============================================================ */
function fin_counters_ensure(){
  static $done=false; if($done) return; $done=true;
  try{
    db()->exec("CREATE TABLE IF NOT EXISTS invoice_counters (
      serie VARCHAR(60) NOT NULL PRIMARY KEY,
      ultimo INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }catch(Exception $e){}
  /* Segunda red: la propia columna rechaza un número repetido. Si la base ya
     arrastra duplicados el índice no se puede crear y se ignora sin más. */
  try{
    $hay=(int)db()->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='invoices' AND index_name='uq_invoices_numero'")->fetchColumn();
    if(!$hay) db()->exec("ALTER TABLE invoices ADD UNIQUE KEY uq_invoices_numero (numero)");
  }catch(Exception $e){}
}

/* Devuelve el siguiente número de una serie y lo reserva. */
function fin_next_numero($serie, $emisor='victor', $fecha=null){
  prog_ensure(); fin_counters_ensure();
  $emisor = fin_emisor_ok($emisor);
  $serie  = trim((string)$serie);
  $anio   = date('Y', $fecha ? strtotime($fecha) : time());
  /* Sin serie propia, el prefijo del emisor: se mantiene el formato de siempre
     (V-2026-001 / G-2026-001) porque fin_emisor_serie() devuelve para Víctor y
     Gabi exactamente la V y la G que ya llevan sus facturas. */
  $clave  = ($serie==='') ? (fin_emisor_serie($emisor).'-'.$anio.'-') : $serie;

  $n = 0;
  try{
    /* La primera vez el contador arranca desde el número más alto que ya exista,
       para no pisar las facturas antiguas. */
    $st=db()->prepare('SELECT ultimo FROM invoice_counters WHERE serie=?'); $st->execute([$clave]);
    if($st->fetchColumn()===false){
      $mx=0; $q=db()->prepare('SELECT numero FROM invoices WHERE numero LIKE ?'); $q->execute([$clave.'%']);
      foreach($q as $r){ if(preg_match('/(\d+)\s*$/',(string)$r['numero'],$m)){ $v=(int)$m[1]; if($v>$mx)$mx=$v; } }
      db()->prepare('INSERT IGNORE INTO invoice_counters (serie,ultimo) VALUES (?,?)')->execute([$clave,$mx]);
    }
    /* Incremento atómico: dos peticiones simultáneas reciben números distintos.
       (En SQLite: UPDATE + SELECT; el candado de escritura de la base serializa.) */
    db()->prepare('UPDATE invoice_counters SET ultimo = ultimo + 1 WHERE serie = ?')->execute([$clave]);
    $stN = db()->prepare('SELECT ultimo FROM invoice_counters WHERE serie = ?'); $stN->execute([$clave]);
    $n = (int)$stN->fetchColumn();
  }catch(Exception $e){ $n=0; }

  if($n<=0) $n=(int)date('His');   // último recurso: feo, pero nunca repetido
  return $clave.str_pad($n,3,'0',STR_PAD_LEFT);
}

/* ============================================================
   CONTABILIDAD
   La contabilidad es la caja: lo que ha entrado de verdad. Emitir una factura
   no es cobrarla, así que el ingreso se apunta SOLO cuando pasa a «pagada» y
   se retira si vuelve atrás. Antes se apuntaba al emitirla e inflaba la caja.
   ============================================================ */
function fin_sync_accounting($invoiceId){
  $invoiceId=(int)$invoiceId; if(!$invoiceId) return;
  try{
    $q=db()->prepare('SELECT * FROM invoices WHERE id=?'); $q->execute([$invoiceId]); $inv=$q->fetch();
    db()->prepare('DELETE FROM accounting WHERE invoice_id=?')->execute([$invoiceId]);
    if(!$inv || ($inv['estado']??'')!=='pagada') return;   // aún no ha entrado el dinero

    $b=db()->prepare('SELECT COALESCE(SUM(cantidad*precio),0) FROM invoice_items WHERE invoice_id=?'); $b->execute([$invoiceId]);
    $sub=(float)$b->fetchColumn();
    $total=$sub*(1+((float)($inv['iva_pct']??0))/100-((float)($inv['irpf_pct']??0))/100);
    $efectivo=(int)($inv['efectivo']??0);
    $emisor=fin_emisor_ok($inv['emisor']??'');
    /* Fecha de caja: el día del cobro si se conoce; si no, la de la factura. */
    $fecha=$inv['fecha_pago'] ?: $inv['fecha'];

    db()->prepare('INSERT INTO accounting (fecha,tipo,concepto,categoria,importe,metodo,legal,ambito,deducible,personal,project_id,client_id,invoice_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$fecha,'ingreso','Factura '.$inv['numero'].' · '.$inv['cliente_nombre'],'Cliente',$total,
                 $efectivo?'efectivo':'transferencia',$efectivo?0:1,$emisor,0,(int)($inv['personal']??0),
                 $inv['project_id']??null,$inv['client_id']??null,$invoiceId]);
  }catch(Exception $e){ error_log('fin_sync_accounting #'.$invoiceId.': '.$e->getMessage()); }
}

/* Limpieza única: retira de la caja los ingresos de facturas que nunca se
   llegaron a cobrar y que el sistema antiguo había apuntado al emitirlas. */
function fin_limpiar_ingresos_no_cobrados(){
  static $done=false; if($done) return; $done=true;
  try{
    $ya=db()->prepare("SELECT valor FROM settings WHERE clave='fin_limpieza_ingresos'"); $ya->execute();
    if($ya->fetchColumn()) return;
    db()->exec("DELETE a FROM accounting a JOIN invoices i ON i.id=a.invoice_id WHERE a.tipo='ingreso' AND i.estado<>'pagada'");
    db()->prepare("INSERT INTO settings (clave,valor) VALUES ('fin_limpieza_ingresos','1') ON DUPLICATE KEY UPDATE valor='1'")->execute();
  }catch(Exception $e){}
}

/* datos del autónomo desde settings (para el snapshot que se guarda en la factura) */
function fin_emisor_data($em){ $em=fin_emisor_ok($em); static $c=null; if($c===null){ $c=[]; try{ foreach(db()->query('SELECT clave,valor FROM settings') as $r) $c[$r['clave']]=$r['valor']; }catch(Exception $e){} }
  $g=function($k)use($c){ return $c['emisor_'.$k] ?? ''; };
  $n=$g($em.'_name'); $iva=$g($em.'_iva'); $irpf=$g($em.'_irpf'); $venc=$g($em.'_venc');
  /* Si no ha rellenado el nombre fiscal, se usa el nombre visible de la lista. */
  return ['key'=>$em,'name'=>$n!==''?$n:fin_emisor_nombre($em),'nif'=>$g($em.'_nif'),'dir'=>$g($em.'_dir'),'email'=>$g($em.'_email'),'phone'=>$g($em.'_phone'),'iban'=>$g($em.'_iban'),'banco'=>$g($em.'_banco'),'iva'=>($iva!==''?$iva:'21'),'irpf'=>($irpf!==''?$irpf:'0'),'venc'=>($venc!==''?$venc:'Contado')]; }

/* genera una factura concreta de una programación para el mes YYYY-MM */
function prog_gen_invoice($s,$ym){
  $dia=max(1,min(28,(int)$s['dia']));
  $fecha=$ym.'-'.str_pad($dia,2,'0',STR_PAD_LEFT);
  $emisor=fin_emisor_ok($s['emisor']??'');
  $serie=trim($s['serie']??'');
  $numero=fin_next_numero($serie,$emisor,$fecha);
  $lineas=json_decode($s['lineas_json'],true); if(!is_array($lineas))$lineas=[];
  $emjson=json_encode(fin_emisor_data($emisor), JSON_UNESCAPED_UNICODE);
  db()->prepare('INSERT INTO invoices (numero,emisor,efectivo,personal,client_id,cliente_nombre,cliente_nif,cliente_dir,cliente_email,cliente_tel,fecha,estado,iva_pct,irpf_pct,cond_pago,emisor_json,notas) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$numero,$emisor,0,0,$s['client_id']?:null,$s['cliente_nombre'],$s['cliente_nif'],$s['cliente_dir'],$s['cliente_email'],$s['cliente_tel'],$fecha,'enviada',$s['iva_pct'],$s['irpf_pct'],$s['cond_pago'],$emjson,'Generada por programación mensual']);
  $iid=(int)db()->lastInsertId();
  $ins=db()->prepare('INSERT INTO invoice_items (invoice_id,concepto,cantidad,precio) VALUES (?,?,?,?)');
  foreach($lineas as $l){ $c=trim($l['c']??''); if($c==='')continue; $q=(float)($l['q']??1); $p=(float)($l['p']??0); $ins->execute([$iid,$c,$q,$p]); }
  /* Nace como «enviada»: todavía no hay dinero en caja, así que no se apunta
     ningún ingreso. Lo hará fin_sync_accounting() cuando se marque pagada. */
  return $iid;
}

/* recorre las programaciones activas y genera lo que toque hasta el mes actual */
function prog_run(){
  prog_ensure();
  try{ $rows=db()->query("SELECT * FROM invoice_schedules WHERE activo=1")->fetchAll(); }catch(Exception $e){ return 0; }
  $cur=date('Y-m'); $today=(int)date('j'); $made=0;
  foreach($rows as $s){
    $ym = $s['last_ym']!=='' ? date('Y-m', strtotime($s['last_ym'].'-01 +1 month')) : ($s['start_ym']!==''?$s['start_ym']:$cur);
    $guard=0;
    while($ym<=$cur && $guard<60){
      $guard++;
      $isCurrent=($ym===$cur);
      if($isCurrent && $today < max(1,min(28,(int)$s['dia']))) break; // este mes aún no toca
      prog_gen_invoice($s,$ym); $made++;
      db()->prepare('UPDATE invoice_schedules SET last_ym=? WHERE id=?')->execute([$ym,$s['id']]);
      if($isCurrent) break;
      $ym=date('Y-m', strtotime($ym.'-01 +1 month'));
    }
  }
  return $made;
}
