import { api, UI } from './ui.js';

const FIELD_TYPES = [
  { value: 'text',     label: 'Text (single line)' },
  { value: 'textarea', label: 'Text (multi-line)' },
  { value: 'email',    label: 'Email' },
  { value: 'phone',    label: 'Phone' },
  { value: 'number',   label: 'Number' },
  { value: 'select',   label: 'Select (dropdown)' },
  { value: 'radio',    label: 'Radio buttons' },
  { value: 'checkbox', label: 'Checkboxes (multi)' },
];

const builder = document.querySelector('[data-form-builder]');
if (builder) {
  const formId      = builder.dataset.formId ? parseInt(builder.dataset.formId, 10) : null;
  const titleEl     = builder.querySelector('[data-form-title]');
  const descHidden  = builder.querySelector('#form-description-hidden');
  const fieldsList  = builder.querySelector('[data-fields-list]');
  const fieldsHint  = builder.querySelector('[data-fields-hint]');
  const footerHost  = builder.querySelector('[data-footer]');
  const toolbar     = builder.querySelector('[data-builder-toolbar]');

  let fields = parseJsonNode('builder-state-fields') || [];
  const footer = parseJsonNode('builder-state-footer') || {};

  renderAll();
  applyFooterState();
  wireStickyToolbar();
  wirePublicUrlRow();

  builder.querySelector('[data-action=add-field]').addEventListener('click', () => {
    fields.push({ key: '', type: 'text', label: '', required: false, options: [] });
    renderAll(fields.length - 1);
  });

  builder.querySelector('[data-action=save-form]').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const payload = {
      title: titleEl.value.trim(),
      description: (descHidden?.value || '').trim(),
      fields: fields.map(cleanField),
      footer: collectFooter(),
    };
    if (!payload.title) { UI.toast('Title is required', 'error'); return; }
    if (!payload.fields.length) { UI.toast('Add at least one field', 'error'); return; }
    btn.disabled = true;
    try {
      const url = formId ? '/forms/' + formId : '/forms';
      const res = await api(url, { method: 'POST', body: JSON.stringify(payload) });
      UI.toast('Form saved', 'success');
      if (!formId && res?.id) location.href = '/forms/' + res.id;
    } catch {} finally { btn.disabled = false; }
  });

  let sortable = null;

  function renderAll(scrollToIdx = -1) {
    fieldsList.replaceChildren();
    fields.forEach((f, idx) => fieldsList.appendChild(buildFieldCard(f, idx)));
    if (fieldsHint) fieldsHint.hidden = fields.length > 0;
    // (Re)attach Sortable on every render so new cards become draggable.
    if (sortable) { sortable.destroy(); sortable = null; }
    if (window.Sortable && fields.length > 1) {
      sortable = window.Sortable.create(fieldsList, {
        handle: '[data-drag-handle]',
        animation: 150,
        ghostClass: 'field-card--ghost',
        chosenClass: 'field-card--dragging',
        onEnd: (e) => {
          if (e.oldIndex === e.newIndex) return;
          const moved = fields.splice(e.oldIndex, 1)[0];
          fields.splice(e.newIndex, 0, moved);
          renderAll();
        },
      });
    }
    if (scrollToIdx >= 0 && fieldsList.children[scrollToIdx]) {
      const node = fieldsList.children[scrollToIdx];
      requestAnimationFrame(() => {
        node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        node.classList.add('field-card--flash');
        setTimeout(() => node.classList.remove('field-card--flash'), 1200);
        const labelInput = node.querySelector('[data-field-label]');
        if (labelInput) labelInput.focus();
      });
    }
  }

  function buildFieldCard(f, idx) {
    const card = document.createElement('div');
    card.className = 'field-card';
    card.dataset.fieldIndex = idx;

    // Header: drag handle + index + delete
    const head = document.createElement('div');
    head.className = 'field-card__head';
    const left = document.createElement('div');
    left.style.cssText = 'display:flex;align-items:center;gap:8px;';
    const drag = document.createElement('span');
    drag.className = 'field-card__drag';
    drag.setAttribute('data-drag-handle', '');
    drag.title = 'Drag to reorder';
    const dragIcon = document.createElement('i'); dragIcon.className = 'fa-solid fa-grip-vertical';
    drag.appendChild(dragIcon);
    left.appendChild(drag);
    const tag = document.createElement('span');
    tag.className = 'field-card__tag';
    tag.textContent = '#' + (idx + 1);
    left.appendChild(tag);
    head.appendChild(left);

    const ctrl = document.createElement('div');
    ctrl.className = 'field-card__ctrl';
    ctrl.appendChild(iconBtn('fa-trash', 'Delete', async () => {
      const label = (f.label || '').trim();
      const msg = label
        ? 'Delete field "' + label + '"?'
        : 'Delete this empty field?';
      if (!await UI.confirm(msg, { danger: true, confirmLabel: 'Delete' })) return;
      fields.splice(idx, 1);
      renderAll();
    }, true));
    head.appendChild(ctrl);
    card.appendChild(head);

    // Body: 4-column grid
    const body = document.createElement('div');
    body.className = 'field-card__body';

    body.appendChild(buildInput('Label', f.label, v => { f.label = v; }, 'data-field-label'));
    body.appendChild(buildInput('Key (auto if empty)', f.key, v => { f.key = v; }));

    // Type select
    const typeWrap = document.createElement('div');
    typeWrap.className = 'field-card__cell';
    const tLabel = document.createElement('label'); tLabel.textContent = 'Type';
    const tSel   = document.createElement('select'); tSel.className = 'input';
    for (const t of FIELD_TYPES) { const o = document.createElement('option'); o.value = t.value; o.textContent = t.label; tSel.appendChild(o); }
    tSel.value = f.type || 'text';
    tSel.addEventListener('change', () => { f.type = tSel.value; renderAll(); });
    typeWrap.appendChild(tLabel); typeWrap.appendChild(tSel);
    body.appendChild(typeWrap);

    // Required toggle
    const reqWrap = document.createElement('label');
    reqWrap.className = 'field-card__required';
    const reqCb = document.createElement('input'); reqCb.type = 'checkbox'; reqCb.checked = !!f.required;
    reqCb.addEventListener('change', () => { f.required = reqCb.checked; });
    reqWrap.appendChild(reqCb); reqWrap.appendChild(document.createTextNode('Required'));
    body.appendChild(reqWrap);

    card.appendChild(body);

    if (['select', 'radio', 'checkbox'].includes(f.type)) {
      const opts = document.createElement('div');
      opts.className = 'field-card__options';
      const oLabel = document.createElement('label'); oLabel.textContent = 'Options (one per line)';
      const ta = document.createElement('textarea'); ta.className = 'textarea'; ta.rows = 3;
      ta.value = (f.options || []).join('\n');
      ta.addEventListener('input', () => {
        f.options = ta.value.split('\n').map(s => s.trim()).filter(Boolean);
      });
      opts.appendChild(oLabel); opts.appendChild(ta);
      card.appendChild(opts);
    }
    return card;
  }

  function applyFooterState() {
    footerHost.querySelectorAll('[data-footer-show]').forEach(cb => {
      const k = cb.dataset.footerShow;
      cb.checked = footer[k] === true || footer[k] === undefined;
    });
    footerHost.querySelectorAll('[data-footer-val]').forEach(el => {
      const k = el.dataset.footerVal;
      el.value = (footer[k] !== undefined && footer[k] !== null) ? String(footer[k]) : '';
    });
  }

  function collectFooter() {
    const out = {};
    footerHost.querySelectorAll('[data-footer-show]').forEach(cb => { out[cb.dataset.footerShow] = !!cb.checked; });
    footerHost.querySelectorAll('[data-footer-val]').forEach(el => { out[el.dataset.footerVal] = el.value || ''; });
    return out;
  }

  function buildInput(labelText, value, onInput, dataAttr) {
    const wrap = document.createElement('div');
    wrap.className = 'field-card__cell';
    const lab = document.createElement('label'); lab.textContent = labelText;
    const inp = document.createElement('input'); inp.className = 'input'; inp.type = 'text'; inp.value = value || '';
    if (dataAttr) inp.setAttribute(dataAttr, '');
    inp.addEventListener('input', () => onInput(inp.value));
    wrap.appendChild(lab); wrap.appendChild(inp);
    return wrap;
  }

  function iconBtn(iconCls, title, onClick, danger = false) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = danger ? 'field-card__btn field-card__btn--danger' : 'field-card__btn';
    b.title = title;
    const i = document.createElement('i'); i.className = 'fa-solid ' + iconCls;
    b.appendChild(i);
    b.addEventListener('click', onClick);
    return b;
  }

  function cleanField(f) {
    return {
      key:      (f.key || '').trim() || (f.label || '').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, ''),
      type:     f.type || 'text',
      label:    (f.label || '').trim(),
      required: !!f.required,
      options:  Array.isArray(f.options) ? f.options.filter(o => (o || '').trim() !== '') : [],
    };
  }

  function parseJsonNode(id) {
    const el = document.getElementById(id);
    if (!el) return null;
    try { return JSON.parse(el.textContent); } catch { return null; }
  }

  function wirePublicUrlRow() {
    const row = document.querySelector('[data-public-url-row]');
    if (!row) return;
    const id      = row.dataset.formId;
    const urlText = row.querySelector('[data-public-url-text]');
    const urlLink = row.querySelector('[data-public-url]');
    const copyBtn = row.querySelector('[data-action=copy-url]');
    const rotBtn  = row.querySelector('[data-action=rotate-url]');

    if (copyBtn) copyBtn.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(urlText.textContent.trim()); UI.toast('Link copied', 'success'); }
      catch { UI.toast('Copy failed', 'error'); }
    });

    if (rotBtn) rotBtn.addEventListener('click', async () => {
      if (!await UI.confirm('Rotate the public link? The current URL will stop working immediately.', { danger: true, confirmLabel: 'Rotate link' })) return;
      rotBtn.disabled = true;
      try {
        const res = await api('/forms/' + id + '/rotate-hash', { method: 'POST' });
        if (res?.url) {
          urlText.textContent = res.url;
          urlLink.href = res.url;
          // Keep the "Open public" toolbar link in sync, if present
          const toolbarOpen = document.querySelector('[data-builder-toolbar] a[href*="/f/"]');
          if (toolbarOpen) toolbarOpen.href = res.url;
        }
        UI.toast('New URL generated', 'success');
      } catch {} finally { rotBtn.disabled = false; }
    });
  }

  function wireStickyToolbar() {
    if (!toolbar) return;
    // Toolbar is position:sticky; toggle .is-stuck once its top hits the viewport edge.
    // IntersectionObserver with a sentinel above the toolbar gives reliable detection.
    const sentinel = document.createElement('div');
    sentinel.style.cssText = 'position:absolute;left:0;right:0;top:0;height:1px;pointer-events:none;';
    toolbar.style.position = toolbar.style.position || 'sticky';
    toolbar.parentElement.insertBefore(sentinel, toolbar);
    const obs = new IntersectionObserver(([entry]) => {
      toolbar.classList.toggle('is-stuck', !entry.isIntersecting);
    }, { threshold: 1 });
    obs.observe(sentinel);
  }
}
