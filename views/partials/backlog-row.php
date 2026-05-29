<?php
// Inputs: $t (task), $tags, $meta
$tags = $tags ?? [];
$meta = $meta ?? ['comments' => 0, 'attachments' => 0, 'links' => 0];
$meta['links'] = $meta['links'] ?? 0;
?>
<li class="backlog__item">
  <a class="backlog__link" href="/tasks/<?= (int)$t['id'] ?>">
    <span class="backlog__id"><?= e(t('tasks.id_prefix', ['id' => (int)$t['id']])) ?></span>
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
    <?php if ($meta['comments'] || $meta['attachments'] || $meta['links']): ?>
      <span class="backlog__counts">
        <?php if ($meta['comments']): ?><span><i class="fa-regular fa-comment"></i> <?= (int)$meta['comments'] ?></span><?php endif; ?>
        <?php if ($meta['attachments']): ?><span><i class="fa-solid fa-paperclip"></i> <?= (int)$meta['attachments'] ?></span><?php endif; ?>
        <?php if ($meta['links']): ?><span title="<?= e(t('tasks.linked_tasks')) ?>"><i class="fa-solid fa-link"></i> <?= (int)$meta['links'] ?></span><?php endif; ?>
      </span>
    <?php endif; ?>
  </a>
</li>
