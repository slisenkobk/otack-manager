<?php
declare(strict_types=1);
namespace App\Repository;

final class TaskColumnRepository {
    public function __construct(private \PDO $pdo) {}

    public function seedDefaults(int $projectId): void {
        // [name, color, position, is_done, is_backlog]
        $defaults = [
            ['Backlog',      '#8B7C68', 0, 0, 1],
            ['To Do',        '#5A4E3F', 1, 0, 0],
            ['In Progress',  '#C2410C', 2, 0, 0],
            ['Done',         '#4D6840', 3, 1, 0],
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO task_columns (project_id, name, color, position, is_done, is_backlog) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($defaults as $d) {
            $stmt->execute([$projectId, $d[0], $d[1], $d[2], $d[3], $d[4]]);
        }
    }

    public function findBacklog(int $projectId): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM task_columns WHERE project_id = ? AND is_backlog = 1 LIMIT 1');
        $stmt->execute([$projectId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listForProject(int $projectId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM task_columns WHERE project_id = ? ORDER BY position ASC');
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }

    public function create(int $projectId, string $name, string $color = '#8B7C68'): int {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(position), -1) AS m FROM task_columns WHERE project_id = ?');
        $stmt->execute([$projectId]);
        $pos = (int)$stmt->fetch()['m'] + 1;
        $ins = $this->pdo->prepare(
            'INSERT INTO task_columns (project_id, name, color, position, is_done) VALUES (?, ?, ?, ?, 0)'
        );
        $ins->execute([$projectId, $name, $color, $pos]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $fields): void {
        $allowed = ['name', 'color', 'position', 'is_done'];
        $set = []; $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $set[] = "$k = ?"; $vals[] = $v;
        }
        if (!$set) return;
        $vals[] = $id;
        $this->pdo->prepare('UPDATE task_columns SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    }

    public function delete(int $id, ?int $moveTasksTo = null): void {
        $this->pdo->beginTransaction();
        try {
            $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM tasks WHERE column_id = ?');
            $countStmt->execute([$id]);
            $hasTasks = (int)$countStmt->fetch()['c'] > 0;
            if ($hasTasks) {
                if ($moveTasksTo === null) throw new \RuntimeException('Column has tasks; provide moveTasksTo');
                $this->pdo->prepare('UPDATE tasks SET column_id = ? WHERE column_id = ?')->execute([$moveTasksTo, $id]);
            }
            $this->pdo->prepare('DELETE FROM task_columns WHERE id = ?')->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack(); throw $e;
        }
    }

    public function reorder(int $projectId, array $orderedIds): void {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE task_columns SET position = ? WHERE id = ? AND project_id = ?');
            foreach ($orderedIds as $pos => $id) {
                $stmt->execute([$pos, $id, $projectId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }
}
