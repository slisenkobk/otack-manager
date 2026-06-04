<?php
declare(strict_types=1);
namespace App\Controller;

use App\Auth\PasswordHasher;
use App\Http\AuthGuard;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\ValidationException;
use App\Http\Validator;
use App\Repository\ApiTokenRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;
use App\Service\EventBus;
use App\View\Renderer;

final class UserController extends BaseController {
    public function __construct(
        Renderer $view,
        ?array $user,
        private UserRepository $users,
        private ApiTokenRepository $apiTokens,
        private PasswordHasher $hasher,
        private SettingsRepository $settings,
        private EventBus $events,
        private Csrf $csrf,
        private object $session,
    ) {
        parent::__construct($view, $user);
        AuthGuard::requireAdmin($this->user);
    }

    private function &session(): array {
        return $this->session->store;
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

    public function show(Request $req, array $params): void {
        $id   = (int)($params['id'] ?? 0);
        $user = $this->users->findById($id);
        if (!$user) { Response::notFound(); return; }

        $csrfToken = $this->csrfToken();
        $apiTokens = $this->apiTokens->listForUser($id);

        // One-time plaintext reveal after an admin-initiated token creation.
        // Drained on first render so a refresh hides it.
        $s = &$this->session();
        $oneTime = $s['flash_token_once'] ?? null;
        unset($s['flash_token_once']);

        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'users', 'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => $user['name'],
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => $user['name'],
            'csrfToken' => $csrfToken,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('users/show', [
                'user'      => $user,
                'apiTokens' => $apiTokens,
                'csrfToken' => $csrfToken,
                'oneTime'   => $oneTime,
                'success'   => $this->consumeFlash('flash_success'),
                'error'     => $this->consumeFlash('flash_error'),
            ]),
        ]));
    }

    public function createToken(Request $req, array $params): void {
        $userId = (int)($params['id'] ?? 0);
        $target = $this->users->findById($userId);
        if (!$target) { Response::notFound(); return; }

        $name = trim((string)($req->post['name'] ?? ''));
        if ($name === '') {
            $this->flash('flash_error', t('api_tokens.error_name_required'));
            Response::redirect('/users/' . $userId); return;
        }
        $expiresRaw = trim((string)($req->post['expires_at'] ?? ''));
        $expiresAt  = null;
        if ($expiresRaw !== '') {
            // strtotime returns false on garbage; treat that as "no expiry" so
            // a typo doesn't 500 the whole submission.
            $ts = strtotime($expiresRaw);
            if ($ts !== false && $ts > time()) {
                $expiresAt = (int)$ts;
            }
        }
        $created = $this->apiTokens->create($userId, $name, $expiresAt);

        // One-time reveal: plaintext is flashed to the admin's session so
        // show() can render it once and clear it. Mirrors the self-serve
        // flow in ProfileController::tokensCreate.
        $s = &$this->session();
        $s['flash_token_once'] = [
            'name'  => $name,
            'token' => $created['token'],
        ];
        Response::redirect('/users/' . $userId);
    }

    public function revokeToken(Request $req, array $params): void {
        $userId  = (int)($params['id'] ?? 0);
        $tokenId = (int)($params['tid'] ?? 0);
        $row = $this->apiTokens->findById($tokenId);
        // Silent no-op on missing / cross-user IDs — admins shouldn't be able
        // to probe token ids that don't belong to this user.
        if ($row && (int)$row['user_id'] === $userId) {
            $this->apiTokens->revoke($tokenId);
            $this->flash('flash_success', t('api_tokens.revoked'));
        }
        Response::redirect('/users/' . $userId);
    }

    public function revokeAllTokens(Request $req, array $params): void {
        $userId = (int)($params['id'] ?? 0);
        $this->apiTokens->revokeAllForUser($userId);
        $this->flash('flash_success', t('api_tokens.all_revoked'));
        Response::redirect('/users/' . $userId);
    }

    public function index(Request $req, array $params = []): void {
        $page    = max(1, (int)($req->query['page'] ?? 1));
        $query   = trim((string)($req->query['q'] ?? ''));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;
        $list    = $this->users->listPaged($perPage, $offset, $query);
        $total   = $this->users->countAll($query);
        $pages   = max(1, (int)ceil($total / $perPage));
        $csrfToken = $this->csrf->token();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'users', 'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => t('nav.users'),
        ]);
        Response::html($this->view->render('layouts/main', [
            'title' => t('nav.users'),
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
            $this->events->fire('user.approved', [
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
        $data = $req->jsonBody($req->post);
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
        $data = $req->jsonBody($req->post);
        try {
            $clean = Validator::for($data)
                ->required('name')
                ->required('email')->email('email')
                ->required('password')->minLength('password', 8)
                ->clean();
        } catch (ValidationException $e) {
            Response::json([
                'error'  => 'Name, email and password (min 8) are required',
                'fields' => $e->fields,
            ], 422); return;
        }
        $name  = $clean['name'];
        $email = $clean['email'];
        $pass  = $clean['password'];
        $role  = in_array($data['role'] ?? 'employee', ['admin', 'manager', 'employee'], true)
            ? $data['role'] : 'employee';
        if ($this->users->findByEmail($email)) {
            Response::json(['error' => 'Email already registered'], 422); return;
        }
        $hash = $this->hasher->hash($pass);
        $id   = $this->users->create($email, $hash, $name);
        // create() defaults to pending for non-first users; admin-created → approved + chosen role.
        $this->users->approve($id);
        $this->users->setRole($id, $role);
        // Locale: take the admin-provided value if valid, otherwise inherit
        // settings.default_locale (also 'en' if unset).
        $locale = (string)($data['locale'] ?? '');
        if (!in_array($locale, available_locales(), true)) {
            $locale = $this->settings->get('default_locale', 'en');
            if (!in_array($locale, available_locales(), true)) $locale = 'en';
        }
        $this->users->updateLocale($id, $locale);
        Response::json(['ok' => true, 'id' => $id]);
    }

    public function update(Request $req, array $params): void {
        $id   = (int)$params['id'];
        $u    = $this->users->findById($id);
        if (!$u) { Response::json(['error' => 'Not found'], 404); return; }
        $data = $req->jsonBody($req->post);
        $name = isset($data['name']) ? trim((string)$data['name']) : null;
        $pass = isset($data['password']) ? (string)$data['password'] : '';
        if ($name !== null && $name !== '' && $name !== $u['name']) {
            $this->users->updateName($id, $name);
        }
        if ($pass !== '') {
            if (strlen($pass) < 8) { Response::json(['error' => 'Password must be at least 8 chars'], 422); return; }
            $this->users->updatePassword($id, $this->hasher->hash($pass));
        }
        $locale = isset($data['locale']) ? (string)$data['locale'] : null;
        if ($locale !== null && in_array($locale, available_locales(), true) && $locale !== ($u['locale'] ?? 'en')) {
            $this->users->updateLocale($id, $locale);
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
