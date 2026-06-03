import { api, UI } from './ui.js';
import { logSilent, t } from './utils.js';

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
        UI.toast(t('js.toast.status_updated'), 'success');
        setTimeout(() => location.reload(), 400);
      } catch {
        statusSel.value = prev;
      }
    });
  }

  const projBtn = aside.querySelector('[data-action=convert-project]');
  if (projBtn) projBtn.addEventListener('click', async () => {
    if (!await UI.confirm(t('js.confirm.create_project_from_submission'), { confirmLabel: 'Create project' })) return;
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
    if (!projectId) { UI.toast(t('js.toast.pick_project_first'), 'error'); return; }
    if (!await UI.confirm(t('js.confirm.create_task_in_project'), { confirmLabel: 'Create task' })) return;
    taskBtn.disabled = true;
    try {
      const res = await api('/api/forms-data/' + id + '/promote', { method: 'POST', body: JSON.stringify({ type: 'task', project_id: projectId }) });
      if (res?.url) location.href = res.url;
    } catch { taskBtn.disabled = false; }
  });

  const delBtn = aside.querySelector('[data-action=delete-sub]');
  if (delBtn) delBtn.addEventListener('click', async () => {
    if (!await UI.confirm(t('js.confirm.delete_submission'), { danger: true, confirmLabel: 'Delete' })) return;
    try {
      await api('/api/forms-data/' + id + '/delete', { method: 'POST' });
      location.href = '/forms-data';
    } catch (e) { logSilent(e, 'forms-data.delete'); }
  });
}
