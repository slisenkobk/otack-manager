import { api, UI } from './ui.js';

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
