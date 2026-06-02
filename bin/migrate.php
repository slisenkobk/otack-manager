#!/usr/bin/env php
<?php
declare(strict_types=1);

// Stand-alone CLI runner for schema migrations. Used by `make migrate` and
// any CI/deploy step that wants to apply migrations explicitly instead of
// waiting for the first HTTP hit. Exit codes: 0 = success, 1 = failure.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "bin/migrate.php must be run from the CLI\n");
    exit(1);
}

require dirname(__DIR__) . '/system/bootstrap.php';

use App\App;
use App\Database\{Connection, Migrations, SchemaBootstrap};

try {
    $pdo  = Connection::openFromEnv();
    SchemaBootstrap::$legacyMarkerDir = APP_ROOT . '/data/.schema';
    $boot = new SchemaBootstrap($pdo);
    $applied = Migrations::run($boot);

    if (!$applied) {
        fwrite(STDOUT, "Schema up to date — no migrations applied (" . basename($dbPath) . ")\n");
    } else {
        fwrite(STDOUT, "Applied " . count($applied) . " migration(s):\n");
        foreach ($applied as $name) {
            fwrite(STDOUT, "  + " . $name . "\n");
        }
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    if (App::env('APP_DEBUG') === 'true') {
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    }
    exit(1);
}
