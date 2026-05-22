<?php
declare(strict_types=1);

namespace App;

final class App
{
    private static array $factories = [];
    private static array $instances = [];

    public static function singleton(string $id, callable $factory): void
    {
        self::$factories[$id] = $factory;
    }

    /** Reset all instances (for CLI-server test isolation). */
    public static function reset(): void
    {
        self::$instances = [];
    }

    public static function make(string $id): object
    {
        if (isset(self::$instances[$id])) return self::$instances[$id];
        if (!isset(self::$factories[$id])) {
            throw new \RuntimeException("Service '$id' not registered");
        }
        return self::$instances[$id] = (self::$factories[$id])();
    }

    public static function env(string $key, string $default = ''): string
    {
        return (string)($_ENV[$key] ?? $default);
    }
}
