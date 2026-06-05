<?php
declare(strict_types=1);
namespace App\Repository;

final class AppBackupRepository
{
    public const KIND_AUTO   = 'auto';
    public const KIND_MANUAL = 'manual';

    public function __construct(private \PDO $pdo) {}

    /**
     * Record a freshly-taken backup. `$codePath` and `$dbSnapshot` are
     * filesystem paths relative to APP_ROOT — the caller is expected to
     * have written the actual files before calling this.
     *
     * `$dbSnapshot` may be null when only code was backed up (e.g. a
     * future "create backup now" button might not snapshot the DB to
     * save disk).
     */
    public function create(
        string $versionFrom,
        string $versionTo,
        string $codePath,
        ?string $dbSnapshot,
        int $sizeBytes,
        string $kind = self::KIND_AUTO
    ): int {
        if (!in_array($kind, [self::KIND_AUTO, self::KIND_MANUAL], true)) {
            throw new \InvalidArgumentException("Unknown backup kind: $kind");
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_backups (version_from, version_to, created_at, code_path, db_snapshot, size_bytes, kind)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$versionFrom, $versionTo, $now, $codePath, $dbSnapshot, $sizeBytes, $kind]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Re-insert an audit row with its original `created_at` and `pruned_at`
     * preserved. Used by Updater::restore() to re-attach the v1.5.0→v1.5.1
     * backup row (and any other newer rows) after the DB snapshot has
     * rolled the table back to its pre-update state.
     */
    public function reattach(
        string $versionFrom,
        string $versionTo,
        string $createdAt,
        string $codePath,
        ?string $dbSnapshot,
        int $sizeBytes,
        string $kind,
        ?string $prunedAt
    ): void {
        if (!in_array($kind, [self::KIND_AUTO, self::KIND_MANUAL], true)) {
            throw new \InvalidArgumentException("Unknown backup kind: $kind");
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_backups (version_from, version_to, created_at, code_path, db_snapshot, size_bytes, kind, pruned_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$versionFrom, $versionTo, $createdAt, $codePath, $dbSnapshot, $sizeBytes, $kind, $prunedAt]);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM app_backups WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Newest first. Pruned backups are still surfaced so the admin sees the historical record.
     *
     * @return list<array<string,mixed>>
     */
    public function listAll(): array
    {
        return $this->pdo->query(
            'SELECT * FROM app_backups ORDER BY created_at DESC, id DESC'
        )->fetchAll();
    }

    /**
     * IDs of non-pruned backups beyond the keep-N retention threshold.
     * Returned newest-first by created_at — callers iterate and prune.
     * Empty array when the threshold isn't exceeded.
     *
     * @return list<int>
     */
    public function idsBeyondRetention(int $keep): array
    {
        $keep = max(0, $keep);
        // Driver decides how to phrase the "skip first N, take all" clause.
        $allOffset = \App\Database\Connection::driverFor($this->pdo)
            ?->paginationAllOffsetSql()
            ?? throw new \RuntimeException('AppBackupRepository: no driver bound to PDO');
        $stmt = $this->pdo->prepare(
            'SELECT id FROM app_backups
             WHERE pruned_at IS NULL
             ORDER BY created_at DESC, id DESC
             ' . $allOffset
        );
        $stmt->bindValue(1, $keep, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Mark a backup as pruned (on-disk artefacts are gone). Row stays. */
    public function markPruned(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $this->pdo->prepare('UPDATE app_backups SET pruned_at = ? WHERE id = ?')
            ->execute([$now, $id]);
    }
}
