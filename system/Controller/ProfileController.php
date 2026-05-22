<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Repository\UserRepository;
use App\Auth\PasswordHasher;

final class ProfileController extends BaseController {
    private UserRepository $users;
    private PasswordHasher $hasher;

    public function __construct($view, $user = null) {
        parent::__construct($view, $user);
        $this->users = App::make('users');
        $this->hasher = App::make('hasher');
    }

    private function &session(): array {
        $obj = App::make('session');
        return $obj->store;
    }

    private function flash(string $key, string $value): void {
        $s = &$this->session();
        $s[$key] = $value;
    }

    private function consumeFlash(string $key): ?string {
        $s = &$this->session();
        $v = $s[$key] ?? null;
        unset($s[$key]);
        return $v;
    }

    public function show(Request $req, array $params = []): void {
        $csrfToken = App::make('csrf')->token();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'profile', 'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => 'Profile',
        ]);
        Response::html($this->view->render('layouts/main', [
            'title' => 'Profile',
            'csrfToken' => $csrfToken,
            'sidebar' => $sidebar,
            'topbar' => $topbar,
            'content' => $this->view->render('profile/show', [
                'user' => $this->user,
                'csrfToken' => $csrfToken,
                'success' => $this->consumeFlash('flash_success'),
                'error' => $this->consumeFlash('flash_error'),
            ]),
        ]));
    }

    public function update(Request $req, array $params = []): void {
        $name = trim($req->post['name'] ?? '');
        if ($name === '') {
            $this->flash('flash_error', 'Name cannot be empty');
        } else {
            $this->users->updateName((int)$this->user['id'], $name);
            $this->flash('flash_success', 'Name updated');
        }
        Response::redirect('/profile');
    }

    public function updatePassword(Request $req, array $params = []): void {
        $current = $req->post['current'] ?? '';
        $new = $req->post['new'] ?? '';
        $confirm = $req->post['confirm'] ?? '';
        if (!$this->hasher->verify($current, $this->user['password_hash'])) {
            $this->flash('flash_error', 'Current password is incorrect');
        } elseif (strlen($new) < 8) {
            $this->flash('flash_error', 'New password must be at least 8 characters');
        } elseif ($new !== $confirm) {
            $this->flash('flash_error', 'Passwords do not match');
        } else {
            $this->users->updatePassword((int)$this->user['id'], $this->hasher->hash($new));
            $this->flash('flash_success', 'Password changed');
        }
        Response::redirect('/profile');
    }
}
