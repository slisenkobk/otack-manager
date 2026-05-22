<div class="brief">
  <h1 style="font-size:32px;font-weight:700;margin:0 0 24px;">Sign in</h1>
  <?php if (!empty($error)): ?>
    <div class="toast toast--error" style="position:static;margin-bottom:16px;"><?= e($error) ?></div>
  <?php endif; ?>
  <form method="post" action="/login">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
    <div class="field">
      <label>Email</label>
      <input class="input" type="email" name="email" required autofocus>
    </div>
    <div class="field" style="margin-top:14px;">
      <label>Password</label>
      <input class="input" type="password" name="password" required>
    </div>
    <button class="submit" type="submit" style="margin-top:22px;width:100%;">Sign in &rarr;</button>
  </form>
  <p style="margin-top:18px;font-size:13px;color:var(--ink-2);">
    New here? <a href="/register" style="color:var(--brand);text-decoration:underline;">Create an account</a>
  </p>
</div>
