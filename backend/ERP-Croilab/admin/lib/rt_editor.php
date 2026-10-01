<?php
/* ============================================================================
   Editor enriquecido REUTILIZABLE (rt_editor)

   Es el MISMO editor que usan la descripción y los comentarios de una tarea
   (task.php): un contenteditable que se guarda como TEXTO con marcadores tipo
   markdown (NO html) y se repinta con rt_blocks(). Aquí está extraído como
   componente para poder usarlo en otras pantallas (p. ej. Actas) con el mismo
   formato de guardado y el mismo render, o sea: idéntico de dinámico.

   Marcadores (compatibles con lo que guarda una tarea):
     # / ## / ###   encabezados
     - texto        viñeta            N. texto   numerada
     > texto        cita              ```        código en bloque
     ---            divisor           | a | b |   tabla (+ fila | --- |)
     **b** __u__ *i* ~~s~~ `code` [t](url)       formato en línea

   Piezas PHP (render en servidor):
     rt_format($s)              formato EN LÍNEA (negrita/cursiva/…/enlaces).
     rt_table_html($rows,$fn)   tabla markdown -> <table class="rt-table">.
     rt_blocks($text,$inline)   motor de BLOQUES (el que pinta lo guardado).
     rt_excerpt($text,$n)       extracto en texto plano (para listados).

   Piezas de interfaz (CSS + JS del navegador):
     rt_editor_assets()   emite una sola vez el CSS y el JS del editor.
     rt_editor_toolbar($edId)  emite la barra de herramientas del editor $edId.

   Las funciones de render están protegidas con function_exists por si esta
   librería y la copia histórica de task.php coinciden alguna vez en la misma
   petición (hoy no ocurre: task.php no incluye esta librería).
   ============================================================================ */

require_once __DIR__ . '/../../auth.php';

if (!function_exists('rt_format')) {
/* Formateador de texto enriquecido EN LÍNEA. Es SEGURO: cada trozo de texto se
   escapa con e(), así que solo salen las etiquetas que generamos aquí. */
function rt_format($s){
  $s=(string)$s; $store=[];
  $ph=function($html) use(&$store){ $k="\x01".count($store)."\x02"; $store[]=$html; return $k; };
  // 1) código inline `x` (su contenido no recibe más formato)
  $s=preg_replace_callback('/`([^`\n]+)`/u', function($m) use($ph){ return $ph('<code class="rt-code">'.e($m[1]).'</code>'); }, $s);
  // 2) enlaces markdown [texto](url)
  $s=preg_replace_callback('/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/u', function($m) use($ph){ return $ph('<a class="rt-link" href="'.e($m[2]).'" target="_blank" rel="noopener">'.e($m[1]).'</a>'); }, $s);
  // 3) URLs sueltas
  $s=preg_replace_callback('/(https?:\/\/[^\s<]+)/u', function($m) use($ph){ return $ph('<a class="rt-link" href="'.e($m[1]).'" target="_blank" rel="noopener">'.e($m[1]).'</a>'); }, $s);
  // 4) escapar el resto del texto
  $s=e($s);
  // 5) formato sobre texto ya escapado
  $s=preg_replace('/\*\*(.+?)\*\*/us','<b>$1</b>',$s);
  $s=preg_replace('/__(.+?)__/us','<u>$1</u>',$s);
  $s=preg_replace('/\*(?!\*)([^*]+?)\*(?!\*)/us','<i>$1</i>',$s);
  $s=preg_replace('/~~(.+?)~~/us','<s>$1</s>',$s);   // tachado
  // 6) menciones (si la página ha preparado el mapa; si no, salen en plano)
  $s=preg_replace_callback('/@([\p{L}0-9_.\-]+)/u', function($m){ $map=$GLOBALS['MENTIONMAP']??[]; $uid=$GLOBALS['MENTIONUID']??[]; $key=mb_strtolower($m[1]); if(isset($map[$key])) return '<span class="mention" data-uid="'.(int)($uid[$key]??0).'">@'.e($map[$key]).'</span>'; return '<span class="mention plain">@'.e($m[1]).'</span>'; }, $s);
  // 7) restaurar código/enlaces
  foreach($store as $i=>$html){ $s=str_replace("\x01".$i."\x02",$html,$s); }
  return $s;
}
}

if (!function_exists('rt_table_html')) {
/* Tabla a partir de filas markdown «| a | b |». La 1ª fila es cabecera. */
function rt_table_html($rows, callable $inline){
  $cells=function($row){ $row=trim($row); $row=preg_replace('/^\||\|$/','',$row); return array_map('trim', explode('|',$row)); };
  $h='<div class="rt-tablewrap"><table class="rt-table">';
  if($rows){ $head=$cells($rows[0]); $h.='<thead><tr>'; foreach($head as $c) $h.='<th>'.$inline($c).'</th>'; $h.='</tr></thead>'; }
  $h.='<tbody>';
  for($r=1;$r<count($rows);$r++){ $cs=$cells($rows[$r]); $h.='<tr>'; foreach($cs as $c) $h.='<td>'.$inline($c).'</td>'; $h.='</tr>'; }
  return $h.'</tbody></table></div>';
}
}

if (!function_exists('rt_blocks')) {
/* Motor de bloques compartido: encabezados, listas, LISTA DE CONTROL ([[chk:0/1]]),
   cita, código, divisor y tablas markdown; el resto son párrafos. El formato EN LÍNEA
   lo pone $inline. Backward-compatible: el texto antiguo (párrafos) se ve igual.
   $chk=true activa la lista de control; $chkEditable=true la pinta con casillas de
   verdad (para el editor). Misma anatomía que la descripción de una tarea (task.php),
   así el texto es intercambiable entre tareas y actas. */
function rt_blocks($text, callable $inline, $chk=false, $chkEditable=false){
  $lines=preg_split("/\r\n|\r|\n/", (string)$text); $n=count($lines); $i=0; $out='';
  while($i<$n){
    $raw=$lines[$i]; $t=rtrim($raw);
    if(preg_match('/^```/',$t)){ $i++; $code=[]; while($i<$n && !preg_match('/^```/',$lines[$i])){ $code[]=$lines[$i]; $i++; } if($i<$n)$i++;
      $out.='<pre class="rt-pre"><code>'.e(implode("\n",$code)).'</code></pre>'; continue; }
    if(preg_match('/^\s*\|.*\|\s*$/',$t) && $i+1<$n && preg_match('/^\s*\|[\s:|\-]+\|\s*$/',$lines[$i+1])){
      $rows=[$t]; $i+=2; while($i<$n && preg_match('/^\s*\|.*\|\s*$/',rtrim($lines[$i]))){ $rows[]=rtrim($lines[$i]); $i++; }
      $out.=rt_table_html($rows,$inline); continue; }
    if($chk && preg_match('/^\s*\[\[chk:([01])\]\]\s?(.*)$/u',$t,$m)){
      if($chkEditable) $out.='<div class="rt-chkedit"><input type="checkbox" '.($m[1]==='1'?'checked':'').'><span class="rt-chktxt">'.$inline($m[2]).'</span></div>';
      else $out.='<div class="rt-chk'.($m[1]==='1'?' done':'').'"><span class="rt-cbox">'.($m[1]==='1'?'✓':'').'</span><span>'.$inline($m[2]).'</span></div>';
      $i++; continue; }
    if(preg_match('/^\s*---+\s*$/',$t)){ $out.='<hr class="rt-hr">'; $i++; continue; }
    if(preg_match('/^(#{1,3})\s+(.*)$/',$t,$m)){ $lvl=strlen($m[1]); $out.='<h'.$lvl.' class="rt-h'.$lvl.'">'.$inline($m[2]).'</h'.$lvl.'>'; $i++; continue; }
    if(preg_match('/^>\s?(.*)$/',$t)){ $items=[]; while($i<$n && preg_match('/^>\s?(.*)$/',rtrim($lines[$i]),$mm)){ $items[]=$inline($mm[1]); $i++; } $out.='<blockquote class="rt-quote">'.implode('<br>',$items).'</blockquote>'; continue; }
    if(preg_match('/^[-•]\s+(.*)$/',$t)){ $out.='<ul class="rt-ul">'; while($i<$n && preg_match('/^[-•]\s+(.*)$/',rtrim($lines[$i]),$mm)){ $out.='<li>'.$inline($mm[1]).'</li>'; $i++; } $out.='</ul>'; continue; }
    if(preg_match('/^\d+[.)]\s+(.*)$/',$t)){ $out.='<ol class="rt-ol">'; while($i<$n && preg_match('/^\d+[.)]\s+(.*)$/',rtrim($lines[$i]),$mm)){ $out.='<li>'.$inline($mm[1]).'</li>'; $i++; } $out.='</ol>'; continue; }
    if($t===''){ $out.='<div class="rt-p"><br></div>'; $i++; continue; }
    $out.='<div class="rt-p">'.$inline($raw).'</div>'; $i++;
  }
  return $out;
}
}

if (!function_exists('rt_excerpt')) {
/* Extracto en texto plano (sin marcadores) para listados y buscadores. */
function rt_excerpt($text, $n=160){
  $s=(string)$text; $out=[];
  foreach(preg_split("/\r\n|\r|\n/",$s) as $ln){
    if(preg_match('/^\s*```/',$ln)) continue;
    if(preg_match('/^\s*\|[\s:|\-]+\|\s*$/',$ln)) continue;
    $ln=preg_replace('/^\s*\[\[chk:[01]\]\]\s?/u','',$ln);
    $ln=preg_replace('/^(#{1,3})\s+/','',$ln);
    $ln=preg_replace('/^[-•]\s+/u','',$ln);
    $ln=preg_replace('/^\d+[.)]\s+/','',$ln);
    $ln=preg_replace('/^>\s?/','',$ln);
    if(preg_match('/^\s*---+\s*$/',$ln)) $ln='';
    if(preg_match('/^\s*\|.*\|\s*$/',$ln)) $ln=trim(str_replace('|',' ',$ln));
    $ln=preg_replace('/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/u','$1',$ln);
    $ln=preg_replace('/\*\*(.+?)\*\*/us','$1',$ln);
    $ln=preg_replace('/__(.+?)__/us','$1',$ln);
    $ln=preg_replace('/~~(.+?)~~/us','$1',$ln);
    $ln=preg_replace('/`([^`\n]+)`/u','$1',$ln);
    $ln=preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/us','$1',$ln);
    $out[]=$ln;
  }
  $plano=trim(preg_replace('/\s+/',' ', implode(' ', $out)));
  if($plano==='') return '';
  return mb_strlen($plano)>$n ? (mb_substr($plano,0,$n).'…') : $plano;
}
}

/* ---- Barra de herramientas del editor (bloques, formato, enlace, código, emoji) ---- */
function rt_editor_toolbar($edId){
  /* El id va dentro de onclick="…(<?= $ed ?>)". json_encode lo envuelve en comillas
     DOBLES y esas comillas cortaban el atributo HTML (rompía TODOS los botones).
     Se escapa para HTML: las " pasan a &quot; y el navegador las relee como " al
     parsear el atributo, dejando un literal JS válido: rtChkAdd("actaBody"). */
  $ed = htmlspecialchars(json_encode((string)$edId), ENT_QUOTES); ?>
  <div class="cbar rt-bar">
    <button class="tool rt-more" type="button" title="Bloques: títulos, listas, cita, tabla…" onclick="rtMenu(this,<?= $ed ?>)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="16" y2="6"/><line x1="3" y1="12" x2="13" y2="12"/><line x1="3" y1="18" x2="16" y2="18"/><line x1="19" y1="9" x2="19" y2="15"/><line x1="16" y1="12" x2="22" y2="12"/></svg></button>
    <button class="tool" type="button" title="Lista de control" onclick="rtChkAdd(<?= $ed ?>)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></button>
    <button class="tool" type="button" data-emoji-btn title="Emoji" onclick="erpEmojiPicker(this,null,document.getElementById(<?= $ed ?>))"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5a4.5 4.5 0 0 0 7 0"/><path d="M9 9.5h.01"/><path d="M15 9.5h.01"/></svg></button>
    <span class="cbar-sep"></span>
    <button class="tool" type="button" title="Negrita (Ctrl+B)" onclick="rtFmt(<?= $ed ?>,'bold')" style="font-weight:800">B</button>
    <button class="tool" type="button" title="Cursiva (Ctrl+I)" onclick="rtFmt(<?= $ed ?>,'italic')" style="font-style:italic;font-weight:700">I</button>
    <button class="tool" type="button" title="Subrayado (Ctrl+U)" onclick="rtFmt(<?= $ed ?>,'underline')" style="text-decoration:underline;font-weight:700">U</button>
    <button class="tool" type="button" title="Tachado" onclick="rtFmt(<?= $ed ?>,'strikeThrough')" style="text-decoration:line-through;font-weight:700">S</button>
    <button class="tool" type="button" title="Enlace" onclick="rtLink(<?= $ed ?>)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.07 0l2.83-2.83a5 5 0 0 0-7.07-7.07l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7.07 0L4.1 13.83a5 5 0 0 0 7.07 7.07l1.5-1.5"/></svg></button>
    <button class="tool" type="button" title="Código / comando" onclick="rtCode(<?= $ed ?>)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 18l6-6-6-6"/><path d="M8 6l-6 6 6 6"/></svg></button>
  </div>
<?php }

/* ---- CSS + JS del editor. Se emite una sola vez por página. ---- */
function rt_editor_assets(){
  static $done=false; if($done) return; $done=true;
  ?>
  <style>
  .rt-wrap{border:none;border-bottom:1px solid var(--line);border-radius:0;background:transparent;transition:border-color .14s ease}
  .rt-wrap:focus-within{border-bottom-color:var(--accent)}
  .rt-editor{min-height:180px;max-height:70vh;overflow:auto;padding:14px 2px;font-size:14px;line-height:1.6;outline:none;color:var(--ink);word-break:break-word}
  .rt-editor:empty:before{content:attr(data-ph);color:var(--label);pointer-events:none}
  .rt-editor b,.rt-editor strong{font-weight:700}.rt-editor u{text-decoration:underline}.rt-editor i,.rt-editor em{font-style:italic}
  .rt-bar{padding:4px 0 8px;display:flex;align-items:center;gap:2px;flex-wrap:wrap}
  .rt-view{font-size:14px;line-height:1.6;color:var(--ink);word-break:break-word}
  .cbar{display:flex;align-items:center;gap:2px;margin-top:6px}
  .cbar .cbar-sep{width:1px;height:18px;background:var(--line);margin:0 5px}
  .cbar .tool{border:none;background:none;color:var(--label);cursor:pointer;padding:6px;border-radius:7px;display:inline-flex}
  .cbar .tool:hover{background:var(--soft);color:var(--ink)}.cbar .tool svg{width:16px;height:16px}
  .rt-code,.rt-editor code,.rt-view code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;font-size:.86em;background:#f3f3f5;border:1px solid #e6e7ea;border-radius:6px;padding:1px 6px;color:#c0343a;white-space:nowrap}
  .rt-link,.rt-editor a,.rt-view a{color:#0071e3;text-decoration:none;font-weight:500;cursor:pointer}
  .rt-link:hover,.rt-view a:hover,.rt-editor a:hover{text-decoration:underline}
  .rt-editor h1,.rt-view .rt-h1{font-size:1.5em;font-weight:750;line-height:1.25;margin:.5em 0 .2em}
  .rt-editor h2,.rt-view .rt-h2{font-size:1.28em;font-weight:700;line-height:1.3;margin:.5em 0 .2em}
  .rt-editor h3,.rt-view .rt-h3{font-size:1.1em;font-weight:700;margin:.4em 0 .15em}
  .rt-editor ul,.rt-editor ol,.rt-view .rt-ul,.rt-view .rt-ol{margin:.25em 0;padding-left:1.5em}
  .rt-editor li,.rt-view li{margin:2px 0}
  .rt-editor blockquote,.rt-view .rt-quote{border-left:3px solid #d7dae0;margin:.45em 0;padding:2px 0 2px 13px;color:#5c616b}
  .rt-editor pre,.rt-view .rt-pre{background:#f6f7f9;border:1px solid var(--line);border-radius:8px;padding:10px 12px;margin:.45em 0;overflow:auto;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.86em;white-space:pre;color:var(--ink)}
  .rt-editor pre code,.rt-view .rt-pre code{background:none;border:none;padding:0;color:inherit;white-space:pre}
  .rt-editor hr,.rt-view .rt-hr{border:none;border-top:1px solid var(--line);margin:.7em 0}
  /* Lista de control: editable (casillas de verdad) en el editor · estática al leer. */
  .rt-chkedit{display:flex;align-items:flex-start;gap:9px;margin:4px 0}
  .rt-chkedit input{margin-top:3px;width:15px;height:15px;accent-color:var(--accent);cursor:pointer;flex:none}
  .rt-chkedit .rt-chktxt{flex:1;outline:none;min-height:1.2em}
  .rt-chk{display:flex;gap:8px;align-items:flex-start;margin:2px 0}
  .rt-chk .rt-cbox{width:16px;height:16px;flex:none;border:1.5px solid var(--line);border-radius:4px;font-size:11px;line-height:13px;text-align:center;color:var(--ok)}
  .rt-chk.done{color:var(--muted);text-decoration:line-through}
  .rt-chk.done .rt-cbox{background:#e7f7ee;border-color:#bfe6cf}
  .rt-view .rt-chk input,.ac-body .rt-chk input{pointer-events:none}
  [data-theme=dark] .rt-chk.done .rt-cbox{background-color:var(--ok-bg,#12341f);border-color:var(--ok-line,#1f5c37)}
  .rt-tablewrap{overflow-x:auto;margin:.45em 0}
  .rt-editor table,.rt-view .rt-table{border-collapse:collapse;font-size:.94em;min-width:200px;cursor:text}
  .rt-editor th,.rt-editor td,.rt-view .rt-table th,.rt-view .rt-table td{border:1px solid var(--line);padding:5px 9px;text-align:left;vertical-align:top}
  .rt-editor th,.rt-view .rt-table th{background:var(--soft);font-weight:650}
  .rt-menu{position:fixed;z-index:2400;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 46px rgba(16,19,24,.18);padding:5px;min-width:212px;display:none;max-height:70vh;overflow:auto}
  .rt-menu.on{display:block;animation:pop .14s ease}
  .rt-menu button{display:flex;align-items:center;gap:11px;width:100%;border:none;background:none;text-align:left;font:inherit;font-size:13px;color:var(--ink);padding:8px 10px;border-radius:8px;cursor:pointer}
  .rt-menu button:hover{background:var(--soft)}
  .rt-menu .rt-mi{width:26px;height:22px;flex:none;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--muted);background:var(--soft);border-radius:6px}
  .rt-menu-sep{height:1px;background:var(--line);margin:4px 6px}
  .rt-more svg{display:block}
  .rt-tctl{position:fixed;z-index:2500;display:none;gap:3px;background:#fff;border:1px solid var(--line);border-radius:9px;box-shadow:0 10px 30px rgba(16,19,24,.16);padding:3px}
  .rt-tctl.on{display:flex}
  .rt-tctl button{border:none;background:var(--soft);color:var(--ink);font:inherit;font-size:11px;font-weight:600;padding:4px 8px;border-radius:6px;cursor:pointer;white-space:nowrap}
  .rt-tctl button:hover{background:var(--accent-soft)}
  .rt-tctl button[data-a="colX"]:hover,.rt-tctl button[data-a="rowX"]:hover{background:#feecec;color:#c0343a}
  [data-theme=dark] .rt-menu,[data-theme=dark] .rt-tctl{background-color:var(--pop)}
  [data-theme=dark] .rt-editor:empty:before{color:var(--muted)}
  [data-theme=dark] .rt-code,[data-theme=dark] .rt-editor code,[data-theme=dark] .rt-view code{background-color:var(--soft);border-color:var(--line)}
  [data-theme=dark] .rt-editor pre,[data-theme=dark] .rt-view .rt-pre{background-color:var(--soft)}
  [data-theme=dark] .rt-editor blockquote,[data-theme=dark] .rt-view .rt-quote{border-left-color:var(--line);color:var(--muted)}
  </style>
  <script>
  /* ===== Editor enriquecido reutilizable (mismo motor que la tarea) ===== */
  (function(){
  if(window._rtEditorReady)return; window._rtEditorReady=1;
  function _rtEsc(s){return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
  function edOf(id){return document.getElementById(id);}
  window.rtExec=function(edId,cmd,val){var ed=edOf(edId);if(!ed)return;ed.focus();try{document.execCommand('styleWithCSS',false,false);}catch(e){}try{document.execCommand(cmd,false,val||null);}catch(e){}};
  window.rtBlock=function(edId,tag){rtExec(edId,'formatBlock',tag);};
  window.rtFmt=function(edId,cmd){var ed=edOf(edId);if(!ed)return;ed.focus();try{document.execCommand('styleWithCSS',false,false);}catch(e){}try{document.execCommand(cmd,false,null);}catch(e){}};
  window.rtTable=function(edId){var ed=edOf(edId);if(!ed)return;ed.focus();document.execCommand('insertHTML',false,'<table class="rt-table"><thead><tr><th>Columna</th><th>Columna</th></tr></thead><tbody><tr><td><br></td><td><br></td></tr><tr><td><br></td><td><br></td></tr></tbody></table><div class="rt-p"><br></div>');};
  window.rtCode=function(edId){var ed=edOf(edId);if(!ed)return;ed.focus();var sel=window.getSelection();var txt=(sel&&sel.rangeCount)?sel.toString():'';document.execCommand('insertHTML',false,'<code>'+_rtEsc(txt||'código')+'</code> ');};
  /* Inserta un bloque (nodo) en el cursor, nunca DENTRO de un ítem de checklist
     (el serializador solo lee su texto): si el cursor está ahí, va justo después. */
  function rtInsertNode(ed,node){if(!ed)return;ed.focus();var sel=window.getSelection();var r;
    if(sel&&sel.rangeCount&&ed.contains(sel.anchorNode)){r=sel.getRangeAt(0);
      var an=(sel.anchorNode.nodeType===1?sel.anchorNode:sel.anchorNode.parentNode);
      var chk=an&&an.closest?an.closest('.rt-chkedit'):null;
      if(chk){r=document.createRange();r.setStartAfter(chk);r.collapse(true);}else{r.deleteContents();}
    }else{r=document.createRange();r.selectNodeContents(ed);r.collapse(false);}
    r.insertNode(node);r.setStartAfter(node);r.collapse(true);sel.removeAllRanges();sel.addRange(r);}
  /* Lista de control: mismo marcador ([[chk:0/1]]) y misma casilla que una tarea. */
  window.rtChkAdd=function(edId){var ed=edOf(edId);if(!ed)return;ed.focus();
    var d=document.createElement('div');d.className='rt-chkedit';
    var cb=document.createElement('input');cb.type='checkbox';
    var sp=document.createElement('span');sp.className='rt-chktxt';
    d.appendChild(cb);d.appendChild(sp);rtInsertNode(ed,d);
    var sel=window.getSelection();var r=document.createRange();r.selectNodeContents(sp);r.collapse(true);sel.removeAllRanges();sel.addRange(r);};
  window.rtLink=function(edId){var ed=edOf(edId);if(!ed)return;ed.focus();var sel=window.getSelection();var range=(sel&&sel.rangeCount&&ed.contains(sel.anchorNode))?sel.getRangeAt(0).cloneRange():null;var txt=range?range.toString():'';
    erpPrompt('Pega o escribe el enlace (URL):',(txt&&/^https?:/i.test(txt))?txt:'https://').then(function(u){if(!u)return;if(!/^https?:\/\//i.test(u))u='https://'+String(u).replace(/^\/+/,'');ed.focus();var s=window.getSelection();if(range){s.removeAllRanges();s.addRange(range);}var label=txt||u;document.execCommand('insertHTML',false,'<a href="'+_rtEsc(u)+'">'+_rtEsc(label)+'</a> ');});};
  var RT_BLOCKS=[
   {t:'Texto normal',i:'¶',fn:function(ed){rtBlock(ed,'P');}},
   {t:'Título grande',i:'H1',fn:function(ed){rtBlock(ed,'H1');}},
   {t:'Título mediano',i:'H2',fn:function(ed){rtBlock(ed,'H2');}},
   {t:'Título pequeño',i:'H3',fn:function(ed){rtBlock(ed,'H3');}},
   {sep:1},
   {t:'Lista con viñetas',i:'•',fn:function(ed){rtExec(ed,'insertUnorderedList');}},
   {t:'Lista numerada',i:'1.',fn:function(ed){rtExec(ed,'insertOrderedList');}},
   {t:'Lista de control',i:'☑',fn:function(ed){rtChkAdd(ed);}},
   {sep:1},
   {t:'Cita',i:'❝',fn:function(ed){rtBlock(ed,'BLOCKQUOTE');}},
   {t:'Bloque de código',i:'{}',fn:function(ed){rtBlock(ed,'PRE');}},
   {t:'Tabla',i:'▦',fn:function(ed){rtTable(ed);}},
   {t:'Divisor',i:'—',fn:function(ed){rtExec(ed,'insertHorizontalRule');}}
  ];
  function rtMenuClose(){var m=document.getElementById('rtMenu');if(m)m.classList.remove('on');}
  window.rtMenu=function(btn,edId){var m=document.getElementById('rtMenu');
    if(!m){m=document.createElement('div');m.id='rtMenu';m.className='rt-menu';document.body.appendChild(m);
      document.addEventListener('mousedown',function(e){if(m.classList.contains('on')&&!e.target.closest('#rtMenu')&&!e.target.closest('.rt-more'))rtMenuClose();});}
    if(m.classList.contains('on')&&m.dataset.ed===edId){rtMenuClose();return;}
    m.dataset.ed=edId;m.innerHTML='';
    RT_BLOCKS.forEach(function(it){if(it.sep){var s=document.createElement('div');s.className='rt-menu-sep';m.appendChild(s);return;}
      var b=document.createElement('button');b.type='button';b.innerHTML='<span class="rt-mi">'+it.i+'</span>'+it.t;
      b.onmousedown=function(e){e.preventDefault();};b.onclick=function(){rtMenuClose();it.fn(edId);};m.appendChild(b);});
    var r=btn.getBoundingClientRect();m.classList.add('on');var h=m.offsetHeight;var top=r.bottom+6;if(top+h>window.innerHeight-8)top=Math.max(8,r.top-h-6);
    m.style.left=Math.max(8,Math.min(r.left,window.innerWidth-232))+'px';m.style.top=top+'px';};
  /* Salir del formato en línea al pulsar Enter (y del código con espacio/letra). */
  function rtEscInline(ed){ if(!ed)return;
    var FMT={code:1,b:1,strong:1,i:1,em:1,u:1,s:1,strike:1,del:1};
    function caretAtEnd(r,el){try{var rng=document.createRange();rng.selectNodeContents(el);rng.setStart(r.startContainer,r.startOffset);return rng.toString().replace(/[​ \s]/g,'').length===0;}catch(e){return false;}}
    ed.addEventListener('keydown',function(e){
      if(e.ctrlKey||e.metaKey||e.altKey)return;
      var isEnter=(e.key==='Enter'),isChar=(e.key===' '||e.key.length===1);
      if(!isEnter&&!isChar)return;
      var sel=window.getSelection(); if(!sel.rangeCount||!sel.isCollapsed)return;
      var r=sel.getRangeAt(0);
      var fmt=null,cur=(r.startContainer.nodeType===3?r.startContainer.parentNode:r.startContainer);
      while(cur&&cur!==ed){var t=(cur.nodeName||'').toLowerCase();
        if(FMT[t]){if(caretAtEnd(r,cur))fmt=cur;else return;}
        else if(cur.nodeType===1&&/^(div|p|li|blockquote|td|th|h1|h2|h3|pre|ul|ol)$/.test(t))break;
        cur=cur.parentNode;}
      if(!fmt)return;
      if(isEnter){var nr=document.createRange();nr.setStartAfter(fmt);nr.collapse(true);sel.removeAllRanges();sel.addRange(nr);return;}
      if((fmt.nodeName||'').toLowerCase()!=='code')return;
      e.preventDefault();
      var ch=(e.key===' '?' ':e.key);
      var tn=document.createTextNode(ch);fmt.parentNode.insertBefore(tn,fmt.nextSibling);
      var nr2=document.createRange();nr2.setStart(tn,ch.length);nr2.collapse(true);sel.removeAllRanges();sel.addRange(nr2);
    });
  }
  /* Tablas editables: Tab entre celdas (+fila al final) y barra flotante. */
  var _rtTcell=null,_rtTedId='',_rtTHideT=null;
  function rtCellFocus(c){if(!c)return;var sel=window.getSelection();var r=document.createRange();r.selectNodeContents(c);r.collapse(true);sel.removeAllRanges();sel.addRange(r);c.scrollIntoView&&c.scrollIntoView({block:'nearest'});}
  function rtRowAdd(row){var n=row.children.length;var tr=document.createElement('tr');for(var i=0;i<n;i++){var td=document.createElement('td');td.innerHTML='<br>';tr.appendChild(td);}row.parentNode.insertBefore(tr,row.nextSibling);return tr;}
  function rtTableTab(ed){ if(!ed)return;
    ed.addEventListener('keydown',function(e){ if(e.key!=='Tab')return;
      var sel=window.getSelection(); if(!sel.rangeCount)return;
      var node=sel.getRangeAt(0).startContainer; var cell=(node.nodeType===3?node.parentNode:node); cell=cell.closest?cell.closest('td,th'):null;
      if(!cell||!ed.contains(cell))return; e.preventDefault();
      var table=cell.closest('table'); var cells=Array.prototype.slice.call(table.querySelectorAll('td,th')); var idx=cells.indexOf(cell);
      if(e.shiftKey){ if(idx>0)rtCellFocus(cells[idx-1]); }
      else if(idx<cells.length-1){ rtCellFocus(cells[idx+1]); }
      else { rtRowAdd(cell.parentNode); var nc=table.querySelectorAll('td,th'); rtCellFocus(nc[idx+1]); }
    });
  }
  function rtTctlEl(){var m=document.getElementById('rtTctl');if(!m){m=document.createElement('div');m.id='rtTctl';m.className='rt-tctl';
      m.innerHTML='<button type="button" data-a="colR" title="Añadir columna a la derecha">＋col</button><button type="button" data-a="rowB" title="Añadir fila debajo">＋fila</button><button type="button" data-a="colX" title="Borrar esta columna">－col</button><button type="button" data-a="rowX" title="Borrar esta fila">－fila</button>';
      document.body.appendChild(m);
      m.addEventListener('mousedown',function(e){e.preventDefault();});
      m.addEventListener('mouseenter',function(){if(_rtTHideT){clearTimeout(_rtTHideT);_rtTHideT=null;}});
      m.addEventListener('mouseleave',function(){rtTctlHideSoon();});
      m.addEventListener('click',function(e){var b=e.target.closest('button');if(b&&_rtTcell)rtTblAction(b.getAttribute('data-a'));});
    }return m;}
  function rtTctlShow(cell){if(!cell)return;var table=cell.closest('table');if(!table)return;var m=rtTctlEl();var tr=table.getBoundingClientRect();
    m.classList.add('on');var w=m.offsetWidth||168;m.style.left=Math.max(8,Math.min(tr.right-w,window.innerWidth-w-8))+'px';m.style.top=Math.max(6,tr.top-30)+'px';}
  function rtTctlHideSoon(){if(_rtTHideT)clearTimeout(_rtTHideT);_rtTHideT=setTimeout(function(){var m=document.getElementById('rtTctl');if(m&&!m.matches(':hover'))m.classList.remove('on');},380);}
  function rtTblAction(a){var cell=_rtTcell;if(!cell||!cell.isConnected)return;var table=cell.closest('table');var row=cell.parentNode;var idx=Array.prototype.indexOf.call(row.children,cell);
    if(a==='rowB'){rtRowAdd(row);}
    else if(a==='rowX'){if(table.querySelectorAll('tr').length>1){var sib=row.nextElementSibling||row.previousElementSibling;row.remove();_rtTcell=sib?sib.children[Math.min(idx,sib.children.length-1)]:table.querySelector('td,th');}}
    else if(a==='colR'){Array.prototype.forEach.call(table.querySelectorAll('tr'),function(tr){var isH=!!tr.closest('thead');var nc=document.createElement(isH?'th':'td');nc.innerHTML='<br>';var ref=tr.children[idx];if(ref)tr.insertBefore(nc,ref.nextSibling);else tr.appendChild(nc);});}
    else if(a==='colX'){var cols=(table.querySelector('tr')||{children:[]}).children.length;if(cols>1)Array.prototype.forEach.call(table.querySelectorAll('tr'),function(tr){if(tr.children[idx])tr.children[idx].remove();});}
    rtTctlShow(_rtTcell&&_rtTcell.isConnected?_rtTcell:table.querySelector('td,th'));}
  function rtTableHover(ed){ if(!ed)return;
    ed.addEventListener('mouseover',function(e){var c=e.target.closest?e.target.closest('td,th'):null;if(c&&ed.contains(c)){_rtTcell=c;_rtTedId=ed.id;if(_rtTHideT){clearTimeout(_rtTHideT);_rtTHideT=null;}rtTctlShow(c);}});
    ed.addEventListener('mouseleave',function(){rtTctlHideSoon();});
  }
  /* Limpia HTML pegado (web, Word, Docs, Notion…) conservando la ESTRUCTURA:
     encabezados, listas, cita, tabla, código y formato en línea. */
  function sanitizePaste(html){
    var doc=new DOMParser().parseFromString(html,'text/html');
    function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
    function url(n){var h=(n.getAttribute&&n.getAttribute('href'))||'';return /^https?:\/\//i.test(h)?h:'';}
    function cleanTable(t){var rows=t.querySelectorAll('tr');if(!rows.length)return '';var h='<table class="rt-table">';var open='';
      rows.forEach(function(tr,ri){var cells=tr.querySelectorAll('th,td');if(!cells.length)return;var tc=(ri===0?'th':'td');var r='<tr>';
        cells.forEach(function(c){var inn=walk(c).replace(/<br>/g,' ').trim();r+='<'+tc+'>'+(inn||'&nbsp;')+'</'+tc+'>';});r+='</tr>';
        if(ri===0){h+='<thead>'+r+'</thead>';open='<tbody>';}else{if(open){h+=open;open='';}h+=r;}});
      return h+(open===''?'</tbody>':'')+'</table>';}
    function walk(node){var out='';var kids=node.childNodes;for(var i=0;i<kids.length;i++){var n=kids[i];
      if(n.nodeType===3){out+=esc(n.nodeValue);continue;}
      if(n.nodeType!==1)continue;
      var tag=n.nodeName.toLowerCase();
      if(tag==='br'){out+='<br>';continue;}
      if(tag==='style'||tag==='script'||tag==='head'||tag==='noscript'||tag==='img'||tag==='svg'||tag==='input'||tag==='button')continue;
      if(tag==='pre'){out+='<pre class="rt-pre"><code>'+esc(n.textContent||'')+'</code></pre>';continue;}
      if(tag==='code'||tag==='kbd'||tag==='samp'||tag==='tt'){out+='<code>'+walk(n)+'</code>';continue;}
      if(tag==='hr'){out+='<hr>';continue;}
      if(tag==='a'){var u=url(n);var ia=walk(n)||esc(n.textContent||'');out+=(u?('<a href="'+esc(u)+'">'+ia+'</a>'):ia);continue;}
      if(/^h[1-6]$/.test(tag)){var lv=Math.min(3,parseInt(tag.charAt(1),10));out+='<h'+lv+'>'+walk(n)+'</h'+lv+'>';continue;}
      if(tag==='ul'||tag==='ol'){var items='';for(var j=0;j<n.children.length;j++){var li=n.children[j];if(li.nodeName.toLowerCase()==='li')items+='<li>'+walk(li).replace(/<br>\s*$/,'')+'</li>';}if(items)out+='<'+tag+' class="rt-'+tag+'">'+items+'</'+tag+'>';continue;}
      if(tag==='blockquote'){out+='<blockquote class="rt-quote">'+walk(n)+'</blockquote>';continue;}
      if(tag==='table'){out+=cleanTable(n);continue;}
      if(tag==='thead'||tag==='tbody'||tag==='tr'||tag==='td'||tag==='th'){out+=walk(n);continue;}
      var st=(n.getAttribute&&n.getAttribute('style'))||'';
      var bold=(tag==='b'||tag==='strong'||/font-weight\s*:\s*(bold|[6-9]00)/i.test(st));
      var ital=(tag==='i'||tag==='em'||/font-style\s*:\s*italic/i.test(st));
      var und=(tag==='u'||/text-decoration[^;]*underline/i.test(st));
      var stk=(tag==='s'||tag==='strike'||tag==='del'||/text-decoration[^;]*line-through/i.test(st));
      var inner=walk(n);
      if(bold)inner='<b>'+inner+'</b>';if(ital)inner='<i>'+inner+'</i>';if(und)inner='<u>'+inner+'</u>';if(stk)inner='<s>'+inner+'</s>';
      if(tag==='p'||tag==='div'||tag==='section'||tag==='article'){out+='<div>'+inner+'</div>';}
      else out+=inner;}
      return out;}
    return walk(doc.body);}
  /* Texto de un nodo recuperando los emojis Apple (spans .ap-e -> data-e). */
  function rtText(node){var s='';var k=node.childNodes;for(var i=0;i<k.length;i++){var n=k[i];
    if(n.nodeType===3){s+=n.nodeValue;}
    else if(n.nodeType===1){s+=(n.classList&&n.classList.contains('ap-e'))?(n.getAttribute('data-e')||''):rtText(n);}}
    return s;}
  /* Serializa el editor a los MISMOS marcadores que guarda una tarea. */
  window.rtSerialize=function(edId){var ed=edOf(edId);if(!ed)return'';var out='';
    (function walk(node){var kids=node.childNodes;for(var i=0;i<kids.length;i++){var n=kids[i];
      if(n.nodeType===3){out+=n.nodeValue;}
      else if(n.nodeType===1){var tag=n.nodeName.toLowerCase();
        if(n.classList&&n.classList.contains('ap-e')){out+=(n.getAttribute('data-e')||'');}
        else if(n.classList&&n.classList.contains('rt-chkedit')){var cb=n.querySelector('input[type=checkbox]');var tx=n.querySelector('.rt-chktxt');out+='\n[[chk:'+((cb&&cb.checked)?'1':'0')+']] '+((tx?rtText(tx):'').replace(/\n/g,' '))+'\n';}
        else if(tag==='br'){out+='\n';}
        else if(tag==='h1'||tag==='h2'||tag==='h3'){var _hp={h1:'# ',h2:'## ',h3:'### '}[tag];var _hs=out;out='';walk(n);var _hi=out.replace(/\s+/g,' ').trim();out=_hs;out+='\n'+_hp+_hi+'\n';}
        else if(tag==='ul'||tag==='ol'){var _lo=(tag==='ol'),_lk=1;out+='\n';for(var _lc=0;_lc<n.children.length;_lc++){var _li=n.children[_lc];if(_li.nodeName.toLowerCase()!=='li')continue;var _ls=out;out='';walk(_li);var _lin=out.replace(/\s+/g,' ').trim();out=_ls;out+=(_lo?(_lk++)+'. ':'- ')+_lin+'\n';}}
        else if(tag==='blockquote'){var _qs=out;out='';walk(n);var _qi=out.replace(/^\n+|\n+$/g,'');out=_qs;out+='\n'+_qi.split('\n').map(function(l){return '> '+l;}).join('\n')+'\n';}
        else if(tag==='pre'){out+='\n```\n'+rtText(n).replace(/```/g,'')+'\n```\n';}
        else if(tag==='hr'){out+='\n---\n';}
        else if(tag==='table'){out+='\n';var _trs=n.querySelectorAll('tr');for(var _ri=0;_ri<_trs.length;_ri++){var _cc=_trs[_ri].querySelectorAll('th,td'),_cs=[];for(var _ci=0;_ci<_cc.length;_ci++){var _ts=out;out='';walk(_cc[_ci]);_cs.push(out.replace(/\s+/g,' ').replace(/\|/g,'/').trim());out=_ts;}if(_cs.length){out+='| '+_cs.join(' | ')+' |\n';if(_ri===0){out+='|'+_cs.map(function(){return' --- ';}).join('|')+'|\n';}}}out+='\n';}
        else if(tag==='s'||tag==='strike'||tag==='del'){out+='~~';walk(n);out+='~~';}
        else if(tag==='code'){out+='`'+rtText(n).replace(/`/g,'')+'`';}
        else if(tag==='a'&&n.getAttribute('href')){var _h=n.getAttribute('href')||'';var _t=n.textContent||'';out+=(/^https?:/i.test(_h)?(_t===_h?_h:'['+_t+']('+_h+')'):_t);}
        else if(tag==='b'||tag==='strong'){out+='**';walk(n);out+='**';}
        else if(tag==='u'){out+='__';walk(n);out+='__';}
        else if(tag==='i'||tag==='em'){out+='*';walk(n);out+='*';}
        else if(tag==='div'||tag==='p'){out+='\n';walk(n);}
        else{var _st=n.style||{};var _td=(_st.textDecoration||'')+' '+(_st.textDecorationLine||'');var _stk=_td.indexOf('line-through')>-1;if(_stk)out+='~~';walk(n);if(_stk)out+='~~';}
      }}})(ed);
    return out.replace(/[ \t]+\n/g,'\n').replace(/\n{3,}/g,'\n\n').replace(/^\n+|\n+$/g,'');};
  /* Cablea cada editor .rt-editor que haya en la página (pegado, tablas, formato). */
  function wire(ed){ if(!ed||ed._rtWired)return; ed._rtWired=1;
    rtEscInline(ed); rtTableTab(ed); rtTableHover(ed);
    ed.addEventListener('paste',function(e){var dt=e.clipboardData||window.clipboardData;if(!dt)return;
      var html=dt.getData('text/html');if(html&&html.trim()){var clean=sanitizePaste(html);if(clean){e.preventDefault();document.execCommand('insertHTML',false,clean);return;}}
      e.preventDefault();var t=dt.getData('text/plain');document.execCommand('insertText',false,t);});
  }
  function wireAll(){document.querySelectorAll('.rt-editor[contenteditable]').forEach(wire);}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',wireAll);else wireAll();
  })();
  </script>
<?php }
