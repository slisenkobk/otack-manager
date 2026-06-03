import { api, UI } from './ui.js';
import { logSilent, t } from './utils.js';

document.querySelectorAll('.card[data-form-id]').forEach(card => {
  const id = card.dataset.formId;
  const href = card.dataset.href;

  // Stop any [data-stop] click from bubbling up to the card-level navigation.
  card.querySelectorAll('[data-stop]').forEach(el => {
    el.addEventListener('click', (e) => { e.stopPropagation(); });
  });

  // Clicking anywhere on the card (outside [data-stop] children) opens edit.
  if (href) {
    card.addEventListener('click', (e) => {
      if (e.target.closest('[data-stop]')) return;
      location.href = href;
    });
  }

  const del = card.querySelector('[data-action=delete-form]');
  if (del) del.addEventListener('click', async () => {
    if (!await UI.confirm(t('js.confirm.delete_form'), { danger: true, confirmLabel: 'Delete' })) return;
    try {
      await api('/forms/' + id + '/delete', { method: 'POST' });
      card.remove();
      UI.toast(t('js.toast.form_deleted'), 'success');
    } catch (e) { logSilent(e, 'forms.delete'); }
  });

  const dup = card.querySelector('[data-action=duplicate-form]');
  if (dup) dup.addEventListener('click', async () => {
    try {
      const res = await api('/forms/' + id + '/copy', { method: 'POST' });
      UI.toast(t('js.toast.form_duplicated'), 'success');
      if (res?.url) location.href = res.url;
    } catch (e) { logSilent(e, 'forms.duplicate'); }
  });

  const copy = card.querySelector('[data-action=copy-link]');
  if (copy) copy.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(copy.dataset.url);
      UI.toast(t('js.toast.link_copied'), 'success');
    } catch {
      UI.toast(t('js.toast.copy_failed_manual'), 'error');
    }
  });
});
