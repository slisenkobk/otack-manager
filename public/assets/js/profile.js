import { UI } from './ui.js';
import { t } from './utils.js';

// Avatar file picker — sits inside the unified profile form. Show the
// chosen file name so the user knows the click registered; the actual
// upload happens when they hit Save.
const fileInput = document.querySelector('[data-avatar-input]');
fileInput?.addEventListener('change', () => {
  const trigger = fileInput.closest('.profile-avatar__upload');
  if (!trigger) return;
  const f = fileInput.files?.[0];
  if (!f) return;
  // Replace the label text with the file name so the user has visible
  // feedback (the upload itself is deferred until they hit Save).
  const labelSpan = trigger.querySelector('[data-avatar-label]') || trigger;
  trigger.classList.add('profile-avatar__upload--ready');
  const span = trigger.querySelector('i')?.nextSibling;
  if (span && span.nodeType === Node.TEXT_NODE) {
    span.textContent = ' ' + f.name;
  }
});

// Remove-avatar button — submits the dedicated POST so we don't carry the
// "delete" intent through the unified Save flow. Wrapped in UI.confirm
// since this hard-deletes the file from disk.
const removeBtn = document.querySelector('[data-remove-avatar]');
const removeForm = document.querySelector('[data-remove-avatar-form]');
removeBtn?.addEventListener('click', async () => {
  if (!await UI.confirm(t('js.confirm.remove_avatar'), { danger: true, confirmLabel: 'Remove' })) return;
  removeForm?.submit();
});
