<?php
/* CRM v1.3 — Fase 6: motor de seguimientos + digest diario.
   Incluido por automatizaciones.php y crm_followup_email.php. */
require_once __DIR__ . '/crm_lib.php';
require_once __DIR__ . '/marca.php';

/* Canales de acción del digest (orden de aparición). slug => [título, tipo_actividad] */
function crm_fu_channels(){ return [
  'llamar'  =>['LLAMAR HOY','llamada'],
  'whatsapp'=>['ESCRIBIR WHATSAPP','whatsapp'],
  'email'   =>['ENVIAR EMAIL','email'],
  'reunion' =>['REUNIONES','reunion'],
]; }

/* Secuencia por defecto tras enviar propuesta (días relativos + canal) */
function crm_fu_secuencia_propuesta(){ return [[2,'llamar','Llamar para confirmar recepción de la propuesta'],[5,'whatsapp','WhatsApp de seguimiento de la propuesta'],[9,'email','Email de último intento / cierre']]; }

/* ¿Ya existe una tarea PENDIENTE equivalente? (idempotencia)
   Antes contaba cualquier estado: en cuanto existía un seguimiento —aunque estuviera
   completado hace meses— no se generaba nunca otro para ese contacto (P2-08). Ahora
   solo cuenta los pendientes, así que al completar uno se puede generar el siguiente. */
function crm_fu_exists($contact_id,$deal_id,$canal,$ciclo){
  try{ $st=db()->prepare("SELECT COUNT(*) FROM follow_up_tasks WHERE contact_id=? AND (deal_id<=>?) AND canal=? AND ciclo=? AND estado='pendiente'");
       $st->execute([$contact_id,$deal_id,$canal,$ciclo]); return (int)$st->fetchColumn()>0; }catch(Exception $e){ return true; }
}
function crm_fu_add($contact_id,$deal_id,$canal,$desc,$fecha,$sec='auto',$ciclo=1){
  if(crm_fu_exists($contact_id,$deal_id,$canal,$ciclo)) return;
  try{ db()->prepare('INSERT INTO follow_up_tasks (contact_id,deal_id,tipo_accion,canal,descripcion,fecha_prevista,estado,secuencia_id,ciclo) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([$contact_id,$deal_id?:null,$canal,$canal,$desc,$fecha,'pendiente',$sec,$ciclo]); }catch(Exception $e){}
}

/* Genera tareas de seguimiento según reglas. Idempotente. */
function crm_fu_generate(){
  try{
    // A) Leads nuevos sin contactar => llamar hoy
    foreach(db()->query("SELECT id FROM contacts WHERE fase='lead_nuevo' AND fecha_ultimo_contacto IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $cid){
      crm_fu_add((int)$cid,null,'llamar','Primer contacto con el lead nuevo',date('Y-m-d'),'lead_nuevo',1);
    }
    // B) Propuestas enviadas => secuencia
    foreach(db()->query("SELECT d.id,d.contact_id,d.fecha_entrada_fase FROM deals d WHERE d.fase='propuesta'")->fetchAll() as $d){
      $base=$d['fecha_entrada_fase']?:date('Y-m-d');
      foreach(crm_fu_secuencia_propuesta() as $step){ [$dias,$canal,$desc]=$step;
        crm_fu_add((int)$d['contact_id'],(int)$d['id'],$canal,$desc,date('Y-m-d',strtotime($base.' +'.$dias.' days')),'propuesta',1);
      }
    }
    // C) Negocios perdidos con fecha de reactivación alcanzada => reactivar
    foreach(db()->query("SELECT id,contact_id FROM deals WHERE fase='perdido' AND fecha_reactivacion IS NOT NULL AND fecha_reactivacion<=CURDATE()")->fetchAll() as $d){
      crm_fu_add((int)$d['contact_id'],(int)$d['id'],'llamar','Reactivar: revisar si es buen momento ahora',date('Y-m-d'),'reactivacion',1);
    }
  }catch(Exception $e){}
}

/* Tareas pendientes para hoy (o vencidas), agrupadas por canal. */
function crm_fu_today($incVencidas=true){
  $out=[]; foreach(array_keys(crm_fu_channels()) as $c) $out[$c]=[];
  try{
    $cmp = $incVencidas ? '<=CURDATE()' : '=CURDATE()';
    $sql="SELECT f.*, c.nombre c_nombre, c.empresa c_empresa, c.telefono, c.whatsapp, c.email, c.sector
          FROM follow_up_tasks f JOIN contacts c ON c.id=f.contact_id
          WHERE f.estado='pendiente' AND f.fecha_prevista $cmp ORDER BY f.fecha_prevista, f.id";
    foreach(db()->query($sql) as $r){ $c=$r['canal']; if(!isset($out[$c]))$out[$c]=[]; $out[$c][]=$r; }
  }catch(Exception $e){}
  return $out;
}

/* HTML del digest diario (para email o vista previa). Diseño minimalista y con
   estilos EN LÍNEA (obligatorio para que se vea bien en los clientes de correo). */
function crm_fu_digest_html($grouped){
  $CH=crm_fu_channels(); $hoy=date('d/m/Y');
  $dias=['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
  $diaNom=$dias[(int)date('N')-1] ?? '';
  $total=0; foreach($grouped as $g) $total+=count($g);
  $colors=['llamar'=>'#3b82f6','whatsapp'=>'#1a9d5b','email'=>'#e0872a','reunion'=>'#d1a000'];
  $h='<div style="font-family:Inter,-apple-system,Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;color:#22262c">';
  /* Cabecera */
  $h.='<div style="margin-bottom:24px">'
     .'<div style="font-size:11.5px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:#a4a9b1">'.htmlspecialchars($diaNom).' · '.$hoy.'</div>'
     .'<div style="font-size:23px;font-weight:750;letter-spacing:-.4px;margin-top:5px">Seguimientos de hoy</div>'
     .'<div style="font-size:14px;color:#6b7079;margin-top:4px">'.($total===0?'Nada pendiente por ahora.':($total.' acción'.($total==1?'':'es').' por hacer.')).'</div>'
     .'</div>';
  if($total===0){
    $h.='<div style="background:#f0faf4;border:1px solid #cdeede;border-radius:14px;padding:28px;text-align:center;color:#1a9d5b;font-size:15px;font-weight:650">✓ No hay seguimientos pendientes hoy.</div>';
  }
  foreach($CH as $slug=>$meta){ $items=$grouped[$slug]??[]; if(!$items) continue;
    $color=$colors[$slug]??'#94a3b8';
    /* Cabecera de sección: punto de color + nombre del canal + contador */
    $h.='<div style="margin:0 0 9px">'
       .'<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:'.$color.';vertical-align:middle;margin-right:9px"></span>'
       .'<span style="font-size:12px;font-weight:800;letter-spacing:.5px;color:'.$color.';vertical-align:middle">'.$meta[0].'</span>'
       .'<span style="font-size:12px;font-weight:700;color:#c0c4cb;margin-left:8px;vertical-align:middle">'.count($items).'</span>'
       .'</div>';
    /* Tarjeta con filas separadas por líneas finas (más limpio que cajas sueltas) */
    $h.='<div style="border:1px solid #ececee;border-radius:14px;overflow:hidden;margin-bottom:22px">';
    $i=0;
    foreach($items as $it){ $sub=[]; if($it['c_empresa'])$sub[]=$it['c_empresa']; if($it['sector'])$sub[]=$it['sector'];
      $contacto = $slug==='whatsapp'?($it['whatsapp']?:$it['telefono']):($slug==='email'?$it['email']:$it['telefono']);
      $bt = $i>0 ? 'border-top:1px solid #f4f4f5;' : '';
      $h.='<div style="'.$bt.'padding:13px 16px">'
         .'<div style="font-weight:650;font-size:14.5px;color:#22262c">'.htmlspecialchars($it['c_nombre']).($contacto?' <span style="color:#a4a9b1;font-weight:500;font-size:12.5px">'.htmlspecialchars($contacto).'</span>':'').'</div>'
         .($sub?'<div style="color:#a4a9b1;font-size:12px;margin-top:2px">'.htmlspecialchars(implode(' · ',$sub)).'</div>':'')
         .'<div style="font-size:13px;color:#3c4149;margin-top:5px;line-height:1.4">'.htmlspecialchars($it['descripcion']).'</div>'
         .'</div>';
      $i++;
    }
    $h.='</div>';
  }
  $h.='<div style="border-top:1px solid #f2f2f3;margin-top:6px;padding-top:15px;color:#c0c4cb;font-size:11px">'.htmlspecialchars(marca_agencia()['name'],ENT_QUOTES).' · CRM · resumen automático diario</div>';
  $h.='</div>';
  return $h;
}

/* Envía (o intenta) el digest diario una vez por día laborable. Devuelve estado. */
function crm_fu_send_daily($force=false){
  try{
    $dow=(int)date('N'); // 1=lun..7=dom
    if(!$force && $dow>=6) return ['sent'=>false,'reason'=>'fin_de_semana'];
    // ¿ya se ejecutó hoy?
    $ya=(int)db()->query("SELECT COUNT(*) FROM email_log WHERE DATE(fecha_ejecucion)=CURDATE()")->fetchColumn();
    if($ya>0 && !$force) return ['sent'=>false,'reason'=>'ya_enviado'];
    crm_fu_generate();
    $grouped=crm_fu_today(true); $n=0; foreach($grouped as $g) $n+=count($g);
    $html=crm_fu_digest_html($grouped);
    /* Destinatarios: los dueños. La tabla `admins` no guarda email, así que el
       resumen no se manda por correo (no hay a dónde): se ENTREGA como notificación
       interna del ERP, que es lo que sí llega. Antes se construía el HTML y no se
       enviaba a ningún sitio (P2-07). El `ref` con la fecha evita duplicar el aviso
       si el ciclo se repite el mismo día (notif_add usa INSERT IGNORE). */
    $dest=db()->query("SELECT id, username FROM admins WHERE role='owner'")->fetchAll();
    $enviado=0;
    if($n>0 && function_exists('notif_add')){
      $hoy=date('Y-m-d');
      foreach($dest as $o){
        notif_add((int)$o['id'],'bell','Resumen de seguimientos de hoy',
          $n.' seguimiento(s) que tocan hoy: llamadas, WhatsApp y emails del CRM.',
          'automatizaciones.php','digest:'.$hoy.':'.(int)$o['id'],'','Sistema');
        $enviado++;
      }
    }
    db()->prepare('INSERT INTO email_log (enviado,n_acciones,destinatarios) VALUES (?,?,?)')
       ->execute([$enviado,$n,implode(',',array_map(fn($o)=>$o['username'],$dest))]);
    return ['sent'=>$enviado>0,'acciones'=>$n,'reason'=>$enviado>0?'ok':($n>0?'sin_dest':'nada_hoy')];
  }catch(Exception $e){ return ['sent'=>false,'reason'=>'error']; }
}
