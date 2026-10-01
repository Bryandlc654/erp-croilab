/* ================= Emojis Apple: render inline + picker global =================
   Un único componente para TODO el ERP. Los emojis se guardan como caracteres
   Unicode normales (no se toca la BD); aquí (a) se pintan como imágenes del set de
   Apple usando un spritesheet, y (b) se ofrece un selector reutilizable anclado a
   cualquier campo de texto. Los datos (lista + coords) llegan en assets/emoji/emoji-data.js. */
(function(){
  if(window.__emxReady) return; window.__emxReady=1;
  var N=window.EMOJI_N||62;                 // rejilla del sheet (62x62, sin huecos)
  var LIST=window.EMOJI_LIST||[];           // [unified,name,keywords,cat,x,y]
  var SKINS=window.EMOJI_SKINS||[];         // [unified,x,y]  (tonos de piel, solo render)
  var CATS=window.EMOJI_CATS||[];
  var IS_MAC=/Mac|iPhone|iPad/i.test(navigator.platform||navigator.userAgent||'');
  var SC_LABEL=(IS_MAC?'⌘':'Ctrl')+' + .';   // atajo para abrir emojis
  window.EMOJI_SC=SC_LABEL;

  function emoChar(u){return u.split('-').map(function(h){return String.fromCodePoint(parseInt(h,16));}).join('');}
  function bgPos(x,y){return (x*100/(N-1))+'% '+(y*100/(N-1))+'%';}

  /* ---- índices ---- */
  var MAP=Object.create(null);              // char -> "x y"  (para pintar en el texto)
  var IDX=Object.create(null);              // unified -> [x,y,name]  (para el picker/pestañas)
  function addRC(u,x,y){
    var c=emoChar(u); if(!(c in MAP))MAP[c]=x+' '+y;
    if(/-?FE0F/i.test(u)){ var bare=u.replace(/-?FE0F/ig,''); if(bare){ var cb=emoChar(bare); if(!(cb in MAP))MAP[cb]=x+' '+y; } }
  }
  LIST.forEach(function(e){ addRC(e[0],e[4],e[5]); IDX[e[0]]=[e[4],e[5],e[1]]; });
  SKINS.forEach(function(s){ addRC(s[0],s[1],s[2]); });

  /* regex con TODAS las secuencias, las largas primero (ZWJ, banderas, VS16) */
  var RE=null;
  (function(){
    var keys=Object.keys(MAP); if(!keys.length){return;}
    keys.sort(function(a,b){return b.length-a.length;});
    var esc=keys.map(function(k){return k.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');});
    try{ RE=new RegExp(esc.join('|'),'g'); }catch(e){ RE=null; }
  })();

  /* ============ 1) Pintar emojis del texto como imágenes Apple ============ */
  function spriteEl(chr,editable){
    var xy=MAP[chr]; if(!xy) return document.createTextNode(chr);
    xy=xy.split(' ');
    var s=document.createElement('span');
    s.className='ap-e'; s.style.backgroundPosition=bgPos(+xy[0],+xy[1]);
    s.setAttribute('role','img'); s.setAttribute('aria-label',chr); s.setAttribute('data-e',chr); s.title=chr;
    /* dentro de un editor va como bloque atómico: el cursor no entra, se borra entero */
    if(editable) s.setAttribute('contenteditable','false');
    return s;
  }
  function sprite(chr){ return spriteEl(chr,false); }
  function editableAncestor(el){ for(var e=el;e;e=e.parentNode){ if(e.nodeType===1&&e.isContentEditable) return true; } return false; }
  function skip(el){
    for(var e=el;e;e=e.parentNode){ if(e.nodeType!==1)continue;
      var t=e.tagName;
      if(t==='SCRIPT'||t==='STYLE'||t==='TEXTAREA'||t==='INPUT'||t==='OPTION') return true;
      if(e.isContentEditable) return true;
      if(e.classList&&(e.classList.contains('ap-e')||e.classList.contains('emx')||e.hasAttribute('data-noemoji')||e.classList.contains('no-emoji'))) return true;
    }
    return false;
  }
  function replaceNode(node){
    var text=node.nodeValue; RE.lastIndex=0;
    var frag=document.createDocumentFragment(), last=0, m, any=false;
    while((m=RE.exec(text))){ any=true;
      if(m.index>last)frag.appendChild(document.createTextNode(text.slice(last,m.index)));
      frag.appendChild(sprite(m[0])); last=m.index+m[0].length;
    }
    if(!any)return;
    if(last<text.length)frag.appendChild(document.createTextNode(text.slice(last)));
    node.parentNode.replaceChild(frag,node);
  }
  var busy=false, obs=null;
  function appleify(root){
    if(!RE||!root||root.nodeType!==1&&root.nodeType!==9&&root.nodeType!==11)return;
    var w=document.createTreeWalker(root,NodeFilter.SHOW_TEXT,{acceptNode:function(n){
      if(!n.nodeValue||n.nodeValue.length<1)return NodeFilter.FILTER_REJECT;
      if(skip(n.parentNode))return NodeFilter.FILTER_REJECT;
      RE.lastIndex=0; return RE.test(n.nodeValue)?NodeFilter.FILTER_ACCEPT:NodeFilter.FILTER_REJECT;
    }});
    var nodes=[],n; while((n=w.nextNode()))nodes.push(n);
    nodes.forEach(replaceNode);
  }
  window.apEmojify=appleify;

  /* ---- Emojis Apple DENTRO de un editor (barra de escribir contenteditable) ----
     El observador global salta los contenteditable a propósito (no debe reescribir lo
     que estás editando). Para los editores que lo piden (data-emoji-live) los pintamos
     aquí de forma controlada, sin perder el cursor. */

  /* Convierte el emoji que ACABAS de escribir/insertar (el que queda justo antes del
     cursor) en su imagen Apple, y deja el cursor detrás. */
  function caretEmoji(ed){
    if(!RE) return;
    var sel=window.getSelection(); if(!sel||!sel.rangeCount||!sel.isCollapsed) return;
    var r=sel.getRangeAt(0); var node=r.startContainer; if(node.nodeType!==3) return;
    if(ed && !ed.contains(node)) return;
    var off=r.startOffset; var before=node.nodeValue.slice(0,off); if(!before) return;
    RE.lastIndex=0; var m, hit=null;
    while((m=RE.exec(before))!==null){ if(m.index+m[0].length===before.length) hit=m; if(m.index===RE.lastIndex)RE.lastIndex++; }
    if(!hit) return;
    var em=hit[0], start=off-em.length;
    var mid=node.splitText(start); mid.splitText(em.length);   // mid = solo el emoji
    var sp=spriteEl(em,true);
    mid.parentNode.replaceChild(sp,mid);
    var nr=document.createRange(); nr.setStartAfter(sp); nr.collapse(true);
    sel.removeAllRanges(); sel.addRange(nr);
    if(ed) ed.__emxRange=nr.cloneRange();
  }
  window.erpAppleifyCaret=caretEmoji;

  /* Pinta TODOS los emojis ya presentes en un editor (al cargar contenido existente). */
  function appleifyEditable(ed){
    if(!RE||!ed) return;
    var w=document.createTreeWalker(ed,NodeFilter.SHOW_TEXT,{acceptNode:function(n){
      if(!n.nodeValue)return NodeFilter.FILTER_REJECT;
      if(n.parentNode&&n.parentNode.classList&&n.parentNode.classList.contains('ap-e'))return NodeFilter.FILTER_REJECT;
      RE.lastIndex=0; return RE.test(n.nodeValue)?NodeFilter.FILTER_ACCEPT:NodeFilter.FILTER_REJECT;
    }});
    var nodes=[],n; while((n=w.nextNode()))nodes.push(n);
    nodes.forEach(function(node){
      var text=node.nodeValue; RE.lastIndex=0;
      var frag=document.createDocumentFragment(), last=0, m, any=false;
      while((m=RE.exec(text))){ any=true;
        if(m.index>last)frag.appendChild(document.createTextNode(text.slice(last,m.index)));
        frag.appendChild(spriteEl(m[0],true)); last=m.index+m[0].length; }
      if(!any)return;
      if(last<text.length)frag.appendChild(document.createTextNode(text.slice(last)));
      node.parentNode.replaceChild(frag,node);
    });
  }
  window.erpAppleifyEditable=appleifyEditable;

  /* Observa cambios (mensajes/comentarios que entran por AJAX) y pinta lo nuevo.
     takeRecords() descarta las mutaciones que provoca el propio pintado → sin bucles. */
  var pend=[], tmr=null;
  function flush(){
    tmr=null; if(!pend.length)return; var roots=pend; pend=[];
    busy=true;
    roots.forEach(function(r){ if(r&&r.isConnected)appleify(r); });
    apAutoScan(document);            // por si el nuevo HTML trae campos de texto
    if(obs)obs.takeRecords(); busy=false;
  }
  function schedule(r){ pend.push(r); if(!tmr)tmr=setTimeout(flush,90); }
  function startObserver(){
    obs=new MutationObserver(function(muts){ if(busy)return;
      muts.forEach(function(mu){ for(var i=0;i<mu.addedNodes.length;i++){ var nd=mu.addedNodes[i];
        if(nd.nodeType===1)schedule(nd); else if(nd.nodeType===3&&nd.parentNode)schedule(nd.parentNode); } });
    });
    obs.observe(document.body,{childList:true,subtree:true});
  }

  /* ============ 2) Selector (picker) global reutilizable ============ */
  var pop=null, curInput=null, curCb=null, curCat=null, navList=[], navSel=-1;
  var REC_KEY='erpEmojiRecientes';
  function recents(){ try{ return JSON.parse(localStorage.getItem(REC_KEY)||'[]'); }catch(e){ return []; } }
  function pushRecent(u){ try{ var r=recents().filter(function(x){return x!==u;}); r.unshift(u); r=r.slice(0,32); localStorage.setItem(REC_KEY,JSON.stringify(r)); }catch(e){} }

  var CAT_ICON={ 'Recientes':null,
    'Smileys & Emotion':'1F600','People & Body':'1F44B','Animals & Nature':'1F436',
    'Food & Drink':'1F34E','Activities':'26BD','Travel & Places':'1F697',
    'Objects':'1F4A1','Symbols':'2764-FE0F','Flags':'1F3C1' };
  var CAT_ES={ 'Recientes':'Recientes','Smileys & Emotion':'Caras y emoción','People & Body':'Personas',
    'Animals & Nature':'Animales y naturaleza','Food & Drink':'Comida y bebida','Activities':'Actividades',
    'Travel & Places':'Viajes y lugares','Objects':'Objetos','Symbols':'Símbolos','Flags':'Banderas' };

  function elSprite(u){ var i=IDX[u]; var s=document.createElement('span'); s.className='ap-e';
    if(i)s.style.backgroundPosition=bgPos(i[0],i[1]); return s; }

  function ensurePop(){
    if(pop)return;
    pop=document.createElement('div'); pop.id='emxPop'; pop.className='emx';
    pop.innerHTML='<div class="emx-search"><input type="text" id="emxQ" placeholder="Buscar emoji…" autocomplete="off" spellcheck="false"></div>'+
      '<div class="emx-tabs" id="emxTabs"></div>'+
      '<div class="emx-body" id="emxBody"></div>'+
      '<div class="emx-foot"><span class="emx-nm" id="emxNm">Elige un emoji</span></div>';
    pop.onmousedown=function(e){ if(e.target.id!=='emxQ')e.preventDefault(); }; // no perder el foco/caret del campo
    document.body.appendChild(pop);
    // pestañas
    var tabs=pop.querySelector('#emxTabs');
    var order=['Recientes'].concat(CATS);
    order.forEach(function(cat){
      var b=document.createElement('button'); b.type='button'; b.className='emx-tab'; b.setAttribute('data-cat',cat); b.title=CAT_ES[cat]||cat;
      if(cat==='Recientes'){ b.innerHTML='<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>'; }
      else { b.appendChild(elSprite(CAT_ICON[cat])); }
      b.onclick=function(){ showCat(cat); };
      tabs.appendChild(b);
    });
    var q=pop.querySelector('#emxQ');
    q.addEventListener('input',function(){ var v=q.value.trim().toLowerCase(); if(v)search(v); else showCat(curCat||CATS[0]); });
    q.addEventListener('keydown',onKey);
    pop.querySelector('#emxBody').addEventListener('mouseover',function(e){ var b=e.target.closest?e.target.closest('.emx-em'):null; if(b)setName(b.getAttribute('data-nm')); });
  }

  function tabActive(cat){ pop.querySelectorAll('.emx-tab').forEach(function(t){ t.classList.toggle('on',t.getAttribute('data-cat')===cat); }); }
  function setName(t){ var n=pop.querySelector('#emxNm'); if(n)n.textContent=t||''; }

  function emBtn(u,name){
    var b=document.createElement('button'); b.type='button'; b.className='emx-em';
    b.setAttribute('data-u',u); b.setAttribute('data-nm',name||''); b.title=name||'';
    b.appendChild(elSprite(u));
    b.onclick=function(){ pick(u); };
    return b;
  }
  function renderGrid(items){
    var body=pop.querySelector('#emxBody'); body.innerHTML=''; navList=[]; navSel=-1;
    if(!items.length){ var e=document.createElement('div'); e.className='emx-empty'; e.textContent='Sin resultados'; body.appendChild(e); return; }
    var grid=document.createElement('div'); grid.className='emx-grid';
    items.forEach(function(it){ var b=emBtn(it[0],it[1]); grid.appendChild(b); navList.push(b); });
    body.appendChild(grid); body.scrollTop=0;
  }
  function showCat(cat){
    curCat=cat; tabActive(cat);
    var q=pop.querySelector('#emxQ'); if(q.value){ q.value=''; }
    if(cat==='Recientes'){
      var rec=recents().map(function(u){ var i=IDX[u]; return i?[u,i[2]]:null; }).filter(Boolean);
      if(!rec.length){ renderGrid([]); pop.querySelector('#emxBody').firstChild.textContent='Aún no has usado ninguno'; return; }
      renderGrid(rec); return;
    }
    var items=LIST.filter(function(e){return e[3]===cat;}).map(function(e){return [e[0],e[1]];});
    renderGrid(items);
  }
  function search(v){
    tabActive('');
    /* ranking: nombre exacto > nombre por prefijo > palabra clave por prefijo > contiene.
       Así "fire" trae 🔥 (nombre exacto) antes que ❤️‍🔥 (que solo lo lleva de keyword). */
    var scored=[];
    for(var i=0;i<LIST.length;i++){ var e=LIST[i], name=e[1], kw=e[2], sc=-1;
      if(name===v)sc=0;
      else if(name.indexOf(v)===0)sc=1;
      else { var words=kw.split(' '), starts=false, hit=false;
        for(var w=0;w<words.length;w++){ if(words[w]===v){sc=0;break;} if(words[w].indexOf(v)===0)starts=true; if(words[w].indexOf(v)>=0)hit=true; }
        if(sc<0){ if(starts)sc=2; else if(name.indexOf(v)>=0||hit)sc=3; } }
      if(sc>=0)scored.push([sc,i,e[0],e[1]]);
    }
    scored.sort(function(a,b){return a[0]-b[0]||a[1]-b[1];});
    renderGrid(scored.slice(0,250).map(function(s){return [s[2],s[3]];}));
  }
  function pick(u){
    var chr=emoChar(u); pushRecent(u);
    if(curCb)curCb(chr);
    else if(curInput)insertInto(curInput,chr);
    /* A propósito NO se cierra: así puedes poner VARIOS seguidos (reacciones, texto…).
       Se cierra con Esc, clic fuera o volviendo a pulsar el atajo/el botón. */
  }

  /* teclado: flechas mueven, Enter elige, Esc cierra */
  function highlight(){ navList.forEach(function(b,i){ b.classList.toggle('sel',i===navSel); }); if(navSel>=0){ var b=navList[navSel]; if(b){ b.scrollIntoView({block:'nearest'}); setName(b.getAttribute('data-nm')); } } }
  function cols(){ if(!navList.length)return 8; var top=navList[0].offsetTop,c=0; for(var i=0;i<navList.length;i++){ if(navList[i].offsetTop!==top)break; c++; } return c||8; }
  function onKey(e){
    if(e.key==='Escape'){ e.preventDefault(); close(); return; }
    if(!navList.length)return;
    var c=cols();
    if(e.key==='ArrowRight'){ e.preventDefault(); navSel=Math.min(navList.length-1,(navSel<0?0:navSel+1)); highlight(); }
    else if(e.key==='ArrowLeft'){ e.preventDefault(); navSel=Math.max(0,(navSel<0?0:navSel-1)); highlight(); }
    else if(e.key==='ArrowDown'){ e.preventDefault(); navSel=Math.min(navList.length-1,(navSel<0?0:navSel+c)); highlight(); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); if(navSel<0){return;} navSel=Math.max(0,navSel-c); highlight(); }
    else if(e.key==='Enter'){ if(navSel>=0){ e.preventDefault(); navList[navSel].click(); } }
  }

  function placeRect(r){
    var W=340,H=430;
    var left=Math.min(r.left, window.innerWidth-W-8); left=Math.max(8,left);
    var top=(r.bottom||r.top)+6; if(top+H>window.innerHeight-8){ top=(r.top||r.bottom)-H-6; if(top<8)top=Math.max(8,window.innerHeight-H-8); }
    pop.style.left=left+'px'; pop.style.top=top+'px';
  }
  function reset(onPick,input){
    ensurePop(); curCb=onPick||null; curInput=input||null;
    pop.classList.add('on');
    var rec=recents().length; showCat(rec?'Recientes':CATS[0]);
    var q=pop.querySelector('#emxQ'); q.value=''; setTimeout(function(){ q.focus(); },10);
  }
  function openPicker(anchor,onPick,input){ reset(onPick,input); placeRect(anchor.getBoundingClientRect()); }
  function openPickerXY(x,y,onPick){ reset(onPick,null); placeRect({left:x,right:x,top:y,bottom:y}); }
  function close(){ if(pop){ pop.classList.remove('on'); curCb=null; curInput=null; navSel=-1; } }
  window.erpEmojiPicker=openPicker; window.erpEmojiPickerXY=openPickerXY; window.erpEmojiClose=close;

  /* Atajo para abrir/cerrar el picker: Ctrl + .  (⌘ + . en Mac).
     Recordamos el ÚLTIMO campo de texto usado, así el atajo funciona aunque el foco
     lo haya robado otra cosa. Se hace preventDefault para ganarle al navegador. */
  function esCampo(el){ return !!el && (el.tagName==='TEXTAREA' || el.isContentEditable ||
    (el.tagName==='INPUT' && ['text','search',''].indexOf((el.type||'text').toLowerCase())>=0)); }
  var ultimoCampo=null;
  document.addEventListener('focusin',function(e){ if(esCampo(e.target)) ultimoCampo=e.target; });
  document.addEventListener('keydown',function(e){
    if((e.ctrlKey||e.metaKey)&&!e.altKey&&!e.shiftKey&&(e.key==='.'||e.code==='Period'||e.keyCode===190||e.which===190)){
      if(pop&&pop.classList.contains('on')){ e.preventDefault(); close(); return; }
      var el=document.activeElement; if(!esCampo(el)) el=ultimoCampo;
      if(esCampo(el)){ e.preventDefault(); try{ el.focus(); }catch(_){} openPicker(el.__emxBtn||el,null,el); }
    }
  },true);

  document.addEventListener('mousedown',function(e){
    if(pop&&pop.classList.contains('on')&&e.target.isConnected&&!e.target.closest('#emxPop')&&!e.target.closest('.emx-btn'))close();
  });

  /* ============ 3) Insertar en un campo y botón por campo ============ */
  /* Rastreador global del cursor: cada vez que mueves el cursor dentro de un campo
     editable, se guarda su posición EN ESE campo. Así, cuando abres el picker (su
     buscador roba el foco) y luego eliges un emoji, se restaura el cursor y el emoji
     entra DONDE ESTABAS, no al principio. */
  document.addEventListener('selectionchange',function(){
    try{
      var a=document.activeElement;
      if(a && (a.tagName==='TEXTAREA' || a.tagName==='INPUT') && 'selectionStart' in a){ a.__emxSel={s:a.selectionStart,e:a.selectionEnd}; return; }
      var sel=window.getSelection(); if(!sel||!sel.rangeCount) return;
      var r=sel.getRangeAt(0); var n=r.startContainer; var el=(n.nodeType===1?n:n.parentNode);
      var ed=el&&el.closest?el.closest('[contenteditable="true"],[contenteditable=""],[contenteditable="plaintext-only"]'):null;
      if(ed) ed.__emxRange=r.cloneRange();
    }catch(e){}
  });
  function insertInto(field,chr){
    var hadFocus=(document.activeElement===field);
    if(field.isContentEditable){
      field.focus();
      if(!hadFocus && field.__emxRange && field.contains(field.__emxRange.startContainer)){
        var s0=window.getSelection(); s0.removeAllRanges(); s0.addRange(field.__emxRange);
      }
      /* editor con emojis Apple en vivo: metemos la imagen directamente en el cursor */
      if(field.hasAttribute('data-emoji-live') && (chr in MAP)){
        var selL=window.getSelection();
        var rgL=(selL&&selL.rangeCount)?selL.getRangeAt(0):null;
        if(!rgL){ rgL=document.createRange(); rgL.selectNodeContents(field); rgL.collapse(false); if(selL){selL.removeAllRanges();selL.addRange(rgL);} }
        rgL.deleteContents();
        var spL=spriteEl(chr,true); rgL.insertNode(spL);
        rgL.setStartAfter(spL); rgL.collapse(true);
        if(selL){ selL.removeAllRanges(); selL.addRange(rgL); }
        field.__emxRange=rgL.cloneRange();
        field.dispatchEvent(new Event('input',{bubbles:true}));
        return;
      }
      var ok=false; try{ ok=document.execCommand('insertText',false,chr); }catch(e){}
      if(!ok){ var sel=window.getSelection(); if(sel&&sel.rangeCount){ var rg=sel.getRangeAt(0); rg.deleteContents(); var tn=document.createTextNode(chr); rg.insertNode(tn); rg.setStartAfter(tn); rg.collapse(true); sel.removeAllRanges(); sel.addRange(rg); } else { field.appendChild(document.createTextNode(chr)); } }
      var sf=window.getSelection(); if(sf&&sf.rangeCount) field.__emxRange=sf.getRangeAt(0).cloneRange();
      field.dispatchEvent(new Event('input',{bubbles:true}));
    } else {
      var s,en;
      if(hadFocus){ s=field.selectionStart; en=field.selectionEnd; }
      else if(field.__emxSel){ s=field.__emxSel.s; en=field.__emxSel.e; }
      else { s=field.selectionStart; en=field.selectionEnd; }
      if(s==null){ s=en=field.value.length; }
      var v=field.value; field.value=v.slice(0,s)+chr+v.slice(en);
      var np=s+chr.length; field.focus(); try{ field.setSelectionRange(np,np); }catch(e){}
      field.__emxSel={s:np,e:np};
      field.dispatchEvent(new Event('input',{bubbles:true}));
    }
  }
  window.erpEmojiInsert=insertInto;

  function makeBtn(){
    var b=document.createElement('button'); b.type='button'; b.className='emx-btn'; b.tabIndex=-1;
    b.setAttribute('aria-label','Emojis'); b.title='Emojis ('+SC_LABEL+')';
    b.innerHTML='<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5c.9 1.2 2.1 1.8 3.5 1.8s2.6-.6 3.5-1.8"/><path d="M9 9.5h.01M15 9.5h.01"/></svg>';
    return b;
  }
  function positionBtn(btn,field){
    var pl=parseFloat(getComputedStyle(field).paddingRight)||0;
    btn.style.left=(field.offsetLeft+field.offsetWidth-30)+'px';
    btn.style.top=(field.offsetTop+field.offsetHeight-30)+'px';
  }
  function attachField(field){
    if(field.__emx||field.classList&&field.classList.contains('no-emoji')||field.hasAttribute('data-noemoji'))return;
    // no engancharse a campos de fecha, búsqueda o password
    if(field.tagName==='INPUT'){ var ty=(field.type||'text').toLowerCase();
      if(!field.matches('.emoji-field,[data-emoji]')) return;         // en <input> solo si se pide explícito
      if(ty==='date'||ty==='password'||ty==='number'||ty==='search') return;
    }
    field.__emx=1;
    var parent=field.parentElement; if(!parent)return;
    if(getComputedStyle(parent).position==='static')parent.style.position='relative';
    var btn=makeBtn(); parent.appendChild(btn);
    field.__emxBtn=btn;
    var reflow=function(){ positionBtn(btn,field); };
    /* El botón NO se ve suelto: aparece solo cuando estás en ese campo (foco o ratón
       encima) y se recoloca en ese momento. Así no flota "por la cara" en ningún sitio. */
    var hideT=null;
    function show(){ if(hideT){clearTimeout(hideT);hideT=null;} reflow(); btn.classList.add('show'); }
    function hideSoon(){ if(hideT)clearTimeout(hideT); hideT=setTimeout(function(){
        if(document.activeElement===field) return;
        if(pop&&pop.classList.contains('on')&&curInput===field) return;
        if(btn.__hover) return;
        btn.classList.remove('show');
      }, 250); }
    btn.addEventListener('mouseenter',function(){ btn.__hover=1; if(hideT){clearTimeout(hideT);hideT=null;} });
    btn.addEventListener('mouseleave',function(){ btn.__hover=0; hideSoon(); });
    btn.addEventListener('click',function(ev){ ev.preventDefault(); ev.stopPropagation();
      if(pop&&pop.classList.contains('on')){ close(); return; }
      openPicker(btn,null,field);
    });
    field.addEventListener('focus',show);
    field.addEventListener('blur',hideSoon);
    field.addEventListener('mouseenter',show);
    field.addEventListener('mouseleave',hideSoon);
    field.addEventListener('input',function(){ if(btn.classList.contains('show'))reflow(); });
    window.addEventListener('resize',function(){ if(btn.classList.contains('show'))reflow(); });
  }
  function apAutoScan(root){
    var r=root||document;
    r.querySelectorAll('textarea, [contenteditable="true"], [contenteditable="plaintext-only"], input.emoji-field, [data-emoji]').forEach(function(f){
      attachField(f);
    });
    /* Editores con emojis Apple en vivo (barra de escribir): pintan lo ya escrito y,
       a cada tecla, convierten el emoji recién puesto en imagen Apple. */
    r.querySelectorAll('[data-emoji-live]').forEach(function(f){
      if(f.__emxLive)return; f.__emxLive=1;
      try{ appleifyEditable(f); }catch(e){}
      f.addEventListener('input',function(){ try{ caretEmoji(f); }catch(e){} });
    });
    /* Botones de emoji que ya viven en una barra (chat, comentario, descripción):
       les ponemos en el tooltip el atajo real del sistema. */
    r.querySelectorAll('[data-emoji-btn]').forEach(function(b){ if(!b.title||/^Emoji/i.test(b.title))b.title='Emojis ('+SC_LABEL+')'; });
  }
  window.apAutoScan=apAutoScan;

  /* ============ arranque ============ */
  function init(){
    appleify(document.body);
    apAutoScan(document);
    startObserver();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);
  else init();
})();
