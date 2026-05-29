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
        $page    = max(1, (int)($req->query['page'] ?? 1));
        $query   = trim((string)($req->query['q'] ?? ''));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;
        $list    = $this->users->listPaged($perPage, $offset, $query);
        $total   = $this->users->countAll($query);
        $pages   = max(1, (int)ceil($total / $perPage));
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
                'page' => $page, 'pages' => $pages, 'total' => $total,
                'query' => $query,
            ]),
        ]));
    }

    public function approve(Request $req, array $params): void {
        $id = (int)$params['id'];
        $this->users->approve($id);
        $approved = $this->users->findById($id);
        if ($approved) {
            App::make('events')->fire('user.approved', [
                'user_id'    => (int)$approved['id'],
                'name'       => $approved['name'],
                'actor_name' => $this->user['name'],
            ]);
        }
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
        if (!in_array($role, ['admin', 'manager', 'employee'], true)) {
            Response::json(['error' => 'Invalid role'], 422); return;
        }
        if ((int)$params['id'] === (int)$this->user['id']) {
            // Prevent the sole admin from locking themselves out of admin functions.
            Response::json(['error' => 'Cannot change your own role'], 422); return;
        }
        $this->users->setRole((int)$params['id'], $role);
        Response::json(['ok' => true]);
    }

    public function create(Request $req, array $params = []): void {
        $data  = json_decode(file_get_contents('php://input'), true) ?? $req->post;
        $name  = trim((string)($data['name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $pass  = (string)($data['password'] ?? '');
        $role  = in_array($data['role'] ?? 'employee', ['admin', 'manager', 'employee'], true)
            ? $data['role'] : 'employee';
        if ($name === '' || $email === '' || strlen($pass) < 8) {
            Response::json(['error' => 'Name, email and password (min 8) are required'], 422); return;
        }
        if ($this->users->findByEmail($email)) {
            Response::json(['error' => 'Email already registered'], 422); return;
        }
        $hash = App::make('hasher')->hash($pass);
        $id   = $this->users->create($email, $hash, $name);
        // create() defaults to pending for non-first users; admin-created → approved + chosen role.
        $this->users->approve($id);
        $this->users->setRole($id, $role);
        Response::json(['ok' => true, 'id' => $id]);
    }

    public function update(Request $req, array $params): void {
        $id   = (int)$params['id'];
        $u    = $this->users->findById($id);
        if (!$u) { Response::json(['error' => 'Not found'], 404); return; }
        $data = json_decode(file_get_contents('php://input'), true) ?? $req->post;
        $name = isset($data['name']) ? trim((string)$data['name']) : null;
        $pass = isset($data['password']) ? (string)$data['password'] : '';
        if ($name !== null && $name !== '' && $name !== $u['name']) {
            $this->users->updateName($id, $name);
        }
        if ($pass !== '') {
            if (strlen($pass) < 8) { Response::json(['error' => 'Password must be at least 8 chars'], 422); return; }
            $this->users->updatePassword($id, App::make('hasher')->hash($pass));
        }
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
