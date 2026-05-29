<?php
declare(strict_types=1);

/*
 * ─── TEMPORARY MIGRATION RUNNER ──────────────────────────────────────────
 *
 *   Browser-accessible companion to bin/migrate.php. Visit:
 *
 *       /migrate.php?hash=<LOGIN_HASH>
 *
 *   to apply any pending migrations on a host where CLI access is awkward
 *   (shared hosting, restricted SSH, etc.). Gated by the same LOGIN_HASH
 *   that protects /login, so this file is not a free attack vector — but
 *   it IS still an extra surface, so DELETE THIS FILE once the deploy is
 *   verified up to date.
 *
 *   Notes:
 *   - public/index.php already runs migrations on every authenticated web
 *     request, so this script is for cases where you want explicit visible
 *     output before users start hitting the app.
 *   - Output is plain HTML, no JS, no session writes.
 *   - Wrapped in a BEGIN IMMEDIATE transaction by Migrations::run.
 */

require dirname(__DIR__) . '/system/bootstrap.php';

use App\App;
use App\Database\{Connection, Migrations, SchemaBootstrap};

$expectedHash = (string)App::env('LOGIN_HASH', '');
$providedHash = (string)($_GET['hash'] ?? '');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Migrate — temporary runner</title>
  <style>
    body { font: 14px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace; background: #1a1612; color: #f5f0e6; margin: 0; padding: 32px; }
    .wrap { max-width: 720px; margin: 0 auto; }
    h1 { font-size: 18px; margin: 0 0 24px; letter-spacing: .04em; text-transform: uppercase; }
    .banner { background: #c2410c; color: #fff; padding: 12px 16px; border-radius: 6px; margin-bottom: 24px; font-weight: 600; }
    .ok { color: #22c55e; }
    .err { color: #ef4444; }
    .applied { color: #fbbf24; }
    ul { list-style: none; padding: 0; }
    li { padding: 6px 0; border-bottom: 1px solid #2d2620; }
    li:last-child { border-bottom: 0; }
    .meta { color: #877963; font-size: 12px; }
    .sep { margin: 20px 0; border: 0; border-top: 1px solid #2d2620; }
    code { background: #2d2620; padding: 2px 6px; border-radius: 3px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="banner">⚠️ TEMPORARY. Delete <code>public/migrate.php</code> once migrations are verified.</div>
    <h1>Schema migrations</h1>

<?php
if ($expectedHash === '' || !hash_equals($expectedHash, $providedHash)) {
    http_response_code(403);
    echo '<p class="err">Forbidden — missing or invalid <code>?hash</code>.</p>';
    echo '<p class="meta">Append <code>?hash=&lt;LOGIN_HASH&gt;</code> to the URL.</p>';
    echo '</div></body></html>';
    exit;
}

// Reset OPcache before doing anything — fresh deploys regularly hit
// "Service X not registered" type errors because PHP-FPM is still serving
// the byte-cached version of public/index.php that pre-dates the new
// service registration. Clearing the cache here makes the rest of the
// app pick up the new code on the very next request.
if (function_exists('opcache_reset')) {
    $opcacheCleared = @opcache_reset();
    echo '<p class="ok">OPcache: ' . ($opcacheCleared ? 'cleared ✓' : 'not cleared (disabled or no permission)') . '</p>';
} else {
    echo '<p class="meta">OPcache: not available.</p>';
}

// Deploy sanity check — confirm public/index.php has the lines the rest
// of the app expects. Tell apart "old file got deployed" from "OPcache
// holds stale byte-code". Comparing the file on disk avoids any chance
// of byte-cache confusion.
$indexPath  = __DIR__ . '/index.php';
$indexCheck = is_file($indexPath) ? (string)@file_get_contents($indexPath) : '';
$markers = [
    'session_manager registration' => "App::singleton('session_manager'",
    'sliding extendCookie call'    => 'SessionManager::REMEMBER_LIFETIME',
];
echo '<p class="meta">Deployed <code>public/index.php</code> mtime: '
   . (is_file($indexPath) ? date('Y-m-d H:i:s', (int)filemtime($indexPath)) : '— missing —')
   . '; ' . number_format(strlen($indexCheck)) . ' bytes.</p>';
echo '<ul>';
foreach ($markers as $label => $needle) {
    $found = $indexCheck !== '' && str_contains($indexCheck, $needle);
    echo '<li class="' . ($found ? 'ok' : 'err') . '">'
       . ($found ? '✓ ' : '✗ ') . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
       . ' — looking for <code>' . htmlspecialchars($needle, ENT_QUOTES, 'UTF-8') . '</code>'
       . '</li>';
}
echo '</ul>';
echo '<hr class="sep">';

try {
    $dbPath = APP_ROOT . '/' . App::env('DB_PATH', 'data/app.sqlite');
    $pdo    = Connection::open($dbPath);
    SchemaBootstrap::$legacyMarkerDir = APP_ROOT . '/data/.schema';
    $boot   = new SchemaBootstrap($pdo);

    $dir     = APP_ROOT . '/system/Database/migrations';
    $statusBefore = $boot->status($dir);
    $pending = array_values(array_filter($statusBefore, fn($r) => !$r['applied']));

    echo '<p class="meta">DB: <code>' . htmlspecialchars(basename($dbPath), ENT_QUOTES, 'UTF-8') . '</code></p>';
    echo '<p>' . count($statusBefore) . ' migration file(s), ' . count($pending) . ' pending.</p>';

    if (!$pending) {
        echo '<p class="ok">✓ Schema is up to date — nothing to apply.</p>';
    } else {
        echo '<p>Pending:</p><ul>';
        foreach ($pending as $row) {
            echo '<li class="applied">→ ' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul><hr class="sep">';

        $applied = Migrations::run($boot);
        if (!$applied) {
            echo '<p class="ok">No migrations were applied (race or no-op).</p>';
        } else {
            echo '<p class="ok">✓ Applied ' . count($applied) . ' migration(s):</p><ul>';
            foreach ($applied as $name) {
                echo '<li class="ok">+ ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            echo '</ul>';
        }
    }

    echo '<hr class="sep"><p class="meta">Done. Reload to re-check, then delete this file.</p>';
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<p class="err">✗ Migration failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    if (App::env('APP_DEBUG') === 'true') {
        echo '<pre style="white-space:pre-wrap;color:#ef4444;font-size:12px;">'
            . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8')
            . '</pre>';
    }
}
?>
  </div>
</body>
</html>
