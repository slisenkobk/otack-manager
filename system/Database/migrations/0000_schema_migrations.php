<?php
declare(strict_types=1);

// Bootstrap migration: creates the schema_migrations bookkeeping table and
// performs a one-time backfill from the legacy data/.schema/ marker files so
// installations that pre-date the per-file migrations layout don't re-apply
// everything on first boot.
//
// IMPORTANT: this file's name is permanent. The runner identifies it by the
// literal "0000_schema_migrations" basename and always executes it first.
//
// Uses the Schema DSL so it works on SQLite and MySQL. The legacy-marker
// backfill is logically idempotent (INSERT IGNORE / INSERT OR IGNORE).

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTableIfNotExists('schema_migrations', function (Blueprint $t) {
        $t->string('name', 200)->primary();
        $t->timestamp('applied_at');
    });

    $markerDir = \App\Database\SchemaBootstrap::$legacyMarkerDir;
    if ($markerDir === null || $markerDir === '' || !is_dir($markerDir)) return;

    $markers = glob($markerDir . '/*') ?: [];
    if (!$markers) return;

    $keyToName = [];
    foreach (glob(__DIR__ . '/*.php') ?: [] as $mf) {
        $base = basename($mf, '.php');
        if (preg_match('/^\d{8}_\d{3}_(.+)$/', $base, $m)) {
            $keyToName[$m[1]] = $base;
        }
    }
    if (!$keyToName) return;

    $pdo = $schema->pdo();
    $verb = $schema->driverName() === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    $insert = $pdo->prepare("$verb INTO schema_migrations (name, applied_at) VALUES (?, ?)");
    foreach ($markers as $marker) {
        if (!is_file($marker)) continue;
        $base = basename($marker);
        if (!preg_match('/^(.+)\.\d+$/', $base, $m)) continue;
        $key = $m[1];
        if (!isset($keyToName[$key])) continue;
        $mtime = @filemtime($marker) ?: time();
        $insert->execute([$keyToName[$key], date('Y-m-d H:i:s', $mtime)]);
    }
};
