<?php
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(app_name()) ?> - Submission received</title>
<link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_href()) ?>">
<?= app_brand_style_tag() ?>
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
<style>
  body { background:
    radial-gradient(circle at 1px 1px, rgba(26,22,18,.06) 1px, transparent 0) 0 0/16px 16px,
    var(--paper);
    min-height: 100vh; display: grid; place-items: center; padding: 40px;
  }
  .pf-thanks {
    max-width: 560px; background: var(--bg-elevated); border: 1px solid var(--rule);
    border-radius: var(--radius); padding: 48px 40px; text-align: center;
  }
  .pf-thanks i { font-size: 48px; color: var(--accent); margin-bottom: 18px; }
  .pf-thanks h1 { font-size: 24px; margin: 0 0 10px; font-weight: 700; }
  .pf-thanks p { color: var(--ink-3); line-height: 1.5; margin: 0; }
</style>
</head>
<body>
  <div class="pf-thanks">
    <i class="fa-solid fa-circle-check"></i>
    <h1>Thanks — your submission was received</h1>
    <p>We'll get back to you shortly.<br>You may close this tab.</p>
  </div>
</body>
</html>
