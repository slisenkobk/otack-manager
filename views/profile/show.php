<?php
echo !empty($success) ? flash_meta($success, 'success') : '';
echo !empty($error)   ? flash_meta($error,   'error')   : '';
$displayNames  = locale_display_names();
$currentLocale = $user['locale'] ?? 'en';
?>

<form method="post" action="/profile" enctype="multipart/form-data" class="profile-form" data-profile-form>
  <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

  <section class="brief brief--wide profile-card">
    <h2 class="profile-card__title"><?= e(t('profile.section.account')) ?></h2>
    <div class="profile-card__body profile-card__body--personal">

      <div class="profile-avatar">
        <?= user_avatar_html((int)$user['id'], $user['name'], $user['avatar'] ?? null, 'lg', ['extra_class' => 'profile-avatar__pic']) ?>
        <div class="profile-avatar__controls">
          <label class="btn--secondary profile-avatar__upload">
            <i class="fa-solid fa-upload"></i> <?= e(t('profile.upload_image')) ?>
            <input type="file" name="avatar" accept="image/png,image/jpeg,image/gif,image/webp" hidden data-avatar-input>
          </label>
          <?php if (!empty($user['avatar'])): ?>
            <button class="btn--ghost profile-avatar__remove" type="button" data-remove-avatar>
              <?= e(t('profile.remove_avatar')) ?>
            </button>
          <?php endif; ?>
          <p class="muted profile-avatar__hint"><?= e(t('profile.avatar_hint')) ?></p>
        </div>
      </div>

      <div class="form-grid form-grid--2 profile-card__fields">
        <div class="field">
          <label for="f-profile-name"><?= e(t('field.display_name')) ?></label>
          <input id="f-profile-name" class="input" type="text" name="name" value="<?= e($user['name']) ?>" required>
        </div>
        <div class="field">
          <label for="f-profile-email"><?= e(t('field.email')) ?></label>
          <input id="f-profile-email" class="input" type="email" name="email" value="<?= e($user['email']) ?>" required>
        </div>
        <div class="field form-grid__span">
          <label><?= e(t('profile.language_label')) ?></label>
          <div class="custom-select" data-custom-select>
            <button type="button" class="custom-select__btn">
              <span class="custom-select__label"><?= e($displayNames[$currentLocale] ?? $currentLocale) ?></span>
              <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
            </button>
            <div class="custom-select__pop" hidden>
              <div class="custom-select__opts">
                <?php foreach (available_locales() as $code): ?>
                  <div class="custom-select__opt<?= $code === $currentLocale ? ' is-selected' : '' ?>" data-value="<?= e($code) ?>">
                    <span class="custom-select__opt-label"><?= e($displayNames[$code] ?? $code) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <input type="hidden" name="locale" value="<?= e($currentLocale) ?>">
          </div>
        </div>
      </div>

    </div>
  </section>

  <section class="brief brief--wide profile-card">
    <h2 class="profile-card__title"><?= e(t('profile.section.password')) ?></h2>
    <p class="muted profile-card__hint"><?= e(t('profile.password_hint')) ?></p>
    <div class="form-grid form-grid--3 profile-card__fields">
      <div class="field">
        <label for="f-current-password"><?= e(t('field.current_password')) ?></label>
        <?php // autocomplete="new-password" everywhere stops Chrome/Safari from
              // autofilling the stored login password into the current-password
              // field on this profile page — users open settings and see dots
              // they did not type, which is alarming. The user types it manually. ?>
        <input id="f-current-password" class="input" type="password" name="current_password" autocomplete="new-password">
      </div>
      <div class="field">
        <label for="f-new-password"><?= e(t('field.new_password')) ?></label>
        <input id="f-new-password" class="input" type="password" name="new_password" minlength="8" autocomplete="new-password">
      </div>
      <div class="field">
        <label for="f-confirm-password"><?= e(t('field.confirm_password')) ?></label>
        <input id="f-confirm-password" class="input" type="password" name="confirm_password" minlength="8" autocomplete="new-password">
      </div>
    </div>
  </section>

  <div class="profile-form__actions">
    <button class="btn btn--primary submit" type="submit"><?= e(t('profile.save')) ?></button>
    <p class="muted profile-form__note"><?= e(t('profile.notifications_note')) ?></p>
  </div>
</form>

<section class="brief brief--wide profile-card">
  <h2 class="profile-card__title"><?= e(t('api_tokens.title')) ?></h2>
  <p class="muted profile-card__hint"><?= e(t('api_tokens.profile_link_hint')) ?></p>
  <p class="u-mt-space-6">
    <a class="btn btn--secondary" href="/profile/tokens">
      <i class="fa-solid fa-key"></i> <?= e(t('api_tokens.manage')) ?>
    </a>
  </p>
</section>

<?php if (!empty($user['avatar'])): ?>
<form method="post" action="/profile/avatar/delete" data-remove-avatar-form hidden>
  <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
</form>
<?php endif; ?>

<script type="module" src="<?= e(asset_url('/assets/js/profile.js')) ?>"></script>
