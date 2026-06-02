<?php
declare(strict_types=1);
namespace App\Repository;

final class PollRepository
{
    public const STATUS_DRAFT  = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED = 'closed';

    public const CONTACT_EMAIL = 'email';
    public const CONTACT_PHONE = 'phone';

    public function __construct(private \PDO $pdo) {}

    public static function generateHash(int $len = 12): string
    {
        return substr(rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='), 0, $len);
    }

    /**
     * Normalize a contact for dedup. Email → trim+lower. Phone → digits only.
     * Returns '' for unsupported / empty contacts so callers can short-circuit.
     */
    public static function normalizeContact(string $contact, string $kind): string
    {
        $contact = trim($contact);
        if ($contact === '') return '';
        if ($kind === self::CONTACT_PHONE) {
            $digits = preg_replace('/\D+/', '', $contact) ?? '';
            return $digits;
        }
        return mb_strtolower($contact);
    }

    /**
     * Build the two preset fields a new poll always starts with: a contact
     * input (email or phone) followed by a single radio "Choice" with two
     * blank options. The form-builder JS then lets the admin rename labels
     * and add more options while in draft.
     */
    public static function defaultFields(string $contactKind = self::CONTACT_EMAIL): array
    {
        $contactType = $contactKind === self::CONTACT_PHONE ? 'tel' : 'email';
        return [
            [
                'key'      => 'contact',
                'type'     => $contactType,
                'label'    => $contactKind === self::CONTACT_PHONE ? 'Phone' : 'Email',
                'required' => true,
                'locked'   => true,
            ],
            [
                'key'      => 'choice',
                'type'     => 'radio',
                'label'    => 'Choose one',
                'required' => true,
                'locked'   => true,
                'options'  => [
                    ['key' => 'opt_1', 'label' => 'Option 1'],
                    ['key' => 'opt_2', 'label' => 'Option 2'],
                ],
            ],
        ];
    }

    public function create(
        string $title,
        ?string $description,
        array $fields,
        array $footer,
        int $createdBy,
        string $locale = 'en',
        string $contactField = self::CONTACT_EMAIL,
        ?int $projectId = null,
        ?string $successMessage = null
    ): int {
        $now  = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $hash = self::generateHash();
        for ($i = 0; $i < 5; $i++) {
            if (!$this->findByHash($hash)) break;
            $hash = self::generateHash();
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO polls (hash, title, description, fields_json, footer_json, contact_field, locale, success_message, status, project_id, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "' . self::STATUS_DRAFT . '", ?, ?, ?, ?)'
        );
        $stmt->execute([
            $hash,
            $title,
            $description,
            json_encode($fields, JSON_UNESCAPED_UNICODE),
            json_encode($footer, JSON_UNESCAPED_UNICODE),
            $contactField,
            $locale,
            $successMessage,
            $projectId,
            $createdBy,
            $now,
            $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function rotateHash(int $id): string
    {
        $hash = self::generateHash();
        for ($i = 0; $i < 5; $i++) {
            if (!$this->findByHash($hash)) break;
            $hash = self::generateHash();
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $this->pdo->prepare('UPDATE polls SET hash = ?, updated_at = ? WHERE id = ?')
            ->execute([$hash, $now, $id]);
        return $hash;
    }

    /**
     * Mutate non-status fields. Callers are expected to gate this on status=draft
     * — the lock-while-active rule lives in the controller, not the repo, so that
     * tests can exercise edits without going through HTTP.
     */
    public function update(int $id, array $fields): void
    {
        $allowed = [
            'title' => true, 'description' => true, 'fields_json' => true,
            'footer_json' => true, 'contact_field' => true, 'locale' => true,
            'success_message' => true, 'project_id' => true,
        ];
        $set = []; $vals = [];
        foreach ($fields as $k => $v) {
            if (!isset($allowed[$k])) continue;
            $set[] = "$k = ?"; $vals[] = $v;
        }
        if (!$set) return;
        $set[] = 'updated_at = ?';
        $vals[] = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $vals[] = $id;
        $this->pdo->prepare('UPDATE polls SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    }

    /**
     * State transitions are strictly one-way: draft → active → closed.
     * Returns true if the transition happened, false if rejected.
     */
    public function activate(int $id): bool
    {
        $row = $this->findById($id);
        if (!$row || $row['status'] !== self::STATUS_DRAFT) return false;
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $this->pdo->prepare('UPDATE polls SET status = ?, activated_at = ?, updated_at = ? WHERE id = ?')
            ->execute([self::STATUS_ACTIVE, $now, $now, $id]);
        return true;
    }

    public function close(int $id): bool
    {
        $row = $this->findById($id);
        if (!$row || $row['status'] !== self::STATUS_ACTIVE) return false;
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $this->pdo->prepare('UPDATE polls SET status = ?, closed_at = ?, updated_at = ? WHERE id = ?')
            ->execute([self::STATUS_CLOSED, $now, $now, $id]);
        return true;
    }

    public function attachSummaryTask(int $id, int $taskId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $this->pdo->prepare('UPDATE polls SET summary_task_id = ?, updated_at = ? WHERE id = ?')
            ->execute([$taskId, $now, $id]);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM polls WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM polls WHERE hash = ?');
        $stmt->execute([$hash]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Manager sees own polls only; admin sees everything. Optional status
     * filter ('draft' | 'active' | 'closed') and free-text query against
     * title/description.
     */
    public function listForUser(int $userId, bool $isAdmin, ?string $status = null, string $query = ''): array
    {
        $where = []; $args = [];
        if (!$isAdmin) { $where[] = 'created_by = ?'; $args[] = $userId; }
        if ($status !== null && $status !== '') { $where[] = 'status = ?'; $args[] = $status; }
        if ($query !== '') {
            $where[] = '(LOWER(title) LIKE ? OR LOWER(description) LIKE ?)';
            $needle = '%' . mb_strtolower($query) . '%';
            $args[] = $needle; $args[] = $needle;
        }
        $sql = 'SELECT * FROM polls' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public function delete(int $id): void
    {
        // Votes cascade via FK ON DELETE CASCADE.
        $this->pdo->prepare('DELETE FROM polls WHERE id = ?')->execute([$id]);
    }
}
