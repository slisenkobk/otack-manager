<?php $canEdit = $canEdit ?? false; ?>
<div class="tag-picker" data-scope="<?= e($scope) ?>" data-entity-type="<?= e($entityType) ?>" data-entity-id="<?= (int)$entityId ?>">
  <div class="tag-chips">
    <?php foreach ($current as $t): ?>
      <span class="tag tag-picker__chip" data-tag-id="<?= (int)$t['id'] ?>"
            style="display:inline-flex;align-items:center;gap:6px;padding:3px 8px;background:<?= e($t['color']) ?>33;color:var(--ink);border-radius:999px;font-size:11px;font-family:var(--font-mono);letter-spacing:.05em;">
        <?= e($t['name']) ?>
        <?php if ($canEdit): ?>
          <button type="button" class="tag-picker__chip-remove" data-action="remove-tag">
            <i class="fa-solid fa-xmark"></i>
          </button>
        <?php endif; ?>
      </span>
    <?php endforeach; ?>
  </div>
  <?php if ($canEdit): ?>
    <div class="tag-input-wrap">
      <input class="input tag-picker__input" type="text" placeholder="+ Add tag&hellip;" data-tag-search>
      <div class="dropdown tag-picker__dropdown" data-tag-dropdown></div>
      <input type="hidden" data-all-tags value="<?= e(json_encode(array_values($all))) ?>">
    </div>
  <?php endif; ?>
</div>

<script type="module" src="/assets/js/tags.js"></script>
