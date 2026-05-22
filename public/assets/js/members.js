import { api, UI } from './ui.js';

document.querySelectorAll('.members-section').forEach(section => {
  const projectId = section.dataset.projectId;
  const search = section.querySelector('[data-member-search]');
  const dropdown = section.querySelector('[data-member-dropdown]');
  const hiddenAll = section.querySelector('[data-all-users]');

  // Remove member
  section.querySelectorAll('[data-action="remove-member"]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const userId = btn.dataset.userId;
      if (!await UI.confirm('Remove this member?')) return;
      try {
        await api('/api/projects/' + projectId + '/members/' + userId + '/delete', { method: 'POST' });
        UI.toast('Member removed', 'success');
        location.reload();
      } catch {}
    });
  });

  if (!search) return;

  const allUsers = hiddenAll ? JSON.parse(hiddenAll.value) : [];
  const memberIds = new Set([...section.querySelectorAll('.member-chip')].map(c => +c.dataset.userId));

  function render(q) {
    dropdown.textContent = '';
    if (!q) { dropdown.style.display = 'none'; return; }
    const lower = q.toLowerCase();
    const matches = allUsers.filter(u =>
      !memberIds.has(+u.id) &&
      (u.name.toLowerCase().includes(lower) || u.email.toLowerCase().includes(lower))
    ).slice(0, 8);
    if (!matches.length) { dropdown.style.display = 'none'; return; }
    matches.forEach(u => {
      const row = document.createElement('div');
      row.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--rule);';
      row.textContent = u.name + ' · ' + u.email;
      row.addEventListener('mouseenter', () => row.style.background = 'var(--paper-2)');
      row.addEventListener('mouseleave', () => row.style.background = '');
      row.addEventListener('click', async () => {
        try {
          await api('/api/projects/' + projectId + '/members', {
            method: 'POST',
            body: JSON.stringify({ user_id: +u.id }),
          });
          UI.toast('Member added', 'success');
          location.reload();
        } catch {}
      });
      dropdown.appendChild(row);
    });
    dropdown.style.display = 'block';
  }

  search.addEventListener('input', () => render(search.value.trim()));
  document.addEventListener('click', e => {
    if (!section.contains(e.target)) dropdown.style.display = 'none';
  });
});
