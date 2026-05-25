<?php if (!empty($success)): ?>
  <div class="toast toast--success" style="position:static;margin-bottom:16px;"><?= e($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="toast toast--error" style="position:static;margin-bottom:16px;"><?= e($error) ?></div>
<?php endif; ?>

<div style="display:flex;flex-direction:column;gap:24px;max-width:540px;">

  <div class="brief">
    <h2 style="font-size:20px;font-weight:600;margin:0 0 16px;">Name</h2>
    <form method="post" action="/profile">
      <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
      <div class="field">
        <label>Display name</label>
        <input class="input" type="text" name="name" value="<?= e($user['name']) ?>" required>
      </div>
      <div class="field" style="margin-top:14px;">
        <label>Email</label>
        <input class="input" type="email" value="<?= e($user['email']) ?>" readonly disabled style="opacity:.6;">
      </div>
      <button class="submit" type="submit" style="margin-top:18px;">Save name →</button>
    </form>
  </div>

  <div class="brief">
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
