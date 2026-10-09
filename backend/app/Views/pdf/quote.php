<?php $e = [\App\Services\Billing\Docs\DocFormat::class, 'e']; $inr = [\App\Services\Billing\Docs\DocFormat::class, 'inr']; $nl = [\App\Services\Billing\Docs\DocFormat::class, 'nl']; ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><?= view('pdf/_style', ['brand' => $brand]) ?></head><body>

<!-- header -->
<table><tr>
  <td style="width:55%">
    <?php if (! empty($brand['logo'])): ?><img src="<?= $brand['logo'] ?>" style="height:38px"><?php else: ?><div class="bold brand" style="font-size:15pt"><?= $e($brand['name']) ?></div><?php endif; ?>
    <div class="small muted" style="margin-top:3px"><?= $e(implode(' · ', array_filter([$seller['phone'] ?? '', $seller['email'] ?? '', $seller['website'] ?? '']))) ?></div>
  </td>
  <td class="right">
    <div class="eyebrow">Travel quotation</div>
    <div class="bold" style="font-size:11pt"><?= $e($quote['number']) ?></div>
    <div class="small muted">Issued <?= $e($quote['date']) ?><?= $quote['valid_until'] ? ' · Valid until <b>' . $e($quote['valid_until']) . '</b>' : '' ?></div>
  </td>
</tr></table>
<div class="rule"></div>

<!-- hero -->
<?php if (! empty($quote['cover'])): ?><img class="hero" src="<?= $quote['cover'] ?>" style="margin-bottom:10px"><?php endif; ?>
<div class="eyebrow"><?= $e($quote['kicker']) ?></div>
<div class="title" style="margin:3px 0 4px"><?= $e($quote['title']) ?></div>
<?php if ($quote['subtitle']): ?><div class="muted" style="font-size:10pt"><?= $e($quote['subtitle']) ?></div><?php endif; ?>

<table style="margin-top:12px"><tr>
  <td style="width:58%; padding-right:10px">
    <table class="card" style="width:100%">
      <?php foreach ($facts as [$label, $value]): ?>
      <tr><td class="small muted" style="width:34%; padding:2px 0"><?= $e($label) ?></td><td class="bold" style="padding:2px 0"><?= $e($value) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </td>
  <td>
    <table style="width:100%; background:<?= $brand['color'] ?>; color:#fff"><tr><td style="padding:12px 14px">
      <div class="tiny" style="letter-spacing:1.5px; opacity:.85">PACKAGE PRICE</div>
      <div style="font-size:20pt; font-weight:bold; line-height:1.1; margin:2px 0"><?= $e($inr($pricing['total'], 0)) ?></div>
      <div class="small" style="opacity:.9">for <?= (int) $quote['pax'] ?> traveller<?= $quote['pax'] > 1 ? 's' : '' ?> · incl. GST<?= $pricing['tcs'] > 0 ? ' &amp; TCS' : '' ?></div>
      <div class="small" style="margin-top:4px; opacity:.9">≈ <?= $e($inr($pricing['per_person'], 0)) ?> per person</div>
    </td></tr></table>
  </td>
</tr></table>

<?php if (! empty($quote['intro'])): ?><p style="margin-top:10px"><?= $nl($quote['intro']) ?></p><?php endif; ?>

<!-- itinerary -->
<div class="h2">Day-by-day itinerary</div>
<?php foreach ($days as $d): ?>
<table class="day"><tr>
  <td class="daynum"><div class="l">DAY</div><div class="n"><?= (int) $d['no'] ?></div></td>
  <td class="daybody">
    <div class="bold" style="font-size:10.2pt"><?= $e($d['title']) ?><?php if ($d['city']): ?> <span class="muted" style="font-weight:normal"> · <?= $e($d['city']) ?></span><?php endif; ?></div>
    <?php if (! empty($d['image'])): ?><img src="<?= $d['image'] ?>" style="width:100%; height:110px; margin:5px 0"><?php endif; ?>
    <?php if ($d['description']): ?><div style="margin:3px 0 4px"><?= $nl($d['description']) ?></div><?php endif; ?>
    <?php if ($d['items']): ?><table class="item">
      <?php foreach ($d['items'] as $i): ?>
      <tr><td style="width:98px"><span class="tag"><?= $e($i['tag']) ?></span></td>
          <td><b><?= $e($i['title']) ?></b><?= $i['nights'] ? ' <span class="muted">· ' . (int) $i['nights'] . ' night' . ($i['nights'] > 1 ? 's' : '') . '</span>' : '' ?><?= $i['details'] ? '<div class="small muted">' . $e($i['details']) . '</div>' : '' ?></td></tr>
      <?php endforeach; ?></table><?php endif; ?>
  </td>
</tr></table>
<?php endforeach; ?>
<?php if ($extras): ?>
<table class="day"><tr><td class="daybody"><div class="bold" style="margin-bottom:3px">Also included</div><table class="item">
  <?php foreach ($extras as $i): ?><tr><td style="width:98px"><span class="tag"><?= $e($i['tag']) ?></span></td><td><b><?= $e($i['title']) ?></b></td></tr><?php endforeach; ?>
</table></td></tr></table>
<?php endif; ?>

<!-- inclusions -->
<?php if ($inclusions || $exclusions): ?>
<div class="h2">What is included</div>
<table class="twocol"><tr>
  <td><div class="bold" style="margin-bottom:3px; color:#047857">Inclusions</div><ul class="tick"><?php foreach ($inclusions as $l): ?><li><span style="position:absolute; left:0; color:#047857">✓</span><?= $e($l) ?></li><?php endforeach; ?></ul></td>
  <td><div class="bold" style="margin-bottom:3px; color:#b91c1c">Exclusions</div><ul class="tick"><?php foreach ($exclusions as $l): ?><li><span style="position:absolute; left:0; color:#b91c1c">✕</span><?= $e($l) ?></li><?php endforeach; ?></ul></td>
</tr></table>
<?php endif; ?>

<!-- price -->
<div style="page-break-inside: avoid">
<div class="h2">Price summary</div>
<table><tr>
<td style="width:58%; padding-right:14px">
  <table class="price">
    <tr><td>Package price</td><td class="right"><?= $e($inr($pricing['subtotal'] + $pricing['discount'])) ?></td></tr>
    <?php if ($pricing['discount'] > 0): ?><tr><td>Discount</td><td class="right" style="color:#047857">− <?= $e($inr($pricing['discount'])) ?></td></tr><?php endif; ?>
    <tr><td>GST @ <?= $e(rtrim(rtrim(number_format($pricing['gst_rate'], 2), '0'), '.')) ?>%</td><td class="right"><?= $e($inr($pricing['gst'])) ?></td></tr>
    <?php if ($pricing['tcs'] > 0): ?><tr><td>TCS @ <?= $e(rtrim(rtrim(number_format($pricing['tcs_rate'], 2), '0'), '.')) ?>% <span class="small muted">(Income-tax Act, overseas package)</span></td><td class="right"><?= $e($inr($pricing['tcs'])) ?></td></tr><?php endif; ?>
    <tr class="total"><td>Total payable</td><td class="right"><?= $e($inr($pricing['total'])) ?></td></tr>
    <?php if (! empty($pricing['fx'])): ?><tr><td colspan="2" class="small muted" style="text-align:right">≈ <?= $e($pricing['fx']['formatted']) ?> — <?= $e($pricing['fx']['note']) ?></td></tr><?php endif; ?>
  </table>
  <?php if ($pricing['tcs'] > 0): ?><div class="tiny muted" style="margin-top:4px">TCS is collected for the Government and can be claimed as credit against your income tax (Form 26AS / AIS).</div><?php endif; ?>
</td>
<td>
  <?php if ($schedule): ?>
  <div class="bold small" style="margin-bottom:3px">Indicative payment schedule</div>
  <table class="grid"><tr><th>Payment</th><th>By</th><th class="right">Amount</th></tr>
    <?php foreach ($schedule as $s): ?><tr><td><?= $e($s['label']) ?></td><td><?= $e($s['due']) ?></td><td class="right"><?= $e($inr($s['amount'], 0)) ?></td></tr><?php endforeach; ?></table>
  <?php endif; ?>
</td></tr></table>
</div>

<!-- accept -->
<?php if (! empty($accept['qr'])): ?>
<table class="card" style="margin-top:12px; page-break-inside: avoid"><tr>
  <td style="width:86px"><img src="<?= $accept['qr'] ?>" style="width:78px; height:78px"></td>
  <td><div class="bold" style="font-size:10.5pt">Ready to go? Accept online</div>
      <div class="small muted" style="margin:2px 0">Scan the code or open the link to view this quote online and accept it in one tap. Your travel expert will then confirm the booking and share the payment schedule.</div>
      <div class="small brand"><?= $e($accept['url']) ?></div></td>
</tr></table>
<?php endif; ?>

<!-- terms -->
<?php if ($terms): ?><div class="h2">Terms &amp; conditions</div>
<?php foreach ($terms as [$heading, $text]): ?><div class="bold small" style="margin-top:5px"><?= $e($heading) ?></div><div class="small muted"><?= $nl($text) ?></div><?php endforeach; ?><?php endif; ?>

<div class="hair" style="margin-top:14px"></div>
<table><tr>
  <td class="small muted"><div class="bold" style="color:#111827"><?= $e($seller['display_name']) ?></div><?= $e(implode(', ', $seller['address'])) ?><br><?= $e(implode(' · ', array_filter([$seller['phone'] ?? '', $seller['email'] ?? '']))) ?></td>
  <td class="right small muted"><?= ! empty($seller['gstin']) ? 'GSTIN ' . $e($seller['gstin']) . '<br>' : '' ?>Prices are subject to availability at the time of booking.</td>
</tr></table>
</body></html>
