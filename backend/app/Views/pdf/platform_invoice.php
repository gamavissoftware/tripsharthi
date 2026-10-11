<?php
/** TripSarthi tax invoice (SaaS sale by the software owner). dompdf: tables for layout, no flex/grid. Data comes from PlatformInvoiceService::renderPdf(). */
$e = [\App\Services\Billing\Docs\DocFormat::class, 'e']; $n = [\App\Services\Billing\Docs\DocFormat::class, 'num']; $inr = [\App\Services\Billing\Docs\DocFormat::class, 'inr']; $nl = [\App\Services\Billing\Docs\DocFormat::class, 'nl'];
$brand = ['color' => '#0a6cc4'];
$intra = $doc['supply_type'] === 'intra';
$rate = rtrim(rtrim(number_format((float) $doc['gst_rate'], 2), '0'), '.'); $half = rtrim(rtrim(number_format((float) $doc['gst_rate'] / 2, 2), '0'), '.');
$tax = $doc['cgst'] + $doc['sgst'] + $doc['igst'];
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><?= view('pdf/_style', ['brand' => $brand]) ?>
<style> .meta td { padding: 2px 0; } .items td, .items th { border: 1px solid #d1d5db; padding: 6px 7px; } .items th { background: #f3f4f6; font-size: 7pt; text-transform: uppercase; letter-spacing: .4px; text-align: left; } .sum td { padding: 3px 8px; } .band { background: #0a1f44; color: #fff; padding: 9px 12px; } </style>
</head><body>

<table class="band"><tr>
  <td style="width:60%">
    <table><tr>
      <?php if (! empty($mark)): ?><td style="width:34px; vertical-align:middle"><img src="<?= $mark ?>" style="height:30px"></td><?php endif; ?>
      <td style="vertical-align:middle"><span style="font-size:16pt; font-weight:bold; color:#fff">Trip</span><span style="font-size:16pt; font-weight:bold; color:#2fd0c2">Sarthi</span><div style="font-size:7pt; color:#9fb3d1">Your travel business, simplified</div></td>
    </tr></table>
  </td>
  <td class="right" style="vertical-align:middle"><div style="font-size:17pt; font-weight:bold; color:#fff; letter-spacing:1px">TAX INVOICE</div><div style="font-size:6.8pt; color:#9fb3d1">Original for recipient</div></td>
</tr></table>

<table style="margin-top:12px"><tr>
  <td style="width:52%; padding-right:12px">
    <div class="eyebrow">Supplier</div>
    <div class="bold" style="font-size:11pt"><?= $e($seller['legal_name']) ?></div>
    <div class="small"><?= $e(implode(', ', $seller['address'])) ?></div>
    <div class="small muted"><?= $e(implode(' · ', array_filter([$seller['phone'] ?? '', $seller['email'] ?? '', $seller['website'] ?? '']))) ?></div>
    <div class="small" style="margin-top:3px"><b>GSTIN:</b> <?= $e($seller['gstin']) ?> &nbsp; <?php if (! empty($seller['pan'])): ?><b>PAN:</b> <?= $e($seller['pan']) ?> &nbsp;<?php endif; ?> <b>State:</b> <?= $e($seller['state_name']) ?> (<?= $e($seller['state_code']) ?>)</div>
  </td>
  <td>
    <table class="meta">
      <tr><td class="small muted" style="width:44%">Invoice no.</td><td class="bold"><?= $e($doc['number']) ?></td></tr>
      <tr><td class="small muted">Invoice date</td><td class="bold"><?= $e($doc['date']) ?></td></tr>
      <tr><td class="small muted">Place of supply</td><td class="bold"><?= $e($doc['place_name']) ?> (<?= $e($doc['place_of_supply']) ?>)</td></tr>
      <tr><td class="small muted">Reverse charge</td><td>No</td></tr>
      <tr><td class="small muted">Payment</td><td><?= $doc['source'] === 'offline' ? 'Received offline (bank / UPI)' : 'Paid online (Razorpay)' ?><?= ! empty($doc['payment_ref']) ? '<div class="tiny muted">Ref: ' . $e($doc['payment_ref']) . '</div>' : '' ?></td></tr>
    </table>
  </td>
</tr></table>
<div class="rule"></div>

<div class="eyebrow">Bill to</div>
<div class="bold" style="font-size:10pt"><?= $e($buyer['name']) ?></div>
<?php if (! empty($buyer['address'])): ?><div class="small"><?= $e(implode(', ', $buyer['address'])) ?></div><?php endif; ?>
<div class="small muted"><?= $e(implode(' · ', array_filter([$buyer['email'] ?? '', $buyer['phone'] ?? '']))) ?></div>
<div class="small" style="margin-top:2px"><b>GSTIN:</b> <?= ! empty($buyer['gstin']) ? $e($buyer['gstin']) : 'Unregistered (consumer)' ?><?php if (! empty($buyer['state_name'])): ?> &nbsp; <b>State:</b> <?= $e($buyer['state_name']) ?> (<?= $e($buyer['state_code']) ?>)<?php endif; ?></div>

<table class="items" style="margin-top:14px">
  <tr><th style="width:4%">#</th><th>Description of service</th><th style="width:9%">SAC</th><th class="right" style="width:15%">Taxable value</th>
    <?php if ($intra): ?><th class="right" style="width:12%">CGST<br><span style="font-weight:normal"><?= $e($half) ?>%</span></th><th class="right" style="width:12%">SGST<br><span style="font-weight:normal"><?= $e($half) ?>%</span></th>
    <?php else: ?><th class="right" style="width:14%">IGST<br><span style="font-weight:normal"><?= $e($rate) ?>%</span></th><?php endif; ?></tr>
  <tr><td>1</td><td><b><?= $e($doc['plan']) ?> plan - software subscription</b><div class="small muted"><?= $e($doc['description']) ?></div></td><td><?= $e($doc['sac']) ?></td>
    <td class="right"><?= $e($n($doc['taxable_value'])) ?></td>
    <?php if ($intra): ?><td class="right"><?= $e($n($doc['cgst'])) ?></td><td class="right"><?= $e($n($doc['sgst'])) ?></td><?php else: ?><td class="right"><?= $e($n($doc['igst'])) ?></td><?php endif; ?></tr>
</table>

<table style="margin-top:6px; page-break-inside: avoid"><tr>
  <td style="width:55%; padding-right:14px">
    <div class="eyebrow">Amount in words</div>
    <div class="bold" style="margin:2px 0 8px"><?= $e($doc['words']) ?></div>
    <div class="tiny muted" style="border-left:2px solid #0a6cc4; padding-left:7px">The amount charged is inclusive of GST at <?= $e($rate) ?>%. Tax is shown above the taxable value for your input-tax-credit records.</div>
  </td>
  <td>
    <table class="sum">
      <tr><td>Taxable value</td><td class="right"><?= $e($inr($doc['taxable_value'])) ?></td></tr>
      <?php if ($intra): ?><tr><td>CGST @ <?= $e($half) ?>%</td><td class="right"><?= $e($inr($doc['cgst'])) ?></td></tr><tr><td>SGST @ <?= $e($half) ?>%</td><td class="right"><?= $e($inr($doc['sgst'])) ?></td></tr>
      <?php else: ?><tr><td>IGST @ <?= $e($rate) ?>%</td><td class="right"><?= $e($inr($doc['igst'])) ?></td></tr><?php endif; ?>
      <tr class="total"><td style="font-size:10pt">Invoice total</td><td class="right" style="font-size:10.5pt"><?= $e($inr($doc['total'])) ?></td></tr>
    </table>
    <table class="sum" style="margin-top:4px"><tr><td class="small muted">Received</td><td class="right small"><?= $e($inr($doc['total'])) ?></td></tr><tr><td class="small bold">Balance due</td><td class="right small bold"><?= $e($inr(0)) ?></td></tr></table>
  </td>
</tr></table>

<?php if (! empty($bank)): ?>
<table class="card" style="margin-top:10px; page-break-inside: avoid"><tr><td>
  <div class="eyebrow">Supplier bank details</div>
  <table class="meta" style="margin-top:3px"><?php foreach ($bank as [$k, $v]): ?><tr><td class="small muted" style="width:30%"><?= $e($k) ?></td><td class="small bold"><?= $e($v) ?></td></tr><?php endforeach; ?></table>
</td></tr></table>
<?php endif; ?>

<?php if (! empty($seller['terms'])): ?><div style="margin-top:10px"><div class="eyebrow">Terms</div><div class="tiny muted"><?= $nl($seller['terms']) ?></div></div><?php endif; ?>

<table style="margin-top:20px; page-break-inside: avoid"><tr>
  <td class="tiny muted" style="width:60%">This is a computer-generated tax invoice and does not require a physical signature.</td>
  <td class="right"><div class="small muted">For <b style="color:#111827"><?= $e($seller['legal_name']) ?></b></div><div style="height:28px"></div><div class="small bold"><?= $e($seller['signatory'] ?: 'Authorised signatory') ?></div><div class="tiny muted">Authorised signatory</div></td>
</tr></table>
</body></html>
