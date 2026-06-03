<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Api\V1\JsonRequest;
use App\Service\RolePolicy;
use App\Http\Request;

abstract class BaseHandler
{
    /** @param array<string, object> $services repositories/services keyed by short name */
    public function __construct(
        protected \PDO $pdo,
        protected array $services,
        protected array $ctx,   // ['user' => ..., 'token' => ...]
    ) {}

    protected function user(): array { return $this->ctx['user']; }
    protected function userId(): int { return (int)$this->ctx['user']['id']; }

    protected function svc(string $name): object
    {
        if (!isset($this->services[$name])) {
            throw new \LogicException("service $name not wired");
        }
        return $this->services[$name];
    }

    protected function readBody(Request $req): array
    {
        // Request is a readonly DTO with no rawBody field; fall back to the
        // PHP input stream. Tests can substitute by injecting a Request
        // subclass that exposes rawBody.
        $raw = property_exists($req, 'rawBody') && $req->rawBody !== null
            ? (string)$req->rawBody
            : (string)(file_get_contents('php://input') ?: '');
        return JsonRequest::parse($raw);
    }

    /** Pull a numeric path id from /api/v1/<resource>/<id>(/...). */
    protected function pathId(Request $req, int $segmentIndex): int
    {
        $parts = explode('/', trim($req->path, '/'));
        return isset($parts[$segmentIndex]) ? (int)$parts[$segmentIndex] : 0;
    }

    protected function notFound(): array { return ApiResponse::error(404, 'not_found', 'Not found'); }
    protected function forbidden(): array { return ApiResponse::error(403, 'forbidden', 'Action not permitted'); }

    /**
     * Visibility helper shared across handlers that gate on project access.
     * Admins see all projects; everyone else needs a membership row or to be
     * the original creator.
     */
    protected function canSeeProject(array $project): bool
    {
        if (RolePolicy::isAdmin($this->user())) return true;
        // ProjectMemberRepository::isMember signature is (projectId, userId).
        return $this->svc('members')->isMember((int)$project['id'], $this->userId())
            || (int)$project['created_by'] === $this->userId();
    }
}
