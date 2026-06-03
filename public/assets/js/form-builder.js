import { api, UI } from './ui.js';
import { logSilent, t, withButtonBusy } from './utils.js';

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

// Encapsulates the form-builder UI on /forms/{id}. The page is a single root
// `[data-form-builder]` with named sub-nodes (title input, fields list, etc.).
// FormBuilder owns the in-memory `fields` array and re-renders the cards list
// on every mutation (add, delete, reorder, type change).
class FormBuilder {
  constructor(root) {
    this.root         = root;
    this.formId       = root.dataset.formId ? parseInt(root.dataset.formId, 10) : null;
    this.titleEl      = root.querySelector('[data-form-title]');
    this.localeHidden = root.querySelector('[data-form-locale]');
    this.descHidden   = root.querySelector('#form-description-hidden');
    this.fieldsList   = root.querySelector('[data-fields-list]');
    this.fieldsHint   = root.querySelector('[data-fields-hint]');
    this.footerHost   = root.querySelector('[data-footer]');
    this.toolbar      = root.querySelector('[data-builder-toolbar]');

    this.fields   = this.parseJsonNode('builder-state-fields') || [];
    this.footer   = this.parseJsonNode('builder-state-footer') || {};
    this.sortable = null;
  }

  init() {
    this.renderAll();
    this.applyFooterState();
    this.wireStickyToolbar();
    this.wirePublicUrlRow();
    this.wireIntegration();

    this.root.querySelector('[data-action=add-field]').addEventListener('click', () => {
      this.fields.push({ key: '', type: 'text', label: '', required: false, options: [] });
      this.renderAll(this.fields.length - 1);
    });

    this.root.querySelector('[data-action=save-form]').addEventListener('click', (e) => this.save(e));
  }

  async save(e) {
    const btn = e.currentTarget;
    const projectVal = this.root.querySelector('[data-form-project]')?.value || '';
    const autoCb    = this.root.querySelector('[data-auto-create-task]');
    const tplInput  = this.root.querySelector('[data-task-title-template]');
    const successEl = this.root.querySelector('[data-success-message]');
    const payload = {
      title: this.titleEl.value.trim(),
      locale: this.localeHidden?.value || 'en',
      description: (this.descHidden?.value || '').trim(),
      fields: this.fields.map(this.cleanField),
      footer: this.collectFooter(),
      project_id: projectVal ? parseInt(projectVal, 10) : null,
      auto_create_task: !!(autoCb && autoCb.checked && projectVal),
      task_title_template: (tplInput?.value || '').trim(),
      success_message: (successEl?.value || '').trim(),
    };
    if (!payload.title) { UI.toast(t('js.toast.title_required'), 'error'); return; }
    if (!payload.fields.length) { UI.toast(t('js.toast.add_one_field'), 'error'); return; }
    await withButtonBusy(btn, async () => {
      try {
        const url = this.formId ? '/forms/' + this.formId : '/forms';
        const res = await api(url, { method: 'POST', body: JSON.stringify(payload) });
        UI.toast(t('js.toast.form_saved'), 'success');
        if (!this.formId && res?.id) location.href = '/forms/' + res.id;
      } catch (e) { logSilent(e, 'form-builder.save'); }
    });
  }

  renderAll(scrollToIdx = -1) {
    // When no explicit scroll target is requested (type change, delete,
    // drag-drop reorder), preserve the user's scroll position — wiping
    // fieldsList via replaceChildren() momentarily collapses page height
    // and the browser clamps scrollY to the new max (often 0).
    const preserveScroll = scrollToIdx < 0;
    const savedScrollY = preserveScroll ? window.scrollY : 0;

    this.fieldsList.replaceChildren();
    this.fields.forEach((f, idx) => this.fieldsList.appendChild(this.buildFieldCard(f, idx)));
    if (this.fieldsHint) this.fieldsHint.hidden = this.fields.length > 0;
    // (Re)attach Sortable on every render so new cards become draggable.
    if (this.sortable) { this.sortable.destroy(); this.sortable = null; }
    if (window.Sortable && this.fields.length > 1) {
      this.sortable = window.Sortable.create(this.fieldsList, {
        handle: '[data-drag-handle]',
        animation: 150,
        ghostClass: 'field-card--ghost',
        chosenClass: 'field-card--dragging',
        onEnd: (e) => {
          if (e.oldIndex === e.newIndex) return;
          const moved = this.fields.splice(e.oldIndex, 1)[0];
          this.fields.splice(e.newIndex, 0, moved);
          this.renderAll();
        },
      });
    }
    if (preserveScroll) {
      window.scrollTo(0, savedScrollY);
    } else if (this.fieldsList.children[scrollToIdx]) {
      const node = this.fieldsList.children[scrollToIdx];
      requestAnimationFrame(() => {
        node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        node.classList.add('field-card--flash');
        setTimeout(() => node.classList.remove('field-card--flash'), 1200);
        const labelInput = node.querySelector('[data-field-label]');
        if (labelInput) labelInput.focus();
      });
    }
  }

  buildFieldCard(f, idx) {
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
    ctrl.appendChild(this.iconBtn('fa-trash', 'Delete', async () => {
      const label = (f.label || '').trim();
      const msg = label
        ? 'Delete field "' + label + '"?'
        : 'Delete this empty field?';
      if (!await UI.confirm(msg, { danger: true, confirmLabel: 'Delete' })) return;
      this.fields.splice(idx, 1);
      this.renderAll();
    }, true));
    head.appendChild(ctrl);
    card.appendChild(head);

    // Body: 4-column grid
    const body = document.createElement('div');
    body.className = 'field-card__body';

    body.appendChild(this.buildInput('Label', f.label, v => { f.label = v; }, 'data-field-label'));
    body.appendChild(this.buildInput('Key (auto if empty)', f.key, v => { f.key = v; }));

    // Type select
    const typeWrap = document.createElement('div');
    typeWrap.className = 'field-card__cell';
    const tLabel = document.createElement('label'); tLabel.textContent = 'Type';
    const tSel   = document.createElement('select'); tSel.className = 'input';
    for (const ft of FIELD_TYPES) { const o = document.createElement('option'); o.value = ft.value; o.textContent = ft.label; tSel.appendChild(o); }
    tSel.value = f.type || 'text';
    tSel.addEventListener('change', () => { f.type = tSel.value; this.renderAll(); });
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

  applyFooterState() {
    this.footerHost.querySelectorAll('[data-footer-show]').forEach(cb => {
      const k = cb.dataset.footerShow;
      cb.checked = this.footer[k] === true || this.footer[k] === undefined;
    });
    this.footerHost.querySelectorAll('[data-footer-val]').forEach(el => {
      const k = el.dataset.footerVal;
      el.value = (this.footer[k] !== undefined && this.footer[k] !== null) ? String(this.footer[k]) : '';
    });
  }

  collectFooter() {
    const out = {};
    this.footerHost.querySelectorAll('[data-footer-show]').forEach(cb => { out[cb.dataset.footerShow] = !!cb.checked; });
    this.footerHost.querySelectorAll('[data-footer-val]').forEach(el => { out[el.dataset.footerVal] = el.value || ''; });
    return out;
  }

  buildInput(labelText, value, onInput, dataAttr) {
    const wrap = document.createElement('div');
    wrap.className = 'field-card__cell';
    const lab = document.createElement('label'); lab.textContent = labelText;
    const inp = document.createElement('input'); inp.className = 'input'; inp.type = 'text'; inp.value = value || '';
    if (dataAttr) inp.setAttribute(dataAttr, '');
    inp.addEventListener('input', () => onInput(inp.value));
    wrap.appendChild(lab); wrap.appendChild(inp);
    return wrap;
  }

  iconBtn(iconCls, title, onClick, danger = false) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = danger ? 'field-card__btn field-card__btn--danger' : 'field-card__btn';
    b.title = title;
    const i = document.createElement('i'); i.className = 'fa-solid ' + iconCls;
    b.appendChild(i);
    b.addEventListener('click', onClick);
    return b;
  }

  cleanField(f) {
    return {
      key:      (f.key || '').trim() || (f.label || '').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, ''),
      type:     f.type || 'text',
      label:    (f.label || '').trim(),
      required: !!f.required,
      options:  Array.isArray(f.options) ? f.options.filter(o => (o || '').trim() !== '') : [],
    };
  }

  parseJsonNode(id) {
    const el = document.getElementById(id);
    if (!el) return null;
    try { return JSON.parse(el.textContent); } catch { return null; }
  }

  wirePublicUrlRow() {
    const row = document.querySelector('[data-public-url-row]');
    if (!row) return;
    const id      = row.dataset.formId;
    const urlText = row.querySelector('[data-public-url-text]');
    const urlLink = row.querySelector('[data-public-url]');
    const copyBtn = row.querySelector('[data-action=copy-url]');
    const rotBtn  = row.querySelector('[data-action=rotate-url]');

    if (copyBtn) copyBtn.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(urlText.textContent.trim()); UI.toast(t('js.toast.link_copied'), 'success'); }
      catch { UI.toast(t('js.toast.copy_failed'), 'error'); }
    });

    if (rotBtn) rotBtn.addEventListener('click', async () => {
      if (!await UI.confirm(t('js.confirm.rotate_public_link'), { danger: true, confirmLabel: 'Rotate link' })) return;
      await withButtonBusy(rotBtn, async () => {
        try {
          const res = await api('/forms/' + id + '/rotate-hash', { method: 'POST' });
          if (res?.url) {
            urlText.textContent = res.url;
            urlLink.href = res.url;
            // Keep the "Open public" toolbar link in sync, if present
            const toolbarOpen = document.querySelector('[data-builder-toolbar] a[href*="/f/"]');
            if (toolbarOpen) toolbarOpen.href = res.url;
          }
          UI.toast(t('js.toast.new_url_generated'), 'success');
        } catch (e) { logSilent(e, 'form-builder.rotateHash'); }
      });
    });
  }

  wireIntegration() {
    const section = this.root.querySelector('[data-integration]');
    if (!section) return;
    const projectHidden = section.querySelector('[data-form-project]');
    const autoRow      = section.querySelector('[data-auto-task-row]');
    const tplRow       = section.querySelector('[data-task-template-row]');
    const autoCb       = section.querySelector('[data-auto-create-task]');
    if (!projectHidden) return;

    const refresh = () => {
      const hasProject = !!(projectHidden.value && projectHidden.value !== '');
      if (autoRow) autoRow.hidden = !hasProject;
      if (!hasProject && autoCb) autoCb.checked = false;
      const showTpl = hasProject && !!(autoCb && autoCb.checked);
      if (tplRow) tplRow.hidden = !showTpl;
    };

    projectHidden.addEventListener('change', refresh);
    if (autoCb) autoCb.addEventListener('change', refresh);
    refresh();
  }

  wireStickyToolbar() {
    if (!this.toolbar) return;
    // Toolbar is position:sticky; toggle .is-stuck once its top hits the viewport edge.
    // IntersectionObserver with a sentinel above the toolbar gives reliable detection.
    const sentinel = document.createElement('div');
    sentinel.style.cssText = 'position:absolute;left:0;right:0;top:0;height:1px;pointer-events:none;';
    this.toolbar.style.position = this.toolbar.style.position || 'sticky';
    this.toolbar.parentElement.insertBefore(sentinel, this.toolbar);
    const obs = new IntersectionObserver(([entry]) => {
      this.toolbar.classList.toggle('is-stuck', !entry.isIntersecting);
    }, { threshold: 1 });
    obs.observe(sentinel);
  }
}

const root = document.querySelector('[data-form-builder]');
if (root) new FormBuilder(root).init();
