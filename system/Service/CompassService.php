<?php
declare(strict_types=1);
namespace App\Service;

use App\App;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;
use App\Repository\SettingsRepository;

// Encapsulates the filesystem walks, row counters and log-tail logic powering
// the /admin/compass panel. Stateless — every call works against the live
// filesystem + DB, so the UI always reflects current truth.
final class CompassService
{
    public function __construct(
        private \PDO $pdo,
        private SchemaBootstrap $boot,
        private SettingsRepository $settings,
        private string $sessionsDir,
        private string $uploadsDir,
        private string $errorsLogPath,
        private string $migrationsDir,
        private string $trashDir,
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

    /** Files modified within this window are never treated as orphans. */
    public const ORPHAN_GRACE_SECONDS = 86400;

    /** Quarantine batches older than this are purged on the next cleanup run. */
    public const TRASH_RETENTION_DAYS = 30;

    /** Max file paths recorded in the cleanup result (activity log meta). */
    private const MOVED_FILES_LOG_LIMIT = 500;

    /**
     * Columns holding an upload path as the whole value
     * (e.g. "uploads/2026/05/<uuid>.jpg"). Written by FileUploader::store()
     * callers: AttachmentController / API AttachmentsHandler → attachments,
     * AvatarService → users.avatar.
     */
    private const EXACT_REFERENCE_COLUMNS = [
        ['attachments', 'filename'],
        ['users', 'avatar'],
    ];

    /**
     * Free-text / Markdown / HTML / JSON columns that may embed an upload URL
     * (a pasted /uploads/... link or image). Scanned with a regex.
     */
    private const TEXT_REFERENCE_COLUMNS = [
        ['projects', 'description'],
        ['projects', 'agent_instructions'],
        ['tasks', 'description'],
        ['comments', 'body'],
        ['knowledge_pages', 'body_md'],
        ['knowledge_page_versions', 'body_md'],
        ['forms', 'description'],
        ['forms', 'fields_json'],
        ['forms', 'footer_json'],
        ['forms', 'success_message'],
        ['polls', 'description'],
        ['polls', 'fields_json'],
        ['polls', 'footer_json'],
        ['polls', 'success_message'],
        ['form_submissions', 'data_json'],
        ['form_submissions', 'footer_json'],
        ['settings', 'value'],
        ['short_links', 'target_url'],
    ];

    /**
     * @return array{total:int, orphan:int, orphan_bytes:int, scan_error:?string}
     * Orphan = file on disk under uploadsDir/YYYY/MM/, older than the grace
     * period, and not referenced by any known source (see
     * knownUploadReferences()). Comparison uses the full relative path
     * (uploads/YYYY/MM/<uuid>.<ext>) — basename matching would silently merge
     * files that share a name across YYYY/MM dirs.
     *
     * If the reference scan fails, orphan is reported as 0 and scan_error
     * carries the reason — nothing is offered for cleanup.
     */
    public function uploadsStats(): array
    {
        try {
            $known = $this->knownUploadReferences();
        } catch (\Throwable $e) {
            $total = 0;
            foreach ($this->iterUploadFiles() as $_) $total++;
            return ['total' => $total, 'orphan' => 0, 'orphan_bytes' => 0, 'scan_error' => $e->getMessage()];
        }
        $threshold = time() - self::ORPHAN_GRACE_SECONDS;
        $total = 0; $orphan = 0; $orphanBytes = 0;
        foreach ($this->iterUploadFiles() as $path) {
            $total++;
            if ($this->isOrphan($path, $known, $threshold)) {
                $orphan++;
                $size = @filesize($path);
                if ($size !== false) $orphanBytes += $size;
            }
        }
        return ['total' => $total, 'orphan' => $orphan, 'orphan_bytes' => $orphanBytes, 'scan_error' => null];
    }

    /**
     * Move unreferenced upload files into a quarantine batch
     * (trashDir/<Ymd-His>/uploads/YYYY/MM/...) instead of unlinking them.
     * Batches older than TRASH_RETENTION_DAYS are purged at the start of
     * each run, so a wrong cleanup can be undone by moving files back.
     *
     * Fail-safe: the reference set is built from every known source; if any
     * source query fails the whole run throws before touching a file.
     *
     * Race-safety: we collect candidate orphans against an initial DB snapshot,
     * then re-snapshot the DB right before moving each candidate. A row
     * inserted between the walk and the move (e.g., an attachment uploaded
     * concurrently) will be in the second snapshot and skipped. The grace
     * period additionally protects anything uploaded in the last 24h.
     *
     * @return array{deleted:int, bytes:int, quarantine:?string, files:list<string>, files_truncated:bool, purged_batches:int}
     * @throws \RuntimeException when the reference scan fails (nothing moved).
     */
    public function clearOrphanUploads(): array
    {
        $purged = $this->purgeExpiredTrash();

        $threshold = time() - self::ORPHAN_GRACE_SECONDS;
        $known = $this->knownUploadReferences();
        $candidates = [];
        foreach ($this->iterUploadFiles() as $path) {
            if ($this->isOrphan($path, $known, $threshold)) {
                $candidates[] = $path;
            }
        }

        $empty = ['deleted' => 0, 'bytes' => 0, 'quarantine' => null, 'files' => [],
                  'files_truncated' => false, 'purged_batches' => $purged];
        if (!$candidates) return $empty;

        // Re-check against a fresh DB snapshot so files referenced during the
        // walk aren't moved. Throws (and moves nothing) if a source fails.
        $known = $this->knownUploadReferences();

        $batch = rtrim($this->trashDir, '/') . '/' . date('Ymd-His');
        if (is_dir($batch)) $batch .= '-' . bin2hex(random_bytes(3));
        if (!@mkdir($batch, 0750, true) && !is_dir($batch)) {
            throw new \RuntimeException('Cannot create upload quarantine dir: ' . $batch);
        }

        $moved = 0; $bytes = 0; $files = [];
        foreach ($candidates as $path) {
            if (!$this->isOrphan($path, $known, $threshold)) continue;
            $rel = $this->relativeToParent($path);
            $size = @filesize($path) ?: 0;
            if ($this->moveToQuarantine($path, $batch . '/' . $rel)) {
                $moved++;
                $bytes += $size;
                $files[] = $rel;
            }
        }
        if ($moved === 0) @rmdir($batch);

        return [
            'deleted'         => $moved,
            'bytes'           => $bytes,
            'quarantine'      => $moved > 0 ? $batch : null,
            'files'           => array_slice($files, 0, self::MOVED_FILES_LOG_LIMIT),
            'files_truncated' => count($files) > self::MOVED_FILES_LOG_LIMIT,
            'purged_batches'  => $purged,
        ];
    }

    // ─── Cache: asset cache-bust ─────────────────────────────────────────────

    /**
     * Bump `asset_version` setting to the current timestamp so CSS/JS URLs
     * get a new `?v=` and browsers refetch. Returns the new value.
     */
    public function bumpAssetVersion(): string
    {
        $version = (string)time();
        $this->settings->set('asset_version', $version);
        return $version;
    }

    public function currentAssetVersion(): string
    {
        return $this->settings->get('asset_version', '');
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
        $driver = \App\Database\Connection::driverFor($this->pdo);
        $driverName = $driver?->name() ?? 'sqlite';
        $listSql = $driver?->listTablesSql()
            ?? "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
        $tableNames = $this->pdo->query($listSql)->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        $tables = [];
        foreach ($tableNames as $name) {
            try {
                // MySQL's default sql_mode treats "name" as a string literal,
                // not an identifier — must use backticks. SQLite accepts
                // either. Use backticks unconditionally.
                $rows = (int)$this->pdo->query(
                    'SELECT COUNT(*) FROM `' . str_replace('`', '``', $name) . '`'
                )->fetchColumn();
            } catch (\PDOException $_) {
                $rows = -1;
            }
            $tables[] = ['name' => $name, 'rows' => $rows];
        }
        // On MySQL there's no single "DB file"; surface the connection
        // string instead so the UI shows something meaningful.
        if ($driverName === 'mysql') {
            $dbPath = $driver->dsn();
            $size = 0;
        } else {
            // Pull the active connection's actual file path so we measure
            // whatever PDO is bound to (tests use temp DBs; production uses
            // data/app.sqlite). Reading APP_ROOT/DB_PATH always pointed at
            // the configured default and left tests reporting size 0.
            $row = $this->pdo->query(
                "SELECT file FROM pragma_database_list WHERE name='main'"
            )->fetch(\PDO::FETCH_ASSOC);
            $dbPath = (string)($row['file'] ?? (APP_ROOT . '/' . App::env('DB_PATH', 'data/app.sqlite')));
            $size = $dbPath !== '' ? (int)@filesize($dbPath) : 0;
        }

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

    /**
     * Truncate the errors log. Returns bytes freed (0 if file was empty or missing).
     * We truncate rather than unlink so PHP's error_log handler keeps writing to
     * the same path without needing a re-open.
     *
     * @return array{bytes:int}
     */
    public function clearErrorsLog(): array
    {
        $bytes = $this->errorsLogSize();
        if ($bytes > 0) {
            @file_put_contents($this->errorsLogPath, '');
        }
        return ['bytes' => $bytes];
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

    /**
     * Every upload path referenced anywhere in the DB, in the relative form
     * FileUploader stores ("uploads/2026/05/<uuid>.<ext>").
     *
     * Fail-safe: any failing source query throws — callers must treat that
     * as "reference set unknown" and delete nothing.
     *
     * @return array<string, true>
     * @throws \RuntimeException
     */
    private function knownUploadReferences(): array
    {
        $base = basename(rtrim($this->uploadsDir, '/'));
        $out = [];

        foreach (self::EXACT_REFERENCE_COLUMNS as [$table, $col]) {
            foreach ($this->fetchReferenceColumn($table, $col, null) as $value) {
                $rel = $this->normalizeReference($value);
                if ($rel !== '') $out[$rel] = true;
                // Also run the text extractor — tolerates absolute URLs or
                // stray prefixes in the stored value.
                foreach ($this->extractUploadPaths($value, $base) as $p) $out[$p] = true;
            }
        }

        foreach (self::TEXT_REFERENCE_COLUMNS as [$table, $col]) {
            foreach ($this->fetchReferenceColumn($table, $col, $base) as $value) {
                foreach ($this->extractUploadPaths($value, $base) as $p) $out[$p] = true;
            }
        }
        return $out;
    }

    /**
     * @return list<string> non-empty values of $table.$col (optionally only
     *   rows whose value contains $needle).
     * @throws \RuntimeException on any query failure.
     */
    private function fetchReferenceColumn(string $table, string $col, ?string $needle): array
    {
        $q = fn(string $id) => '`' . str_replace('`', '``', $id) . '`';
        $sql = 'SELECT ' . $q($col) . ' FROM ' . $q($table)
             . ' WHERE ' . $q($col) . ' IS NOT NULL';
        try {
            if ($needle === null) {
                $stmt = $this->pdo->query($sql);
            } else {
                $stmt = $this->pdo->prepare($sql . ' AND ' . $q($col) . ' LIKE ?');
                if ($stmt !== false && !$stmt->execute(['%' . $needle . '%'])) $stmt = false;
            }
            if ($stmt === false) {
                throw new \RuntimeException('query returned false');
            }
            $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Upload reference scan failed on {$table}.{$col}: " . $e->getMessage(), 0, $e
            );
        }
        $out = [];
        foreach ($rows ?: [] as $v) {
            $v = (string)$v;
            if ($v !== '') $out[] = $v;
        }
        return $out;
    }

    /**
     * Normalise a whole-value reference ("uploads/…", "/uploads/…",
     * "public/uploads/…", with optional ?query/#fragment) to "uploads/…".
     */
    private function normalizeReference(string $value): string
    {
        $v = trim($value);
        $v = preg_replace('/[?#].*$/s', '', $v) ?? $v;
        $v = ltrim($v, '/');
        if (str_starts_with($v, 'public/')) $v = substr($v, strlen('public/'));
        return $v;
    }

    /**
     * Pull every "<base>/…" path out of free text (Markdown, HTML, JSON with
     * escaped slashes, absolute URLs). Over-matching is harmless — it only
     * keeps more files — so each hit is added both raw and with trailing
     * punctuation / URL-encoding stripped.
     *
     * @return list<string>
     */
    private function extractUploadPaths(string $text, string $base): array
    {
        if (!str_contains($text, $base)) return [];
        $text = str_replace('\\/', '/', $text);
        $re = '#(?<![A-Za-z0-9_.\-])' . preg_quote($base, '#') . '/[^\s"\'<>()\[\]{}\\\\?\#|`]+#u';
        if (!preg_match_all($re, $text, $m)) return [];
        $out = [];
        foreach ($m[0] as $hit) {
            $out[$hit] = true;
            $trim = rtrim($hit, '.,;:!*_~');
            $out[$trim] = true;
            $out[rawurldecode($trim)] = true;
            $out[html_entity_decode($trim, ENT_QUOTES | ENT_HTML5, 'UTF-8')] = true;
        }
        return array_keys($out);
    }

    /** @param array<string, true> $known */
    private function isOrphan(string $path, array $known, int $graceThreshold): bool
    {
        if (isset($known[$this->relativeToParent($path)])) return false;
        $mtime = @filemtime($path);
        // Unknown or recent mtime → keep (fail-safe).
        if ($mtime === false || $mtime >= $graceThreshold) return false;
        return true;
    }

    /** rename(), falling back to copy+verify+unlink across filesystems. */
    private function moveToQuarantine(string $src, string $dst): bool
    {
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return false;
        if (@rename($src, $dst)) return true;
        if (!@copy($src, $dst)) return false;
        if (@filesize($dst) !== @filesize($src)) { @unlink($dst); return false; }
        @touch($dst, (int)@filemtime($src));
        return @unlink($src);
    }

    /**
     * Remove quarantine batches (trashDir/<Ymd-His>[-suffix]) older than
     * TRASH_RETENTION_DAYS. Only directories matching that name pattern are
     * touched. Returns the number of purged batches.
     */
    private function purgeExpiredTrash(): int
    {
        if (!is_dir($this->trashDir)) return 0;
        $cutoff = time() - self::TRASH_RETENTION_DAYS * 86400;
        $purged = 0;
        foreach (scandir($this->trashDir) ?: [] as $name) {
            if (!preg_match('/^(\d{8}-\d{6})(-[0-9a-f]+)?$/', $name, $m)) continue;
            $ts = \DateTimeImmutable::createFromFormat('Ymd-His', $m[1]);
            if ($ts === false || $ts->getTimestamp() >= $cutoff) continue;
            $dir = rtrim($this->trashDir, '/') . '/' . $name;
            if (!is_dir($dir) || is_link($dir)) continue;
            $rii = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($rii as $f) {
                ($f->isDir() && !$f->isLink()) ? @rmdir((string)$f) : @unlink((string)$f);
            }
            if (@rmdir($dir)) $purged++;
        }
        return $purged;
    }

    /**
     * Convert an absolute upload-file path into the same relative form stored
     * in attachments.filename (e.g. /…/public/uploads/2026/05/abc.jpg becomes
     * "uploads/2026/05/abc.jpg"). Falls back to basename if the path is
     * unexpectedly outside the uploads tree.
     */
    private function relativeToParent(string $absolute): string
    {
        $parent = dirname(rtrim($this->uploadsDir, '/')) . '/';
        if (str_starts_with($absolute, $parent)) {
            return substr($absolute, strlen($parent));
        }
        return basename($absolute);
    }
}
