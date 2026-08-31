<?php
declare(strict_types=1);

namespace App\Agent;

/**
 * Minimal REST client for the Otack Manager API.
 *
 * Uses stream contexts rather than curl to match this repository's existing
 * HTTP call site (`App\Service\Updater::fetchTags()`) and to keep the agent
 * dependency-free.
 *
 * `rawRequest()` is the single seam a test double replaces; everything above
 * it — URL building, headers, JSON, retry — is exercised for real in tests.
 * `sleep()` is a second, narrower seam: it exists only so a test double can
 * observe the retry delay the client chose without actually waiting for it.
 */
class Client
{
    /** Server's rate-limit window (`RateLimiter::windowSeconds`) plus slack. */
    private const RETRY_AFTER_MAX = 65;

    public function __construct(
        private string $baseUrl,
        private string $token,
        private int $timeout = 15,
        private int $retrySleepSeconds = 1,
    ) {}

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, null, $query);
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body, []);
    }

    public function patch(string $path, array $body): array
    {
        return $this->request('PATCH', $path, $body, []);
    }

    /**
     * Follow `next_cursor` (ApiResponse::paginated()'s cursor field) until it
     * comes back null or `$maxPages` is reached, and return the merged
     * `items`. Both `GET /tasks` (TasksHandler::index(), `$limit = min(100,
     * ...)`) and `GET /tasks/{id}/comments` (CommentsHandler::paginatedList(),
     * same `min(100, ...)` clamp) cap a single page at 100 rows server-side; a
     * caller that reads only page one silently loses everything past row
     * 100 — for a comment thread, that can push the agent's own last
     * comment out of the window and make the whole thread look unread.
     *
     * Throws on a non-200 page rather than returning a partial list, so a
     * bad id or a permissions error surfaces the same way `get()` errors
     * already do for callers in the CLI (their shared top-level `catch`) —
     * silently returning what pagination happened to gather so far would
     * turn "task not found" into "no new comments", which is a different
     * and misleading answer.
     *
     * @return list<array<string,mixed>>
     */
    public function getAll(string $path, array $query = [], int $maxPages = 20): array
    {
        $items = [];
        $cursor = null;
        for ($page = 0; $page < $maxPages; $page++) {
            $q = $query;
            if ($cursor !== null) { $q['after'] = $cursor; }
            $r = $this->get($path, $q);
            if ($r['status'] !== 200) {
                throw new \RuntimeException("GET $path returned {$r['status']}");
            }
            foreach ((array)($r['json']['items'] ?? []) as $item) { $items[] = $item; }
            $cursor = $r['json']['next_cursor'] ?? null;
            if ($cursor === null) { break; }
        }
        return $items;
    }

    private function request(string $method, string $path, ?array $body, array $query): array
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
            'User-Agent: otack-agent',
        ];
        $payload = null;
        if ($body !== null) {
            $payload    = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers[]  = 'Content-Type: application/json';
        }

        $res = $this->rawRequest($method, $url, $headers, $payload);

        // One retry on 429. The API allows 60 req/min per token (window =
        // RateLimiter::windowSeconds = 60s) and tells us exactly how long to
        // wait via Retry-After (ApiKernel sets it from RateLimiter::check()).
        // Honour that instead of a flat guess: a flat 1s retry against a
        // 60s window just re-triggers the same 429. Fall back to the
        // configured $retrySleepSeconds only when the header is missing or
        // not numeric — a second 429 after that still means something is
        // wrong that sleeping will not fix, so we surface it instead of
        // looping further.
        if ($res['status'] === 429) {
            $wait = $this->retrySleepSeconds;
            if (isset($res['headers']['retry-after']) && is_numeric($res['headers']['retry-after'])) {
                $wait = max(1, min(self::RETRY_AFTER_MAX, (int)$res['headers']['retry-after']));
            }
            $this->sleep($wait);
            $res = $this->rawRequest($method, $url, $headers, $payload);
        }

        if ($res['status'] === 401) {
            throw new \RuntimeException(
                'API rejected the token (401). Re-issue it at /profile/tokens and update ~/.claude/otack-agent/.env'
            );
        }

        return [
            'status' => $res['status'],
            'json'   => $res['body'] === '' ? null : json_decode($res['body'], true),
        ];
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    protected function rawRequest(string $method, string $url, array $headers, ?string $body): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'content'       => $body ?? '',
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException("HTTP request failed: $method $url");
        }

        // Same idiom as App\Service\Updater::httpStatusFromResponseHeaders()
        // for the status line; extended here to also keep named headers
        // (lower-cased) so 429 handling can read Retry-After.
        $status = 0;
        $respHeaders = [];
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int)$m[1];
                continue;
            }
            if (str_contains($h, ':')) {
                [$k, $v] = explode(':', $h, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
        }

        return ['status' => $status, 'body' => $raw, 'headers' => $respHeaders];
    }

    /** Test seam: FakeClient overrides this to record the delay instead of blocking. */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
