<?php
// Theme preference: cookie 'theme' ∈ {light,dark,auto}. 'auto' (or unset)
// falls back to prefers-color-scheme — handled purely in CSS.
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
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
<?= app_brand_style_tag() ?>
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="/assets/vendor/quill/quill.snow.css">
<meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
<meta name="upload-max-image" content="<?= e(\App\App::env('UPLOAD_MAX_IMAGE', '5242880')) ?>">
<meta name="upload-max-file"  content="<?= e(\App\App::env('UPLOAD_MAX_FILE',  '52428800')) ?>">
<?php
  // Locale list for JS modals (user create/edit dropdown, etc.).
  $localeMeta = [];
  foreach (available_locales() as $code) {
      $localeMeta[] = ['code' => $code, 'name' => locale_display_names()[$code] ?? $code];
  }
?>
<meta name="i18n-locales" content="<?= e(json_encode($localeMeta)) ?>">
<meta name="i18n-locale"  content="<?= e(user_locale()) ?>">
</head>
<body>
<div class="shell" data-shell>
  <div class="mobile-nav-backdrop" data-mobile-nav-backdrop hidden></div>
  <?= $sidebar ?? '' ?>
  <main class="main">
    <?= $topbar ?? '' ?>
    <div class="body-wrap"><?= $content ?></div>
  </main>
</div>
<?php require APP_ROOT . '/views/partials/modal-root.php'; ?>
<?php require APP_ROOT . '/views/partials/toast-root.php'; ?>
<?php require APP_ROOT . '/views/partials/lightbox-root.php'; ?>
<script src="/assets/vendor/quill/quill.min.js" defer></script>
<script type="module" src="<?= e(asset_url('/assets/js/theme.js')) ?>"></script>
<script type="module" src="<?= e(asset_url('/assets/js/ui.js')) ?>"></script>
<script type="module" src="<?= e(asset_url('/assets/js/wysiwyg.js')) ?>"></script>
</body>
</html>
