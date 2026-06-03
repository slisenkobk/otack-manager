import { api, UI } from './ui.js';
import { logSilent, t } from './utils.js';

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
        UI.toast(t('js.toast.task_added'), 'success');
        window.location.reload();
      } catch {
        UI.toast(t('js.toast.task_add_failed'), 'error');
      }
    });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { input.value = ''; input.blur(); }
    });
  }

  // Lazy-load more backlog rows
  const list = root.querySelector('[data-backlog-list]');
  const sentinel = list?.querySelector('[data-backlog-sentinel]');
  if (list && sentinel) {
    const colId = list.dataset.columnId;
    const projId = list.dataset.projectId;
    const io = new IntersectionObserver(async entries => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        io.unobserve(sentinel);
        const offset = +(list.dataset.loaded || 0);
        try {
          const res = await fetch('/api/projects/' + projId + '/columns/' + colId + '/tasks?offset=' + offset + '&limit=50&format=backlog', {
            headers: { 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]')?.content || '' },
          });
          const data = await res.json();
          if (!data.ok) continue;
          const doc = new DOMParser().parseFromString('<ul>' + data.html + '</ul>', 'text/html');
          const newRows = doc.querySelector('ul') ? Array.from(doc.querySelector('ul').children) : [];
          newRows.forEach(r => sentinel.before(r));
          list.dataset.loaded = String(offset + newRows.length);
          if (+list.dataset.loaded < +list.dataset.total) {
            io.observe(sentinel);
          } else {
            sentinel.remove();
          }
        } catch (e) { logSilent(e, 'backlog.lazyLoad'); }
      }
    }, { rootMargin: '200px' });
    io.observe(sentinel);
  }
}
