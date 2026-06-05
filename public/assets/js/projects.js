import { api, UI } from './ui.js';
import { logSilent, withButtonBusy } from './utils.js';
import { buildField } from './ui-fields.js';

// Pin / unpin a project card from the index grid.
// Card itself is an <a>, so we stop propagation on the pin button click.
document.querySelectorAll('.card[data-project-id] [data-action="toggle-pin"]').forEach(btn => {
  btn.addEventListener('click', async (e) => {
    e.preventDefault();
    e.stopPropagation();
    const card = btn.closest('.card');
    const id = card?.dataset.projectId;
    if (!id) return;
    await withButtonBusy(btn, async () => {
      try {
        const res = await api('/api/projects/' + id + '/pin', { method: 'POST' });
        const pinned = !!res.pinned;
        btn.classList.toggle('is-on', pinned);
        card.classList.toggle('is-pinned', pinned);
        UI.toast(pinned ? 'Project pinned to top' : 'Project unpinned', 'success');
        const grid = card.closest('.cards-row');
        if (grid) {
          if (pinned) grid.prepend(card);
          else {
            const pinnedCards = grid.querySelectorAll('.card.is-pinned');
            const lastPinned = pinnedCards[pinnedCards.length - 1];
            if (lastPinned && lastPinned !== card) lastPinned.after(card);
          }
        }
      } catch (e) { logSilent(e, 'projects.pin'); }
    });
  });
});

// New-project modal — replaces the standalone /projects/new page so creation
// feels light-weight (same modal shell as the new-user flow). Labels travel
// in data-attributes on the trigger so the modal stays in the user's locale
// without us building a parallel JS i18n catalogue.
// Project colour picker — single built-in palette, kept in sync with the
// server-side ProjectRepository::PALETTE constant. No admin-configurable
// setting any more; the workspace settings panel doesn't expose this.
const PROJECT_PALETTE = [
  '#EA580C', '#5A4E3F', '#2563EB', '#CA8A04', '#4D6840',
  '#6D28D9', '#DC2626', '#0891B2', '#9333EA', '#0F766E',
];
function pickPaletteColor() {
  return PROJECT_PALETTE[Math.floor(Math.random() * PROJECT_PALETTE.length)];
}

function openProjectModal(trigger) {
  const L = trigger.dataset || {};
  const labels = {
    title:       L.labelTitle       || 'New project',
    name:        L.labelName        || 'Name',
    description: L.labelDescription || 'Description',
    color:       L.labelColor       || 'Color',
    submit:      L.labelSubmit      || 'Create',
    cancel:      L.labelCancel      || 'Cancel',
  };

  const body = document.createElement('div');
  const nameF = buildField(labels.name, () => {
    const i = document.createElement('input');
    i.className = 'input';
    i.placeholder = labels.name;
    return i;
  }, { spaced: true });
  body.appendChild(nameF.wrap);

  // Color: text + native picker, matching the show-page chip style. We avoid
  // Quill here on purpose — description-at-creation is rare and a textarea
  // keeps the modal compact (matches the user-create modal in feel).
  const colorVal = pickPaletteColor();
  const colorWrap = buildField(labels.color, () => {
    const row = document.createElement('div');
    row.className = 'color-picker-row';
    const text = document.createElement('input');
    text.type = 'text';
    text.className = 'input';
    text.value = colorVal;
    text.maxLength = 7;
    const sw = document.createElement('label');
    sw.className = 'color-swatch';
    sw.style.background = colorVal;
    const pick = document.createElement('input');
    pick.type = 'color';
    pick.value = colorVal;
    sw.appendChild(pick);
    row.appendChild(text);
    row.appendChild(sw);
    function apply(v) {
      if (!/^#[0-9a-fA-F]{6}$/.test(v)) return;
      sw.style.background = v;
      if (text.value.toLowerCase() !== v.toLowerCase()) text.value = v;
      if (pick.value.toLowerCase() !== v.toLowerCase()) pick.value = v;
    }
    pick.addEventListener('input', () => apply(pick.value));
    text.addEventListener('input', () => apply(text.value));
    row.__colorText = text;
    return row;
  }, { spaced: true });
  body.appendChild(colorWrap.wrap);

  // Description uses the same Quill editor as the show-page so creation
  // matches every other place we collect rich-text. wysiwyg.js's
  // MutationObserver picks the [data-quill] node up when the modal mounts.
  const descHidden = document.createElement('input');
  descHidden.type = 'hidden';
  descHidden.id = 'project-description-hidden-' + Date.now();
  const descEditor = document.createElement('div');
  descEditor.className = 'wysiwyg-editor';
  descEditor.setAttribute('data-quill', '');
  descEditor.dataset.quillTarget = '#' + descHidden.id;
  descEditor.dataset.placeholder = labels.description;
  const descHost = document.createElement('div');
  descHost.className = 'wysiwyg-host';
  descHost.appendChild(descEditor);
  const descF = buildField(labels.description, () => {
    const wrap = document.createElement('div');
    wrap.appendChild(descHost);
    wrap.appendChild(descHidden);
    return wrap;
  }, { spaced: true });
  body.appendChild(descF.wrap);

  UI.modal({
    title: labels.title,
    body,
    actions: [
      { label: labels.cancel, variant: 'btn--ghost', onClick: c => c() },
      { label: labels.submit, variant: 'submit', onClick: async (close) => {
          const name = nameF.input.value.trim();
          if (!name) { nameF.input.focus(); return; }
          const payload = {
            name,
            color: colorWrap.input.__colorText?.value || colorVal,
            description: (descHidden.value || '').trim(),
          };
          try {
            const res = await api('/projects', { method: 'POST', body: JSON.stringify(payload) });
            close();
            if (res && res.id) location.href = '/projects/' + res.id + '?tab=overview';
            else location.reload();
          } catch (e) { logSilent(e, 'projects.create'); }
        }
      },
    ],
  });
  setTimeout(() => nameF.input.focus(), 0);
}

document.addEventListener('click', (e) => {
  const t = e.target.closest('[data-action="new-project"]');
  if (!t) return;
  e.preventDefault();
  openProjectModal(t);
});

// Auto-open the modal when arriving from /dashboard's "New" link (and similar
// shortcuts elsewhere that set ?new=1). Drops the query-string after opening
// so a back-nav or reload doesn't keep re-triggering.
if (new URLSearchParams(location.search).get('new') === '1') {
  const trigger = document.querySelector('[data-action="new-project"]');
  if (trigger) {
    openProjectModal(trigger);
    const url = new URL(location.href);
    url.searchParams.delete('new');
    history.replaceState({}, '', url.pathname + (url.search ? url.search : ''));
  }
}
