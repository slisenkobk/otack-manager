// Wires the destructive Compass buttons (Migrations · Cache tabs). Reads
// `data-compass-action` on a button + i18n-aware copy from `data-compass-*`
// attributes injected server-side, asks for confirmation via UI.confirm,
// POSTs to the matching endpoint, then toasts the server-returned `message`
// (already translated) and reloads.

const ACTIONS = {
  'clear-sessions': {
    url: '/admin/compass/cache/sessions/clear',
    danger: true,
  },
  'clear-uploads-orphans': {
    url: '/admin/compass/cache/uploads/orphans/clear',
    danger: true,
  },
  'bust-asset-cache': {
    url: '/admin/compass/cache/bust',
    danger: false,
  },
  'clear-error-log': {
    url: '/admin/compass/logs/clear',
    danger: true,
  },
  'prune-activity-log': {
    url: '/admin/compass/cache/activity-log/prune',
    danger: true,
  },
};

async function runAction(btn) {
  const key = btn.dataset.compassAction;
  const spec = ACTIONS[key];
  if (!spec) return;
  const confirmText  = btn.dataset.compassConfirm || 'Are you sure?';
  const confirmLabel = btn.dataset.compassConfirmLabel || 'OK';

  const ok = await UI.confirm(confirmText, {
    confirmLabel,
    danger: spec.danger,
  });
  if (!ok) return;

  btn.disabled = true;
  try {
    const data = await api(spec.url, { method: 'POST' });
    UI.toast(data.message || 'OK', 'success');
    setTimeout(() => window.location.reload(), 600);
  } catch (err) {
    btn.disabled = false;
  }
}

async function runMigrations(btn) {
  const confirmText  = btn.dataset.compassConfirm || 'Run pending migrations?';
  const confirmLabel = btn.dataset.compassConfirmLabel || 'Run';

  const ok = await UI.confirm(confirmText, { confirmLabel, danger: false });
  if (!ok) return;

  btn.disabled = true;
  try {
    const data = await api('/admin/compass/migrations/run', { method: 'POST' });
    UI.toast(data.message || 'OK', 'success');
    setTimeout(() => window.location.reload(), 600);
  } catch (err) {
    btn.disabled = false;
  }
}

document.querySelectorAll('[data-compass-action]').forEach(btn => {
  btn.addEventListener('click', () => runAction(btn));
});
document.querySelectorAll('[data-compass-run-migrations]').forEach(btn => {
  btn.addEventListener('click', () => runMigrations(btn));
});
document.querySelectorAll('[data-compass-auto-submit]').forEach(el => {
  el.addEventListener('change', () => el.form?.submit());
});
