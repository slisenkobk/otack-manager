<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Api\V1\JsonRequest;
use App\Service\RolePolicy;
use App\Http\Request;

final class TasksHandler extends BaseHandler
{
    /** GET /api/v1/tasks/{id} */
    public function show(Request $req, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $task = $this->svc('tasks')->findById($id);
        if (!$task) return $this->notFound();
        $project = $this->svc('projects')->findById((int)$task['project_id']);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();
        return ApiResponse::ok($this->serializeOne($task));
    }

    /** GET /api/v1/projects/{id}/tasks */
    public function indexForProject(Request $req, array $params = []): array
    {
        $projectId = (int)($params['id'] ?? 0);
        $project = $this->svc('projects')->findById($projectId);
        if (!$project) return $this->notFound();
        if (!$this->canSeeProject($project)) return $this->notFound();

        $after = isset($req->query['after']) ? (int)$req->query['after'] : 0;
        $limit = min(100, max(1, (int)($req->query['limit'] ?? 50)));

        $filters = [];
        foreach (['column_id', 'assignee_id', 'tag_id'] as $k) {
            if (isset($req->query[$k]) && $req->query[$k] !== '') {
                $filters[$k] = (int)$req->query[$k];
            }
        }
        foreach (['status', 'priority', 'search'] as $k) {
            if (isset($req->query[$k]) && $req->query[$k] !== '') {
                $filters[$k] = (string)$req->query[$k];
            }
        }

        $items = $this->svc('tasks')->listForProjectAfterId($projectId, $filters, $after, $limit + 1);
        $next = null;
        if (count($items) > $limit) {
            $items = array_slice($items, 0, $limit);
            $next = (int)end($items)['id'];
        }
        return ApiResponse::paginated(array_map([$this, 'serializeListItem'], $items), $next);
    }

    /** POST /api/v1/projects/{id}/tasks */
    public function createInProject(Request $req, array $params = []): array
    {
        $projectId = (int)($params['id'] ?? 0);
        $project = $this->svc('projects')->findById($projectId);
        if (!$project) return $this->notFound();
        if (!$this->canSeeProject($project)) return $this->notFound();
        // canEditTask is per-task, but for creation we mirror the controller —
        // member of the project is sufficient (employees can create tasks).
        if (!$this->isMemberOrAdmin($projectId)) return $this->forbidden();

        $body = $this->readBody($req);
        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            return ApiResponse::error(422, 'validation_failed', 'title is required', ['title' => 'required']);
        }

        // Default column = first non-backlog column by position (the "To Do").
        $columnId = JsonRequest::optionalInt($body, 'column_id');
        $cols = $this->svc('columns')->listForProject($projectId);
        if (!$cols) {
            return ApiResponse::error(422, 'validation_failed', 'project has no columns', ['column_id' => 'required']);
        }
        $byId = [];
        foreach ($cols as $c) { $byId[(int)$c['id']] = $c; }
        if ($columnId !== null) {
            if (!isset($byId[$columnId])) {
                return ApiResponse::error(422, 'validation_failed', 'column_id does not belong to project', ['column_id' => 'invalid']);
            }
        } else {
            $board = array_values(array_filter($cols, fn($c) => (int)($c['is_backlog'] ?? 0) === 0));
            usort($board, fn($a, $b) => (int)$a['position'] <=> (int)$b['position']);
            $columnId = $board ? (int)$board[0]['id'] : (int)$cols[0]['id'];
        }

        $assigneeId = JsonRequest::optionalInt($body, 'assignee_id');
        $description = JsonRequest::optionalString($body, 'description');
        $priority = JsonRequest::optionalString($body, 'priority');
        $subStatus = JsonRequest::optionalString($body, 'sub_status');

        $id = $this->svc('tasks')->create($projectId, $columnId, $title, $this->userId(), $description, $assigneeId);

        $patch = [];
        if ($priority !== null && $priority !== '') {
            $allowed = ['none', 'low', 'medium', 'high', 'urgent'];
            $patch['priority'] = in_array($priority, $allowed, true) ? $priority : 'none';
        }
        if ($subStatus !== null && $subStatus !== '') {
            $patch['sub_status'] = $subStatus;
        }
        if ($patch) $this->svc('tasks')->update($id, $patch);

        // Optional tag attachments.
        if (isset($body['tag_ids']) && is_array($body['tag_ids'])) {
            foreach ($body['tag_ids'] as $tagId) {
                $tagId = (int)$tagId;
                if ($tagId > 0) $this->svc('tags')->attachToTask($id, $tagId);
            }
        }

        $task = $this->svc('tasks')->findById($id);
        return ApiResponse::created($this->serializeOne($task));
    }

    /** PATCH /api/v1/tasks/{id} */
    public function update(Request $req, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $task = $this->svc('tasks')->findById($id);
        if (!$task) return $this->notFound();
        $project = $this->svc('projects')->findById((int)$task['project_id']);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();
        if (!RolePolicy::canEditTask($this->user(), $task, $this->svc('members'))) {
            return $this->forbidden();
        }

        $body = $this->readBody($req);
        $fields = [];
        if (isset($body['title'])) {
            $title = trim((string)$body['title']);
            if ($title === '') {
                return ApiResponse::error(422, 'validation_failed', 'title cannot be empty', ['title' => 'required']);
            }
            $fields['title'] = $title;
        }
        if (array_key_exists('description', $body)) {
            $fields['description'] = $body['description'] === '' || $body['description'] === null
                ? null
                : (string)$body['description'];
        }
        if (isset($body['column_id'])) {
            $newColumnId = (int)$body['column_id'];
            $cols = $this->svc('columns')->listForProject((int)$task['project_id']);
            $byId = [];
            foreach ($cols as $c) { $byId[(int)$c['id']] = $c; }
            if (!isset($byId[$newColumnId])) {
                return ApiResponse::error(422, 'validation_failed', 'column_id does not belong to project', ['column_id' => 'invalid']);
            }
            $fields['column_id'] = $newColumnId;
        }
        if (array_key_exists('assignee_id', $body)) {
            $fields['assignee_id'] = $body['assignee_id'] === null || $body['assignee_id'] === ''
                ? null : (int)$body['assignee_id'];
        }
        if (array_key_exists('due_date', $body)) {
            $fields['due_date'] = $body['due_date'] === '' ? null : $body['due_date'];
        }
        if (array_key_exists('priority', $body)) {
            $p = (string)$body['priority'];
            $allowed = ['none', 'low', 'medium', 'high', 'urgent'];
            $fields['priority'] = in_array($p, $allowed, true) ? $p : 'none';
        }
        if (array_key_exists('sub_status', $body)) {
            $fields['sub_status'] = $body['sub_status'] === '' || $body['sub_status'] === null
                ? null : (string)$body['sub_status'];
        }

        if ($fields) $this->svc('tasks')->update($id, $fields);
        return ApiResponse::ok($this->serializeOne($this->svc('tasks')->findById($id)));
    }

    /** POST /api/v1/tasks/{id}/move */
    public function move(Request $req, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $task = $this->svc('tasks')->findById($id);
        if (!$task) return $this->notFound();
        $project = $this->svc('projects')->findById((int)$task['project_id']);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();
        if (!RolePolicy::canEditTask($this->user(), $task, $this->svc('members'))) {
            return $this->forbidden();
        }

        $body = $this->readBody($req);
        $columnId = (int)($body['column_id'] ?? 0);
        $position = (float)($body['position'] ?? 0);
        if ($columnId <= 0) {
            return ApiResponse::error(422, 'validation_failed', 'column_id is required', ['column_id' => 'required']);
        }
        // Validate target column belongs to this task's project.
        $cols = $this->svc('columns')->listForProject((int)$task['project_id']);
        $byId = [];
        foreach ($cols as $c) { $byId[(int)$c['id']] = $c; }
        if (!isset($byId[$columnId])) {
            return ApiResponse::error(422, 'validation_failed', 'column_id does not belong to project', ['column_id' => 'invalid']);
        }

        $this->svc('tasks')->move($id, $columnId, $position);
        return ApiResponse::ok($this->serializeOne($this->svc('tasks')->findById($id)));
    }

    /** DELETE /api/v1/tasks/{id} */
    public function destroy(Request $req, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $task = $this->svc('tasks')->findById($id);
        if (!$task) return $this->notFound();
        $project = $this->svc('projects')->findById((int)$task['project_id']);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();
        if (!RolePolicy::canEditTask($this->user(), $task, $this->svc('members'))) {
            return $this->forbidden();
        }

        $this->svc('tasks')->delete($id);
        // Mirror controller cleanup of dangling FKs.
        $this->svc('task_links')->deleteForTask($id);
        return ApiResponse::noContent();
    }

    /** POST /api/v1/tasks/{id}/promote-to-project */
    public function promoteToProject(Request $req, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $task = $this->svc('tasks')->findById($id);
        if (!$task) return $this->notFound();
        $project = $this->svc('projects')->findById((int)$task['project_id']);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();
        if (!RolePolicy::canEditTask($this->user(), $task, $this->svc('members'))) {
            return $this->forbidden();
        }
        if (!RolePolicy::canPromoteTaskToProject($this->user())) return $this->forbidden();

        $name = trim((string)$task['title']);
        if ($name === '') {
            return ApiResponse::error(422, 'validation_failed', 'task has no title', ['title' => 'required']);
        }
        $description = (string)($task['description'] ?? '');

        $newId = $this->svc('projects')->create(
            $name,
            $description !== '' ? $description : null,
            $this->userId()
        );
        $this->svc('members')->add($newId, $this->userId(), 'owner');
        $this->svc('columns')->seedDefaults($newId);

        // Duplicate attachments (filename paths shared, no file copy).
        $attachments = $this->svc('attachments')->listFor('task', $id);
        foreach ($attachments as $a) {
            $this->svc('attachments')->create([
                'entity_type'   => 'project',
                'entity_id'     => $newId,
                'filename'      => $a['filename'],
                'original_name' => $a['original_name'],
                'mime'          => $a['mime'],
                'size'          => (int)$a['size'],
                'is_image'      => (int)$a['is_image'] === 1,
                'uploaded_by'   => $this->userId(),
            ]);
        }

        // Copy tags into the project scope.
        $tagsRepo = $this->svc('tags');
        foreach ($tagsRepo->listForTask($id) as $tg) {
            $projTagId = $tagsRepo->create('project', (string)$tg['name'], (string)($tg['color'] ?? '#8B7C68'));
            $tagsRepo->attachToProject($newId, $projTagId);
        }

        return ApiResponse::ok([
            'project_id' => $newId,
            'url'        => '/projects/' . $newId . '?tab=overview',
        ]);
    }

    /** POST /api/v1/tasks/{id}/links */
    public function link(Request $req, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $task = $this->svc('tasks')->findById($id);
        if (!$task) return $this->notFound();
        $project = $this->svc('projects')->findById((int)$task['project_id']);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();
        if (!RolePolicy::canEditTask($this->user(), $task, $this->svc('members'))) {
            return $this->forbidden();
        }

        $body = $this->readBody($req);
        $otherId = (int)($body['other_id'] ?? 0);
        if ($otherId <= 0 || $otherId === $id) {
            return ApiResponse::error(422, 'validation_failed', 'other_id is required', ['other_id' => 'required']);
        }
        $other = $this->svc('tasks')->findById($otherId);
        if (!$other) return $this->notFound();
        $otherProject = $this->svc('projects')->findById((int)$other['project_id']);
        if (!$otherProject || !$this->canSeeProject($otherProject)) return $this->notFound();

        $created = $this->svc('task_links')->link($id, $otherId, $this->userId());
        return ApiResponse::created([
            'task_id'  => $id,
            'other_id' => $otherId,
            'created'  => $created,
        ]);
    }

    /** DELETE /api/v1/tasks/{id}/links/{otherId} */
    public function unlink(Request $req, array $params = []): array
    {
        $id      = (int)($params['id'] ?? 0);
        $otherId = (int)($params['otherId'] ?? 0);
        $task = $this->svc('tasks')->findById($id);
        if (!$task) return $this->notFound();
        $project = $this->svc('projects')->findById((int)$task['project_id']);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();
        if (!RolePolicy::canEditTask($this->user(), $task, $this->svc('members'))) {
            return $this->forbidden();
        }
        $this->svc('task_links')->unlink($id, $otherId);
        return ApiResponse::noContent();
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function isMemberOrAdmin(int $projectId): bool
    {
        if (RolePolicy::isAdmin($this->user())) return true;
        return $this->svc('members')->isMember($projectId, $this->userId());
    }

    /** Lighter shape for list endpoints — drop counts/tags/links for payload size. */
    private function serializeListItem(array $t): array
    {
        return [
            'id'          => (int)$t['id'],
            'project_id'  => (int)$t['project_id'],
            'column_id'   => (int)$t['column_id'],
            'title'       => $t['title'],
            'position'    => (float)$t['position'],
            'priority'    => $t['priority'] ?? 'none',
            'assignee_id' => $t['assignee_id'] !== null ? (int)$t['assignee_id'] : null,
            'due_date'    => $t['due_date'] ?? null,
            'sub_status'  => $t['sub_status'] ?? null,
            // ISO-normalise so list shape matches ProjectsHandler / the
            // detail shape and clients don't have to handle raw DB strings
            // (which differ between SQLite text and MySQL DATETIME).
            'created_at'  => $this->isoTime($t['created_at'] ?? null),
            'updated_at'  => $this->isoTime($t['updated_at'] ?? null),
        ];
    }

    /** Rich shape for single-task GET / write responses. */
    private function serializeOne(array $t): array
    {
        $id = (int)$t['id'];
        $base = $this->serializeListItem($t);
        $base['description'] = $t['description'] ?? null;
        $base['created_by']  = isset($t['created_by']) ? (int)$t['created_by'] : null;

        // Counts via direct PDO — cheap and avoids leaning on optional repo methods.
        $base['comments_count']    = $this->countFor('comments', 'task', $id);
        $base['attachments_count'] = $this->countFor('attachments', 'task', $id);

        // Tags.
        $tags = $this->svc('tags')->listForTask($id);
        $base['tags'] = array_map(fn($g) => [
            'id'    => (int)$g['id'],
            'name'  => (string)$g['name'],
            'color' => $g['color'] ?? null,
        ], $tags);

        // Linked task ids (other-side only).
        $base['links'] = array_values(array_map('intval', $this->svc('task_links')->linkedIds($id)));

        return $base;
    }

    private function countFor(string $table, string $entityType, int $entityId): int
    {
        // SQL identifier interpolation is gated by a static allowlist so a
        // misuse of this helper (or a future refactor that takes the table
        // name from input) cannot turn into a SQL-injection sink. The two
        // permitted tables match the schema for `entity_type` + `entity_id`
        // polymorphic associations.
        static $allowed = ['comments' => true, 'attachments' => true];
        if (!isset($allowed[$table])) {
            throw new \LogicException("countFor: table not in allowlist: $table");
        }
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM $table WHERE entity_type = ? AND entity_id = ?"
        );
        $stmt->execute([$entityType, $entityId]);
        return (int)$stmt->fetchColumn();
    }
}
