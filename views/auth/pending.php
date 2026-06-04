<div class="brief text-center">
  <i class="fa-solid fa-hourglass-half" style="font-size:48px;color:var(--brand);margin-bottom:18px;"></i>
  <h1 style="font-size:24px;font-weight:600;margin:0 0 12px;"><?= e(t('auth.pending_title')) ?></h1>
  <p style="color:var(--ink-2);font-size:15px;line-height:1.55;">
    <?= e(t('auth.pending_body')) ?>
  </p>
  <form method="post" action="/logout" class="mt-24">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken ?? '') ?>">
    <button class="btn--ghost" type="submit"><?= e(t('auth.sign_out')) ?></button>
  </form>
</div>
