<?php
use App\Service\Markdown;
$currentUserId      = $currentUserId ?? null;
$isAdmin            = $isAdmin ?? false;
$commentAttachments = $commentAttachments ?? [];

// Group comments into a single-level tree: roots + replies keyed by parent id.
$roots   = [];
$replies = [];
foreach ($comments as $c) {
    $pid = (int)($c['parent_id'] ?? 0);
    if ($pid) { $replies[$pid][] = $c; } else { $roots[] = $c; }
}

$canPost = $canPost ?? false;
$renderComment = function (array $c, bool $isReply = false) use ($commentAttachments, $canPost, $currentUserId, $isAdmin) {
    $atts = $commentAttachments[(int)$c['id']] ?? [];
    $canDelete = $isAdmin || ((int)($c['user_id'] ?? 0) === (int)($currentUserId ?? 0));
    ?>
    <article class="comment<?= $isReply ? ' comment--reply' : '' ?>" data-comment-id="<?= (int)$c['id'] ?>">
      <?= user_avatar_html((int)$c['user_id'], $c['author_name'], $c['author_avatar'] ?? null, 'sm', ['extra_class' => 'comment__avatar']) ?>
      <div class="comment__main">
        <div class="comment-meta">
          <span class="comment-meta__name"><?= e($c['author_name']) ?></span>
          <?php if ($canPost): ?>
            <button type="button" class="comment-reply-btn" data-action="reply">
              <i class="fa-solid fa-reply"></i> <?= e(t('common.reply')) ?>
            </button>
          <?php endif; ?>
          <?php if ($canDelete): ?>
            <button type="button" class="comment-reply-btn comment-delete-btn" data-action="delete-comment" title="<?= e(t('common.delete')) ?>">
              <i class="fa-solid fa-trash"></i>
            </button>
          <?php endif; ?>
          <span class="comment-meta__time" data-iso="<?= e($c['created_at']) ?>"><?= fmt_datetime($c['created_at']) ?></span>
        </div>
        <div class="comment-body"><?= \App\Service\LinkPreview::enhance(Markdown::render($c['body'])) ?></div>
        <?php if ($atts): ?>
          <div class="comment-attachments">
            <?php foreach ($atts as $a): ?>
              <a class="comment-attachment" href="/<?= e($a['filename']) ?>" target="_blank" rel="noopener" title="<?= e($a['original_name']) ?>">
                <i class="fa-solid fa-<?= $a['is_image'] ? 'image' : 'paperclip' ?>"></i>
                <span><?= e($a['original_name']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </article>
    <?php
};
?>
<div class="comment-thread" data-entity-type="<?= e($entityType) ?>" data-entity-id="<?= (int)$entityId ?>">
  <h3 class="comment-thread__title"><?= e(t('tasks.comments')) ?></h3>
  <div class="comment-list">
    <?php foreach ($roots as $root): ?>
      <div class="comment-group" data-root-id="<?= (int)$root['id'] ?>">
        <?php $renderComment($root, false); ?>
        <?php $childList = $replies[(int)$root['id']] ?? []; ?>
        <div class="comment-replies"<?= $childList ? '' : ' hidden' ?>>
          <?php foreach ($childList as $r): $renderComment($r, true); endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($canPost): ?>
    <form class="comment-composer" data-composer="root">
      <textarea class="textarea" name="body" placeholder="<?= e(t('tasks.comment_placeholder')) ?>" rows="3"></textarea>
      <div class="comment-composer__row">
        <label class="btn btn--ghost comment-composer__attach">
          <i class="fa-solid fa-paperclip"></i> <?= e(t('attach.attach_files')) ?>
          <input type="file" multiple hidden data-comment-attach>
        </label>
        <button class="submit" type="submit"><?= e(t('tasks.post_comment')) ?></button>
      </div>
      <div class="comment-pending-attachments"></div>
    </form>
  <?php endif; ?>
</div>

<script type="module" src="/assets/js/comments.js"></script>
