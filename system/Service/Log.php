<?php
declare(strict_types=1);
namespace App\Service;

/**
 * Tiny logging facade over PHP's error_log(). Centralises format so every
 * line looks the same — ISO-8601 UTC timestamp, uppercase level, colon-tagged
 * source, free-form message, optional JSON context — instead of letting each
 * caller invent its own bracket-style prefix.
 *
 * Example:
 *   Log::error('updater', 'rollback failed', ['backup_id' => $id]);
 *   // [2026-06-03T21:31:00Z] ERROR:updater rollback failed {"backup_id":42}
 */
final class Log
{
    /** @param array<string,mixed> $ctx */
    public static function error(string $tag, string $msg, array $ctx = []): void
    {
        self::write('error', $tag, $msg, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    public static function warn(string $tag, string $msg, array $ctx = []): void
    {
        self::write('warn', $tag, $msg, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    public static function info(string $tag, string $msg, array $ctx = []): void
    {
        self::write('info', $tag, $msg, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private static function write(string $level, string $tag, string $msg, array $ctx): void
    {
        $line = sprintf(
            '[%s] %s:%s %s%s',
            gmdate('Y-m-d\TH:i:s\Z'),
            strtoupper($level),
            $tag,
            $msg,
            $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        error_log($line);
    }
}
