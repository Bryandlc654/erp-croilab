<?php
/* Marco común de Ajustes — el menú lateral único de toda la configuración.

   El problema que resuelve: «Ajustes» era una pantalla más entre otras nueve.
   settings.php tenía su propio menú con seis apartados y enlaces «→» que te
   sacaban a Servicios, Tipos, Equipo y Marca blanca; y el menú de la izquierda
   del ERP listaba otras cuatro (Integraciones, Papelera, Datos, Credenciales)
   que también son ajustes pero no aparecían dentro de Ajustes. Resultado: para
   cambiar algo había que acordarse de si estaba «dentro» o «al lado».

   Ahora hay un único menú de configuración, con las mismas tres zonas, y lo
   pintan igual todas las pantallas de ajustes. Da igual que el contenido lo
   sirva settings.php, servicios.php o integraciones.php: el usuario ve una
   sola pantalla con un menú estable a la izquierda.

   Cómo se usa en una página de ajustes:

     require_once __DIR__.'/../auth.php'; require_admin(); ensure_schema();
     require_once __DIR__.'/../erp_nav.php';
     require_once __DIR__.'/ajustes_nav.php';
     … lógica y POST …
     aj_head('servicios', 'Servicios', 'Descripción del apartado.', $botonHtml);
     … contenido …
     aj_foot();

   Las entradas de tipo «tab» son paneles que viven dentro de settings.php; las
   de tipo «page» son pantallas propias. Para quien usa el ERP no hay diferencia
   ninguna: se pintan igual y en la misma lista.
*/

/* LA lista de los ajustes. Fuente única: la pinta erp_nav.php en el menú de la
   izquierda, de ella salen los títulos de cada apartado y con ella busca el
   buscador. Añade aquí lo que crees y aparece en los tres sitios a la vez.

   Cada entrada: [clave, icono, etiqueta, destino, basenames que la marcan]
   El quinto elemento es opcional; si falta, la entrada se marca comparando el
   ?tab= (para los apartados de settings.php) o el propio nombre del archivo. */
function aj_menu() {
  $z = [];

  $z['Organización'] = [
    ['agency',      'home',      'Agencia',            'settings.php?tab=agency'],
    ['facturacion', 'euro',      'Facturación',        'settings.php?tab=facturacion'],
    ['equipo',      'user',      'Mi equipo',          'team.php', ['team.php','team-edit.php','team-delete.php']],
    ['permisos',    'usercheck', 'Roles y permisos',   'permisos.php'],
    ['servicios',   'list',      'Servicios',          'servicios.php'],
  ];

  /* «Clientes en alta» está en DOS sitios y es a propósito: es su propio módulo
     del raíl (icono Clientes), porque la lista de clientes es trabajo del día y
     tiene que seguir estando para quien no entra en Ajustes; y sigue aquí porque
     es donde el dueño lleva años buscándola. Duplicar una ENTRADA de menú no es
     lo mismo que duplicar un menú entero: el destino es el mismo archivo. */
  $z['Clientes'] = [
    /* ?ctx=aj = «vengo de Ajustes»: erp_nav.php mantiene el menú de Ajustes en vez
       de saltar al de Tareas. Desde el raíl la entrada va sin ctx (menú de trabajo). */
    ['clientes', 'clients', 'Clientes en alta',       'index.php?ctx=aj'],
    ['tipos',    'flag',    'Tipos de cliente',       'types.php', ['types.php','type-edit.php','type-delete.php']],
    ['cred',     'vault',   'Bóveda de credenciales', 'credenciales.php'],
  ];

  $z['Portal de clientes'] = [
    ['contacto', 'chat',     'Contacto',           'settings.php?tab=contacto'],
    ['videos',   'eye',      'Vídeos',             'settings.php?tab=videos'],
    ['metricas', 'chart',    'Métricas de Google', 'metricas.php'],
    ['agencias', 'building', 'Marca blanca',       'agencias.php'],
  ];

  $z['Sistema'] = [
    ['reglas',   'bolt',  'Reglas automáticas', 'settings.php?tab=reglas'],
    ['integr',   'link',  'Integraciones',      'integraciones.php'],
    ['papelera', 'trash', 'Papelera',           'papelera.php'],
  ];
  /* Datos avanzados escribe en la base de datos sin validar nada: solo aparece
     para quien tiene ese permiso, para no enseñar una puerta que da 403. */
  if (!function_exists('can') || can('datos.avanzado'))
    $z['Sistema'][] = ['datos', 'grid', 'Datos (avanzado)', 'data.php'];

  /* Lo mismo con el resto: cada entrada desaparece del menú si el rol no puede
     abrirla. El permiso de cada pantalla es el mismo que aplica auth.php
     (perm_de_pagina), así que el menú y lo que deja pasar el servidor no pueden
     decir cosas distintas. */
  if (function_exists('can') && function_exists('perm_de_pagina')) {
    foreach ($z as $zona => $items) {
      $vivos = [];
      foreach ($items as $it) {
        $need = perm_de_pagina(strtok($it[3], '?'));
        if ($need === null || can($need)) $vivos[] = $it;
      }
      if ($vivos) $z[$zona] = $vivos; else unset($z[$zona]);
    }
  }

  return $z;
}

/* Índice del buscador: qué se configura y en qué apartado está.

   Ajustes tiene unos cuarenta campos repartidos en diez apartados. Sin esto, dar
   con «el IBAN» o «el WhatsApp del portal» es abrir apartados hasta acertar. Cada
   entrada es [palabras que se buscan, clave del apartado, destino, campo].
   «campo» es el name del input: cuando se llega desde el buscador, ese campo se
   enfoca y se resalta un segundo, para no dejar al usuario delante del apartado
   correcto buscando la casilla con la vista.

   Es una lista escrita a mano a propósito: así se puede buscar por como lo llama
   uno («la cuenta del banco», «el vídeo») y no solo por la etiqueta exacta. */
function aj_indice() {
  $ix = [
    // --- Agencia ---
    ['Nombre de la agencia',      'agency','agency_name'],
    ['Email de la agencia',       'agency','agency_email'],
    ['Teléfono de la agencia',    'agency','agency_phone'],
    ['Web de la agencia',         'agency','agency_web'],
    ['CIF / NIF de la agencia',   'agency','agency_cif'],
    ['Dirección de la agencia',   'agency','agency_address'],
    ['Logo de la agencia',        'agency','agency_logo'],
    ['Color de marca',            'agency','agency_color'],
    // --- Facturación (emisores) ---
    ['IBAN · cuenta del banco para cobrar',      'facturacion','emisor_victor_iban'],
    ['Banco',                                    'facturacion','emisor_victor_banco'],
    ['NIF / DNI de quien factura',               'facturacion','emisor_victor_nif'],
    ['Dirección fiscal',                         'facturacion','emisor_victor_dir'],
    ['IVA por defecto',                          'facturacion','emisor_victor_iva'],
    ['IRPF / retención por defecto',             'facturacion','emisor_victor_irpf'],
    ['Vencimiento por defecto · forma de pago',  'facturacion','emisor_victor_venc'],
    ['Autónomos que facturan · emisores',        'facturacion',''],
    ['Datos fiscales de los clientes',           'facturacion',''],
    // --- Portal ---
    ['Enlace de reservas · agendar reunión','contacto','meeting_url'],
    ['WhatsApp de contacto',                'contacto','whatsapp'],
    ['Email de contacto del portal',        'contacto','email'],
    ['Vídeo de presentación · YouTube',     'videos','video_id'],
    ['Vídeo de cada servicio',              'videos',''],
    // --- Reglas ---
    ['Reglas automáticas · automatizaciones','reglas',''],
    ['Recordar leads del CRM',               'reglas',''],
    ['Aviso de facturas vencidas',           'reglas',''],
    ['Aviso de informe mensual',             'reglas',''],
    ['Emitir facturas recurrentes',          'reglas',''],
    ['Seguimientos del CRM',                 'reglas',''],
    ['Resumen diario',                       'reglas',''],
    ['Vaciar la papelera automáticamente',   'reglas',''],
  ];
  /* Los apartados con pantalla propia: se busca por su nombre y por lo que
     contienen, y llevan a la pantalla entera. La clave del medio es la del menú
     (aj_menu), para que el buscador diga bien en qué apartado está cada cosa. */
  $paginas = [
    ['Servicios · catálogo de lo que ofreces', 'servicios','servicios.php'],
    ['Tipos de cliente · qué ve cada uno en su portal','tipos','types.php'],
    ['Marca blanca · agencias colaboradoras','agencias','agencias.php'],
    ['Integraciones · n8n, API, token','integr','integraciones.php?i=api'],
    ['Google Calendar · conectar el calendario','integr','integraciones.php?i=gcal'],
    ['MCP · conectar Claude','integr','integraciones.php?i=mcp'],
    ['Equipo · quién entra al panel','equipo','team.php'],
    ['Roles y permisos · qué puede hacer cada uno','permisos','permisos.php'],
    ['Quitarle una función a alguien','permisos','permisos.php'],
    ['Clientes en alta','clientes','index.php'],
    ['Credenciales y contraseñas de clientes','cred','credenciales.php'],
    ['Papelera · recuperar algo borrado','papelera','papelera.php'],
    ['Facturación de clientes · datos fiscales','','fin-ajustes.php'],
    ['Mi cuenta, mi contraseña y mis avisos','','perfil.php'],
  ];
  if (function_exists('is_owner') && is_owner())
    $paginas[] = ['Datos avanzados · editar las tablas','datos','data.php'];

  $out = [];
  foreach ($ix as $r)      $out[] = ['t'=>$r[0], 'ap'=>$r[1], 'href'=>'settings.php?tab='.$r[1], 'campo'=>$r[2]];
  foreach ($paginas as $r) $out[] = ['t'=>$r[0], 'ap'=>$r[1], 'href'=>$r[2], 'campo'=>''];
  return $out;
}

/* Qué módulo del ERP marca cada apartado en el raíl negro y en la barra lateral
   grande. Se respeta lo que ya hacía cada página para no mover el menú del ERP
   de sitio al navegar por Ajustes. */
function aj_erp_active($clave) {
  $m = ['servicios'=>'roles','tipos'=>'roles','datos'=>'roles','agencias'=>'agencias','integr'=>'integr'];
  return $m[$clave] ?? 'ajustes';
}

/* Abre la pantalla: cabecera del ERP, estilos del marco, menú y columna de
   contenido. $accion es HTML opcional que se pinta a la derecha del título del
   apartado (típicamente el botón «Nuevo …»). */
function aj_head($clave, $titulo = '', $sub = '', $accion = '') {
  /* Sin título explícito se coge la etiqueta del menú: así el encabezado dice
     siempre lo mismo que la entrada marcada a la izquierda, sin repetirlo en dos
     archivos que luego se desincronizan. */
  if ($titulo === '') {
    foreach (aj_menu() as $items) foreach ($items as $it) if ($it[0] === $clave) { $titulo = $it[2]; break 2; }
  }
  erp_head(aj_erp_active($clave), $titulo ?: 'Ajustes');
  ?>
<style>
/* Sin el menú interno sobra sitio, así que las pantallas de ajustes usan el ancho
   de verdad. El límite existe igual: un campo de texto de 1200px no se lee mejor,
   se lee peor. Lo que se hace con el espacio es poner los campos cortos juntos
   (ver la rejilla de abajo), no estirarlos. */
.set-wrap{max-width:1180px}
/* .set-zone (el separador de bloques dentro de una tarjeta) vive en erp_nav.php,
   junto a .set-grid: lo usan también team-edit.php y edit.php, que no pasan por
   aquí. */
/* El panel NO anima al cargar: `.erp-wrap` ya trae su fadeUp, y dos translateY
   anidados hacen que la pantalla tiemble al entrar. Solo anima cuando se salta
   de un apartado a otro sin recargar (el buscador), que es cuando aporta algo. */
.set-panel{display:none}
.set-panel.on{display:block}
.set-panel.saltando{animation:fadeUp .2s ease}
/* Mismas medidas que .card / .panel / .kpi de erp_nav.php (docs/05 §11): radio 16
   y margen de 16. El 14 de los grupos de workspace.php es local de esa pantalla,
   no el estándar del ERP. */
.set-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px 22px;margin-bottom:16px}
.set-card h3{font-size:15.5px;color:var(--ink-strong);margin-bottom:4px}
.set-card .h-sub{font-size:12.5px;color:var(--muted);margin-bottom:16px;line-height:1.5}
/* Los campos reaccionan como las celdas de Tareas: se iluminan al pasar por
   encima y se marcan con el acento al escribir, en vez de quedarse inertes. */
.set-f input:not([type=color]),.set-f select,.set-f textarea{transition:background .12s ease,border-color .12s ease,box-shadow .14s ease}
.set-f input:not([type=color]):hover:not(:disabled),.set-f select:hover:not(:disabled){background:#fcfcfd;border-color:#dcdde0}
.set-f input:focus,.set-f select:focus,.set-f textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft);outline:none}
.set-f input:disabled{background:var(--soft);color:var(--muted);cursor:not-allowed}
/* Los botones se levantan un pelín, igual que el de «añadir tarea». */
.set-card .btn:not(.ghost):hover{transform:translateY(-1px);box-shadow:0 5px 13px rgba(0,0,0,.15)}
.set-card .btn{transition:transform .16s ease,box-shadow .18s ease,background .12s ease}
/* La rejilla de formulario (.set-grid, .set-f y sus anchos c2..c12) vive ahora
   en erp_nav.php: la usan también Editar cliente y cualquier pantalla con
   formulario, no solo Ajustes (docs/05 §11). */
/* Cabecera del apartado. El <h1> es el nombre del apartado —«Facturación»,
   «Servicios»—, no «Ajustes»: dónde estás ya lo dice el menú de la izquierda, y
   repetirlo en cada pantalla sobraba. */
.aj-h{display:flex;align-items:flex-start;gap:16px;margin-bottom:20px}
.aj-h .t{flex:1;min-width:0}
.aj-h h1{margin:0 0 4px}
.aj-h p{font-size:12.5px;color:var(--muted);line-height:1.5;margin:0;max-width:75ch}
.aj-h .f{flex:none;width:230px;padding-top:3px}
.aj-h .a{flex:none;padding-top:3px}
/* --- Buscador de ajustes --- */
.aj-find{position:relative}
.aj-find input{width:100%;border:1px solid var(--line);border-radius:10px;padding:8px 11px 8px 32px;font-size:13px;font-family:inherit;background:#fff;color:var(--ink)}
.aj-find input::placeholder{color:#b0b4bb}
.aj-find input:focus{outline:none;border-color:#c8ccd2}
.aj-find .lupa{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#b0b4bb;display:flex;pointer-events:none}
.aj-find .lupa svg{width:14px;height:14px}
.aj-res{position:absolute;z-index:60;left:0;right:0;top:calc(100% + 5px);background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 44px -20px rgba(0,0,0,.4);padding:5px;display:none;max-height:340px;overflow:auto}
.aj-res.on{display:block}
.aj-res a{display:block;padding:7px 9px;border-radius:8px;text-decoration:none;color:var(--ink);font-size:13px;line-height:1.35}
.aj-res a small{display:block;font-size:11px;color:var(--muted);margin-top:1px}
.aj-res a:hover,.aj-res a.sel{background:var(--soft)}
.aj-res .vacio{padding:10px;font-size:12.5px;color:var(--muted)}
/* Resalte del campo al que te lleva el buscador: dura un momento y se va. */
@keyframes ajPing{0%{box-shadow:0 0 0 0 rgba(59,130,246,.45)}100%{box-shadow:0 0 0 7px rgba(59,130,246,0)}}
.aj-ping{animation:ajPing 1.1s ease-out 2;border-color:#3b82f6 !important}
/* --- Cambios sin guardar --- */
.aj-flag{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#b7791f;margin-left:12px}
.aj-flag:before{content:"";width:7px;height:7px;border-radius:50%;background:#e8a33d}
/* En pantallas medias los campos muy estrechos se quedan sin sitio para su
   etiqueta, así que suben a la mitad de la fila antes de colapsar del todo. */
@media(max-width:1100px){ .set-grid .c2{grid-column:span 3} .set-grid .c3{grid-column:span 4} }
@media(max-width:860px){ .set-grid .c2,.set-grid .c3,.set-grid .c4,.set-grid .c5{grid-column:span 6} .set-grid .c8{grid-column:1/-1} }
@media(max-width:640px){ .set-grid > *{grid-column:1 / -1 !important} }
@media(max-width:760px){ .aj-h{flex-wrap:wrap} .aj-h .f{order:3;width:100%;max-width:none} }
</style>
<div class="set-wrap">
  <div class="aj-h">
    <div class="t">
      <h1><?= e($titulo ?: 'Ajustes') ?></h1>
      <?php if($sub!==''): ?><p><?= $sub /* admite enlaces: lo escribe la propia página */ ?></p><?php endif; ?>
    </div>
    <?php /* El buscador va en la cabecera, no en un menú aparte: el menú de los
             ajustes es el de la izquierda y no se duplica. */ ?>
    <div class="f">
      <div class="aj-find">
        <span class="lupa"><?= ic('search',14) ?></span>
        <input type="text" id="ajFind" placeholder="Buscar un ajuste…" autocomplete="off" spellcheck="false">
        <div class="aj-res" id="ajRes"></div>
      </div>
    </div>
    <?php if ($accion !== ''): ?><div class="a"><?= $accion ?></div><?php endif; ?>
  </div>

  <?php /* Aquí NO va erp-in-stg. `.erp-wrap` ya trae su propia animación de
           entrada (fadeUp, con un translateY de 9px), y meter otra animación con
           translate dentro hacía que la página temblara al cargar: dos elementos
           anidados desplazándose a la vez, cada uno con su curva y su duración.
           Una animación de entrada por pantalla, y la pone el armazón. */ ?>
  <div class="set-main">
<?php
}

/* Cierra la pantalla y emite el JS del marco: aviso de cambios sin guardar,
   guardado sin recargar y buscador. */
function aj_foot() {
  /* Etiquetas de los apartados para el buscador. Antes se leían del menú interno
     del propio marco; ese menú ya no existe (el de los ajustes es el de la
     izquierda, y no se duplica), así que salen de aj_menu(). */
  $lb = [];
  foreach (aj_menu() as $items) foreach ($items as $it) $lb[$it[0]] = $it[2];
  ?>
  </div>
</div>
<script>
(function(){
  var IX = <?= json_encode(aj_indice(), JSON_UNESCAPED_UNICODE) ?>;
  var LB = <?= json_encode($lb, JSON_UNESCAPED_UNICODE) ?>;

  /* ================= 1 · Cambios sin guardar =================
     Antes se podía escribir media ficha de la agencia, cambiar de apartado y
     perderlo todo sin un solo aviso. Ahora cada formulario recuerda cómo estaba
     al cargar; si difiere, se marca y se avisa antes de salir o de cambiar de
     apartado. */
  /* Los campos ocultos quedan fuera a propósito. No son datos que el usuario
     escriba, y sobre todo: erp_nav.php inyecta el _csrf en cada formulario con un
     MutationObserver, o sea DESPUÉS de que esto se ejecute. Si contara, la
     primera foto saldría sin token, todas las siguientes con él, y el formulario
     se daría por modificado nada más cargar la página: el aviso «Sin guardar» se
     quedaría puesto para siempre y dejaría de significar nada. */
  function foto(f){
    return [].slice.call(f.elements)
      .filter(function(el){ return el.name && !el.disabled && el.type!=='hidden'; })
      .map(function(el){ return el.name+'='+(el.type==='checkbox'||el.type==='radio'?el.checked:el.value); }).join('\n');
  }
  var forms = [].slice.call(document.querySelectorAll('.set-main form'));
  forms.forEach(function(f){
    /* El formulario de una sola acción (un botón y ningún campo que rellenar,
       como «Ejecutar ahora») no tiene nada que perder: no se vigila. */
    var campos=[].slice.call(f.elements).filter(function(el){return el.name&&el.type!=='hidden'&&el.type!=='submit';});
    if(!campos.length) return;
    f.dataset.ajFoto = foto(f);
    f.addEventListener('input', marcar); f.addEventListener('change', marcar);
    function marcar(){
      var sucio = foto(f)!==f.dataset.ajFoto;
      f.classList.toggle('aj-dirty', sucio);
      var btn=f.querySelector('button[type=submit],button:not([type])');
      if(!btn) return;
      var flag=f.querySelector('.aj-flag');
      if(sucio && !flag){ flag=document.createElement('span'); flag.className='aj-flag'; flag.textContent='Sin guardar'; btn.parentNode.insertBefore(flag, btn.nextSibling); }
      else if(!sucio && flag){ flag.remove(); }
    }
  });
  function haySucio(){ return !!document.querySelector('.set-main form.aj-dirty'); }
  function limpiar(f){ f.dataset.ajFoto=foto(f); f.classList.remove('aj-dirty'); var g=f.querySelector('.aj-flag'); if(g)g.remove(); }
  /* Enviar un formulario recarga la página, y sin esto el aviso de «cambios sin
     guardar» saltaría justo al guardar, que es exactamente cuando no toca. */
  var enviando=false;
  forms.forEach(function(f){ f.addEventListener('submit',function(){ enviando=true; }); });
  window.addEventListener('beforeunload',function(ev){ if(!enviando && haySucio()){ ev.preventDefault(); ev.returnValue=''; } });

  /* ================= 2 · Guardar sin recargar =================
     Solo los formularios marcados con .aj-save. El resto (añadir filas, modales,
     acciones sueltas) sigue con su envío normal, que es lo que necesitan.
     El PHP no cambia: responde con su redirect de siempre y aquí se mira si la
     respuesta acabó en una URL con «ok=». */
  document.querySelectorAll('.set-main form.aj-save').forEach(function(f){
    f.addEventListener('submit',function(ev){
      ev.preventDefault();
      var btn=f.querySelector('button[type=submit],button:not([type])');
      var txt=btn?btn.innerHTML:''; if(btn){ btn.disabled=true; btn.textContent='Guardando…'; }
      fetch(f.action||location.href,{method:'POST',body:new FormData(f)})
        .then(function(r){
          if(!r.ok) throw new Error(r.status===403?'No tienes permiso para guardar esto.':'El servidor ha respondido '+r.status);
          limpiar(f);
          if(window.toast) toast('Guardado');
          /* El aviso verde de la recarga anterior ya no viene a cuento. */
          var n=f.parentNode.querySelector('.ok-note'); if(n) n.remove();
        })
        .catch(function(e){ if(window.toast) toast(e.message||'No se ha podido guardar','err'); })
        .finally(function(){ if(btn){ btn.disabled=false; btn.innerHTML=txt; } });
    });
  });

  /* ================= 3 · Buscador de ajustes ================= */
  var inp=document.getElementById('ajFind'), caja=document.getElementById('ajRes'), sel=-1, vistos=[];
  /* Se busca sin tildes y sin mayúsculas: «video» encuentra «Vídeo». */
  function normal(s){ return (s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,''); }
  function pintar(q){
    var n=normal(q);
    vistos = n.length<2 ? [] : IX.filter(function(r){ return normal(r.t).indexOf(n)>=0; }).slice(0,10);
    sel=-1;
    if(!n || n.length<2){ caja.classList.remove('on'); caja.innerHTML=''; return; }
    if(!vistos.length){ caja.innerHTML='<div class="vacio">Nada con «'+window.escHtml(q)+'».</div>'; caja.classList.add('on'); return; }
    caja.innerHTML = vistos.map(function(r,i){
      var donde = (r.ap && LB[r.ap]) ? LB[r.ap] : 'Otra pantalla';
      return '<a href="#" data-i="'+i+'">'+window.escHtml(r.t)+'<small>'+window.escHtml(donde)+'</small></a>';
    }).join('');
    caja.classList.add('on');
  }
  function ir(r){
    if(!r) return;
    caja.classList.remove('on'); inp.value='';
    var aqui = r.href.split('?')[0]==='settings.php' && document.getElementById('p-'+r.ap);
    if(aqui){ window.setTab(r.ap); resaltar(r.campo); return; }
    /* Otra pantalla: se lleva el campo en la URL para resaltarlo al llegar. */
    location.href = r.href + (r.campo ? ((r.href.indexOf('?')<0?'?':'&')+'ir='+encodeURIComponent(r.campo)) : '');
  }
  function resaltar(campo){
    if(!campo) return;
    var el=document.querySelector('[name="'+campo+'"]'); if(!el) return;
    el.scrollIntoView({block:'center',behavior:'smooth'});
    el.classList.add('aj-ping'); if(!el.disabled) el.focus();
    setTimeout(function(){ el.classList.remove('aj-ping'); },2400);
  }
  if(inp){
    inp.addEventListener('input',function(){ pintar(inp.value); });
    inp.addEventListener('keydown',function(ev){
      if(ev.key==='Escape'){ inp.value=''; caja.classList.remove('on'); inp.blur(); return; }
      if(!vistos.length) return;
      if(ev.key==='ArrowDown'||ev.key==='ArrowUp'){
        ev.preventDefault(); sel=(sel+(ev.key==='ArrowDown'?1:-1)+vistos.length)%vistos.length;
        caja.querySelectorAll('a').forEach(function(a,i){ a.classList.toggle('sel',i===sel); });
      } else if(ev.key==='Enter'){ ev.preventDefault(); ir(vistos[sel<0?0:sel]); }
    });
    caja.addEventListener('click',function(ev){
      var a=ev.target.closest('a'); if(!a) return; ev.preventDefault(); ir(vistos[+a.dataset.i]);
    });
    document.addEventListener('click',function(ev){ if(!ev.target.closest('.aj-find')) caja.classList.remove('on'); });
  }
  /* Al llegar de otra pantalla con ?ir=campo, se resalta ese campo. */
  var irA=new URL(location).searchParams.get('ir'); if(irA) setTimeout(function(){ resaltar(irA); },120);

  /* ================= 4 · Cambio de apartado dentro de settings.php =================
     Los apartados de settings.php son ahora entradas del menú de la izquierda, o
     sea enlaces normales que recargan la página. Esto solo queda para los saltos
     del buscador dentro de la propia pantalla, que no tienen por qué recargar. */
  if(!document.querySelector('.set-panel')) return;
  window.setTab=function(t){
    var u=new URL(location); u.searchParams.set('tab',t); u.searchParams.delete('ok'); u.searchParams.delete('ir');
    history.pushState({tab:t},'',u);
    document.querySelectorAll('.set-panel').forEach(function(p){
      var esEste = (p.id==='p-'+t);
      p.classList.toggle('on', esEste);
      /* La animación solo en el salto, y se quita al acabar para que no vuelva a
         dispararse si se salta otra vez al mismo apartado. */
      p.classList.remove('saltando');
      if(esEste){ void p.offsetWidth; p.classList.add('saltando'); }
    });
    /* El menú de la izquierda lo pinta PHP en la carga, así que al saltar sin
       recargar hay que moverle la marca de «estás aquí» a mano. */
    document.querySelectorAll('.side .nav a').forEach(function(a){
      var h=a.getAttribute('href')||'';
      if(h.indexOf('settings.php?tab=')===0) a.classList.toggle('on', h==='settings.php?tab='+t);
    });
    var h1=document.querySelector('.aj-h h1'); if(h1 && LB[t]) h1.textContent=LB[t];
  };
  window.addEventListener('popstate',function(){
    location.reload();   // volver atrás recarga: así el título y el menú siempre cuadran
  });
})();
</script>
<?php
  erp_foot();
}
