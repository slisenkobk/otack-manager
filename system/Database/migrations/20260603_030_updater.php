<?php
declare(strict_types=1);

// In-app updater tables (see docs/UPDATES.md §5).
//
//   app_versions  — append-only audit log of every version that has run
//                   on this install: 'install' (first boot), 'update'
//                   (came from GitHub), 'restore' (rolled back from a
//                   backup). Never updated, only inserted to.
//
//   app_backups   — one row per pre-change snapshot. `code_path` points
//                   at a directory and `db_snapshot` at an .sqlite file
//                   inside the backup workdir. `pruned_at` is set by
//                   the retention sweep when on-disk artefacts are
//                   removed; the row itself stays so history is preserved.
return function (\PDO $pdo) {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_versions (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            version      TEXT NOT NULL,
            installed_at TEXT NOT NULL,
            source       TEXT NOT NULL,
            applied_by   INTEGER NULL,
            notes        TEXT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_app_versions_installed_at ON app_versions(installed_at)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_backups (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            version_from TEXT NOT NULL,
            version_to   TEXT NOT NULL,
            created_at   TEXT NOT NULL,
            code_path    TEXT NOT NULL,
            db_snapshot  TEXT NULL,
            size_bytes   INTEGER NOT NULL DEFAULT 0,
            kind         TEXT NOT NULL DEFAULT "auto",
            pruned_at    TEXT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_app_backups_created_at ON app_backups(created_at)');

    // Seed the very first app_versions row so the History panel never
    // shows an empty install lineage. We use the currently-running
    // APP_VERSION because if this install was created before the
    // updater existed, that's the version it's been running. source
    // is 'install' (no admin actor available — applied_by NULL).
    $existing = (int)$pdo->query('SELECT COUNT(*) FROM app_versions')->fetchColumn();
    if ($existing === 0) {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $version = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
        $pdo->prepare(
            'INSERT INTO app_versions (version, installed_at, source, applied_by, notes)
             VALUES (?, ?, "install", NULL, NULL)'
        )->execute([$version, $now]);
    }
};
