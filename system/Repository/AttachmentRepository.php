<?php
declare(strict_types=1);
namespace App\Repository;

final class AttachmentRepository
{
    public function __construct(private \PDO $pdo) {}

    public function create(array $p): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
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

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM attachments WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function listFor(string $entityType, int $entityId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM attachments WHERE entity_type = ? AND entity_id = ? ORDER BY created_at ASC'
        );
        $stmt->execute([$entityType, $entityId]);
        return $stmt->fetchAll();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM attachments WHERE id = ?')->execute([$id]);
    }
}
