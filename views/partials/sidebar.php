<aside class="sidebar">
  <a href="/" class="brand">
    <span class="mark">
      <svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg"><rect width="32" height="32" rx="6" fill="#C2410C"/></svg>
    </span>
    <span class="word">
      <span class="row">
        <span class="a">Otack</span>
        <span class="b">Tasks</span>
      </span>
    </span>
  </a>

  <div class="nav-group">
    <p class="nav-group-label">Workspace</p>
    <a class="nav-item<?= ($activeNav ?? '') === 'dashboard' ? ' active' : '' ?>" href="/">
      <span class="marker">01</span>
      <span>Dashboard</span>
      <span></span>
    </a>
    <a class="nav-item<?= ($activeNav ?? '') === 'projects' ? ' active' : '' ?>" href="/projects">
      <span class="marker">02</span>
      <span>Projects</span>
      <span></span>
    </a>
    <?php if (!empty($user['role']) && $user['role'] === 'admin'): ?>
      <a class="nav-item<?= ($activeNav ?? '') === 'users' ? ' active' : '' ?>" href="/users">
        <span class="marker">03</span>
        <span>Users</span>
        <span></span>
      </a>
      <a class="nav-item<?= ($activeNav ?? '') === 'tags' ? ' active' : '' ?>" href="/admin/tags">
        <span class="marker">04</span>
        <span>Tags</span>
        <span></span>
      </a>
    <?php endif; ?>
  </div>

  <div class="nav-divider"></div>

  <div class="nav-group">
    <p class="nav-group-label">Account</p>
    <a class="nav-item<?= ($activeNav ?? '') === 'profile' ? ' active' : '' ?>" href="/profile">
      <span class="marker">&#8999;</span>
      <span>Profile</span>
      <span></span>
    </a>
    <form method="post" action="/logout" style="margin:0;">
      <input type="hidden" name="_csrf" value="<?= e($csrfToken ?? '') ?>">
      <button type="submit" class="nav-item" style="background:none;border:none;text-align:left;width:100%;cursor:pointer;font:inherit;">
        <span class="marker">&#8599;</span>
        <span>Logout</span>
        <span></span>
      </button>
    </form>
  </div>
</aside>
