<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Service\RolePolicy;
use App\Http\Request;

final class ProjectsHandler extends BaseHandler
{
    public function index(Request $req): array
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

    public function show(Request $req): array
    {
        // Segment index 3 because /api/v1/projects/{id} → ['api','v1','projects','{id}']
        $id = $this->pathId($req, 3);
        $project = $this->svc('projects')->findById($id);
        if (!$project) return $this->notFound();
        if (!$this->canSee($project)) return $this->notFound();

        $columns = $this->svc('columns')->listForProject($id);
        $members = $this->svc('members')->list($id);
        return ApiResponse::ok($this->serializeOne($project, $columns, $members));
    }

    private function canSee(array $project): bool
    {
        if (RolePolicy::isAdmin($this->user())) return true;
        $members = $this->svc('members');
        // ProjectMemberRepository::isMember signature is (projectId, userId).
        return $members->isMember((int)$project['id'], $this->userId())
            || (int)$project['created_by'] === $this->userId();
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

    private function isoTime($v): ?string
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return gmdate('Y-m-d\TH:i:s\Z', (int)$v);
        try { return (new \DateTimeImmutable((string)$v))->format('Y-m-d\TH:i:s\Z'); }
        catch (\Throwable $_) { return (string)$v; }
    }
}
