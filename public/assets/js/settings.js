// Settings page interactions. Currently just the brand-color picker:
// the visible <input type="color"> is decorative — the submitted value
// goes via a hidden input so we can distinguish "no override" (empty)
// from "user picked the default color" (non-empty).
//
// Reset → clears the hidden value AND repaints the picker to the system
// default brand. Submitting empty triggers the server-side "fall back to
// design-system brand" branch.

const DEFAULT_BRAND = '#c2410c';

function init() {
  document.querySelectorAll('[data-color-picker-group]').forEach(group => {
    const picker = group.querySelector('[data-color-picker]');
    const hidden = group.querySelector('[data-color-value]');
    const reset  = group.querySelector('[data-color-reset]');
    if (!picker || !hidden) return;

    picker.addEventListener('input', () => { hidden.value = picker.value; });

    reset?.addEventListener('click', () => {
      picker.value = DEFAULT_BRAND;
      hidden.value = '';
    });
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
