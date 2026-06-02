<?php
declare(strict_types=1);
namespace App\Repository;

final class FormSubmissionRepository
{
    public const STATUSES = ['new', 'in_progress', 'rejected', 'done', 'converted_task', 'converted_project'];

    public function __construct(private \PDO $pdo) {}

    public function create(int $formId, array $data, array $footer, ?string $remoteIp = null): int {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO form_submissions (form_id, data_json, footer_json, status, remote_ip, created_at, updated_at)
             VALUES (?, ?, ?, "new", ?, ?, ?)'
        );
        $stmt->execute([
            $formId,
            json_encode($data, JSON_UNESCAPED_UNICODE),
            json_encode($footer, JSON_UNESCAPED_UNICODE),
            $remoteIp,
            $now,
            $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Count submissions from a given IP within the last $windowSeconds.
     * Used by PublicFormController to throttle anonymous spam.
     */
    public function countRecentByIp(string $remoteIp, int $windowSeconds): int {
        $since = (new \DateTimeImmutable('-' . max(1, $windowSeconds) . ' seconds'))
            ->format('Y-m-d\TH:i:s.u\Z');
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM form_submissions WHERE remote_ip = ? AND created_at >= ?'
        );
        $stmt->execute([$remoteIp, $since]);
        return (int)$stmt->fetchColumn();
    }

    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM form_submissions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Filter by form_id, status, and a free-text query. Query searches
     * the submission JSON blob (raw text match) and the parent form title;
     * users mostly remember submissions by what was typed in or which
     * form it belonged to.
     */
    public function listAll(?int $formId = null, ?string $status = null, string $query = ''): array {
        $sql  = 'SELECT s.*, f.title AS form_title, f.hash AS form_hash
                 FROM form_submissions s
                 INNER JOIN forms f ON f.id = s.form_id
                 WHERE 1=1';
        $args = [];
        if ($formId !== null) { $sql .= ' AND s.form_id = ?'; $args[] = $formId; }
        if ($status !== null && $status !== '') { $sql .= ' AND s.status = ?'; $args[] = $status; }
        if ($query !== '') {
            $sql .= ' AND (s.data_json LIKE ? OR f.title LIKE ?)';
            $needle = '%' . $query . '%';
            $args[] = $needle;
            $args[] = $needle;
        }
        $sql .= ' ORDER BY s.created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public function setStatus(int $id, string $status, ?int $taskId = null, ?int $projectId = null): void {
        if (!in_array($status, self::STATUSES, true)) return;
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        if ($taskId !== null || $projectId !== null) {
            $this->pdo->prepare(
                'UPDATE form_submissions SET status = ?, converted_task_id = ?, converted_project_id = ?, updated_at = ? WHERE id = ?'
            )->execute([$status, $taskId, $projectId, $now, $id]);
        } else {
            $this->pdo->prepare('UPDATE form_submissions SET status = ?, updated_at = ? WHERE id = ?')
                ->execute([$status, $now, $id]);
        }
    }

    public function delete(int $id): void {
        $this->pdo->prepare('DELETE FROM form_submissions WHERE id = ?')->execute([$id]);
    }

    /**
     * Detach any submissions converted into the given project/task. Resets
     * their status back to 'new' so the user can re-convert them.
     */
    public function detachConverted(string $kind, int $entityId): int {
        // Hard-coded column literals via match — no $col interpolation, no
        // chance a future $kind value introduces SQL injection.
        [$sqlUpdate, $sqlWhere] = match ($kind) {
            'project' => ['converted_project_id = NULL', 'converted_project_id = ?'],
            'task'    => ['converted_task_id    = NULL', 'converted_task_id    = ?'],
            default   => [null, null],
        };
        if ($sqlUpdate === null) return 0;
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $stmt = $this->pdo->prepare(
            "UPDATE form_submissions
                SET $sqlUpdate, status = 'new', updated_at = ?
              WHERE $sqlWhere"
        );
        $stmt->execute([$now, $entityId]);
        return (int)$stmt->rowCount();
    }

    public function countByStatus(): array {
        $rows = $this->pdo->query('SELECT status, COUNT(*) AS c FROM form_submissions GROUP BY status')->fetchAll();
        $out = [];
        foreach ($rows as $r) { $out[$r['status']] = (int)$r['c']; }
        return $out;
    }

    /**
     * Submissions still awaiting admin attention — everything that hasn't
     * been explicitly closed via 'done' or 'rejected'. Conversion to a
     * task/project is a routing action, not a "handled" verdict: the lead
     * still needs follow-up until the admin marks it done.
     */
    public function countUnhandled(): int {
        $row = $this->pdo->query(
            "SELECT COUNT(*) AS c FROM form_submissions WHERE status NOT IN ('done', 'rejected')"
        )->fetch();
        return (int)($row['c'] ?? 0);
    }
}
