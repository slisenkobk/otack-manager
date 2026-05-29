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

function icon(string $name, string $extraClass = ''): string
{
    return '<i class="fa-solid fa-' . e($name) . ($extraClass ? ' ' . e($extraClass) : '') . '"></i>';
}

// Append a `?v=…` cache-buster to a static asset URL. Reads `asset_version`
// from settings (bumped via /admin/compass cache tab). Returns the path
// unchanged when no version is set yet.
function asset_url(string $path): string
{
    static $ver = null;
    if ($ver === null) {
        try { $ver = \App\App::make('settings')->get('asset_version', ''); }
        catch (\Throwable $_) { $ver = ''; }
    }
    return $ver === '' ? $path : $path . (str_contains($path, '?') ? '&' : '?') . 'v=' . rawurlencode($ver);
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
function project_statuses(): array
{
    return [
        'active'       => 'Active',
        'planning'     => 'Planning',
        'under_review' => 'Under review',
        'archived'     => 'Archived',
    ];
}

function project_status_label(?string $status): string
{
    $map = project_statuses();
    return $map[(string)$status] ?? ucfirst((string)$status);
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
 * Inline <style> that re-bases the brand palette (and its dark-theme pair)
 * onto the user-chosen color from settings. Empty when no override is set
 * — letting the design-system defaults from app.css apply unchanged.
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
