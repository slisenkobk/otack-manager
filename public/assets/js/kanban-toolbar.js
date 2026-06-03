// kanban-toolbar.js — filter chips, search, mine-only toggle, sort toggle.
//
// Cross-module state:
// - `recountVisible` is imported from `./kanban-board.js` (called after server
//   filter or mine-only toggle changes card visibility). Functions are hoisted
//   so the import-cycle with board.js resolves cleanly at call time.
// - `_searchIds` is module-private: set by `applyFilter` after the server search
//   request, read by the same call to decide per-card visibility. Not exposed.
// - `mineOnly` persists per-project in localStorage under `kanban-mine-<id>`.
// - Sort mode persists per-project in localStorage under `kanban-sort-<id>`.

import { logSilent } from './utils.js';
import { recountVisible } from './kanban-board.js';

let _searchIds = null; // null = no server filter active; Set<int> otherwise

async function applyFilter(root, q, tag, mineOnly) {
  // Hit server when there's a text query OR tag — server-side filtering & case-insensitive.
  if (q || tag) {
    const projectId = root.dataset.projectId;
    try {
      const params = new URLSearchParams();
      if (q) params.set('q', q);
      if (tag) params.set('tag', tag);
      const res = await fetch('/api/projects/' + projectId + '/tasks/search?' + params.toString(), {
        headers: { 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]')?.content || '' },
      });
      const data = await res.json();
      _searchIds = data.ids ? new Set(data.ids) : null;
    } catch { _searchIds = null; }
  } else {
    _searchIds = null;
  }
  const cards = root.querySelectorAll('.kanban-card');
  const meId = +(root.dataset.currentUserId || 0);
  cards.forEach(card => {
    const id = +(card.dataset.taskId || 0);
    const assignee = +(card.dataset.assigneeId || 0);
    const matchesServer = _searchIds === null || _searchIds.has(id);
    const matchesMine = !mineOnly || assignee === meId;
    card.style.display = matchesServer && matchesMine ? '' : 'none';
  });
  recountVisible(root);
}

export function initToolbar(root) {
  const toolbar = document.querySelector('.kanban-toolbar');
  if (!toolbar) return;
  const search = toolbar.querySelector('[data-task-search]');
  const chips = toolbar.querySelectorAll('.kanban-tagbar .chip');
  const projectId = root.dataset.projectId;
  const mineKey = 'kanban-mine-' + projectId;
  let currentTag = '';
  let currentQ = '';
  let mineOnly = (() => { try { return localStorage.getItem(mineKey) === '1'; } catch { return false; } })();

  function refilter() { applyFilter(root, currentQ, currentTag, mineOnly); }

  chips.forEach(chip => {
    chip.addEventListener('click', () => {
      chips.forEach(c => c.classList.remove('chip--active'));
      chip.classList.add('chip--active');
      currentTag = chip.dataset.tag || '';
      refilter();
    });
  });

  if (search) {
    const trigger = () => { currentQ = search.value.trim(); refilter(); };
    search.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); trigger(); }
      if (e.key === 'Escape') { search.value = ''; trigger(); }
    });
    toolbar.querySelector('[data-task-search-submit]')?.addEventListener('click', trigger);
  }

  const mineBtn = toolbar.querySelector('[data-mine-toggle]');
  const mineLbl = toolbar.querySelector('[data-mine-label]');
  if (mineBtn) {
    const labelMine = mineBtn.dataset.labelMine || 'Mine';
    const labelAll  = mineBtn.dataset.labelAll  || 'All';
    function paintMine() {
      mineBtn.dataset.sort = mineOnly ? 'priority' : ''; // reuse highlight style
      mineLbl.textContent = mineOnly ? labelMine : labelAll;
    }
    paintMine();
    refilter();
    mineBtn.addEventListener('click', () => {
      mineOnly = !mineOnly;
      try { localStorage.setItem(mineKey, mineOnly ? '1' : '0'); } catch (e) { logSilent(e, 'kanban.localStorage.mineOnly'); }
      paintMine();
      refilter();
    });
  }

  const sortBtn = toolbar.querySelector('[data-sort-toggle]');
  const sortLbl = toolbar.querySelector('[data-sort-label]');
  const sortKey = 'kanban-sort-' + projectId;
  const labelPriority = sortBtn?.dataset.labelPriority || 'Priority';
  const labelManual   = sortBtn?.dataset.labelManual   || 'Manual';
  function setSortMode(mode) {
    sortBtn.dataset.sort = mode;
    sortLbl.textContent = mode === 'priority' ? labelPriority : labelManual;
    applySort(root, mode);
    try { localStorage.setItem(sortKey, mode); } catch (e) { logSilent(e, 'kanban.localStorage.sort'); }
  }
  if (sortBtn) {
    const initial = (() => { try { return localStorage.getItem(sortKey); } catch { return null; } })() || 'manual';
    setSortMode(initial);
    sortBtn.addEventListener('click', () => {
      setSortMode(sortBtn.dataset.sort === 'priority' ? 'manual' : 'priority');
    });
  }
}

const PRIO_WEIGHT = { urgent: 4, high: 3, medium: 2, low: 1, none: 0 };
export function applySort(root, mode) {
  root.querySelectorAll('.kanban-list').forEach(list => {
    const cards = [...list.querySelectorAll('.kanban-card')];
    if (mode === 'priority') {
      cards.sort((a, b) => {
        const pa = PRIO_WEIGHT[a.dataset.priority || 'none'] ?? 0;
        const pb = PRIO_WEIGHT[b.dataset.priority || 'none'] ?? 0;
        if (pa !== pb) return pb - pa;
        return (+a.dataset.position || 0) - (+b.dataset.position || 0);
      });
    } else {
      cards.sort((a, b) => (+a.dataset.position || 0) - (+b.dataset.position || 0));
    }
    cards.forEach(c => list.appendChild(c));
  });
}
