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

function fmt_date(?string $iso): string
{
    if (!$iso) return '';
    return date('d.m.Y', strtotime($iso));
}

function fmt_datetime(?string $iso): string
{
    if (!$iso) return '';
    $t = strtotime($iso);
    if ($t === false) return '';
    return date('Y-m-d') === date('Y-m-d', $t)
        ? date('H:i', $t)
        : date('d.m.Y H:i', $t);
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

function fmt_size(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 1) . ' GB';
}
