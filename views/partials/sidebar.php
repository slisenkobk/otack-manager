<?php
$isAdminSb = !empty($user['role']) && $user['role'] === 'admin';
$projectsCount = \App\App::make('projects')->countAllForUser((int)($user['id'] ?? 0), $isAdminSb);
?>
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
      <span class="nav-item__marker"><i class="fa-solid fa-house"></i></span>
      <span>Dashboard</span>
      <span></span>
    </a>
    <a class="nav-item<?= ($activeNav ?? '') === 'projects' ? ' nav-item--active' : '' ?>" href="/projects">
      <span class="nav-item__marker"><i class="fa-solid fa-folder"></i></span>
      <span>Projects</span>
      <span class="nav-item__count"><?= (int)$projectsCount ?></span>
    </a>
    <?php if ($isAdminSb): ?>
      <a class="nav-item<?= ($activeNav ?? '') === 'users' ? ' nav-item--active' : '' ?>" href="/users">
        <span class="nav-item__marker"><i class="fa-solid fa-users"></i></span>
        <span>Users</span>
        <span></span>
      </a>
      <a class="nav-item<?= ($activeNav ?? '') === 'tags' ? ' nav-item--active' : '' ?>" href="/admin/tags">
        <span class="nav-item__marker"><i class="fa-solid fa-tags"></i></span>
        <span>Tags</span>
        <span></span>
      </a>
    <?php endif; ?>
  </div>

  <div class="sidebar__foot">
    Otack Manager Ecosystem<br>
    <span class="sidebar__foot-sub">By Goup Space</span>
  </div>
</aside>
