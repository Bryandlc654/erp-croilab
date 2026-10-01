<?php
/* Asistente IA — maqueta visual (no operativa todavía). */
require_once __DIR__ . '/../auth.php';
require_admin();
require_once __DIR__ . '/erp_nav.php';
$me = current_admin();
erp_head('ia', 'Asistente IA');
?>
<style>
/* Blanco, negro y gris, como el resto del ERP. Esta pantalla se había maquetado
   con un degradado violeta y azul propio suyo: era la única del proyecto con
   colores de marca distintos, y al entrar parecía otra aplicación. */
.ia-wrap{max-width:880px;margin:0 auto}
.ia-hero{display:flex;align-items:center;gap:16px;margin-bottom:8px}
.ia-orb{width:52px;height:52px;border-radius:16px;background:var(--accent);display:flex;align-items:center;justify-content:center;color:#fff;box-shadow:0 10px 26px rgba(31,35,42,.22)}
.ia-hero h1{margin:0}
.ia-badge{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;background:var(--soft);color:var(--muted);border:1px solid var(--line);padding:3px 9px;border-radius:99px;margin-left:8px;vertical-align:middle}
.ia-sub{color:var(--muted);margin:2px 0 22px}
.ia-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:26px}
.ia-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px 20px;cursor:pointer}
.ia-card:hover{border-color:var(--accent);box-shadow:0 8px 24px rgba(31,35,42,.07);transform:translateY(-2px)}
.ia-card .ic{width:34px;height:34px;border-radius:10px;background:var(--soft);color:var(--ink-strong);display:flex;align-items:center;justify-content:center;margin-bottom:12px}
.ia-card b{font-size:14.5px;font-weight:600;color:var(--ink-strong);display:block;margin-bottom:5px}
.ia-card span{font-size:12.5px;color:var(--muted);line-height:1.55}
.ia-chat{background:#fff;border:1px solid var(--line);border-radius:18px;overflow:hidden}
.ia-feed{padding:22px;display:flex;flex-direction:column;gap:16px;min-height:220px;max-height:420px;overflow:auto}
.ia-m{display:flex;gap:11px;max-width:82%}
.ia-m .av{width:30px;height:30px;border-radius:9px;flex:none;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;color:#fff}
.ia-m.bot .av{background:var(--accent)}
.ia-m .bub{background:var(--soft);border-radius:13px;border-top-left-radius:4px;padding:11px 15px;font-size:13.5px;line-height:1.6;color:var(--ink)}
.ia-m.me{align-self:flex-end;flex-direction:row-reverse}
.ia-m.me .av{background:<?= avatar_color($me['username']) ?>}
.ia-m.me .bub{background:var(--accent);color:#fff;border-radius:13px;border-top-right-radius:4px}
.ia-compose{border-top:1px solid var(--line);padding:14px 18px;display:flex;gap:10px;align-items:center;background:#fcfcfd}
.ia-compose input{flex:1;border:1px solid var(--line);border-radius:12px;padding:12px 15px;font-size:13.5px;font-family:inherit;outline:none;background:#fff}
.ia-compose input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.ia-compose button{border:none;background:var(--accent);color:#fff;width:44px;height:44px;border-radius:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex:none}
.ia-note{text-align:center;font-size:12px;color:var(--muted);margin-top:14px}
.ia-typing span{display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--muted);margin:0 1px;animation:iablink 1s infinite}
.ia-typing span:nth-child(2){animation-delay:.2s}.ia-typing span:nth-child(3){animation-delay:.4s}
@keyframes iablink{0%,60%,100%{opacity:.3;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}
/* Móvil (≤640px): las tres tarjetas de sugerencia se apilan y las burbujas del
   chat pueden ocupar casi todo el ancho, que a 375px se quedaban muy estrechas. */
@media(max-width:640px){
  .ia-cards{grid-template-columns:1fr;gap:12px}
  .ia-m{max-width:92%}
  .ia-feed{padding:16px}
}
/* Modo oscuro: tarjetas, panel de chat y el campo de escritura. El orbe y las
   burbujas del bot/usuario usan acento/soft y se dejan como están. */
[data-theme=dark] .ia-card{background-color:var(--card)}
[data-theme=dark] .ia-chat{background-color:var(--card)}
[data-theme=dark] .ia-compose{background-color:var(--card)}
[data-theme=dark] .ia-compose input{background-color:var(--field)}
</style>

<div class="ia-wrap">
  <div class="ia-hero">
    <div class="ia-orb"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.6 4.8L18 9.5l-4.4 1.7L12 16l-1.6-4.8L6 9.5l4.4-1.7z"/><path d="M19 14l.7 2.1L22 17l-2.3.9L19 20l-.7-2.1L16 17l2.3-.9z"/></svg></div>
    <div><h1 style="display:inline">Asistente IA <span class="ia-badge">Próximamente</span></h1><div class="ia-sub">Tu copiloto para redactar informes, resumir clientes y crear tareas — así se verá.</div></div>
  </div>

  <div class="ia-cards">
    <div class="ia-card" onclick="iaAsk('Redáctame el informe mensual de SEO para un cliente')"><div class="ic"><?= ic('inbox',18) ?></div><b>Redactar informes</b><span>Genera el correo de seguimiento mensual a partir de los datos.</span></div>
    <div class="ia-card" onclick="iaAsk('Resume el estado de todas mis tareas de esta semana')"><div class="ic"><?= ic('check',18) ?></div><b>Resumir tareas</b><span>Un resumen claro de lo pendiente, en curso y completado.</span></div>
    <div class="ia-card" onclick="iaAsk('Sugiere una estrategia de contenidos para un cliente de dentistas')"><div class="ic"><?= ic('trend',18) ?></div><b>Ideas y estrategia</b><span>Propuestas de contenido, keywords y acciones SEO.</span></div>
  </div>

  <div class="ia-chat">
    <div class="ia-feed" id="iaFeed">
      <div class="ia-m bot"><span class="av"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.6 4.8L18 9.5l-4.4 1.7L12 16l-1.6-4.8L6 9.5l4.4-1.7z"/></svg></span><div class="bub">¡Hola <?= e($me['username']) ?>! Soy el asistente de <?= e(marca_agencia()['name']) ?>. Cuando esté activo podré redactar informes, resumir clientes, crear tareas y responder sobre tus datos. Prueba una sugerencia de las de arriba.</div></div>
    </div>
    <div class="ia-compose">
      <input type="text" id="iaInput" placeholder="Escribe algo para ver la demo…" onkeydown="if(event.key==='Enter')iaSend()">
      <button onclick="iaSend()" aria-label="Enviar mensaje"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg></button>
    </div>
  </div>
  <div class="ia-note">Esta es una vista previa. La IA todavía no está conectada — pronto podrás usarla de verdad.</div>
</div>

<script>
var IA_ME=<?= json_encode(mb_strtoupper(mb_substr($me['username'],0,2))) ?>;
function esc(s){return(''+s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
function iaBottom(){var f=document.getElementById('iaFeed');f.scrollTop=f.scrollHeight;}
function iaAdd(role,html){var f=document.getElementById('iaFeed');var d=document.createElement('div');d.className='ia-m '+(role==='me'?'me':'bot');
  var av=(role==='me')?esc(IA_ME):'<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.6 4.8L18 9.5l-4.4 1.7L12 16l-1.6-4.8L6 9.5l4.4-1.7z"/></svg>';
  d.innerHTML='<span class="av">'+av+'</span><div class="bub">'+html+'</div>';f.appendChild(d);iaBottom();return d;}
function iaAsk(q){document.getElementById('iaInput').value=q;iaSend();}
function iaSend(){var i=document.getElementById('iaInput');var q=i.value.trim();if(!q)return;i.value='';iaAdd('me',esc(q));
  var t=iaAdd('bot','<span class="ia-typing"><span></span><span></span><span></span></span>');
  setTimeout(function(){t.querySelector('.bub').innerHTML='Buena pregunta. Cuando la IA esté conectada te respondería esto usando tus datos reales de clientes, tareas e informes. De momento es solo una demo visual del aspecto que tendrá el asistente.';iaBottom();},900);}
</script>
<?php erp_foot(); ?>
