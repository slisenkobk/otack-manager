import { api, UI } from './ui.js';

// Tag search / filter
const tagSearch = document.querySelector('[data-tag-search]');
if (tagSearch) {
  const rows = document.querySelectorAll('[data-tag-row]');
  tagSearch.addEventListener('input', () => {
    const q = tagSearch.value.trim().toLowerCase();
    rows.forEach(r => {
      const name = (r.querySelector('[data-tag-name]')?.textContent || '').toLowerCase();
      r.style.display = !q || name.includes(q) ? '' : 'none';
    });
  });
}

document.querySelectorAll('.card[data-tag-id]').forEach(card => {
  const tagId   = card.dataset.tagId;
  const nameEl  = card.querySelector('.tag-name');
  const colorIn = card.querySelector('.tag-color-input');
  const swatch  = card.querySelector('.tag-swatch');

  // Rename on blur
  nameEl?.addEventListener('blur', async () => {
    const newName = nameEl.textContent.trim();
    const original = nameEl.dataset.original;
    if (newName === original || newName === '') {
      nameEl.textContent = original;
      return;
    }
    try {
      await api('/api/admin/tags/' + tagId, {
        method: 'POST',
        body: JSON.stringify({ name: newName }),
      });
      nameEl.dataset.original = newName;
      UI.toast('Tag renamed', 'success');
    } catch {
      nameEl.textContent = original;
    }
  });

  nameEl?.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); nameEl.blur(); }
    if (e.key === 'Escape') {
      nameEl.textContent = nameEl.dataset.original;
      nameEl.blur();
    }
  });

  // Color change
  colorIn?.addEventListener('change', async () => {
    const newColor = colorIn.value;
    try {
      await api('/api/admin/tags/' + tagId, {
        method: 'POST',
        body: JSON.stringify({ color: newColor }),
      });
      if (swatch) swatch.style.background = newColor;
      UI.toast('Color updated', 'success');
    } catch {
      // error shown by api()
    }
  });

  // Delete
  card.querySelector('[data-action="delete-tag"]')?.addEventListener('click', async () => {
    const name = nameEl?.dataset.original || nameEl?.textContent?.trim() || 'this tag';
    if (!await UI.confirm('Delete tag "' + name + '"? This removes it from all projects and tasks.', { danger: true, confirmLabel: 'Delete' })) return;
    try {
      await api('/api/admin/tags/' + tagId + '/delete', { method: 'POST' });
      card.remove();
      UI.toast('Tag deleted', 'success');
    } catch {
      // error shown by api()
    }
  });
});
