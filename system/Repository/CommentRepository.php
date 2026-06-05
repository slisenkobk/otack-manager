<?php
declare(strict_types=1);
namespace App\Repository;

final class CommentRepository
{
    public function __construct(private \PDO $pdo) {}

    public function create(
        string $entityType,
        int    $entityId,
        int    $userId,
        string $body,
        ?int   $parentId = null
    ): int {
        $now  = iso_now_utc();
        $stmt = $this->pdo->prepare(
            'INSERT INTO comments (entity_type, entity_id, user_id, body, parent_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$entityType, $entityId, $userId, $body, $parentId, $now]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM comments WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function listFor(string $entityType, int $entityId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, u.name AS author_name, u.avatar AS author_avatar
             FROM comments c
             INNER JOIN users u ON u.id = c.user_id
             WHERE c.entity_type = ? AND c.entity_id = ?
             ORDER BY c.created_at ASC'
        );
        $stmt->execute([$entityType, $entityId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function delete(int $id): void
    {
        // Cascade replies so orphans don't linger with a dangling parent_id.
        $this->pdo->prepare('DELETE FROM comments WHERE id = ? OR parent_id = ?')->execute([$id, $id]);
    }

    /** @return list<array<string,mixed>> */
    public function recentForUser(int $userId, bool $isAdmin, int $limit = 10, int $offset = 0): array
    {
        if ($isAdmin) {
            $stmt = $this->pdo->prepare(
                'SELECT c.*, u.name AS author_name, u.avatar AS author_avatar FROM comments c
                 INNER JOIN users u ON u.id = c.user_id
                 ORDER BY c.created_at DESC LIMIT ? OFFSET ?'
            );
            $stmt->execute([$limit, $offset]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT c.*, u.name AS author_name, u.avatar AS author_avatar FROM comments c
                 INNER JOIN users u ON u.id = c.user_id
                 WHERE
                   (c.entity_type = 'project' AND c.entity_id IN (
                      SELECT project_id FROM project_members WHERE user_id = ?
                   ))
                   OR
                   (c.entity_type = 'task' AND c.entity_id IN (
                      SELECT t.id FROM tasks t
                      INNER JOIN project_members pm ON pm.project_id = t.project_id
                      WHERE pm.user_id = ?
                   ))
                 ORDER BY c.created_at DESC LIMIT ? OFFSET ?"
            );
            $stmt->execute([$userId, $userId, $limit, $offset]);
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
