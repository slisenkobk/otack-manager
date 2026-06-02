<?php
declare(strict_types=1);
namespace App\Database\Snapshot;

/**
 * Per-driver backup/restore adapter. The Updater asks the driver for
 * one of these; the driver decides how to capture and replay the
 * database file/dump. See docs/DATABASE.md §6.
 *
 * Both calls run synchronously and throw on failure. The Updater
 * wraps them in its own rollback / lock logic; this interface stays
 * dumb on purpose.
 */
interface SnapshotInterface
{
    /** File extension for snapshots emitted by this adapter (e.g. 'sqlite', 'sql.gz'). */
    public function fileExtension(): string;

    /**
     * Write the current live DB to $destPath. Caller created the
     * parent directory.
     */
    public function backupTo(string $destPath): void;

    /**
     * Replace the live DB with the contents of $srcPath. The runtime
     * connection may need to be reopened after this returns — adapters
     * are allowed to invalidate any cached PDO.
     */
    public function restoreFrom(string $srcPath): void;
}
