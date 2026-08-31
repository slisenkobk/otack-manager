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

        if (!is_file($envPath)) {
            throw new \RuntimeException("Agent config missing: $envPath. See agent/projects.example.json.");
        }

        // The token is a bearer credential with the full power of its user.
        // Refuse to read it out of a file anyone else on the box can open.
        $mode = fileperms($envPath) & 0077;
        if ($mode !== 0) {
            throw new \RuntimeException("$envPath must be mode 0600 (group/other bits are set)");
        }

        $env = [];
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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
        if (is_file($projPath)) {
            $raw = json_decode((string)file_get_contents($projPath), true);
            if (!is_array($raw)) {
                throw new \RuntimeException("$projPath is not valid JSON");
            }
            foreach ((array)($raw['projects'] ?? []) as $id => $p) {
                if (!is_array($p) || ($p['path'] ?? '') === '') continue;
                $projects[(string)$id] = [
                    'path'     => (string)$p['path'],
                    'write'    => (bool)($p['write'] ?? false),
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
}
