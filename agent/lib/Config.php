<?php
declare(strict_types=1);

namespace App\Agent;

/**
 * The machine-local half of the agent's configuration.
 *
 * The server knows *what* a project is — repository URL, branches,
 * instructions. This file knows *where it lives on this machine and whether
 * the agent may write to it*. The split is deliberate (spec §7.3): if the
 * local path came from the server, anyone who can edit a project in the web
 * UI could point an automated worker at an arbitrary directory.
 *
 * A project absent from projects.json is not "read-only" — it is invisible.
 *
 * This class governs an autonomous bot that reads a bearer token from
 * `.env` and a directory allow-list from `projects.json` and then commits
 * and pushes on its own. Every failure here must be loud and fail-closed:
 * silently narrowing to "nothing allowed" when the real cause is a broken
 * config reads to the operator as "no work to do", not as "config is
 * broken" — so anything ambiguous throws instead of degrading quietly.
 *
 * `App\Agent\*` is deliberately outside the repo autoloader's
 * `App\* -> system/*.php` map (`system/bootstrap.php:50-55`): `agent/` is
 * a standalone tool over the API, not part of the deployed application, so
 * every consumer of a class in `agent/lib/` must `require_once` it
 * explicitly (as the tests and `agent/bin/otack` do).
 */
final class Config
{
    public const SCOPES = ['agent', 'manager'];
    /** Same character set as otack-tick's TEST_CMD_SAFE_PATTERN: a `)` or `:` would inject permission rules. */
    public const TEST_CMD_PATTERN = '#^[A-Za-z0-9 _./-]+$#';
    private const DEFAULT_APPROVAL = [
        'mode' => 'none',
        'digest_size' => 10,
        'digest_every_hours' => 24,
        'critical_tags' => ['bug', 'critical', 'security', 'баг', 'критично', 'срочно'],
    ];

    /**
     * Same rule as otack-tick's isSafeBranchName() (pinned by a test): a
     * branch the tick would reject makes it exit for every project, so it must
     * never get into projects.json in the first place.
     */
    public static function isSafeBranchName(string $name): bool
    {
        if ($name === '' || $name[0] === '-' || str_contains($name, '..')) { return false; }
        return preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $name) === 1;
    }


    /**
     * @param array<string, array{
     *     path: string,
     *     write: bool,
     *     test_cmd: ?string,
     *     in_progress_column: int|string|null,
     *     review_column: int|string|null,
     *     default_branch: ?string,
     * }> $projects
     * @param array<string, string> $roots name => realpath
     * @param array{mode: string, digest_size: int, digest_every_hours: int, critical_tags: list<string>} $approval
     * @param array{token: string, chat_id: string}|null $telegram
     */
    private function __construct(
        public readonly string $instance,
        public readonly string $token,
        public readonly array $projects,
        public readonly string $scope,
        public readonly array $roots,
        public readonly array $approval,
        public readonly ?array $telegram,
        public readonly string $projectsFile,
    ) {}

    /**
     * `agent` scope: the bot's token from ~/.claude/otack-agent/.env.
     * `manager` scope: the owner's personal token from
     * ~/.claude/otack-manager/.env. Both read the same
     * ~/.claude/otack-agent/projects.json.
     */
    public static function load(string $home, string $scope = 'agent'): self
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException("unknown scope \"$scope\"");
        }
        $dir = rtrim($home, '/') . '/.claude/otack-agent';

        // M1: the directory that holds `.env` and `projects.json` names the
        // directories an autonomous writer may touch. It need not be
        // secret, but if it is group- or world-writable, the 0600 on `.env`
        // below can simply be replaced by anyone else on the box.
        self::assertNotGroupOrWorldWritable($dir, "$dir directory");

        // The owner's personal token lives apart from the bot's, so the bot
        // session can be denied it by one path rule (otack-tick).
        $envDir = $scope === 'agent' ? $dir : rtrim($home, '/') . '/.claude/otack-manager';
        if ($envDir !== $dir) {
            if (!is_dir($envDir)) {
                throw new \RuntimeException("Manager config missing: $envDir/.env — run make install-agent and put your personal token there");
            }
            self::assertNotGroupOrWorldWritable($envDir, "$envDir directory");
        }
        $envPath = $envDir . '/.env';
        $env = self::readEnv($envPath);

        foreach (['OTACK_API_URL', 'OTACK_API_TOKEN'] as $required) {
            if (($env[$required] ?? '') === '') {
                throw new \RuntimeException("$required is not set in $envPath");
            }
        }

        $projects = [];
        $roots = [];
        $approval = self::DEFAULT_APPROVAL;
        $projPath = $dir . '/projects.json';
        if (file_exists($projPath)) {
            // M2: a directory (or anything else) sitting where projects.json
            // is expected must be an error, not treated as "absent".
            if (!is_file($projPath)) {
                throw new \RuntimeException("$projPath exists but is not a regular file");
            }

            self::assertNotGroupOrWorldWritable($projPath, $projPath);

            $raw = json_decode((string)file_get_contents($projPath), true);
            if (!is_array($raw)) {
                throw new \RuntimeException("$projPath is not valid JSON");
            }
            // A missing/typo'd "projects" key, a top-level JSON array, or a
            // bare project map without the "projects" wrapper must all be
            // loud errors — not an empty allow-list indistinguishable from
            // "no projects.json at all".
            if (!isset($raw['projects']) || !is_array($raw['projects'])) {
                throw new \RuntimeException(
                    "$projPath must have a top-level \"projects\" object. See agent/projects.example.json."
                );
            }

            foreach ($raw['projects'] as $id => $p) {
                $idStr = (string)$id;
                $projects[$idStr] = self::parseProject($idStr, $p, $projPath);
            }
            $roots = self::parseRoots($raw['roots'] ?? null, $projPath);
            $approval = self::parseApproval($raw['approval'] ?? null, $projPath);
        }

        // The Telegram bot token is the bot's credential, read only for the
        // bot's own scope; the manager scope never carries it.
        $telegram = null;
        if ($scope === 'agent' && $approval['mode'] === 'telegram') {
            $tgToken = $env['OTACK_TG_BOT_TOKEN'] ?? '';
            $tgChat  = $env['OTACK_TG_CHAT_ID'] ?? '';
            if ($tgToken === '' || !preg_match('/^-?\d{1,20}$/', $tgChat)) {
                throw new \RuntimeException(
                    "approval.mode is \"telegram\" but OTACK_TG_BOT_TOKEN / OTACK_TG_CHAT_ID (numeric) are not both set in $envPath"
                );
            }
            $telegram = ['token' => $tgToken, 'chat_id' => $tgChat];
        }

        return new self(rtrim($env['OTACK_API_URL'], '/'), $env['OTACK_API_TOKEN'], $projects,
            $scope, $roots, $approval, $telegram, $projPath);
    }

    /** @return array<string, string> */
    private static function readEnv(string $envPath): array
    {
        if (!is_file($envPath)) {
            throw new \RuntimeException("Agent config missing: $envPath. See agent/projects.example.json.");
        }

        // m2: open once, fstat the open handle, and read from that same
        // handle — so the permission bits we approve are provably the ones
        // guarding the bytes we then read, instead of checking the path
        // and reopening it moments later.
        $envHandle = fopen($envPath, 'rb');
        if ($envHandle === false) {
            throw new \RuntimeException("cannot read $envPath (permission denied?)");
        }
        try {
            $stat = fstat($envHandle);
            if ($stat === false) {
                throw new \RuntimeException("Cannot stat $envPath");
            }
            // The token is a bearer credential with the full power of its
            // user. Refuse to read it out of a file anyone else on the box
            // can open.
            if (($stat['mode'] & 0077) !== 0) {
                throw new \RuntimeException("$envPath must be mode 0600 (group/other bits are set)");
            }

            $contents = stream_get_contents($envHandle);
            if ($contents === false) {
                throw new \RuntimeException("cannot read $envPath (permission denied?)");
            }
        } finally {
            fclose($envHandle);
        }

        $env = [];
        foreach (preg_split('/\r\n|\r|\n/', $contents) as $line) {
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim($v, " \t\"'");
        }

        return $env;
    }

    /**
     * Validates one projects.json entry. Shared by load() and
     * writeProjectEntry(), so a written entry passes exactly the rules a
     * later load() applies.
     */
    private static function parseProject(string $idStr, mixed $p, string $projPath): array
    {
        // m3: json_decode leaves non-canonical numeric keys (e.g.
        // "042") as strings while projectPath(42) looks up "42".
        // Reject anything that isn't a canonical integer key so the
        // mismatch can't silently make a project unreachable.
        if ((string)(int)$idStr !== $idStr) {
            throw new \RuntimeException(
                "Project id \"$idStr\" in $projPath is not a canonical integer key"
            );
        }

        if (!is_array($p)) {
            throw new \RuntimeException(
                "Project \"$idStr\" in $projPath must be an object with a \"path\" key"
            );
        }
        if (!isset($p['path']) || $p['path'] === '') {
            throw new \RuntimeException(
                "Project \"$idStr\" in $projPath is missing a non-empty \"path\""
            );
        }

        // C1: `write` must be a real JSON boolean. A quoted string
        // like "false" is the most common hand-editing mistake and
        // must never silently coerce to true.
        $write = false;
        if (array_key_exists('write', $p)) {
            if (!is_bool($p['write'])) {
                $got = var_export($p['write'], true);
                throw new \RuntimeException(
                    "Project \"$idStr\" in $projPath has a non-boolean \"write\" value ($got); use a real JSON true/false, not a quoted string"
                );
            }
            $write = $p['write'] === true;
        }

        // M4: nothing upstream guarantees this path is a string, is
        // absolute, exists, or is a directory — yet a containment
        // check downstream is meaningless against a relative or
        // "~"-prefixed prefix, and spec §9 forbids working outside
        // a project's path.
        $rawPath = $p['path'];
        if (!is_string($rawPath)) {
            throw new \RuntimeException(
                "Project \"$idStr\" in $projPath has a non-string \"path\""
            );
        }
        if ($rawPath === '' || $rawPath[0] !== '/') {
            $hint = str_starts_with($rawPath, '~')
                ? ' (PHP never expands "~" — use an absolute path)'
                : '';
            throw new \RuntimeException(
                "Project \"$idStr\" in $projPath has a non-absolute \"path\": \"$rawPath\"$hint"
            );
        }
        $real = realpath($rawPath);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException(
                "Project \"$idStr\" in $projPath: path \"$rawPath\" does not exist or is not a directory"
            );
        }

        // The board's in-progress/review columns are free-text and
        // vary by team language (Russian/Polish boards won't contain
        // the English words "progress"/"review"), so the phase
        // machine cannot infer them from the server's is_done/
        // is_backlog flags — the operator names them here instead.
        // Each is either an integer column id or an exact column
        // name (string); anything else (float, bool, array, object)
        // is a hand-editing mistake and must throw naming the
        // project and the key, the same fail-closed spirit as the
        // "write" boolean check above (C1) — never silently
        // stringify or truncate it into something that looks valid.
        $inProgressColumn = self::readColumnRef($p, 'in_progress_column', $idStr, $projPath);
        $reviewColumn     = self::readColumnRef($p, 'review_column', $idStr, $projPath);

        $testCmd = null;
        if (isset($p['test_cmd']) && $p['test_cmd'] !== '') {
            if (!is_string($p['test_cmd']) || !preg_match(self::TEST_CMD_PATTERN, $p['test_cmd'])) {
                throw new \RuntimeException(
                    "Project \"$idStr\" in $projPath has a test_cmd outside the safe set [A-Za-z0-9 _./-]"
                );
            }
            $testCmd = $p['test_cmd'];
        }
        $defaultBranch = null;
        if (array_key_exists('default_branch', $p) && $p['default_branch'] !== null) {
            if (!is_string($p['default_branch']) || strlen($p['default_branch']) > 100 || !self::isSafeBranchName($p['default_branch'])) {
                throw new \RuntimeException(
                    "Project \"$idStr\" in $projPath has an invalid \"default_branch\" "
                    . '(letters, digits, . _ / - only; must start with a letter or digit, no "..")'
                );
            }
            $defaultBranch = $p['default_branch'];
        }

        return [
            'path'               => $real,
            'write'              => $write,
            'test_cmd'           => $testCmd,
            'in_progress_column' => $inProgressColumn,
            'review_column'      => $reviewColumn,
            'default_branch'     => $defaultBranch,
        ];
    }

    /** @return array<string, string> name => realpath of an existing directory */
    private static function parseRoots(mixed $raw, string $projPath): array
    {
        if ($raw === null) { return []; }
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new \RuntimeException("\"roots\" in $projPath must be an object of name → absolute directory");
        }
        $out = [];
        foreach ($raw as $key => $path) {
            $key = (string)$key;
            if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/', $key)) {
                throw new \RuntimeException("\"roots\" key \"$key\" in $projPath must match [a-z][a-z0-9-]*");
            }
            if (!is_string($path) || $path === '' || $path[0] !== '/') {
                throw new \RuntimeException("\"roots\".\"$key\" in $projPath must be an absolute path");
            }
            $real = realpath($path);
            if ($real === false || !is_dir($real)) {
                throw new \RuntimeException("\"roots\".\"$key\" in $projPath: \"$path\" does not exist or is not a directory");
            }
            $out[$key] = $real;
        }
        return $out;
    }

    private static function parseApproval(mixed $raw, string $projPath): array
    {
        $a = self::DEFAULT_APPROVAL;
        if ($raw === null) { return $a; }
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new \RuntimeException("\"approval\" in $projPath must be an object");
        }
        if (array_key_exists('mode', $raw)) {
            if (!in_array($raw['mode'], ['none', 'telegram'], true)) {
                throw new \RuntimeException("\"approval\".\"mode\" in $projPath must be \"none\" or \"telegram\"");
            }
            $a['mode'] = $raw['mode'];
        }
        foreach (['digest_size' => [1, 20], 'digest_every_hours' => [1, 168]] as $k => [$min, $max]) {
            if (!array_key_exists($k, $raw)) { continue; }
            if (!is_int($raw[$k]) || $raw[$k] < $min || $raw[$k] > $max) {
                throw new \RuntimeException("\"approval\".\"$k\" in $projPath must be an integer $min..$max");
            }
            $a[$k] = $raw[$k];
        }
        if (array_key_exists('critical_tags', $raw)) {
            $tags = $raw['critical_tags'];
            if (!is_array($tags) || !array_is_list($tags)) {
                throw new \RuntimeException("\"approval\".\"critical_tags\" in $projPath must be a list of tag names");
            }
            foreach ($tags as $t) {
                if (!is_string($t) || trim($t) === '') {
                    throw new \RuntimeException("\"approval\".\"critical_tags\" in $projPath must contain non-empty strings");
                }
            }
            $a['critical_tags'] = array_values(array_map(fn($t) => mb_strtolower(trim($t)), $tags));
        }
        return $a;
    }

    /**
     * Adds or replaces one project entry. Manager scope only: this file bounds
     * what the bot may touch, so only the owner's interactive session writes
     * it. Validated with the exact rules load() applies, written to a temp
     * file and renamed over the original (a tick reading it concurrently sees
     * the old or the new file, never half of one).
     */
    public function writeProjectEntry(int $id, array $entry): array
    {
        if ($this->scope !== 'manager') {
            throw new \RuntimeException('projects.json is written only from the manager scope (otack-manager)');
        }
        if ($id <= 0) {
            throw new \RuntimeException('project id must be positive');
        }
        $path = $this->projectsFile;
        $raw = ['projects' => []];
        if (is_file($path)) {
            $raw = json_decode((string)file_get_contents($path), true);
            if (!is_array($raw) || !isset($raw['projects']) || !is_array($raw['projects'])) {
                throw new \RuntimeException("$path is not a valid projects.json — fix it by hand before writing to it");
            }
        }
        $parsed = self::parseProject((string)$id, $entry, $path);
        $raw['projects'][(string)$id] = $entry;
        $json = json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('cannot encode projects.json: ' . json_last_error_msg());
        }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $oldUmask = umask(077);
        try {
            if (file_put_contents($tmp, $json . "\n") === false) {
                throw new \RuntimeException("cannot write $tmp");
            }
            chmod($tmp, 0600);
            if (!rename($tmp, $path)) {
                throw new \RuntimeException("cannot replace $path");
            }
        } finally {
            umask($oldUmask);
            if (is_file($tmp)) { @unlink($tmp); }
        }
        return $parsed;
    }

    /**
     * @return array{
     *     path: string,
     *     write: bool,
     *     test_cmd: ?string,
     *     in_progress_column: int|string|null,
     *     review_column: int|string|null,
     *     default_branch: ?string,
     * }|null
     */
    public function projectPath(int $id): ?array
    {
        return $this->projects[(string)$id] ?? null;
    }

    // Reads an optional in_progress_column / review_column value out of one
    // project entry. Absent -> null. Present -> must be a JSON integer or a
    // JSON string (a column id or an exact column name); any other type
    // (float, bool, array, object) throws, naming both the project id and
    // the key, so a hand-editing mistake reads to the operator as "this
    // project's config is broken" rather than being coerced into something
    // that silently resolves to the wrong column.
    private static function readColumnRef(array $p, string $key, string $idStr, string $projPath): int|string|null
    {
        if (!array_key_exists($key, $p) || $p[$key] === null) {
            return null;
        }
        $v = $p[$key];
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '') {
            return $v;
        }
        $got = var_export($v, true);
        throw new \RuntimeException(
            "Project \"$idStr\" in $projPath has an invalid \"$key\" value ($got); "
            . 'use either an integer column id or a non-empty string with the exact column name'
        );
    }

    // M1 + m1: reject a path that is group- or world-writable. fileperms()
    // returns false on stat failure; treating that as "no bits set" would
    // be fail-open (false & 0022 === 0), so a stat failure is itself an
    // error.
    private static function assertNotGroupOrWorldWritable(string $path, string $label): void
    {
        $perms = @fileperms($path);
        if ($perms === false) {
            throw new \RuntimeException("Cannot stat $label");
        }
        if (($perms & 0022) !== 0) {
            $mode = decoct($perms & 0777);
            throw new \RuntimeException("$label must not be group- or world-writable (mode $mode)");
        }
    }
}
