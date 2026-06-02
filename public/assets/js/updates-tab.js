import { api, UI } from './ui.js';

// "Check now" button in Settings → Updates. Hits the API, refreshes the
// section in place, and surfaces errors as toasts (GitHub rate-limit /
// wrong repo URL are the common ones).
const root = document.querySelector('[data-updates-section]');
if (root) {
  const btn   = root.querySelector('[data-action=check-now]');
  if (btn) btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      await api('/api/updates/check');
      UI.toast(btn.dataset.toastOk || 'Updates checked', 'success');
      setTimeout(() => location.reload(), 350);
    } catch (e) {
      UI.toast(btn.dataset.toastError || 'Check failed — see server log', 'error');
    } finally {
      btn.disabled = false;
    }
  });

  // "Update now" — gated by a hard confirmation modal, then the hidden
  // <form> POSTs to /admin/updates/run. We deliberately use a full form
  // post (not fetch) so the browser shows a loading state while the
  // pipeline runs and the server-rendered flash arrives on redirect.
  const runBtn = root.querySelector('[data-action=run-update]');
  const form   = root.querySelector('[data-update-form]');
  if (runBtn && form) {
    runBtn.addEventListener('click', async () => {
      const ok = await UI.confirm(
        runBtn.dataset.confirmBody || 'Run the update now? Your data will not be touched.',
        {
          title: runBtn.dataset.confirmTitle || 'Confirm update',
          confirmLabel: runBtn.dataset.confirmLabel || 'Update now',
          cancelLabel:  runBtn.dataset.cancelLabel  || 'Cancel',
        }
      );
      if (!ok) return;
      runBtn.disabled = true;
      runBtn.replaceChildren();
      const spinner = document.createElement('i');
      spinner.className = 'fa-solid fa-spinner fa-spin';
      spinner.setAttribute('aria-hidden', 'true');
      runBtn.append(spinner, ' ' + (runBtn.dataset.runningLabel || 'Updating…'));
      form.submit();
    });
  }
}
