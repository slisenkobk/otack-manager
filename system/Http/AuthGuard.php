<?php
declare(strict_types=1);
namespace App\Http;

use App\App;

final class AuthGuard {
    /** @return array user row */
    public static function require(Request $req): array {
        $session = App::make('session');
        $userId = $session->store['user_id'] ?? null;
        if (!$userId) {
            Response::redirect('/login');
            exit;
        }
        $user = App::make('users')->findById((int)$userId);
        if (!$user) {
            // stale session — clean and redirect
            unset($session->store['user_id']);
            Response::redirect('/login');
            exit;
        }
        if ($user['status'] === 'pending') {
            Response::redirect('/pending');
            exit;
        }
        if ($user['status'] === 'blocked') {
            // log them out silently
            unset($session->store['user_id']);
            Response::redirect('/login');
            exit;
        }
        return $user;
    }

    public static function requireAdmin(?array $user): void {
        if (!$user || $user['role'] !== 'admin') {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo '<h1>403 Forbidden</h1><p>Admin only.</p>';
            exit;
        }
    }
}
