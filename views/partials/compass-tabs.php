<?php
// Inputs: $currentTab ∈ {migrations, cache, stats, logs}
$tabs = [
    'migrations' => ['label' => 'Migrations', 'href' => '/admin/compass/migrations', 'icon' => 'fa-database'],
    'cache'      => ['label' => 'Cache',      'href' => '/admin/compass/cache',      'icon' => 'fa-broom'],
    'stats'      => ['label' => 'DB stats',   'href' => '/admin/compass/db-stats',   'icon' => 'fa-chart-simple'],
    'logs'       => ['label' => 'Logs',       'href' => '/admin/compass/logs',       'icon' => 'fa-file-lines'],
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
