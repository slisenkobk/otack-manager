<div class="section-head">
  <div class="num">01</div>
  <div class="title">All <span style="color:var(--brand);font-weight:600;">users</span></div>
  <div class="rule"></div>
  <div class="meta"><?= count($users) ?> total</div>
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

<script type="module">
import { api, UI } from '/assets/js/ui.js';

document.querySelectorAll('article[data-user-id]').forEach(card => {
  card.querySelectorAll('[data-action]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = card.dataset.userId;
      const action = btn.dataset.action;
      try {
        if (action === 'approve') {
          await api('/users/' + id + '/approve', { method: 'POST' });
          UI.toast('Approved', 'success');
        } else if (action === 'block') {
          if (!await UI.confirm('Block this user?', {danger: true})) return;
          await api('/users/' + id + '/block', { method: 'POST' });
          UI.toast('Blocked', 'success');
        } else if (action === 'toggle-role') {
          const next = btn.dataset.currentRole === 'admin' ? 'member' : 'admin';
          if (!await UI.confirm('Set role to ' + next + '?')) return;
          await api('/users/' + id + '/role', { method: 'POST', body: JSON.stringify({ role: next }) });
          UI.toast('Role updated', 'success');
        } else if (action === 'delete') {
          if (!await UI.confirm('Delete this user permanently?', {danger:true, confirmLabel:'Delete'})) return;
          await api('/users/' + id + '/delete', { method: 'POST' });
          UI.toast('Deleted', 'success');
          card.remove();
          return;
        }
        // Reload the row by reloading the page (simple)
        setTimeout(() => location.reload(), 600);
      } catch (err) {
        // api() already shows the error toast
      }
    });
  });
});
</script>
