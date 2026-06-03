import { api, UI } from './ui.js';
import { logSilent } from './utils.js';

const root = document.querySelector('[data-link-edit]');
if (root) {
  const id = root.dataset.linkId;
  const target = root.querySelector('[data-link-target]');
  const title  = root.querySelector('[data-link-title]');
  const urlText = root.querySelector('[data-public-url-text]');
  const urlLink = root.querySelector('[data-public-url]');

  root.querySelector('[data-action=save-link]').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const payload = { target_url: target.value.trim(), title: title.value.trim() };
    if (!/^https?:\/\//i.test(payload.target_url)) {
      UI.toast('URL must start with http:// or https://', 'error');
      target.focus();
      return;
    }
    btn.disabled = true;
    try {
      await api('/links/' + id, { method: 'POST', body: JSON.stringify(payload) });
      UI.toast('Link saved', 'success');
    } catch (e) { logSilent(e, 'links-show.save'); } finally { btn.disabled = false; }
  });

  const copyBtn = root.querySelector('[data-action=copy-url]');
  if (copyBtn) copyBtn.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(urlText.textContent.trim()); UI.toast('Link copied', 'success'); }
    catch { UI.toast('Copy failed', 'error'); }
  });

  const rotateBtn = root.querySelector('[data-action=rotate-url]');
  if (rotateBtn) rotateBtn.addEventListener('click', async () => {
    if (!await UI.confirm('Rotate the slug? The current /s/… URL will stop working immediately.', { danger: true, confirmLabel: 'Rotate' })) return;
    rotateBtn.disabled = true;
    try {
      const res = await api('/links/' + id + '/rotate-slug', { method: 'POST' });
      if (res?.url) {
        urlText.textContent = res.url;
        if (urlLink) urlLink.href = res.url;
      }
      UI.toast('New URL generated', 'success');
    } catch (e) { logSilent(e, 'links-show.rotateSlug'); } finally { rotateBtn.disabled = false; }
  });

  const toggleBtn = root.querySelector('[data-action=toggle-link]');
  if (toggleBtn) toggleBtn.addEventListener('click', async () => {
    try {
      const res = await api('/links/' + id + '/toggle', { method: 'POST' });
      UI.toast(res.is_disabled ? 'Link disabled' : 'Link enabled', 'success');
      setTimeout(() => location.reload(), 300);
    } catch (e) { logSilent(e, 'links-show.toggle'); }
  });

  const delBtn = root.querySelector('[data-action=delete-link]');
  if (delBtn) delBtn.addEventListener('click', async () => {
    if (!await UI.confirm('Delete this short link? Visit history will be erased too.', { danger: true, confirmLabel: 'Delete' })) return;
    try {
      await api('/links/' + id + '/delete', { method: 'POST' });
      UI.toast('Link deleted', 'success');
      location.href = '/links';
    } catch (e) { logSilent(e, 'links-show.delete'); }
  });
}
