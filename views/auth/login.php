<?= !empty($error) ? flash_meta($error, 'error') : '' ?>
<?php $fieldErrors = $fieldErrors ?? []; ?>
<div class="brief">
  <h1 class="page-section-title-lg"><?= e(t('auth.sign_in')) ?></h1>
  <form method="post" action="/login">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
    <div class="field<?= isset($fieldErrors['email']) ? ' field--invalid' : '' ?>">
      <label for="f-email"><?= e(t('field.email')) ?></label>
      <input id="f-email" class="input" type="email" name="email" required autofocus>
      <?php if (isset($fieldErrors['email'])): ?>
        <?php $emailKey = 'errors.' . $fieldErrors['email']; $emailMsg = t($emailKey); ?>
        <div class="field__error"><?= e($emailMsg === $emailKey ? t('errors.required') : $emailMsg) ?></div>
      <?php endif; ?>
    </div>
    <div class="field<?= isset($fieldErrors['password']) ? ' field--invalid' : '' ?> mt-14">
      <label for="f-password"><?= e(t('field.password')) ?></label>
      <input id="f-password" class="input" type="password" name="password" required>
      <?php if (isset($fieldErrors['password'])): ?>
        <?php $pwKey = 'errors.' . $fieldErrors['password']; $pwMsg = t($pwKey); ?>
        <div class="field__error"><?= e($pwMsg === $pwKey ? t('errors.required') : $pwMsg) ?></div>
      <?php endif; ?>
    </div>
    <label class="remember-me auth-remember-me">
      <input type="checkbox" name="remember" value="1">
      <?= e(t('auth.remember_me')) ?>
    </label>
    <button class="submit auth-submit" type="submit"><?= e(t('auth.sign_in_arrow')) ?></button>
  </form>
  <p class="text-13-ink-2-mt-18">
    <?= e(t('auth.new_here')) ?> <a class="text-brand-underline" href="/register"><?= e(t('auth.create_account')) ?></a>
  </p>
</div>
