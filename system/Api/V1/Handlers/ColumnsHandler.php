<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Api\V1\JsonRequest;
use App\Service\RolePolicy;
use App\Http\Request;

final class ColumnsHandler extends BaseHandler
{
    /** GET /api/v1/projects/{id}/columns */
    public function indexForProject(Request $req, array $params = []): array
    {
        $projectId = (int)($params['id'] ?? 0);
        $project = $this->svc('projects')->findById($projectId);
        if (!$project) return $this->notFound();
        if (!$this->canSeeProject($project)) return $this->notFound();

        $columns = $this->svc('columns')->listForProject($projectId);
        return ApiResponse::ok(['items' => array_map([$this, 'serialize'], $columns)]);
    }

    /** POST /api/v1/projects/{id}/columns */
    public function createInProject(Request $req, array $params = []): array
    {
        $projectId = (int)($params['id'] ?? 0);
        $project = $this->svc('projects')->findById($projectId);
        if (!$project) return $this->notFound();
        if (!RolePolicy::canEditProject($this->user(), $project, $this->svc('members'))) {
            return $this->forbidden();
        }

        $body = $this->readBody($req);
        try { $name = JsonRequest::requireString($body, 'name'); }
        catch (\InvalidArgumentException $e) {
            return ApiResponse::error(422, 'validation_failed', 'name is required', ['name' => 'required']);
        }
        if (trim($name) === '') {
            return ApiResponse::error(422, 'validation_failed', 'name is required', ['name' => 'required']);
        }

        $id = $this->svc('columns')->create($projectId, $name);

        // Optional explicit position on create — reorder afterwards so existing
        // sibling positions stay contiguous.
        $position = JsonRequest::optionalInt($body, 'position');
        if ($position !== null) {
            $this->svc('columns')->update($id, ['position' => $position]);
        }

        $col = $this->svc('columns')->findById($id);
        return ApiResponse::created($this->serialize($col));
    }

    /** PATCH /api/v1/columns/{id} */
    public function update(Request $req, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $col = $this->svc('columns')->findById($id);
        if (!$col) return $this->notFound();
        $project = $this->svc('projects')->findById((int)$col['project_id']);
        // 404 (not 403) when the project is invisible to the caller, mirroring
        // TasksHandler/CommentsHandler/AttachmentsHandler — refuses to leak
        // the existence of foreign projects via differential status codes.
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();
        if (!RolePolicy::canEditProject($this->user(), $project, $this->svc('members'))) {
            return $this->forbidden();
        }

        $body = $this->readBody($req);
        $patch = [];
        $name = JsonRequest::optionalString($body, 'name');
        if ($name !== null) {
            if (trim($name) === '') {
                return ApiResponse::error(422, 'validation_failed', 'name cannot be empty', ['name' => 'required']);
            }
            $patch['name'] = $name;
        }
        $position = JsonRequest::optionalInt($body, 'position');
        if ($position !== null) $patch['position'] = $position;
        $color = JsonRequest::optionalString($body, 'color');
        if ($color !== null) $patch['color'] = $color;

        if ($patch) $this->svc('columns')->update($id, $patch);
        return ApiResponse::ok($this->serialize($this->svc('columns')->findById($id)));
    }

    /** DELETE /api/v1/columns/{id} (?force=true to drop tasks too) */
    public function destroy(Request $req, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $col = $this->svc('columns')->findById($id);
        if (!$col) return $this->notFound();
        $project = $this->svc('projects')->findById((int)$col['project_id']);
        // 404 (not 403) when the project is invisible to the caller — see update().
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();
        if (!RolePolicy::canEditProject($this->user(), $project, $this->svc('members'))) {
            return $this->forbidden();
        }

        $count = $this->svc('tasks')->countForColumn($id);
        $force = ($req->query['force'] ?? '') === 'true';
        if ($count > 0 && !$force) {
            return ApiResponse::error(409, 'conflict', 'Column is not empty', ['tasks' => (string)$count]);
        }

        if ($count > 0) {
            $this->svc('columns')->deleteWithTasks($id);
        } else {
            $this->svc('columns')->delete($id);
        }
        return ApiResponse::noContent();
    }

    /** POST /api/v1/projects/{id}/columns/reorder */
    public function reorder(Request $req, array $params = []): array
    {
        $projectId = (int)($params['id'] ?? 0);
        $project = $this->svc('projects')->findById($projectId);
        if (!$project) return $this->notFound();
        if (!RolePolicy::canEditProject($this->user(), $project, $this->svc('members'))) {
            return $this->forbidden();
        }

        $body = $this->readBody($req);
        $order = $body['order'] ?? null;
        if (!is_array($order) || $order === []) {
            return ApiResponse::error(422, 'validation_failed', 'order[] required', ['order' => 'required']);
        }
        $ids = array_values(array_map('intval', $order));

        // Security: refuse ids that don't belong to this project so a caller
        // can't reach into a sibling project's columns from this endpoint.
        $existing = array_column($this->svc('columns')->listForProject($projectId), 'id');
        $existing = array_map('intval', $existing);
        foreach ($ids as $cid) {
            if (!in_array($cid, $existing, true)) {
                return ApiResponse::error(422, 'validation_failed', 'order contains foreign column ids', ['order' => 'foreign_id']);
            }
        }

        $this->svc('columns')->reorder($projectId, $ids);
        $columns = $this->svc('columns')->listForProject($projectId);
        return ApiResponse::ok(['items' => array_map([$this, 'serialize'], $columns)]);
    }

    private function serialize(array $c): array
    {
        return [
            'id'         => (int)$c['id'],
            'project_id' => (int)$c['project_id'],
            'name'       => $c['name'],
            'color'      => $c['color'] ?? null,
            'position'   => (int)$c['position'],
            'is_done'    => !empty($c['is_done']),
            'is_backlog' => !empty($c['is_backlog']),
        ];
    }
}
