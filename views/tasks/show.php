<div class="task-breadcrumb">
  <a class="task-breadcrumb__back" href="/projects/<?= (int)$project['id'] ?>?tab=board">
    <i class="fa-solid fa-arrow-left"></i> <?= e(t('tasks.back_to_board')) ?>
  </a>
</div>
<?php
  $currentTab     = 'board';
  $boardOpenCount = $tabCounts['board_open'] ?? 0;
  $backlogCount   = $tabCounts['backlog'] ?? 0;
  require APP_ROOT . '/views/partials/project-tabs.php';
?>

<div style="display:grid;grid-template-columns:1fr 280px;gap:40px;align-items:start;">
  <main>
    <p class="mono muted task-breadcrumb-line">
      <a href="/projects/<?= (int)$project['id'] ?>" class="task-breadcrumb-line__project"><?= e($project['name']) ?></a>
      <span class="task-breadcrumb-line__sep">/</span>
      <span class="task-header__id task-header__id--inline"><?= e(t('tasks.id_prefix', ['id' => (int)$task['id']])) ?></span>
    </p>

    <div class="task-header" style="margin:0 0 24px;">
      <?php if (!empty($task['sub_status'])): ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
          <span class="sub-status sub-status--<?= e($task['sub_status']) ?>" data-task-sub-status>
            <?= e($task['sub_status'] === 'reopened' ? t('tasks.sub_status.reopened') : t('tasks.sub_status.returned')) ?>
          </span>
        </div>
      <?php else: ?>
        <span class="sub-status" data-task-sub-status hidden></span>
      <?php endif; ?>
      <h1 class="task-title<?= !empty($canEditTask) ? ' is-editable' : '' ?>" <?= !empty($canEditTask) ? 'contenteditable="true"' : '' ?> spellcheck="false"
          style="font-size:24px;font-weight:700;letter-spacing:-0.015em;margin:0;outline:none;border-bottom:1px dashed transparent;padding-bottom:4px;<?= !empty($canEditTask) ? 'cursor:text;' : '' ?>line-height:1.25;"
          data-task-id="<?= (int)$task['id'] ?>"><?= e($task['title']) ?></h1>
    </div>

    <section class="overview-panel task-description-section">
      <header class="overview-panel__head">
        <h2 class="overview-panel__title"><?= e(t('tasks.description')) ?></h2>
        <?php if (!empty($canEditTask)): ?>
          <button class="btn-ghost" type="button" data-action="edit-description" style="font-size:12px;"><?= e(t('common.edit')) ?></button>
        <?php endif; ?>
      </header>
      <div class="overview-panel__body rich-text task-description-rendered">
        <?= $task['description'] ? \App\Service\LinkPreview::enhance((string)$task['description']) : '<em class="overview-panel__empty">' . e(t('tasks.no_description')) . '</em>' ?>
      </div>
      <div class="overview-panel__body task-description-editor" style="display:none;">
        <div class="wysiwyg-host">
          <div class="wysiwyg-editor"
               data-quill
               data-quill-target="#task-description-hidden"
               data-placeholder="<?= e(t('projects.overview.description_placeholder')) ?>"><?= $task['description'] ?? '' ?></div>
        </div>
        <input type="hidden" id="task-description-hidden" value="<?= e($task['description'] ?? '') ?>">
        <div style="display:flex;gap:8px;margin-top:8px;">
          <button class="btn btn--primary submit" type="button" data-action="save-description"><?= e(t('common.save')) ?></button>
          <button class="btn btn--ghost" type="button" data-action="cancel-description"><?= e(t('common.cancel')) ?></button>
        </div>
      </div>
      <footer class="overview-panel__attach">
        <div class="overview-panel__attach-label"><?= e(t('tasks.attachments')) ?></div>
        <?php
          $entityType = 'task';
          $entityId = (int)$task['id'];
          require APP_ROOT . '/views/partials/attachment-list.php';
        ?>
        <?php
          $fromCommentsCount = count($commentAttachmentsFlat) + count($commentLinks);
          if ($fromCommentsCount > 0):
        ?>
          <details class="from-comments-block">
            <summary class="from-comments-block__summary">
              <i class="fa-solid fa-chevron-right from-comments-block__caret"></i>
              <?= e(t('tasks.from_comments')) ?>
              <span class="from-comments-block__count"><?= $fromCommentsCount ?></span>
            </summary>
            <div class="from-comments-block__body">
              <?php if (!empty($commentAttachmentsFlat)):
                $savedAttachments = $attachments; $savedCanEdit = $canEdit;
                $attachments = $commentAttachmentsFlat;
                $canEdit     = false;
                $readOnly    = true; // delete inside this grid would also drop from the comment
                require APP_ROOT . '/views/partials/attachment-list.php';
                $readOnly = false; $canEdit = $savedCanEdit; $attachments = $savedAttachments;
              endif; ?>
              <?php if (!empty($commentLinks)): ?>
                <div class="comment-links-list">
                  <?php foreach ($commentLinks as $cl):
                    $pretty = preg_replace('#^https?://#i', '', $cl['url']);
                    $pretty = rtrim($pretty, '/');
                    if (mb_strlen($pretty) > 56) $pretty = mb_substr($pretty, 0, 53) . '…';
                  ?>
                    <a class="link-card" href="<?= e($cl['url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= e(t('tasks.from_comment_link', ['name' => $cl['author_name']])) ?>">
                      <span class="link-card__icon"><i class="fa-solid fa-link"></i></span>
                      <span class="link-card__body">
                        <span class="link-card__url"><?= e($pretty) ?></span>
                      </span>
                      <span class="link-card__cta"><?= e(t('common.open')) ?> <i class="fa-solid fa-arrow-up-right-from-square"></i></span>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </details>
        <?php endif; ?>
      </footer>

      <div class="overview-panel__section overview-panel__section--cream overview-panel--links" data-linked-tasks data-task-id="<?= (int)$task['id'] ?>">
        <header class="overview-panel__head" style="padding:0 0 var(--space-6);">
          <h2 class="overview-panel__title"><?= e(t('tasks.related')) ?></h2>
          <?php if ($canEdit): ?>
            <button class="btn-ghost" type="button" data-action="add-link" style="font-size:12px;">
              <i class="fa-solid fa-plus"></i> <?= e(t('tasks.add_link')) ?>
            </button>
          <?php endif; ?>
        </header>
        <div class="overview-panel__body" style="padding:0;">
          <div class="linked-tasks__list" data-linked-list>
            <?php if (empty($linkedTasks)): ?>
              <em class="overview-panel__empty" data-linked-empty><?= e(t('tasks.no_related')) ?></em>
            <?php else: ?>
              <?php foreach ($linkedTasks as $lt): require APP_ROOT . '/views/partials/linked-task-row.php'; endforeach; ?>
            <?php endif; ?>
          </div>
          <?php if ($canEdit): ?>
            <div class="linked-tasks__picker" data-linked-picker hidden>
              <div class="linked-tasks__search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="input input--inline" data-linked-search placeholder="<?= e(t('tasks.link_search')) ?>">
              </div>
              <div class="linked-tasks__results" data-linked-results></div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="overview-panel__section">
        <?php
          $entityType = 'task';
          $entityId = (int)$task['id'];
          $canPost = $canEdit;
          require APP_ROOT . '/views/partials/comment-thread.php';
        ?>
      </div>
    </section>
  </main>

  <aside class="task-sidebar" data-task-id="<?= (int)$task['id'] ?>" data-project-id="<?= (int)$project['id'] ?>"
         style="border:1px solid var(--rule);padding:18px;background:var(--paper);border-radius:4px;">
    <h3 style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.15em;color:var(--ink-3);margin:0 0 8px;"><?= e(t('projects.overview.details')) ?></h3>

    <div class="field" style="margin-bottom:7px;">
      <label style="font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.1em;"><?= e(t('projects.overview.status')) ?></label>
      <?php
        $currentCol = null;
        foreach ($columns as $c) { if ((int)$c['id'] === (int)$task['column_id']) { $currentCol = $c; break; } }
      ?>
      <div class="custom-select" data-custom-select>
        <button type="button" class="custom-select__btn">
          <span class="custom-select__icon"><span class="dot col-dot" style="background: <?= e($currentCol['color'] ?? '#8B7C68') ?>"></span></span>
          <span class="custom-select__label"><?= e($currentCol['name'] ?? '—') ?></span>
          <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
        </button>
        <div class="custom-select__pop" hidden>
          <?php foreach ($columns as $c): ?>
            <div class="custom-select__opt<?= (int)$c['id'] === (int)$task['column_id'] ? ' is-selected' : '' ?>" data-value="<?= (int)$c['id'] ?>">
              <span class="custom-select__icon"><span class="dot col-dot" style="background: <?= e($c['color']) ?>"></span></span>
              <span class="custom-select__opt-label"><?= e($c['name']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <input type="hidden" data-field="column_id" value="<?= (int)$task['column_id'] ?>">
      </div>
    </div>

    <div class="field" style="margin-bottom:7px;">
      <label style="font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.1em;"><?= e(t('tasks.assignee')) ?></label>
      <?php
        $currentAssignee = null;
        foreach ($members as $m) {
          if ((int)$m['id'] === (int)($task['assignee_id'] ?? 0)) { $currentAssignee = $m; break; }
        }
      ?>
      <div class="assignee-picker" data-assignee-picker>
        <input type="hidden" data-field="assignee_id" value="<?= (int)($task['assignee_id'] ?? 0) ?: '' ?>">
        <button type="button" class="assignee-picker__btn" data-toggle>
          <?php if ($currentAssignee): ?>
            <?= user_avatar_html((int)$currentAssignee['id'], $currentAssignee['name'], $currentAssignee['avatar'] ?? null, 'sm') ?>
            <span class="assignee-picker__name"><?= e($currentAssignee['name']) ?></span>
          <?php else: ?>
            <span class="user-avatar user-avatar--sm user-avatar--empty"><i class="fa-regular fa-circle"></i></span>
            <span class="assignee-picker__name assignee-picker__name--muted"><?= e(t('tasks.unassigned')) ?></span>
          <?php endif; ?>
          <i class="fa-solid fa-chevron-down assignee-picker__chevron"></i>
        </button>
        <div class="assignee-picker__pop" hidden>
          <div class="assignee-dropdown__row" data-assignee-id="">
            <span class="user-avatar user-avatar--sm user-avatar--empty"><i class="fa-regular fa-circle"></i></span>
            <span><?= e(t('tasks.unassigned')) ?></span>
          </div>
          <?php foreach ($members as $m): ?>
            <div class="assignee-dropdown__row" data-assignee-id="<?= (int)$m['id'] ?>">
              <?= user_avatar_html((int)$m['id'], $m['name'], $m['avatar'] ?? null, 'sm') ?>
              <span><?= e($m['name']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="field" style="margin-bottom:7px;">
      <label style="font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.1em;"><?= e(t('tasks.due_date')) ?></label>
      <input class="input" type="date" data-field="due_date" value="<?= e($task['due_date'] ?? '') ?>">
    </div>

    <div class="field" style="margin-bottom:7px;">
      <label style="font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.1em;"><?= e(t('tasks.priority')) ?></label>
      <?php
        $prioOpts = [
            'none'   => t('tasks.priority.none'),
            'low'    => t('tasks.priority.low'),
            'medium' => t('tasks.priority.medium'),
            'high'   => t('tasks.priority.high'),
            'urgent' => t('tasks.priority.urgent'),
        ];
        $curPrio  = $task['priority'] ?? 'none';
      ?>
      <div class="custom-select" data-custom-select data-update-attr="priority" data-priority="<?= e($curPrio) ?>">
        <button type="button" class="custom-select__btn">
          <span class="custom-select__icon"><span class="priority-dot"></span></span>
          <span class="custom-select__label"><?= e($prioOpts[$curPrio]) ?></span>
          <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
        </button>
        <div class="custom-select__pop" hidden>
          <?php foreach ($prioOpts as $pVal => $pLabel): ?>
            <div class="custom-select__opt<?= $curPrio === $pVal ? ' is-selected' : '' ?>" data-value="<?= e($pVal) ?>" data-priority="<?= e($pVal) ?>">
              <span class="custom-select__icon"><span class="priority-dot"></span></span>
              <span class="custom-select__opt-label"><?= e($pLabel) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <input type="hidden" data-field="priority" value="<?= e($curPrio) ?>">
      </div>
    </div>

    <div class="field" style="margin-bottom:7px;">
      <label style="font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.1em;"><?= e(t('tasks.tags')) ?></label>
      <?php
        $scope      = 'task';
        $entityType = 'task';
        $entityId   = (int)$task['id'];
        $current    = $taskTags ?? [];
        $all        = $allTaskTags ?? [];
        require APP_ROOT . '/views/partials/tag-picker.php';
      ?>
    </div>

    <div style="border-top:1px solid var(--rule);padding-top:6px;margin-top:9px;font-size:11px;color:var(--ink-3);">
      <div><?= e(t('tasks.created_by')) ?> <strong style="color:var(--ink-2);"><?= e($createdBy['name'] ?? t('tasks.unknown_user')) ?></strong></div>
      <div style="margin-top:4px;font-family:var(--font-mono);"><?= fmt_datetime($task['created_at']) ?></div>
    </div>

    <?php if (!empty($canCreateProject)): ?>
      <button class="btn-ghost" type="button" data-action="promote-to-project"
              style="margin-top:9px;width:100%;padding:8px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;display:inline-flex;align-items:center;justify-content:center;gap:6px;">
        <i class="fa-solid fa-up-right-from-square"></i> <?= e(t('tasks.promote_to_project')) ?>
      </button>
    <?php endif; ?>

    <?php if (!empty($canEditTask)): ?>
      <button class="btn-danger" type="button" data-action="delete-task"
              style="margin-top:5px;width:100%;padding:8px;font-size:12px;letter-spacing:.1em;text-transform:uppercase;">
        <?= e(t('tasks.delete_btn')) ?>
      </button>
    <?php endif; ?>
  </aside>
</div>

<script type="module" src="<?= e(asset_url('/assets/js/task-page.js')) ?>"></script>
