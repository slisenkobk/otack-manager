<?php
declare(strict_types=1);
namespace App\Repository;

final class ActivityLogRepository
{
    public function __construct(private \PDO $pdo) {}

    /**
     * Record an activity log entry.
     *
     * Two call styles supported:
     *   - Positional (legacy): log('task.created', $userId, $projectId, $taskId, $summary, $meta = [])
     *   - Assoc (preferred):   log([
     *         'event'      => 'task.created',
     *         'actor_id'   => $userId,
     *         'project_id' => $projectId,
     *         'task_id'    => $taskId,
     *         'summary'    => '…',
     *         'meta'       => [...],
     *     ])
     *
     * The assoc form is forward-compatible if new fields are added.
     *
     * @param string|array<string,mixed> $event
     * @param array<string,mixed> $meta
     */
    public function log(
        string|array $event,
        int $actorId = 0,
        ?int $projectId = null,
        ?int $taskId = null,
        string $summary = '',
        array $meta = [],
    ): int {
        if (is_array($event)) {
            $args      = $event;
            $event     = (string)($args['event'] ?? '');
            $actorId   = (int)($args['actor_id'] ?? 0);
            $projectId = isset($args['project_id']) ? (int)$args['project_id'] : null;
            $taskId    = isset($args['task_id']) ? (int)$args['task_id'] : null;
            $summary   = (string)($args['summary'] ?? '');
            $meta      = (array)($args['meta'] ?? []);
        }
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

    /**
     * Delete activity_log rows older than the given cutoff timestamp.
     * `$cutoff` must be in the same string format as `activity_log.created_at`
     * (`Y-m-d H:i:s`). Returns the number of rows pruned.
     */
    public function pruneBefore(string $cutoff): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM activity_log WHERE created_at < ?');
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
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
