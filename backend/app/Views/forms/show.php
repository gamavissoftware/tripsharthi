<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= esc($form['title'] ?? 'Contact Form') ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
           background: #f5f7fa; display: flex; align-items: center;
           justify-content: center; min-height: 100vh; padding: 1.5rem; }
    .card { background: #fff; border-radius: 10px; padding: 2rem;
            width: 100%; max-width: 480px;
            box-shadow: 0 2px 16px rgba(0,0,0,.08); }
    h1   { font-size: 1.3rem; font-weight: 700; margin-bottom: 1.5rem; color: #1a1a2e; }
    .field { display: flex; flex-direction: column; gap: .35rem; margin-bottom: 1rem; }
    label { font-size: .875rem; font-weight: 500; color: #374151; }
    input, select { padding: .6rem .75rem; border: 1px solid #d1d5db; border-radius: 6px;
                    font-size: .95rem; outline: none; width: 100%; }
    input:focus { border-color: #0a6cc4; }
    .error-msg { color: #dc2626; font-size: .8rem; margin-top: .2rem; }
    .global-error { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px;
                    padding: .75rem 1rem; color: #dc2626; font-size: .875rem; margin-bottom: 1rem; }
    button { width: 100%; padding: .7rem; background: #0a6cc4; color: #fff;
             border: none; border-radius: 6px; font-size: 1rem; font-weight: 600;
             cursor: pointer; margin-top: .5rem; }
    button:active { opacity: .9; }
  </style>
</head>
<body>
<div class="card">
  <h1><?= esc($form['title'] ?? 'Contact Form') ?></h1>

  <?php if (! empty($errors['form'])): ?>
    <div class="global-error"><?= esc($errors['form']) ?></div>
  <?php endif; ?>

  <form method="POST" action="<?= base_url('forms/' . esc($form['form_token']??'') . '/submit') ?>">
    <?= csrf_field() ?>
    <?php // Ad attribution: click IDs / UTMs forwarded by the embed script (or present on a direct link). Kept across a validation re-render.
    foreach (['gclid', 'gbraid', 'wbraid', 'fbclid', 'fbp', 'fbc', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'landing_url'] as $__t):
        $__v = $_GET[$__t] ?? $_POST[$__t] ?? '';
        if (is_string($__v) && $__v !== ''): ?>
      <input type="hidden" name="<?= $__t ?>" value="<?= esc(mb_substr($__v, 0, 500)) ?>">
    <?php endif; endforeach; ?>

    <?php foreach ($fields as $field): ?>
      <?php
        $key     = $field['field_key'];
        $label   = $field['label'];
        $req     = ! empty($field['required']);
        $val     = esc($old[$key] ?? '');
        $errMsg  = $errors[$key] ?? null;
      ?>
      <div class="field">
        <label for="<?= esc($key) ?>">
          <?= esc($label) ?><?= $req ? ' <span style="color:#dc2626">*</span>' : '' ?>
        </label>
        <input type="<?= $key === 'email' ? 'email' : 'text' ?>"
               id="<?= esc($key) ?>"
               name="<?= esc($key) ?>"
               value="<?= $val ?>"
               <?= $req ? 'required' : '' ?>>
        <?php if ($errMsg): ?>
          <span class="error-msg"><?= esc($errMsg) ?></span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <button type="submit">Submit</button>
  </form>
</div>

<script>
  // Post height to parent for iframe resize
  window.addEventListener('load', function() {
    parent.postMessage({ lpHeight: document.body.scrollHeight, lpToken: '<?= esc($form['form_token']??'') ?>' }, '*');
  });
</script>
</body>
</html>
