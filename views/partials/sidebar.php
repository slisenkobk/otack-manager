<?php
$isAdminSb   = !empty($user['role']) && $user['role'] === 'admin';
$isManagerSb = !empty($user['role']) && $user['role'] === 'manager';
$canFormsSb  = $isAdminSb || $isManagerSb;
// Sidebar Projects badge: only projects awaiting review (more useful than the
// total — the total is already on the /projects page itself).
$projectsCount = \App\App::make('projects')->countAllForUser((int)($user['id'] ?? 0), $isAdminSb, 'under_review');
$newSubmissionsCount = 0;
if ($canFormsSb) {
    $byStatus = \App\App::make('form_submissions')->countByStatus();
    $newSubmissionsCount = (int)($byStatus['new'] ?? 0);
}
?>
<aside class="sidebar">
  <?php [$brandA, $brandB] = app_name_parts(); ?>
  <a class="brand" href="/" aria-label="<?= e(app_name()) ?>">
    <span class="brand__mark" aria-hidden="true">
      <?php include APP_ROOT . '/public/assets/img/logo.svg'; ?>
    </span>
    <span class="brand__word">
      <span class="brand__word-a"><?= e($brandA) ?></span>
      <?php if ($brandB !== ''): ?>
        <span class="brand__word-b"><?= e($brandB) ?></span>
      <?php endif; ?>
    </span>
  </a>

  <div class="nav-group">
    <p class="nav-group__label"><?= e(t('nav.workspace')) ?></p>
    <a class="nav-item<?= ($activeNav ?? '') === 'dashboard' ? ' nav-item--active' : '' ?>" href="/">
      <span class="nav-item__marker"><i class="fa-solid fa-house"></i></span>
      <span><?= e(t('nav.dashboard')) ?></span>
      <span></span>
    </a>
    <a class="nav-item<?= ($activeNav ?? '') === 'projects' ? ' nav-item--active' : '' ?>" href="/projects">
      <span class="nav-item__marker"><i class="fa-solid fa-folder"></i></span>
      <span><?= e(t('nav.projects')) ?></span>
      <span class="nav-item__count"><?= (int)$projectsCount ?></span>
    </a>
    <?php if ($canFormsSb): ?>
      <a class="nav-item<?= ($activeNav ?? '') === 'forms' ? ' nav-item--active' : '' ?>" href="/forms">
        <span class="nav-item__marker"><i class="fa-solid fa-clipboard-list"></i></span>
        <span><?= e(t('nav.forms')) ?></span>
        <span></span>
      </a>
      <a class="nav-item<?= ($activeNav ?? '') === 'forms-data' ? ' nav-item--active' : '' ?>" href="/forms-data">
        <span class="nav-item__marker"><i class="fa-solid fa-inbox"></i></span>
        <span><?= e(t('nav.forms_data')) ?></span>
        <span class="nav-item__count<?= $newSubmissionsCount > 0 ? ' nav-item__count--accent' : '' ?>"><?= $newSubmissionsCount ?></span>
      </a>
    <?php endif; ?>
    <?php if ($isAdminSb): ?>
      <a class="nav-item<?= ($activeNav ?? '') === 'users' ? ' nav-item--active' : '' ?>" href="/users">
        <span class="nav-item__marker"><i class="fa-solid fa-users"></i></span>
        <span><?= e(t('nav.users')) ?></span>
        <span></span>
      </a>
      <a class="nav-item<?= ($activeNav ?? '') === 'tags' ? ' nav-item--active' : '' ?>" href="/admin/tags">
        <span class="nav-item__marker"><i class="fa-solid fa-tags"></i></span>
        <span><?= e(t('nav.tags')) ?></span>
        <span></span>
      </a>
      <a class="nav-item<?= ($activeNav ?? '') === 'settings' ? ' nav-item--active' : '' ?>" href="/admin/settings">
        <span class="nav-item__marker"><i class="fa-solid fa-gear"></i></span>
        <span><?= e(t('nav.settings')) ?></span>
        <span></span>
      </a>
      <a class="nav-item<?= ($activeNav ?? '') === 'compass' ? ' nav-item--active' : '' ?>" href="/admin/compass">
        <span class="nav-item__marker"><i class="fa-solid fa-compass"></i></span>
        <span><?= e(t('nav.compass')) ?></span>
        <span></span>
      </a>
    <?php endif; ?>
  </div>

  <?php
  $brandLink = '<a class="sidebar__foot-link" href="https://otack.eu" target="_blank" rel="noopener noreferrer">' . e(app_name()) . '</a>';
  $goupLink  = '<a class="sidebar__foot-link" href="https://goupspace.eu" target="_blank" rel="noopener noreferrer">Goup Space</a>';
  ?>
  <div class="sidebar__foot">
    <?= t('nav.ecosystem_line', ['link' => $brandLink]) ?><br>
    <span class="sidebar__foot-sub">
      <?= t('nav.ecosystem_by', ['link' => $goupLink]) ?>
    </span>
  </div>
</aside>
