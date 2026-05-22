<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Http\AuthGuard;
use App\Repository\UserRepository;

final class UserController extends BaseController {
    private UserRepository $users;

    public function __construct($view, $user = null) {
        parent::__construct($view, $user);
        AuthGuard::requireAdmin($this->user);
        $this->users = App::make('users');
    }

    public function index(Request $req, array $params = []): void {
        $list = $this->users->listAll();
        $csrfToken = App::make('csrf')->token();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'users', 'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => 'Users',
        ]);
        Response::html($this->view->render('layouts/main', [
            'title' => 'Users',
            'csrfToken' => $csrfToken,
            'sidebar' => $sidebar,
            'topbar' => $topbar,
            'content' => $this->view->render('users/index', [
                'users' => $list, 'currentUserId' => (int)$this->user['id'],
            ]),
        ]));
    }

    public function approve(Request $req, array $params): void {
        $this->users->approve((int)$params['id']);
        Response::json(['ok' => true]);
    }

    public function block(Request $req, array $params): void {
        $id = (int)$params['id'];
        if ($id === (int)$this->user['id']) {
            Response::json(['error' => 'Cannot block yourself'], 422); return;
        }
        $this->users->block($id);
        Response::json(['ok' => true]);
    }

    public function setRole(Request $req, array $params): void {
        $data = json_decode(file_get_contents('php://input'), true) ?? $req->post;
        $role = $data['role'] ?? '';
        if (!in_array($role, ['admin', 'member'], true)) {
            Response::json(['error' => 'Invalid role'], 422); return;
        }
        $this->users->setRole((int)$params['id'], $role);
        Response::json(['ok' => true]);
    }

    public function delete(Request $req, array $params): void {
        $id = (int)$params['id'];
        if ($id === (int)$this->user['id']) {
            Response::json(['error' => 'Cannot delete yourself'], 422); return;
        }
        if ($this->users->hasRelatedData($id)) {
            Response::json(['error' => 'User has related data — block instead', 'has_data' => true], 422); return;
        }
        $this->users->delete($id);
        Response::json(['ok' => true]);
    }
}
