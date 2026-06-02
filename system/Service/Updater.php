<?php
declare(strict_types=1);
namespace App\Service;

use App\App;
use App\Repository\AppBackupRepository;
use App\Repository\AppVersionRepository;
use App\Repository\SettingsRepository;

/**
 * In-app updater service (docs/UPDATES.md).
 *
 * Discovery (check/cachedPayload) is hit from the dashboard every
 * UPDATE_CHECK_INTERVAL seconds and from the "Check now" button.
 *
 * update() is the heavy synchronous pipeline run from
 * POST /admin/updates/run. It snapshots code+DB, downloads the target
 * tag tarball, swaps files, runs migrations, records the result, and
 * rolls back on failure.
 */
final class Updater
{
    private const DEFAULT_REPO_URL       = 'https://github.com/slisenkobk/otack-manager';
    private const DEFAULT_CHECK_INTERVAL = 3600;
    private const HTTP_TIMEOUT_SECONDS   = 5;
    private const DOWNLOAD_TIMEOUT_SECONDS = 120;
    private const TAG_RE                 = '/^v(\d+\.\d+\.\d+)$/';

    /**
     * Paths the updater MUST NOT touch when swapping files. Paths are
     * relative to APP_ROOT; matching is "this exact path OR anything
     * beneath it". This list is authoritative — docs/UPDATES.md §4
     * mirrors it for readability.
     */
    private const IGNORE_PATHS = [
        'data',
        'public/uploads',
        'public/uploads-test',
        '.env',
        'node_modules',
        'test-results',
        '.git',
        '.playwright',
    ];

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
        if ($intervalS === 0) return $cached;
        $age = $cached['checked_at'] === null ? PHP_INT_MAX : time() - $cached['checked_at'];
        if ($age < $intervalS) return $cached;
        try {
            return $this->check();
        } catch (\Throwable $_) {
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
        $lastDur   = (int)$this->settings->get('last_update_duration_seconds', '0');
        return [
            'current'    => self::currentVersion(),
            'available'  => $available !== '' ? $available : null,
            'has_update' => $available !== '' && version_compare($available, self::currentVersion(), '>'),
            'notes'      => (string)$this->settings->get('available_notes', ''),
            'checked_at' => $checkedAt > 0 ? $checkedAt : null,
            'last_duration_seconds' => $lastDur > 0 ? $lastDur : null,
        ];
    }

    public static function currentVersion(): string
    {
        return defined('APP_VERSION') ? APP_VERSION : '0.0.0';
    }

    /**
     * Execute the full update pipeline (docs/UPDATES.md §10).
     *
     * Throws \RuntimeException on any failure after rolling back the
     * code + DB to their pre-update state. The caller (UpdatesController
     * or bin/self-update.php) surfaces the message to the admin.
     *
     * @return array{from:string,to:string,duration_seconds:int,backup_id:int}
     */
    public function update(string $targetVersion, ?int $actorUserId): array
    {
        $this->preloadClasses();

        // 1. validate
        $current = self::currentVersion();
        if (!preg_match('/^\d+\.\d+\.\d+$/', $targetVersion)) {
            throw new \RuntimeException("Invalid target version: $targetVersion (expected semver MAJOR.MINOR.PATCH)");
        }
        if (!version_compare($targetVersion, $current, '>')) {
            throw new \RuntimeException("Already at or beyond v$targetVersion (current: v$current)");
        }

        [$owner, $repo] = $this->resolveRepo();

        // 2. allocate workdir
        $workdir = $this->allocateWorkdir($current, $targetVersion);
        $startedAt = microtime(true);

        try {
            // 3. snapshot code
            $codeSnapshotDir = $workdir . '/code';
            $this->snapshotCode($codeSnapshotDir);

            // 4. snapshot DB
            $dbSnapshotPath = $this->snapshotDb($workdir);

            // 5. download tarball
            $tarPath = $workdir . '/incoming.tar.gz';
            $this->downloadTarball($owner, $repo, $targetVersion, $tarPath);

            // 6. extract
            $stagingDir = $workdir . '/staging';
            mkdir($stagingDir, 0755, true);
            $extractRoot = $this->extractTarball($tarPath, $stagingDir);

            // 7. apply swap
            $removedDir = $workdir . '/removed';
            $this->applySwap($extractRoot, $removedDir);

            // 8. persist version (verify only — file came from the tarball already)
            $this->verifyDeployedVersion($targetVersion);

            // 9. migrate
            $this->runMigrations();

            // 10. record
            /** @var AppVersionRepository $versions */
            $versions = App::make('app_versions');
            /** @var AppBackupRepository $backups */
            $backups = App::make('app_backups');

            $versions->log($targetVersion, AppVersionRepository::SOURCE_UPDATE, $actorUserId, null);

            $size = $this->dirSizeBytes($workdir);
            $relCode = $this->toRelative($codeSnapshotDir);
            $relDb   = $dbSnapshotPath !== null ? $this->toRelative($dbSnapshotPath) : null;
            $backupId = $backups->create($current, $targetVersion, $relCode, $relDb, $size, AppBackupRepository::KIND_AUTO);

            $duration = (int)round(microtime(true) - $startedAt);
            $this->settings->set('last_update_duration_seconds', (string)$duration);

            // 11. prune — handled by step 6 retention sweep (not in scope here)

            return [
                'from' => $current,
                'to' => $targetVersion,
                'duration_seconds' => $duration,
                'backup_id' => $backupId,
            ];
        } catch (\Throwable $e) {
            $this->rollback($workdir);
            throw new \RuntimeException(
                'Update failed and was rolled back: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    // ─── internals: pipeline ───────────────────────────────────────────

    /**
     * Touch every class we'll need after the file swap. Without this an
     * autoload that lands on a renamed file mid-swap could miss. After
     * preload these are in the opcache / Composer-style cached class map.
     */
    private function preloadClasses(): void
    {
        // Already in scope: App, Updater, SettingsRepository.
        // Make sure the rest of the pipeline's PHP dependencies are loaded
        // BEFORE any file moves.
        class_exists(AppVersionRepository::class);
        class_exists(AppBackupRepository::class);
        class_exists(\App\Database\Connection::class);
        class_exists(\App\Database\Migrations::class);
        class_exists(\App\Database\SchemaBootstrap::class);
    }

    private function allocateWorkdir(string $fromVersion, string $toVersion): string
    {
        $stamp = (new \DateTimeImmutable())->format('Ymd_His');
        $dir = APP_ROOT . "/data/backups/{$stamp}_v{$fromVersion}_to_v{$toVersion}";
        if (is_dir($dir)) {
            // Collision in the same second — extremely unlikely; suffix with a counter.
            $dir .= '_' . uniqid('', false);
        }
        if (!mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Cannot create workdir: $dir");
        }
        return $dir;
    }

    private function snapshotCode(string $dest): void
    {
        if (!mkdir($dest, 0755, true)) {
            throw new \RuntimeException("Cannot create snapshot dir: $dest");
        }
        $this->copyTreeFiltered(APP_ROOT, $dest);
    }

    /** Recursively copy $src → $dest, skipping anything in IGNORE_PATHS. */
    private function copyTreeFiltered(string $src, string $dest): void
    {
        $src  = rtrim($src, '/');
        $dest = rtrim($dest, '/');

        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
                function (\SplFileInfo $info, $key, $iter) use ($src) {
                    $rel = $this->relativePath($src, $info->getPathname());
                    return !$this->isIgnored($rel);
                }
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $info) {
            $rel = $this->relativePath($src, $info->getPathname());
            $target = $dest . '/' . $rel;
            if ($info->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true)) {
                    throw new \RuntimeException("Cannot create $target");
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0755, true)) {
                    throw new \RuntimeException("Cannot create $parent");
                }
                if (!@copy($info->getPathname(), $target)) {
                    throw new \RuntimeException("Cannot copy $info → $target");
                }
            }
        }
    }

    private function snapshotDb(string $workdir): ?string
    {
        $dbPath = APP_ROOT . '/' . App::env('DB_PATH', 'data/app.sqlite');
        if (!is_file($dbPath)) return null;

        // Flush WAL to the main file so a plain copy is a consistent snapshot.
        try {
            $pdo = App::make('db');
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (\Throwable $_) {
            // Non-fatal — copy() below is still a consistent SQLite snapshot
            // for non-WAL mode; in WAL mode the worst case is the snapshot
            // misses the last few un-checkpointed transactions.
        }

        $dest = $workdir . '/app.sqlite';
        if (!@copy($dbPath, $dest)) {
            throw new \RuntimeException("Cannot snapshot DB: $dbPath → $dest");
        }
        return $dest;
    }

    private function downloadTarball(string $owner, string $repo, string $version, string $dest): void
    {
        $url = "https://github.com/{$owner}/{$repo}/archive/refs/tags/v{$version}.tar.gz";

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => self::DOWNLOAD_TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'follow_location' => 1,
                'header'        => "User-Agent: otack-manager-updater\r\n",
            ],
        ]);

        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || $data === '') {
            throw new \RuntimeException("Cannot download release tarball: $url");
        }
        $status = $this->httpStatusFromResponseHeaders($http_response_header ?? []);
        if ($status >= 400) {
            if ($status === 404) throw new \RuntimeException("Release tarball not found (HTTP 404): $url");
            throw new \RuntimeException("Tarball download failed (HTTP $status): $url");
        }
        if (file_put_contents($dest, $data) === false) {
            throw new \RuntimeException("Cannot write tarball to $dest");
        }
    }

    /**
     * Extract tarball into $stagingDir and return the path of the single
     * top-level directory inside (GitHub archives wrap everything in
     * `{repo}-{tag}/`). Fails loudly if the structure isn't that shape.
     */
    private function extractTarball(string $tarPath, string $stagingDir): string
    {
        $out = [];
        $code = 0;
        exec(
            'tar -xzf ' . escapeshellarg($tarPath) . ' -C ' . escapeshellarg($stagingDir) . ' 2>&1',
            $out,
            $code
        );
        if ($code !== 0) {
            throw new \RuntimeException('tar extraction failed: ' . implode("\n", $out));
        }
        $entries = array_values(array_filter(
            scandir($stagingDir) ?: [],
            fn($n) => $n !== '.' && $n !== '..'
        ));
        if (count($entries) !== 1 || !is_dir($stagingDir . '/' . $entries[0])) {
            throw new \RuntimeException('Unexpected tarball layout: expected exactly one top-level dir');
        }
        return $stagingDir . '/' . $entries[0];
    }

    /**
     * Apply staged release atop APP_ROOT. Files in APP_ROOT that the new
     * release omits are moved to $removedDir (preserving relative paths)
     * so a rollback can put them back. Ignore-list paths in APP_ROOT are
     * never touched.
     *
     * The "manifest" is derived from $stagingRoot itself: a file exists in
     * the new release iff it exists in staging. Future releases may ship
     * an explicit MANIFEST file at the repo root; if present it's read
     * and used as the authoritative set; otherwise we walk staging.
     */
    private function applySwap(string $stagingRoot, string $removedDir): void
    {
        $manifest = $this->buildManifest($stagingRoot);

        // (a) Move APP_ROOT files that aren't in the manifest (and aren't ignored) into removed/.
        if (!mkdir($removedDir, 0755, true)) {
            throw new \RuntimeException("Cannot create removed/ dir: $removedDir");
        }
        $appFiles = $this->walkFilesRelative(APP_ROOT);
        foreach ($appFiles as $rel) {
            if ($this->isIgnored($rel)) continue;
            if (isset($manifest[$rel])) continue;
            $src = APP_ROOT . '/' . $rel;
            $dst = $removedDir . '/' . $rel;
            $parent = dirname($dst);
            if (!is_dir($parent)) mkdir($parent, 0755, true);
            if (!@rename($src, $dst) && !@copy($src, $dst)) {
                throw new \RuntimeException("Cannot stash removed file: $rel");
            }
            @unlink($src);
        }

        // (b) Rename every staged file into APP_ROOT.
        foreach ($manifest as $rel => $_) {
            $src = $stagingRoot . '/' . $rel;
            $dst = APP_ROOT . '/' . $rel;
            $parent = dirname($dst);
            if (!is_dir($parent) && !mkdir($parent, 0755, true)) {
                throw new \RuntimeException("Cannot create $parent");
            }
            // Atomic when src+dst are on the same filesystem; we deliberately
            // place workdir under data/backups/ to guarantee that.
            if (!@rename($src, $dst)) {
                // Cross-device fallback (unusual layout). Copy then unlink.
                if (!@copy($src, $dst)) {
                    throw new \RuntimeException("Cannot swap file into place: $rel");
                }
                @unlink($src);
            }
        }
    }

    /** @return array<string,true> set of relative paths the new release ships */
    private function buildManifest(string $stagingRoot): array
    {
        $explicit = $stagingRoot . '/MANIFEST';
        if (is_file($explicit)) {
            $lines = file($explicit, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $set = [];
            foreach ($lines as $line) {
                $rel = ltrim(trim($line), '/');
                if ($rel !== '' && $rel !== 'MANIFEST') $set[$rel] = true;
            }
            return $set;
        }
        $set = [];
        foreach ($this->walkFilesRelative($stagingRoot) as $rel) {
            $set[$rel] = true;
        }
        return $set;
    }

    /** @return string[] relative paths of every file under $root */
    private function walkFilesRelative(string $root): array
    {
        $root = rtrim($root, '/');
        if (!is_dir($root)) return [];
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $info) {
            if (!$info->isFile()) continue;
            $out[] = $this->relativePath($root, $info->getPathname());
        }
        return $out;
    }

    private function isIgnored(string $rel): bool
    {
        foreach (self::IGNORE_PATHS as $p) {
            if ($rel === $p) return true;
            if (str_starts_with($rel, $p . '/')) return true;
        }
        // Workdirs the updater itself creates also live under data/backups,
        // which is already covered by the 'data' prefix above.
        return false;
    }

    private function verifyDeployedVersion(string $expected): void
    {
        $path = APP_ROOT . '/system/version.php';
        if (!is_file($path)) {
            throw new \RuntimeException("system/version.php missing after swap");
        }
        $src = (string)file_get_contents($path);
        if (!preg_match("/APP_VERSION\\s*=\\s*'([^']+)'/", $src, $m)) {
            throw new \RuntimeException("Cannot read APP_VERSION from system/version.php");
        }
        if ($m[1] !== $expected) {
            throw new \RuntimeException(
                "Deployed APP_VERSION ({$m[1]}) does not match requested ($expected)"
            );
        }
    }

    private function runMigrations(): void
    {
        $pdo = App::make('db');
        $boot = new \App\Database\SchemaBootstrap($pdo);
        \App\Database\Migrations::run($boot);
    }

    /**
     * Roll back code and DB to their pre-swap state, using the snapshot
     * directory inside the workdir. Logs but does not throw — rollback
     * must always run to completion.
     */
    private function rollback(string $workdir): void
    {
        $codeSnapshot = $workdir . '/code';
        $dbSnapshot   = $workdir . '/app.sqlite';

        if (is_dir($codeSnapshot)) {
            try {
                $this->copyTreeFiltered($codeSnapshot, APP_ROOT);
            } catch (\Throwable $_) {
                // Best-effort — partial restore is better than none.
            }
            // Files moved to removed/ should come back too.
            $removedDir = $workdir . '/removed';
            if (is_dir($removedDir)) {
                foreach ($this->walkFilesRelative($removedDir) as $rel) {
                    if ($this->isIgnored($rel)) continue;
                    $src = $removedDir . '/' . $rel;
                    $dst = APP_ROOT . '/' . $rel;
                    $parent = dirname($dst);
                    if (!is_dir($parent)) @mkdir($parent, 0755, true);
                    @rename($src, $dst);
                }
            }
        }

        if (is_file($dbSnapshot)) {
            $live = APP_ROOT . '/' . App::env('DB_PATH', 'data/app.sqlite');
            @copy($dbSnapshot, $live);
        }
    }

    private function dirSizeBytes(string $dir): int
    {
        if (!is_dir($dir)) return 0;
        $total = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $info) {
            if ($info->isFile()) $total += (int)$info->getSize();
        }
        return $total;
    }

    private function toRelative(string $absPath): string
    {
        $root = rtrim(APP_ROOT, '/') . '/';
        if (str_starts_with($absPath, $root)) {
            return substr($absPath, strlen($root));
        }
        return $absPath;
    }

    private function relativePath(string $root, string $abs): string
    {
        $root = rtrim($root, '/');
        if (str_starts_with($abs, $root . '/')) {
            return substr($abs, strlen($root) + 1);
        }
        return $abs;
    }

    // ─── internals: discovery (existing) ───────────────────────────────

    /** @return array{0:string,1:string} [owner, repo] */
    private function resolveRepo(): array
    {
        $url = trim((string)App::env('UPDATE_REPO_URL', self::DEFAULT_REPO_URL));
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
                $valid[] = $m[1];
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
