<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Service\RolePolicy;
use App\Http\Request;

final class TagsHandler extends BaseHandler
{
    /** GET /api/v1/projects/{id}/tags */
    public function indexForProject(Request $req, array $params = []): array
    {
        $projectId = (int)($params['id'] ?? 0);
        $project = $this->svc('projects')->findById($projectId);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();

        $tags = $this->svc('tags')->listForProject($projectId);
        return ApiResponse::ok(['items' => array_map([$this, 'serialize'], $tags)]);
    }

    /** GET /api/v1/tags — admin-only global catalogue. */
    public function indexGlobal(Request $req, array $params = []): array
    {
        if (!RolePolicy::isAdmin($this->user())) return $this->forbidden();
        $tags = $this->svc('tags')->listAll();
        return ApiResponse::ok(['items' => array_map([$this, 'serialize'], $tags)]);
    }

    /** POST /api/v1/tags — admin-only global tag creation. */
    public function createGlobal(Request $req, array $params = []): array
    {
        if (!RolePolicy::isAdmin($this->user())) return $this->forbidden();

        $body = $this->readBody($req);
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') {
            return ApiResponse::error(422, 'validation_failed', 'name is required', ['name' => 'required']);
        }
        $color = isset($body['color']) && $body['color'] !== '' ? (string)$body['color'] : '#8B7C68';
        // Scope defaults to 'task' since both project_tag_map and task_tag_map
        // reference tags by id regardless of scope, and the admin catalogue is
        // mixed. Mirror TagController by accepting an optional scope override.
        $scope = isset($body['scope']) && in_array($body['scope'], ['project', 'task'], true)
            ? (string)$body['scope'] : 'task';

        $id = $this->svc('tags')->create($scope, $name, $color);
        $tag = $this->svc('tags')->findById($id);
        return ApiResponse::created($this->serialize($tag));
    }

    /** POST /api/v1/projects/{id}/tags — attach tag to project. */
    public function attachToProject(Request $req, array $params = []): array
    {
        $projectId = (int)($params['id'] ?? 0);
        $project = $this->svc('projects')->findById($projectId);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();
        if (!RolePolicy::canEditProject($this->user(), $project, $this->svc('members'))) {
            return $this->forbidden();
        }

        $body = $this->readBody($req);
        $tagId = (int)($body['tag_id'] ?? 0);
        if ($tagId <= 0) {
            return ApiResponse::error(422, 'validation_failed', 'tag_id is required', ['tag_id' => 'required']);
        }
        if (!$this->svc('tags')->findById($tagId)) {
            return ApiResponse::error(422, 'validation_failed', 'tag_id is invalid', ['tag_id' => 'invalid']);
        }

        $this->svc('tags')->attachToProject($projectId, $tagId);
        return ApiResponse::created([
            'project_id' => $projectId,
            'tag_id'     => $tagId,
        ]);
    }

    /** DELETE /api/v1/projects/{id}/tags/{tagId} — detach. */
    public function detachFromProject(Request $req, array $params = []): array
    {
        $projectId = (int)($params['id'] ?? 0);
        $tagId     = (int)($params['tagId'] ?? 0);
        $project = $this->svc('projects')->findById($projectId);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();
        if (!RolePolicy::canEditProject($this->user(), $project, $this->svc('members'))) {
            return $this->forbidden();
        }

        $this->svc('tags')->detachFromProject($projectId, $tagId);
        return ApiResponse::noContent();
    }

    /** POST /api/v1/tasks/{id}/tags — attach tag to task. */
    public function attachToTask(Request $req, array $params = []): array
    {
        $taskId = (int)($params['id'] ?? 0);
        $task = $this->svc('tasks')->findById($taskId);
        if (!$task) return $this->notFound();
        $project = $this->svc('projects')->findById((int)$task['project_id']);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();
        if (!RolePolicy::canEditTask($this->user(), $task, $this->svc('members'))) {
            return $this->forbidden();
        }

        $body = $this->readBody($req);
        $tagId = (int)($body['tag_id'] ?? 0);
        if ($tagId <= 0) {
            return ApiResponse::error(422, 'validation_failed', 'tag_id is required', ['tag_id' => 'required']);
        }
        if (!$this->svc('tags')->findById($tagId)) {
            return ApiResponse::error(422, 'validation_failed', 'tag_id is invalid', ['tag_id' => 'invalid']);
        }

        $this->svc('tags')->attachToTask($taskId, $tagId);
        return ApiResponse::created([
            'task_id' => $taskId,
            'tag_id'  => $tagId,
        ]);
    }

    /** DELETE /api/v1/tasks/{id}/tags/{tagId} — detach. */
    public function detachFromTask(Request $req, array $params = []): array
    {
        $taskId = (int)($params['id'] ?? 0);
        $tagId  = (int)($params['tagId'] ?? 0);
        $task = $this->svc('tasks')->findById($taskId);
        if (!$task) return $this->notFound();
        $project = $this->svc('projects')->findById((int)$task['project_id']);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();
        if (!RolePolicy::canEditTask($this->user(), $task, $this->svc('members'))) {
            return $this->forbidden();
        }

        $this->svc('tags')->detachFromTask($taskId, $tagId);
        return ApiResponse::noContent();
    }

    private function serialize(array $t): array
    {
        return [
            'id'    => (int)$t['id'],
            'name'  => (string)$t['name'],
            'color' => $t['color'] ?? null,
            'scope' => $t['scope'] ?? null,
        ];
    }
}
