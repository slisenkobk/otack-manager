<?php
declare(strict_types=1);

use App\Service\ConfigStore;

function cs_with_tmp_path(): array {
    $path = sys_get_temp_dir() . '/cs-test-' . bin2hex(random_bytes(4)) . '.json';
    register_shutdown_function(fn() => @unlink($path));
    $cs = new ConfigStore($path);
    return [$cs, $path];
}

it('load() on absent file returns empty array', function () {
    [$cs] = cs_with_tmp_path();
    assert_eq([], $cs->load());
    assert_true(!$cs->exists());
});

it('set + load round-trip', function () {
    [$cs, $path] = cs_with_tmp_path();
    $cs->set(['APP_URL' => 'https://example.com', 'TG_CHAT_ID' => '-100123']);
    $loaded = $cs->load();
    assert_eq('https://example.com', $loaded['APP_URL']);
    assert_eq('-100123', $loaded['TG_CHAT_ID']);
    assert_true($cs->exists());
});

it('set rejects non-whitelist key', function () {
    [$cs] = cs_with_tmp_path();
    $threw = false;
    try { $cs->set(['EVIL_KEY' => 'x']); }
    catch (\InvalidArgumentException $e) {
        $threw = str_contains($e->getMessage(), 'EVIL_KEY');
    }
    assert_true($threw);
});

it('set rejects malformed DB_DSN', function () {
    [$cs] = cs_with_tmp_path();
    foreach (['file:///etc/passwd', 'no-scheme', 'http://foo'] as $bad) {
        $threw = false;
        try { $cs->set(['DB_DSN' => $bad]); }
        catch (\InvalidArgumentException $e) { $threw = true; }
        assert_true($threw, "expected reject of $bad");
    }
    // Sqlite and mysql schemes accepted.
    $cs->set(['DB_DSN' => 'sqlite::memory:']);
    $cs->set(['DB_DSN' => 'mysql:host=127.0.0.1;dbname=x']);
});

it('set rejects invalid APP_URL', function () {
    [$cs] = cs_with_tmp_path();
    $threw = false;
    try { $cs->set(['APP_URL' => 'javascript:alert(1)']); }
    catch (\InvalidArgumentException $e) { $threw = true; }
    assert_true($threw);
});

it('set casts bool/int to string', function () {
    [$cs, $path] = cs_with_tmp_path();
    $cs->set(['UPDATE_ENABLED' => true, 'UPDATE_CHECK_INTERVAL' => 3600]);
    $loaded = $cs->load();
    assert_eq('true', $loaded['UPDATE_ENABLED']);
    assert_eq('3600', $loaded['UPDATE_CHECK_INTERVAL']);
});

it('unset removes listed keys, preserves others', function () {
    [$cs] = cs_with_tmp_path();
    $cs->set(['APP_URL' => 'https://x', 'TG_CHAT_ID' => '1']);
    $cs->unset(['APP_URL']);
    $loaded = $cs->load();
    assert_true(!isset($loaded['APP_URL']));
    assert_eq('1', $loaded['TG_CHAT_ID']);
});

it('write sets file mode 0600', function () {
    [$cs, $path] = cs_with_tmp_path();
    $cs->set(['APP_URL' => 'https://x']);
    $mode = fileperms($path) & 0777;
    assert_eq(0600, $mode);
});

it('get returns null for absent key, string for present key', function () {
    [$cs] = cs_with_tmp_path();
    assert_eq(null, $cs->get('APP_URL'));
    $cs->set(['APP_URL' => 'https://x']);
    assert_eq('https://x', $cs->get('APP_URL'));
});

it('load() drops keys with invalid values (hand-tampered config.json)', function () {
    [$cs, $path] = cs_with_tmp_path();
    // Hand-craft a config.json with one valid key + 3 malformed
    // values that bypass set()'s validation.
    file_put_contents($path, json_encode([
        'APP_URL'    => 'https://good.example',
        'DB_DSN'     => 'file:///etc/passwd',        // not sqlite:/mysql:
        'TG_CHAT_ID' => 'NOT_A_NUMBER',
        'EVIL_KEY'   => 'whatever',                  // not in allow-list
    ]));
    chmod($path, 0600);
    $loaded = $cs->load();
    // Valid value passes through.
    assert_eq('https://good.example', $loaded['APP_URL']);
    // Bad values are dropped.
    assert_true(!isset($loaded['DB_DSN']),     'DB_DSN with bad scheme leaked through load()');
    assert_true(!isset($loaded['TG_CHAT_ID']), 'TG_CHAT_ID with non-numeric leaked through load()');
    assert_true(!isset($loaded['EVIL_KEY']),   'EVIL_KEY not in allow-list leaked through load()');
});
