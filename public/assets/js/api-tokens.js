import { UI } from './ui.js';

// Click-to-copy on `pre.copyable`. Falls back to a select-all hint if
// navigator.clipboard isn't available (older browsers / non-HTTPS local).
document.querySelectorAll('pre.copyable').forEach((node) => {
  node.addEventListener('click', async () => {
    const text = node.dataset.copy || node.textContent || '';
    try {
      await navigator.clipboard.writeText(text);
      node.classList.add('copyable--flash');
      setTimeout(() => node.classList.remove('copyable--flash'), 600);
      UI.toast('Copied to clipboard', 'success');
    } catch {
      UI.toast('Copy failed — select and copy manually', 'error');
    }
  });
});

// Destructive forms (revoke). The codebase convention is UI.confirm() — never
// native confirm(). We intercept submit, prompt, then re-submit on confirm.
document.querySelectorAll('form[data-confirm]').forEach((form) => {
  form.addEventListener('submit', async (e) => {
    if (form.dataset.confirmed === '1') return;
    e.preventDefault();
    const ok = await UI.confirm(form.dataset.confirm || 'Are you sure?', {
      danger: true,
      confirmLabel: form.dataset.confirmLabel || 'Confirm',
    });
    if (!ok) return;
    form.dataset.confirmed = '1';
    form.submit();
  });
});
