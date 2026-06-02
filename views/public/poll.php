<?php
// Self-contained public poll page. Renders one of two stages: 'contact' or 'vote'.
header('Content-Type: text/html; charset=utf-8');
$error = $error ?? null;
$values = $values ?? [];
$contactInputType = $contactField === 'phone' ? 'tel' : 'email';
$contactLabel = $contactField === 'phone' ? t('public_poll.phone_label') : t('public_poll.email_label');
?><!doctype html>
<html lang="<?= e(user_locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(app_name() . ' - ' . $poll['title']) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_href()) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
<?= app_brand_style_tag() ?>
</head>
<body class="public-form">
  <div class="pf-shell">

    <header class="pf-header">
      <span class="pf-tag"><?= e(app_name()) ?></span>
      <h1 class="pf-title"><?= e($poll['title']) ?></h1>
      <?php if (!empty($poll['description'])): ?>
        <div class="pf-desc rich-text"><?= $poll['description'] /* server-sanitised HTML */ ?></div>
      <?php endif; ?>
    </header>

    <form method="post" action="/p/<?= e($poll['hash']) ?>" novalidate>
      <?php // Anti-bot honeypot — hidden from sighted users + screen readers. ?>
      <div class="pf-honeypot" aria-hidden="true">
        <label><?= e(t('public_form.honeypot')) ?>
          <input type="text" name="<?= e($honeypot) ?>" tabindex="-1" autocomplete="off">
        </label>
      </div>
      <input type="hidden" name="ts"     value="<?= e((string)($trap['ts'] ?? '')) ?>">
      <input type="hidden" name="ts_sig" value="<?= e((string)($trap['sig'] ?? '')) ?>">

      <?php if ($stage === 'contact'): ?>
        <div class="pf-card">
          <h3 class="pf-card__title"><?= e(t('public_poll.stage_contact_title')) ?></h3>
          <p class="muted" style="margin:-8px 0 16px;color:var(--ink-3);font-size:13px;"><?= e(t('public_poll.stage_contact_hint')) ?></p>
          <?php if ($error): ?>
            <p class="pf-error" role="alert" style="color:var(--accent);margin:0 0 12px;"><?= e($error) ?></p>
          <?php endif; ?>
          <div class="pf-field<?= $error ? ' pf-field--invalid' : '' ?>">
            <label class="pf-field__label pf-field__label--req" for="poll_contact"><?= e($contactLabel) ?></label>
            <input id="poll_contact"
                   class="input"
                   type="<?= e($contactInputType) ?>"
                   name="contact"
                   value="<?= e((string)($values['contact'] ?? '')) ?>"
                   autocomplete="<?= e($contactField === 'phone' ? 'tel' : 'email') ?>"
                   required>
          </div>
          <button type="submit" class="btn btn--primary submit" style="width:100%;margin-top:8px;"><?= e(t('public_poll.stage_contact_submit')) ?></button>
        </div>

      <?php elseif ($stage === 'vote' && $choiceField): ?>
        <input type="hidden" name="contact"             value="<?= e((string)$contactRaw) ?>">
        <input type="hidden" name="contact_normalized"  value="<?= e((string)$contactNorm) ?>">
        <input type="hidden" name="contact_token"       value="<?= e((string)$contactToken) ?>">
        <input type="hidden" name="contact_token_ts"    value="<?= e((string)$contactTokenTs) ?>">
        <div class="pf-card">
          <h3 class="pf-card__title"><?= e((string)$choiceField['label']) ?></h3>
          <p class="muted" style="margin:-8px 0 16px;color:var(--ink-3);font-size:13px;">
            <?= e(t('public_poll.voting_as', ['contact' => $contactRaw])) ?>
          </p>
          <?php if ($error): ?>
            <p class="pf-error" role="alert" style="color:var(--accent);margin:0 0 12px;"><?= e($error) ?></p>
          <?php endif; ?>
          <div class="pf-field">
            <?php foreach ((array)($choiceField['options'] ?? []) as $opt):
              if (!is_array($opt)) continue;
              $key   = (string)($opt['key'] ?? '');
              $label = (string)($opt['label'] ?? $key);
              if ($key === '') continue;
            ?>
              <label class="pf-radio">
                <input type="radio" name="choice" value="<?= e($key) ?>" required>
                <span><?= e($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="btn btn--primary submit" style="width:100%;margin-top:8px;"><?= e(t('public_poll.stage_vote_submit')) ?></button>
        </div>
      <?php endif; ?>

    </form>
  </div>
</body>
</html>
