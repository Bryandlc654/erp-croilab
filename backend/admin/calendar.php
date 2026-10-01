<?php
/* Calendario del ERP: vistas Mes / Semana / Día, con las tareas (por fecha límite)
   y, si el usuario ha conectado Google, sus eventos (ver, crear, editar, borrar). */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/gcal.php';
require_once __DIR__ . '/lib/logos.php';

$me = current_admin(); $meId = (int)$me['id'];

/* ---- Acciones sobre eventos de Google (crear / editar / borrar) ---- */
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array(($_POST['action']??''), ['gcal_new','gcal_edit','gcal_del','gcal_move'], true)) {
  header('Content-Type: application/json');
  if (!gcal_connected($meId)) { echo json_encode(['ok'=>0,'msg'=>'No estás conectado a Google Calendar.']); exit; }
  $a=$_POST['action'];
  $o=['titulo'=>$_POST['titulo']??'','fecha'=>$_POST['fecha']??'','hora'=>$_POST['hora']??'','hora_fin'=>$_POST['hora_fin']??'','invitados'=>$_POST['invitados']??'','meet'=>($_POST['meet']??'')==='1','gemini'=>($_POST['gemini']??'')==='1','location'=>$_POST['location']??'','descripcion'=>$_POST['descripcion']??'','recur'=>$_POST['recur']??'','recordar'=>$_POST['recordar']??'','notificar'=>!empty($_POST['notificar']),'erp_meeting'=>$_POST['erp_meeting']??''];
  if ($a==='gcal_new')  list($ok,$msg)=gcal_create_event($meId,$o);
  elseif ($a==='gcal_edit') list($ok,$msg)=gcal_update_event($meId,(string)($_POST['id']??''),$o);
  elseif ($a==='gcal_move') list($ok,$msg)=gcal_move_event($meId,(string)($_POST['id']??''),(string)($_POST['fecha']??''),(string)($_POST['hora']??''),(string)($_POST['hora_fin']??''));
  else list($ok,$msg)=gcal_delete_event($meId,(string)($_POST['id']??''));
  echo json_encode(['ok'=>$ok?1:0,'msg'=>$msg]); exit;
}

$ESTADOS = ['pendiente'=>['En espera','#b0b4bb'],'en proceso'=>['En proceso','#3b82f6'],'atemporal'=>['Atemporal','#e0a000'],'completada'=>['Completada','#12a150']];
$MESES = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$DOW = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];

/* ---- Vista y fecha de anclaje ---- */
$view = $_GET['view'] ?? 'mes'; if(!in_array($view,['mes','semana','dia','agenda'],true)) $view='mes';
$anchor = $_GET['d'] ?? '';
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$anchor)){
  $anchor = (isset($_GET['ym']) && preg_match('/^\d{4}-\d{2}$/',$_GET['ym'])) ? $_GET['ym'].'-01' : date('Y-m-d');
}
$aT = strtotime($anchor);
$todayIso = date('Y-m-d');

/* Rango a cargar + navegación + título según la vista. */
if ($view==='mes') {
  $year=(int)date('Y',$aT); $mon=(int)date('n',$aT);
  $rangeStart=sprintf('%04d-%02d-01',$year,$mon);
  $rangeEnd=date('Y-m-t',$aT);
  $prevD=date('Y-m-01',strtotime($rangeStart.' -1 month'));
  $nextD=date('Y-m-01',strtotime($rangeStart.' +1 month'));
  $titulo=$MESES[$mon].' '.$year;
} elseif ($view==='semana') {
  $wd=((int)date('N',$aT)+6)%7; // 0=Lunes
  $rangeStart=date('Y-m-d',strtotime($anchor.' -'.$wd.' days'));
  $rangeEnd=date('Y-m-d',strtotime($rangeStart.' +6 days'));
  $prevD=date('Y-m-d',strtotime($rangeStart.' -7 days'));
  $nextD=date('Y-m-d',strtotime($rangeStart.' +7 days'));
  $ini=strtotime($rangeStart); $fin=strtotime($rangeEnd);
  $titulo=(int)date('j',$ini).' '.($MESES[(int)date('n',$ini)]).' – '.(int)date('j',$fin).' '.$MESES[(int)date('n',$fin)].' '.date('Y',$fin);
} elseif ($view==='agenda') {
  $rangeStart=date('Y-m-d',$aT);
  $rangeEnd=date('Y-m-d',strtotime($anchor.' +29 days'));
  $prevD=date('Y-m-d',strtotime($anchor.' -30 days'));
  $nextD=date('Y-m-d',strtotime($anchor.' +30 days'));
  $ini=strtotime($rangeStart); $fin=strtotime($rangeEnd);
  $titulo='Agenda · '.(int)date('j',$ini).' '.$MESES[(int)date('n',$ini)].' – '.(int)date('j',$fin).' '.$MESES[(int)date('n',$fin)];
} else { // dia
  $rangeStart=$rangeEnd=date('Y-m-d',$aT);
  $prevD=date('Y-m-d',strtotime($anchor.' -1 day'));
  $nextD=date('Y-m-d',strtotime($anchor.' +1 day'));
  $titulo=$DOW[((int)date('N',$aT)+6)%7].' '.(int)date('j',$aT).' de '.$MESES[(int)date('n',$aT)].' '.date('Y',$aT);
}

/* Festivos nacionales de España (fijos + Viernes Santo). */
function cal_festivos($year){ $f=[ "$year-01-01"=>'Año Nuevo',"$year-01-06"=>'Reyes',"$year-05-01"=>'Día del Trabajo',"$year-08-15"=>'Asunción',"$year-10-12"=>'Fiesta Nacional',"$year-11-01"=>'Todos los Santos',"$year-12-06"=>'Constitución',"$year-12-08"=>'Inmaculada',"$year-12-25"=>'Navidad' ]; if(function_exists('easter_date')){ $e=easter_date($year); $f[date('Y-m-d',strtotime('-2 days',$e))]='Viernes Santo'; } return $f; }
$festivos=[]; foreach(range((int)date('Y',strtotime($rangeStart)),(int)date('Y',strtotime($rangeEnd))) as $yy){ $festivos+=cal_festivos($yy); }

/* ---- Tareas del rango (por fecha límite) + asignados ---- */
$byDay = [];
$st = db()->prepare("SELECT t.id,t.titulo,t.estado,t.due_date,t.responsable_id,c.name cname FROM tasks t JOIN clients c ON c.id=t.client_id WHERE t.due_date BETWEEN ? AND ? ORDER BY t.due_date, t.id");
$st->execute([$rangeStart,$rangeEnd]);
$calIds=[];
foreach ($st as $r) { $byDay[substr($r['due_date'],0,10)][] = $r; $calIds[]=(int)$r['id']; }
$respMap=[]; foreach(db()->query('SELECT id,username FROM admins') as $ra){ $respMap[(int)$ra['id']]=$ra['username']; }
$calAsg=[];
if($calIds){ task_asignados_ensure(); $calIds=array_values(array_unique($calIds)); $in=implode(',',array_fill(0,count($calIds),'?'));
  try{ $qa=db()->prepare("SELECT task_id,admin_id FROM task_assignees WHERE task_id IN ($in) ORDER BY orden,admin_id"); $qa->execute($calIds); foreach($qa as $ra){ $calAsg[(int)$ra['task_id']][]=(int)$ra['admin_id']; } }catch(Exception $e){} }
function cal_asg_ids($ev,$calAsg,$respMap){ $ids=$calAsg[(int)$ev['id']]??[]; if(!$ids && !empty($ev['responsable_id'])) $ids=[(int)$ev['responsable_id']]; return array_values(array_filter($ids,fn($i)=>isset($respMap[$i]))); }

/* ---- Eventos de Google del rango (mío + compañeros seleccionados) ---- */
$gcConfigured = gcal_configured();
$gcConnected  = gcal_connected($meId);
$gcRevoked    = $gcConnected && gcal_revoked($meId);

/* Compañeros con su Google conectado (para superponer su agenda). */
$teamConnected = [];
foreach ($respMap as $aid=>$uname){ if((int)$aid!==$meId && gcal_connected((int)$aid)) $teamConnected[(int)$aid]=$uname; }
/* Selección actual de compañeros a mostrar (?team=2,3). */
$teamSel = [];
foreach (explode(',', (string)($_GET['team']??'')) as $x){ if(ctype_digit($x) && isset($teamConnected[(int)$x])) $teamSel[]=(int)$x; }
$teamSel = array_values(array_unique($teamSel));

$MICOLOR='#4285F4';
$PALETA=['#8e44ad','#e67e22','#16a085','#d35400','#2980b9','#c0392b','#0f9d58'];
$calShow=[]; // uid => [name,color,mine]
if ($gcConnected) $calShow[$meId]=['name'=>($respMap[$meId]??'Yo'),'color'=>$MICOLOR,'mine'=>true];
$pi=0; foreach($teamSel as $tid){ $calShow[$tid]=['name'=>$teamConnected[$tid],'color'=>$PALETA[$pi++ % count($PALETA)],'mine'=>false]; }

$gByDay = [];   // día completo por ISO
$gTimed = [];   // con hora por ISO
foreach ($calShow as $uid=>$meta){
  foreach (gcal_events((int)$uid, $rangeStart, $rangeEnd) as $ge){
    $ge['color']=$meta['color']; $ge['mine']=$meta['mine']; $ge['owner']=$meta['name'];
    if(!$meta['mine']) $ge['editable']=false; // los de otros son solo lectura
    if ($ge['allday']) { $gByDay[$ge['dia']][]=$ge; }
    else { $s=strtotime($ge['ini']); $e=$ge['fin']!==''?strtotime($ge['fin']):$s+3600; $ge['sMin']=(int)date('G',$s)*60+(int)date('i',$s); $ge['eMin']=(int)date('G',$e)*60+(int)date('i',$e); if($ge['eMin']<=$ge['sMin'])$ge['eMin']=$ge['sMin']+30; $gTimed[$ge['dia']][]=$ge; }
  }
}
/* Reparte los eventos que se solapan en columnas (col/cols) para que se vean todos. */
function cal_layout($evs){
  usort($evs, fn($a,$b)=>($a['sMin']<=>$b['sMin'])?:($a['eMin']<=>$b['eMin']));
  $out=[]; $cluster=[]; $curEnd=-1;
  $flush=function() use(&$cluster,&$out){ if(!$cluster)return; $colsEnd=[]; foreach($cluster as &$e){ $placed=false; foreach($colsEnd as $ci=>$en){ if($e['sMin']>=$en){ $e['col']=$ci; $colsEnd[$ci]=$e['eMin']; $placed=true; break; } } if(!$placed){ $e['col']=count($colsEnd); $colsEnd[]=$e['eMin']; } } unset($e); $nc=count($colsEnd); foreach($cluster as $e){ $e['cols']=$nc; $out[]=$e; } $cluster=[]; };
  foreach($evs as $e){ if($e['sMin']>=$curEnd && $cluster){ $flush(); $curEnd=-1; } $cluster[]=$e; $curEnd=max($curEnd,$e['eMin']); }
  $flush();
  return $out;
}
foreach($gTimed as $k=>$list){ $gTimed[$k]=cal_layout($list); }

$ROW=46; // alto de cada hora (px) en las vistas Semana/Día
function cal_url($view,$d){ global $teamSel; $t=$teamSel?('&team='.implode(',',$teamSel)):''; return 'calendar.php?view='.$view.'&d='.$d.$t; }
/* Href para (des)activar un compañero en la superposición. */
function cal_team_url($view,$d,$teamSel,$tid){ $tid=(int)$tid; $set=in_array($tid,$teamSel,true)?array_diff($teamSel,[$tid]):array_merge($teamSel,[$tid]); $set=array_values($set); return 'calendar.php?view='.$view.'&d='.$d.($set?('&team='.implode(',',$set)):''); }
/* Días del rango (para semana/día). */
$rangeDays=[]; for($t=strtotime($rangeStart);$t<=strtotime($rangeEnd);$t=strtotime('+1 day',$t)) $rangeDays[]=date('Y-m-d',$t);

erp_head('cal', 'Calendario');
?>
<style>
.cl-head{display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap}
.cl-head h1{margin:0;min-width:160px;font-size:24px}
.cl-applogo{width:52px;height:52px;border-radius:14px;border:1px solid var(--line);background:#fff;display:flex;align-items:center;justify-content:center;flex:none;box-shadow:0 2px 8px -4px rgba(16,19,24,.15)}
.gc-revoked{display:flex;align-items:center;gap:10px;background:#fdecec;border:1px solid #f7c9c9;color:#c0343a;border-radius:12px;padding:11px 16px;font-size:13px;font-weight:600;margin-bottom:16px}
.gc-revoked a{margin-left:auto;background:#c0343a;color:#fff;padding:6px 14px;border-radius:8px;text-decoration:none;font-weight:700}
.gc-revoked a:hover{filter:brightness(1.1)}
.cl-nav{display:flex;align-items:center;gap:4px}
.cl-nav a{width:34px;height:34px;border-radius:9px;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;color:#6b7280;background:#fff}
.cl-nav a:hover{background:var(--soft);color:var(--ink)}
.cl-today{border:1px solid var(--line);background:#fff;border-radius:9px;padding:8px 14px;font-size:12.5px;font-weight:600;color:#4c515b}
.cl-today:hover{background:var(--soft)}
/* conmutador de vistas */
.cl-views{display:flex;background:#fff;border:1px solid var(--line);border-radius:10px;padding:3px;gap:2px}
.cl-views a{padding:6px 13px;border-radius:7px;font-size:12.5px;font-weight:600;color:#6b7280}
.cl-views a.on{background:#111318;color:#fff}
.cl-legend{display:flex;gap:13px;margin-left:auto;flex-wrap:wrap;align-items:center}
.cl-legend span{font-size:11.5px;color:var(--muted);display:inline-flex;align-items:center;gap:6px}
.cl-legend .d{width:9px;height:9px;border-radius:3px}
.cl-gc{display:flex;align-items:center;gap:10px}
.gc-btn{border:1px solid var(--line);background:#fff;border-radius:9px;padding:8px 12px;font-size:12.5px;font-weight:600;color:#4c515b;display:inline-flex;align-items:center;gap:7px;cursor:pointer;text-decoration:none}
.gc-btn:hover{background:var(--soft)}
.gc-btn.gc-new{background:var(--accent);color:#fff;border-color:var(--accent)}
.gc-btn.gc-new:hover{filter:brightness(1.14)}
.gc-badge{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#3c4149;font-weight:600}
.gc-dot{width:9px;height:9px;border-radius:50%;background:#4285F4;flex:none}

/* ---- Vista Mes ---- */
.cl-grid{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
.cl-dow{display:grid;grid-template-columns:repeat(7,1fr);background:#fbfbfc;border-bottom:1px solid var(--line)}
.cl-dow span{padding:12px 10px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:650;text-align:center}
.cl-days{display:grid;grid-template-columns:repeat(7,1fr)}
.cl-cell{min-height:126px;border-right:1px solid var(--line);border-bottom:1px solid var(--line);padding:9px 10px;display:flex;flex-direction:column;gap:5px}
.cl-cell:nth-child(7n){border-right:none}
.cl-cell.pad{background:#fcfcfd}
.cl-cell .dn{font-size:12px;font-weight:600;color:#6b7280;align-self:flex-start;width:24px;height:24px;display:flex;align-items:center;justify-content:center;border-radius:7px}
.cl-cell.today .dn{background:var(--accent);color:#fff}
.cl-ev{display:flex;align-items:center;gap:5px;font-size:11.5px;padding:3px 7px;border-radius:6px;background:var(--soft);color:var(--ink);border-left:3px solid #ccc;text-decoration:none}
.cl-ev .cl-ev-t{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cl-ev .cl-ev-av{display:inline-flex;flex:none}
.cl-ev .cav-cal{width:16px;height:16px;border-radius:50%;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:8px;font-weight:700;border:1.5px solid #fff}
.cl-ev .cav-cal+.cav-cal{margin-left:-6px}
.cl-ev .cav-cal.xtra{background:#c8ccd2;color:#3c4149;font-size:7.5px}
.cl-ev:hover{background:#eef0f3}
.cl-gev{display:flex;align-items:center;gap:5px;font-size:11.5px;padding:3px 7px;border-radius:6px;background:#eef4ff;color:#1a56db;border-left:3px solid #4285F4;cursor:pointer}
.cl-gev .cl-ev-t{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cl-gev .gh{font-size:10px;font-weight:700;opacity:.85;flex:none}
.cl-gev:hover{background:#e2ecff}
.cl-more{font-size:10.5px;color:var(--muted);padding-left:4px}
/* festivos */
.cl-cell.fest{background:#fbfaf7}
.cl-cell .cl-fest{font-size:9.5px;color:#b08900;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:-2px}
.tg-hcol.fest .dnm{color:#b08900}
.tg-allday .adg .ad-fest{font-size:10px;color:#b08900;font-weight:700}
/* vista agenda */
.ag-wrap{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
.ag-day{display:flex;gap:16px;padding:14px 18px;border-bottom:1px solid var(--line2)}
.ag-day:last-child{border-bottom:none}
.ag-date{width:52px;flex:none;text-align:center;padding-top:2px}
.ag-date .agn{font-size:22px;font-weight:750;color:var(--ink-strong);line-height:1}
.ag-date .agm{font-size:10.5px;text-transform:uppercase;color:var(--muted);font-weight:650;letter-spacing:.4px;margin-top:2px}
.ag-date.today .agn{background:#3c4149;color:#fff;border-radius:11px;width:34px;height:34px;line-height:34px;display:inline-block}
.ag-items{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.ag-fest{font-size:12px;color:#b08900;font-weight:700;display:flex;align-items:center;gap:6px;padding:4px 0}
.ag-it{display:flex;align-items:center;gap:11px;padding:8px 10px;border-radius:10px;text-decoration:none;color:inherit;cursor:pointer}
.ag-it:hover{background:var(--soft)}
.ag-it .ag-dot{width:9px;height:9px;border-radius:50%;flex:none}
.ag-it .ag-h{font-size:12px;color:var(--muted);font-weight:600;width:74px;flex:none}
.ag-it .ag-t{font-size:13.5px;font-weight:600;color:var(--ink-strong);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:6px}
.ag-it .ag-c{font-size:12px;color:var(--muted);flex:none;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ag-empty{padding:60px 20px;text-align:center;color:var(--muted)}

/* ---- Vistas Semana / Día (rejilla de horas) ---- */
.tg{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
.tg-head{display:grid;border-bottom:1px solid var(--line);background:#fbfbfc}
.tg-allday{display:grid;border-bottom:1px solid var(--line);min-height:34px}
.tg-allday .adg{border-right:1px solid var(--line2);padding:4px 6px;display:flex;flex-direction:column;gap:3px}
.tg-allday .adg:last-child{border-right:none}
.tg-allday .adlbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;padding-left:8px}
.tg-hcol{padding:9px 6px;text-align:center;border-right:1px solid var(--line2)}
.tg-hcol:last-child{border-right:none}
.tg-hcol .dnm{font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:650}
.tg-hcol .dnum{font-size:17px;font-weight:700;color:var(--ink-strong);margin-top:2px;width:30px;height:30px;line-height:30px;border-radius:50%;display:inline-block}
.tg-hcol.today .dnum{background:var(--accent);color:#fff}
.tg-scroll{max-height:64vh;overflow-y:auto}
.tg-body{display:grid;position:relative}
.tg-gutter .gh{height:<?= $ROW ?>px;font-size:10.5px;color:var(--muted);text-align:right;padding-right:8px;transform:translateY(-6px)}
.tg-col{position:relative;border-right:1px solid var(--line2);background-image:repeating-linear-gradient(to bottom,var(--line2) 0,var(--line2) 1px,transparent 1px,transparent <?= $ROW ?>px)}
.tg-col:last-child{border-right:none}
.tg-col.today{background-color:#fafcff}
.tg-slot{position:absolute;left:0;right:0;height:<?= $ROW ?>px;cursor:pointer}
.tg-slot:hover{background:rgba(66,133,244,.06)}
.tg-tev{position:absolute;left:3px;right:3px;background:#e2ecff;border:1px solid #bcd2ff;border-left:3px solid #4285F4;border-radius:6px;padding:3px 6px;font-size:11px;color:#1a3f9e;overflow:hidden;cursor:pointer;z-index:2;box-sizing:border-box}
.tg-tev:hover{filter:brightness(.97)}
.tg-tev .tt{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tg-tev .th{font-size:10px;opacity:.8}
.tg-nowline{position:absolute;left:0;right:0;height:2px;background:#ef4444;z-index:3}
.tg-nowline:before{content:"";position:absolute;left:-4px;top:-3px;width:8px;height:8px;border-radius:50%;background:#ef4444}
.tg-ad-task{font-size:11px;background:var(--soft);border-left:3px solid #ccc;border-radius:5px;padding:2px 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-decoration:none;color:var(--ink)}
.tg-ad-ev{font-size:11px;background:#eef4ff;color:#1a56db;border-left:3px solid #4285F4;border-radius:5px;padding:2px 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer}

/* Barra de compañeros (superponer sus agendas) */
.cal-team{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:-4px 0 16px}
.cal-team .ct-lbl{font-size:12px;color:var(--muted);font-weight:600;display:inline-flex;align-items:center;gap:5px}
.ct-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);border-radius:99px;padding:5px 11px;font-size:12.5px;font-weight:600;color:#6b7280;background:#fff;text-decoration:none}
.ct-chip.on{color:var(--ink);border-color:#d5d7db;background:#fbfbfc}
.ct-chip:hover{background:var(--soft)}
.ct-dot{width:9px;height:9px;border-radius:50%;flex:none}
/* Arrastrar / redimensionar eventos */
.tg-tev.tg-mine{cursor:grab}
.tg-tev.tg-drag{cursor:grabbing!important;opacity:.9;z-index:6;box-shadow:0 10px 22px -6px rgba(0,0,0,.3)}
.tg-rz{position:absolute;left:0;right:0;height:7px;cursor:ns-resize;z-index:3}
.tg-rz-t{top:-2px}.tg-rz-b{bottom:-2px}
.tg-tip{position:fixed;z-index:640;background:#111318;color:#fff;font-size:11.5px;font-weight:600;padding:3px 8px;border-radius:6px;pointer-events:none;display:none;white-space:nowrap}
.tg-tip.on{display:block}
.gc-modal .gc-team{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.gc-modal .gc-team button{border:1px solid var(--line);background:#fff;border-radius:99px;padding:4px 10px;font-size:12px;cursor:pointer;font-family:inherit;color:#4c515b;display:inline-flex;align-items:center;gap:5px}
.gc-modal .gc-team button:hover{background:var(--soft)}
.gc-modal .gc-meet svg{vertical-align:middle}
/* Popover de detalle de evento */
.ev-pop{position:fixed;z-index:500;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 20px 50px -16px rgba(16,19,24,.34);width:290px;padding:15px 16px;display:none}
.ev-pop.on{display:block}
.ev-pop h4{margin:0 0 3px;font-size:15px}
.ev-pop .ep-meta{font-size:12.5px;color:var(--muted);margin-bottom:3px;display:flex;gap:7px;align-items:flex-start}
.ev-pop .ep-acts{display:flex;gap:8px;margin-top:14px}
.ev-pop .ep-acts .btn{flex:1;justify-content:center}
.ev-pop .ep-del{border:none;background:none;color:#c0343a;cursor:pointer;font-size:12.5px;font-weight:600;padding:8px}
.ev-pop .ep-del:hover{text-decoration:underline}

/* Modal crear/editar */
.gc-ov{position:fixed;inset:0;background:rgba(16,19,24,.32);z-index:520;display:none;align-items:center;justify-content:center}
.gc-ov.on{display:flex}
/* Modal de evento al estilo del de Reuniones: cabecera con logo + subtítulo,
   campos con etiqueta e interruptores .sw (globales de erp_nav). */
.gm{background:#fff;border-radius:18px;box-shadow:0 30px 80px -24px rgba(16,19,24,.5);width:470px;max-width:calc(100vw - 28px);
  padding:0;overflow:hidden;max-height:calc(100vh - 40px);display:flex;flex-direction:column}
.gm-mh{display:flex;align-items:center;gap:12px;padding:18px 22px 14px;border-bottom:1px solid var(--line2)}
.gm-mh-logo{width:40px;height:40px;border-radius:11px;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;flex:none}
.gm-mh-t{display:flex;flex-direction:column;flex:1;min-width:0}
.gm-mh-t b{font-size:16px;color:var(--ink-strong)}
.gm-mh-t small{font-size:11.5px;color:var(--muted);display:flex;align-items:center;gap:5px;margin-top:2px}
.gm-x{border:none;background:none;color:var(--label);cursor:pointer;font-size:15px;padding:6px;border-radius:8px;align-self:flex-start}
.gm-x:hover{background:var(--soft);color:var(--ink)}
.gm-b{padding:16px 22px 8px;overflow-y:auto;display:flex;flex-direction:column;gap:13px}
.gm-b label{display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px}
.gm-b label .sub{font-weight:400;color:var(--label);margin-left:4px}
.gm-inp{width:100%;box-sizing:border-box;border:1px solid var(--line);background:var(--soft);border-radius:10px;padding:9px 11px;
  font:inherit;font-size:14px;color:var(--ink);outline:none;transition:border-color .12s,background .12s}
.gm-inp:focus{border-color:#c9ccd1;background:#fff}
.gm-area{resize:vertical;min-height:44px;line-height:1.5}
.gm-row2{display:flex;gap:10px}.gm-row2>div{flex:1;min-width:0}
.gm-whenrow{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.gm-date{width:auto;flex:none}
.gm-timewrap{display:none;align-items:center;gap:6px}
.gm-tf{width:98px}
.gm-dash{color:var(--muted)}
.gm-link{border:none;background:none;color:#1a56db;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;padding:4px 6px;border-radius:8px}
.gm-link:hover{background:#eef4ff}
.gm-team{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.gm-team button{border:1px solid var(--line);background:#fff;border-radius:99px;padding:4px 10px;font-size:12px;cursor:pointer;font-family:inherit;color:#4c515b;display:inline-flex;align-items:center;gap:4px}
.gm-team button:hover{background:var(--soft)}
.gm-sws{display:flex;flex-direction:column;gap:4px;border:1px solid var(--line2);border-radius:13px;padding:5px;background:var(--soft)}
.gm-sws label.gm-sw{display:flex;align-items:center;gap:14px;padding:13px 13px;border-radius:10px;cursor:pointer;margin:0;background:#fff}
.gm-sw:hover{background:#fff;box-shadow:0 1px 0 var(--line2) inset,0 0 0 1px var(--line2)}
.gm-sw .lg{width:30px;flex:none;display:flex;align-items:center;justify-content:center}
.gm-invwrap{position:relative}
.gm-invpop{position:absolute;left:0;right:0;top:calc(100% + 4px);background:#fff;border:1px solid var(--line);border-radius:11px;box-shadow:0 14px 36px rgba(16,19,24,.16);padding:5px;z-index:30;display:none;max-height:210px;overflow:auto}
.gm-invpop.on{display:block}
.gm-invpop button{display:block;width:100%;text-align:left;border:none;background:none;padding:8px 11px;border-radius:8px;font:inherit;font-size:13px;color:var(--ink);cursor:pointer}
.gm-invpop button:hover,.gm-invpop button.sel{background:var(--accent-soft);color:var(--ink-strong)}
.gm-sw .tx{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px;padding-right:6px}
.gm-sw .tx b{font-size:13px;font-weight:600;color:var(--ink);line-height:1.35}
.gm-sw .tx em{font-style:normal;font-size:11.5px;color:var(--muted);line-height:1.4}
.gm-sw .sw{flex:none}
.gm-acts{display:flex;justify-content:flex-end;gap:8px;padding:14px 22px 18px;border-top:1px solid var(--line2)}
/* Cabecera simple (la usa el mini-modal «Evento repetido») */
.gm-head{display:flex;align-items:center;gap:10px;margin-bottom:2px}
.gm-htxt{flex:1}
.cl-gev[draggable=true]{cursor:grab}.cl-gev.dragging{opacity:.4}
.cl-cell.drop-ok{background:#eef4ff;outline:2px dashed #4285F4;outline-offset:-3px}
/* menú contextual del calendario */
.cal-ctx{position:fixed;z-index:560;background:#fff;border:1px solid var(--line);border-radius:11px;box-shadow:0 18px 46px rgba(0,0,0,.18);padding:5px;min-width:180px;display:none}
.cal-ctx.on{display:block}
.cal-ctx button{display:flex;align-items:center;gap:9px;width:100%;border:none;background:none;text-align:left;font-family:inherit;font-size:13px;color:#4c515b;padding:8px 11px;border-radius:8px;cursor:pointer}
.cal-ctx button:hover{background:var(--soft);color:var(--ink)}
.cal-ctx button.danger{color:#c0343a}.cal-ctx button.danger:hover{background:#fdecec}
.cal-ctx .sep{height:1px;background:var(--line);margin:4px 6px}

/* Pantalla de bienvenida (sin conectar) */
.gc-onboard{max-width:540px;margin:6vh auto;text-align:center;background:#fff;border:1px solid var(--line);border-radius:20px;padding:42px 36px;box-shadow:0 24px 60px -34px rgba(16,19,24,.35)}
.gc-onboard .onb-ic{width:74px;height:74px;border-radius:20px;background:linear-gradient(135deg,#4285F4,#1a56db);color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto 18px}
.gc-onboard h2{margin:0 0 10px;font-size:21px}
.gc-onboard p{color:var(--muted);font-size:14px;line-height:1.65;margin:0 auto 24px;max-width:420px}
.gc-onboard .gc-btn.gc-new{font-size:14px;padding:12px 22px}
.onb-note{font-size:12.5px;color:var(--muted);margin-top:16px;line-height:1.5}
.onb-link{display:inline-block;margin-top:24px;background:none;border:none;color:#6b7280;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;padding:6px}
.onb-link:hover{color:var(--ink);text-decoration:underline}
@media(max-width:900px){.cl-cell{min-height:80px}}
/* ---- Modo oscuro: remapea las superficies y textos propios ---- */
[data-theme=dark] .cl-applogo,[data-theme=dark] .cl-nav a,[data-theme=dark] .cl-today,[data-theme=dark] .cl-views,[data-theme=dark] .gc-btn,[data-theme=dark] .cl-grid,[data-theme=dark] .ag-wrap,[data-theme=dark] .tg,[data-theme=dark] .ct-chip,[data-theme=dark] .gc-modal .gc-team button,[data-theme=dark] .gm,[data-theme=dark] .gm-sw,[data-theme=dark] .gm-sw:hover,[data-theme=dark] .gm-team button,[data-theme=dark] .gc-onboard{background-color:var(--card)}
[data-theme=dark] .cl-nav a,[data-theme=dark] .cl-views a,[data-theme=dark] .ct-chip,[data-theme=dark] .onb-link,[data-theme=dark] .gm-b label .sub,[data-theme=dark] .gm-x{color:var(--muted)}
[data-theme=dark] .cl-today,[data-theme=dark] .gc-badge,[data-theme=dark] .gc-modal .gc-team button,[data-theme=dark] .gm-team button,[data-theme=dark] .cal-ctx button{color:var(--ink)}
[data-theme=dark] .cl-views a.on{background-color:var(--rev);color:var(--rev-fg)}
[data-theme=dark] .gc-btn.gc-new{color:var(--accent-fg)}
[data-theme=dark] .cl-cell.today .dn{color:var(--accent-fg)}
[data-theme=dark] .cl-dow,[data-theme=dark] .cl-cell.pad,[data-theme=dark] .cl-cell.fest,[data-theme=dark] .tg-head,[data-theme=dark] .tg-col.today,[data-theme=dark] .ct-chip.on,[data-theme=dark] .cl-ev:hover,[data-theme=dark] .gm-link:hover,[data-theme=dark] .cl-cell.drop-ok{background-color:var(--soft)}
[data-theme=dark] .cl-cell .dn{color:var(--muted)}
[data-theme=dark] .ag-date.today .agn,[data-theme=dark] .tg-tip{background-color:var(--rev);color:var(--rev-fg)}
[data-theme=dark] .ev-pop,[data-theme=dark] .gm-invpop,[data-theme=dark] .cal-ctx{background-color:var(--pop)}
[data-theme=dark] .gm-inp:focus{background-color:var(--field)}
[data-theme=dark] .gc-revoked{background-color:var(--danger-bg);border-color:var(--danger-line);color:var(--danger)}
[data-theme=dark] .cal-ctx button.danger:hover{background-color:var(--danger-bg)}
/* ====== MÓVIL (≤640px): calendario cómodo en el teléfono ====== */
@media(max-width:640px){
  .cl-head h1{min-width:0;font-size:20px}
  .cl-views{width:100%;justify-content:space-between}
  .cl-views a{flex:1;text-align:center;padding:8px 6px}
  .cl-legend{margin-left:0;width:100%}
  /* Vista Mes: celdas compactas, eventos como mini-pastillas que no desbordan */
  .cl-dow span{padding:8px 2px;font-size:9px;letter-spacing:.2px}
  .cl-cell{min-height:62px;padding:5px 4px;gap:3px}
  .cl-cell .dn{width:20px;height:20px;font-size:11px}
  .cl-ev,.cl-gev{font-size:9.5px;padding:2px 4px;gap:3px}
  .cl-ev .cl-ev-av{display:none}
  .cl-cell .cl-fest{font-size:8.5px}
  .cl-more{font-size:9.5px}
  /* Vista Agenda: más aire para el pulgar */
  .ag-day{gap:12px;padding:12px 14px}
  .ag-date{width:44px}
  .ag-it{padding:10px 8px}
  .ag-it .ag-h{width:60px;font-size:11.5px}
  .ag-it .ag-c{max-width:110px}
  /* Semana/Día: el bloque de horas se puede desplazar en vertical sin cortar */
  .tg-scroll{max-height:70vh}
  /* Vista SEMANA: 7 columnas no caben legibles en el teléfono. Se desplaza en
     HORIZONTAL (columnas ~90px) manteniendo la cabecera y las horas alineadas. */
  .tg-wk{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .tg-wk .tg-head,.tg-wk .tg-allday,.tg-wk .tg-scroll{min-width:660px}
  .tg-hcol{padding:7px 2px}
  .tg-hcol .dnum{font-size:15px;width:26px;height:26px;line-height:26px}
  /* Modal de evento: casi pantalla completa con scroll interno */
  .gm{width:100%;max-width:calc(100vw - 20px);max-height:calc(100vh - 24px)}
  .gm-row2{flex-direction:column;gap:13px}
  .gm-acts{padding:13px 18px 16px}
  .gm-acts .btn{flex:1;justify-content:center}
  /* Popover de evento anclado: nunca más ancho que la pantalla */
  .ev-pop{width:auto;max-width:calc(100vw - 24px)}
}
</style>

<?php if(!$gcConnected): ?>
<div class="gc-onboard" id="gcOnboard">
  <div class="onb-ic" style="background:#fff;border:1px solid var(--line)"><?= svc_logo('gcal',40) ?></div>
  <h2>Tu calendario, conectado a Google</h2>
  <p>Conecta tu cuenta de Google para ver aquí tus reuniones y eventos y crear nuevos desde el ERP. Tú y tu equipo podréis veros las agendas y organizar reuniones juntos.</p>
  <?php if($gcConfigured): ?>
    <a class="gc-btn gc-new" href="gcal_callback.php?start=1"><?= svc_logo('gcal',18) ?> Conectar Google Calendar</a>
  <?php elseif(is_owner()): ?>
    <a class="gc-btn gc-new" href="integraciones.php?i=gcal"><?= ic('settings',15) ?> Configurar Google Calendar</a>
    <div class="onb-note">Solo tú (Dueño) haces esta configuración, una única vez.</div>
  <?php else: ?>
    <div class="onb-note">Pídele al Dueño que configure Google Calendar en Ajustes.<br>Después podrás conectar tu cuenta desde aquí.</div>
  <?php endif; ?>
  <br><button type="button" class="onb-link" onclick="calShowTasks()">Ver solo las fechas de mis tareas del ERP →</button>
</div>
<?php endif; ?>

<div id="calMain"<?= $gcConnected?'':' style="display:none"' ?>>
<?php if($gcRevoked): ?>
<div class="gc-revoked"><?= ic('alert',16) ?> <span>Tu conexión con Google ha caducado.</span> <a href="gcal_callback.php?start=1">Reconectar</a></div>
<?php endif; ?>
<div class="cl-head">
  <?php if($gcConnected): ?><span class="cl-applogo" title="Google Calendar · <?= e(gcal_email($meId)) ?>"><?= svc_logo('gcal',36) ?></span><?php endif; ?>
  <h1><?= e($titulo) ?></h1>
  <div class="cl-nav"><a href="<?= cal_url($view,$prevD) ?>" title="Anterior"><?= ic('back',16) ?></a><a href="<?= cal_url($view,$nextD) ?>" title="Siguiente" style="transform:scaleX(-1)"><?= ic('back',16) ?></a></div>
  <a class="cl-today" href="<?= cal_url($view,$todayIso) ?>">Hoy</a>
  <div class="cl-views">
    <a href="<?= cal_url('mes',$anchor) ?>" class="<?= $view==='mes'?'on':'' ?>">Mes</a>
    <a href="<?= cal_url('semana',$anchor) ?>" class="<?= $view==='semana'?'on':'' ?>">Semana</a>
    <a href="<?= cal_url('dia',$anchor) ?>" class="<?= $view==='dia'?'on':'' ?>">Día</a>
    <a href="<?= cal_url('agenda',$anchor) ?>" class="<?= $view==='agenda'?'on':'' ?>">Agenda</a>
  </div>
  <div class="cl-legend"><?php foreach($ESTADOS as $k=>$v): ?><span><span class="d" style="background:<?= $v[1] ?>"></span><?= e($v[0]) ?></span><?php endforeach; ?><?php if($gcConnected): ?><span><span class="d" style="background:#4285F4"></span>Google</span><?php endif; ?></div>
  <div class="cl-gc">
    <?php if($gcConnected): ?>
      <button type="button" class="gc-btn gc-new" onclick="gcOpen()"><?= ic('plus',14) ?> Nuevo evento</button>
    <?php elseif($gcConfigured): ?>
      <a class="gc-btn" href="gcal_callback.php?start=1"><?= svc_logo('gcal',18) ?> Conectar Google Calendar</a>
    <?php elseif(is_owner()): ?>
      <a class="gc-btn" href="integraciones.php?i=gcal"><?= svc_logo('gcal',18) ?> Configurar Google Calendar</a>
    <?php endif; ?>
  </div>
</div>

<?php if($gcConnected && $teamConnected): ?>
<div class="cal-team">
  <span class="ct-lbl"><?= ic('user',14) ?> Ver también:</span>
  <?php foreach($teamConnected as $tid=>$tname): $on=isset($calShow[$tid]); $c=$on?$calShow[$tid]['color']:'#c8ccd2'; ?>
    <a class="ct-chip <?= $on?'on':'' ?>" href="<?= e(cal_team_url($view,$anchor,$teamSel,$tid)) ?>"><span class="ct-dot" style="background:<?= $c ?>"></span><?= e($tname) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if($view==='mes'):
  $year=(int)date('Y',$aT); $mon=(int)date('n',$aT); $daysIn=(int)date('t',$aT);
  $startDow=((int)date('N',strtotime($rangeStart))+6)%7;
?>
<div class="cl-grid">
  <div class="cl-dow"><?php foreach($DOW as $dn): ?><span><?= $dn ?></span><?php endforeach; ?></div>
  <div class="cl-days">
    <?php for($i=0;$i<$startDow;$i++): ?><div class="cl-cell pad"></div><?php endfor; ?>
    <?php for($d=1;$d<=$daysIn;$d++): $iso=sprintf('%04d-%02d-%02d',$year,$mon,$d); $evs=$byDay[$iso]??[]; $gAll=$gByDay[$iso]??[]; $gTm=$gTimed[$iso]??[]; $gevs=array_merge($gAll,$gTm); $tShow=$gevs?3:4; ?>
      <div class="cl-cell <?= $iso===$todayIso?'today':'' ?> <?= isset($festivos[$iso])?'fest':'' ?>" data-iso="<?= $iso ?>"<?= $gcConnected?' onclick="cellClick(event,\''.$iso.'\')" oncontextmenu="cellMenu(event,\''.$iso.'\')" ondragover="cellDragOver(event)" ondrop="cellDrop(event,\''.$iso.'\')" style="cursor:cell"':'' ?>>
        <span class="dn"><?= $d ?></span>
        <?php if(isset($festivos[$iso])): ?><span class="cl-fest" title="Festivo"><?= e($festivos[$iso]) ?></span><?php endif; ?>
        <?php foreach(array_slice($evs,0,$tShow) as $ev): $col=$ESTADOS[$ev['estado']][1]??'#ccc'; $aids=cal_asg_ids($ev,$calAsg,$respMap); ?>
          <a class="cl-ev" style="border-left-color:<?= $col ?>" href="task.php?id=<?= (int)$ev['id'] ?>&ret=" title="<?= e($ev['titulo']).' · '.e($ev['cname']) ?>" onclick="event.stopPropagation()"><span class="cl-ev-t"><?= e($ev['titulo']) ?></span><?php if($aids): ?><span class="cl-ev-av"><?php foreach(array_slice($aids,0,2) as $ai): ?><span class="cav-cal" style="background:<?= avatar_color($respMap[$ai]) ?>"><?= e(mb_strtoupper(mb_substr($respMap[$ai],0,1))) ?></span><?php endforeach; ?></span><?php endif; ?></a>
        <?php endforeach; ?>
        <?php foreach(array_slice($gevs,0,2) as $ge): $gj=json_encode($ge,JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT); ?>
          <div class="cl-gev" style="background:<?= $ge['color'] ?>1e;border-left-color:<?= $ge['color'] ?>;color:<?= $ge['color'] ?>"<?= $ge['editable']?' draggable="true"':'' ?> onclick='event.stopPropagation();evShow(this,<?= $gj ?>)' oncontextmenu='evMenu(event,<?= $gj ?>)'<?= $ge['editable']?' ondragstart=\'evDragStart(event,'.$gj.')\'':'' ?>><?php if($ge['hora']!==''): ?><span class="gh"><?= e($ge['hora']) ?></span><?php endif; ?><span class="cl-ev-t"><?= e($ge['titulo']) ?></span></div>
        <?php endforeach; ?>
        <?php $over=max(0,count($evs)-$tShow)+max(0,count($gevs)-2); if($over>0): ?><span class="cl-more">+<?= $over ?> más</span><?php endif; ?>
      </div>
    <?php endfor; ?>
    <?php $tot=$startDow+$daysIn; $rem=(7-($tot%7))%7; for($i=0;$i<$rem;$i++): ?><div class="cl-cell pad"></div><?php endfor; ?>
  </div>
</div>

<?php elseif($view==='agenda'): /* ---- Vista Agenda (lista de 30 días) ---- */ ?>
<div class="ag-wrap">
  <?php $anyAg=false; foreach($rangeDays as $iso): $t=strtotime($iso);
    $tks=$byDay[$iso]??[]; $gAll=$gByDay[$iso]??[]; $gTm=$gTimed[$iso]??[];
    if(!$tks && !$gAll && !$gTm) continue; $anyAg=true;
    $fest=$festivos[$iso]??''; ?>
    <div class="ag-day">
      <div class="ag-date <?= $iso===$todayIso?'today':'' ?>"><div class="agn"><?= (int)date('j',$t) ?></div><div class="agm"><?= $DOW[((int)date('N',$t)+6)%7] ?></div></div>
      <div class="ag-items">
        <?php if($fest): ?><div class="ag-fest"><?= ic('flag',13) ?> <?= e($fest) ?> · festivo</div><?php endif; ?>
        <?php foreach($tks as $ev): $col=$ESTADOS[$ev['estado']][1]??'#ccc'; ?>
          <a class="ag-it" href="task.php?id=<?= (int)$ev['id'] ?>&ret="><span class="ag-dot" style="background:<?= $col ?>"></span><span class="ag-h">Entrega</span><span class="ag-t"><?= e($ev['titulo']) ?></span><span class="ag-c"><?= e($ev['cname']) ?></span></a>
        <?php endforeach; ?>
        <?php foreach($gAll as $ge): $gj=json_encode($ge,JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT); ?>
          <div class="ag-it" onclick='evShow(this,<?= $gj ?>)' oncontextmenu='evMenu(event,<?= $gj ?>)'><span class="ag-dot" style="background:<?= $ge['color'] ?>"></span><span class="ag-h">Todo el día</span><span class="ag-t"><?= e($ge['titulo']) ?></span><span class="ag-c"><?= $ge['mine']?'':e($ge['owner']) ?></span></div>
        <?php endforeach; ?>
        <?php foreach($gTm as $ge): $gj=json_encode($ge,JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT); ?>
          <div class="ag-it" onclick='evShow(this,<?= $gj ?>)' oncontextmenu='evMenu(event,<?= $gj ?>)'><span class="ag-dot" style="background:<?= $ge['color'] ?>"></span><span class="ag-h"><?= e($ge['hora']) ?></span><span class="ag-t"><?= e($ge['titulo']) ?><?php if($ge['meet']): ?> <?= svc_logo('meet',13) ?><?php endif; ?></span><span class="ag-c"><?= $ge['mine']?'':e($ge['owner']) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if(!$anyAg): ?><?= erp_empty('cal','Nada en los próximos 30 días','Cuando tengas eventos o reuniones agendadas, aparecerán aquí.') ?><?php endif; ?>
</div>

<?php else: /* ---- Semana / Día: rejilla de horas ---- */
  $nCols=count($rangeDays); $gridCols='56px repeat('.$nCols.',1fr)';
?>
<div class="tg<?= $nCols>1?' tg-wk':'' ?>">
  <div class="tg-head" style="grid-template-columns:<?= $gridCols ?>">
    <div></div>
    <?php foreach($rangeDays as $iso): $t=strtotime($iso); ?>
      <div class="tg-hcol <?= $iso===$todayIso?'today':'' ?> <?= isset($festivos[$iso])?'fest':'' ?>" <?= isset($festivos[$iso])?'title="Festivo: '.e($festivos[$iso]).'"':'' ?>><a href="<?= cal_url('dia',$iso) ?>" style="color:inherit"><div class="dnm"><?= $DOW[((int)date('N',$t)+6)%7] ?></div><div class="dnum"><?= (int)date('j',$t) ?></div></a></div>
    <?php endforeach; ?>
  </div>
  <div class="tg-allday" style="grid-template-columns:<?= $gridCols ?>">
    <div class="adlbl">Todo el día</div>
    <?php foreach($rangeDays as $iso): $tks=$byDay[$iso]??[]; $gAll=$gByDay[$iso]??[]; ?>
      <div class="adg">
        <?php if(isset($festivos[$iso])): ?><div class="ad-fest"><?= ic('flag',11) ?> <?= e($festivos[$iso]) ?></div><?php endif; ?>
        <?php foreach($tks as $ev): $col=$ESTADOS[$ev['estado']][1]??'#ccc'; ?><a class="tg-ad-task" style="border-left-color:<?= $col ?>" href="task.php?id=<?= (int)$ev['id'] ?>&ret=" title="<?= e($ev['titulo']).' · '.e($ev['cname']) ?>"><?= e($ev['titulo']) ?></a><?php endforeach; ?>
        <?php foreach($gAll as $ge): $gj=json_encode($ge,JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT); ?><div class="tg-ad-ev" style="background:<?= $ge['color'] ?>1e;border-left-color:<?= $ge['color'] ?>;color:<?= $ge['color'] ?>" onclick='evShow(this,<?= $gj ?>)' oncontextmenu='evMenu(event,<?= $gj ?>)'><?= e($ge['titulo']) ?></div><?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="tg-scroll" id="tgScroll">
    <div class="tg-body" style="grid-template-columns:<?= $gridCols ?>;height:<?= 24*$ROW ?>px">
      <div class="tg-gutter"><?php for($h=0;$h<24;$h++): ?><div class="gh"><?= $h>0?sprintf('%02d:00',$h):'' ?></div><?php endfor; ?></div>
      <?php foreach($rangeDays as $iso): $tm=$gTimed[$iso]??[]; ?>
        <div class="tg-col <?= $iso===$todayIso?'today':'' ?>" data-iso="<?= $iso ?>">
          <?php if($gcConnected): for($h=0;$h<24;$h++): ?><div class="tg-slot" style="top:<?= $h*$ROW ?>px" onclick="gcOpen({fecha:'<?= $iso ?>',hora:'<?= sprintf('%02d:00',$h) ?>'})"></div><?php endfor; endif; ?>
          <?php foreach($tm as $ge):
            $sMin=$ge['sMin']; $eMin=$ge['eMin']; $top=$sMin/60*$ROW; $hgt=max(22,($eMin-$sMin)/60*$ROW);
            $cols=max(1,(int)($ge['cols']??1)); $col=(int)($ge['col']??0); $wp=100/$cols; $lp=$col*$wp;
          ?>
            <?php $gj=json_encode($ge,JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT); ?>
            <div class="tg-tev<?= $ge['editable']?' tg-mine':'' ?>" data-smin="<?= $sMin ?>" data-dur="<?= max(15,$eMin-$sMin) ?>" style="top:<?= round($top,1) ?>px;height:<?= round($hgt,1) ?>px;left:calc(<?= round($lp,3) ?>% + 2px);width:calc(<?= round($wp,3) ?>% - 4px);right:auto;background:<?= $ge['color'] ?>22;border-left-color:<?= $ge['color'] ?>;color:<?= $ge['color'] ?>" onclick='event.stopPropagation();evShow(this,<?= $gj ?>)' oncontextmenu='evMenu(event,<?= $gj ?>)'>
              <?php if($ge['editable']): ?><div class="tg-rz tg-rz-t"></div><?php endif; ?>
              <div class="tt"><?= e($ge['titulo']) ?></div><div class="th"><?= e($ge['hora']).($ge['hora_fin']!==''?'–'.e($ge['hora_fin']):'') ?></div>
              <?php if($ge['editable']): ?><div class="tg-rz tg-rz-b"></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if($iso===$todayIso): ?><div class="tg-nowline" id="nowLine" style="top:<?= round(((int)date('G')*60+(int)date('i'))/60*$ROW,1) ?>px"></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>
</div><!-- /#calMain -->

<!-- Popover detalle de evento + menú contextual -->
<div class="ev-pop" id="evPop"></div>
<div class="cal-ctx" id="calCtx"></div>
<?php if($gcConnected): ?>
<div class="gc-ov" id="recurOv" onclick="if(event.target===this)recurClose()">
  <div class="gm" style="width:360px;padding:18px 20px 20px">
    <div class="gm-head"><span class="gm-htxt" id="recurT" style="color:var(--ink-strong);font-size:15px;font-weight:650">Evento repetido</span><button class="gm-x" type="button" onclick="recurClose()">✕</button></div>
    <div style="padding:4px 2px 2px;color:var(--muted);font-size:13px">Este evento se repite. ¿A qué quieres aplicarlo?</div>
    <div style="display:flex;flex-direction:column;gap:8px;margin-top:16px">
      <button class="btn" type="button" id="recurThis" style="width:100%;justify-content:center">Solo este evento</button>
      <button class="btn ghost sm" type="button" id="recurSeries" style="width:100%;justify-content:center">Toda la serie</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if($gcConnected): $teamInvite=[]; foreach($teamConnected as $tid=>$tname){ $em=gcal_email((int)$tid); if($em) $teamInvite[]=['name'=>$tname,'email'=>$em]; }
  /* Correos para sugerir al escribir invitados: TODOS los ya conocidos — cuentas del
     equipo (admins), contactos del CRM y clientes. */
  $gcEmails=[];
  foreach($teamInvite as $ti){ $e=strtolower(trim((string)$ti['email'])); if($e!=='') $gcEmails[$e]=1; }
  foreach(['admins','contacts','clients'] as $tbl){ try{ foreach(db()->query("SELECT DISTINCT email FROM $tbl WHERE email IS NOT NULL AND email<>''") as $r){ $e=strtolower(trim((string)$r['email'])); if($e!=='' && strpos($e,'@')!==false) $gcEmails[$e]=1; } }catch(Exception $ex){} }
  $gcEmails=array_keys($gcEmails); sort($gcEmails); ?>
<script>window.GC_EMAILS = <?= json_encode($gcEmails, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;</script>
<div class="gc-ov" id="gcOv" onclick="if(event.target===this)gcClose()">
  <div class="gm">
    <div class="gm-mh">
      <span class="gm-mh-logo"><?= svc_logo('gcal',26) ?></span>
      <span class="gm-mh-t"><b id="gcTitle">Nuevo evento</b><small><?= svc_logo('gcal',12) ?> Se guarda en tu Google Calendar</small></span>
      <button class="gm-x" type="button" onclick="gcClose()" aria-label="Cerrar">✕</button>
    </div>
    <input type="hidden" id="gcId">
    <div class="gm-b">
      <div><label>Título</label><input id="gcTit" class="gm-inp" placeholder="Ej: Reunión de equipo" autocomplete="off"></div>

      <div class="gm-row2">
        <div><label>Fecha</label>
          <div class="gm-whenrow">
            <input type="date" id="gcFecha" class="gm-inp gm-date">
            <span id="gcTimeWrap" class="gm-timewrap"><input type="time" id="gcHora" class="gm-inp gm-tf"><span class="gm-dash">–</span><input type="time" id="gcHoraFin" class="gm-inp gm-tf"></span>
            <button type="button" id="gcAddTime" class="gm-link" onclick="gcToggleTime()">Añadir una hora</button>
            <button type="button" id="gcRmTime" class="gm-link" style="display:none" onclick="gcSetTime(false)">Todo el día</button>
          </div>
        </div>
        <div style="flex:none;width:150px"><label>Repetición</label><select id="gcRecur" class="gm-inp"><option value="">No se repite</option><option value="DAILY">Cada día</option><option value="WEEKLY">Cada semana</option><option value="MONTHLY">Cada mes</option></select></div>
      </div>

      <div><label>Invitados <span class="sub">correos separados por coma</span></label>
        <div class="gm-invwrap">
          <input id="gcInv" class="gm-inp" placeholder="cliente@empresa.com, otro@…" autocomplete="off" oninput="gcInvType()" onkeydown="gcInvKey(event)">
          <div class="gm-invpop" id="gcInvPop"></div>
        </div>
        <?php if($teamInvite): ?><div class="gm-team"><?php foreach($teamInvite as $ti): ?><button type="button" onclick="gcAddInv(<?= json_encode($ti['email']) ?>)"><?= ic('plus',11) ?> <?= e($ti['name']) ?></button><?php endforeach; ?></div><?php endif; ?>
      </div>

      <div><label>Ubicación</label><input id="gcLoc" class="gm-inp" placeholder="Sala, dirección o enlace" autocomplete="off"></div>
      <div><label>Descripción</label><textarea id="gcDesc" class="gm-inp gm-area" placeholder="Detalles del evento" rows="2"></textarea></div>

      <div><label>Recordatorio <span class="sub">el aviso que salta antes</span></label>
        <select id="gcRecordar" class="gm-inp">
          <option value="">Predeterminado de Google</option>
          <option value="10">10 minutos antes</option>
          <option value="30" selected>30 minutos antes</option>
          <option value="60">1 hora antes</option>
          <option value="120">2 horas antes</option>
          <option value="1440">1 día antes</option>
          <option value="no">Sin recordatorio</option>
        </select>
      </div>

      <div class="gm-sws">
        <label class="gm-sw" id="gcMeetRow"><span class="lg"><?= svc_logo('meet',22) ?></span>
          <span class="tx"><b>Añadir videollamada de Google Meet</b></span>
          <span class="sw"><input type="checkbox" id="gcMeet" onchange="gcMeetLabel()"><span class="tr"></span></span></label>
        <label class="gm-sw"><span class="lg"><?= svc_logo('gcal',22) ?></span>
          <span class="tx"><b>Avisar a los invitados por correo</b><em>Les llega la invitación de Google Calendar.</em></span>
          <span class="sw"><input type="checkbox" id="gcNotif" checked><span class="tr"></span></span></label>
        <label class="gm-sw" id="gcGemRow" style="display:none"><span class="lg"><?= svc_logo('gemini',22) ?></span>
          <span class="tx"><b>Tomar notas con Gemini</b><em>Deja un aviso para pulsar «Tomar notas» en la reunión de Meet.</em></span>
          <span class="sw"><input type="checkbox" id="gcGem"><span class="tr"></span></span></label>
      </div>
    </div>

    <div class="gm-acts">
      <button class="btn ghost sm" type="button" onclick="gcClose()">Cancelar</button>
      <button class="btn" type="button" id="gcSave" onclick="gcSave()">Guardar</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function calShowTasks(){var m=document.getElementById('calMain');if(m)m.style.display='block';var o=document.getElementById('gcOnboard');if(o)o.style.display='none';}
/* Atajos de teclado: n=nuevo · m/s/d/a=vistas · t=hoy · ←/→=anterior/siguiente */
window.CALNAV={mes:<?= json_encode(cal_url('mes',$anchor)) ?>,semana:<?= json_encode(cal_url('semana',$anchor)) ?>,dia:<?= json_encode(cal_url('dia',$anchor)) ?>,agenda:<?= json_encode(cal_url('agenda',$anchor)) ?>,hoy:<?= json_encode(cal_url($view,$todayIso)) ?>,prev:<?= json_encode(cal_url($view,$prevD)) ?>,next:<?= json_encode(cal_url($view,$nextD)) ?>,canNew:<?= $gcConnected?'true':'false' ?>};
document.addEventListener('keydown',function(e){
  if(e.metaKey||e.ctrlKey||e.altKey)return;
  var tag=(e.target.tagName||'').toLowerCase(); if(tag==='input'||tag==='textarea'||tag==='select'||e.target.isContentEditable)return;
  var ov=document.getElementById('gcOv'); if(ov&&ov.classList.contains('on'))return;
  var k=(e.key||'').toLowerCase();
  if(k==='n'&&window.CALNAV.canNew&&typeof gcOpen==='function'){e.preventDefault();gcOpen();return;}
  var go={m:'mes',s:'semana',d:'dia',a:'agenda'};
  if(go[k]){e.preventDefault();location.href=window.CALNAV[go[k]];return;}
  if(k==='t'){e.preventDefault();location.href=window.CALNAV.hoy;return;}
  if(e.key==='ArrowLeft'){location.href=window.CALNAV.prev;return;}
  if(e.key==='ArrowRight'){location.href=window.CALNAV.next;return;}
});
<?php if($gcConnected): ?>
/* Auto-scroll de la rejilla a las 7:00. */
(function(){var s=document.getElementById('tgScroll');if(s)s.scrollTop=<?= 7*$ROW ?>;})();
<?php if(isset($_GET['new'])): /* Agendar reunión desde el CRM: abre el modal precargado. */ ?>
document.addEventListener('DOMContentLoaded',function(){ gcOpen({titulo:<?= json_encode((string)($_GET['titulo']??'')) ?>,invitados:<?= json_encode((string)($_GET['invitados']??'')) ?>,fecha:<?= json_encode($anchor) ?>,hora:<?= json_encode((string)($_GET['hora']??'10:00')) ?>,erp_meeting:<?= json_encode((string)($_GET['meeting']??'')) ?>}); });
<?php endif; ?>

var _ev=null;
function evShow(el,data){_ev=data;var p=document.getElementById('evPop');
  var hora=data.allday?'Todo el día':(data.hora+(data.hora_fin?(' – '+data.hora_fin):''));
  var fecha=data.dia.split('-').reverse().join('/');
  var inv=data.invitados?('<div class="ep-meta">👥 '+escHtml(data.invitados)+'</div>'):'';
  if(!data.mine && data.owner) inv='<div class="ep-meta">👤 Agenda de '+escHtml(data.owner)+'</div>'+inv;
  var acts=data.editable
    ? '<div class="ep-acts"><button class="btn ghost sm" onclick="evEdit()">Editar</button><a class="btn ghost sm" href="'+escHtml(data.link||'#')+'" target="_blank" rel="noopener">Google</a></div><div style="text-align:center"><button class="ep-del" onclick="evDel()">Eliminar evento</button></div>'
    : '<div class="ep-acts"><a class="btn ghost sm" href="'+escHtml(data.link||'#')+'" target="_blank" rel="noopener" style="flex:1;justify-content:center">Ver en Google</a></div>';
  p.innerHTML='<h4>'+escHtml(data.titulo)+'</h4><div class="ep-meta">🗓️ '+escHtml(fecha)+'</div><div class="ep-meta">🕒 '+escHtml(hora)+'</div>'+inv+acts;
  var r=el.getBoundingClientRect();var top=r.bottom+6,left=Math.min(r.left,window.innerWidth-306);
  if(top+220>window.innerHeight)top=Math.max(10,r.top-230);
  p.style.top=top+'px';p.style.left=Math.max(8,left)+'px';p.classList.add('on');
}
function evClose(){document.getElementById('evPop').classList.remove('on');}
/* Diálogo para eventos repetidos: solo este / toda la serie. */
var _recurT=null,_recurS=null;
function recurAsk(title,thisFn,seriesFn){document.getElementById('recurT').textContent=title;_recurT=thisFn;_recurS=seriesFn;document.getElementById('recurOv').classList.add('on');}
function recurClose(){document.getElementById('recurOv').classList.remove('on');}
(function(){var t=document.getElementById('recurThis'),s=document.getElementById('recurSeries');if(t)t.onclick=function(){recurClose();if(_recurT)_recurT();};if(s)s.onclick=function(){recurClose();if(_recurS)_recurS();};})();
function evEditOpen(id){var d=_ev;evClose();gcOpen({id:id,titulo:d.titulo,fecha:d.dia,hora:d.allday?'':d.hora,hora_fin:d.hora_fin,invitados:d.invitados,location:d.location,descripcion:d.descripcion});}
function evEdit(){if(!_ev)return;var d=_ev; if(d.recurring&&d.masterId){ recurAsk('Editar evento repetido',function(){evEditOpen(d.id);},function(){evEditOpen(d.masterId);}); return;} evEditOpen(d.id);}
function evDoDel(id,titulo){var body=new URLSearchParams();body.set('action','gcal_del');body.set('id',id);
  fetch('calendar.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}).then(function(r){return r.json();}).then(function(d){
    evClose();if(d&&d.ok){toast('Evento eliminado');setTimeout(function(){location.reload();},500);}else{toast((d&&d.msg)||'No se pudo eliminar','err');}});}
function evDel(){if(!_ev)return;var d=_ev;
  if(d.recurring&&d.masterId){ recurAsk('Eliminar evento repetido',function(){evClose();erpConfirm('¿Eliminar solo esta ocurrencia?',{titulo:'Eliminar',danger:true}).then(function(ok){if(ok)evDoDel(d.id);});},function(){evClose();erpConfirm('¿Eliminar TODA la serie?',{titulo:'Eliminar serie',danger:true}).then(function(ok){if(ok)evDoDel(d.masterId);});}); return; }
  erpConfirm('¿Eliminar «'+d.titulo+'» de Google Calendar?',{titulo:'Eliminar evento',danger:true}).then(function(ok){if(ok)evDoDel(d.id);});}
document.addEventListener('mousedown',function(e){var p=document.getElementById('evPop');if(p&&p.classList.contains('on')&&!p.contains(e.target))evClose();});

function _g(id){return document.getElementById(id);}
function _plusHour(h){var p=(h||'09:00').split(':');var d=new Date(2000,0,1,+p[0],+p[1]);d.setHours(d.getHours()+1);return (d.getHours()<10?'0':'')+d.getHours()+':'+(d.getMinutes()<10?'0':'')+d.getMinutes();}
function gcSetTime(on){_g('gcTimeWrap').style.display=on?'inline-flex':'none';_g('gcAddTime').style.display=on?'none':'inline-block';_g('gcRmTime').style.display=on?'inline-block':'none';if(!on){_g('gcHora').value='';_g('gcHoraFin').value='';}}
function gcToggleTime(){gcSetTime(true);if(!_g('gcHora').value){_g('gcHora').value='09:00';_g('gcHoraFin').value='10:00';}_g('gcHora').focus();}
function gcMeetLabel(){var on=_g('gcMeet').checked;var gr=_g('gcGemRow');if(gr){gr.style.display=on?'flex':'none';if(!on)_g('gcGem').checked=false;}}
function gcToggleMeet(){var c=_g('gcMeet');c.checked=!c.checked;gcMeetLabel();}
function gcToggleGem(){var c=_g('gcGem');c.checked=!c.checked;}
var _gcErp='';                       // reunión del CRM a enlazar con el evento (para traer luego las notas de Gemini)
function gcOpen(o){o=o||{};
  _gcErp=o.erp_meeting||'';
  _g('gcId').value=o.id||'';
  _g('gcTit').value=o.titulo||'';
  _g('gcFecha').value=o.fecha||new Date().toISOString().slice(0,10);
  _g('gcInv').value=o.invitados||'';
  _g('gcLoc').value=o.location||'';
  _g('gcDesc').value=o.descripcion||'';
  _g('gcRecur').value=o.recur||'';
  _g('gcRecordar').value=(o.recordar!=null&&o.recordar!=='')?o.recordar:(o.id?'':'30');
  _g('gcNotif').checked=(o.notificar!==undefined)?!!o.notificar:true;
  _g('gcMeet').checked=!!o.meet; _g('gcGem').checked=false; gcMeetLabel();
  if(o.hora){_g('gcHora').value=o.hora;_g('gcHoraFin').value=o.hora_fin||_plusHour(o.hora);gcSetTime(true);}
  else{_g('gcHora').value='';_g('gcHoraFin').value='';gcSetTime(false);}
  _g('gcMeetRow').style.display=o.id?'none':'flex'; // Meet solo al crear
  if(o.id)_g('gcGemRow').style.display='none'; // Gemini solo al crear
  _g('gcTitle').textContent=o.id?'Editar evento':(o.meet?'Nueva reunión':'Nuevo evento');
  _g('gcSave').textContent=o.id?'Guardar cambios':'Guardar';
  _g('gcOv').classList.add('on');setTimeout(function(){_g('gcTit').focus();},30);}

/* Clic simple en una casilla del mes (zona vacía) → crear evento ese día. */
function cellClick(e,iso){ if(e.target.closest('.cl-gev,.cl-ev'))return; gcOpen({fecha:iso}); }

/* ---- Menú contextual (clic derecho) ---- */
function calMenu(x,y,items){var m=document.getElementById('calCtx');m.innerHTML='';
  items.forEach(function(it){ if(it.sep){var s=document.createElement('div');s.className='sep';m.appendChild(s);return;}
    var b=document.createElement('button');if(it.danger)b.className='danger';b.textContent=it.t;
    b.onclick=function(){m.classList.remove('on');it.fn();};m.appendChild(b);});
  m.style.left=Math.min(x,window.innerWidth-200)+'px';m.style.top=Math.min(y,window.innerHeight-40*items.length-10)+'px';m.classList.add('on');}
function cellMenu(e,iso){ if(e.target.closest('.cl-gev,.cl-ev'))return; e.preventDefault();
  calMenu(e.clientX,e.clientY,[{t:'Nuevo evento',fn:function(){gcOpen({fecha:iso});}},{t:'Nueva reunión (Meet)',fn:function(){gcOpen({fecha:iso,meet:true});}}]); }
function evMenu(e,data){ e.preventDefault();e.stopPropagation();var items=[];
  if(data.editable){ items.push({t:'Editar',fn:function(){_ev=data;evEdit();}});
    items.push({t:'Duplicar',fn:function(){gcOpen({titulo:data.titulo,fecha:data.dia,hora:data.allday?'':data.hora,hora_fin:data.hora_fin,invitados:data.invitados,location:data.location,descripcion:data.descripcion});}});
    items.push({sep:true}); items.push({t:'Eliminar',danger:true,fn:function(){_ev=data;evDel();}}); items.push({sep:true}); }
  items.push({t:'Ver en Google',fn:function(){if(data.link)window.open(data.link,'_blank');}});
  calMenu(e.clientX,e.clientY,items); }
document.addEventListener('mousedown',function(e){var m=document.getElementById('calCtx');if(m&&m.classList.contains('on')&&!m.contains(e.target))m.classList.remove('on');});

/* ---- Arrastrar un evento a otro día (vista Mes) ---- */
var _drag=null;
function evDragStart(e,data){_drag=data;e.currentTarget.classList.add('dragging');e.dataTransfer.effectAllowed='move';try{e.dataTransfer.setData('text/plain',data.id);}catch(x){}}
function cellDragOver(e){ if(_drag){e.preventDefault();e.dataTransfer.dropEffect='move';var c=e.currentTarget;c.classList.add('drop-ok');} }
document.addEventListener('dragleave',function(e){if(e.target.classList&&e.target.classList.contains('cl-cell'))e.target.classList.remove('drop-ok');});
document.addEventListener('dragend',function(){document.querySelectorAll('.dragging').forEach(function(x){x.classList.remove('dragging');});document.querySelectorAll('.drop-ok').forEach(function(x){x.classList.remove('drop-ok');});});
function cellDrop(e,iso){e.preventDefault();e.currentTarget.classList.remove('drop-ok');if(!_drag)return;var d=_drag;_drag=null;if(d.dia===iso)return;
  var body=new URLSearchParams();body.set('action','gcal_move');body.set('id',d.id);body.set('fecha',iso);if(!d.allday){body.set('hora',d.hora||'');body.set('hora_fin',d.hora_fin||'');}
  fetch('calendar.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}).then(function(r){return r.json();}).then(function(x){
    if(x&&x.ok){toast('Evento movido');setTimeout(function(){location.reload();},450);}else{toast((x&&x.msg)||'No se pudo mover','err');}});}
function gcClose(){document.getElementById('gcOv').classList.remove('on');}
function gcSave(){var id=_g('gcId').value,t=_g('gcTit').value.trim(),f=_g('gcFecha').value;
  var timed=_g('gcTimeWrap').style.display!=='none';
  if(!t){toast('Escribe un título','err');_g('gcTit').focus();return;} if(!f){toast('Elige una fecha','err');return;}
  var btn=_g('gcSave');btn.disabled=true;btn.textContent='Guardando…';
  var body=new URLSearchParams();body.set('action',id?'gcal_edit':'gcal_new');if(id)body.set('id',id);
  body.set('titulo',t);body.set('fecha',f);body.set('hora',timed?_g('gcHora').value:'');body.set('hora_fin',timed?_g('gcHoraFin').value:'');
  if(window.erpEmailMem)erpEmailMem.add(_g('gcInv').value);   // recuerda los correos escritos
  body.set('invitados',_g('gcInv').value);body.set('location',_g('gcLoc').value);body.set('descripcion',_g('gcDesc').value);body.set('recur',_g('gcRecur').value);
  body.set('recordar',_g('gcRecordar').value); if(_g('gcNotif').checked)body.set('notificar','1');
  if(!id&&_g('gcMeet').checked)body.set('meet','1');
  if(!id&&_g('gcMeet').checked&&_g('gcGem').checked)body.set('gemini','1');
  if(!id&&_gcErp)body.set('erp_meeting',_gcErp);   // enlaza el evento con la reunión del CRM
  fetch('calendar.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}).then(function(r){return r.json();}).then(function(d){
    btn.disabled=false;btn.textContent=id?'Guardar cambios':'Guardar';
    if(d&&d.ok){gcClose();toast(id?'Evento actualizado ✓':'Evento creado ✓');setTimeout(function(){location.reload();},600);}else{toast((d&&d.msg)||'No se pudo guardar','err');}
  }).catch(function(){btn.disabled=false;btn.textContent=id?'Guardar cambios':'Guardar';toast('Error de red','err');});}
document.addEventListener('keydown',function(e){
  if(e.key==='Escape'){gcClose();evClose();}
  if((e.metaKey||e.ctrlKey)&&e.key==='Enter'&&_g('gcOv').classList.contains('on')){e.preventDefault();gcSave();}
});
/* Invitar a un compañero (por nombre → su correo). */
function gcAddInv(email){var f=document.getElementById('gcInv');var v=f.value.trim();if(v&&v.indexOf(email)>=0)return;f.value=v?(v.replace(/,\s*$/,'')+', '+email):email;}
/* Autocompletado de correos al escribir invitados (equipo + contactos + clientes). */
var _gcInvSel=-1;
function gcInvType(){
  var inp=document.getElementById('gcInv');var toks=inp.value.split(',');var cur=(toks[toks.length-1]||'').trim().toLowerCase();
  var pop=document.getElementById('gcInvPop'); if(cur.length<1){gcInvClose();return;}
  var ya=toks.slice(0,-1).map(function(t){return t.trim().toLowerCase();});
  var _pool=(window.erpEmailMem?erpEmailMem.pool(window.GC_EMAILS||[]):(window.GC_EMAILS||[]));
  var m=_pool.filter(function(em){return em.indexOf(cur)>-1 && ya.indexOf(em)<0;}).slice(0,6);
  if(!m.length){gcInvClose();return;}
  _gcInvSel=-1;
  pop.innerHTML=m.map(function(em,i){return '<button type="button" data-i="'+i+'" onmousedown="event.preventDefault();gcInvPick('+i+')">'+escHtml(em)+'</button>';}).join('');
  pop.dataset.opts=JSON.stringify(m);pop.classList.add('on');
}
function gcInvPick(i){var pop=document.getElementById('gcInvPop');var opts=[];try{opts=JSON.parse(pop.dataset.opts||'[]');}catch(_){}
  var em=opts[i];if(!em)return;var inp=document.getElementById('gcInv');var toks=inp.value.split(',');toks[toks.length-1]=' '+em;
  inp.value=toks.join(',').replace(/^\s+/,'')+', ';gcInvClose();inp.focus();}
function gcInvKey(e){var pop=document.getElementById('gcInvPop');if(!pop.classList.contains('on'))return;var btns=pop.querySelectorAll('button');
  if(e.key==='ArrowDown'){e.preventDefault();_gcInvSel=Math.min(_gcInvSel+1,btns.length-1);}
  else if(e.key==='ArrowUp'){e.preventDefault();_gcInvSel=Math.max(_gcInvSel-1,0);}
  else if(e.key==='Enter'&&_gcInvSel>=0){e.preventDefault();gcInvPick(_gcInvSel);return;}
  else if(e.key==='Escape'){gcInvClose();return;}
  else return;
  btns.forEach(function(b,i){b.classList.toggle('sel',i===_gcInvSel);});}
function gcInvClose(){var p=document.getElementById('gcInvPop');if(p){p.classList.remove('on');p.innerHTML='';}}

/* ---- Arrastrar y redimensionar eventos (Semana/Día) ---- */
(function(){
  var ROW=<?= $ROW ?>, SNAP=15, st=null;
  function tip(){var t=document.getElementById('tgTip');if(!t){t=document.createElement('div');t.id='tgTip';t.className='tg-tip';document.body.appendChild(t);}return t;}
  function fmt(m){m=((m%1440)+1440)%1440;var h=Math.floor(m/60),mm=m%60;return (h<10?'0':'')+h+':'+(mm<10?'0':'')+mm;}
  function yToMin(clientY,col){var r=col.getBoundingClientRect();var y=clientY-r.top;var m=Math.round((y/ROW*60)/SNAP)*SNAP;return Math.max(0,Math.min(24*60,m));}
  function showTip(x,y,a,b){var t=tip();t.textContent=fmt(a)+' – '+fmt(b);t.style.left=(x+14)+'px';t.style.top=(y+10)+'px';t.classList.add('on');}
  function hideTip(){var t=document.getElementById('tgTip');if(t)t.classList.remove('on');}
  document.addEventListener('mousedown',function(e){
    var ev=e.target.closest&&e.target.closest('.tg-tev.tg-mine'); if(!ev||e.button!==0)return;
    var handle=e.target.classList.contains('tg-rz')?(e.target.classList.contains('tg-rz-t')?'top':'bot'):null;
    var col=ev.closest('.tg-col');
    st={ev:ev,col:col,mode:handle?('rz-'+handle):'move',smin:+ev.dataset.smin,dur:+ev.dataset.dur,startY:e.clientY,startX:e.clientX,moved:false};
    st.grab=yToMin(e.clientY,col)-st.smin;
    e.preventDefault();
    document.addEventListener('mousemove',mv);document.addEventListener('mouseup',up);
  });
  function mv(e){ if(!st)return; if(!st.moved && (Math.abs(e.clientY-st.startY)+Math.abs(e.clientX-st.startX))<4)return; st.moved=true; st.ev.classList.add('tg-drag');
    var col=st.col;
    if(st.mode==='move'){ var el=document.elementFromPoint(e.clientX,e.clientY); var c=el&&el.closest?el.closest('.tg-col'):null; if(c){col=c;st.curCol=c;} var top=yToMin(e.clientY,col)-st.grab; top=Math.max(0,Math.min(24*60-st.dur,Math.round(top/SNAP)*SNAP)); st.curStart=top; st.ev.style.top=(top/60*ROW)+'px'; if(c&&c!==st.ev.parentNode){c.appendChild(st.ev);} showTip(e.clientX,e.clientY,top,top+st.dur); }
    else if(st.mode==='rz-bot'){ var end=yToMin(e.clientY,col); if(end<st.smin+SNAP)end=st.smin+SNAP; st.curEnd=end; st.ev.style.height=((end-st.smin)/60*ROW)+'px'; showTip(e.clientX,e.clientY,st.smin,end); }
    else{ var tp=yToMin(e.clientY,col); if(tp>st.smin+st.dur-SNAP)tp=st.smin+st.dur-SNAP; st.curStart=tp; st.ev.style.top=(tp/60*ROW)+'px'; st.ev.style.height=((st.smin+st.dur-tp)/60*ROW)+'px'; showTip(e.clientX,e.clientY,tp,st.smin+st.dur); }
  }
  function up(e){ document.removeEventListener('mousemove',mv);document.removeEventListener('mouseup',up);hideTip();
    if(!st)return; var s=st; st=null; s.ev.classList.remove('tg-drag');
    if(!s.moved)return; // fue un clic normal → lo maneja onclick
    var sup=function(ev){ev.stopPropagation();ev.preventDefault();document.removeEventListener('click',sup,true);}; document.addEventListener('click',sup,true);
    var dia=(s.curCol||s.col).dataset.iso, start, end;
    if(s.mode==='move'){start=(s.curStart!=null?s.curStart:s.smin);end=start+s.dur;}
    else if(s.mode==='rz-bot'){start=s.smin;end=(s.curEnd!=null?s.curEnd:s.smin+s.dur);}
    else{start=(s.curStart!=null?s.curStart:s.smin);end=s.smin+s.dur;}
    var body=new URLSearchParams();body.set('action','gcal_move');body.set('id',_moveId(s.ev));body.set('fecha',dia);body.set('hora',fmt(start));body.set('hora_fin',fmt(end));
    fetch('calendar.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}).then(function(r){return r.json();}).then(function(x){
      if(x&&x.ok){toast('Evento actualizado');setTimeout(function(){location.reload();},350);}else{toast((x&&x.msg)||'No se pudo mover','err');location.reload();}});
  }
  /* El id del evento se saca del onclick (evShow(this,{...})). */
  function _moveId(ev){ try{ var m=(ev.getAttribute('onclick')||'').match(/evShow\(this,(\{.*\})\)/); if(m){var o=JSON.parse(m[1]);return o.id;} }catch(x){} return ''; }
})();
<?php endif; ?>
</script>
<script>(function(){var g=<?= json_encode($_GET['gc'] ?? '') ?>;if(!g||!window.toast)return;if(g==='ok')toast('Google Calendar conectado ✓');else if(g==='off')toast('Google Calendar desconectado');else if(g==='err')toast('No se pudo conectar con Google','err');})();</script>
<?php erp_foot(); ?>
