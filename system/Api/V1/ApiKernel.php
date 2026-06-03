<?php
declare(strict_types=1);
namespace App\Api\V1;

use App\Http\Request;

final class ApiKernel
{
    /** @var array<string, array{handler:string, action:string}> "METHOD PATTERN" => handler */
    private array $routes = [];

    public function __construct(
        private TokenAuthenticator $auth,
        private RateLimiter $limiter,
        private \App\Repository\ApiTokenRepository $tokens,
        private \App\Repository\ActivityLogRepository $activity,
        private \PDO $pdo,
        private array $services,   // ['projects' => ProjectRepository, ...] injected
    ) {
        $this->register();
    }

    private function register(): void
    {
        // Stub for Task 7; later tasks expand the table.
        $this->routes['GET /api/v1/ping'] = ['handler' => 'Ping', 'action' => 'ping'];
        $this->routes['GET /api/v1/me']   = ['handler' => 'Me',   'action' => 'show'];

        $this->routes['GET /api/v1/projects']      = ['handler' => 'Projects', 'action' => 'index'];
        $this->routes['GET /api/v1/projects/{id}'] = ['handler' => 'Projects', 'action' => 'show'];
        $this->routes['POST /api/v1/projects']                       = ['handler' => 'Projects', 'action' => 'create'];
        $this->routes['PATCH /api/v1/projects/{id}']                 = ['handler' => 'Projects', 'action' => 'update'];
        $this->routes['DELETE /api/v1/projects/{id}']                = ['handler' => 'Projects', 'action' => 'destroy'];
        $this->routes['POST /api/v1/projects/{id}/pin']              = ['handler' => 'Projects', 'action' => 'setPin'];
        $this->routes['POST /api/v1/projects/{id}/members']          = ['handler' => 'Projects', 'action' => 'addMember'];
        $this->routes['DELETE /api/v1/projects/{id}/members/{id}']   = ['handler' => 'Projects', 'action' => 'removeMember'];

        $this->routes['GET /api/v1/projects/{id}/columns']           = ['handler' => 'Columns', 'action' => 'indexForProject'];
        $this->routes['POST /api/v1/projects/{id}/columns']          = ['handler' => 'Columns', 'action' => 'createInProject'];
        $this->routes['PATCH /api/v1/columns/{id}']                  = ['handler' => 'Columns', 'action' => 'update'];
        $this->routes['DELETE /api/v1/columns/{id}']                 = ['handler' => 'Columns', 'action' => 'destroy'];
        $this->routes['POST /api/v1/projects/{id}/columns/reorder']  = ['handler' => 'Columns', 'action' => 'reorder'];

        $this->routes['GET /api/v1/tasks/{id}']                          = ['handler' => 'Tasks', 'action' => 'show'];
        $this->routes['GET /api/v1/projects/{id}/tasks']                 = ['handler' => 'Tasks', 'action' => 'indexForProject'];
        $this->routes['POST /api/v1/projects/{id}/tasks']                = ['handler' => 'Tasks', 'action' => 'createInProject'];
        $this->routes['PATCH /api/v1/tasks/{id}']                        = ['handler' => 'Tasks', 'action' => 'update'];
        $this->routes['POST /api/v1/tasks/{id}/move']                    = ['handler' => 'Tasks', 'action' => 'move'];
        $this->routes['DELETE /api/v1/tasks/{id}']                       = ['handler' => 'Tasks', 'action' => 'destroy'];
        $this->routes['POST /api/v1/tasks/{id}/promote-to-project']      = ['handler' => 'Tasks', 'action' => 'promoteToProject'];
        $this->routes['POST /api/v1/tasks/{id}/links']                   = ['handler' => 'Tasks', 'action' => 'link'];
        $this->routes['DELETE /api/v1/tasks/{id}/links/{id}']            = ['handler' => 'Tasks', 'action' => 'unlink'];

        $this->routes['GET /api/v1/tasks/{id}/comments']    = ['handler' => 'Comments', 'action' => 'indexForTask'];
        $this->routes['GET /api/v1/projects/{id}/comments'] = ['handler' => 'Comments', 'action' => 'indexForProject'];
        $this->routes['POST /api/v1/comments']              = ['handler' => 'Comments', 'action' => 'create'];
        $this->routes['DELETE /api/v1/comments/{id}']       = ['handler' => 'Comments', 'action' => 'destroy'];

        $this->routes['GET /api/v1/projects/{id}/tags']                  = ['handler' => 'Tags', 'action' => 'indexForProject'];
        $this->routes['GET /api/v1/tags']                                = ['handler' => 'Tags', 'action' => 'indexGlobal'];
        $this->routes['POST /api/v1/tags']                               = ['handler' => 'Tags', 'action' => 'createGlobal'];
        $this->routes['POST /api/v1/projects/{id}/tags']                 = ['handler' => 'Tags', 'action' => 'attachToProject'];
        $this->routes['DELETE /api/v1/projects/{id}/tags/{id}']          = ['handler' => 'Tags', 'action' => 'detachFromProject'];
        $this->routes['POST /api/v1/tasks/{id}/tags']                    = ['handler' => 'Tags', 'action' => 'attachToTask'];
        $this->routes['DELETE /api/v1/tasks/{id}/tags/{id}']             = ['handler' => 'Tags', 'action' => 'detachFromTask'];

        $this->routes['GET /api/v1/tasks/{id}/attachments']     = ['handler' => 'Attachments', 'action' => 'indexForTask'];
        $this->routes['GET /api/v1/projects/{id}/attachments']  = ['handler' => 'Attachments', 'action' => 'indexForProject'];
        $this->routes['POST /api/v1/attachments']               = ['handler' => 'Attachments', 'action' => 'create'];
        $this->routes['DELETE /api/v1/attachments/{id}']        = ['handler' => 'Attachments', 'action' => 'destroy'];
    }

    public function handle(Request $req): void
    {
        try {
            // API clients should never get a session cookie. session_start already
            // ran in index.php; abort it before sending any headers so no session
            // file is persisted for this caller, and strip the Set-Cookie header
            // that session_start() already queued onto the response.
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_abort();
            }
            if (!headers_sent()) {
                header_remove('Set-Cookie');
            }

            // Public schema endpoint — no auth, no rate limit.
            if ($req->method === 'GET' && $req->path === '/api/v1/openapi.yaml') {
                $this->serveOpenApi();
                return;
            }

            $ctx = $this->auth->authenticate($req->header('authorization'));
            if (!$ctx) {
                $this->send(ApiResponse::error(401, 'unauthorized', 'Missing or invalid API token'));
                return;
            }

            $rl = $this->limiter->check((int)$ctx['token']['id']);
            if (!$rl['allowed']) {
                $resp = ApiResponse::error(429, 'rate_limited', 'Too many requests');
                $resp['headers']['Retry-After'] = (string)$rl['retry_after'];
                $this->send($resp);
                return;
            }

            $this->tokens->touchUsage((int)$ctx['token']['id'], (string)($_SERVER['REMOTE_ADDR'] ?? ''));

            $key = $req->method . ' ' . $this->normalisePath($req->path);
            $match = $this->routes[$key] ?? null;
            if (!$match) {
                $this->send(ApiResponse::error(404, 'not_found', 'Route not found'));
                return;
            }

            try {
                $resp = $this->dispatch($match, $req, $ctx);
            } catch (\InvalidArgumentException $e) {
                $resp = ApiResponse::error(422, 'validation_failed', 'Invalid input', [$e->getMessage() => 'required_or_invalid']);
            } catch (\Throwable $e) {
                error_log('[api] ' . $e);
                $resp = ApiResponse::error(500, 'server_error', 'Internal error');
            }

            // ActivityLogRepository::log signature is:
            //   log(string $event, int $actorId, ?int $projectId, ?int $taskId, string $summary, array $meta = [])
            $eventName = 'api.' . strtolower($match['handler']) . '.' . $match['action'];
            $summary   = $req->method . ' ' . $req->path . ' → ' . (int)$resp['status'];
            $this->activity->log(
                $eventName,
                (int)$ctx['user']['id'],
                null,
                null,
                $summary,
                ['route' => $key, 'status' => $resp['status'], 'token_id' => (int)$ctx['token']['id']]
            );

            $this->send($resp);
        } catch (\Throwable $e) {
            // Outer safety net — guarantees API clients always get JSON, never
            // the HTML 500 page from the global set_exception_handler in
            // public/index.php. Wraps auth/limiter/serveOpenApi/activity log
            // failures plus anything dispatch() rethrows past its inner catch.
            error_log('[api kernel] ' . $e);
            $this->send(ApiResponse::error(500, 'server_error', 'Internal error'));
        }
    }

    /** Strip path params from the route key (handled by handlers themselves). */
    private function normalisePath(string $path): string
    {
        return preg_replace('#/\d+#', '/{id}', $path) ?? $path;
    }

    private function dispatch(array $match, Request $req, array $ctx): array
    {
        $class = '\\App\\Api\\V1\\Handlers\\' . $match['handler'] . 'Handler';
        if (!class_exists($class)) {
            return ApiResponse::error(404, 'not_found', 'Route not found');
        }
        $handler = new $class($this->pdo, $this->services, $ctx);
        return $handler->{$match['action']}($req);
    }

    private function serveOpenApi(): void
    {
        $path = APP_ROOT . '/docs/openapi.yaml';
        if (!is_file($path)) { http_response_code(404); echo '# openapi.yaml not present yet'; return; }
        header('Content-Type: application/yaml; charset=utf-8');
        readfile($path);
    }

    private function send(array $resp): void
    {
        http_response_code($resp['status']);
        foreach ($resp['headers'] ?? [] as $k => $v) header("$k: $v");
        echo $resp['body'];
    }
}
