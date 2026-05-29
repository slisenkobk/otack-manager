<?php
// Inputs: $t (task row), $tags (array of tag rows), $meta (['comments' => int, 'attachments' => int])
$prio = $t['priority'] ?? 'none';
$tags = $tags ?? [];
$meta = $meta ?? ['comments' => 0, 'attachments' => 0, 'links' => 0];
$meta['links'] = $meta['links'] ?? 0;
?>
<div class="kanban-card"
     data-task-id="<?= (int)$t['id'] ?>"
     data-task-url="/tasks/<?= (int)$t['id'] ?>"
     data-position="<?= (float)$t['position'] ?>"
     data-priority="<?= e($prio) ?>"
     data-assignee-id="<?= (int)($t['assignee_id'] ?? 0) ?>"
     data-tags="<?= e(implode(',', array_column($tags, 'name'))) ?>"
     data-title="<?= e(strtolower($t['title'])) ?>">
  <?php if ($tags || !empty($t['sub_status'])): ?>
    <div class="kanban-card__row">
      <?php foreach (array_slice($tags, 0, 2) as $tag): ?>
        <span class="kanban-card__tag tag"
              style="--tag: <?= e($tag['color']) ?>; --tag-bg: <?= e($tag['color']) ?>22;">
          <?= e($tag['name']) ?>
        </span>
      <?php endforeach; ?>
      <?php if (!empty($t['sub_status'])): ?>
        <span class="sub-status sub-status--<?= e($t['sub_status']) ?>" title="<?= e(ucfirst($t['sub_status'])) ?>">
          <?= e($t['sub_status'] === 'reopened' ? t('tasks.sub_status.reopened') : t('tasks.sub_status.returned')) ?>
        </span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="kanban-card__head">
    <span class="kanban-card__id"><?= e(t('tasks.id_prefix', ['id' => (int)$t['id']])) ?></span>
    <?php if ($prio !== 'none'): ?>
      <span class="kanban-card__prio" title="<?= e(ucfirst($prio)) ?> priority">
        <span class="priority-dot" aria-hidden="true"></span>
        <span class="kanban-card__prio-label"><?= e(ucfirst($prio)) ?></span>
      </span>
    <?php endif; ?>
  </div>
  <div class="kanban-card__title"><?= e($t['title']) ?></div>
  <?php if (!empty($t['assignee_id'])): ?>
    <div class="kanban-card__assignee">
      <?= user_avatar_html((int)$t['assignee_id'], $t['assignee_name'] ?? '?', $t['assignee_avatar'] ?? null, 'xs') ?>
      <span><?= e($t['assignee_name'] ?? '?') ?></span>
    </div>
  <?php endif; ?>
  <?php if (!empty($t['due_date']) || $meta['comments'] || $meta['attachments'] || $meta['links']): ?>
    <div class="kanban-card__meta">
      <?php if (!empty($t['due_date'])): ?>
        <span class="kanban-card__due">
          <i class="fa-regular fa-calendar"></i>
          <?= e(fmt_date($t['due_date'])) ?>
        </span>
      <?php else: ?>
        <span></span>
      <?php endif; ?>
      <?php if ($meta['comments'] || $meta['attachments'] || $meta['links']): ?>
        <span class="kanban-card__counts">
          <?php if ($meta['comments']): ?>
            <span><i class="fa-regular fa-comment"></i> <?= (int)$meta['comments'] ?></span>
          <?php endif; ?>
          <?php if ($meta['attachments']): ?>
            <span><i class="fa-solid fa-paperclip"></i> <?= (int)$meta['attachments'] ?></span>
          <?php endif; ?>
          <?php if ($meta['links']): ?>
            <span title="<?= e(t('tasks.linked_tasks')) ?>"><i class="fa-solid fa-link"></i> <?= (int)$meta['links'] ?></span>
          <?php endif; ?>
        </span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
