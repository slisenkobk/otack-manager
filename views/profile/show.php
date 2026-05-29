<?php
echo !empty($success) ? flash_meta($success, 'success') : '';
echo !empty($error)   ? flash_meta($error,   'error')   : '';
$displayNames = locale_display_names();
$currentLocale = $user['locale'] ?? 'en';
?>

<div style="display:flex;flex-direction:column;gap:24px;">

  <div class="brief" style="max-width:none;">
    <h2 style="font-size:20px;font-weight:600;margin:0 0 16px;"><?= e(t('profile.section.avatar')) ?></h2>
    <div style="display:flex;align-items:center;gap:20px;">
      <?= user_avatar_html((int)$user['id'], $user['name'], $user['avatar'] ?? null, 'lg') ?>
      <form method="post" action="/profile/avatar" enctype="multipart/form-data" style="display:flex;align-items:center;gap:10px;" data-avatar-form>
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
        <label class="btn-secondary" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
          <i class="fa-solid fa-upload"></i> <?= e(t('profile.upload_image')) ?>
          <input type="file" name="avatar" accept="image/png,image/jpeg,image/gif,image/webp" hidden data-avatar-input>
        </label>
        <?php if (!empty($user['avatar'])): ?>
          <button class="btn-ghost" formaction="/profile/avatar/delete" type="submit" style="font-size:12px;color:var(--ink-3);"><?= e(t('profile.remove_avatar')) ?></button>
        <?php endif; ?>
      </form>
      <script type="module" src="<?= e(asset_url('/assets/js/profile.js')) ?>"></script>
    </div>
    <p class="muted" style="font-size:12px;color:var(--ink-3);margin:10px 0 0;"><?= e(t('profile.avatar_hint')) ?></p>
  </div>

  <div class="brief" style="max-width:none;">
    <h2 style="font-size:20px;font-weight:600;margin:0 0 16px;"><?= e(t('profile.section.account')) ?></h2>
    <form method="post" action="/profile">
      <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
      <div class="field">
        <label><?= e(t('field.display_name')) ?></label>
        <input class="input" type="text" name="name" value="<?= e($user['name']) ?>" required>
      </div>
      <div class="field" style="margin-top:14px;">
        <label><?= e(t('field.email')) ?></label>
        <input class="input" type="email" name="email" value="<?= e($user['email']) ?>" required>
      </div>
      <button class="submit" type="submit" style="margin-top:18px;"><?= e(t('profile.save')) ?></button>
    </form>
  </div>

  <div class="brief" style="max-width:none;">
    <h2 style="font-size:20px;font-weight:600;margin:0 0 16px;"><?= e(t('profile.section.language')) ?></h2>
    <form method="post" action="/profile/locale">
      <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
      <div class="field">
        <label><?= e(t('profile.language_label')) ?></label>
        <select class="input" name="locale">
          <?php foreach (available_locales() as $code): ?>
            <option value="<?= e($code) ?>"<?= $code === $currentLocale ? ' selected' : '' ?>>
              <?= e($displayNames[$code] ?? $code) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="muted" style="font-size:12px;color:var(--ink-3);margin:10px 0 16px;">
        <?= e(t('profile.language_hint')) ?>
      </p>
      <button class="submit" type="submit"><?= e(t('common.save')) ?></button>
    </form>
  </div>

  <div class="brief" style="max-width:none;">
    <h2 style="font-size:20px;font-weight:600;margin:0 0 16px;"><?= e(t('profile.section.password')) ?></h2>
    <form method="post" action="/profile/password">
      <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
      <div class="field">
        <label><?= e(t('field.current_password')) ?></label>
        <input class="input" type="password" name="current" required>
      </div>
      <div class="field" style="margin-top:14px;">
        <label><?= e(t('field.new_password')) ?></label>
        <input class="input" type="password" name="new" minlength="8" required>
      </div>
      <div class="field" style="margin-top:14px;">
        <label><?= e(t('field.confirm_password')) ?></label>
        <input class="input" type="password" name="confirm" minlength="8" required>
      </div>
      <button class="submit" type="submit" style="margin-top:18px;"><?= e(t('profile.change_password')) ?></button>
    </form>
  </div>

  <p class="muted" style="font-size:13px;color:var(--ink-3);">
    <?= e(t('profile.notifications_note')) ?>
  </p>
</div>
