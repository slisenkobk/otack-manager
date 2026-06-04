<?php
$themePref = $_COOKIE['theme'] ?? 'auto';
if (!in_array($themePref, ['light', 'dark', 'auto'], true)) $themePref = 'auto';
$themeAttr = ($themePref === 'auto') ? '' : ' data-theme="' . $themePref . '"';
?>
<!doctype html>
<html lang="<?= e(user_locale()) ?>"<?= $themeAttr ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(app_name() . (!empty($title) ? ' - ' . $title : '')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_href()) ?>">
<link rel="preload" as="font" type="font/woff2" href="/assets/fonts/manrope-400.woff2" crossorigin>
<link rel="preload" as="font" type="font/woff2" href="/assets/fonts/manrope-500.woff2" crossorigin>
<link rel="preload" as="font" type="font/woff2" href="/assets/fonts/jetbrainsmono-400.woff2" crossorigin>
<?php // See views/layouts/main.php for the no-flash rationale. ?>
<script src="/assets/js/theme-init.js"></script>
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/layout.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/forms.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/kanban.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/cards-panels.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/modal-toast.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/utilities.css')) ?>">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
<?= app_brand_style_tag() ?>
<meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
</head>
<body class="auth-page">
<main style="min-height:100vh;display:grid;place-items:center;padding:40px;">
  <div class="w-full max-w-440">
    <?= $content ?>
  </div>
</main>
<?php require APP_ROOT . '/views/partials/modal-root.php'; ?>
<?php require APP_ROOT . '/views/partials/toast-root.php'; ?>
<?php require APP_ROOT . '/views/partials/lightbox-root.php'; ?>
<?php
  // JS i18n channel — mirror of main.php so toasts surfaced on login/register
  // pages (flash messages, validation hints) still resolve via window.__t.
  $__jsLocale = [];
  foreach (i18n_catalog() as $__k => $__v) {
      if (is_string($__v) && str_starts_with($__k, 'js.')) $__jsLocale[$__k] = $__v;
  }
?>
<script type="application/json" id="i18n-js"><?= json_encode($__jsLocale, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php // No `?v=` query: matches the URL page-level modules use when they
      // `import './ui.js'`, so ES-module dedup gives us one execution per page.
      // See views/layouts/main.php for the same canonicalisation rationale. ?>
<script type="module" src="/assets/js/ui.js"></script>
</body>
</html>
