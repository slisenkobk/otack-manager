<?php
declare(strict_types=1);
namespace App\Api\V1;

final class ApiResponse
{
    private const HEADERS = ['Content-Type' => 'application/json; charset=utf-8'];

    public static function ok(array $data, int $status = 200): array
    {
        return ['status' => $status, 'body' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'headers' => self::HEADERS];
    }

    public static function created(array $data): array { return self::ok($data, 201); }

    public static function noContent(): array
    {
        return ['status' => 204, 'body' => '', 'headers' => []];
    }

    public static function error(int $status, string $code, string $message, array $fields = []): array
    {
        $body = ['error' => $code, 'message' => $message];
        if ($fields) $body['fields'] = $fields;
        return ['status' => $status, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE), 'headers' => self::HEADERS];
    }

    public static function paginated(array $items, ?int $nextCursor): array
    {
        return self::ok(['items' => $items, 'next_cursor' => $nextCursor]);
    }
}
