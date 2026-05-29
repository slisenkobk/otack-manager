document.querySelectorAll('[data-auto-submit-on-change]').forEach(el => {
  el.addEventListener('change', () => {
    const form = el.closest('form');
    if (form) form.submit();
  });
});
