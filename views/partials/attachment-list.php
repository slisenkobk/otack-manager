<?php
$canEdit       = $canEdit ?? false;
$currentUserId = $currentUserId ?? 0;
$isAdmin       = $isAdmin ?? false;
$readOnly      = $readOnly ?? false; // disables per-item delete button and the upload field
?>
<div class="attachments-section" data-entity-type="<?= e($entityType) ?>" data-entity-id="<?= (int)$entityId ?>">
  <div class="attach-grid">
    <?php foreach ($attachments as $a):
      $canDelete = $isAdmin || (int)$a['uploaded_by'] === (int)$currentUserId;
      $url = '/' . $a['filename'];
      $kind = attach_kind($a);
    ?>
      <article class="attach-item attach-item--<?= e($kind) ?><?= !empty($a['comment_id']) ? ' attach-item--from-comment' : '' ?>"
               data-attachment-id="<?= (int)$a['id'] ?>"
               data-is-image="<?= (int)$a['is_image'] ?>"
               data-url="<?= e($url) ?>"
               data-name="<?= e($a['original_name']) ?>"
               <?= !empty($a['comment_id']) ? 'title="' . e(t('attach.from_comment')) . '"' : '' ?>>
        <div class="attach-item__top">
          <?php if ($kind === 'image'): ?>
            <a href="#" class="attach-item__action" data-action="lightbox" title="<?= e(t('tasks.view_image')) ?>">
              <i class="fa-solid fa-magnifying-glass-plus"></i> <?= e(t('attach.view')) ?>
            </a>
          <?php elseif ($kind === 'viewable'): ?>
            <a href="<?= e($url) ?>" target="_blank" rel="noopener" class="attach-item__action" title="<?= e(t('tasks.open_in_browser')) ?>">
              <i class="fa-solid fa-arrow-up-right-from-square"></i> <?= e(t('attach.open')) ?>
            </a>
          <?php else: ?>
            <a href="<?= e($url) ?>" download class="attach-item__action" title="<?= e(t('tasks.download')) ?>">
              <i class="fa-solid fa-download"></i> <?= e(t('attach.download_btn')) ?>
            </a>
          <?php endif; ?>
        </div>
        <?php if ($kind === 'image'): ?>
          <a href="#" class="attach-item__media" data-action="lightbox">
            <img src="<?= e($url) ?>" alt="<?= e($a['original_name']) ?>" loading="lazy">
          </a>
        <?php else: ?>
          <div class="attach-item__media attach-item__media--icon">
            <i class="<?= attach_icon($kind, (string)$a['mime']) ?>"></i>
            <div class="attach-item__name"><?= e(mb_strimwidth($a['original_name'], 0, 24, '…')) ?></div>
          </div>
        <?php endif; ?>
        <div class="attach-item__foot">
          <span><?= fmt_size((int)$a['size']) ?></span>
          <?php if ($canDelete && !$readOnly): ?>
            <button type="button" class="attach-item__del" data-action="delete-attachment" aria-label="<?= e(t('common.delete')) ?>">
              <i class="fa-solid fa-xmark"></i>
            </button>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($canEdit): ?>
    <div style="margin-top:16px;">
      <label class="btn--secondary" style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;">
        <i class="fa-solid fa-paperclip"></i> <?= e(t('attach.attach_files')) ?>
        <input type="file" multiple data-attach-input class="d-none">
      </label>
      <span class="muted" style="font-size:12px;color:var(--ink-3);margin-left:12px;">
        <?= e(t('attach.size_hint')) ?>
      </span>
    </div>
  <?php endif; ?>
</div>

<script type="module" src="<?= e(asset_url('/assets/js/attachments.js')) ?>"></script>
