<?php
declare(strict_types=1);
namespace App\Repository;

/**
 * Knowledge-base categories — a flat list (no parent_id), surfaced as a
 * dropdown on the editor and as a filter on the index. Admins manage
 * the list at /admin/knowledge/categories. Deleting a category nulls
 * the foreign key on knowledge_pages.category_id (ON DELETE SET NULL),
 * so pages survive a category teardown.
 */
final class KnowledgeCategoryRepository
{
    public function __construct(private \PDO $pdo) {}

    public function create(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Category name is required');
        }
        $slug = $this->slugify($name);
        // sort_order defaults to (max+1) so new categories land at the end.
        $next = (int)$this->pdo->query(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM knowledge_categories'
        )->fetchColumn();
        $now = iso_now_utc();
        $stmt = $this->pdo->prepare(
            'INSERT INTO knowledge_categories (name, slug, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $slug, $next, $now, $now]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM knowledge_categories WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM knowledge_categories WHERE slug = ?');
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function listAll(): array
    {
        return $this->pdo->query(
            'SELECT * FROM knowledge_categories ORDER BY sort_order ASC, name ASC'
        )->fetchAll();
    }

    public function rename(int $id, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Category name is required');
        }
        $now = iso_now_utc();
        $stmt = $this->pdo->prepare(
            'UPDATE knowledge_categories SET name = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$name, $now, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM knowledge_categories WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** Slugify name → unique slug (suffix -2/-3/… on collision). */
    public function slugify(string $name): string
    {
        $base = self::baseSlug($name) ?: 'category';
        $slug = $base;
        $n = 2;
        while ($this->findBySlug($slug)) {
            $slug = $base . '-' . $n;
            $n++;
        }
        return $slug;
    }

    /** Shared with KnowledgePageRepository — kept here as the canonical impl. */
    public static function baseSlug(string $s): string
    {
        $tr = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'h','ґ'=>'g','д'=>'d','е'=>'e','є'=>'ie','ж'=>'zh','з'=>'z',
            'и'=>'y','і'=>'i','ї'=>'i','й'=>'i','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p',
            'р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch',
            'ь'=>'','ю'=>'iu','я'=>'ia','ы'=>'y','э'=>'e','ъ'=>'','ё'=>'e',
        ];
        $lower = mb_strtolower(trim($s), 'UTF-8');
        $lower = strtr($lower, $tr);
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $lower) ?? '';
        return trim($slug, '-');
    }
}
