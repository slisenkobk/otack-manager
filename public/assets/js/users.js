import { api, UI, buildCustomSelect } from './ui.js';
import { logSilent, t } from './utils.js';
import { buildField } from './ui-fields.js';

// Search form submits on Enter (native) or via the submit button.

function getLocales() {
  try {
    return JSON.parse(document.querySelector('meta[name=i18n-locales]')?.content || '[]');
  } catch {
    return [];
  }
}

// Wrapper preserves the `{ spaced: true }` styling without forcing every
// call site to pass it — every user modal field uses the spaced variant.
const F = (label, factory) => buildField(label, factory, { spaced: true });

function openUserModal({ title, name = '', email = '', locale = 'en', isEdit = false, onSubmit }) {
  const body = document.createElement('div');
  const nameF = F('Name', () => { const i = document.createElement('input'); i.className = 'input'; i.value = name; return i; });
  const emailF = F('Email', () => { const i = document.createElement('input'); i.className = 'input'; i.type = 'email'; i.value = email; if (isEdit) { i.disabled = true; i.style.opacity = '.6'; } return i; });
  const passF = F(isEdit ? 'New password (leave blank to keep)' : 'Password (min 8)', () => { const i = document.createElement('input'); i.className = 'input'; i.type = 'password'; i.minLength = 8; return i; });
  body.appendChild(nameF.wrap);
  body.appendChild(emailF.wrap);
  body.appendChild(passF.wrap);

  let roleF = null;
  if (!isEdit) {
    const roleItems = [
      { value: 'employee', label: 'Employee' },
      { value: 'manager',  label: 'Manager'  },
      { value: 'admin',    label: 'Admin'    },
    ];
    const built = buildCustomSelect(roleItems, 'employee');
    roleF = F('Role', () => built.root);
    roleF.hidden = built.hidden;
    body.appendChild(roleF.wrap);
  }

  // Language picker — both on create and edit. Admin sets the locale the
  // user will see; user can still change it themselves later via /profile.
  // Uses the same custom-select component as the timezone picker so the
  // dropdown is visually consistent with the rest of the chrome.
  const localeItems = getLocales().map(l => ({ value: l.code, label: l.name }));
  const localeBuilt = buildCustomSelect(localeItems.length ? localeItems : [{ value: locale, label: locale }], locale);
  const localeF = F('Language', () => localeBuilt.root);
  localeF.hidden = localeBuilt.hidden;
  body.appendChild(localeF.wrap);

  UI.modal({
    title,
    body,
    actions: [
      { label: 'Cancel', variant: 'btn-ghost', onClick: c => c() },
      { label: isEdit ? 'Save' : 'Create', variant: 'submit', onClick: async (close) => {
          const payload = { name: nameF.input.value.trim() };
          if (!isEdit) payload.email = emailF.input.value.trim();
          if (passF.input.value) payload.password = passF.input.value;
          if (roleF && roleF.hidden) payload.role = roleF.hidden.value;
          payload.locale = localeF.hidden ? localeF.hidden.value : locale;
          try {
            await onSubmit(payload);
            close();
            setTimeout(() => location.reload(), 400);
          } catch (e) { logSilent(e, 'users.submit'); }
        }
      },
    ],
  });
  setTimeout(() => nameF.input.focus(), 0);
}

document.querySelector('[data-action="new-user"]')?.addEventListener('click', () => {
  // New users default to the admin-configured default_locale via the server;
  // the picker just shows the current default as pre-selected.
  const defaultLocale = document.querySelector('meta[name=i18n-locale]')?.content || 'en';
  openUserModal({
    title: 'New user',
    isEdit: false,
    locale: defaultLocale,
    onSubmit: async (payload) => {
      await api('/users', { method: 'POST', body: JSON.stringify(payload) });
      UI.toast(t('js.toast.user_created'), 'success');
    },
  });
});

document.querySelectorAll('[data-user-id]').forEach(card => {
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
        UI.toast(t('js.toast.role_updated'), 'success');
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
            locale: card.dataset.userLocale || 'en',
            isEdit: true,
            onSubmit: async (payload) => {
              await api('/users/' + id, { method: 'POST', body: JSON.stringify(payload) });
              UI.toast(t('js.toast.user_updated'), 'success');
            },
          });
          return;
        }
        if (action === 'approve') {
          await api('/users/' + id + '/approve', { method: 'POST' });
          UI.toast(t('js.toast.approved'), 'success');
        } else if (action === 'block') {
          if (!await UI.confirm(t('js.confirm.block_user'), {danger: true})) return;
          await api('/users/' + id + '/block', { method: 'POST' });
          UI.toast(t('js.toast.blocked'), 'success');
        } else if (action === 'delete') {
          if (!await UI.confirm(t('js.confirm.delete_user'), {danger:true, confirmLabel:'Delete'})) return;
          await api('/users/' + id + '/delete', { method: 'POST' });
          UI.toast(t('js.toast.deleted'), 'success');
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
