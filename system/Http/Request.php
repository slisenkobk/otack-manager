<?php
declare(strict_types=1);
namespace App\Http;

final class Request {
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $headers,
        public readonly array $cookies,
    ) {}

    public static function fromGlobals(): self {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$name] = $v;
            }
        }
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $uri, $_GET, $_POST, $_FILES, $headers, $_COOKIE
        );
    }

    public function header(string $name): ?string {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isAjax(): bool {
        $a = strtolower($this->header('accept') ?? '');
        return str_contains($a, 'application/json')
            || $this->header('x-requested-with') === 'XMLHttpRequest';
    }

    /**
     * Best-effort client IP. Honours X-Forwarded-For's first hop ONLY when
     * REMOTE_ADDR is in the TRUSTED_PROXIES allowlist (comma-separated CIDR
     * or single-IP entries). When the env is empty, XFF is ignored.
     *
     * This replaces 3 places that previously trusted XFF unconditionally
     * (PublicForm/Poll/Link rate-limit + audit IP) — those were spoofable.
     */
    public static function clientIp(): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $trustedEnv = $_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES') ?: '';
        if ($trustedEnv === '' || $remote === '') return $remote;

        $trusted = array_filter(array_map('trim', explode(',', $trustedEnv)));
        $isTrusted = false;
        foreach ($trusted as $cidr) {
            if (self::ipInRange($remote, $cidr)) { $isTrusted = true; break; }
        }
        if (!$isTrusted) return $remote;

        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff === '') return $remote;
        if (preg_match('/[\r\n]/', $xff)) return $remote;
        $first = trim(strtok($xff, ','));
        if (!filter_var($first, FILTER_VALIDATE_IP)) return $remote;
        return $first;
    }

    private static function ipInRange(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) return $ip === $cidr;
        [$subnet, $maskLen] = explode('/', $cidr, 2);
        if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipL = ip2long($ip); $subL = ip2long($subnet);
            $mask = -1 << (32 - (int)$maskLen);
            return ($ipL & $mask) === ($subL & $mask);
        }
        $ipBin = @inet_pton($ip); $subBin = @inet_pton($subnet);
        if (!$ipBin || !$subBin || strlen($ipBin) !== strlen($subBin)) return false;
        $maskLen = (int)$maskLen;
        $bytes = intdiv($maskLen, 8); $bits = $maskLen % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) return false;
        if ($bits === 0) return true;
        $mask = chr(0xff << (8 - $bits) & 0xff);
        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($subBin[$bytes]) & ord($mask));
    }
}
