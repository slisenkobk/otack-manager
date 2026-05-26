<header class="topbar">
  <h1 class="topbar__title">
    <?php if (!empty($crumbNum)): ?>
      <span class="topbar__title-num"><?= e($crumbNum) ?></span>
    <?php endif; ?>
    <span><?= e($crumb ?? 'Dashboard') ?></span>
    <?php if (!empty($crumbExtra)): ?>
      <span class="topbar__title-extra"><?= $crumbExtra ?></span>
    <?php endif; ?>
  </h1>
  <div class="topbar__rhs">
    <?php if (!empty($user['name'])): ?>
      <div class="user-menu" data-user-menu>
        <button type="button" class="topbar__avatar" data-user-menu-toggle
                aria-haspopup="menu" aria-expanded="false" aria-label="Open account menu">
          <?= user_avatar_html((int)($user['id'] ?? 0), $user['name'] ?? '', $user['avatar'] ?? null, 'md') ?>
        </button>
        <div class="user-menu__pop" role="menu" hidden>
          <div class="user-menu__head">
            <div class="user-menu__name"><?= e($user['name']) ?></div>
            <?php if (!empty($user['email'])): ?>
              <div class="user-menu__email"><?= e($user['email']) ?></div>
            <?php endif; ?>
          </div>
          <a class="user-menu__item" href="/profile" role="menuitem">
            <i class="fa-regular fa-user" aria-hidden="true"></i>
            <span>Profile</span>
          </a>
          <form method="post" action="/logout" class="user-menu__item user-menu__item--form" role="none">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken ?? '') ?>">
            <button type="submit" role="menuitem">
              <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
              <span>Logout</span>
            </button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</header>
