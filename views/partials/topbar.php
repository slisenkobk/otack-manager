<header class="topbar">
  <div class="lhs">
    <span class="seal">Otack Tasks</span>
    <?php if (!empty($crumb)): ?>
      <span>&middot; <?= e($crumb) ?></span>
    <?php endif; ?>
  </div>
  <div class="rhs">
    <?php if (!empty($user['name'])): ?>
      <div class="avatar"><?= e(mb_substr($user['name'], 0, 1)) ?></div>
    <?php endif; ?>
  </div>
</header>
