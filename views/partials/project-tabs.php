<?php
// Inputs: $project, $currentTab ('board'|'backlog'|'overview'), $boardOpenCount, $backlogCount
$pid = (int)$project['id'];
$tab_link = function (string $key, string $label) use ($pid, $currentTab) {
    $active = $currentTab === $key;
    return sprintf(
        '<a href="/projects/%d?tab=%s" class="project-tab%s">%s</a>',
        $pid, $key, $active ? ' project-tab--active' : '', htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
    );
};
?>
<div class="project-tabs">
  <?= $tab_link('board',    'Board' . (!empty($boardOpenCount) ? ' · ' . (int)$boardOpenCount : '')) ?>
  <?= $tab_link('backlog',  'Backlog' . (!empty($backlogCount) ? ' · ' . (int)$backlogCount : '')) ?>
  <?= $tab_link('overview', 'Overview') ?>
</div>
