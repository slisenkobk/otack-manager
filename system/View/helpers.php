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
    return date('d.m.Y H:i', strtotime($iso));
}

function fmt_size(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 1) . ' GB';
}
