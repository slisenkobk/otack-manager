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
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE project_id = ? ORDER BY column_id ASC, position ASC');
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll();
        $byCol = [];
        foreach ($rows as $r) {
            $byCol[(int)$r['column_id']][] = $r;
        }
        return $byCol;
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
}
