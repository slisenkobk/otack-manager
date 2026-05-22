<?php
declare(strict_types=1);

namespace App\Http;

final class Csrf
{
    public function __construct(private array &$store) {}

    public function token(): string
    {
        if (empty($this->store['csrf'])) {
            $this->store['csrf'] = bin2hex(random_bytes(32));
        }
        return $this->store['csrf'];
    }

    public function regenerate(): void
    {
        $this->store['csrf'] = bin2hex(random_bytes(32));
    }

    public function verify(?string $token): bool
    {
        if (!$token || empty($this->store['csrf'])) {
            return false;
        }
        return hash_equals($this->store['csrf'], $token);
    }
}
