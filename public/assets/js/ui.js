// Public façade for the UI layer (J-6 split, 2026-06-04). The 411-LOC
// original was carved into three concerns:
//   - ui-modal.js     — UI.modal/confirm/prompt/toast/lightbox primitives
//   - ui-fetch.js     — `api()` wrapper with CSRF + JSON error toasts
//   - ui-bootstrap.js — user-menu, flash, mobile-nav, auto-submit,
//                       custom-select widget + buildCustomSelect()
//
// Every page module still does `import { api, UI, buildCustomSelect } from './ui.js'`,
// so the import shape stays stable. The side-effecting bootstrap runs once
// per page because the layout script tag and module imports resolve to the
// same canonical URL (`/assets/js/ui.js`, no `?v=…` query — see J-5).

import { UI } from './ui-modal.js';
import { api } from './ui-fetch.js';
import { buildCustomSelect } from './ui-bootstrap.js';

export { UI, api, buildCustomSelect };

// Legacy globals for non-module callers (e.g. ad-hoc inline event handlers).
window.UI = UI;
window.api = api;
