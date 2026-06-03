<?php
declare(strict_types=1);

/**
 * `app_secret()` is the dedicated accessor for the HMAC signing material used
 * by anti-bot time-traps + short-link IP hashing. It prefers APP_SECRET and
 * falls back to LOGIN_HASH for backward compatibility (Task 11 / S-8).
 *
 * Each case saves and restores the original env values so the suite stays
 * hermetic — other tests that read LOGIN_HASH must see whatever .env shipped.
 */

it('app_secret prefers APP_SECRET over LOGIN_HASH', function () {
    $origApp   = $_ENV['APP_SECRET'] ?? null;
    $origLogin = $_ENV['LOGIN_HASH'] ?? null;
    try {
        $_ENV['APP_SECRET'] = 'appsec';
        putenv('APP_SECRET=appsec');
        $_ENV['LOGIN_HASH'] = 'loginh';
        putenv('LOGIN_HASH=loginh');
        assert_eq('appsec', app_secret());
    } finally {
        if ($origApp === null) { unset($_ENV['APP_SECRET']); putenv('APP_SECRET'); }
        else { $_ENV['APP_SECRET'] = $origApp; putenv('APP_SECRET=' . $origApp); }
        if ($origLogin === null) { unset($_ENV['LOGIN_HASH']); putenv('LOGIN_HASH'); }
        else { $_ENV['LOGIN_HASH'] = $origLogin; putenv('LOGIN_HASH=' . $origLogin); }
    }
});

it('app_secret falls back to LOGIN_HASH when APP_SECRET empty', function () {
    $origApp   = $_ENV['APP_SECRET'] ?? null;
    $origLogin = $_ENV['LOGIN_HASH'] ?? null;
    try {
        $_ENV['APP_SECRET'] = '';
        putenv('APP_SECRET=');
        $_ENV['LOGIN_HASH'] = 'fallback';
        putenv('LOGIN_HASH=fallback');
        assert_eq('fallback', app_secret());
    } finally {
        if ($origApp === null) { unset($_ENV['APP_SECRET']); putenv('APP_SECRET'); }
        else { $_ENV['APP_SECRET'] = $origApp; putenv('APP_SECRET=' . $origApp); }
        if ($origLogin === null) { unset($_ENV['LOGIN_HASH']); putenv('LOGIN_HASH'); }
        else { $_ENV['LOGIN_HASH'] = $origLogin; putenv('LOGIN_HASH=' . $origLogin); }
    }
});
