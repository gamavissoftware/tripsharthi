<?php
$e = [\App\Services\Billing\Docs\DocFormat::class, 'e']; $n = [\App\Services\Billing\Docs\DocFormat::class, 'num']; $inr = [\App\Services\Billing\Docs\DocFormat::class, 'inr']; $nl = [\App\Services\Billing\Docs\DocFormat::class, 'nl'];
$isCn = $doc['doc_type'] === 'credit_note'; $isBos = $doc['doc_type'] === 'bill_of_supply'; $intra = $doc['supply_type'] === 'intra'; $hasTax = ! $isBos;
$rate = rtrim(rtrim(number_format((float) $doc['gst_rate'], 2), '0'), '.'); $half = rtrim(rtrim(number_format((float) $doc['gst_rate'] / 2, 2), '0'), '.');
$label = ['tax_invoice' => 'TAX INVOICE', 'bill_of_supply' => 'BILL OF SUPPLY', 'credit_note' => 'CREDIT NOTE'][$doc['doc_type']];
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><?= view('pdf/_style', ['brand' => $brand]) ?>
<style> .meta td { padding: 2px 0; } .bt td, .bt th { border: 1px solid #d1d5db; } .items td, .items th { border: 1px solid #d1d5db; padding: 6px 7px; } .items th { background: #f3f4f6; font-size: 7pt; text-transform: uppercase; letter-spacing: .4px; text-align: left; } .sum td { padding: 3px 8px; } </style>
</head><body>

<table><tr>
  <td style="width:58%">
    <?php if (! empty($brand['logo'])): ?><img src="<?= $brand['logo'] ?>" style="height:40px; margin-bottom:4px"><?php endif; ?>
    <div class="bold" style="font-size:12.5pt"><?= $e($seller['legal_name']) ?></div>
    <?php if (! empty($seller['trade_name']) && $seller['trade_name'] !== $seller['legal_name']): ?><div class="small muted">(<?= $e($seller['trade_name']) ?>)</div><?php endif; ?>
    <div class="small"><?= $e(implode(', ', $seller['address'])) ?></div>
    <div class="small muted"><?= $e(implode(' · ', array_filter([$seller['phone'] ?? '', $seller['email'] ?? '']))) ?></div>
    <div class="small" style="margin-top:3px">
      <?php if (! empty($seller['gstin'])): ?><b>GSTIN:</b> <?= $e($seller['gstin']) ?> &nbsp; <?php endif; ?>
      <?php if (! empty($seller['pan'])): ?><b>PAN:</b> <?= $e($seller['pan']) ?> &nbsp; <?php endif; ?>
      <b>State:</b> <?= $e($seller['state_name']) ?> (<?= $e($seller['state_code']) ?>)</div>
  </td>
  <td class="right">
    <div style="font-size:19pt; font-weight:bold; color:<?= $brand['color'] ?>; letter-spacing:1px"><?= $label ?></div>
    <div class="tiny muted" style="margin-bottom:6px"><?= $isCn ? 'Original for recipient' : 'Original for recipient · Duplicate for supplier' ?></div>
    <?php if ($doc['status'] === 'cancelled'): ?><div class="stamp" style="display:inline-block">CANCELLED</div><?php endif; ?>
  </td>
</tr></table>
<div class="rule"></div>

<table class="meta"><tr>
  <td style="width:50%; padding-right:12px">
    <div class="eyebrow">Bill to</div>
    <div class="bold" style="font-size:10pt"><?= $e($buyer['name']) ?></div>
    <?php if ($buyer['address']): ?><div class="small"><?= $e(implode(', ', $buyer['address'])) ?></div><?php endif; ?>
    <div class="small"><?= $e(implode(' · ', array_filter([$buyer['phone'] ?? '', $buyer['email'] ?? '']))) ?></div>
    <div class="small" style="margin-top:2px"><b>GSTIN:</b> <?= ! empty($buyer['gstin']) ? $e($buyer['gstin']) : 'Unregistered (consumer)' ?><?php if ($buyer['state_name']): ?> &nbsp; <b>State:</b> <?= $e($buyer['state_name']) ?> (<?= $e($buyer['state_code']) ?>)<?php endif; ?></div>
  </td>
  <td>
    <table class="meta">
      <tr><td class="small muted" style="width:42%"><?= $isCn ? 'Credit note no.' : 'Invoice no.' ?></td><td class="bold"><?= $e($doc['number']) ?></td></tr>
      <tr><td class="small muted">Date</td><td class="bold"><?= $e($doc['date']) ?></td></tr>
      <?php if ($isCn): ?><tr><td class="small muted">Against invoice</td><td class="bold"><?= $e($doc['original_number']) ?> (<?= $e($doc['original_date']) ?>)</td></tr><?php endif; ?>
      <?php if ($hasTax): ?><tr><td class="small muted">Place of supply</td><td class="bold"><?= $e($doc['place_name']) ?> (<?= $e($doc['place_of_supply']) ?>)</td></tr>
      <tr><td class="small muted">Reverse charge</td><td>No</td></tr><?php endif; ?>
      <tr><td class="small muted">Booking ref.</td><td><?= $e($doc['booking_ref']) ?></td></tr>
    </table>
  </td>
</tr></table>
<?php if (! empty($doc['einvoice'])): $ei = $doc['einvoice']; ?>
<table class="card" style="margin-top:8px; page-break-inside: avoid"><tr>
  <td>
    <div class="eyebrow">E-invoice (Invoice Registration Portal)</div>
    <div class="tiny" style="word-break: break-all; margin-top:2px"><b>IRN:</b> <?= $e($ei['irn']) ?></div>
    <div class="tiny"><b>Ack no.:</b> <?= $e($ei['ack_no']) ?> &nbsp; <b>Ack date:</b> <?= $e($ei['ack_dt']) ?></div>
  </td>
  <?php if (! empty($ei['qr'])): ?><td class="center" style="width:110px"><img src="<?= $ei['qr'] ?>" style="width:96px; height:96px"><div class="tiny muted">Signed QR code</div></td><?php endif; ?>
</tr></table>
<?php endif; ?>

<table class="items" style="margin-top:12px">
  <tr><th style="width:4%">#</th><th>Description of service</th><?php if ($hasTax): ?><th style="width:9%">SAC</th><?php endif; ?><th class="right" style="width:15%"><?= $isCn ? 'Taxable value (reduced)' : 'Taxable value' ?></th>
    <?php if ($hasTax): ?><?php if ($intra): ?><th class="right" style="width:11%">CGST<br><span style="font-weight:normal"><?= $e($half) ?>%</span></th><th class="right" style="width:11%">SGST<br><span style="font-weight:normal"><?= $e($half) ?>%</span></th>
    <?php else: ?><th class="right" style="width:13%">IGST<br><span style="font-weight:normal"><?= $e($rate) ?>%</span></th><?php endif; ?><?php endif; ?>
  </tr>
  <?php foreach ($lines as $i => $l): ?>
  <tr><td><?= $i + 1 ?></td><td><b><?= $e($l['title']) ?></b><?php if (! empty($l['detail'])): ?><div class="small muted"><?= $e($l['detail']) ?></div><?php endif; ?></td>
    <?php if ($hasTax): ?><td><?= $e($doc['sac']) ?></td><?php endif; ?>
    <td class="right"><?= $e($n($doc['taxable_value'])) ?></td>
    <?php if ($hasTax): ?><?php if ($intra): ?><td class="right"><?= $e($n($doc['cgst'])) ?></td><td class="right"><?= $e($n($doc['sgst'])) ?></td><?php else: ?><td class="right"><?= $e($n($doc['igst'])) ?></td><?php endif; ?><?php endif; ?></tr>
  <?php endforeach; ?>
</table>

<table style="margin-top:6px; page-break-inside: avoid"><tr>
  <td style="width:55%; padding-right:14px">
    <div class="eyebrow">Amount in words</div>
    <div class="bold" style="margin:2px 0 8px"><?= $e($doc['words']) ?></div>
    <?php if ($declaration): ?><div class="tiny muted" style="border-left:2px solid <?= $brand['color'] ?>; padding-left:7px; margin-bottom:6px"><?= $e($declaration) ?></div><?php endif; ?>
    <?php if ($doc['reason']): ?><div class="small"><b>Reason:</b> <?= $e($doc['reason']) ?></div><?php endif; ?>
  </td>
  <td>
    <table class="sum">
      <tr><td><?= $isCn ? 'Taxable value (reduced)' : 'Taxable value' ?></td><td class="right"><?= $e($inr($doc['taxable_value'])) ?></td></tr>
      <?php if ($hasTax && $intra): ?><tr><td>CGST @ <?= $e($half) ?>%</td><td class="right"><?= $e($inr($doc['cgst'])) ?></td></tr><tr><td>SGST @ <?= $e($half) ?>%</td><td class="right"><?= $e($inr($doc['sgst'])) ?></td></tr>
      <?php elseif ($hasTax): ?><tr><td>IGST @ <?= $e($rate) ?>%</td><td class="right"><?= $e($inr($doc['igst'])) ?></td></tr><?php endif; ?>
      <?php if ($doc['tcs'] > 0): ?><tr><td>TCS @ <?= $e(rtrim(rtrim(number_format((float) $doc['tcs_rate'], 2), '0'), '.')) ?>% <span class="tiny muted">(not GST)</span></td><td class="right"><?= $e($inr($doc['tcs'])) ?></td></tr><?php endif; ?>
      <tr class="total"><td style="font-size:10pt"><?= $isCn ? 'Credit total' : 'Invoice total' ?></td><td class="right" style="font-size:10.5pt"><?= $e($inr($doc['total'])) ?></td></tr>
    </table>
    <?php if (! $isCn && isset($doc['paid_at_issue'])): ?>
    <table class="sum" style="margin-top:4px"><tr><td class="small muted">Received till <?= $e($doc['date']) ?></td><td class="right small"><?= $e($inr($doc['paid_at_issue'])) ?></td></tr>
      <tr><td class="small bold">Balance due</td><td class="right small bold"><?= $e($inr(max(0, $doc['total'] - $doc['paid_at_issue']))) ?></td></tr></table><?php endif; ?>
  </td>
</tr></table>

<?php if (! $isCn && ($bank['has'] || ! empty($bank['qr']))): ?>
<table class="card" style="margin-top:10px; page-break-inside: avoid"><tr>
  <td>
    <div class="eyebrow">Pay by bank transfer</div>
    <table class="meta" style="margin-top:3px">
      <?php foreach ($bank['rows'] as [$k, $v]): ?><tr><td class="small muted" style="width:34%"><?= $e($k) ?></td><td class="small bold"><?= $e($v) ?></td></tr><?php endforeach; ?>
    </table>
  </td>
  <?php if (! empty($bank['qr'])): ?><td class="center" style="width:120px"><img src="<?= $bank['qr'] ?>" style="width:84px; height:84px"><div class="tiny muted">Scan to pay <?= $e($inr($bank['qr_amount'])) ?> via UPI</div></td><?php endif; ?>
</tr></table>
<?php endif; ?>

<?php if ($terms && ! $isCn): ?><div style="margin-top:10px"><div class="eyebrow">Terms</div><div class="tiny muted"><?= $nl($terms) ?></div></div><?php endif; ?>

<table style="margin-top:18px; page-break-inside: avoid"><tr>
  <td class="tiny muted" style="width:60%">This is a computer-generated document.<?= $hasTax ? '' : ' The supplier is not registered under GST; no tax is charged.' ?></td>
  <td class="right"><div class="small muted">For <b style="color:#111827"><?= $e($seller['legal_name']) ?></b></div><div style="height:30px"></div><div class="small bold"><?= $e($seller['signatory'] ?: 'Authorised signatory') ?></div><div class="tiny muted">Authorised signatory</div></td>
</tr></table>
</body></html>
