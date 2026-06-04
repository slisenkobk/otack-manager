<div class="dashboard-page">

<section class="hero mb-16">
  <h1 class="display"><?= t('dashboard.greeting', ['name' => '<span class="dashboard-greeting-name">' . e($user['name']) . '</span>']) ?></h1>
  <p class="mono dashboard-summary-line">
    <?= e(t('dashboard.summary_line', [
      'projects' => (int)$stats['open_projects'],
      'tasks'    => (int)$stats['my_tasks'],
      'events'   => (int)$stats['activity'],
    ])) ?>
  </p>
</section>

<?php
/**
 * Build a 7-bar sparkline from an array of day counts. Heights are scaled
 * against the local max so a slow week still shows shape; an all-zero
 * week renders nothing (we hide the element instead of drawing flatline).
 */
$spark = function (array $series): string {
    $max = max($series);
    if ($max <= 0) return '';
    $bars = '';
    foreach ($series as $v) {
        $h = max(8, (int)round(($v / $max) * 100));
        $bars .= '<span ' . inline_style('height:' . $h . '%') . '></span>';
    }
    return '<span class="spark" aria-hidden="true">' . $bars . '</span>';
};
$sparkOpened = $spark($trend['opened'] ?? []);
$sparkClosed = $spark($trend['closed'] ?? []);
?>
<section class="stats-grid">
  <div class="stat-card" data-metric="open">
    <span class="stat-card__label"><?= e(t('dashboard.stat.open')) ?></span>
    <span class="stat-card__value"><?= (int)$stats['open_tasks'] ?></span>
    <span class="stat-card__sub"><?= e(t('dashboard.stat.backlog_suffix', ['n' => (int)$stats['backlog_tasks']])) ?></span>
  </div>
  <div class="stat-card" data-metric="closed">
    <span class="stat-card__label"><?= e(t('dashboard.stat.closed')) ?></span>
    <span class="stat-card__value"><?= (int)$stats['closed_tasks'] ?></span>
    <span class="stat-card__sub"><?= e(t('dashboard.stat.total_done')) ?></span>
  </div>
  <div class="stat-card" data-metric="week-open">
    <span class="stat-card__label"><?= e(t('dashboard.stat.opened_week')) ?></span>
    <span class="stat-card__value"><?= (int)$stats['opened_week'] ?></span>
    <span class="stat-card__sub"><?= e(t('dashboard.stat.since_mon')) ?></span>
    <?= $sparkOpened ?>
  </div>
  <div class="stat-card" data-metric="week-closed">
    <span class="stat-card__label"><?= e(t('dashboard.stat.closed_week')) ?></span>
    <span class="stat-card__value"><?= (int)$stats['closed_week'] ?></span>
    <span class="stat-card__sub"><?= e(t('dashboard.stat.since_mon')) ?></span>
    <?= $sparkClosed ?>
  </div>
</section>

<section class="mb-16">
  <div class="section-head">
    <div class="title"><?= t('dashboard.my_tasks', ['highlight' => '<span class="text-brand fw-600">' . e(t('dashboard.my_tasks_highlight')) . '</span>']) ?></div>
    <div class="rule"></div>
    <div class="meta"><?= e(tn('open.count', (int)$stats['my_tasks'], ['n' => (int)$stats['my_tasks']])) ?></div>
  </div>
  <?php if (!$myTasks): ?>
    <div class="empty-state">
      <span class="empty-state__tag"><?= e(app_name()) ?></span>
      <p class="empty-state__text"><?= e(t('dashboard.empty.my_tasks')) ?></p>
    </div>
  <?php else: ?>
    <div class="cards-row cards-row--auto">
      <?php foreach ($myTasks as $i => $tk): ?>
        <a class="card card--link" href="/tasks/<?= (int)$tk['id'] ?>">
          <span class="corner-tag"><?= e(t('tasks.id_prefix', ['id' => (int)$tk['id']])) ?></span>
          <span class="corner-meta"><?= e($tk['column_name']) ?></span>
          <div class="mt-24">
            <div class="my-task-card__title"><?= e($tk['title']) ?></div>
            <div class="mono my-task-card__project"><?= e($tk['project_name']) ?></div>
          </div>
          <div class="card-row">
            <?php if (!empty($tk['due_date'])): ?>
              <span class="mono fz-10 text-ink-3"><?= fmt_date($tk['due_date']) ?></span>
            <?php else: ?>
              <span></span>
            <?php endif; ?>
            <span class="share-link"><?= e(t('common.open')) ?> <span class="arr">&#8594;</span></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="mb-16">
  <div class="section-head">
    <div class="title"><?= t('dashboard.recent_projects', ['highlight' => '<span class="text-brand fw-600">' . e(t('dashboard.recent_projects_highlight')) . '</span>']) ?></div>
    <div class="rule"></div>
    <div class="section-head__actions">
      <a class="meta meta--action" href="/projects?new=1"><?= e(t('common.new')) ?> <span class="arr">&#8594;</span></a>
      <a class="meta" href="/projects"><?= e(t('dashboard.all_projects')) ?> <span class="arr">&#8594;</span></a>
    </div>
  </div>
  <?php if (!$recentProjects): ?>
    <div class="empty-state">
      <span class="empty-state__tag"><?= e(app_name()) ?></span>
      <p class="empty-state__text">
        <?= t('dashboard.empty.projects', ['link' => '<a href="/projects?new=1">' . e(t('dashboard.empty.projects_link')) . '</a>']) ?>
      </p>
    </div>
  <?php else: ?>
    <div class="cards-row">
      <?php foreach ($recentProjects as $i => $p): ?>
        <a class="card no-underline-inherit" href="/projects/<?= (int)$p['id'] ?>">
          <span class="corner-tag"><?= e(t('projects.label.id', ['id' => (int)$p['id']])) ?></span>
          <span class="corner-meta"><?= fmt_date($p['updated_at']) ?></span>
          <div class="card-head">
            <div class="ini" data-bg="<?= e($p['color'] ?? '#1A1612') ?>"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 2))) ?></div>
            <div><h3 class="name"><?= e($p['name']) ?></h3></div>
          </div>
          <?php $desc = trim(strip_tags((string)($p['description'] ?? ''))); ?>
          <?php if ($desc !== ''): ?>
            <p class="card__desc"><?= e($desc) ?></p>
          <?php endif; ?>
          <?php $tc = ($projectTaskCounts ?? [])[(int)$p['id']] ?? ['open' => 0, 'total' => 0]; ?>
          <div class="card__tasks">
            <span class="card__tasks-caption"><?= e(t('projects.tasks_caption')) ?></span>
            <span class="card__tasks-num"><?= (int)$tc['open'] ?></span>
            <span class="card__tasks-label"><?= e(t('projects.tasks_open_label')) ?><?= $tc['total'] !== $tc['open'] ? e(t('projects.tasks_total_suffix', ['n' => (int)$tc['total']])) : '' ?></span>
          </div>
          <div class="card-row">
            <span class="status status--<?= e($p['status']) ?>"><?= e(t('status.project.' . $p['status'])) ?></span>
            <span class="share-link"><?= e(t('common.open')) ?> <span class="arr">&#8594;</span></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section>
  <div class="section-head">
    <div class="title"><?= t('dashboard.recent_activity', ['highlight' => '<span class="text-brand fw-600">' . e(t('dashboard.recent_activity_highlight')) . '</span>']) ?></div>
    <div class="rule"></div>
  </div>
  <?php if (!$recentActivity): ?>
    <div class="empty-state">
      <span class="empty-state__tag"><?= e(app_name()) ?></span>
      <p class="empty-state__text"><?= e(t('dashboard.empty.activity')) ?></p>
    </div>
  <?php else: ?>
    <div id="activity-list" class="activity-list">
      <?php foreach ($recentActivity as $a):
        require APP_ROOT . '/views/partials/activity-row.php';
      endforeach; ?>
    </div>
    <?php if (count($recentActivity) >= 10): ?>
      <div class="activity-load-more">
        <button class="btn--secondary load-more-activity" type="button" data-offset="10"><?= e(t('common.load_more')) ?></button>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

</div>

<script type="module" src="<?= e(asset_url('/assets/js/dashboard.js')) ?>"></script>
