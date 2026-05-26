<div class="members-section" data-project-id="<?= (int)$projectId ?>">
  <div style="display:flex;flex-direction:column;gap:6px;">
    <?php foreach ($members as $m): ?>
      <div class="member-chip" data-user-id="<?= (int)$m['id'] ?>"
           style="display:flex;align-items:center;gap:10px;padding:6px 10px;background:var(--paper);border:1px solid var(--rule);border-radius:6px;">
        <?= user_avatar_html((int)$m['id'], $m['name'], $m['avatar'] ?? null, 'sm') ?>
        <div style="flex:1;font-size:13px;">
          <?= e($m['name']) ?>
          <span class="muted" style="font-size:10px;color:var(--ink-3);"><?= e($m['role']) ?></span>
        </div>
        <?php if ($canEdit && $m['role'] !== 'owner'): ?>
          <button type="button" class="btn-icon" data-action="remove-member" data-user-id="<?= (int)$m['id'] ?>"
                  style="background:none;border:none;cursor:pointer;color:var(--ink-3);">
            <i class="fa-solid fa-xmark"></i>
          </button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($canEdit): ?>
    <div class="member-add" style="margin-top:12px;position:relative;">
      <input class="input" type="text" placeholder="+ Add member" data-member-search>
      <div class="dropdown" data-member-dropdown
           style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--paper);border:1px solid var(--rule);max-height:260px;overflow-y:auto;z-index:var(--z-dropdown);border-radius:var(--radius-md);box-shadow:var(--shadow-pop);">
      </div>
      <input type="hidden" data-all-users value="<?= e(json_encode(array_values($allUsers))) ?>">
    </div>
  <?php endif; ?>
</div>

<script type="module" src="/assets/js/members.js"></script>
