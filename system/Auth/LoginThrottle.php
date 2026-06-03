<?php
declare(strict_types=1);
namespace App\Auth;

final class LoginThrottle
{
    public function __construct(
        private \PDO $pdo,
        public readonly int $max = 5,
        public readonly int $windowSeconds = 900,
    ) {}

    public function isThrottled(string $email): bool
    {
        $key = $this->key($email);
        $row = $this->pdo->prepare('SELECT window_start, count FROM login_attempts WHERE key_hash = ?');
        $row->execute([$key]);
        $r = $row->fetch();
        if (!$r) return false;
        if ((time() - (int)$r['window_start']) >= $this->windowSeconds) return false;
        return (int)$r['count'] >= $this->max;
    }

    public function recordFail(string $email): void
    {
        $key = $this->key($email);
        $now = time();
        $driver = method_exists(\App\Database\Connection::class, 'driverFor')
            ? (\App\Database\Connection::driverFor($this->pdo)?->name() ?? 'sqlite')
            : 'sqlite';
        if ($driver === 'mysql') {
            $sql = 'INSERT INTO login_attempts (key_hash, window_start, count) VALUES (?, ?, 1)
                    ON DUPLICATE KEY UPDATE
                      window_start = IF(window_start + ? <= VALUES(window_start), VALUES(window_start), window_start),
                      count        = IF(window_start + ? <= VALUES(window_start), 1, count + 1)';
            $this->pdo->prepare($sql)->execute([$key, $now, $this->windowSeconds, $this->windowSeconds]);
        } else {
            $sql = 'INSERT INTO login_attempts (key_hash, window_start, count) VALUES (?, ?, 1)
                    ON CONFLICT(key_hash) DO UPDATE SET
                      window_start = CASE WHEN window_start + ? <= excluded.window_start THEN excluded.window_start ELSE window_start END,
                      count        = CASE WHEN window_start + ? <= excluded.window_start THEN 1 ELSE count + 1 END';
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(1, $key);
            $stmt->bindValue(2, $now, \PDO::PARAM_INT);
            $stmt->bindValue(3, $this->windowSeconds, \PDO::PARAM_INT);
            $stmt->bindValue(4, $this->windowSeconds, \PDO::PARAM_INT);
            $stmt->execute();
        }
    }

    public function resetFails(string $email): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE key_hash = ?')->execute([$this->key($email)]);
    }

    private function key(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }
}
