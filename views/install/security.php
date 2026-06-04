<?php
$flash       = $flash ?? null;
$appUrl      = $detectedAppUrl ?? '';
$loginHashOn = !empty($form['enable_login_hash']);
?>
<div class="install-card">
  <h2 class="install-heading"><?= e(t('install.security.heading')) ?></h2>

  <?php if (!empty($flash)): ?>
    <div class="install-alert install-alert--error"><?= e($flash) ?></div>
  <?php endif; ?>

  <form method="post" action="/install/security" class="install-form">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

    <label class="install-checkbox">
      <input type="checkbox" name="enable_login_hash" value="1"<?= $loginHashOn ? ' checked' : '' ?>>
      <span>
        <strong><?= e(t('install.security.login_hash_label')) ?></strong>
        <small class="install-hint"><?= e(t('install.security.login_hash_hint')) ?></small>
      </span>
    </label>

    <div class="field mt-14">
      <label for="f-app-url"><?= e(t('install.security.app_url')) ?></label>
      <input id="f-app-url" class="input" name="app_url" type="url" required
             value="<?= e($appUrl) ?>">
      <small class="install-hint"><?= e(t('install.security.app_url_hint')) ?></small>
    </div>

    <button type="submit" class="btn btn--primary install-cta mt-14">
      <?= e(t('install.security.next')) ?>
    </button>
  </form>
</div>
