<?php
declare(strict_types=1);
namespace App\Auth;

final class SessionManager {
    /** "Remember me" sliding window. Picked to match the requirement of
     *  "keep the user signed in for 30 days" — bump if you want longer. */
    public const REMEMBER_LIFETIME = 30 * 24 * 3600;
    /** Default sign-in lifetime when the user did not tick remember-me.
     *  Matches "1 day" per the product spec. */
    public const DEFAULT_LIFETIME  = 24 * 3600;

    public function start(int $lifetime): void {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $dir = APP_ROOT . '/data/sessions';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        session_save_path($dir);
        // The garbage collector must keep remember-me session files around
        // for at least 30 days even if the per-request cookie lifetime is
        // shorter — otherwise a returning user clicks the link, hits a
        // GC'd file, and gets bounced to /login.
        ini_set('session.gc_maxlifetime', (string)max($lifetime, self::REMEMBER_LIFETIME));
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

    /**
     * Re-issue the session cookie with a new expiry. Used at login (when the
     * user picks remember-me) and on every subsequent request to slide the
     * window forward, so the cookie keeps a fresh 30-day / 24-hour horizon
     * relative to "now" rather than expiring against the original login time.
     *
     * Safe to call any time after session_start() — only updates the cookie
     * the browser receives in the response.
     */
    public function extendCookie(int $seconds): void {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        if (headers_sent()) return;
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $seconds,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
    }

    public function &storage(): array {
        return $_SESSION;
    }

    public function destroy(): void {
        $_SESSION = [];
        session_destroy();
    }
}
