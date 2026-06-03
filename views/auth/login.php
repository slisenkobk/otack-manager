<?= !empty($error) ? flash_meta($error, 'error') : '' ?>
<?php $fieldErrors = $fieldErrors ?? []; ?>
<div class="brief">
  <h1 style="font-size:32px;font-weight:700;margin:0 0 24px;"><?= e(t('auth.sign_in')) ?></h1>
  <form method="post" action="/login">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
    <div class="field<?= isset($fieldErrors['email']) ? ' field--invalid' : '' ?>">
      <label for="f-email"><?= e(t('field.email')) ?></label>
      <input id="f-email" class="input" type="email" name="email" required autofocus>
      <?php if (isset($fieldErrors['email'])): ?>
        <div class="field__error"><?= e(t('errors.' . $fieldErrors['email'], t('errors.required'))) ?></div>
      <?php endif; ?>
    </div>
    <div class="field<?= isset($fieldErrors['password']) ? ' field--invalid' : '' ?>" style="margin-top:14px;">
      <label for="f-password"><?= e(t('field.password')) ?></label>
      <input id="f-password" class="input" type="password" name="password" required>
      <?php if (isset($fieldErrors['password'])): ?>
        <div class="field__error"><?= e(t('errors.' . $fieldErrors['password'], t('errors.required'))) ?></div>
      <?php endif; ?>
    </div>
    <label class="remember-me" style="margin-top:18px;display:inline-flex;align-items:center;gap:8px;font-size:13px;color:var(--ink-2);cursor:pointer;">
      <input type="checkbox" name="remember" value="1">
      <?= e(t('auth.remember_me')) ?>
    </label>
    <button class="submit" type="submit" style="margin-top:18px;width:100%;"><?= e(t('auth.sign_in_arrow')) ?></button>
  </form>
  <p style="margin-top:18px;font-size:13px;color:var(--ink-2);">
    <?= e(t('auth.new_here')) ?> <a href="/register" style="color:var(--brand);text-decoration:underline;"><?= e(t('auth.create_account')) ?></a>
  </p>
</div>
