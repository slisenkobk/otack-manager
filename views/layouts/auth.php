<?php
$themePref = $_COOKIE['theme'] ?? 'auto';
if (!in_array($themePref, ['light', 'dark', 'auto'], true)) $themePref = 'auto';
$themeAttr = ($themePref === 'auto') ? '' : ' data-theme="' . $themePref . '"';
?>
<!doctype html>
<html lang="uk"<?= $themeAttr ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(app_name() . (!empty($title) ? ' - ' . $title : '')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_href()) ?>">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
<?= app_brand_style_tag() ?>
<meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
</head>
<body class="auth-page">
<main style="min-height:100vh;display:grid;place-items:center;padding:40px;">
  <div style="width:100%;max-width:440px;">
    <?= $content ?>
  </div>
</main>
<?php require APP_ROOT . '/views/partials/modal-root.php'; ?>
<?php require APP_ROOT . '/views/partials/toast-root.php'; ?>
<?php require APP_ROOT . '/views/partials/lightbox-root.php'; ?>
<script type="module" src="/assets/js/ui.js"></script>
</body>
</html>
