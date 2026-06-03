<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Http\Request;

final class PingHandler
{
    public function __construct(
        private \PDO $pdo,
        private array $services,
        private array $ctx,
    ) {}

    public function ping(Request $req): array
    {
        return ApiResponse::ok(['ok' => true, 'user_id' => (int)$this->ctx['user']['id']]);
    }
}
