<?php
/* ============================================================
   Vista de UNA factura para el CLIENTE (portal).
   El cliente ve/imprime/guarda como PDF una factura SUYA.

   Seguridad: se exige sesión de cliente y la factura se carga
   SIEMPRE filtrando por su propio client_id y por estado != borrador,
   para que nadie pueda ver la factura de otro cambiando el id.
   Reutiliza el diseño de la hoja imprimible del ERP (admin/facturas.php),
   pero es una página propia del portal (la del ERP es solo para admins).
   ============================================================ */
require __DIR__ . '/auth.php';

/* Un admin puede previsualizar cualquier factura con ?cli=ID (solo lectura),
   igual que en el portal. El cliente normal solo ve las suyas. */
$previewId = isset($_GET['cli']) ? (int)$_GET['cli'] : 0;
if ($previewId && current_admin()) {
    /* Alcance: un miembro con rol limitado solo previsualiza facturas de SUS clientes. */
    if (function_exists('alcance_ve_cliente') && !alcance_ve_cliente($previewId)) { header('Location: admin/index.php'); exit; }
    $clientId = $previewId;
} else {
    require_client();
    $cl = current_client();
    $clientId = (int)$cl['id'];
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* Formato de euros propio (el portal no carga los helpers del ERP). */
function fac_eur($n){ return number_format((float)$n, 2, ',', '.') . ' €'; }

$ESTADOS = [
  'enviada' => ['Enviada', '#3b82f6'],
  'pagada'  => ['Pagada',  '#12a150'],
  'vencida' => ['Vencida', '#ef4444'],
];

$inv = null;
if ($id) {
    $st = db()->prepare("SELECT * FROM invoices WHERE id=? AND client_id=? AND estado IN ('enviada','pagada','vencida')");
    $st->execute([$id, $clientId]);
    $inv = $st->fetch();
}

/* Datos del emisor: del snapshot guardado en la propia factura (emisor_json),
   para que la factura sea autocontenida. Con respaldos vacíos si falta algo. */
$em = [];
if ($inv && !empty($inv['emisor_json'])) { $j = json_decode($inv['emisor_json'], true); if (is_array($j)) $em = $j; }
function em_v($em, $k){ return isset($em[$k]) ? (string)$em[$k] : ''; }

$items = []; $sub = 0; $iva = 0; $irpf = 0; $tot = 0;
if ($inv) {
    $stI = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id');
    $stI->execute([$id]); $items = $stI->fetchAll();
    foreach ($items as $it) $sub += $it['cantidad'] * $it['precio'];
    $iva  = $sub * $inv['iva_pct']  / 100;
    $irpf = $sub * $inv['irpf_pct'] / 100;
    $tot  = $sub + $iva - $irpf;
}
$autoPrint = isset($_GET['print']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $inv ? 'Factura '.e($inv['numero']) : 'Factura' ?></title>
<style>
  :root{
    --bg:#f5f5f7; --ink:#22262c; --ink-strong:#0f1113; --muted:#9aa0a8;
    --line:#eeeeef; --line2:#f6f6f7; --accent:#1f232a;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--ink);-webkit-font-smoothing:antialiased;padding:26px 16px 60px}
  a{text-decoration:none;color:inherit}
  .inv-sheet,.inv-sheet *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important}
  @page{margin:0}
  @media print{.no-print{display:none!important}body{background:#fff!important;padding:0!important}.inv-sheet{box-shadow:none!important;border:none!important;margin:0 auto!important;border-radius:0!important}.inv-pad{padding:16mm 15mm!important}}
  .inv-bar{max-width:680px;margin:0 auto 16px;display:flex;gap:10px;align-items:center}
  .inv-bar .sp{flex:1}
  .btn{border:none;border-radius:11px;padding:11px 18px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:.18s cubic-bezier(.16,1,.3,1)}
  .btn.solid{background:var(--accent);color:#fff}
  .btn.solid:hover{background:#000;transform:translateY(-2px)}
  .btn.ghost{background:#fff;color:var(--ink);border:1px solid var(--line)}
  .btn.ghost:hover{background:#fff;transform:translateY(-2px);box-shadow:0 6px 18px rgba(16,19,24,.1)}
  .btn svg{width:16px;height:16px;stroke:currentColor;fill:none}
  .inv-sheet{max-width:680px;margin:0 auto;background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 16px 50px rgba(0,0,0,.08);overflow:hidden}
  .inv-pad{padding:40px 42px}
  .inv-hd{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:26px}
  .inv-fact{font-size:36px;font-weight:850;letter-spacing:-1.3px;color:var(--ink-strong);line-height:.9}
  .inv-numpill{display:inline-block;margin-top:14px;border:1.5px solid var(--ink-strong);border-radius:99px;padding:5px 15px;font-size:12.5px;font-weight:700;color:var(--ink-strong)}
  .inv-chips{display:flex;flex-direction:column;gap:9px;align-items:flex-end}
  .inv-chip{border:1.5px solid var(--line);border-radius:99px;padding:6px 15px;font-size:12px;font-weight:600;color:var(--ink)}
  .inv-chip b{color:var(--ink-strong)}
  .inv-badge{font-size:11px;font-weight:700;padding:5px 13px;border-radius:99px;color:#fff}
  .inv-parts{display:grid;grid-template-columns:1fr 1fr;border:1.5px solid var(--ink-strong);border-radius:16px;overflow:hidden;margin-bottom:28px}
  .inv-block{padding:18px 22px}
  .inv-block+.inv-block{border-left:1.5px solid var(--ink-strong)}
  .inv-block .l{font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);font-weight:800;margin-bottom:8px}
  .inv-block .n{font-size:14px;font-weight:700;color:var(--ink-strong)}
  .inv-block .d{font-size:12px;color:#6b7280;line-height:1.65;margin-top:4px}
  .inv-tbl{width:100%;border-collapse:separate;border-spacing:0;margin-bottom:22px}
  .inv-tbl thead th{background:var(--ink-strong);color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:.4px;font-weight:700;padding:12px 16px;text-align:left}
  .inv-tbl thead th:first-child{border-radius:10px 0 0 10px}
  .inv-tbl thead th:last-child{border-radius:0 10px 10px 0}
  .inv-tbl thead th.r{text-align:right}
  .inv-tbl td{padding:13px 16px;border-bottom:1px solid var(--line2);font-size:13px;color:var(--ink)}
  .inv-tbl .r{text-align:right}
  .inv-tbl .cpt{font-weight:600;color:var(--ink-strong)}
  .inv-tot{display:flex;justify-content:flex-end;margin-top:6px}
  .inv-tot .box{width:330px}
  .inv-tot .row{display:flex;justify-content:space-between;font-size:13px;padding:8px 2px}
  .inv-tot .row.sub{color:#6b7280}
  .inv-tot .row.base{color:var(--ink-strong);font-weight:700;border-top:1.5px solid var(--line);border-bottom:1.5px solid var(--line);padding:10px 2px}
  .inv-tot .big{display:flex;justify-content:space-between;align-items:center;background:var(--ink-strong);color:#fff;border-radius:12px;padding:15px 20px;margin-top:12px}
  .inv-tot .big .t{font-weight:700;font-size:14px;letter-spacing:.3px}
  .inv-tot .big .a{font-size:23px;font-weight:850;letter-spacing:-.5px}
  .inv-pay{margin-top:26px;border:1.5px solid var(--line);border-radius:14px;padding:16px 20px}
  .inv-pay .h{font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);font-weight:800;margin-bottom:10px}
  .inv-pay .row{display:flex;justify-content:space-between;gap:14px;font-size:12.5px;padding:3px 0}
  .inv-pay .row span:first-child{color:#6b7280}
  .inv-pay .row span:last-child{color:var(--ink-strong);font-weight:600;text-align:right}
  .inv-notes{margin-top:20px;font-size:12px;color:var(--muted);line-height:1.6}
  .miss{max-width:520px;margin:60px auto;background:#fff;border:1px solid var(--line);border-radius:18px;padding:34px 30px;text-align:center;box-shadow:0 16px 50px rgba(0,0,0,.06)}
  .miss h1{font-size:19px;font-weight:750;margin-bottom:8px}
  .miss p{color:var(--muted);font-size:14px;line-height:1.6}
</style>
</head>
<body>
<?php if (!$inv): ?>
  <div class="inv-bar no-print"><a class="btn ghost" href="index.php"><svg viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg>Volver</a></div>
  <div class="miss"><h1>No encontramos esa factura</h1><p>Puede que ya no esté disponible o que no corresponda a tu cuenta. Vuelve a tus facturas e inténtalo de nuevo.</p></div>
<?php else:
  $ev = $ESTADOS[$inv['estado']] ?? ['—','#9aa0a8'];
  $forma = !empty($inv['efectivo']) ? 'Efectivo' : 'Transferencia';
?>
  <div class="inv-bar no-print">
    <a class="btn ghost" href="index.php"><svg viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg>Volver</a>
    <span class="sp"></span>
    <span class="inv-badge" style="background:<?= e($ev[1]) ?>"><?= e($ev[0]) ?></span>
    <button class="btn solid" onclick="window.print()"><svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14"/></svg>Descargar / Imprimir</button>
  </div>
  <div class="inv-sheet"><div class="inv-pad">
    <div class="inv-hd">
      <div>
        <div class="inv-fact">FACTURA</div>
        <div class="inv-numpill">Nº <?= e($inv['numero']) ?></div>
      </div>
      <div class="inv-chips">
        <div class="inv-chip">Fecha: <b><?= e(date('d/m/Y', strtotime($inv['fecha']))) ?></b></div>
        <?php if(!empty($inv['fecha_venc'])): ?><div class="inv-chip">Vencimiento: <b><?= e(date('d/m/Y', strtotime($inv['fecha_venc']))) ?></b></div><?php endif; ?>
      </div>
    </div>
    <div class="inv-parts">
      <div class="inv-block">
        <div class="l">Datos del cliente</div>
        <div class="n"><?= e($inv['cliente_nombre'] ?: '—') ?></div>
        <div class="d"><?php $cd=array_filter([$inv['cliente_nif'],($inv['cliente_tel']??''),$inv['cliente_dir'],$inv['cliente_email']]); echo nl2br(e(implode("\n",$cd))); ?></div>
      </div>
      <div class="inv-block">
        <div class="l">Emitida por</div>
        <div class="n"><?= e(em_v($em,'name') ?: 'Croilab') ?></div>
        <div class="d"><?php $ad=array_filter([em_v($em,'nif'),em_v($em,'email'),em_v($em,'phone'),em_v($em,'dir')]); echo nl2br(e(implode("\n",$ad))); ?></div>
      </div>
    </div>
    <table class="inv-tbl">
      <thead><tr><th>Detalle</th><th class="r" style="width:84px">Cantidad</th><th class="r" style="width:120px">Precio</th><th class="r" style="width:120px">Total</th></tr></thead>
      <tbody>
        <?php foreach($items as $it): ?><tr><td class="cpt"><?= e($it['concepto']) ?></td><td class="r"><?= e(rtrim(rtrim(number_format($it['cantidad'],2,',','.'),'0'),',')) ?></td><td class="r"><?= e(fac_eur($it['precio'])) ?></td><td class="r"><?= e(fac_eur($it['cantidad']*$it['precio'])) ?></td></tr><?php endforeach; ?>
        <?php if(!$items): ?><tr><td colspan="4" style="color:var(--muted);text-align:center;padding:20px">Sin líneas.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <div class="inv-tot"><div class="box">
      <div class="row base"><span>Base imponible</span><span><?= e(fac_eur($sub)) ?></span></div>
      <div class="row sub"><span>IVA (+<?= e(rtrim(rtrim(number_format($inv['iva_pct'],2,',','.'),'0'),',')) ?>%)</span><span><?= e(fac_eur($iva)) ?></span></div>
      <?php if($inv['irpf_pct'] > 0): ?><div class="row sub"><span>IRPF (−<?= e(rtrim(rtrim(number_format($inv['irpf_pct'],2,',','.'),'0'),',')) ?>%)</span><span>−<?= e(fac_eur($irpf)) ?></span></div><?php endif; ?>
      <div class="big"><span class="t">TOTAL</span><span class="a"><?= e(fac_eur($tot)) ?></span></div>
    </div></div>
    <div class="inv-pay">
      <div class="h">Información de pago</div>
      <?php if(em_v($em,'banco')): ?><div class="row"><span>Banco</span><span><?= e(em_v($em,'banco')) ?></span></div><?php endif; ?>
      <div class="row"><span>Titular</span><span><?= e(em_v($em,'name') ?: 'Croilab') ?></span></div>
      <div class="row"><span>Forma de pago</span><span><?= e($forma) ?></span></div>
      <?php if(!empty($inv['cond_pago'])): ?><div class="row"><span>Condiciones</span><span><?= e($inv['cond_pago']) ?></span></div><?php endif; ?>
      <?php if(empty($inv['efectivo']) && em_v($em,'iban')): ?><div class="row"><span>IBAN</span><span><?= e(em_v($em,'iban')) ?></span></div><?php endif; ?>
    </div>
    <?php if(!empty($inv['efectivo'])): ?><div class="inv-notes">Operación cobrada en efectivo.</div><?php endif; ?>
    <?php if(trim((string)$inv['notas'])!==''): ?><div class="inv-notes"><?= nl2br(e($inv['notas'])) ?></div><?php endif; ?>
  </div></div>
  <?php if($autoPrint): ?><script>window.addEventListener('load',function(){setTimeout(function(){window.print();},250);});</script><?php endif; ?>
<?php endif; ?>
</body>
</html>
