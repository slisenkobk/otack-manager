import { api, UI } from './ui.js';
import { logSilent, t, withButtonBusy } from './utils.js';

(() => {
  const titleEl = document.querySelector('.task-title');
  const sidebar = document.querySelector('.task-sidebar');
  if (!sidebar) return; // not on a task page

  const taskId = sidebar.dataset.taskId;
  const projectId = sidebar.dataset.projectId;

  // Title blur (only wired if the element is contenteditable — non-author/non-admin gets a read-only h1)
  if (titleEl && titleEl.isContentEditable) {
    let lastTitle = titleEl.textContent;
    titleEl.addEventListener('blur', async () => {
      const title = titleEl.textContent.trim();
      if (!title || title === lastTitle) { titleEl.textContent = lastTitle; return; }
      try {
        const res = await api('/tasks/' + taskId, { method: 'POST', body: JSON.stringify({ title }) });
        lastTitle = res.task.title;
        titleEl.textContent = res.task.title;
        UI.toast(t('js.toast.title_saved'), 'success');
      } catch {
        titleEl.textContent = lastTitle;
      }
    });
    titleEl.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); titleEl.blur(); }
      if (e.key === 'Escape') { titleEl.textContent = lastTitle; titleEl.blur(); }
    });
  }

  // Description edit/save/cancel (Quill WYSIWYG)
  const descSection = document.querySelector('.task-description-section');
  const rendered = descSection.querySelector('.task-description-rendered');
  const editor = descSection.querySelector('.task-description-editor');
  const editDescBtn = descSection.querySelector('[data-action=edit-description]');
  if (editDescBtn) editDescBtn.addEventListener('click', () => {
    rendered.style.display = 'none';
    editor.style.display = 'block';
    // Focus Quill editor if available
    const quillEditor = editor.querySelector('.ql-editor');
    if (quillEditor) quillEditor.focus();
  });
  descSection.querySelector('[data-action=cancel-description]').addEventListener('click', () => {
    editor.style.display = 'none';
    rendered.style.display = 'block';
  });
  descSection.querySelector('[data-action=save-description]').addEventListener('click', async () => {
    const hidden = document.querySelector('#task-description-hidden');
    const description = hidden ? hidden.value : '';
    try {
      const res = await api('/tasks/' + taskId, { method: 'POST', body: JSON.stringify({ description }) });
      // description_html is server-sanitized HTML — safe to set as innerHTML
      const safeHtml = res.task.description_html
        || '<em class="overview-panel__empty">No description. Click Edit to add one.</em>';
      rendered.innerHTML = safeHtml; // nosec: server-sanitized HTML
      editor.style.display = 'none';
      rendered.style.display = 'block';
      UI.toast(t('js.toast.description_saved'), 'success');
    } catch (e) { logSilent(e, 'task-page.saveDescription'); }
  });

  // Assignee picker (custom dropdown with avatars)
  sidebar.querySelectorAll('[data-assignee-picker]').forEach(picker => {
    const hidden = picker.querySelector('input[data-field=assignee_id]');
    const btn    = picker.querySelector('[data-toggle]');
    const pop    = picker.querySelector('.assignee-picker__pop');
    const nameEl = picker.querySelector('.assignee-picker__name');
    const avatarEl = picker.querySelector('.assignee-picker__btn > .user-avatar');

    btn.addEventListener('click', e => {
      e.stopPropagation();
      pop.hidden = !pop.hidden;
    });
    document.addEventListener('click', e => {
      if (!picker.contains(e.target)) pop.hidden = true;
    });

    pop.querySelectorAll('.assignee-dropdown__row').forEach(row => {
      row.addEventListener('click', () => {
        const newId = row.dataset.assigneeId || '';
        if ((hidden.value || '') === newId) { pop.hidden = true; return; }
        hidden.value = newId;
        // Mirror visual state from the chosen row to the button.
        const src = row.querySelector('.user-avatar');
        if (src && avatarEl) {
          avatarEl.className = src.className;
          avatarEl.style.cssText = src.style.cssText;
          avatarEl.innerHTML = src.innerHTML;
        }
        if (nameEl) {
          nameEl.textContent = row.textContent.trim() || 'Unassigned';
          nameEl.classList.toggle('assignee-picker__name--muted', !newId);
        }
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
        pop.hidden = true;
      });
    });
  });

  // Sidebar selectors
  sidebar.querySelectorAll('[data-field]').forEach(field => {
    field.addEventListener('change', async () => {
      const key = field.dataset.field;
      const val = field.value;
      const body = {};
      if (key === 'column_id') body.column_id = +val;
      else if (key === 'assignee_id') body.assignee_id = val ? +val : null;
      else if (key === 'due_date') body.due_date = val || null;
      else if (key === 'priority') body.priority = val || 'none';
      try {
        const res = await api('/tasks/' + taskId, { method: 'POST', body: JSON.stringify(body) });
        if (key === 'column_id') {
          const badge = document.querySelector('[data-task-sub-status]');
          if (badge) {
            const sub = res.task?.sub_status;
            badge.className = 'sub-status' + (sub ? ' sub-status--' + sub : '');
            badge.textContent = sub === 'reopened' ? '↻ Reopened'
                              : sub === 'returned' ? '↩ Returned' : '';
            badge.hidden = !sub;
          }
        }
        // J-8: explicit per-field i18n keys instead of building toast text
        // from the snake_cased field name (which produced "column id updated"
        // and was untranslatable into PL/UK).
        const labelKeys = {
          column_id:   'js.toast.column_changed',
          assignee_id: 'js.toast.assignee_changed',
          due_date:    'js.toast.due_changed',
          priority:    'js.toast.priority_changed',
        };
        UI.toast(t(labelKeys[key] ?? 'js.toast.updated'), 'success');
      } catch (e) { logSilent(e, 'task-page.fieldSave'); }
    });
  });

  // Related tasks
  const linksSection = document.querySelector('[data-linked-tasks]');
  if (linksSection) {
    const list   = linksSection.querySelector('[data-linked-list]');
    const picker = linksSection.querySelector('[data-linked-picker]');
    const search = linksSection.querySelector('[data-linked-search]');
    const results = linksSection.querySelector('[data-linked-results]');
    const addBtn = linksSection.querySelector('[data-action=add-link]');

    const ensureEmptyState = () => {
      const rows = list.querySelectorAll('.linked-task');
      const existing = list.querySelector('[data-linked-empty]');
      if (rows.length === 0 && !existing) {
        const em = document.createElement('em');
        em.className = 'overview-panel__empty';
        em.setAttribute('data-linked-empty', '');
        em.textContent = 'No related tasks yet.';
        list.appendChild(em);
      } else if (rows.length > 0 && existing) {
        existing.remove();
      }
    };

    const statusHtml = (t) => {
      const color = t.column_color || '#8B7C68';
      const name  = t.column_name  || '—';
      const dot   = document.createElement('span');
      dot.className = 'dot col-dot';
      dot.style.background = color;
      const wrap = document.createElement('span');
      wrap.appendChild(dot);
      const lbl = document.createElement('span');
      lbl.textContent = name;
      wrap.appendChild(lbl);
      return wrap;
    };

    const renderLinkedRow = (t) => {
      const a = document.createElement('a');
      a.className = 'linked-task';
      a.href = '/tasks/' + t.id;
      a.dataset.linkedId = t.id;

      const idEl = document.createElement('span');
      idEl.className = 'linked-task__id';
      idEl.textContent = 'TASK-' + t.id;
      a.appendChild(idEl);

      const titleEl = document.createElement('span');
      titleEl.className = 'linked-task__title';
      titleEl.textContent = t.title;
      a.appendChild(titleEl);

      const status = statusHtml(t);
      status.className = 'linked-task__status';
      a.appendChild(status);

      const rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'linked-task__remove';
      rm.dataset.action = 'unlink';
      rm.setAttribute('aria-label', 'Remove link');
      rm.title = 'Remove link';
      rm.innerHTML = '<i class="fa-solid fa-xmark"></i>';
      a.appendChild(rm);

      return a;
    };

    const renderResults = (tasks) => {
      results.innerHTML = '';
      if (!tasks.length) {
        const em = document.createElement('div');
        em.className = 'linked-result__empty';
        em.textContent = 'No tasks found.';
        results.appendChild(em);
        results.classList.add('is-open');
        return;
      }
      tasks.forEach(t => {
        const row = document.createElement('div');
        row.className = 'linked-result';
        row.dataset.linkedId = t.id;

        const idEl = document.createElement('span');
        idEl.className = 'linked-result__id';
        idEl.textContent = 'TASK-' + t.id;
        row.appendChild(idEl);

        const titleEl = document.createElement('span');
        titleEl.className = 'linked-result__title';
        titleEl.textContent = t.title;
        row.appendChild(titleEl);

        const status = statusHtml(t);
        status.className = 'linked-result__status';
        row.appendChild(status);

        row.addEventListener('click', async () => {
          try {
            const res = await api('/api/tasks/' + taskId + '/links', {
              method: 'POST',
              body: JSON.stringify({ linked_task_id: +t.id }),
            });
            list.appendChild(renderLinkedRow(res.task));
            ensureEmptyState();
            UI.toast(t('js.toast.task_linked'), 'success');
            // refresh search to drop the just-linked item
            doSearch(search.value);
          } catch (e) { logSilent(e, 'task-page.linkTask'); }
        });
        results.appendChild(row);
      });
      results.classList.add('is-open');
    };

    let searchTimer = null;
    const doSearch = (q) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(async () => {
        try {
          const url = '/api/tasks/' + taskId + '/links/search' + (q ? '?q=' + encodeURIComponent(q) : '');
          const res = await api(url);
          renderResults(res.tasks || []);
        } catch (e) { logSilent(e, 'task-page.linkSearch'); }
      }, 150);
    };

    addBtn?.addEventListener('click', () => {
      picker.hidden = !picker.hidden;
      if (!picker.hidden) {
        search.value = '';
        results.innerHTML = '';
        doSearch('');
        setTimeout(() => search.focus(), 0);
      }
    });

    search?.addEventListener('input', () => doSearch(search.value));

    document.addEventListener('click', (e) => {
      if (!picker || picker.hidden) return;
      if (!picker.contains(e.target) && !addBtn.contains(e.target)) {
        picker.hidden = true;
      }
    });

    list.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-action=unlink]');
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      const row = btn.closest('.linked-task');
      const otherId = row?.dataset.linkedId;
      if (!otherId) return;
      if (!await UI.confirm(t('js.confirm.remove_link'))) return;
      try {
        await api('/api/tasks/' + taskId + '/links/' + otherId + '/delete', { method: 'POST' });
        row.remove();
        ensureEmptyState();
        UI.toast(t('js.toast.link_removed'), 'success');
      } catch (e) { logSilent(e, 'task-page.unlink'); }
    });
  }

  // Delete (button may be absent for non-author/non-admin)
  const deleteBtn = sidebar.querySelector('[data-action=delete-task]');
  if (deleteBtn) deleteBtn.addEventListener('click', async () => {
    if (!await UI.confirm(t('js.confirm.delete_task'), {danger: true, confirmLabel: 'Delete'})) return;
    try {
      await api('/tasks/' + taskId + '/delete', { method: 'POST' });
      location.href = '/projects/' + projectId;
    } catch (e) { logSilent(e, 'task-page.delete'); }
  });

  // Spin task off into a brand-new project
  const promoteBtn = sidebar.querySelector('[data-action=promote-to-project]');
  if (promoteBtn) promoteBtn.addEventListener('click', async () => {
    if (!await UI.confirm(t('js.confirm.create_project_from_task'), { confirmLabel: 'Create project' })) return;
    await withButtonBusy(promoteBtn, async () => {
      try {
        const res = await api('/api/tasks/' + taskId + '/promote-to-project', { method: 'POST' });
        if (res?.url) location.href = res.url;
      } catch (e) { logSilent(e, 'task-page.promoteToProject'); }
    });
  });
})();
