<?php
$e = [\App\Services\Billing\Docs\DocFormat::class, 'e']; $inr = [\App\Services\Billing\Docs\DocFormat::class, 'inr'];
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><?= view('pdf/_style', ['brand' => $brand]) ?></head><body>
<table><tr>
  <td style="width:58%">
    <?php if (! empty($brand['logo'])): ?><img src="<?= $brand['logo'] ?>" style="height:38px; margin-bottom:4px"><?php endif; ?>
    <div class="bold" style="font-size:12pt"><?= $e($seller['legal_name']) ?></div>
    <div class="small"><?= $e(implode(', ', $seller['address'])) ?></div>
    <div class="small muted"><?= $e(implode(' · ', array_filter([$seller['phone'] ?? '', $seller['email'] ?? '']))) ?></div>
    <?php if (! empty($seller['gstin'])): ?><div class="small"><b>GSTIN:</b> <?= $e($seller['gstin']) ?></div><?php endif; ?>
  </td>
  <td class="right"><div style="font-size:19pt; font-weight:bold; color:<?= $brand['color'] ?>; letter-spacing:1px">PAYMENT RECEIPT</div>
    <div class="bold" style="font-size:10.5pt"><?= $e($doc['number']) ?></div><div class="small muted">Date <?= $e($doc['date']) ?></div></td>
</tr></table>
<div class="rule"></div>

<table style="margin-top:6px"><tr>
  <td style="width:55%; padding-right:12px">
    <div class="eyebrow">Received from</div>
    <div class="bold" style="font-size:10.5pt"><?= $e($buyer['name']) ?></div>
    <?php if ($buyer['address']): ?><div class="small"><?= $e(implode(', ', $buyer['address'])) ?></div><?php endif; ?>
    <div class="small muted"><?= $e(implode(' · ', array_filter([$buyer['phone'] ?? '', $buyer['email'] ?? '']))) ?></div>
  </td>
  <td><table class="card" style="width:100%"><tr><td>
    <div class="eyebrow">Amount received</div>
    <div style="font-size:20pt; font-weight:bold; color:<?= $brand['color'] ?>"><?= $e($inr($doc['total'])) ?></div>
    <div class="small bold"><?= $e($doc['words']) ?></div></td></tr></table></td>
</tr></table>

<table class="grid" style="margin-top:14px">
  <tr><th style="width:30%">Towards</th><td><?= $e($doc['towards']) ?></td></tr>
  <tr><th>Booking reference</th><td><?= $e($doc['booking_ref']) ?></td></tr>
  <tr><th>Payment mode</th><td><?= $e($doc['mode']) ?></td></tr>
  <?php if ($doc['reference']): ?><tr><th>Transaction / UTR reference</th><td><?= $e($doc['reference']) ?></td></tr><?php endif; ?>
  <tr><th>Received on</th><td><?= $e($doc['paid_on']) ?></td></tr>
  <?php if ($doc['invoice_number']): ?><tr><th>Against invoice</th><td><?= $e($doc['invoice_number']) ?></td></tr><?php endif; ?>
</table>

<table style="margin-top:12px; page-break-inside: avoid"><tr><td style="width:50%"></td><td>
  <table class="price">
    <tr><td>Booking total</td><td class="right"><?= $e($inr($doc['booking_total'])) ?></td></tr>
    <tr><td>Received so far (incl. this payment)</td><td class="right"><?= $e($inr($doc['received_total'])) ?></td></tr>
    <tr><td class="bold">Balance due</td><td class="right bold"><?= $e($inr(max(0, $doc['booking_total'] - $doc['received_total']))) ?></td></tr>
  </table></td></tr></table>

<div class="tiny muted" style="margin-top:14px">This receipt acknowledges payment received. It is not a tax invoice; the GST tax invoice for this booking is issued separately.<?= $doc['tcs_note'] ? ' ' . $e($doc['tcs_note']) : '' ?></div>
<table style="margin-top:22px"><tr><td></td><td class="right"><div class="small muted">For <b style="color:#111827"><?= $e($seller['legal_name']) ?></b></div><div style="height:26px"></div><div class="small bold"><?= $e($seller['signatory'] ?: 'Authorised signatory') ?></div></td></tr></table>
</body></html>
