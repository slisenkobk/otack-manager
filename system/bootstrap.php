<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

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
