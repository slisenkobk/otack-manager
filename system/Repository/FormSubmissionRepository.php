<?php
declare(strict_types=1);
namespace App\Repository;

final class FormSubmissionRepository
{
    public const STATUSES = ['new', 'in_progress', 'rejected', 'done', 'converted_task', 'converted_project'];

    public function __construct(private \PDO $pdo) {}

    public function create(int $formId, array $data, array $footer): int {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO form_submissions (form_id, data_json, footer_json, status, created_at, updated_at)
             VALUES (?, ?, ?, "new", ?, ?)'
        );
        $stmt->execute([
            $formId,
            json_encode($data, JSON_UNESCAPED_UNICODE),
            json_encode($footer, JSON_UNESCAPED_UNICODE),
            $now,
            $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM form_submissions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Filter by form_id and/or status; both optional. */
    public function listAll(?int $formId = null, ?string $status = null): array {
        $sql  = 'SELECT s.*, f.title AS form_title, f.hash AS form_hash
                 FROM form_submissions s
                 INNER JOIN forms f ON f.id = s.form_id
                 WHERE 1=1';
        $args = [];
        if ($formId !== null) { $sql .= ' AND s.form_id = ?'; $args[] = $formId; }
        if ($status !== null && $status !== '') { $sql .= ' AND s.status = ?'; $args[] = $status; }
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
        if (!in_array($kind, ['project', 'task'], true)) return 0;
        $col = $kind === 'project' ? 'converted_project_id' : 'converted_task_id';
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $stmt = $this->pdo->prepare(
            "UPDATE form_submissions
                SET $col = NULL,
                    status = 'new',
                    updated_at = ?
              WHERE $col = ?"
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
}
