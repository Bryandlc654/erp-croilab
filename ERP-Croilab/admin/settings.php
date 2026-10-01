<?php
/* Ajustes del ERP: un único sitio, con navegación lateral en tres zonas.

   Antes esto era una fila de seis pastillas planas —Agencia, Autónomos, Portal
   cliente, Reglas, Mi cuenta, Avanzado— y dentro de la primera había una tarjeta
   de «Accesos rápidos» con enlaces a Equipo, Servicios y Datos. Esa tarjeta era la
   prueba de que faltaban apartados: si hay que poner atajos dentro de una pestaña
   es que esas cosas deberían ser pestañas. Y además había ajustes repartidos en
   cuatro páginas distintas (esta, automations.php, fin-ajustes.php y agencias.php),
   así que para cambiar algo había que acordarse de en cuál de las cuatro estaba.

   Ahora hay una sola pantalla de Ajustes con tres zonas: Organización (lo que es
   la agencia por dentro), Portal de clientes (lo que ven ellos) y Sistema (lo que
   hace funcionar el ERP). Las entradas que todavía tienen página propia —Equipo,
   Servicios, Tipos de cliente, Marca blanca— aparecen aquí como una entrada más
   del menú lateral, no escondidas dentro de otra pestaña. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';
require_once __DIR__ . '/lib/gcal.php';
/* logos.php se carga aquí a mano: erp_nav.php solo lo incluye dentro de erp_foot(),
   y en el cuerpo se usa svc_logo('gcal') para el enlace de reservas. */
require_once __DIR__ . '/lib/logos.php';
/* El menú lateral de Ajustes ya no se escribe aquí: lo pinta el marco común, que
   usan también Servicios, Tipos de cliente, Marca blanca, Integraciones y Datos.
   Así las once pantallas de configuración se ven como una sola. */
require_once __DIR__ . '/lib/ajustes_nav.php';

$me = current_admin();

function ssget($k){ static $c=null; if($c===null){ $c=[]; try{ foreach(db()->query('SELECT clave,valor FROM settings') as $r) $c[$r['clave']]=$r['valor']; }catch(Exception $e){} } return $c[$k] ?? ''; }
function ssput($k,$v){ db()->prepare('INSERT INTO settings (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')->execute([$k,$v]); }
/* ssput con traza: deja constancia de quién cambió qué. Sirve sobre todo para
   los valores que salen en el portal de todos los clientes. */
function ssput_log($k,$v){ $antes=ssget($k); ssput($k,$v); audit_setting($k,$antes,$v); }

/* El portal estaba en un único bloque donde el enlace de la reunión, el WhatsApp y
   siete IDs de vídeo de YouTube se mezclaban en la misma rejilla. Son dos cosas
   distintas: por dónde te contactan y qué vídeo se ve en cada servicio. */
$portalContacto = [
  'meeting_url' => ['Enlace de reservas (Google Calendar · Horarios de citas)', 'https://calendar.app.google/…'],
  'whatsapp'    => ['WhatsApp de contacto (sin +, con país)', '34600000000'],
  'email'       => ['Email de contacto', 'hola@croilab.com'],
];
/* Los vídeos por servicio ya no son siete claves escritas a mano: salen del
   catálogo de Servicios. Si añades «Email marketing» al catálogo, aquí aparece
   su casilla de vídeo sola. Antes no había forma de dársela. */
require_once __DIR__ . '/lib/servicios_cat.php';
require_once __DIR__ . '/lib/audit.php';

/* Emisores: los mismos datos que edita fin-ajustes.php, con su juego completo de
   campos (banco, IVA, IRPF y vencimiento por defecto incluidos). Antes esta página
   guardaba solo seis de los diez y la otra los diez, así que había dos formularios
   para lo mismo y uno de ellos se quedaba corto. Ahora los emisores se editan aquí
   y fin-ajustes.php se queda solo con los datos fiscales de los clientes.

   Y ya no son «Víctor y Gabi» escritos a mano: la lista se gestiona desde aquí y
   la sirve lib/fin_prog.php, que es quien numera las facturas y las contabiliza.
   Ver la cabecera de ese archivo para lo que no se puede romper (las claves no
   cambian nunca, el prefijo de serie se guarda aparte, y un emisor con facturas
   no se borra). */
require_once __DIR__ . '/lib/fin_prog.php';
$EMS    = fin_emisores();
$EMFLDS = ['name','nif','dir','email','phone','banco','iban','iva','irpf','venc'];

/* Estas son exactamente las tareas que ejecuta cron.php: la clave de aquí es la
   misma que la de allí, así que apagar un interruptor la salta de verdad. Antes
   solo aparecían tres y las otras cuatro corrían sin que nadie pudiera pararlas. */
$AUTOS = [
  'lead_reminder'     => ['Recordar leads','Crea una notificación cuando llega la fecha de volver a contactar un lead del CRM.','crm'],
  'invoice_due'       => ['Facturas vencidas','Marca como «vencida» las facturas enviadas que pasan su fecha de vencimiento y avisa.','file'],
  'monthly_report'    => ['Aviso de informe mensual','Recordatorio a principio de mes para preparar y enviar los informes de clientes.','inbox'],
  'invoice_recurring' => ['Facturas recurrentes','Emite sola cada factura programada el día del mes que le toca.','euro'],
  'followups'         => ['Seguimientos del CRM','Genera las llamadas, emails y WhatsApps de seguimiento que tocan cada día.','clock'],
  'daily_digest'      => ['Resumen diario','Prepara el resumen de seguimientos de la mañana, de lunes a viernes.','inbox'],
  'trash_purge'       => ['Vaciar la papelera','Borra definitivamente lo que lleva más de 30 días en la papelera.','trash'],
  'uploads_sweep'     => ['Borrar archivos sin uso','Una vez al día borra los archivos subidos (más de 48 h) que ya no usa nada del ERP ni nada de la papelera. Ahorra espacio en el servidor.','trash'],
];

/* Cada formulario dice a qué zona del menú vuelve después de guardar, para que la
   página no se abra siempre por Agencia al terminar. */
$SEC_TAB = ['agency'=>'agency','emisores'=>'facturacion','em_del'=>'facturacion',
            'portal'=>'contacto','videos'=>'videos','reglas'=>'reglas'];

$saved=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $sec = $_POST['section'] ?? '';
  /* El interruptor guarda solo, sin recargar la página ni perder la pestaña abierta. */
  if ($sec==='auto_toggle') {
    /* Antes devolvía {ok:1} aunque el usuario no tuviera permiso: el interruptor
       se pintaba cambiado y al recargar volvía atrás, haciéndole creer que había
       apagado una automatización que seguía corriendo (P1-05). Ahora devuelve un
       error real si no puede editar. */
    header('Content-Type: application/json');
    if (!can_edit()) { http_response_code(403); echo json_encode(['ok'=>0,'msg'=>'No tienes permiso para cambiar las automatizaciones.']); exit; }
    $k=$_POST['key']??''; if(array_key_exists($k,$AUTOS)) ssput('auto_'.$k, ($_POST['on']??'')==='1'?'1':'0');
    echo json_encode(['ok'=>1]); exit;
  }
  if ($sec==='agency' && is_owner()) {
    /* Identidad de la agencia: reservada al Dueño (P2-16). Un editor no debe poder
       cambiar el nombre, el logo ni el color de marca que ven los clientes.
       agency_logo y agency_color son los que hacen que los dos logins y el portal
       dejen de llevar «Croilab» escrito a mano. */
    foreach (['agency_name','agency_email','agency_phone','agency_web','agency_address','agency_cif','agency_logo','agency_color'] as $k) ssput($k, trim($_POST[$k] ?? ''));
    $saved='agency';
  } elseif ($sec==='emisores' && is_owner()) {
    /* Un solo formulario para todo lo de facturación: los datos de cada autónomo,
       su nombre visible y las altas. Antes había dos formularios con dos botones
       de guardar para gestionar lo mismo.

       Datos fiscales (NIF, IBAN…): reservados al Dueño (P2-16). El IBAN sale
       impreso en las facturas reales; un editor no debe poder cambiarlo. */
    foreach (array_keys($EMS) as $em) foreach ($EMFLDS as $f) ssput('emisor_'.$em.'_'.$f, trim($_POST['emisor_'.$em.'_'.$f] ?? ''));
    /* El prefijo de serie se guarda aparte y solo si viene con algo: dejarlo en
       blanco cambiaría la numeración de las facturas de ese emisor. */
    foreach (array_keys($EMS) as $em) { $pf=strtoupper(trim($_POST['emisor_'.$em.'_serie'] ?? '')); if($pf!=='') ssput('emisor_'.$em.'_serie', $pf); }
    ssput('emisor_por_defecto', fin_emisor_ok($_POST['emisor_por_defecto'] ?? ''));

    /* Nombres y altas. Renombrar solo cambia la etiqueta —la clave se conserva,
       así que ni una factura, ni un apunte contable ni la numeración se enteran—;
       y a los nuevos fin_emisores_guardar() les genera su clave y su prefijo. */
    $ren = (array)($_POST['ren'] ?? []);
    $filas = [];
    foreach ($EMS as $k=>$n) { $nuevo = trim((string)($ren[$k] ?? '')); $filas[] = ['k'=>$k,'nombre'=>($nuevo!==''?$nuevo:$n)]; }
    $altas = 0;
    foreach ((array)($_POST['nuevo'] ?? []) as $nom) { $nom = trim((string)$nom); if ($nom!=='') { $filas[] = ['k'=>'','nombre'=>$nom]; $altas++; } }
    fin_emisores_guardar($filas);

    $saved = $altas ? 'em_add' : 'emisores';
  } elseif ($sec==='em_del' && is_owner()) {
    /* Baja. Si tiene facturas, programaciones o apuntes contables, no se borra:
       su histórico es contabilidad real y tiene que seguir consultándose. */
    $k = (string)($_POST['k'] ?? '');
    if (!isset($EMS[$k])) { $err='Ese emisor ya no existe.'; }
    elseif (count($EMS)<=1) { $err='Tiene que quedar al menos alguien que facture.'; }
    else {
      $uso = fin_emisor_uso($k);
      if ($uso['total']>0) {
        $p=[]; if($uso['facturas'])$p[]=$uso['facturas'].' factura'.($uso['facturas']==1?'':'s');
        if($uso['programaciones'])$p[]=$uso['programaciones'].' programaci'.($uso['programaciones']==1?'ón':'ones');
        if($uso['apuntes'])$p[]=$uso['apuntes'].' apunte'.($uso['apuntes']==1?'':'s').' de contabilidad';
        $err='No se puede quitar a '.$EMS[$k].': tiene '.implode(', ',$p).'. Su histórico es contabilidad real y debe poder consultarse.';
      } else {
        $filas=[]; foreach ($EMS as $ek=>$en) if ($ek!==$k) $filas[]=['k'=>$ek,'nombre'=>$en];
        fin_emisores_guardar($filas);
        $saved='em_del';
      }
    }
  } elseif ($sec==='portal' && can_edit()) {
    foreach (array_keys($portalContacto) as $k) ssput($k, trim($_POST[$k] ?? ''));
    $saved='portal';
  } elseif ($sec==='videos' && can_edit()) {
    /* El de presentación es general y sigue en settings; los de cada servicio
       van dentro del catálogo, junto a su nombre. Se envían por posición
       (vid[] en el mismo orden que el catálogo) para no depender de que el
       nombre del servicio sea un identificador válido de formulario. */
    ssput_log('video_id', svc_video_id($_POST['video_id'] ?? ''));
    $vids = $_POST['vid'] ?? []; if (!is_array($vids)) $vids = []; $filas = [];
    /* El formulario manda vid[] por posición, en el mismo orden que el
       catálogo. Un campo vacío en el navegador sigue llegando como cadena
       vacía, así que se borra; pero si el array llega más corto —se recorta
       algo, o es una petición hecha a mano— los servicios que falten conservan
       el vídeo que ya tenían en vez de quedarse sin él. */
    foreach (svc_catalogo() as $i=>$s) {
      $v = array_key_exists($i, $vids) ? svc_video_id($vids[$i]) : svc_video_id($s['video']);
      $filas[] = ['nombre'=>$s['nombre'], 'video'=>$v];
    }
    $cambios = [];
    $previo = []; foreach (svc_catalogo() as $s) $previo[$s['nombre']] = svc_video_id($s['video']);
    foreach ($filas as $f) if (($previo[$f['nombre']] ?? '') !== $f['video'])
      $cambios[] = $f['nombre'] . ': ' . ($f['video'] !== '' ? $f['video'] : '(sin vídeo)');
    svc_guardar($filas);
    if ($cambios) audit_log('videos', 'cambiados ' . count($cambios) . ': ' . implode(' | ', $cambios));
    svc_guardar($filas);
    $saved='videos';
  /* Las secciones «password» y «notifpref» se han quitado: su panel aquí era solo
     una tarjeta con enlaces, y quien guarda de verdad la contraseña y las
     preferencias de aviso es perfil.php (escribe el mismo notifmute_<id>). Eran
     dos formas de hacer lo mismo y esta no tenía ni formulario que la enviara. */
  } elseif ($sec==='reglas' && can_edit()) {
    /* «Ejecutar ahora»: pasa las reglas a mano sin esperar a que las dispare el uso
       normal del ERP. Útil justo después de encender una. */
    if (function_exists('notif_sync_leads'))    notif_sync_leads();
    if (function_exists('notif_sync_invoices')) notif_sync_invoices();
    if (ssget('auto_monthly_report')!=='0' && (int)date('d')<=5) {
      foreach (db()->query('SELECT id FROM admins')->fetchAll(PDO::FETCH_COLUMN) as $ad)
        notif_add((int)$ad,'info','Prepara los informes del mes','Es principio de mes: revisa y envía los informes de tus clientes.','workspace.php?view=all','report:'.date('Y-m'));
    }
    $saved='reglas';
  }
  /* Las secciones api/gcal/mcp se gestionan ahora en integraciones.php (sus paneles
     aquí eran inalcanzables porque el redirect de más abajo se disparaba antes). */
  if ($err==='') { header('Location: settings.php?tab='.($SEC_TAB[$sec] ?? 'agency').'&ok='.$saved); exit; }
}

/* Los nombres viejos de pestaña siguen valiendo: hay enlaces guardados por ahí
   (automations.php reenvía a ?tab=reglas) y no se rompe ninguno. */
$ALIAS = ['emisores'=>'facturacion','avanzado'=>'api','portal'=>'contacto'];
$tab = $_GET['tab'] ?? 'agency';
/* Si el POST ha fallado no hay redirect y se pinta la página aquí mismo: hay que
   abrirla por el apartado donde estaba el usuario, o vería el error en Agencia
   mientras el formulario que falló está en Facturación. */
if ($err!=='' && isset($SEC_TAB[$_POST['section'] ?? ''])) $tab = $SEC_TAB[$_POST['section']];
$tab = $ALIAS[$tab] ?? $tab;
/* «Mi cuenta» ya no es un apartado: era una tarjeta con tres botones que
   llevaban al perfil. Los enlaces guardados a ?tab=cuenta van directos allí. */
if ($tab==='cuenta') { header('Location: perfil.php?id='.(int)$me['id']); exit; }
/* Integraciones se ha mudado a su propia página (menú lateral). Los enlaces antiguos
   ?tab=api|gcal|mcp|integr redirigen allí. */
$reInt = ['integr'=>'', 'api'=>'api', 'gcal'=>'gcal', 'mcp'=>'mcp'];
if (array_key_exists($tab, $reInt)) { header('Location: integraciones.php'.($reInt[$tab]?('?i='.$reInt[$tab]):'')); exit; }
$ok  = $_GET['ok'] ?? '';

/* Cuántos clientes están sin datos fiscales: se enseña en Facturación para que se
   note desde aquí sin tener que entrar a mirarlo. */
$sinFact = 0;
try { $sinFact = (int)db()->query("SELECT COUNT(*) FROM clients WHERE fact_nombre IS NULL OR fact_nombre=''")->fetchColumn(); } catch(Exception $e){}

/* El armazón —estilos, encabezado del apartado y buscador— lo pinta aj_head(); el
   título sale solo de la entrada del menú. Aquí abajo solo quedan los estilos
   propios de los paneles de esta pantalla.

   Los cinco apartados siguen siendo un único settings.php con cinco paneles y un
   ?tab=, pero para quien lo usa son cinco entradas del menú de la izquierda como
   cualquier otra pantalla. */
$SUBS = [
  'agency'      => 'Quién eres tú como agencia. Aparece en documentos internos y en la cabecera del portal del cliente.',
  'facturacion' => 'Quién factura, con qué datos y con qué numeración. Cada autónomo lleva su contabilidad aparte.',
  'contacto'    => 'Por dónde te escriben los clientes desde su portal.',
  'videos'      => 'Qué vídeo ve el cliente en cada servicio de su portal.',
  'reglas'      => 'Lo que el ERP hace solo mientras trabajas: avisos, facturas recurrentes y seguimientos.',
];
/* Atajo para los campos que solo el Dueño puede tocar: se repite en cuarenta
   sitios y escribir la condición entera en cada uno los hacía ilegibles. */
$ro = is_owner() ? '' : 'disabled';
aj_head($tab, '', $SUBS[$tab] ?? '');
?>
<style>
/* .ok-note y .err-note están en erp_nav.php: las usan varias páginas. */
/* Vista previa de la marca (pestaña Agencia). */
/* Vista previa de la marca: dos maquetas pequeñas de los sitios reales donde
   aparece, para ver el logo y el color en su contexto y no en un cuadrado suelto. */
.ag-prevs{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:6px}
@media(max-width:760px){ .ag-prevs{grid-template-columns:1fr} }
.ag-p-t{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;margin-bottom:8px}
.ag-mark{border-radius:14px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;
  font-weight:800;flex:none;overflow:hidden;transition:background .18s ease}
.ag-mark img{width:100%;height:100%;object-fit:contain;display:block}
.ag-mark.grande{width:52px;height:52px;font-size:23px;border-radius:16px;margin:0 auto 12px}
.ag-mark.chico{width:38px;height:38px;font-size:17px;border-radius:11px}
/* Maqueta 1: la pantalla de acceso del cliente. */
.ag-login{background:#f5f5f7;border:1px solid var(--line);border-radius:14px;padding:20px 18px;text-align:center}
.ag-login b{display:block;font-size:14.5px;color:var(--ink-strong)}
.ag-login span{display:block;font-size:11.5px;color:var(--muted);margin-top:2px}
.ag-fake-inp{height:28px;border-radius:8px;background:#fff;border:1px solid var(--line);margin-top:9px}
.ag-fake-btn{margin-top:9px;height:30px;border-radius:8px;background:var(--accent);color:#fff;
  font-size:12px;font-weight:600;display:flex;align-items:center;justify-content:center;transition:background .18s ease}
/* Maqueta 2: la cabecera del portal. */
.ag-portal{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px}
.ag-p-nm b{display:block;font-size:14px;color:var(--ink-strong)}
.ag-p-nm span{font-size:11.5px;color:var(--muted)}
/* Ficha del autónomo elegido en el segmentado. Solo se ve una a la vez; las
   demás siguen en el HTML para que un único «Guardar» las guarde todas. */
.em-ficha{display:none}
.em-ficha.on{display:block}
/* Solo anima al cambiar de autónomo, no al cargar: `.erp-wrap` ya trae su propia
   animación de entrada y dos translateY anidados hacen temblar la pantalla. */
.em-ficha.saltando{animation:fadeUp .22s cubic-bezier(.2,.7,.3,1)}
.em-cab{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;padding-bottom:16px;border-bottom:1px solid var(--line2)}
.em-info{display:flex;align-items:center;gap:9px;flex:1;min-width:150px;padding-bottom:7px}
.em-info .muted{font-size:11.5px}
.em-cab .icon-btn{margin-bottom:5px}
.em-cab .icon-btn.del:hover{background:#feecec;color:#c0343a}
.em-pie{display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-top:20px;padding-top:18px;border-top:1px solid var(--line2)}
/* El nombre del segmentado se actualiza mientras se escribe en la ficha. */
.seg .em-nm{transition:opacity .12s ease}
.set-badge{background:#fff4e5;border:1px solid #f3dcbf;color:#b7791f;border-radius:99px;padding:3px 10px;font-size:11.5px;font-weight:600;margin-left:8px}
/* Filas de las reglas automáticas, dispuestas en dos columnas para que el panel
   no sea una única columna muy alta. En móvil (≤640px) vuelven a una sola. */
.set-rules-grid{display:grid;grid-template-columns:1fr 1fr;column-gap:30px}
.set-rule{display:flex;align-items:center;gap:16px;border-top:1px solid var(--line);padding:16px 0}
.set-rule-ic{width:38px;height:38px;border-radius:11px;background:var(--accent-soft);color:var(--accent);display:flex;align-items:center;justify-content:center;flex:none}
.set-rule-bd{flex:1;min-width:0}
.set-rule-bd b{font-size:14.5px;font-weight:600;color:var(--ink-strong);display:block}
.set-rule-bd p{font-size:13px;color:var(--muted);margin-top:4px;line-height:1.55}
/* El interruptor es .sw, de erp_nav.php. Aquí lleva .ok porque en las reglas
   sí significa «esto está funcionando», y por eso va en verde. */
.nf-chk{display:flex;align-items:center;gap:10px;margin:8px 0;cursor:pointer;font-size:13.5px;color:var(--ink)}
.nf-chk input{width:17px;height:17px;flex:none;accent-color:var(--accent);cursor:pointer}
.nf-chk span{line-height:1.4}
.tok{font-family:monospace;font-size:13px;background:var(--soft);border:1px solid var(--line);border-radius:9px;padding:10px 12px;word-break:break-all;cursor:pointer}
/* Las reglas van a dos columnas hasta el móvil, igual que .set-grid (que ya
   colapsa sola en el marco común). */
@media(max-width:640px){ .set-rules-grid{grid-template-columns:1fr} }
/* Un poco más de aire entre filas de los formularios de Ajustes: la rejilla del
   marco trae 13px y con las etiquetas ya con icono respiran mejor a 20px. No se
   toca el gap entre columnas ni la rejilla de 12. */
.set-panel .set-grid{row-gap:20px}
/* Modo oscuro: solo se remapean las superficies y textos propios de esta pantalla
   (maquetas de la marca, ficha del autónomo, insignia). Nada del CSS claro cambia. */
[data-theme=dark] .ag-login{background-color:var(--soft)}
[data-theme=dark] .ag-fake-inp{background-color:var(--field)}
[data-theme=dark] .ag-portal{background-color:var(--card)}
[data-theme=dark] .em-cab .icon-btn.del:hover{background-color:var(--danger-bg);color:var(--danger)}
[data-theme=dark] .set-badge{background-color:var(--soft);border-color:var(--line);color:var(--warn)}
</style>

<!-- AGENCIA -->
    <div class="set-panel <?= $tab==='agency'?'on':'' ?>" id="p-agency">
      <?php if($ok==='agency'): ?><div class="ok-note">Datos de la agencia guardados.</div><?php endif; ?>
      <form method="post" class="set-card aj-save"><input type="hidden" name="section" value="agency">
        <h3>Datos de la agencia</h3><div class="h-sub">Identidad de la agencia dentro del ERP. Aparecen en documentos internos y en la cabecera del portal.</div>
        <?php /* Tres bloques con sentido propio en vez de ocho campos seguidos:
                 quién eres, cómo te localizan y con qué cara sales. Y cada campo
                 con el ancho que le toca — un CIF no necesita media pantalla. */ ?>

        <div class="set-zone" style="margin-top:4px">Identidad</div>
        <div class="set-grid">
          <div class="set-f c5"><label><?= ic('building',15) ?> Nombre de la agencia</label><input type="text" name="agency_name" value="<?= e(ssget('agency_name') ?: 'Croilab') ?>" aria-label="Nombre de la agencia" <?= $ro ?>></div>
          <div class="set-f c3"><label><?= ic('file',15) ?> CIF / NIF</label><input type="text" name="agency_cif" value="<?= e(ssget('agency_cif')) ?>" placeholder="B12345678" <?= $ro ?>></div>
        </div>

        <div class="set-zone">Cómo te localizan</div>
        <div class="set-grid">
          <div class="set-f c5"><label><?= ic('inbox',15) ?> Email</label><input type="email" name="agency_email" value="<?= e(ssget('agency_email')) ?>" placeholder="hola@croilab.com" <?= $ro ?>></div>
          <div class="set-f c3"><label>Teléfono</label><input type="tel" name="agency_phone" value="<?= e(ssget('agency_phone')) ?>" placeholder="600 000 000" <?= $ro ?>></div>
          <div class="set-f c4"><label><?= ic('link',15) ?> Web</label><input type="text" name="agency_web" value="<?= e(ssget('agency_web')) ?>" placeholder="https://croilab.com" <?= $ro ?>></div>
          <div class="set-f c12"><label><?= ic('home',15) ?> Dirección</label><input type="text" name="agency_address" value="<?= e(ssget('agency_address')) ?>" placeholder="Calle, número, código postal y ciudad" <?= $ro ?>></div>
        </div>

        <div class="set-zone">Tu marca</div>
        <?php /* La vista previa va AL LADO de los campos que la cambian, no debajo
                 del formulario entero: ahí es donde se mira mientras se pega la URL
                 del logo, no tres pantallazos más abajo. */ ?>
        <div class="set-grid">
          <div class="set-f c8"><label><?= ic('eye',15) ?> Logo (dirección de la imagen)</label><input type="text" id="agLogo" name="agency_logo" value="<?= e(ssget('agency_logo')) ?>" placeholder="https://croilab.com/logo.png" oninput="agPrev()" <?= $ro ?>><div class="hint">Si se deja vacío se usa la inicial del nombre.</div></div>
          <div class="set-f c4"><label><?= ic('eye',15) ?> Color de marca</label>
            <div style="display:flex;gap:8px;align-items:center">
              <input type="text" id="agColor" name="agency_color" value="<?= e(ssget('agency_color')) ?>" placeholder="#1f232a" oninput="agPrev();agSync()" <?= $ro ?>>
              <input type="color" id="agColorPick" value="<?= e(preg_match('/^#[0-9a-f]{6}$/i',(string)ssget('agency_color')) ? ssget('agency_color') : '#1f232a') ?>" oninput="document.getElementById('agColor').value=this.value;agPrev()" style="width:38px;height:36px;padding:2px;flex:none;cursor:pointer" title="Elegir color" <?= $ro ?>>
            </div>
            <div class="hint">Solo lo usa el portal del cliente. El panel del equipo se queda en blanco y negro.</div>
          </div>
          <?php /* Vista previa de los DOS sitios donde se ve la marca de verdad:
                   la pantalla de acceso del cliente y la cabecera de su portal.
                   Antes era un solo cuadrado suelto que no decía dónde acababa
                   apareciendo eso que estabas configurando. */ ?>
          <div class="c12">
            <div class="ag-prevs">
              <div class="ag-p">
                <div class="ag-p-t">Su pantalla de acceso</div>
                <div class="ag-login">
                  <div class="ag-mark grande" id="agMark"></div>
                  <b id="agName"><?= e(ssget('agency_name') ?: 'Croilab') ?></b>
                  <span>Área de cliente</span>
                  <div class="ag-fake-inp"></div>
                  <div class="ag-fake-inp"></div>
                  <div class="ag-fake-btn" id="agBtn">Entrar</div>
                </div>
              </div>
              <div class="ag-p">
                <div class="ag-p-t">La cabecera de su portal</div>
                <div class="ag-portal">
                  <div class="ag-mark chico" id="agMark2"></div>
                  <div class="ag-p-nm"><b id="agName2"><?= e(ssget('agency_name') ?: 'Croilab') ?></b><span>Hola, María 👋</span></div>
                </div>
                <div class="hint" style="margin-top:10px">El color solo se usa aquí, en el portal del cliente. El panel de tu equipo se queda en blanco y negro.</div>
              </div>
            </div>
          </div>
        </div>
        <script>
        /* Nombre, logo y color se dibujan aquí mismo mientras se escriben. */
        function agPrev(){
          var n=(document.querySelector('[name=agency_name]')||{}).value||'Croilab';
          var l=(document.getElementById('agLogo')||{}).value||'';
          var c=(document.getElementById('agColor')||{}).value||'';
          /* Las dos maquetas se pintan a la vez: el mismo logo y el mismo color en
             la pantalla de acceso y en la cabecera del portal. */
          ['agMark','agMark2'].forEach(function(id){
            var m=document.getElementById(id); if(!m) return;
            m.style.background = c || '#1f232a';
            m.innerHTML = l ? '<img src="'+window.escHtml(l)+'" alt="">' : window.escHtml(n.charAt(0).toUpperCase());
          });
          var b=document.getElementById('agBtn'); if(b) b.style.background = c || '#1f232a';
          var n1=document.getElementById('agName');  if(n1) n1.textContent = n;
          var n2=document.getElementById('agName2'); if(n2) n2.textContent = n;
        }
        /* El selector de color y la casilla de texto son el mismo dato: si se
           escribe «#e11d48» a mano, el cuadradito también tiene que moverse. */
        function agSync(){
          var v=(document.getElementById('agColor')||{}).value||'';
          var p=document.getElementById('agColorPick');
          if(p && /^#[0-9a-fA-F]{6}$/.test(v)) p.value=v;
        }
        document.addEventListener('DOMContentLoaded',function(){
          var nm=document.querySelector('[name=agency_name]'); if(nm) nm.addEventListener('input',agPrev);
          agPrev();
        });
        </script>
        <?php if(is_owner()): ?><div style="margin-top:16px"><button class="btn" type="submit">Guardar datos</button></div>
        <?php else: ?><div class="h-sub" style="margin-top:16px">Solo el Dueño puede cambiar la identidad de la agencia.</div><?php endif; ?>
      </form>
    </div>

    <!-- FACTURACIÓN: EMISORES -->
    <?php /* Antes las fichas de todos los autónomos iban apiladas: con dos ya eran
             1.900 píxeles de alto, y para corregirle el IBAN al segundo había que
             pasar por delante de los diez campos del primero. Y su nombre se
             editaba en OTRA tarjeta, al final de la página.

             Ahora se elige a uno arriba y se ve solo su ficha, con su nombre, su
             prefijo y su botón de quitar dentro. Los campos de los demás siguen en
             el HTML (solo ocultos), así que un único «Guardar» los guarda todos. */ ?>
    <div class="set-panel <?= $tab==='facturacion'?'on':'' ?>" id="p-facturacion">
      <?php if($ok==='emisores'): ?><div class="ok-note">Datos de facturación guardados.</div><?php endif; ?>
      <?php if($ok==='em_add'):  ?><div class="ok-note">Autónomo añadido. Rellénale ahora el NIF, la dirección y el IBAN.</div><?php endif; ?>
      <?php if($ok==='em_del'):  ?><div class="ok-note">Autónomo quitado de la lista.</div><?php endif; ?>
      <?php if($ok==='em_ren'):  ?><div class="ok-note">Nombres actualizados. Las facturas ya emitidas no cambian.</div><?php endif; ?>
      <?php if($err!==''):       ?><div class="err-note"><?= e($err) ?></div><?php endif; ?>

      <?php $emKeys = array_keys($EMS); $emAct = $emKeys[0] ?? ''; ?>
      <div class="seg" id="emSeg" style="margin-bottom:16px">
        <?php foreach($EMS as $em=>$lb): $u = fin_emisor_uso($em); ?>
          <button type="button" class="<?= $em===$emAct?'on':'' ?>" data-em="<?= e($em) ?>" onclick="emVer('<?= e(addslashes($em)) ?>')">
            <?= ic('user',15) ?> <span class="em-nm"><?= e($lb) ?></span>
            <?php if($u['facturas']): ?><span class="cnt"><?= (int)$u['facturas'] ?></span><?php endif; ?>
          </button>
        <?php endforeach; ?>
        <?php if(is_owner()): ?>
          <button type="button" onclick="emNuevo()" title="Dar de alta a otro autónomo"><?= ic('plus',15) ?> Añadir</button>
        <?php endif; ?>
      </div>

      <form method="post" class="set-card aj-save"><input type="hidden" name="section" value="emisores">
        <?php foreach($EMS as $em=>$lb): $uso = fin_emisor_uso($em); ?>
        <div class="em-ficha <?= $em===$emAct?'on':'' ?>" data-em="<?= e($em) ?>">

          <div class="em-cab">
            <div class="set-f" style="flex:1;min-width:190px">
              <label><?= ic('user',15) ?> Nombre en el ERP</label>
              <input type="text" name="ren[<?= e($em) ?>]" value="<?= e($lb) ?>" data-emnm="<?= e($em) ?>" oninput="emNombre(this)" <?= $ro ?>>
            </div>
            <div class="set-f" style="width:120px;flex:none">
              <label><?= ic('file',15) ?> Prefijo</label>
              <input type="text" name="emisor_<?= e($em) ?>_serie" value="<?= e(fin_emisor_serie($em)) ?>" maxlength="10" <?= $ro ?>>
            </div>
            <div class="em-info">
              <span class="tag <?= $uso['facturas']?'on':'' ?>"><?= (int)$uso['facturas'] ?> factura<?= $uso['facturas']==1?'':'s' ?></span>
              <span class="muted">Nº <?= e(fin_emisor_serie($em)) ?>-<?= date('Y') ?>-001</span>
            </div>
            <?php /* Quitar solo a quien no tiene histórico. Al resto le sale el
                     número de facturas, que explica por qué no está el botón. */
                  if(is_owner() && $uso['total']===0 && count($EMS)>1): ?>
              <button type="button" class="icon-btn del" title="Quitar de la lista"
                onclick="return erpAsk('¿Quitar a <?= e(addslashes($lb)) ?> de quienes facturan?',{titulo:'Quitar autónomo',ok:'Quitar',danger:true,post:'settings.php',data:{section:'em_del',k:'<?= e(addslashes($em)) ?>'}})"><?= ic('trash',16) ?></button>
            <?php endif; ?>
          </div>

          <div class="set-zone">Datos fiscales · salen impresos en la factura</div>
          <div class="set-grid">
            <div class="set-f c8"><label><?= ic('user',15) ?> Nombre y apellidos / razón social</label><input type="text" name="emisor_<?= $em ?>_name" value="<?= e(ssget('emisor_'.$em.'_name')) ?>" placeholder="<?= e($lb) ?>" <?= $ro ?>></div>
            <div class="set-f c4"><label><?= ic('file',15) ?> NIF / DNI</label><input type="text" name="emisor_<?= $em ?>_nif" value="<?= e(ssget('emisor_'.$em.'_nif')) ?>" placeholder="12345678Z" <?= $ro ?>></div>
            <div class="set-f c12"><label><?= ic('home',15) ?> Dirección fiscal</label><input type="text" name="emisor_<?= $em ?>_dir" value="<?= e(ssget('emisor_'.$em.'_dir')) ?>" placeholder="Calle, número, código postal y ciudad" <?= $ro ?>></div>
            <div class="set-f c8"><label><?= ic('inbox',15) ?> Email</label><input type="email" name="emisor_<?= $em ?>_email" value="<?= e(ssget('emisor_'.$em.'_email')) ?>" <?= $ro ?>></div>
            <div class="set-f c4"><label>Teléfono</label><input type="tel" name="emisor_<?= $em ?>_phone" value="<?= e(ssget('emisor_'.$em.'_phone')) ?>" <?= $ro ?>></div>
          </div>

          <div class="set-zone">Dónde te pagan</div>
          <div class="set-grid">
            <div class="set-f c8"><label><?= ic('euro',15) ?> IBAN</label><input type="text" name="emisor_<?= $em ?>_iban" value="<?= e(ssget('emisor_'.$em.'_iban')) ?>" placeholder="ES00 0000 0000 0000 0000 0000" <?= $ro ?>></div>
            <div class="set-f c4"><label><?= ic('euro',15) ?> Banco</label><input type="text" name="emisor_<?= $em ?>_banco" value="<?= e(ssget('emisor_'.$em.'_banco')) ?>" placeholder="BBVA" <?= $ro ?>></div>
          </div>

          <div class="set-zone">Se rellena solo al crear una factura</div>
          <div class="set-grid">
            <div class="set-f c2 u" data-u="%"><label><?= ic('calc',15) ?> IVA</label><input type="text" name="emisor_<?= $em ?>_iva" value="<?= e(ssget('emisor_'.$em.'_iva') ?: '21') ?>" placeholder="21" inputmode="decimal" <?= $ro ?>></div>
            <div class="set-f c2 u" data-u="%"><label><?= ic('calc',15) ?> IRPF</label><input type="text" name="emisor_<?= $em ?>_irpf" value="<?= e(ssget('emisor_'.$em.'_irpf') ?: '0') ?>" placeholder="0" inputmode="decimal" <?= $ro ?>></div>
            <div class="set-f c4"><label><?= ic('clock',15) ?> Vencimiento</label><input type="text" name="emisor_<?= $em ?>_venc" value="<?= e(ssget('emisor_'.$em.'_venc') ?: 'Contado') ?>" placeholder="Contado" <?= $ro ?>></div>
          </div>
        </div>
        <?php endforeach; ?>

        <?php /* El alta va en este campo oculto, que rellena emNuevo(): se guarda
                 con el resto y no hace falta un segundo formulario. */ ?>
        <input type="hidden" name="nuevo[]" id="emNuevoNom" value="">

        <?php if(is_owner()): ?>
          <div class="em-pie">
            <button class="btn" type="submit"><?= ic('check',15) ?> Guardar facturación</button>
            <?php if(count($EMS)>1): ?>
              <div class="set-f" style="width:210px;flex:none">
                <label><?= ic('usercheck',15) ?> Por defecto</label>
                <select name="emisor_por_defecto" title="El que se elige solo en las facturas que crea el ERP por su cuenta">
                  <?php $pd = fin_emisor_ok(ssget('emisor_por_defecto')); foreach($EMS as $k=>$n): ?>
                    <option value="<?= e($k) ?>" <?= $pd===$k?'selected':'' ?>><?= e($n) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>
            <span class="muted" style="font-size:12.5px;flex:1;min-width:210px">Cambiar el nombre no toca ninguna factura ya emitida. El prefijo tampoco: no lo cambies a mitad de año.</span>
          </div>
        <?php else: ?>
          <div class="h-sub" style="margin:0">Solo el Dueño puede cambiar los datos fiscales del emisor (NIF, IBAN…).</div>
        <?php endif; ?>
      </form>

      <div class="set-card">
        <h3>Datos fiscales de los clientes<?php if($sinFact): ?><span class="set-badge"><?= (int)$sinFact ?> sin datos</span><?php endif; ?></h3>
        <div class="h-sub">La razón social, el NIF y la dirección de cada cliente, que se copian solos a sus facturas. Se editan cliente a cliente en su propia pantalla.</div>
        <a class="btn ghost sm" href="fin-ajustes.php"><?= ic('euro',15) ?> Abrir facturación de clientes</a>
      </div>
    </div>

    <!-- PORTAL · CONTACTO -->
    <div class="set-panel <?= $tab==='contacto'?'on':'' ?>" id="p-contacto">
      <?php if($ok==='portal'): ?><div class="ok-note">Datos de contacto guardados.</div><?php endif; ?>
      <form method="post" class="set-card aj-save"><input type="hidden" name="section" value="portal">
        <h3>Contacto del portal</h3><div class="h-sub">Por dónde te escriben los clientes desde su portal. Los clientes de marca blanca usan el contacto de su agencia si lo tiene configurado.</div>
        <?php $rw = can_edit()?'':'disabled';
              /* El WhatsApp son doce cifras y el email cabe de sobra en dos
                 tercios: no tienen por qué ocupar media pantalla cada uno solo
                 porque el enlace de reservas sí la necesite. */ ?>
        <div class="set-grid">
          <div class="set-f c8"><label><?= ic('inbox',15) ?> Email de contacto</label><input type="email" name="email" value="<?= e(ssget('email')) ?>" placeholder="hola@croilab.com" <?= $rw ?>></div>
          <div class="set-f c4"><label><?= ic('chat',15) ?> WhatsApp</label><input type="tel" name="whatsapp" value="<?= e(ssget('whatsapp')) ?>" placeholder="34600000000" <?= $rw ?>><div class="hint">Sin el «+», con el prefijo del país.</div></div>
          <div class="set-f c12"><label><span class="glogo"><?= svc_logo('gcal',17) ?></span> Enlace de reservas (Google Calendar · Horarios de citas)</label><input type="text" name="meeting_url" value="<?= e(ssget('meeting_url')) ?>" placeholder="https://calendar.app.google/…" <?= $rw ?>>
            <div class="hint">En Google Calendar → <b>Crear → Horario de citas</b>, defines tus huecos libres y copias el enlace público. Pégalo aquí: es el que se ofrece en «Agendar reunión → Que elija el cliente».</div>
          </div>
        </div>
        <?php if(can_edit()): ?><div style="margin-top:16px"><button class="btn" type="submit">Guardar contacto</button></div><?php endif; ?>
      </form>
    </div>

    <!-- PORTAL · VÍDEOS -->
    <div class="set-panel <?= $tab==='videos'?'on':'' ?>" id="p-videos">
      <?php if($ok==='videos'): ?><div class="ok-note">Vídeos guardados.</div><?php endif; ?>
      <form method="post" class="set-card aj-save"><input type="hidden" name="section" value="videos">
        <h3>Vídeos del portal</h3><div class="h-sub">Solo el identificador de YouTube, no la dirección entera. En <code>youtube.com/watch?v=<b>J9-aEZ523bA</b></code> el identificador es lo que va después de <code>v=</code>. Cada servicio enseña el suyo; si lo dejas vacío, ese servicio enseña el de presentación.</div>
        <?php $rw = can_edit()?'':'disabled'; ?>
        <div class="set-grid">
          <div class="set-f c4"><label><?= ic('eye',15) ?> Vídeo de presentación</label><input type="text" name="video_id" value="<?= e(ssget('video_id')) ?>" placeholder="J9-aEZ523bA" maxlength="120" pattern="[A-Za-z0-9_/?=&.:%+-]{0,120}" title="El identificador de YouTube (11 caracteres), o la URL completa del vídeo." <?= $rw ?>><div class="hint">El que se ve cuando un servicio no tiene el suyo.</div></div>
        </div>
        <?php /* Una casilla por servicio del catálogo, en su orden. Los servicios
                 se crean y se borran en Servicios; aquí solo se les pone vídeo,
                 así que no hay dos listas que puedan descuadrarse.
                 Un identificador de YouTube son 11 caracteres: cuatro por fila
                 caben de sobra y así se ve el catálogo entero de una vez, sin
                 tener que bajar. */
              $svcCat = svc_catalogo(); ?>
        <div class="set-zone">Un vídeo por servicio</div>
        <div class="set-grid">
          <?php foreach($svcCat as $i=>$s): ?>
            <div class="set-f c3"><label><?= ic('eye',15) ?> <?= e($s['nombre']) ?></label><input type="text" name="vid[<?= (int)$i ?>]" value="<?= e($s['video']) ?>" placeholder="Sin vídeo propio" maxlength="120" pattern="[A-Za-z0-9_/?=&.:%+-]{0,120}" title="El identificador de YouTube (11 caracteres), o la URL completa del vídeo." <?= $rw ?>></div>
          <?php endforeach; ?>
        </div>
        <div class="h-sub" style="margin-top:14px">¿Falta un servicio en esta lista? Créalo primero en <a href="servicios.php">Servicios</a> y volverá aquí con su casilla.</div>
        <?php if(can_edit()): ?><div style="margin-top:16px"><button class="btn" type="submit">Guardar vídeos</button></div><?php endif; ?>
      </form>
    </div>

    <!-- REGLAS AUTOMÁTICAS -->
    <div class="set-panel <?= $tab==='reglas'?'on':'' ?>" id="p-reglas">
      <?php if($ok==='reglas'): ?><div class="ok-note">Reglas ejecutadas. Revisa tus notificaciones.</div><?php endif; ?>
      <div class="set-card">
        <div style="display:flex;align-items:flex-start;gap:12px">
          <div style="flex:1">
            <h3>Reglas automáticas</h3>
            <div class="h-sub">Se ejecutan solas mientras usas el ERP. Enciéndelas o apágalas.</div>
          </div>
          <?php if(can_edit()): ?><form method="post" style="margin:0"><input type="hidden" name="section" value="reglas"><button class="btn ghost sm" type="submit"><?= ic('bolt',14) ?> Ejecutar ahora</button></form><?php endif; ?>
        </div>
        <div class="set-rules-grid">
        <?php foreach($AUTOS as $k=>$c): $on = (ssget('auto_'.$k)!=='0'); ?>
          <div class="set-rule">
            <div class="set-rule-ic"><?= ic($c[2],19) ?></div>
            <div class="set-rule-bd"><b><?= e($c[0]) ?></b><p><?= e($c[1]) ?></p></div>
            <label class="sw ok"><input type="checkbox" <?= $on?'checked':'' ?> <?= can_edit()?'onchange="autoToggle(\''.e($k).'\',this.checked,this)"':'disabled' ?>><span class="tr"></span></label>
          </div>
        <?php endforeach; ?>
        </div>
      </div>
      <div class="set-card">
        <h3>Seguimientos del CRM</h3>
        <div class="h-sub">Los recordatorios de contactar leads (llamadas, emails y su resumen diario) se gestionan en su propia pantalla, dentro del CRM.</div>
        <a class="btn ghost sm" href="automatizaciones.php"><?= ic('bolt',15) ?> Abrir Reporting del CRM</a>
      </div>
    </div>

    <!-- Las integraciones (n8n/API, Google Calendar, MCP) viven ahora en
         integraciones.php. Aquí había paneles y estilos duplicados que eran
         inalcanzables: el redirect de ?tab=api|gcal|mcp|integr se dispara antes
         de renderizar (ver arriba). Se han eliminado para no mantener código muerto. -->

    <!-- «Mi cuenta» ya no está aquí: era una tarjeta con tres botones que llevaban
         a perfil.php. Ahora el perfil se enlaza desde el pie del menú de Ajustes,
         junto al resto de pantallas con vida propia, y ?tab=cuenta redirige. -->

<script>
/* ---- Facturación: elegir de quién es la ficha que se ve ---- */
function emVer(k){
  document.querySelectorAll('.em-ficha').forEach(function(f){
    var esEste = (f.dataset.em===k);
    f.classList.toggle('on', esEste);
    f.classList.remove('saltando');
    if(esEste){ void f.offsetWidth; f.classList.add('saltando'); }   // reinicia la animación
  });
  document.querySelectorAll('#emSeg [data-em]').forEach(function(b){ b.classList.toggle('on', b.dataset.em===k); });
}
/* Al cambiarle el nombre en la ficha, la pestaña de arriba lo dice ya. */
function emNombre(inp){
  var b = document.querySelector('#emSeg [data-em="'+inp.dataset.emnm+'"] .em-nm');
  if (b) b.textContent = inp.value || '—';
}
/* Alta: se pide el nombre y se guarda con el resto del formulario, en vez de
   tener un segundo formulario solo para esto. */
function emNuevo(){
  erpPrompt('¿Quién va a facturar?','',{msg:'Tendrá su propia numeración, su contabilidad aparte y su pestaña en Facturas.',placeholder:'Ej: Marta Ruiz',ok:'Añadir'})
    .then(function(nombre){
      if(!nombre) return;
      var h=document.getElementById('emNuevoNom'); if(!h) return;
      h.value=nombre;
      /* Se envía ya: dejarlo en un campo oculto a la espera de que alguien pulse
         «Guardar» haría pensar que no se ha añadido. */
      h.form.submit();
    });
}

/* setTab() y la navegación entre apartados los pone el marco (lib/ajustes_nav.php).
   El token CSRF lo añade solo el envoltorio de fetch() de erp_foot. */
function autoToggle(k,on,el){
  fetch('settings.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'section=auto_toggle&key='+encodeURIComponent(k)+'&on='+(on?'1':'0')})
    .then(function(r){return r.json().catch(function(){return {ok:r.ok?1:0};});})
    .then(function(j){
      if(j&&j.ok){toast(on?'Regla activada':'Regla desactivada');}
      else{if(el)el.checked=!on;toast((j&&j.msg)||'No se pudo guardar','err');}
    })
    .catch(function(){if(el)el.checked=!on;toast('No se pudo guardar','err');});
}
</script>
<?php aj_foot(); ?>
