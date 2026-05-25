<?php
$canEdit       = $canEdit ?? false;
$currentUserId = $currentUserId ?? 0;
$isAdmin       = $isAdmin ?? false;
?>
<?php
function attach_kind(array $a): string {
    if ((int)($a['is_image'] ?? 0) === 1) return 'image';
    $mime = (string)($a['mime'] ?? '');
    $name = strtolower((string)($a['original_name'] ?? ''));
    if (preg_match('/(zip|rar|7z|tar|gz|bz2)$/', $name)) return 'archive';
    if (in_array($mime, ['application/zip', 'application/x-7z-compressed', 'application/x-rar-compressed', 'application/x-tar', 'application/gzip'], true)) return 'archive';
    if ($mime === 'application/pdf' || str_starts_with($mime, 'text/')) return 'viewable';
    if ($mime === 'application/json' || $mime === 'application/xml') return 'viewable';
    return 'download';
}
function attach_icon(string $kind, string $mime): string {
    if ($kind === 'archive') return 'fa-solid fa-file-zipper';
    if ($mime === 'application/pdf') return 'fa-solid fa-file-pdf';
    if (str_starts_with($mime, 'text/')) return 'fa-solid fa-file-lines';
    return 'fa-solid fa-file';
}
?>
<div class="attachments-section" data-entity-type="<?= e($entityType) ?>" data-entity-id="<?= (int)$entityId ?>">
  <div class="attach-grid">
    <?php foreach ($attachments as $a):
      $canDelete = $isAdmin || (int)$a['uploaded_by'] === (int)$currentUserId;
      $url = '/' . $a['filename'];
      $kind = attach_kind($a);
    ?>
      <article class="attach-item attach-item--<?= e($kind) ?>"
               data-attachment-id="<?= (int)$a['id'] ?>"
               data-is-image="<?= (int)$a['is_image'] ?>"
               data-url="<?= e($url) ?>"
               data-name="<?= e($a['original_name']) ?>">
        <?php if ($kind === 'image'): ?>
          <a href="#" class="attach-item__media" data-action="lightbox">
            <img src="<?= e($url) ?>" alt="<?= e($a['original_name']) ?>" loading="lazy">
          </a>
        <?php elseif ($kind === 'viewable'): ?>
          <a href="<?= e($url) ?>" target="_blank" rel="noopener" class="attach-item__media attach-item__media--icon"
             title="Open in browser">
            <i class="<?= attach_icon($kind, (string)$a['mime']) ?>"></i>
            <div class="attach-item__name"><?= e(mb_strimwidth($a['original_name'], 0, 24, '…')) ?></div>
            <span class="attach-item__cta"><i class="fa-solid fa-arrow-up-right-from-square"></i> open</span>
          </a>
        <?php else: ?>
          <a href="<?= e($url) ?>" download class="attach-item__media attach-item__media--icon"
             title="Download">
            <i class="<?= attach_icon($kind, (string)$a['mime']) ?>"></i>
            <div class="attach-item__name"><?= e(mb_strimwidth($a['original_name'], 0, 24, '…')) ?></div>
            <span class="attach-item__cta"><i class="fa-solid fa-download"></i> download</span>
          </a>
        <?php endif; ?>
        <div class="attach-item__foot">
          <span><?= fmt_size((int)$a['size']) ?></span>
          <?php if ($canDelete): ?>
            <button type="button" class="attach-item__del" data-action="delete-attachment" aria-label="Delete">
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
