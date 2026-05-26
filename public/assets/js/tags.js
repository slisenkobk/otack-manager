import { api, UI } from './ui.js';

document.querySelectorAll('.tag-picker').forEach(picker => {
  const scope      = picker.dataset.scope;
  const entityType = picker.dataset.entityType;
  const entityId   = picker.dataset.entityId;
  const chips      = picker.querySelector('.tag-chips');
  const search     = picker.querySelector('[data-tag-search]');
  const dropdown   = picker.querySelector('[data-tag-dropdown]');
  const hiddenAll  = picker.querySelector('[data-all-tags]');

  // Attach remove handlers to already-rendered chips
  chips.querySelectorAll('[data-action="remove-tag"]').forEach(btn => attachRemove(btn));

  function attachRemove(btn) {
    btn.addEventListener('click', async () => {
      const chip  = btn.closest('.tag');
      const tagId = chip.dataset.tagId;
      const url   = entityType === 'project'
        ? '/api/projects/' + entityId + '/tags/' + tagId + '/delete'
        : '/api/tasks/' + entityId + '/tags/' + tagId + '/delete';
      try {
        await api(url, { method: 'POST' });
        attachedIds.delete(+tagId);
        chip.remove();
        UI.toast('Tag removed', 'success');
      } catch {}
    });
  }

  if (!search) return;

  const allTags    = hiddenAll ? JSON.parse(hiddenAll.value) : [];
  const attachedIds = new Set([...chips.querySelectorAll('.tag')].map(c => +c.dataset.tagId));

  function render(q) {
    dropdown.textContent = '';
    const lower   = q.toLowerCase();
    const available = allTags.filter(t => !attachedIds.has(+t.id));
    const matches = (q
      ? available.filter(t => t.name.toLowerCase().includes(lower))
      : available).slice(0, 12);
    const exact = q ? allTags.find(t => t.name.toLowerCase() === lower) : true;

    if (!matches.length && !q) {
      dropdown.appendChild(mkRow('No tags yet — start typing to create one.', null, true));
    }
    matches.forEach(t => dropdown.appendChild(mkRow(t, () => attachExisting(t))));
    if (q && !exact) {
      const row = mkRow('+ Create "' + q + '"', () => createAndAttach(q));
      if (matches.length) row.style.borderTop = '1px solid var(--rule)';
      dropdown.appendChild(row);
    }
    dropdown.style.display = 'block';
  }

  function mkRow(payload, onClick, isHint = false) {
    const row = document.createElement('div');
    row.className = 'tag-dropdown__row' + (isHint ? ' tag-dropdown__row--hint' : '');
    if (typeof payload === 'string') {
      row.textContent = payload;
    } else {
      const dot = document.createElement('span');
      dot.className = 'tag-dropdown__dot';
      dot.style.background = payload.color || '#8B7C68';
      row.appendChild(dot);
      const name = document.createElement('span');
      name.textContent = payload.name;
      row.appendChild(name);
    }
    if (onClick) row.addEventListener('click', onClick);
    return row;
  }

  async function attachExisting(tag) {
    const url = entityType === 'project'
      ? '/api/projects/' + entityId + '/tags'
      : '/api/tasks/' + entityId + '/tags';
    try {
      await api(url, { method: 'POST', body: JSON.stringify({ tag_id: +tag.id }) });
      appendChip(tag);
      attachedIds.add(+tag.id);
      search.value = '';
      dropdown.style.display = 'none';
      UI.toast('Tag added', 'success');
    } catch {}
  }

  async function createAndAttach(name) {
    try {
      const res = await api('/api/tags', { method: 'POST', body: JSON.stringify({ scope, name }) });
      const tag = res.tag;
      allTags.push(tag);
      await attachExisting(tag);
    } catch {}
  }

  function appendChip(t) {
    const span       = document.createElement('span');
    span.className   = 'tag';
    span.dataset.tagId = t.id;
    span.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:3px 8px;background:' +
      (t.color || '#8B7C68') + '33;color:var(--ink);border-radius:999px;font-size:11px;font-family:var(--font-mono);letter-spacing:.05em;';
    span.textContent = t.name + ' ';
    const btn        = document.createElement('button');
    btn.type         = 'button';
    btn.dataset.action = 'remove-tag';
    btn.style.cssText  = 'background:none;border:none;cursor:pointer;color:var(--ink-3);padding:0;font-size:10px;';
    const i          = document.createElement('i');
    i.className      = 'fa-solid fa-xmark';
    btn.appendChild(i);
    span.appendChild(btn);
    chips.appendChild(span);
    attachRemove(btn);
  }

  search.addEventListener('input', () => render(search.value.trim()));
  search.addEventListener('focus', () => render(search.value.trim()));
  document.addEventListener('click', e => {
    if (!picker.contains(e.target)) dropdown.style.display = 'none';
  });
});
