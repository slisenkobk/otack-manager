<?php
/** @var array $lt linked task row */
$ltColor = $lt['column_color'] ?? '#8B7C68';
$ltName  = $lt['column_name']  ?? '—';
?>
<a class="linked-task" data-linked-id="<?= (int)$lt['id'] ?>" href="/tasks/<?= (int)$lt['id'] ?>">
  <span class="linked-task__id"><?= e(t('tasks.id_prefix', ['id' => (int)$lt['id']])) ?></span>
  <span class="linked-task__title"><?= e($lt['title']) ?></span>
  <span class="linked-task__status">
    <span class="dot col-dot" style="background: <?= e($ltColor) ?>"></span>
    <span><?= e($ltName) ?></span>
  </span>
  <?php if ($canEdit ?? true): ?>
    <button type="button" class="linked-task__remove" data-action="unlink" aria-label="<?= e(t('tasks.remove_link')) ?>" title="<?= e(t('tasks.remove_link')) ?>">
      <i class="fa-solid fa-xmark"></i>
    </button>
  <?php endif; ?>
</a>
