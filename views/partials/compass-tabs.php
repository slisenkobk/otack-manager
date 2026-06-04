<?php
// Inputs: $currentTab ∈ {migrations, cache, stats, logs, db-migrate, platform}
$tabs = [
    'migrations' => ['label' => t('compass.tab.migrations'), 'href' => '/admin/compass/migrations', 'icon' => 'fa-database'],
    'cache'      => ['label' => t('compass.tab.cache'),      'href' => '/admin/compass/cache',      'icon' => 'fa-broom'],
    'stats'      => ['label' => t('compass.tab.stats'),      'href' => '/admin/compass/db-stats',   'icon' => 'fa-chart-simple'],
    'logs'       => ['label' => t('compass.tab.logs'),       'href' => '/admin/compass/logs',       'icon' => 'fa-file-lines'],
    'db-migrate' => ['label' => t('compass.tab.db_migrate'), 'href' => '/admin/compass/db-migrate', 'icon' => 'fa-right-left'],
    'platform'   => ['label' => t('compass.tab.platform'),   'href' => '/admin/compass/platform',   'icon' => 'fa-sliders'],
];
?>
<div class="page-head">
  <div class="project-tabs">
    <?php foreach ($tabs as $key => $tab): ?>
      <a href="<?= e($tab['href']) ?>"
         class="project-tab<?= $currentTab === $key ? ' project-tab--active' : '' ?>">
        <i class="fa-solid <?= e($tab['icon']) ?> mr-space-2" aria-hidden="true"></i>
        <?= e($tab['label']) ?>
      </a>
    <?php endforeach; ?>
  </div>
  <a href="/admin/settings" class="btn btn--secondary btn--sm page-head__btn">
    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
    <?= e(t('compass.back_to_settings')) ?>
  </a>
</div>
