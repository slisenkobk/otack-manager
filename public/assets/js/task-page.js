import { api, UI } from './ui.js';

const titleEl = document.querySelector('.task-title');
const sidebar = document.querySelector('.task-sidebar');
if (!sidebar) {
  // not on a task page
} else {
  const taskId = sidebar.dataset.taskId;
  const projectId = sidebar.dataset.projectId;

  // Title blur
  let lastTitle = titleEl.textContent;
  titleEl.addEventListener('blur', async () => {
    const title = titleEl.textContent.trim();
    if (!title || title === lastTitle) { titleEl.textContent = lastTitle; return; }
    try {
      const res = await api('/tasks/' + taskId, { method: 'POST', body: JSON.stringify({ title }) });
      lastTitle = res.task.title;
      titleEl.textContent = res.task.title;
      UI.toast('Title saved', 'success');
    } catch {
      titleEl.textContent = lastTitle;
    }
  });
  titleEl.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); titleEl.blur(); }
    if (e.key === 'Escape') { titleEl.textContent = lastTitle; titleEl.blur(); }
  });

  // Description edit/save/cancel (Quill WYSIWYG)
  const descSection = document.querySelector('.task-description-section');
  const rendered = descSection.querySelector('.task-description-rendered');
  const editor = descSection.querySelector('.task-description-editor');
  descSection.querySelector('[data-action=edit-description]').addEventListener('click', () => {
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
        || '<span class="muted" style="color:var(--ink-3);">No description. Click Edit to add one.</span>';
      rendered.innerHTML = safeHtml; // nosec: server-sanitized HTML
      editor.style.display = 'none';
      rendered.style.display = 'block';
      UI.toast('Description saved', 'success');
    } catch {}
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
      try {
        await api('/tasks/' + taskId, { method: 'POST', body: JSON.stringify(body) });
        UI.toast(key.replace('_', ' ') + ' updated', 'success');
      } catch {}
    });
  });

  // Delete
  sidebar.querySelector('[data-action=delete-task]').addEventListener('click', async () => {
    if (!await UI.confirm('Delete this task permanently?', {danger: true, confirmLabel: 'Delete'})) return;
    try {
      await api('/tasks/' + taskId + '/delete', { method: 'POST' });
      location.href = '/projects/' + projectId;
    } catch {}
  });
}
