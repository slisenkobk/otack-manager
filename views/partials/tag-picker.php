<?php $canEdit = $canEdit ?? false; ?>
<div class="tag-picker" data-scope="<?= e($scope) ?>" data-entity-type="<?= e($entityType) ?>" data-entity-id="<?= (int)$entityId ?>">
  <div class="tag-chips" style="display:flex;flex-wrap:wrap;gap:6px;">
    <?php foreach ($current as $t): ?>
      <span class="tag" data-tag-id="<?= (int)$t['id'] ?>"
            style="display:inline-flex;align-items:center;gap:6px;padding:3px 8px;background:<?= e($t['color']) ?>33;color:var(--ink);border-radius:999px;font-size:11px;font-family:var(--font-mono);letter-spacing:.05em;">
        <?= e($t['name']) ?>
        <?php if ($canEdit): ?>
          <button type="button" data-action="remove-tag"
                  style="background:none;border:none;cursor:pointer;color:var(--ink-3);padding:0;font-size:10px;">
            <i class="fa-solid fa-xmark"></i>
          </button>
        <?php endif; ?>
      </span>
    <?php endforeach; ?>
  </div>
  <?php if ($canEdit): ?>
    <div class="tag-input-wrap" style="margin-top:8px;position:relative;">
      <input class="input" type="text" placeholder="+ Add tag&hellip;" data-tag-search style="font-size:13px;">
      <div class="dropdown" data-tag-dropdown
           style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--paper);border:1px solid var(--rule);max-height:200px;overflow-y:auto;z-index:10;margin-top:4px;border-radius:4px;"></div>
      <input type="hidden" data-all-tags value="<?= e(json_encode(array_values($all))) ?>">
    </div>
  <?php endif; ?>
</div>

<script type="module" src="/assets/js/tags.js"></script>
