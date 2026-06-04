<div class="page-actions">
  <div class="project-tabs project-tabs--inline">
    <?php $cur = $status ?? 'active'; foreach (project_statuses() as $sVal => $sLabel): ?>
      <a class="project-tab<?= $cur === $sVal ? ' is-active' : '' ?>" href="/projects?status=<?= e($sVal) ?>">
        <?= e($sLabel) ?> <span class="project-tab__count"><?= (int)(($statusCounts ?? [])[$sVal] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="page-actions__right">
    <form method="get" action="/projects" class="kanban-search kanban-search--with-submit m-0">
      <input type="hidden" name="status" value="<?= e($status ?? 'active') ?>">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input class="input input--inline" name="q" placeholder="<?= e(t('projects.search_placeholder')) ?>" value="<?= e($query ?? '') ?>" data-project-search>
      <button type="submit" class="kanban-search__submit" aria-label="<?= e(t('common.search')) ?>"><i class="fa-solid fa-arrow-right"></i></button>
    </form>
    <?php if (!empty($canCreateProject)): ?>
      <button type="button" class="btn btn--secondary" data-action="new-project"
        data-label-title="<?= e(t('projects.new_project')) ?>"
        data-label-name="<?= e(t('projects.form.name')) ?>"
        data-label-description="<?= e(t('projects.form.description')) ?>"
        data-label-color="<?= e(t('projects.form.color')) ?>"
        data-label-submit="<?= e(t('projects.form.save')) ?>"
        data-label-cancel="<?= e(t('common.cancel')) ?>"><?= e(t('projects.new_project_short')) ?></button>
    <?php endif; ?>
  </div>
</div>

<?php if (!$projects): ?>
  <div class="empty-state">
    <span class="empty-state__tag"><?= e(app_name()) ?></span>
    <p class="empty-state__text">
      <?php if (!empty($query)): ?>
        <?= e(t('projects.empty.match', ['q' => $query])) ?>
      <?php elseif (($status ?? 'active') !== 'active'): ?>
        <?= e(t('projects.empty.status', ['label' => project_status_label($status ?? '')])) ?>
      <?php else: ?>
        <?= t('projects.empty.none', ['link' => '<a href="#" data-action="new-project">' . e(t('projects.empty.create_first')) . '</a>']) ?>
      <?php endif; ?>
    </p>
  </div>
<?php else: ?>
  <div class="cards-row">
    <?php foreach ($projects as $i => $p): ?>
      <a class="card<?= !empty($p['pinned_at']) ? ' is-pinned' : '' ?> no-underline-inherit" href="/projects/<?= (int)$p['id'] ?>" data-project-id="<?= (int)$p['id'] ?>">
        <span class="corner-tag"><?= e(t('projects.label.id', ['id' => (int)$p['id']])) ?></span>
        <span class="corner-meta"><?= fmt_date($p['updated_at']) ?></span>
        <button type="button" class="card-pin<?= !empty($p['pinned_at']) ? ' is-on' : '' ?>" data-action="toggle-pin" aria-label="<?= e(t('projects.pin_aria')) ?>">
          <i class="fa-solid fa-thumbtack"></i>
        </button>
        <div class="card-head">
          <div class="ini" data-bg="<?= e($p['color'] ?? '#1A1612') ?>"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 2))) ?></div>
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
          <span class="card__tasks-caption"><?= e(t('projects.tasks_caption')) ?></span>
          <span class="card__tasks-num"><?= (int)$tc['open'] ?></span>
          <span class="card__tasks-label"><?= e(t('projects.tasks_open_label')) ?><?= $tc['total'] !== $tc['open'] ? e(t('projects.tasks_total_suffix', ['n' => (int)$tc['total']])) : '' ?></span>
        </div>
        <div class="card-row">
          <span class="status status--<?= e($p['status']) ?>"><?= e(project_status_label($p['status'])) ?></span>
          <span class="share-link"><?= e(t('common.open')) ?> <span class="arr">&#8594;</span></span>
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

<script type="module" src="<?= e(asset_url('/assets/js/projects.js')) ?>"></script>
