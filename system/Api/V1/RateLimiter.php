<?php
declare(strict_types=1);
namespace App\Api\V1;

/**
 * Sliding-window rate limiter keyed on api_tokens.id. Hot path: invoked
 * on every authenticated API request, so we keep the work to a single
 * SELECT + one INSERT-or-UPDATE. The row lifecycle is:
 *
 *   - no row              → INSERT with count=1, fresh window_start.
 *   - row, window stale   → reset: window_start=now, count=1.
 *   - row, window fresh   → count += 1; allowed iff count <= max.
 *
 * The table has no FK to api_tokens by design (see the migration): a
 * stale row for a revoked token is harmless and the FK check costs more
 * than it saves on every authenticated request.
 *
 * UPSERT dialect: SQLite and MySQL spell it differently. We branch on
 * the driver name via {@see \App\Database\Connection::driverFor()} —
 * the same pattern used by ProjectMemberRepository / TagRepository.
 */
final class RateLimiter
{
    public function __construct(
        private \PDO $pdo,
        public readonly int $max = 60,
        public readonly int $windowSeconds = 60,
    ) {}

    /**
     * Check + increment for a token id. Returns:
     *   ['allowed' => bool, 'count' => int, 'retry_after' => int]
     * retry_after is seconds until the window resets when allowed=false, 0 otherwise.
     *
     * @return array{allowed:bool,count:int,retry_after:int}
     */
    public function check(int $tokenId): array
    {
        $now = time();
        $sel = $this->pdo->prepare('SELECT window_start, count FROM api_rate_limits WHERE token_id = ?');
        $sel->execute([$tokenId]);
        $r = $sel->fetch();

        if (!$r || ($now - (int)$r['window_start']) >= $this->windowSeconds) {
            // Fresh window — UPSERT to 1. We can't INSERT blindly because the
            // row may already exist (stale window case), so we need the
            // dialect-specific "on conflict, reset" verb.
            $this->pdo->prepare($this->upsertResetSql())->execute([$tokenId, $now]);
            return ['allowed' => true, 'count' => 1, 'retry_after' => 0];
        }

        $count = (int)$r['count'] + 1;
        $this->pdo->prepare('UPDATE api_rate_limits SET count = ? WHERE token_id = ?')
            ->execute([$count, $tokenId]);

        if ($count > $this->max) {
            $retry = $this->windowSeconds - ($now - (int)$r['window_start']);
            return ['allowed' => false, 'count' => $count, 'retry_after' => max(1, $retry)];
        }
        return ['allowed' => true, 'count' => $count, 'retry_after' => 0];
    }

    /**
     * Returns an UPSERT statement with two positional parameters
     * (token_id, window_start) that sets count=1 either on insert or on
     * conflict. Dialect-specific because the ON CONFLICT vs.
     * ON DUPLICATE KEY syntaxes are incompatible.
     */
    private function upsertResetSql(): string
    {
        $driver = \App\Database\Connection::driverFor($this->pdo)?->name() ?? 'sqlite';
        if ($driver === 'mysql') {
            return 'INSERT INTO api_rate_limits (token_id, window_start, count) VALUES (?, ?, 1)
                    ON DUPLICATE KEY UPDATE window_start = VALUES(window_start), count = 1';
        }
        // SQLite (and the default fallback for unbound PDO in tests).
        return 'INSERT INTO api_rate_limits (token_id, window_start, count) VALUES (?, ?, 1)
                ON CONFLICT(token_id) DO UPDATE SET window_start = excluded.window_start, count = 1';
    }
}
