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

?>
<?php
  $boardOpenCount = $openBoardTasks;
  $backlogCount   = count($backlogTasks);
  require APP_ROOT . '/views/partials/project-tabs.php';
?>

<?php if ($currentTab === 'board'): ?>
  <div class="kanban-toolbar">
    <div class="kanban-tagbar">
      <button type="button" class="chip chip--active" data-tag="">All</button>
      <?php foreach ($allTaskTagsInProject ?? [] as $t): ?>
        <button type="button" class="chip" data-tag="<?= e($t['name']) ?>"><?= e($t['name']) ?></button>
      <?php endforeach; ?>
    </div>
    <button type="button" class="kanban-sort-toggle" data-mine-toggle title="Show only my tasks">
      <i class="fa-solid fa-user"></i>
      <span data-mine-label>All</span>
    </button>
    <button type="button" class="kanban-sort-toggle" data-sort-toggle title="Toggle sort order">
      <i class="fa-solid fa-arrow-down-wide-short"></i>
      <span data-sort-label>Manual</span>
    </button>
    <div class="kanban-search kanban-search--with-submit">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input class="input input--inline" placeholder="Search tasks…" data-task-search>
      <button type="button" class="kanban-search__submit" data-task-search-submit aria-label="Search"><i class="fa-solid fa-arrow-right"></i></button>
    </div>
  </div>
  <div class="kanban" data-project-id="<?= (int)$project['id'] ?>" data-current-user-id="<?= (int)$currentUserId ?>">
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
        <?php $kanbanInitial = 25; $colTotal = count($colTasks); $colInitial = array_slice($colTasks, 0, $kanbanInitial); ?>
        <div class="kanban-list kanban-col__list"
             data-column-id="<?= (int)$col['id'] ?>"
             data-loaded="<?= count($colInitial) ?>"
             data-total="<?= $colTotal ?>">
          <?php if (empty($colInitial)): ?>
            <div class="kanban-empty">No tasks yet</div>
          <?php endif; ?>
          <?php foreach ($colInitial as $t):
            $tags = ($taskTags ?? [])[(int)$t['id']] ?? [];
            $meta = ($taskMeta ?? [])[(int)$t['id']] ?? ['comments' => 0, 'attachments' => 0];
            require APP_ROOT . '/views/partials/kanban-card.php';
          endforeach; ?>
          <?php if ($colTotal > count($colInitial)): ?>
            <div class="kanban-load-sentinel" data-load-sentinel
                 data-column-id="<?= (int)$col['id'] ?>"
                 data-project-id="<?= (int)$project['id'] ?>">
              Loading more…
            </div>
          <?php endif; ?>
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
        <div class="empty-state">
          <span class="empty-state__tag"><?= e(app_name()) ?></span>
          <p class="empty-state__text">No tasks parked in the backlog yet.</p>
        </div>
      <?php else: ?>
        <?php $backlogInitial = 30; $backlogTotal = count($backlogTasks); $backlogChunk = array_slice($backlogTasks, 0, $backlogInitial); ?>
        <ul class="backlog__list"
            data-backlog-list
            data-column-id="<?= (int)$backlogCol['id'] ?>"
            data-project-id="<?= (int)$project['id'] ?>"
            data-loaded="<?= count($backlogChunk) ?>"
            data-total="<?= $backlogTotal ?>">
          <?php foreach ($backlogChunk as $t):
            $tags = ($taskTags ?? [])[(int)$t['id']] ?? [];
            $meta = ($taskMeta ?? [])[(int)$t['id']] ?? ['comments' => 0, 'attachments' => 0];
            require APP_ROOT . '/views/partials/backlog-row.php';
          endforeach; ?>
          <?php if ($backlogTotal > count($backlogChunk)): ?>
            <li class="backlog__sentinel" data-backlog-sentinel>Loading more…</li>
          <?php endif; ?>
        </ul>
      <?php endif; ?>
    </div>
    <script type="module" src="/assets/js/backlog.js"></script>
  <?php endif; ?>
<?php else: ?>
  <div class="project-overview" data-project-id="<?= (int)$project['id'] ?>" style="display:grid;grid-template-columns:1fr 280px;gap:32px;align-items:start;">
    <div>
      <div class="project-overview__header" style="display:flex;align-items:center;gap:14px;margin-bottom:20px;">
        <?php if (!empty($canEdit)): ?>
          <label class="project-avatar-edit" title="Change project color">
            <div class="ini project-avatar" style="width:44px;height:44px;font-size:14px;background: <?= e($project['color'] ?? '#1A1612') ?>;flex-shrink:0;">
              <?= e(mb_strtoupper(mb_substr($project['name'], 0, 2))) ?>
            </div>
            <input type="color" data-project-color value="<?= e($project['color'] ?? '#1A1612') ?>">
          </label>
        <?php else: ?>
          <div class="ini project-avatar" style="width:44px;height:44px;font-size:14px;background: <?= e($project['color'] ?? '#1A1612') ?>;flex-shrink:0;">
            <?= e(mb_strtoupper(mb_substr($project['name'], 0, 2))) ?>
          </div>
        <?php endif; ?>
        <h1 class="project-title<?= !empty($canEdit) ? ' is-editable' : '' ?>"
            <?= !empty($canEdit) ? 'contenteditable="true" spellcheck="false"' : '' ?>
            data-project-id="<?= (int)$project['id'] ?>"
            style="font-size:32px;font-weight:700;letter-spacing:-0.02em;margin:0;outline:none;border-bottom:1px dashed transparent;padding-bottom:4px;flex:1;min-width:0;<?= !empty($canEdit) ? 'cursor:text;' : '' ?>"><?= e($project['name']) ?></h1>
      </div>
      <section class="overview-panel">
        <header class="overview-panel__head">
          <h2 class="overview-panel__title">Description</h2>
          <?php if (!empty($canEdit)): ?>
            <button class="btn-ghost" type="button" data-action="edit-description" style="font-size:12px;">Edit</button>
          <?php endif; ?>
        </header>
        <div class="overview-panel__body rich-text project-description-rendered"><?= $project['description'] ? \App\Service\LinkPreview::enhance(\App\Service\HtmlSanitizer::clean((string)$project['description'])) : '<em class="overview-panel__empty">No description</em>' ?></div>
        <?php if (!empty($canEdit)): ?>
          <div class="overview-panel__body project-description-editor" style="display:none;">
            <div class="wysiwyg-host">
              <div class="wysiwyg-editor"
                   data-quill
                   data-quill-target="#project-description-hidden"
                   data-placeholder="Description…"><?= $project['description'] ? \App\Service\HtmlSanitizer::clean((string)$project['description']) : '' ?></div>
            </div>
            <input type="hidden" id="project-description-hidden" value="<?= e($project['description'] ?? '') ?>">
            <div style="display:flex;gap:8px;margin-top:8px;">
              <button class="btn btn--primary submit" type="button" data-action="save-description">Save</button>
              <button class="btn btn--ghost" type="button" data-action="cancel-description">Cancel</button>
            </div>
          </div>
        <?php endif; ?>
        <footer class="overview-panel__attach">
          <div class="overview-panel__attach-label">Attachments</div>
          <?php
            $entityType  = 'project';
            $entityId    = (int)$project['id'];
            $attachments = $projectAttachments ?? [];
            require APP_ROOT . '/views/partials/attachment-list.php';
          ?>
        </footer>
        <div class="overview-panel__section">
          <?php
            $entityType = 'project';
            $entityId   = (int)$project['id'];
            $comments   = $projectComments ?? [];
            // $canPost is pre-computed in ProjectController::show() and passed as a view variable
            // $commentAttachments passed from controller
            require APP_ROOT . '/views/partials/comment-thread.php';
          ?>
        </div>
      </section>
    </div>
    <aside class="project-sidebar"
           style="border:1px solid var(--rule);padding:18px;background:var(--paper);border-radius:4px;">
      <h3 style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.15em;color:var(--ink-3);margin:0 0 8px;">Details</h3>

      <?php if (!empty($canEdit)): ?>
        <div class="field" style="margin-bottom:7px;">
          <label style="font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.1em;">Status</label>
          <form method="post" action="/projects/<?= (int)$project['id'] ?>" class="project-status-form" style="margin:0;">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
            <?php
              $statusOpts = project_statuses();
              $curStatus  = $project['status'] ?? 'active';
              $curLabel   = $statusOpts[$curStatus] ?? ucfirst($curStatus);
            ?>
            <div class="custom-select" data-custom-select>
              <button type="button" class="custom-select__btn">
                <span class="custom-select__icon"><span class="status-dot status-dot--<?= e($curStatus) ?>"></span></span>
                <span class="custom-select__label"><?= e($curLabel) ?></span>
                <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
              </button>
              <div class="custom-select__pop" hidden>
                <?php foreach ($statusOpts as $sVal => $sLabel): ?>
                  <div class="custom-select__opt<?= $curStatus === $sVal ? ' is-selected' : '' ?>" data-value="<?= e($sVal) ?>">
                    <span class="custom-select__icon"><span class="status-dot status-dot--<?= e($sVal) ?>"></span></span>
                    <span class="custom-select__opt-label"><?= e($sLabel) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
              <input type="hidden" name="status" value="<?= e($curStatus) ?>" data-auto-submit>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="field" style="margin-bottom:7px;">
        <label style="font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.1em;">Members</label>
        <?php $projectId = (int)$project['id']; require APP_ROOT . '/views/partials/members.php'; ?>
      </div>

      <div class="field" style="margin-bottom:7px;">
        <label style="font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.1em;">Tags</label>
        <?php
          $scope      = 'project';
          $entityType = 'project';
          $entityId   = (int)$project['id'];
          $current    = $projectTags ?? [];
          $all        = $allProjectTags ?? [];
          require APP_ROOT . '/views/partials/tag-picker.php';
        ?>
      </div>

      <?php if (!empty($canEdit)): ?>
        <button class="btn-danger" type="button" data-action="delete-project"
                style="margin-top:9px;width:100%;padding:8px;font-size:12px;letter-spacing:.1em;text-transform:uppercase;">
          Delete project
        </button>
      <?php endif; ?>
    </aside>
  </div>
  <script type="module" src="/assets/js/project-page.js"></script>
<?php endif; ?>
