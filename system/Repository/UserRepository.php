<?php
declare(strict_types=1);
namespace App\Repository;

final class UserRepository {
    public function __construct(private \PDO $pdo) {}

    public function findByEmail(string $email): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function create(string $email, string $hash, string $name): int {
        $this->pdo->beginTransaction();
        try {
            $count = (int)$this->pdo->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
            $role   = $count === 0 ? 'admin'    : 'member';
            $status = $count === 0 ? 'approved' : 'pending';
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (email, password_hash, name, role, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
            $stmt->execute([$email, $hash, $name, $role, $status, $now]);
            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function approve(int $id): void {
        $this->pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute(['approved', $id]);
    }

    public function block(int $id): void {
        $this->pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute(['blocked', $id]);
    }

    public function setRole(int $id, string $role): void {
        $this->pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
    }

    public function setTelegramChatId(int $id, ?string $chatId): void {
        $this->pdo->prepare('UPDATE users SET telegram_chat_id = ? WHERE id = ?')->execute([$chatId, $id]);
    }

    public function touchLastLogin(int $id): void {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $this->pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([$now, $id]);
    }

    public function listAll(): array {
        return $this->pdo->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();
    }

    public function delete(int $id): void {
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }

    public function hasRelatedData(int $id): bool {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM projects WHERE created_by = ? '
           .'UNION ALL SELECT 1 FROM comments WHERE user_id = ? '
           .'UNION ALL SELECT 1 FROM attachments WHERE uploaded_by = ? '
           .'LIMIT 1'
        );
        $stmt->execute([$id, $id, $id]);
        return (bool)$stmt->fetch();
    }

    public function updatePassword(int $id, string $hash): void {
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    public function updateName(int $id, string $name): void {
        $this->pdo->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $id]);
    }
}
