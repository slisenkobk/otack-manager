// Auto-submit the avatar form when a file is picked.
const input = document.querySelector('[data-avatar-input]');
input?.addEventListener('change', () => {
  const form = input.closest('[data-avatar-form]');
  if (form) form.submit();
});
