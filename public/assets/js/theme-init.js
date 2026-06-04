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
  if (!window.matchMedia) {
    html.setAttribute('data-theme', 'light');
    return;
  }
  var mql = window.matchMedia('(prefers-color-scheme: dark)');
  var apply = function () {
    // Only re-apply when the user is still on 'auto' — explicit choice
    // means data-theme is set (and the user-menu toggle owns it).
    var c = (document.cookie.match(/(?:^|; )theme=([^;]+)/) || [])[1];
    if (c === 'light' || c === 'dark') return;
    html.setAttribute('data-theme', mql.matches ? 'dark' : 'light');
  };
  apply();
  // Live-follow OS theme changes mid-session. addEventListener is the
  // current API; addListener is the deprecated fallback for older
  // browsers (matchMedia is a MediaQueryList).
  if (mql.addEventListener) mql.addEventListener('change', apply);
  else if (mql.addListener) mql.addListener(apply);
})();
