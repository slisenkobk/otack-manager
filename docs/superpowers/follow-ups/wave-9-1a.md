# Wave 9.1a — known follow-ups

Items discovered during execution that were intentionally NOT fixed inline
(scope of the wave was closed). Tracked here so they don't get lost.

## Pre-existing e2e flakes (verified on `main` before Wave A)

Both reproduce on the pre-Wave-A commit `ab60a43` when running the full e2e
suite (`make e2e`). Not introduced by Wave A; not blockers for v1.2.0 tag.

### 1. `qa-walk.spec.ts:126 — 1.4 login with correct password after throttle`

The test exercises the throttle reset path. With the DB-backed throttle from
Task 3 (S-1), the throttle row persists across spec files when the
`app.test.sqlite` is not wiped between them. Inside `qa-walk.spec.ts` itself
the file has its own `beforeAll` reset, so isolation runs pass, but the cascade
from the previous test in the same file (1.3) leaves a still-locked row that
the 15-minute window doesn't expire in CI time.

Fix path: either (a) reset the `login_attempts` row at the start of test 1.4,
(b) reduce the window via env in test mode, or (c) hit `LoginThrottle::resetFails`
through a test-only admin endpoint. Not done — pre-existing.

### 2. `visual-audit.spec.ts:841 — B12 no console errors on key pages`

The actual failure is in the `loginAs(alice@u.com)` helper, not in any
console-error assertion. By the time B12 runs (last test in a long serial
file), alice's session is broken — either logged out by an earlier test, or
the throttle row from earlier failed attempts is still active. Reproduces
identically on `main`.

Fix path: add `await registerAdmin(page)` or a fresh-DB seed at the top of
B12, OR run B12 in its own file with its own `beforeAll` wipe. Not done —
pre-existing.

## Notes carried forward

- Wave 9.1b architecture cleanup will touch the inline event listeners in
  `public/index.php` (A-2). The CSP `style-src 'unsafe-inline'` migration
  (S-6) depends on V-3..V-5 progress in Wave B.
- `LOGIN_HASH` reuse as anti-bot HMAC secret (S-8) is documented in
  `docs/SECURITY.md` §9 but not yet split into `APP_SECRET`. Wave B item.
- `HtmlSanitizer` still falls back to `strip_tags` when `ext-dom` missing
  (S-3). `docs/DEPLOYMENT.md` lists `ext-dom` as required; making it a hard
  prereq at boot is Wave B.

## Stats at end of Wave 9.1a

- Unit: 234 passed (was 189) — +45 tests.
- API: 84 passed (unchanged).
- E2E: 65 passed, 2 pre-existing flakes documented above, 48 skipped (serial
  cascade — same number as on `main`).
- Commit count: 16 commits on `fix/9-1a-ship-blockers`.

## Hot-fix v1.2.1 (CSP regression from J-7)

The original J-7 emit used `<script>window.__t = …</script>` (inline executable),
which is blocked by the existing `script-src 'self'` CSP. Effect on v1.2.0:
every migrated toast/confirm rendered the raw key (`"js.toast.saved"`) instead
of its translation.

Hot-fix shipped on `main` as v1.2.1: switched the channel to a non-executable
JSON island (`<script type="application/json" id="i18n-js">…</script>`) parsed
lazily on first `t()` call in `public/assets/js/utils.js`. Same payload, no
CSP violation. Verified in a real browser session — `window.__t` populates
correctly and `t('js.toast.column_added')` returns "Column added".

## Other pre-existing CSP issues NOT touched in Wave A

- `views/projects/form.php:38-…` ships an inline `<script>` that has always
  been CSP-blocked. Wave B's CSP nonce work (S-6) should cover it.
- The audit's S-6 item is about migrating `style-src 'unsafe-inline'` to
  nonces; `script-src` was already strict and no inline scripts other than
  the one above existed pre-Wave-A. Future contributors: do not add inline
  scripts without a nonce or a JSON-island pattern.
