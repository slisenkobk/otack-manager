<header class="topbar">
  <button type="button" class="topbar__nav-toggle" data-mobile-nav-toggle aria-label="<?= e(t('nav.menu')) ?>">
    <i class="fa-solid fa-bars" aria-hidden="true"></i>
  </button>
  <h1 class="topbar__title">
    <?php if (!empty($crumbNum)): ?>
      <span class="topbar__title-num"><?= e($crumbNum) ?></span>
    <?php endif; ?>
    <span><?= e($crumb ?? t('nav.dashboard')) ?></span>
    <?php if (!empty($crumbExtra)): ?>
      <span class="topbar__title-extra"><?= $crumbExtra ?></span>
    <?php endif; ?>
  </h1>
  <div class="topbar__rhs">
    <?php if (!empty($user['name'])): ?>
      <div class="user-menu" data-user-menu>
        <button type="button" class="topbar__user" data-user-menu-toggle
                aria-haspopup="menu" aria-expanded="false" aria-label="<?= e(t('nav.profile')) ?>">
          <span class="topbar__user-name"><?= e($user['name']) ?></span>
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
            <i class="fa-solid fa-user" aria-hidden="true"></i>
            <span><?= e(t('nav.profile')) ?></span>
          </a>
          <?php $themeCurrent = $_COOKIE['theme'] ?? 'auto'; ?>
          <div class="user-menu__theme" role="group" aria-label="<?= e(t('theme.label')) ?>">
            <span class="user-menu__theme-label"><?= e(t('theme.label')) ?></span>
            <div class="theme-toggle" data-theme-toggle>
              <button type="button" data-theme-set="light" class="theme-toggle__btn<?= $themeCurrent === 'light' ? ' is-active' : '' ?>" aria-label="<?= e(t('theme.light')) ?>">
                <i class="fa-solid fa-sun" aria-hidden="true"></i>
              </button>
              <button type="button" data-theme-set="auto" class="theme-toggle__btn<?= $themeCurrent === 'auto' ? ' is-active' : '' ?>" aria-label="<?= e(t('theme.auto')) ?>">
                <i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i>
              </button>
              <button type="button" data-theme-set="dark" class="theme-toggle__btn<?= $themeCurrent === 'dark' ? ' is-active' : '' ?>" aria-label="<?= e(t('theme.dark')) ?>">
                <i class="fa-solid fa-moon" aria-hidden="true"></i>
              </button>
            </div>
          </div>
          <form method="post" action="/logout" class="user-menu__item user-menu__item--form" role="none">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken ?? '') ?>">
            <button type="submit" role="menuitem">
              <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
              <span><?= e(t('nav.logout')) ?></span>
            </button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</header>
