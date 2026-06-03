import { api, UI } from './ui.js';
import { logSilent } from './utils.js';

// Module-level state must be declared BEFORE initKanban runs — otherwise TDZ trips.
let _searchIds = null; // null = no server filter active; Set<int> otherwise
let searchDebounce;

function syncEmptyState(list) {
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

const root = document.querySelector('.kanban');
if (root) initKanban(root);

function initKanban(root) {
  root.querySelectorAll('.kanban-list').forEach(initSortable);
  initColumnSortable(root);
  initCardClick(root);
  initQuickAdd(root);
  initAddColumn(root);
  initColumnSettings(root);
  initToolbar(root);
  initHighlight();
  initLazyLoad(root);
}

function initLazyLoad(root) {
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

function initColumnSortable(root) {
  const projectId = +root.dataset.projectId;
  if (!projectId) return;
  window.Sortable.create(root, {
    animation: 150,
    handle: '.kanban-col__drag',
    draggable: '.kanban-col',
    ghostClass: 'kanban-col-ghost',
    dragClass: 'kanban-col-dragging',
    filter: '.add-column',
    onEnd: async () => {
      const ids = [...root.querySelectorAll('.kanban-col')].map(c => +c.dataset.columnId);
      try {
        await api('/api/projects/' + projectId + '/columns/reorder', {
          method: 'POST',
          body: JSON.stringify({ ids }),
        });
        UI.toast('Column order saved', 'success');
      } catch {
        UI.toast('Failed to save column order', 'error');
      }
    },
  });
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

function recount(root) {
  root.querySelectorAll('.kanban-col').forEach(col => {
    const countEl = col.querySelector('.kanban-col__count, .kanban-col-count');
    if (!countEl) return;
    countEl.textContent = col.querySelectorAll('.kanban-list .kanban-card').length;
  });
}

function recountVisible(root) {
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

function initSortable(list) {
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
        UI.toast('Task moved', 'success');
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

function initCardClick(root) {
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

function initQuickAdd(root) {
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
        UI.toast('Task added', 'success');
      } catch (e) { logSilent(e, 'kanban.quickAdd'); }
    });
  });
}

// ─── Add column ───────────────────────────────────────────────────────────────

function initAddColumn(root) {
  root.querySelector('.add-column')?.addEventListener('click', async () => {
    const body = document.createElement('div');
    const nameField = buildField('Name', () => {
      const i = document.createElement('input');
      i.className = 'input';
      i.placeholder = 'Column name';
      return i;
    });
    body.appendChild(nameField.wrap);
    const colorField = buildColorPickerField('Color', '#8B7C68');
    colorField.wrap.style.marginTop = '14px';
    body.appendChild(colorField.wrap);

    UI.modal({
      title: 'New column',
      body,
      actions: [
        { label: 'Cancel', variant: 'btn-ghost', onClick: c => c() },
        { label: 'Create', variant: 'submit', onClick: async (close) => {
            const name = nameField.input.value.trim();
            if (!name) { nameField.input.focus(); return; }
            try {
              await api('/api/columns', {
                method: 'POST',
                body: JSON.stringify({
                  project_id: +root.dataset.projectId,
                  name,
                  color: colorField.getValue(),
                }),
              });
              UI.toast('Column added', 'success');
              close();
              setTimeout(() => location.reload(), 400);
            } catch (e) { logSilent(e, 'kanban.addColumn'); }
          }
        },
      ],
    });
    setTimeout(() => nameField.input.focus(), 0);
  });
}

// Build a labelled form field. factory creates the control element.
function buildField(labelText, factory) {
  const wrap = document.createElement('div');
  wrap.className = 'field';
  const label = document.createElement('label');
  label.textContent = labelText;
  label.style.fontSize = '11px';
  label.style.color = 'var(--ink-3)';
  label.style.textTransform = 'uppercase';
  label.style.letterSpacing = '.1em';
  label.style.display = 'block';
  label.style.marginBottom = '6px';
  wrap.appendChild(label);
  const input = factory();
  wrap.appendChild(input);
  return { wrap, input };
}

// Reusable colour-picker row: text input + square swatch with hidden native picker.
export function buildColorPickerField(labelText, initial = '#8B7C68') {
  const wrap = document.createElement('div');
  wrap.className = 'field';
  const label = document.createElement('label');
  label.textContent = labelText;
  label.style.fontSize = '11px';
  label.style.color = 'var(--ink-3)';
  label.style.textTransform = 'uppercase';
  label.style.letterSpacing = '.1em';
  label.style.display = 'block';
  label.style.marginBottom = '6px';
  wrap.appendChild(label);

  const row = document.createElement('div');
  row.className = 'color-picker-row';

  const textInput = document.createElement('input');
  textInput.type = 'text';
  textInput.className = 'input';
  textInput.value = initial;
  textInput.maxLength = 7;
  row.appendChild(textInput);

  const swatch = document.createElement('label');
  swatch.className = 'color-swatch';
  swatch.style.background = initial;

  const colorInput = document.createElement('input');
  colorInput.type = 'color';
  colorInput.value = initial;
  swatch.appendChild(colorInput);
  row.appendChild(swatch);

  wrap.appendChild(row);

  function setColor(v) {
    if (!/^#[0-9a-fA-F]{6}$/.test(v)) return;
    swatch.style.background = v;
    if (textInput.value.toLowerCase() !== v.toLowerCase()) textInput.value = v;
    if (colorInput.value.toLowerCase() !== v.toLowerCase()) colorInput.value = v;
  }
  colorInput.addEventListener('input', () => setColor(colorInput.value));
  textInput.addEventListener('input', () => setColor(textInput.value));

  return { wrap, getValue: () => textInput.value };
}
window.buildColorPickerField = buildColorPickerField;

// ─── Column settings ──────────────────────────────────────────────────────────

function initColumnSettings(root) {
  root.querySelectorAll('.col-settings').forEach(btn => {
    btn.addEventListener('click', () => openColumnSettings(+btn.dataset.columnId));
  });
}

async function openColumnSettings(columnId) {
  const colEl = root.querySelector('.kanban-col[data-column-id="' + columnId + '"]');
  if (!colEl) return;
  const nameEl = colEl.querySelector('.kanban-col__name, .kanban-col-head .name');
  const name = nameEl ? nameEl.textContent : '';
  const dotEl = colEl.querySelector('.kanban-col__dot, .kanban-col-head .dot');
  const colorMatch = (dotEl?.getAttribute('style') || '').match(/#[0-9a-f]{6}/i);
  const color = colorMatch ? colorMatch[0] : '#8B7C68';

  const body = document.createElement('div');
  const nameField = buildField('Name', () => {
    const i = document.createElement('input');
    i.className = 'input';
    i.value = name;
    return i;
  });
  body.appendChild(nameField.wrap);
  const colorField = buildColorPickerField('Color', color);
  colorField.wrap.style.marginTop = '14px';
  body.appendChild(colorField.wrap);
  const nameInput = nameField.input;

  UI.modal({
    title: 'Column settings',
    body,
    actions: [
      { label: 'Delete', variant: 'btn-danger', onClick: async (close) => {
          close();
          await tryDelete(columnId);
        }
      },
      { label: 'Cancel', variant: 'btn-ghost', onClick: (close) => close() },
      { label: 'Save', variant: 'submit', onClick: async (close) => {
          try {
            await api('/api/columns/' + columnId, {
              method: 'POST',
              body: JSON.stringify({ name: nameInput.value.trim(), color: colorField.getValue() }),
            });
            close();
            UI.toast('Column updated', 'success');
            setTimeout(() => location.reload(), 400);
          } catch (e) { logSilent(e, 'kanban.columnSettings.save'); }
        }
      },
    ],
  });

  async function tryDelete(columnId) {
    if (!await UI.confirm('Delete this column?', { danger: true, confirmLabel: 'Delete' })) return;
    try {
      await api('/api/columns/' + columnId + '/delete', { method: 'POST' });
      UI.toast('Column deleted', 'success');
      setTimeout(() => location.reload(), 400);
    } catch {
      const res = await fetch('/api/columns/' + columnId + '/delete', {
        method: 'POST',
        headers: {
          'X-CSRF-Token': document.querySelector('meta[name=csrf-token]')?.content || '',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({}),
      });
      const data = await res.json().catch(() => ({}));
      if (data.has_tasks) {
        const otherCols = [...root.querySelectorAll('.kanban-col')].filter(c => +c.dataset.columnId !== columnId);
        if (!otherCols.length) {
          UI.toast('No other column to move tasks into', 'error');
          return;
        }
        const items = otherCols.map(c => {
          const nameEl2 = c.querySelector('.kanban-col__name, .kanban-col-head .name');
          return { value: c.dataset.columnId, label: nameEl2 ? nameEl2.textContent : c.dataset.columnId };
        });
        const cs = window.buildCustomSelect(items, items[0].value);
        const body2 = document.createElement('div');
        const p = document.createElement('p');
        p.textContent = 'Move tasks to:';
        body2.appendChild(p);
        body2.appendChild(cs.root);
        UI.modal({
          title: 'Move tasks first',
          body: body2,
          actions: [
            { label: 'Cancel', variant: 'btn-ghost', onClick: c => c() },
            { label: 'Move and delete', variant: 'btn-danger', onClick: async (close) => {
                try {
                  await api('/api/columns/' + columnId + '/delete', {
                    method: 'POST',
                    body: JSON.stringify({ move_to: +cs.hidden.value }),
                  });
                  close();
                  UI.toast('Column deleted', 'success');
                  setTimeout(() => location.reload(), 400);
                } catch (e) { logSilent(e, 'kanban.columnSettings.moveAndDelete'); }
              }
            },
          ],
        });
      }
    }
  }
}

// ─── Filter toolbar (tag chips + search) ─────────────────────────────────────

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

function initToolbar(root) {
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
function applySort(root, mode) {
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
window.kanbanApplySort = applySort;

// ─── Highlight pulse on ?highlight=N ─────────────────────────────────────────

function initHighlight() {
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
