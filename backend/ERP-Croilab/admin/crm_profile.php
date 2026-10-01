<?php
/* Fragmento del perfil de cliente (1.3) — se carga por AJAX dentro del overlay.
   Espera: $c (contacto), $STAGES,$ORIGENES,$SERVICIOS,$respMap, funciones del lib. */
if(!isset($c)||!$c){ echo '<div style="padding:40px;text-align:center;color:var(--label)">Contacto no encontrado.</div>'; return; }
$TIPOS = crm_comment_tipos();
$stg=$STAGES[$c['fase']]??['nombre'=>$c['fase'],'color'=>'#98a2b3'];
$svc=json_decode((string)$c['servicio_json'],true); if(!is_array($svc))$svc=[];
$bill=db()->prepare('SELECT * FROM billing_data WHERE contact_id=?'); $bill->execute([$c['id']]); $bill=$bill->fetch() ?: [];
$props=db()->prepare('SELECT * FROM proposals WHERE contact_id=? ORDER BY fecha_envio DESC, id DESC'); $props->execute([$c['id']]); $props=$props->fetchAll();
$cms=db()->prepare('SELECT cm.*, a.username FROM comments cm LEFT JOIN admins a ON a.id=cm.autor_id WHERE cm.contact_id=? ORDER BY cm.fecha DESC, cm.id DESC'); $cms->execute([$c['id']]); $cms=$cms->fetchAll();
$acts=db()->prepare('SELECT * FROM activities WHERE contact_id=? ORDER BY fecha DESC, id DESC LIMIT 60'); $acts->execute([$c['id']]); $acts=$acts->fetchAll();
$atts=db()->prepare('SELECT * FROM attachments WHERE contact_id=? ORDER BY id DESC'); $atts->execute([$c['id']]); $atts=$atts->fetchAll();
$listas=db()->prepare('SELECT l.nombre FROM list_members lm JOIN lists l ON l.id=lm.list_id WHERE lm.contact_id=?'); $listas->execute([$c['id']]); $listas=$listas->fetchAll(PDO::FETCH_COLUMN);
$allTags=crm_all_tags(); $curTags=[]; $ctq=db()->prepare('SELECT tag_id FROM contact_tags WHERE contact_id=?'); $ctq->execute([$c['id']]); foreach($ctq->fetchAll(PDO::FETCH_COLUMN) as $tid) $curTags[(int)$tid]=true;
$PROPEST=['enviada'=>'Enviada','vista'=>'Vista','aceptada'=>'Aceptada','rechazada'=>'Rechazada'];
$mailto='https://mail.google.com/mail/?view=cm&to='.rawurlencode($c['email']??'');
function pf_v($v){ return $v!==null&&$v!==''?e($v):'<span class="pf-mut">—</span>'; }
?>
<div class="pf-head">
  <div class="pf-hl">
    <div class="pf-av" style="background:<?= avatar_color($c['nombre']) ?>"><?= e(mb_strtoupper(mb_substr($c['nombre'],0,2))) ?></div>
    <div>
      <input class="pf-name" aria-label="Nombre del contacto" value="<?= e($c['nombre']) ?>" onchange="cmSave(<?= (int)$c['id'] ?>,'nombre',this.value)">
      <div class="pf-sub"><?php $bits=array_filter([$c['sector'],$c['empresa'],implode(', ',$svc)]); echo e(implode(' · ',$bits)); ?>
        <span class="pf-badge" style="background:<?= e($stg['color']) ?>"><?= e($stg['nombre']) ?></span></div>
    </div>
  </div>
  <div class="pf-nav">
    <button type="button" class="pf-arw" onclick="cmNav(-1)" id="pfPrev" title="Anterior">‹</button>
    <span id="pfPos" class="pf-pos"></span>
    <button type="button" class="pf-arw" onclick="cmNav(1)" id="pfNext" title="Siguiente">›</button>
    <button type="button" class="pf-x" onclick="cmCloseProfile()">✕</button>
  </div>
</div>

<div class="pf-acts">
  <button type="button" onclick="document.getElementById('pfCmBody').focus()"><?= ic('pencil',15) ?> Crear nota</button>
  <a href="<?= e($mailto) ?>" target="_blank" onclick="cmAct(<?= (int)$c['id'] ?>,'email','Email abierto')"><?= ic('inbox',15) ?> Email</a>
  <a href="tel:<?= e($c['telefono']) ?>" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= e($c['telefono']) ?>');cmAct(<?= (int)$c['id'] ?>,'llamada','Llamada iniciada')"><?= ic('clock',15) ?> Llamar</a>
  <button type="button" onclick="erpAgendar({nombre:<?= e(json_encode($c['nombre'], JSON_UNESCAPED_UNICODE)) ?>,email:<?= e(json_encode($c['email']??'', JSON_UNESCAPED_UNICODE)) ?>,whatsapp:<?= e(json_encode(($c['whatsapp']??'')?:($c['telefono']??''), JSON_UNESCAPED_UNICODE)) ?>,contactId:<?= (int)$c['id'] ?>})"><?= ic('cal',15) ?> Agendar reunión</button>
  <?php /* Puente CRM -> Clientes. Si ya se convirtió, el botón deja de ofrecer
           crear otra ficha y pasa a ser el atajo para abrir la que ya existe. */
        $pfCli = (int)($c['client_id'] ?? 0);
        if ($pfCli):
          $pfCn = db()->prepare('SELECT name FROM clients WHERE id=?'); $pfCn->execute([$pfCli]); $pfCn = (string)($pfCn->fetchColumn() ?: '');
          if ($pfCn): ?>
  <a href="client.php?id=<?= $pfCli ?>" class="pf-isclient"><?= ic('clients',15) ?> Ver ficha de <?= e($pfCn) ?></a>
  <?php   else: $pfCli = 0; endif; endif;
        if (!$pfCli && can_edit()): ?>
  <button type="button" onclick="pfToCliente(<?= (int)$c['id'] ?>)"><?= ic('clients',15) ?> Convertir en cliente</button>
  <?php endif; ?>
</div>

<div class="pf-body">
  <div class="pf-col">
    <div class="pf-sec">Comentarios / Actualizaciones</div>
    <div class="pf-add">
      <div class="pf-types">
        <?php $i=0; foreach($TIPOS as $tk=>$tv): ?><button type="button" class="pf-tp <?= $i===0?'on':'' ?>" data-tp="<?= $tk ?>" onclick="pfPickTipo(this)"><?= e($tv[0]) ?></button><?php $i++; endforeach; ?>
      </div>
      <textarea id="pfCmBody" placeholder="Escribe una actualización… (@ para mencionar)"></textarea>
      <div class="pf-add-b"><button type="button" class="pf-pub" onclick="pfPublish(<?= (int)$c['id'] ?>)">Publicar</button></div>
    </div>
    <div class="pf-cms">
      <?php if(!$cms): ?><div class="pf-empty">Sin comentarios todavía.</div><?php else: foreach($cms as $cm): $un=$cm['username']?:'—'; $tp=$TIPOS[$cm['tipo']]??['Nota',false]; ?>
      <div class="pf-cm">
        <span class="pf-cav" style="background:<?= avatar_color($un) ?>"><?= e(mb_strtoupper(mb_substr($un,0,2))) ?></span>
        <div class="pf-cmb">
          <div class="pf-cmh"><b><?= e($un) ?></b><span class="pf-tag pf-tag-<?= e($cm['tipo']) ?>"><?= e($tp[0]) ?></span><span class="pf-date"><?= date('d/m/Y H:i',strtotime($cm['fecha'])) ?></span>
            <button type="button" class="pf-cmdel" onclick="pfDelCm(<?= (int)$cm['id'] ?>,<?= (int)$c['id'] ?>)" title="Eliminar">✕</button></div>
          <div class="pf-cmtxt"><?= nl2br(pf_mentions($cm['contenido'])) ?></div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div class="pf-col">
    <div class="pf-sec">Etiquetas</div>
    <div class="pf-tagbox">
      <?php if(!$allTags): ?><span class="pf-empty">Sin etiquetas. Créalas en Contactos › Etiquetas.</span>
      <?php else: foreach($allTags as $t): $on=isset($curTags[(int)$t['id']]); ?>
        <button type="button" class="pf-tagchip <?= $on?'on':'' ?>" data-t="<?= (int)$t['id'] ?>" style="<?= $on?'background:'.e($t['color']?:'#98a2b3').';border-color:'.e($t['color']?:'#98a2b3').';color:#fff':'' ?>" onclick="pfTag(<?= (int)$c['id'] ?>,<?= (int)$t['id'] ?>,this)"><?= e($t['nombre']) ?></button>
      <?php endforeach; endif; ?>
    </div>

    <div class="pf-sec">Datos de contacto</div>
    <div class="pf-grid">
      <div><label>Email</label><input type="email" aria-label="Email" value="<?= e($c['email']) ?>" onchange="cmSave(<?= (int)$c['id'] ?>,'email',this.value)"></div>
      <div><label>Teléfono</label><input aria-label="Teléfono" value="<?= e($c['telefono']) ?>" onchange="cmSave(<?= (int)$c['id'] ?>,'telefono',this.value)"></div>
      <div><label>WhatsApp</label><input aria-label="WhatsApp" value="<?= e($c['whatsapp']) ?>" onchange="cmSave(<?= (int)$c['id'] ?>,'whatsapp',this.value)"></div>
      <div><label>LinkedIn</label><input aria-label="LinkedIn" value="<?= e($c['linkedin']) ?>" onchange="cmSave(<?= (int)$c['id'] ?>,'linkedin',this.value)"></div>
      <div class="pf-2"><label>Web</label><input aria-label="Web" value="<?= e($c['web']) ?>" onchange="cmSave(<?= (int)$c['id'] ?>,'web',this.value)"></div>
    </div>

    <div class="pf-sec">Datos comerciales</div>
    <div class="pf-grid">
      <div><label>Sector</label><input list="cmSectors" aria-label="Sector" value="<?= e($c['sector']) ?>" onchange="cmSave(<?= (int)$c['id'] ?>,'sector',this.value)"></div>
      <div><label>Origen</label><input aria-label="Origen del lead" value="<?= e($c['origen_lead']) ?>" onchange="cmSave(<?= (int)$c['id'] ?>,'origen_lead',this.value)"></div>
      <div><label>Valor (€)</label><input aria-label="Valor en euros" value="<?= $c['valor']!==null?e(number_format((float)$c['valor'],0,',','.')):'' ?>" onchange="cmSave(<?= (int)$c['id'] ?>,'valor',this.value)"></div>
      <div><label>Propietario</label><select aria-label="Propietario" onchange="cmSave(<?= (int)$c['id'] ?>,'propietario_id',this.value)"><option value=""></option><?php foreach($respMap as $rid=>$rn): ?><option value="<?= (int)$rid ?>" <?= (int)$c['propietario_id']===(int)$rid?'selected':'' ?>><?= e($rn) ?></option><?php endforeach; ?></select></div>
    </div>

    <div class="pf-sec">Datos de facturación</div>
    <div class="pf-grid">
      <div class="pf-2"><label>Razón social</label><input aria-label="Razón social" value="<?= e($bill['razon_social']??'') ?>" onchange="pfBill(<?= (int)$c['id'] ?>,'razon_social',this.value)"></div>
      <div><label>CIF / NIF</label><input aria-label="CIF o NIF" value="<?= e($bill['cif']??'') ?>" onchange="pfBill(<?= (int)$c['id'] ?>,'cif',this.value)"></div>
      <div><label>IBAN</label><input aria-label="IBAN" value="<?= e($bill['iban']??'') ?>" onchange="pfBill(<?= (int)$c['id'] ?>,'iban',this.value)"></div>
      <div class="pf-2"><label>Dirección</label><input aria-label="Dirección" value="<?= e($bill['direccion']??'') ?>" onchange="pfBill(<?= (int)$c['id'] ?>,'direccion',this.value)"></div>
      <div><label>CP</label><input aria-label="Código postal" value="<?= e($bill['cp']??'') ?>" onchange="pfBill(<?= (int)$c['id'] ?>,'cp',this.value)"></div>
      <div><label>Ciudad</label><input aria-label="Ciudad" value="<?= e($bill['ciudad']??'') ?>" onchange="pfBill(<?= (int)$c['id'] ?>,'ciudad',this.value)"></div>
      <div><label>Provincia</label><input aria-label="Provincia" value="<?= e($bill['provincia']??'') ?>" onchange="pfBill(<?= (int)$c['id'] ?>,'provincia',this.value)"></div>
      <div><label>País</label><input aria-label="País" value="<?= e($bill['pais']??'') ?>" onchange="pfBill(<?= (int)$c['id'] ?>,'pais',this.value)"></div>
      <div class="pf-2"><label>Email facturación</label><input type="email" aria-label="Email de facturación" value="<?= e($bill['email_facturacion']??'') ?>" onchange="pfBill(<?= (int)$c['id'] ?>,'email_facturacion',this.value)"></div>
    </div>

    <div class="pf-sec">Propuestas enviadas <button type="button" class="pf-mini" onclick="pfAddProp(<?= (int)$c['id'] ?>)"><?= ic('plus',13) ?> Añadir</button></div>
    <div class="pf-props">
      <?php if(!$props): ?><div class="pf-empty">Sin propuestas.</div><?php else: foreach($props as $pr): ?>
        <div class="pf-prop"><div class="pf-prn"><b><?= e($pr['nombre']?:'Propuesta') ?></b><span><?= $pr['fecha_envio']?date('d/m/Y',strtotime($pr['fecha_envio'])):'' ?> · <?= e(number_format((float)$pr['importe'],0,',','.')) ?> €</span></div>
          <span class="pf-prest pf-prest-<?= e($pr['estado']) ?>"><?= e($PROPEST[$pr['estado']]??$pr['estado']) ?></span>
          <?php if($pr['url_archivo']): ?><a href="<?= e(safe_url($pr['url_archivo'])) ?>" target="_blank" rel="noopener noreferrer" class="pf-prlink"><?= ic('link',13) ?></a><?php endif; ?>
          <button type="button" class="pf-cmdel" onclick="pfDelProp(<?= (int)$pr['id'] ?>,<?= (int)$c['id'] ?>)">✕</button></div>
      <?php endforeach; endif; ?>
    </div>

    <div class="pf-sec">Archivos adjuntos <label class="pf-mini" style="cursor:pointer"><?= ic('plus',13) ?> Subir<input type="file" style="display:none" onchange="pfUpload(<?= (int)$c['id'] ?>,this)"></label></div>
    <div class="pf-atts">
      <?php if(!$atts): ?><div class="pf-empty">Sin archivos.</div><?php else: foreach($atts as $at): ?>
        <div class="pf-att"><?= ic('file',15) ?><a href="<?= e(url_adjunto($at['url'],'crm')) ?>" target="_blank" rel="noopener noreferrer" class="pf-attn"><?= e($at['nombre']) ?></a><span class="pf-attd"><?= $at['fecha_subida']?date('d/m/Y',strtotime($at['fecha_subida'])):'' ?></span><button type="button" class="pf-cmdel" onclick="pfDelAtt(<?= (int)$at['id'] ?>,<?= (int)$c['id'] ?>)">✕</button></div>
      <?php endforeach; endif; ?>
    </div>

    <?php if($listas): ?><div class="pf-sec">Listas</div><div class="pf-lists"><?php foreach($listas as $ln): ?><span class="pf-lchip"><?= e($ln) ?></span><?php endforeach; ?></div><?php endif; ?>

    <?php $meetings=crm_meetings($c['id']); $ESTM=['agendada'=>['Agendada','#6b7280'],'realizada'=>['Realizada','#12a150'],'no_show'=>['No se presentó','#c76a12'],'cancelada'=>['Cancelada','#c0343a']]; if($meetings): ?>
    <div class="pf-sec">Reuniones</div>
    <div style="display:flex;flex-direction:column;gap:9px;margin-bottom:18px">
      <?php foreach($meetings as $mt): $past=$mt['fecha']&&$mt['fecha']<date('Y-m-d'); $pend=$mt['estado']==='agendada'; $em=$ESTM[$mt['estado']]??$ESTM['agendada'];
        $docs=[]; if(!empty($mt['notas_doc'])){ $dd=json_decode((string)$mt['notas_doc'],true); if(is_array($dd)) $docs=$dd; } ?>
        <div style="display:flex;flex-direction:column;gap:9px;padding:12px 14px;border:1px <?= ($past&&$pend)?'dashed #e0a000':'solid var(--line)' ?>;border-radius:10px;<?= ($past&&$pend)?'background:#fffaf0':'background:var(--card)' ?>">
          <div style="display:flex;align-items:center;gap:10px">
            <span style="font-size:13px;font-weight:600;color:var(--ink-strong)"><?= $mt['fecha']?date('d/m/Y',strtotime($mt['fecha'])):'—' ?><?= $mt['hora']?' · '.e($mt['hora']):'' ?></span>
            <span style="font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:99px;color:<?= $em[1] ?>;background:<?= $em[1] ?>1e"><?= $em[0] ?></span>
            <span style="flex:1"></span>
            <?php if($past&&$pend): ?><button type="button" class="pf-mini" onclick="pfOutcome(<?= (int)$mt['id'] ?>)"><?= ic('check',13) ?> Registrar resultado</button><?php endif; ?>
            <?php if($past): ?><button type="button" class="pf-mini" onclick="pfMeetingNotes(<?= (int)$mt['id'] ?>,this)"><?= ic('download',13) ?> Notas de Gemini</button><?php endif; ?>
          </div>
          <?php if(trim((string)$mt['notas'])!==''): ?><div style="font-size:12px;color:var(--muted);line-height:1.5;white-space:pre-line"><?= e($mt['notas']) ?></div><?php endif; ?>
          <div class="mt-docs" data-mid="<?= (int)$mt['id'] ?>" style="display:flex;flex-wrap:wrap;gap:6px<?= $docs?'':';display:none' ?>">
            <?php foreach($docs as $doc): if(empty($doc['url']))continue; ?>
              <a href="<?= e($doc['url']) ?>" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#3c4149;background:#f2f2f3;border:1px solid var(--line);border-radius:8px;padding:5px 9px;text-decoration:none"><?= ic('file',13) ?> <?= e($doc['title']?:'Notas de la reunión') ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="pf-sec">Historial de actividad</div>
    <div class="pf-acthist">
      <?php if(!$acts): ?><div class="pf-empty">Sin actividad registrada.</div><?php else: foreach($acts as $ac): ?>
        <div class="pf-actrow"><span class="pf-actdot"></span><span class="pf-acttx"><?= e($ac['descripcion']?:$ac['tipo']) ?></span><span class="pf-actd"><?= date('d/m/Y H:i',strtotime($ac['fecha'])) ?></span></div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
