<?php
declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path): string
{
    return rtrim(\App\App::env('APP_URL', ''), '/') . '/' . ltrim($path, '/');
}

/**
 * Build an absolute URL safe to send outside the app (Telegram, emails, etc.).
 * Prefers APP_URL; falls back to deriving from the current request so callers
 * never accidentally emit relative links that downstream parsers drop.
 */
function abs_url(string $path): string
{
    $base = rtrim((string)\App\App::env('APP_URL', ''), '/');
    if ($base === '' || !preg_match('#^https?://#i', $base)) {
        $scheme = ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $base = $scheme . '://' . $host;
    }
    return $base . '/' . ltrim($path, '/');
}

function csrf_field(string $token): string
{
    return '<input type="hidden" name="_csrf" value="' . e($token) . '">';
}

/**
 * HMAC secret for anti-bot time-traps, short-link IP hashing, and similar
 * server-only signing material. Reads `APP_SECRET`; falls back to
 * `LOGIN_HASH` for backward compatibility (which historically doubled as
 * both the /login URL gate AND the HMAC secret). New installs should set
 * `APP_SECRET` explicitly so the login-URL gate and the HMAC material can
 * rotate independently.
 */
function app_secret(): string
{
    $appSecret = (string)\App\App::env('APP_SECRET', '');
    if ($appSecret !== '') return $appSecret;
    return (string)\App\App::env('LOGIN_HASH', '');
}

function icon(string $name, string $extraClass = ''): string
{
    return '<i class="fa-solid fa-' . e($name) . ($extraClass ? ' ' . e($extraClass) : '') . '"></i>';
}

// Append a `?v=…` cache-buster to a static asset URL. Reads `asset_version`
// from settings (bumped via /admin/compass cache tab). Returns the path
// unchanged when no version is set yet.
//
// Memoised per request via the `asset_version` DI singleton — App::reset()
// at the top of every request refreshes the value, so admins still see the
// bump take effect on the next page-load while a single render does one
// SELECT instead of one per <script>/<link> tag emitted.
function asset_url(string $path): string
{
    try { $ver = \App\App::make('asset_version')->value; }
    catch (\Throwable $_) { $ver = ''; }
    if ($ver === '') return $path;
    return $path . (str_contains($path, '?') ? '&' : '?') . 'v=' . rawurlencode($ver);
}

// Format a byte count as human-readable. Used in admin diagnostic views.
function human_bytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB', 'MB', 'GB', 'TB'];
    $v = $bytes / 1024;
    $i = 0;
    while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
    return number_format($v, $v >= 100 ? 0 : 1) . ' ' . $units[$i];
}

// ─── i18n ────────────────────────────────────────────────────────────────────
//
// Plain-PHP catalogs under system/i18n/<locale>.php — each returns an array
// of `'dotted.key' => 'String'` mappings. Plural-aware entries are arrays
// keyed by the language's plural form names (en: one/other, pl: one/few/many).
//
// Locale resolution per request, cached by i18n_locale_cache():
//   1. ?locale= query param (only when APP_DEBUG=true)
//   2. session _locale_override (set when an admin temporarily previews PL)
//   3. authenticated user's users.locale
//   4. Accept-Language header (`en` / `pl` prefix match)
//   5. fallback 'en'

/** @return list<string> */
function available_locales(): array { return ['en', 'pl', 'uk']; }

/** @return array<string, string> code => native display name */
function locale_display_names(): array
{
    return ['en' => 'English', 'pl' => 'Polski', 'uk' => 'Українська'];
}

function user_locale(): string
{
    if (isset($GLOBALS['__i18n_locale_resolved__'])) return $GLOBALS['__i18n_locale_resolved__'];
    $available = available_locales();
    $debug = false;
    try { $debug = \App\App::env('APP_DEBUG') === 'true'; } catch (\Throwable $_) {}

    if ($debug) {
        $q = (string)($_GET['locale'] ?? '');
        if ($q !== '' && in_array($q, $available, true)) {
            return $GLOBALS['__i18n_locale_resolved__'] = $q;
        }
    }

    $override = $_SESSION['_locale_override'] ?? null;
    if (is_string($override) && in_array($override, $available, true)) {
        return $GLOBALS['__i18n_locale_resolved__'] = $override;
    }

    try {
        $store = \App\App::make('session')->store ?? [];
        $userId = $store['user_id'] ?? null;
        if ($userId) {
            $u = \App\App::make('users')->findById((int)$userId);
            if ($u && !empty($u['locale']) && in_array($u['locale'], $available, true)) {
                return $GLOBALS['__i18n_locale_resolved__'] = (string)$u['locale'];
            }
        }
    } catch (\Throwable $_) {}

    $accept = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    foreach (explode(',', $accept) as $tag) {
        $code = strtolower(substr(trim(explode(';', $tag)[0]), 0, 2));
        if (in_array($code, $available, true)) {
            return $GLOBALS['__i18n_locale_resolved__'] = $code;
        }
    }

    return $GLOBALS['__i18n_locale_resolved__'] = 'en';
}

/**
 * Resets the per-request locale + catalog cache. Tests call this between
 * cases; production code never needs to.
 */
function i18n_reset_cache(): void
{
    unset(
        $GLOBALS['__i18n_catalog__'],
        $GLOBALS['__i18n_locale__'],
        $GLOBALS['__i18n_locale_resolved__']
    );
}

/** @return array<string, mixed> the loaded catalog for the current locale */
function i18n_catalog(): array
{
    $locale = user_locale();
    if (!empty($GLOBALS['__i18n_catalog__']) && ($GLOBALS['__i18n_locale__'] ?? null) === $locale) {
        return $GLOBALS['__i18n_catalog__'];
    }
    $path = APP_ROOT . '/system/i18n/' . $locale . '.php';
    $cat = is_file($path) ? (require $path) : [];
    if (!is_array($cat)) $cat = [];
    $GLOBALS['__i18n_catalog__'] = $cat;
    $GLOBALS['__i18n_locale__']  = $locale;
    return $cat;
}

/**
 * Translate a key. Falls back to the key itself when missing — that surfaces
 * untranslated strings in development without crashing pages.
 *
 * `$args` substitutes `:name` placeholders via strtr. Example:
 *   t('greeting', ['name' => 'Bohdan'])  // catalog: 'Hello, :name!'
 */
function t(string $key, array $args = []): string
{
    $cat = i18n_catalog();
    $val = $cat[$key] ?? null;
    if (!is_string($val) || $val === '') {
        // English fallback when the active locale is missing this key.
        if (user_locale() !== 'en') {
            $en = APP_ROOT . '/system/i18n/en.php';
            if (is_file($en)) {
                $fb = require $en;
                if (is_array($fb) && isset($fb[$key]) && is_string($fb[$key])) {
                    $val = $fb[$key];
                }
            }
        }
    }
    if (!is_string($val) || $val === '') return $key;
    if ($args) {
        $sub = [];
        foreach ($args as $k => $v) $sub[':' . $k] = (string)$v;
        return strtr($val, $sub);
    }
    return $val;
}

/**
 * Plural-aware translate. The catalog stores the key as an array of plural
 * forms; we pick the form matching $count using the current locale's rules,
 * substitute `:n` (and any extra $args) into the chosen form.
 *
 * Catalog shape:
 *   'tasks.count' => ['one' => ':n task', 'other' => ':n tasks'],          // en
 *   'tasks.count' => ['one' => ':n zadanie', 'few' => ':n zadania',         // pl
 *                     'many' => ':n zadań'],
 *
 * Polish forms:
 *   one  → n = 1
 *   few  → n % 10 ∈ {2,3,4} AND n % 100 ∉ {12,13,14}
 *   many → everything else (0, 5–21, 22–24 use few again, …)
 */
function tn(string $key, int $count, array $args = []): string
{
    $cat = i18n_catalog();
    $entry = $cat[$key] ?? null;
    if (!is_array($entry)) {
        // Missing or wrong shape — fall back to English catalog so the page
        // doesn't break on an untranslated key.
        if (user_locale() !== 'en') {
            $en = APP_ROOT . '/system/i18n/en.php';
            if (is_file($en)) {
                $fb = require $en;
                if (is_array($fb) && isset($fb[$key]) && is_array($fb[$key])) {
                    $entry = $fb[$key];
                }
            }
        }
    }
    if (!is_array($entry)) return $key;

    $form = i18n_plural_form(user_locale(), $count);
    $tmpl = $entry[$form] ?? ($entry['other'] ?? ($entry['many'] ?? reset($entry)));
    if (!is_string($tmpl)) return $key;

    $sub = [':n' => (string)$count];
    foreach ($args as $k => $v) $sub[':' . $k] = (string)$v;
    return strtr($tmpl, $sub);
}

/** Pick which plural form name applies to a count under a given locale. */
function i18n_plural_form(string $locale, int $count): string
{
    $n = abs($count);
    // Ukrainian (CLDR): one — n%10=1 and n%100≠11; few — n%10∈{2,3,4} and
    // n%100∉{12,13,14}; many — everything else. Same shape as Russian.
    if ($locale === 'uk') {
        $rem10 = $n % 10;
        $rem100 = $n % 100;
        if ($rem10 === 1 && $rem100 !== 11) return 'one';
        if ($rem10 >= 2 && $rem10 <= 4 && !($rem100 >= 12 && $rem100 <= 14)) return 'few';
        return 'many';
    }
    if ($locale === 'pl') {
        if ($n === 1) return 'one';
        $rem10 = $n % 10;
        $rem100 = $n % 100;
        if ($rem10 >= 2 && $rem10 <= 4 && !($rem100 >= 12 && $rem100 <= 14)) {
            return 'few';
        }
        return 'many';
    }
    // English (and default): one / other.
    return $n === 1 ? 'one' : 'other';
}

/**
 * Resolve the configured timezone. No cross-request static cache — long-lived
 * workers (PHP-FPM, the dev server) would otherwise serve stale values after an
 * admin changes the setting until the worker recycles. One settings lookup per
 * call is fine; the values are read from a small key-value table.
 *
 * Falls back to UTC if the settings table hasn't been initialised yet
 * (early bootstrap, before migrations).
 */
function app_timezone(): \DateTimeZone
{
    try {
        $name = \App\App::make('settings')->get('timezone', 'Europe/Kyiv');
        if ($name === '') $name = 'Europe/Kyiv';
        return new \DateTimeZone($name);
    } catch (\Throwable $e) {
        return new \DateTimeZone('UTC');
    }
}

function fmt_date(?string $iso): string
{
    if (!$iso) return '';
    try {
        $d = new \DateTimeImmutable($iso);
        return $d->setTimezone(app_timezone())->format('d.m.Y');
    } catch (\Throwable $e) {
        return '';
    }
}

function fmt_datetime(?string $iso): string
{
    if (!$iso) return '';
    try {
        $d = (new \DateTimeImmutable($iso))->setTimezone(app_timezone());
    } catch (\Throwable $e) {
        return '';
    }
    $today = (new \DateTimeImmutable('now', app_timezone()))->format('Y-m-d');
    return $d->format('Y-m-d') === $today
        ? $d->format('H:i')
        : $d->format('d.m.Y H:i');
}

/** Deterministic OKLCH-ish hue per user id → stable colored avatar background. */
function user_color(int $id): string
{
    if ($id <= 0) return '#9a9a9a';
    $hue = ($id * 47) % 360;
    return 'hsl(' . $hue . ', 55%, 48%)';
}

/**
 * Render a user avatar — image if uploaded, else letter on coloured background.
 * $size: 'xs' | 'sm' | 'md' | 'lg'.
 */
function user_avatar_html(int $userId, string $name, ?string $avatar = null, string $size = 'md', array $opts = []): string
{
    $cls = 'user-avatar user-avatar--' . $size;
    if (!empty($opts['extra_class'])) $cls .= ' ' . $opts['extra_class'];
    $title = $opts['title'] ?? $name;
    $titleAttr = $title !== '' ? ' title="' . e($title) . '"' : '';
    $styleAttr = '';
    $inner = e(mb_substr($name !== '' ? $name : '?', 0, 1));

    if ($avatar !== null && $avatar !== '') {
        $cls .= ' user-avatar--img';
        $inner = '<img src="/' . e(ltrim($avatar, '/')) . '" alt="' . e($name) . '" loading="lazy">';
    } else {
        $styleAttr = ' style="background:' . user_color($userId) . '"';
    }
    return '<span class="' . $cls . '"' . $styleAttr . $titleAttr . '>' . $inner . '</span>';
}

function attach_kind(array $a): string
{
    if ((int)($a['is_image'] ?? 0) === 1) return 'image';
    $mime = (string)($a['mime'] ?? '');
    $name = strtolower((string)($a['original_name'] ?? ''));
    if (preg_match('/(zip|rar|7z|tar|gz|bz2)$/', $name)) return 'archive';
    if (in_array($mime, ['application/zip', 'application/x-7z-compressed', 'application/x-rar-compressed', 'application/x-tar', 'application/gzip'], true)) return 'archive';
    if ($mime === 'application/pdf' || str_starts_with($mime, 'text/')) return 'viewable';
    if ($mime === 'application/json' || $mime === 'application/xml') return 'viewable';
    return 'download';
}

function attach_icon(string $kind, string $mime): string
{
    if ($kind === 'archive') return 'fa-solid fa-file-zipper';
    if ($mime === 'application/pdf') return 'fa-solid fa-file-pdf';
    if (str_starts_with($mime, 'text/')) return 'fa-solid fa-file-lines';
    return 'fa-solid fa-file';
}

/**
 * Allowed project statuses with display labels.
 * Centralized so controllers/views/repos agree.
 */
/**
 * Returns the list of project statuses as `code => translated label`.
 * The keys are the underlying values stored in the DB; the labels are
 * resolved via the i18n catalog so they follow the active locale.
 */
function project_statuses(): array
{
    $codes = ['active', 'planning', 'under_review', 'archived'];
    $out = [];
    foreach ($codes as $code) $out[$code] = t('status.project.' . $code);
    return $out;
}

function project_status_label(?string $status): string
{
    $code = (string)$status;
    if ($code === '') return '';
    $label = t('status.project.' . $code);
    return $label !== 'status.project.' . $code ? $label : ucfirst($code);
}

/**
 * White-label app name from settings ('app_name'); falls back to "Otack Manager"
 * if unset. Cached per-request — the settings read is cheap (single K/V lookup)
 * but we still avoid hitting it once per partial render.
 */
function app_name(): string
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $val = trim((string)\App\App::make('settings')->get('app_name', ''));
    } catch (\Throwable $e) {
        $val = '';
    }
    return $cached = ($val !== '' ? $val : 'Otack Manager');
}

/**
 * Split the app name into [first_word, rest] so the sidebar brand can color
 * the first word in --accent and the rest in --text. Single-word names get
 * an empty second element — caller should hide it.
 *
 * @return array{0:string, 1:string}
 */
function app_name_parts(): array
{
    $name = app_name();
    $sp = mb_strpos($name, ' ');
    if ($sp === false) return [$name, ''];
    return [mb_substr($name, 0, $sp), trim(mb_substr($name, $sp + 1))];
}

/**
 * Brand color override from settings ('app_color'). Empty → use default
 * --brand from the design system (the warm Otack orange). Returned with
 * the leading '#'; never falls through to malformed values because the
 * controller validates input as #RRGGBB before persisting.
 */
function app_color(): ?string
{
    static $cached = false;
    if ($cached !== false) return $cached;
    try {
        $val = trim((string)\App\App::make('settings')->get('app_color', ''));
    } catch (\Throwable $e) {
        $val = '';
    }
    return $cached = ($val !== '' ? $val : null);
}

/**
 * Build the brand-aware favicon as a base64 data URI so a custom app_color
 * shows in the browser tab without an extra HTTP roundtrip. Uses the SVG
 * logo as a template; the path/rects carry `currentColor`, which we swap
 * to the chosen brand. Falls back to the static /favicon.svg when no
 * override is set.
 */
/**
 * Build the brand-aware favicon as a base64 data URI. Always returns a data
 * URI so the browser treats each color choice as a distinct resource and
 * actually refreshes the tab icon — falling back to the static `/favicon.svg`
 * would just serve `currentColor`, which renders as black in standalone SVG
 * and ignores brand changes. When no override is set we still bake in the
 * default brand orange so the static SVG's `currentColor` stops mattering.
 */
function app_favicon_href(): string
{
    $color = app_color() ?? '#c2410c';
    $svgPath = APP_ROOT . '/public/favicon.svg';
    if (!is_file($svgPath)) return '/favicon.svg';
    $svg = (string)file_get_contents($svgPath);
    $svg = str_replace('currentColor', $color, $svg);
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/**
 * Per-request CSP nonce. Lazy-generated on first call; cached for the rest
 * of the request. Used by inline `<style>` tags that survive the
 * Wave-C inline-style sweep so we can eventually drop `'unsafe-inline'`
 * from the `style-src` directive without breaking the brand palette.
 *
 * Until the sweep is complete the nonce is additive: the CSP keeps
 * `'unsafe-inline'` AND lists the nonce, so already-nonced tags are
 * forwards-compatible.
 */
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) $nonce = base64_encode(random_bytes(16));
    return $nonce;
}

/**
 * Emit a `style="…"` attribute WITH the per-request CSP nonce so it
 * survives the post-S-6 `style-src 'self' 'nonce-X'` directive.
 * Use sparingly — most cases should be a class. Reserved for genuinely
 * dynamic styles (user-picked colors, progress widths, etc.).
 *
 * The CSS body is HTML-attribute-escaped so the attribute stays
 * well-formed, but no CSS-level validation is performed — callers must
 * validate user-controlled fragments themselves (e.g. hex-color regex)
 * to prevent CSS injection.
 */
function inline_style(string $css): string
{
    $css = str_replace(["\n", "\r"], ' ', $css);
    return 'style="' . e($css) . '" nonce="' . e(csp_nonce()) . '"';
}

/**
 * Inline <style> that re-bases the brand palette (and its dark-theme pair)
 * onto the user-chosen color from settings. Empty when no override is set
 * — letting the design-system defaults from tokens.css apply unchanged.
 * Renders nothing-safe HTML so it can sit directly in <head>.
 */
function app_brand_style_tag(): string
{
    $c = app_color();
    if ($c === null) return '';
    return '<style>' .
        ':root{' .
            '--brand:' . $c . ';' .
            '--brand-2:color-mix(in srgb,' . $c . ' 78%,#000 22%);' .
            '--brand-3:color-mix(in srgb,' . $c . ' 18%,#ffffff 82%);' .
            '--brand-4:color-mix(in srgb,' . $c . ' 30%,#ffffff 70%);' .
            '--brand-pop:color-mix(in srgb,' . $c . ' 88%,#ffffff 12%);' .
            '--focus-ring-alpha:color-mix(in srgb,' . $c . ' 25%,transparent);' .
        '}' .
        ':root[data-theme="dark"]{' .
            '--brand:' . $c . ';' .
            '--brand-2:color-mix(in srgb,' . $c . ' 70%,#ffffff 30%);' .
            '--brand-3:color-mix(in srgb,' . $c . ' 22%,#1A1612 78%);' .
            '--brand-4:color-mix(in srgb,' . $c . ' 32%,#1A1612 68%);' .
            '--brand-pop:color-mix(in srgb,' . $c . ' 85%,#ffffff 15%);' .
        '}' .
        '</style>';
}

/**
 * Render a flash message as <meta> tags. A boot script reads these on page
 * load and pipes them through UI.toast, so we get the same animated toast
 * everywhere (matches the JS-fired toasts on tag rename, file upload, etc.)
 * without each view re-implementing the inline-banner pattern.
 *
 * Pass empty $message to render nothing — safe to call unconditionally.
 */
function flash_meta(string $message, string $type = 'success'): string
{
    if ($message === '') return '';
    $type = in_array($type, ['success', 'error', 'info'], true) ? $type : 'info';
    return '<meta name="flash-message" content="' . e($message) . '">'
         . '<meta name="flash-type" content="' . $type . '">';
}

function fmt_size(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 1) . ' GB';
}
