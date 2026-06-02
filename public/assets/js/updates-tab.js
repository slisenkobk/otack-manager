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
      UI.toast('Updates checked', 'success');
      // Reload so the tab re-renders with the fresh server data
      // (badge in topbar, available-version block, etc).
      setTimeout(() => location.reload(), 350);
    } catch (e) {
      UI.toast('Check failed — see server log', 'error');
    } finally {
      btn.disabled = false;
    }
  });
}
