<?php
declare(strict_types=1);
namespace App\Service;

use App\App;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;

// Encapsulates the filesystem walks, row counters and log-tail logic powering
// the /admin/compass panel. Stateless — every call works against the live
// filesystem + DB, so the UI always reflects current truth.
final class CompassService
{
    public function __construct(
        private \PDO $pdo,
        private SchemaBootstrap $boot,
        private string $sessionsDir,
        private string $uploadsDir,
        private string $errorsLogPath,
        private string $migrationsDir,
    ) {}

    // ─── Migrations ──────────────────────────────────────────────────────────

    /** @return list<array{name:string, applied:bool}> */
    public function migrationsStatus(): array
    {
        return $this->boot->status($this->migrationsDir);
    }

    public function appliedMigrationDetails(): array
    {
        try {
            return $this->pdo
                ->query('SELECT name, applied_at FROM schema_migrations ORDER BY name')
                ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $_) {
            return [];
        }
    }

    /** @return list<string> names of newly-applied migrations */
    public function runPendingMigrations(): array
    {
        return Migrations::run($this->boot, $this->migrationsDir);
    }

    // ─── Cache: sessions ─────────────────────────────────────────────────────

    /**
     * @return array{total:int, stale:int, stale_bytes:int, lifetime_seconds:int}
     * Stale = older than SESSION_LIFETIME (defaults to 12h).
     */
    public function sessionsStats(): array
    {
        $lifetime = (int)App::env('SESSION_LIFETIME', '43200');
        $threshold = time() - $lifetime;
        $total = 0; $stale = 0; $staleBytes = 0;
        foreach ($this->iterSessionFiles() as $path) {
            $total++;
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $threshold) {
                $stale++;
                $size = @filesize($path);
                if ($size !== false) $staleBytes += $size;
            }
        }
        return [
            'total' => $total,
            'stale' => $stale,
            'stale_bytes' => $staleBytes,
            'lifetime_seconds' => $lifetime,
        ];
    }

    /**
     * Delete session files older than SESSION_LIFETIME. Never touches recent
     * sessions — clearing those would log everyone out.
     *
     * @return array{deleted:int, bytes:int}
     */
    public function clearStaleSessions(): array
    {
        $lifetime = (int)App::env('SESSION_LIFETIME', '43200');
        $threshold = time() - $lifetime;
        $deleted = 0; $bytes = 0;
        foreach ($this->iterSessionFiles() as $path) {
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime >= $threshold) continue;
            $size = @filesize($path) ?: 0;
            if (@unlink($path)) {
                $deleted++;
                $bytes += $size;
            }
        }
        return ['deleted' => $deleted, 'bytes' => $bytes];
    }

    // ─── Cache: orphan uploads ───────────────────────────────────────────────

    /**
     * @return array{total:int, orphan:int, orphan_bytes:int}
     * Orphan = file on disk under uploadsDir/YYYY/MM/ but no matching row in
     * the attachments table.
     */
    public function uploadsStats(): array
    {
        $known = $this->knownAttachmentBasenames();
        $total = 0; $orphan = 0; $orphanBytes = 0;
        foreach ($this->iterUploadFiles() as $path) {
            $total++;
            if (!isset($known[basename($path)])) {
                $orphan++;
                $size = @filesize($path);
                if ($size !== false) $orphanBytes += $size;
            }
        }
        return ['total' => $total, 'orphan' => $orphan, 'orphan_bytes' => $orphanBytes];
    }

    /** @return array{deleted:int, bytes:int} */
    public function clearOrphanUploads(): array
    {
        $known = $this->knownAttachmentBasenames();
        $deleted = 0; $bytes = 0;
        foreach ($this->iterUploadFiles() as $path) {
            if (isset($known[basename($path)])) continue;
            $size = @filesize($path) ?: 0;
            if (@unlink($path)) {
                $deleted++;
                $bytes += $size;
            }
        }
        return ['deleted' => $deleted, 'bytes' => $bytes];
    }

    // ─── Cache: asset cache-bust ─────────────────────────────────────────────

    /**
     * Bump `asset_version` setting to the current timestamp so CSS/JS URLs
     * get a new `?v=` and browsers refetch. Returns the new value.
     */
    public function bumpAssetVersion(): string
    {
        $version = (string)time();
        App::make('settings')->set('asset_version', $version);
        return $version;
    }

    public function currentAssetVersion(): string
    {
        return App::make('settings')->get('asset_version', '');
    }

    // ─── DB stats ────────────────────────────────────────────────────────────

    /**
     * @return array{
     *   tables: list<array{name:string, rows:int}>,
     *   db_path: string,
     *   db_size: int,
     *   last_migration: ?array{name:string, applied_at:string}
     * }
     */
    public function dbStats(): array
    {
        $tableNames = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        $tables = [];
        foreach ($tableNames as $name) {
            try {
                $rows = (int)$this->pdo->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $name) . '"')->fetchColumn();
            } catch (\PDOException $_) {
                $rows = -1;
            }
            $tables[] = ['name' => $name, 'rows' => $rows];
        }
        $dbPath = APP_ROOT . '/' . App::env('DB_PATH', 'data/app.sqlite');
        $size = @filesize($dbPath);

        $lastMigration = null;
        try {
            $row = $this->pdo
                ->query('SELECT name, applied_at FROM schema_migrations ORDER BY applied_at DESC, name DESC LIMIT 1')
                ->fetch(\PDO::FETCH_ASSOC);
            if ($row) $lastMigration = $row;
        } catch (\PDOException $_) {}

        return [
            'tables' => $tables,
            'db_path' => $dbPath,
            'db_size' => $size === false ? 0 : (int)$size,
            'last_migration' => $lastMigration,
        ];
    }

    // ─── Logs ────────────────────────────────────────────────────────────────

    /**
     * Tail data/errors.log. Returns last $limit *entries*, where an entry is a
     * `[date] level: message` header line plus any following stack-trace lines
     * that belong to it. Returns newest-first.
     *
     * @return list<array{level:string, time:string, body:string}>
     */
    public function tailErrorsLog(int $limit = 100, ?string $levelFilter = null): array
    {
        if (!is_file($this->errorsLogPath)) return [];
        $size = filesize($this->errorsLogPath) ?: 0;
        // Bound read size: 256KB is plenty for the last ~hundred entries.
        $window = min($size, 256 * 1024);
        $f = @fopen($this->errorsLogPath, 'rb');
        if (!$f) return [];
        fseek($f, max(0, $size - $window));
        $tail = stream_get_contents($f);
        fclose($f);
        if ($tail === false || $tail === '') return [];

        $lines = preg_split("/\r?\n/", $tail) ?: [];
        $entries = [];
        $current = null;
        foreach ($lines as $line) {
            if (preg_match('/^\[(?<time>[^\]]+)\]\s*(?:PHP\s+)?(?<level>[A-Za-z][\w\s]*?):\s*(?<msg>.*)$/', $line, $m)) {
                if ($current) $entries[] = $current;
                $current = [
                    'time'  => trim($m['time']),
                    'level' => trim($m['level']),
                    'body'  => $m['msg'],
                ];
            } elseif ($current) {
                $current['body'] .= "\n" . $line;
            }
        }
        if ($current) $entries[] = $current;

        if ($levelFilter !== null && $levelFilter !== '') {
            $needle = mb_strtolower($levelFilter);
            $entries = array_values(array_filter(
                $entries,
                fn($e) => str_contains(mb_strtolower($e['level']), $needle)
            ));
        }
        $entries = array_reverse($entries);
        return array_slice($entries, 0, max(1, $limit));
    }

    public function errorsLogSize(): int
    {
        return is_file($this->errorsLogPath) ? (int)(@filesize($this->errorsLogPath) ?: 0) : 0;
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    /** @return \Generator<string> */
    private function iterSessionFiles(): \Generator
    {
        if (!is_dir($this->sessionsDir)) return;
        foreach (glob($this->sessionsDir . '/sess_*') ?: [] as $path) {
            if (is_file($path)) yield $path;
        }
    }

    /** @return \Generator<string> */
    private function iterUploadFiles(): \Generator
    {
        if (!is_dir($this->uploadsDir)) return;
        // Year dirs are 4-digit; month dirs are 2-digit; files anywhere
        // beneath are considered candidates.
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->uploadsDir,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME
            )
        );
        foreach ($rii as $path) {
            if (is_file($path)) yield $path;
        }
    }

    /** @return array<string, true> basenames of attachments stored on disk */
    private function knownAttachmentBasenames(): array
    {
        $stmt = $this->pdo->query('SELECT filename FROM attachments');
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $rel) {
            $out[basename((string)$rel)] = true;
        }
        return $out;
    }
}
