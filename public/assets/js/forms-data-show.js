import { api, UI } from './ui.js';

const aside = document.querySelector('[data-sub-id]');
if (aside) {
  const id = aside.dataset.subId;

  const statusSel = aside.querySelector('[data-status-select]');
  if (statusSel) {
    let prev = statusSel.value;
    statusSel.addEventListener('change', async () => {
      const next = statusSel.value;
      try {
        await api('/api/forms-data/' + id + '/status', { method: 'POST', body: JSON.stringify({ status: next }) });
        prev = next;
        UI.toast('Status updated', 'success');
        setTimeout(() => location.reload(), 400);
      } catch {
        statusSel.value = prev;
      }
    });
  }

  const projBtn = aside.querySelector('[data-action=convert-project]');
  if (projBtn) projBtn.addEventListener('click', async () => {
    if (!await UI.confirm('Create a new project from this submission?', { confirmLabel: 'Create project' })) return;
    projBtn.disabled = true;
    try {
      const res = await api('/api/forms-data/' + id + '/promote', { method: 'POST', body: JSON.stringify({ type: 'project' }) });
      if (res?.url) location.href = res.url;
    } catch { projBtn.disabled = false; }
  });

  const taskBtn = aside.querySelector('[data-action=convert-task]');
  if (taskBtn) taskBtn.addEventListener('click', async () => {
    const sel = aside.querySelector('[data-task-project]');
    const projectId = sel?.value ? parseInt(sel.value, 10) : 0;
    if (!projectId) { UI.toast('Pick a project first', 'error'); return; }
    if (!await UI.confirm('Create a task in this project?', { confirmLabel: 'Create task' })) return;
    taskBtn.disabled = true;
    try {
      const res = await api('/api/forms-data/' + id + '/promote', { method: 'POST', body: JSON.stringify({ type: 'task', project_id: projectId }) });
      if (res?.url) location.href = res.url;
    } catch { taskBtn.disabled = false; }
  });

  const delBtn = aside.querySelector('[data-action=delete-sub]');
  if (delBtn) delBtn.addEventListener('click', async () => {
    if (!await UI.confirm('Delete this submission permanently?', { danger: true, confirmLabel: 'Delete' })) return;
    try {
      await api('/api/forms-data/' + id + '/delete', { method: 'POST' });
      location.href = '/forms-data';
    } catch {}
  });
}
