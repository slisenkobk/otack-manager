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
        $verb = \App\Database\Connection::driverFor($this->pdo)?->insertIgnoreVerb() ?? 'INSERT OR IGNORE';
        $this->pdo->prepare("$verb INTO project_tag_map (project_id, tag_id) VALUES (?, ?)")
            ->execute([$projectId, $tagId]);
    }

    public function detachFromProject(int $projectId, int $tagId): void {
        $this->pdo->prepare('DELETE FROM project_tag_map WHERE project_id = ? AND tag_id = ?')
            ->execute([$projectId, $tagId]);
    }

    public function attachToTask(int $taskId, int $tagId): void {
        $verb = \App\Database\Connection::driverFor($this->pdo)?->insertIgnoreVerb() ?? 'INSERT OR IGNORE';
        $this->pdo->prepare("$verb INTO task_tag_map (task_id, tag_id) VALUES (?, ?)")
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

    /**
     * @return array<int, array<array{id:int, name:string, color:string}>>
     *   Map of task_id => [tag rows]
     */
    public function listForProjectTasks(int $projectId): array {
        $stmt = $this->pdo->prepare(
            'SELECT m.task_id, t.id AS tag_id, t.name, t.color
             FROM task_tag_map m
             INNER JOIN tags t ON t.id = m.tag_id
             WHERE m.task_id IN (SELECT id FROM tasks WHERE project_id = ?)'
        );
        $stmt->execute([$projectId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int)$r['task_id']][] = ['id' => (int)$r['tag_id'], 'name' => $r['name'], 'color' => $r['color']];
        }
        return $out;
    }

    public function listForTask(int $taskId): array {
        $stmt = $this->pdo->prepare(
            'SELECT t.* FROM tags t INNER JOIN task_tag_map m ON m.tag_id = t.id WHERE m.task_id = ? ORDER BY t.name ASC'
        );
        $stmt->execute([$taskId]);
        return $stmt->fetchAll();
    }

    public function listAll(): array {
        $stmt = $this->pdo->query('SELECT * FROM tags ORDER BY scope ASC, name ASC');
        return $stmt->fetchAll();
    }

    public function listPaged(int $limit, int $offset, string $query = ''): array {
        if ($query !== '') {
            $like = '%' . mb_strtolower($query) . '%';
            $stmt = $this->pdo->prepare('SELECT * FROM tags WHERE LOWER(name) LIKE ? ORDER BY scope ASC, name ASC LIMIT ? OFFSET ?');
            $stmt->execute([$like, $limit, $offset]);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM tags ORDER BY scope ASC, name ASC LIMIT ? OFFSET ?');
            $stmt->execute([$limit, $offset]);
        }
        return $stmt->fetchAll();
    }

    public function countAll(string $query = ''): int {
        if ($query !== '') {
            $like = '%' . mb_strtolower($query) . '%';
            $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM tags WHERE LOWER(name) LIKE ?');
            $stmt->execute([$like]);
            return (int)$stmt->fetch()['c'];
        }
        return (int)$this->pdo->query('SELECT COUNT(*) AS c FROM tags')->fetch()['c'];
    }

    public function countUsage(int $tagId): array {
        $proj = $this->pdo->prepare('SELECT COUNT(*) FROM project_tag_map WHERE tag_id = ?');
        $proj->execute([$tagId]);
        $task = $this->pdo->prepare('SELECT COUNT(*) FROM task_tag_map WHERE tag_id = ?');
        $task->execute([$tagId]);
        return [
            'project_count' => (int)$proj->fetchColumn(),
            'task_count'    => (int)$task->fetchColumn(),
        ];
    }

    public function update(int $id, array $fields): void {
        $allowed = ['name', 'color'];
        $sets = [];
        $vals = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $sets[] = "$key = ?";
                $vals[] = $fields[$key];
            }
        }
        if (!$sets) return;
        $vals[] = $id;
        $this->pdo->prepare('UPDATE tags SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute($vals);
    }

    public function delete(int $id): void {
        // Map tables use ON DELETE CASCADE via FK; SQLite requires foreign_keys=ON
        // We delete from map tables explicitly for safety.
        $this->pdo->prepare('DELETE FROM project_tag_map WHERE tag_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM task_tag_map WHERE tag_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
    }
}
