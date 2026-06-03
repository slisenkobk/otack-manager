// kanban.js — thin façade that wires the three kanban sub-modules together.
//
// Loaded as `<script type="module">` from views/projects/show.php — auto-boots
// against `.kanban` on script evaluation. The split moves logic into:
//   - kanban-board.js   — per-card render, drag/drop, lazy-load, quick-add
//   - kanban-toolbar.js — filter chips, search, mine-only, sort toggle
//   - kanban-columns.js — add-column form, column settings modal, column drag
//
// Only `initKanban(root)` and `initHighlight()` are called from views/scripts;
// `window.kanbanApplySort` is preserved for legacy callers.

import { initLazyLoad, initSortable, initCardClick, initQuickAdd } from './kanban-board.js';
import { initToolbar, applySort } from './kanban-toolbar.js';
import { initAddColumn, initColumnSettings, initColumnSortable } from './kanban-columns.js';

export function initKanban(root) {
  if (!root) return;
  root.querySelectorAll('.kanban-list').forEach(list => initSortable(root, list));
  initColumnSortable(root);
  initCardClick(root);
  initQuickAdd(root);
  initAddColumn(root);
  initColumnSettings(root);
  initToolbar(root);
  initHighlight();
  initLazyLoad(root);
}

// ─── Highlight pulse on ?highlight=N ─────────────────────────────────────────

export function initHighlight() {
  const url = new URL(location.href);
  const id = url.searchParams.get('highlight');
  if (!id) return;
  url.searchParams.delete('highlight');
  history.replaceState({}, '', url.toString());
  const card = document.querySelector('.kanban-card[data-task-id="' + id + '"]');
  if (!card) return;
  card.scrollIntoView({ behavior: 'smooth', block: 'center' });
  card.classList.add('is-highlight');
  setTimeout(() => card.classList.remove('is-highlight'), 2000);
}

// Legacy global — kept for any external callers depending on the old surface.
window.kanbanApplySort = applySort;

// Auto-boot when present on page (mirrors pre-split behaviour).
const root = document.querySelector('.kanban');
if (root) initKanban(root);
