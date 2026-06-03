import { api, UI } from './ui.js';
import { logSilent } from './utils.js';

const overview = document.querySelector('.project-overview');
if (overview) {
  const projectId = overview.dataset.projectId;

  const rendered = overview.querySelector('.project-description-rendered');
  const editor   = overview.querySelector('.project-description-editor');
  const editBtn  = overview.querySelector('[data-action=edit-description]');

  function replaceRenderedWith(html) {
    // Server returns HTML already sanitized via HtmlSanitizer::clean()
    const tpl = document.createElement('template');
    tpl.innerHTML = html;
    rendered.replaceChildren(...tpl.content.childNodes);
  }

  editBtn?.addEventListener('click', () => {
    rendered.style.display = 'none';
    editor.style.display = 'block';
    const quillEditor = editor.querySelector('.ql-editor');
    if (quillEditor) quillEditor.focus();
  });

  editor?.querySelector('[data-action=cancel-description]')?.addEventListener('click', () => {
    editor.style.display = 'none';
    rendered.style.display = 'block';
  });

  editor?.querySelector('[data-action=save-description]')?.addEventListener('click', async () => {
    const hidden = document.querySelector('#project-description-hidden');
    const description = hidden ? hidden.value : '';
    try {
      const res = await api('/projects/' + projectId, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ description }),
      });
      const html = res.project?.description
        ? res.project.description
        : '<em class="overview-panel__empty">No description</em>';
      replaceRenderedWith(html);
      editor.style.display = 'none';
      rendered.style.display = 'block';
      UI.toast('Description saved', 'success');
    } catch (e) { logSilent(e, 'project-page.saveDescription'); }
  });

  const titleEl = overview.querySelector('.project-title[contenteditable]');
  if (titleEl) {
    let lastTitle = titleEl.textContent.trim();
    titleEl.addEventListener('blur', async () => {
      const name = titleEl.textContent.trim();
      if (!name || name === lastTitle) { titleEl.textContent = lastTitle; return; }
      try {
        const res = await api('/projects/' + projectId, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name }),
        });
        lastTitle = res.project?.name || name;
        titleEl.textContent = lastTitle;
        UI.toast('Project name saved', 'success');
      } catch {
        titleEl.textContent = lastTitle;
      }
    });
    titleEl.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); titleEl.blur(); }
      if (e.key === 'Escape') { titleEl.textContent = lastTitle; titleEl.blur(); }
    });
  }

  const colorInput = overview.querySelector('[data-project-color]');
  if (colorInput) {
    const avatar = overview.querySelector('.project-avatar');
    colorInput.addEventListener('input', () => {
      if (avatar) avatar.style.background = colorInput.value;
    });
    colorInput.addEventListener('change', async () => {
      try {
        await api('/projects/' + projectId, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ color: colorInput.value }),
        });
        UI.toast('Color saved', 'success');
      } catch (e) { logSilent(e, 'project-page.saveColor'); }
    });
  }

  overview.querySelector('[data-action=delete-project]')?.addEventListener('click', async () => {
    if (!await UI.confirm('Delete this project permanently? All tasks, comments and attachments will be lost.',
                          { danger: true, confirmLabel: 'Delete' })) return;
    try {
      await api('/projects/' + projectId + '/delete', { method: 'POST' });
      location.href = '/projects';
    } catch (e) { logSilent(e, 'project-page.delete'); }
  });
}
