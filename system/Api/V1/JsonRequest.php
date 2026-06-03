<?php
declare(strict_types=1);
namespace App\Api\V1;

final class JsonRequest
{
    /** Parse a raw JSON body. Empty string → []. Malformed → throws. */
    public static function parse(string $raw): array
    {
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('malformed_json');
        }
        return $decoded;
    }

    public static function requireString(array $body, string $key): string
    {
        if (!array_key_exists($key, $body) || !is_string($body[$key]) || $body[$key] === '') {
            throw new \InvalidArgumentException($key);
        }
        return $body[$key];
    }

    public static function optionalString(array $body, string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) return $default;
        return is_string($body[$key]) ? $body[$key] : $default;
    }

    public static function requireInt(array $body, string $key): int
    {
        if (!array_key_exists($key, $body) || !is_int($body[$key])) {
            throw new \InvalidArgumentException($key);
        }
        return $body[$key];
    }

    public static function optionalInt(array $body, string $key): ?int
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) return null;
        return is_int($body[$key]) ? $body[$key] : null;
    }

    public static function optionalBool(array $body, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $body)) return $default;
        return (bool)$body[$key];
    }
}
