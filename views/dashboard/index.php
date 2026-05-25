<section class="hero" style="margin-bottom:64px;">
  <p class="kicker">
    <span class="num">REQUEST · 01</span>
    <span class="sep">/</span>
    <span>Welcome back</span>
  </p>
  <h1 class="display" style="font-weight:700;">Hello, <span style="color:var(--brand);"><?= e($user['name']) ?></span></h1>
  <p class="mono" style="margin-top:18px;font-size:12px;letter-spacing:.15em;color:var(--ink-3);text-transform:uppercase;">
    <?= (int)$stats['open_projects'] ?> open projects · <?= (int)$stats['my_tasks'] ?> my tasks · <?= (int)$stats['activity'] ?> activity events
  </p>
</section>

<section style="margin-bottom:48px;">
  <div class="section-head">
    <div class="num">02</div>
    <div class="title">My <span style="color:var(--brand);font-weight:600;">tasks</span></div>
    <div class="rule"></div>
    <div class="meta"><?= (int)$stats['my_tasks'] ?> open</div>
  </div>
  <?php if (!$myTasks): ?>
    <p class="muted" style="color:var(--ink-3);font-size:14px;">No assigned tasks. Enjoy the calm.</p>
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

<section style="margin-bottom:48px;">
  <div class="section-head">
    <div class="num">03</div>
    <div class="title">Recent <span style="color:var(--brand);font-weight:600;">projects</span></div>
    <div class="rule"></div>
    <a class="meta" href="/projects">All projects <span class="arr">&#8594;</span></a>
  </div>
  <?php if (!$recentProjects): ?>
    <p class="muted" style="color:var(--ink-3);font-size:14px;">No projects yet. <a href="/projects/new" style="color:var(--brand);">Create one</a>.</p>
  <?php else: ?>
    <div class="cards-row">
      <?php foreach ($recentProjects as $i => $p): ?>
        <a class="card" href="/projects/<?= (int)$p['id'] ?>" style="text-decoration:none;color:inherit;">
          <span class="corner-tag">P-<?= (int)$p['id'] ?></span>
          <span class="corner-meta"><?= fmt_date($p['updated_at']) ?></span>
          <div class="card-head">
            <div class="ini"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 2))) ?></div>
            <div><h3 class="name"><?= e($p['name']) ?></h3></div>
          </div>
          <?php $desc = trim(strip_tags((string)($p['description'] ?? ''))); ?>
          <?php if ($desc !== ''): ?>
            <p class="card__desc"><?= e($desc) ?></p>
          <?php endif; ?>
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
    <p class="muted" style="color:var(--ink-3);font-size:14px;">No activity yet.</p>
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

<script type="module" src="/assets/js/dashboard.js"></script>
