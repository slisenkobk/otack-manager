<?php
use App\Service\Markdown;
$currentUserId = $currentUserId ?? null;
$isAdmin       = $isAdmin ?? false;
?>
<div class="comment-thread" data-entity-type="<?= e($entityType) ?>" data-entity-id="<?= (int)$entityId ?>">
  <h3 style="font-weight:600;font-size:14px;text-transform:uppercase;letter-spacing:.1em;color:var(--ink-3);margin:0 0 16px;">Comments</h3>
  <div class="comment-list" style="display:flex;flex-direction:column;gap:14px;">
    <?php foreach ($comments as $c): ?>
      <article class="comment" data-comment-id="<?= (int)$c['id'] ?>" style="background:var(--paper);border:1px solid var(--rule);padding:12px;border-radius:4px;">
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
      </article>
    <?php endforeach; ?>
  </div>
  <?php if ($canPost): ?>
    <form class="comment-composer" style="margin-top:16px;display:flex;flex-direction:column;gap:8px;">
      <textarea class="textarea" name="body" placeholder="Write a comment… **bold** `code` [link](https://…)" rows="3"></textarea>
      <div style="display:flex;justify-content:flex-end;">
        <button class="submit" type="submit">Post comment →</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<script type="module" src="/assets/js/comments.js"></script>
