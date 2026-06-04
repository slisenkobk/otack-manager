import { api, UI } from './ui.js';
import { withButtonBusy } from './utils.js';

// "Check now" button in Settings → Updates. Hits the API, refreshes the
// section in place, and surfaces errors as toasts (GitHub rate-limit /
// wrong repo URL are the common ones).
const root = document.querySelector('[data-updates-section]');
if (root) {
  const btn   = root.querySelector('[data-action=check-now]');
  if (btn) btn.addEventListener('click', () => withButtonBusy(btn, async () => {
    try {
      await api('/api/updates/check');
      UI.toast(btn.dataset.toastOk || 'Updates checked', 'success');
      setTimeout(() => location.reload(), 350);
    } catch (e) {
      UI.toast(btn.dataset.toastError || 'Check failed — see server log', 'error');
    }
  }));

  // "Update now" — gated by a hard confirmation modal, then the hidden
  // <form> POSTs to /admin/updates/run. We deliberately use a full form
  // post (not fetch) so the browser shows a loading state while the
  // pipeline runs and the server-rendered flash arrives on redirect.
  const runBtn = root.querySelector('[data-action=run-update]');
  const form   = root.querySelector('[data-update-form]');
  if (runBtn && form) {
    wireConfirmedSubmit(runBtn, form, { danger: false });
  }

  // Restore buttons live in the Backups table — there may be many.
  // Each one sits inside its own form; binding is per-button.
  root.querySelectorAll('[data-action=run-restore]').forEach(btn => {
    const f = btn.closest('form[data-restore-form]');
    if (f) wireConfirmedSubmit(btn, f, { danger: true });
  });
}

function wireConfirmedSubmit(btn, form, { danger }) {
  btn.addEventListener('click', async () => {
    const ok = await UI.confirm(
      btn.dataset.confirmBody || 'Are you sure?',
      {
        title: btn.dataset.confirmTitle || 'Confirm',
        confirmLabel: btn.dataset.confirmLabel || 'Confirm',
        cancelLabel:  btn.dataset.cancelLabel  || 'Cancel',
        danger,
      }
    );
    if (!ok) return;
    btn.disabled = true;
    btn.replaceChildren();
    const spinner = document.createElement('i');
    spinner.className = 'fa-solid fa-spinner fa-spin';
    spinner.setAttribute('aria-hidden', 'true');
    btn.append(spinner, ' ' + (btn.dataset.runningLabel || 'Working…'));
    form.submit();
  });
}
