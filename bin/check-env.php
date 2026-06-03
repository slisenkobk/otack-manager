<?php
declare(strict_types=1);

/**
 * Sanity check for `make setup` / deploy targets (O-6): verifies the host
 * has the PHP extensions and writable directories the app requires before
 * anyone hits `index.php`. Exits non-zero on failure so CI / shell scripts
 * can gate on it.
 *
 * Required extensions match `system/bootstrap.php`'s hard-fail list
 * (pdo, dom, fileinfo, mbstring) plus curl (Telegram, updater). One of
 * `pdo_sqlite` or `pdo_mysql` must be present — DB driver is chosen at
 * runtime from DB_DSN.
 */

$required = ['pdo', 'dom', 'fileinfo', 'mbstring', 'curl'];
$oneOf    = ['pdo_sqlite', 'pdo_mysql'];

$missing = [];
foreach ($required as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}
$hasAnyDriver = false;
foreach ($oneOf as $ext) {
    if (extension_loaded($ext)) { $hasAnyDriver = true; break; }
}
if (!$hasAnyDriver) {
    $missing[] = '(pdo_sqlite OR pdo_mysql)';
}

if ($missing) {
    fwrite(STDERR, "Missing required PHP extensions: " . implode(', ', $missing) . "\n");
    exit(1);
}
echo "All required PHP extensions present (PHP " . PHP_VERSION . ").\n";

$root = dirname(__DIR__);
foreach (['data', 'public/uploads'] as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        fwrite(STDERR, "Required directory missing: $path\n");
        exit(1);
    }
    if (!is_writable($path)) {
        fwrite(STDERR, "Required directory not writable: $path\n");
        exit(1);
    }
}
echo "Required directories present and writable.\n";

exit(0);
