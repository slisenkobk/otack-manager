<div class="members-section" data-project-id="<?= (int)$projectId ?>">
  <div class="members-list">
    <?php foreach ($members as $m): ?>
      <div class="member-chip" data-user-id="<?= (int)$m['id'] ?>">
        <?= user_avatar_html((int)$m['id'], $m['name'], $m['avatar'] ?? null, 'sm') ?>
        <div class="member-chip__name">
          <?= e($m['name']) ?>
          <span class="muted fz-10 text-ink-3"><?= e($m['role']) ?></span>
        </div>
        <?php if ($canEdit && $m['role'] !== 'owner'): ?>
          <button type="button" class="btn-icon member-chip__remove" data-action="remove-member" data-user-id="<?= (int)$m['id'] ?>">
            <i class="fa-solid fa-xmark"></i>
          </button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($canEdit): ?>
    <div class="member-add">
      <input class="input" type="text" placeholder="+ Add member" data-member-search>
      <div class="dropdown member-add__dropdown" data-member-dropdown></div>
      <input type="hidden" data-all-users value="<?= e(json_encode(array_values($allUsers))) ?>">
    </div>
  <?php endif; ?>
</div>

<script type="module" src="/assets/js/members.js"></script>
