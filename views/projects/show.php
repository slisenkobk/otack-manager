<?php $currentTab = $tab ?? 'board'; ?>
<div class="section-head">
  <div class="num"><?= str_pad((string)$project['id'], 2, '0', STR_PAD_LEFT) ?></div>
  <div class="title"><?= e($project['name']) ?></div>
  <div class="rule"></div>
  <span class="status<?= $project['status'] === 'active' ? ' is-ready' : '' ?>"><?= e($project['status']) ?></span>
</div>

<div style="display:flex;gap:24px;border-bottom:1px solid var(--rule);margin-bottom:28px;">
  <a href="/projects/<?= (int)$project['id'] ?>?tab=board"
     class="<?= $currentTab === 'board' ? 'active' : '' ?>"
     style="padding:10px 0;border-bottom:2px solid <?= $currentTab === 'board' ? 'var(--brand)' : 'transparent' ?>;text-decoration:none;color:<?= $currentTab === 'board' ? 'var(--brand)' : 'var(--ink-2)' ?>;font-weight:<?= $currentTab === 'board' ? '600' : '400' ?>;">Board</a>
  <a href="/projects/<?= (int)$project['id'] ?>?tab=overview"
     style="padding:10px 0;border-bottom:2px solid <?= $currentTab === 'overview' ? 'var(--brand)' : 'transparent' ?>;text-decoration:none;color:<?= $currentTab === 'overview' ? 'var(--brand)' : 'var(--ink-2)' ?>;font-weight:<?= $currentTab === 'overview' ? '600' : '400' ?>;">Overview</a>
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
    <?php foreach ($columns as $col): ?>
      <?php $colTasks = $tasksByCol[$col['id']] ?? []; ?>
      <div class="kanban-col" data-column-id="<?= (int)$col['id'] ?>">
        <div class="kanban-col__head kanban-col-head">
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
              <div class="kanban-card__title"><?= e($t['title']) ?></div>
              <?php if (!empty($t['assignee_id'])): ?>
                <div class="kanban-card__assignee">
                  <span class="avatar avatar--xs"><?= e(mb_substr($t['assignee_name'] ?? '?', 0, 2)) ?></span>
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
<?php else: ?>
  <div style="display:grid;grid-template-columns:1fr 280px;gap:32px;">
    <div>
      <h2 style="font-weight:600;font-size:18px;">Description</h2>
      <p style="color:var(--ink-2);"><?= $project['description'] ? e($project['description']) : '<em style="color:var(--ink-3);">No description</em>' ?></p>
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
