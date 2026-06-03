<?php
declare(strict_types=1);
namespace App\Auth;

use App\Repository\UserRepository;

final class AuthManager {
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private array &$session,
        private LoginThrottle $throttle,
    ) {}

    public function login(string $email, string $plain): array|string|null {
        if ($this->throttle->isThrottled($email)) return 'throttled';
        $user = $this->users->findByEmail($email);
        if (!$user || !$this->hasher->verify($plain, $user['password_hash'])) {
            $this->throttle->recordFail($email);
            return null;
        }
        if ($user['status'] === 'pending') return 'pending';
        if ($user['status'] === 'blocked') return 'blocked';
        $this->users->touchLastLogin((int)$user['id']);
        $this->session['user_id'] = (int)$user['id'];
        $this->throttle->resetFails($email);
        return $user;
    }

    public function logout(): void { unset($this->session['user_id'], $this->session['__remember']); }

    public function currentUser(): ?array {
        $id = $this->session['user_id'] ?? null;
        return $id ? $this->users->findById((int)$id) : null;
    }
}
