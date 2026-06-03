<?php
declare(strict_types=1);

namespace App;

final class App
{
    private static array $factories = [];
    private static array $instances = [];
    private static array $resolving = [];

    public static function singleton(string $id, callable $factory): void
    {
        self::$factories[$id] = $factory;
    }

    /** Reset all instances (for CLI-server test isolation). */
    public static function reset(): void
    {
        self::$instances = [];
        self::$resolving = [];
    }

    public static function make(string $id): object
    {
        if (isset(self::$instances[$id])) return self::$instances[$id];
        if (!isset(self::$factories[$id])) {
            throw new \RuntimeException("Service '$id' not registered");
        }
        if (isset(self::$resolving[$id])) {
            $chain = implode(' -> ', array_keys(self::$resolving)) . " -> $id";
            self::$resolving = [];
            throw new \LogicException("DI circular dependency: $chain");
        }
        self::$resolving[$id] = true;
        try {
            return self::$instances[$id] = (self::$factories[$id])();
        } finally {
            unset(self::$resolving[$id]);
        }
    }

    public static function env(string $key, string $default = ''): string
    {
        if (isset($_ENV[$key])) return (string)$_ENV[$key];
        $v = getenv($key);
        return $v === false ? $default : (string)$v;
    }
}
