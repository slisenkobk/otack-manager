<?php $flash = $flash ?? null; ?>
<div class="install-card">
  <h2 class="install-heading"><?= e(t('install.db.heading')) ?></h2>

  <?php if (!empty($flash)): ?>
    <div class="install-alert install-alert--error"><?= e($flash) ?></div>
  <?php endif; ?>

  <form method="post" action="/install/db" class="install-form install-db-form">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

    <fieldset class="install-fieldset">
      <legend class="install-fieldset__legend"><?= e(t('install.db.sqlite')) ?></legend>
      <p class="install-hint"><?= e(t('install.db.sqlite_hint')) ?></p>
      <button type="submit" name="driver" value="sqlite" data-driver="sqlite" class="btn btn--primary">
        <?= e(t('install.db.next')) ?>
      </button>
    </fieldset>

    <fieldset class="install-fieldset">
      <legend class="install-fieldset__legend"><?= e(t('install.db.mysql')) ?></legend>
      <p class="install-hint"><?= e(t('install.db.mysql_hint')) ?></p>
      <div class="install-grid-2">
        <div class="field">
          <label for="f-host"><?= e(t('install.db.host')) ?></label>
          <input id="f-host" class="input" name="host" value="127.0.0.1">
        </div>
        <div class="field">
          <label for="f-port"><?= e(t('install.db.port')) ?></label>
          <input id="f-port" class="input" name="port" type="number" value="3306">
        </div>
      </div>
      <div class="field mt-14">
        <label for="f-dbname"><?= e(t('install.db.dbname')) ?></label>
        <input id="f-dbname" class="input" name="dbname">
      </div>
      <div class="install-grid-2 mt-14">
        <div class="field">
          <label for="f-user"><?= e(t('install.db.user')) ?></label>
          <input id="f-user" class="input" name="user">
        </div>
        <div class="field">
          <label for="f-password"><?= e(t('install.db.password')) ?></label>
          <input id="f-password" class="input" type="password" name="password" autocomplete="off">
        </div>
      </div>
      <button type="submit" name="driver" value="mysql" data-driver="mysql" class="btn btn--ghost mt-14">
        <?= e(t('install.db.test')) ?>
      </button>
    </fieldset>
  </form>
</div>
