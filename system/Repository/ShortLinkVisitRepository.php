<?php
declare(strict_types=1);
namespace App\Repository;

final class ShortLinkVisitRepository
{
    public function __construct(private \PDO $pdo) {}

    /**
     * Hash a visitor IP with the app's shared secret so unique-visitor counts
     * work without storing raw addresses. Returns null when the IP itself is
     * empty/invalid — we still log the visit, just without dedupe.
     */
    public static function hashIp(string $ip, string $secret): ?string
    {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) return null;
        $secret = $secret !== '' ? $secret : 'otack-short-link-fallback';
        return substr(hash_hmac('sha256', $ip, $secret), 0, 32);
    }

    public function log(int $linkId, ?string $ipHash, ?string $userAgent, ?string $referer): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $ua  = $userAgent !== null ? mb_substr($userAgent, 0, 500) : null;
        $ref = $referer !== null ? mb_substr($referer, 0, 500) : null;
        $stmt = $this->pdo->prepare(
            'INSERT INTO short_link_visits (short_link_id, ip_hash, user_agent, referer, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$linkId, $ipHash, $ua, $ref, $now]);
    }

    public function countTotal(int $linkId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM short_link_visits WHERE short_link_id = ?');
        $stmt->execute([$linkId]);
        return (int)$stmt->fetch()['c'];
    }

    /**
     * Unique visitors counted by ip_hash. Anonymous visits (NULL ip_hash —
     * malformed/missing IP) are excluded entirely so callers don't have to
     * choose between "all anon = 1" and "all anon = N" interpretations. The
     * tradeoff is documented: if anti-spoof later strips most XFF values,
     * unique counts shrink — that's intentional.
     */
    public function countUnique(int $linkId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT ip_hash) AS c
             FROM short_link_visits
             WHERE short_link_id = ? AND ip_hash IS NOT NULL'
        );
        $stmt->execute([$linkId]);
        return (int)$stmt->fetch()['c'];
    }

    /**
     * Daily counts for the last $days days, oldest first. The day boundary
     * uses UTC to match how created_at is stored (ISO 8601 with `Z` suffix);
     * mixing local time would shift the window by the server's offset.
     * `unique` here follows the same "NULL ip_hash excluded" rule as
     * countUnique above so the two methods stay consistent.
     *
     * @return array<int, array{date: string, total: int, unique: int}>
     */
    public function countByDay(int $linkId, int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $utc  = new \DateTimeZone('UTC');
        $rangeStart = (new \DateTimeImmutable("-" . ($days - 1) . " days", $utc))
            ->format('Y-m-d\T00:00:00\Z');
        $stmt = $this->pdo->prepare(
            'SELECT substr(created_at, 1, 10) AS d,
                    COUNT(*) AS total,
                    COUNT(DISTINCT ip_hash) AS uniq
             FROM short_link_visits
             WHERE short_link_id = ? AND created_at >= ?
             GROUP BY d'
        );
        $stmt->execute([$linkId, $rangeStart]);
        $byDay = [];
        foreach ($stmt->fetchAll() as $r) {
            $byDay[$r['d']] = ['total' => (int)$r['total'], 'unique' => (int)$r['uniq']];
        }
        $out = [];
        $now = new \DateTimeImmutable('now', $utc);
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = $now->modify("-$i days")->format('Y-m-d');
            $out[] = [
                'date'   => $d,
                'total'  => $byDay[$d]['total']  ?? 0,
                'unique' => $byDay[$d]['unique'] ?? 0,
            ];
        }
        return $out;
    }

    /**
     * Top referer hosts with hit counts. Empty referer is bucketed as "direct".
     * Hosts are extracted in PHP because SQLite has no native URL-parsing
     * function — grouping by raw URL would split twitter.com/post/1 and
     * /post/2 into separate buckets, which defeats the point of the report.
     */
    public function topReferers(int $linkId, int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));
        // Cap the scan at 100k recent rows so a viral link with millions of
        // visits doesn't pull the whole table into PHP memory just to compute
        // a top-5 list. Most-recent visits give a current-trend snapshot.
        $stmt = $this->pdo->prepare(
            'SELECT referer FROM short_link_visits WHERE short_link_id = ? ORDER BY id DESC LIMIT 100000'
        );
        $stmt->execute([$linkId]);
        $counts = [];
        foreach ($stmt->fetchAll() as $r) {
            $ref = (string)($r['referer'] ?? '');
            $host = '';
            if ($ref !== '') {
                $h = parse_url($ref, PHP_URL_HOST);
                $host = is_string($h) ? $h : '';
            }
            $host = $host !== '' ? $host : 'direct';
            $counts[$host] = ($counts[$host] ?? 0) + 1;
        }
        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, $limit, true) as $host => $c) {
            $out[] = ['host' => $host, 'count' => $c];
        }
        return $out;
    }
}
