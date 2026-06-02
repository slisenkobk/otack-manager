import { api, UI } from './ui.js';

const builder = document.querySelector('[data-poll-builder]');
if (builder) {
  const pollId      = builder.dataset.pollId ? parseInt(builder.dataset.pollId, 10) : null;
  const titleEl     = builder.querySelector('[data-poll-title]');
  const localeHidden = builder.querySelector('[data-poll-locale]');
  const descHidden  = builder.querySelector('#poll-description-hidden');
  const projectHidden = builder.querySelector('[data-poll-project]');
  const successEl   = builder.querySelector('[data-success-message]');
  const contactLabelEl = builder.querySelector('[data-contact-label]');
  const choiceLabelEl  = builder.querySelector('[data-choice-label]');
  const optionsList    = builder.querySelector('[data-options-list]');

  // Choice options support: add/remove rows, with at least 2 enforced at save.
  builder.querySelector('[data-action=add-option]').addEventListener('click', () => {
    optionsList.appendChild(buildOptionRow('', ''));
  });

  optionsList.addEventListener('click', (e) => {
    const rmBtn = e.target.closest('[data-action=remove-option]');
    if (!rmBtn) return;
    const rows = optionsList.querySelectorAll('[data-option-row]');
    if (rows.length <= 2) {
      UI.toast('A poll needs at least two options', 'error');
      return;
    }
    rmBtn.closest('[data-option-row]').remove();
  });

  builder.querySelector('[data-action=save-poll]').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const contactKind = builder.querySelector('input[name=poll_contact_field]:checked')?.value || 'email';
    const fields = collectFields(contactKind);

    const payload = {
      title:         (titleEl.value || '').trim(),
      locale:        localeHidden?.value || 'en',
      description:   (descHidden?.value || '').trim(),
      contact_field: contactKind,
      fields:        fields,
      project_id:    projectHidden?.value ? parseInt(projectHidden.value, 10) : null,
      success_message: (successEl?.value || '').trim(),
    };
    if (!payload.title) { UI.toast('Title is required', 'error'); titleEl.focus(); return; }
    if (countValidOptions(fields) < 2) {
      UI.toast('A poll needs at least two non-empty options', 'error');
      return;
    }

    btn.disabled = true;
    try {
      const url = pollId ? '/polls/' + pollId : '/polls';
      const res = await api(url, { method: 'POST', body: JSON.stringify(payload) });
      UI.toast('Poll saved', 'success');
      if (!pollId && res?.id) location.href = '/polls/' + res.id;
    } catch {} finally { btn.disabled = false; }
  });

  // Activate (draft → active). Once active, the page reloads to show stats.
  const activateBtn = builder.querySelector('[data-action=activate-poll]');
  if (activateBtn) activateBtn.addEventListener('click', async () => {
    const msg = activateBtn.dataset.confirm || 'Activate this poll? You will not be able to edit it anymore.';
    if (!await UI.confirm(msg, { confirmLabel: 'Activate' })) return;
    activateBtn.disabled = true;
    try {
      await api('/polls/' + pollId + '/activate', { method: 'POST' });
      UI.toast('Poll activated', 'success');
      location.reload();
    } catch {} finally { activateBtn.disabled = false; }
  });

  // Public URL row (only present for already-saved polls).
  const urlRow = builder.querySelector('[data-public-url-row]');
  if (urlRow) {
    const urlText = urlRow.querySelector('[data-public-url-text]');
    const urlLink = urlRow.querySelector('[data-public-url]');
    const copyBtn = urlRow.querySelector('[data-action=copy-url]');
    const rotBtn  = urlRow.querySelector('[data-action=rotate-url]');
    if (copyBtn) copyBtn.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(urlText.textContent.trim()); UI.toast('Link copied', 'success'); }
      catch { UI.toast('Copy failed', 'error'); }
    });
    if (rotBtn) rotBtn.addEventListener('click', async () => {
      if (!await UI.confirm('Rotate the public link? The current URL will stop working immediately.', { danger: true, confirmLabel: 'Rotate link' })) return;
      rotBtn.disabled = true;
      try {
        const res = await api('/polls/' + pollId + '/rotate-hash', { method: 'POST' });
        if (res?.url) {
          urlText.textContent = res.url;
          urlLink.href = res.url;
        }
        UI.toast('New URL generated', 'success');
      } catch {} finally { rotBtn.disabled = false; }
    });
  }

  function buildOptionRow(key, label) {
    const row = document.createElement('div');
    row.className = 'options-list__row';
    row.setAttribute('data-option-row', '');
    const inp = document.createElement('input');
    inp.className = 'input options-list__input';
    inp.type = 'text';
    inp.value = label || '';
    inp.placeholder = 'New option';
    inp.setAttribute('data-option-input', '');
    if (key) inp.setAttribute('data-option-key', key);
    const rmBtn = document.createElement('button');
    rmBtn.type = 'button';
    rmBtn.className = 'btn-ghost';
    rmBtn.setAttribute('data-action', 'remove-option');
    rmBtn.title = 'Remove';
    const x = document.createElement('i');
    x.className = 'fa-solid fa-xmark';
    rmBtn.appendChild(x);
    row.appendChild(inp);
    row.appendChild(rmBtn);
    return row;
  }

  // Rebuild the locked contact + choice fields from the current DOM state.
  // The server will reject anything that drops these or empties options below 2,
  // but we duplicate the rules client-side so save errors stay rare.
  function collectFields(contactKind) {
    const contactLabel = (contactLabelEl.value || '').trim() || (contactKind === 'phone' ? 'Phone' : 'Email');
    const choiceLabel  = (choiceLabelEl.value || '').trim()  || 'Choose one';
    const opts = [];
    optionsList.querySelectorAll('[data-option-row]').forEach((row, i) => {
      const inp = row.querySelector('[data-option-input]');
      const label = (inp?.value || '').trim();
      if (!label) return;
      const existingKey = inp?.dataset?.optionKey || '';
      const key = existingKey || ('opt_' + (i + 1));
      opts.push({ key, label });
    });
    return [
      {
        key: 'contact',
        type: contactKind === 'phone' ? 'tel' : 'email',
        label: contactLabel,
        required: true,
        locked: true,
      },
      {
        key: 'choice',
        type: 'radio',
        label: choiceLabel,
        required: true,
        locked: true,
        options: opts,
      },
    ];
  }

  function countValidOptions(fields) {
    const c = fields.find(f => f.key === 'choice');
    return c ? (c.options || []).filter(o => (o.label || '').trim() !== '').length : 0;
  }
}
