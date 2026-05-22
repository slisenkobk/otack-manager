<?php
declare(strict_types=1);
namespace App\Repository;

final class TaskRepository {
    public function __construct(private \PDO $pdo) {}

    public static function computePosition(?float $prev, ?float $next): float {
        if ($prev === null && $next === null) return 1024.0;
        if ($prev === null) return $next - 1024.0;
        if ($next === null) return $prev + 1024.0;
        return ($prev + $next) / 2.0;
    }

    public function create(
        int $projectId, int $columnId, string $title, int $createdBy,
        ?string $description = null, ?int $assigneeId = null, ?string $dueDate = null
    ): int {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(position), 0) AS m FROM tasks WHERE column_id = ?');
        $stmt->execute([$columnId]);
        $maxPos = (float)$stmt->fetch()['m'];
        $position = $maxPos + 1024.0;
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $ins = $this->pdo->prepare(
            'INSERT INTO tasks (project_id, column_id, title, description, position, assignee_id, due_date, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$projectId, $columnId, $title, $description, $position, $assigneeId, $dueDate, $createdBy, $now, $now]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function listForProject(int $projectId): array {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, u.name AS assignee_name
             FROM tasks t
             LEFT JOIN users u ON u.id = t.assignee_id
             WHERE t.project_id = ?
             ORDER BY t.column_id ASC, t.position ASC'
        );
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll();
        $byCol = [];
        foreach ($rows as $r) {
            $byCol[(int)$r['column_id']][] = $r;
        }
        return $byCol;
    }

    /**
     * Counts of comments + attachments per task in a project.
     * @return array<int, array{comments: int, attachments: int}>
     */
    public function countMetaForProject(int $projectId): array {
        $stmt = $this->pdo->prepare(
            "SELECT t.id,
                    (SELECT COUNT(*) FROM comments WHERE entity_type='task' AND entity_id = t.id) AS comments,
                    (SELECT COUNT(*) FROM attachments WHERE entity_type='task' AND entity_id = t.id) AS attachments
             FROM tasks t WHERE t.project_id = ?"
        );
        $stmt->execute([$projectId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int)$r['id']] = ['comments' => (int)$r['comments'], 'attachments' => (int)$r['attachments']];
        }
        return $out;
    }

    public function update(int $id, array $fields): void {
        $allowed = ['title', 'description', 'column_id', 'position', 'assignee_id', 'due_date'];
        $set = []; $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $set[] = "$k = ?"; $vals[] = $v;
        }
        if (!$set) return;
        $set[] = 'updated_at = ?';
        $vals[] = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $vals[] = $id;
        $this->pdo->prepare('UPDATE tasks SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    }

    public function move(int $id, int $newColumnId, float $newPosition): void {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $this->pdo->prepare('UPDATE tasks SET column_id = ?, position = ?, updated_at = ? WHERE id = ?')
            ->execute([$newColumnId, $newPosition, $now, $id]);
    }

    public function delete(int $id): void {
        $this->pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
    }

    public function countOpenForAssignee(int $userId): int {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS c FROM tasks t
             INNER JOIN task_columns c ON c.id = t.column_id
             WHERE t.assignee_id = ? AND c.is_done = 0'
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetch()['c'];
    }

    public function listForAssignee(int $userId, int $limit = 6): array {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, p.name AS project_name, c.name AS column_name
             FROM tasks t
             INNER JOIN projects p ON p.id = t.project_id
             INNER JOIN task_columns c ON c.id = t.column_id
             WHERE t.assignee_id = ? AND c.is_done = 0
             ORDER BY t.updated_at DESC LIMIT ?'
        );
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }
}
