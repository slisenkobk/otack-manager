// kanban-board.js — per-card render, drag/drop sortable, lazy-load, quick-add, card-click.
//
// Cross-module state:
// - `applySort` is imported from `./kanban-toolbar.js`. Called from `initLazyLoad`
//   and `initSortable` when the sort toggle (in the toolbar) is set to "priority".
//   The toggle's current mode lives in `[data-sort-toggle].dataset.sort` on the DOM.
// - The active board root is passed in via `initKanban(root)` and threaded explicitly
//   to functions that need it; we do NOT rely on a module-level `root` constant.

import { api, UI } from './ui.js';
import { logSilent, t } from './utils.js';
import { applySort } from './kanban-toolbar.js';

export function syncEmptyState(list) {
  if (!list) return;
  const hasCards    = !!list.querySelector('.kanban-card');
  const placeholder = list.querySelector('.kanban-empty');
  if (hasCards && placeholder) placeholder.remove();
  if (!hasCards && !placeholder) {
    const el = document.createElement('div');
    el.className = 'kanban-empty';
    el.textContent = 'No tasks yet';
    list.prepend(el);
  }
}

export function initLazyLoad(root) {
  const projectId = root.dataset.projectId;
  if (!projectId) return;
  const io = new IntersectionObserver(async (entries) => {
    for (const entry of entries) {
      if (!entry.isIntersecting) continue;
      const sentinel = entry.target;
      io.unobserve(sentinel);
      const list = sentinel.closest('.kanban-list');
      const colId = sentinel.dataset.columnId;
      const offset = +(list.dataset.loaded || 0);
      try {
        const res = await fetch('/api/projects/' + projectId + '/columns/' + colId + '/tasks?offset=' + offset + '&limit=50', {
          headers: { 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]')?.content || '' },
        });
        const data = await res.json();
        if (!data.ok) continue;
        // server-rendered HTML; parsed via DOMParser to avoid direct innerHTML assignment
        const doc = new DOMParser().parseFromString('<div>' + data.html + '</div>', 'text/html');
        const newCards = doc.body.firstChild ? Array.from(doc.body.firstChild.children) : [];
        newCards.forEach(card => sentinel.before(card));
        list.dataset.loaded = String(offset + newCards.length);
        if (+list.dataset.loaded < +list.dataset.total) {
          io.observe(sentinel);
        } else {
          sentinel.remove();
        }
        const sortBtn = document.querySelector('[data-sort-toggle]');
        if (sortBtn?.dataset.sort === 'priority') applySort(root, 'priority');
      } catch (e) { logSilent(e, 'kanban.lazyLoad'); }
    }
  }, { root: null, rootMargin: '200px' });
  root.querySelectorAll('[data-load-sentinel]').forEach(s => io.observe(s));
}

// ─── Position helpers ────────────────────────────────────────────────────────

function midpoint(prev, next) {
  if (prev == null && next == null) return 1024;
  if (prev == null) return next - 1024;
  if (next == null) return prev + 1024;
  return (prev + next) / 2;
}

// ─── Count badge ─────────────────────────────────────────────────────────────

function updateCardSubStatus(card, sub) {
  const head = card.querySelector('.kanban-card__head');
  if (!head) return;
  let badge = head.querySelector('.sub-status');
  if (sub) {
    if (!badge) {
      badge = document.createElement('span');
      head.querySelector('.kanban-card__id')?.after(badge);
    }
    badge.className = 'sub-status sub-status--' + sub;
    badge.textContent = sub === 'reopened' ? '↻ Reopened' : '↩ Returned';
  } else if (badge) {
    badge.remove();
  }
}

export function recount(root) {
  root.querySelectorAll('.kanban-col').forEach(col => {
    const countEl = col.querySelector('.kanban-col__count, .kanban-col-count');
    if (!countEl) return;
    countEl.textContent = col.querySelectorAll('.kanban-list .kanban-card').length;
  });
}

export function recountVisible(root) {
  root.querySelectorAll('.kanban-col').forEach(col => {
    const countEl = col.querySelector('.kanban-col__count, .kanban-col-count');
    if (!countEl) return;
    const visible = [...col.querySelectorAll('.kanban-list .kanban-card')]
      .filter(c => c.style.display !== 'none').length;
    countEl.textContent = visible;
  });
}

// ─── Build card (for AJAX quick-add) ─────────────────────────────────────────

function buildCard(task) {
  const card = document.createElement('div');
  card.className = 'kanban-card';
  card.dataset.taskId = task.id;
  card.dataset.taskUrl = '/tasks/' + task.id;
  card.dataset.position = task.position;
  card.dataset.priority = task.priority || 'none';
  card.dataset.assigneeId = task.assignee_id || 0;
  card.dataset.tags = '';
  card.dataset.title = (task.title || '').toLowerCase();

  const head = document.createElement('div');
  head.className = 'kanban-card__head';
  const id = document.createElement('span');
  id.className = 'kanban-card__id';
  id.textContent = 'TASK-' + task.id;
  head.appendChild(id);
  card.appendChild(head);

  const titleEl = document.createElement('div');
  titleEl.className = 'kanban-card__title';
  titleEl.textContent = task.title;
  card.appendChild(titleEl);
  return card;
}

// ─── Sortable drag/drop ───────────────────────────────────────────────────────

export function initSortable(root, list) {
  window.Sortable.create(list, {
    group: 'kanban',
    animation: 150,
    ghostClass: 'kanban-ghost',
    dragClass: 'kanban-dragging',
    onEnd: async (evt) => {
      const card = evt.item;
      const newCol = evt.to;
      const oldCol = evt.from;
      const oldIndex = evt.oldIndex;
      const id = +card.dataset.taskId;
      const columnId = +newCol.dataset.columnId;
      const prev = card.previousElementSibling?.dataset.position;
      const next = card.nextElementSibling?.dataset.position;
      const position = midpoint(prev ? +prev : null, next ? +next : null);
      card.dataset.position = position;
      // The destination column may have shown "No tasks yet" — drop it now that we have one.
      newCol.querySelector('.kanban-empty')?.remove();
      // The source column may now be empty — re-add the placeholder.
      syncEmptyState(oldCol);
      try {
        const moveRes = await api('/api/tasks/' + id + '/move', {
          method: 'POST',
          body: JSON.stringify({ column_id: columnId, position }),
        });
        updateCardSubStatus(card, moveRes.sub_status || null);
        recount(root);
        const sortBtn = document.querySelector('[data-sort-toggle]');
        if (sortBtn?.dataset.sort === 'priority') applySort(root, 'priority');
        UI.toast(t('js.toast.task_moved'), 'success');
      } catch {
        // Rollback: put card back in old column at original index
        const children = Array.from(oldCol.children);
        if (oldIndex >= children.length) {
          oldCol.appendChild(card);
        } else {
          oldCol.insertBefore(card, children[oldIndex]);
        }
        syncEmptyState(oldCol);
        syncEmptyState(newCol);
        recount(root);
      }
    },
  });
}

// ─── Card click (click vs drag threshold 5px) ────────────────────────────────

export function initCardClick(root) {
  let downAt = null;
  root.addEventListener('pointerdown', e => {
    const card = e.target.closest('.kanban-card');
    if (!card) return;
    downAt = { x: e.clientX, y: e.clientY, card };
  });
  root.addEventListener('pointerup', e => {
    if (!downAt) return;
    const dx = Math.abs(e.clientX - downAt.x);
    const dy = Math.abs(e.clientY - downAt.y);
    if (dx < 5 && dy < 5) {
      const url = downAt.card.dataset.taskUrl;
      if (url) location.href = url;
    }
    downAt = null;
  });
  root.addEventListener('auxclick', e => {
    const card = e.target.closest('.kanban-card');
    if (!card || e.button !== 1) return;
    window.open(card.dataset.taskUrl, '_blank', 'noopener');
  });
}

// ─── Quick-add fold (button → form → Esc closes) ─────────────────────────────

export function initQuickAdd(root) {
  root.querySelectorAll('.kanban-col__footer').forEach(footer => {
    const btn = footer.querySelector('[data-quickadd-trigger]');
    const form = footer.querySelector('form');
    if (!btn || !form) return;
    const input = form.querySelector('input[name=title]');

    function closeForm() {
      form.hidden = true;
      btn.hidden = false;
      input.value = '';
    }

    btn.addEventListener('click', () => {
      btn.hidden = true;
      form.hidden = false;
      input.focus();
    });

    input.addEventListener('keydown', e => {
      if (e.key === 'Escape') closeForm();
    });
    // Close form when focus leaves it entirely (next tick so click-on-Submit still fires)
    input.addEventListener('blur', () => {
      setTimeout(() => {
        if (!form.contains(document.activeElement)) closeForm();
      }, 150);
    });

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const title = input.value.trim();
      if (!title) return;
      const projectId = root.dataset.projectId;
      const columnId = form.dataset.columnId;
      try {
        const res = await api('/projects/' + projectId + '/tasks', {
          method: 'POST',
          body: JSON.stringify({ column_id: +columnId, title }),
        });
        const list = footer.closest('.kanban-col').querySelector('.kanban-list');
        list.querySelector('.kanban-empty')?.remove();
        list.appendChild(buildCard(res.task));
        closeForm();
        recount(root);
        UI.toast(t('js.toast.task_added'), 'success');
      } catch (e) { logSilent(e, 'kanban.quickAdd'); }
    });
  });
}
