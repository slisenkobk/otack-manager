<?php
declare(strict_types=1);
namespace App\Repository;

final class TaskLinkRepository {
    public function __construct(private \PDO $pdo) {}

    /** Symmetric link: stores one row per pair (smaller id first). */
    public function link(int $taskA, int $taskB, int $createdBy): bool {
        if ($taskA === $taskB) return false;
        [$a, $b] = $taskA < $taskB ? [$taskA, $taskB] : [$taskB, $taskA];
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        try {
            $this->pdo->prepare(
                'INSERT INTO task_links (task_id, linked_task_id, created_by, created_at) VALUES (?, ?, ?, ?)'
            )->execute([$a, $b, $createdBy, $now]);
            return true;
        } catch (\PDOException $e) {
            // UNIQUE violation — already linked
            return false;
        }
    }

    public function unlink(int $taskA, int $taskB): void {
        [$a, $b] = $taskA < $taskB ? [$taskA, $taskB] : [$taskB, $taskA];
        $this->pdo->prepare('DELETE FROM task_links WHERE task_id = ? AND linked_task_id = ?')->execute([$a, $b]);
    }

    /**
     * Wipe every link that touches a task — called when the task itself is
     * deleted, so the other side's "Linked tasks" panel doesn't keep dangling
     * pointers. Returns the number of rows removed.
     */
    public function deleteForTask(int $taskId): int {
        $stmt = $this->pdo->prepare('DELETE FROM task_links WHERE task_id = ? OR linked_task_id = ?');
        $stmt->execute([$taskId, $taskId]);
        return $stmt->rowCount();
    }

    /**
     * Returns linked tasks (id, title, column_id, status info) for the given task,
     * scoped to its own project.
     *
     * @return list<array<string,mixed>>
     */
    public function listForTask(int $taskId): array {
        $stmt = $this->pdo->prepare(
            "SELECT t.id, t.title, t.column_id, t.priority, t.sub_status,
                    c.name AS column_name, c.color AS column_color,
                    c.is_done AS column_is_done, c.is_backlog AS column_is_backlog
             FROM task_links l
             INNER JOIN tasks t ON t.id = CASE WHEN l.task_id = ? THEN l.linked_task_id ELSE l.task_id END
             INNER JOIN task_columns c ON c.id = t.column_id
             WHERE (l.task_id = ? OR l.linked_task_id = ?)
             ORDER BY t.id DESC"
        );
        $stmt->execute([$taskId, $taskId, $taskId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * IDs already linked to $taskId (excluding self).
     *
     * @return list<int>
     */
    public function linkedIds(int $taskId): array {
        $stmt = $this->pdo->prepare(
            'SELECT CASE WHEN task_id = ? THEN linked_task_id ELSE task_id END AS other
             FROM task_links WHERE task_id = ? OR linked_task_id = ?'
        );
        $stmt->execute([$taskId, $taskId, $taskId]);
        return array_map('intval', array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'other'));
    }
}
