import { api, UI } from './ui.js';

const root = document.querySelector('.kanban');
if (root) initKanban(root);

function initKanban(root) {
  root.querySelectorAll('.kanban-list').forEach(initSortable);
  initCardClick(root);
  initQuickAdd(root);
  initAddColumn(root);
  initColumnSettings(root);
}

function midpoint(prev, next) {
  if (prev == null && next == null) return 1024;
  if (prev == null) return next - 1024;
  if (next == null) return prev + 1024;
  return (prev + next) / 2;
}

function recount(root) {
  root.querySelectorAll('.kanban-col').forEach(col => {
    col.querySelector('.kanban-col-count').textContent =
      col.querySelector('.kanban-list').children.length;
  });
}

function buildCard(task) {
  const card = document.createElement('div');
  card.className = 'kanban-card';
  card.dataset.taskId = task.id;
  card.dataset.taskUrl = '/tasks/' + task.id;
  card.dataset.position = task.position;
  const title = document.createElement('div');
  title.className = 'title';
  title.textContent = task.title;
  card.appendChild(title);
  return card;
}

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

function initQuickAdd(root) {
  root.querySelectorAll('.kanban-quickadd').forEach(form => {
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const input = form.querySelector('input[name=title]');
      const title = input.value.trim();
      if (!title) return;
      const projectId = root.dataset.projectId;
      const columnId = form.dataset.columnId;
      try {
        const res = await api('/projects/' + projectId + '/tasks', {
          method: 'POST',
          body: JSON.stringify({ column_id: +columnId, title }),
        });
        form.closest('.kanban-col').querySelector('.kanban-list').appendChild(buildCard(res.task));
        input.value = '';
        recount(root);
      } catch {}
    });
  });
}

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

function initColumnSettings(root) {
  root.querySelectorAll('.col-settings').forEach(btn => {
    btn.addEventListener('click', () => openColumnSettings(+btn.dataset.columnId));
  });
}

async function openColumnSettings(columnId) {
  // Implemented in Task 24
}
