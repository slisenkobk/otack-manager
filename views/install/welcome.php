<div class="install-card">
  <p class="install-lede"><?= e(t('install.subtitle')) ?></p>
  <form method="post" action="/install/start" class="install-form">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
    <button type="submit" class="btn btn--primary install-cta" data-action="install-start">
      <?= e(t('install.start')) ?>
    </button>
  </form>
</div>
