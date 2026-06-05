<?php
declare(strict_types=1);
namespace App\Repository;

final class AppVersionRepository
{
    public const SOURCE_INSTALL = 'install';
    public const SOURCE_UPDATE  = 'update';
    public const SOURCE_RESTORE = 'restore';

    public function __construct(private \PDO $pdo) {}

    /**
     * Record that the app has just landed on `$version` via `$source`.
     * Append-only — callers never update rows, only insert new ones.
     */
    public function log(string $version, string $source, ?int $appliedBy = null, ?string $notes = null): int
    {
        if (!in_array($source, [self::SOURCE_INSTALL, self::SOURCE_UPDATE, self::SOURCE_RESTORE], true)) {
            throw new \InvalidArgumentException("Unknown source: $source");
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_versions (version, installed_at, source, applied_by, notes)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$version, $now, $source, $appliedBy, $notes]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Re-insert an audit row with its original `installed_at` preserved.
     * Used by Updater::restore() to re-attach history rows that lived in
     * the pre-restore DB but vanished when the DB snapshot was rolled
     * back — without this, restoring to v1.5.0 would also wipe the v1.5.1
     * "update" row that was recorded between snapshot and restore.
     */
    public function reattach(string $version, string $installedAt, string $source, ?int $appliedBy, ?string $notes): void
    {
        if (!in_array($source, [self::SOURCE_INSTALL, self::SOURCE_UPDATE, self::SOURCE_RESTORE], true)) {
            throw new \InvalidArgumentException("Unknown source: $source");
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_versions (version, installed_at, source, applied_by, notes)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$version, $installedAt, $source, $appliedBy, $notes]);
    }

    /**
     * Newest first. limit caps at 200 just to bound the History table render.
     *
     * @return list<array<string,mixed>>
     */
    public function listRecent(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT v.*, u.name AS applied_by_name
             FROM app_versions v
             LEFT JOIN users u ON u.id = v.applied_by
             ORDER BY v.installed_at DESC, v.id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * The "current" version is the most recent row in app_versions. It
     * matches APP_VERSION on a clean install, may briefly diverge during
     * an in-flight update (between code swap and the post-swap insert)
     * — callers that need authoritative "what's running right now"
     * should read APP_VERSION instead. This method is for the audit UI.
     *
     * @return array<string,mixed>|null
     */
    public function current(): ?array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM app_versions ORDER BY installed_at DESC, id DESC LIMIT 1'
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
