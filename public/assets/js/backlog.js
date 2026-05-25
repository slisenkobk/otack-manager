import { api, UI } from './ui.js';

const root = document.querySelector('.backlog');
if (root) {
  const projectId = +root.dataset.projectId;
  const columnId  = +root.dataset.columnId;
  const form = root.querySelector('.backlog__add');
  if (form && projectId && columnId) {
    const input = form.querySelector('input[name=title]');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const title = input.value.trim();
      if (!title) return;
      try {
        await api('/projects/' + projectId + '/tasks', {
          method: 'POST',
          body: JSON.stringify({ title, column_id: columnId }),
        });
        window.location.reload();
      } catch {
        UI.toast('Could not add task', 'error');
      }
    });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { input.value = ''; input.blur(); }
    });
  }
}
