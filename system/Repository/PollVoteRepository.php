<?php
declare(strict_types=1);
namespace App\Repository;

final class PollVoteRepository
{
    public function __construct(private \PDO $pdo) {}

    /**
     * Record a vote. The UNIQUE(poll_id, contact_normalized) index is the
     * hard dedup; we surface the collision as a clean boolean instead of
     * propagating PDO's SQLSTATE 23000 to the controller.
     *
     * Returns ['ok' => bool, 'reason' => 'duplicate'|null].
     */
    public function record(
        int $pollId,
        string $contact,
        string $contactNormalized,
        string $choiceKey,
        ?string $remoteIp = null,
        ?string $userAgent = null
    ): array {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO poll_votes (poll_id, contact, contact_normalized, choice_key, remote_ip, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$pollId, $contact, $contactNormalized, $choiceKey, $remoteIp, $userAgent, $now]);
            return ['ok' => true, 'reason' => null];
        } catch (\PDOException $e) {
            // SQLite: "UNIQUE constraint failed". MySQL would return 1062.
            if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'UNIQUE')) {
                return ['ok' => false, 'reason' => 'duplicate'];
            }
            throw $e;
        }
    }

    public function hasVoted(int $pollId, string $contactNormalized): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM poll_votes WHERE poll_id = ? AND contact_normalized = ? LIMIT 1'
        );
        $stmt->execute([$pollId, $contactNormalized]);
        return (bool)$stmt->fetchColumn();
    }

    public function countTotal(int $pollId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM poll_votes WHERE poll_id = ?');
        $stmt->execute([$pollId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Aggregate vote count per choice_key, ordered by count desc. The caller
     * joins this against the poll's fields_json to get human-readable labels
     * — the repo deliberately doesn't know about field schema.
     */
    public function tallyByChoice(int $pollId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT choice_key, COUNT(*) AS count
             FROM poll_votes
             WHERE poll_id = ?
             GROUP BY choice_key
             ORDER BY count DESC, choice_key ASC'
        );
        $stmt->execute([$pollId]);
        return $stmt->fetchAll();
    }

    /**
     * Paginated list for the Voters tab. Newest first. Page size capped at 200.
     */
    public function listVoters(int $pollId, int $page = 1, int $perPage = 50): array
    {
        $perPage = max(1, min(200, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            'SELECT id, contact, choice_key, remote_ip, created_at
             FROM poll_votes
             WHERE poll_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $pollId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset,  \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
