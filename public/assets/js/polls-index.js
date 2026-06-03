import { api, UI } from './ui.js';
import { logSilent, t } from './utils.js';

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
    if (!await UI.confirm(t('js.confirm.delete_poll'), { danger: true, confirmLabel: 'Delete' })) return;
    try {
      await api('/polls/' + id + '/delete', { method: 'POST' });
      card.remove();
      UI.toast(t('js.toast.poll_deleted'), 'success');
    } catch (e) { logSilent(e, 'polls.delete'); }
  });

  const dup = card.querySelector('[data-action=duplicate-poll]');
  if (dup) dup.addEventListener('click', async () => {
    try {
      const res = await api('/polls/' + id + '/copy', { method: 'POST' });
      UI.toast(t('js.toast.poll_duplicated'), 'success');
      if (res?.url) location.href = res.url;
    } catch (e) { logSilent(e, 'polls.duplicate'); }
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
      UI.toast(t('js.toast.poll_closed'), 'success');
      location.reload();
    } catch (e) { logSilent(e, 'polls.close'); }
  });

  const taskBtn = showRoot.querySelector('[data-action=create-summary-task]');
  if (taskBtn) taskBtn.addEventListener('click', async () => {
    try {
      const res = await api('/polls/' + pollId + '/create-summary-task', { method: 'POST' });
      UI.toast(t('js.toast.task_created'), 'success');
      if (res?.url) location.href = res.url;
    } catch (e) { logSilent(e, 'polls.createSummaryTask'); }
  });

  const copyUrl = showRoot.querySelector('[data-action=copy-url]');
  if (copyUrl) copyUrl.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(copyUrl.dataset.url);
      UI.toast(t('js.toast.link_copied'), 'success');
    } catch {
      UI.toast(t('js.toast.copy_failed'), 'error');
    }
  });
}

// Voters tab: lazy-load extra rows via /api/polls/{id}/voters?offset=…
// Mirrors the dashboard activity pattern: "Load more" button bumps offset,
// removes itself when has_more is false.
const votersTable = document.querySelector('[data-voters-table]');
const loadMoreBtn = document.querySelector('.load-more-voters');
if (votersTable && loadMoreBtn) {
  const pollId = votersTable.dataset.pollId;
  const tbody  = votersTable.querySelector('[data-voters-tbody]');
  loadMoreBtn.addEventListener('click', async () => {
    const offset = parseInt(loadMoreBtn.dataset.offset, 10) || 0;
    loadMoreBtn.disabled = true;
    try {
      const res = await api('/api/polls/' + pollId + '/voters?offset=' + offset);
      if (res.items?.length) {
        for (const v of res.items) tbody.appendChild(renderVoterRow(v));
      }
      loadMoreBtn.dataset.offset = String(offset + (res.items?.length || 0));
      if (!res.has_more) {
        loadMoreBtn.parentElement?.remove();
      } else {
        loadMoreBtn.disabled = false;
      }
    } catch {
      loadMoreBtn.disabled = false;
    }
  });
}

function renderVoterRow(v) {
  const tr  = document.createElement('tr');
  const td1 = document.createElement('td'); td1.textContent = v.contact || '';
  const td2 = document.createElement('td'); td2.textContent = v.choice_label || v.choice_key || '';
  const td3 = document.createElement('td'); td3.className = 'voters-table__when';
  td3.textContent = fmtDateTime(v.created_at);
  tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3);
  return tr;
}

function fmtDateTime(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d)) return iso;
  // Same locale-aware short form fmt_datetime() emits server-side.
  return d.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
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
      UI.toast(t('js.toast.project_updated'), 'success');
    } catch (e) { logSilent(e, 'polls.saveProject'); } finally { btn.disabled = false; }
  });
}
