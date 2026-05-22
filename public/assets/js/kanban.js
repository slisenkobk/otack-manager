import { api, UI } from './ui.js';

const root = document.querySelector('.kanban');
if (root) initKanban(root);

function initKanban(root) {
  root.querySelectorAll('.kanban-list').forEach(initSortable);
  initCardClick(root);
  initQuickAdd(root);
  initAddColumn(root);
  initColumnSettings(root);
  initToolbar(root);
  initHighlight();
}

// ─── Position helpers ────────────────────────────────────────────────────────

function midpoint(prev, next) {
  if (prev == null && next == null) return 1024;
  if (prev == null) return next - 1024;
  if (next == null) return prev + 1024;
  return (prev + next) / 2;
}

// ─── Count badge ─────────────────────────────────────────────────────────────

function recount(root) {
  root.querySelectorAll('.kanban-col').forEach(col => {
    const countEl = col.querySelector('.kanban-col__count, .kanban-col-count');
    if (countEl) {
      countEl.textContent = col.querySelector('.kanban-list').children.length;
    }
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
  card.dataset.tags = '';
  card.dataset.title = (task.title || '').toLowerCase();

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
      try {
        await api('/api/tasks/' + id + '/move', {
          method: 'POST',
          body: JSON.stringify({ column_id: columnId, position }),
        });
        recount(root);
      } catch {
        // Rollback: put card back in old column at original index
        const children = Array.from(oldCol.children);
        if (oldIndex >= children.length) {
          oldCol.appendChild(card);
        } else {
          oldCol.insertBefore(card, children[oldIndex]);
        }
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
      if (url) window.open(url, '_blank', 'noopener');
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

    btn.addEventListener('click', () => {
      btn.hidden = true;
      form.hidden = false;
      input.focus();
    });

    input.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        form.hidden = true;
        btn.hidden = false;
        input.value = '';
      }
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
        list.appendChild(buildCard(res.task));
        input.value = '';
        // Keep form open + refocus for fast batch entry
        input.focus();
        recount(root);
      } catch {}
    });
  });
}

// ─── Add column ───────────────────────────────────────────────────────────────

function initAddColumn(root) {
  root.querySelector('.add-column')?.addEventListener('click', async () => {
    const name = await UI.prompt('Column name');
    if (!name) return;
    await api('/api/columns', {
      method: 'POST',
      body: JSON.stringify({ project_id: +root.dataset.projectId, name }),
    });
    location.reload();
  });
}

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
  const nameField = document.createElement('div');
  nameField.className = 'field';
  const nameLabel = document.createElement('label');
  nameLabel.textContent = 'Name';
  nameLabel.style.fontSize = '11px';
  const nameInput = document.createElement('input');
  nameInput.className = 'input';
  nameInput.value = name;
  nameField.appendChild(nameLabel);
  nameField.appendChild(nameInput);
  body.appendChild(nameField);

  const colorField = document.createElement('div');
  colorField.className = 'field';
  colorField.style.marginTop = '14px';
  const colorLabel = document.createElement('label');
  colorLabel.textContent = 'Color';
  colorLabel.style.fontSize = '11px';
  const colorInput = document.createElement('input');
  colorInput.type = 'color';
  colorInput.className = 'input';
  colorInput.value = color;
  colorInput.style.height = '40px';
  colorField.appendChild(colorLabel);
  colorField.appendChild(colorInput);
  body.appendChild(colorField);

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
              body: JSON.stringify({ name: nameInput.value.trim(), color: colorInput.value }),
            });
            close();
            UI.toast('Column updated', 'success');
            setTimeout(() => location.reload(), 400);
          } catch {}
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
        const select = document.createElement('select');
        select.className = 'select';
        otherCols.forEach(c => {
          const opt = document.createElement('option');
          opt.value = c.dataset.columnId;
          const nameEl2 = c.querySelector('.kanban-col__name, .kanban-col-head .name');
          opt.textContent = nameEl2 ? nameEl2.textContent : c.dataset.columnId;
          select.appendChild(opt);
        });
        const body2 = document.createElement('div');
        const p = document.createElement('p');
        p.textContent = 'Move tasks to:';
        body2.appendChild(p);
        body2.appendChild(select);
        UI.modal({
          title: 'Move tasks first',
          body: body2,
          actions: [
            { label: 'Cancel', variant: 'btn-ghost', onClick: c => c() },
            { label: 'Move and delete', variant: 'btn-danger', onClick: async (close) => {
                try {
                  await api('/api/columns/' + columnId + '/delete', {
                    method: 'POST',
                    body: JSON.stringify({ move_to: +select.value }),
                  });
                  close();
                  UI.toast('Column deleted', 'success');
                  setTimeout(() => location.reload(), 400);
                } catch {}
              }
            },
          ],
        });
      }
    }
  }
}

// ─── Filter toolbar (tag chips + search) ─────────────────────────────────────

function applyFilter(root, q, tag) {
  const cards = root.querySelectorAll('.kanban-card');
  const lowerQ = q.toLowerCase();
  cards.forEach(card => {
    const title = card.dataset.title || '';
    const tags = card.dataset.tags || '';
    const matchesQ = !lowerQ || title.includes(lowerQ);
    const matchesTag = !tag || tags.split(',').includes(tag);
    card.style.display = matchesQ && matchesTag ? '' : 'none';
  });
  recountVisible(root);
}

let searchDebounce;

function initToolbar(root) {
  const toolbar = document.querySelector('.kanban-toolbar');
  if (!toolbar) return;
  const search = toolbar.querySelector('[data-task-search]');
  const chips = toolbar.querySelectorAll('.kanban-tagbar .chip');
  let currentTag = '';
  let currentQ = '';

  chips.forEach(chip => {
    chip.addEventListener('click', () => {
      chips.forEach(c => c.classList.remove('chip--active'));
      chip.classList.add('chip--active');
      currentTag = chip.dataset.tag || '';
      applyFilter(root, currentQ, currentTag);
    });
  });

  if (search) {
    search.addEventListener('input', () => {
      clearTimeout(searchDebounce);
      searchDebounce = setTimeout(() => {
        currentQ = search.value.trim();
        applyFilter(root, currentQ, currentTag);
      }, 250);
    });
  }
}

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
