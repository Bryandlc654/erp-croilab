<?php
/* CRM v1.3 — librería común: esquema + constantes compartidas.
   Nomenclatura obligatoria: "Embudo de venta" (fase_embudo). Nunca "ciclo de vida". */

function crm_origenes(){ return ['Outreach','Referido','Google Ads','Meta Ads','Web/formulario','Evento','LinkedIn','Otro']; }
function crm_servicios(){ return ['Web','SEO','Meta Ads','Automatización','Mantenimiento','Otro']; }

/* Tipos de comentario (1.4). Solo los cuatro de interacción reinician el contador. */
function crm_comment_tipos(){ return [
  'nota'=>['Nota', false],
  'llamada'=>['Llamada', true],
  'whatsapp'=>['WhatsApp', true],
  'email'=>['Email', true],
  'reunion'=>['Reunión', true],
]; }

/* Motivos de pérdida (3.7) => meses de reactivación (5.2 fase 4). null = nunca */
function crm_motivos_perdida(){ return [
  'precio'=>['Precio', 3],
  'timing'=>['Timing / no es el momento', 3],
  'competencia'=>['Se ha ido con la competencia', 6],
  'sin_respuesta'=>['Sin respuesta', 6],
  'no_cualificado'=>['No cualificado', null],
  'otro'=>['Otro', 6],
]; }

/* Fases del embudo de venta (3.2) — de la tabla pipeline_stages */
/* $force=true relee de la base y refresca la caché: necesario cuando en la misma
   petición se reordenan o renombran etapas y luego se vuelven a pintar, para no
   mostrar la copia vieja de la `static` (P2-13). */
function crm_stages($force=false){
  static $s=null; if($s!==null && !$force) return $s;
  $s=[];
  try{ foreach(db()->query('SELECT * FROM pipeline_stages ORDER BY orden,id') as $r){ $s[$r['slug']]=$r; } }catch(Exception $e){}
  return $s;
}

/* Sectores (lista cerrada y editable — settings JSON) */
function crm_sectors(){
  try{ $st=db()->prepare('SELECT valor FROM settings WHERE clave=?'); $st->execute(['crm_sectors']); $v=$st->fetchColumn();
       $a=json_decode((string)$v,true); if(is_array($a)&&$a) return $a; }catch(Exception $e){}
  return ['Restauración','Ecommerce','Servicios','Salud','Inmobiliaria','Formación','Otro'];
}

/* Un ajuste del CRM (settings). */
function crm_set($k,$def=null){ try{ $st=db()->prepare('SELECT valor FROM settings WHERE clave=?'); $st->execute([$k]); $v=$st->fetchColumn(); return $v!==false?$v:$def; }catch(Exception $e){ return $def; } }
function crm_set_save($k,$v){ try{ db()->prepare('INSERT INTO settings (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')->execute([$k,$v]); }catch(Exception $e){} }

/* Registra una actividad automática (nota de arquitectura: toda acción escribe en activities). */
function crm_activity($contact_id,$deal_id,$tipo,$desc){
  try{ db()->prepare('INSERT INTO activities (contact_id,deal_id,tipo,descripcion,fecha) VALUES (?,?,?,?,NOW())')->execute([$contact_id?:null,$deal_id?:null,$tipo,$desc]); }catch(Exception $e){}
}

/* Reuniones del CRM con su resultado (agendada / realizada / no_show / cancelada). */
function crm_meetings_ensure(){ static $ok=false; if($ok) return; try{ db()->exec("CREATE TABLE IF NOT EXISTS crm_meetings (id INT AUTO_INCREMENT PRIMARY KEY, contact_id INT NOT NULL, fecha DATE, hora VARCHAR(10) DEFAULT '', titulo VARCHAR(200) DEFAULT '', estado VARCHAR(20) DEFAULT 'agendada', notas TEXT, notas_doc TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY(contact_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  /* Tablas ya creadas antes de existir la columna: la añadimos si falta (resumen/notas de Gemini). */
  try{ db()->exec("ALTER TABLE crm_meetings ADD COLUMN notas_doc TEXT"); }catch(Exception $e){}
  $ok=true; }
function crm_meetings($contact_id){ crm_meetings_ensure(); try{ $q=db()->prepare('SELECT * FROM crm_meetings WHERE contact_id=? ORDER BY fecha DESC, hora DESC, id DESC'); $q->execute([(int)$contact_id]); return $q->fetchAll(); }catch(Exception $e){ return []; } }

/* Sincroniza la columna "Última actualización" del contacto con el último comentario. */
function crm_sync_ultima($contact_id){
  try{ $st=db()->prepare('SELECT contenido FROM comments WHERE contact_id=? ORDER BY fecha DESC, id DESC LIMIT 1'); $st->execute([$contact_id]);
    $c=$st->fetchColumn(); $c=$c!==false?mb_substr(preg_replace('/\s+/u',' ',trim((string)$c)),0,80):null;
    db()->prepare('UPDATE contacts SET ultima_actualizacion=? WHERE id=?')->execute([$c,$contact_id]);
  }catch(Exception $e){}
}

/* Pone la fase del CONTACTO al día a partir de sus negocios.
   El problema que resuelve: contacto y negocio tenían cada uno su propia fase y
   nadie las juntaba, así que se movía la tarjeta en el embudo y en la lista del
   CRM el contacto seguía apareciendo como «Lead nuevo» para siempre.

   El criterio, en orden:
     1. Si tiene algún negocio abierto, manda el más avanzado de ellos.
     2. Si no queda ninguno abierto pero hay uno ganado, el contacto es cliente.
     3. Si solo hay perdidos, el contacto es perdido.
     4. Si no tiene negocios, no se toca: ahí manda lo que ponga a mano el equipo.
   Devuelve la fase que ha quedado, o null si no ha cambiado nada. */
function crm_sync_fase_contacto($contact_id){
  $contact_id=(int)$contact_id; if(!$contact_id) return null;
  try{
    $STG = crm_stages(); if(!$STG) return null;
    $st=db()->prepare('SELECT fase FROM deals WHERE contact_id=? AND archivado=0'); $st->execute([$contact_id]);
    $fases=$st->fetchAll(PDO::FETCH_COLUMN);
    if(!$fases) return null;                     // sin negocios: manda la mano

    $mejorAbierta=null; $ordenMejor=-1; $hayGanada=false; $hayPerdida=false;
    foreach($fases as $f){
      $s=$STG[$f]??null; if(!$s) continue;
      $tipo=$s['tipo']??'abierta';
      if($tipo==='ganada'){ $hayGanada=true; continue; }
      if($tipo==='perdida'){ $hayPerdida=true; continue; }
      if($tipo==='pausa') continue;              // en pausa no arrastra al contacto
      $o=(int)($s['orden']??0);
      if($o>$ordenMejor){ $ordenMejor=$o; $mejorAbierta=$f; }
    }

    $nueva=$mejorAbierta;
    if($nueva===null && $hayGanada)  foreach($STG as $sl=>$s){ if(($s['tipo']??'')==='ganada'){ $nueva=$sl; break; } }
    if($nueva===null && $hayPerdida) foreach($STG as $sl=>$s){ if(($s['tipo']??'')==='perdida'){ $nueva=$sl; break; } }
    if($nueva===null) return null;

    $act=db()->prepare('SELECT fase FROM contacts WHERE id=?'); $act->execute([$contact_id]);
    if($act->fetchColumn()===$nueva) return null;
    db()->prepare('UPDATE contacts SET fase=? WHERE id=?')->execute([$nueva,$contact_id]);
    return $nueva;
  }catch(Exception $e){ return null; }
}

/* Todas las etiquetas (crm_tags). */
function crm_all_tags(){ try{ return db()->query('SELECT * FROM crm_tags ORDER BY nombre')->fetchAll(); }catch(Exception $e){ return []; } }
/* Paleta por defecto para etiquetas nuevas. */
function crm_tag_colors(){ return ['#2563eb','#0f7a3d','#c2410c','#a16207','#b91c1c','#7c3aed','#0e7490','#be185d','#475569','#0369a1']; }

/* Crea/actualiza el esquema del CRM. Idempotente. */
function ensure_crm_schema(){
  static $done=false; if($done) return; $done=true;
  try{
    db()->exec("CREATE TABLE IF NOT EXISTS contacts (
      id INT AUTO_INCREMENT PRIMARY KEY,
      nombre VARCHAR(200) NOT NULL, empresa VARCHAR(200) NULL, sector VARCHAR(80) NULL,
      email VARCHAR(160) NULL, telefono VARCHAR(60) NULL, whatsapp VARCHAR(60) NULL,
      linkedin VARCHAR(200) NULL, web VARCHAR(200) NULL, origen_lead VARCHAR(40) NULL,
      servicio_json VARCHAR(255) NULL, valor DECIMAL(12,2) NULL,
      fase VARCHAR(40) NOT NULL DEFAULT 'lead_nuevo', proxima_accion VARCHAR(255) NULL, fecha_prox DATE NULL,
      propietario_id INT NULL, foto_url VARCHAR(255) NULL, ultima_actualizacion VARCHAR(255) NULL,
      fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP, fecha_ultimo_contacto DATE NULL,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX(sector), INDEX(fase), INDEX(propietario_id), INDEX(origen_lead)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS pipeline_stages (
      id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(80) NOT NULL, slug VARCHAR(40) NOT NULL,
      orden INT NOT NULL DEFAULT 0, probabilidad INT NOT NULL DEFAULT 0, tipo VARCHAR(20) NOT NULL DEFAULT 'abierta',
      color VARCHAR(20) NULL, dias_alerta_estancamiento INT NOT NULL DEFAULT 0, UNIQUE KEY uq_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS billing_data (
      id INT AUTO_INCREMENT PRIMARY KEY, contact_id INT NOT NULL,
      razon_social VARCHAR(200) NULL, cif VARCHAR(40) NULL, direccion VARCHAR(200) NULL,
      cp VARCHAR(15) NULL, ciudad VARCHAR(100) NULL, provincia VARCHAR(100) NULL, pais VARCHAR(80) NULL,
      email_facturacion VARCHAR(160) NULL, iban VARCHAR(40) NULL, UNIQUE KEY uq_c (contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS deals (
      id INT AUTO_INCREMENT PRIMARY KEY, contact_id INT NOT NULL, nombre VARCHAR(200) NULL,
      valor DECIMAL(12,2) NULL, servicio VARCHAR(80) NULL, fase VARCHAR(40) NOT NULL DEFAULT 'lead_nuevo',
      probabilidad INT NOT NULL DEFAULT 0, fecha_cierre_prevista DATE NULL, fecha_entrada_fase DATE NULL,
      fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP, fecha_cierre_real DATE NULL,
      motivo_perdida VARCHAR(40) NULL, motivo_perdida_txt VARCHAR(255) NULL, fecha_reactivacion DATE NULL,
      propietario_id INT NULL, orden INT NOT NULL DEFAULT 0, archivado TINYINT NOT NULL DEFAULT 0, fecha_archivado DATE NULL,
      INDEX(contact_id), INDEX(fase), INDEX(archivado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS comments (
      id INT AUTO_INCREMENT PRIMARY KEY, contact_id INT NOT NULL, autor_id INT NULL,
      tipo VARCHAR(20) NOT NULL DEFAULT 'nota', contenido MEDIUMTEXT NULL, menciones VARCHAR(255) NULL,
      fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS activities (
      id INT AUTO_INCREMENT PRIMARY KEY, contact_id INT NULL, deal_id INT NULL,
      tipo VARCHAR(40) NOT NULL, descripcion VARCHAR(255) NULL, fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(contact_id), INDEX(deal_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS lists (
      id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(160) NOT NULL, descripcion VARCHAR(255) NULL,
      tipo VARCHAR(20) NOT NULL DEFAULT 'activa', condiciones MEDIUMTEXT NULL,
      fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP, fecha_congelado DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS list_members (
      list_id INT NOT NULL, contact_id INT NOT NULL, PRIMARY KEY (list_id,contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS proposals (
      id INT AUTO_INCREMENT PRIMARY KEY, contact_id INT NOT NULL, nombre VARCHAR(200) NULL,
      importe DECIMAL(12,2) NULL, estado VARCHAR(20) NOT NULL DEFAULT 'enviada',
      fecha_envio DATE NULL, url_archivo VARCHAR(400) NULL, INDEX(contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS attachments (
      id INT AUTO_INCREMENT PRIMARY KEY, contact_id INT NOT NULL, nombre VARCHAR(255) NULL,
      url VARCHAR(400) NULL, tipo VARCHAR(60) NULL, fecha_subida TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_tags (
      id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(80) NOT NULL, color VARCHAR(20) NULL, UNIQUE KEY uq_n (nombre)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS deal_tags (
      deal_id INT NOT NULL, tag_id INT NOT NULL, PRIMARY KEY (deal_id,tag_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS contact_tags (
      contact_id INT NOT NULL, tag_id INT NOT NULL, PRIMARY KEY (contact_id,tag_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS saved_views (
      id INT AUTO_INCREMENT PRIMARY KEY, usuario_id INT NULL, nombre VARCHAR(120) NOT NULL,
      modulo VARCHAR(20) NOT NULL, filtros MEDIUMTEXT NULL, fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS follow_up_tasks (
      id INT AUTO_INCREMENT PRIMARY KEY, contact_id INT NOT NULL, deal_id INT NULL,
      tipo_accion VARCHAR(40) NULL, canal VARCHAR(20) NULL, descripcion VARCHAR(255) NULL,
      fecha_prevista DATE NULL, estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
      secuencia_id VARCHAR(20) NULL, ciclo INT NOT NULL DEFAULT 1, INDEX(contact_id), INDEX(fecha_prevista)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS email_log (
      id INT AUTO_INCREMENT PRIMARY KEY, fecha_ejecucion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      enviado TINYINT NOT NULL DEFAULT 0, n_acciones INT NOT NULL DEFAULT 0, destinatarios VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Semilla de las 9 fases por defecto (3.2 / 3.2.1)
    if((int)db()->query('SELECT COUNT(*) FROM pipeline_stages')->fetchColumn()===0){
      $seed=[
        ['Lead nuevo','lead_nuevo',1,5,'abierta','#64748b'],
        ['Onboarding','onboarding',2,15,'abierta','#2563eb'],
        ['Onboarding hecho','onboarding_hecho',3,30,'abierta','#1d4ed8'],
        ['Propuesta enviada','propuesta',4,50,'abierta','#a16207'],
        ['Negociación','negociacion',5,70,'abierta','#c2410c'],
        ['Contrato firmado','contrato',6,90,'abierta','#0f7a3d'],
        ['Cerrado ganado','ganado',7,100,'ganada','#047857'],
        ['Cerrado perdido','perdido',8,0,'perdida','#b91c1c'],
        ['En pausa','pausa',9,0,'pausa','#64748b'],
      ];
      $ins=db()->prepare('INSERT INTO pipeline_stages (nombre,slug,orden,probabilidad,tipo,color) VALUES (?,?,?,?,?,?)');
      foreach($seed as $r) $ins->execute($r);
    }
  }catch(Exception $e){}
}

/* Render de menciones @usuario en comentarios */
function pf_mentions($s){
  $s=e($s); $map=$GLOBALS['CRM_MENTION']??[];
  return preg_replace_callback('/@([\p{L}0-9_.\-]+)/u',function($m) use($map){ $k=mb_strtolower($m[1]); if(isset($map[$k])) return '<span class="pf-mention">@'.e($map[$k]).'</span>'; return '@'.e($m[1]); },$s);
}
