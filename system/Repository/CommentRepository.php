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
        string $body
    ): int {
        $now  = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO comments (entity_type, entity_id, user_id, body, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$entityType, $entityId, $userId, $body, $now]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM comments WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function listFor(string $entityType, int $entityId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, u.name AS author_name
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
        $this->pdo->prepare('DELETE FROM comments WHERE id = ?')->execute([$id]);
    }

    public function recentForUser(int $userId, bool $isAdmin, int $limit = 10): array
    {
        if ($isAdmin) {
            $stmt = $this->pdo->prepare(
                'SELECT c.*, u.name AS author_name FROM comments c
                 INNER JOIN users u ON u.id = c.user_id
                 ORDER BY c.created_at DESC LIMIT ?'
            );
            $stmt->execute([$limit]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT c.*, u.name AS author_name FROM comments c
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
                 ORDER BY c.created_at DESC LIMIT ?"
            );
            $stmt->execute([$userId, $userId, $limit]);
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
