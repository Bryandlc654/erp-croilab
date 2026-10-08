<?php
/* ============================================================================
   google_metrics.php — El ERP lee Search Console + GA4 POR SÍ MISMO y llena las
   métricas del portal. SIN Claude, SIN n8n, SIN créditos: es PHP normal que corre
   en el cron del servidor.

   Autenticación: cuenta de servicio de Google (la misma que ya usas, `croilab-gsc`).
   El JSON de la clave lo pega el dueño en Ajustes (setting `google_sa_json`) o se deja
   en un fichero cuya ruta se guarda en `google_sa_path`. La IA NO maneja esa clave.

   Mapeo a los campos del portal (met_json):
     · vi  = clics de Google (Search Console)
     · ap  = apariciones/impresiones (Search Console)
     · ctr = CTR % (Search Console)
     · ll  = evento de GA4 de "llamadas"    (nombre configurable, def. phone_call)
     · wa  = evento de GA4 de "WhatsApp"     (nombre configurable, def. whatsapp_click)
     · fo  = evento de GA4 de "formularios"  (nombre configurable, def. generate_lead)

   Por cliente hacen falta dos datos (en su ficha):
     · gsc_site_url      p.ej. "https://laplayasurfhouse.com/" o "sc-domain:dodox.es"
     · ga4_property_id   p.ej. "313888031"  (solo el número, sin "properties/")
   ============================================================================ */

if (!function_exists('gm_setting')) {
  function gm_setting($k, $def=''){ try{ $v=get_setting($k); return ($v===null||$v==='')?$def:$v; }catch(Exception $e){ return $def; } }
}

/* Asegura las columnas por cliente (patrón DDL en caliente del ERP). */
function gm_ensure_schema(){ if (croilab_esquema_gestionado()) return;   /* el esquema lo crean las migraciones */
  foreach(['gsc_site_url'=>"VARCHAR(255) DEFAULT NULL",'ga4_property_id'=>"VARCHAR(40) DEFAULT NULL",'met_sync_at'=>"DATETIME DEFAULT NULL",
           'ga4_ev_ll'=>"VARCHAR(255) DEFAULT NULL",'ga4_ev_wa'=>"VARCHAR(255) DEFAULT NULL",'ga4_ev_fo'=>"VARCHAR(255) DEFAULT NULL"] as $col=>$def){
    try{ if(!db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='$col'")->fetchColumn())
           db()->exec("ALTER TABLE clients ADD COLUMN $col $def"); }catch(Exception $e){}
  }
}

/* --- Conexión por OAuth (la vía buena: NO usa "claves de cuenta de servicio", que la
       organización de Croilab tiene bloqueadas por seguridad). El dueño crea un
       «ID de cliente de OAuth» en Google, pega aquí Client ID + Secreto y pulsa
       «Conectar con Google» una vez. Google devuelve un permiso permanente
       (refresh token) que se guarda y ya sirve para siempre. --- */
/* El secreto y el refresh token van cifrados en la bóveda (propósito «gmet»,
   migración 0051 y Croilab\Google\GoogleOAuth). Se leen siempre por aquí:
   boveda_valor() también entiende lo que quedara en claro y lo cifra al leerlo. */
require_once __DIR__ . '/boveda.php';
function gm_secreto($k){ return trim((string)boveda_valor($k, 'gmet')); }
function gm_oauth_cfg(){ $id=gm_setting('google_oauth_client_id',''); $sec=gm_secreto('google_oauth_client_secret'); return ($id!==''&&$sec!=='')?['id'=>$id,'secret'=>$sec]:null; }
function gm_configurada(){ return gm_oauth_cfg()!==null && gm_secreto('google_oauth_refresh_token')!==''; }

/* Dirección de retorno que hay que registrar EXACTA en Google (según cómo se abra el ERP). */
function gm_redirect_uri(){
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['SERVER_PORT']??'')==='443') || (($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
  $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
  /* Dirección de retorno propia y estable (gmet_callback.php). Antes apuntaba a
     metricas.php; se cambió al mover la conexión a Integraciones. Hay que tenerla
     registrada en Google Cloud (Credenciales → el cliente OAuth → URIs autorizados). */
  return ($https?'https':'http').'://'.$host.'/admin/gmet_callback.php';
}
/* URL a la que se manda al usuario para dar permiso. */
function gm_oauth_url(){
  $c=gm_oauth_cfg(); if(!$c) return '';
  $scope='https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly';
  return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id'=>$c['id'],'redirect_uri'=>gm_redirect_uri(),'response_type'=>'code','scope'=>$scope,'access_type'=>'offline','prompt'=>'consent','include_granted_scopes'=>'true']);
}
/* Cambia el "code" que devuelve Google por el permiso permanente (refresh token). */
function gm_oauth_exchange($code){
  $c=gm_oauth_cfg(); if(!$c) return 'Faltan el ID de cliente y el secreto.';
  list($st,$body)=gm_http_post('https://oauth2.googleapis.com/token', http_build_query(['code'=>$code,'client_id'=>$c['id'],'client_secret'=>$c['secret'],'redirect_uri'=>gm_redirect_uri(),'grant_type'=>'authorization_code']), ['Content-Type: application/x-www-form-urlencoded']);
  $j=json_decode($body,true);
  if($st===200 && !empty($j['refresh_token'])){ if(!boveda_guardar('google_oauth_refresh_token',$j['refresh_token'],'gmet')) return 'No se ha podido guardar el permiso cifrado.'; return true; }
  if($st===200){ return 'Google no dio el permiso permanente. Vuelve a pulsar «Conectar» y acepta todo.'; }
  return 'Error de Google ('.$st.'): '.($j['error_description']??$j['error']??$body);
}
if(!function_exists('gm_setting_save')){ function gm_setting_save($k,$v){ db()->prepare('INSERT INTO settings (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')->execute([$k,$v]); } }

/* --- POST HTTP (curl si está, si no stream). Devuelve [status, body]. --- */
function gm_http_post($url, $body, $headers=[]){
  if(function_exists('curl_init')){
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,
      CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>10]);
    $res=curl_exec($ch); $st=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return [$st, (string)$res];
  }
  $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'timeout'=>25,'ignore_errors'=>true]]);
  $res=@file_get_contents($url,false,$ctx); $st=0;
  if(isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/',$http_response_header[0],$m)) $st=(int)$m[1];
  return [$st, (string)$res];
}

function gm_b64url($s){ return rtrim(strtr(base64_encode($s),'+/','-_'),'='); }

/* --- Token de acceso: se pide con el permiso permanente (refresh token). Cachea 1 vez. --- */
function gm_access_token(){
  static $cache=null; if($cache!==null) return $cache?:null;
  $cache=false;
  $c=gm_oauth_cfg(); $rt=gm_secreto('google_oauth_refresh_token'); if(!$c || $rt==='') return null;
  list($st,$body)=gm_http_post('https://oauth2.googleapis.com/token',
    http_build_query(['client_id'=>$c['id'],'client_secret'=>$c['secret'],'refresh_token'=>$rt,'grant_type'=>'refresh_token']),
    ['Content-Type: application/x-www-form-urlencoded']);
  $j=json_decode($body,true);
  if($st===200 && !empty($j['access_token'])){ $cache=$j['access_token']; return $cache; }
  /* Se registra solo el código de estado y el tipo de error, NO el cuerpo entero:
     la respuesta de Google puede contener material sensible del token. */
  error_log('gm_access_token(refresh) estado '.$st.' error='.(is_array($j)&&isset($j['error'])?$j['error']:'desconocido'));
  return null;
}

/* --- Search Console: totales de un rango. Devuelve [clicks, impressions, ctr%]. --- */
function gm_sc_totales($token, $site, $start, $end){
  $url='https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($site).'/searchAnalytics/query';
  list($st,$body)=gm_http_post($url, json_encode(['startDate'=>$start,'endDate'=>$end,'dimensions'=>[]]),
    ['Authorization: Bearer '.$token,'Content-Type: application/json']);
  $j=json_decode($body,true);
  if($st!==200){ error_log('gm_sc '.$site.' '.$st.': '.$body); return null; }
  if(empty($j['rows'])) return ['clicks'=>0,'impressions'=>0,'ctr'=>0.0];
  $r=$j['rows'][0];
  return ['clicks'=>(int)round($r['clicks']??0),'impressions'=>(int)round($r['impressions']??0),
          'ctr'=>round(($r['ctr']??0)*100,2)];
}

/* --- GA4: cuenta de UNO O VARIOS eventos (separados por comas) en un rango. Suma todos. --- */
function gm_ga4_evento($token, $propertyId, $eventNames, $start, $end){
  $names=array_values(array_filter(array_map('trim', explode(',', (string)$eventNames)), function($s){ return $s!==''; }));
  if(!$names) return 0;
  $url='https://analyticsdata.googleapis.com/v1beta/properties/'.rawurlencode($propertyId).':runReport';
  $payload=['dateRanges'=>[['startDate'=>$start,'endDate'=>$end]],
            'metrics'=>[['name'=>'eventCount']],
            'dimensionFilter'=>['filter'=>['fieldName'=>'eventName','inListFilter'=>['values'=>$names]]]];
  list($st,$body)=gm_http_post($url, json_encode($payload),
    ['Authorization: Bearer '.$token,'Content-Type: application/json']);
  $j=json_decode($body,true);
  if($st!==200){ error_log('gm_ga4 '.$propertyId.' '.$st.': '.$body); return 0; }
  if(empty($j['rows'])) return 0;
  return (int)round($j['rows'][0]['metricValues'][0]['value']??0);
}

/* --- GA4: reparto de SESIONES por canal (de dónde viene el tráfico) en un rango.
       Devuelve ['Organic Search'=>N,'Direct'=>N,...] ordenado, o [] si no hay datos. --- */
function gm_ga4_canales($token, $propertyId, $start, $end){
  $url='https://analyticsdata.googleapis.com/v1beta/properties/'.rawurlencode($propertyId).':runReport';
  $payload=['dateRanges'=>[['startDate'=>$start,'endDate'=>$end]],
            'dimensions'=>[['name'=>'sessionDefaultChannelGroup']],
            'metrics'=>[['name'=>'sessions']],
            'orderBys'=>[['metric'=>['metricName'=>'sessions'],'desc'=>true]],
            'limit'=>12];
  list($st,$body)=gm_http_post($url, json_encode($payload),
    ['Authorization: Bearer '.$token,'Content-Type: application/json']);
  $j=json_decode($body,true);
  if($st!==200){ error_log('gm_ga4_src '.$propertyId.' '.$st.': '.$body); return []; }
  if(empty($j['rows'])) return [];
  $out=[];
  foreach($j['rows'] as $r){
    $canal=(string)($r['dimensionValues'][0]['value']??''); if($canal==='') $canal='Otros';
    $out[$canal]=(int)round($r['metricValues'][0]['value']??0);
  }
  return $out;
}

/* --- GA4: sesiones por PAÍS (para el mapa del mundo). Devuelve ['ES'=>657,'US'=>12,...]
       con código ISO alpha-2 en mayúsculas (countryId), o [] si no hay datos. --- */
function gm_ga4_paises($token, $propertyId, $start, $end){
  $url='https://analyticsdata.googleapis.com/v1beta/properties/'.rawurlencode($propertyId).':runReport';
  $payload=['dateRanges'=>[['startDate'=>$start,'endDate'=>$end]],
            'dimensions'=>[['name'=>'countryId']],
            'metrics'=>[['name'=>'sessions']],
            'orderBys'=>[['metric'=>['metricName'=>'sessions'],'desc'=>true]],
            'limit'=>250];
  list($st,$body)=gm_http_post($url, json_encode($payload),
    ['Authorization: Bearer '.$token,'Content-Type: application/json']);
  $j=json_decode($body,true);
  if($st!==200){ error_log('gm_ga4_geo '.$propertyId.' '.$st.': '.$body); return []; }
  if(empty($j['rows'])) return [];
  $out=[];
  foreach($j['rows'] as $r){
    $code=strtoupper((string)($r['dimensionValues'][0]['value']??'')); if($code===''||$code==='(NOT SET)') continue;
    $out[$code]=(int)round($r['metricValues'][0]['value']??0);
  }
  return $out;
}

/* --- GA4: lista los EVENTOS de una propiedad (últimos 90 días), para que el equipo
       elija cuáles son llamadas/WhatsApp/formularios en vez de teclear el nombre.
       Devuelve [['name'=>'phone_call','n'=>123], ...] ordenado por volumen. --- */
function gm_ga4_lista_eventos($token, $propertyId){
  $url='https://analyticsdata.googleapis.com/v1beta/properties/'.rawurlencode($propertyId).':runReport';
  $payload=['dateRanges'=>[['startDate'=>'90daysAgo','endDate'=>'today']],
            'dimensions'=>[['name'=>'eventName']],
            'metrics'=>[['name'=>'eventCount']],
            'orderBys'=>[['metric'=>['metricName'=>'eventCount'],'desc'=>true]],
            'limit'=>100];
  list($st,$body)=gm_http_post($url, json_encode($payload), ['Authorization: Bearer '.$token,'Content-Type: application/json']);
  $j=json_decode($body,true);
  if($st!==200){ error_log('gm_ga4_events '.$propertyId.' '.$st.': '.$body); return []; }
  if(empty($j['rows'])) return [];
  $out=[]; foreach($j['rows'] as $r){ $nm=(string)($r['dimensionValues'][0]['value']??''); if($nm!=='') $out[]=['name'=>$nm,'n'=>(int)round($r['metricValues'][0]['value']??0)]; }
  return $out;
}

/* Nombres de mes en español para las claves de met_json (deben cuadrar con tareas_json). */
function gm_mes_nombre($ym){
  $meses=[1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
  $m=(int)substr($ym,5,2); return $meses[$m]??$ym;
}

/* --- Sincroniza UN cliente para una lista de meses "YYYY-MM". Fusiona en met_json:
       actualiza vi/ap/ctr (Search Console) y ll/wa/fo (GA4) sin borrar lo demás. --- */
function gm_sync_client($clientId, $months, &$msg=null){
  $clientId=(int)$clientId;
  $token=gm_access_token(); if(!$token){ $msg='Sin token (¿clave de cuenta de servicio puesta?).'; return false; }
  $q=db()->prepare('SELECT gsc_site_url, ga4_property_id, ga4_ev_ll, ga4_ev_wa, ga4_ev_fo, met_json FROM clients WHERE id=?'); $q->execute([$clientId]); $cl=$q->fetch();
  if(!$cl){ $msg='Cliente no encontrado.'; return false; }
  $site=trim((string)($cl['gsc_site_url']??'')); $prop=trim((string)($cl['ga4_property_id']??''));
  $met=[]; if(!empty($cl['met_json'])){ $tmp=json_decode($cl['met_json'],true); if(is_array($tmp)) $met=$tmp; }
  /* Nombres de evento POR CLIENTE (cada web de GA4 los llama distinto). Si el
     cliente no los tiene puestos, se usan los nombres globales por defecto. */
  $evLl = trim((string)($cl['ga4_ev_ll']??''))!=='' ? $cl['ga4_ev_ll'] : gm_setting('ga4_event_ll','phone_call');
  $evWa = trim((string)($cl['ga4_ev_wa']??''))!=='' ? $cl['ga4_ev_wa'] : gm_setting('ga4_event_wa','whatsapp_click');
  $evFo = trim((string)($cl['ga4_ev_fo']??''))!=='' ? $cl['ga4_ev_fo'] : gm_setting('ga4_event_fo','generate_lead');
  $tocados=0; $ultimo='';
  foreach($months as $ym){
    if(!preg_match('/^\d{4}-\d{2}$/',$ym)) continue;
    $start=$ym.'-01'; $end=date('Y-m-t', strtotime($start));
    $nombre=gm_mes_nombre($ym);
    $e = isset($met[$nombre]) && is_array($met[$nombre]) ? $met[$nombre] : [];
    // Search Console (visibilidad)
    if($site!==''){ $sc=gm_sc_totales($token,$site,$start,$end); if($sc){ $e['vi']=$sc['clicks']; $e['ap']=$sc['impressions']; $e['ctr']=$sc['ctr']; } }
    // GA4 (conversiones)
    if($prop!==''){ $e['ll']=gm_ga4_evento($token,$prop,$evLl,$start,$end);
                    $e['wa']=gm_ga4_evento($token,$prop,$evWa,$start,$end);
                    $e['fo']=gm_ga4_evento($token,$prop,$evFo,$start,$end);
                    // De dónde viene el tráfico (canales). No se borra si no hay datos.
                    $src=gm_ga4_canales($token,$prop,$start,$end); if($src) $e['src']=$src;
                    // Países (para el mapa del mundo).
                    $geo=gm_ga4_paises($token,$prop,$start,$end); if($geo) $e['geo']=$geo; }
    // Defaults por si faltara alguno (para no romper el portal)
    foreach(['ll','wa','fo','vi','ap','ctr'] as $k) if(!isset($e[$k])) $e[$k]=0;
    $met[$nombre]=$e; $tocados++; $ultimo=$nombre;
  }
  if($tocados===0){ $msg='Nada que sincronizar.'; return false; }
  db()->prepare('UPDATE clients SET met_json=?, met_sync_at=NOW() WHERE id=?')->execute([json_encode($met, JSON_UNESCAPED_UNICODE), $clientId]);
  if($ultimo!=='') db()->prepare("UPDATE clients SET actual=? WHERE id=? AND (actual IS NULL OR actual='')")->execute([$ultimo,$clientId]);
  $msg='OK · '.$tocados.' mes(es).'; return true;
}

/* --- Sincroniza TODOS los clientes con web/propiedad configurada. Lo llama el cron. --- */
function gm_sync_all($months=null){
  gm_ensure_schema();
  if($months===null){ $months=[date('Y-m'), date('Y-m', strtotime('first day of last month'))]; }
  $out=['ok'=>0,'fail'=>0,'detalle'=>[]];
  foreach(db()->query("SELECT id,name FROM clients WHERE (gsc_site_url IS NOT NULL AND gsc_site_url<>'') OR (ga4_property_id IS NOT NULL AND ga4_property_id<>'')") as $c){
    $m=''; $ok=gm_sync_client((int)$c['id'],$months,$m); $out[$ok?'ok':'fail']++; $out['detalle'][]=$c['name'].': '.$m;
  }
  gm_setting_save('gm_auto_day', date('Y-m-d'));   // marca que ya se ha traído hoy
  return $out;
}

/* Refresco DIARIO automático, pensado para llamarse al final de cada página del ERP
   (erp_foot). Se salta si ya se hizo hoy o si no está conectado. Y solo trabaja cuando
   puede CERRAR la respuesta antes (fastcgi/litespeed): así la página ya está en el
   navegador y traer los datos de Google no hace esperar a nadie. Donde eso no exista,
   no bloquea: lo cubre el cron (cron_metricas.php) o el botón «Actualizar ahora». */
function gm_auto_daily(){
  if (gm_setting('gm_auto_day','') === date('Y-m-d')) return;   // ya hecho hoy
  if (!gm_configurada()) return;                                 // sin conexión, nada
  $puedeCerrar = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
  if (!$puedeCerrar) return;                                     // no bloquear al usuario
  gm_setting_save('gm_auto_day', date('Y-m-d'));                 // marca antes de tardar (evita duplicados)
  @ignore_user_abort(true);
  if (function_exists('session_write_close')) @session_write_close();
  if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
  elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
  @set_time_limit(300);
  try { gm_sync_all(); } catch (\Throwable $e) { error_log('gm_auto_daily: '.$e->getMessage()); }
}
