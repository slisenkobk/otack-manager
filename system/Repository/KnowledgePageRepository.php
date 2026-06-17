<?php
declare(strict_types=1);
namespace App\Repository;

/**
 * Knowledge-base pages — flat list, addressable by slug, with optional
 * category. Tags and comments live in the existing polymorphic tables
 * (TagRepository scope='knowledge_page', CommentRepository
 * entity_type='knowledge_page'); this repo only manages the page row
 * itself.
 *
 * Version snapshots are append-only via KnowledgePageVersionRepository
 * and never written here — `update()` mutates body_md in place.
 */
final class KnowledgePageRepository
{
    public function __construct(private \PDO $pdo) {}

    public function create(string $title, string $bodyMd, ?int $categoryId, int $authorId): int
    {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Title is required');
        }
        $slug = $this->slugify($title);
        $now = iso_now_utc();
        $stmt = $this->pdo->prepare(
            'INSERT INTO knowledge_pages (slug, title, body_md, category_id, author_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$slug, $title, $bodyMd, $categoryId, $authorId, $now, $now]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, u.name AS author_name, c.name AS category_name, c.slug AS category_slug
             FROM knowledge_pages p
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN knowledge_categories c ON c.id = p.category_id
             WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, u.name AS author_name, c.name AS category_name, c.slug AS category_slug
             FROM knowledge_pages p
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN knowledge_categories c ON c.id = p.category_id
             WHERE p.slug = ?'
        );
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    public function update(int $id, string $title, string $bodyMd, ?int $categoryId): void
    {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Title is required');
        }
        $now = iso_now_utc();
        $stmt = $this->pdo->prepare(
            'UPDATE knowledge_pages
                SET title = ?, body_md = ?, category_id = ?, updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([$title, $bodyMd, $categoryId, $now, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM knowledge_pages WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * List pages with optional filters. Tag filter is applied via
     * EXISTS subquery against tag_assignments; q does case-insensitive
     * LIKE on title + body_md (we accept the body scan cost — the table
     * stays small for a knowledge base, and FTS5/MATCH would split
     * SQLite/MySQL parity badly).
     *
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function listFiltered(
        ?int $categoryId,
        ?int $tagId,
        string $q,
        int $page,
        int $perPage
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $where = [];
        $args  = [];
        if ($categoryId !== null) { $where[] = 'p.category_id = ?'; $args[] = $categoryId; }
        if ($q !== '') {
            // Unicode-aware case-insensitive substring match. On SQLite we
            // use the mb_lower() UDF registered in SqliteDriver::postConnect;
            // built-in LOWER() folds only ASCII so Cyrillic/Polish-diacritic
            // searches would silently miss case-variant hits. On MySQL we
            // fall back to LOWER() which handles utf8mb4 correctly via the
            // table's collation.
            $driver = \App\Database\Connection::driverFor($this->pdo);
            $lower  = ($driver?->name() === 'sqlite') ? 'mb_lower' : 'LOWER';
            $where[] = "($lower(p.title) LIKE ? OR $lower(p.body_md) LIKE ?)";
            $needle = '%' . mb_strtolower($q, 'UTF-8') . '%';
            $args[] = $needle;
            $args[] = $needle;
        }
        if ($tagId !== null) {
            $where[] = "EXISTS (
                SELECT 1 FROM knowledge_page_tag_map m
                WHERE m.tag_id = ? AND m.page_id = p.id
            )";
            $args[] = $tagId;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM knowledge_pages p $whereSql"
        );
        $countStmt->execute($args);
        $total = (int)$countStmt->fetchColumn();

        $listStmt = $this->pdo->prepare(
            "SELECT p.id, p.slug, p.title, p.category_id, p.author_id, p.updated_at,
                    u.name AS author_name,
                    c.name AS category_name, c.slug AS category_slug
               FROM knowledge_pages p
               LEFT JOIN users u ON u.id = p.author_id
               LEFT JOIN knowledge_categories c ON c.id = p.category_id
               $whereSql
              ORDER BY p.updated_at DESC, p.id DESC
              LIMIT ? OFFSET ?"
        );
        $i = 1;
        foreach ($args as $a) { $listStmt->bindValue($i++, $a); }
        $listStmt->bindValue($i++, $perPage, \PDO::PARAM_INT);
        $listStmt->bindValue($i,   $offset,  \PDO::PARAM_INT);
        $listStmt->execute();

        return ['rows' => $listStmt->fetchAll(), 'total' => $total];
    }

    /**
     * Total page count + per-category counts for the filter rail.
     * Categories with zero pages are still useful (admin may want to
     * see them), so the caller merges these into the full category
     * list — this method only reports counts.
     *
     * @return array{total: int, byCategory: array<int,int>, uncategorised: int}
     */
    public function categoryCounts(): array
    {
        $stmt = $this->pdo->query(
            'SELECT category_id, COUNT(*) AS n FROM knowledge_pages GROUP BY category_id'
        );
        $byCategory   = [];
        $uncategorised = 0;
        $total = 0;
        foreach ($stmt->fetchAll() as $row) {
            $n = (int)$row['n'];
            $total += $n;
            if ($row['category_id'] === null) $uncategorised = $n;
            else $byCategory[(int)$row['category_id']] = $n;
        }
        return ['total' => $total, 'byCategory' => $byCategory, 'uncategorised' => $uncategorised];
    }

    public function slugify(string $title): string
    {
        $base = KnowledgeCategoryRepository::baseSlug($title) ?: 'page';
        $slug = $base;
        $n = 2;
        while ($this->findBySlug($slug)) {
            $slug = $base . '-' . $n;
            $n++;
        }
        return $slug;
    }
}
