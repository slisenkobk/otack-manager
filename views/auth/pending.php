<div class="brief" style="text-align:center;">
  <i class="fa-solid fa-hourglass-half" style="font-size:48px;color:var(--brand);margin-bottom:18px;"></i>
  <h1 style="font-size:24px;font-weight:600;margin:0 0 12px;">Awaiting approval</h1>
  <p style="color:var(--ink-2);font-size:15px;line-height:1.55;">
    Your account is awaiting admin approval. You'll be able to sign in once an admin approves you.
  </p>
  <form method="post" action="/logout" style="margin-top:24px;">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken ?? '') ?>">
    <button class="btn-ghost" type="submit">Sign out</button>
  </form>
</div>
