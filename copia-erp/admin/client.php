<?php
/* ===========================================================
   FICHA / HUB del cliente. Rediseño limpio (estilo dashboard):
   cabecera + barra de ACCIONES operativas + resumen + tarjetas
   ordenadas. Toda la lógica de datos es la de siempre.
   =========================================================== */
require_once __DIR__ . '/_layout.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
/* Hasta dónde ve cada uno: quien no tenga «Ve todos los clientes» solo abre las
   fichas de los suyos. Sin esto, el filtro del listado se saltaría escribiendo
   el id en la barra de direcciones. */
if ($id && function_exists('alcance_exigir_cliente')) alcance_exigir_cliente($id);
$st = db()->prepare('SELECT c.*, t.nombre AS tipo_nombre FROM clients c LEFT JOIN client_types t ON t.id=c.tipo_id WHERE c.id = ?');
$st->execute([$id]);
$c = $st->fetch();
if (!$c) { header('Location: index.php'); exit; }

/* Restablecer la contraseña de acceso del cliente al portal, de un clic. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_pass') {
    require_can_edit();
    require_once __DIR__ . '/lib/puentes.php';   // pu_password()
    $nueva = function_exists('pu_password') ? pu_password() : bin2hex(random_bytes(4));
    db()->prepare('UPDATE clients SET password_hash=? WHERE id=?')->execute([password_hash($nueva, PASSWORD_DEFAULT), $id]);
    $_SESSION['cli_newpass'] = $nueva;
    header('Location: client.php?id=' . $id); exit;
}
$nuevaPass = $_SESSION['cli_newpass'] ?? null; if ($nuevaPass !== null) unset($_SESSION['cli_newpass']);

$estado  = jdecode($c['estado_json'], ['nombre'=>'','etiqueta'=>'','siguiente'=>'','fases'=>[]]);
$plan    = jdecode($c['plan_json'], ['resumen'=>'','items'=>[],'detalle'=>[]]);
$accesos = jdecode($c['accesos_json'], []);
$met     = jdecode($c['met_json'], []);
$tareas  = jdecode($c['tareas_json'], []);

// resumen métricas
$mesesMet = array_keys($met);
$ultimoMes = count($mesesMet) ? end($mesesMet) : '';
$ult = $ultimoMes !== '' ? $met[$ultimoMes] : null;
$oportUlt = $ult ? ((int)($ult['ll']??0)+(int)($ult['wa']??0)+(int)($ult['fo']??0)) : 0;
// resumen tareas
$nTareas=0;$nPend=0;
foreach ($tareas as $g){ $nTareas += count($g['completado']??[])+count($g['pendiente']??[]); $nPend += count($g['pendiente']??[]); }
// backlog de listas de trabajo (gestor interno)
$listsHub = db()->prepare('SELECT l.id, l.nombre, l.es_cliente,
    (SELECT COUNT(*) FROM tasks t WHERE t.list_id=l.id) cnt,
    (SELECT COUNT(*) FROM tasks t WHERE t.list_id=l.id AND t.estado<>\'completada\') pend
  FROM task_lists l WHERE l.client_id=? ORDER BY l.orden,l.id');
$listsHub->execute([$id]); $listsHub=$listsHub->fetchAll();
try { $st2=db()->prepare('SELECT COUNT(*) FROM client_credentials WHERE client_id=?'); $st2->execute([$id]); $nCreds=(int)$st2->fetchColumn(); } catch(Exception $e){ $nCreds=0; }
$credList=[]; try { $cq=db()->prepare('SELECT id,titulo,categoria,url,usuario,secreto,nota FROM client_credentials WHERE client_id=? ORDER BY orden,id LIMIT 6'); $cq->execute([$id]); $credList=$cq->fetchAll(); } catch(Exception $e){}
/* Mismas categorías que la bóveda (credenciales.php) para el icono y la etiqueta. */
$CRED_CATS = ['web'=>['Web','link'],'correo'=>['Correo','inbox'],'hosting'=>['Hosting','vault'],'database'=>['Base de datos','settings'],'api'=>['API / Token','settings'],'cms'=>['CMS','settings'],'domain'=>['Dominio','link'],'social'=>['Redes','link'],'other'=>['Otro','vault']];
/* facturas del cliente */
$facs=[]; $facN=0;$facCob=0;$facPen=0;
try {
  $ESTF=['borrador'=>['Borrador','#9aa0a8'],'enviada'=>['Enviada','#3b82f6'],'pagada'=>['Pagada','#12a150'],'vencida'=>['Vencida','#ef4444']];
  $fq=db()->prepare("SELECT i.*, (SELECT COALESCE(SUM(cantidad*precio),0) FROM invoice_items WHERE invoice_id=i.id) base FROM invoices i WHERE i.client_id=? ORDER BY i.fecha DESC, i.id DESC");
  $fq->execute([$id]); $allf=$fq->fetchAll();
  foreach($allf as $iv){ $facN++; $tt=$iv['base']*(1+$iv['iva_pct']/100-$iv['irpf_pct']/100); if($iv['estado']==='pagada')$facCob+=$tt; elseif($iv['estado']!=='borrador')$facPen+=$tt; }
  $facs=array_slice($allf,0,5);
} catch(Exception $e){ $ESTF=[]; }
/* Soporte del cliente */
$tks=[]; $tkAbiertos=0;
try {
  $tq=db()->prepare("SELECT id,asunto,estado,prioridad,updated_at FROM support_tickets WHERE client_id=?
                     ORDER BY FIELD(estado,'abierto','en_curso','esperando','resuelto','cerrado'), FIELD(prioridad,4,3,2,1), updated_at DESC");
  $tq->execute([$id]); $allt=$tq->fetchAll();
  foreach($allt as $t){ if(in_array($t['estado'],['abierto','en_curso','esperando'],true)) $tkAbiertos++; }
  $tks=array_slice($allt,0,4);
} catch(Exception $e){}
$ESTT=['abierto'=>['Abierto','#3b82f6'],'en_curso'=>['En curso','#7b68ee'],'esperando'=>['Esperando','#e0a000'],'resuelto'=>['Resuelto','#12a150'],'cerrado'=>['Cerrado','#9aa0a8']];
$PRIT=[1=>['Baja','#94a3b8'],2=>['Normal','#3b82f6'],3=>['Alta','#f59e0b'],4=>['Urgente','#ef4444']];

/* De qué lead salió este cliente */
$org=null;
try {
  if (!empty($c['contact_id'])) {
    $oq=db()->prepare('SELECT id,nombre,empresa,email,telefono,whatsapp,fase,origen_lead FROM contacts WHERE id=?');
    $oq->execute([(int)$c['contact_id']]); $org=$oq->fetch() ?: null;
  }
  if (!$org) {
    $oq=db()->prepare('SELECT id,nombre,empresa,email,telefono,whatsapp,fase,origen_lead FROM contacts WHERE client_id=? ORDER BY id LIMIT 1');
    $oq->execute([$id]); $org=$oq->fetch() ?: null;
  }
} catch(Exception $e){}

/* Datos fiscales, en lectura */
$FISC = [
  ['Nombre fiscal', trim((string)($c['fact_nombre'] ?? '')) ?: (string)$c['name']],
  ['NIF / CIF',     trim((string)($c['fact_nif'] ?? ''))],
  ['Dirección',     trim((string)($c['fact_dir'] ?? ''))],
  ['Email factura', trim((string)($c['fact_email'] ?? ''))],
];
$fiscHuecos = 0; foreach($FISC as $f){ if($f[1]==='') $fiscHuecos++; }

function ini2($s){ return e(mb_strtoupper(mb_substr((string)$s,0,2))); }

/* Datos para el botón operativo «Agendar reunión» (prefill del calendario). */
$manana = date('Y-m-d', strtotime('+1 day'));
$agEmail = trim((string)($c['fact_email'] ?? '')); if ($agEmail==='' && $org) $agEmail = (string)($org['email'] ?? '');
$agUrl = 'calendar.php?view=dia&new=1&d='.$manana.'&hora=10:00&titulo='.rawurlencode('Reunión con '.$c['name']).($agEmail?('&invitados='.rawurlencode($agEmail)):'');

ahead('Ficha de '.$c['name']);
?>
<style>
.cf-head{display:flex;align-items:center;gap:15px;margin-bottom:20px;flex-wrap:wrap}
.cf-av{width:54px;height:54px;border-radius:16px;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:20px;flex:none}
.cf-hid{flex:1;min-width:0}
.cf-hid h1{margin:0;font-size:25px;font-weight:650;letter-spacing:-.4px}
.cf-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:9px}
.cf-chip{font-size:11.5px;font-weight:600;color:#52555c;background:var(--soft);border:1px solid var(--line);border-radius:99px;padding:4px 12px}
.cf-headacts{display:flex;gap:8px;flex-wrap:wrap}
.cf-btn{display:inline-flex;align-items:center;gap:7px;white-space:nowrap;font-size:13px;font-weight:600;color:var(--ink-strong);background:#fff;border:1px solid var(--line);border-radius:11px;padding:10px 15px;text-decoration:none;cursor:pointer;transition:.12s}
.cf-btn:hover{border-color:#d5d7dc;box-shadow:0 4px 14px rgba(0,0,0,.06)}
.cf-btn svg{width:15px;height:15px}
/* barra de acciones operativas */
.cf-quick{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:26px}
@media(max-width:1080px){.cf-quick{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.cf-quick{grid-template-columns:repeat(2,1fr)}}
.cf-q{display:flex;flex-direction:column;gap:11px;padding:18px;background:#fff;border:1px solid var(--line);border-radius:14px;text-decoration:none;color:inherit;transition:.12s}
.cf-q:hover{border-color:#dcdee2;transform:translateY(-2px);box-shadow:0 12px 26px -18px rgba(0,0,0,.5)}
.cf-q .qi{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;flex:none}
.cf-q .qi svg{width:17px;height:17px}
.cf-q b{font-size:13.5px;font-weight:650;color:var(--ink-strong)}
.cf-q small{font-size:11px;color:var(--muted);line-height:1.5}
/* stats */
.cf-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
@media(max-width:720px){.cf-stats{grid-template-columns:1fr 1fr}}
.cf-stat{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px 20px}
.cf-stat span{font-size:12px;color:var(--muted);font-weight:500}
.cf-stat b{display:block;font-size:27px;font-weight:700;letter-spacing:-.6px;margin-top:8px;color:var(--ink-strong)}
.cf-stat b.sm{font-size:17px;margin-top:10px}
/* grid de tarjetas */
.cf-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:stretch}
/* Al reaparecer la barra lateral (~1024px) el contenido se estrecha: reflow a una
   sola columna para no desbordar. En 1280/1440 vuelven las dos columnas. */
@media(max-width:1024px){.cf-grid{grid-template-columns:minmax(0,1fr)}}
.cf-col{display:flex;flex-direction:column;gap:20px;min-width:0}
/* La última tarjeta de cada columna se estira para rellenar el hueco: así las dos
   columnas quedan SIEMPRE a la misma altura (normalmente le toca a Soporte). */
.cf-col > .cf-card:last-child{flex:1 0 auto}
.cf-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:26px 28px}
.cf-ch{display:flex;align-items:center;gap:8px;margin-bottom:20px;padding-bottom:2px}
.cf-card .cf-fisc{margin-top:4px}
.cf-ch h3{margin:0;font-size:12px;font-weight:650;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);display:inline-flex;align-items:center;gap:9px}
.cf-ch h3 svg{width:15px;height:15px;color:var(--muted);flex:none}
.cf-ch a{margin-left:auto;font-size:12px;font-weight:600;color:#0071e3;text-decoration:none}
.cf-ch a:hover{text-decoration:underline}
.cf-row{display:flex;align-items:center;gap:13px;padding:14px 2px;border-top:1px solid var(--line2);text-decoration:none;color:inherit}
.cf-list .cf-row:first-child{border-top:none}
.cf-ri{width:30px;height:30px;border-radius:9px;background:var(--soft);color:#22242a;display:flex;align-items:center;justify-content:center;flex:none}
.cf-ri svg{width:15px;height:15px}
.cf-rt{flex:1;min-width:0}.cf-rt b{font-weight:600;font-size:13.5px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cf-rt span{font-size:12px;color:var(--muted)}
.cf-rv{font-size:12.5px;color:var(--muted);font-weight:600;flex:none}
.cf-empty{color:var(--muted);font-size:13px;padding:8px 2px}
.cf-mini{font-size:9px;background:var(--accent-soft);color:var(--accent);border-radius:5px;padding:1px 6px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;margin-left:6px}
.sbadge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:650;border-radius:99px;padding:2px 9px}
.sbadge .d{width:5px;height:5px;border-radius:50%}
.cf-fisc{display:grid;grid-template-columns:1fr 1fr;gap:18px 24px;padding:2px 0}
@media(max-width:520px){.cf-fisc{grid-template-columns:1fr}}
.cf-fisc div span{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:650;margin-bottom:5px}
.cf-fisc div b{font-size:14px;font-weight:600;color:var(--ink-strong);word-break:break-word;line-height:1.5}
.cf-fisc div b.vacio{color:var(--muted);font-weight:400}
.cf-pbar{display:flex;gap:5px;margin:10px 0 6px}
.cf-pbar i{flex:1;height:6px;border-radius:99px;background:#eceef1}.cf-pbar i.on{background:var(--accent)}
.cf-pass{margin-top:10px;background:#eef7f0;border:1px solid #cde8d5;border-radius:12px;padding:14px 16px;font-size:13px;line-height:1.5;color:#12603a}
/* Credenciales · acceso rápido — mismas tarjetas que la bóveda, en la columna derecha */
.cf-cred-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}
.cf-cred{border:1px solid var(--line);border-radius:16px;background:#fff;padding:22px}
.cf-cred-h{display:flex;align-items:center;gap:11px;margin-bottom:16px}
.cf-cred-ic{width:36px;height:36px;border-radius:10px;background:var(--soft);color:#22242a;display:flex;align-items:center;justify-content:center;flex:none}
.cf-cred-ic svg{width:18px;height:18px}
.cf-cred-tt{flex:1;min-width:0}.cf-cred-tt b{font-size:14.5px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--ink-strong)}
.cf-cred-tt span{font-size:9.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700}
.cf-cred-f{background:#f7f8fa;border-radius:11px;padding:11px 13px;margin-bottom:11px}
.cf-cred-f label{font-size:9.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;display:block;margin-bottom:3px}
.cf-cred-v{display:flex;align-items:center;gap:8px}
.cf-cred-v code{flex:1;min-width:0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;color:var(--ink-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cf-cred-v button{border:none;background:none;color:var(--label);cursor:pointer;padding:3px;border-radius:6px;display:inline-flex;flex:none}
.cf-cred-v button:hover{background:#e9ebef;color:#6b7280}
.cf-cred-v button svg{width:15px;height:15px}
.cf-cred-link{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--ink-strong);font-weight:600;margin-top:2px;text-decoration:none}
.cf-cred-link:hover{text-decoration:underline}
.cf-cred-link svg{width:14px;height:14px}
.cf-danger{margin-top:26px;padding-top:20px;border-top:1px solid var(--line);display:flex;align-items:center;gap:12px}
/* ---- Modo oscuro: remapea SOLO las superficies y textos propios de esta ficha
   a las variables del tema. No toca el modo claro ni los colores de marca. ---- */
[data-theme=dark] .cf-chip{color:var(--ink)}
[data-theme=dark] .cf-btn{background-color:var(--card)}
[data-theme=dark] .cf-btn:hover{border-color:var(--line-strong)}
[data-theme=dark] .cf-q{background-color:var(--card)}
[data-theme=dark] .cf-q:hover{border-color:var(--line-strong)}
[data-theme=dark] .cf-stat{background-color:var(--card)}
[data-theme=dark] .cf-card{background-color:var(--card)}
[data-theme=dark] .cf-ri{color:var(--ink)}
[data-theme=dark] .cf-pbar i{background-color:var(--soft)}
[data-theme=dark] .cf-pass{background-color:var(--ok-bg);border-color:var(--ok-line);color:var(--ok)}
[data-theme=dark] .cf-cred{background-color:var(--card)}
[data-theme=dark] .cf-cred-ic{color:var(--ink)}
[data-theme=dark] .cf-cred-f{background-color:var(--soft)}
[data-theme=dark] .cf-cred-v button{color:var(--muted)}
[data-theme=dark] .cf-cred-v button:hover{background-color:var(--soft);color:var(--ink)}
/* ---- Móvil (teléfono): todo a una columna, cómodo con el pulgar ---- */
@media(max-width:640px){
  .cf-head{gap:12px;margin-bottom:16px}
  .cf-av{width:44px;height:44px;border-radius:13px;font-size:16px}
  .cf-hid h1{font-size:20px}
  .cf-headacts{width:100%}
  .cf-headacts .cf-btn{flex:1;justify-content:center}
  /* Accesos y estadísticas: SIEMPRE dos en fila y compactos (nada de cajas
     gigantes apiladas). Se mantienen 2 columnas incluso en pantallas estrechas. */
  .cf-quick{grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px}
  .cf-q{padding:12px;gap:8px;border-radius:12px}
  .cf-q .qi{width:30px;height:30px;border-radius:9px}
  .cf-stats{grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px}
  .cf-stat{padding:12px 14px;border-radius:12px}
  .cf-stat b{font-size:20px;margin-top:5px}
  .cf-stat b.sm{font-size:15px;margin-top:6px}
  .cf-grid{gap:12px;grid-template-columns:minmax(0,1fr)}
  .cf-col{gap:12px;min-width:0}
  .cf-card{padding:16px 16px;border-radius:14px;min-width:0}
  /* Los valores largos de credenciales (usuario/URL/API) PARTEN, no desbordan la pantalla */
  .cf-cred code,.cf-cred a,.cf-cred span,.cf-cred b,.cf-cred .cf-cred-f{overflow-wrap:anywhere;word-break:break-word;min-width:0}
  .cf-ch{margin-bottom:14px}
  .cf-row{padding:11px 2px;gap:11px}
  .cf-fisc{grid-template-columns:1fr;gap:12px}
  .cf-cred-grid{grid-template-columns:minmax(0,1fr);min-width:0}
  .cf-cred{padding:16px}
  .cf-danger{flex-direction:column;align-items:stretch;gap:10px}
  .cf-danger .btn{text-align:center}
}
</style>

<!-- CABECERA -->
<div class="cf-head">
  <div class="cf-av" style="background:<?= avatar_color($c['name']) ?>"><?= ini2($c['name']) ?></div>
  <div class="cf-hid">
    <h1><?= e($c['name']) ?></h1>
    <div class="cf-chips">
      <span class="cf-chip"><?= $c['tipo_nombre'] ? e($c['tipo_nombre']) : 'Sin tipo' ?></span>
      <span class="cf-chip">usuario: <?= e($c['username']) ?></span>
      <span class="cf-chip"><?= $c['conversiones'] ? 'Con métricas' : 'Solo web' ?></span>
      <?php if($c['actual']): ?><span class="cf-chip">Mes: <?= e($c['actual']) ?></span><?php endif; ?>
    </div>
  </div>
  <div class="cf-headacts">
    <a class="cf-btn" href="../index.php?cli=<?= (int)$c['id'] ?>" target="_blank"><?= ic('eye',15) ?> Ver como cliente</a>
    <a class="cf-btn" href="index.php"><?= ic('back',15) ?> Clientes</a>
  </div>
</div>

<?php if (isset($_GET['dup'])): ?>
<div class="ok-note">Copia creada. Cámbiale el <b>usuario</b> y la <b>contraseña</b> entrando en “Editar ficha”.</div>
<?php endif; ?>

<!-- ACCIONES OPERATIVAS -->
<div class="cf-quick">
  <?php if (can_edit()): ?>
  <a class="cf-q" href="../index.php?cli=<?= (int)$c['id'] ?>&edit=1"><span class="qi" style="background:#64748b"><?= ic('settings',17) ?></span><b>Editar en vivo</b><small>Su portal con lápices</small></a>
  <a class="cf-q" href="#" onclick="erpTarea({clientId:<?= (int)$c['id'] ?>,clientName:<?= e(json_encode($c['name'], JSON_UNESCAPED_UNICODE)) ?>,lists:<?= e(json_encode(array_map(fn($l)=>['id'=>(int)$l['id'],'nombre'=>$l['nombre']],$listsHub), JSON_UNESCAPED_UNICODE)) ?>});return false"><span class="qi" style="background:#e0a000"><?= ic('check',17) ?></span><b>Nueva tarea</b><small>Popup rápido</small></a>
  <a class="cf-q" href="#" onclick="erpAgendar({titulo:<?= e(json_encode('Reunión con '.$c['name'], JSON_UNESCAPED_UNICODE)) ?>,email:<?= e(json_encode($agEmail, JSON_UNESCAPED_UNICODE)) ?>,whatsapp:<?= e(json_encode($org?($org['whatsapp']?:$org['telefono']):'', JSON_UNESCAPED_UNICODE)) ?>,nombre:<?= e(json_encode($org?$org['nombre']:$c['name'], JSON_UNESCAPED_UNICODE)) ?>,contactId:<?= $org?(int)$org['id']:0 ?>});return false"><span class="qi" style="background:#4285F4"><?= ic('cal',17) ?></span><b>Agendar reunión</b><small>Popup rápido</small></a>
  <a class="cf-q" href="workspace.php?view=cliente&cli=<?= (int)$c['id'] ?>&informe=1"><span class="qi" style="background:#0ea5e9"><?= ic('file',17) ?></span><b>Informe del mes</b><small>Lo que verá en su portal</small></a>
  <?php if ($c['conversiones']): ?>
  <a class="cf-q" href="conversiones.php?cli=<?= (int)$c['id'] ?>"><span class="qi" style="background:#a855f7"><?= ic('chart',17) ?></span><b>Métricas</b><small>Web · Analytics · conversiones</small></a>
  <?php endif; ?>
  <a class="cf-q" href="facturas.php?new=1&cli=<?= (int)$c['id'] ?>"><span class="qi" style="background:#34c759"><?= ic('euro',17) ?></span><b>Nueva factura</b><small>Emitir y cobrar</small></a>
  <a class="cf-q" href="#" onclick="erpTicket({clientId:<?= (int)$c['id'] ?>,clientName:<?= e(json_encode($c['name'], JSON_UNESCAPED_UNICODE)) ?>});return false"><span class="qi" style="background:#ef4444"><?= ic('ticket',17) ?></span><b>Abrir ticket</b><small>Popup rápido</small></a>
  <a class="cf-q" href="edit.php?id=<?= (int)$c['id'] ?>"><span class="qi" style="background:#5e5ce6"><?= ic('pencil',17) ?></span><b>Editar ficha</b><small>Campo a campo</small></a>
  <?php else: ?>
  <a class="cf-q" href="workspace.php?view=cliente&cli=<?= (int)$c['id'] ?>"><span class="qi" style="background:#e0a000"><?= ic('check',17) ?></span><b>Tareas</b><small>Backlog del cliente</small></a>
  <a class="cf-q" href="facturas.php?cli=<?= (int)$c['id'] ?>"><span class="qi" style="background:#34c759"><?= ic('euro',17) ?></span><b>Facturas</b><small>Ver del cliente</small></a>
  <a class="cf-q" href="support.php?cli=<?= (int)$c['id'] ?>"><span class="qi" style="background:#ef4444"><?= ic('ticket',17) ?></span><b>Soporte</b><small>Tickets del cliente</small></a>
  <?php endif; ?>
</div>

<!-- RESUMEN -->
<div class="cf-stats">
  <div class="cf-stat"><span>Oportunidades<?= $ultimoMes?' · '.e($ultimoMes):'' ?></span><b><?= $ult?$oportUlt:'—' ?></b></div>
  <div class="cf-stat"><span>Tareas en curso</span><b><?= $nPend ?></b></div>
  <div class="cf-stat"><span>Soporte abierto</span><b<?= $tkAbiertos?' style="color:#3b82f6"':'' ?>><?= (int)$tkAbiertos ?></b></div>
  <div class="cf-stat"><span>Cobrado</span><b class="sm"><?= eur_vis(eur($facCob)) ?></b></div>
</div>

<!-- TARJETAS -->
<div class="cf-grid">
  <div class="cf-col">
    <!-- Tareas -->
    <div class="cf-card">
      <div class="cf-ch"><h3><?= ic('check',14) ?>Tareas y backlog</h3><a href="workspace.php?view=cliente&cli=<?= (int)$c['id'] ?>">Abrir →</a></div>
      <div class="cf-list">
        <?php if ($listsHub): foreach ($listsHub as $l): ?>
          <a class="cf-row" href="workspace.php?view=cliente&cli=<?= (int)$c['id'] ?>&list=<?= (int)$l['id'] ?>">
            <span class="cf-ri"><?= ic('list',15) ?></span>
            <span class="cf-rt"><b><?= e($l['nombre']) ?><?php if($l['es_cliente']): ?><span class="cf-mini">cliente</span><?php endif; ?></b></span>
            <span class="cf-rv"><?= (int)$l['pend'] ?> abiertas · <?= (int)$l['cnt'] ?></span>
          </a>
        <?php endforeach; else: ?><div class="cf-empty">Sin listas de trabajo todavía.</div><?php endif; ?>
      </div>
    </div>

    <!-- Facturas -->
    <div class="cf-card">
      <div class="cf-ch"><h3><?= ic('euro',14) ?>Facturas</h3><a href="facturas.php?cli=<?= (int)$c['id'] ?>">Abrir →</a></div>
      <a class="cf-row" href="facturas.php?cli=<?= (int)$c['id'] ?>">
        <span class="cf-ri"><?= ic('file',15) ?></span>
        <span class="cf-rt"><b><?= (int)$facN ?> factura<?= $facN==1?'':'s' ?></b><span>Cobrado <?= eur_vis(eur($facCob)) ?> · Pendiente <?= eur_vis(eur($facPen)) ?></span></span>
      </a>
      <div class="cf-list">
        <?php foreach($facs as $iv): $ev=$ESTF[$iv['estado']]??['·','#9aa0a8']; $tt=$iv['base']*(1+$iv['iva_pct']/100-$iv['irpf_pct']/100); ?>
          <a class="cf-row" href="facturas.php?v=<?= (int)$iv['id'] ?>">
            <span class="cf-rt"><b><?= e($iv['numero']) ?></b><span><?= e(date('d/m/Y',strtotime($iv['fecha']))) ?> · <span style="color:<?= $ev[1] ?>;font-weight:600"><?= e($ev[0]) ?></span></span></span>
            <span class="cf-rv"><?= eur_vis(eur($tt)) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Soporte -->
    <div class="cf-card">
      <div class="cf-ch"><h3><?= ic('ticket',14) ?>Soporte</h3><a href="support.php?cli=<?= (int)$c['id'] ?>">Abrir →</a></div>
      <div class="cf-list">
        <?php if ($tks): foreach($tks as $t): $ev=$ESTT[$t['estado']]??$ESTT['abierto']; $pv=$PRIT[(int)$t['prioridad']]??$PRIT[2]; ?>
          <a class="cf-row" href="support.php?t=<?= (int)$t['id'] ?>">
            <span class="cf-ri"><?= ic('ticket',15) ?></span>
            <span class="cf-rt"><b><?= e($t['asunto']) ?></b><span>#<?= (int)$t['id'] ?> · <?= e($pv[0]) ?> · <?= e(date('d/m/Y',strtotime($t['updated_at']))) ?></span></span>
            <span class="cf-rv"><span class="sbadge" style="background:<?= $ev[1] ?>18;color:<?= $ev[1] ?>"><span class="d" style="background:<?= $ev[1] ?>"></span><?= e($ev[0]) ?></span></span>
          </a>
        <?php endforeach; else: ?>
          <div class="cf-empty">Sin tickets<?php if(can_edit()): ?> · <a href="#" onclick="erpTicket({clientId:<?= (int)$c['id'] ?>,clientName:<?= e(json_encode($c['name'], JSON_UNESCAPED_UNICODE)) ?>});return false" style="color:var(--accent);font-weight:600">abrir uno →</a><?php endif; ?></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($org): ?>
    <!-- Contacto de origen -->
    <div class="cf-card">
      <div class="cf-ch"><h3><?= ic('crm',14) ?>Contacto de origen</h3><a href="crm.php?open=<?= (int)$org['id'] ?>">Ver en CRM →</a></div>
      <a class="cf-row" href="crm.php?open=<?= (int)$org['id'] ?>">
        <span class="cf-ri"><?= ic('user',15) ?></span>
        <span class="cf-rt"><b><?= e($org['nombre']) ?></b><span><?= e(implode(' · ', array_filter([$org['empresa'], $org['email'], $org['telefono']?:$org['whatsapp']]))) ?: 'Sin datos de contacto' ?></span></span>
        <span class="cf-rv"><?= $org['origen_lead'] ? e($org['origen_lead']) : ic('chevron',15) ?></span>
      </a>
    </div>
    <?php endif; ?>
  </div>

  <div class="cf-col">
    <!-- Acceso al portal -->
    <div class="cf-card">
      <div class="cf-ch"><h3><?= ic('user',14) ?>Acceso al portal</h3></div>
      <div class="cf-row" style="cursor:default">
        <span class="cf-ri"><?= ic('user',15) ?></span>
        <span class="cf-rt"><b>Usuario: <?= e($c['username'] ?: '—') ?></b><span>Entra en el portal del cliente</span></span>
        <?php if (can_edit()): ?>
        <form method="post" style="margin:0" onsubmit="return erpSubmitAsk(this,'Se generará una contraseña nueva y la actual dejará de valer. Tendrás que pasársela al cliente.',{titulo:'¿Restablecer contraseña?',ok:'Restablecer'})">
          <input type="hidden" name="action" value="reset_pass">
          <button class="cf-btn" type="submit" style="padding:7px 11px"><?= ic('bolt',14) ?> Restablecer</button>
        </form>
        <?php endif; ?>
      </div>
      <?php if ($nuevaPass !== null): ?>
      <div class="cf-pass">
        Contraseña nueva de <b><?= e($c['username']) ?></b>: <code style="background:#fff;border:1px solid #cde8d5;border-radius:7px;padding:2px 8px;font-size:13.5px;font-weight:700;user-select:all"><?= e($nuevaPass) ?></code>
        <div style="margin-top:5px;color:#3c7a56;font-size:12px">Apúntala y pásasela al cliente: no se vuelve a mostrar.</div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Datos fiscales -->
    <div class="cf-card">
      <div class="cf-ch"><h3><?= ic('file',14) ?>Datos fiscales</h3><?php if(can_edit()): ?><a href="edit.php?id=<?= (int)$c['id'] ?>#fact">Editar →</a><?php endif; ?></div>
      <div class="cf-fisc">
        <?php foreach($FISC as $f): ?>
          <div><span><?= e($f[0]) ?></span><b class="<?= $f[1]===''?'vacio':'' ?>"><?= $f[1]!=='' ? e($f[1]) : '—' ?></b></div>
        <?php endforeach; ?>
      </div>
      <?php if ($fiscHuecos && $facN): ?><div class="cf-empty" style="padding-top:10px">Faltan <?= (int)$fiscHuecos ?> dato<?= $fiscHuecos==1?'':'s' ?> de facturación y ya hay facturas emitidas.</div><?php endif; ?>
    </div>

    <!-- Credenciales · acceso rápido (en la columna derecha) -->
    <div class="cf-card cf-credsec">
      <div class="cf-ch"><h3><?= ic('vault',14) ?>Credenciales · acceso rápido</h3><a href="credenciales.php?cli=<?= (int)$c['id'] ?>"><?= ($nCreds>count($credList))?('Ver las '.(int)$nCreds.' →'):'Abrir bóveda →' ?></a></div>
      <?php if ($credList): ?>
      <div class="cf-cred-grid">
        <?php foreach ($credList as $cr): $cat=$CRED_CATS[$cr['categoria']]??$CRED_CATS['other']; ?>
          <div class="cf-cred">
            <div class="cf-cred-h">
              <span class="cf-cred-ic"><?= ic($cat[1],18) ?></span>
              <div class="cf-cred-tt"><b><?= e($cr['titulo']?:'Credencial') ?></b><span><?= e(mb_strtoupper($cat[0])) ?></span></div>
            </div>
            <?php if (trim((string)$cr['usuario'])!==''): ?>
            <div class="cf-cred-f"><label>Usuario</label><div class="cf-cred-v"><code><?= e($cr['usuario']) ?></code><button type="button" title="Copiar" onclick="cfCp(this,<?= htmlspecialchars(json_encode($cr['usuario']), ENT_QUOTES) ?>)"><?= ic('link',15) ?></button></div></div>
            <?php endif; ?>
            <?php if ((string)$cr['secreto']!==''): ?>
            <div class="cf-cred-f"><label>Contraseña</label><div class="cf-cred-v"><code class="cf-pw" data-v="<?= e($cr['secreto']) ?>">••••••••••••</code><button type="button" title="Ver" onclick="cfTg(this)"><?= ic('eye',15) ?></button><button type="button" title="Copiar" onclick="cfCp(this,<?= htmlspecialchars(json_encode($cr['secreto']), ENT_QUOTES) ?>)"><?= ic('link',15) ?></button></div></div>
            <?php endif; ?>
            <?php if (!empty($cr['url'])): ?><a class="cf-cred-link" href="<?= e($cr['url']) ?>" target="_blank" rel="noopener"><?= ic('link',14) ?> Acceder al servicio</a><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="cf-empty" style="padding:18px 2px">Sin credenciales guardadas. <a href="credenciales.php?cli=<?= (int)$c['id'] ?>" style="color:#0071e3;font-weight:600">Registrar la primera →</a></div>
      <?php endif; ?>
    </div>

    <?php if (!empty($estado['nombre']) || !empty($estado['fases'])): ?>
    <!-- Estado del proyecto -->
    <div class="cf-card">
      <div class="cf-ch"><h3><?= ic('flag',14) ?>Estado del proyecto</h3></div>
      <div style="font-size:15px;font-weight:650"><?= e($estado['nombre']) ?: '<span class="cf-empty">Sin definir</span>' ?></div>
      <?php if (!empty($estado['fases'])): ?><div class="cf-pbar"><?php foreach (($estado['fases']?:[]) as $f): $on = in_array($f['estado']??'', ['done','now']); ?><i class="<?= $on?'on':'' ?>" title="<?= e($f['t']??'') ?>"></i><?php endforeach; ?></div><?php endif; ?>
      <?php if (!empty($estado['siguiente'])): ?><div class="cf-empty"><b>Lo siguiente:</b> <?= e($estado['siguiente']) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($plan['items'])): ?>
    <!-- Plan contratado -->
    <div class="cf-card">
      <div class="cf-ch"><h3><?= ic('layers',14) ?>Plan contratado</h3></div>
      <div class="cf-list">
        <?php foreach ($plan['items'] as $it): ?><div class="cf-row"><span class="cf-rt"><b><?= e($it['t']??'') ?></b></span><span class="cf-rv"><?= e($it['n']??'') ?></span></div><?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (can_edit()): ?>
<div class="cf-danger">
  <div class="muted" style="flex:1;font-size:13px">¿Dar de baja a este cliente?</div>
  <a class="btn danger" href="#" onclick="return erpAsk('¿Eliminar a <?= e(addslashes($c['name'])) ?>? Se borrarán sus tareas, listas y credenciales. Queda 30 días en la papelera.',{post:'delete.php',data:{id:<?= (int)$c['id'] ?>},danger:true})">Eliminar cliente</a>
</div>
<?php endif; ?>
<script>
/* Ver / ocultar contraseña y copiar al portapapeles (con tic verde de confirmación). */
function cfTg(b){var c=b.parentNode.querySelector('.cf-pw');if(c.dataset.shown){c.textContent='••••••••••••';c.dataset.shown='';}else{c.textContent=c.dataset.v;c.dataset.shown='1';}}
function cfCp(b,v){if(navigator.clipboard)navigator.clipboard.writeText(v);var o=b.innerHTML;b.innerHTML='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#12a150" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';if(window.toast)toast('Copiado ✓','plain');setTimeout(function(){b.innerHTML=o;},1100);}
</script>
<?php afoot(); ?>
