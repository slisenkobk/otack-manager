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
        // Strip CR/LF so a poisoned URL (e.g. one stored in DB with embedded
        // newlines) can't smuggle additional response headers via the
        // Location: line. Defence in depth — callers should also validate.
        $url = str_replace(["\r", "\n"], '', $url);
        http_response_code($status);
        header('Location: ' . $url);
    }

    public static function notFound(string $msg = 'Not found'): void {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        try {
            $view = \App\App::make('view');
            echo $view->render('errors/404', ['title' => '404 — ' . $msg], 'layouts/auth');
        } catch (\Throwable $_) {
            echo '<!doctype html><html><head><title>404</title></head><body>'
                . '<h1>404</h1><p>' . htmlspecialchars($msg) . '</p></body></html>';
        }
    }

    public static function forbidden(string $msg = 'Forbidden'): void {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        try {
            $view = \App\App::make('view');
            echo $view->render('errors/403', ['title' => '403 — ' . $msg], 'layouts/auth');
        } catch (\Throwable $_) {
            echo '<!doctype html><html><head><title>403</title></head><body>'
                . '<h1>403</h1><p>' . htmlspecialchars($msg) . '</p></body></html>';
        }
    }
}
