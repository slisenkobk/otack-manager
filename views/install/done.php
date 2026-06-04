<div class="install-card">
  <h2 class="install-heading"><?= e(t('install.done.heading')) ?></h2>
  <p class="install-lede"><?= e(t('install.done.summary')) ?></p>

  <ul class="install-summary">
    <li>
      <span class="install-summary__key"><?= e(t('install.done.field_db')) ?></span>
      <strong><?= e($summary['db']) ?></strong>
    </li>
    <li>
      <span class="install-summary__key"><?= e(t('install.done.field_admin')) ?></span>
      <strong><?= e($summary['admin_email']) ?></strong>
    </li>
    <li>
      <span class="install-summary__key"><?= e(t('install.done.field_login_hash')) ?></span>
      <strong><?= e($summary['login_hash'] ? t('install.done.on') : t('install.done.off')) ?></strong>
    </li>
    <li>
      <span class="install-summary__key"><?= e(t('install.done.field_telegram')) ?></span>
      <strong><?= e($summary['telegram'] ? t('install.done.configured') : t('install.done.skipped')) ?></strong>
    </li>
  </ul>

  <a href="<?= e($summary['sign_in_url']) ?>" class="btn btn--primary install-cta">
    <?= e(t('install.done.sign_in')) ?>
  </a>
</div>
