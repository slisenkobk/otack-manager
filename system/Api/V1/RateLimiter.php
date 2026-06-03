<?php
declare(strict_types=1);
namespace App\Api\V1;

/**
 * Sliding-window rate limiter keyed on api_tokens.id. Hot path: invoked
 * on every authenticated API request, so we keep the work to a single
 * UPSERT + one SELECT. The row lifecycle is:
 *
 *   - no row              → INSERT with count=1, fresh window_start.
 *   - row, window stale   → reset: window_start=now, count=1.
 *   - row, window fresh   → count += 1; allowed iff count <= max.
 *
 * The table has no FK to api_tokens by design (see the migration): a
 * stale row for a revoked token is harmless and the FK check costs more
 * than it saves on every authenticated request.
 *
 * Concurrency: previously the implementation did SELECT-then-UPDATE,
 * which is a TOCTOU race — two concurrent callers could both observe
 * count=N and both write N+1, so each was counted only once. The
 * effective ceiling drifted up to `max + (concurrency - 1)`. The new
 * implementation uses a single atomic conditional UPSERT that either
 * resets the window or increments count in one statement, so each
 * caller's increment is recorded exactly once. The follow-up SELECT
 * may observe a slightly newer state when racing with another caller,
 * but that's fine — we always read a count >= our own increment, so
 * the limit is enforced strictly.
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

        // Atomic conditional UPSERT: either insert a new row (count=1),
        // reset a stale row (count=1, window_start=now), or increment a
        // fresh row (count += 1). The CASE on window_start compares the
        // EXISTING row's window_start against $now (passed twice: once
        // for the predicate, once as the candidate new value).
        //
        // PARAM_INT binding is essential: with the default string-binding,
        // SQLite compares `INTEGER + TEXT <= TEXT` and the typeless RHS
        // makes the predicate's truth-value undefined for our purposes.
        $stmt = $this->pdo->prepare($this->upsertSql());
        $stmt->bindValue(1, $tokenId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $now, \PDO::PARAM_INT);
        $stmt->bindValue(3, $this->windowSeconds, \PDO::PARAM_INT);
        $stmt->bindValue(4, $now, \PDO::PARAM_INT);
        $stmt->bindValue(5, $this->windowSeconds, \PDO::PARAM_INT);
        $stmt->bindValue(6, $now, \PDO::PARAM_INT);
        $stmt->execute();

        // Re-read post-UPSERT state. Under contention this may show a
        // count larger than our own increment (another caller just
        // bumped) — that's safe, we just over-throttle by at most one
        // request per concurrent caller, never under-throttle.
        $sel = $this->pdo->prepare('SELECT window_start, count FROM api_rate_limits WHERE token_id = ?');
        $sel->execute([$tokenId]);
        $r = $sel->fetch();
        if (!$r) {
            // Defensive: the UPSERT just ran. If a row is somehow gone (e.g.
            // a separate process truncated the table) treat as fresh-allowed.
            return ['allowed' => true, 'count' => 1, 'retry_after' => 0];
        }

        $count = (int)$r['count'];
        $windowStart = (int)$r['window_start'];

        if ($count > $this->max) {
            $retry = $this->windowSeconds - ($now - $windowStart);
            return ['allowed' => false, 'count' => $count, 'retry_after' => max(1, $retry)];
        }
        return ['allowed' => true, 'count' => $count, 'retry_after' => 0];
    }

    /**
     * Returns the atomic conditional UPSERT statement.
     * Positional parameters (in order):
     *   1: token_id (INSERT value)
     *   2: window_start (INSERT value = now)
     *   3: windowSeconds (predicate for stale window)
     *   4: now (predicate compare)
     *   5: windowSeconds (predicate for stale window — count branch)
     *   6: now (predicate compare — count branch)
     *
     * On INSERT: count=1, window_start=now.
     * On CONFLICT: if the existing row's window_start is stale (i.e.
     * window_start + windowSeconds <= now), reset to count=1 with
     * window_start=now; otherwise increment count by 1 and keep
     * window_start.
     */
    private function upsertSql(): string
    {
        $driver = \App\Database\Connection::driverFor($this->pdo)?->name() ?? 'sqlite';
        if ($driver === 'mysql') {
            // In MySQL ON DUPLICATE KEY, bare column references mean the
            // EXISTING row's column; VALUES(col) is the proposed INSERT
            // value. We pass `now` and `windowSeconds` as additional
            // positional params for the CASE predicate.
            return 'INSERT INTO api_rate_limits (token_id, window_start, count) VALUES (?, ?, 1)
                    ON DUPLICATE KEY UPDATE
                      window_start = CASE WHEN window_start + ? <= ? THEN VALUES(window_start) ELSE window_start END,
                      count        = CASE WHEN window_start + ? <= ? THEN 1                     ELSE count + 1   END';
        }
        // SQLite: bare column references in DO UPDATE refer to the existing
        // row; `excluded.col` is the INSERT-candidate value.
        return 'INSERT INTO api_rate_limits (token_id, window_start, count) VALUES (?, ?, 1)
                ON CONFLICT(token_id) DO UPDATE SET
                  window_start = CASE WHEN api_rate_limits.window_start + ? <= ? THEN excluded.window_start ELSE api_rate_limits.window_start END,
                  count        = CASE WHEN api_rate_limits.window_start + ? <= ? THEN 1                     ELSE api_rate_limits.count + 1   END';
    }
}
