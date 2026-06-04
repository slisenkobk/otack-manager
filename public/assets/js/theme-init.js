// No-flash theme initialiser. Loaded SYNCHRONOUSLY in <head> before any
// stylesheet so we can stamp `data-theme` on <html> before CSS evaluates.
//
// Why this file exists (CSS-6): we used to ship two duplicated dark-palette
// blocks in tokens.css — one inside `@media (prefers-color-scheme: dark)`
// for the auto case, one under `:root[data-theme="dark"]` for the explicit
// case. JS-set `data-theme` lets us keep only the second block; this file
// is the bridge that makes "auto" still feel automatic.
//
// Server already renders `<html data-theme="dark|light">` when the user has
// chosen explicitly (cookie `theme=dark|light`). When the cookie is missing
// or `auto`, the attribute is omitted and this script fills it in.
(function () {
  var html = document.documentElement;
  if (html.hasAttribute('data-theme')) return; // explicit choice — leave it
  var cookie = (document.cookie.match(/(?:^|; )theme=([^;]+)/) || [])[1];
  if (cookie === 'light' || cookie === 'dark') {
    html.setAttribute('data-theme', cookie);
    return;
  }
  // 'auto' or unset → follow system preference. matchMedia is supported
  // everywhere we run; the optional-chain on `.matches` keeps the script
  // a no-op on hypothetical environments without it.
  var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  html.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
})();
