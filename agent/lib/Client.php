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
 * `parseResponseHeaders()` is a third seam, pulled out specifically so the
 * status-line/header parsing that a FakeClient-based test can never reach
 * (it overrides rawRequest() wholesale) has something a test *can* call
 * directly.
 */
class Client
{
    /** Server's rate-limit window (`RateLimiter::windowSeconds`) plus slack. */
    private const RETRY_AFTER_MAX = 65;
    private const RETRY_AFTER_MIN = 1;

    public function __construct(
        private string $baseUrl,
        private string $token,
        private int $timeout = 15,
        private int $retrySleepSeconds = 1,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');

        // The bearer token is the only access lever this API has (no
        // scopes — spec §10), so it must never travel in cleartext.
        // Loopback is exempt: tests and local development talk to
        // 127.0.0.1 / ::1 / localhost without TLS.
        $scheme = parse_url($this->baseUrl, PHP_URL_SCHEME);
        $host   = parse_url($this->baseUrl, PHP_URL_HOST);
        $isLoopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
        if ($scheme !== 'https' && !$isLoopback) {
            throw new \InvalidArgumentException(
                "Client requires an https:// base URL (got '$baseUrl'); a plain http:// origin "
                . 'would send the bearer token in cleartext. Loopback hosts (127.0.0.1, ::1, '
                . 'localhost) are exempt for local development.'
            );
        }
    }

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
     * and misleading answer. For the same reason, it also throws instead of
     * staying silent when `next_cursor` fails to advance (a looping server
     * would otherwise return duplicated rows while burning request budget)
     * or when `$maxPages` is exhausted with a cursor still pending (that
     * would otherwise return a result indistinguishable from a complete
     * list, silently truncated).
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
            $pageItems = $r['json']['items'] ?? [];
            if (!is_array($pageItems)) {
                throw new \RuntimeException(
                    "GET $path: expected 'items' to be an array, got " . get_debug_type($pageItems)
                );
            }
            foreach ($pageItems as $item) { $items[] = $item; }

            $next = $r['json']['next_cursor'] ?? null;
            if ($next === null) {
                $cursor = null;
                break;
            }
            if ($next === $cursor) {
                throw new \RuntimeException(
                    "GET $path: next_cursor '$next' did not advance past the previous page — "
                    . 'the server appears to be looping'
                );
            }
            $cursor = $next;
        }
        if ($cursor !== null) {
            throw new \RuntimeException(
                "GET $path: reached maxPages=$maxPages with next_cursor '$cursor' still pending — "
                . 'the result is truncated, not complete'
            );
        }
        return $items;
    }

    private function request(string $method, string $path, ?array $body, array $query): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query !== []) {
            // http_build_query() silently drops null-valued entries rather
            // than erroring or sending them empty. Accepted: no caller in
            // this codebase passes a null query value today.
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
            'User-Agent: otack-agent',
        ];
        $payload = null;
        if ($body !== null) {
            // json_encode([]) is '[]', which many JSON APIs (including this
            // one) read as an array, not an object — a genuinely empty POST
            // body must still serialise as an object.
            $payload    = json_encode($body ?: new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers[]  = 'Content-Type: application/json';
        }

        $res = $this->rawRequest($method, $url, $headers, $payload);

        // One retry on 429. The API allows 60 req/min per token (window =
        // RateLimiter::windowSeconds = 60s) and tells us exactly how long to
        // wait via Retry-After (ApiKernel sets it from RateLimiter::check()).
        // Honour that instead of a flat guess: a flat 1s retry against a
        // 60s window just re-triggers the same 429. Fall back to the
        // configured $retrySleepSeconds only when the header is missing or
        // not numeric (e.g. an RFC-7231 HTTP-date, which is a legal
        // Retry-After value we don't parse) — a second 429 after that still
        // means something is wrong that sleeping will not fix, so we
        // surface it instead of looping further. The clamp applies to
        // *every* path here, including the configured fallback: an
        // unclamped $retrySleepSeconds of 0 would hot-retry into the same
        // window.
        if ($res['status'] === 429) {
            $wait = $this->retrySleepSeconds;
            if (isset($res['headers']['retry-after']) && is_numeric($res['headers']['retry-after'])) {
                $wait = (int)$res['headers']['retry-after'];
            }
            $wait = max(self::RETRY_AFTER_MIN, min(self::RETRY_AFTER_MAX, $wait));
            $this->sleep($wait);
            $res = $this->rawRequest($method, $url, $headers, $payload);
        }

        // This API never legitimately redirects. Following one anyway would
        // re-send the Authorization header — a full-power, unscoped bearer
        // token (spec §10) — to whatever host Location points at. rawRequest()
        // is configured not to follow (follow_location => 0), so a 3xx here
        // is the single hop itself; refuse it instead of returning it as if
        // it were a normal response.
        if ($res['status'] >= 300 && $res['status'] < 400) {
            $location = $res['headers']['location'] ?? '(no Location header)';
            throw new \RuntimeException(
                "$method $url returned {$res['status']} redirecting to $location. This API never "
                . 'redirects; the client does not follow one to avoid resending the bearer token.'
            );
        }

        if ($res['status'] === 401) {
            throw new \RuntimeException(
                'API rejected the token (401). Re-issue it at /profile/tokens and update ~/.claude/otack-agent/.env'
            );
        }

        $json = null;
        if ($res['body'] !== '') {
            $decoded = json_decode($res['body'], true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                // A 200 with a non-JSON body (WAF interstitial, a PHP fatal
                // rendered as an HTML page, a captive portal, ...) must not
                // read as an empty success — that turns "the request never
                // reached the app" into "no new comments" for a caller that
                // only checks status.
                $snippet = substr($res['body'], 0, 200);
                throw new \RuntimeException(
                    "$method $url returned {$res['status']} with a body that isn't valid JSON: $snippet"
                );
            }
            $json = $decoded;
        }

        return [
            'status' => $res['status'],
            'json'   => $json,
        ];
    }

    /**
     * Stream an uploaded file (an attachment's `path`, `/uploads/…`) into
     * `$dest`, at most `$maxBytes` of it.
     *
     * `/uploads/` is plain static content under `public/` — Apache's
     * "existing file" rewrite rule (and the built-in server's router in
     * `public/index.php`) serves it before the app runs, with no session or
     * token check; the web UI links to it directly. So this request carries
     * NO Authorization header at all: the bearer token is not needed and is
     * not sent. The URL is built only from the configured instance (the API
     * base with its `/api/vN` suffix dropped) plus a path that must look like
     * `/uploads/…` — an absolute URL, a `//host` or a `..` segment is refused
     * before any request, and a redirect is never followed, so the request
     * cannot leave the configured origin.
     *
     * Never throws for an HTTP-level problem; returns
     * `['ok' => false, 'reason' => …]` so one bad file does not abort the rest.
     *
     * @param resource $dest writable stream
     * @return array{ok:bool, bytes:int, reason:?string}
     */
    public function download(string $path, $dest, int $maxBytes, int $deadlineSeconds = 120): array
    {
        if (!self::isUploadPath($path)) {
            return ['ok' => false, 'bytes' => 0, 'reason' => 'path is not a /uploads/ file on this instance'];
        }
        $url = $this->appBase() . $path;
        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => "User-Agent: otack-agent\r\nAccept: */*",
                'timeout'         => $this->timeout,
                'ignore_errors'   => true,
                // Same reasoning as rawRequest(): never leave the origin.
                'follow_location' => 0,
                'max_redirects'   => 1,
            ],
        ]);
        $in = @fopen($url, 'rb', false, $ctx);
        if ($in === false) {
            return ['ok' => false, 'bytes' => 0, 'reason' => 'request failed'];
        }
        try {
            $meta = stream_get_meta_data($in);
            $parsed = self::parseResponseHeaders(is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : []);
            if ($parsed['status'] !== 200) {
                $where = isset($parsed['headers']['location']) ? ' (redirect not followed)' : '';
                return ['ok' => false, 'bytes' => 0, 'reason' => "HTTP {$parsed['status']}$where"];
            }
            $declared = $parsed['headers']['content-length'] ?? null;
            if ($declared !== null && ctype_digit($declared) && (int)$declared > $maxBytes) {
                return ['ok' => false, 'bytes' => 0, 'reason' => "oversize: Content-Length $declared > $maxBytes"];
            }
            $bytes = 0;
            $deadline = microtime(true) + $deadlineSeconds;
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    return ['ok' => false, 'bytes' => $bytes, 'reason' => 'read failed'];
                }
                if (stream_get_meta_data($in)['timed_out'] ?? false) {
                    return ['ok' => false, 'bytes' => $bytes, 'reason' => 'read timed out'];
                }
                $bytes += strlen($chunk);
                if ($bytes > $maxBytes) {
                    return ['ok' => false, 'bytes' => $bytes, 'reason' => "oversize: more than $maxBytes bytes"];
                }
                if ($chunk !== '' && fwrite($dest, $chunk) !== strlen($chunk)) {
                    return ['ok' => false, 'bytes' => $bytes, 'reason' => 'local write failed'];
                }
                if (microtime(true) > $deadline) {
                    return ['ok' => false, 'bytes' => $bytes, 'reason' => "download took longer than {$deadlineSeconds}s"];
                }
            }
            if ($declared !== null && ctype_digit($declared) && (int)$declared !== $bytes) {
                return ['ok' => false, 'bytes' => $bytes, 'reason' => "truncated: got $bytes of $declared bytes"];
            }
            return ['ok' => true, 'bytes' => $bytes, 'reason' => null];
        } finally {
            fclose($in);
        }
    }

    /** `/uploads/` + safe segments only: no scheme, no `//host`, no `..`, no query. */
    public static function isUploadPath(string $path): bool
    {
        if (!preg_match('#^/uploads/[A-Za-z0-9._/-]+$#', $path)) { return false; }
        foreach (explode('/', substr($path, 1)) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') { return false; }
        }
        return true;
    }

    /** The web root of the instance: the API base without its `/api/vN` suffix. */
    private function appBase(): string
    {
        $base = preg_replace('#/api/v\d+$#', '', $this->baseUrl) ?? $this->baseUrl;
        if ($base === $this->baseUrl) {
            // Not the documented `…/api/v1` shape: fall back to the bare origin.
            $p = parse_url($this->baseUrl);
            $base = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        }
        return rtrim($base, '/');
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    protected function rawRequest(string $method, string $url, array $headers, ?string $body): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'          => $method,
                'header'          => implode("\r\n", $headers),
                'content'         => $body ?? '',
                // `timeout` bounds each read, not the initial TCP connect —
                // the http:// stream wrapper has no separate connect-timeout
                // option. A peer that accepts the connection and then never
                // sends bytes is bounded by this; a peer whose SYN is
                // black-holed instead falls back to PHP's
                // default_socket_timeout. Accepted, not fixed here.
                'timeout'         => $this->timeout,
                'ignore_errors'   => true,
                // Never follow a redirect: it would re-send the
                // Authorization header to the redirect target. request()
                // above turns the single hop this allows into an error.
                'follow_location' => 0,
                'max_redirects'   => 1,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException("HTTP request failed: $method $url");
        }

        $parsed = self::parseResponseHeaders($http_response_header ?? []);

        return ['status' => $parsed['status'], 'body' => $raw, 'headers' => $parsed['headers']];
    }

    /**
     * Parse `$http_response_header`-shaped lines (as PHP's http stream
     * wrapper populates them) into a status code and a lower-cased header
     * map. Same idiom as `App\Service\Updater::httpStatusFromResponseHeaders()`
     * for the status line, extended to also collect named headers so 429
     * and redirect handling can read them (e.g. Retry-After, Location).
     *
     * A redirect (even the single hop `follow_location => 0` still leaves
     * PHP room to report) emits *two* status lines and two header blocks in
     * the same array — one per hop. Only the last hop is what the caller
     * actually received, so the header map resets on every status line;
     * carrying headers from an earlier hop forward would describe a
     * response the caller never got (e.g. a redirect's Retry-After
     * attached to the page it redirected to).
     *
     * @param list<string> $raw
     * @return array{status:int, headers:array<string,string>}
     */
    protected static function parseResponseHeaders(array $raw): array
    {
        $status = 0;
        $headers = [];
        foreach ($raw as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int)$m[1];
                $headers = [];
                continue;
            }
            if (str_contains($h, ':')) {
                [$k, $v] = explode(':', $h, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return ['status' => $status, 'headers' => $headers];
    }

    /** Test seam: FakeClient overrides this to record the delay instead of blocking. */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
