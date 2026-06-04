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
<link rel="preload" as="font" type="font/woff2" href="/assets/fonts/manrope-400.woff2" crossorigin>
<link rel="preload" as="font" type="font/woff2" href="/assets/fonts/manrope-500.woff2" crossorigin>
<link rel="preload" as="font" type="font/woff2" href="/assets/fonts/jetbrainsmono-400.woff2" crossorigin>
<?php // Synchronous in <head> so data-theme is stamped on <html> BEFORE the CSS below evaluates — prevents a light/dark flash for users with cookie=auto. See public/assets/js/theme-init.js. ?>
<script src="/assets/js/theme-init.js"></script>
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/layout.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/forms.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/kanban.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/cards-panels.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/modal-toast.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/utilities.css')) ?>">
<?= app_brand_style_tag() ?>
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
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
<?php
  // Project-creation palette: admin-configurable via Settings → Workspace.
  // Comma-separated hex without `#`. projects.js reads this attribute and
  // picks a random colour; falls back to a built-in default if absent.
  $__palette = \App\App::make('settings')->get('project_palette',
      'EA580C,5A4E3F,2563EB,CA8A04,4D6840,6D28D9,DC2626,0891B2,9333EA,0F766E');
?>
<meta name="project-palette" content="<?= e($__palette) ?>">
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
<script type="module" src="<?= e(asset_url('/assets/js/theme.js')) ?>"></script>
<?php
  // JS i18n channel: emit only the js.* slice of the current catalog onto
  // window.__t so client modules call t('js.toast.saved') instead of
  // hard-coding EN literals. JSON_HEX_* flags neutralise any `</script>`,
  // `&`, quote, or apostrophe inside a translation so it can't break out
  // of the inline script tag.
  $__jsLocale = [];
  foreach (i18n_catalog() as $__k => $__v) {
      if (is_string($__v) && str_starts_with($__k, 'js.')) $__jsLocale[$__k] = $__v;
  }
?>
<script type="application/json" id="i18n-js"><?= json_encode($__jsLocale, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php // Three site-wide modules: load without the asset_version query so the URL
      // matches what page-level modules use when they `import './ui.js'`. ES
      // modules dedup by URL — keeping them on the same canonical path is what
      // lets us drop the old `window.__otack*Init` guards. These files change
      // rarely; production deploys that touch them should bust browser cache
      // via the HTTP layer (nginx ETag / Cache-Control) rather than `?v=`. ?>
<script type="module" src="/assets/js/ui.js"></script>
<script type="module" src="/assets/js/wysiwyg.js"></script>
<script type="module" src="/assets/js/sidebar-groups.js"></script>
</body>
</html>
