<div class="page-actions" style="margin-bottom:var(--space-8);">
  <form method="get" action="/users" class="kanban-search kanban-search--with-submit" style="max-width:360px;margin:0;">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input class="input input--inline" name="q" placeholder="Search users by name or email…" value="<?= e($query ?? '') ?>" data-user-search>
    <button type="submit" class="kanban-search__submit" aria-label="Search"><i class="fa-solid fa-arrow-right"></i></button>
  </form>
  <button type="button" class="btn btn--secondary" data-action="new-user">
    <i class="fa-solid fa-user-plus"></i> New user
  </button>
</div>

<?php if (!$users): ?>
  <div class="empty-state">
    <span class="empty-state__tag">Otack Manager</span>
    <p class="empty-state__text"><?= !empty($query) ? 'No users match "' . e($query) . '".' : 'No users yet.' ?></p>
  </div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:12px;">
  <?php foreach ($users as $u): ?>
    <article class="card" data-user-id="<?= (int)$u['id'] ?>" style="flex-direction:row;align-items:center;gap:18px;min-height:auto;">
      <?php if ($u['status'] === 'pending'): ?>
        <span class="corner-tag" style="background:var(--brand);">PENDING</span>
      <?php endif; ?>
      <?= user_avatar_html((int)$u['id'], $u['name'], $u['avatar'] ?? null, 'md') ?>
      <div style="flex:1;min-width:0;">
        <div data-user-name style="font-size:16px;font-weight:600;"><?= e($u['name']) ?></div>
        <div data-user-email style="font-size:13px;color:var(--ink-3);font-family:var(--font-mono);"><?= e($u['email']) ?></div>
      </div>
      <span class="status<?= $u['status'] === 'approved' ? ' is-ready' : '' ?>"><?= e($u['status']) ?></span>
      <?php if ((int)$u['id'] !== (int)$currentUserId): ?>
        <select class="input input--inline" data-role-select style="width:auto;font-size:11px;padding:4px 8px;">
          <?php foreach (['admin' => 'Admin', 'manager' => 'Manager', 'employee' => 'Employee'] as $rVal => $rLabel): ?>
            <option value="<?= e($rVal) ?>"<?= $u['role'] === $rVal ? ' selected' : '' ?>><?= e($rLabel) ?></option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <span class="mono" style="font-size:11px;color:var(--ink-3);"><?= e($u['role']) ?></span>
      <?php endif; ?>
      <span class="mono" style="font-size:10px;color:var(--ink-3);"><?= fmt_date($u['created_at']) ?></span>
      <div style="display:flex;gap:6px;" data-actions>
        <?php if ($u['status'] === 'pending'): ?>
          <button class="btn-secondary" data-action="approve" type="button" title="Approve">
            <i class="fa-solid fa-check"></i> Approve
          </button>
        <?php endif; ?>
        <button class="btn-secondary" data-action="edit-user" type="button" title="Edit user">
          <i class="fa-solid fa-pen"></i>
        </button>
        <?php if ($u['status'] !== 'blocked' && (int)$u['id'] !== (int)$currentUserId): ?>
          <button class="btn-secondary" data-action="block" type="button" title="Block">
            <i class="fa-solid fa-ban"></i>
          </button>
        <?php endif; ?>
        <?php if ((int)$u['id'] !== (int)$currentUserId): ?>
          <button class="btn-danger" data-action="delete" type="button" title="Delete">
            <i class="fa-solid fa-trash"></i>
          </button>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php
  $hrefBuilder = function (int $p) use ($query) {
      return '/users?page=' . $p . (!empty($query) ? '&q=' . urlencode($query) : '');
  };
  require APP_ROOT . '/views/partials/pagination.php';
?>
<?php endif; ?>

<script type="module" src="/assets/js/users.js"></script>
