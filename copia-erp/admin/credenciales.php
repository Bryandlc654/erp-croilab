<?php
/* Bóveda de credenciales por cliente. Eliges un cliente y ves todas
   sus credenciales (web, correo, hosting, API…) en tarjetas. */
require_once __DIR__ . '/../auth.php';
require_admin();
ensure_schema();
require_once __DIR__ . '/erp_nav.php';

$CATS = [
  'web'=>['Web','link'], 'correo'=>['Correo','inbox'], 'hosting'=>['Hosting','vault'],
  'database'=>['Base de datos','settings'], 'api'=>['API / Token','settings'],
  'cms'=>['CMS','settings'], 'domain'=>['Dominio','link'], 'social'=>['Redes','link'], 'other'=>['Otro','vault'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && can_edit()) {
    $a = $_POST['action'] ?? '';
    $cli = (int)($_POST['cli'] ?? 0);
    if ($a === 'add_cred' && $cli) {
        $cat = array_key_exists($_POST['categoria'] ?? '', $CATS) ? $_POST['categoria'] : 'other';
        $vis = !empty($_POST['visible_cliente']) ? 1 : 0;
        $titulo=trim($_POST['titulo']??''); $usuario=trim($_POST['usuario']??''); $secreto=(string)($_POST['secreto']??''); $url=trim($_POST['url']??''); $nota=trim($_POST['nota']??'');
        if ($titulo !== '') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                db()->prepare('UPDATE client_credentials SET titulo=?, categoria=?, usuario=?, secreto=?, url=?, nota=?, visible_cliente=? WHERE client_id=? AND id=?')->execute([$titulo,$cat,$usuario,$secreto,$url,$nota,$vis,$cli,$id]);
            } else {
                db()->prepare('INSERT INTO client_credentials (titulo,categoria,usuario,secreto,url,nota,visible_cliente,client_id) VALUES (?,?,?,?,?,?,?,?)')->execute([$titulo,$cat,$usuario,$secreto,$url,$nota,$vis,$cli]);
            }
        }
        header('Location: credenciales.php?cli='.$cli); exit;
    } elseif ($a === 'del_cred' && $cli) {
        db()->prepare('DELETE FROM client_credentials WHERE id=? AND client_id=?')->execute([(int)($_POST['id']??0), $cli]);
        header('Location: credenciales.php?cli='.$cli); exit;
    }
}

$cli = isset($_GET['cli']) ? (int)$_GET['cli'] : 0;
function ini2($s){ return e(mb_strtoupper(mb_substr((string)$s,0,2))); }

if ($cli) {
    $st=db()->prepare('SELECT * FROM clients WHERE id=?'); $st->execute([$cli]); $client=$st->fetch();
    if(!$client){ header('Location: credenciales.php'); exit; }
    $cr=db()->prepare('SELECT * FROM client_credentials WHERE client_id=? ORDER BY orden,id'); $cr->execute([$cli]); $creds=$cr->fetchAll();
} else {
    $clients = db()->query("SELECT c.id,c.name,(SELECT COUNT(*) FROM client_credentials cc WHERE cc.client_id=c.id) n FROM clients c ORDER BY c.name")->fetchAll();
}

/* Si se entra desde el menú de Tareas conservamos ese contexto en toda la navegación interna. */
$ctxOps = (($_GET['ctx']??'')==='ops');
$ctxA = $ctxOps ? '&ctx=ops' : '';   // para URLs que ya llevan '?'
$ctxQ = $ctxOps ? '?ctx=ops' : '';   // para URLs sin parámetros
erp_head('vault', $cli ? 'Credenciales · '.$client['name'] : 'Bóveda de credenciales');
?>
<style>
.vault-hero{display:flex;align-items:flex-start;gap:14px;margin-bottom:22px}
.vault-hero .sp{flex:1}
.vault-hero h1{display:flex;align-items:center;gap:10px}.vault-hero h1 svg{width:20px;height:20px;color:var(--muted)}
.pick{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}
.pick a{display:flex;align-items:center;gap:14px;padding:18px 20px;border:1px solid var(--line);border-radius:14px;background:#fff;transition:.12s}
.pick a:hover{border-color:#dcdcde;box-shadow:0 8px 24px rgba(0,0,0,.06);transform:translateY(-2px)}
.pick .av{width:40px;height:40px;border-radius:12px;background:#18181b;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex:none}
.pick b{font-size:15px;font-weight:600;display:block}.pick span{font-size:12.5px;color:var(--muted)}
.filtbar{display:flex;align-items:center;gap:14px;margin:6px 0 20px;flex-wrap:wrap}
.srch{flex:1;min-width:200px;position:relative}
.srch input{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 13px 10px 36px;font-size:13.5px;background:#fff}
.srch svg{position:absolute;left:11px;top:11px;width:16px;height:16px;color:var(--label)}
/* Chips de filtro de la bóveda (solo aquí): pastilla con icono + contador. */
.vchips{display:flex;gap:7px;flex-wrap:wrap}
.vchip{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--line);background:#fff;border-radius:99px;padding:7px 13px;font-size:13px;font-weight:600;color:#52555c;cursor:pointer;font-family:inherit;line-height:1;transition:background .12s,border-color .12s,color .12s}
.vchip svg{width:14px;height:14px;color:var(--label);transition:color .12s}
.vchip:hover{border-color:#d5d7dc;background:var(--soft)}
.vchip.on{background:#18181b;color:#fff;border-color:#18181b}
.vchip.on svg{color:#fff}
.vchip .vc-n{font-size:11px;font-weight:700;background:rgba(0,0,0,.07);border-radius:99px;padding:2px 7px;min-width:18px;text-align:center}
.vchip.on .vc-n{background:rgba(255,255,255,.22)}
.cred-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}
.cred{border:1px solid var(--line);border-radius:16px;background:#fff;padding:22px;transition:border-color .16s ease,box-shadow .18s ease,transform .16s ease}
.cred:hover{border-color:#dcdcde;box-shadow:0 8px 26px rgba(0,0,0,.05);transform:translateY(-2px)}
.cred-h{display:flex;align-items:center;gap:12px;margin-bottom:16px}
.cred-h .ic{width:36px;height:36px;border-radius:10px;background:var(--soft);color:#22242a;display:flex;align-items:center;justify-content:center;flex:none}
.cred-h .ic svg{width:18px;height:18px}
.cred-h .tt{flex:1;min-width:0}.cred-h .tt b{font-size:15px;font-weight:600;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cred-h .cat{font-size:9.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700}
.cred-h .hact{display:flex;gap:4px}
.cred-h .hact button,.cred-h .hact a{border:none;background:none;color:var(--label);cursor:pointer;padding:5px;border-radius:7px;display:inline-flex}
.cred-h .hact button:hover,.cred-h .hact a:hover{background:#eef0f3;color:#6b7280}
.cred-h .hact svg{width:15px;height:15px}
.field{background:#f7f8fa;border-radius:11px;padding:11px 14px;margin-bottom:10px}
.field label{font-size:9.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;display:block;margin-bottom:3px}
.field .fv{display:flex;align-items:center;gap:8px}
.field .fv code{flex:1;min-width:0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.field .fv button{border:none;background:none;color:var(--label);cursor:pointer;padding:3px;border-radius:6px;display:inline-flex;flex:none}
.field .fv button:hover{background:#e9ebef;color:#6b7280}
.field .fv button svg{width:15px;height:15px}
.cred .note{font-size:12px;color:var(--muted);font-style:italic;background:#f7f8fa;border-radius:9px;padding:8px 11px;margin-bottom:9px}
.cred-link{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--accent);font-weight:600;margin-top:2px}
.cred-link svg{width:14px;height:14px}
.cred-empty{border:1px dashed #d9dce1;border-radius:16px;padding:46px;text-align:center;color:var(--muted)}
/* form modal */
.cv-ov{position:fixed;inset:0;background:rgba(15,18,25,.36);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);display:flex;align-items:flex-start;justify-content:center;z-index:100;padding:44px 18px;overflow:auto;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .18s ease,visibility .18s ease}
.cv-ov.on{opacity:1;visibility:visible;pointer-events:auto}
.cv{background:#fff;border-radius:16px;max-width:520px;width:100%;padding:26px 28px;box-shadow:0 30px 80px rgba(0,0,0,.25)}
.cv h3{font-size:17px;font-weight:650;margin-bottom:18px}
.cv label{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);font-weight:600;margin:12px 0 5px}
/* Icono de la etiqueta: gris tenue y del tamaño del texto, igual que en el resto
   de Ajustes. */
.cv label svg{width:14px;height:14px;color:var(--label);flex:none}
.cv input,.cv select{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 13px;font-size:14px;font-family:inherit}
.cv .r2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
/* Móvil (≤640px): en la ventana de registrar credencial, categoría y usuario se
   apilan para que cada campo tenga su ancho completo en el teléfono. */
@media(max-width:640px){
  .cv{padding:22px 20px}
  .cv .r2{grid-template-columns:1fr}
}
/* Modo oscuro: bóveda (selector de cliente, buscador, chips, tarjetas de
   credencial, campos y ventana). Los avatares/chips activos pasan al "negro"
   invertido del tema. */
[data-theme=dark] .pick a{background-color:var(--card)}
[data-theme=dark] .pick a:hover{border-color:var(--line-strong)}
[data-theme=dark] .pick .av{color:#fff}
[data-theme=dark] .srch input{background-color:var(--field)}
[data-theme=dark] .srch svg{color:var(--muted)}
[data-theme=dark] .vchip{background-color:var(--card);color:var(--ink)}
[data-theme=dark] .vchip svg{color:var(--muted)}
[data-theme=dark] .vchip:hover{border-color:var(--line-strong)}
[data-theme=dark] .vchip.on{background-color:var(--rev);color:var(--rev-fg);border-color:var(--rev)}
[data-theme=dark] .vchip.on svg{color:var(--rev-fg)}
[data-theme=dark] .vchip:not(.on) .vc-n{background-color:var(--soft)}
[data-theme=dark] .cred{background-color:var(--card)}
[data-theme=dark] .cred:hover{border-color:var(--line-strong)}
[data-theme=dark] .cred-h .hact button,[data-theme=dark] .cred-h .hact a{color:var(--muted)}
[data-theme=dark] .cred-h .hact button:hover,[data-theme=dark] .cred-h .hact a:hover{background-color:var(--soft);color:var(--ink)}
[data-theme=dark] .field{background-color:var(--soft)}
[data-theme=dark] .field .fv button{color:var(--muted)}
[data-theme=dark] .field .fv button:hover{background-color:var(--line);color:var(--ink)}
[data-theme=dark] .cred .note{background-color:var(--soft)}
[data-theme=dark] .cred-empty{border-color:var(--line)}
[data-theme=dark] .cv{background-color:var(--card)}
[data-theme=dark] .cv label svg{color:var(--muted)}
[data-theme=dark] .cv input,[data-theme=dark] .cv select{background-color:var(--field)}
</style>

<?php if (!$cli): ?>
  <div class="vault-hero">
    <div class="sp"><h1><?= ic('vault',20) ?> Bóveda de credenciales</h1><div class="lead">Elige un cliente para ver todos sus accesos (web, correo, hosting, API…).</div></div>
  </div>
  <div class="srch" style="margin-bottom:18px"><?= ic('search',16) ?><input id="cq" onkeyup="fp()" placeholder="Buscar cliente…"></div>
  <div class="pick" id="pick">
    <?php foreach ($clients as $c): ?>
      <a href="credenciales.php?cli=<?= (int)$c['id'] ?><?= $ctxA ?>" data-n="<?= e(mb_strtolower($c['name'])) ?>">
        <span class="av" style="background:<?= avatar_color($c['name']) ?>"><?= ini2($c['name']) ?></span>
        <span><b><?= e($c['name']) ?></b><span><?= (int)$c['n'] ?> credencial<?= (int)$c['n']==1?'':'es' ?></span></span>
      </a>
    <?php endforeach; ?>
  </div>
  <script>
  function fp(){var q=document.getElementById('cq').value.toLowerCase();document.querySelectorAll('#pick a').forEach(function(a){a.style.display=a.dataset.n.indexOf(q)>-1?'':'none';});}
  </script>

<?php else: ?>
  <div class="vault-hero">
    <div class="sp">
      <div class="tk-crumb"><a href="credenciales.php<?= $ctxQ ?>"><?= ic('back',14) ?></a> <a href="credenciales.php<?= $ctxQ ?>">Bóveda</a> <span class="sep">/</span> <?= e($client['name']) ?></div>
      <h1><?= ic('vault',20) ?> <?= e($client['name']) ?></h1>
      <div class="lead"><?= count($creds) ?> credencial(es) guardadas.</div>
    </div>
    <?php if (can_edit()): ?><button class="btn" onclick="openCred()"><?= ic('plus',16) ?> Registrar credencial</button><?php endif; ?>
  </div>

  <?php $catCounts=[]; foreach($creds as $cd){ $ck=($cd['categoria']?:'other'); $catCounts[$ck]=($catCounts[$ck]??0)+1; } ?>
  <?php if ($creds): ?>
  <div class="filtbar">
    <div class="srch"><?= ic('search',16) ?><input id="q" onkeyup="ff()" placeholder="Buscar credenciales…"></div>
    <div class="vchips" id="chips">
      <button type="button" class="vchip on" data-c="" onclick="setCat(this)">Todas <span class="vc-n"><?= count($creds) ?></span></button>
      <?php foreach ($CATS as $k=>$v): if(empty($catCounts[$k])) continue; ?><button type="button" class="vchip" data-c="<?= $k ?>" onclick="setCat(this)"><?= ic($v[1],14) ?> <?= e($v[0]) ?> <span class="vc-n"><?= (int)$catCounts[$k] ?></span></button><?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$creds): ?>
    <div class="cred-empty">Sin credenciales todavía.<?php if(can_edit()): ?><br><br><button class="btn" onclick="openCred()"><?= ic('plus',16) ?> Registrar la primera</button><?php endif; ?></div>
  <?php else: ?>
  <div class="cred-grid" id="grid">
    <?php foreach ($creds as $cd): $cat=$CATS[$cd['categoria']]??$CATS['other']; ?>
      <div class="cred" data-cat="<?= e($cd['categoria']) ?>" data-s="<?= e(mb_strtolower($cd['titulo'].' '.$cd['usuario'].' '.$cd['url'])) ?>">
        <div class="cred-h">
          <span class="ic"><?= ic($cat[1],18) ?></span>
          <div class="tt"><b><?= e($cd['titulo']) ?></b><span class="cat"><?= e($cat[0]) ?></span><?php if(!empty($cd['visible_cliente'])): ?><span class="cat" style="background:#e4f6ec;color:#12854a" title="El cliente ve este acceso en su portal">👁 Visible</span><?php endif; ?></div>
          <?php if (can_edit()): ?><div class="hact">
            <button title="Editar" onclick='openCred(<?= json_encode($cd, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG) ?>)'><?= ic('settings',15) ?></button>
            <form method="post" style="margin:0" onsubmit="return erpSubmitAsk(this,'¿Borrar credencial?')"><input type="hidden" name="action" value="del_cred"><input type="hidden" name="cli" value="<?= $cli ?>"><input type="hidden" name="id" value="<?= (int)$cd['id'] ?>"><button title="Borrar"><?= ic('trash',15) ?></button></form>
          </div><?php endif; ?>
        </div>
        <?php if ($cd['usuario']!==''): ?>
        <div class="field"><label>Usuario</label><div class="fv"><code><?= e($cd['usuario']) ?></code><button type="button" title="Copiar" onclick="cp(this,<?= htmlspecialchars(json_encode($cd['usuario']), ENT_QUOTES) ?>)"><?= ic('link',15) ?></button></div></div>
        <?php endif; ?>
        <?php if ((string)$cd['secreto']!==''): ?>
        <div class="field"><label>Contraseña</label><div class="fv"><code class="pw" data-v="<?= e($cd['secreto']) ?>">••••••••••••</code><button type="button" title="Ver" onclick="tg(this)"><?= ic('eye',15) ?></button><button type="button" title="Copiar" onclick="cp(this,<?= htmlspecialchars(json_encode($cd['secreto']), ENT_QUOTES) ?>)"><?= ic('link',15) ?></button></div></div>
        <?php endif; ?>
        <?php if ($cd['nota']!==''): ?><div class="note"><?= e($cd['nota']) ?></div><?php endif; ?>
        <?php if ($cd['url']!==''): ?><a class="cred-link" href="<?= e($cd['url']) ?>" target="_blank" rel="noopener"><?= ic('link',14) ?> Acceder al servicio</a><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (can_edit()): ?>
  <div class="cv-ov" id="cvOv"><div class="cv">
    <h3 id="cvTitle">Registrar credencial</h3>
    <form method="post">
      <input type="hidden" name="action" value="add_cred"><input type="hidden" name="cli" value="<?= $cli ?>"><input type="hidden" name="id" id="cv_id">
      <label><?= ic('file',15) ?> Título *</label><input name="titulo" id="cv_titulo" placeholder="Ej: WordPress de la web" required>
      <div class="r2">
        <div><label><?= ic('folder',15) ?> Categoría</label><select name="categoria" id="cv_cat"><?php foreach($CATS as $k=>$v): ?><option value="<?= $k ?>"><?= e($v[0]) ?></option><?php endforeach; ?></select></div>
        <div><label><?= ic('user',15) ?> Usuario / email</label><input name="usuario" id="cv_usuario"></div>
      </div>
      <label><?= ic('vault',15) ?> Contraseña / token</label><input name="secreto" id="cv_secreto">
      <label><?= ic('link',15) ?> URL de acceso</label><input name="url" id="cv_url" placeholder="https://…">
      <label><?= ic('pencil',15) ?> Nota</label><input name="nota" id="cv_nota" placeholder="Opcional">
      <label class="cv-vis" style="display:flex;align-items:center;gap:9px;margin-top:14px;padding:11px 13px;border:1px solid var(--line);border-radius:11px;background:var(--soft);cursor:pointer;font-size:13.5px;color:var(--ink)"><input type="checkbox" name="visible_cliente" id="cv_vis" value="1" style="width:auto;margin:0"> <span><b>Visible para el cliente</b> en su portal (Accesos). Deja sin marcar los accesos internos (FTP, base de datos, tokens…).</span></label>
      <div class="flex" style="margin-top:18px"><button class="btn" type="submit">Guardar</button><button class="btn ghost" type="button" onclick="document.getElementById('cvOv').classList.remove('on')">Cancelar</button></div>
    </form>
  </div></div>
  <?php endif; ?>

  <script>
  function tg(b){var c=b.parentNode.querySelector('.pw');if(c.dataset.shown){c.textContent='••••••••••••';c.dataset.shown='';}else{c.textContent=c.dataset.v;c.dataset.shown='1';}}
  function cp(b,v){navigator.clipboard&&navigator.clipboard.writeText(v);var o=b.innerHTML;b.innerHTML='<svg width=15 height=15 viewBox="0 0 24 24" fill=none stroke="#0f1012" stroke-width=2.5 stroke-linecap=round stroke-linejoin=round><path d="M20 6 9 17l-5-5"/></svg>';setTimeout(function(){b.innerHTML=o;},1100);}
  function ff(){var q=document.getElementById('q').value.toLowerCase();var cat=window._cat||'';document.querySelectorAll('#grid .cred').forEach(function(el){var ok=(!q||el.dataset.s.indexOf(q)>-1)&&(!cat||el.dataset.cat===cat);el.style.display=ok?'':'none';});}
  function setCat(el){window._cat=el.dataset.c;document.querySelectorAll('#chips .vchip').forEach(function(c){c.classList.remove('on');});el.classList.add('on');ff();}
  function openCred(d){d=d||{};document.getElementById('cvTitle').textContent=d.id?'Editar credencial':'Registrar credencial';document.getElementById('cv_id').value=d.id||'';document.getElementById('cv_titulo').value=d.titulo||'';document.getElementById('cv_cat').value=d.categoria||'other';document.getElementById('cv_usuario').value=d.usuario||'';document.getElementById('cv_secreto').value=d.secreto||'';document.getElementById('cv_url').value=d.url||'';document.getElementById('cv_nota').value=d.nota||'';document.getElementById('cv_vis').checked=!!(+(d.visible_cliente||0));document.getElementById('cvOv').classList.add('on');}
  var _ov=document.getElementById('cvOv');if(_ov)_ov.addEventListener('click',function(e){if(e.target===this)this.classList.remove('on');});
  </script>
<?php endif; ?>
<?php erp_foot(); ?>
