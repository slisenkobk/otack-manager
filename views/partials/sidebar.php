<?php
$isAdminSb   = !empty($user['role']) && $user['role'] === 'admin';
$isManagerSb = !empty($user['role']) && $user['role'] === 'manager';
$canFormsSb  = $isAdminSb || $isManagerSb;
// Sidebar Projects badge: only projects awaiting review (more useful than the
// total — the total is already on the /projects page itself).
$projectsCount = \App\App::make('projects')->countAllForUser((int)($user['id'] ?? 0), $isAdminSb, 'under_review');
$newSubmissionsCount = 0;
if ($canFormsSb) {
    // Conversion to a task/project is just routing — admin still needs to
    // mark the submission as done or rejected for it to drop off the badge.
    $newSubmissionsCount = \App\App::make('form_submissions')->countUnhandled();
}
?>
<aside class="sidebar">
  <button type="button" class="sidebar__close" data-mobile-nav-close aria-label="<?= e(t('common.close')) ?>">
    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
  </button>
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
      <?php if ($projectsCount > 0): ?>
        <span class="nav-item__count"><?= (int)$projectsCount ?></span>
      <?php endif; ?>
    </a>
    <?php if ($canFormsSb):
      // Integrations submenu wraps forms/forms-data/polls/links. Auto-open when
      // the active page lives inside the group; otherwise honor the localStorage
      // flag (the JS flips an attribute on the wrapper at load time).
      $integrationsActiveNavs = ['forms', 'forms-data', 'polls', 'links'];
      $integrationsIsOpen = in_array(($activeNav ?? ''), $integrationsActiveNavs, true);
    ?>
      <div class="nav-group nav-group--collapsible<?= $integrationsIsOpen ? ' is-open' : '' ?>"
           data-nav-group="integrations">
        <button type="button" class="nav-group__toggle" aria-expanded="<?= $integrationsIsOpen ? 'true' : 'false' ?>">
          <span class="nav-item__marker"><i class="fa-solid fa-diagram-project"></i></span>
          <span class="nav-group__toggle-label"><?= e(t('nav.integrations')) ?></span>
          <span class="nav-group__toggle-meta">
            <?php if ($newSubmissionsCount > 0): ?>
              <span class="nav-item__count nav-item__count--accent nav-group__rollup-count"><?= $newSubmissionsCount ?></span>
            <?php endif; ?>
            <i class="fa-solid fa-chevron-down nav-group__chevron"></i>
          </span>
        </button>
        <div class="nav-subgroup">
          <a class="nav-item<?= ($activeNav ?? '') === 'forms' ? ' nav-item--active' : '' ?>" href="/forms">
            <span class="nav-item__marker"><i class="fa-solid fa-clipboard-list"></i></span>
            <span><?= e(t('nav.forms')) ?></span>
            <span></span>
          </a>
          <a class="nav-item<?= ($activeNav ?? '') === 'forms-data' ? ' nav-item--active' : '' ?>" href="/forms-data">
            <span class="nav-item__marker"><i class="fa-solid fa-inbox"></i></span>
            <span><?= e(t('nav.forms_data')) ?></span>
            <?php if ($newSubmissionsCount > 0): ?>
              <span class="nav-item__count nav-item__count--accent"><?= (int)$newSubmissionsCount ?></span>
            <?php endif; ?>
          </a>
          <a class="nav-item<?= ($activeNav ?? '') === 'polls' ? ' nav-item--active' : '' ?>" href="/polls">
            <span class="nav-item__marker"><i class="fa-solid fa-square-poll-vertical"></i></span>
            <span><?= e(t('nav.polls')) ?></span>
            <span></span>
          </a>
          <a class="nav-item<?= ($activeNav ?? '') === 'links' ? ' nav-item--active' : '' ?>" href="/links">
            <span class="nav-item__marker"><i class="fa-solid fa-link"></i></span>
            <span><?= e(t('nav.links')) ?></span>
            <span></span>
          </a>
        </div>
      </div>
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
    <span class="sidebar__foot-version">v<?= e(\App\Service\Updater::currentVersion()) ?></span>
  </div>
</aside>
