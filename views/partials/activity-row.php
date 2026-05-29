<?php
/** Expects $a as an associative array shaped by DashboardController::formatActivity. */
$icon = match ($a['event']) {
    'comment.created'      => 'fa-regular fa-comment',
    'comment.deleted'      => 'fa-regular fa-comment-slash',
    'attachment.uploaded'  => 'fa-solid fa-paperclip',
    'task.status_changed'  => 'fa-solid fa-arrow-right-arrow-left',
    'task.created'         => 'fa-solid fa-plus',
    'task.deleted'         => 'fa-solid fa-trash',
    'task.linked'          => 'fa-solid fa-link',
    'task.unlinked'        => 'fa-solid fa-link-slash',
    'project.deleted'      => 'fa-solid fa-folder-minus',
    'form.submitted'       => 'fa-solid fa-fire',
    'form.created'         => 'fa-solid fa-clipboard-list',
    'form.updated'         => 'fa-solid fa-pen',
    'form.deleted'         => 'fa-solid fa-trash',
    default                => 'fa-regular fa-circle-dot',
};
$verb = match ($a['event']) {
    'comment.created'      => 'commented',
    'comment.deleted'      => 'deleted comment',
    'attachment.uploaded'  => 'attached file',
    'task.status_changed'  => 'changed status',
    'task.created'         => 'created task',
    'task.deleted'         => 'deleted task',
    'task.linked'          => 'linked',
    'task.unlinked'        => 'unlinked',
    'project.deleted'      => 'deleted project',
    'form.submitted'       => 'submitted form',
    'form.created'         => 'created form',
    'form.updated'         => 'updated form',
    'form.deleted'         => 'deleted form',
    default                => 'updated',
};
// External form submissions: actor isn't a logged-in user. Override the
// actor label with a neutral "Visitor" tag so it doesn't misattribute the
// action to the form's owner (who is just the activity scope holder).
$isExternal = $a['event'] === 'form.submitted';
$actorName  = $isExternal ? 'Visitor' : $a['actor_name'];
?>
<div class="activity-row<?= $isExternal ? ' activity-row--important' : '' ?>">
  <i class="activity-row__icon <?= e($icon) ?>" aria-hidden="true"></i>
  <span class="activity-row__time mono"><?= fmt_datetime($a['created_at']) ?></span>
  <span class="activity-row__actor"><?= e($actorName) ?></span>
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
