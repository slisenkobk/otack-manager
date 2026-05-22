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
}
