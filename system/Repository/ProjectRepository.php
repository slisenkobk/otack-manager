<?php
declare(strict_types=1);
namespace App\Repository;

final class ProjectRepository {
    public function __construct(private \PDO $pdo) {}

    public function create(string $name, ?string $description, int $createdBy): int {
        $this->pdo->beginTransaction();
        try {
            $slug = $this->slugify($name);
            $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
            $stmt = $this->pdo->prepare(
                'INSERT INTO projects (name, slug, description, status, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $slug, $description, 'active', $createdBy, $now, $now]);
            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findBySlug(string $slug): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE slug = ?');
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    public function update(int $id, array $fields): void {
        $allowed = ['name', 'description', 'status'];
        $set = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $set[] = "$k = ?"; $vals[] = $v;
        }
        if (!$set) return;
        $set[] = 'updated_at = ?';
        $vals[] = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $vals[] = $id;
        $this->pdo->prepare('UPDATE projects SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    }

    public function listForUser(int $userId, bool $isAdmin): array {
        if ($isAdmin) {
            return $this->pdo->query('SELECT * FROM projects ORDER BY updated_at DESC')->fetchAll();
        }
        $stmt = $this->pdo->prepare(
            'SELECT p.* FROM projects p
             INNER JOIN project_members pm ON pm.project_id = p.id
             WHERE pm.user_id = ?
             ORDER BY p.updated_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function delete(int $id): void {
        $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
    }

    public function slugify(string $name): string {
        // Cyrillic -> latin (basic Ukrainian + Russian)
        $tr = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'h','ґ'=>'g','д'=>'d','е'=>'e','є'=>'ie','ж'=>'zh','з'=>'z',
            'и'=>'y','і'=>'i','ї'=>'i','й'=>'i','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p',
            'р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch',
            'ь'=>'','ю'=>'iu','я'=>'ia','ы'=>'y','э'=>'e','ъ'=>'','ё'=>'e',
        ];
        $lower = mb_strtolower($name, 'UTF-8');
        $lower = strtr($lower, $tr);
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $lower);
        $slug = trim($slug, '-');
        if ($slug === '') $slug = 'project';

        // Uniqueness
        $base = $slug;
        $n = 2;
        while ($this->findBySlug($slug)) {
            $slug = $base . '-' . $n;
            $n++;
        }
        return $slug;
    }
}
