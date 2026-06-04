<div class="brief text-center">
  <i class="fa-solid fa-hourglass-half auth-pending__icon"></i>
  <h1 class="auth-pending__title"><?= e(t('auth.pending_title')) ?></h1>
  <p class="auth-pending__body">
    <?= e(t('auth.pending_body')) ?>
  </p>
  <form method="post" action="/logout" class="mt-24">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken ?? '') ?>">
    <button class="btn btn--ghost" type="submit"><?= e(t('auth.sign_out')) ?></button>
  </form>
</div>
