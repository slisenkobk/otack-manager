<?php
declare(strict_types=1);
namespace App\Repository;

final class ActivityLogRepository
{
    public function __construct(private \PDO $pdo) {}

    public function log(
        string $event,
        int $actorId,
        ?int $projectId,
        ?int $taskId,
        string $summary,
        array $meta = []
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO activity_log (event, actor_id, project_id, task_id, summary, meta, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $event,
            $actorId,
            $projectId,
            $taskId,
            mb_substr($summary, 0, 500),
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Recent activity visible to the user. Admin sees everything; members see only their projects.
     *
     * @return list<array<string,mixed>>
     */
    public function recentForUser(int $userId, bool $isAdmin, int $limit = 10, int $offset = 0): array
    {
        $base =
            'SELECT a.*, u.name AS actor_name,
                    p.name AS project_name,
                    t.title AS task_title
             FROM activity_log a
             LEFT JOIN users u ON u.id = a.actor_id
             LEFT JOIN projects p ON p.id = a.project_id
             LEFT JOIN tasks t ON t.id = a.task_id';
        $params = [];
        if (!$isAdmin) {
            $base .= ' WHERE a.project_id IS NULL
                       OR a.project_id IN (SELECT project_id FROM project_members WHERE user_id = ?)';
            $params[] = $userId;
        }
        $base .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $this->pdo->prepare($base);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function countForUser(int $userId, bool $isAdmin): int
    {
        if ($isAdmin) {
            return (int)$this->pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM activity_log
             WHERE project_id IS NULL
                OR project_id IN (SELECT project_id FROM project_members WHERE user_id = ?)'
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
}
