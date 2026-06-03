# Security

This document describes the security posture of Otack Manager. It is a working
reference for operators and contributors, not a marketing page — assumptions are
spelled out so they can be checked.

## 1. Threat model

We defend against (in roughly decreasing order of attention paid):

- **Stored XSS** on persisted Quill HTML (task descriptions, comments) — every
  Quill-derived field flows through [`HtmlSanitizer`](../system/Service/HtmlSanitizer.php).
- **CSRF** on every state-changing web request — global gate in
  [`public/index.php`](../public/index.php) verifies `_csrf` token or
  `X-CSRF-Token` header.
- **Session fixation** on privilege transitions — `session_regenerate_id(true)`
  is called on login and on register-auto-login in
  [`AuthController`](../system/Controller/AuthController.php).
- **Brute-force login** — DB-backed throttle in
  [`LoginThrottle`](../system/Auth/LoginThrottle.php), 5 attempts per 15-minute
  window keyed on `sha256(lower(trim(email)))`.
- **IP spoofing via `X-Forwarded-For`** — XFF is ignored unless `REMOTE_ADDR` is
  in `TRUSTED_PROXIES`.
- **Open redirects** — internal redirects only; user-supplied URLs are never
  passed through `Response::redirect()`.
- **SQL injection** — every query runs through PDO prepared statements (no
  string interpolation into SQL).
- **File-upload abuse** — finfo-based MIME sniff, UUID filename, server-side
  type allow-list.
- **Public-form / poll spam** — honeypot field plus HMAC-signed time-trap.

We do **not** defend against:

- DDoS or volumetric L4/L7 attacks (push this to your CDN / WAF).
- OS-level compromise of the host running PHP.
- Supply-chain compromise of PHP itself or `ext-dom`.
- Physical access to the server filesystem.

## 2. Content-Security-Policy

The CSP is set at the top of [`public/index.php`](../public/index.php) before any
output:

```
default-src 'self';
img-src 'self' data:;
style-src 'self' 'unsafe-inline';
script-src 'self';
font-src 'self';
connect-src 'self';
frame-ancestors 'none'
```

`style-src 'unsafe-inline'` is intentional for now — Quill, drag-and-drop, and a
few inline `<style>` blocks in the public landing/error pages depend on it.
Migrating to a nonce-based policy is tracked as a Tier 2 hardening item and is
not in this release.

`X-Content-Type-Options: nosniff` and `Referrer-Policy: strict-origin-when-cross-origin`
are set alongside the CSP.

## 3. CSRF

[`App\Http\Csrf`](../system/Http/Csrf.php) stores a 32-byte hex token in the
session. The global gate in `public/index.php` runs for every POST except:

- `/api/v1/*` — bearer-token auth, JSON only, no CSRF (handled by `ApiKernel`).
- `/f/*` — public form submissions; protected by HMAC time-trap + honeypot.
- `/p/*` — public poll submissions; protected by HMAC time-trap + honeypot.
- Short-link redirects under `/s/*` are GET-only and not state-changing.

Forms read the token via `App::make('csrf')->token()`; XHR helpers send
`X-CSRF-Token`. The token is rotated by `regenerate()` on every privilege change
(register approval, role change, password update).

## 4. Sessions

[`SessionManager`](../system/Auth/SessionManager.php) sets these cookie flags:

- `name`: `OTACK_TASKS`
- `httponly`: true
- `samesite`: `Lax`
- `secure`: auto (set when `$_SERVER['HTTPS']` is non-empty — terminate TLS on
  the proxy so this flips on in production).
- `lifetime`: 24h default (`SESSION_LIFETIME=43200` in the bundled `.env.example`
  is 12h; bump as needed). Remember-me re-issues with a 30-day cookie via
  `extendCookie()`.

Sessions are stored as files in `data/sessions/` (created mode 0700 on first
boot). `session.gc_maxlifetime` is forced to at least 30 days so that
remember-me files survive GC between visits.

`session_regenerate_id(true)` is called on login and on the register-auto-login
path. There is no separate "log out everywhere" yet — destroying the cookie
plus the session file is the only logout.

## 5. Login throttle

DB-backed via [`LoginThrottle`](../system/Auth/LoginThrottle.php) and the
`login_attempts` table. Key is `sha256(lower(trim(email)))` — the email is never
stored as plaintext for the purposes of the throttle. Defaults:

- `max = 5`
- `windowSeconds = 900`

UPSERT is dialect-aware: SQLite uses `ON CONFLICT`, MySQL uses
`ON DUPLICATE KEY UPDATE`. Successful login resets the row. The throttle is
keyed on email, not IP — a wrong `TRUSTED_PROXIES` configuration does not make
it spoofable.

## 6. API tokens

`/api/v1/*` uses Bearer-token auth. See [API.md](API.md) for the public spec.

- Token format: `otk_` + 40 base62 characters = 44 characters total. Generated
  in [`ApiTokenRepository`](../system/Repository/ApiTokenRepository.php).
- Stored as `sha256(plaintext)`; the plaintext is shown to the user exactly once
  at creation time (`/profile/tokens`) and never again.
- Revoke: per-token at `/profile/tokens/{id}/revoke`; admin can revoke any
  user's tokens via `/users/{id}/tokens/{tid}/revoke` (one) or
  `/users/{id}/tokens/revoke-all` (all).
- Rate-limited: 60 requests per 60-second window per token via
  [`RateLimiter`](../system/Api/V1/RateLimiter.php) and the `api_rate_limits`
  table. UPSERT is atomic — no read-then-write race window.

## 7. Trusted proxies

[`Request::clientIp()`](../system/Http/Request.php) returns the best-effort
client IP. `X-Forwarded-For`'s first hop is honoured **only** when `REMOTE_ADDR`
matches an entry in `TRUSTED_PROXIES`. The env accepts:

- single IPs: `10.0.0.5`
- CIDR ranges: `10.0.0.0/8, 172.16.0.0/12`

When `TRUSTED_PROXIES` is empty (the default), XFF is ignored entirely.
`REMOTE_ADDR` is then used unchanged.

This IP is used by:

- poll rate-limit (one vote per IP per poll)
- short-link unique-visitor counter
- public form submission audit log

A misconfigured `TRUSTED_PROXIES` (too permissive) lets a client spoof its IP
and skew those three surfaces. Set it to the proxy's actual CIDR — nothing
wider.

## 8. File uploads

[`FileUploader`](../system/Service/FileUploader.php):

- finfo MIME sniff on the temp file — declared `$_FILES[…]['type']` is not
  trusted.
- Allow-list of MIME types: `image/jpeg|png|gif|webp` plus PDF, Office formats,
  archives, plain text, CSV, JSON, XML, MP4, MP3. `image/svg+xml` is explicitly
  rejected.
- Filename: `bin2hex(random_bytes(16))` (UUID-ish), preserving extension
  inferred from the real MIME.
- Stored under `public/uploads/YYYY/MM/`.
- Size caps: `UPLOAD_MAX_IMAGE` (default 5 MB) and `UPLOAD_MAX_FILE`
  (default 50 MB).

The upload directory lives under `public/` so a misconfigured server could
serve a `.php` file dropped into it. The bundled
[`public/.htaccess`](../public/.htaccess) routes everything that is not a real
file through `index.php`, so a `.html` or `.svg` would still be returned as-is.
Operators on nginx must add `location ~* /uploads/.*\.(php|phtml|phar)$ { deny all; }`
or move uploads outside the document root.

## 9. Anti-bot on public forms and polls

`PublicFormController`, `PublicPollController`, and `PublicLinkController` all
implement two checks on every public POST:

- **Honeypot**: a hidden text field, normally `website` — if filled, the
  submission is silently accepted but never persisted.
- **HMAC time-trap**: every public form is rendered with an `_h` field carrying
  `hash_hmac('sha256', $renderedAt, $secret)`. The handler rejects submissions
  whose timestamp is in the future, is older than ~24h, or whose HMAC does not
  verify.

The HMAC secret today is `LOGIN_HASH`. This is documented but flagged as a
Tier 2 hardening item — `LOGIN_HASH` doubles as the `/login` URL gate and the
`?hash=` parameter for the bundled migrator, and it does not belong rotating
the public-form anti-spam key. The planned change is to split this into an
`APP_SECRET` env (rotated independently). Not in this release.

## 10. HtmlSanitizer

[`HtmlSanitizer`](../system/Service/HtmlSanitizer.php) is used for all
Quill-derived persisted HTML.

Allow-list (any other tag is unwrapped):

```
p, strong, em, u, a, code, pre, ul, ol, li, br, blockquote
```

- Only `<a>` may carry attributes — `href`, `title`, `target`.
- `href` accepts only `http://`, `https://`, `mailto:` — anything else is
  stripped.
- Any attribute whose name starts with `on` (event handler) is stripped.
- `<a target="_blank">` automatically gets `rel="noopener noreferrer"`.

The implementation requires `ext-dom`. When `ext-dom` is missing the sanitizer
falls back to `strip_tags`, which is **markedly weaker** — it removes
unallowed tags but does not enforce the attribute allow-list. `ext-dom` is
therefore listed as a hard requirement in [DEPLOYMENT.md](DEPLOYMENT.md).

## 11. Reporting vulnerabilities

Please open a private GitHub Security Advisory on
[slisenkobk/otack-manager](https://github.com/slisenkobk/otack-manager) — we do
not maintain a separate `security@` mailbox. Public issues for unpatched
vulnerabilities will be removed and re-filed privately.
