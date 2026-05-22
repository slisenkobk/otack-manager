<aside class="sidebar">
  <a class="brand" href="/" aria-label="Otack Manager">
    <span class="brand__mark" aria-hidden="true">
      <?php include APP_ROOT . '/public/assets/img/logo.svg'; ?>
    </span>
    <span class="brand__word">
      <span class="brand__word-a">Otack</span>
      <span class="brand__word-b">Manager</span>
    </span>
  </a>

  <div class="nav-group">
    <p class="nav-group__label">Workspace</p>
    <a class="nav-item<?= ($activeNav ?? '') === 'dashboard' ? ' nav-item--active' : '' ?>" href="/">
      <span class="nav-item__marker">01</span>
      <span>Dashboard</span>
      <span></span>
    </a>
    <a class="nav-item<?= ($activeNav ?? '') === 'projects' ? ' nav-item--active' : '' ?>" href="/projects">
      <span class="nav-item__marker">02</span>
      <span>Projects</span>
      <span></span>
    </a>
    <?php if (!empty($user['role']) && $user['role'] === 'admin'): ?>
      <a class="nav-item<?= ($activeNav ?? '') === 'users' ? ' nav-item--active' : '' ?>" href="/users">
        <span class="nav-item__marker">03</span>
        <span>Users</span>
        <span></span>
      </a>
      <a class="nav-item<?= ($activeNav ?? '') === 'tags' ? ' nav-item--active' : '' ?>" href="/admin/tags">
        <span class="nav-item__marker">04</span>
        <span>Tags</span>
        <span></span>
      </a>
    <?php endif; ?>
  </div>

  <div class="nav-divider"></div>

  <div class="nav-group">
    <p class="nav-group__label">Account</p>
    <a class="nav-item<?= ($activeNav ?? '') === 'profile' ? ' nav-item--active' : '' ?>" href="/profile">
      <span class="nav-item__marker">&#8999;</span>
      <span>Profile</span>
      <span></span>
    </a>
    <form method="post" action="/logout" style="margin:0;">
      <input type="hidden" name="_csrf" value="<?= e($csrfToken ?? '') ?>">
      <button type="submit" class="nav-item" style="background:none;border:none;text-align:left;width:100%;cursor:pointer;font:inherit;">
        <span class="nav-item__marker">&#8599;</span>
        <span>Logout</span>
        <span></span>
      </button>
    </form>
  </div>
</aside>
