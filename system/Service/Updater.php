<?php
declare(strict_types=1);
namespace App\Service;

use App\App;
use App\Repository\SettingsRepository;

/**
 * In-app updater service (docs/UPDATES.md).
 *
 * Step 2 surface — only the "is there a newer version?" discovery and
 * its cached settings storage. The actual download / swap / migrate
 * pipeline arrives in step 4; restore in step 5.
 *
 * GitHub tags endpoint is unauthenticated: 60 requests/hour shared per
 * outbound IP. Per spec we cache the answer in the `settings` table
 * for UPDATE_CHECK_INTERVAL seconds (default 3600), so a typical
 * single-tenant install hits the API at most once per hour.
 */
final class Updater
{
    private const DEFAULT_REPO_URL       = 'https://github.com/slisenkobk/otack-manager';
    private const DEFAULT_CHECK_INTERVAL = 3600;
    private const HTTP_TIMEOUT_SECONDS   = 5;
    private const TAG_RE                 = '/^v(\d+\.\d+\.\d+)$/';

    public function __construct(private SettingsRepository $settings) {}

    public static function isEnabled(): bool
    {
        $val = strtolower((string)App::env('UPDATE_ENABLED', 'true'));
        return $val !== 'false' && $val !== '0' && $val !== 'no';
    }

    /**
     * Run a discovery cycle if the cache has expired. Returns either the
     * existing cached payload or a fresh one. Failure to reach GitHub is
     * swallowed — we don't want a flaky outbound connection to slow down
     * the dashboard. Callers must treat the absence of `available` as
     * "no info yet".
     */
    public function checkIfStale(): array
    {
        $cached    = $this->cachedPayload();
        $intervalS = max(0, (int)App::env('UPDATE_CHECK_INTERVAL', (string)self::DEFAULT_CHECK_INTERVAL));
        // Interval of 0 means auto-check is disabled; only manual check() runs.
        if ($intervalS === 0) return $cached;
        $age = $cached['checked_at'] === null ? PHP_INT_MAX : time() - $cached['checked_at'];
        if ($age < $intervalS) return $cached;
        try {
            return $this->check();
        } catch (\Throwable $_) {
            // Swallow: stale cache is better than a broken dashboard.
            return $cached;
        }
    }

    /**
     * Force a fresh GitHub lookup, refresh the cache, and return the new
     * payload. Throws on network or repo-config errors so callers (e.g.
     * the "Check now" button) can surface them to the admin.
     */
    public function check(): array
    {
        [$owner, $repo] = $this->resolveRepo();
        $tags = $this->fetchTags($owner, $repo);
        $latest = $this->latestSemverTag($tags);
        $now = time();

        $this->settings->setMany([
            'available_version'  => $latest ?? '',
            'available_check_at' => (string)$now,
            // Release notes belong to /releases/latest, not /tags. Step 2 ships
            // without notes; step 3 wires the release endpoint and fills this.
            'available_notes'    => $this->settings->get('available_notes', ''),
        ]);

        return [
            'current'    => self::currentVersion(),
            'available'  => $latest,
            'has_update' => $latest !== null && version_compare($latest, self::currentVersion(), '>'),
            'notes'      => (string)$this->settings->get('available_notes', ''),
            'checked_at' => $now,
        ];
    }

    /** Cached payload assembled from settings without hitting the network. */
    public function cachedPayload(): array
    {
        $available = trim((string)$this->settings->get('available_version', ''));
        $checkedAt = (int)$this->settings->get('available_check_at', '0');
        return [
            'current'    => self::currentVersion(),
            'available'  => $available !== '' ? $available : null,
            'has_update' => $available !== '' && version_compare($available, self::currentVersion(), '>'),
            'notes'      => (string)$this->settings->get('available_notes', ''),
            'checked_at' => $checkedAt > 0 ? $checkedAt : null,
        ];
    }

    public static function currentVersion(): string
    {
        return defined('APP_VERSION') ? APP_VERSION : '0.0.0';
    }

    // ─── internals ──────────────────────────────────────────────────────

    /** @return array{0:string,1:string} [owner, repo] */
    private function resolveRepo(): array
    {
        $url = trim((string)App::env('UPDATE_REPO_URL', self::DEFAULT_REPO_URL));
        // Accept either https://github.com/{owner}/{repo} or with a trailing slash / .git
        $clean = preg_replace('#\.git$#', '', rtrim($url, '/'));
        if (!preg_match('#^https?://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#', $clean ?? '', $m)) {
            throw new \RuntimeException("UPDATE_REPO_URL must look like https://github.com/owner/repo; got: $url");
        }
        return [$m[1], $m[2]];
    }

    /** @return string[] raw tag names from the GitHub API */
    private function fetchTags(string $owner, string $repo): array
    {
        $api  = "https://api.github.com/repos/$owner/$repo/tags?per_page=100";
        $ctx  = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => self::HTTP_TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'header'        => "User-Agent: otack-manager-updater\r\nAccept: application/vnd.github+json\r\n",
            ],
        ]);
        $body = @file_get_contents($api, false, $ctx);
        if ($body === false) {
            throw new \RuntimeException("GitHub API unreachable: $api");
        }
        $status = $this->httpStatusFromResponseHeaders($http_response_header ?? []);
        if ($status >= 400) {
            // Helpful messages for the two common 4xx cases the admin
            // might actually hit (rate-limit + wrong repo URL).
            if ($status === 403) throw new \RuntimeException('GitHub API rate-limited (try again later)');
            if ($status === 404) throw new \RuntimeException("Repository not found at $api");
            throw new \RuntimeException("GitHub API returned $status");
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) throw new \RuntimeException('GitHub API returned non-JSON body');
        return array_values(array_filter(array_map(fn($t) => (string)($t['name'] ?? ''), $decoded), fn($n) => $n !== ''));
    }

    /** Highest semver-shaped tag, or null if none match the strict pattern. */
    private function latestSemverTag(array $tags): ?string
    {
        $valid = [];
        foreach ($tags as $tag) {
            if (preg_match(self::TAG_RE, $tag, $m)) {
                $valid[] = $m[1]; // strip the leading 'v'
            }
        }
        if (!$valid) return null;
        usort($valid, fn($a, $b) => version_compare($b, $a));
        return $valid[0];
    }

    private function httpStatusFromResponseHeaders(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) return (int)$m[1];
        }
        return 0;
    }
}
