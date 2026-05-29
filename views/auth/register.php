<?= !empty($error) ? flash_meta($error, 'error') : '' ?>
<div class="brief">
  <h1 style="font-size:32px;font-weight:700;margin:0 0 24px;"><?= e(t('auth.create_account')) ?></h1>
  <form method="post" action="/register">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
    <div class="field">
      <label><?= e(t('field.name')) ?></label>
      <input class="input" type="text" name="name" required autofocus>
    </div>
    <div class="field" style="margin-top:14px;">
      <label><?= e(t('field.email')) ?></label>
      <input class="input" type="email" name="email" required>
    </div>
    <div class="field" style="margin-top:14px;">
      <label><?= e(t('field.password_min8')) ?></label>
      <input class="input" type="password" name="password" minlength="8" required>
    </div>
    <button class="submit" type="submit" style="margin-top:22px;width:100%;"><?= e(t('auth.create_account_arrow')) ?></button>
  </form>
  <p style="margin-top:18px;font-size:13px;color:var(--ink-2);">
    <?= e(t('auth.already_registered')) ?> <a href="/login" style="color:var(--brand);text-decoration:underline;"><?= e(t('auth.sign_in')) ?></a>
  </p>
</div>
