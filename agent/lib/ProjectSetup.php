<?php
declare(strict_types=1);

namespace App\Agent;

/** Local folder naming and repository identity for `otack-manager setup-project`. */
final class ProjectSetup
{
    public const FOLDER_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';
    public const RULE_SAFE_PATH = '#^/[A-Za-z0-9_./-]+$#';

    private const TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ye',
        'ж' => 'zh', 'з' => 'z', 'и' => 'y', 'і' => 'i', 'ї' => 'yi', 'й' => 'y', 'к' => 'k', 'л' => 'l',
        'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ь' => '', 'ю' => 'yu',
        'я' => 'ya', 'ё' => 'yo', 'ъ' => '', 'ы' => 'y', 'э' => 'e', '’' => '', "'" => '', 'ʼ' => '',
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
    ];

    public static function slugify(string $s): string
    {
        $s = mb_strtolower(trim($s));
        // Ukrainian national rule: є ю я ї are ye/yu/ya/yi word-initially, ie/iu/ia/i elsewhere.
        $s = (string)preg_replace_callback('/(^|[^\p{L}])([єюяї])|([єюяї])/u', static function (array $m): string {
            $init = ['є' => 'ye', 'ю' => 'yu', 'я' => 'ya', 'ї' => 'yi'];
            $mid = ['є' => 'ie', 'ю' => 'iu', 'я' => 'ia', 'ї' => 'i'];
            return ($m[2] ?? '') !== '' ? $m[1] . $init[$m[2]] : $mid[$m[3]];
        }, $s);
        $s = strtr($s, self::TRANSLIT);
        $s = (string)preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        return rtrim(substr($s, 0, 48), '-');
    }

    public static function folderName(array $project): string
    {
        $repoUrl = $project['repo_url'] ?? null;
        if (is_string($repoUrl) && trim($repoUrl) !== '') {
            $last = basename(rtrim(str_replace(':', '/', trim($repoUrl)), '/'));
            $fromRepo = self::slugify((string)preg_replace('/\.git$/i', '', $last));
            if ($fromRepo !== '') { return $fromRepo; }
        }
        foreach (['slug', 'name'] as $k) {
            $v = self::slugify((string)($project[$k] ?? ''));
            if ($v !== '') { return $v; }
        }
        return 'project-' . (int)($project['id'] ?? 0);
    }

    public static function normalizeRepo(string $url): string
    {
        $u = trim($url);
        $scheme = str_contains($u, '://');
        if (!str_contains($u, '://') && preg_match('#^[\w.-]+@([^:/]+):(.+)$#', $u, $m)) {
            $u = $m[1] . '/' . $m[2];                                   // git@host:org/repo
        } else {
            $u = (string)preg_replace('#^[a-z][a-z0-9+.-]*://([^@/]+@)?#i', '', $u); // scheme + userinfo
        }
        $u = (string)preg_replace('#(\.git)?/*$#i', '', $u);
        [$host, $rest] = array_pad(explode('/', $u, 2), 2, '');
        $host = strtolower($host);
        if ($scheme) { $host = (string)preg_replace('/:\d+$/', '', $host); } // port only in scheme URLs
        return $host . '/' . $rest;
    }

    public static function sameRepo(string $a, string $b): bool
    {
        $na = self::normalizeRepo($a);
        $nb = self::normalizeRepo($b);
        foreach ([$na, $nb] as $n) {
            if (trim($n, '/') === '') { return false; }
        }
        return $na === $nb;
    }

    public static function originOf(string $dir): ?string
    {
        $real = realpath($dir);
        if ($real === false) { return null; }
        [$tc, $top] = self::git(['git', '-C', $dir, 'rev-parse', '--show-toplevel']);
        if ($tc !== 0 || realpath(trim($top)) !== $real) { return null; } // not the root of its own checkout
        [$code, $out] = self::git(['git', '-C', $dir, 'remote', 'get-url', 'origin']);
        return $code === 0 && trim($out) !== '' ? trim($out) : null;
    }

    /**
     * The branch origin/HEAD points at (`main` for `origin/main`), when $dir
     * is the root of its own checkout; null otherwise. A fresh clone always
     * has it; an older checkout may need `git remote set-head origin -a`.
     */
    public static function originHead(string $dir): ?string
    {
        $real = realpath($dir);
        if ($real === false) { return null; }
        [$tc, $top] = self::git(['git', '-C', $dir, 'rev-parse', '--show-toplevel']);
        if ($tc !== 0 || realpath(trim($top)) !== $real) { return null; }
        [$code, $out] = self::git(['git', '-C', $dir, 'symbolic-ref', '--quiet', '--short', 'refs/remotes/origin/HEAD']);
        $head = trim($out);
        if ($code !== 0 || !str_starts_with($head, 'origin/')) { return null; }
        $branch = substr($head, strlen('origin/'));
        return $branch !== '' ? $branch : null;
    }

    /** Drops "user:pass@" after every "://" — greedy up to the last "@" before the host, so a password containing "@" goes too. */
    public static function redactUrl(string $s): string
    {
        return (string)preg_replace('#(://)[^/\s]*@#', '$1', $s);
    }

    /**
     * Deletes a directory tree this run created (rollback). Never follows
     * symlinks: a link inside is unlinked, its target left alone. With
     * $keepRoot only the contents go and $dir itself stays.
     */
    public static function removeTree(string $dir, bool $keepRoot = false): void
    {
        if (is_link($dir) || !is_dir($dir)) { return; }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $p = $f->getPathname();
            if (is_link($p) || !is_dir($p)) { @unlink($p); } else { @rmdir($p); }
        }
        if (!$keepRoot) { @rmdir($dir); }
    }

    public static function cloneInto(string $repoUrl, string $dir): void
    {
        [$code, , $err] = self::git(['git', 'clone', '--quiet', '--', $repoUrl, $dir]);
        if ($code !== 0) {
            $err = self::redactUrl(trim($err)); // never leak URL credentials
            throw new \RuntimeException('git clone failed: ' . $err);
        }
    }

    /** @return array{0:int,1:string,2:string} exit code, stdout, stderr — no shell involved. */
    private static function git(array $argv): array
    {
        $env = array_merge(getenv(), [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_SSH_COMMAND' => 'ssh -o BatchMode=yes',
            'GIT_ALLOW_PROTOCOL' => 'https:ssh:git:file',
        ]);
        $p = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($p)) { return [127, '', 'cannot start git']; }
        fclose($pipes[0]);
        // Drain both pipes together: reading stdout to EOF first deadlocks
        // once git fills the stderr pipe buffer (a chatty clone) and blocks.
        $buf = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $h) { stream_set_blocking($h, false); }
        while ($open !== []) {
            $read = array_values($open);
            $w = $e = null;
            if (@stream_select($read, $w, $e, 5) === false) { break; }
            foreach ($open as $fd => $h) {
                if (!in_array($h, $read, true)) { continue; }
                $chunk = fread($h, 65536);
                if ($chunk !== false && $chunk !== '') { $buf[$fd] .= $chunk; continue; }
                if (feof($h)) { fclose($h); unset($open[$fd]); }
            }
        }
        foreach ($open as $h) { fclose($h); }
        return [proc_close($p), $buf[1], $buf[2]];
    }
}
