<?php
declare(strict_types=1);
namespace App\Http;

final class Response {
    public static function html(string $body, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $body;
    }

    public static function json(array $payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function redirect(string $url, int $status = 303): void {
        http_response_code($status);
        header('Location: ' . $url);
    }

    public static function notFound(string $msg = 'Not found'): void {
        self::html('<h1>404</h1><p>' . htmlspecialchars($msg) . '</p>', 404);
    }
}
