<?php $e = [\App\Services\Billing\Docs\DocFormat::class, 'e']; $nl = [\App\Services\Billing\Docs\DocFormat::class, 'nl']; ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><?= view('pdf/_style', ['brand' => $brand]) ?></head><body>
<table><tr>
  <td style="width:58%">
    <?php if (! empty($brand['logo'])): ?><img src="<?= $brand['logo'] ?>" style="height:38px"><?php else: ?><div class="bold brand" style="font-size:14pt"><?= $e($brand['name']) ?></div><?php endif; ?>
    <div class="small muted" style="margin-top:3px"><?= $e(implode(' · ', array_filter([$seller['phone'] ?? '', $seller['email'] ?? '']))) ?></div>
  </td>
  <td class="right"><div style="font-size:19pt; font-weight:bold; color:<?= $brand['color'] ?>; letter-spacing:1px">SERVICE VOUCHER</div>
    <div class="small muted">Booking <b><?= $e($v['booking_ref']) ?></b></div></td>
</tr></table>
<div class="rule"></div>

<table><tr>
  <td style="width:60%; padding-right:10px">
    <div class="eyebrow"><?= $e($v['kind']) ?></div>
    <div class="title" style="font-size:18pt; margin:2px 0 6px"><?= $e($v['title']) ?></div>
    <?php if ($v['supplier']): ?><div class="bold"><?= $e($v['supplier']) ?></div><?php endif; ?>
    <?php if ($v['supplier_contact']): ?><div class="small muted"><?= $e($v['supplier_contact']) ?></div><?php endif; ?>
  </td>
  <td><table class="card" style="width:100%"><tr><td>
    <div class="eyebrow">Confirmation number</div>
    <div style="font-size:15pt; font-weight:bold; color:<?= $brand['color'] ?>"><?= $v['confirmation'] ? $e($v['confirmation']) : 'Pending' ?></div>
    <div class="small muted" style="margin-top:2px">Status: <b><?= $e($v['status']) ?></b></div></td></tr></table></td>
</tr></table>

<table class="grid" style="margin-top:14px">
  <?php foreach ($v['rows'] as [$k, $val]): ?><tr><th style="width:28%"><?= $e($k) ?></th><td><?= $e($val) ?></td></tr><?php endforeach; ?>
</table>

<?php if ($v['travellers']): ?><div class="h2">Travellers</div>
<table class="grid"><tr><th style="width:6%">#</th><th>Name (as on passport / ID)</th><th style="width:18%">Type</th></tr>
<?php foreach ($v['travellers'] as $i => $t): ?><tr><td><?= $i + 1 ?></td><td><?= $e($t['name']) ?></td><td><?= $e($t['type']) ?></td></tr><?php endforeach; ?></table><?php endif; ?>

<div class="h2">Important information</div>
<ul class="tick small muted">
  <li>Please carry this voucher (printed or on your phone) and a valid photo ID / passport for every traveller.</li>
  <li>Quote the confirmation number above at check-in or when the service starts.</li>
  <li>Any extras (meals, activities, upgrades) not mentioned here are payable directly to the service provider.</li>
  <li>For help during your trip, contact <?= $e($seller['display_name']) ?> on <?= $e($seller['phone'] ?: ($seller['email'] ?: 'your travel expert')) ?>.</li>
</ul>
<?php if ($v['notes']): ?><div class="h2">Notes</div><div class="small"><?= $nl($v['notes']) ?></div><?php endif; ?>
<div class="hair" style="margin-top:20px"></div>
<div class="tiny muted">Issued by <?= $e($seller['display_name']) ?> on behalf of the service provider. This voucher is not a tax invoice.</div>
</body></html>
