import { api, UI } from './ui.js';
import { logSilent, t } from './utils.js';

document.querySelectorAll('.card[data-link-id]').forEach(card => {
  const id = card.dataset.linkId;
  const href = card.dataset.href;

  card.querySelectorAll('[data-stop]').forEach(el => {
    el.addEventListener('click', (e) => { e.stopPropagation(); });
  });

  if (href) {
    card.addEventListener('click', (e) => {
      if (e.target.closest('[data-stop]')) return;
      location.href = href;
    });
  }

  const del = card.querySelector('[data-action=delete-link]');
  if (del) del.addEventListener('click', async () => {
    if (!await UI.confirm(t('js.confirm.delete_short_link'), { danger: true, confirmLabel: 'Delete' })) return;
    try {
      await api('/links/' + id + '/delete', { method: 'POST' });
      card.remove();
      UI.toast(t('js.toast.link_deleted'), 'success');
    } catch (e) { logSilent(e, 'links-index.delete'); }
  });

  const toggle = card.querySelector('[data-action=toggle-link]');
  if (toggle) toggle.addEventListener('click', async () => {
    try {
      const res = await api('/links/' + id + '/toggle', { method: 'POST' });
      card.classList.toggle('card--muted', !!res.is_disabled);
      UI.toast(res.is_disabled ? 'Link disabled' : 'Link enabled', 'success');
      // Refresh icon — simplest is reload so the title/icon stay in sync.
      setTimeout(() => location.reload(), 350);
    } catch (e) { logSilent(e, 'links-index.toggle'); }
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

// "+ New link" trigger — uses a small modal collecting only the target URL.
// Title is optional and can be added on the edit page.
document.addEventListener('click', (e) => {
  const trig = e.target.closest('[data-action="new-link"]');
  if (!trig) return;
  e.preventDefault();
  openNewLinkModal();
});

function openNewLinkModal() {
  const body = document.createElement('div');
  const wrap = document.createElement('div'); wrap.className = 'field';
  const lab = document.createElement('label'); lab.textContent = 'Target URL';
  const inp = document.createElement('input'); inp.className = 'input'; inp.type = 'url'; inp.placeholder = 'https://example.com/landing';
  wrap.appendChild(lab); wrap.appendChild(inp);
  body.appendChild(wrap);

  UI.modal({
    title: 'New short link',
    body,
    actions: [
      { label: 'Cancel', variant: 'btn--ghost', onClick: c => c() },
      { label: 'Create', variant: 'submit', onClick: async (close) => {
          const target = inp.value.trim();
          if (!/^https?:\/\//i.test(target)) { UI.toast(t('js.toast.url_must_start_with_http'), 'error'); inp.focus(); return; }
          try {
            const res = await api('/links', { method: 'POST', body: JSON.stringify({ target_url: target }) });
            close();
            if (res && res.url) location.href = res.url;
            else location.reload();
          } catch (e) { logSilent(e, 'links-index.create'); }
        }
      },
    ],
  });
  setTimeout(() => inp.focus(), 0);
}
