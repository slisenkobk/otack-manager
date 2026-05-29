import { api, UI } from './ui.js';

document.querySelectorAll('a.card[data-form-id]').forEach(card => {
  const id = card.dataset.formId;

  // Stop any [data-stop] click from following the card's href.
  card.querySelectorAll('[data-stop]').forEach(el => {
    el.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); });
  });

  const del = card.querySelector('[data-action=delete-form]');
  if (del) del.addEventListener('click', async () => {
    if (!await UI.confirm('Delete this form? All collected submissions will be removed too.', { danger: true, confirmLabel: 'Delete' })) return;
    try {
      await api('/forms/' + id + '/delete', { method: 'POST' });
      card.remove();
      UI.toast('Form deleted', 'success');
    } catch {}
  });

  const copy = card.querySelector('[data-action=copy-link]');
  if (copy) copy.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(copy.dataset.url);
      UI.toast('Link copied', 'success');
    } catch {
      UI.toast('Copy failed — select and copy manually', 'error');
    }
  });
});
