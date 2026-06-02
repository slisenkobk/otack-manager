// Collapsible sidebar groups. Open/closed state is driven entirely by the
// server via $activeNav: the partial adds `is-open` when the current page
// lives inside the group, otherwise the group renders collapsed. JS only
// binds the toggle button so the user can flip the state within a single
// page view — refreshing or navigating resets to the server-truth state,
// which is what the user expects ("inside Integrations → open; on a
// Projects page → closed").
document.querySelectorAll('[data-nav-group]').forEach((group) => {
  const btn = group.querySelector('.nav-group__toggle');
  if (!btn) return;
  btn.addEventListener('click', () => {
    const next = !group.classList.contains('is-open');
    group.classList.toggle('is-open', next);
    btn.setAttribute('aria-expanded', next ? 'true' : 'false');
  });
});
