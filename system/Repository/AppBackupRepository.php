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

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM app_backups WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Newest first. Pruned backups are still surfaced so the admin sees the historical record. */
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
     */
    public function idsBeyondRetention(int $keep): array
    {
        $keep = max(0, $keep);
        $stmt = $this->pdo->prepare(
            'SELECT id FROM app_backups
             WHERE pruned_at IS NULL
             ORDER BY created_at DESC, id DESC
             LIMIT -1 OFFSET ?'
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
