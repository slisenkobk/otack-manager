<div class="kanban-search" style="max-width:360px;margin-bottom:var(--space-8);">
  <i class="fa-solid fa-magnifying-glass"></i>
  <input class="input input--inline" placeholder="Search users by name or email…" data-user-search>
</div>

<div style="display:flex;flex-direction:column;gap:12px;">
  <?php foreach ($users as $u): ?>
    <article class="card" data-user-id="<?= (int)$u['id'] ?>" style="flex-direction:row;align-items:center;gap:18px;min-height:auto;">
      <?php if ($u['status'] === 'pending'): ?>
        <span class="corner-tag" style="background:var(--brand);">PENDING</span>
      <?php endif; ?>
      <div class="ini" style="background:var(--ink);"><?= e(mb_substr($u['name'], 0, 2)) ?></div>
      <div style="flex:1;min-width:0;">
        <div style="font-size:16px;font-weight:600;"><?= e($u['name']) ?></div>
        <div style="font-size:13px;color:var(--ink-3);font-family:var(--font-mono);"><?= e($u['email']) ?></div>
      </div>
      <span class="status<?= $u['status'] === 'approved' ? ' is-ready' : '' ?>"><?= e($u['status']) ?></span>
      <span class="mono" style="font-size:11px;color:var(--ink-3);"><?= e($u['role']) ?></span>
      <span class="mono" style="font-size:10px;color:var(--ink-3);"><?= fmt_date($u['created_at']) ?></span>
      <div style="display:flex;gap:8px;" data-actions>
        <?php if ($u['status'] === 'pending'): ?>
          <button class="btn-secondary" data-action="approve" type="button">Approve</button>
        <?php endif; ?>
        <?php if ($u['status'] !== 'blocked' && (int)$u['id'] !== (int)$currentUserId): ?>
          <button class="btn-secondary" data-action="block" type="button">Block</button>
        <?php endif; ?>
        <?php if ((int)$u['id'] !== (int)$currentUserId): ?>
          <button class="btn-secondary" data-action="toggle-role" data-current-role="<?= e($u['role']) ?>" type="button">
            <?= $u['role'] === 'admin' ? 'Make member' : 'Make admin' ?>
          </button>
          <button class="btn-danger" data-action="delete" type="button">Delete</button>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<script type="module" src="/assets/js/users.js"></script>
