<?php
$flash = $flash ?? null;
$form  = $form  ?? [];
?>
<div class="install-card">
  <h2 class="install-heading"><?= e(t('install.admin.heading')) ?></h2>

  <?php if (!empty($flash)): ?>
    <div class="install-alert install-alert--error"><?= e($flash) ?></div>
  <?php endif; ?>

  <form method="post" action="/install/admin" class="install-form">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
    <div class="field">
      <label for="f-name"><?= e(t('install.admin.name')) ?></label>
      <input id="f-name" class="input" name="name" required minlength="2" autofocus
             value="<?= e($form['name'] ?? '') ?>">
    </div>
    <div class="field mt-14">
      <label for="f-email"><?= e(t('install.admin.email')) ?></label>
      <input id="f-email" class="input" name="email" type="email" required
             value="<?= e($form['email'] ?? '') ?>">
    </div>
    <div class="field mt-14">
      <label for="f-password"><?= e(t('install.admin.password')) ?></label>
      <input id="f-password" class="input" name="password" type="password" required minlength="8">
      <small class="install-hint"><?= e(t('install.admin.password_hint')) ?></small>
    </div>
    <button type="submit" class="btn btn--primary install-cta mt-14">
      <?= e(t('install.admin.submit')) ?>
    </button>
  </form>
</div>
