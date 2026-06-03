<?php
declare(strict_types=1);
namespace App\Api\V1;

use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;

final class TokenAuthenticator
{
    public function __construct(
        private ApiTokenRepository $tokens,
        private UserRepository $users,
    ) {}

    /**
     * @param string|null $authHeader raw Authorization header value
     * @return array{user: array, token: array}|null
     */
    public function authenticate(?string $authHeader): ?array
    {
        if (!is_string($authHeader)) return null;
        if (!preg_match('/^Bearer\s+(otk_\S+)$/', $authHeader, $m)) return null;
        $token = $m[1];

        $tokenRow = $this->tokens->findActiveByToken($token);
        if (!$tokenRow) return null;

        $user = $this->users->findById((int)$tokenRow['user_id']);
        if (!$user) return null;
        // Production status is 'approved'; some historical test fixtures used
        // 'active'. We accept both so the API auth path tolerates either —
        // anything else (pending, blocked, disabled, …) is rejected.
        $status = (string)($user['status'] ?? '');
        if ($status !== 'approved' && $status !== 'active') return null;

        return ['user' => $user, 'token' => $tokenRow];
    }
}
