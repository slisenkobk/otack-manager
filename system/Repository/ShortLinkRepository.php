<?php
declare(strict_types=1);
namespace App\Repository;

final class ShortLinkRepository
{
    public function __construct(private \PDO $pdo) {}

    /** Generate a base64url slug. 8 chars ≈ 47 bits — collision-resistant for our scale. */
    public static function generateSlug(int $len = 8): string
    {
        return substr(rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='), 0, $len);
    }

    /**
     * Allow-list for target URL schemes. Anything else (javascript:, data:,
     * file:, ftp:) is rejected so the redirect never becomes an XSS vector.
     * Also rejects user-info (https://evil.com@trusted.com) — passes
     * parse_url's host check but browsers redirect to evil.com.
     * Strings with control characters (CR, LF, NUL) are rejected to keep
     * Response::redirect's Location header safe even before sanitisation.
     */
    public static function isAllowedUrl(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > 2000) return false;
        if (preg_match('/[\r\n\0]/', $url)) return false;
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'http' && $scheme !== 'https') return false;
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') return false;
        // Reject user-info (and password) in authority — they enable a class
        // of open-redirect tricks where the "host" the validator sees is not
        // the host the browser eventually loads.
        if (parse_url($url, PHP_URL_USER) !== null) return false;
        if (parse_url($url, PHP_URL_PASS) !== null) return false;
        return true;
    }

    public function create(string $targetUrl, ?string $title, int $createdBy): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $slug = $this->generateUniqueSlug();
        $stmt = $this->pdo->prepare(
            'INSERT INTO short_links (slug, target_url, title, is_disabled, created_by, created_at, updated_at)
             VALUES (?, ?, ?, 0, ?, ?, ?)'
        );
        $stmt->execute([$slug, $targetUrl, $title, $createdBy, $now, $now]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM short_links WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Active-only lookup: disabled links return null so /s/{slug} can 404 them. */
    public function findActiveBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM short_links WHERE slug = ? AND is_disabled = 0');
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM short_links WHERE slug = ?');
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    /**
     * List for the admin grid. Managers see their own only; admin sees all.
     * Query matches against title or target_url substrings.
     */
    public function listForUser(int $userId, bool $isAdmin, string $query = ''): array
    {
        $where = [];
        $args  = [];
        if (!$isAdmin) { $where[] = 'created_by = ?'; $args[] = $userId; }
        if ($query !== '') {
            $where[] = '(LOWER(title) LIKE ? OR LOWER(target_url) LIKE ? OR slug LIKE ?)';
            $needle  = '%' . mb_strtolower($query) . '%';
            $args[]  = $needle; $args[] = $needle; $args[] = $query . '%';
        }
        $sql = 'SELECT * FROM short_links' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public function update(int $id, array $fields): void
    {
        $allowed = ['target_url' => true, 'title' => true, 'is_disabled' => true];
        $set = []; $vals = [];
        foreach ($fields as $k => $v) {
            if (!isset($allowed[$k])) continue;
            $set[] = "$k = ?"; $vals[] = $v;
        }
        if (!$set) return;
        $set[] = 'updated_at = ?';
        $vals[] = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $vals[] = $id;
        $this->pdo->prepare('UPDATE short_links SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    }

    public function rotateSlug(int $id): string
    {
        $slug = $this->generateUniqueSlug();
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $this->pdo->prepare('UPDATE short_links SET slug = ?, updated_at = ? WHERE id = ?')
            ->execute([$slug, $now, $id]);
        return $slug;
    }

    /**
     * Generate a slug guaranteed to be free at call time. Throws if 5
     * attempts all collide — exhaustion is astronomically unlikely at our
     * scale, but a hard error beats silently UPDATE-ing into a UNIQUE
     * violation and surfacing as a raw PDO exception to the user.
     */
    private function generateUniqueSlug(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $slug = self::generateSlug();
            if (!$this->findBySlug($slug)) return $slug;
        }
        throw new \RuntimeException('Could not allocate a unique short-link slug after 5 attempts');
    }

    public function delete(int $id): void
    {
        // Visits cascade via FK ON DELETE CASCADE.
        $this->pdo->prepare('DELETE FROM short_links WHERE id = ?')->execute([$id]);
    }
}
