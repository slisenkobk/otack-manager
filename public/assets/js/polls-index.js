import { api, UI } from './ui.js';

// Card list interactions (mirrors forms-index.js).
document.querySelectorAll('.card[data-poll-id]').forEach(card => {
  const id   = card.dataset.pollId;
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

  const del = card.querySelector('[data-action=delete-poll]');
  if (del) del.addEventListener('click', async () => {
    if (!await UI.confirm('Delete this poll? Votes will be removed too.', { danger: true, confirmLabel: 'Delete' })) return;
    try {
      await api('/polls/' + id + '/delete', { method: 'POST' });
      card.remove();
      UI.toast('Poll deleted', 'success');
    } catch {}
  });

  const dup = card.querySelector('[data-action=duplicate-poll]');
  if (dup) dup.addEventListener('click', async () => {
    try {
      const res = await api('/polls/' + id + '/copy', { method: 'POST' });
      UI.toast('Poll duplicated', 'success');
      if (res?.url) location.href = res.url;
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

// Show-page actions: close (active → closed) and create summary task.
const showRoot = document.querySelector('[data-poll-show]');
if (showRoot) {
  const pollId = showRoot.dataset.pollId;

  const closeBtn = showRoot.querySelector('[data-action=close-poll]');
  if (closeBtn) closeBtn.addEventListener('click', async () => {
    const msg = closeBtn.dataset.confirm || 'Close this poll? No more votes will be accepted.';
    if (!await UI.confirm(msg, { danger: true, confirmLabel: 'Close' })) return;
    try {
      await api('/polls/' + pollId + '/close', { method: 'POST' });
      UI.toast('Poll closed', 'success');
      location.reload();
    } catch {}
  });

  const taskBtn = showRoot.querySelector('[data-action=create-summary-task]');
  if (taskBtn) taskBtn.addEventListener('click', async () => {
    try {
      const res = await api('/polls/' + pollId + '/create-summary-task', { method: 'POST' });
      UI.toast('Task created', 'success');
      if (res?.url) location.href = res.url;
    } catch {}
  });

  const copyUrl = showRoot.querySelector('[data-action=copy-url]');
  if (copyUrl) copyUrl.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(copyUrl.dataset.url);
      UI.toast('Link copied', 'success');
    } catch {
      UI.toast('Copy failed', 'error');
    }
  });
}

// Project tab on an active/closed poll: PATCH the attached project_id.
const projectEditor = document.querySelector('[data-poll-project-edit]');
if (projectEditor) {
  const id    = projectEditor.dataset.pollId;
  const input = projectEditor.querySelector('[data-poll-project-input]');
  const btn   = projectEditor.querySelector('[data-action=save-project]');
  if (btn && input) btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      const raw = (input.value || '').trim();
      await api('/polls/' + id + '/project', {
        method: 'POST',
        body: JSON.stringify({ project_id: raw ? parseInt(raw, 10) : null }),
      });
      UI.toast('Project updated', 'success');
    } catch {} finally { btn.disabled = false; }
  });
}
