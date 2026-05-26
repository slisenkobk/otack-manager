<div class="dashboard-page">

<section class="hero" style="margin-bottom:16px;">
  <h1 class="display" style="font-weight:700;">Hello, <span style="color:var(--brand);"><?= e($user['name']) ?></span></h1>
  <p class="mono" style="margin-top:12px;font-size:12px;letter-spacing:.15em;color:var(--ink-3);text-transform:uppercase;">
    <?= (int)$stats['open_projects'] ?> open projects · <?= (int)$stats['my_tasks'] ?> my tasks · <?= (int)$stats['activity'] ?> activity events
  </p>
</section>

<section class="stats-grid">
  <div class="stat-card">
    <span class="stat-card__label">Open</span>
    <span class="stat-card__value"><?= (int)$stats['open_tasks'] ?></span>
    <span class="stat-card__sub">+ <?= (int)$stats['backlog_tasks'] ?> backlog</span>
  </div>
  <div class="stat-card">
    <span class="stat-card__label">Closed</span>
    <span class="stat-card__value"><?= (int)$stats['closed_tasks'] ?></span>
    <span class="stat-card__sub">total done</span>
  </div>
  <div class="stat-card stat-card--accent">
    <span class="stat-card__label">Opened this week</span>
    <span class="stat-card__value"><?= (int)$stats['opened_week'] ?></span>
    <span class="stat-card__sub">since Mon</span>
  </div>
  <div class="stat-card stat-card--green">
    <span class="stat-card__label">Closed this week</span>
    <span class="stat-card__value"><?= (int)$stats['closed_week'] ?></span>
    <span class="stat-card__sub">since Mon</span>
  </div>
</section>

<section style="margin-bottom:16px;">
  <div class="section-head">
    <div class="num">02</div>
    <div class="title">My <span style="color:var(--brand);font-weight:600;">tasks</span></div>
    <div class="rule"></div>
    <div class="meta"><?= (int)$stats['my_tasks'] ?> open</div>
  </div>
  <?php if (!$myTasks): ?>
    <div class="empty-state">
      <span class="empty-state__tag">Otack Manager</span>
      <p class="empty-state__text">No assigned tasks. Enjoy the calm.</p>
    </div>
  <?php else: ?>
    <div class="cards-row" style="grid-template-columns:repeat(auto-fill, minmax(260px, 1fr));">
      <?php foreach ($myTasks as $i => $t): ?>
        <a class="card" href="/tasks/<?= (int)$t['id'] ?>" style="text-decoration:none;color:inherit;min-height:130px;">
          <span class="corner-tag">TASK-<?= (int)$t['id'] ?></span>
          <span class="corner-meta"><?= e($t['column_name']) ?></span>
          <div style="margin-top:24px;">
            <div style="font-weight:600;font-size:15px;line-height:1.3;"><?= e($t['title']) ?></div>
            <div class="mono muted" style="font-size:10px;color:var(--ink-3);margin-top:6px;letter-spacing:.1em;text-transform:uppercase;"><?= e($t['project_name']) ?></div>
          </div>
          <div class="card-row">
            <?php if (!empty($t['due_date'])): ?>
              <span class="mono" style="font-size:10px;color:var(--ink-3);"><?= fmt_date($t['due_date']) ?></span>
            <?php else: ?>
              <span></span>
            <?php endif; ?>
            <span class="share-link">Open <span class="arr">&#8594;</span></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section style="margin-bottom:16px;">
  <div class="section-head">
    <div class="num">03</div>
    <div class="title">Recent <span style="color:var(--brand);font-weight:600;">projects</span></div>
    <div class="rule"></div>
    <div class="section-head__actions">
      <a class="meta meta--action" href="/projects/new">+ New <span class="arr">&#8594;</span></a>
      <a class="meta" href="/projects">All projects <span class="arr">&#8594;</span></a>
    </div>
  </div>
  <?php if (!$recentProjects): ?>
    <div class="empty-state">
      <span class="empty-state__tag">Otack Manager</span>
      <p class="empty-state__text">No projects yet. <a href="/projects/new">Create one</a>.</p>
    </div>
  <?php else: ?>
    <div class="cards-row">
      <?php foreach ($recentProjects as $i => $p): ?>
        <a class="card" href="/projects/<?= (int)$p['id'] ?>" style="text-decoration:none;color:inherit;">
          <span class="corner-tag">P-<?= (int)$p['id'] ?></span>
          <span class="corner-meta"><?= fmt_date($p['updated_at']) ?></span>
          <div class="card-head">
            <div class="ini" style="background: <?= e($p['color'] ?? '#1A1612') ?>;"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 2))) ?></div>
            <div><h3 class="name"><?= e($p['name']) ?></h3></div>
          </div>
          <?php $desc = trim(strip_tags((string)($p['description'] ?? ''))); ?>
          <?php if ($desc !== ''): ?>
            <p class="card__desc"><?= e($desc) ?></p>
          <?php endif; ?>
          <?php $tc = ($projectTaskCounts ?? [])[(int)$p['id']] ?? ['open' => 0, 'total' => 0]; ?>
          <div class="card__tasks">
            <span class="card__tasks-caption">Tasks</span>
            <span class="card__tasks-num"><?= (int)$tc['open'] ?></span>
            <span class="card__tasks-label">open<?= $tc['total'] !== $tc['open'] ? ' / ' . (int)$tc['total'] . ' total' : '' ?></span>
          </div>
          <div class="card-row">
            <span class="status<?= $p['status'] === 'active' ? ' is-ready' : '' ?>"><?= e($p['status']) ?></span>
            <span class="share-link">Open <span class="arr">&#8594;</span></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section>
  <div class="section-head">
    <div class="num">04</div>
    <div class="title">Recent <span style="color:var(--brand);font-weight:600;">activity</span></div>
    <div class="rule"></div>
  </div>
  <?php if (!$recentActivity): ?>
    <div class="empty-state">
      <span class="empty-state__tag">Otack Manager</span>
      <p class="empty-state__text">No activity yet.</p>
    </div>
  <?php else: ?>
    <div id="activity-list" class="activity-list">
      <?php foreach ($recentActivity as $a):
        require APP_ROOT . '/views/partials/activity-row.php';
      endforeach; ?>
    </div>
    <?php if (count($recentActivity) >= 10): ?>
      <div style="margin-top:12px;text-align:center;">
        <button class="btn-secondary load-more-activity" type="button" data-offset="10">Load more</button>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

</div>

<script type="module" src="/assets/js/dashboard.js"></script>
