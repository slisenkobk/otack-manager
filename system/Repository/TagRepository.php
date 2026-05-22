<?php
declare(strict_types=1);
namespace App\Repository;

final class TagRepository {
    public function __construct(private \PDO $pdo) {}

    public function create(string $scope, string $name, string $color = '#8B7C68'): int {
        $stmt = $this->pdo->prepare('SELECT id FROM tags WHERE scope = ? AND name = ?');
        $stmt->execute([$scope, $name]);
        $row = $stmt->fetch();
        if ($row) return (int)$row['id'];
        $ins = $this->pdo->prepare('INSERT INTO tags (scope, name, color) VALUES (?, ?, ?)');
        $ins->execute([$scope, $name, $color]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM tags WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function listForScope(string $scope): array {
        $stmt = $this->pdo->prepare('SELECT * FROM tags WHERE scope = ? ORDER BY name ASC');
        $stmt->execute([$scope]);
        return $stmt->fetchAll();
    }

    public function attachToProject(int $projectId, int $tagId): void {
        $this->pdo->prepare('INSERT OR IGNORE INTO project_tag_map (project_id, tag_id) VALUES (?, ?)')
            ->execute([$projectId, $tagId]);
    }

    public function detachFromProject(int $projectId, int $tagId): void {
        $this->pdo->prepare('DELETE FROM project_tag_map WHERE project_id = ? AND tag_id = ?')
            ->execute([$projectId, $tagId]);
    }

    public function attachToTask(int $taskId, int $tagId): void {
        $this->pdo->prepare('INSERT OR IGNORE INTO task_tag_map (task_id, tag_id) VALUES (?, ?)')
            ->execute([$taskId, $tagId]);
    }

    public function detachFromTask(int $taskId, int $tagId): void {
        $this->pdo->prepare('DELETE FROM task_tag_map WHERE task_id = ? AND tag_id = ?')
            ->execute([$taskId, $tagId]);
    }

    public function listForProject(int $projectId): array {
        $stmt = $this->pdo->prepare(
            'SELECT t.* FROM tags t INNER JOIN project_tag_map m ON m.tag_id = t.id WHERE m.project_id = ? ORDER BY t.name ASC'
        );
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }

    public function listForTask(int $taskId): array {
        $stmt = $this->pdo->prepare(
            'SELECT t.* FROM tags t INNER JOIN task_tag_map m ON m.tag_id = t.id WHERE m.task_id = ? ORDER BY t.name ASC'
        );
        $stmt->execute([$taskId]);
        return $stmt->fetchAll();
    }
}
