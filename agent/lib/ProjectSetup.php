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
        if (preg_match('#^[\w.-]+@([^:/]+):(?!\d+/)(.+)$#', $u, $m)) {
            $u = $m[1] . '/' . $m[2];                                   // git@host:org/repo
        } else {
            $u = (string)preg_replace('#^[a-z][a-z0-9+.-]*://([^@/]+@)?#i', '', $u); // scheme + userinfo
        }
        $u = (string)preg_replace('#(\.git)?/*$#i', '', $u);
        [$host, $rest] = array_pad(explode('/', $u, 2), 2, '');
        $host = (string)preg_replace('/:\d+$/', '', strtolower($host));
        return $host . '/' . $rest;
    }

    public static function sameRepo(string $a, string $b): bool
    {
        return self::normalizeRepo($a) === self::normalizeRepo($b);
    }

    public static function originOf(string $dir): ?string
    {
        [$code, $out] = self::git(['git', '-C', $dir, 'remote', 'get-url', 'origin']);
        return $code === 0 && trim($out) !== '' ? trim($out) : null;
    }

    public static function cloneInto(string $repoUrl, string $dir): void
    {
        [$code, , $err] = self::git(['git', 'clone', '--quiet', '--', $repoUrl, $dir]);
        if ($code !== 0) {
            throw new \RuntimeException('git clone failed: ' . trim($err));
        }
    }

    /** @return array{0:int,1:string,2:string} exit code, stdout, stderr — no shell involved. */
    private static function git(array $argv): array
    {
        $p = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) { return [127, '', 'cannot start git']; }
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]); fclose($pipes[2]);
        return [proc_close($p), $out, $err];
    }
}
