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
        $name  = trim($req->post['name']  ?? '');
        $email = strtolower(trim($req->post['email'] ?? ''));
        if ($name === '') {
            $this->flash('flash_error', 'Name cannot be empty');
            Response::redirect('/profile'); return;
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('flash_error', 'Email is not valid');
            Response::redirect('/profile'); return;
        }
        // Email uniqueness — allow keeping our own
        if (strtolower((string)$this->user['email']) !== $email) {
            $taken = $this->users->findByEmail($email);
            if ($taken && (int)$taken['id'] !== (int)$this->user['id']) {
                $this->flash('flash_error', 'Email is already in use');
                Response::redirect('/profile'); return;
            }
            $this->users->updateEmail((int)$this->user['id'], $email);
        }
        if ($name !== (string)$this->user['name']) {
            $this->users->updateName((int)$this->user['id'], $name);
        }
        $this->flash('flash_success', 'Profile updated');
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

    public function updateAvatar(Request $req, array $params = []): void {
        $file = $req->files['avatar'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->flash('flash_error', 'No file uploaded');
            Response::redirect('/profile'); return;
        }
        $uploader = App::make('uploader');
        $err = $uploader->validate($file);
        if ($err) { $this->flash('flash_error', $err); Response::redirect('/profile'); return; }
        if (!$uploader->isImage($file['type'] ?? '')) {
            $this->flash('flash_error', 'Avatar must be an image');
            Response::redirect('/profile'); return;
        }
        try {
            $stored = $uploader->store($file);
        } catch (\Throwable $e) {
            $this->flash('flash_error', 'Upload failed: ' . $e->getMessage());
            Response::redirect('/profile'); return;
        }
        // Remove old avatar file (best-effort) before swapping.
        if (!empty($this->user['avatar'])) {
            $oldAbs = APP_ROOT . '/public/' . ltrim($this->user['avatar'], '/');
            if (is_file($oldAbs)) @unlink($oldAbs);
        }
        $this->users->updateAvatar((int)$this->user['id'], $stored['filename']);
        $this->flash('flash_success', 'Avatar updated');
        Response::redirect('/profile');
    }

    public function removeAvatar(Request $req, array $params = []): void {
        if (!empty($this->user['avatar'])) {
            $oldAbs = APP_ROOT . '/public/' . ltrim($this->user['avatar'], '/');
            if (is_file($oldAbs)) @unlink($oldAbs);
            $this->users->updateAvatar((int)$this->user['id'], null);
            $this->flash('flash_success', 'Avatar removed');
        }
        Response::redirect('/profile');
    }
}
