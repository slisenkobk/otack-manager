<?php
declare(strict_types=1);

// Bootstrap migration: creates the schema_migrations bookkeeping table and
// performs a one-time backfill from the legacy data/.schema/ marker files so
// installations that pre-date the per-file migrations layout don't re-apply
// everything on first boot.
//
// IMPORTANT: this file's name is permanent. The runner identifies it by the
// literal "0000_schema_migrations" basename and always executes it first.

return function (\PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        name TEXT PRIMARY KEY,
        applied_at TEXT NOT NULL
    )');

    $markerDir = \App\Database\SchemaBootstrap::$legacyMarkerDir;
    if ($markerDir === null || $markerDir === '' || !is_dir($markerDir)) return;

    $markers = glob($markerDir . '/*') ?: [];
    if (!$markers) return;

    // Build key -> migration-filename map from sibling files. We only insert
    // a backfill row when the legacy key resolves to an actual migration file
    // in this directory; orphans (renamed/removed migrations) are skipped.
    $keyToName = [];
    foreach (glob(__DIR__ . '/*.php') ?: [] as $mf) {
        $base = basename($mf, '.php');
        if (preg_match('/^\d{8}_\d{3}_(.+)$/', $base, $m)) {
            $keyToName[$m[1]] = $base;
        }
    }
    if (!$keyToName) return;

    $insert = $pdo->prepare(
        'INSERT OR IGNORE INTO schema_migrations (name, applied_at) VALUES (?, ?)'
    );
    foreach ($markers as $marker) {
        if (!is_file($marker)) continue;
        $base = basename($marker);
        if (!preg_match('/^(.+)\.\d+$/', $base, $m)) continue;
        $key = $m[1];
        if (!isset($keyToName[$key])) continue;
        $mtime = @filemtime($marker) ?: time();
        $insert->execute([
            $keyToName[$key],
            date('Y-m-d H:i:s', $mtime),
        ]);
    }
};
