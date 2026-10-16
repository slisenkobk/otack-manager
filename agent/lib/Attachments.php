<?php
declare(strict_types=1);

namespace App\Agent;

/**
 * Local side of `otack download`: where a task's attachments land, what
 * they are called on disk, and the optional plain-text copy of a Word-ish
 * document.
 *
 * Everything an attachment carries (its original name, its bytes) was
 * written by whoever uploaded it, so none of it is trusted: the name never
 * becomes a path on its own, the directory is private to this user, and the
 * text conversion runs without a shell.
 */
final class Attachments
{
    /** Per-file cap. OTACK_ATTACHMENT_MAX_BYTES may lower it (tests do), never raise it. */
    public const MAX_BYTES = 25 * 1024 * 1024;
    /** Whole-download budget per file, on top of Client's per-read timeout. */
    public const DEADLINE_SECONDS = 120;
    /** Bytes of the sanitised original name kept after the "<id>-" prefix. */
    private const NAME_MAX_BYTES = 100;
    private const TEXTUTIL = '/usr/bin/textutil';
    private const TEXTUTIL_TIMEOUT = 30;
    private const TEXT_EXTENSIONS = ['docx', 'doc', 'rtf', 'odt'];

    public static function maxBytes(): int
    {
        $env = getenv('OTACK_ATTACHMENT_MAX_BYTES');
        if (is_string($env) && ctype_digit($env) && (int)$env > 0) {
            return min(self::MAX_BYTES, (int)$env);
        }
        return self::MAX_BYTES;
    }

    /**
     * Download directory for one task.
     *
     * Manager scope: ~/.cache/otack-manager/attachments/task-<id>/.
     * Bot scope: under the tick session's cwd (~/.cache/otack-agent/
     * session-cwd, see otack-tick F5) — the child session already reads and
     * writes there, so this grants it nothing new; it is not inside any
     * repository and not next to .env / projects.json.
     */
    public static function taskDir(string $home, string $scope, int $taskId): string
    {
        $home = rtrim($home, '/');
        $root = $scope === 'agent'
            ? $home . '/.cache/otack-agent/session-cwd/attachments'
            : $home . '/.cache/otack-manager/attachments';
        return $root . '/task-' . $taskId;
    }

    /** Create $dir (0700 for every level this makes), refuse a symlink anywhere from the cache root down. */
    public static function ensureDir(string $home, string $dir): void
    {
        $cache = rtrim($home, '/') . '/.cache';
        if (!str_starts_with($dir, $cache . '/')) {
            throw new \RuntimeException("$dir is not under $cache");
        }
        $path = $cache;
        foreach (explode('/', substr($dir, strlen($cache) + 1)) as $seg) {
            $path .= '/' . $seg;
            if (is_link($path)) {
                throw new \RuntimeException("$path is a symlink — refusing to download into it");
            }
            if (!is_dir($path)) {
                if (file_exists($path)) { throw new \RuntimeException("$path exists and is not a directory"); }
                if (!@mkdir($path, 0700, true) && !is_dir($path)) {
                    throw new \RuntimeException("cannot create $path");
                }
                @chmod($path, 0700);
            }
        }
        if (!@chmod($dir, 0700)) {
            throw new \RuntimeException("cannot chmod 0700 $dir");
        }
    }

    /**
     * "<id>-<name>": the last path component of $original only, with
     * anything but letters, digits, ".", "_", "-", "(", ")" turned into "_",
     * no leading dots, no "..", capped in bytes with the extension kept.
     */
    public static function safeName(int $id, string $original): string
    {
        $name = mb_scrub($original, 'UTF-8');
        $name = (string)preg_replace('#^.*[/\\\\]#su', '', $name);
        $name = (string)preg_replace('/[^\p{L}\p{N}._()-]+/u', '_', $name);
        $name = (string)preg_replace('/\.{2,}/', '.', $name);
        $name = ltrim($name, '.');
        $name = rtrim($name, '.');
        if ($name === '') { $name = 'file'; }
        if (strlen($name) > self::NAME_MAX_BYTES) {
            $ext = '';
            $dot = strrpos($name, '.');
            if ($dot !== false && strlen($name) - $dot <= 10) { $ext = substr($name, $dot); }
            $stem = mb_strcut(substr($name, 0, strlen($name) - strlen($ext)), 0, self::NAME_MAX_BYTES - strlen($ext), 'UTF-8');
            $name = $stem . $ext;
        }
        return $id . '-' . $name;
    }

    /** Lower-case extension of a safeName(), or ''. */
    public static function extension(string $name): string
    {
        $dot = strrpos($name, '.');
        return $dot === false ? '' : strtolower(substr($name, $dot + 1));
    }

    public static function wantsText(string $name): bool
    {
        return in_array(self::extension($name), self::TEXT_EXTENSIONS, true);
    }

    /**
     * `textutil -convert txt` (macOS only) into "$file.txt", atomically.
     * Returns the text path, or null when textutil is unavailable or fails.
     */
    public static function toText(string $file): ?string
    {
        if (PHP_OS_FAMILY !== 'Darwin' || !is_executable(self::TEXTUTIL)) { return null; }
        $out = $file . '.txt';
        $tmp = dirname($file) . '/.tmp-' . bin2hex(random_bytes(6)) . '.txt';
        $p = @proc_open(
            [self::TEXTUTIL, '-convert', 'txt', '-output', $tmp, $file],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if (!is_resource($p)) { return null; }
        // exitcode is reported only by the first proc_get_status() that sees
        // the child gone, so keep that one.
        $deadline = microtime(true) + self::TEXTUTIL_TIMEOUT;
        $st = proc_get_status($p);
        while (($st['running'] ?? false) && microtime(true) < $deadline) {
            usleep(50_000);
            $st = proc_get_status($p);
        }
        if ($st['running'] ?? false) {
            proc_terminate($p, 9);
            proc_close($p);
            @unlink($tmp);
            return null;
        }
        $code = $st['exitcode'] ?? -1;
        proc_close($p);
        if ($code !== 0 || !is_file($tmp) || is_link($tmp)) { @unlink($tmp); return null; }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $out)) { @unlink($tmp); return null; }
        return $out;
    }
}
