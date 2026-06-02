// Collapsible sidebar groups. The PHP partial pre-applies `is-open` when the
// active page lives inside the group, so the first-paint state is correct
// without JS. JS only restores the user's preference between navigations
// (when the active page is OUTSIDE the group) and binds the toggle button.
const STORAGE_PREFIX = 'sidebar.group.';

document.querySelectorAll('[data-nav-group]').forEach((group) => {
  const key   = group.dataset.navGroup;
  const btn   = group.querySelector('.nav-group__toggle');
  if (!btn) return;

  // If the server didn't auto-open (no active descendant), honour stored pref.
  if (!group.classList.contains('is-open')) {
    const stored = localStorage.getItem(STORAGE_PREFIX + key);
    if (stored === 'open') setOpen(group, btn, true);
  } else {
    // Server auto-opened — sync the storage so next visit remembers.
    localStorage.setItem(STORAGE_PREFIX + key, 'open');
  }

  btn.addEventListener('click', () => {
    const next = !group.classList.contains('is-open');
    setOpen(group, btn, next);
    localStorage.setItem(STORAGE_PREFIX + key, next ? 'open' : 'closed');
  });
});

function setOpen(group, btn, open) {
  group.classList.toggle('is-open', open);
  btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}
