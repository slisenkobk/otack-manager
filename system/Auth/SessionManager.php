<?php
declare(strict_types=1);
namespace App\Auth;

final class SessionManager {
    public function start(int $lifetime): void {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $dir = APP_ROOT . '/data/sessions';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        session_save_path($dir);
        session_name('OTACK_TASKS');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
        session_start();
        $_SESSION['__last'] = time();
    }

    public function &storage(): array {
        return $_SESSION;
    }

    public function destroy(): void {
        $_SESSION = [];
        session_destroy();
    }
}
