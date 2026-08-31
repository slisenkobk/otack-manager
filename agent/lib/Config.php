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
    /** @param array<string, array{path:string, write:bool, test_cmd:?string}> $projects */
    private function __construct(
        public readonly string $instance,
        public readonly string $token,
        public readonly array $projects,
    ) {}

    public static function load(string $home): self
    {
        $dir     = rtrim($home, '/') . '/.claude/otack-agent';
        $envPath = $dir . '/.env';

        // M1: the directory that holds `.env` and `projects.json` names the
        // directories an autonomous writer may touch. It need not be
        // secret, but if it is group- or world-writable, the 0600 on `.env`
        // below can simply be replaced by anyone else on the box.
        self::assertNotGroupOrWorldWritable($dir, "$dir directory");

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

        foreach (['OTACK_API_URL', 'OTACK_API_TOKEN'] as $required) {
            if (($env[$required] ?? '') === '') {
                throw new \RuntimeException("$required is not set in $envPath");
            }
        }

        $projects = [];
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

                $projects[$idStr] = [
                    'path'     => $real,
                    'write'    => $write,
                    'test_cmd' => isset($p['test_cmd']) && $p['test_cmd'] !== '' ? (string)$p['test_cmd'] : null,
                ];
            }
        }

        return new self(rtrim($env['OTACK_API_URL'], '/'), $env['OTACK_API_TOKEN'], $projects);
    }

    /** @return array{path:string, write:bool, test_cmd:?string}|null */
    public function projectPath(int $id): ?array
    {
        return $this->projects[(string)$id] ?? null;
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
