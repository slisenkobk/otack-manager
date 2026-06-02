<?php
header('Content-Type: text/html; charset=utf-8');
http_response_code(empty($poll) ? 404 : 410);
$reason = $reason ?? null; // 'draft' | 'closed' | null (not found)
?><!doctype html>
<html lang="<?= e(user_locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(app_name()) ?> - <?= e(t('public_poll.unavailable_title')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_href()) ?>">
<?= app_brand_style_tag() ?>
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
<style>
  body { background: var(--paper); min-height: 100vh; display: grid; place-items: center; padding: 40px; }
  .pf-thanks {
    max-width: 560px; background: var(--bg-elevated); border: 1px solid var(--rule);
    border-radius: var(--radius); padding: 48px 40px; text-align: center;
  }
  .pf-thanks i { font-size: 48px; color: var(--ink-3); margin-bottom: 18px; }
  .pf-thanks h1 { font-size: 22px; margin: 0 0 10px; font-weight: 600; }
  .pf-thanks p { color: var(--ink-3); line-height: 1.5; margin: 0; }
</style>
</head>
<body>
  <div class="pf-thanks">
    <i class="fa-regular fa-circle-question"></i>
    <?php if ($reason === 'closed'): ?>
      <h1><?= e(t('public_poll.closed_title')) ?></h1>
      <p><?= e(t('public_poll.closed_body')) ?></p>
    <?php elseif ($reason === 'draft'): ?>
      <h1><?= e(t('public_poll.draft_title')) ?></h1>
      <p><?= e(t('public_poll.draft_body')) ?></p>
    <?php else: ?>
      <h1><?= e(t('public_poll.notfound_title')) ?></h1>
      <p><?= e(t('public_poll.notfound_body')) ?></p>
    <?php endif; ?>
  </div>
</body>
</html>
