<?php
// Inputs: $currentTab ∈ {migrations, cache, stats, logs}
$tabs = [
    'migrations' => ['label' => t('compass.tab.migrations'), 'href' => '/admin/compass/migrations', 'icon' => 'fa-database'],
    'cache'      => ['label' => t('compass.tab.cache'),      'href' => '/admin/compass/cache',      'icon' => 'fa-broom'],
    'stats'      => ['label' => t('compass.tab.stats'),      'href' => '/admin/compass/db-stats',   'icon' => 'fa-chart-simple'],
    'logs'       => ['label' => t('compass.tab.logs'),       'href' => '/admin/compass/logs',       'icon' => 'fa-file-lines'],
];
?>
<div class="project-tabs" style="margin-bottom:var(--space-8);">
    <?php foreach ($tabs as $key => $tab): ?>
      <a href="<?= e($tab['href']) ?>"
         class="project-tab<?= $currentTab === $key ? ' project-tab--active' : '' ?>">
        <i class="fa-solid <?= e($tab['icon']) ?>" aria-hidden="true" style="margin-right:var(--space-2);"></i>
        <?= e($tab['label']) ?>
      </a>
    <?php endforeach; ?>
</div>
