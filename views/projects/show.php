<?php
$currentTab = $tab ?? 'board';
$backlogCol = null;
$boardColumns = [];
foreach ($columns as $c) {
    if ((int)($c['is_backlog'] ?? 0) === 1) { $backlogCol = $c; }
    else { $boardColumns[] = $c; }
}
$backlogTasks = $backlogCol ? ($tasksByCol[(int)$backlogCol['id']] ?? []) : [];

// Count open tasks on the board (every non-Done, non-Backlog column).
$openBoardTasks = 0;
foreach ($boardColumns as $c) {
    if ((int)($c['is_done'] ?? 0) === 1) continue;
    $openBoardTasks += count($tasksByCol[(int)$c['id']] ?? []);
}

function tab_link(int $pid, string $key, string $current, string $label) {
    $active = $current === $key;
    return sprintf(
        '<a href="/projects/%d?tab=%s" class="project-tab%s">%s</a>',
        $pid, $key, $active ? ' project-tab--active' : '', htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
    );
}
?>
<div class="project-tabs">
  <?= tab_link((int)$project['id'], 'board',    $currentTab, 'Board' . ($openBoardTasks ? ' · ' . $openBoardTasks : '')) ?>
  <?= tab_link((int)$project['id'], 'backlog',  $currentTab, 'Backlog' . ($backlogTasks ? ' · ' . count($backlogTasks) : '')) ?>
  <?= tab_link((int)$project['id'], 'overview', $currentTab, 'Overview') ?>
</div>

<?php if ($currentTab === 'board'): ?>
  <div class="kanban-toolbar">
    <div class="kanban-tagbar">
      <button type="button" class="chip chip--active" data-tag="">All</button>
      <?php foreach ($allTaskTagsInProject ?? [] as $t): ?>
        <button type="button" class="chip" data-tag="<?= e($t['name']) ?>"><?= e($t['name']) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="kanban-search">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input class="input input--inline" placeholder="Search tasks…" data-task-search>
    </div>
  </div>
  <div class="kanban" data-project-id="<?= (int)$project['id'] ?>">
    <?php foreach ($boardColumns as $col): ?>
      <?php $colTasks = $tasksByCol[$col['id']] ?? []; ?>
      <div class="kanban-col" data-column-id="<?= (int)$col['id'] ?>">
        <div class="kanban-col__head kanban-col-head">
          <button type="button" class="kanban-col__drag" aria-label="Reorder column" title="Drag to reorder column"><i class="fa-solid fa-grip-vertical"></i></button>
          <span class="kanban-col__dot dot" style="background: <?= e($col['color']) ?>"></span>
          <span class="kanban-col__name name"><?= e($col['name']) ?></span>
          <span class="kanban-col__count kanban-col-count"><?= count($colTasks) ?></span>
          <button type="button" class="btn-icon col-settings kanban-col__settings" data-column-id="<?= (int)$col['id'] ?>" aria-label="Column settings"><i class="fa-solid fa-ellipsis"></i></button>
        </div>
        <div class="kanban-list kanban-col__list" data-column-id="<?= (int)$col['id'] ?>">
          <?php if (empty($colTasks)): ?>
            <div class="kanban-empty">No tasks yet</div>
          <?php endif; ?>
          <?php foreach ($colTasks as $t):
            $tags = ($taskTags ?? [])[(int)$t['id']] ?? [];
            $meta = ($taskMeta ?? [])[(int)$t['id']] ?? ['comments' => 0, 'attachments' => 0];
          ?>
            <div class="kanban-card"
                 data-task-id="<?= (int)$t['id'] ?>"
                 data-task-url="/tasks/<?= (int)$t['id'] ?>"
                 data-position="<?= (float)$t['position'] ?>"
                 data-tags="<?= e(implode(',', array_column($tags, 'name'))) ?>"
                 data-title="<?= e(strtolower($t['title'])) ?>">
              <?php if ($tags): ?>
                <div class="kanban-card__row">
                  <?php foreach (array_slice($tags, 0, 2) as $tag): ?>
                    <span class="kanban-card__tag tag"
                          style="--tag: <?= e($tag['color']) ?>; --tag-bg: <?= e($tag['color']) ?>22;">
                      <?= e($tag['name']) ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <div class="kanban-card__id">TASK-<?= (int)$t['id'] ?></div>
              <div class="kanban-card__title"><?= e($t['title']) ?></div>
              <?php if (!empty($t['assignee_id'])): ?>
                <div class="kanban-card__assignee">
                  <span class="user-avatar user-avatar--xs" style="background: <?= user_color((int)$t['assignee_id']) ?>"><?= e(mb_substr($t['assignee_name'] ?? '?', 0, 1)) ?></span>
                  <span><?= e($t['assignee_name'] ?? '?') ?></span>
                </div>
              <?php endif; ?>
              <?php if (!empty($t['due_date']) || $meta['comments'] || $meta['attachments']): ?>
                <div class="kanban-card__meta">
                  <?php if (!empty($t['due_date'])): ?>
                    <span class="kanban-card__due">
                      <i class="fa-regular fa-calendar"></i>
                      <?= e(fmt_date($t['due_date'])) ?>
                    </span>
                  <?php else: ?>
                    <span></span>
                  <?php endif; ?>
                  <?php if ($meta['comments'] || $meta['attachments']): ?>
                    <span class="kanban-card__counts">
                      <?php if ($meta['comments']): ?>
                        <span><i class="fa-regular fa-comment"></i> <?= (int)$meta['comments'] ?></span>
                      <?php endif; ?>
                      <?php if ($meta['attachments']): ?>
                        <span><i class="fa-solid fa-paperclip"></i> <?= (int)$meta['attachments'] ?></span>
                      <?php endif; ?>
                    </span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="kanban-col__footer kanban-quickadd" data-column-id="<?= (int)$col['id'] ?>">
          <button type="button" class="kanban-col__add" data-quickadd-trigger data-column-id="<?= (int)$col['id'] ?>">
            <i class="fa-solid fa-plus"></i> Add task
          </button>
          <form class="kanban-col__form" data-column-id="<?= (int)$col['id'] ?>" hidden>
            <input class="input input--sm" type="text" name="title" placeholder="Task title" maxlength="200">
            <span class="kanban-col__hint">Enter to save, Esc to cancel</span>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
    <button type="button" class="btn-secondary add-column"><i class="fa-solid fa-plus"></i> Column</button>
  </div>
  <script src="/assets/vendor/sortable.min.js"></script>
  <script type="module" src="/assets/js/kanban.js"></script>
<?php elseif ($currentTab === 'backlog'): ?>
  <?php if (!$backlogCol): ?>
    <p style="color:var(--text-muted);">Backlog column not configured for this project.</p>
  <?php else: ?>
    <div class="backlog" data-project-id="<?= (int)$project['id'] ?>" data-column-id="<?= (int)$backlogCol['id'] ?>">
      <form class="backlog__add" data-quickadd-trigger data-column-id="<?= (int)$backlogCol['id'] ?>" autocomplete="off"
            onclick="this.querySelector('input').focus()">
        <i class="fa-solid fa-plus"></i>
        <input class="input input--inline" type="text" name="title" placeholder="Type a task title and press Enter…" maxlength="200">
        <span class="backlog__add-hint">Enter to save · Esc to cancel</span>
      </form>
      <?php if (!$backlogTasks): ?>
        <p class="backlog__empty">No tasks parked in the backlog yet.</p>
      <?php else: ?>
        <ul class="backlog__list">
          <?php foreach ($backlogTasks as $t):
            $tags = ($taskTags ?? [])[(int)$t['id']] ?? [];
            $meta = ($taskMeta ?? [])[(int)$t['id']] ?? ['comments' => 0, 'attachments' => 0];
          ?>
            <li class="backlog__item">
              <a class="backlog__link" href="/tasks/<?= (int)$t['id'] ?>">
                <span class="backlog__id">TASK-<?= (int)$t['id'] ?></span>
                <span class="backlog__title"><?= e($t['title']) ?></span>
                <?php if ($tags): ?>
                  <span class="backlog__tags">
                    <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                      <span class="tag" style="--tag: <?= e($tag['color']) ?>; --tag-bg: <?= e($tag['color']) ?>22;"><?= e($tag['name']) ?></span>
                    <?php endforeach; ?>
                  </span>
                <?php endif; ?>
                <?php if (!empty($t['assignee_name'])): ?>
                  <span class="backlog__assignee">
                    <span class="avatar avatar--xs"><?= e(mb_substr($t['assignee_name'], 0, 2)) ?></span>
                    <span><?= e($t['assignee_name']) ?></span>
                  </span>
                <?php endif; ?>
                <?php if (!empty($t['due_date'])): ?>
                  <span class="backlog__due"><i class="fa-regular fa-calendar"></i> <?= e(fmt_date($t['due_date'])) ?></span>
                <?php endif; ?>
                <?php if ($meta['comments'] || $meta['attachments']): ?>
                  <span class="backlog__counts">
                    <?php if ($meta['comments']): ?><span><i class="fa-regular fa-comment"></i> <?= (int)$meta['comments'] ?></span><?php endif; ?>
                    <?php if ($meta['attachments']): ?><span><i class="fa-solid fa-paperclip"></i> <?= (int)$meta['attachments'] ?></span><?php endif; ?>
                  </span>
                <?php endif; ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <script type="module" src="/assets/js/backlog.js"></script>
  <?php endif; ?>
<?php else: ?>
  <div style="display:grid;grid-template-columns:1fr 280px;gap:32px;">
    <div>
      <h2 style="font-weight:600;font-size:18px;">Description</h2>
      <div class="rich-text" style="color:var(--ink-2);"><?= $project['description'] ? \App\Service\HtmlSanitizer::clean((string)$project['description']) : '<em style="color:var(--ink-3);">No description</em>' ?></div>
      <div style="margin-top:32px;">
        <h3 style="font-weight:600;font-size:14px;text-transform:uppercase;letter-spacing:.1em;color:var(--ink-3);margin:0 0 12px;">Attachments</h3>
        <?php
          $entityType  = 'project';
          $entityId    = (int)$project['id'];
          $attachments = $projectAttachments ?? [];
          require APP_ROOT . '/views/partials/attachment-list.php';
        ?>
      </div>
      <div style="margin-top:32px;">
        <?php
          $entityType = 'project';
          $entityId   = (int)$project['id'];
          $comments   = $projectComments ?? [];
          // $canPost is pre-computed in ProjectController::show() and passed as a view variable
          // $commentAttachments passed from controller
          require APP_ROOT . '/views/partials/comment-thread.php';
        ?>
      </div>
    </div>
    <aside>
      <?php if (!empty($canEdit)): ?>
        <h3 style="font-weight:600;font-size:14px;text-transform:uppercase;letter-spacing:.1em;color:var(--ink-3);">Status</h3>
        <form method="post" action="/projects/<?= (int)$project['id'] ?>" class="project-status-form" style="margin:12px 0 24px;">
          <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
          <select class="select" name="status" data-auto-submit>
            <option value="active"   <?= $project['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
            <option value="archived" <?= $project['status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
          </select>
        </form>
      <?php endif; ?>
      <h3 style="font-weight:600;font-size:14px;text-transform:uppercase;letter-spacing:.1em;color:var(--ink-3);">Members</h3>
      <div style="margin-top:12px;">
        <?php $projectId = (int)$project['id']; require APP_ROOT . '/views/partials/members.php'; ?>
      </div>
      <h3 style="font-weight:600;font-size:14px;text-transform:uppercase;letter-spacing:.1em;color:var(--ink-3);margin:24px 0 12px;">Tags</h3>
      <?php
        $scope      = 'project';
        $entityType = 'project';
        $entityId   = (int)$project['id'];
        $current    = $projectTags ?? [];
        $all        = $allProjectTags ?? [];
        require APP_ROOT . '/views/partials/tag-picker.php';
      ?>
    </aside>
  </div>
<?php endif; ?>
