<?php
declare(strict_types=1);
namespace App\Auth;

use App\Repository\UserRepository;

final class AuthManager {
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private array &$session,
    ) {}

    public function login(string $email, string $plain): array|string|null {
        if ($this->isThrottled($email)) return 'throttled';
        $user = $this->users->findByEmail($email);
        if (!$user || !$this->hasher->verify($plain, $user['password_hash'])) {
            $this->recordFail($email);
            return null;
        }
        if ($user['status'] === 'pending') return 'pending';
        if ($user['status'] === 'blocked') return 'blocked';
        $this->users->touchLastLogin((int)$user['id']);
        $this->session['user_id'] = (int)$user['id'];
        $this->resetFails($email);
        return $user;
    }

    public function logout(): void { unset($this->session['user_id']); }

    public function currentUser(): ?array {
        $id = $this->session['user_id'] ?? null;
        return $id ? $this->users->findById((int)$id) : null;
    }

    private function failsKey(string $email): string { return 'auth_fails_' . md5(strtolower($email)); }

    private function isThrottled(string $email): bool {
        $k = $this->failsKey($email);
        $f = $this->session[$k] ?? null;
        if (!$f) return false;
        if ((time() - $f['first']) > 900) { unset($this->session[$k]); return false; }
        return $f['count'] >= 5;
    }

    private function recordFail(string $email): void {
        $k = $this->failsKey($email);
        $f = $this->session[$k] ?? ['first' => time(), 'count' => 0];
        if ((time() - $f['first']) > 900) $f = ['first' => time(), 'count' => 0];
        $f['count']++;
        $this->session[$k] = $f;
    }

    private function resetFails(string $email): void { unset($this->session[$this->failsKey($email)]); }
}
