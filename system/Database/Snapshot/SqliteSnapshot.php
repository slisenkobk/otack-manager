<?php
declare(strict_types=1);
namespace App\Database\Snapshot;

/**
 * Backup / restore for SQLite: just a file copy of data/app.sqlite,
 * with a WAL checkpoint up-front so the snapshot is internally
 * consistent. Honours `-wal` / `-shm` sidecars in both directions —
 * if the checkpoint flushed them away, we skip; otherwise they ride
 * along so SQLite can replay them.
 */
final class SqliteSnapshot implements SnapshotInterface
{
    public function __construct(private \PDO $pdo, private string $livePath) {}

    public function fileExtension(): string { return 'sqlite'; }

    public function backupTo(string $destPath): void
    {
        $checkpointed = false;
        try {
            $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $checkpointed = true;
        } catch (\Throwable $_) {
            // Non-WAL or busy lock — fall through and ship sidecars.
        }
        if (!@copy($this->livePath, $destPath)) {
            throw new \RuntimeException("SqliteSnapshot: cannot copy $this->livePath → $destPath");
        }
        if (!$checkpointed) {
            foreach (['-wal', '-shm'] as $suf) {
                if (is_file($this->livePath . $suf)) {
                    @copy($this->livePath . $suf, $destPath . $suf);
                }
            }
        }
    }

    public function restoreFrom(string $srcPath): void
    {
        try {
            $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (\Throwable $_) {}

        if (!@copy($srcPath, $this->livePath)) {
            throw new \RuntimeException("SqliteSnapshot: cannot restore $srcPath → $this->livePath");
        }
        // Clear stale sidecars belonging to the pre-restore DB so
        // SQLite doesn't replay them on top of the new file.
        foreach (['-wal', '-shm'] as $suf) {
            if (is_file($this->livePath . $suf)) @unlink($this->livePath . $suf);
        }
        // Reinstate the snapshot's own sidecars if present.
        foreach (['-wal', '-shm'] as $suf) {
            if (is_file($srcPath . $suf)) {
                @copy($srcPath . $suf, $this->livePath . $suf);
            }
        }
    }
}
