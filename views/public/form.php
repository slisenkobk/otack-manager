<?php
// Self-contained public form — single page with own HTML wrapper.
header('Content-Type: text/html; charset=utf-8');
$errors = $errors ?? [];
$values = $values ?? [];
$contact = $contact ?? [];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(app_name() . ' - ' . $form['title']) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_href()) ?>">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
<?= app_brand_style_tag() ?>
</head>
<body class="public-form">
  <div class="pf-shell">

    <header class="pf-header">
      <span class="pf-tag"><?= e(app_name()) ?></span>
      <h1 class="pf-title"><?= e($form['title']) ?></h1>
      <?php if (!empty($form['description'])): ?>
        <div class="pf-desc rich-text"><?= $form['description'] /* server-sanitised HTML */ ?></div>
      <?php endif; ?>
    </header>

    <form method="post" action="/f/<?= e($form['hash']) ?>" novalidate>

      <div class="pf-card">
        <h3 class="pf-card__title">Your answers</h3>

        <?php foreach ($fields as $f):
          $val = $values[$f['key']] ?? '';
          $err = $errors[$f['key']] ?? null;
          $isReq = !empty($f['required']);
        ?>
          <div class="pf-field<?= $err ? ' pf-field--invalid' : '' ?>">
            <label class="pf-field__label<?= $isReq ? ' pf-field__label--req' : '' ?>" for="f_<?= e($f['key']) ?>"><?= e($f['label']) ?></label>

            <?php if ($f['type'] === 'textarea'): ?>
              <textarea id="f_<?= e($f['key']) ?>" class="textarea" name="<?= e($f['key']) ?>" rows="4"><?= e((string)$val) ?></textarea>

            <?php elseif ($f['type'] === 'select'): ?>
              <div class="pf-select">
                <select id="f_<?= e($f['key']) ?>" class="select" name="<?= e($f['key']) ?>">
                  <option value="">— pick —</option>
                  <?php foreach (($f['options'] ?? []) as $opt): ?>
                    <option value="<?= e($opt) ?>"<?= $val === $opt ? ' selected' : '' ?>><?= e($opt) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

            <?php elseif ($f['type'] === 'radio'): ?>
              <div class="pf-choices">
                <?php foreach (($f['options'] ?? []) as $i => $opt): ?>
                  <label class="pf-choice">
                    <input type="radio" name="<?= e($f['key']) ?>" value="<?= e($opt) ?>"<?= $val === $opt ? ' checked' : '' ?>>
                    <span class="pf-choice__dot"></span>
                    <span class="pf-choice__text"><?= e($opt) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>

            <?php elseif ($f['type'] === 'checkbox'): ?>
              <?php $arr = is_array($val) ? $val : []; ?>
              <div class="pf-choices">
                <?php foreach (($f['options'] ?? []) as $i => $opt): ?>
                  <label class="pf-choice">
                    <input type="checkbox" name="<?= e($f['key']) ?>[]" value="<?= e($opt) ?>"<?= in_array($opt, $arr, true) ? ' checked' : '' ?>>
                    <span class="pf-choice__box"></span>
                    <span class="pf-choice__text"><?= e($opt) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>

            <?php else:
              $inputType = match ($f['type']) {
                'email' => 'email', 'phone' => 'tel', 'number' => 'number', default => 'text',
              };
            ?>
              <input id="f_<?= e($f['key']) ?>" class="input" type="<?= e($inputType) ?>" name="<?= e($f['key']) ?>" value="<?= e((string)$val) ?>">
            <?php endif; ?>

            <?php if ($err): ?><div class="pf-error"><?= e($err) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (!empty($contact)): ?>
        <aside class="pf-contact">
          <h3 class="pf-card__title">Contact info</h3>
          <dl class="pf-contact__list">
            <?php foreach ($contact as $row): ?>
              <div class="pf-contact__row pf-contact__row--<?= e($row['kind']) ?>">
                <dt><?= e($row['label']) ?></dt>
                <dd>
                  <?php if ($row['kind'] === 'email'): ?>
                    <a href="mailto:<?= e($row['value']) ?>"><?= e($row['value']) ?></a>
                  <?php elseif ($row['kind'] === 'phone'): ?>
                    <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $row['value'])) ?>"><?= e($row['value']) ?></a>
                  <?php elseif ($row['kind'] === 'note'): ?>
                    <div class="rich-text"><?= $row['value'] /* server-sanitised HTML */ ?></div>
                  <?php else: ?>
                    <?= nl2br(e($row['value'])) ?>
                  <?php endif; ?>
                </dd>
              </div>
            <?php endforeach; ?>
          </dl>
        </aside>
      <?php endif; ?>

      <div class="pf-actions">
        <button class="btn btn--primary submit pf-submit" type="submit">Submit <i class="fa-solid fa-arrow-right"></i></button>
      </div>
    </form>

  </div>
</body>
</html>
