<header class="topbar">
  <div class="topbar__lhs">
    <span class="topbar__seal">Otack Manager</span>
    <?php if (!empty($crumb)): ?>
      <span class="topbar__crumb">&middot; <?= e($crumb) ?></span>
    <?php endif; ?>
  </div>
  <div class="topbar__rhs">
    <?php if (!empty($user['name'])): ?>
      <a class="topbar__avatar avatar" href="/profile" title="Open profile" aria-label="Open profile">
        <?= e(mb_substr($user['name'], 0, 1)) ?>
      </a>
    <?php endif; ?>
  </div>
</header>
