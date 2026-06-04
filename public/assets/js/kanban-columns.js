// kanban-columns.js — add-column form, column settings modal, column drag/drop.
//
// Cross-module state: none. The board root is passed explicitly to every init
// function (and threaded into `openColumnSettings`). Field builders moved to
// ./ui-fields.js so projects/users modals can share the same primitives.

import { api, UI } from './ui.js';
import { logSilent, t, loadSortable } from './utils.js';
import { buildField, buildColorPickerField } from './ui-fields.js';

export async function initColumnSortable(root) {
  const projectId = +root.dataset.projectId;
  if (!projectId) return;
  const Sortable = await loadSortable();
  Sortable.create(root, {
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
        UI.toast(t('js.toast.column_order_saved'), 'success');
      } catch {
        UI.toast(t('js.toast.column_order_failed'), 'error');
      }
    },
  });
}

// ─── Add column ───────────────────────────────────────────────────────────────

export function initAddColumn(root) {
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
        { label: 'Cancel', variant: 'btn--ghost', onClick: c => c() },
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
              UI.toast(t('js.toast.column_added'), 'success');
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

// ─── Column settings ──────────────────────────────────────────────────────────

export function initColumnSettings(root) {
  root.querySelectorAll('.col-settings').forEach(btn => {
    btn.addEventListener('click', () => openColumnSettings(root, +btn.dataset.columnId));
  });
}

async function openColumnSettings(root, columnId) {
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
      { label: 'Delete', variant: 'btn--danger', onClick: async (close) => {
          close();
          await tryDelete(columnId);
        }
      },
      { label: 'Cancel', variant: 'btn--ghost', onClick: (close) => close() },
      { label: 'Save', variant: 'submit', onClick: async (close) => {
          try {
            await api('/api/columns/' + columnId, {
              method: 'POST',
              body: JSON.stringify({ name: nameInput.value.trim(), color: colorField.getValue() }),
            });
            close();
            UI.toast(t('js.toast.column_updated'), 'success');
            setTimeout(() => location.reload(), 400);
          } catch (e) { logSilent(e, 'kanban.columnSettings.save'); }
        }
      },
    ],
  });

  async function tryDelete(columnId) {
    if (!await UI.confirm(t('js.confirm.delete_column'), { danger: true, confirmLabel: 'Delete' })) return;
    try {
      await api('/api/columns/' + columnId + '/delete', { method: 'POST' });
      UI.toast(t('js.toast.column_deleted'), 'success');
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
          UI.toast(t('js.toast.no_other_column'), 'error');
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
            { label: 'Cancel', variant: 'btn--ghost', onClick: c => c() },
            { label: 'Move and delete', variant: 'btn--danger', onClick: async (close) => {
                try {
                  await api('/api/columns/' + columnId + '/delete', {
                    method: 'POST',
                    body: JSON.stringify({ move_to: +cs.hidden.value }),
                  });
                  close();
                  UI.toast(t('js.toast.column_deleted'), 'success');
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
