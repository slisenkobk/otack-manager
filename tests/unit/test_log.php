<?php
declare(strict_types=1);

use App\Service\Log;

/**
 * Capture error_log() output by redirecting it to a temp file via ini_set.
 * Each call gets a fresh file so tests can't bleed into one another.
 *
 * @return array{0:string,1:string} [captured-line, file-path]
 */
function capture_log(callable $fn): array {
    $file = tempnam(sys_get_temp_dir(), 'otack-log-test-');
    $prev = ini_set('error_log', $file);
    try {
        $fn();
    } finally {
        if ($prev !== false) ini_set('error_log', $prev);
    }
    $content = is_file($file) ? trim((string)file_get_contents($file)) : '';
    @unlink($file);
    return [$content, $file];
}

it('Log::error writes uppercase level, tag and message', function () {
    [$out] = capture_log(fn() => Log::error('updater', 'rollback failed'));
    // Strip the PHP date prefix error_log adds to file targets:
    //   "[03-Jun-2026 21:31:00 UTC] [2026-06-03T21:31:00Z] ERROR:updater rollback failed"
    assert_true(str_contains($out, 'ERROR:updater rollback failed'),
        'expected ERROR line, got: ' . $out);
    // ISO-8601 UTC timestamp inside the inner brackets
    assert_true((bool)preg_match('/\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\]/', $out),
        'expected ISO-8601 timestamp, got: ' . $out);
});

it('Log::warn uses WARN level prefix', function () {
    [$out] = capture_log(fn() => Log::warn('db', 'slow query'));
    assert_true(str_contains($out, 'WARN:db slow query'), $out);
});

it('Log::info uses INFO level prefix', function () {
    [$out] = capture_log(fn() => Log::info('boot', 'started'));
    assert_true(str_contains($out, 'INFO:boot started'), $out);
});

it('context array is appended as JSON', function () {
    [$out] = capture_log(fn() => Log::error('api', 'denied', ['user_id' => 42, 'ip' => '1.2.3.4']));
    assert_true(str_contains($out, 'ERROR:api denied {"user_id":42,"ip":"1.2.3.4"}'), $out);
});

it('empty context array does not append a trailing space or {}', function () {
    [$out] = capture_log(fn() => Log::info('boot', 'started'));
    // Line should end with the message, not with a trailing space + empty JSON.
    assert_true(str_ends_with($out, 'INFO:boot started'), $out);
});
