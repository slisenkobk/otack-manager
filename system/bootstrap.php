<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

// Hard-require core PHP extensions. Missing any of these aborts startup —
// silent fallbacks (e.g. strip_tags instead of DOMDocument in HtmlSanitizer)
// are unsafe in production. pdo_sqlite OR pdo_mysql is verified separately
// inside Connection::openFromEnv() based on the configured DSN.
foreach (['pdo', 'dom', 'fileinfo', 'mbstring'] as $__ext) {
    if (!extension_loaded($__ext)) {
        fwrite(STDERR, "[bootstrap] missing PHP extension: $__ext\n");
        if (PHP_SAPI !== 'cli') {
            http_response_code(500);
            echo "Server misconfiguration: missing PHP extension '$__ext'.";
        }
        exit(1);
    }
}
unset($__ext);

if (is_file(APP_ROOT . '/.env')) {
    foreach (file(APP_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#') continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($k);
        if (isset($_ENV[$key]) || getenv($key) !== false) continue;
        $_ENV[$key] = trim($v);
    }
}

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $rel = str_replace('\\', '/', substr($class, 4)) . '.php';
    $path = APP_ROOT . '/system/' . $rel;
    if (is_file($path)) require $path;
});

require APP_ROOT . '/system/App.php';
require APP_ROOT . '/system/View/helpers.php';
require APP_ROOT . '/system/version.php';
