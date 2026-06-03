<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Http\Request;

final class PingHandler extends BaseHandler
{
    public function ping(Request $req, array $params = []): array
    {
        return ApiResponse::ok(['ok' => true, 'user_id' => $this->userId()]);
    }
}
