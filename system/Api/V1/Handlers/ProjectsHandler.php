<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Api\V1\JsonRequest;
use App\Service\RolePolicy;
use App\Http\Request;

final class ProjectsHandler extends BaseHandler
{
    public function index(Request $req, array $params = []): array
    {
        $after = isset($req->query['after']) ? (int)$req->query['after'] : 0;
        $limit = min(100, max(1, (int)($req->query['limit'] ?? 50)));
        $isAdmin = RolePolicy::isAdmin($this->user());

        $projects = $this->svc('projects');
        $members  = $this->svc('members');

        $items = $isAdmin
            ? $this->listAllAfter($projects, $after, $limit + 1)
            : $this->listVisibleAfter($projects, $members, $this->userId(), $after, $limit + 1);

        $next = null;
        if (count($items) > $limit) {
            $items = array_slice($items, 0, $limit);
            $next = (int)end($items)['id'];
        }
        return ApiResponse::paginated($this->serializeMany($items), $next);
    }

    public function show(Request $req, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $project = $this->svc('projects')->findById($id);
        if (!$project) return $this->notFound();
        if (!$this->canSeeProject($project)) return $this->notFound();

        $columns = $this->svc('columns')->listForProject($id);
        $members = $this->svc('members')->list($id);
        return ApiResponse::ok($this->serializeOne($project, $columns, $members));
    }

    public function create(Request $req, array $params = []): array
    {
        if (!RolePolicy::canCreateProject($this->user())) return $this->forbidden();
        $body = $this->readBody($req);
        try { $name = JsonRequest::requireString($body, 'name'); }
        catch (\InvalidArgumentException $e) {
            return ApiResponse::error(422, 'validation_failed', 'name is required', ['name' => 'required']);
        }
        // ProjectRepository::create signature: (name, description, createdBy, color)
        $id = $this->svc('projects')->create(
            $name,
            JsonRequest::optionalString($body, 'description'),
            $this->userId(),
            JsonRequest::optionalString($body, 'color')
        );
        // Mirror web controller: register creator as owner so canEditProject works.
        $this->svc('members')->add($id, $this->userId(), 'owner');
        $project = $this->svc('projects')->findById($id);
        return ApiResponse::created($this->serializeMany([$project])[0]);
    }

    public function update(Request $req, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $project = $this->svc('projects')->findById($id);
        if (!$project) return $this->notFound();
        if (!RolePolicy::canEditProject($this->user(), $project, $this->svc('members'))) return $this->forbidden();
        $body = $this->readBody($req);
        $patch = [];
        foreach (['name', 'color', 'description', 'status'] as $f) {
            $v = JsonRequest::optionalString($body, $f);
            if ($v !== null) $patch[$f] = $v;
        }
        if ($patch) $this->svc('projects')->update($id, $patch);
        return ApiResponse::ok($this->serializeMany([$this->svc('projects')->findById($id)])[0]);
    }

    public function destroy(Request $req, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $project = $this->svc('projects')->findById($id);
        if (!$project) return $this->notFound();
        if (!RolePolicy::isAdmin($this->user())) return $this->forbidden();
        $this->svc('projects')->delete($id);
        return ApiResponse::noContent();
    }

    public function setPin(Request $req, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $project = $this->svc('projects')->findById($id);
        if (!$project) return $this->notFound();
        // Visibility check first — invisible project should look like a 404,
        // not differentiate against the edit-gate 403 below.
        if (!$this->canSeeProject($project)) return $this->notFound();
        // pinned is a project-wide flag (no per-user state), so changing it
        // affects every member's board view. Plain employees on the project
        // should NOT be able to pin/unpin — match the web UI which gates this
        // to admins/managers/owners. canEditProject is the right policy here.
        if (!RolePolicy::canEditProject($this->user(), $project, $this->svc('members'))) {
            return $this->forbidden();
        }
        $pinned = JsonRequest::optionalBool($this->readBody($req), 'pinned', false);
        // ProjectRepository::setPinned signature is (id, pinned) — no per-user pin state.
        $this->svc('projects')->setPinned($id, $pinned);
        return ApiResponse::ok(['id' => $id, 'pinned' => $pinned]);
    }

    public function addMember(Request $req, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $project = $this->svc('projects')->findById($id);
        if (!$project) return $this->notFound();
        if (!RolePolicy::canEditProject($this->user(), $project, $this->svc('members'))) return $this->forbidden();
        try { $userId = JsonRequest::requireInt($this->readBody($req), 'user_id'); }
        catch (\InvalidArgumentException $e) {
            return ApiResponse::error(422, 'validation_failed', 'user_id required', ['user_id' => 'required']);
        }
        $this->svc('members')->add($id, $userId);
        return ApiResponse::created(['project_id' => $id, 'user_id' => $userId]);
    }

    public function removeMember(Request $req, array $params = []): array
    {
        $id     = (int)($params['id'] ?? 0);
        $userId = (int)($params['userId'] ?? 0);
        $project = $this->svc('projects')->findById($id);
        if (!$project) return $this->notFound();
        if (!RolePolicy::canEditProject($this->user(), $project, $this->svc('members'))) return $this->forbidden();
        $this->svc('members')->remove($id, $userId);
        return ApiResponse::noContent();
    }

    /**
     * Cursor-paginated list for admins. Falls back to listForUser + PHP filter
     * when no dedicated cursor SQL exists on the repository yet.
     */
    private function listAllAfter(object $projects, int $after, int $limit): array
    {
        if (method_exists($projects, 'listAfterId')) {
            return $projects->listAfterId($after, $limit);
        }
        // Fallback: full admin listing, then PHP-side cursor + sort by id ASC.
        $all = method_exists($projects, 'listForUser')
            ? $projects->listForUser(0, true)
            : [];
        $rest = array_values(array_filter($all, fn($p) => (int)$p['id'] > $after));
        usort($rest, fn($a, $b) => (int)$a['id'] - (int)$b['id']);
        return array_slice($rest, 0, $limit);
    }

    private function listVisibleAfter(object $projects, object $members, int $userId, int $after, int $limit): array
    {
        if (method_exists($projects, 'listVisibleToUserAfterId')) {
            return $projects->listVisibleToUserAfterId($userId, $after, $limit);
        }
        // Fallback: listForUser(non-admin) already enforces membership join.
        // Creator-only visibility (where caller created a project but isn't a
        // member row) is intentionally not surfaced here — keep it minimal and
        // add a dedicated SQL method in a follow-up if needed.
        $visible = method_exists($projects, 'listForUser')
            ? $projects->listForUser($userId, false)
            : [];
        $byId = [];
        foreach ($visible as $p) $byId[(int)$p['id']] = $p;
        $rest = array_values(array_filter($byId, fn($p) => (int)$p['id'] > $after));
        usort($rest, fn($a, $b) => (int)$a['id'] - (int)$b['id']);
        return array_slice($rest, 0, $limit);
    }

    private function serializeMany(array $rows): array
    {
        return array_map(fn($p) => [
            'id'         => (int)$p['id'],
            'name'       => $p['name'],
            'slug'       => $p['slug'] ?? null,
            'color'      => $p['color'] ?? null,
            'status'     => $p['status'] ?? 'active',
            'pinned'     => !empty($p['pinned_at']),
            'created_at' => $this->isoTime($p['created_at'] ?? null),
            'updated_at' => $this->isoTime($p['updated_at'] ?? null),
        ], $rows);
    }

    private function serializeOne(array $p, array $columns, array $members): array
    {
        $base = $this->serializeMany([$p])[0];
        $base['columns'] = array_map(fn($c) => [
            'id'       => (int)$c['id'],
            'name'     => $c['name'],
            'position' => (int)$c['position'],
        ], $columns);
        $base['members'] = array_map(fn($m) => [
            'user_id' => (int)($m['user_id'] ?? $m['id'] ?? 0),
            'name'    => $m['name'] ?? null,
            'role'    => $m['role'] ?? null,
        ], $members);
        return $base;
    }

    // isoTime() now lives on BaseHandler — used by every handler that emits
    // created_at/updated_at, ensuring a single ISO normalisation source.
}
