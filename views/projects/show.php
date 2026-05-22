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
  <div class="kanban" data-project-id="<?= (int)$project['id'] ?>">
    <?php foreach ($columns as $col): ?>
      <?php $colTasks = $tasksByCol[$col['id']] ?? []; ?>
      <div class="kanban-col" data-column-id="<?= (int)$col['id'] ?>">
        <div class="kanban-col-head">
          <span class="dot" style="background: <?= e($col['color']) ?>"></span>
          <span class="name"><?= e($col['name']) ?></span>
          <span class="kanban-col-count"><?= count($colTasks) ?></span>
          <button type="button" class="btn-icon col-settings" data-column-id="<?= (int)$col['id'] ?>" aria-label="Column settings"><i class="fa-solid fa-ellipsis-vertical"></i></button>
        </div>
        <div class="kanban-list" data-column-id="<?= (int)$col['id'] ?>">
          <?php foreach ($colTasks as $t): ?>
            <div class="kanban-card" data-task-id="<?= (int)$t['id'] ?>" data-task-url="/tasks/<?= (int)$t['id'] ?>" data-position="<?= (float)$t['position'] ?>">
              <div class="title"><?= e($t['title']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <form class="kanban-quickadd" data-column-id="<?= (int)$col['id'] ?>">
          <input type="text" name="title" placeholder="+ Add task" maxlength="200">
        </form>
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
        <?php
          $entityType = 'project';
          $entityId   = (int)$project['id'];
          $comments   = $projectComments ?? [];
          $canPost    = $isAdmin || App::make('members')->isMember($entityId, (int)$currentUserId);
          require APP_ROOT . '/views/partials/comment-thread.php';
        ?>
      </div>
    </div>
    <aside>
      <h3 style="font-weight:600;font-size:14px;text-transform:uppercase;letter-spacing:.1em;color:var(--ink-3);">Members</h3>
      <div style="margin-top:12px;">
        <?php $projectId = (int)$project['id']; require APP_ROOT . '/views/partials/members.php'; ?>
      </div>
    </aside>
  </div>
<?php endif; ?>
