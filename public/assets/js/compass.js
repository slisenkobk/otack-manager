// Wires the destructive Compass buttons (Migrations · Cache tabs). Reads
// `data-compass-action` on a button, asks for confirmation via UI.confirm,
// POSTs to the matching admin endpoint, then toasts + reloads on success.

const ACTIONS = {
  'clear-sessions': {
    url: '/admin/compass/cache/sessions/clear',
    confirm: (btn) => {
      const n = parseInt(btn.dataset.compassCount || '0', 10);
      return `Delete ${n} stale session file${n === 1 ? '' : 's'}? Users with active sessions are unaffected.`;
    },
    confirmLabel: 'Delete',
    success: (data) => `Cleared ${data.deleted} session${data.deleted === 1 ? '' : 's'}`,
  },
  'clear-uploads-orphans': {
    url: '/admin/compass/cache/uploads/orphans/clear',
    confirm: (btn) => {
      const n = parseInt(btn.dataset.compassCount || '0', 10);
      return `Delete ${n} orphan upload${n === 1 ? '' : 's'}? Files referenced by attachments are kept.`;
    },
    confirmLabel: 'Delete',
    success: (data) => `Deleted ${data.deleted} orphan file${data.deleted === 1 ? '' : 's'}`,
  },
  'bust-asset-cache': {
    url: '/admin/compass/cache/bust',
    confirm: () => 'Force every visitor\'s browser to re-download CSS and JS on next page load?',
    confirmLabel: 'Bump version',
    success: (data) => `Asset version bumped to ${data.version}`,
  },
};

async function runAction(btn) {
  const key = btn.dataset.compassAction;
  const spec = ACTIONS[key];
  if (!spec) return;

  const ok = await UI.confirm(spec.confirm(btn), {
    confirmLabel: spec.confirmLabel,
    danger: spec.confirmLabel === 'Delete',
  });
  if (!ok) return;

  btn.disabled = true;
  try {
    const data = await api(spec.url, { method: 'POST' });
    UI.toast(spec.success(data), 'success');
    setTimeout(() => window.location.reload(), 600);
  } catch (err) {
    btn.disabled = false;
  }
}

async function runMigrations(btn) {
  const ok = await UI.confirm(
    'Run all pending migrations now? This wraps everything in one BEGIN IMMEDIATE.',
    { confirmLabel: 'Run', danger: false }
  );
  if (!ok) return;

  btn.disabled = true;
  try {
    const data = await api('/admin/compass/migrations/run', { method: 'POST' });
    const n = (data.applied || []).length;
    UI.toast(
      n === 0 ? 'Schema already up to date' : `Applied ${n} migration${n === 1 ? '' : 's'}`,
      'success'
    );
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
