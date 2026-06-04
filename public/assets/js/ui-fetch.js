// Authenticated JSON fetch wrapper used by every page module. Pulls the
// CSRF token from <meta>, sets Content-Type for JSON bodies, and surfaces
// non-2xx responses through UI.toast so silent failures stay visible.
//
// **Import via `./ui.js` (the façade), not directly from this file.** The
// façade also triggers `./ui-bootstrap.js`, which sets up the shell
// listeners (user menu, flash, mobile nav, custom-select observer). Direct
// imports bypass those.
import { UI } from './ui-modal.js';
import { logSilent } from './utils.js';

export async function api(url, opts = {}) {
  const headers = opts.headers || {};
  headers['X-CSRF-Token'] = document.querySelector('meta[name=csrf-token]')?.content || '';
  if (opts.body && !(opts.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
  }
  const res = await fetch(url, { ...opts, headers });
  let data = null;
  try { data = await res.json(); } catch (e) { logSilent(e, 'api.parseJson'); }
  if (!res.ok) {
    UI.toast(data?.error || ('HTTP ' + res.status), 'error');
    throw new Error(data?.error || ('HTTP ' + res.status));
  }
  return data;
}
