<?php
declare(strict_types=1);
namespace App\Repository;

/**
 * Append-only journal of Knowledge-page snapshots. Created by the
 * explicit "Save snapshot" button on the editor — never automatically
 * on save. Surface stays intentionally narrow:
 *
 *   - `append()` to record a snapshot
 *   - `listForPage()` for the journal sidebar / history page
 *   - `findById()` for the version-detail view
 *
 * There is no `restore()` — snapshots are read-only history per the
 * 2026-06-17 design discussion. If the operator wants to revert a page
 * they can copy the snapshot body manually into a new edit.
 */
final class KnowledgePageVersionRepository
{
    public function __construct(private \PDO $pdo) {}

    public function append(
        int $pageId,
        string $title,
        string $bodyMd,
        ?string $note,
        int $snapshotBy
    ): int {
        $now = iso_now_utc();
        $stmt = $this->pdo->prepare(
            'INSERT INTO knowledge_page_versions (page_id, title, body_md, note, snapshot_by, snapshot_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$pageId, $title, $bodyMd, $note, $snapshotBy, $now]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Newest snapshot first. Joins author so the journal entry shows
     * `<note> · by <name> · <when>` without a second roundtrip.
     *
     * @return list<array<string,mixed>>
     */
    public function listForPage(int $pageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.*, u.name AS snapshot_by_name
               FROM knowledge_page_versions v
               LEFT JOIN users u ON u.id = v.snapshot_by
              WHERE v.page_id = ?
              ORDER BY v.snapshot_at DESC, v.id DESC'
        );
        $stmt->execute([$pageId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.*, u.name AS snapshot_by_name
               FROM knowledge_page_versions v
               LEFT JOIN users u ON u.id = v.snapshot_by
              WHERE v.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function countForPage(int $pageId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM knowledge_page_versions WHERE page_id = ?'
        );
        $stmt->execute([$pageId]);
        return (int)$stmt->fetchColumn();
    }
}
