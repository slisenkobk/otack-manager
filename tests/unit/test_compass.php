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
    $tmpTrash    = sys_get_temp_dir() . '/otack-compass-trash-' . uniqid();
    mkdir($tmpUploads, 0755, true);
    mkdir($tmpSessions, 0755, true);

    $pdo  = Connection::open($tmpDb);
    $boot = new SchemaBootstrap($pdo);
    Migrations::run($boot);

    $svc = new CompassService(
        $pdo, $boot, new SettingsRepository($pdo),
        $tmpSessions, $tmpUploads, $tmpLog,
        APP_ROOT . '/system/Database/migrations',
        $tmpTrash
    );
    return [$svc, $pdo, $tmpDb, $tmpUploads, $tmpSessions, $tmpLog, $tmpTrash];
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
    touch($uploads . '/2026/01/shared.jpg', time() - 3 * 86400);
    touch($uploads . '/2026/02/shared.jpg', time() - 3 * 86400);

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

it('clearOrphanUploads quarantines an old file nothing references', function () {
    [$svc, $pdo, $db, $uploads, , , $trash] = _new_compass();

    mkdir($uploads . '/2026/03', 0755, true);
    file_put_contents($uploads . '/2026/03/ghost.bin', 'orphan');
    touch($uploads . '/2026/03/ghost.bin', time() - 3 * 86400);

    $stats = $svc->uploadsStats();
    assert_eq(1, $stats['total']);
    assert_eq(1, $stats['orphan']);
    assert_eq(null, $stats['scan_error']);

    $res = $svc->clearOrphanUploads();
    $rel = basename($uploads) . '/2026/03/ghost.bin';
    assert_eq(1, $res['deleted']);
    assert_eq(6, $res['bytes']);
    assert_eq([$rel], $res['files'], 'moved paths reported for the activity log');
    assert_true(!file_exists($uploads . '/2026/03/ghost.bin'), 'orphan removed from uploads');
    assert_true(is_string($res['quarantine']) && str_starts_with($res['quarantine'], $trash . '/'), 'batch under trash dir');
    assert_eq('orphan', (string)@file_get_contents($res['quarantine'] . '/' . $rel), 'orphan kept in quarantine');

    _rrmdir($uploads); _rrmdir($trash);
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

// ─── Orphan uploads: every reference source is honoured ──────────────────

function _old_upload(string $uploads, string $name, string $body = 'x'): string {
    if (!is_dir($uploads . '/2026/05')) mkdir($uploads . '/2026/05', 0755, true);
    $abs = $uploads . '/2026/05/' . $name;
    file_put_contents($abs, $body);
    touch($abs, time() - 3 * 86400);
    return basename($uploads) . '/2026/05/' . $name;
}

it('clearOrphanUploads keeps avatars, attachments and images embedded in text', function () {
    [$svc, $pdo, $db, $uploads, , , $trash] = _new_compass();
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $now = date('Y-m-d H:i:s');

    $avatar   = _old_upload($uploads, 'avatar.jpg');
    $attach   = _old_upload($uploads, 'attach.pdf');
    $taskImg  = _old_upload($uploads, 'task.png');
    $cmtImg   = _old_upload($uploads, 'comment.webp');
    $wikiImg  = _old_upload($uploads, 'wiki.gif');
    $wikiOld  = _old_upload($uploads, 'wiki-v1.gif');
    $formImg  = _old_upload($uploads, 'form.jpg');
    $absImg   = _old_upload($uploads, 'abs.jpg');
    $ghost    = _old_upload($uploads, 'ghost.jpg');

    $pdo->prepare("INSERT INTO users (email, password_hash, name, role, status, created_at, avatar)
                   VALUES ('a@x', '', 'A', 'admin', 'approved', ?, ?)")->execute([$now, $avatar]);
    $pdo->prepare("INSERT INTO attachments (entity_type, entity_id, filename, original_name, mime, size, is_image, uploaded_by, created_at)
                   VALUES ('task', 1, ?, 'a.pdf', 'application/pdf', 1, 0, 1, ?)")->execute([$attach, $now]);
    $pdo->prepare("INSERT INTO tasks (project_id, column_id, title, description, position, created_by, created_at, updated_at)
                   VALUES (1, 1, 't', ?, 1, 1, ?, ?)")->execute(["See ![shot](/$taskImg).", $now, $now]);
    $pdo->prepare("INSERT INTO comments (entity_type, entity_id, user_id, body, created_at)
                   VALUES ('task', 1, 1, ?, ?)")->execute(["<p><img src=\"/$cmtImg?v=2\" alt=\"\"></p>", $now]);
    $pdo->prepare("INSERT INTO knowledge_pages (slug, title, body_md, author_id, created_at, updated_at)
                   VALUES ('p', 'P', ?, 1, ?, ?)")->execute(["Intro\n\n![](https://otack.example.com/$wikiImg)", $now, $now]);
    $pdo->prepare("INSERT INTO knowledge_page_versions (page_id, title, body_md, snapshot_by, snapshot_at)
                   VALUES (1, 'P', ?, 1, ?)")->execute(["old: /$wikiOld", $now]);
    $pdo->prepare("INSERT INTO forms (hash, title, fields_json, created_by, created_at, updated_at)
                   VALUES ('h1', 'F', ?, 1, ?, ?)")->execute([json_encode([['image' => '/' . $formImg]]), $now, $now]);
    $pdo->prepare("INSERT INTO settings (key, value) VALUES ('x_logo', ?)")->execute(["https://h.example/$absImg"]);

    $stats = $svc->uploadsStats();
    assert_eq(9, $stats['total']);
    assert_eq(1, $stats['orphan'], 'only the ghost is an orphan');

    $res = $svc->clearOrphanUploads();
    assert_eq(1, $res['deleted']);
    assert_eq([$ghost], $res['files']);
    foreach ([$avatar, $attach, $taskImg, $cmtImg, $wikiImg, $wikiOld, $formImg, $absImg] as $rel) {
        assert_true(is_file(dirname($uploads) . '/' . $rel), "$rel must survive");
    }
    assert_true(!is_file(dirname($uploads) . '/' . $ghost), 'ghost moved out');

    _rrmdir($uploads); _rrmdir($trash);
    @unlink($db);
});

it('clearOrphanUploads never touches files younger than the grace period', function () {
    [$svc, $pdo, $db, $uploads, , , $trash] = _new_compass();
    mkdir($uploads . '/2026/05', 0755, true);
    file_put_contents($uploads . '/2026/05/fresh.jpg', 'new');

    assert_eq(0, $svc->uploadsStats()['orphan']);
    $res = $svc->clearOrphanUploads();
    assert_eq(0, $res['deleted']);
    assert_true(is_file($uploads . '/2026/05/fresh.jpg'), 'fresh upload kept');

    _rrmdir($uploads); _rrmdir($trash);
    @unlink($db);
});

it('clearOrphanUploads deletes nothing when a reference query fails', function () {
    [$svc, $pdo, $db, $uploads, , , $trash] = _new_compass();
    $ghost = _old_upload($uploads, 'ghost.jpg');
    // Break one text source: the scan must abort, not skip it.
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('DROP TABLE knowledge_page_versions');

    $stats = $svc->uploadsStats();
    assert_eq(1, $stats['total']);
    assert_eq(0, $stats['orphan'], 'no orphans offered when the scan fails');
    assert_true(is_string($stats['scan_error']) && str_contains($stats['scan_error'], 'knowledge_page_versions'));

    $threw = false;
    try { $svc->clearOrphanUploads(); } catch (\RuntimeException $e) { $threw = true; }
    assert_true($threw, 'cleanup aborts with an exception');
    assert_true(is_file(dirname($uploads) . '/' . $ghost), 'file untouched');
    assert_true(!is_dir($trash) || count(glob($trash . '/*') ?: []) === 0, 'nothing quarantined');

    _rrmdir($uploads); _rrmdir($trash);
    @unlink($db);
});

it('clearOrphanUploads purges quarantine batches past retention only', function () {
    [$svc, $pdo, $db, $uploads, , , $trash] = _new_compass();
    $old   = $trash . '/' . date('Ymd-His', time() - (CompassService::TRASH_RETENTION_DAYS + 1) * 86400);
    $young = $trash . '/' . date('Ymd-His', time() - 86400);
    $other = $trash . '/keep-me';
    foreach ([$old, $young, $other] as $d) {
        mkdir($d . '/uploads/2026/01', 0755, true);
        file_put_contents($d . '/uploads/2026/01/f.jpg', 'x');
    }

    $res = $svc->clearOrphanUploads();
    assert_eq(1, $res['purged_batches']);
    assert_true(!is_dir($old), 'expired batch purged');
    assert_true(is_file($young . '/uploads/2026/01/f.jpg'), 'recent batch kept');
    assert_true(is_file($other . '/uploads/2026/01/f.jpg'), 'non-batch dir ignored');

    _rrmdir($uploads); _rrmdir($trash);
    @unlink($db);
});
