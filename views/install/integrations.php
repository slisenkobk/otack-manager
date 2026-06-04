<?php $flash = $flash ?? null; ?>
<div class="install-card">
  <h2 class="install-heading"><?= e(t('install.integrations.heading')) ?></h2>

  <?php if (!empty($flash)): ?>
    <div class="install-alert install-alert--error"><?= e($flash) ?></div>
  <?php endif; ?>

  <form method="post" action="/install/integrations" class="install-form">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

    <div class="field">
      <label for="f-tg-token"><?= e(t('install.integrations.tg_token')) ?></label>
      <input id="f-tg-token" class="input" type="password" name="tg_token" autocomplete="off">
    </div>
    <div class="field mt-14">
      <label for="f-tg-chat"><?= e(t('install.integrations.tg_chat_id')) ?></label>
      <input id="f-tg-chat" class="input" name="tg_chat_id" pattern="-?\d+">
    </div>

    <div class="install-actions mt-14">
      <button type="submit" name="action" value="save" class="btn btn--primary">
        <?= e(t('install.integrations.save')) ?>
      </button>
      <button type="submit" name="action" value="skip" data-action="skip-integrations" class="btn btn--ghost">
        <?= e(t('install.integrations.skip')) ?>
      </button>
    </div>
  </form>
</div>
