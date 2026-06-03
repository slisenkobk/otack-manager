// ui-fields.js — shared form-field builders used by modals across the app.
//
// Consolidates three near-identical buildField() copies that lived in
// kanban-columns.js, projects.js, and users.js. The label styling is the
// uppercase "small-caps" look used by every modal in the chrome.
//
// Contract:
//   buildField(label, factory, opts?) → { wrap, input }
//     - label   : visible label text
//     - factory : () => HTMLElement; element returned becomes the control
//     - opts.spaced (bool, default false) : adds 12px wrap margin-bottom
//                                           (matches projects/users modals
//                                           that stack several fields)
//
//   buildColorPickerField(label, initial = '#8B7C68') → { wrap, getValue }
//     Text + native color picker pair with two-way binding.

export function buildField(labelText, factory, opts = {}) {
  const wrap = document.createElement('div');
  wrap.className = 'field';
  if (opts.spaced) wrap.style.marginBottom = '12px';
  const label = document.createElement('label');
  label.textContent = labelText;
  label.style.fontSize = '11px';
  label.style.color = 'var(--ink-3)';
  label.style.textTransform = 'uppercase';
  label.style.letterSpacing = '.1em';
  label.style.display = 'block';
  label.style.marginBottom = opts.spaced ? '4px' : '6px';
  wrap.appendChild(label);
  const input = factory();
  wrap.appendChild(input);
  return { wrap, input };
}

// Reusable colour-picker row: text input + square swatch with hidden native picker.
export function buildColorPickerField(labelText, initial = '#8B7C68') {
  const wrap = document.createElement('div');
  wrap.className = 'field';
  const label = document.createElement('label');
  label.textContent = labelText;
  label.style.fontSize = '11px';
  label.style.color = 'var(--ink-3)';
  label.style.textTransform = 'uppercase';
  label.style.letterSpacing = '.1em';
  label.style.display = 'block';
  label.style.marginBottom = '6px';
  wrap.appendChild(label);

  const row = document.createElement('div');
  row.className = 'color-picker-row';

  const textInput = document.createElement('input');
  textInput.type = 'text';
  textInput.className = 'input';
  textInput.value = initial;
  textInput.maxLength = 7;
  row.appendChild(textInput);

  const swatch = document.createElement('label');
  swatch.className = 'color-swatch';
  swatch.style.background = initial;

  const colorInput = document.createElement('input');
  colorInput.type = 'color';
  colorInput.value = initial;
  swatch.appendChild(colorInput);
  row.appendChild(swatch);

  wrap.appendChild(row);

  function setColor(v) {
    if (!/^#[0-9a-fA-F]{6}$/.test(v)) return;
    swatch.style.background = v;
    if (textInput.value.toLowerCase() !== v.toLowerCase()) textInput.value = v;
    if (colorInput.value.toLowerCase() !== v.toLowerCase()) colorInput.value = v;
  }
  colorInput.addEventListener('input', () => setColor(colorInput.value));
  textInput.addEventListener('input', () => setColor(textInput.value));

  return { wrap, getValue: () => textInput.value };
}
