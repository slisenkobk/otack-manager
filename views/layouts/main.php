<!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Otack Manager') ?></title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="/assets/vendor/quill/quill.snow.css">
<meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
<meta name="upload-max-image" content="<?= e(\App\App::env('UPLOAD_MAX_IMAGE', '5242880')) ?>">
<meta name="upload-max-file"  content="<?= e(\App\App::env('UPLOAD_MAX_FILE',  '52428800')) ?>">
</head>
<body>
<div class="shell">
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
<script type="module" src="/assets/js/ui.js"></script>
<script type="module" src="/assets/js/wysiwyg.js"></script>
</body>
</html>
