<?= !empty($error) ? flash_meta($error, 'error') : '' ?>
<?php
$fieldErrors = $fieldErrors ?? [];
// Map raw validator codes ('required', 'invalid_email', etc.) + the
// 'taken' code we flash explicitly to the right errors.* key.
$emailMsg = isset($fieldErrors['email'])
    ? ($fieldErrors['email'] === 'taken' ? t('auth.email_taken') : t('errors.' . $fieldErrors['email'], t('errors.required')))
    : null;
?>
<div class="brief">
  <h1 class="page-section-title-lg"><?= e(t('auth.create_account')) ?></h1>
  <form method="post" action="/register">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
    <div class="field<?= isset($fieldErrors['name']) ? ' field--invalid' : '' ?>">
      <label for="f-name"><?= e(t('field.name')) ?></label>
      <input id="f-name" class="input" type="text" name="name" required autofocus>
      <?php if (isset($fieldErrors['name'])): ?>
        <div class="field__error"><?= e(t('errors.' . $fieldErrors['name'], t('errors.required'))) ?></div>
      <?php endif; ?>
    </div>
    <div class="field<?= isset($fieldErrors['email']) ? ' field--invalid' : '' ?> mt-14">
      <label for="f-email"><?= e(t('field.email')) ?></label>
      <input id="f-email" class="input" type="email" name="email" required>
      <?php if ($emailMsg !== null): ?>
        <div class="field__error"><?= e($emailMsg) ?></div>
      <?php endif; ?>
    </div>
    <div class="field<?= isset($fieldErrors['password']) ? ' field--invalid' : '' ?> mt-14">
      <label for="f-password"><?= e(t('field.password_min8')) ?></label>
      <input id="f-password" class="input" type="password" name="password" minlength="8" required>
      <?php if (isset($fieldErrors['password'])): ?>
        <div class="field__error"><?= e(t('errors.' . $fieldErrors['password'], t('errors.required'))) ?></div>
      <?php endif; ?>
    </div>
    <button class="submit auth-submit auth-submit--tight" type="submit"><?= e(t('auth.create_account_arrow')) ?></button>
  </form>
  <p class="text-13-ink-2-mt-18">
    <?= e(t('auth.already_registered')) ?> <a class="text-brand-underline" href="/login"><?= e(t('auth.sign_in')) ?></a>
  </p>
</div>
