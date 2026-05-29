import { api, UI } from './ui.js';

// Search form submits on Enter (native) or via the submit button.

function buildField(label, factory) {
  const wrap = document.createElement('div');
  wrap.className = 'field';
  wrap.style.marginBottom = '12px';
  const l = document.createElement('label');
  l.textContent = label;
  l.style.fontSize = '11px';
  l.style.textTransform = 'uppercase';
  l.style.letterSpacing = '.1em';
  l.style.color = 'var(--ink-3)';
  l.style.display = 'block';
  l.style.marginBottom = '4px';
  wrap.appendChild(l);
  const input = factory();
  wrap.appendChild(input);
  return { wrap, input };
}

function openUserModal({ title, name = '', email = '', isEdit = false, onSubmit }) {
  const body = document.createElement('div');
  const nameF = buildField('Name', () => { const i = document.createElement('input'); i.className = 'input'; i.value = name; return i; });
  const emailF = buildField('Email', () => { const i = document.createElement('input'); i.className = 'input'; i.type = 'email'; i.value = email; if (isEdit) { i.disabled = true; i.style.opacity = '.6'; } return i; });
  const passF = buildField(isEdit ? 'New password (leave blank to keep)' : 'Password (min 8)', () => { const i = document.createElement('input'); i.className = 'input'; i.type = 'password'; i.minLength = 8; return i; });
  body.appendChild(nameF.wrap);
  body.appendChild(emailF.wrap);
  body.appendChild(passF.wrap);
  let roleF = null;
  if (!isEdit) {
    roleF = buildField('Role', () => {
      const s = document.createElement('select');
      s.className = 'input';
      for (const [v, l] of [['employee', 'Employee'], ['manager', 'Manager'], ['admin', 'Admin']]) {
        const o = document.createElement('option');
        o.value = v; o.textContent = l;
        s.appendChild(o);
      }
      return s;
    });
    body.appendChild(roleF.wrap);
  }

  UI.modal({
    title,
    body,
    actions: [
      { label: 'Cancel', variant: 'btn-ghost', onClick: c => c() },
      { label: isEdit ? 'Save' : 'Create', variant: 'submit', onClick: async (close) => {
          const payload = { name: nameF.input.value.trim() };
          if (!isEdit) payload.email = emailF.input.value.trim();
          if (passF.input.value) payload.password = passF.input.value;
          if (roleF) payload.role = roleF.input.value;
          try {
            await onSubmit(payload);
            close();
            setTimeout(() => location.reload(), 400);
          } catch {}
        }
      },
    ],
  });
  setTimeout(() => nameF.input.focus(), 0);
}

document.querySelector('[data-action="new-user"]')?.addEventListener('click', () => {
  openUserModal({
    title: 'New user',
    isEdit: false,
    onSubmit: async (payload) => {
      await api('/users', { method: 'POST', body: JSON.stringify(payload) });
      UI.toast('User created', 'success');
    },
  });
});

document.querySelectorAll('article[data-user-id]').forEach(card => {
  const roleSelect = card.querySelector('[data-role-select]');
  if (roleSelect) {
    let prev = roleSelect.value;
    roleSelect.addEventListener('change', async () => {
      const next = roleSelect.value;
      const id = card.dataset.userId;
      if (!await UI.confirm('Set role to "' + next + '"?')) {
        roleSelect.value = prev;
        return;
      }
      try {
        await api('/users/' + id + '/role', { method: 'POST', body: JSON.stringify({ role: next }) });
        prev = next;
        UI.toast('Role updated', 'success');
      } catch {
        roleSelect.value = prev;
      }
    });
  }

  card.querySelectorAll('[data-action]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = card.dataset.userId;
      const action = btn.dataset.action;
      try {
        if (action === 'edit-user') {
          openUserModal({
            title: 'Edit user',
            name: card.querySelector('[data-user-name]')?.textContent.trim() || '',
            email: card.querySelector('[data-user-email]')?.textContent.trim() || '',
            isEdit: true,
            onSubmit: async (payload) => {
              await api('/users/' + id, { method: 'POST', body: JSON.stringify(payload) });
              UI.toast('User updated', 'success');
            },
          });
          return;
        }
        if (action === 'approve') {
          await api('/users/' + id + '/approve', { method: 'POST' });
          UI.toast('Approved', 'success');
        } else if (action === 'block') {
          if (!await UI.confirm('Block this user?', {danger: true})) return;
          await api('/users/' + id + '/block', { method: 'POST' });
          UI.toast('Blocked', 'success');
        } else if (action === 'delete') {
          if (!await UI.confirm('Delete this user permanently?', {danger:true, confirmLabel:'Delete'})) return;
          await api('/users/' + id + '/delete', { method: 'POST' });
          UI.toast('Deleted', 'success');
          card.remove();
          return;
        }
        setTimeout(() => location.reload(), 600);
      } catch (err) {
        // api() already shows the error toast
      }
    });
  });
});
