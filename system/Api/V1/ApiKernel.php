<?php
declare(strict_types=1);
namespace App\Api\V1;

use App\Http\Request;

final class ApiKernel
{
    /**
     * Route registration table. Patterns use semantic placeholder names so
     * routes with two ids stay unambiguous after regex compilation:
     *
     *   - {id}      — primary resource id for the URL
     *   - {userId}  — second numeric segment on /projects/{id}/members/{userId}
     *   - {otherId} — second numeric segment on /tasks/{id}/links/{otherId}
     *   - {tagId}   — second numeric segment on …/tags/{tagId}
     *
     * All placeholders compile to `\d+` and are surfaced to handlers via the
     * `$params` array (e.g. `$params['userId']`). The OpenAPI drift test
     * already canonicalizes any `\{[^}]+\}` to `{id}` before comparing, so
     * the semantic names here do not regress drift coverage.
     *
     * @var array<string, array{handler:string, action:string}>
     *      keyed by "METHOD PATTERN" (the literal pattern string)
     */
    private array $routes = [];

    /**
     * Pre-compiled view of $routes built at construction time. Each entry:
     *   ['method' => 'POST', 'pattern' => '<original>',
     *    'regex' => '#^/api/v1/...$#', 'paramNames' => ['id','userId'],
     *    'handler' => 'Projects', 'action' => 'addMember']
     *
     * @var list<array{method:string, pattern:string, regex:string,
     *                 paramNames:list<string>, handler:string, action:string}>
     */
    private array $compiled = [];

    public function __construct(
        private TokenAuthenticator $auth,
        private RateLimiter $limiter,
        private \App\Repository\ApiTokenRepository $tokens,
        private \App\Repository\ActivityLogRepository $activity,
        private \PDO $pdo,
        private array $services,   // ['projects' => ProjectRepository, ...] injected
    ) {
        $this->register();
        $this->compile();
    }

    private function register(): void
    {
        $this->routes['GET /api/v1/ping'] = ['handler' => 'Ping', 'action' => 'ping'];
        $this->routes['GET /api/v1/me']   = ['handler' => 'Me',   'action' => 'show'];

        $this->routes['GET /api/v1/projects']                            = ['handler' => 'Projects', 'action' => 'index'];
        $this->routes['GET /api/v1/projects/{id}']                       = ['handler' => 'Projects', 'action' => 'show'];
        $this->routes['POST /api/v1/projects']                           = ['handler' => 'Projects', 'action' => 'create'];
        $this->routes['PATCH /api/v1/projects/{id}']                     = ['handler' => 'Projects', 'action' => 'update'];
        $this->routes['DELETE /api/v1/projects/{id}']                    = ['handler' => 'Projects', 'action' => 'destroy'];
        $this->routes['POST /api/v1/projects/{id}/pin']                  = ['handler' => 'Projects', 'action' => 'setPin'];
        $this->routes['POST /api/v1/projects/{id}/members']              = ['handler' => 'Projects', 'action' => 'addMember'];
        // {userId} disambiguates the second numeric segment from the project id.
        $this->routes['DELETE /api/v1/projects/{id}/members/{userId}']   = ['handler' => 'Projects', 'action' => 'removeMember'];

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
        // {otherId} matches the previous pathId(5) "other task id".
        $this->routes['DELETE /api/v1/tasks/{id}/links/{otherId}']       = ['handler' => 'Tasks', 'action' => 'unlink'];

        $this->routes['GET /api/v1/tasks/{id}/comments']    = ['handler' => 'Comments', 'action' => 'indexForTask'];
        $this->routes['GET /api/v1/projects/{id}/comments'] = ['handler' => 'Comments', 'action' => 'indexForProject'];
        $this->routes['POST /api/v1/comments']              = ['handler' => 'Comments', 'action' => 'create'];
        $this->routes['DELETE /api/v1/comments/{id}']       = ['handler' => 'Comments', 'action' => 'destroy'];

        $this->routes['GET /api/v1/projects/{id}/tags']                  = ['handler' => 'Tags', 'action' => 'indexForProject'];
        $this->routes['GET /api/v1/tags']                                = ['handler' => 'Tags', 'action' => 'indexGlobal'];
        $this->routes['POST /api/v1/tags']                               = ['handler' => 'Tags', 'action' => 'createGlobal'];
        $this->routes['POST /api/v1/projects/{id}/tags']                 = ['handler' => 'Tags', 'action' => 'attachToProject'];
        // {tagId} disambiguates from the project id.
        $this->routes['DELETE /api/v1/projects/{id}/tags/{tagId}']       = ['handler' => 'Tags', 'action' => 'detachFromProject'];
        $this->routes['POST /api/v1/tasks/{id}/tags']                    = ['handler' => 'Tags', 'action' => 'attachToTask'];
        // {tagId} disambiguates from the task id.
        $this->routes['DELETE /api/v1/tasks/{id}/tags/{tagId}']          = ['handler' => 'Tags', 'action' => 'detachFromTask'];

        $this->routes['GET /api/v1/tasks/{id}/attachments']     = ['handler' => 'Attachments', 'action' => 'indexForTask'];
        $this->routes['GET /api/v1/projects/{id}/attachments']  = ['handler' => 'Attachments', 'action' => 'indexForProject'];
        $this->routes['POST /api/v1/attachments']               = ['handler' => 'Attachments', 'action' => 'create'];
        $this->routes['DELETE /api/v1/attachments/{id}']        = ['handler' => 'Attachments', 'action' => 'destroy'];

        $this->routes['GET /api/v1/forms']                       = ['handler' => 'Forms', 'action' => 'index'];
        $this->routes['GET /api/v1/forms/{id}']                  = ['handler' => 'Forms', 'action' => 'show'];
        $this->routes['GET /api/v1/forms/{id}/submissions']      = ['handler' => 'Forms', 'action' => 'submissions'];
        $this->routes['GET /api/v1/submissions/{id}']            = ['handler' => 'Forms', 'action' => 'showSubmission'];

        $this->routes['GET /api/v1/polls']                       = ['handler' => 'Polls', 'action' => 'index'];
        $this->routes['GET /api/v1/polls/{id}']                  = ['handler' => 'Polls', 'action' => 'show'];
        $this->routes['GET /api/v1/polls/{id}/voters']           = ['handler' => 'Polls', 'action' => 'voters'];
    }

    /**
     * Compile every entry in $routes to a per-route regex with named captures.
     * Placeholder spellings (`{id}`, `{userId}`, …) all match \d+; semantic
     * names disambiguate routes that carry two numeric ids in one URL.
     */
    private function compile(): void
    {
        foreach ($this->routes as $key => $info) {
            [$method, $pattern] = explode(' ', $key, 2);
            $paramNames = [];
            $regex = preg_replace_callback(
                '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
                function ($m) use (&$paramNames) {
                    $name = $m[1];
                    $paramNames[] = $name;
                    return '(?<' . $name . '>\d+)';
                },
                $pattern
            ) ?? $pattern;
            $this->compiled[] = [
                'method'     => $method,
                'pattern'    => $pattern,
                'regex'      => '#^' . $regex . '$#',
                'paramNames' => $paramNames,
                'handler'    => $info['handler'],
                'action'     => $info['action'],
            ];
        }
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
            if (($req->method === 'GET' || $req->method === 'HEAD') && $req->path === '/api/v1/openapi.yaml') {
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

            $match = $this->matchRoute($req->method, $req->path);
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
                [
                    // Use the original literal pattern (not the request path) so the
                    // activity log groups by route shape rather than per-instance.
                    'route'    => $req->method . ' ' . $match['pattern'],
                    'status'   => $resp['status'],
                    'token_id' => (int)$ctx['token']['id'],
                ]
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

    /**
     * Match (method, path) against the compiled routing table.
     * Returns the matched entry augmented with extracted params, or null.
     *
     * @return array{handler:string, action:string, pattern:string,
     *               params:array<string,int>}|null
     */
    private function matchRoute(string $method, string $path): ?array
    {
        foreach ($this->compiled as $route) {
            if ($route['method'] !== $method) continue;
            if (!preg_match($route['regex'], $path, $m)) continue;
            $params = [];
            foreach ($route['paramNames'] as $name) {
                $params[$name] = (int)$m[$name];
            }
            return [
                'handler' => $route['handler'],
                'action'  => $route['action'],
                'pattern' => $route['pattern'],
                'params'  => $params,
            ];
        }
        return null;
    }

    private function dispatch(array $match, Request $req, array $ctx): array
    {
        $class = '\\App\\Api\\V1\\Handlers\\' . $match['handler'] . 'Handler';
        if (!class_exists($class)) {
            return ApiResponse::error(404, 'not_found', 'Route not found');
        }
        $handler = new $class($this->pdo, $this->services, $ctx);
        return $handler->{$match['action']}($req, $match['params']);
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
