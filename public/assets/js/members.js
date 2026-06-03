import { api, UI } from './ui.js';
import { logSilent, t } from './utils.js';

document.querySelectorAll('.members-section').forEach(section => {
  const projectId = section.dataset.projectId;
  const search = section.querySelector('[data-member-search]');
  const dropdown = section.querySelector('[data-member-dropdown]');
  const hiddenAll = section.querySelector('[data-all-users]');

  // Remove member
  section.querySelectorAll('[data-action="remove-member"]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const userId = btn.dataset.userId;
      if (!await UI.confirm(t('js.confirm.remove_member'))) return;
      try {
        await api('/api/projects/' + projectId + '/members/' + userId + '/delete', { method: 'POST' });
        UI.toast(t('js.toast.member_removed'), 'success');
        location.reload();
      } catch (e) { logSilent(e, 'members.remove'); }
    });
  });

  if (!search) return;

  const allUsers = hiddenAll ? JSON.parse(hiddenAll.value) : [];
  const memberIds = new Set([...section.querySelectorAll('.member-chip')].map(c => +c.dataset.userId));

  function colorFor(id) {
    // Mirrors PHP user_color(): deterministic hue per user id.
    if (id <= 0) return '#9a9a9a';
    return 'hsl(' + ((id * 47) % 360) + ', 55%, 48%)';
  }

  function render(q) {
    dropdown.textContent = '';
    const lower = (q || '').toLowerCase();
    const matches = allUsers
      .filter(u => !memberIds.has(+u.id))
      .filter(u => !lower || u.name.toLowerCase().includes(lower) || u.email.toLowerCase().includes(lower))
      .slice(0, 12);
    if (!matches.length) {
      const empty = document.createElement('div');
      empty.className = 'member-dropdown__empty';
      empty.textContent = lower ? 'No matches' : 'All users are already members';
      dropdown.appendChild(empty);
      dropdown.style.display = 'block';
      return;
    }
    matches.forEach(u => {
      const row = document.createElement('div');
      row.className = 'member-dropdown__row';
      const av = document.createElement('span');
      av.className = 'user-avatar user-avatar--sm';
      if (u.avatar) {
        av.classList.add('user-avatar--img');
        const img = document.createElement('img');
        img.src = '/' + String(u.avatar).replace(/^\/+/, '');
        img.alt = u.name || '';
        img.loading = 'lazy';
        av.appendChild(img);
      } else {
        av.style.background = colorFor(+u.id);
        av.textContent = (u.name || '?').slice(0, 1).toUpperCase();
      }
      row.appendChild(av);
      const lbl = document.createElement('div');
      lbl.className = 'member-dropdown__label';
      const name = document.createElement('div');
      name.className = 'member-dropdown__name';
      name.textContent = u.name;
      const email = document.createElement('div');
      email.className = 'member-dropdown__email';
      email.textContent = u.email;
      lbl.appendChild(name);
      lbl.appendChild(email);
      row.appendChild(lbl);
      row.addEventListener('click', async () => {
        try {
          await api('/api/projects/' + projectId + '/members', {
            method: 'POST',
            body: JSON.stringify({ user_id: +u.id }),
          });
          UI.toast(t('js.toast.member_added'), 'success');
          location.reload();
        } catch (e) { logSilent(e, 'members.add'); }
      });
      dropdown.appendChild(row);
    });
    dropdown.style.display = 'block';
  }

  search.addEventListener('focus', () => render(search.value.trim()));
  search.addEventListener('input', () => render(search.value.trim()));
  document.addEventListener('click', e => {
    if (!section.contains(e.target)) dropdown.style.display = 'none';
  });
});
