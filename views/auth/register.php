<?= !empty($error) ? flash_meta($error, 'error') : '' ?>
<div class="brief">
  <h1 style="font-size:32px;font-weight:700;margin:0 0 24px;">Create account</h1>
  <form method="post" action="/register">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
    <div class="field">
      <label>Name</label>
      <input class="input" type="text" name="name" required autofocus>
    </div>
    <div class="field" style="margin-top:14px;">
      <label>Email</label>
      <input class="input" type="email" name="email" required>
    </div>
    <div class="field" style="margin-top:14px;">
      <label>Password (min 8 chars)</label>
      <input class="input" type="password" name="password" minlength="8" required>
    </div>
    <button class="submit" type="submit" style="margin-top:22px;width:100%;">Create account &rarr;</button>
  </form>
  <p style="margin-top:18px;font-size:13px;color:var(--ink-2);">
    Already registered? <a href="/login" style="color:var(--brand);text-decoration:underline;">Sign in</a>
  </p>
</div>
