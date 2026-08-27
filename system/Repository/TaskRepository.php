<?php
declare(strict_types=1);
namespace App\Repository;

final class TaskRepository {
    public function __construct(private \PDO $pdo) {}

    public static function computePosition(?float $prev, ?float $next): float {
        if ($prev === null && $next === null) return 1024.0;
        if ($prev === null) return $next - 1024.0;
        if ($next === null) return $prev + 1024.0;
        return ($prev + $next) / 2.0;
    }

    public function create(
        int $projectId, int $columnId, string $title, int $createdBy,
        ?string $description = null, ?int $assigneeId = null, ?string $dueDate = null
    ): int {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(position), 0) AS m FROM tasks WHERE column_id = ?');
        $stmt->execute([$columnId]);
        $maxPos = (float)$stmt->fetch()['m'];
        $position = $maxPos + 1024.0;
        $now = iso_now_utc();
        $ins = $this->pdo->prepare(
            'INSERT INTO tasks (project_id, column_id, title, description, position, assignee_id, due_date, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$projectId, $columnId, $title, $description, $position, $assigneeId, $dueDate, $createdBy, $now, $now]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function listForColumnPaged(int $columnId, int $limit, int $offset): array {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, u.name AS assignee_name, u.avatar AS assignee_avatar
             FROM tasks t
             LEFT JOIN users u ON u.id = t.assignee_id
             WHERE t.column_id = ?
             ORDER BY t.position ASC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$columnId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Returns ['open' => int, 'total' => int] grouped by project_id for the given ids.
     * Open = tasks in columns where is_done = 0 AND is_backlog = 0.
     *
     * @param list<int> $projectIds
     * @return array<int, array{open:int,total:int}>
     */
    public function countByProject(array $projectIds): array {
        if (!$projectIds) return [];
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT t.project_id,
                    SUM(CASE WHEN c.is_done = 0 AND c.is_backlog = 0 THEN 1 ELSE 0 END) AS open_count,
                    COUNT(*) AS total_count
             FROM tasks t
             INNER JOIN task_columns c ON c.id = t.column_id
             WHERE t.project_id IN ($placeholders)
             GROUP BY t.project_id"
        );
        $stmt->execute($projectIds);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['project_id']] = ['open' => (int)$r['open_count'], 'total' => (int)$r['total_count']];
        }
        return $out;
    }

    /**
     * Per-tab counts for a single project.
     *
     * @return array{board_open:int,backlog:int,done:int}
     */
    public function countsForProject(int $projectId): array {
        $stmt = $this->pdo->prepare(
            "SELECT
                SUM(CASE WHEN c.is_done = 0 AND c.is_backlog = 0 THEN 1 ELSE 0 END) AS board_open,
                SUM(CASE WHEN c.is_backlog = 1 THEN 1 ELSE 0 END)                  AS backlog,
                SUM(CASE WHEN c.is_done = 1 THEN 1 ELSE 0 END)                     AS done
             FROM tasks t
             INNER JOIN task_columns c ON c.id = t.column_id
             WHERE t.project_id = ?"
        );
        $stmt->execute([$projectId]);
        $r = $stmt->fetch() ?: [];
        return [
            'board_open' => (int)($r['board_open'] ?? 0),
            'backlog'    => (int)($r['backlog'] ?? 0),
            'done'       => (int)($r['done'] ?? 0),
        ];
    }

    public function countForColumn(int $columnId): int {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM tasks WHERE column_id = ?');
        $stmt->execute([$columnId]);
        return (int)$stmt->fetch()['c'];
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Cursor-paginated, filterable task list for the external API.
     *
     * Filters (all optional):
     *   - column_id   (int)    exact match
     *   - assignee_id (int)    exact match
     *   - tag_id      (int)    inner join on task_tag_map
     *   - status      (string) 'open' | 'done' | 'backlog' (joins task_columns)
     *   - priority    (string) one of TaskRepository priority enum
     *   - search      (string) case-insensitive LIKE on title
     *
     * Returns the requested page of tasks ordered by id ASC, where the page
     * is "all tasks in this project with id > $afterId". Cap the limit before
     * calling — this method trusts the value.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function listForProjectAfterId(int $projectId, array $filters, int $afterId, int $limit): array {
        $sql = "SELECT t.* FROM tasks t";
        $where = ['t.project_id = ?', 't.id > ?'];
        $args  = [$projectId, $afterId];

        if (isset($filters['tag_id']) && (int)$filters['tag_id'] > 0) {
            $sql .= ' INNER JOIN task_tag_map ttm ON ttm.task_id = t.id';
            $where[] = 'ttm.tag_id = ?';
            $args[] = (int)$filters['tag_id'];
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $sql .= ' INNER JOIN task_columns tc ON tc.id = t.column_id';
            $st = (string)$filters['status'];
            if ($st === 'done') {
                $where[] = 'tc.is_done = 1';
            } elseif ($st === 'backlog') {
                $where[] = 'tc.is_backlog = 1';
            } elseif ($st === 'open') {
                $where[] = 'tc.is_done = 0 AND tc.is_backlog = 0';
            }
        }
        if (isset($filters['column_id']) && (int)$filters['column_id'] > 0) {
            $where[] = 't.column_id = ?';
            $args[] = (int)$filters['column_id'];
        }
        if (isset($filters['assignee_id']) && (int)$filters['assignee_id'] > 0) {
            $where[] = 't.assignee_id = ?';
            $args[] = (int)$filters['assignee_id'];
        }
        if (isset($filters['priority']) && $filters['priority'] !== '') {
            $where[] = 't.priority = ?';
            $args[] = (string)$filters['priority'];
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $where[] = 'LOWER(t.title) LIKE ?';
            $args[] = '%' . mb_strtolower((string)$filters['search']) . '%';
        }

        $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY t.id ASC LIMIT ?';
        $args[] = $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<int, list<array<string,mixed>>> */
    public function listForProject(int $projectId): array {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, u.name AS assignee_name, u.avatar AS assignee_avatar
             FROM tasks t
             LEFT JOIN users u ON u.id = t.assignee_id
             WHERE t.project_id = ?
             ORDER BY t.column_id ASC, t.position ASC'
        );
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll();
        $byCol = [];
        foreach ($rows as $r) {
            $byCol[(int)$r['column_id']][] = $r;
        }
        return $byCol;
    }

    /**
     * Counts of comments + attachments per task in a project.
     * @return array<int, array{comments: int, attachments: int}>
     */
    public function countMetaForProject(int $projectId): array {
        $stmt = $this->pdo->prepare(
            "SELECT t.id,
                    (SELECT COUNT(*) FROM comments WHERE entity_type='task' AND entity_id = t.id) AS comments,
                    (SELECT COUNT(*) FROM attachments WHERE entity_type='task' AND entity_id = t.id) AS attachments,
                    (SELECT COUNT(*) FROM task_links WHERE task_id = t.id OR linked_task_id = t.id) AS links
             FROM tasks t WHERE t.project_id = ?"
        );
        $stmt->execute([$projectId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int)$r['id']] = [
                'comments' => (int)$r['comments'],
                'attachments' => (int)$r['attachments'],
                'links' => (int)$r['links'],
            ];
        }
        return $out;
    }

    public function update(int $id, array $fields): void {
        $allowed = ['title', 'description', 'column_id', 'position', 'assignee_id', 'due_date', 'priority', 'sub_status', 'agent_state', 'agent_state_at'];
        $set = []; $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $set[] = "$k = ?"; $vals[] = $v;
        }
        if (!$set) return;
        $set[] = 'updated_at = ?';
        $vals[] = iso_now_utc();
        $vals[] = $id;
        $this->pdo->prepare('UPDATE tasks SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    }

    public function move(int $id, int $newColumnId, float $newPosition, ?string $subStatus = null, bool $clearSubStatus = false): void {
        $now = iso_now_utc();
        if ($subStatus !== null) {
            $this->pdo->prepare('UPDATE tasks SET column_id = ?, position = ?, sub_status = ?, updated_at = ? WHERE id = ?')
                ->execute([$newColumnId, $newPosition, $subStatus, $now, $id]);
        } elseif ($clearSubStatus) {
            $this->pdo->prepare('UPDATE tasks SET column_id = ?, position = ?, sub_status = NULL, updated_at = ? WHERE id = ?')
                ->execute([$newColumnId, $newPosition, $now, $id]);
        } else {
            $this->pdo->prepare('UPDATE tasks SET column_id = ?, position = ?, updated_at = ? WHERE id = ?')
                ->execute([$newColumnId, $newPosition, $now, $id]);
        }
    }

    public function delete(int $id): void {
        $this->pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
    }

    /**
     * Returns dashboard counters for the visible scope.
     * Admin sees all projects; others see only projects they're members of.
     * Keys: open, backlog, closed, opened_week, closed_week.
     *
     * @return array{open:int,backlog:int,closed:int,opened_week:int,closed_week:int}
     */
    public function dashboardCounters(int $userId, bool $isAdmin): array {
        $weekStart = (new \DateTimeImmutable('monday this week'))->format('Y-m-d\T00:00:00\Z');
        $scopeJoin = '';
        $scopeWhere = '';
        $args = [];
        if (!$isAdmin) {
            $scopeJoin  = ' INNER JOIN project_members pm ON pm.project_id = t.project_id';
            $scopeWhere = ' AND pm.user_id = ?';
            $args[] = $userId;
        }
        $get = function (string $extra) use ($scopeJoin, $scopeWhere, $args) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM tasks t INNER JOIN task_columns c ON c.id = t.column_id" . $scopeJoin . " WHERE 1=1 " . $extra . $scopeWhere);
            $stmt->execute(array_merge([], $args));
            return (int)$stmt->fetchColumn();
        };
        $getArgs = function (string $extra, array $extraArgs) use ($scopeJoin, $scopeWhere, $args) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM tasks t INNER JOIN task_columns c ON c.id = t.column_id" . $scopeJoin . " WHERE 1=1 " . $extra . $scopeWhere);
            $stmt->execute(array_merge($extraArgs, $args));
            return (int)$stmt->fetchColumn();
        };
        return [
            'open'         => $get('AND c.is_done = 0 AND c.is_backlog = 0'),
            'backlog'      => $get('AND c.is_backlog = 1'),
            'closed'       => $get('AND c.is_done = 1'),
            'opened_week'  => $getArgs('AND t.created_at >= ?', [$weekStart]),
            'closed_week'  => $getArgs('AND c.is_done = 1 AND t.updated_at >= ?', [$weekStart]),
        ];
    }

    /**
     * 7-day daily counts for opened/closed tasks (used by the dashboard
     * sparkline bars). Returns ['opened' => [int x 7], 'closed' => [int x 7]],
     * oldest day first. Scope mirrors dashboardCounters().
     *
     * @return array{opened:list<int>,closed:list<int>}
     */
    public function dashboardWeekTrend(int $userId, bool $isAdmin): array {
        $days = [];
        $opened = array_fill(0, 7, 0);
        $closed = array_fill(0, 7, 0);
        $now = new \DateTimeImmutable('now');
        for ($i = 6; $i >= 0; $i--) {
            $d = $now->modify("-$i days")->format('Y-m-d');
            $days[$d] = 6 - $i;
        }
        $rangeStart = (new \DateTimeImmutable('-6 days'))->format('Y-m-d\T00:00:00\Z');

        $scopeJoin = '';
        $scopeWhere = '';
        $scopeArgs = [];
        if (!$isAdmin) {
            $scopeJoin  = ' INNER JOIN project_members pm ON pm.project_id = t.project_id';
            $scopeWhere = ' AND pm.user_id = ?';
            $scopeArgs[] = $userId;
        }

        // Opened: tasks created in the range.
        $stmt = $this->pdo->prepare(
            "SELECT substr(t.created_at, 1, 10) AS d, COUNT(*) AS c
             FROM tasks t" . $scopeJoin . "
             WHERE t.created_at >= ?" . $scopeWhere . "
             GROUP BY d"
        );
        $stmt->execute(array_merge([$rangeStart], $scopeArgs));
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (isset($days[$row['d']])) $opened[$days[$row['d']]] = (int)$row['c'];
        }

        // Closed: tasks whose last update landed in a done column inside the range.
        $stmt = $this->pdo->prepare(
            "SELECT substr(t.updated_at, 1, 10) AS d, COUNT(*) AS c
             FROM tasks t
             INNER JOIN task_columns c ON c.id = t.column_id" . $scopeJoin . "
             WHERE c.is_done = 1 AND t.updated_at >= ?" . $scopeWhere . "
             GROUP BY d"
        );
        $stmt->execute(array_merge([$rangeStart], $scopeArgs));
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (isset($days[$row['d']])) $closed[$days[$row['d']]] = (int)$row['c'];
        }

        return ['opened' => $opened, 'closed' => $closed];
    }

    public function countOpenForAssignee(int $userId): int {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS c FROM tasks t
             INNER JOIN task_columns c ON c.id = t.column_id
             WHERE t.assignee_id = ? AND c.is_done = 0'
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetch()['c'];
    }

    /** @return list<array<string,mixed>> */
    public function listForAssignee(int $userId, int $limit = 6): array {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, p.name AS project_name, c.name AS column_name
             FROM tasks t
             INNER JOIN projects p ON p.id = t.project_id
             INNER JOIN task_columns c ON c.id = t.column_id
             WHERE t.assignee_id = ? AND c.is_done = 0
             ORDER BY t.updated_at DESC LIMIT ?'
        );
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }
}
