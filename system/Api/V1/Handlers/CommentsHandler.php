<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Api\V1\JsonRequest;
use App\Service\RolePolicy;
use App\Http\Request;

final class CommentsHandler extends BaseHandler
{
    /** GET /api/v1/tasks/{id}/comments */
    public function indexForTask(Request $req, array $params = []): array
    {
        $taskId = (int)($params['id'] ?? 0);
        $task = $this->svc('tasks')->findById($taskId);
        if (!$task) return $this->notFound();
        $project = $this->svc('projects')->findById((int)$task['project_id']);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();

        return $this->paginatedList('task', $taskId, $req);
    }

    /** GET /api/v1/projects/{id}/comments */
    public function indexForProject(Request $req, array $params = []): array
    {
        $projectId = (int)($params['id'] ?? 0);
        $project = $this->svc('projects')->findById($projectId);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();

        return $this->paginatedList('project', $projectId, $req);
    }

    /** POST /api/v1/comments */
    public function create(Request $req, array $params = []): array
    {
        $body       = $this->readBody($req);
        $entity     = (string)($body['entity'] ?? '');
        $entityId   = (int)($body['entity_id'] ?? 0);
        $text       = trim((string)($body['body'] ?? ''));
        $parentId   = isset($body['parent_id']) && $body['parent_id'] ? (int)$body['parent_id'] : null;

        if (!in_array($entity, ['task', 'project'], true)) {
            return ApiResponse::error(422, 'validation_failed', 'entity must be "task" or "project"', ['entity' => 'invalid']);
        }
        if ($entityId <= 0) {
            return ApiResponse::error(422, 'validation_failed', 'entity_id is required', ['entity_id' => 'required']);
        }
        if ($text === '') {
            return ApiResponse::error(422, 'validation_failed', 'body is required', ['body' => 'required']);
        }

        // Resolve project for visibility.
        if ($entity === 'task') {
            $task = $this->svc('tasks')->findById($entityId);
            if (!$task) return $this->notFound();
            $project = $this->svc('projects')->findById((int)$task['project_id']);
        } else {
            $project = $this->svc('projects')->findById($entityId);
        }
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();

        // Validate parent_id: must belong to the same entity. Normalize to top-level root.
        if ($parentId !== null) {
            $parent = $this->svc('comments')->findById($parentId);
            if (!$parent
                || (string)$parent['entity_type'] !== $entity
                || (int)$parent['entity_id']      !== $entityId
            ) {
                return ApiResponse::error(422, 'validation_failed', 'invalid parent_id', ['parent_id' => 'invalid']);
            }
            $parentId = (int)($parent['parent_id'] ?: $parent['id']);
        }

        $id = $this->svc('comments')->create($entity, $entityId, $this->userId(), $text, $parentId);
        $created = $this->svc('comments')->findById($id);
        return ApiResponse::created($this->serialize($created));
    }

    /** DELETE /api/v1/comments/{id} */
    public function destroy(Request $req, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $comment = $this->svc('comments')->findById($id);
        if (!$comment) return $this->notFound();

        // Visibility: caller must still be able to see the parent project.
        $entityType = (string)$comment['entity_type'];
        $entityId   = (int)$comment['entity_id'];
        if ($entityType === 'task') {
            $task = $this->svc('tasks')->findById($entityId);
            $project = $task ? $this->svc('projects')->findById((int)$task['project_id']) : null;
        } else {
            $project = $this->svc('projects')->findById($entityId);
        }
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();

        if (!RolePolicy::canDeleteComment($this->user(), $comment)) {
            return $this->forbidden();
        }

        $this->svc('comments')->delete($id);
        return ApiResponse::noContent();
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function paginatedList(string $entity, int $entityId, Request $req): array
    {
        $limit  = min(100, max(1, (int)($req->query['limit'] ?? 50)));
        $after  = isset($req->query['after']) ? (int)$req->query['after'] : 0;

        $all = $this->svc('comments')->listFor($entity, $entityId); // already ASC by created_at
        if ($after > 0) {
            $all = array_values(array_filter($all, fn($c) => (int)$c['id'] > $after));
        }
        $slice = array_slice($all, 0, $limit + 1);
        $next  = null;
        if (count($slice) > $limit) {
            $slice = array_slice($slice, 0, $limit);
            $next  = (int)end($slice)['id'];
        }
        return ApiResponse::paginated(array_map([$this, 'serialize'], $slice), $next);
    }

    /** Body returned as raw Markdown — clients render. */
    private function serialize(array $c): array
    {
        return [
            'id'          => (int)$c['id'],
            'entity_type' => (string)$c['entity_type'],
            'entity_id'   => (int)$c['entity_id'],
            'user_id'     => (int)$c['user_id'],
            'body'        => (string)$c['body'],
            'parent_id'   => $c['parent_id'] !== null ? (int)$c['parent_id'] : null,
            'created_at'  => $c['created_at'] ?? null,
            'author_name' => $c['author_name'] ?? null,
        ];
    }
}
