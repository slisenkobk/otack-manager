<?php
declare(strict_types=1);
namespace App\Repository;

final class AttachmentRepository
{
    public function __construct(private \PDO $pdo) {}

    public function create(array $p): int
    {
        $now  = iso_now_utc();
        $stmt = $this->pdo->prepare(
            'INSERT INTO attachments
                (entity_type, entity_id, filename, original_name, mime, size, is_image, uploaded_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $p['entity_type'],
            $p['entity_id'],
            $p['filename'],
            $p['original_name'],
            $p['mime'],
            $p['size'],
            $p['is_image'] ? 1 : 0,
            $p['uploaded_by'],
            $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM attachments WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function listFor(string $entityType, int $entityId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM attachments WHERE entity_type = ? AND entity_id = ? ORDER BY created_at ASC'
        );
        $stmt->execute([$entityType, $entityId]);
        return $stmt->fetchAll();
    }

    /**
     * Returns attachments belonging to the task itself plus any files attached to comments
     * on that task — single chronological list for the task's "Attachments" panel.
     *
     * @return list<array<string,mixed>>
     */
    public function listForTaskAggregate(int $taskId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM (
                SELECT a.*, NULL AS comment_id
                FROM attachments a
                WHERE a.entity_type = 'task' AND a.entity_id = ?
                UNION ALL
                SELECT a.*, c.id AS comment_id
                FROM attachments a
                INNER JOIN comments c ON c.id = a.entity_id
                WHERE a.entity_type = 'comment' AND c.entity_type = 'task' AND c.entity_id = ?
             ) ORDER BY created_at ASC"
        );
        $stmt->execute([$taskId, $taskId]);
        return $stmt->fetchAll();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM attachments WHERE id = ?')->execute([$id]);
    }
}
