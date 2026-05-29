<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;
use App\Repository\SettingsRepository;
use App\Service\CompassService;

function _new_compass(): array {
    $tmpDb       = sys_get_temp_dir() . '/otack-compass-db-' . uniqid() . '.sqlite';
    $tmpUploads  = sys_get_temp_dir() . '/otack-compass-up-' . uniqid();
    $tmpSessions = sys_get_temp_dir() . '/otack-compass-sess-' . uniqid();
    $tmpLog      = sys_get_temp_dir() . '/otack-compass-log-' . uniqid() . '.log';
    mkdir($tmpUploads, 0755, true);
    mkdir($tmpSessions, 0755, true);

    $pdo  = Connection::open($tmpDb);
    $boot = new SchemaBootstrap($pdo);
    Migrations::run($boot);

    $svc = new CompassService(
        $pdo, $boot, new SettingsRepository($pdo),
        $tmpSessions, $tmpUploads, $tmpLog,
        APP_ROOT . '/system/Database/migrations'
    );
    return [$svc, $pdo, $tmpDb, $tmpUploads, $tmpSessions, $tmpLog];
}

function _rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $rii = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($rii as $f) {
        $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
    }
    @rmdir($dir);
}

it('clearOrphanUploads keeps files with same basename in different YYYY/MM dirs', function () {
    [$svc, $pdo, $db, $uploads] = _new_compass();

    // Two attachments share the same basename but live in different month
    // directories — basename matching would miss one and delete it.
    mkdir($uploads . '/2026/01', 0755, true);
    mkdir($uploads . '/2026/02', 0755, true);
    file_put_contents($uploads . '/2026/01/shared.jpg', 'jan');
    file_put_contents($uploads . '/2026/02/shared.jpg', 'feb');

    $uploadsBase = basename($uploads);
    $insert = $pdo->prepare(
        "INSERT INTO attachments (entity_type, entity_id, filename, original_name, mime, size, is_image, uploaded_by, created_at)
         VALUES ('task', 1, ?, 'shared.jpg', 'image/jpeg', 3, 1,
                 (SELECT id FROM users LIMIT 1) , datetime('now'))"
    );
    // Seed at least one user so the FK resolves.
    $pdo->exec("INSERT INTO users (email, password_hash, name, role, status, created_at)
                VALUES ('seed@x', '', 'Seed', 'admin', 'approved', datetime('now'))");
    $insert->execute([$uploadsBase . '/2026/01/shared.jpg']);
    $insert->execute([$uploadsBase . '/2026/02/shared.jpg']);

    $stats = $svc->uploadsStats();
    assert_eq(2, $stats['total']);
    assert_eq(0, $stats['orphan']);

    $res = $svc->clearOrphanUploads();
    assert_eq(0, $res['deleted']);
    assert_true(is_file($uploads . '/2026/01/shared.jpg'), 'Jan file kept');
    assert_true(is_file($uploads . '/2026/02/shared.jpg'), 'Feb file kept');

    _rrmdir($uploads);
    @unlink($db);
});

it('clearOrphanUploads deletes a file with no matching attachments row', function () {
    [$svc, $pdo, $db, $uploads] = _new_compass();

    mkdir($uploads . '/2026/03', 0755, true);
    file_put_contents($uploads . '/2026/03/ghost.bin', 'orphan');

    $stats = $svc->uploadsStats();
    assert_eq(1, $stats['total']);
    assert_eq(1, $stats['orphan']);

    $res = $svc->clearOrphanUploads();
    assert_eq(1, $res['deleted']);
    assert_eq(6, $res['bytes']);
    assert_true(!file_exists($uploads . '/2026/03/ghost.bin'), 'orphan deleted');

    _rrmdir($uploads);
    @unlink($db);
});

it('clearStaleSessions deletes only sessions older than SESSION_LIFETIME', function () {
    [$svc, $pdo, $db, $uploads, $sessions] = _new_compass();

    // Two old sessions + one fresh session.
    $oldA = $sessions . '/sess_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $oldB = $sessions . '/sess_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    $new  = $sessions . '/sess_ccccccccccccccccccccccccccccccccc';
    foreach ([$oldA, $oldB, $new] as $p) file_put_contents($p, 'x');
    // Default SESSION_LIFETIME is 43200 (12h). Make the two old files
    // older than that, leave the new one fresh.
    touch($oldA, time() - 86400);
    touch($oldB, time() - 86400);

    $stats = $svc->sessionsStats();
    assert_eq(3, $stats['total']);
    assert_eq(2, $stats['stale']);

    $res = $svc->clearStaleSessions();
    assert_eq(2, $res['deleted']);
    assert_true(!file_exists($oldA), 'old A gone');
    assert_true(!file_exists($oldB), 'old B gone');
    assert_true(is_file($new), 'fresh session kept');

    _rrmdir($sessions);
    _rrmdir($uploads);
    @unlink($db);
});

it('bumpAssetVersion writes a numeric timestamp the helper reads back', function () {
    [$svc, $pdo, $db, $uploads, $sessions] = _new_compass();
    $ver = $svc->bumpAssetVersion();
    assert_true(ctype_digit($ver), 'version is numeric');
    assert_eq($ver, $svc->currentAssetVersion());
    _rrmdir($sessions); _rrmdir($uploads); @unlink($db);
});

it('tailErrorsLog parses [date] LEVEL: msg headers and folds following lines', function () {
    [$svc, $pdo, $db, $uploads, $sessions, $log] = _new_compass();
    $body = <<<'TXT'
[22-May-2026 14:10:43 UTC] PHP Fatal error: undefined function foo() in /a/b.php:9
Stack trace:
#0 /a/b.php(99)
#1 {main}
[23-May-2026 11:00:00 UTC] PHP Notice: Trying to access array offset on null in /c/d.php:5
TXT;
    file_put_contents($log, $body);

    $entries = $svc->tailErrorsLog(50);
    assert_eq(2, count($entries));
    // Newest first.
    assert_true(str_contains($entries[0]['level'], 'Notice'), 'first entry is the Notice');
    assert_true(str_contains($entries[1]['level'], 'Fatal'), 'second is the Fatal');
    assert_true(str_contains($entries[1]['body'], 'Stack trace'), 'stack trace folded into body');

    $errOnly = $svc->tailErrorsLog(50, 'fatal');
    assert_eq(1, count($errOnly));

    _rrmdir($sessions); _rrmdir($uploads); @unlink($db); @unlink($log);
});

it('dbStats reports per-table row counts and DB file size', function () {
    [$svc, $pdo, $db, $uploads, $sessions] = _new_compass();
    $pdo->exec("INSERT INTO users (email, password_hash, name, role, status, created_at)
                VALUES ('a@x', '', 'A', 'admin', 'approved', datetime('now'))");
    $pdo->exec("INSERT INTO users (email, password_hash, name, role, status, created_at)
                VALUES ('b@x', '', 'B', 'employee', 'approved', datetime('now'))");

    $stats = $svc->dbStats();
    assert_true($stats['db_size'] > 0, 'sqlite file has a size');
    $byName = [];
    foreach ($stats['tables'] as $t) $byName[$t['name']] = $t['rows'];
    assert_eq(2, $byName['users']);
    assert_true(isset($byName['schema_migrations']) && $byName['schema_migrations'] > 0);

    _rrmdir($sessions); _rrmdir($uploads); @unlink($db);
});
