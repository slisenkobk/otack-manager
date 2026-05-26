<div class="page-actions">
  <div class="project-tabs project-tabs--inline">
    <a class="project-tab<?= ($status ?? 'active') === 'active' ? ' is-active' : '' ?>" href="/projects?status=active">
      Active <span class="project-tab__count"><?= (int)($totalActive ?? 0) ?></span>
    </a>
    <a class="project-tab<?= ($status ?? 'active') === 'archived' ? ' is-active' : '' ?>" href="/projects?status=archived">
      Archived <span class="project-tab__count"><?= (int)($totalArchived ?? 0) ?></span>
    </a>
  </div>
  <div style="display:flex;gap:12px;align-items:center;">
    <form method="get" action="/projects" class="kanban-search kanban-search--with-submit" style="margin:0;">
      <input type="hidden" name="status" value="<?= e($status ?? 'active') ?>">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input class="input input--inline" name="q" placeholder="Search projects…" value="<?= e($query ?? '') ?>" data-project-search>
      <button type="submit" class="kanban-search__submit" aria-label="Search"><i class="fa-solid fa-arrow-right"></i></button>
    </form>
    <a href="/projects/new" class="btn btn--secondary" style="text-decoration:none;">+ New project</a>
  </div>
</div>

<?php if (!$projects): ?>
  <div class="empty-state">
    <span class="empty-state__tag">Otack Manager</span>
    <p class="empty-state__text">
      <?php if (!empty($query)): ?>
        No projects match "<?= e($query) ?>".
      <?php elseif (($status ?? 'active') === 'archived'): ?>
        No archived projects.
      <?php else: ?>
        No projects yet. <a href="/projects/new">Create your first one</a>.
      <?php endif; ?>
    </p>
  </div>
<?php else: ?>
  <div class="cards-row">
    <?php foreach ($projects as $i => $p): ?>
      <a class="card" href="/projects/<?= (int)$p['id'] ?>" style="text-decoration:none;color:inherit;">
        <span class="corner-tag">P-<?= (int)$p['id'] ?></span>
        <span class="corner-meta"><?= fmt_date($p['updated_at']) ?></span>
        <div class="card-head">
          <div class="ini" style="background: <?= e($p['color'] ?? '#1A1612') ?>;"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 2))) ?></div>
          <div>
            <h3 class="name"><?= e($p['name']) ?></h3>
          </div>
        </div>
        <?php $desc = trim(strip_tags((string)($p['description'] ?? ''))); ?>
        <?php if ($desc !== ''): ?>
          <p class="card__desc"><?= e($desc) ?></p>
        <?php endif; ?>
        <?php $tc = $taskCounts[(int)$p['id']] ?? ['open' => 0, 'total' => 0]; ?>
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
  <?php
    $hrefBuilder = function (int $p) use ($status, $query) {
        $qs = 'status=' . urlencode($status) . '&page=' . $p;
        if (!empty($query)) $qs .= '&q=' . urlencode($query);
        return '/projects?' . $qs;
    };
    require APP_ROOT . '/views/partials/pagination.php';
  ?>
<?php endif; ?>

<script type="module" src="/assets/js/projects.js"></script>
