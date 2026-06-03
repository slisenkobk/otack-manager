<?php
declare(strict_types=1);
namespace App\Repository;

final class ApiTokenRepository
{
    public function __construct(private \PDO $pdo) {}

    /**
     * Generate a fresh plaintext token: 'otk_' + 40 base62 chars = 44 chars total.
     *
     * Uses random_int() per character (not random_bytes()+modulo) to avoid the
     * modulo bias inherent in `byte % 62` — 256 isn't divisible by 62, so the
     * first four base62 chars (A-D) would otherwise have ~5/256 probability vs
     * ~4/256 for the rest. random_int() draws from the CSPRNG with rejection
     * sampling, yielding a uniform distribution over [0, 61].
     */
    public static function generate(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < 40; $i++) {
            $out .= $alphabet[random_int(0, 61)];
        }
        return 'otk_' . $out;
    }

    /** Hash for storage / lookup. Plaintext is never stored. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Create a token. Returns ['id' => int, 'token' => string]. Plaintext is shown to the user once.
     *
     * @return array{id:int,token:string}
     */
    public function create(int $userId, string $name, ?int $expiresAt = null): array
    {
        $token = self::generate();
        $hash  = self::hash($token);
        $stmt  = $this->pdo->prepare(
            'INSERT INTO api_tokens (user_id, name, token_hash, prefix, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $name, $hash, substr($token, 0, 12), time(), $expiresAt]);
        return ['id' => (int)$this->pdo->lastInsertId(), 'token' => $token];
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM api_tokens WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /**
     * Active = not revoked, not expired, prefix-sane. Returns full row or null.
     *
     * @return array<string,mixed>|null
     */
    public function findActiveByToken(string $token): ?array
    {
        // A valid token is 'otk_' (4) + 40 base62 chars = 44 chars. The old
        // bound of 40 admitted four-too-short candidates; tighten to 44.
        if (!str_starts_with($token, 'otk_') || strlen($token) < 44) return null;
        $s = $this->pdo->prepare(
            'SELECT * FROM api_tokens
              WHERE token_hash = ? AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > ?)'
        );
        $s->execute([self::hash($token), time()]);
        return $s->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        $s = $this->pdo->prepare('SELECT * FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC, id DESC');
        $s->execute([$userId]);
        return $s->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function listActiveForUser(int $userId): array
    {
        $s = $this->pdo->prepare(
            'SELECT * FROM api_tokens
              WHERE user_id = ? AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > ?)
              ORDER BY created_at DESC, id DESC'
        );
        $s->execute([$userId, time()]);
        return $s->fetchAll();
    }

    public function revoke(int $id): void
    {
        $s = $this->pdo->prepare('UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL');
        $s->execute([time(), $id]);
    }

    public function revokeAllForUser(int $userId): void
    {
        $s = $this->pdo->prepare('UPDATE api_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL');
        $s->execute([time(), $userId]);
    }

    public function touchUsage(int $id, string $ip): void
    {
        $s = $this->pdo->prepare('UPDATE api_tokens SET last_used_at = ?, last_used_ip = ? WHERE id = ?');
        $s->execute([time(), $ip, $id]);
    }
}
