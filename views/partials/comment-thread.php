<?php
use App\Service\Markdown;
$currentUserId      = $currentUserId ?? null;
$isAdmin            = $isAdmin ?? false;
$commentAttachments = $commentAttachments ?? [];
?>
<div class="comment-thread" data-entity-type="<?= e($entityType) ?>" data-entity-id="<?= (int)$entityId ?>">
  <h3 style="font-weight:600;font-size:14px;text-transform:uppercase;letter-spacing:.1em;color:var(--ink-3);margin:0 0 16px;">Comments</h3>
  <div class="comment-list" style="display:flex;flex-direction:column;gap:14px;">
    <?php foreach ($comments as $c): ?>
      <article class="comment" data-comment-id="<?= (int)$c['id'] ?>">
        <div class="ini" style="width:32px;height:32px;font-size:11px;background:var(--ink);flex-shrink:0;margin-top:2px;"><?= e(mb_substr($c['author_name'], 0, 2)) ?></div>
        <div style="min-width:0;">
          <div class="comment-meta" style="display:flex;align-items:center;gap:10px;font-size:12px;color:var(--ink-3);margin-bottom:6px;">
            <span style="font-weight:600;color:var(--ink-2);"><?= e($c['author_name']) ?></span>
            <span><?= fmt_datetime($c['created_at']) ?></span>
            <?php if ($isAdmin || (int)$c['user_id'] === (int)$currentUserId): ?>
              <button type="button" data-action="delete-comment" style="margin-left:auto;background:none;border:none;cursor:pointer;color:var(--ink-3);font-size:12px;">
                <i class="fa-solid fa-xmark"></i>
              </button>
            <?php endif; ?>
          </div>
          <div class="comment-body" style="font-size:14px;line-height:1.55;"><?= Markdown::render($c['body']) ?></div>
          <?php $atts = $commentAttachments[(int)$c['id']] ?? []; ?>
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
    <?php endforeach; ?>
  </div>
  <?php if ($canPost): ?>
    <form class="comment-composer" style="margin-top:16px;display:flex;flex-direction:column;gap:8px;">
      <textarea class="textarea" name="body" placeholder="Write a comment… **bold** `code` [link](https://…)" rows="3"></textarea>
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <label class="btn-ghost" style="cursor:pointer;font-size:12px;">
          <i class="fa-solid fa-paperclip"></i> Attach files
          <input type="file" multiple style="display:none;" data-comment-attach>
        </label>
        <button class="submit" type="submit">Post comment →</button>
      </div>
      <div class="comment-pending-attachments" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:4px;"></div>
    </form>
  <?php endif; ?>
</div>

<script type="module" src="/assets/js/comments.js"></script>
