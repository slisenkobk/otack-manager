<?php
// Inputs: $config (associative array), $driver, $saved, $flash, $csrfToken
// Five sections (panels): database (read-only summary), auth (login hash + APP_SECRET),
// urls (APP_URL + TRUSTED_PROXIES), telegram, updates. Each posts to
// /admin/compass/platform with a `section` discriminator.
?>
<?php include APP_ROOT . '/views/partials/compass-tabs.php'; ?>
<div class="brief brief--wide">

  <?php if (!empty($saved)): ?>
    <div class="alert alert--success">
      <?= e(t('platform.flash.saved', ['section' => $saved])) ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($flash)): ?>
    <div class="alert alert--danger"><?= e($flash) ?></div>
  <?php endif; ?>

  <section class="compass-card">
    <h3 class="compass-card__title">
      <i class="fa-solid fa-database text-text-2" aria-hidden="true"></i>
      <?= e(t('platform.section.database')) ?>
    </h3>
    <p class="m-0 fz-14">
      <?= e(t('platform.database.current_driver')) ?>: <strong><?= e($driver) ?></strong>
    </p>
    <p class="muted fz-12 m-0"><?= e(t('platform.database.hint')) ?></p>
    <div>
      <a class="btn btn--secondary" href="/admin/compass/db-migrate">
        <?= e(t('platform.action.switch_to_mysql')) ?>
      </a>
    </div>
  </section>

  <section class="compass-card">
    <h3 class="compass-card__title">
      <i class="fa-solid fa-shield-halved text-text-2" aria-hidden="true"></i>
      <?= e(t('platform.section.auth')) ?>
    </h3>
    <form method="post" action="/admin/compass/platform">
      <?= csrf_field($csrfToken) ?>
      <input type="hidden" name="section" value="auth">
      <label class="checkbox-row">
        <input type="checkbox" name="enable_login_hash" value="1" <?= $config['LOGIN_HASH_SET'] ? 'checked' : '' ?>>
        <span>
          <strong><?= e(t('install.security.login_hash_label')) ?></strong>
          <small class="muted"><?= e(t('install.security.login_hash_hint')) ?></small>
        </span>
      </label>
      <?php if ($config['LOGIN_HASH_SET']): ?>
        <p class="muted fz-12 m-0">
          <?= e(t('platform.auth.login_hash_current')) ?>:
          <code data-login-hash-value><?= e($config['LOGIN_HASH_MASK']) ?></code>
        </p>
      <?php endif; ?>
      <div>
        <button type="submit" class="btn btn--primary" data-action="save-auth">
          <?= e(t('platform.action.save')) ?>
        </button>
      </div>
    </form>
    <form method="post" action="/admin/compass/platform" class="mt-12">
      <?= csrf_field($csrfToken) ?>
      <input type="hidden" name="section" value="rotate-app-secret">
      <p class="muted fz-12 m-0"><?= e(t('platform.warn.app_secret_rotation')) ?></p>
      <div>
        <button type="submit" class="btn btn--danger"
                data-action="rotate-app-secret"
                onclick="return confirm('<?= e(t('platform.confirm.rotate_secret')) ?>')">
          <?= e(t('platform.action.rotate_app_secret')) ?>
        </button>
      </div>
    </form>
  </section>

  <section class="compass-card">
    <h3 class="compass-card__title">
      <i class="fa-solid fa-link text-text-2" aria-hidden="true"></i>
      <?= e(t('platform.section.urls')) ?>
    </h3>
    <form method="post" action="/admin/compass/platform">
      <?= csrf_field($csrfToken) ?>
      <input type="hidden" name="section" value="urls">
      <label>
        <span><?= e(t('platform.urls.app_url')) ?></span>
        <input class="input" name="app_url" type="url" value="<?= e($config['APP_URL']) ?>">
      </label>
      <label>
        <span><?= e(t('platform.urls.trusted_proxies')) ?></span>
        <input class="input" name="trusted_proxies" value="<?= e($config['TRUSTED_PROXIES']) ?>">
        <small class="muted"><?= e(t('platform.urls.trusted_proxies_hint')) ?></small>
      </label>
      <div>
        <button type="submit" class="btn btn--primary" data-action="save-urls">
          <?= e(t('platform.action.save')) ?>
        </button>
      </div>
    </form>
  </section>

  <section class="compass-card">
    <h3 class="compass-card__title">
      <i class="fa-brands fa-telegram text-text-2" aria-hidden="true"></i>
      <?= e(t('platform.section.telegram')) ?>
    </h3>
    <form method="post" action="/admin/compass/platform">
      <?= csrf_field($csrfToken) ?>
      <input type="hidden" name="section" value="telegram">
      <label>
        <span><?= e(t('platform.telegram.bot_token')) ?></span>
        <input class="input" type="text" name="tg_bot_token"
               value="<?= e($config['TG_BOT_TOKEN_MASK']) ?>" autocomplete="off">
        <small class="muted"><?= e(t('platform.telegram.bot_token_hint')) ?></small>
      </label>
      <label>
        <span><?= e(t('platform.telegram.chat_id')) ?></span>
        <input class="input" name="tg_chat_id" value="<?= e($config['TG_CHAT_ID']) ?>" pattern="-?\d*">
      </label>
      <div>
        <button type="submit" class="btn btn--primary" data-action="save-telegram">
          <?= e(t('platform.action.save')) ?>
        </button>
      </div>
    </form>
  </section>

  <section class="compass-card">
    <h3 class="compass-card__title">
      <i class="fa-solid fa-cloud-arrow-down text-text-2" aria-hidden="true"></i>
      <?= e(t('platform.section.updates')) ?>
    </h3>
    <form method="post" action="/admin/compass/platform">
      <?= csrf_field($csrfToken) ?>
      <input type="hidden" name="section" value="updates">
      <label class="checkbox-row">
        <input type="checkbox" name="update_enabled" value="1" <?= $config['UPDATE_ENABLED'] ? 'checked' : '' ?>>
        <span><?= e(t('platform.updates.enabled')) ?></span>
      </label>
      <label>
        <span><?= e(t('platform.updates.check_interval')) ?></span>
        <input class="input" type="number" name="update_check_interval"
               value="<?= (int)$config['UPDATE_CHECK_INTERVAL'] ?>" min="0">
      </label>
      <label>
        <span><?= e(t('platform.updates.backup_keep')) ?></span>
        <input class="input" type="number" name="update_backup_keep"
               value="<?= (int)$config['UPDATE_BACKUP_KEEP'] ?>" min="0">
      </label>
      <div>
        <button type="submit" class="btn btn--primary" data-action="save-updates">
          <?= e(t('platform.action.save')) ?>
        </button>
      </div>
    </form>
  </section>

</div>
