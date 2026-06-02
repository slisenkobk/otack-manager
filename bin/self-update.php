#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Standalone updater runner. Used when:
 *   - the admin runs `php bin/self-update.php <version>` from the shell,
 *     usually after an in-process update broke the current request and
 *     the next HTTP hit still can't recover;
 *   - belt-and-braces for the controller path — if the in-process
 *     update proves too fragile in the field, the controller can exec()
 *     this runner and exit immediately.
 *
 * Exit codes:
 *   0 — success
 *   1 — bad invocation / argument
 *   2 — update failed (pipeline ran rollback before exiting)
 *
 * Usage:
 *   php bin/self-update.php <version>         # e.g. 1.0.3
 *   php bin/self-update.php --latest          # pick the highest semver tag
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "bin/self-update.php must be run from the CLI\n");
    exit(1);
}

require dirname(__DIR__) . '/system/bootstrap.php';

use App\App;
use App\Service\Updater;

$arg = $argv[1] ?? '';
if ($arg === '' || $arg === '-h' || $arg === '--help') {
    fwrite(STDOUT, "Usage:\n  bin/self-update.php <version>\n  bin/self-update.php --latest\n");
    exit($arg === '' ? 1 : 0);
}

/** @var Updater $updater */
$updater = App::make('updater');

if ($arg === '--latest') {
    try {
        $payload = $updater->check();
    } catch (\Throwable $e) {
        fwrite(STDERR, "Cannot discover latest version: " . $e->getMessage() . "\n");
        exit(2);
    }
    if (empty($payload['available']) || empty($payload['has_update'])) {
        fwrite(STDOUT, "Already up to date (v" . $payload['current'] . ")\n");
        exit(0);
    }
    $target = (string)$payload['available'];
} else {
    $target = ltrim($arg, 'v');
}

fwrite(STDOUT, "Updating to v$target …\n");
try {
    $result = $updater->update($target, null);
    fwrite(STDOUT,
        "OK: v{$result['from']} → v{$result['to']} in {$result['duration_seconds']}s "
        . "(backup #{$result['backup_id']})\n"
    );
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "Update failed: " . $e->getMessage() . "\n");
    if (App::env('APP_DEBUG') === 'true') {
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    }
    exit(2);
}
