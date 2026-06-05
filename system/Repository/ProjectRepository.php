<?php
declare(strict_types=1);
namespace App\Repository;

final class ProjectRepository {
    public function __construct(private \PDO $pdo) {}

    public const PALETTE = [
        '#EA580C', '#5A4E3F', '#2563EB', '#CA8A04', '#4D6840',
        '#6D28D9', '#DC2626', '#0891B2', '#9333EA', '#0F766E',
    ];

    public function create(string $name, ?string $description, int $createdBy, ?string $color = null): int {
        $this->pdo->beginTransaction();
        try {
            $slug = $this->slugify($name);
            $now = iso_now_utc();
            if (!$color || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $color = self::PALETTE[array_rand(self::PALETTE)];
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO projects (name, slug, description, color, status, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $slug, $description, $color, 'active', $createdBy, $now, $now]);
            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE slug = ?');
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    public function update(int $id, array $fields): void {
        $allowed = ['name', 'description', 'status', 'color'];
        $set = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $set[] = "$k = ?"; $vals[] = $v;
        }
        if (!$set) return;
        $set[] = 'updated_at = ?';
        $vals[] = iso_now_utc();
        $vals[] = $id;
        $this->pdo->prepare('UPDATE projects SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId, bool $isAdmin): array {
        if ($isAdmin) {
            return $this->pdo->query('SELECT * FROM projects ORDER BY (pinned_at IS NULL), pinned_at DESC, updated_at DESC')->fetchAll();
        }
        $stmt = $this->pdo->prepare(
            'SELECT p.* FROM projects p
             INNER JOIN project_members pm ON pm.project_id = p.id
             WHERE pm.user_id = ?
             ORDER BY (p.pinned_at IS NULL), p.pinned_at DESC, p.updated_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function delete(int $id): void {
        $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
    }

    public function setPinned(int $id, bool $pinned): void {
        if ($pinned) {
            $now = iso_now_utc();
            $this->pdo->prepare('UPDATE projects SET pinned_at = ? WHERE id = ?')->execute([$now, $id]);
        } else {
            $this->pdo->prepare('UPDATE projects SET pinned_at = NULL WHERE id = ?')->execute([$id]);
        }
    }

    public function countAllForUser(int $userId, bool $isAdmin, ?string $status = null, string $query = ''): int {
        $where = '';
        $args  = [];
        if ($status !== null) { $where .= ' AND p.status = ?'; $args[] = $status; }
        if ($query !== '')    { $where .= ' AND LOWER(p.name) LIKE ?'; $args[] = '%' . mb_strtolower($query) . '%'; }
        if ($isAdmin) {
            $sql = 'SELECT COUNT(*) AS c FROM projects p WHERE 1=1' . $where;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($args);
        } else {
            $sql = 'SELECT COUNT(*) AS c FROM projects p
                    INNER JOIN project_members pm ON pm.project_id = p.id
                    WHERE pm.user_id = ?' . $where;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge([$userId], $args));
        }
        return (int)$stmt->fetch()['c'];
    }

    /** @return list<array<string,mixed>> */
    public function listForUserPaged(int $userId, bool $isAdmin, ?string $status, int $limit, int $offset, string $query = ''): array {
        $where = '';
        $args  = [];
        if ($status !== null) { $where .= ' AND p.status = ?'; $args[] = $status; }
        if ($query !== '')    { $where .= ' AND LOWER(p.name) LIKE ?'; $args[] = '%' . mb_strtolower($query) . '%'; }
        if ($isAdmin) {
            $sql = 'SELECT * FROM projects p WHERE 1=1' . $where . ' ORDER BY (p.pinned_at IS NULL), p.pinned_at DESC, p.updated_at DESC LIMIT ? OFFSET ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge($args, [$limit, $offset]));
        } else {
            $sql = 'SELECT p.* FROM projects p
                    INNER JOIN project_members pm ON pm.project_id = p.id
                    WHERE pm.user_id = ?' . $where . '
                    ORDER BY (p.pinned_at IS NULL), p.pinned_at DESC, p.updated_at DESC LIMIT ? OFFSET ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge([$userId], $args, [$limit, $offset]));
        }
        return $stmt->fetchAll();
    }

    public function countOpenForUser(int $userId, bool $isAdmin): int {
        if ($isAdmin) {
            $row = $this->pdo->query("SELECT COUNT(*) AS c FROM projects WHERE status = 'active'")->fetch();
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS c FROM projects p
                 INNER JOIN project_members pm ON pm.project_id = p.id
                 WHERE pm.user_id = ? AND p.status = 'active'"
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
        }
        return (int)$row['c'];
    }

    /** @return list<array<string,mixed>> */
    public function recentForUser(int $userId, bool $isAdmin, int $limit = 3): array {
        if ($isAdmin) {
            $stmt = $this->pdo->prepare('SELECT * FROM projects ORDER BY (pinned_at IS NULL), pinned_at DESC, updated_at DESC LIMIT ?');
            $stmt->execute([$limit]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT p.* FROM projects p
                 INNER JOIN project_members pm ON pm.project_id = p.id
                 WHERE pm.user_id = ?
                 ORDER BY (p.pinned_at IS NULL), p.pinned_at DESC, p.updated_at DESC LIMIT ?'
            );
            $stmt->execute([$userId, $limit]);
        }
        return $stmt->fetchAll();
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
