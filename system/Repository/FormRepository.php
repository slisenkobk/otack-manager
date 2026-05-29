<?php
declare(strict_types=1);
namespace App\Repository;

final class FormRepository
{
    public function __construct(private \PDO $pdo) {}

    /** Generate a random URL-safe hash. Collisions are vanishingly unlikely. */
    public static function generateHash(int $len = 12): string {
        return substr(rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='), 0, $len);
    }

    public function create(string $title, ?string $description, array $fields, array $footer, int $createdBy): int {
        $now  = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $hash = self::generateHash();
        // tiny retry on the off-chance of a hash collision
        for ($i = 0; $i < 5; $i++) {
            if (!$this->findByHash($hash)) break;
            $hash = self::generateHash();
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO forms (hash, title, description, fields_json, footer_json, status, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, "published", ?, ?, ?)'
        );
        $stmt->execute([
            $hash,
            $title,
            $description,
            json_encode($fields, JSON_UNESCAPED_UNICODE),
            json_encode($footer, JSON_UNESCAPED_UNICODE),
            $createdBy,
            $now,
            $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Rotate the public hash on a form, killing the old URL. Returns the new hash.
     */
    public function rotateHash(int $id): string {
        $hash = self::generateHash();
        for ($i = 0; $i < 5; $i++) {
            if (!$this->findByHash($hash)) break;
            $hash = self::generateHash();
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $this->pdo->prepare('UPDATE forms SET hash = ?, updated_at = ? WHERE id = ?')
            ->execute([$hash, $now, $id]);
        return $hash;
    }

    public function update(int $id, array $fields): void {
        $allowed = ['title' => true, 'description' => true, 'fields_json' => true, 'footer_json' => true, 'status' => true];
        $set = []; $vals = [];
        foreach ($fields as $k => $v) {
            if (!isset($allowed[$k])) continue;
            $set[] = "$k = ?"; $vals[] = $v;
        }
        if (!$set) return;
        $set[] = 'updated_at = ?';
        $vals[] = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $vals[] = $id;
        $this->pdo->prepare('UPDATE forms SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    }

    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM forms WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByHash(string $hash): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM forms WHERE hash = ?');
        $stmt->execute([$hash]);
        return $stmt->fetch() ?: null;
    }

    public function listAll(string $query = ''): array {
        if ($query === '') {
            return $this->pdo->query('SELECT * FROM forms ORDER BY created_at DESC')->fetchAll();
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM forms
             WHERE title LIKE ? OR description LIKE ?
             ORDER BY created_at DESC'
        );
        $needle = '%' . $query . '%';
        $stmt->execute([$needle, $needle]);
        return $stmt->fetchAll();
    }

    public function delete(int $id): void {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM form_submissions WHERE form_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
