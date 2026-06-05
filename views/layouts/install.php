<?php
/**
 * Install wizard layout (Task #10). Standalone — no global nav, no auth
 * chrome — and no JS framework, so the page works even when the rest of
 * the asset pipeline hasn't been configured yet. Same CSS bundle as the
 * auth pages (login/register) so the visual language is consistent.
 *
 * $title       — page title for <title> + visible heading
 * $currentStep — 'welcome' | 'db' | 'admin' | 'security' | 'integrations' | 'done'
 * $content     — pre-rendered body buffered by the step view
 */
$steps = ['db', 'admin', 'security', 'integrations', 'done'];
$active = array_search($currentStep ?? 'welcome', $steps, true);
$themePref = $_COOKIE['theme'] ?? 'auto';
if (!in_array($themePref, ['light', 'dark', 'auto'], true)) $themePref = 'auto';
$themeAttr = ($themePref === 'auto') ? '' : ' data-theme="' . $themePref . '"';
?>
<!doctype html>
<html lang="<?= e(user_locale()) ?>"<?= $themeAttr ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(app_name() . ' — ' . ($title ?? '')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_href()) ?>">
<script src="/assets/js/theme-init.js"></script>
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/layout.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/forms.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/cards-panels.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/utilities.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/modal-toast.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/install.css')) ?>">
<?= app_brand_style_tag() ?>
<meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
</head>
<body class="auth-page install-page">
<main class="auth-page__main">
  <div class="install-wrap">
    <header class="install-header">
      <h1 class="install-title"><?= e($title ?? app_name()) ?></h1>
      <?php if (($currentStep ?? 'welcome') !== 'welcome'): ?>
      <ol class="install-steps" aria-label="install progress">
        <?php foreach ($steps as $i => $key):
          $isDone    = $active !== false && $i <  $active;
          $isCurrent = $active !== false && $i === $active;
          $cls = 'install-steps__item'
               . ($isDone    ? ' is-done'    : '')
               . ($isCurrent ? ' is-current' : '');
        ?>
          <li class="<?= $cls ?>"><span class="install-steps__index"><?= $i + 1 ?>.</span> <?= e(t('install.step.' . $key)) ?></li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </header>
    <section class="install-body">
      <?= $content ?? '' ?>
    </section>
  </div>
</main>
<?php require APP_ROOT . '/views/partials/toast-root.php'; ?>
<?php
  // JS i18n channel — the Done step's click-to-copy on the sign-in URL
  // calls t('js.toast.copied') / t('js.toast.copy_failed_manual'), so we
  // need the same window.__t bridge auth/main layouts ship.
  $__jsLocale = [];
  foreach (i18n_catalog() as $__k => $__v) {
      if (is_string($__v) && str_starts_with($__k, 'js.')) $__jsLocale[$__k] = $__v;
  }
?>
<script type="application/json" id="i18n-js"><?= json_encode($__jsLocale, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="module" src="/assets/js/ui.js"></script>
</body>
</html>
