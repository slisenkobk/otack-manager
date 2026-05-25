<?php
/** Expects $a as an associative array shaped by DashboardController::formatActivity. */
$icon = match ($a['event']) {
    'comment.created'      => 'fa-regular fa-comment',
    'attachment.uploaded'  => 'fa-solid fa-paperclip',
    'task.status_changed'  => 'fa-solid fa-arrow-right-arrow-left',
    'task.created'         => 'fa-solid fa-plus',
    default                => 'fa-regular fa-circle-dot',
};
$verb = match ($a['event']) {
    'comment.created'      => 'commented',
    'attachment.uploaded'  => 'attached file',
    'task.status_changed'  => 'changed status',
    'task.created'         => 'created task',
    default                => 'updated',
};
?>
<div class="activity-row">
  <i class="activity-row__icon <?= e($icon) ?>" aria-hidden="true"></i>
  <span class="activity-row__time mono"><?= fmt_datetime($a['created_at']) ?></span>
  <span class="activity-row__actor"><?= e($a['actor_name']) ?></span>
  <span class="activity-row__verb"><?= e($verb) ?></span>
  <span class="activity-row__target">
    <?php if ($a['task_id'] && $a['task_url']): ?>
      <a class="activity-row__link" href="<?= e($a['task_url']) ?>"><?= e($a['task_title'] ?: ('Task #' . $a['task_id'])) ?></a>
      <?php if ($a['project_name'] && $a['project_url']): ?>
        <span class="activity-row__in">in</span>
        <a class="activity-row__link activity-row__link--muted" href="<?= e($a['project_url']) ?>"><?= e($a['project_name']) ?></a>
      <?php endif; ?>
    <?php elseif ($a['project_id'] && $a['project_url']): ?>
      <a class="activity-row__link" href="<?= e($a['project_url']) ?>"><?= e($a['project_name'] ?: ('Project #' . $a['project_id'])) ?></a>
    <?php endif; ?>
  </span>
  <?php if ($a['summary']): ?>
    <span class="activity-row__summary">— <?= e($a['summary']) ?></span>
  <?php endif; ?>
</div>
