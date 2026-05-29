<?php
echo !empty($success) ? flash_meta($success, 'success') : '';
echo !empty($error)   ? flash_meta($error,   'error')   : '';
?>

<div style="display:flex;flex-direction:column;gap:24px;">

  <div class="brief" style="max-width:none;">
    <h2 style="font-size:20px;font-weight:600;margin:0 0 16px;">Avatar</h2>
    <div style="display:flex;align-items:center;gap:20px;">
      <?= user_avatar_html((int)$user['id'], $user['name'], $user['avatar'] ?? null, 'lg') ?>
      <form method="post" action="/profile/avatar" enctype="multipart/form-data" style="display:flex;align-items:center;gap:10px;" data-avatar-form>
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
        <label class="btn-secondary" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
          <i class="fa-solid fa-upload"></i> Upload image
          <input type="file" name="avatar" accept="image/png,image/jpeg,image/gif,image/webp" hidden data-avatar-input>
        </label>
        <?php if (!empty($user['avatar'])): ?>
          <button class="btn-ghost" formaction="/profile/avatar/delete" type="submit" style="font-size:12px;color:var(--ink-3);">Remove</button>
        <?php endif; ?>
      </form>
      <script type="module" src="/assets/js/profile.js"></script>
    </div>
    <p class="muted" style="font-size:12px;color:var(--ink-3);margin:10px 0 0;">PNG / JPG / GIF / WebP. Max 5 MB.</p>
  </div>

  <div class="brief" style="max-width:none;">
    <h2 style="font-size:20px;font-weight:600;margin:0 0 16px;">Account</h2>
    <form method="post" action="/profile">
      <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
      <div class="field">
        <label>Display name</label>
        <input class="input" type="text" name="name" value="<?= e($user['name']) ?>" required>
      </div>
      <div class="field" style="margin-top:14px;">
        <label>Email</label>
        <input class="input" type="email" name="email" value="<?= e($user['email']) ?>" required>
      </div>
      <button class="submit" type="submit" style="margin-top:18px;">Save profile →</button>
    </form>
  </div>

  <div class="brief" style="max-width:none;">
    <h2 style="font-size:20px;font-weight:600;margin:0 0 16px;">Password</h2>
    <form method="post" action="/profile/password">
      <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
      <div class="field">
        <label>Current password</label>
        <input class="input" type="password" name="current" required>
      </div>
      <div class="field" style="margin-top:14px;">
        <label>New password (min 8 chars)</label>
        <input class="input" type="password" name="new" minlength="8" required>
      </div>
      <div class="field" style="margin-top:14px;">
        <label>Confirm new password</label>
        <input class="input" type="password" name="confirm" minlength="8" required>
      </div>
      <button class="submit" type="submit" style="margin-top:18px;">Change password →</button>
    </form>
  </div>

  <p class="muted" style="font-size:13px;color:var(--ink-3);">
    Notifications go to a shared Telegram channel configured by admin.
  </p>
</div>
