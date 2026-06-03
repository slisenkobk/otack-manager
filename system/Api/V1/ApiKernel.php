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
    }

    public function handle(Request $req): void
    {
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
    }

    /** Strip path params from the route key (handled by handlers themselves). */
    private function normalisePath(string $path): string
    {
        return preg_replace('#/\d+#', '/{id}', $path) ?? $path;
    }

    private function dispatch(array $match, Request $req, array $ctx): array
    {
        if ($match['handler'] === 'Ping') {
            return ApiResponse::ok(['ok' => true, 'user_id' => (int)$ctx['user']['id']]);
        }
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
