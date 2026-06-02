<?php
use App\Repository\AppVersionRepository;
use App\Repository\AppBackupRepository;

function _updPdo(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    // The updater migration references the `users` table for a LEFT JOIN
    // in listRecent — create a minimal shim so the test PDO has it.
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    (require dirname(__DIR__, 2) . '/system/Database/migrations/20260603_030_updater.php')($pdo);
    return $pdo;
}

it('AppVersionRepository::log inserts and findRecent returns newest first', function () {
    $pdo  = _updPdo();
    $repo = new AppVersionRepository($pdo);
    $pdo->exec("INSERT INTO users (id, name) VALUES (1, 'admin')");

    // The migration's auto-seed already inserted one 'install' row.
    $repo->log('1.0.1', AppVersionRepository::SOURCE_UPDATE, 1, 'release notes here');
    $repo->log('1.0.0', AppVersionRepository::SOURCE_RESTORE, 1);

    $rows = $repo->listRecent(10);
    assert_eq(3, count($rows));
    // Newest first — the restore is the latest entry.
    assert_eq(AppVersionRepository::SOURCE_RESTORE, $rows[0]['source']);
    assert_eq(AppVersionRepository::SOURCE_UPDATE,  $rows[1]['source']);
    assert_eq(AppVersionRepository::SOURCE_INSTALL, $rows[2]['source']);
    // applied_by_name resolves the join when present.
    assert_eq('admin', $rows[1]['applied_by_name']);
    assert_eq(null,    $rows[2]['applied_by_name']);
});

it('AppVersionRepository::log rejects unknown source values', function () {
    $repo = new AppVersionRepository(_updPdo());
    $threw = false;
    try { $repo->log('1.0.0', 'sneak', 1); } catch (\InvalidArgumentException $e) { $threw = true; }
    assert_true($threw, 'log() must reject sources outside the enum');
});

it('AppVersionRepository::current returns the latest row', function () {
    $pdo  = _updPdo();
    $repo = new AppVersionRepository($pdo);
    // The seeded install row is current after migration.
    assert_eq(AppVersionRepository::SOURCE_INSTALL, $repo->current()['source']);
    $repo->log('1.0.1', AppVersionRepository::SOURCE_UPDATE);
    assert_eq('1.0.1', $repo->current()['version']);
    assert_eq(AppVersionRepository::SOURCE_UPDATE, $repo->current()['source']);
});

it('AppBackupRepository::create + findById round-trips', function () {
    $repo = new AppBackupRepository(_updPdo());
    $id = $repo->create('1.0.0', '1.0.1', 'data/backups/foo/code', 'data/backups/foo/app.sqlite', 12345);
    assert_true($id > 0);
    $row = $repo->findById($id);
    assert_eq('1.0.0', $row['version_from']);
    assert_eq('1.0.1', $row['version_to']);
    assert_eq(12345,   (int)$row['size_bytes']);
    assert_eq('auto',  $row['kind']);
    assert_eq(null,    $row['pruned_at']);
});

it('AppBackupRepository::idsBeyondRetention returns the oldest extras', function () {
    $repo = new AppBackupRepository(_updPdo());
    // Create 7 backups with strictly-increasing created_at by spacing them.
    for ($i = 1; $i <= 7; $i++) {
        $repo->create("1.0.$i", "1.0." . ($i + 1), "data/backups/b$i/code", null, 100);
        usleep(2000);
    }
    // Keep 5 → 2 oldest are beyond retention.
    $extras = $repo->idsBeyondRetention(5);
    assert_eq(2, count($extras));
    // listAll returns newest-first; the two oldest IDs are the lowest ones (1, 2).
    $all = $repo->listAll();
    $lastTwoIds = [(int)$all[5]['id'], (int)$all[6]['id']];
    sort($extras); sort($lastTwoIds);
    assert_eq($lastTwoIds, $extras);
});

it('AppBackupRepository::idsBeyondRetention skips already-pruned rows', function () {
    $repo = new AppBackupRepository(_updPdo());
    for ($i = 1; $i <= 5; $i++) {
        $repo->create("1.0.$i", "1.0." . ($i + 1), "data/backups/b$i/code", null, 100);
        usleep(2000);
    }
    // Mark the two oldest as already pruned. Now only 3 are live; keep=2
    // should target only 1 extra (the third-oldest still-live one).
    $all = $repo->listAll();
    $repo->markPruned((int)$all[3]['id']);
    $repo->markPruned((int)$all[4]['id']);
    $extras = $repo->idsBeyondRetention(2);
    assert_eq(1, count($extras));
});

it('AppBackupRepository::markPruned sets timestamp but keeps the row', function () {
    $repo = new AppBackupRepository(_updPdo());
    $id = $repo->create('1.0.0', '1.0.1', 'data/backups/x/code', null, 100);
    $repo->markPruned($id);
    $row = $repo->findById($id);
    assert_true($row !== null);
    assert_true($row['pruned_at'] !== null);
});
