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
  <?= $tab_link('board',    t('projects.tab.board')    . (!empty($boardOpenCount) ? ' · ' . (int)$boardOpenCount : '')) ?>
  <?= $tab_link('backlog',  t('projects.tab.backlog')  . (!empty($backlogCount) ? ' · ' . (int)$backlogCount : '')) ?>
  <?= $tab_link('overview', t('projects.tab.overview')) ?>
</div>
