<?php
$canEdit       = $canEdit ?? false;
$currentUserId = $currentUserId ?? 0;
$isAdmin       = $isAdmin ?? false;
?>
<div class="attachments-section" data-entity-type="<?= e($entityType) ?>" data-entity-id="<?= (int)$entityId ?>">
  <div class="attach-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px;">
    <?php foreach ($attachments as $a):
      $canDelete = $isAdmin || (int)$a['uploaded_by'] === (int)$currentUserId;
      $url = '/' . $a['filename'];
    ?>
      <article class="attach-item"
               data-attachment-id="<?= (int)$a['id'] ?>"
               data-is-image="<?= (int)$a['is_image'] ?>"
               data-url="<?= e($url) ?>"
               data-name="<?= e($a['original_name']) ?>"
               style="position:relative;border:1px solid var(--rule);border-radius:4px;overflow:hidden;background:var(--paper);">
        <?php if ($a['is_image']): ?>
          <a href="#" class="attach-img" data-action="lightbox" style="display:block;height:120px;">
            <img src="<?= e($url) ?>" alt="<?= e($a['original_name']) ?>" loading="lazy"
                 style="width:100%;height:100%;object-fit:cover;">
          </a>
        <?php else: ?>
          <a href="<?= e($url) ?>" download
             style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:120px;text-decoration:none;color:var(--ink-2);">
            <i class="fa-solid fa-file" style="font-size:32px;color:var(--ink-3);"></i>
            <div style="font-size:11px;margin-top:8px;font-family:var(--font-mono);text-align:center;padding:0 6px;word-break:break-all;line-height:1.3;">
              <?= e(mb_strimwidth($a['original_name'], 0, 24, '…')) ?>
            </div>
          </a>
        <?php endif; ?>
        <div style="position:absolute;bottom:0;left:0;right:0;background:rgba(26,22,18,0.85);color:var(--paper);padding:3px 6px;font-size:10px;font-family:var(--font-mono);display:flex;justify-content:space-between;align-items:center;">
          <span><?= fmt_size((int)$a['size']) ?></span>
          <?php if ($canDelete): ?>
            <button type="button" data-action="delete-attachment"
                    style="background:none;border:none;color:var(--paper);cursor:pointer;padding:0;">
              <i class="fa-solid fa-xmark"></i>
            </button>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($canEdit): ?>
    <div style="margin-top:16px;">
      <label class="btn-secondary" style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;">
        <i class="fa-solid fa-paperclip"></i> Attach file(s)
        <input type="file" multiple style="display:none;" data-attach-input>
      </label>
      <span class="muted" style="font-size:12px;color:var(--ink-3);margin-left:12px;">
        Images up to 5 MB; other files up to 50 MB. SVG not allowed.
      </span>
    </div>
  <?php endif; ?>
</div>

<script type="module" src="/assets/js/attachments.js"></script>
