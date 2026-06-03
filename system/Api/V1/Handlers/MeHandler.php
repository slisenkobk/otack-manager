<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Http\Request;

final class MeHandler extends BaseHandler
{
    public function show(Request $req, array $params = []): array
    {
        $u = $this->user();
        return ApiResponse::ok([
            'id'     => (int)$u['id'],
            'name'   => $u['name'],
            'email'  => $u['email'],
            'role'   => $u['role'],
            'locale' => $u['locale'] ?? 'en',
        ]);
    }
}
